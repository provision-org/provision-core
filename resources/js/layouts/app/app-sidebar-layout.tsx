import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { TeamIconRail } from '@/components/team-icon-rail';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <TeamIconRail />
            <div className="hidden w-[52px] shrink-0 md:block" />
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className="flex h-svh max-h-svh flex-col overflow-hidden"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
