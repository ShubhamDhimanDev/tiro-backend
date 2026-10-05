<?php

namespace Database\Factories;

use App\Enums\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => PaymentType::Charge,
            'gateway' => PaymentGateway::Stripe,
            'method' => PaymentMethod::Card,
            'status' => PaymentTransactionStatus::Pending,
            'amount' => 75600,
            'gateway_reference' => 'pi_'.Str::random(24),
            'raw_response' => null,
            'idempotency_key' => null,
        ];
    }

    /**
     * A settled charge.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentTransactionStatus::Succeeded,
        ]);
    }

    /**
     * A refund row against an existing charge.
     */
    public function refund(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PaymentType::Refund,
            'status' => PaymentTransactionStatus::Succeeded,
            'gateway_reference' => 're_'.Str::random(24),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
