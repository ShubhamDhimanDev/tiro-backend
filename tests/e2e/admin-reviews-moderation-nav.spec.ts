import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { loginWithTotp } from './support/login';

/**
 * Real-browser coverage for the Phase 8 admin Reviews screen
 * (`resources/js/pages/reviews/index.tsx`, backed by
 * `App\Http\Controllers\Admin\Reviews\ReviewController`) — previously ZERO
 * E2E coverage (only Pest-level `assertInertia` prop assertions in
 * `tests/Feature/Admin/Reviews/ReviewControllerTest.php`, which never
 * actually renders the React page in a browser).
 *
 * The single most important thing this spec exists for: `Review.body` and
 * `Review.review_url` are genuinely nullable (a star-only Google rating with
 * no written comment; Google's API schema can omit a review URL entirely —
 * see `resources/js/types/reviews.ts`'s docblock and
 * `SyncGoogleReviewsCommand::upsert()`'s comment on `review_url`). A real bug
 * was found and fixed mid-phase where the page didn't render these
 * defensively; this spec proves the fix in a real browser, the one thing
 * Pest's `assertInertia` prop-shape checks structurally cannot catch (a prop
 * being `null` is not the same as it being handled correctly by JSX).
 *
 * Same convention as `admin-promotions-crud-nav.spec.ts` / this directory's
 * other `admin-*-crud-nav.spec.ts` files (used as the reference point): real
 * login, real DB via factories/tinker, real rendered sidebar `<Link>` for
 * navigation, `workers: 1` per `.ai/rules/playwright-e2e.md`. One deliberate
 * exception, matching every sibling spec's own stated convention: the
 * permission-denial check at the bottom hits `/admin/reviews` directly via
 * `page.goto()`, because proving the server still blocks it (rather than the
 * UI merely hiding a link to it) is the actual point there.
 *
 * Notable wiring facts pulled from the real source rather than guessed:
 * - The sidebar's "Reviews" link (`resources/js/components/app-sidebar.tsx`)
 *   is gated `anyOf: ['content.view', 'content.manage']` — the same tier as
 *   "Content" above it, no new permission (confirmed against
 *   `routes/admin.php`'s Reviews route group and
 *   `database/seeders/RolesAndPermissionsSeeder.php`'s `ROLE_MODULE_TIERS`).
 *   Only `super_admin` and `ecommerce` hold a `content` entry at all (both
 *   `manage`) — there is no `content.view`-only role in this seeder, so
 *   unlike the Promotions spec there are only two states to exercise: full
 *   access (`ecommerce`) and no access at all (`customer_support`, which has
 *   no `content` entry whatsoever).
 * - `ReviewController::index()` deliberately returns every review, hidden
 *   and visible alike (unlike the public `GET /api/v1/reviews`, which scopes
 *   to `visible()`) — staff need to see moderated-out rows to un-hide them.
 *   The rendered row gets `opacity-60` and a "Hidden" `<Badge>` when
 *   `is_hidden` is true (`reviews/index.tsx`).
 * - The moderation toggle (`ModerationToggle` in `reviews/index.tsx`) is a
 *   real Inertia `useForm().patch()` against
 *   `ReviewController.update(review.id).url` — `PATCH /admin/reviews/{id}`,
 *   gated `content.manage`. The "Sync now" button (`ResyncButton`) is a real
 *   `useForm().post()` against `ReviewController.resync().url` — `POST
 *   /admin/reviews/resync`, also `content.manage`-gated, and runs
 *   `reviews:sync-google` synchronously inline (not queued) — see
 *   `SyncGoogleReviewsCommand`'s docblock. This dev environment's `.env` has
 *   every `GOOGLE_REVIEWS_*` var blank (confirmed directly), so the command
 *   fails fast at its own "not configured" guard with no outbound HTTP call
 *   at all — safe to click for real in this spec, and it exercises the
 *   documented failure path: `ReviewController::resync()` flashes an
 *   `error`-type toast ("Google reviews sync failed — check the logs.")
 *   rather than crashing, which `resources/js/hooks/use-flash-toast.ts`
 *   turns into a real `sonner` toast on the next Inertia page visit.
 *
 * Run the same way as the other specs in this directory (see
 * staff-invite-nav.spec.ts's header comment for the `@playwright/test` npx
 * caveat on this Windows/Node setup):
 *
 *   cd backend
 *   npx --yes @playwright/test@1.63.0 test tests/e2e/admin-reviews-moderation-nav.spec.ts
 *
 * Prerequisite discovered while building this spec: the dev database this
 * suite runs against (`tiro`, per `.env`'s `DB_DATABASE` — distinct from
 * Pest's `tiro_testing`) had never had the `2026_09_25_170111_create_reviews_table`
 * migration applied (`php artisan migrate:status` showed it `Pending`) —
 * unsurprising given zero E2E coverage existed to exercise this screen
 * before. Ran a plain `php artisan migrate --force` (not `migrate:fresh`, so
 * every other real row already seeded in this shared dev DB was left
 * untouched) to unblock this spec.
 */

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BACKEND_ROOT = path.resolve(__dirname, '..', '..');

const E2E_PASSWORD = 'E2EReviewsModeration!2026';

const ECOMMERCE_EMAIL = 'e2e-ecommerce-reviews@tiro.test';
const NO_CONTENT_EMAIL = 'e2e-no-content-reviews@tiro.test';

const STAMP = Date.now();
const NULL_FIELDS_AUTHOR = `E2E Null Fields Review ${STAMP}`;
const FULL_AUTHOR = `E2E Full Review ${STAMP}`;
const HIDDEN_AUTHOR = `E2E Hidden Review ${STAMP}`;
const FULL_BODY_TEXT = `E2E full review body text ${STAMP}.`;
const FULL_REVIEW_URL = `https://search.google.com/local/reviews?placeid=e2e-fake&review=e2e-${STAMP}`;

const totpSecrets: Record<'ecommerce' | 'no_content', string> = {
    ecommerce: '',
    no_content: '',
};

let fullReviewId = 0;

/** Fails the test if the page logged a console error or an uncaught JS error. */
function watchForConsoleErrors(page: Page): () => void {
    const errors: string[] = [];
    const onConsole = (msg: import('@playwright/test').ConsoleMessage) => {
        if (msg.type() === 'error') {
            errors.push(msg.text());
        }
    };
    const onPageError = (err: Error) => errors.push(String(err));
    page.on('console', onConsole);
    page.on('pageerror', onPageError);

    return () => {
        page.off('console', onConsole);
        page.off('pageerror', onPageError);
        expect(
            errors,
            `Unexpected browser console/page errors:\n${errors.join('\n')}`,
        ).toEqual([]);
    };
}

test.beforeAll(() => {
    // Provisions two fixed-credential staff users (a `content.manage` one via
    // `ecommerce`, and a no-`content`-permission-at-all one via
    // `customer_support` -- see `RolesAndPermissionsSeeder::ROLE_MODULE_TIERS`,
    // confirmed directly: only `super_admin`/`ecommerce` hold a `content`
    // entry) with freshly generated TOTP secrets every run -- see
    // staff-invite-nav.spec.ts's header comment for why a fresh secret is
    // required each run rather than a reused fixed one. Also seeds the three
    // `Review` rows this spec's assertions depend on directly via
    // `Review::factory()`, with explicit `body`/`review_url` overrides (the
    // factory's default state randomizes `body` 80% of the time and always
    // sets a `review_url` -- neither guaranteed-null state this spec needs
    // exists as a named factory state yet, so both are set explicitly here
    // rather than via a new factory state, since a one-off spec-local
    // override is the smaller change). `published_at` is pinned to `now()`
    // for all three so they always sort first under `index()`'s
    // `orderByDesc('published_at')` regardless of how many other reviews
    // already exist in this shared dev DB (there were zero at the time this
    // spec was written, but this doesn't rely on that staying true).
    const php = `
        $google2fa = app(PragmaRX\\Google2FA\\Google2FA::class);
        $roles = [
            'ecommerce' => '${ECOMMERCE_EMAIL}',
            'customer_support' => '${NO_CONTENT_EMAIL}',
        ];
        $secretByRole = [];
        foreach ($roles as $role => $email) {
            $secret = $google2fa->generateSecretKey();
            $user = App\\Models\\User::firstOrNew(['email' => $email]);
            $user->name = 'E2E ' . ucfirst(str_replace('_', ' ', $role)) . ' Reviews';
            $user->password = Illuminate\\Support\\Facades\\Hash::make('${E2E_PASSWORD}');
            $user->email_verified_at = now();
            $user->two_factor_secret = encrypt($secret);
            $user->two_factor_recovery_codes = encrypt(json_encode(['recovery-code-1']));
            $user->two_factor_confirmed_at = now();
            $user->save();
            $user->syncRoles([$role]);
            $secretByRole[$role] = $secret;
        }
        echo "ecommerce:{$secretByRole['ecommerce']}\\n";
        echo "no_content:{$secretByRole['customer_support']}\\n";

        App\\Models\\Review::factory()->create([
            'author_name' => '${NULL_FIELDS_AUTHOR}',
            'body' => null,
            'review_url' => null,
            'is_hidden' => false,
            'published_at' => now(),
        ]);

        $full = App\\Models\\Review::factory()->create([
            'author_name' => '${FULL_AUTHOR}',
            'body' => '${FULL_BODY_TEXT}',
            'review_url' => '${FULL_REVIEW_URL}',
            'is_hidden' => false,
            'published_at' => now(),
        ]);

        App\\Models\\Review::factory()->hidden()->create([
            'author_name' => '${HIDDEN_AUTHOR}',
            'published_at' => now(),
        ]);

        echo "full_review_id:{$full->id}\\n";
        echo "seed:ok\\n";
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

    if (!output.includes('seed:ok')) {
        throw new Error(
            `Tinker seed script did not report success. Output:\n${output}`,
        );
    }

    for (const role of ['ecommerce', 'no_content'] as const) {
        const match = output.match(new RegExp(`${role}:([A-Z2-7]{16,})`));
        if (!match) {
            throw new Error(
                `Could not parse a generated TOTP secret for role "${role}" out of tinker's output:\n${output}`,
            );
        }
        totpSecrets[role] = match[1];
    }

    const idMatch = output.match(/full_review_id:(\d+)/);
    if (!idMatch) {
        throw new Error(
            `Could not parse the seeded "full" review's id out of tinker's output:\n${output}`,
        );
    }
    fullReviewId = Number(idMatch[1]);
});

test('a content.manage (ecommerce) staff member sees every seeded review rendered correctly via the real Reviews nav — including the hidden badge, the null-body italic fallback, and the absent "View original" link when review_url is null — then flips a real row\'s moderation state and triggers a real (failing, since GOOGLE_REVIEWS_* is unset) resync', async ({
    page,
}) => {
    test.setTimeout(120_000);

    await loginWithTotp(
        page,
        BASE_URL,
        ECOMMERCE_EMAIL,
        E2E_PASSWORD,
        totpSecrets.ecommerce,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    // Real rendered sidebar link, not page.goto() -- same convention as
    // every sibling spec in this directory.
    await page.getByRole('link', { name: 'Reviews' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/reviews`);
    await expect(page.getByRole('heading', { name: 'Reviews' })).toBeVisible();

    // --- Hidden row: visible in the admin list (unlike the public storefront
    // endpoint), with a "Hidden" badge and the dimmed row styling ---
    const hiddenRow = page.locator('tr', { hasText: HIDDEN_AUTHOR });
    await expect(hiddenRow).toBeVisible();
    await expect(hiddenRow.getByText('Hidden', { exact: true })).toBeVisible();
    await expect(hiddenRow).toHaveClass(/opacity-60/);
    await expect(
        hiddenRow.getByRole('button', { name: 'Restore' }),
    ).toBeVisible();

    // --- Null body/review_url row: the italic fallback renders instead of a
    // blank cell, and "View original" is genuinely absent, not just unlinked ---
    const nullFieldsRow = page.locator('tr', { hasText: NULL_FIELDS_AUTHOR });
    await expect(nullFieldsRow).toBeVisible();
    await expect(
        nullFieldsRow.getByText('No written review (star rating only)'),
    ).toBeVisible();
    await expect(
        nullFieldsRow.getByRole('link', { name: 'View original' }),
    ).toHaveCount(0);

    // --- Full row (contrast case): real body text renders, and the "View
    // original" link is genuinely present when review_url is set ---
    const fullRow = page.locator('tr', { hasText: FULL_AUTHOR });
    await expect(fullRow).toBeVisible();
    await expect(fullRow.getByText(FULL_BODY_TEXT)).toBeVisible();
    await expect(
        fullRow.getByRole('link', { name: 'View original' }),
    ).toBeVisible();
    await expect(fullRow.getByText('Visible', { exact: true })).toBeVisible();
    await expect(fullRow).not.toHaveClass(/opacity-60/);

    // --- Moderation toggle, exercised live: Hide, then Restore, on the same
    // real row, asserting the badge/opacity/button-label actually flip after
    // each request completes ---
    const [hideResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/reviews/${fullReviewId}` &&
                res.request().method() === 'PATCH',
        ),
        fullRow.getByRole('button', { name: 'Hide' }).click(),
    ]);
    expect(hideResponse.status()).toBeLessThan(400);

    await expect(fullRow.getByText('Hidden', { exact: true })).toBeVisible();
    await expect(fullRow).toHaveClass(/opacity-60/);
    await expect(fullRow.getByRole('button', { name: 'Restore' })).toBeVisible();

    const [restoreResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/reviews/${fullReviewId}` &&
                res.request().method() === 'PATCH',
        ),
        fullRow.getByRole('button', { name: 'Restore' }).click(),
    ]);
    expect(restoreResponse.status()).toBeLessThan(400);

    await expect(fullRow.getByText('Visible', { exact: true })).toBeVisible();
    await expect(fullRow).not.toHaveClass(/opacity-60/);
    await expect(fullRow.getByRole('button', { name: 'Hide' })).toBeVisible();

    // --- "Sync now", exercised live against this dev environment's real,
    // unconfigured GOOGLE_REVIEWS_* env -- the command fails fast (no
    // outbound HTTP call), and the controller must surface that as a clean
    // error toast rather than a broken page ---
    const [resyncResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/reviews/resync` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Sync now' }).click(),
    ]);
    expect(resyncResponse.status()).toBeLessThan(400);
    await expect(
        page.getByText('Google reviews sync failed — check the logs.'),
    ).toBeVisible();

    // The page itself must still be intact after the failure toast -- not a
    // broken/blank page. The heading and every seeded row are still there.
    await expect(page.getByRole('heading', { name: 'Reviews' })).toBeVisible();
    await expect(hiddenRow).toBeVisible();

    stopWatchingErrors();
});

test('a staff member with no content permission at all (customer_support) never sees the Reviews nav link, and a direct hit to /admin/reviews is still server-side blocked', async ({
    page,
}) => {
    test.setTimeout(60_000);

    await loginWithTotp(
        page,
        BASE_URL,
        NO_CONTENT_EMAIL,
        E2E_PASSWORD,
        totpSecrets.no_content,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    await expect(page.getByRole('link', { name: 'Reviews' })).toHaveCount(0);

    stopWatchingErrors();

    // Deliberate exception to this spec's "never page.goto() straight to a
    // destination" convention -- proving the server still blocks it is the
    // actual point here, same as every sibling spec's own denial check.
    const denied = await page.goto(`${BASE_URL}/admin/reviews`);
    expect(denied?.status()).toBe(403);
});
