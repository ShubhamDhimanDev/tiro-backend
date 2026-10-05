import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { loginWithTotp } from './support/login';

/**
 * Real-browser coverage for two Phase 7 ("Customer Accounts & Notifications")
 * surfaces that Pest cannot honestly verify end to end:
 *
 * 1. The new Customers admin module
 *    (`resources/js/pages/customers/{index,show}.tsx`) — search, the detail
 *    view's profile/saved-vehicles/saved-addresses/order-history/
 *    notifications cards, and permission-gated access across three of the
 *    six roles (`customer_support` and `operations` both hold some tier of
 *    `customers.*`; `fleet` holds none at all).
 * 2. `Admin\Orders\OrderController::updateStatus()`'s Phase 7 gap fix now
 *    *visibly* flipping the linked booking's status badge in the real
 *    rendered Order detail page when an order is marked completed — not
 *    just the underlying `Booking.status` column Pest already asserts on
 *    (`tests/Feature/Admin/Orders/OrderControllerTest.php`).
 *
 * Pest already covers the request/response contract thoroughly
 * (`tests/Feature/Admin/Customers/CustomerControllerTest.php`,
 * `tests/Feature/Admin/Orders/OrderControllerTest.php`). This spec exists
 * for the class of bug Pest's `route()`-based calls structurally cannot
 * catch — a hardcoded/wrong client-side URL, a prop never actually wired
 * into the rendered card, or a `<Select>`/status-update control that posts
 * the wrong value. Same convention as `admin-promotions-crud-nav.spec.ts`
 * (used as the direct reference point here) and
 * `admin-vehicles-crud-nav.spec.ts`.
 *
 * Notable wiring facts pulled from the real source rather than guessed:
 * - The sidebar's single "Customers" module link
 *   (`resources/js/components/app-sidebar.tsx`) is gated `anyOf:
 *   ['customers.view', 'customers.manage']` and lands on the customers
 *   index. The Customers module is read-only this phase (no create/edit UI
 *   at all — see `CustomerControllerTest.php`'s header docblock), so there
 *   is no `.manage`-only element to distinguish in the UI itself; the two
 *   roles below are exercised to prove both permission tiers actually reach
 *   the screen the server allows them to reach, not because the UI differs
 *   between them.
 * - `database/seeders/RolesAndPermissionsSeeder.php`: `customer_support`
 *   holds `customers.manage` (implies `.view`), `operations` holds
 *   `customers.view` only, `fleet` holds neither (no `customers` module
 *   entry at all) — confirmed directly against `ROLE_MODULE_TIERS`, not
 *   assumed.
 * - The Order detail page's "Appointment" card
 *   (`resources/js/pages/orders/show.tsx`) renders `order.booking.status`
 *   as a `<Badge>` right next to the scheduled date/slot — this is the
 *   live element this spec watches flip from "Confirmed" to "Completed".
 *   The status-update control itself is a `<Select>` (options labelled
 *   "Mark {label}") + a separate "Apply" button, gated
 *   `<Can permission="orders.manage">` — both `customer_support` and
 *   `operations` hold `orders.manage`, `operations` is used here since it's
 *   already logged in for the Customers-module portion of this spec.
 *
 * Deliberately does NOT assert on the resulting `BookingCompleted`
 * notification/`NotificationLog` row here — that side effect is queued
 * (`QUEUE_CONNECTION=redis` in this dev environment) and already covered
 * deterministically and synchronously by
 * `tests/Feature/Admin/Orders/OrderControllerTest.php`'s
 * "dispatches BookingCompleted ... end to end" case; asserting on an
 * async queue side effect from a browser test would only add flakiness for
 * no additional confidence.
 *
 * Run the same way as the other specs in this directory (see
 * `staff-invite-nav.spec.ts`'s header comment for the `@playwright/test`
 * npx caveat on this Windows/Node setup):
 *
 *   cd backend
 *   npx --yes @playwright/test@1.63.0 test tests/e2e/admin-customers-and-order-completion-nav.spec.ts
 */

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BACKEND_ROOT = path.resolve(__dirname, '..', '..');

const E2E_PASSWORD = 'E2ECustomersOrders!2026';

const SUPPORT_EMAIL = 'e2e-support-customers@tiro.test';
const OPERATIONS_EMAIL = 'e2e-operations-customers@tiro.test';
const FLEET_EMAIL = 'e2e-fleet-customers@tiro.test';

const STAMP = Date.now();
const CUSTOMER_NAME = `E2E Jane Customer ${STAMP}`;
const CUSTOMER_EMAIL = `e2e-jane-customer-${STAMP}@example.com`;
const VEHICLE_LABEL = `E2E Daily Driver ${STAMP}`;
const ADDRESS_LABEL = `E2E Home Address ${STAMP}`;
const COMPLETION_ORDER_STAMP = `E2E Completion Order ${STAMP}`;

const totpSecrets: Record<'customer_support' | 'operations' | 'fleet', string> =
    {
        customer_support: '',
        operations: '',
        fleet: '',
    };

let completionOrderNumber = '';
let seededCustomerId = '';

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
    // required each run rather than a reused fixed one. Also seeds a
    // customer with a saved vehicle, a saved address, a confirmed order with
    // a confirmed linked booking, and a notification-log row (the Customers
    // detail page's four cards), plus a second, separate confirmed order +
    // confirmed booking for the order-completion flow -- deliberately not
    // relying on whatever customer/order data happens to already exist in
    // the shared dev DB, so this spec's row lookups are unambiguous.
    const php = `
        $google2fa = app(PragmaRX\\Google2FA\\Google2FA::class);
        $roles = [
            'customer_support' => '${SUPPORT_EMAIL}',
            'operations' => '${OPERATIONS_EMAIL}',
            'fleet' => '${FLEET_EMAIL}',
        ];
        foreach ($roles as $role => $email) {
            $secret = $google2fa->generateSecretKey();
            $user = App\\Models\\User::firstOrNew(['email' => $email]);
            $user->name = 'E2E ' . ucfirst(str_replace('_', ' ', $role)) . ' Customers';
            $user->password = Illuminate\\Support\\Facades\\Hash::make('${E2E_PASSWORD}');
            $user->email_verified_at = now();
            $user->two_factor_secret = encrypt($secret);
            $user->two_factor_recovery_codes = encrypt(json_encode(['recovery-code-1']));
            $user->two_factor_confirmed_at = now();
            $user->save();
            $user->syncRoles([$role]);
            echo "{$role}:{$secret}\\n";
        }

        $customer = App\\Models\\Customer::factory()->create([
            'name' => '${CUSTOMER_NAME}',
            'email' => '${CUSTOMER_EMAIL}',
        ]);
        echo "customer_id:{$customer->id}\\n";

        App\\Models\\CustomerVehicle::factory()->create([
            'customer_id' => $customer->id,
            'label' => '${VEHICLE_LABEL}',
            'saved_fitment' => ['all' => ['width' => 225, 'profile' => 45, 'rim_diameter' => 17]],
            'is_default' => true,
        ]);

        App\\Models\\Address::factory()->create([
            'customer_id' => $customer->id,
            'label' => '${ADDRESS_LABEL}',
            'is_default' => true,
        ]);

        // A raw query-builder update (not forceFill()->save()) deliberately
        // bypasses Eloquent model events here -- forceFill()->save() would
        // trigger the real App\\Observers\\BookingNotificationObserver (the
        // booking's factory-default status is pending_hold, so this would be
        // a real pending_hold -> confirmed transition), dispatching a real,
        // queued BookingConfirmed notification via the redis queue this dev
        // environment uses. That's a real pipeline worth exercising, but not
        // deterministically from a seed script -- it would race the
        // dedicated NotificationLog row seeded explicitly below and produce
        // two 'booking.confirmed' rows instead of one (confirmed directly:
        // this spec's first run against forceFill()->save() here produced
        // exactly that -- a strict-mode Playwright violation on two matching
        // notification rows).
        $order = App\\Models\\Order::factory()->confirmed()->create(['customer_id' => $customer->id]);
        App\\Models\\Booking::query()->where('id', $order->booking->id)->update([
            'status' => App\\Enums\\BookingStatus::Confirmed,
            'customer_id' => $customer->id,
        ]);

        App\\Models\\NotificationLog::factory()->create([
            'notifiable_type' => App\\Models\\Customer::class,
            'notifiable_id' => $customer->id,
            'type' => 'booking.confirmed',
            'channel' => App\\Enums\\NotificationChannel::Mail,
            'status' => App\\Enums\\NotificationDeliveryStatus::Sent,
            'recipient' => $customer->email,
            'related_type' => App\\Models\\Booking::class,
            'related_id' => $order->booking->id,
        ]);

        $completionCustomer = App\\Models\\Customer::factory()->create(['name' => '${COMPLETION_ORDER_STAMP}']);
        $completionOrder = App\\Models\\Order::factory()->confirmed()->create(['customer_id' => $completionCustomer->id]);
        App\\Models\\Booking::query()->where('id', $completionOrder->booking->id)->update([
            'status' => App\\Enums\\BookingStatus::Confirmed,
            'customer_id' => $completionCustomer->id,
        ]);
        echo "completion_order_number:{$completionOrder->order_number}\\n";

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

    for (const role of ['customer_support', 'operations', 'fleet'] as const) {
        const match = output.match(new RegExp(`${role}:([A-Z2-7]{16,})`));
        if (!match) {
            throw new Error(
                `Could not parse a generated TOTP secret for role "${role}" out of tinker's output:\n${output}`,
            );
        }
        totpSecrets[role] = match[1];
    }

    const orderNumberMatch = output.match(
        /completion_order_number:(\S+)/,
    );
    if (!orderNumberMatch) {
        throw new Error(
            `Could not parse the seeded completion order's number out of tinker's output:\n${output}`,
        );
    }
    completionOrderNumber = orderNumberMatch[1];

    const customerIdMatch = output.match(/customer_id:(\d+)/);
    if (!customerIdMatch) {
        throw new Error(
            `Could not parse the seeded customer's id out of tinker's output:\n${output}`,
        );
    }
    seededCustomerId = customerIdMatch[1];
});

test('a customer_support-role staff member (customers.manage) can reach the Customers module via the real sidebar link, search by name, and see the real rendered detail page with saved vehicle, saved address, order history, and notification cards', async ({
    page,
}) => {
    test.setTimeout(90_000);

    await loginWithTotp(
        page,
        BASE_URL,
        SUPPORT_EMAIL,
        E2E_PASSWORD,
        totpSecrets.customer_support,
    );

    const stopWatchingErrors = watchForConsoleErrors(page);

    await page.getByRole('link', { name: 'Customers' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/customers`);
    await expect(
        page.getByRole('heading', { name: 'Customers' }),
    ).toBeVisible();

    await page.getByLabel('Name, email, or mobile').fill(CUSTOMER_NAME);
    await page.getByRole('button', { name: 'Search' }).click();

    const customerRow = page.locator('tr', { hasText: CUSTOMER_NAME });
    await expect(customerRow).toBeVisible();
    await expect(customerRow.getByText(CUSTOMER_EMAIL)).toBeVisible();

    await customerRow.getByRole('link', { name: 'View' }).click();
    await expect(page).toHaveURL(/\/admin\/customers\/\d+$/);
    await expect(
        page.getByRole('heading', { name: CUSTOMER_NAME }),
    ).toBeVisible();

    // Profile card
    await expect(page.getByText(CUSTOMER_EMAIL).first()).toBeVisible();

    // Saved vehicles card. `CardTitle` (resources/js/components/ui/card.tsx)
    // renders a plain `<div>`, not a semantic heading element, so these
    // section labels are matched by text rather than `getByRole('heading')`
    // -- confirmed by reading the component and by this spec's own first
    // failed run against `getByRole('heading', ...)` (every one of these
    // labels was present in the accessibility snapshot as a plain "text"
    // node, not a "heading" node).
    await expect(page.getByText('Saved vehicles', { exact: true })).toBeVisible();
    await expect(page.getByText(VEHICLE_LABEL)).toBeVisible();
    await expect(page.getByText('225/45R17')).toBeVisible();

    // Saved addresses card
    await expect(page.getByText('Saved addresses', { exact: true })).toBeVisible();
    await expect(page.getByText(ADDRESS_LABEL)).toBeVisible();

    // Order history card
    await expect(page.getByText('Order history', { exact: true })).toBeVisible();
    await expect(page.getByText('Confirmed', { exact: true })).toBeVisible();

    // Recent notifications card
    await expect(
        page.getByText('Recent notifications', { exact: true }),
    ).toBeVisible();
    await expect(page.getByText('booking.confirmed')).toBeVisible();

    stopWatchingErrors();
});

test('an operations-role staff member (customers.view only, not .manage) can still reach the Customers module and its detail page, and marking a confirmed order completed via the real Order detail page visibly flips the linked booking status badge (Phase 7 gap fix, live in the browser)', async ({
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

    // --- customers.view reaches the module and the detail page ---
    await page.getByRole('link', { name: 'Customers' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/customers`);

    await page.getByLabel('Name, email, or mobile').fill(CUSTOMER_NAME);
    await page.getByRole('button', { name: 'Search' }).click();

    const customerRow = page.locator('tr', { hasText: CUSTOMER_NAME });
    await expect(customerRow).toBeVisible();
    await customerRow.getByRole('link', { name: 'View' }).click();
    await expect(
        page.getByRole('heading', { name: CUSTOMER_NAME }),
    ).toBeVisible();

    // --- orders.manage: real "mark completed" flow, watching the live booking-status badge ---
    await page.getByRole('link', { name: 'Orders' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/admin/orders`);

    await page
        .getByLabel('Order number or customer')
        .fill(completionOrderNumber);
    await page.getByRole('button', { name: 'Search' }).click();

    const orderRow = page.locator('tr', { hasText: completionOrderNumber });
    await expect(orderRow).toBeVisible();
    await orderRow.getByRole('link', { name: 'View' }).click();
    await expect(page).toHaveURL(/\/admin\/orders\/\d+$/);

    // `CardTitle` (resources/js/components/ui/card.tsx) renders a plain
    // `<div>`, not a semantic heading -- scope by the `Card` root's own
    // `data-slot="card"` attribute (unique per card, siblings never nested
    // inside one another) instead of `getByRole('heading', ...)`, which
    // never matches here.
    const appointmentCard = page
        .locator('[data-slot="card"]')
        .filter({ has: page.getByText('Appointment', { exact: true }) });
    await expect(appointmentCard.getByText('Confirmed', { exact: true })).toBeVisible();

    // `StatusUpdateControl` (resources/js/pages/orders/show.tsx) has no
    // `<Label>` wired to its `<Select>` -- unlike every labeled `<Select>`
    // in the promotions/price-rule forms `getByLabel` works for elsewhere in
    // this repo's specs -- so this targets the trigger by its own
    // placeholder text instead (rendered as the `<SelectValue>`'s visible
    // text content until a value is chosen).
    await page.getByText('Set status…', { exact: true }).click();
    await page.getByRole('option', { name: 'Mark Completed' }).click();

    const [updateResponse] = await Promise.all([
        page.waitForResponse(
            (res) =>
                res.url().includes('/admin/orders/') &&
                res.url().endsWith('/status') &&
                res.request().method() === 'PATCH',
        ),
        page.getByRole('button', { name: 'Apply' }).click(),
    ]);
    expect(updateResponse.status()).toBeLessThan(400);

    // The order-level status badge and the Appointment card's booking-status
    // badge both flip on the same page, live, with no manual reload -- this
    // is the browser-level confirmation of the Phase 7 gap fix
    // (`OrderController::updateStatus()` also completing the linked booking).
    await expect(
        page.getByRole('heading', { name: `Order ${completionOrderNumber}` }),
    ).toBeVisible();
    await expect(appointmentCard.getByText('Completed')).toBeVisible();
    await expect(appointmentCard.getByText('Confirmed')).not.toBeVisible();

    stopWatchingErrors();
});

test('a fleet-role staff member (no customers permission at all) cannot see the Customers nav link, and the server still blocks direct hits to both the index and a specific customer\'s detail route', async ({
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

    // fleet holds no `customers` module entry at all
    // (database/seeders/RolesAndPermissionsSeeder.php) -- the sidebar item
    // is gated `anyOf: ['customers.view', 'customers.manage']`, so it must
    // not render at all.
    await expect(page.getByRole('link', { name: 'Customers' })).toHaveCount(
        0,
    );

    stopWatchingErrors();

    // Deliberate exception to "never page.goto() straight to a destination"
    // -- this is the missing browser-level half of the Pest gate-denial
    // coverage: proving the server still blocks a direct hit even though the
    // UI never exposes a path there. Covers both the index and a specific
    // customer's detail route.
    const deniedIndex = await page.goto(`${BASE_URL}/admin/customers`);
    expect(deniedIndex?.status()).toBe(403);

    const deniedShow = await page.goto(
        `${BASE_URL}/admin/customers/${seededCustomerId}`,
    );
    expect(deniedShow?.status()).toBe(403);
});
