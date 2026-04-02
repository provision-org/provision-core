import { router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { TeamAvatar } from '@/components/team-avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { SharedData } from '@/types';

export function TeamIconRail() {
    const { auth, teams } = usePage<SharedData>().props;
    const currentTeamId = auth.user.current_team_id;

    return (
        <div className="fixed inset-y-0 left-0 z-20 hidden w-[52px] flex-col items-center border-r border-sidebar-border bg-sidebar py-3 md:flex">
            {/* App logo */}
            <div className="mb-4 flex items-center justify-center">
                <div className="flex size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                    <AppLogoIcon className="size-[18px] fill-current text-white dark:text-black" />
                </div>
            </div>

            <div className="mx-auto mb-3 h-px w-6 bg-sidebar-border" />

            {/* Team avatars */}
            <div className="flex flex-1 flex-col items-center gap-2 overflow-y-auto">
                <TooltipProvider delayDuration={0}>
                    {teams?.map((team) => {
                        const isActive = team.id === currentTeamId;
                        return (
                            <Tooltip key={team.id}>
                                <TooltipTrigger asChild>
                                    <button
                                        onClick={() => {
                                            if (!isActive) {
                                                router.put(
                                                    '/settings/current-team',
                                                    { team_id: team.id },
                                                    { preserveState: false },
                                                );
                                            }
                                        }}
                                        className={`relative transition-all duration-200 ${
                                            isActive
                                                ? 'scale-100'
                                                : 'scale-[0.85] opacity-50 hover:scale-95 hover:opacity-80'
                                        }`}
                                    >
                                        <TeamAvatar
                                            name={team.name}
                                            size={36}
                                        />
                                        {isActive && (
                                            <span className="absolute top-1/2 -left-[10px] h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-sidebar-primary" />
                                        )}
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent side="right" sideOffset={8}>
                                    {team.name}
                                </TooltipContent>
                            </Tooltip>
                        );
                    })}
                </TooltipProvider>
            </div>

            {/* Add team button */}
            <div className="mt-3">
                <TooltipProvider delayDuration={0}>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <button
                                onClick={() =>
                                    router.get('/settings/teams/create')
                                }
                                className="flex size-8 items-center justify-center rounded-lg border border-dashed border-sidebar-border text-sidebar-foreground/40 transition-colors hover:border-sidebar-foreground/30 hover:text-sidebar-foreground/60"
                            >
                                <Plus className="size-4" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="right" sideOffset={8}>
                            Create team
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
        </div>
    );
}
