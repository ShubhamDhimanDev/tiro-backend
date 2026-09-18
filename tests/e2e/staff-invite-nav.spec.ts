import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';
import { totp } from './support/totp';

/**
 * Regression coverage for a bug class that slipped through two prior
 * "fixed and verified" rounds of this same feature:
 * `resources/js/components/app-sidebar.tsx` and
 * `resources/js/pages/users/index.tsx` hardcoded the literal string
 * `/users` for the sidebar nav link, the invite `<Form>`'s `action`, and the
 * breadcrumb -- instead of the Wayfinder-generated
 * `UserController.index().url` / `UserController.store.form()` helpers
 * every other admin module uses. The real route lives behind
 * `routes/admin.php`'s `prefix('admin')` group (`/admin/users`, not
 * `/users`), so every Pest test exercising this via
 * `route('admin.users.index')` / `route('admin.users.store')` passed
 * regardless of what was hardcoded client-side -- `route()` always resolves
 * the correct URL from the route name, so it can never catch a hardcoded,
 * wrong string in the React file. Only a real browser clicking the actual
 * rendered `<a href>` and submitting the actual rendered `<form action>`
 * can catch this class of bug.
 *
 * This project has no CI -- run manually against a running dev server
 * (`composer run dev` inside `backend/`) with, e.g.:
 *
 *   cd backend
 *   npx --yes @playwright/test@1.63.0 test tests/e2e
 *
 * `@playwright/test` is intentionally NOT added to `package.json` here
 * (adding dependencies needs approval) -- npx resolves it on demand.
 *
 * CAVEAT (seen 2026-09-11, Windows/Node 25, npm 11): a bare
 * `npx --yes @playwright/test@1.63.0 test tests/e2e` can fail with
 * `Cannot find package '@playwright/test' imported from
 * tests/e2e/*.spec.ts`, because `package.json` has `"type": "module"` here
 * and Node's ESM resolver looks for `@playwright/test` starting from the
 * spec file's own directory upward, not from npx's ephemeral cache. `npx
 * --yes @playwright/test@1.63.0 --version` still succeeds standalone (that's
 * npx resolving its own entry point), which makes the failure easy to miss
 * until `test` actually tries to load a spec file. If this happens, run
 * `npm install --no-save --no-audit --no-fund @playwright/test@1.63.0`
 * inside `backend/` first (verify `package.json`/`package-lock.json` are
 * unchanged afterward -- `--no-save` should leave them untouched, only
 * populating the gitignored `node_modules/`), then `npx playwright test
 * tests/e2e` resolves normally.
 *
 * `beforeAll` below provisions its own fixed-credential super_admin
 * directly via `php artisan tinker` against whatever database the running
 * dev server is pointed at, so this doesn't depend on any particular
 * pre-existing seeded account. It requests a *freshly generated* TOTP
 * secret each run rather than reusing a fixed one: Fortify's
 * `TwoFactorAuthenticationProvider::verify()` caches the last-accepted
 * timestamp per code value (`fortify.2fa_codes.md5($code)`,
 * `vendor/laravel/fortify/src/TwoFactorAuthenticationProvider.php`) and
 * rejects a code that isn't newer than that cached entry -- a fixed secret
 * re-run within the same 30s TOTP window would regenerate the identical
 * code and get spuriously rejected as a replay.
 */

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BACKEND_ROOT = path.resolve(__dirname, '..', '..');

const E2E_ADMIN_EMAIL = 'e2e-nav-regression-admin@tiro.test';
const E2E_ADMIN_PASSWORD = 'E2ENavRegression!2026';

let e2eAdminTotpSecret: string;

test.beforeAll(() => {
    const php = `
        $secret = app(PragmaRX\\Google2FA\\Google2FA::class)->generateSecretKey();
        $user = App\\Models\\User::firstOrNew(['email' => '${E2E_ADMIN_EMAIL}']);
        $user->name = 'E2E Nav Regression Admin';
        $user->password = Illuminate\\Support\\Facades\\Hash::make('${E2E_ADMIN_PASSWORD}');
        $user->email_verified_at = now();
        $user->two_factor_secret = encrypt($secret);
        $user->two_factor_recovery_codes = encrypt(json_encode(['recovery-code-1']));
        $user->two_factor_confirmed_at = now();
        $user->save();
        if (!$user->hasRole('super_admin')) { $user->assignRole('super_admin'); }
        echo $secret;
    `;
    const output = execFileSync(
        'php',
        ['artisan', 'tinker', '--execute', php],
        {
            cwd: BACKEND_ROOT,
            encoding: 'utf-8',
        },
    );
    console.log(output);

    const match = output.trim().match(/([A-Z2-7]{16,})\s*$/);
    if (!match) {
        throw new Error(
            `Could not parse a generated TOTP secret out of tinker's output:\n${output}`,
        );
    }
    e2eAdminTotpSecret = match[1];
});

test('a super_admin can reach Roles & Users via the real sidebar link and submit the real invite form', async ({
    page,
}) => {
    // Real login + a real TOTP challenge is inherently slower (and more
    // timing-sensitive around the 30s TOTP window) than a mocked auth flow;
    // give it more headroom than the 30s default so a slow but healthy run
    // doesn't fail for reasons unrelated to the nav/form bug this covers.
    test.setTimeout(60_000);

    await page.goto(`${BASE_URL}/login`);
    await page.getByLabel('Email address').fill(E2E_ADMIN_EMAIL);
    await page
        .getByRole('textbox', { name: 'Password' })
        .fill(E2E_ADMIN_PASSWORD);
    await page.getByRole('button', { name: /log in/i }).click();

    await page.waitForURL(/two-factor/);
    await page.locator('input[name="code"]').fill(totp(e2eAdminTotpSecret));
    await page.getByRole('button', { name: /continue/i }).click();

    await page.waitForURL(`${BASE_URL}/dashboard`);

    // The regression under test: click the *rendered* nav link, never
    // `route()` or `page.goto()` straight to the destination -- that's
    // exactly what would let a hardcoded, wrong `href` slip through
    // undetected, as happened twice before.
    await page.getByRole('link', { name: 'Roles & Users' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/users`);
    await expect(
        page.getByRole('heading', { name: 'Roles & Users' }),
    ).toBeVisible();

    const inviteEmail = `e2e-invite-${Date.now()}@example.test`;
    await page.getByLabel('Name').fill('E2E Nav Regression Invite');
    await page.getByLabel('Email address').fill(inviteEmail);
    await page.getByLabel('Role').click();
    await page.getByRole('option', { name: 'Ecommerce' }).click();

    // Likewise: submit the *rendered* `<Form>` via its real button, never a
    // direct `route()`-backed POST -- that's what would let a hardcoded,
    // wrong `action` slip through undetected.
    const [response] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/users` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Send invite' }).click(),
    ]);

    expect(response.status()).toBeLessThan(400);
    await expect(page.getByText(inviteEmail)).toBeVisible();
});
