import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { logger } from '../src/logger.js';
import { OpenClawGatewayRelay } from '../src/openclaw-gateway-relay.js';
import type { ProvisionApiClient } from '../src/provision-api.js';
import type {
    ChatRelayEvent,
    Config,
    OpenClawSessionSnapshot,
} from '../src/types.js';

type JsonRecord = Record<string, unknown>;

type RelayInternals = {
    socket: WebSocket | null;
    pending: Map<string, unknown>;
    eventQueue: ChatRelayEvent[];
    sessionSyncTimer: ReturnType<typeof setTimeout> | null;
    watchdogTimer: ReturnType<typeof setInterval> | null;
    challengeResolver: ((nonce: string) => void) | null;
    challengeTimer: ReturnType<typeof setTimeout> | null;
    lastOuterSequence: number | null;
    connectAndRun(credentials: { port: number; token: string }): Promise<void>;
    normalizeEvent(event: string, payload?: JsonRecord): ChatRelayEvent | null;
    sessionSnapshot(row: JsonRecord): OpenClawSessionSnapshot | null;
    enqueueEvent(event: ChatRelayEvent): void;
    takeEventBatch(): ChatRelayEvent[];
    handleMessage(event: MessageEvent, sourceSocket?: WebSocket): void;
    scheduleSessionSync(delay?: number): void;
};

const config: Config = {
    apiUrl: 'https://provision.test',
    daemonToken: 'daemon-token',
    serverId: 'server-01',
    pollInterval: 30,
    maxConcurrent: 2,
    taskTimeout: 600,
    checkoutDuration: 3600,
};

class FakeWebSocket extends EventTarget {
    static readonly CONNECTING = 0;
    static readonly OPEN = 1;
    static readonly CLOSING = 2;
    static readonly CLOSED = 3;
    static instances: FakeWebSocket[] = [];
    static onRequest:
        | ((socket: FakeWebSocket, frame: JsonRecord) => void)
        | null = null;

    readonly sent: JsonRecord[] = [];
    readonly closeCalls: Array<{ code?: number; reason?: string }> = [];
    readyState = FakeWebSocket.CONNECTING;

    constructor(readonly url: string) {
        super();
        FakeWebSocket.instances.push(this);
    }

    open(): void {
        this.readyState = FakeWebSocket.OPEN;
        this.dispatchEvent(new Event('open'));
    }

    send(data: string): void {
        const frame = JSON.parse(data) as JsonRecord;
        this.sent.push(frame);
        FakeWebSocket.onRequest?.(this, frame);
    }

    close(code?: number, reason?: string): void {
        this.closeCalls.push({ code, reason });
        if (this.readyState === FakeWebSocket.CLOSED) {
            return;
        }
        this.readyState = FakeWebSocket.CLOSED;
        this.dispatchEvent(new Event('close'));
    }

    emitFrame(frame: JsonRecord): void {
        this.dispatchEvent(
            new MessageEvent('message', {
                data: JSON.stringify(frame),
            }),
        );
    }
}

function makeApi() {
    const reportChatEvents = vi.fn(
        async (_events: ChatRelayEvent[]) => undefined,
    );
    const syncOpenClawSessions = vi.fn(
        async (_sessions: OpenClawSessionSnapshot[]) => undefined,
    );

    return {
        api: {
            reportChatEvents,
            syncOpenClawSessions,
        } as unknown as ProvisionApiClient,
        reportChatEvents,
        syncOpenClawSessions,
    };
}

function makeRelay(api = makeApi().api): {
    relay: OpenClawGatewayRelay;
    internals: RelayInternals;
} {
    const relay = new OpenClawGatewayRelay(config, api);
    return { relay, internals: relay as unknown as RelayInternals };
}

function response(id: unknown, payload: JsonRecord): JsonRecord {
    return { type: 'res', id, ok: true, payload };
}

function hello(scopes = ['operator.read', 'operator.write']): JsonRecord {
    return {
        type: 'hello-ok',
        protocol: 4,
        server: { version: '2026.7.1', connId: 'connection-01' },
        features: { methods: [], events: [] },
        snapshot: {},
        auth: { role: 'operator', scopes },
        policy: {
            maxPayload: 25_000_000,
            maxBufferedBytes: 50_000_000,
            tickIntervalMs: 15_000,
        },
    };
}

function delta(overrides: Partial<ChatRelayEvent> = {}): ChatRelayEvent {
    return {
        event: 'chat',
        agent_id: 'ruhi',
        session_key: 'agent:ruhi:dashboard:conversation-01',
        run_id: 'run-01',
        sequence: 1,
        state: 'delta',
        delta: 'A',
        cumulative: 'A',
        ...overrides,
    };
}

beforeEach(() => {
    FakeWebSocket.instances = [];
    FakeWebSocket.onRequest = null;
    vi.stubGlobal('WebSocket', FakeWebSocket as unknown as typeof WebSocket);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('OpenClaw Gateway v4 connection', () => {
    it('uses the reserved loopback backend handshake before subscribing and listing sessions', async () => {
        const { api } = makeApi();
        const { internals } = makeRelay(api);

        FakeWebSocket.onRequest = (socket, frame) => {
            const method = frame.method;
            if (method === 'connect') {
                queueMicrotask(() =>
                    socket.emitFrame(response(frame.id, hello())),
                );
            } else if (method === 'sessions.subscribe') {
                queueMicrotask(() =>
                    socket.emitFrame(response(frame.id, { subscribed: true })),
                );
            } else if (method === 'sessions.list') {
                queueMicrotask(() => {
                    socket.emitFrame(
                        response(frame.id, {
                            sessions: [],
                            hasMore: false,
                            nextOffset: null,
                        }),
                    );
                    queueMicrotask(() => socket.close(1006, 'test complete'));
                });
            }
        };

        const connection = internals.connectAndRun({
            port: 18_789,
            token: 'gateway-secret',
        });
        const socket = FakeWebSocket.instances[0];
        expect(socket.url).toBe('ws://127.0.0.1:18789');
        socket.open();
        socket.emitFrame({
            type: 'event',
            event: 'connect.challenge',
            payload: { nonce: 'nonce-01', ts: Date.now() },
        });

        await expect(connection).rejects.toThrow('Gateway WebSocket closed');

        expect(socket.sent.map((frame) => frame.method)).toEqual([
            'connect',
            'sessions.subscribe',
            'sessions.list',
        ]);
        const connectParams = socket.sent[0].params as JsonRecord;
        expect(connectParams).toMatchObject({
            minProtocol: 4,
            maxProtocol: 4,
            client: {
                id: 'gateway-client',
                mode: 'backend',
                instanceId: 'provisiond:server-01',
            },
            role: 'operator',
            scopes: ['operator.read', 'operator.write'],
            caps: [],
            auth: { token: 'gateway-secret' },
        });
        expect(connectParams).not.toHaveProperty('device');
        expect(socket.sent[2].params).toMatchObject({
            configuredAgentsOnly: true,
            includeUnknown: true,
            includeGlobal: false,
            limit: 100,
            offset: 0,
        });
    });

    it('closes and clears a failed connection instead of leaking it into reconnect', async () => {
        const { internals } = makeRelay();
        FakeWebSocket.onRequest = (socket, frame) => {
            if (frame.method === 'connect') {
                queueMicrotask(() =>
                    socket.emitFrame(
                        response(frame.id, hello(['operator.read'])),
                    ),
                );
            }
        };

        const connection = internals.connectAndRun({
            port: 18_790,
            token: 'gateway-secret',
        });
        const socket = FakeWebSocket.instances[0];
        socket.open();
        socket.emitFrame({
            type: 'event',
            event: 'connect.challenge',
            payload: { nonce: 'nonce-02', ts: Date.now() },
        });

        await expect(connection).rejects.toThrow('under-scoped handshake');

        expect(socket.closeCalls).toContainEqual({
            code: 1000,
            reason: 'Gateway connection attempt ended',
        });
        expect(socket.sent.map((frame) => frame.method)).toEqual(['connect']);
        expect(internals.socket).toBeNull();
        expect(internals.pending.size).toBe(0);
        expect(internals.challengeResolver).toBeNull();
        expect(internals.challengeTimer).toBeNull();
        expect(internals.sessionSyncTimer).toBeNull();
        expect(internals.watchdogTimer).toBeNull();
    });

    it('fails immediately when the socket closes before opening', async () => {
        const { internals } = makeRelay();
        const connection = internals.connectAndRun({
            port: 18_791,
            token: 'gateway-secret',
        });
        const socket = FakeWebSocket.instances[0];

        socket.close(1006, 'open failed');

        await expect(connection).rejects.toThrow('closed before opening');
        expect(internals.socket).toBeNull();
        expect(internals.pending.size).toBe(0);
    });

    it('logs and retries an initial snapshot failure without closing realtime events', async () => {
        const { api, syncOpenClawSessions } = makeApi();
        syncOpenClawSessions.mockRejectedValueOnce(
            new Error('Provision API unavailable'),
        );
        const warn = vi.spyOn(logger, 'warn').mockImplementation(() => {});
        const { relay, internals } = makeRelay(api);
        FakeWebSocket.onRequest = (socket, frame) => {
            if (frame.method === 'connect') {
                queueMicrotask(() =>
                    socket.emitFrame(response(frame.id, hello())),
                );
            } else if (frame.method === 'sessions.subscribe') {
                queueMicrotask(() =>
                    socket.emitFrame(response(frame.id, { subscribed: true })),
                );
            } else if (frame.method === 'sessions.list') {
                queueMicrotask(() =>
                    socket.emitFrame(
                        response(frame.id, {
                            sessions: [
                                {
                                    key: 'agent:ruhi:dashboard:conversation-01',
                                    kind: 'unknown',
                                    spawnedBy: 's'.repeat(300),
                                },
                            ],
                            hasMore: false,
                            nextOffset: null,
                        }),
                    ),
                );
            }
        };

        const connection = internals.connectAndRun({
            port: 18_792,
            token: 'gateway-secret',
        });
        const socket = FakeWebSocket.instances[0];
        socket.open();
        socket.emitFrame({
            type: 'event',
            event: 'connect.challenge',
            payload: { nonce: 'nonce-03', ts: Date.now() },
        });

        await vi.waitFor(() => {
            expect(syncOpenClawSessions).toHaveBeenCalledTimes(1);
            expect(internals.sessionSyncTimer).not.toBeNull();
        });

        const snapshots = syncOpenClawSessions.mock.calls[0][0];
        expect(snapshots[0].spawnedBy).toHaveLength(255);
        expect(socket.readyState).toBe(FakeWebSocket.OPEN);
        expect(socket.closeCalls).toHaveLength(0);
        expect(warn).toHaveBeenCalledWith(
            'OpenClaw session reconciliation failed',
            { error: 'Provision API unavailable' },
        );

        relay.stop();
        await expect(connection).resolves.toBeUndefined();
    });
});

describe('event normalization', () => {
    it('preserves streaming whitespace and canonical correlation fields', () => {
        const { internals } = makeRelay();

        const event = internals.normalizeEvent('chat', {
            sessionKey: 'agent:ruhi:dashboard:conversation-01',
            agentId: 'ruhi',
            runId: 'provision-chat:01KABC',
            seq: 7,
            state: 'delta',
            deltaText: ' ',
            message: {
                role: 'assistant',
                content: [{ type: 'text', text: 'Hello ' }],
            },
        });

        expect(event).toMatchObject({
            event: 'chat',
            agent_id: 'ruhi',
            session_key: 'agent:ruhi:dashboard:conversation-01',
            run_id: 'provision-chat:01KABC',
            sequence: 7,
            state: 'delta',
            delta: ' ',
            cumulative: 'Hello ',
        });
        expect(
            internals.normalizeEvent('chat', {
                sessionKey: 'agent:ruhi:main',
                state: 'final',
            }),
        ).toBeNull();
    });

    it('normalizes nested session state and strips tool arguments and results', () => {
        const { internals } = makeRelay();

        const message = internals.normalizeEvent('session.message', {
            sessionKey: 'agent:ruhi:main',
            message: {
                role: 'assistant',
                idempotencyKey: 'run-01',
                __openclaw: { id: 'entry-01', seq: 9 },
            },
            session: { hasActiveRun: true },
        });
        const tool = internals.normalizeEvent('agent', {
            sessionKey: 'agent:ruhi:main',
            runId: 'run-01',
            seq: 10,
            stream: 'tool',
            data: {
                phase: 'start',
                name: 'browser_navigate',
                args: { token: 'must-not-leak' },
                result: 'must-not-leak',
            },
        });

        expect(message).toMatchObject({
            event: 'session.message',
            role: 'assistant',
            idempotency_key: 'run-01',
            message_id: 'entry-01',
            message_sequence: 9,
            has_active_run: true,
        });
        expect(tool).toEqual({
            event: 'session.tool',
            agent_id: 'ruhi',
            session_key: 'agent:ruhi:main',
            run_id: 'run-01',
            sequence: 10,
            tool: 'browser_navigate',
            phase: 'start',
            label: 'using browser navigate',
        });
    });

    it('rejects mismatched agents and overlong session keys instead of truncating identities', () => {
        const { internals } = makeRelay();
        const overlongKey = `agent:ruhi:${'x'.repeat(245)}`;
        expect(overlongKey.length).toBeGreaterThan(255);

        expect(
            internals.normalizeEvent('chat', {
                sessionKey: overlongKey,
                agentId: 'ruhi',
                runId: 'run-01',
                state: 'final',
            }),
        ).toBeNull();
        expect(
            internals.normalizeEvent('chat', {
                sessionKey: 'agent:ruhi:main',
                agentId: 'another-agent',
                runId: 'run-01',
                state: 'final',
            }),
        ).toBeNull();
    });
});

describe('session snapshots', () => {
    it('keeps unknown dashboard sessions for reconciliation and bounds active run identities', () => {
        const { internals } = makeRelay();
        const snapshot = internals.sessionSnapshot({
            key: 'agent:ruhi:dashboard:conversation-01',
            kind: 'unknown',
            channel: 'webchat',
            derivedTitle: 'Dashboard thread',
            updatedAt: 1_775_000_000_000,
            hasActiveRun: true,
            activeRunIds: [' run-01 ', '', 'x'.repeat(256), 'run-02'],
        });

        expect(snapshot).toEqual({
            agentId: 'ruhi',
            key: 'agent:ruhi:dashboard:conversation-01',
            kind: 'unknown',
            channel: 'webchat',
            chatType: undefined,
            label: undefined,
            displayName: undefined,
            derivedTitle: 'Dashboard thread',
            subject: undefined,
            lastMessagePreview: undefined,
            updatedAt: 1_775_000_000_000,
            hasActiveRun: true,
            activeRunIds: ['run-01', 'run-02'],
            spawnedBy: undefined,
            subagentRole: undefined,
        });
    });

    it('skips session keys that cannot fit the persisted 255-character identity', () => {
        const { internals } = makeRelay();
        expect(
            internals.sessionSnapshot({
                key: `agent:ruhi:${'x'.repeat(245)}`,
                kind: 'direct',
            }),
        ).toBeNull();
    });
});

describe('queue and reconnect recovery', () => {
    it('coalesces only contiguous deltas for the same run and keeps the highest sequence', () => {
        vi.useFakeTimers();
        const { relay, internals } = makeRelay();

        internals.enqueueEvent(delta());
        internals.enqueueEvent(
            delta({ sequence: 3, delta: ' B', cumulative: undefined }),
        );
        internals.enqueueEvent(
            delta({ sequence: 2, delta: ' stale', cumulative: 'stale' }),
        );

        expect(internals.eventQueue).toHaveLength(1);
        expect(internals.eventQueue[0]).toMatchObject({
            sequence: 3,
            cumulative: 'A B',
        });

        internals.enqueueEvent({
            event: 'session.tool',
            agent_id: 'ruhi',
            session_key: 'agent:ruhi:dashboard:conversation-01',
            run_id: 'run-01',
            tool: 'browser',
            phase: 'start',
        });
        internals.enqueueEvent(
            delta({ sequence: 4, delta: ' C', cumulative: 'A B C' }),
        );
        internals.enqueueEvent(
            delta({ sequence: 5, state: 'final', delta: undefined }),
        );
        internals.enqueueEvent(
            delta({ sequence: 6, delta: ' late', cumulative: 'A B C late' }),
        );

        expect(
            internals.eventQueue.map(
                (event) => `${event.event}:${event.state ?? event.phase}`,
            ),
        ).toEqual([
            'chat:delta',
            'session.tool:start',
            'chat:delta',
            'chat:final',
            'chat:delta',
        ]);
        relay.stop();
    });

    it('never evicts terminal events and limits each forwarded batch by serialized size', () => {
        vi.useFakeTimers();
        const { relay, internals } = makeRelay();
        for (let index = 0; index < 500; index++) {
            internals.enqueueEvent(
                delta({
                    run_id: `run-${index}`,
                    state: 'final',
                    delta: undefined,
                    cumulative: undefined,
                }),
            );
        }
        internals.enqueueEvent(delta({ run_id: 'discardable-delta' }));
        internals.enqueueEvent(
            delta({
                run_id: 'terminal-501',
                state: 'final',
                delta: undefined,
                cumulative: undefined,
            }),
        );

        expect(internals.eventQueue).toHaveLength(501);
        expect(internals.eventQueue[0].run_id).toBe('run-0');
        expect(
            internals.eventQueue.some(
                (event) => event.run_id === 'discardable-delta',
            ),
        ).toBe(false);
        expect(internals.eventQueue.at(-1)?.run_id).toBe('terminal-501');

        internals.eventQueue = Array.from({ length: 10 }, (_, index) =>
            delta({
                session_key: `agent:ruhi:dashboard:${index}`,
                run_id: `large-${index}`,
                cumulative: 'x'.repeat(200_000),
            }),
        );
        const batch = internals.takeEventBatch();
        expect(batch.length).toBeLessThan(10);
        expect(
            batch.reduce(
                (size, event) =>
                    size + Buffer.byteLength(JSON.stringify(event), 'utf8') + 1,
                0,
            ),
        ).toBeLessThanOrEqual(1_000_000);
        relay.stop();
    });

    it('ignores late frames from an old socket and reconciles a gap on the active socket', () => {
        vi.useFakeTimers();
        const { relay, internals } = makeRelay();
        const oldSocket = new FakeWebSocket('ws://127.0.0.1:18789');
        const activeSocket = new FakeWebSocket('ws://127.0.0.1:18789');
        oldSocket.open();
        activeSocket.open();
        internals.socket = activeSocket as unknown as WebSocket;
        const reconcile = vi.spyOn(internals, 'scheduleSessionSync');
        const frame = (outerSequence: number, runSequence: number) =>
            new MessageEvent('message', {
                data: JSON.stringify({
                    type: 'event',
                    event: 'chat',
                    seq: outerSequence,
                    payload: {
                        sessionKey: 'agent:ruhi:main',
                        runId: 'run-01',
                        seq: runSequence,
                        state: 'delta',
                        deltaText: 'A',
                    },
                }),
            });

        internals.handleMessage(frame(9, 1), oldSocket as unknown as WebSocket);
        expect(internals.lastOuterSequence).toBeNull();
        expect(internals.eventQueue).toHaveLength(0);

        internals.handleMessage(
            frame(10, 1),
            activeSocket as unknown as WebSocket,
        );
        internals.handleMessage(
            frame(12, 3),
            activeSocket as unknown as WebSocket,
        );
        internals.handleMessage(
            frame(11, 2),
            activeSocket as unknown as WebSocket,
        );

        expect(internals.lastOuterSequence).toBe(12);
        expect(reconcile).toHaveBeenCalledTimes(2);
        expect(reconcile).toHaveBeenNthCalledWith(1, 0);
        expect(reconcile).toHaveBeenNthCalledWith(2, 0);
        relay.stop();
    });
});
