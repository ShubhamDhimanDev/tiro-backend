import { Link, usePage } from '@inertiajs/react';
import { ShieldAlert, X } from 'lucide-react';
import { useState } from 'react';
import { edit } from '@/routes/security';

const STORAGE_KEY = 'two-factor-banner-dismissed';

function wasDismissed(): boolean {
    try {
        return sessionStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * Dismissible announcement bar nudging staff to enable 2FA (authenticator app
 * or passkey). Shown only while neither is set up; dismissal lasts for the
 * browser session.
 */
export function TwoFactorBanner() {
    const { twoFactorSetupRequired } = usePage().props;
    const [dismissed, setDismissed] = useState(wasDismissed);

    if (!twoFactorSetupRequired || dismissed) {
        return null;
    }

    const dismiss = () => {
        try {
            sessionStorage.setItem(STORAGE_KEY, '1');
        } catch {
            // Dismissal just won't persist across reloads.
        }
        setDismissed(true);
    };

    return (
        <div
            role="status"
            className="flex items-center gap-3 bg-amber-100 px-4 py-2 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
        >
            <ShieldAlert className="size-4 shrink-0" />
            <p className="flex-1">
                Protect your account: enable two-factor authentication or add a
                passkey.{' '}
                <Link
                    href={edit()}
                    className="font-semibold underline underline-offset-4"
                >
                    Set it up
                </Link>
            </p>
            <button
                type="button"
                onClick={dismiss}
                aria-label="Dismiss"
                className="rounded p-1 hover:bg-black/10 dark:hover:bg-white/10"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}
