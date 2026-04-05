/**
 * Provision Daemon type definitions.
 *
 * These types mirror the API contracts between provisiond and Provision core.
 */
export interface Config {
    apiUrl: string;
    daemonToken: string;
    serverId: string;
    pollInterval: number;
    maxConcurrent: number;
    taskTimeout: number;
    checkoutDuration: number;
}
export interface WorkQueueTask {
    id: string;
    identifier: string;
    title: string;
    description: string;
    priority: string;
    status: string;
    agent: {
        id: string;
        name: string;
        harness_agent_id: string;
        harness_type: 'openclaw' | 'hermes';
        api_server_port: number;
        org_title: string;
        manager_name: string | null;
    };
    goal: {
        id: string;
        title: string;
        parent_title: string | null;
        root_title: string | null;
    } | null;
    direct_reports: Array<{
        name: string;
        org_title: string;
        capabilities: string;
    }>;
}
export interface TaskResult {
    daemon_run_id: string;
    status: 'done' | 'in_progress' | 'blocked' | 'failed';
    result_summary: string;
    tokens_input: number;
    tokens_output: number;
    model: string;
    delegations: Array<{
        assign_to_agent_name: string;
        title: string;
        description: string;
    }>;
    approval_requests: Array<{
        type: string;
        title: string;
        description: string;
    }>;
}
export interface GatewayResponse {
    outputText: string;
    inputTokens: number;
    outputTokens: number;
    model: string;
}
export interface ParsedResponse {
    resultSummary: string;
    delegations: Array<{
        assignToAgentName: string;
        title: string;
        description: string;
    }>;
    approvalRequests: Array<{
        type: string;
        title: string;
        description: string;
    }>;
}
export interface ResolvedApproval {
    id: string;
    status: 'approved' | 'rejected' | 'revision_requested';
    linked_task_id: string | null;
    review_note: string | null;
}
export interface UsageEvent {
    agent_id: string;
    task_id?: string;
    model: string;
    input_tokens: number;
    output_tokens: number;
    source: 'daemon';
}
//# sourceMappingURL=types.d.ts.map