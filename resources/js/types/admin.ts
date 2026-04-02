import type {
    Agent,
    AgentStatus,
    Server,
    ServerStatus,
    Team,
    User,
} from './auth';

export type AdminStats = {
    total_users: number;
    activated_users: number;
    waitlisted_users: number;
    total_teams: number;
    total_agents: number;
    agents_by_status: Partial<Record<AgentStatus, number>>;
    total_servers: number;
    servers_by_status: Partial<Record<ServerStatus, number>>;
};

export type AdminUser = User & {
    teams_count: number;
};

export type AdminTeam = Team & {
    members_count: number;
    agents_count: number;
    owner: Pick<User, 'id' | 'name' | 'email'>;
    server?: Pick<Server, 'id' | 'status'> | null;
    credit_wallet?: { balance_cents: number } | null;
};

export type AdminAgent = Agent & {
    team: Pick<Team, 'id' | 'name' | 'user_id'> & {
        owner: Pick<User, 'id' | 'name'>;
    };
};

export type AdminServer = Server & {
    team: Pick<Team, 'id' | 'name'>;
    agents_count: number;
};

export type PaginatedData<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
};
