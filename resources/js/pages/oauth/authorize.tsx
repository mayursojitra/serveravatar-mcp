import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';
import { type LevelOption } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    clientName: string;
    redirectUri: string;
    redirectHost: string;
    userEmail: string;
    authToken: string;
    levels: LevelOption[];
    csrfToken: string;
}

/**
 * The consent screen. Both buttons are plain form posts, not Inertia requests: approving answers
 * with a redirect to the AI client's own address, which a background request cannot follow.
 */
export default function Authorize({ clientName, redirectHost, userEmail, authToken, levels, csrfToken }: Props) {
    const [level, setLevel] = useState('read');
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <AuthLayout title={`Authorize ${clientName}`} description="This AI client wants to use ServerAvatar tools on your behalf.">
            <Head title="Authorize a connection" />

            <div className="text-muted-foreground rounded-lg border p-3 text-sm">
                Signed in as <span className="text-foreground font-medium">{userEmail}</span>. After you approve, you are sent back to{' '}
                <span className="text-foreground font-medium break-all">{redirectHost}</span>. Only continue if you started this connection.
            </div>

            <form method="POST" action={route('passport.authorizations.approve')} className="grid gap-4">
                <input type="hidden" name="_token" value={csrfToken} />
                <input type="hidden" name="auth_token" value={authToken} />

                <fieldset className="grid gap-2">
                    <legend className="mb-1 text-sm font-medium">How much may it do?</legend>
                    {levels.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm',
                                level === option.value ? 'border-primary bg-primary/5' : 'hover:bg-muted/50',
                            )}
                        >
                            <input
                                type="radio"
                                name="level"
                                value={option.value}
                                checked={level === option.value}
                                onChange={() => setLevel(option.value)}
                                className="mt-1"
                            />
                            <span>
                                <span className="block font-medium">{option.label}</span>
                                <span className="text-muted-foreground block text-xs">{option.description}</span>
                            </span>
                        </label>
                    ))}
                    <InputError message={errors?.level} />
                </fieldset>

                <p className="text-muted-foreground text-xs">You can change the level or disconnect at any time on the Connections page.</p>

                <Button type="submit" className="w-full">
                    Authorize
                </Button>
            </form>

            <form method="POST" action={route('passport.authorizations.deny')}>
                <input type="hidden" name="_token" value={csrfToken} />
                <input type="hidden" name="_method" value="DELETE" />
                <input type="hidden" name="auth_token" value={authToken} />
                <Button type="submit" variant="outline" className="w-full">
                    Cancel
                </Button>
            </form>
        </AuthLayout>
    );
}
