<?php

namespace Database\Factories;

use App\Enums\VehicleFitmentPosition;
use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\TyreVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingLineItem>
 */
class BookingLineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'tyre_variant_id' => TyreVariant::factory(),
            'quantity' => 1,
            'position' => VehicleFitmentPosition::All,
        ];
    }
}
