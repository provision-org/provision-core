import type { ComponentType } from 'react';

export type ExtensionPoint =
    | 'agent.create.steps'
    | 'agent.show.tabs'
    | 'agent.show.sidebar'
    | 'dashboard.widgets'
    | 'settings.sections';

export interface ModuleExtension {
    point: ExtensionPoint;
    component: ComponentType<Record<string, unknown>>;
    order?: number;
    label?: string;
    icon?: ComponentType<Record<string, unknown>>;
}

const extensions: ModuleExtension[] = [];

export function registerExtension(ext: ModuleExtension): void {
    extensions.push(ext);
    extensions.sort((a, b) => (a.order ?? 50) - (b.order ?? 50));
}

export function getExtensions(point: ExtensionPoint): ModuleExtension[] {
    return extensions.filter((e) => e.point === point);
}

export function hasExtensions(point: ExtensionPoint): boolean {
    return extensions.some((e) => e.point === point);
}
