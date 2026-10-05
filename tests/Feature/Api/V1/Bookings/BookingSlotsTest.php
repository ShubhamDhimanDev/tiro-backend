<?php

use App\Enums\TyreCategory;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Models\Van;
use Carbon\CarbonImmutable;

/**
 * `GET /api/v1/booking-slots` — see
 * docs/architecture/02-api-contract.md's "Booking & capacity endpoints"
 * section.
 */
beforeEach(function () {
    seedDurationRules();
});

function bookingSlotsMonday(): string
{
    return CarbonImmutable::parse('next monday')->toDateString();
}

it('returns a server-computed duration and per-date slot list', function () {
    $zone = ServiceZone::factory()->create();
    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => Technician::factory(),
        'van_id' => Van::factory(),
        'date' => bookingSlotsMonday(),
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    $model = TyreModel::factory()->create(['category' => TyreCategory::Car, 'run_flat' => false]);
    $variant = TyreVariant::factory()->create(['tyre_model_id' => $model->id]);

    $response = $this->getJson('/api/v1/booking-slots?'.http_build_query([
        'zone' => $zone->id,
        'date_from' => bookingSlotsMonday(),
        'date_to' => bookingSlotsMonday(),
        'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 4, 'position' => 'all']],
    ]));

    $response->assertOk();
    // base(15) + car(10*4=40)
    expect($response->json('data.duration_minutes'))->toBe(55);
    expect($response->json('data.days.0.date'))->toBe(bookingSlotsMonday());
    expect($response->json('data.days.0.slots'))->not->toBeEmpty();
    expect($response->json('data.days.0.slots.0'))->toBe(['start' => '09:00', 'end' => '09:55']);
});

it('represents every requested date even when a day has no availability', function () {
    $zone = ServiceZone::factory()->create();

    $sunday = CarbonImmutable::parse(bookingSlotsMonday())->subDay()->toDateString();

    $response = $this->getJson('/api/v1/booking-slots?'.http_build_query([
        'zone' => $zone->id,
        'date_from' => $sunday,
        'date_to' => $sunday,
    ]));

    $response->assertOk();
    expect($response->json('data.days.0.date'))->toBe($sunday);
    expect($response->json('data.days.0.slots'))->toBe([]);
});

it('404s for an unresolvable zone id', function () {
    $response = $this->getJson('/api/v1/booking-slots?'.http_build_query([
        'zone' => 999999,
        'date_from' => bookingSlotsMonday(),
        'date_to' => bookingSlotsMonday(),
    ]));

    $response->assertStatus(404);
});

it('422s when the requested date range exceeds 14 days', function () {
    $zone = ServiceZone::factory()->create();

    $response = $this->getJson('/api/v1/booking-slots?'.http_build_query([
        'zone' => $zone->id,
        'date_from' => bookingSlotsMonday(),
        'date_to' => CarbonImmutable::parse(bookingSlotsMonday())->addDays(15)->toDateString(),
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors('date_to');
});

it('422s for a malformed item position', function () {
    $zone = ServiceZone::factory()->create();
    $model = TyreModel::factory()->create(['category' => TyreCategory::Car]);
    $variant = TyreVariant::factory()->create(['tyre_model_id' => $model->id]);

    $response = $this->getJson('/api/v1/booking-slots?'.http_build_query([
        'zone' => $zone->id,
        'date_from' => bookingSlotsMonday(),
        'date_to' => bookingSlotsMonday(),
        'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 1, 'position' => 'sideways']],
    ]));

    $response->assertStatus(422)->assertJsonValidationErrors('items.0.position');
});
