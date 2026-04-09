/**
 * HTTP client for Provision's daemon API.
 *
 * All endpoints are prefixed with /api/daemon/{token}/.
 * Uses Node's built-in fetch API (Node 22+).
 */
import type { Config, WorkQueueTask, TaskResult, ResolvedApproval, UsageEvent } from './types.js';
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
    sendHeartbeat(activeRuns: string[]): Promise<void>;
    private request;
}
//# sourceMappingURL=provision-api.d.ts.map