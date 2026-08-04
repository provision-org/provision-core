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
        handle: string | null;
        harness_agent_id: string;
        harness_type: 'openclaw' | 'hermes';
        api_server_port: number;
        api_server_key: string | null;
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
        handle: string | null;
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
    work_products: Array<{
        title: string;
        file_path?: string;
        url?: string;
        type?: string;
        summary?: string;
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
    workProducts: Array<{
        title: string;
        filePath?: string;
        summary?: string;
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
export interface ChatRelayEvent {
    event: 'chat' | 'session.message' | 'session.tool' | 'sessions.changed';
    agent_id: string;
    session_key: string;
    run_id?: string;
    sequence?: number;
    state?: 'delta' | 'final' | 'aborted' | 'error';
    delta?: string;
    cumulative?: string;
    replace?: boolean;
    role?: 'user' | 'assistant';
    idempotency_key?: string;
    message_id?: string;
    message_sequence?: number;
    tool?: string;
    phase?: string;
    label?: string;
    error_kind?: 'refusal' | 'timeout' | 'rate_limit' | 'context_length' | 'unknown';
    has_active_run?: boolean;
}
export interface OpenClawSessionSnapshot {
    agentId: string;
    key: string;
    kind: 'direct' | 'group' | 'global' | 'unknown';
    channel?: string;
    chatType?: string;
    label?: string;
    displayName?: string;
    derivedTitle?: string;
    subject?: string;
    lastMessagePreview?: string;
    updatedAt?: number;
    hasActiveRun?: boolean;
    activeRunIds?: string[];
    spawnedBy?: string;
    subagentRole?: string;
}
//# sourceMappingURL=types.d.ts.map