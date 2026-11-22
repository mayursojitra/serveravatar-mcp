import { Badge } from '@/components/ui/badge';
import { type ActivityRow } from '@/types';

const statusVariant = (status: string) => (status === 'ok' ? 'secondary' : 'destructive');

/** "14:02:11" for today, otherwise the date and time. */
export function formatTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'medium' });
}

function describe(row: ActivityRow): string {
    const parts: string[] = [];
    if (row.tool) parts.push(row.tool);
    if (row.method) parts.push(row.method);
    const reason = row.meta && typeof row.meta.reason === 'string' ? row.meta.reason : null;
    if (reason) parts.push(reason.replace('_', ' '));
    const target = row.meta && typeof row.meta.target_email === 'string' ? row.meta.target_email : null;
    if (target) parts.push(target);
    if (row.error) parts.push(row.error);
    return parts.join(' · ');
}

export function ActivityTable({ rows, showUser }: { rows: ActivityRow[]; showUser: boolean }) {
    if (rows.length === 0) {
        return <p className="text-muted-foreground py-6 text-sm">No activity yet.</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
                <thead>
                    <tr className="text-muted-foreground border-b text-xs">
                        <th className="py-2 pr-4 font-medium">Time</th>
                        {showUser && <th className="py-2 pr-4 font-medium">User</th>}
                        <th className="py-2 pr-4 font-medium">Event</th>
                        <th className="py-2 pr-4 font-medium">Details</th>
                        <th className="py-2 pr-4 font-medium">Client</th>
                        <th className="py-2 pr-4 font-medium">IP</th>
                        <th className="py-2 pr-4 font-medium">Result</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-b align-top last:border-0">
                            <td className="py-2 pr-4 whitespace-nowrap">{formatTime(row.time)}</td>
                            {showUser && (
                                <td className="py-2 pr-4">
                                    {row.user_email ?? <span className="text-muted-foreground">unknown</span>}
                                    {row.user_role === 'admin' && (
                                        <Badge variant="outline" className="ml-2">
                                            admin
                                        </Badge>
                                    )}
                                </td>
                            )}
                            <td className="py-2 pr-4 font-mono text-xs">{row.kind}</td>
                            <td className="text-muted-foreground max-w-xs py-2 pr-4 break-words">{describe(row)}</td>
                            <td className="py-2 pr-4">{row.client_name ?? ''}</td>
                            <td className="py-2 pr-4 font-mono text-xs">{row.ip ?? ''}</td>
                            <td className="py-2 pr-4">
                                <Badge variant={statusVariant(row.status)}>{row.status}</Badge>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
