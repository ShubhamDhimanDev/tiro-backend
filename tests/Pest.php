<?php

use App\Enums\BookingStatus;
use App\Enums\DurationRuleAppliesTo;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\Status;
use App\Models\Booking;
use App\Models\DurationRule;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\User;
use App\Models\Van;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stripe\WebhookSignature;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Moved here from its original home in `StaffUserInviteTest.php` — as a
 * plain function declared inside a single test file, it was only available
 * in a given process when that specific file happened to also be part of
 * the run, which silently broke for any other test file exercising it in
 * isolation (or alongside a different subset of files). `tests/Pest.php` is
 * unconditionally loaded for every run, so this is the one place a shared
 * test helper is actually reliable. Callers still need their own
 * `beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class))` —
 * this only creates the user/role, it doesn't seed the permission tables.
 */
function actingSuperAdmin(): User
{
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

/**
 * Seed the fixed `(applies_to, key)` `DurationRule` vocabulary the booking
 * duration-calculation formula relies on (see
 * docs/architecture/04-booking-capacity-engine.md) — every booking-related
 * test needs at least `base:setup_overhead` to exist, or duration
 * calculation hard-errors by design. Callers can override individual minute
 * values via `$overrides` (e.g. `['tyre_category:car' => 5]`), or omit a
 * combination entirely by overriding it to `null` (e.g. to exercise the
 * missing-rule hard-error path), without having to reseed the whole
 * vocabulary.
 *
 * @param  array<string, int|null>  $overrides
 */
function seedDurationRules(array $overrides = []): void
{
    $defaults = [
        'base:setup_overhead' => 15,
        'tyre_category:car' => 10,
        'tyre_category:suv' => 12,
        'tyre_category:4x4' => 15,
        'tyre_category:light_truck' => 18,
        'tyre_addon:run_flat' => 5,
        'booking_addon:alignment' => 20,
        'booking_addon:locking_nuts' => 5,
        'booking_addon:staggered' => 10,
    ];

    $combinations = array_filter(array_merge($defaults, $overrides), fn (?int $minutes) => $minutes !== null);

    foreach ($combinations as $compositeKey => $minutes) {
        [$appliesTo, $key] = explode(':', $compositeKey, 2);

        DurationRule::query()->updateOrCreate(
            ['applies_to' => DurationRuleAppliesTo::from($appliesTo), 'key' => $key],
            ['minutes' => $minutes, 'status' => Status::Active],
        );
    }
}

/**
 * Moved here from `BookingStoreTest.php` for the same reason
 * `actingSuperAdmin()` was — a plain function declared in one test file
 * isn't reliably available to any other test file exercising
 * `POST /api/v1/bookings` in isolation (e.g. a Phase 5 promo-hold test
 * running without `BookingStoreTest.php` in the same process). `next
 * monday` keeps every booking test's fixture inside a
 * `TechnicianShift`'s Mon-Fri window regardless of which day the suite
 * actually runs on.
 */
function bookingMonday(): string
{
    return CarbonImmutable::parse('next monday')->toDateString();
}

/**
 * @return array{zone: ServiceZone, technician: Technician, van: Van}
 */
function bookableFixture(): array
{
    $zone = ServiceZone::factory()->create();
    $technician = Technician::factory()->create();
    $van = Van::factory()->create();

    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'date' => bookingMonday(),
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    return compact('zone', 'technician', 'van');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storeBookingPayload(ServiceZone $zone, array $overrides = []): array
{
    return array_merge([
        'service_zone_id' => $zone->id,
        'scheduled_date' => bookingMonday(),
        'slot_start' => '09:00',
    ], $overrides);
}

/**
 * Moved here from `StripeWebhookTest.php` for the same reason as the
 * booking-fixture helpers above — `StripeWebhookPromotionTest.php` also
 * needs these, and a Pest test file must be able to run alone.
 *
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function stripeEvent(string $type, array $object, ?string $id = null): array
{
    return [
        'id' => $id ?? 'evt_'.Str::random(24),
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $object],
    ];
}

/**
 * @param  array<string, mixed>  $eventPayload
 * @return array{0: string, 1: string} [json payload, Stripe-Signature header]
 */
function signedStripePayload(array $eventPayload): array
{
    $payload = json_encode($eventPayload);
    $secret = (string) config('services.stripe.webhook_secret');

    return [$payload, WebhookSignature::generateSignatureHeader($payload, $secret)];
}

/**
 * @param  array<string, mixed>  $eventPayload
 */
function postStripeWebhook(array $eventPayload, ?string $signatureOverride = null)
{
    [$payload, $signature] = signedStripePayload($eventPayload);

    return test()->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
        'HTTP_Stripe-Signature' => $signatureOverride ?? $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

/**
 * @param  array<string, mixed>  $bookingOverrides
 * @return array{booking: Booking, order: Order, payment: Payment}
 */
function webhookFixture(array $bookingOverrides = []): array
{
    $booking = Booking::factory()->create(array_merge([
        'status' => BookingStatus::PendingHold,
        'hold_expires_at' => now()->addMinutes(10),
    ], $bookingOverrides));

    $order = Order::factory()->create([
        'booking_id' => $booking->id,
        'status' => OrderStatus::PendingPayment,
        'payment_status' => PaymentStatus::Pending,
    ]);

    $payment = Payment::factory()->create([
        'order_id' => $order->id,
        'type' => PaymentType::Charge,
        'status' => PaymentTransactionStatus::Pending,
        'gateway_reference' => 'pi_'.Str::random(24),
        'amount' => $order->grand_total,
    ]);

    return compact('booking', 'order', 'payment');
}
