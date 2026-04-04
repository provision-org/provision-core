import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bot,
    KanbanSquare,
    LayoutDashboard,
    Library,
    Moon,
    Network,
    Plus,
    PlusCircle,
    Puzzle,
    ScrollText,
    ShieldCheck as ShieldCheckIcon,
    Sun,
    Target,
    Wallet,
} from 'lucide-react';
import { NavUser } from '@/components/nav-user';
import { TeamAvatar } from '@/components/team-avatar';
import { TeamSwitcher } from '@/components/team-switcher';
import { Button } from '@/components/ui/button';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAppearance } from '@/hooks/use-appearance';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useIsMobile } from '@/hooks/use-mobile';
import type { NavItem, SharedData } from '@/types';

const platformNavItems: NavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { title: 'My Agents', href: '/agents', icon: Bot },
    { title: 'Task Board', href: '/governance/tasks', icon: KanbanSquare },
];

const exploreNavItems: NavItem[] = [
    { title: 'Agent Library', href: '/agents/library', icon: Library },
];

const companyNavItems: NavItem[] = [
    { title: 'Org Chart', href: '/governance/org', icon: Network },
    { title: 'Goals', href: '/governance/goals', icon: Target },
    {
        title: 'Approvals',
        href: '/governance/approvals',
        icon: ShieldCheckIcon,
    },
    { title: 'Usage', href: '/governance/usage', icon: BarChart3 },
    { title: 'Audit Log', href: '/governance/audit', icon: ScrollText },
];

const trainingNavItems: NavItem[] = [
    { title: 'Your Skills', href: '/skills', icon: Puzzle },
    { title: 'Create New Skill', href: '/skills/create', icon: PlusCircle },
];

function NavSection({ label, items }: { label: string; items: NavItem[] }) {
    const { currentUrl, isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu className="gap-0.5">
                {items.map((item) => {
                    const exactMatch = isCurrentUrl(item.href);
                    const prefixMatch = currentUrl.startsWith(item.href + '/');
                    const moreSpecificExists =
                        prefixMatch &&
                        !exactMatch &&
                        items.some(
                            (other) =>
                                other !== item &&
                                currentUrl.startsWith(other.href) &&
                                other.href.startsWith(item.href + '/'),
                        );
                    const active =
                        exactMatch || (prefixMatch && !moreSpecificExists);

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={active}
                                tooltip={{ children: item.title }}
                                className="h-9 px-3"
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}

function CreditWidget() {
    const { wallet } = usePage<SharedData>().props;

    if (!wallet) return null;

    const balance = (wallet.balance_cents / 100).toFixed(2);
    const remainingPercent =
        wallet.lifetime_credits_cents > 0
            ? Math.round(
                  ((wallet.lifetime_credits_cents -
                      wallet.lifetime_usage_cents) /
                      wallet.lifetime_credits_cents) *
                      100,
              )
            : 0;

    return (
        <SidebarGroup className="px-2 pb-0 group-data-[collapsible=icon]:hidden">
            <Link
                href="/billing"
                className="block rounded-lg border border-sidebar-border/60 p-3 transition-colors hover:bg-sidebar-accent/50"
            >
                <div className="flex items-center gap-2 text-xs font-medium text-sidebar-foreground/70">
                    <Wallet className="size-3.5" />
                    <span>Credits</span>
                </div>
                <div className="mt-1 text-lg font-semibold text-sidebar-foreground">
                    ${balance}
                </div>
                <div className="mt-1.5">
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-sidebar-accent">
                        <div
                            className="h-full rounded-full bg-sidebar-primary transition-all"
                            style={{
                                width: `${Math.max(remainingPercent, 0)}%`,
                            }}
                        />
                    </div>
                    <div className="mt-1 text-[10px] text-sidebar-foreground/50">
                        {remainingPercent}% remaining
                    </div>
                </div>
            </Link>
        </SidebarGroup>
    );
}

function AppearanceToggle() {
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const isDark = resolvedAppearance === 'dark';

    return (
        <button
            type="button"
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
            className="flex size-7 items-center justify-center rounded-md text-sidebar-foreground/40 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground/70"
            aria-label="Toggle theme"
        >
            {isDark ? (
                <Sun className="size-3.5" />
            ) : (
                <Moon className="size-3.5" />
            )}
        </button>
    );
}

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const currentTeam = auth.user.current_team;
    const isMobile = useIsMobile();

    return (
        <Sidebar collapsible="icon" className="left-[52px]">
            <SidebarHeader className="pb-3">
                {isMobile ? (
                    <TeamSwitcher />
                ) : (
                    <Link
                        href="/agents"
                        className="flex items-center gap-2.5 px-1 py-1 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0"
                    >
                        <TeamAvatar
                            name={currentTeam?.name ?? 'T'}
                            size={32}
                            className="shrink-0"
                        />
                        <span className="truncate text-sm font-semibold text-sidebar-foreground group-data-[collapsible=icon]:hidden">
                            {currentTeam?.name ?? 'Select Team'}
                        </span>
                    </Link>
                )}
            </SidebarHeader>

            <div className="mx-3 mb-3 h-px bg-sidebar-border" />

            <SidebarContent>
                <div className="px-4 py-2 group-data-[collapsible=icon]:px-2 group-data-[collapsible=icon]:py-0">
                    <Button
                        asChild
                        size="sm"
                        className="w-full justify-start gap-2 rounded-lg shadow-sm transition-transform group-data-[collapsible=icon]:w-auto group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 hover:scale-[1.02]"
                    >
                        <Link href="/agents/create">
                            <Plus className="size-4 shrink-0" />
                            <span className="group-data-[collapsible=icon]:hidden">
                                Deploy Agent
                            </span>
                        </Link>
                    </Button>
                </div>
                <NavSection label="Platform" items={platformNavItems} />
                <NavSection label="Company" items={companyNavItems} />
                <NavSection label="Explore" items={exploreNavItems} />
                {(usePage().props as Record<string, unknown>).modules &&
                    (
                        (usePage().props as Record<string, unknown>)
                            .modules as Record<string, boolean>
                    )?.skills && (
                        <NavSection label="Training" items={trainingNavItems} />
                    )}
            </SidebarContent>

            <SidebarFooter>
                <CreditWidget />
                {auth.user.is_admin && (
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild size="sm">
                                <Link href="/admin/dashboard" prefetch>
                                    <ShieldCheckIcon className="size-4" />
                                    <span>Admin Panel</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                )}
                <div className="flex items-center justify-between">
                    <div className="flex-1">
                        <NavUser />
                    </div>
                    <div className="shrink-0 pr-1 group-data-[collapsible=icon]:hidden">
                        <AppearanceToggle />
                    </div>
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
