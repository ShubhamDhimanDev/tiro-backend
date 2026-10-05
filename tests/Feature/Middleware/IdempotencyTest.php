<?php

use App\Models\ServiceZone;

/**
 * `App\Http\Middleware\Idempotency` — thin format-validation only, applied
 * to `POST /api/v1/bookings`. See docs/architecture/02-api-contract.md's
 * "Idempotency" section. Exercised against the real booking route rather
 * than a synthetic test route, so this also proves the middleware alias is
 * actually wired in `bootstrap/app.php`.
 */
it('rejects a booking request with no Idempotency-Key header', function () {
    $zone = ServiceZone::factory()->create();

    $response = $this->postJson('/api/v1/bookings', [
        'service_zone_id' => $zone->id,
        'scheduled_date' => now()->addDay()->toDateString(),
        'slot_start' => '09:00',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
});

it('rejects a booking request with a malformed Idempotency-Key header', function () {
    $zone = ServiceZone::factory()->create();

    $response = $this->withHeader('Idempotency-Key', 'not-a-uuid')->postJson('/api/v1/bookings', [
        'service_zone_id' => $zone->id,
        'scheduled_date' => now()->addDay()->toDateString(),
        'slot_start' => '09:00',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
});
