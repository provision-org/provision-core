import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const { currentUrl, isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
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
