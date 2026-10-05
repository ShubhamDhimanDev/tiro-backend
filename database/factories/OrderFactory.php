<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'TMS-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'customer_id' => null,
            'booking_id' => Booking::factory(),
            'address_id' => Address::factory(),
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'subtotal' => 75600,
            'discount_total' => 0,
            'tax_total' => 6873,
            'service_fee_total' => 0,
            'grand_total' => 75600,
            'currency' => 'AUD',
            'idempotency_key' => (string) Str::uuid(),
            'guest_token_hash' => null,
            'placed_at' => null,
        ];
    }

    /**
     * A paid, confirmed order.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'placed_at' => now(),
        ]);
    }
}
