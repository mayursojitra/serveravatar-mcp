import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface Flash {
    status?: string | null;
    error?: string | null;
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: Flash;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: 'user' | 'admin';
    must_change_password: boolean;
    avatar?: string;
}

export interface ActivityRow {
    id: number;
    time: string | null;
    user_email: string | null;
    user_role: string | null;
    kind: string;
    status: 'ok' | 'failed' | 'denied' | string;
    client_name: string | null;
    method: string | null;
    tool: string | null;
    ip: string | null;
    args_redacted: Record<string, unknown> | null;
    meta: Record<string, unknown> | null;
    error: string | null;
    duration_ms: number | null;
}

export interface TokenSummary {
    last4: string;
    organizations: { id: number; name: string }[];
    verified_at: string | null;
    last_error: string | null;
}

export interface LevelOption {
    value: string;
    label: string;
    description: string;
}

export interface Connection {
    id: number;
    client_name: string;
    level: string;
    connected_at: string | null;
    last_used_at: string | null;
}
