import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Two-factor sign-in', href: '/settings/security' }];

interface Props {
    enabled: boolean;
    required: boolean;
    setup: { qr_code: string; manual_key: string } | null;
    recovery_codes: string[] | null;
    recovery_codes_left: number | null;
    status?: string;
}

function PasswordAction({
    label,
    routeName,
    method,
    variant = 'outline',
}: {
    label: string;
    routeName: string;
    method: 'post' | 'delete';
    variant?: 'outline' | 'destructive';
}) {
    const { data, setData, post, delete: destroy, processing, errors, reset } = useForm({ current_password: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onFinish: () => reset('current_password') };
        if (method === 'post') {
            post(route(routeName), options);
        } else {
            destroy(route(routeName), options);
        }
    };

    return (
        <form onSubmit={submit} className="grid max-w-sm gap-2">
            <Label htmlFor={`pw-${routeName}`}>Current password</Label>
            <Input
                id={`pw-${routeName}`}
                type="password"
                required
                autoComplete="current-password"
                value={data.current_password}
                onChange={(e) => setData('current_password', e.target.value)}
            />
            <InputError message={errors.current_password} />
            <div>
                <Button type="submit" variant={variant} disabled={processing}>
                    {label}
                </Button>
            </div>
        </form>
    );
}

function RecoveryCodes({ codes }: { codes: string[] }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(codes.join('\n'));
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="space-y-3 rounded-lg border border-amber-500/50 p-4">
            <HeadingSmall
                title="Save your recovery codes now"
                description="Each code works once and signs you in if you lose your phone. They are shown only this one time: store them somewhere safe, such as a password manager."
            />
            <ul className="grid grid-cols-2 gap-2 font-mono text-sm">
                {codes.map((code) => (
                    <li key={code}>{code}</li>
                ))}
            </ul>
            <Button type="button" variant="outline" size="sm" onClick={copy}>
                {copied ? 'Copied' : 'Copy codes'}
            </Button>
        </div>
    );
}

function Setup({ setup }: { setup: NonNullable<Props['setup']> }) {
    const { data, setData, post, processing, errors, reset } = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.confirm'), { preserveScroll: true, onFinish: () => reset('code') });
    };

    return (
        <div className="space-y-6">
            <HeadingSmall
                title="Set up your authenticator app"
                description="Scan this QR code with an authenticator app such as Google Authenticator, 1Password, Authy or Aegis, then enter the 6-digit code it shows."
            />
            <img
                src={setup.qr_code}
                alt="QR code to add this account to your authenticator app"
                width={192}
                height={192}
                className="rounded-md border bg-white"
            />
            <div className="text-sm">
                <p className="text-muted-foreground">Cannot scan it? Enter this key in the app by hand:</p>
                <p className="font-mono">{setup.manual_key}</p>
            </div>
            <form onSubmit={submit} className="grid max-w-sm gap-2">
                <Label htmlFor="setup-code">Code from the app</Label>
                <Input
                    id="setup-code"
                    type="text"
                    inputMode="numeric"
                    required
                    autoComplete="one-time-code"
                    maxLength={8}
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value)}
                    placeholder="123456"
                />
                <InputError message={errors.code} />
                <div className="flex gap-2">
                    <Button type="submit" disabled={processing}>
                        Turn on
                    </Button>
                    <Button type="button" variant="outline" onClick={() => router.delete(route('two-factor.disable'), { preserveScroll: true })}>
                        Cancel
                    </Button>
                </div>
            </form>
        </div>
    );
}

export default function Security({ enabled, required, setup, recovery_codes, recovery_codes_left, status }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-factor sign-in" />

            <SettingsLayout>
                <div className="space-y-8">
                    <HeadingSmall
                        title="Two-factor sign-in"
                        description="Besides your password, sign-in asks for a code from an app on your phone. This also protects connecting new AI clients, because that starts with signing in."
                    />

                    {status && <p className="text-sm font-medium text-green-600">{status}</p>}

                    {required && !enabled && (
                        <p className="text-sm font-medium">Your administrator requires two-factor sign-in. Set it up to continue using the app.</p>
                    )}

                    {recovery_codes && recovery_codes.length > 0 && <RecoveryCodes codes={recovery_codes} />}

                    {enabled ? (
                        <div className="space-y-8">
                            <div className="flex items-center gap-3">
                                <Badge variant="secondary">On</Badge>
                                <span className="text-muted-foreground text-sm">{recovery_codes_left ?? 0} recovery codes left</span>
                            </div>

                            <div className="space-y-3">
                                <HeadingSmall title="Make new recovery codes" description="The old codes stop working." />
                                <PasswordAction label="Make new codes" routeName="two-factor.recovery-codes" method="post" />
                            </div>

                            <div className="space-y-3">
                                <HeadingSmall
                                    title="Turn off"
                                    description={
                                        required
                                            ? 'Your administrator requires two-factor sign-in, so it cannot be turned off.'
                                            : 'Sign-in will ask for your password only.'
                                    }
                                />
                                {!required && (
                                    <PasswordAction
                                        label="Turn off two-factor sign-in"
                                        routeName="two-factor.disable"
                                        method="delete"
                                        variant="destructive"
                                    />
                                )}
                            </div>
                        </div>
                    ) : setup ? (
                        <Setup setup={setup} />
                    ) : (
                        <div className="space-y-3">
                            <div className="flex items-center gap-3">
                                <Badge variant="outline">Off</Badge>
                            </div>
                            <p className="text-sm">Confirm your password to start. Nothing changes until you enter a code from your app.</p>
                            <PasswordAction label="Set up two-factor sign-in" routeName="two-factor.enable" method="post" />
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
