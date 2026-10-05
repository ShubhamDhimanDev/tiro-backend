import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { loginWithTotp } from './support/login';

/**
 * Real-browser coverage for the three Phase 5 Promotions admin screens
 * (`resources/js/pages/promotions/campaigns/index.tsx`,
 * `promotions/price-guarantee-claims/index.tsx`,
 * `promotions/price-rules/index.tsx`) — campaign CRUD + eligibility
 * sub-editor, the price-guarantee claim review queue, and PriceRule CRUD.
 *
 * Pest already covers the request/response contract thoroughly
 * (tests/Feature/Admin/Promotions/PromotionControllerTest.php,
 * PriceGuaranteeClaimControllerTest.php, PriceRuleControllerTest.php,
 * tests/Feature/Promotions/PromotionEvaluationServiceTest.php). This spec
 * exists for the class of bug Pest's `route()`-based calls structurally
 * cannot catch — a hardcoded/wrong client-side URL, a `<Can>`-gated button
 * that never renders, a `Select` wired to the wrong form field, or an error
 * response that never actually surfaces in the rendered UI. That exact bug
 * class (a hardcoded, wrong URL string in a React file instead of a
 * Wayfinder-generated helper) survived TWO rounds of Pest-only "fixed and
 * verified" sign-off before `staff-invite-nav.spec.ts` existed, because
 * `route()` always resolves the correct URL server-side and Pest never
 * catches a wrong client-side string. Every navigation below clicks the real
 * rendered sidebar `<Link>` / `PromotionsLayout` tab `<Link>` and submits the
 * real rendered dialog form's button — never `route()` or `page.goto()`
 * straight to a destination, with one deliberate exception: the
 * permission-denied checks, where hitting the URL directly *is* the point
 * (proving the server still blocks it even though the UI never exposes a
 * path there for a `promotions.view`-only role) — same convention as
 * `admin-catalog-crud-nav.spec.ts` and `admin-vehicles-crud-nav.spec.ts`
 * (used here as the second reference point, per qa-lead's brief).
 *
 * Notable wiring facts pulled from the real source rather than guessed:
 * - The sidebar has a single "Promotions" module link
 *   (`resources/js/components/app-sidebar.tsx`), gated `anyOf:
 *   ['promotions.view', 'promotions.manage']` and pointing at the campaigns
 *   index. All three screens share
 *   `resources/js/layouts/promotions/layout.tsx`, a horizontal tab nav with
 *   real `<Link>`s labelled "Campaigns" / "Price-guarantee claims" / "Price
 *   rules" — that's how this spec moves between the three screens instead of
 *   a second sidebar entry per screen.
 * - Every `Select` in this app (shadcn/Radix) portals its option list to
 *   `document.body`, outside the Dialog's own DOM subtree — so option
 *   clicks below are deliberately *not* scoped to the dialog locator (a
 *   `dialog.getByRole('option', ...)` would never find them), matching
 *   `staff-invite-nav.spec.ts`'s existing Select-in-a-form pattern.
 * - **Updated 2026-09-25 (Phase 6 RBAC hardening pass correction):**
 *   `routes/admin.php`'s Promotions group splits read from write —
 *   the three GET `index` routes (`promotions`, `price-guarantee-claims`,
 *   `price-rules`) are gated `permission:promotions.view`, reachable and
 *   real (200) for a `.view`-only role; every mutating route (campaign
 *   store/update/destroy, eligibility store/destroy, claim approve/reject,
 *   price-rule store/update/destroy) stays gated `permission:
 *   promotions.manage` only. This was a deliberate Phase 6 change (see the
 *   dated comment directly above that route group, and
 *   `app-sidebar.tsx`'s matching dated comment) — previously the index
 *   routes were ALSO `.manage`-only, which is what the two role-denial tests
 *   below used to assert against (`.toHaveCount(0)` on the nav link, a
 *   403 on every direct route hit). Both assertions were stale relative to
 *   that fix and failing outright until this correction (found during a
 *   Phase 7 QA pass that ran this full spec directly rather than trusting
 *   a stale docblock's claim — same "Pest passes, real navigation reveals a
 *   bug" lesson this project already has on record, just pointed at an E2E
 *   spec's own claim this time instead of app code). `customer_support` and
 *   `operations` both hold `promotions` => `'view'`
 *   (`database/seeders/RolesAndPermissionsSeeder.php`), `ecommerce` holds
 *   `'manage'`. Both `.view`-only roles are exercised below to prove: the
 *   nav link now renders and is real-navigable for them across all three
 *   tabs, AND every `.manage`-only mutation control (`<Can
 *   permission="promotions.manage">` in each page — "New campaign"/"Edit"/
 *   "Delete" on campaigns, "New price rule"/"Edit"/"Delete" on price rules,
 *   "Approve"/"Reject" on claims) is absent from the rendered DOM. The
 *   underlying server-side 403 on an actual mutating request is Pest's job
 *   (`PromotionControllerTest.php` et al. already cover it) — this spec's
 *   job is only the browser-level half: that the UI genuinely never offers
 *   a `.view`-only visitor a path to reach those actions at all.
 *
 * Run the same way as the other specs in this directory (see
 * staff-invite-nav.spec.ts's header comment for the `@playwright/test` npx
 * caveat on this Windows/Node setup):
 *
 *   cd backend
 *   npx --yes @playwright/test@1.63.0 test tests/e2e/admin-promotions-crud-nav.spec.ts
 */

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BACKEND_ROOT = path.resolve(__dirname, '..', '..');

const E2E_PASSWORD = 'E2EPromotionsCrud!2026';

const ECOMMERCE_EMAIL = 'e2e-ecommerce-promotions@tiro.test';
const SUPPORT_EMAIL = 'e2e-support-promotions@tiro.test';
const OPERATIONS_EMAIL = 'e2e-operations-promotions@tiro.test';

const STAMP = Date.now();
const BRAND_NAME = `E2E Promo Brand ${STAMP}`;
const ZONE_NAME = `E2E Promo Zone ${STAMP}`;
const APPROVE_CLAIMANT_NAME = `E2E Promo Approve Claimant ${STAMP}`;
const REJECT_CLAIMANT_NAME = `E2E Promo Reject Claimant ${STAMP}`;

const totpSecrets: Record<
    'ecommerce' | 'customer_support' | 'operations',
    string
> = {
    ecommerce: '',
    customer_support: '',
    operations: '',
};

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
    // Provisions three fixed-credential staff users (one per RBAC tier this
    // spec needs) with freshly generated TOTP secrets every run -- see
    // staff-invite-nav.spec.ts's header comment for why a fresh secret is
    // required each run rather than a reused fixed one (Fortify's replay
    // cache would spuriously reject a same-window re-run). Also seeds its
    // own Brand, ServiceZone, and two pending PriceGuaranteeClaim rows
    // directly via factories -- deliberately not relying on whatever
    // catalogue/zone data happens to already exist in the shared dev DB, so
    // this spec is self-contained and its option-list lookups
    // (`getByRole('option', { name: ... })`) are unambiguous.
    const php = `
        $google2fa = app(PragmaRX\\Google2FA\\Google2FA::class);
        $roles = [
            'ecommerce' => '${ECOMMERCE_EMAIL}',
            'customer_support' => '${SUPPORT_EMAIL}',
            'operations' => '${OPERATIONS_EMAIL}',
        ];
        foreach ($roles as $role => $email) {
            $secret = $google2fa->generateSecretKey();
            $user = App\\Models\\User::firstOrNew(['email' => $email]);
            $user->name = 'E2E ' . ucfirst(str_replace('_', ' ', $role)) . ' Promotions';
            $user->password = Illuminate\\Support\\Facades\\Hash::make('${E2E_PASSWORD}');
            $user->email_verified_at = now();
            $user->two_factor_secret = encrypt($secret);
            $user->two_factor_recovery_codes = encrypt(json_encode(['recovery-code-1']));
            $user->two_factor_confirmed_at = now();
            $user->save();
            $user->syncRoles([$role]);
            echo "{$role}:{$secret}\\n";
        }

        App\\Models\\Brand::factory()->create(['name' => '${BRAND_NAME}']);
        App\\Models\\ServiceZone::factory()->create(['name' => '${ZONE_NAME}']);

        $approveCustomer = App\\Models\\Customer::factory()->create(['name' => '${APPROVE_CLAIMANT_NAME}']);
        $approveVariant = App\\Models\\TyreVariant::factory()->create(['base_price' => 50000]);
        App\\Models\\PriceGuaranteeClaim::factory()->create([
            'customer_id' => $approveCustomer->id,
            'tyre_variant_id' => $approveVariant->id,
        ]);

        $rejectCustomer = App\\Models\\Customer::factory()->create(['name' => '${REJECT_CLAIMANT_NAME}']);
        $rejectVariant = App\\Models\\TyreVariant::factory()->create(['base_price' => 50000]);
        App\\Models\\PriceGuaranteeClaim::factory()->create([
            'customer_id' => $rejectCustomer->id,
            'tyre_variant_id' => $rejectVariant->id,
        ]);

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

    for (const role of [
        'ecommerce',
        'customer_support',
        'operations',
    ] as const) {
        const match = output.match(new RegExp(`${role}:([A-Z2-7]{16,})`));
        if (!match) {
            throw new Error(
                `Could not parse a generated TOTP secret for role "${role}" out of tinker's output:\n${output}`,
            );
        }
        totpSecrets[role] = match[1];
    }
});

test('an ecommerce-role (promotions.manage) staff member can create a campaign via the real Promotions nav + form, add and remove an eligibility rule via the real sub-editor, create a price rule via the real Price rules tab + form, and approve/reject price-guarantee claims via the real review-queue UI', async ({
    page,
}) => {
    // One login for the whole flow, deliberately not split into separate
    // `test()` blocks that would each log in as the same ECOMMERCE_EMAIL +
    // `totpSecrets.ecommerce` a second time: two logins with the identical
    // TOTP secret executed back-to-back by the same worker can land in the
    // same 30-second TOTP window and generate the exact same code, which
    // Fortify's replay-prevention cache correctly (and unavoidably) rejects
    // as a reused code on the second attempt -- observed directly while
    // building this spec (a second `test()` here failed at the 2FA step
    // with the page stuck on `/two-factor-challenge`). Splitting into a
    // fresh role+secret per login (as this spec already does for
    // ecommerce/customer_support/operations) fixes it for *different*
    // people; two logins as the *same* person doesn't have that option, so
    // one continuous session is the correct fix here.
    test.setTimeout(120_000);

    await loginWithTotp(
        page,
        BASE_URL,
        ECOMMERCE_EMAIL,
        E2E_PASSWORD,
        totpSecrets.ecommerce,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    // The sidebar's single "Promotions" module link lands on the campaigns
    // index (PromotionController::index()) -- see app-sidebar.tsx.
    await page.getByRole('link', { name: 'Promotions' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/promotions`);
    await expect(
        page.getByRole('heading', { name: 'Promotions' }),
    ).toBeVisible();

    // --- Campaign creation via the real rendered dialog form ---
    const campaignName = `E2E Campaign ${Date.now()}`;
    const today = new Date();
    const startsAt = today.toISOString().slice(0, 10);
    const endsDate = new Date(today);
    endsDate.setMonth(endsDate.getMonth() + 1);
    const endsAt = endsDate.toISOString().slice(0, 10);

    await page.getByRole('button', { name: 'New campaign' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Name').fill(campaignName);
    // Type defaults to "Percentage off" -- left untouched; its value field
    // label is "Discount (%)" for that type.
    await createDialog.getByLabel('Discount (%)').fill('15');
    await createDialog.getByLabel('Starts').fill(startsAt);
    await createDialog.getByLabel('Ends').fill(endsAt);
    await createDialog.getByLabel('Status').click();
    await page.getByRole('option', { name: 'Active', exact: true }).click();

    const [createResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/promotions` &&
                res.request().method() === 'POST',
        ),
        createDialog.getByRole('button', { name: 'Create campaign' }).click(),
    ]);
    expect(createResponse.status()).toBeLessThan(400);

    const campaignRow = page.locator('tr', { hasText: campaignName });
    await expect(campaignRow).toBeVisible();
    await expect(campaignRow.getByText('Active')).toBeVisible();

    // --- Eligibility sub-editor: real add, then real remove ---
    await campaignRow.getByRole('button', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await expect(
        editDialog.getByText(/No eligibility rules yet/),
    ).toBeVisible();

    // Scope defaults to "brand"; select the brand this spec seeded.
    await editDialog.getByLabel('Brand').click();
    await page.getByRole('option', { name: BRAND_NAME, exact: true }).click();

    const [addEligibilityResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url().startsWith(`${BASE_URL}/admin/promotions/`) &&
                res.url().endsWith('/eligibilities') &&
                res.request().method() === 'POST',
        ),
        editDialog.getByRole('button', { name: 'Add rule' }).click(),
    ]);
    expect(addEligibilityResponse.status()).toBeLessThan(400);
    await expect(editDialog.getByText(BRAND_NAME)).toBeVisible();

    const [removeEligibilityResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url().startsWith(`${BASE_URL}/admin/promotions/`) &&
                res.url().includes('/eligibilities/') &&
                res.request().method() === 'DELETE',
        ),
        editDialog.getByRole('button', { name: 'Remove' }).click(),
    ]);
    expect(removeEligibilityResponse.status()).toBeLessThan(400);
    await expect(
        editDialog.getByText(/No eligibility rules yet/),
    ).toBeVisible();

    await page.keyboard.press('Escape');

    // --- Price rules, reached via the real tab nav, created via the real form ---
    await page.getByRole('link', { name: 'Price rules' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/price-rules`);

    await page.getByRole('button', { name: 'New price rule' }).click();
    const priceRuleDialog = page.getByRole('dialog');
    await priceRuleDialog.getByLabel('Service zone').click();
    await page.getByRole('option', { name: ZONE_NAME, exact: true }).click();
    // fee_type defaults to "flat", whose amount field is labelled "Fee ($)".
    await priceRuleDialog.getByLabel('Fee ($)').fill('20.00');

    const [priceRuleResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/price-rules` &&
                res.request().method() === 'POST',
        ),
        priceRuleDialog
            .getByRole('button', { name: 'Create price rule' })
            .click(),
    ]);
    expect(priceRuleResponse.status()).toBeLessThan(400);

    const priceRuleRow = page.locator('tr', { hasText: ZONE_NAME });
    await expect(priceRuleRow).toBeVisible();
    await expect(priceRuleRow.getByText('$20.00')).toBeVisible();

    // --- Price-guarantee claims, reached via the real tab nav, actioned via the real review-queue dialogs ---
    await page.getByRole('link', { name: 'Price-guarantee claims' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/price-guarantee-claims`);

    // --- Approve the first seeded pending claim ---
    const approveRow = page.locator('tr', { hasText: APPROVE_CLAIMANT_NAME });
    await expect(approveRow).toBeVisible();
    await approveRow.getByRole('button', { name: 'Approve' }).click();

    const approveDialog = page.getByRole('dialog');
    await approveDialog.getByLabel('Approved discount ($)').fill('10.00');
    await approveDialog
        .getByLabel('Admin note (optional)')
        .fill('Verified competitor listing — E2E test.');

    const [approveResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url().includes('/admin/price-guarantee-claims/') &&
                res.url().endsWith('/approve') &&
                res.request().method() === 'POST',
        ),
        approveDialog
            .getByRole('button', { name: 'Confirm approval' })
            .click(),
    ]);
    expect(approveResponse.status()).toBeLessThan(400);
    await expect(approveRow.getByText('Approved')).toBeVisible();

    // --- Reject the second seeded pending claim ---
    const rejectRow = page.locator('tr', { hasText: REJECT_CLAIMANT_NAME });
    await expect(rejectRow).toBeVisible();
    await rejectRow.getByRole('button', { name: 'Reject' }).click();

    const rejectDialog = page.getByRole('dialog');
    const rejectNote = 'Not a matching competitor listing — E2E test.';
    await rejectDialog.getByLabel('Admin note (required)').fill(rejectNote);

    const [rejectResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url().includes('/admin/price-guarantee-claims/') &&
                res.url().endsWith('/reject') &&
                res.request().method() === 'POST',
        ),
        rejectDialog
            .getByRole('button', { name: 'Confirm rejection' })
            .click(),
    ]);
    expect(rejectResponse.status()).toBeLessThan(400);
    await expect(rejectRow.getByText('Rejected')).toBeVisible();
    await expect(rejectRow.getByText(rejectNote)).toBeVisible();

    stopWatchingErrors();
});

/**
 * Shared by both `.view`-only role tests below: real-navigates across all
 * three Promotions tabs (never `page.goto()` straight to a destination,
 * matching this spec's own stated convention) and asserts every
 * `.manage`-only mutation control is absent from each rendered page. Kept as
 * a helper rather than duplicated inline so the two role tests below stay
 * focused on "which role, which nav entry point" and don't drift apart on
 * what "still blocked" actually checks.
 */
async function assertViewOnlyPromotionsAccess(page: Page): Promise<void> {
    // The sidebar's "Promotions" link is now visible for a `.view`-only role
    // too (Phase 6 fix, see header docblock) -- real click, not page.goto().
    await expect(page.getByRole('link', { name: 'Promotions' })).toHaveCount(
        1,
    );
    await page.getByRole('link', { name: 'Promotions' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/promotions`);
    await expect(
        page.getByRole('heading', { name: 'Promotions' }),
    ).toBeVisible();

    // Read-only: the create control and every per-row manage control are
    // gone, not merely disabled -- `<Can permission="promotions.manage">`
    // renders nothing at all for this role.
    await expect(
        page.getByRole('button', { name: 'New campaign' }),
    ).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Edit' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Delete' })).toHaveCount(0);

    // `PromotionsLayout` (resources/js/layouts/promotions/layout.tsx) renders
    // one fixed `<Heading title="Promotions">` shared by all three tabs — it
    // never changes per tab, so "Price-guarantee claims"/"Price rules" are
    // never themselves an accessible heading. The layout's own "Promotions"
    // heading staying visible plus each page's own unique content (the
    // "Status" filter here; a `CardTitle` -- a plain, non-heading `<div>`,
    // see `resources/js/components/ui/card.tsx` -- on price rules) is this
    // spec's real proof each tab actually rendered its own screen.
    await page.getByRole('link', { name: 'Price-guarantee claims' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/price-guarantee-claims`);
    await expect(
        page.getByRole('heading', { name: 'Promotions' }),
    ).toBeVisible();
    await expect(page.getByLabel('Status')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(
        0,
    );
    await expect(page.getByRole('button', { name: 'Reject' })).toHaveCount(0);

    await page.getByRole('link', { name: 'Price rules' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/price-rules`);
    await expect(
        page.getByRole('heading', { name: 'Promotions' }),
    ).toBeVisible();
    // "Price rules" text alone is ambiguous (breadcrumb link, tab link, AND
    // the page's own `CardTitle` all use that exact string) -- scope to the
    // `CardTitle` specifically via its `data-slot="card-title"` attribute.
    await expect(
        page.locator('[data-slot="card-title"]').getByText('Price rules', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'New price rule' }),
    ).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Edit' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Delete' })).toHaveCount(0);
}

test('a customer_support-role staff member (promotions.view only, not .manage) can reach all three Promotions screens via the real nav — Phase 6 made the index routes view-reachable — but every create/edit/delete/approve/reject control stays hidden', async ({
    page,
}) => {
    test.setTimeout(60_000);

    await loginWithTotp(
        page,
        BASE_URL,
        SUPPORT_EMAIL,
        E2E_PASSWORD,
        totpSecrets.customer_support,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    await assertViewOnlyPromotionsAccess(page);

    stopWatchingErrors();
});

test('an operations-role staff member (promotions.view only, not .manage) can reach all three Promotions screens via the real nav, with the same manage-only controls hidden', async ({
    page,
}) => {
    test.setTimeout(60_000);

    await loginWithTotp(
        page,
        BASE_URL,
        OPERATIONS_EMAIL,
        E2E_PASSWORD,
        totpSecrets.operations,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    // operations also holds `promotions.view` only, same as customer_support
    // above (database/seeders/RolesAndPermissionsSeeder.php) -- confirmed
    // distinct role, same permission tier for this module.
    await assertViewOnlyPromotionsAccess(page);

    stopWatchingErrors();
});
