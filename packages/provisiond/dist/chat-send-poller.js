/**
 * Fast-send poller: long-polls the Provision chat outbox and fires queued
 * sends over the gateway relay's loopback WebSocket.
 *
 * This is the latency-critical half of daemon fast-send — the dashboard
 * drops a message into the outbox and blocks (briefly) on our ack. Everything
 * here is built to ack fast and honestly: if the relay socket is down we ack
 * `error` immediately so the server falls back to its SSH path instead of
 * waiting out its timeout.
 */
import { logger } from './logger.js';
const POLL_WAIT_SECONDS = 12;
const ERROR_BACKOFF_MS = 5_000;
const MAX_MESSAGE_CHARS = 200_000;
export class ChatSendPoller {
    api;
    relay;
    stopped = false;
    runner = null;
    constructor(api, relay) {
        this.api = api;
        this.relay = relay;
    }
    start() {
        if (this.runner) {
            return;
        }
        this.runner = this.loop();
    }
    async stop() {
        this.stopped = true;
        await this.runner?.catch(() => undefined);
    }
    async loop() {
        logger.info('Chat send poller started');
        while (!this.stopped) {
            try {
                const send = await this.api.pollChatOutbox(POLL_WAIT_SECONDS);
                if (send) {
                    await this.handleSend(send);
                }
            }
            catch (error) {
                if (this.stopped) {
                    break;
                }
                logger.warn('Chat outbox poll failed', {
                    error: error instanceof Error ? error.message : String(error),
                });
                await this.sleep(ERROR_BACKOFF_MS);
            }
        }
    }
    async handleSend(send) {
        if (!this.validSend(send)) {
            logger.warn('Discarding malformed chat outbox entry');
            return;
        }
        if (!this.relay.isConnected()) {
            await this.ackSafely({
                message_id: send.message_id,
                status: 'error',
                error: 'Gateway relay socket is not connected',
            });
            return;
        }
        try {
            const result = await this.relay.sendChat({
                sessionKey: send.session_key,
                agentId: send.agent_id,
                message: send.message,
                idempotencyKey: send.idempotency_key,
            });
            await this.ackSafely({
                message_id: send.message_id,
                status: 'started',
                run_id: result.runId ?? send.idempotency_key,
            });
        }
        catch (error) {
            await this.ackSafely({
                message_id: send.message_id,
                status: 'error',
                error: error instanceof Error
                    ? error.message.slice(0, 500)
                    : 'chat.send failed',
            });
        }
    }
    validSend(send) {
        return (typeof send.message_id === 'string' &&
            send.message_id.length > 0 &&
            typeof send.session_key === 'string' &&
            send.session_key.length > 0 &&
            typeof send.agent_id === 'string' &&
            send.agent_id.length > 0 &&
            typeof send.message === 'string' &&
            send.message.length > 0 &&
            send.message.length <= MAX_MESSAGE_CHARS &&
            typeof send.idempotency_key === 'string' &&
            send.idempotency_key.startsWith('provision-chat:'));
    }
    async ackSafely(ack) {
        try {
            await this.api.ackChatSend(ack);
        }
        catch (error) {
            logger.warn('Chat send ack failed', {
                message_id: ack.message_id,
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }
    sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }
}
//# sourceMappingURL=chat-send-poller.js.map