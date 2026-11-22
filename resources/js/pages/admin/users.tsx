import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/admin/users' }];

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    role: 'user' | 'admin';
    disabled: boolean;
    must_change_password: boolean;
    has_token: boolean;
    created_at: string;
}

function CreateUserForm() {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', email: '', password: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.users.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Create a user</CardTitle>
                <CardDescription>
                    New users get the role "user" and must choose their own password at first sign-in. Admins cannot be created here: use{' '}
                    <code className="text-xs">php artisan serveravatar:create-admin</code> on the server.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="new-name">Name</Label>
                        <Input id="new-name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="new-email">Email</Label>
                        <Input id="new-email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required />
                        <InputError message={errors.email} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="new-password">Temporary password</Label>
                        <Input
                            id="new-password"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                        <InputError message={errors.password} />
                    </div>
                    <div className="sm:col-span-3">
                        <Button type="submit" disabled={processing}>
                            Create user
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function EditDialog({ user, open, onClose }: { user: ManagedUser; open: boolean; onClose: () => void }) {
    const { data, setData, patch, processing, errors } = useForm({ name: user.name, disabled: user.disabled as boolean });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('admin.users.update', user.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Edit {user.email}</DialogTitle>
                        <DialogDescription>Rename the user. The role cannot be changed.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor={`name-${user.id}`}>Name</Label>
                        <Input id={`name-${user.id}`} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                        <InputError message={errors.name} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ResetDialog({ user, open, onClose }: { user: ManagedUser; open: boolean; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.users.reset-password', user.id), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Reset password for {user.email}</DialogTitle>
                        <DialogDescription>
                            They are signed out everywhere, must choose a new password at next sign-in, and their connected AI clients are
                            disconnected.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor={`reset-${user.id}`}>Temporary password</Label>
                        <Input
                            id={`reset-${user.id}`}
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                        <InputError message={errors.password} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Reset password
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function UserRow({ user }: { user: ManagedUser }) {
    const [editing, setEditing] = useState(false);
    const [resetting, setResetting] = useState(false);
    const [busy, setBusy] = useState(false);
    const request = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    // The current values are read at click time. A form object created once would keep sending the
    // values from when the row first loaded, undoing a rename or toggling the wrong way.
    const toggleDisabled = () => router.patch(route('admin.users.update', user.id), { name: user.name, disabled: !user.disabled }, request);
    const remove = () => {
        if (window.confirm(`Delete ${user.email}? This cannot be undone.`)) {
            router.delete(route('admin.users.destroy', user.id), request);
        }
    };

    return (
        <tr className="border-b align-top last:border-0">
            <td className="py-3 pr-4">
                <div className="font-medium">{user.name}</div>
                <div className="text-muted-foreground text-xs">{user.email}</div>
                <div className="text-muted-foreground text-xs">{user.has_token ? 'ServerAvatar token saved' : 'No ServerAvatar token yet'}</div>
            </td>
            <td className="py-3 pr-4">
                <Badge variant={user.role === 'admin' ? 'default' : 'outline'}>{user.role}</Badge>
            </td>
            <td className="py-3 pr-4">
                {user.disabled ? (
                    <Badge variant="destructive">disabled</Badge>
                ) : user.must_change_password ? (
                    <Badge variant="secondary">must set password</Badge>
                ) : (
                    <Badge variant="secondary">active</Badge>
                )}
            </td>
            <td className="py-3">
                {user.role === 'admin' ? (
                    <span className="text-muted-foreground text-xs">Managed from the command line</span>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                            Edit
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => setResetting(true)}>
                            Reset password
                        </Button>
                        <Button size="sm" variant="outline" disabled={busy} onClick={toggleDisabled}>
                            {user.disabled ? 'Enable' : 'Disable'}
                        </Button>
                        <Button size="sm" variant="destructive" disabled={busy} onClick={remove}>
                            Delete
                        </Button>
                    </div>
                )}
                {editing && <EditDialog user={user} open onClose={() => setEditing(false)} />}
                {resetting && <ResetDialog user={user} open onClose={() => setResetting(false)} />}
            </td>
        </tr>
    );
}

export default function AdminUsers({ users }: { users: ManagedUser[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Users</h1>
                    <p className="text-muted-foreground text-sm">Accounts exist only because an admin created them. There is no sign-up.</p>
                </div>

                <CreateUserForm />

                <Card>
                    <CardContent className="overflow-x-auto pt-6">
                        <table className="w-full text-left text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-xs">
                                    <th className="py-2 pr-4 font-medium">User</th>
                                    <th className="py-2 pr-4 font-medium">Role</th>
                                    <th className="py-2 pr-4 font-medium">Status</th>
                                    <th className="py-2 font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <UserRow key={user.id} user={user} />
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
