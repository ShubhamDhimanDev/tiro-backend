<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\Van;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => null,
            'customer_id' => null,
            'vehicle_id' => null,
            'service_zone_id' => ServiceZone::factory(),
            'address_id' => null,
            'scheduled_date' => now()->addDay()->toDateString(),
            'slot_start' => '09:00:00',
            'slot_end' => '09:45:00',
            'technician_id' => Technician::factory(),
            'van_id' => Van::factory(),
            'status' => BookingStatus::PendingHold,
            'duration_minutes' => 45,
            'addons' => null,
            'access_notes' => null,
            'hold_expires_at' => now()->addMinutes(15),
            'idempotency_key' => (string) Str::uuid(),
            'manage_token_hash' => null,
            'cancellation_fee_amount' => null,
        ];
    }

    /**
     * A confirmed booking — no active hold TTL.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BookingStatus::Confirmed,
            'hold_expires_at' => null,
        ]);
    }
}
