/**
 * Persistent, server-local OpenClaw Gateway relay.
 *
 * The daemon authenticates through OpenClaw's reserved direct-loopback
 * `gateway-client` backend path. Gateway credentials never leave the machine;
 * only bounded chat lifecycle fields and allowlisted session metadata are
 * forwarded through the daemon-authenticated Provision API.
 */
import { ProvisionApiClient } from './provision-api.js';
import type { Config } from './types.js';
export declare class OpenClawGatewayRelay {
    private readonly config;
    private readonly api;
    private socket;
    private stopped;
    private runner;
    private pending;
    private eventQueue;
    private flushTimer;
    private flushInProgress;
    private sessionSyncTimer;
    private watchdogTimer;
    private lastFrameAt;
    private lastOuterSequence;
    private reconnectAttempt;
    private challengeNonce;
    private challengeRejecter;
    private challengeTimer;
    constructor(config: Config, api: ProvisionApiClient);
    start(): void;
    stop(): void;
    private run;
    private connectAndRun;
    private handleMessage;
    private challengeResolver;
    private waitForChallenge;
    private cancelChallengeWait;
    isConnected(): boolean;
    /**
     * Fire a chat.send over the relay's authenticated loopback socket —
     * the fast-send path. The gateway acks with {runId, status}; streaming
     * output arrives via the normal broadcast events this relay already
     * forwards.
     */
    sendChat(params: {
        sessionKey: string;
        agentId: string;
        message: string;
        idempotencyKey: string;
    }): Promise<{
        runId: string | null;
        status: string | null;
    }>;
    private request;
    private normalizeEvent;
    private enqueueEvent;
    private coalesceChatDelta;
    private scheduleEventFlush;
    private flushEvents;
    private takeEventBatch;
    private syncSessions;
    private sessionSnapshot;
    private scheduleSessionSync;
    private logSessionSyncFailure;
    private startWatchdog;
    private clearConnectionState;
    private clearTimers;
    private rejectPending;
    private loadCredentials;
    private waitForOpen;
    private hasRequiredOperatorScopes;
    private agentIdFromSessionKey;
    private messageText;
    private toolLabel;
    private gatewayError;
    private recordValue;
    private isRecord;
    private stringValue;
    private identifierValue;
    private textValue;
    private integerValue;
    private booleanValue;
    private sleep;
}
//# sourceMappingURL=openclaw-gateway-relay.d.ts.map