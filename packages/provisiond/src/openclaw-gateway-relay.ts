/**
 * Persistent, server-local OpenClaw Gateway relay.
 *
 * The daemon authenticates through OpenClaw's reserved direct-loopback
 * `gateway-client` backend path. Gateway credentials never leave the machine;
 * only bounded chat lifecycle fields and allowlisted session metadata are
 * forwarded through the daemon-authenticated Provision API.
 */

import { randomUUID } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { logger } from './logger.js';
import { ProvisionApiClient } from './provision-api.js';
import type {
    ChatRelayEvent,
    Config,
    OpenClawSessionSnapshot,
} from './types.js';
import { VERSION } from './version.js';

const OPENCLAW_CONFIG_PATH = '/root/.openclaw/openclaw.json';
const DEFAULT_GATEWAY_PORT = 18_789;
const MAX_INBOUND_FRAME_CHARS = 1_000_000;
const MAX_EVENT_QUEUE = 500;
const MAX_CRITICAL_EVENT_QUEUE = 1_000;
const MAX_EVENT_BATCH_BYTES = 1_000_000;
const EVENT_FLUSH_DELAY_MS = 300;
const SESSION_SYNC_INTERVAL_MS = 5 * 60_000;
const SESSION_SYNC_RETRY_MS = 30_000;
const SOCKET_STALE_AFTER_MS = 60_000;
const MAX_SESSION_KEY_LENGTH = 255;

type JsonRecord = Record<string, unknown>;

type PendingRequest = {
    resolve: (payload: JsonRecord) => void;
    reject: (error: Error) => void;
    timer: ReturnType<typeof setTimeout>;
};

type GatewayCredentials = {
    port: number;
    token: string;
};

export class OpenClawGatewayRelay {
    private socket: WebSocket | null = null;
    private stopped = false;
    private runner: Promise<void> | null = null;
    private pending = new Map<string, PendingRequest>();
    private eventQueue: ChatRelayEvent[] = [];
    private flushTimer: ReturnType<typeof setTimeout> | null = null;
    private flushInProgress = false;
    private sessionSyncTimer: ReturnType<typeof setTimeout> | null = null;
    private watchdogTimer: ReturnType<typeof setInterval> | null = null;
    private lastFrameAt = 0;
    private lastOuterSequence: number | null = null;
    private reconnectAttempt = 0;
    private challengeNonce: string | null = null;
    private challengeRejecter: ((error: Error) => void) | null = null;
    private challengeTimer: ReturnType<typeof setTimeout> | null = null;

    constructor(
        private readonly config: Config,
        private readonly api: ProvisionApiClient,
    ) {}

    start(): void {
        if (this.runner) {
            return;
        }

        this.stopped = false;
        this.runner = this.run().finally(() => {
            this.runner = null;
        });
    }

    stop(): void {
        this.stopped = true;
        this.clearTimers();
        this.cancelChallengeWait(new Error('Gateway relay stopped'));
        this.rejectPending(new Error('Gateway relay stopped'));
        this.socket?.close(1000, 'provisiond stopping');
        this.socket = null;
    }

    private async run(): Promise<void> {
        while (!this.stopped) {
            try {
                const credentials = this.loadCredentials();
                if (!credentials) {
                    await this.sleep(30_000);
                    continue;
                }

                await this.connectAndRun(credentials);
                this.reconnectAttempt = 0;
            } catch (error) {
                if (this.stopped) {
                    return;
                }

                logger.warn('OpenClaw chat relay disconnected', {
                    error:
                        error instanceof Error ? error.message : String(error),
                });
                const baseDelay = Math.min(
                    30_000,
                    1000 * 2 ** this.reconnectAttempt,
                );
                this.reconnectAttempt = Math.min(this.reconnectAttempt + 1, 5);
                const jitter = Math.floor(
                    Math.random() * Math.max(250, baseDelay / 4),
                );
                await this.sleep(baseDelay + jitter);
            }
        }
    }

    private async connectAndRun(
        credentials: GatewayCredentials,
    ): Promise<void> {
        this.lastOuterSequence = null;
        this.lastFrameAt = Date.now();
        this.challengeNonce = null;

        const socket = new WebSocket(`ws://127.0.0.1:${credentials.port}`);
        this.socket = socket;
        const closed = new Promise<void>((resolve) => {
            socket.addEventListener(
                'close',
                () => {
                    if (this.socket === socket) {
                        this.cancelChallengeWait(
                            new Error('Gateway WebSocket closed'),
                        );
                        this.rejectPending(
                            new Error('Gateway WebSocket closed'),
                        );
                    }
                    resolve();
                },
                { once: true },
            );
            socket.addEventListener(
                'error',
                () => {
                    try {
                        socket.close();
                    } catch {
                        // The connection may already be closing.
                    }
                },
                { once: true },
            );
        });

        socket.addEventListener('message', (event) =>
            this.handleMessage(event, socket),
        );
        try {
            await Promise.race([
                this.waitForOpen(socket),
                closed.then(() => {
                    throw new Error('Gateway WebSocket closed before opening');
                }),
            ]);
            await Promise.race([
                this.waitForChallenge(),
                closed.then(() => {
                    throw new Error(
                        'Gateway WebSocket closed before challenge',
                    );
                }),
            ]);

            const hello = await this.request('connect', {
                minProtocol: 4,
                maxProtocol: 4,
                client: {
                    id: 'gateway-client',
                    version: VERSION,
                    platform: process.platform,
                    mode: 'backend',
                    instanceId: `provisiond:${this.config.serverId}`,
                },
                role: 'operator',
                // operator.admin short-circuits 2026.8.1's canReceiveSessionEvent
                // visibility filter: without it, a future gateway.roles config
                // write would silently stop ALL chat events reaching the relay.
                scopes: ['operator.read', 'operator.write', 'operator.admin'],
                caps: [],
                commands: [],
                permissions: {},
                auth: { token: credentials.token },
                // The reserved direct-loopback backend path intentionally omits device.
            });

            if (
                hello.type !== 'hello-ok' ||
                hello.protocol !== 4 ||
                !this.hasRequiredOperatorScopes(hello)
            ) {
                throw new Error(
                    'Gateway returned an incompatible or under-scoped handshake',
                );
            }

            await this.request('sessions.subscribe', {});
            this.reconnectAttempt = 0;
            logger.info('OpenClaw chat relay connected', {
                gatewayVersion: this.stringValue(
                    this.recordValue(hello.server)?.version,
                    32,
                ),
            });

            this.startWatchdog();
            let nextSessionSyncDelay = SESSION_SYNC_INTERVAL_MS;
            try {
                await this.syncSessions();
            } catch (error) {
                nextSessionSyncDelay = SESSION_SYNC_RETRY_MS;
                this.logSessionSyncFailure(error);
            }
            this.scheduleSessionSync(nextSessionSyncDelay);
            await closed;

            if (!this.stopped) {
                throw new Error('Gateway WebSocket closed');
            }
        } finally {
            this.cancelChallengeWait(
                new Error('Gateway connection attempt ended'),
            );
            if (
                socket.readyState === WebSocket.CONNECTING ||
                socket.readyState === WebSocket.OPEN
            ) {
                try {
                    socket.close(1000, 'Gateway connection attempt ended');
                } catch {
                    // The connection may already be closing.
                }
            }
            this.clearConnectionState(socket);
        }
    }

    private handleMessage(event: MessageEvent, sourceSocket?: WebSocket): void {
        if (sourceSocket && sourceSocket !== this.socket) {
            return;
        }

        if (
            typeof event.data !== 'string' ||
            event.data.length > MAX_INBOUND_FRAME_CHARS
        ) {
            // Client-sent close codes must be 1000 or 3000-4999 (WHATWG); the
        // reserved 1xxx codes previously used here made undici throw
        // InvalidAccessError and crash the whole daemon on gateway restarts.
        this.socket?.close(4009, 'Gateway frame too large');
            return;
        }

        let frame: JsonRecord;
        try {
            frame = JSON.parse(event.data) as JsonRecord;
        } catch {
            return;
        }
        this.lastFrameAt = Date.now();

        if (frame.type === 'res' && typeof frame.id === 'string') {
            const pending = this.pending.get(frame.id);
            if (!pending) {
                return;
            }
            this.pending.delete(frame.id);
            clearTimeout(pending.timer);
            if (frame.ok === true && this.isRecord(frame.payload)) {
                pending.resolve(frame.payload);
            } else {
                pending.reject(new Error(this.gatewayError(frame.error)));
            }
            return;
        }

        if (frame.type !== 'event' || typeof frame.event !== 'string') {
            return;
        }

        if (frame.event === 'connect.challenge') {
            const payload = this.recordValue(frame.payload);
            const nonce = this.stringValue(payload?.nonce, 512);
            if (nonce) {
                if (this.challengeResolver) {
                    this.challengeResolver(nonce);
                    this.challengeResolver = null;
                } else {
                    this.challengeNonce = nonce;
                }
            }
            return;
        }

        const outerSequence = this.integerValue(frame.seq);
        if (
            outerSequence !== undefined &&
            this.lastOuterSequence !== null &&
            outerSequence <= this.lastOuterSequence
        ) {
            logger.warn(
                'OpenClaw relay received an out-of-order frame; scheduling reconciliation',
                {
                    previous: this.lastOuterSequence,
                    received: outerSequence,
                },
            );
            this.scheduleSessionSync(0);
            return;
        }
        if (
            outerSequence !== undefined &&
            this.lastOuterSequence !== null &&
            outerSequence !== this.lastOuterSequence + 1
        ) {
            logger.warn(
                'OpenClaw relay sequence gap; scheduling reconciliation',
                {
                    expected: this.lastOuterSequence + 1,
                    received: outerSequence,
                },
            );
            this.scheduleSessionSync(0);
        }
        if (outerSequence !== undefined) {
            this.lastOuterSequence = outerSequence;
        }

        const normalized = this.normalizeEvent(
            frame.event,
            this.recordValue(frame.payload),
        );
        if (normalized) {
            this.enqueueEvent(normalized);
        }

        if (frame.event === 'sessions.changed') {
            this.scheduleSessionSync(1000);
        } else if (frame.event === 'shutdown') {
            this.socket?.close(4012, 'Gateway restarting');
        }
    }

    private challengeResolver: ((nonce: string) => void) | null = null;

    private waitForChallenge(): Promise<string> {
        if (this.challengeNonce) {
            const nonce = this.challengeNonce;
            this.challengeNonce = null;
            return Promise.resolve(nonce);
        }

        this.cancelChallengeWait(new Error('Gateway challenge superseded'));
        return new Promise((resolve, reject) => {
            this.challengeTimer = setTimeout(() => {
                this.challengeTimer = null;
                this.challengeResolver = null;
                this.challengeRejecter = null;
                reject(new Error('Gateway challenge timed out'));
            }, 15_000);
            this.challengeResolver = (nonce) => {
                if (this.challengeTimer) {
                    clearTimeout(this.challengeTimer);
                    this.challengeTimer = null;
                }
                this.challengeResolver = null;
                this.challengeRejecter = null;
                resolve(nonce);
            };
            this.challengeRejecter = (error) => {
                if (this.challengeTimer) {
                    clearTimeout(this.challengeTimer);
                    this.challengeTimer = null;
                }
                this.challengeResolver = null;
                this.challengeRejecter = null;
                reject(error);
            };
        });
    }

    private cancelChallengeWait(error: Error): void {
        const rejecter = this.challengeRejecter;
        if (this.challengeTimer) {
            clearTimeout(this.challengeTimer);
            this.challengeTimer = null;
        }
        this.challengeResolver = null;
        this.challengeRejecter = null;
        rejecter?.(error);
    }

    isConnected(): boolean {
        return this.socket?.readyState === WebSocket.OPEN;
    }

    /**
     * Fire a chat.send over the relay's authenticated loopback socket —
     * the fast-send path. The gateway acks with {runId, status}; streaming
     * output arrives via the normal broadcast events this relay already
     * forwards.
     */
    async sendChat(params: {
        sessionKey: string;
        agentId: string;
        message: string;
        idempotencyKey: string;
    }): Promise<{ runId: string | null; status: string | null }> {
        const payload = await this.request('chat.send', { ...params });
        return {
            runId: typeof payload.runId === 'string' ? payload.runId : null,
            status: typeof payload.status === 'string' ? payload.status : null,
        };
    }

    private request(method: string, params: JsonRecord): Promise<JsonRecord> {
        const socket = this.socket;
        if (!socket || socket.readyState !== WebSocket.OPEN) {
            return Promise.reject(
                new Error('Gateway WebSocket is not connected'),
            );
        }

        const id = randomUUID();
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.pending.delete(id);
                reject(new Error(`Gateway ${method} request timed out`));
            }, 20_000);
            this.pending.set(id, { resolve, reject, timer });
            try {
                socket.send(
                    JSON.stringify({ type: 'req', id, method, params }),
                );
            } catch (error) {
                clearTimeout(timer);
                this.pending.delete(id);
                reject(
                    error instanceof Error ? error : new Error(String(error)),
                );
            }
        });
    }

    private normalizeEvent(
        event: string,
        payload?: JsonRecord,
    ): ChatRelayEvent | null {
        if (!payload) {
            return null;
        }

        const sessionKey = this.identifierValue(
            payload.sessionKey,
            MAX_SESSION_KEY_LENGTH,
        );
        const sessionAgentId = sessionKey
            ? this.agentIdFromSessionKey(sessionKey)
            : undefined;
        const explicitAgentId = this.identifierValue(payload.agentId, 255);
        if (
            explicitAgentId &&
            sessionAgentId &&
            explicitAgentId !== sessionAgentId
        ) {
            return null;
        }
        const agentId = explicitAgentId ?? sessionAgentId;
        if (!sessionKey || !agentId) {
            return null;
        }

        if (event === 'chat') {
            const state = payload.state;
            const runId = this.identifierValue(payload.runId, 255);
            if (
                !runId ||
                !['delta', 'final', 'aborted', 'error'].includes(String(state))
            ) {
                return null;
            }
            const message = this.recordValue(payload.message);
            const cumulative = this.messageText(message);
            const errorKind = [
                'refusal',
                'timeout',
                'rate_limit',
                'context_length',
                'unknown',
            ].includes(String(payload.errorKind))
                ? (payload.errorKind as ChatRelayEvent['error_kind'])
                : undefined;

            return {
                event: 'chat',
                agent_id: agentId,
                session_key: sessionKey,
                run_id: runId,
                sequence: this.integerValue(payload.seq),
                state: state as ChatRelayEvent['state'],
                delta: this.textValue(payload.deltaText, 50_000),
                cumulative: cumulative
                    ? cumulative.slice(0, 200_000)
                    : undefined,
                replace: payload.replace === true ? true : undefined,
                error_kind: errorKind,
            };
        }

        if (event === 'session.message') {
            const message = this.recordValue(payload.message);
            const metadata = this.recordValue(message?.__openclaw);
            const session = this.recordValue(payload.session);
            const role = message?.role;
            if (role !== 'user' && role !== 'assistant') {
                return null;
            }

            return {
                event: 'session.message',
                agent_id: agentId,
                session_key: sessionKey,
                role,
                idempotency_key:
                    this.identifierValue(message?.idempotencyKey, 255) ??
                    this.identifierValue(metadata?.idempotencyKey, 255),
                message_id:
                    this.identifierValue(payload.messageId, 255) ??
                    this.identifierValue(metadata?.id, 255),
                message_sequence:
                    this.integerValue(payload.messageSeq) ??
                    this.integerValue(metadata?.seq),
                has_active_run:
                    this.booleanValue(payload.hasActiveRun) ??
                    this.booleanValue(session?.hasActiveRun),
            };
        }

        if (
            event === 'session.tool' ||
            (event === 'agent' && payload.stream === 'tool')
        ) {
            const data = this.recordValue(payload.data);
            const runId = this.identifierValue(payload.runId, 255);
            const tool = this.stringValue(data?.name, 128);
            const phase = this.stringValue(data?.phase, 64);
            if (!runId) {
                return null;
            }

            return {
                event: 'session.tool',
                agent_id: agentId,
                session_key: sessionKey,
                run_id: runId,
                sequence: this.integerValue(payload.seq),
                tool,
                phase,
                label: tool ? this.toolLabel(tool, phase) : 'working',
            };
        }

        if (event === 'sessions.changed') {
            const session = this.recordValue(payload.session);

            return {
                event: 'sessions.changed',
                agent_id: agentId,
                session_key: sessionKey,
                run_id:
                    this.identifierValue(payload.clientRunId, 255) ??
                    this.identifierValue(payload.runId, 255),
                has_active_run:
                    this.booleanValue(payload.hasActiveRun) ??
                    this.booleanValue(session?.hasActiveRun),
            };
        }

        return null;
    }

    private enqueueEvent(event: ChatRelayEvent): void {
        if (
            event.event === 'chat' &&
            event.state === 'delta' &&
            this.coalesceChatDelta(event)
        ) {
            return;
        }

        if (this.eventQueue.length >= MAX_EVENT_QUEUE) {
            const deltaIndex = this.eventQueue.findIndex(
                (candidate) =>
                    candidate.event === 'chat' && candidate.state === 'delta',
            );
            if (deltaIndex >= 0) {
                this.eventQueue.splice(deltaIndex, 1);
            } else if (event.event === 'chat' && event.state === 'delta') {
                this.scheduleSessionSync(0);
                return;
            }
        }
        this.eventQueue.push(event);

        if (this.eventQueue.length > MAX_CRITICAL_EVENT_QUEUE) {
            this.socket?.close(4013, 'Gateway event relay overloaded');
        }

        this.scheduleEventFlush(
            this.eventQueue.length >= MAX_EVENT_QUEUE
                ? 0
                : EVENT_FLUSH_DELAY_MS,
        );
    }

    private coalesceChatDelta(event: ChatRelayEvent): boolean {
        for (let index = this.eventQueue.length - 1; index >= 0; index--) {
            const candidate = this.eventQueue[index];
            if (
                candidate.agent_id !== event.agent_id ||
                candidate.session_key !== event.session_key
            ) {
                continue;
            }

            if (
                candidate.event !== 'chat' ||
                candidate.state !== 'delta' ||
                candidate.run_id !== event.run_id
            ) {
                return false;
            }

            if (
                candidate.sequence !== undefined &&
                event.sequence !== undefined &&
                event.sequence < candidate.sequence
            ) {
                return true;
            }

            const baseText = candidate.cumulative ?? candidate.delta ?? '';
            const cumulative =
                event.cumulative ??
                (event.replace === true
                    ? event.delta
                    : `${baseText}${event.delta ?? ''}`);
            this.eventQueue[index] = {
                ...candidate,
                ...event,
                cumulative: cumulative
                    ? cumulative.slice(0, 200_000)
                    : undefined,
                sequence:
                    candidate.sequence === undefined
                        ? event.sequence
                        : event.sequence === undefined
                          ? candidate.sequence
                          : Math.max(candidate.sequence, event.sequence),
            };
            return true;
        }

        return false;
    }

    private scheduleEventFlush(delay: number): void {
        if (this.flushTimer || this.flushInProgress) {
            return;
        }
        this.flushTimer = setTimeout(() => {
            this.flushTimer = null;
            void this.flushEvents();
        }, delay);
    }

    private async flushEvents(): Promise<void> {
        if (this.eventQueue.length === 0) {
            return;
        }

        this.flushInProgress = true;
        const batch = this.takeEventBatch();
        let retryDelay = 0;
        try {
            await this.api.reportChatEvents(batch);
        } catch (error) {
            this.eventQueue.unshift(...batch);
            retryDelay = 1000;
            logger.warn('Could not forward OpenClaw chat events', {
                error: error instanceof Error ? error.message : String(error),
            });
        } finally {
            this.flushInProgress = false;
        }

        if (this.eventQueue.length > 0) {
            this.scheduleEventFlush(retryDelay);
        }
    }

    private takeEventBatch(): ChatRelayEvent[] {
        const batch: ChatRelayEvent[] = [];
        let bytes = 0;
        while (batch.length < 100 && this.eventQueue.length > 0) {
            const event = this.eventQueue[0];
            const eventBytes =
                Buffer.byteLength(JSON.stringify(event), 'utf8') + 1;
            if (
                batch.length > 0 &&
                bytes + eventBytes > MAX_EVENT_BATCH_BYTES
            ) {
                break;
            }
            batch.push(event);
            this.eventQueue.shift();
            bytes += eventBytes;
        }
        return batch;
    }

    private async syncSessions(): Promise<void> {
        const snapshots: OpenClawSessionSnapshot[] = [];
        let offset = 0;

        for (let page = 0; page < 10; page++) {
            const result = await this.request('sessions.list', {
                configuredAgentsOnly: true,
                includeDerivedTitles: true,
                includeLastMessage: true,
                includeGlobal: false,
                includeUnknown: true,
                limit: 100,
                offset,
            });
            const rows = Array.isArray(result.sessions) ? result.sessions : [];
            for (const value of rows) {
                const row = this.recordValue(value);
                const snapshot = row ? this.sessionSnapshot(row) : null;
                if (snapshot) {
                    snapshots.push(snapshot);
                }
            }

            // sessions.list has never returned nextOffset/hasMore (verified at
            // 2026.7.1 and 2026.8.1) — page on row count until a short page.
            if (rows.length < 100) {
                break;
            }
            offset += rows.length;
        }

        for (let index = 0; index < snapshots.length; index += 100) {
            await this.api.syncOpenClawSessions(
                snapshots.slice(index, index + 100),
            );
        }
    }

    private sessionSnapshot(row: JsonRecord): OpenClawSessionSnapshot | null {
        const key = this.identifierValue(row.key, MAX_SESSION_KEY_LENGTH);
        const agentId = key ? this.agentIdFromSessionKey(key) : undefined;
        const kind = row.kind;
        if (
            !key ||
            !agentId ||
            !['direct', 'group', 'global', 'unknown'].includes(String(kind))
        ) {
            return null;
        }

        return {
            agentId,
            key,
            kind: kind as OpenClawSessionSnapshot['kind'],
            channel: this.stringValue(row.channel, 64),
            chatType: this.stringValue(row.chatType, 64),
            label: this.stringValue(row.label, 255),
            displayName: this.stringValue(row.displayName, 255),
            derivedTitle: this.stringValue(row.derivedTitle, 255),
            subject: this.stringValue(row.subject, 255),
            lastMessagePreview: this.stringValue(row.lastMessagePreview, 500),
            updatedAt: this.integerValue(row.updatedAt),
            hasActiveRun: this.booleanValue(row.hasActiveRun),
            activeRunIds: Array.isArray(row.activeRunIds)
                ? row.activeRunIds
                      .map((value) => this.identifierValue(value, 255))
                      .filter((value): value is string => value !== undefined)
                      .slice(0, 20)
                : undefined,
            spawnedBy: this.stringValue(row.spawnedBy, 255),
            subagentRole: this.stringValue(row.subagentRole, 64),
        };
    }

    private scheduleSessionSync(delay = SESSION_SYNC_INTERVAL_MS): void {
        if (this.stopped || !this.socket) {
            return;
        }
        if (this.sessionSyncTimer) {
            clearTimeout(this.sessionSyncTimer);
        }
        const socket = this.socket;
        this.sessionSyncTimer = setTimeout(() => {
            this.sessionSyncTimer = null;
            let nextDelay = SESSION_SYNC_INTERVAL_MS;
            void this.syncSessions()
                .catch((error) => {
                    nextDelay = SESSION_SYNC_RETRY_MS;
                    this.logSessionSyncFailure(error);
                })
                .finally(() => {
                    if (!this.stopped && this.socket === socket) {
                        this.scheduleSessionSync(nextDelay);
                    }
                });
        }, delay);
    }

    private logSessionSyncFailure(error: unknown): void {
        logger.warn('OpenClaw session reconciliation failed', {
            error: error instanceof Error ? error.message : String(error),
        });
    }

    private startWatchdog(): void {
        if (this.watchdogTimer) {
            clearInterval(this.watchdogTimer);
        }
        this.watchdogTimer = setInterval(() => {
            if (Date.now() - this.lastFrameAt > SOCKET_STALE_AFTER_MS) {
                this.socket?.close(4000, 'Gateway heartbeat timed out');
            }
        }, 15_000);
    }

    private clearConnectionState(socket: WebSocket): void {
        if (this.socket !== socket) {
            return;
        }
        this.rejectPending(new Error('Gateway WebSocket closed'));
        if (this.watchdogTimer) {
            clearInterval(this.watchdogTimer);
            this.watchdogTimer = null;
        }
        if (this.sessionSyncTimer) {
            clearTimeout(this.sessionSyncTimer);
            this.sessionSyncTimer = null;
        }
        this.socket = null;
    }

    private clearTimers(): void {
        if (this.flushTimer) {
            clearTimeout(this.flushTimer);
            this.flushTimer = null;
        }
        if (this.sessionSyncTimer) {
            clearTimeout(this.sessionSyncTimer);
            this.sessionSyncTimer = null;
        }
        if (this.watchdogTimer) {
            clearInterval(this.watchdogTimer);
            this.watchdogTimer = null;
        }
    }

    private rejectPending(error: Error): void {
        for (const pending of this.pending.values()) {
            clearTimeout(pending.timer);
            pending.reject(error);
        }
        this.pending.clear();
    }

    private loadCredentials(): GatewayCredentials | null {
        if (!existsSync(OPENCLAW_CONFIG_PATH)) {
            return null;
        }

        try {
            const config = JSON.parse(
                readFileSync(OPENCLAW_CONFIG_PATH, 'utf8'),
            ) as JsonRecord;
            const gateway = this.recordValue(config.gateway);
            const auth = this.recordValue(gateway?.auth);
            const token = this.stringValue(auth?.token, 4096);
            const port =
                this.integerValue(gateway?.port) ?? DEFAULT_GATEWAY_PORT;
            if (!token || port < 1 || port > 65_535) {
                return null;
            }
            return { token, port };
        } catch (error) {
            logger.warn('Could not read OpenClaw Gateway credentials', {
                error: error instanceof Error ? error.message : String(error),
            });
            return null;
        }
    }

    private waitForOpen(socket: WebSocket): Promise<void> {
        return new Promise((resolve, reject) => {
            const cleanup = () => {
                clearTimeout(timer);
                socket.removeEventListener('open', onOpen);
                socket.removeEventListener('error', onError);
                socket.removeEventListener('close', onClose);
            };
            const onOpen = () => {
                cleanup();
                resolve();
            };
            const onError = () => {
                cleanup();
                reject(new Error('Gateway WebSocket could not open'));
            };
            const onClose = () => {
                cleanup();
                reject(new Error('Gateway WebSocket closed before opening'));
            };
            const timer = setTimeout(() => {
                cleanup();
                reject(new Error('Gateway WebSocket open timed out'));
            }, 15_000);
            socket.addEventListener('open', onOpen, { once: true });
            socket.addEventListener('error', onError, { once: true });
            socket.addEventListener('close', onClose, { once: true });
        });
    }

    private hasRequiredOperatorScopes(hello: JsonRecord): boolean {
        const auth = this.recordValue(hello.auth);
        const scopes = Array.isArray(auth?.scopes)
            ? auth.scopes.filter(
                  (scope): scope is string => typeof scope === 'string',
              )
            : [];
        return (
            scopes.includes('operator.read') &&
            scopes.includes('operator.write')
        );
    }

    private agentIdFromSessionKey(sessionKey: string): string | undefined {
        const match = /^agent:([^:]+):/.exec(sessionKey);
        return match?.[1] && match[1].length <= 255 ? match[1] : undefined;
    }

    private messageText(message?: JsonRecord): string | undefined {
        if (!message) {
            return undefined;
        }
        if (typeof message.content === 'string') {
            return message.content;
        }
        if (!Array.isArray(message.content)) {
            return undefined;
        }
        const text = message.content
            .map((block) => this.recordValue(block))
            .filter((block): block is JsonRecord => Boolean(block))
            .filter(
                (block) =>
                    block.type === 'text' && typeof block.text === 'string',
            )
            .map((block) => String(block.text))
            .join('\n');
        return text || undefined;
    }

    private toolLabel(tool: string, phase?: string): string {
        const name = tool
            .replace(/[_-]+/g, ' ')
            .replace(/([a-z])([A-Z])/g, '$1 $2')
            .trim()
            .toLowerCase();
        return phase === 'result' ? `finished ${name}` : `using ${name}`;
    }

    private gatewayError(value: unknown): string {
        const error = this.recordValue(value);
        return (
            this.stringValue(error?.message, 500) ?? 'Gateway request failed'
        );
    }

    private recordValue(value: unknown): JsonRecord | undefined {
        return this.isRecord(value) ? value : undefined;
    }

    private isRecord(value: unknown): value is JsonRecord {
        return (
            typeof value === 'object' && value !== null && !Array.isArray(value)
        );
    }

    private stringValue(value: unknown, maxLength: number): string | undefined {
        if (typeof value !== 'string') {
            return undefined;
        }
        const trimmed = value.trim();
        return trimmed ? trimmed.slice(0, maxLength) : undefined;
    }

    private identifierValue(
        value: unknown,
        maxLength: number,
    ): string | undefined {
        if (typeof value !== 'string') {
            return undefined;
        }
        const trimmed = value.trim();
        return trimmed && trimmed.length <= maxLength ? trimmed : undefined;
    }

    private textValue(value: unknown, maxLength: number): string | undefined {
        return typeof value === 'string' && value.length > 0
            ? value.slice(0, maxLength)
            : undefined;
    }

    private integerValue(value: unknown): number | undefined {
        return typeof value === 'number' &&
            Number.isSafeInteger(value) &&
            value >= 0
            ? value
            : undefined;
    }

    private booleanValue(value: unknown): boolean | undefined {
        return typeof value === 'boolean' ? value : undefined;
    }

    private sleep(milliseconds: number): Promise<void> {
        return new Promise((resolve) => setTimeout(resolve, milliseconds));
    }
}
