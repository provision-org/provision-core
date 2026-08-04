/**
 * HTTP client for Provision's daemon API.
 *
 * All endpoints are server-scoped under /api/daemon/servers/{serverId}.
 * The daemon token is sent only through the Authorization header.
 * Uses Node's built-in fetch API (Node 22+).
 */
import type { ChatRelayEvent, Config, OpenClawSessionSnapshot, WorkQueueTask, TaskResult, ResolvedApproval, UsageEvent } from './types.js';
export declare class ProvisionApiClient {
    private readonly baseUrl;
    private readonly token;
    constructor(config: Config);
    getWorkQueue(): Promise<WorkQueueTask[]>;
    checkoutTask(taskId: string, runId: string): Promise<{
        ok: boolean;
        task?: WorkQueueTask;
    }>;
    reportResult(taskId: string, result: TaskResult): Promise<void>;
    releaseTask(taskId: string, runId: string, reason?: string): Promise<void>;
    getResolvedApprovals(): Promise<ResolvedApproval[]>;
    reportUsage(event: UsageEvent): Promise<void>;
    postNote(taskId: string, body: string): Promise<void>;
    sendHeartbeat(activeRuns: string[], version?: string, capabilities?: string[]): Promise<void>;
    reportChatEvents(events: ChatRelayEvent[]): Promise<void>;
    syncOpenClawSessions(sessions: OpenClawSessionSnapshot[]): Promise<void>;
    private request;
}
//# sourceMappingURL=provision-api.d.ts.map