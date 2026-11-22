import { ActivityTable } from '@/components/activity-table';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type ActivityRow, type BreadcrumbItem, type SharedData, type TokenSummary } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

interface Usage {
    calls_24h: number;
    failed_24h: number;
    denied_24h: number;
    connections: number;
    last_call_at: string | null;
}

interface Props {
    recent: ActivityRow[];
    usage: Usage;
    stats: { users: number; disabled: number; admins: number; everyone: Usage } | null;
    token: TokenSummary | null;
}

function UsageCards({ usage, label }: { usage: Usage; label: string }) {
    return (
        <div className="grid gap-4 sm:grid-cols-3">
            <Card>
                <CardHeader>
                    <CardDescription>{label}: tool calls, last 24 hours</CardDescription>
                    <CardTitle className="text-3xl">{usage.calls_24h}</CardTitle>
                </CardHeader>
                <CardContent className="text-muted-foreground text-sm">
                    {usage.last_call_at ? `Latest ${new Date(usage.last_call_at).toLocaleString()}` : 'No calls yet.'}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Failed or refused, last 24 hours</CardDescription>
                    <CardTitle className="text-3xl">{usage.failed_24h + usage.denied_24h}</CardTitle>
                </CardHeader>
                <CardContent className="text-muted-foreground text-sm">
                    {usage.failed_24h} failed, {usage.denied_24h} above the connection&apos;s permission level.
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>AI connections</CardDescription>
                    <CardTitle className="text-3xl">{usage.connections}</CardTitle>
                </CardHeader>
                <CardContent className="text-muted-foreground text-sm">
                    <Link href="/connections" className="underline underline-offset-4">
                        Manage connections
                    </Link>
                </CardContent>
            </Card>
        </div>
    );
}

export default function Dashboard({ recent, usage, stats, token }: Props) {
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Welcome, {auth.user.name}</h1>
                    <p className="text-muted-foreground text-sm">
                        Signed in as {auth.user.email}
                        {auth.user.role === 'admin' ? ' (admin)' : ''}.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardDescription>ServerAvatar token</CardDescription>
                        <CardTitle className="text-lg">
                            {token ? (
                                <>
                                    Saved <span className="font-mono font-normal">…{token.last4}</span>
                                </>
                            ) : (
                                'Not saved yet'
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="text-muted-foreground text-sm">
                        {token ? (
                            token.last_error ? (
                                <span className="text-destructive">{token.last_error}</span>
                            ) : (
                                `${token.organizations.length} organization${token.organizations.length === 1 ? '' : 's'} visible.`
                            )
                        ) : (
                            'Save your token so your AI clients have something to use.'
                        )}{' '}
                        <Link href="/token" className="underline underline-offset-4">
                            {token ? 'Manage' : 'Add your token'}
                        </Link>
                    </CardContent>
                </Card>

                <UsageCards usage={usage} label="You" />

                {stats && <UsageCards usage={stats.everyone} label="Everyone" />}

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardDescription>Users</CardDescription>
                                <CardTitle className="text-3xl">{stats.users}</CardTitle>
                            </CardHeader>
                            <CardContent className="text-muted-foreground text-sm">
                                <Link href="/admin/users" className="underline underline-offset-4">
                                    Manage users
                                </Link>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardDescription>Admins</CardDescription>
                                <CardTitle className="text-3xl">{stats.admins}</CardTitle>
                            </CardHeader>
                            <CardContent className="text-muted-foreground text-sm">Created with the command line only.</CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardDescription>Disabled accounts</CardDescription>
                                <CardTitle className="text-3xl">{stats.disabled}</CardTitle>
                            </CardHeader>
                        </Card>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Recent activity</CardTitle>
                        <CardDescription>
                            Your latest events.{' '}
                            <Link href="/activity" className="underline underline-offset-4">
                                See all
                            </Link>
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ActivityTable rows={recent} showUser={false} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
