export type TeamRole = 'admin' | 'member';
export type CreditTransactionTypeValue =
    | 'signup_bonus'
    | 'top_up'
    | 'usage_debit'
    | 'manual_adjustment'
    | 'refund'
    | 'auto_top_up';

export type CreditWallet = {
    balance_cents: number;
    lifetime_credits_cents: number;
    lifetime_usage_cents: number;
    auto_topup_enabled: boolean;
    auto_topup_threshold_cents: number | null;
    auto_topup_amount_cents: number | null;
};

export type PaymentMethod = {
    id: string;
    brand: string;
    last_four: string;
    exp_month: number;
    exp_year: number;
};

export type CreditTransaction = {
    id: string;
    type: CreditTransactionTypeValue;
    type_label: string;
    amount_cents: number;
    balance_after_cents: number;
    description: string | null;
    created_at: string;
};

export type Team = {
    id: string;
    user_id: string;
    name: string;
    personal_team: boolean;
    company_name: string | null;
    company_url: string | null;
    company_description: string | null;
    target_market: string | null;
    created_at: string;
    updated_at: string;
    owner?: User;
    pivot?: {
        role: TeamRole;
    };
    server?: Server;
    agents?: Agent[];
    api_keys?: TeamApiKey[];
    env_vars?: TeamEnvVar[];
};

export type TeamMember = User & {
    pivot: {
        role: TeamRole;
    };
};

export type TeamInvitation = {
    id: string;
    team_id: string;
    email: string;
    role: TeamRole;
    created_at: string;
    updated_at: string;
};

export type ServerStatus =
    | 'provisioning'
    | 'setup_complete'
    | 'running'
    | 'stopped'
    | 'error'
    | 'destroying';
export type AgentStatus =
    | 'pending'
    | 'deploying'
    | 'active'
    | 'paused'
    | 'error';
export type AgentRole =
    | 'bdr'
    | 'executive_assistant'
    | 'frontend_developer'
    | 'backend_developer'
    | 'researcher'
    | 'content_writer'
    | 'customer_support'
    | 'data_analyst'
    | 'project_manager'
    | 'design_reviewer'
    | 'custom';
export type SlackConnectionStatus = 'connected' | 'disconnected' | 'error';
export type LlmProvider = 'anthropic' | 'openai' | 'open_router';

export type CloudProvider = 'hetzner' | 'digitalocean';

export type Server = {
    id: string;
    team_id: string;
    name: string;
    cloud_provider: CloudProvider;
    provider_server_id: string | null;
    ipv4_address: string | null;
    server_type: string;
    region: string;
    status: ServerStatus;
    provisioned_at: string | null;
    last_health_check: string | null;
    created_at: string;
    updated_at: string;
};

export type HarnessType = 'openclaw' | 'hermes';

export type Agent = {
    id: string;
    team_id: string;
    server_id: string | null;
    harness_type: HarnessType;
    name: string;
    role: AgentRole;
    status: AgentStatus;
    model_primary: string | null;
    model_fallbacks: string[] | null;
    system_prompt: string | null;
    identity: string | null;
    soul: string | null;
    tools_config: string | null;
    user_context: string | null;
    harness_agent_id: string | null;
    avatar_path: string | null;
    default_password: string | null;
    tools?: Array<{
        id: string;
        name: string;
        url: string | null;
        status: string;
    }>;
    is_syncing: boolean;
    last_synced_at: string | null;
    stats_total_sessions: number;
    stats_total_messages: number;
    stats_tokens_input: number;
    stats_tokens_output: number;
    stats_last_active_at: string | null;
    stats_synced_at: string | null;
    server?: Server;
    slack_connection?: AgentSlackConnection;
    email_connection?: AgentEmailConnection;
    telegram_connection?: AgentTelegramConnection;
    discord_connection?: AgentDiscordConnection;
    skills?: Skill[];
    created_at: string;
    updated_at: string;
};

export type SlackDmPolicy = 'open' | 'disabled';
export type SlackGroupPolicy = 'open' | 'disabled';
export type SlackReplyToMode = 'off' | 'first' | 'all';
export type SlackDmSessionScope = 'main' | 'per-peer';

export type AgentSlackConnection = {
    id: string;
    agent_id: string;
    slack_app_id: string | null;
    status: SlackConnectionStatus;
    allowed_channels: string[] | null;
    slack_team_id: string | null;
    slack_bot_user_id: string | null;
    is_automated: boolean;
    dm_policy: SlackDmPolicy;
    group_policy: SlackGroupPolicy;
    require_mention: boolean;
    reply_to_mode: SlackReplyToMode;
    dm_session_scope: SlackDmSessionScope;
    created_at: string;
    updated_at: string;
};

export type SlackConfigurationToken = {
    id: string;
    team_id: string;
    expires_at: string;
    created_at: string;
    updated_at: string;
};

export type AgentEmailConnection = {
    id: string;
    agent_id: string;
    email_address: string | null;
    status: string;
    created_at: string;
    updated_at: string;
};

export type AgentTelegramConnection = {
    id: string;
    agent_id: string;
    bot_username: string | null;
    bot_token_masked: string | null;
    status: 'connected' | 'disconnected' | 'error';
    created_at: string;
    updated_at: string;
};

export type AgentDiscordConnection = {
    id: string;
    agent_id: string;
    bot_username: string | null;
    token_masked: string | null;
    application_id: string | null;
    guild_id: string | null;
    status: 'connected' | 'disconnected' | 'error';
    require_mention: boolean;
    created_at: string;
    updated_at: string;
};

export type TeamApiKey = {
    id: string;
    team_id: string;
    provider: LlmProvider;
    is_active: boolean;
    masked_key: string;
    created_at: string;
    updated_at: string;
};

export type TeamEnvVar = {
    id: string;
    team_id: string;
    key: string;
    value_preview: string;
    is_secret: boolean;
    created_at: string;
    updated_at: string;
};

export type User = {
    id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    current_team_id: string | null;
    current_team?: Team;
    pronouns: string | null;
    timezone: string | null;
    profile_completed_at: string | null;
    activated_at: string | null;
    is_admin: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type AgentTemplate = {
    id: string;
    slug: string;
    name: string;
    tagline: string;
    emoji: string;
    role: AgentRole;
    avatar_path: string | null;
    system_prompt: string | null;
    recommended_tools: Array<{ name: string; url?: string }> | null;
    sort_order: number;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};

// Mission Control types

export type TaskStatus =
    | 'inbox'
    | 'up_next'
    | 'in_progress'
    | 'in_review'
    | 'done';
export type TaskPriority = 'none' | 'low' | 'medium' | 'high';
export type ActivityType =
    | 'message_received'
    | 'message_sent'
    | 'task_created'
    | 'task_completed'
    | 'task_blocked'
    | 'session_started'
    | 'session_ended'
    | 'error'
    | 'agent_hired';

export type AgentActivity = {
    id: string;
    agent_id: string;
    agent_name?: string;
    type: ActivityType;
    channel: string | null;
    summary: string;
    metadata: Record<string, unknown> | null;
    created_at: string;
};

export type Task = {
    id: string;
    team_id: string;
    agent_id: string | null;
    created_by_type: 'user' | 'agent';
    created_by_id: string;
    title: string;
    description: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    tags: string[] | null;
    sort_order: number;
    completed_at: string | null;
    agent?: Agent;
    notes?: TaskNote[];
    created_at: string;
    updated_at: string;
};

export type TaskNote = {
    id: string;
    task_id: string;
    author_type: 'user' | 'agent';
    author_id: string;
    body: string;
    created_at: string;
    updated_at: string;
};

export type TeamPack = {
    id: string;
    slug: string;
    name: string;
    tagline: string;
    description: string;
    emoji: string;
    templates?: AgentTemplate[];
};

export type CronJob = {
    id: string;
    agentId: string;
    name: string;
    description?: string;
    enabled: boolean;
    schedule: { kind: string; everyMs: number };
    payload: { kind: string; message: string };
    state: { nextRunAtMs: number };
};

// Chat types
export type ChatContentBlock =
    | { type: 'text'; text: string }
    | { type: 'image'; url: string; fileName: string; mimeType: string }
    | { type: 'file'; url: string; fileName: string; mimeType: string };

export type ChatConversation = {
    id: string;
    title: string | null;
    last_message_at: string | null;
    created_at: string;
};

export type ChatMessage = {
    id: string;
    chat_conversation_id: string;
    role: 'user' | 'assistant';
    content: ChatContentBlock[];
    sent_at: string;
};

export type Skill = {
    id: string;
    name: string;
    slug: string;
    version: string | null;
    description: string | null;
    tags: string[] | null;
    requires: { bins?: string[]; env?: string[] } | null;
    steps: string[] | null;
    parameters: Record<string, unknown>[] | null;
    skill_content: string | null;
    readme: string | null;
    visibility: 'public' | 'private';
    is_active: boolean;
    downloads: number;
    team_id: string | null;
    author_id: string | null;
    author?: { id: string; name: string };
    pivot?: {
        installed_version: string | null;
        installed_at: string | null;
    };
    created_at: string;
    updated_at: string;
};

export type AgentSession = {
    session_id: string;
    inputTokens: number;
    outputTokens: number;
    updatedAt: string;
    sessionFile: string;
};

export type SessionMessage = {
    role: string;
    content: string;
    timestamp: string;
};
