import { describe, expect, it, vi } from 'vitest';
import { ChatSendPoller } from '../src/chat-send-poller.js';
import type { OpenClawGatewayRelay } from '../src/openclaw-gateway-relay.js';
import type { ProvisionApiClient } from '../src/provision-api.js';
import type { ChatOutboxSend } from '../src/types.js';

function makeSend(overrides: Partial<ChatOutboxSend> = {}): ChatOutboxSend {
    return {
        message_id: '01kn-message-1',
        session_key: 'agent:fast-agent:dashboard:conv-1',
        agent_id: 'fast-agent',
        message: 'hello',
        idempotency_key: 'provision-chat:01kn-message-1',
        queued_at: Date.now(),
        ...overrides,
    };
}

function makeHarness(options: {
    sends: Array<ChatOutboxSend | null>;
    connected?: boolean;
    sendChat?: () => Promise<{ runId: string | null; status: string | null }>;
}) {
    const acks: unknown[] = [];
    let polls = 0;

    const api = {
        pollChatOutbox: vi.fn(async () => {
            const send = options.sends[polls] ?? null;
            polls += 1;
            if (polls >= options.sends.length) {
                // Stop the loop after the scripted sends are consumed.
                poller.stop().catch(() => undefined);
            }
            return send;
        }),
        ackChatSend: vi.fn(async (ack: unknown) => {
            acks.push(ack);
        }),
    } as unknown as ProvisionApiClient;

    const relay = {
        isConnected: () => options.connected ?? true,
        sendChat:
            options.sendChat ??
            (async () => ({ runId: 'run-1', status: 'started' })),
    } as unknown as OpenClawGatewayRelay;

    const poller = new ChatSendPoller(api, relay);

    return { poller, api, acks };
}

describe('ChatSendPoller', () => {
    it('fires chat.send and acks started', async () => {
        const { poller, acks } = makeHarness({ sends: [makeSend(), null] });

        poller.start();
        await poller.stop();

        expect(acks).toHaveLength(1);
        expect(acks[0]).toMatchObject({
            message_id: '01kn-message-1',
            status: 'started',
            run_id: 'run-1',
        });
    });

    it('acks an immediate error when the relay socket is down', async () => {
        const { poller, acks } = makeHarness({
            sends: [makeSend(), null],
            connected: false,
        });

        poller.start();
        await poller.stop();

        expect(acks[0]).toMatchObject({
            message_id: '01kn-message-1',
            status: 'error',
        });
    });

    it('acks an error when chat.send rejects', async () => {
        const { poller, acks } = makeHarness({
            sends: [makeSend(), null],
            sendChat: async () => {
                throw new Error('gateway exploded');
            },
        });

        poller.start();
        await poller.stop();

        expect(acks[0]).toMatchObject({
            message_id: '01kn-message-1',
            status: 'error',
            error: 'gateway exploded',
        });
    });

    it('discards malformed outbox entries without acking', async () => {
        const { poller, acks } = makeHarness({
            sends: [makeSend({ idempotency_key: 'not-a-provision-key' }), null],
        });

        poller.start();
        await poller.stop();

        expect(acks).toHaveLength(0);
    });
});
