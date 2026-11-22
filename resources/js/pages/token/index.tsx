import { formatTime } from '@/components/activity-table';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type TokenSummary } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'ServerAvatar token', href: '/token' }];

export default function TokenPage({ token }: { token: TokenSummary | null }) {
    const [replacing, setReplacing] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [busy, setBusy] = useState(false);
    const request = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    // No form key: Inertia would otherwise remember the typed token in the browser history.
    const { data, setData, post, processing, errors, reset } = useForm({ token: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('token.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setReplacing(false);
            },
            onFinish: () => setData('token', ''),
        });
    };

    const showForm = token === null || replacing;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="ServerAvatar token" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">ServerAvatar token</h1>
                    <p className="text-muted-foreground text-sm">
                        The token lets your AI clients act on your ServerAvatar account. It is checked with ServerAvatar before it is saved, stored
                        encrypted, and only used when your AI client asks for something. It is never shown again, only its last four characters.
                    </p>
                </div>

                {token && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex flex-wrap items-center gap-2">
                                Saved token <span className="font-mono text-base font-normal">…{token.last4}</span>
                                {token.last_error ? (
                                    <Badge variant="destructive">needs attention</Badge>
                                ) : (
                                    <Badge variant="secondary">verified</Badge>
                                )}
                            </CardTitle>
                            <CardDescription>
                                {token.verified_at ? `Last verified ${formatTime(token.verified_at)}` : 'Not verified yet'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 text-sm">
                            {token.last_error && <p className="text-destructive">{token.last_error}</p>}

                            <div>
                                <div className="mb-1 font-medium">Organizations it can see</div>
                                {token.organizations.length === 0 ? (
                                    <p className="text-muted-foreground">None.</p>
                                ) : (
                                    <ul className="text-muted-foreground list-disc pl-5">
                                        {token.organizations.map((org) => (
                                            <li key={org.id}>
                                                {org.name} <span className="font-mono text-xs">#{org.id}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button size="sm" variant="outline" disabled={busy} onClick={() => router.post(route('token.test'), {}, request)}>
                                    Test now
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => setReplacing((r) => !r)}>
                                    {replacing ? 'Cancel' : 'Replace'}
                                </Button>
                                <Button size="sm" variant="destructive" disabled={busy} onClick={() => setConfirmingDelete(true)}>
                                    Delete
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {showForm && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{token ? 'Replace the token' : 'Add your token'}</CardTitle>
                            <CardDescription>
                                Paste the API token from your ServerAvatar account, with or without the word "Bearer". Your AI clients keep working
                                after a replace.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="grid max-w-xl gap-4" autoComplete="off">
                                <div className="grid gap-2">
                                    <Label htmlFor="token">ServerAvatar API token</Label>
                                    <Input
                                        id="token"
                                        name="token"
                                        type="password"
                                        autoComplete="off"
                                        autoCapitalize="off"
                                        spellCheck={false}
                                        value={data.token}
                                        onChange={(e) => setData('token', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.token} />
                                </div>
                                <div>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Checking with ServerAvatar…' : 'Verify and save'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete the saved token?</DialogTitle>
                            <DialogDescription>
                                Your connected AI clients are disconnected at once, because there is nothing for them to use. Save a token again and
                                connect them again to continue.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setConfirmingDelete(false)}>
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => {
                                    setConfirmingDelete(false);
                                    router.delete(route('token.destroy'), request);
                                }}
                            >
                                Delete token
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
