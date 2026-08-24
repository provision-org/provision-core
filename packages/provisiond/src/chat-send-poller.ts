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
import type { OpenClawGatewayRelay } from './openclaw-gateway-relay.js';
import type { ProvisionApiClient } from './provision-api.js';
import type { ChatOutboxSend } from './types.js';

const POLL_WAIT_SECONDS = 12;
const ERROR_BACKOFF_MS = 5_000;
const MAX_MESSAGE_CHARS = 200_000;

export class ChatSendPoller {
    private stopped = false;

    private runner: Promise<void> | null = null;

    constructor(
        private readonly api: ProvisionApiClient,
        private readonly relay: OpenClawGatewayRelay,
    ) {}

    start(): void {
        if (this.runner) {
            return;
        }
        this.runner = this.loop();
    }

    async stop(): Promise<void> {
        this.stopped = true;
        await this.runner?.catch(() => undefined);
    }

    private async loop(): Promise<void> {
        logger.info('Chat send poller started');

        while (!this.stopped) {
            try {
                const send = await this.api.pollChatOutbox(POLL_WAIT_SECONDS);
                if (send) {
                    await this.handleSend(send);
                }
            } catch (error) {
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

    private async handleSend(send: ChatOutboxSend): Promise<void> {
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
        } catch (error) {
            await this.ackSafely({
                message_id: send.message_id,
                status: 'error',
                error:
                    error instanceof Error
                        ? error.message.slice(0, 500)
                        : 'chat.send failed',
            });
        }
    }

    private validSend(send: ChatOutboxSend): boolean {
        return (
            typeof send.message_id === 'string' &&
            send.message_id.length > 0 &&
            typeof send.session_key === 'string' &&
            send.session_key.length > 0 &&
            typeof send.agent_id === 'string' &&
            send.agent_id.length > 0 &&
            typeof send.message === 'string' &&
            send.message.length > 0 &&
            send.message.length <= MAX_MESSAGE_CHARS &&
            typeof send.idempotency_key === 'string' &&
            send.idempotency_key.startsWith('provision-chat:')
        );
    }

    private async ackSafely(ack: {
        message_id: string;
        status: 'started' | 'error';
        run_id?: string | null;
        error?: string | null;
    }): Promise<void> {
        try {
            await this.api.ackChatSend(ack);
        } catch (error) {
            logger.warn('Chat send ack failed', {
                message_id: ack.message_id,
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }

    private sleep(ms: number): Promise<void> {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }
}
