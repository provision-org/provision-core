import { router, usePage } from '@inertiajs/react';
import { ChevronsUpDown, Plus } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import type { SharedData } from '@/types';

export function TeamSwitcher() {
    const { auth, teams } = usePage<SharedData>().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    const currentTeam = auth.user.current_team;

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                        >
                            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                                <span className="text-xs font-semibold">
                                    {currentTeam?.name
                                        ?.charAt(0)
                                        .toUpperCase() ?? 'T'}
                                </span>
                            </div>
                            <div className="grid flex-1 text-left text-sm leading-tight">
                                <span className="truncate font-medium">
                                    {currentTeam?.name ?? 'Select Team'}
                                </span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'bottom'
                        }
                    >
                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                            Teams
                        </DropdownMenuLabel>
                        {teams?.map((team) => (
                            <DropdownMenuItem
                                key={team.id}
                                onClick={() => {
                                    if (team.id !== auth.user.current_team_id) {
                                        router.put(
                                            '/settings/current-team',
                                            { team_id: team.id },
                                            { preserveState: false },
                                        );
                                    }
                                }}
                                className={
                                    team.id === auth.user.current_team_id
                                        ? 'bg-accent'
                                        : ''
                                }
                            >
                                <div className="flex size-6 items-center justify-center rounded-sm border">
                                    <span className="text-xs font-semibold">
                                        {team.name.charAt(0).toUpperCase()}
                                    </span>
                                </div>
                                {team.name}
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onClick={() => {
                                router.get('/settings/teams/create');
                            }}
                        >
                            <div className="flex size-6 items-center justify-center rounded-md border bg-background">
                                <Plus className="size-4" />
                            </div>
                            Create Team
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
