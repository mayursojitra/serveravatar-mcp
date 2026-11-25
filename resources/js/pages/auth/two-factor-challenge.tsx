import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function TwoFactorChallenge() {
    const [useRecovery, setUseRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.challenge.store'), {
            onFinish: () => reset('code', 'recovery_code'),
        });
    };

    const toggle = () => {
        setUseRecovery((v) => !v);
        reset('code', 'recovery_code');
        clearErrors();
    };

    return (
        <AuthLayout
            title="Two-factor sign-in"
            description={
                useRecovery
                    ? 'Enter one of the recovery codes you saved when you turned on two-factor sign-in. Each code works once.'
                    : 'Enter the 6-digit code from your authenticator app.'
            }
        >
            <Head title="Two-factor sign-in" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-2">
                    {useRecovery ? (
                        <>
                            <Label htmlFor="recovery_code">Recovery code</Label>
                            <Input
                                id="recovery_code"
                                type="text"
                                required
                                autoFocus
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                maxLength={32}
                                value={data.recovery_code}
                                onChange={(e) => setData('recovery_code', e.target.value)}
                                placeholder="xxxxx-xxxxx"
                            />
                            <InputError message={errors.recovery_code ?? errors.code} />
                        </>
                    ) : (
                        <>
                            <Label htmlFor="code">Authentication code</Label>
                            <Input
                                id="code"
                                type="text"
                                inputMode="numeric"
                                required
                                autoFocus
                                autoComplete="one-time-code"
                                maxLength={8}
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="123456"
                            />
                            <InputError message={errors.code} />
                        </>
                    )}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Continue
                </Button>

                <button type="button" onClick={toggle} className="text-muted-foreground hover:text-foreground text-center text-sm underline">
                    {useRecovery ? 'Use an authenticator code instead' : 'Use a recovery code instead'}
                </button>
            </form>
        </AuthLayout>
    );
}
