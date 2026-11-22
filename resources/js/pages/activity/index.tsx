import { ActivityTable } from '@/components/activity-table';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type ActivityRow, type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Props {
    rows: ActivityRow[];
    scope: 'mine' | 'all';
    filters: { kind: string | null; status: string | null; tool: string | null; user: string | null };
    tools: string[];
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}

const kinds = [
    { value: '', label: 'All events' },
    { value: 'auth', label: 'Sign-ins' },
    { value: 'admin', label: 'Admin actions' },
    { value: 'mcp', label: 'MCP calls' },
    { value: 'token', label: 'Token changes' },
];

const statuses = [
    { value: '', label: 'Any result' },
    { value: 'ok', label: 'ok' },
    { value: 'failed', label: 'failed' },
    { value: 'denied', label: 'denied' },
];

export default function ActivityIndex({ rows, scope, filters, tools, pagination }: Props) {
    const base = scope === 'all' ? '/admin/activity' : '/activity';
    const breadcrumbs: BreadcrumbItem[] = [{ title: scope === 'all' ? 'All activity' : 'Activity', href: base }];

    const apply = (next: Partial<{ kind: string; status: string; tool: string; user: string }>) => {
        const merged = { kind: filters.kind ?? '', status: filters.status ?? '', tool: filters.tool ?? '', user: filters.user ?? '', ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, v]) => v));
        router.get(base, query, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={scope === 'all' ? 'All activity' : 'Activity'} />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{scope === 'all' ? 'All activity' : 'Your activity'}</h1>
                    <p className="text-muted-foreground text-sm">
                        {scope === 'all'
                            ? 'Sign-ins, admin actions and every MCP call, for all users and admins. Rows are kept for 90 days.'
                            : 'Your sign-ins and MCP calls. Rows are kept for 90 days.'}
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    <select
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        aria-label="Event type"
                        value={filters.kind ?? ''}
                        onChange={(e) => apply({ kind: e.target.value })}
                    >
                        {kinds.map((k) => (
                            <option key={k.value} value={k.value}>
                                {k.label}
                            </option>
                        ))}
                    </select>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        aria-label="Result"
                        value={filters.status ?? ''}
                        onChange={(e) => apply({ status: e.target.value })}
                    >
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        aria-label="Tool"
                        value={filters.tool ?? ''}
                        onChange={(e) => apply({ tool: e.target.value })}
                    >
                        <option value="">Any tool</option>
                        {tools.map((t) => (
                            <option key={t} value={t}>
                                {t}
                            </option>
                        ))}
                    </select>
                    {scope === 'all' && (
                        <input
                            type="search"
                            className="border-input bg-background h-9 w-56 rounded-md border px-3 text-sm"
                            aria-label="User e-mail"
                            placeholder="User e-mail contains…"
                            defaultValue={filters.user ?? ''}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') apply({ user: e.currentTarget.value.trim() });
                            }}
                            onBlur={(e) => {
                                if (e.currentTarget.value.trim() !== (filters.user ?? '')) apply({ user: e.currentTarget.value.trim() });
                            }}
                        />
                    )}
                </div>

                <ActivityTable rows={rows} showUser={scope === 'all'} />

                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                        {pagination.total} rows · page {pagination.current_page} of {pagination.last_page}
                    </span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" asChild disabled={!pagination.prev_page_url}>
                            {pagination.prev_page_url ? <Link href={pagination.prev_page_url}>Previous</Link> : <span>Previous</span>}
                        </Button>
                        <Button variant="outline" size="sm" asChild disabled={!pagination.next_page_url}>
                            {pagination.next_page_url ? <Link href={pagination.next_page_url}>Next</Link> : <span>Next</span>}
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
