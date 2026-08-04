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
export class OpenClawGatewayRelay {
    config;
    api;
    socket = null;
    stopped = false;
    runner = null;
    pending = new Map();
    eventQueue = [];
    flushTimer = null;
    flushInProgress = false;
    sessionSyncTimer = null;
    watchdogTimer = null;
    lastFrameAt = 0;
    lastOuterSequence = null;
    reconnectAttempt = 0;
    challengeNonce = null;
    challengeRejecter = null;
    challengeTimer = null;
    constructor(config, api) {
        this.config = config;
        this.api = api;
    }
    start() {
        if (this.runner) {
            return;
        }
        this.stopped = false;
        this.runner = this.run().finally(() => {
            this.runner = null;
        });
    }
    stop() {
        this.stopped = true;
        this.clearTimers();
        this.cancelChallengeWait(new Error('Gateway relay stopped'));
        this.rejectPending(new Error('Gateway relay stopped'));
        this.socket?.close(1000, 'provisiond stopping');
        this.socket = null;
    }
    async run() {
        while (!this.stopped) {
            try {
                const credentials = this.loadCredentials();
                if (!credentials) {
                    await this.sleep(30_000);
                    continue;
                }
                await this.connectAndRun(credentials);
                this.reconnectAttempt = 0;
            }
            catch (error) {
                if (this.stopped) {
                    return;
                }
                logger.warn('OpenClaw chat relay disconnected', {
                    error: error instanceof Error ? error.message : String(error),
                });
                const baseDelay = Math.min(30_000, 1000 * 2 ** this.reconnectAttempt);
                this.reconnectAttempt = Math.min(this.reconnectAttempt + 1, 5);
                const jitter = Math.floor(Math.random() * Math.max(250, baseDelay / 4));
                await this.sleep(baseDelay + jitter);
            }
        }
    }
    async connectAndRun(credentials) {
        this.lastOuterSequence = null;
        this.lastFrameAt = Date.now();
        this.challengeNonce = null;
        const socket = new WebSocket(`ws://127.0.0.1:${credentials.port}`);
        this.socket = socket;
        const closed = new Promise((resolve) => {
            socket.addEventListener('close', () => {
                if (this.socket === socket) {
                    this.cancelChallengeWait(new Error('Gateway WebSocket closed'));
                    this.rejectPending(new Error('Gateway WebSocket closed'));
                }
                resolve();
            }, { once: true });
            socket.addEventListener('error', () => {
                try {
                    socket.close();
                }
                catch {
                    // The connection may already be closing.
                }
            }, { once: true });
        });
        socket.addEventListener('message', (event) => this.handleMessage(event, socket));
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
                    throw new Error('Gateway WebSocket closed before challenge');
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
                scopes: ['operator.read', 'operator.write'],
                caps: [],
                commands: [],
                permissions: {},
                auth: { token: credentials.token },
                // The reserved direct-loopback backend path intentionally omits device.
            });
            if (hello.type !== 'hello-ok' ||
                hello.protocol !== 4 ||
                !this.hasRequiredOperatorScopes(hello)) {
                throw new Error('Gateway returned an incompatible or under-scoped handshake');
            }
            await this.request('sessions.subscribe', {});
            this.reconnectAttempt = 0;
            logger.info('OpenClaw chat relay connected', {
                gatewayVersion: this.stringValue(this.recordValue(hello.server)?.version, 32),
            });
            this.startWatchdog();
            let nextSessionSyncDelay = SESSION_SYNC_INTERVAL_MS;
            try {
                await this.syncSessions();
            }
            catch (error) {
                nextSessionSyncDelay = SESSION_SYNC_RETRY_MS;
                this.logSessionSyncFailure(error);
            }
            this.scheduleSessionSync(nextSessionSyncDelay);
            await closed;
            if (!this.stopped) {
                throw new Error('Gateway WebSocket closed');
            }
        }
        finally {
            this.cancelChallengeWait(new Error('Gateway connection attempt ended'));
            if (socket.readyState === WebSocket.CONNECTING ||
                socket.readyState === WebSocket.OPEN) {
                try {
                    socket.close(1000, 'Gateway connection attempt ended');
                }
                catch {
                    // The connection may already be closing.
                }
            }
            this.clearConnectionState(socket);
        }
    }
    handleMessage(event, sourceSocket) {
        if (sourceSocket && sourceSocket !== this.socket) {
            return;
        }
        if (typeof event.data !== 'string' ||
            event.data.length > MAX_INBOUND_FRAME_CHARS) {
            this.socket?.close(1009, 'Gateway frame too large');
            return;
        }
        let frame;
        try {
            frame = JSON.parse(event.data);
        }
        catch {
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
            }
            else {
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
                }
                else {
                    this.challengeNonce = nonce;
                }
            }
            return;
        }
        const outerSequence = this.integerValue(frame.seq);
        if (outerSequence !== undefined &&
            this.lastOuterSequence !== null &&
            outerSequence <= this.lastOuterSequence) {
            logger.warn('OpenClaw relay received an out-of-order frame; scheduling reconciliation', {
                previous: this.lastOuterSequence,
                received: outerSequence,
            });
            this.scheduleSessionSync(0);
            return;
        }
        if (outerSequence !== undefined &&
            this.lastOuterSequence !== null &&
            outerSequence !== this.lastOuterSequence + 1) {
            logger.warn('OpenClaw relay sequence gap; scheduling reconciliation', {
                expected: this.lastOuterSequence + 1,
                received: outerSequence,
            });
            this.scheduleSessionSync(0);
        }
        if (outerSequence !== undefined) {
            this.lastOuterSequence = outerSequence;
        }
        const normalized = this.normalizeEvent(frame.event, this.recordValue(frame.payload));
        if (normalized) {
            this.enqueueEvent(normalized);
        }
        if (frame.event === 'sessions.changed') {
            this.scheduleSessionSync(1000);
        }
        else if (frame.event === 'shutdown') {
            this.socket?.close(1012, 'Gateway restarting');
        }
    }
    challengeResolver = null;
    waitForChallenge() {
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
    cancelChallengeWait(error) {
        const rejecter = this.challengeRejecter;
        if (this.challengeTimer) {
            clearTimeout(this.challengeTimer);
            this.challengeTimer = null;
        }
        this.challengeResolver = null;
        this.challengeRejecter = null;
        rejecter?.(error);
    }
    request(method, params) {
        const socket = this.socket;
        if (!socket || socket.readyState !== WebSocket.OPEN) {
            return Promise.reject(new Error('Gateway WebSocket is not connected'));
        }
        const id = randomUUID();
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.pending.delete(id);
                reject(new Error(`Gateway ${method} request timed out`));
            }, 20_000);
            this.pending.set(id, { resolve, reject, timer });
            try {
                socket.send(JSON.stringify({ type: 'req', id, method, params }));
            }
            catch (error) {
                clearTimeout(timer);
                this.pending.delete(id);
                reject(error instanceof Error ? error : new Error(String(error)));
            }
        });
    }
    normalizeEvent(event, payload) {
        if (!payload) {
            return null;
        }
        const sessionKey = this.identifierValue(payload.sessionKey, MAX_SESSION_KEY_LENGTH);
        const sessionAgentId = sessionKey
            ? this.agentIdFromSessionKey(sessionKey)
            : undefined;
        const explicitAgentId = this.identifierValue(payload.agentId, 255);
        if (explicitAgentId &&
            sessionAgentId &&
            explicitAgentId !== sessionAgentId) {
            return null;
        }
        const agentId = explicitAgentId ?? sessionAgentId;
        if (!sessionKey || !agentId) {
            return null;
        }
        if (event === 'chat') {
            const state = payload.state;
            const runId = this.identifierValue(payload.runId, 255);
            if (!runId ||
                !['delta', 'final', 'aborted', 'error'].includes(String(state))) {
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
                ? payload.errorKind
                : undefined;
            return {
                event: 'chat',
                agent_id: agentId,
                session_key: sessionKey,
                run_id: runId,
                sequence: this.integerValue(payload.seq),
                state: state,
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
                idempotency_key: this.identifierValue(message?.idempotencyKey, 255) ??
                    this.identifierValue(metadata?.idempotencyKey, 255),
                message_id: this.identifierValue(payload.messageId, 255) ??
                    this.identifierValue(metadata?.id, 255),
                message_sequence: this.integerValue(payload.messageSeq) ??
                    this.integerValue(metadata?.seq),
                has_active_run: this.booleanValue(payload.hasActiveRun) ??
                    this.booleanValue(session?.hasActiveRun),
            };
        }
        if (event === 'session.tool' ||
            (event === 'agent' && payload.stream === 'tool')) {
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
                run_id: this.identifierValue(payload.clientRunId, 255) ??
                    this.identifierValue(payload.runId, 255),
                has_active_run: this.booleanValue(payload.hasActiveRun) ??
                    this.booleanValue(session?.hasActiveRun),
            };
        }
        return null;
    }
    enqueueEvent(event) {
        if (event.event === 'chat' &&
            event.state === 'delta' &&
            this.coalesceChatDelta(event)) {
            return;
        }
        if (this.eventQueue.length >= MAX_EVENT_QUEUE) {
            const deltaIndex = this.eventQueue.findIndex((candidate) => candidate.event === 'chat' && candidate.state === 'delta');
            if (deltaIndex >= 0) {
                this.eventQueue.splice(deltaIndex, 1);
            }
            else if (event.event === 'chat' && event.state === 'delta') {
                this.scheduleSessionSync(0);
                return;
            }
        }
        this.eventQueue.push(event);
        if (this.eventQueue.length > MAX_CRITICAL_EVENT_QUEUE) {
            this.socket?.close(1013, 'Gateway event relay overloaded');
        }
        this.scheduleEventFlush(this.eventQueue.length >= MAX_EVENT_QUEUE
            ? 0
            : EVENT_FLUSH_DELAY_MS);
    }
    coalesceChatDelta(event) {
        for (let index = this.eventQueue.length - 1; index >= 0; index--) {
            const candidate = this.eventQueue[index];
            if (candidate.agent_id !== event.agent_id ||
                candidate.session_key !== event.session_key) {
                continue;
            }
            if (candidate.event !== 'chat' ||
                candidate.state !== 'delta' ||
                candidate.run_id !== event.run_id) {
                return false;
            }
            if (candidate.sequence !== undefined &&
                event.sequence !== undefined &&
                event.sequence < candidate.sequence) {
                return true;
            }
            const baseText = candidate.cumulative ?? candidate.delta ?? '';
            const cumulative = event.cumulative ??
                (event.replace === true
                    ? event.delta
                    : `${baseText}${event.delta ?? ''}`);
            this.eventQueue[index] = {
                ...candidate,
                ...event,
                cumulative: cumulative
                    ? cumulative.slice(0, 200_000)
                    : undefined,
                sequence: candidate.sequence === undefined
                    ? event.sequence
                    : event.sequence === undefined
                        ? candidate.sequence
                        : Math.max(candidate.sequence, event.sequence),
            };
            return true;
        }
        return false;
    }
    scheduleEventFlush(delay) {
        if (this.flushTimer || this.flushInProgress) {
            return;
        }
        this.flushTimer = setTimeout(() => {
            this.flushTimer = null;
            void this.flushEvents();
        }, delay);
    }
    async flushEvents() {
        if (this.eventQueue.length === 0) {
            return;
        }
        this.flushInProgress = true;
        const batch = this.takeEventBatch();
        let retryDelay = 0;
        try {
            await this.api.reportChatEvents(batch);
        }
        catch (error) {
            this.eventQueue.unshift(...batch);
            retryDelay = 1000;
            logger.warn('Could not forward OpenClaw chat events', {
                error: error instanceof Error ? error.message : String(error),
            });
        }
        finally {
            this.flushInProgress = false;
        }
        if (this.eventQueue.length > 0) {
            this.scheduleEventFlush(retryDelay);
        }
    }
    takeEventBatch() {
        const batch = [];
        let bytes = 0;
        while (batch.length < 100 && this.eventQueue.length > 0) {
            const event = this.eventQueue[0];
            const eventBytes = Buffer.byteLength(JSON.stringify(event), 'utf8') + 1;
            if (batch.length > 0 &&
                bytes + eventBytes > MAX_EVENT_BATCH_BYTES) {
                break;
            }
            batch.push(event);
            this.eventQueue.shift();
            bytes += eventBytes;
        }
        return batch;
    }
    async syncSessions() {
        const snapshots = [];
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
            const nextOffset = this.integerValue(result.nextOffset);
            if (nextOffset === undefined ||
                result.hasMore !== true ||
                nextOffset <= offset) {
                break;
            }
            offset = nextOffset;
        }
        for (let index = 0; index < snapshots.length; index += 100) {
            await this.api.syncOpenClawSessions(snapshots.slice(index, index + 100));
        }
    }
    sessionSnapshot(row) {
        const key = this.identifierValue(row.key, MAX_SESSION_KEY_LENGTH);
        const agentId = key ? this.agentIdFromSessionKey(key) : undefined;
        const kind = row.kind;
        if (!key ||
            !agentId ||
            !['direct', 'group', 'global', 'unknown'].includes(String(kind))) {
            return null;
        }
        return {
            agentId,
            key,
            kind: kind,
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
                    .filter((value) => value !== undefined)
                    .slice(0, 20)
                : undefined,
            spawnedBy: this.stringValue(row.spawnedBy, 255),
            subagentRole: this.stringValue(row.subagentRole, 64),
        };
    }
    scheduleSessionSync(delay = SESSION_SYNC_INTERVAL_MS) {
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
    logSessionSyncFailure(error) {
        logger.warn('OpenClaw session reconciliation failed', {
            error: error instanceof Error ? error.message : String(error),
        });
    }
    startWatchdog() {
        if (this.watchdogTimer) {
            clearInterval(this.watchdogTimer);
        }
        this.watchdogTimer = setInterval(() => {
            if (Date.now() - this.lastFrameAt > SOCKET_STALE_AFTER_MS) {
                this.socket?.close(1001, 'Gateway heartbeat timed out');
            }
        }, 15_000);
    }
    clearConnectionState(socket) {
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
    clearTimers() {
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
    rejectPending(error) {
        for (const pending of this.pending.values()) {
            clearTimeout(pending.timer);
            pending.reject(error);
        }
        this.pending.clear();
    }
    loadCredentials() {
        if (!existsSync(OPENCLAW_CONFIG_PATH)) {
            return null;
        }
        try {
            const config = JSON.parse(readFileSync(OPENCLAW_CONFIG_PATH, 'utf8'));
            const gateway = this.recordValue(config.gateway);
            const auth = this.recordValue(gateway?.auth);
            const token = this.stringValue(auth?.token, 4096);
            const port = this.integerValue(gateway?.port) ?? DEFAULT_GATEWAY_PORT;
            if (!token || port < 1 || port > 65_535) {
                return null;
            }
            return { token, port };
        }
        catch (error) {
            logger.warn('Could not read OpenClaw Gateway credentials', {
                error: error instanceof Error ? error.message : String(error),
            });
            return null;
        }
    }
    waitForOpen(socket) {
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
    hasRequiredOperatorScopes(hello) {
        const auth = this.recordValue(hello.auth);
        const scopes = Array.isArray(auth?.scopes)
            ? auth.scopes.filter((scope) => typeof scope === 'string')
            : [];
        return (scopes.includes('operator.read') &&
            scopes.includes('operator.write'));
    }
    agentIdFromSessionKey(sessionKey) {
        const match = /^agent:([^:]+):/.exec(sessionKey);
        return match?.[1] && match[1].length <= 255 ? match[1] : undefined;
    }
    messageText(message) {
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
            .filter((block) => Boolean(block))
            .filter((block) => block.type === 'text' && typeof block.text === 'string')
            .map((block) => String(block.text))
            .join('\n');
        return text || undefined;
    }
    toolLabel(tool, phase) {
        const name = tool
            .replace(/[_-]+/g, ' ')
            .replace(/([a-z])([A-Z])/g, '$1 $2')
            .trim()
            .toLowerCase();
        return phase === 'result' ? `finished ${name}` : `using ${name}`;
    }
    gatewayError(value) {
        const error = this.recordValue(value);
        return (this.stringValue(error?.message, 500) ?? 'Gateway request failed');
    }
    recordValue(value) {
        return this.isRecord(value) ? value : undefined;
    }
    isRecord(value) {
        return (typeof value === 'object' && value !== null && !Array.isArray(value));
    }
    stringValue(value, maxLength) {
        if (typeof value !== 'string') {
            return undefined;
        }
        const trimmed = value.trim();
        return trimmed ? trimmed.slice(0, maxLength) : undefined;
    }
    identifierValue(value, maxLength) {
        if (typeof value !== 'string') {
            return undefined;
        }
        const trimmed = value.trim();
        return trimmed && trimmed.length <= maxLength ? trimmed : undefined;
    }
    textValue(value, maxLength) {
        return typeof value === 'string' && value.length > 0
            ? value.slice(0, maxLength)
            : undefined;
    }
    integerValue(value) {
        return typeof value === 'number' &&
            Number.isSafeInteger(value) &&
            value >= 0
            ? value
            : undefined;
    }
    booleanValue(value) {
        return typeof value === 'boolean' ? value : undefined;
    }
    sleep(milliseconds) {
        return new Promise((resolve) => setTimeout(resolve, milliseconds));
    }
}
//# sourceMappingURL=openclaw-gateway-relay.js.map