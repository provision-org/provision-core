export type * from './admin';
export type * from './auth';
export type * from './navigation';
export type * from './ui';

import type { Auth, CreditWallet, Team } from './auth';

export type SharedData = {
    name: string;
    auth: Auth;
    teams: Team[];
    sidebarOpen: boolean;
    wallet: CreditWallet | null;
    [key: string]: unknown;
};
