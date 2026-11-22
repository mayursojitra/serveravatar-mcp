import { formatTime } from '@/components/activity-table';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Connection, type LevelOption } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Connections', href: '/connections' }];

function CopyField({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard access can be blocked; the text is selectable anyway.
        }
    };

    return (
        <div className="flex gap-2">
            <code className="bg-muted flex-1 rounded-md px-3 py-2 text-sm break-all select-all">{value}</code>
            <Button type="button" variant="outline" size="sm" onClick={copy}>
                {copied ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}

function ConnectionRow({ connection, levels }: { connection: Connection; levels: LevelOption[] }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const request = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    return (
        <tr className="border-b align-top last:border-0">
            <td className="py-3 pr-4">
                <div className="font-medium">{connection.client_name}</div>
                <div className="text-muted-foreground text-xs">Connected {formatTime(connection.connected_at)}</div>
            </td>
            <td className="py-3 pr-4">
                <select
                    aria-label={`Permission level for ${connection.client_name}`}
                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                    value={connection.level}
                    disabled={busy}
                    onChange={(e) => router.patch(route('connections.update', connection.id), { level: e.target.value }, request)}
                >
                    {levels.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </td>
            <td className="py-3 pr-4 text-sm whitespace-nowrap">
                {connection.last_used_at ? formatTime(connection.last_used_at) : <span className="text-muted-foreground">Never used</span>}
            </td>
            <td className="py-3">
                <Button size="sm" variant="destructive" disabled={busy} onClick={() => setConfirming(true)}>
                    Revoke
                </Button>
                <Dialog open={confirming} onOpenChange={setConfirming}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Revoke {connection.client_name}?</DialogTitle>
                            <DialogDescription>
                                It is disconnected at once and can no longer use your tools. To use it again, connect it again and approve it.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setConfirming(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => {
                                    setConfirming(false);
                                    router.delete(route('connections.destroy', connection.id), request);
                                }}
                            >
                                Revoke
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </td>
        </tr>
    );
}

export default function Connections({
    connections,
    levels,
    mcpUrl,
    hasToken,
}: {
    connections: Connection[];
    levels: LevelOption[];
    mcpUrl: string;
    hasToken: boolean;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Connections" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Connections</h1>
                    <p className="text-muted-foreground text-sm">
                        The AI clients you have connected. You can change what each may do, or disconnect it.
                    </p>
                </div>

                {!hasToken && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            You have not saved a ServerAvatar token yet, so there is nothing for an AI client to use.{' '}
                            <Link href={route('token.show')} className="underline underline-offset-4">
                                Add your token
                            </Link>{' '}
                            before connecting one.
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Connect an AI client</CardTitle>
                        <CardDescription>
                            Add this address as an MCP server in your AI client. It will ask you to sign in here and choose a permission level.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm">
                        <CopyField value={mcpUrl} />
                        <ul className="text-muted-foreground list-disc space-y-1 pl-5">
                            <li>
                                <span className="text-foreground font-medium">claude.ai:</span> Settings, Connectors, Add custom connector, paste the
                                address.
                            </li>
                            <li>
                                <span className="text-foreground font-medium">Claude Code:</span>{' '}
                                <code className="text-xs">claude mcp add --transport http serveravatar {mcpUrl}</code>, then run{' '}
                                <code className="text-xs">/mcp</code> to sign in.
                            </li>
                            <li>
                                <span className="text-foreground font-medium">Cursor:</span> add{' '}
                                <code className="text-xs">{`{"mcpServers": {"serveravatar": {"url": "${mcpUrl}"}}}`}</code> to{' '}
                                <code className="text-xs">~/.cursor/mcp.json</code>, then connect it in Cursor&apos;s MCP settings.
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            Your connections <Badge variant="secondary">{connections.length}</Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {connections.length === 0 ? (
                            <p className="text-muted-foreground py-4 text-sm">Nothing connected yet.</p>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-xs">
                                        <th className="py-2 pr-4 font-medium">Client</th>
                                        <th className="py-2 pr-4 font-medium">Permission level</th>
                                        <th className="py-2 pr-4 font-medium">Last used</th>
                                        <th className="py-2 font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connections.map((connection) => (
                                        <ConnectionRow key={connection.id} connection={connection} levels={levels} />
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
