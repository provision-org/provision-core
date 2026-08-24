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
import type { OpenClawGatewayRelay } from './openclaw-gateway-relay.js';
import type { ProvisionApiClient } from './provision-api.js';
export declare class ChatSendPoller {
    private readonly api;
    private readonly relay;
    private stopped;
    private runner;
    constructor(api: ProvisionApiClient, relay: OpenClawGatewayRelay);
    start(): void;
    stop(): Promise<void>;
    private loop;
    private handleSend;
    private validSend;
    private ackSafely;
    private sleep;
}
//# sourceMappingURL=chat-send-poller.d.ts.map