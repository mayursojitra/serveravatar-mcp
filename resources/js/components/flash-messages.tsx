import { Alert, AlertDescription } from '@/components/ui/alert';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** Status and error messages flashed by the server after an action. */
export function FlashMessages() {
    const { flash } = usePage<SharedData>().props;

    if (!flash?.status && !flash?.error) {
        return null;
    }

    return (
        <div className="space-y-2 px-4 pt-4">
            {flash.status && (
                <Alert>
                    <AlertDescription>{flash.status}</AlertDescription>
                </Alert>
            )}
            {flash.error && (
                <Alert variant="destructive">
                    <AlertDescription>{flash.error}</AlertDescription>
                </Alert>
            )}
        </div>
    );
}
