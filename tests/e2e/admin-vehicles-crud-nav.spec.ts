import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { loginWithTotp } from './support/login';

/**
 * Real-browser coverage for the Phase 2 Vehicles admin module (row-level
 * `Vehicle`/`VehicleFitment` CRUD + the bulk CSV/JSON import screen) — see
 * docs/architecture/01-data-model.md's "Vehicles & fitment" section and
 * docs/plan/01-task-breakdown.md's Phase 2 row.
 *
 * Pest already covers the request/response contract thoroughly
 * (tests/Feature/Admin/Vehicles/*, tests/Feature/Vehicles/*,
 * tests/Feature/Console/FitmentImportCommandTest.php). This spec exists for
 * the class of bug Pest's `route()`-based calls structurally cannot catch —
 * a hardcoded/wrong client-side URL, a `<Can>`-gated button that never
 * renders, a `Select`/`Checkbox` wired to the wrong form field, or an error
 * response that never actually surfaces in the rendered UI — the same class
 * that hit `staff-invite-nav.spec.ts`'s feature twice before a real-browser
 * spec existed for it. Every navigation below clicks the real rendered
 * sidebar/tab `<Link>` and submits the real rendered form's button — never
 * `route()` or `page.goto()` straight to a destination, except the
 * deliberate permission-denied checks, where hitting the URL directly is the
 * point: proving the server still blocks it even though the UI never exposes
 * a path there.
 *
 * Run the same way as the other specs in this directory (see
 * staff-invite-nav.spec.ts's header comment for the `@playwright/test` npx
 * caveat on this Windows/Node setup):
 *
 *   cd backend
 *   npx --yes @playwright/test@1.63.0 test tests/e2e/admin-vehicles-crud-nav.spec.ts
 */

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BACKEND_ROOT = path.resolve(__dirname, '..', '..');

const E2E_PASSWORD = 'E2EVehiclesCrud!2026';

const ECOMMERCE_EMAIL = 'e2e-ecommerce-crud@tiro.test';
const OPERATIONS_EMAIL = 'e2e-operations-crud@tiro.test';
const FLEET_EMAIL = 'e2e-fleet-crud@tiro.test';

const totpSecrets: Record<'ecommerce' | 'operations' | 'fleet', string> = {
    ecommerce: '',
    operations: '',
    fleet: '',
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
    // Reuses the same fixed-credential ecommerce/operations/fleet accounts
    // as admin-catalog-crud-nav.spec.ts (RBAC tiers are identical: operations
    // = vehicles.manage, ecommerce = vehicles.view, fleet = no vehicles
    // access at all — database/seeders/RolesAndPermissionsSeeder.php). A
    // fresh TOTP secret is generated every run rather than reusing a fixed
    // one — see staff-invite-nav.spec.ts's header comment for why (Fortify's
    // replay cache would spuriously reject a same-window re-run).
    const php = `
        $google2fa = app(PragmaRX\\Google2FA\\Google2FA::class);
        $roles = [
            'ecommerce' => '${ECOMMERCE_EMAIL}',
            'operations' => '${OPERATIONS_EMAIL}',
            'fleet' => '${FLEET_EMAIL}',
        ];
        foreach ($roles as $role => $email) {
            $secret = $google2fa->generateSecretKey();
            $user = App\\Models\\User::firstOrNew(['email' => $email]);
            $user->name = "E2E " . ucfirst($role) . " CRUD";
            $user->password = Illuminate\\Support\\Facades\\Hash::make('${E2E_PASSWORD}');
            $user->email_verified_at = now();
            $user->two_factor_secret = encrypt($secret);
            $user->two_factor_recovery_codes = encrypt(json_encode(['recovery-code-1']));
            $user->two_factor_confirmed_at = now();
            $user->save();
            $user->syncRoles([$role]);
            echo "{$role}:{$secret}\\n";
        }
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

    for (const role of ['ecommerce', 'operations', 'fleet'] as const) {
        const match = output.match(new RegExp(`${role}:([A-Z2-7]{16,})`));
        if (!match) {
            throw new Error(
                `Could not parse a generated TOTP secret for role "${role}" out of tinker's output:\n${output}`,
            );
        }
        totpSecrets[role] = match[1];
    }
});

test('an operations-role staff member can create a vehicle, flip it to staggered fitment via the real form, hit the real duplicate-vehicle validation error, then delete it', async ({
    page,
}) => {
    test.setTimeout(90_000);

    await loginWithTotp(
        page,
        BASE_URL,
        OPERATIONS_EMAIL,
        E2E_PASSWORD,
        totpSecrets.operations,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    await page.getByRole('link', { name: 'Vehicles' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/vehicles`);
    await expect(page.getByRole('heading', { name: 'Vehicles' })).toBeVisible();

    const make = `E2E Make ${Date.now()}`;
    const model = 'CRUD Model';

    // --- Create: non-staggered, single "all" fitment row ---
    await page.getByRole('button', { name: 'New vehicle' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Make').fill(make);
    await createDialog.getByLabel('Model').fill(model);
    await createDialog.getByLabel('Year from').fill('2019');
    await createDialog.getByLabel('Year to').fill('2023');
    await createDialog.locator('#all-width').fill('205');
    await createDialog.locator('#all-profile').fill('55');
    await createDialog.locator('#all-rim').fill('16');

    const [createResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/vehicles` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Create vehicle' }).click(),
    ]);
    expect(createResponse.status()).toBeLessThan(400);

    const vehicleRow = page.locator('tr', { hasText: make });
    await expect(vehicleRow).toBeVisible();
    await expect(vehicleRow.getByText('205/55R16')).toBeVisible();

    // --- Real duplicate-vehicle validation error, rendered in the dialog ---
    // (mirrors the Pest coverage in VehicleManagementTest.php, but proves the
    // 422/session error actually surfaces in the rendered UI instead of just
    // asserting the HTTP response).
    await page.getByRole('button', { name: 'New vehicle' }).click();
    const dupDialog = page.getByRole('dialog');
    await dupDialog.getByLabel('Make').fill(make);
    await dupDialog.getByLabel('Model').fill(model);
    await dupDialog.getByLabel('Year from').fill('2019');
    await dupDialog.getByLabel('Year to').fill('2023');
    await dupDialog.locator('#all-width').fill('205');
    await dupDialog.locator('#all-profile').fill('55');
    await dupDialog.locator('#all-rim').fill('16');

    const [dupResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/vehicles` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Create vehicle' }).click(),
    ]);
    // Inertia surfaces validation failures as a normal 200-with-shared-errors
    // response on the same page (never a raw 4xx the client has to catch),
    // so the meaningful assertion is the rendered error text, not the status.
    expect(dupResponse.status()).toBeLessThan(400);
    await expect(dupDialog.getByText(/already exists/i)).toBeVisible();
    await page.keyboard.press('Escape');

    // --- Edit: flip is_staggered on and confirm the sub-form's shape
    // actually changes from one "all" row to two "front"/"rear" rows ---
    await vehicleRow.getByRole('button', { name: 'Edit' }).click();
    const editDialog = page.getByRole('dialog');
    await expect(editDialog.locator('#all-width')).toBeVisible();
    await expect(editDialog.locator('#front-width')).toHaveCount(0);

    await editDialog.getByLabel(/Staggered fitment/).check();

    await expect(editDialog.locator('#all-width')).toHaveCount(0);
    await expect(editDialog.locator('#front-width')).toBeVisible();
    await expect(editDialog.locator('#rear-width')).toBeVisible();

    await editDialog.locator('#front-width').fill('225');
    await editDialog.locator('#front-profile').fill('40');
    await editDialog.locator('#front-rim').fill('18');
    await editDialog.locator('#rear-width').fill('245');
    await editDialog.locator('#rear-profile').fill('35');
    await editDialog.locator('#rear-rim').fill('18');

    const idForUpdate = await vehicleId(page, make);
    const [updateResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/vehicles/${idForUpdate}` &&
                res.request().method() === 'PUT',
        ),
        editDialog.getByRole('button', { name: 'Save changes' }).click(),
    ]);
    expect(updateResponse.status()).toBeLessThan(400);
    await expect(vehicleRow.getByText(/F 225\/40R18/)).toBeVisible();
    await expect(vehicleRow.getByText(/R 245\/35R18/)).toBeVisible();

    stopWatchingErrors();

    // --- Delete ---
    await vehicleRow.getByRole('button', { name: 'Delete' }).click();
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Delete vehicle' })
        .click();
    await expect(page.locator('tr', { hasText: make })).toHaveCount(0);
});

/** Reads the just-created vehicle's numeric id straight from the DB by its unique make, so the PUT's URL can be asserted precisely. */
async function vehicleId(page: Page, make: string): Promise<number> {
    const output = execFileSync(
        'php',
        [
            'artisan',
            'tinker',
            '--execute',
            `echo App\\Models\\Vehicle::where('make', '${make}')->value('id');`,
        ],
        { cwd: BACKEND_ROOT, encoding: 'utf-8' },
    );
    const id = Number(output.trim());
    if (!Number.isInteger(id) || id <= 0) {
        throw new Error(
            `Could not resolve a vehicle id for make "${make}" from tinker output:\n${output}`,
        );
    }
    return id;
}

test('an ecommerce-role (vehicles.view only) staff member sees the read-only Vehicles list but no manage controls, and the server still blocks direct hits to the manage-only routes', async ({
    page,
}) => {
    test.setTimeout(60_000);

    await loginWithTotp(
        page,
        BASE_URL,
        ECOMMERCE_EMAIL,
        E2E_PASSWORD,
        totpSecrets.ecommerce,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    await page.getByRole('link', { name: 'Vehicles' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/vehicles`);
    await expect(page.getByRole('heading', { name: 'Vehicles' })).toBeVisible();

    // view-only: no "New vehicle" button, and no "Bulk import" tab (both are
    // <Can permission="vehicles.manage"> gated).
    await expect(page.getByRole('button', { name: 'New vehicle' })).toHaveCount(
        0,
    );
    await expect(page.getByRole('link', { name: 'Bulk import' })).toHaveCount(
        0,
    );

    stopWatchingErrors();

    // Server-side gating, hit directly -- not just "the UI hides it".
    const importPage = await page.goto(`${BASE_URL}/admin/vehicles/import`);
    expect(importPage?.status()).toBe(403);

    // A raw page.request POST doesn't get the XSRF-TOKEN header Inertia's
    // in-browser XHR client attaches automatically -- read it from the
    // session's own cookie jar so this proves the `permission:vehicles.manage`
    // gate itself denies it (403), not an incidental CSRF short-circuit (419).
    const cookies = await page.context().cookies();
    const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
    if (!xsrfCookie) {
        throw new Error('Expected an XSRF-TOKEN cookie to be set after login.');
    }

    const storeAttempt = await page.request.post(`${BASE_URL}/admin/vehicles`, {
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie.value),
        },
        multipart: {
            make: 'Should Not Persist',
            model: 'Should Not Persist',
            year_from: '2020',
            year_to: '2021',
            status: 'active',
            is_staggered: 'false',
            'fitments[0][position]': 'all',
            'fitments[0][width]': '205',
            'fitments[0][profile]': '55',
            'fitments[0][rim_diameter]': '16',
            'fitments[0][confidence]': 'confirmed',
        },
        failOnStatusCode: false,
    });
    expect(storeAttempt.status()).toBe(403);
});

test('a fleet-role staff member (no vehicles access at all) cannot see or reach the Vehicles module', async ({
    page,
}) => {
    test.setTimeout(60_000);

    await loginWithTotp(
        page,
        BASE_URL,
        FLEET_EMAIL,
        E2E_PASSWORD,
        totpSecrets.fleet,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    await expect(page.getByRole('link', { name: 'Vehicles' })).toHaveCount(0);

    stopWatchingErrors();

    const denied = await page.goto(`${BASE_URL}/admin/vehicles`);
    expect(denied?.status()).toBe(403);
});

test('an operations-role staff member reaches the bulk import screen via the real tab, sees a malformed row error surfaced per-row in a dry run, then completes a real import', async ({
    page,
}) => {
    test.setTimeout(90_000);

    await loginWithTotp(
        page,
        BASE_URL,
        OPERATIONS_EMAIL,
        E2E_PASSWORD,
        totpSecrets.operations,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    await page.getByRole('link', { name: 'Vehicles' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/vehicles`);

    // Reach the import screen via the real rendered tab, not page.goto().
    await page.getByRole('link', { name: 'Bulk import' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/vehicles/import`);
    // "Bulk fitment import" is a shadcn CardTitle (a styled <div>, not a
    // heading element), so it isn't exposed with an accessible heading role.
    await expect(page.getByText('Bulk fitment import')).toBeVisible();

    const stamp = Date.now();
    const goodMake = `E2E Import Good ${stamp}`;
    const badMake = `E2E Import Bad ${stamp}`;
    const header =
        'make,model,series,body_type,year_from,year_to,position,width,profile,rim_diameter,load_index,speed_rating,is_staggered,source,confidence,notes';
    const dryRunCsv = [
        header,
        `${goodMake},Model A,,,2020,2024,all,205,55,16,91,V,false,manual,confirmed,`,
        `${badMake},Model B,,,2020,2024,sideways,225,60,17,99,H,false,manual,confirmed,`,
    ].join('\n');

    const tmpDir = mkdtempSync(path.join(tmpdir(), 'e2e-fitment-import-'));
    const dryRunPath = path.join(tmpDir, 'dry-run.csv');
    writeFileSync(dryRunPath, dryRunCsv);

    await page.locator('#file').setInputFiles(dryRunPath);
    await page.getByLabel(/Validate only/).check();

    const [dryRunResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/vehicles/import` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Validate file' }).click(),
    ]);
    expect(dryRunResponse.status()).toBeLessThan(400);

    // The crux assertion: the per-row error is actually rendered, not just
    // present in the HTTP payload Pest already checks.
    await expect(page.getByText('Dry-run result')).toBeVisible();
    await expect(page.getByText(/1 row\(s\) had errors/)).toBeVisible();
    await expect(page.getByText(/Unrecognized position value/)).toBeVisible();

    // Dry run must not have persisted anything. This originally used a hard
    // `page.goto()` instead of a sidebar `<Link>` click to route around a
    // real bug: the sidebar's "Vehicles" link had Inertia's `prefetch`
    // enabled (resources/js/components/nav-modules.tsx), and clicking back to
    // a `prefetch`-cached page after mutating data on a *different* page
    // (this import screen) could serve a stale cached response instead of
    // re-fetching -- confirmed by comparing a same-session `Link` click
    // (stale: showed "No vehicles yet" with 6 real rows in the DB) against a
    // genuinely fresh page load (correct). `prefetch` has since been removed
    // from every sidebar/nav `Link` (nav-modules.tsx, nav-main.tsx,
    // app-sidebar.tsx, user-menu-content.tsx, app-header.tsx), so that
    // staleness class of bug no longer applies -- see the real sidebar-link
    // click and assertion after the *real* import below, which exercises the
    // fix directly. This assertion is still a negative one (rows must be
    // absent), which a stale cache wouldn't have broken either way, so it
    // keeps `page.goto()` for a guaranteed-fresh read rather than being
    // rewritten just for its own sake.
    await page.goto(`${BASE_URL}/admin/vehicles`);
    await expect(page.locator('tr', { hasText: goodMake })).toHaveCount(0);
    await expect(page.locator('tr', { hasText: badMake })).toHaveCount(0);

    // --- Now a real (non-dry-run) import of a fully valid file ---
    await page.getByRole('link', { name: 'Bulk import' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/vehicles/import`);

    const realMake = `E2E Import Real ${stamp}`;
    const realCsv = [
        header,
        `${realMake},Model C,,,2021,2025,all,215,60,17,95,H,false,manual,confirmed,`,
    ].join('\n');
    const realPath = path.join(tmpDir, 'real-import.csv');
    writeFileSync(realPath, realCsv);

    await page.locator('#file').setInputFiles(realPath);
    // Uncheck the still-checked dry-run box from the prior submission's
    // re-rendered form state before doing a real import.
    const dryRunCheckbox = page.getByLabel(/Validate only/);
    if (await dryRunCheckbox.isChecked()) {
        await dryRunCheckbox.uncheck();
    }

    const [realResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/vehicles/import` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Import file' }).click(),
    ]);
    expect(realResponse.status()).toBeLessThan(400);
    await expect(page.getByText('All rows imported cleanly')).toBeVisible();

    stopWatchingErrors();

    // Regression coverage for the prefetch-staleness bug itself: nav-modules.tsx
    // no longer sets `prefetch` on the sidebar `Link`s, so a real click on the
    // sidebar's "Vehicles" link (not a `page.goto()` workaround) must now show
    // the just-imported row -- this is the exact repro qa-lead reported
    // (bulk-import a row, click the sidebar link, see stale "No vehicles yet"
    // instead of the fresh DB state). Scoped to the sidebar container because
    // this page's breadcrumbs also render their own "Vehicles" link.
    await page
        .locator('[data-sidebar="sidebar"]')
        .getByRole('link', { name: 'Vehicles' })
        .click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/vehicles`);
    await expect(page.locator('tr', { hasText: realMake })).toBeVisible();
});
