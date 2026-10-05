<?php

use App\Contracts\Payments\PaymentGateway;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\ServiceZone;
use App\Models\Suburb;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\TyreVariant;
use App\Models\Van;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentGateway;

/**
 * Flexible booking: any window in the day for a fixed discount. The server
 * still assigns a concrete, capacity-checked slot (see
 * App\Services\Bookings\FlexibleBookingPolicy).
 */
beforeEach(function () {
    seedDurationRules();
    Queue::fake();
});

function flexibleSlotsQuery(ServiceZone $zone): string
{
    return '/api/v1/booking-slots?'.http_build_query([
        'zone' => $zone->id,
        'date_from' => bookingMonday(),
        'date_to' => CarbonImmutable::parse(bookingMonday())->addDay()->toDateString(),
    ]);
}

function postFlexibleBooking(array $payload)
{
    return test()->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/bookings', $payload);
}

it('advertises the flexible option on the slots endpoint, overall and per day', function () {
    ['zone' => $zone] = bookableFixture();

    $response = $this->getJson(flexibleSlotsQuery($zone));

    $response->assertOk();
    expect($response->json('data.flexible'))->toBe(['available' => true, 'discount_cents' => 1000, 'label' => 'Flexible arrival: save $10'])
        ->and($response->json('data.days.0.flexible'))->toBe(['available' => true, 'window_start' => '08:00', 'window_end' => '18:00'])
        // Tuesday has no shift, so no assignable slot, so no flexible offer.
        ->and($response->json('data.days.1.flexible'))->toBe(['available' => false, 'window_start' => null, 'window_end' => null]);
});

it('does not offer flexible when nothing is bookable', function () {
    $zone = ServiceZone::factory()->create();

    $response = $this->getJson(flexibleSlotsQuery($zone));

    expect($response->json('data.flexible.available'))->toBeFalse()
        ->and($response->json('data.flexible.discount_cents'))->toBe(1000);
});

it('reflects a changed discount and hides the option when disabled', function () {
    ['zone' => $zone] = bookableFixture();

    config(['bookings.flexible.discount_cents' => 1550]);
    expect($this->getJson(flexibleSlotsQuery($zone))->json('data.flexible'))->toBe(['available' => true, 'discount_cents' => 1550, 'label' => 'Flexible arrival: save $15.50']);

    config(['bookings.flexible.enabled' => false]);
    expect($this->getJson(flexibleSlotsQuery($zone))->json('data.flexible'))->toBe(['available' => false, 'discount_cents' => 0, 'label' => null]);
});

it('assigns the earliest concrete slot for a flexible booking without a slot_start', function () {
    ['zone' => $zone, 'technician' => $technician] = bookableFixture();

    $response = postFlexibleBooking(['service_zone_id' => $zone->id, 'scheduled_date' => bookingMonday(), 'flexible' => true]);

    $response->assertCreated();
    expect($response->json('data.flexible'))->toBeTrue()
        ->and($response->json('data.slot_start'))->toBe('09:00')
        ->and($response->json('data.flexible_window'))->toBe(['start' => '08:00', 'end' => '18:00']);

    $booking = Booking::query()->findOrFail($response->json('data.id'));
    expect($booking->is_flexible)->toBeTrue()->and($booking->technician_id)->toBe($technician->id)->and($booking->status)->toBe(BookingStatus::PendingHold);
});

it('tries a given slot_start first when flexible', function () {
    ['zone' => $zone] = bookableFixture();

    $response = postFlexibleBooking(['service_zone_id' => $zone->id, 'scheduled_date' => bookingMonday(), 'slot_start' => '13:00', 'flexible' => true]);

    $response->assertCreated();
    expect($response->json('data.slot_start'))->toBe('13:00');
});

it('is capacity safe: a flexible booking takes a real window and cannot overlap an existing job', function () {
    ['zone' => $zone] = bookableFixture();

    postFlexibleBooking(storeBookingPayload($zone))->assertCreated();
    $second = postFlexibleBooking(['service_zone_id' => $zone->id, 'scheduled_date' => bookingMonday(), 'flexible' => true]);

    $second->assertCreated();
    // First job ends 09:15 (no items) + 20 min travel buffer; the flexible
    // booking must start after that, on the 15-minute grid.
    expect($second->json('data.slot_start') >= '09:35')->toBeTrue();
    expect(Booking::query()->count())->toBe(2);
});

it('is capacity safe: a full van (max jobs per day) refuses a flexible booking with 409', function () {
    $zone = ServiceZone::factory()->create();
    $van = Van::factory()->create(['max_jobs_per_day' => 1]);
    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id, 'technician_id' => Technician::factory(), 'van_id' => $van->id,
        'date' => bookingMonday(), 'shift_start' => '09:00:00', 'shift_end' => '17:00:00',
    ]);

    postFlexibleBooking(storeBookingPayload($zone))->assertCreated();
    $response = postFlexibleBooking(['service_zone_id' => $zone->id, 'scheduled_date' => bookingMonday(), 'flexible' => true]);

    $response->assertStatus(409);
    expect(Booking::query()->count())->toBe(1);
});

it('409s for a flexible booking on a day with no shifts', function () {
    $zone = ServiceZone::factory()->create();

    postFlexibleBooking(['service_zone_id' => $zone->id, 'scheduled_date' => bookingMonday(), 'flexible' => true])->assertStatus(409);
});

it('requires slot_start unless flexible', function () {
    ['zone' => $zone] = bookableFixture();

    postFlexibleBooking(['service_zone_id' => $zone->id, 'scheduled_date' => bookingMonday()])
        ->assertUnprocessable()->assertJsonValidationErrors(['slot_start']);
});

it('rejects flexible with 422 when the option is switched off', function () {
    ['zone' => $zone] = bookableFixture();
    config(['bookings.flexible.enabled' => false]);

    postFlexibleBooking(['service_zone_id' => $zone->id, 'scheduled_date' => bookingMonday(), 'flexible' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['flexible']);
    expect(Booking::query()->count())->toBe(0);
});

it('a non-flexible booking reports flexible=false and a null window', function () {
    ['zone' => $zone] = bookableFixture();

    $response = postFlexibleBooking(storeBookingPayload($zone));

    expect($response->json('data.flexible'))->toBeFalse()->and($response->json('data.flexible_window'))->toBeNull()->and($response->json('data.promo_code'))->toBeNull();
});

it('rejects a non-boolean flexible value', function () {
    ['zone' => $zone] = bookableFixture();

    postFlexibleBooking(storeBookingPayload($zone, ['flexible' => 'maybe']))->assertUnprocessable()->assertJsonValidationErrors(['flexible']);
});

it('cart/calculate mode 1 applies the flexible discount as a labelled line', function () {
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);

    $response = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id, 'flexible' => true, 'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 2]],
    ]);

    $data = $response->json('data');
    expect($data['flexible_discount'])->toBe(['label' => 'Flexible booking discount', 'amount' => 1000])
        ->and($data['discount_total'])->toBe(1000)
        ->and($data['grand_total'])->toBe(39000)
        ->and($data['discount_lines'])->toBe([['type' => 'flexible', 'label' => 'Flexible booking discount', 'amount' => 1000]])
        ->and($data['lines'][0]['discount_amount'])->toBe(0);
});

it('cart/calculate combines the flexible discount with promotions', function () {
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $promotion = Promotion::factory()->create(['name' => 'Spring Sale', 'value' => 10]);
    PromotionEligibility::factory()->forVariant($variant)->create(['promotion_id' => $promotion->id]);

    $data = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id, 'flexible' => true, 'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 2]],
    ])->json('data');

    expect($data['discount_total'])->toBe(5000)->and($data['grand_total'])->toBe(35000)
        ->and(collect($data['discount_lines'])->pluck('type')->all())->toBe(['promotion', 'flexible']);
});

it('cart/calculate never lets the flexible discount push the total below zero', function () {
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 600]);

    $data = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id, 'flexible' => true, 'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1]],
    ])->json('data');

    expect($data['flexible_discount']['amount'])->toBe(600)->and($data['grand_total'])->toBe(0);
});

it('cart/calculate ignores flexible when the option is disabled', function () {
    config(['bookings.flexible.enabled' => false]);
    $zone = ServiceZone::factory()->create();
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);

    $data = $this->postJson('/api/v1/cart/calculate', [
        'zone_id' => $zone->id, 'flexible' => true, 'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1]],
    ])->json('data');

    expect($data['flexible_discount'])->toBeNull()->and($data['discount_total'])->toBe(0);
});

it('cart/calculate mode 2 applies the discount when the booking is flexible, and only then', function () {
    $variant = TyreVariant::factory()->create(['base_price' => 20000]);
    $flexible = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('tok'), 'is_flexible' => true]);
    $fixed = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('tok')]);
    foreach ([$flexible, $fixed] as $booking) {
        BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 2]);
    }

    $flexibleData = $this->withHeader('X-Booking-Manage-Token', 'tok')->postJson('/api/v1/cart/calculate', ['booking_id' => $flexible->id])->json('data');
    $fixedData = $this->withHeader('X-Booking-Manage-Token', 'tok')->postJson('/api/v1/cart/calculate', ['booking_id' => $fixed->id])->json('data');

    expect($flexibleData['flexible_discount']['amount'])->toBe(1000)->and($flexibleData['grand_total'])->toBe(39000)
        ->and($fixedData['flexible_discount'])->toBeNull()->and($fixedData['grand_total'])->toBe(40000);
});

it('cart/calculate rejects flexible together with booking_id', function () {
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('tok')]);

    $this->withHeader('X-Booking-Manage-Token', 'tok')
        ->postJson('/api/v1/cart/calculate', ['booking_id' => $booking->id, 'flexible' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['flexible']);
});

it('an order for a flexible booking stamps and labels the flexible discount', function () {
    $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('guest-token-123'), 'is_flexible' => true]);
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 4]);
    $suburb = Suburb::factory()->create();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', flexibleOrderPayload($booking, $suburb));

    $response->assertCreated();
    expect($response->json('data.discount_total'))->toBe(1000)
        ->and($response->json('data.flexible_discount'))->toBe(['label' => 'Flexible booking discount', 'amount' => 1000])
        ->and($response->json('data.grand_total'))->toBe(74600);
    expect(Order::query()->sole()->flexible_discount_total)->toBe(1000);
});

it('an order for a normal booking has a null flexible_discount', function () {
    $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
    $variant = TyreVariant::factory()->create(['base_price' => 18900]);
    $booking = Booking::factory()->create(['manage_token_hash' => Booking::hashManageToken('guest-token-123')]);
    BookingLineItem::factory()->create(['booking_id' => $booking->id, 'tyre_variant_id' => $variant->id, 'quantity' => 4]);
    $suburb = Suburb::factory()->create();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->withHeader('X-Booking-Manage-Token', 'guest-token-123')
        ->postJson('/api/v1/orders', flexibleOrderPayload($booking, $suburb));

    expect($response->json('data.flexible_discount'))->toBeNull()->and($response->json('data.discount_total'))->toBe(0);
});

function flexibleOrderPayload(Booking $booking, Suburb $suburb): array
{
    return [
        'booking_id' => $booking->id,
        'customer' => ['name' => 'Jane Citizen', 'email' => 'jane@example.com', 'mobile' => '+61411222333'],
        'address' => ['suburb_id' => $suburb->id, 'line1' => '12 Example St', 'line2' => null, 'lat' => -37.8, 'lng' => 144.9, 'access_instructions' => 'Park in driveway'],
    ];
}
