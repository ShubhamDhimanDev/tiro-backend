import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { loginWithTotp } from './support/login';

/**
 * Real-browser coverage for the Products, Inventory, and Locations admin
 * CRUD screens (Phase 1: Catalogue, Location & Serviceability Engine).
 *
 * This phase's summary claimed these screens were "built and verified", but
 * that verification was ad hoc/interactive and predates the staff-invite-nav
 * bug class documented in `staff-invite-nav.spec.ts` -- a hardcoded, wrong
 * URL string in a React file (instead of a Wayfinder-generated helper)
 * survived TWO rounds of Pest-only "fixed and verified" sign-off because
 * `route()` always resolves the correct URL from the route name and can
 * never catch a hardcoded wrong string client-side. The same risk class
 * applies to every module here and had no browser-level regression guard
 * until this spec. Every navigation below clicks the real rendered sidebar
 * `<Link>` and submits the real rendered dialog form's button -- never
 * `route()` or `page.goto()` straight to a destination (the one deliberate
 * exception is the permission-denied checks, where hitting the URL directly
 * is the point: proving the server still blocks it even though the UI never
 * exposes a path there).
 *
 * Field names, roles, and permission slugs below were taken from the real
 * Pest coverage and seeder, not guessed:
 * - tests/Feature/Admin/Products/BrandManagementTest.php
 * - tests/Feature/Admin/Inventory/StockLocationManagementTest.php
 * - tests/Feature/Admin/Locations/StateManagementTest.php
 * - database/seeders/RolesAndPermissionsSeeder.php (role -> permission bundle)
 *
 * Run the same way as `staff-invite-nav.spec.ts` (see its header comment for
 * why `@playwright/test` is intentionally not in package.json):
 *
 *   cd backend
 *   npx --yes @playwright/test@1.63.0 test tests/e2e
 */

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BACKEND_ROOT = path.resolve(__dirname, '..', '..');

const E2E_PASSWORD = 'E2ECatalogCrud!2026';

const ECOMMERCE_EMAIL = 'e2e-ecommerce-crud@tiro.test';
const OPERATIONS_EMAIL = 'e2e-operations-crud@tiro.test';
const FLEET_EMAIL = 'e2e-fleet-crud@tiro.test';

const totpSecrets: Record<'ecommerce' | 'operations' | 'fleet', string> = {
    ecommerce: '',
    operations: '',
    fleet: '',
};

// Real AU state codes already seeded (see LocationSeeder) -- excluded so a
// freshly generated random code can't collide with data that already exists.
const RESERVED_STATE_CODES = new Set([
    'NSW',
    'VIC',
    'QLD',
    'WA',
    'SA',
    'TAS',
    'ACT',
    'NT',
]);

function randomStateCode(): string {
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    let code: string;
    do {
        code = Array.from(
            { length: 3 },
            () => letters[Math.floor(Math.random() * letters.length)],
        ).join('');
    } while (RESERVED_STATE_CODES.has(code));
    return code;
}

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
    // One tinker call provisions all three fixed-credential staff users used
    // below, each with a freshly generated TOTP secret (see
    // staff-invite-nav.spec.ts's header comment for why a fresh secret is
    // required every run rather than a reused fixed one -- Fortify's replay
    // cache would spuriously reject a re-run within the same 30s window).
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
        const match = output.match(
            new RegExp(`${role}:([A-Z2-7]{16,})`),
        );
        if (!match) {
            throw new Error(
                `Could not parse a generated TOTP secret for role "${role}" out of tinker's output:\n${output}`,
            );
        }
        totpSecrets[role] = match[1];
    }
});

test('an ecommerce-role staff member can create a brand via the real Products nav + form, and cannot see or reach Locations', async ({
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

    // Reach the screen via the real rendered sidebar link.
    await page.getByRole('link', { name: 'Products' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/products/brands`);
    await expect(page.getByRole('heading', { name: 'Brands' })).toBeVisible();

    // Create a record via the real rendered form.
    const brandName = `E2E Brand ${Date.now()}`;
    const brandSlug = `e2e-brand-${Date.now()}`;

    await page.getByRole('button', { name: 'New brand' }).click();
    await page.getByLabel('Name').fill(brandName);
    await page.getByLabel('Slug').fill(brandSlug);
    await page.getByLabel('Country of origin').fill('Testland');

    const [response] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/products/brands` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Create brand' }).click(),
    ]);

    expect(response.status()).toBeLessThan(400);
    await expect(page.getByRole('link', { name: brandName })).toBeVisible();

    stopWatchingErrors();

    // Permission-denied check: ecommerce holds no `locations.*` permission
    // at all (database/seeders/RolesAndPermissionsSeeder.php), so the nav
    // item must not render, and the route must still refuse a direct visit.
    await expect(page.getByRole('link', { name: 'Locations' })).toHaveCount(
        0,
    );
    const denied = await page.goto(`${BASE_URL}/admin/locations/states`);
    expect(denied?.status()).toBe(403);
});

test('an operations-role staff member can create a stock location via the real Inventory nav + form, then a state via the real Locations nav + form', async ({
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

    // --- Inventory ---
    await page.getByRole('link', { name: 'Inventory' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/inventory/locations`);
    await expect(
        page.getByRole('heading', { name: 'Stock locations' }),
    ).toBeVisible();

    const locationName = `E2E Depot ${Date.now()}`;

    await page.getByRole('button', { name: 'New stock location' }).click();
    await page.getByLabel('Name').fill(locationName);
    await page
        .getByLabel('Address')
        .fill('1 Example St, Melbourne VIC 3000');
    await page.getByLabel('Latitude').fill('-37.8136');
    await page.getByLabel('Longitude').fill('144.9631');

    const [locationResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/inventory/locations` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Create location' }).click(),
    ]);

    expect(locationResponse.status()).toBeLessThan(400);
    await expect(
        page.getByRole('link', { name: locationName }),
    ).toBeVisible();

    // --- Locations (from the same session, via the real sidebar link) ---
    await page.getByRole('link', { name: 'Locations' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/locations/states`);
    await expect(page.getByText('States & territories')).toBeVisible();

    const stateCode = randomStateCode();
    const stateName = `E2E Territory ${Date.now()}`;

    await page.getByRole('button', { name: 'New state' }).click();
    await page.getByLabel('Code').fill(stateCode);
    await page.getByLabel('Name').fill(stateName);

    const [stateResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url() === `${BASE_URL}/admin/locations/states` &&
                res.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Create state' }).click(),
    ]);

    expect(stateResponse.status()).toBeLessThan(400);
    await expect(page.getByText(stateCode, { exact: true })).toBeVisible();
    await expect(page.getByText(stateName)).toBeVisible();

    stopWatchingErrors();
});

test('a fleet-role staff member cannot see or reach Products or Inventory', async ({
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

    // fleet holds neither `products.*` nor `inventory.*` permissions
    // (database/seeders/RolesAndPermissionsSeeder.php) -- both nav items
    // must be absent, and both routes must still refuse a direct visit.
    await expect(page.getByRole('link', { name: 'Products' })).toHaveCount(
        0,
    );
    await expect(page.getByRole('link', { name: 'Inventory' })).toHaveCount(
        0,
    );

    stopWatchingErrors();

    const deniedProducts = await page.goto(
        `${BASE_URL}/admin/products/brands`,
    );
    expect(deniedProducts?.status()).toBe(403);

    const deniedInventory = await page.goto(
        `${BASE_URL}/admin/inventory/locations`,
    );
    expect(deniedInventory?.status()).toBe(403);
});
