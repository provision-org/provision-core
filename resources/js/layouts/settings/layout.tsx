import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import type { NavItem, SharedData } from '@/types';

type SettingsNavSection = {
    title: string;
    /** When true, render items without the section heading text. */
    hideHeading?: boolean;
    tone?: 'default' | 'danger';
    items: NavItem[];
};

export default function SettingsLayout({ children }: PropsWithChildren) {
    const page = usePage<SharedData>();
    const { auth } = page.props;
    const modules = (page.props.modules ?? {}) as Record<string, unknown>;
    const { isCurrentUrl } = useCurrentUrl();

    const currentTeamId = auth.user.current_team_id;
    // Email domain is provided by the MailboxKit module — gate the link on it.
    const emailDomainAvailable = 'emailDomain' in modules;

    const teamItems: NavItem[] = currentTeamId
        ? [
              {
                  title: 'General',
                  href: `/settings/teams/${currentTeamId}`,
                  icon: null,
              },
              {
                  title: 'Slack',
                  href: `/settings/teams/${currentTeamId}/slack-config`,
                  icon: null,
              },
              ...(emailDomainAvailable
                  ? [
                        {
                            title: 'Email domain',
                            href: `/settings/teams/${currentTeamId}/email-domain`,
                            icon: null,
                        } as NavItem,
                    ]
                  : []),
          ]
        : [];

    const sections: SettingsNavSection[] = [
        {
            title: 'Account',
            items: [
                { title: 'Profile', href: edit(), icon: null },
                { title: 'Appearance', href: editAppearance(), icon: null },
            ],
        },
        {
            title: 'Security',
            items: [
                { title: 'Password', href: editPassword(), icon: null },
                { title: 'Two-Factor Auth', href: show(), icon: null },
                { title: 'API Tokens', href: '/settings/api', icon: null },
            ],
        },
        ...(teamItems.length > 0
            ? [
                  {
                      title: 'Team settings',
                      items: teamItems,
                  } as SettingsNavSection,
              ]
            : []),
        {
            title: 'Danger zone',
            hideHeading: true,
            tone: 'danger',
            items: [
                {
                    title: 'Danger zone',
                    href: '/settings/danger-zone',
                    icon: null,
                },
            ],
        },
    ];

    // When server-side rendering, we only render the layout on the client...
    if (typeof window === 'undefined') {
        return null;
    }

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-56">
                    <nav
                        className="flex flex-col space-y-6"
                        aria-label="Settings"
                    >
                        {sections.map((section) => (
                            <div
                                key={section.title}
                                className="flex flex-col space-y-1"
                            >
                                {!section.hideHeading && (
                                    <p
                                        className={cn(
                                            'px-3 pb-1 text-xs font-semibold tracking-wide uppercase',
                                            section.tone === 'danger'
                                                ? 'text-red-600 dark:text-red-400'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {section.title}
                                    </p>
                                )}
                                {section.items.map((item, index) => (
                                    <Button
                                        key={`${toUrl(item.href)}-${index}`}
                                        size="sm"
                                        variant="ghost"
                                        asChild
                                        className={cn('w-full justify-start', {
                                            'bg-muted': isCurrentUrl(item.href),
                                            'text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-500/10 dark:hover:text-red-300':
                                                section.tone === 'danger',
                                        })}
                                    >
                                        <Link href={item.href}>
                                            {item.icon && (
                                                <item.icon className="h-4 w-4" />
                                            )}
                                            {item.title}
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
