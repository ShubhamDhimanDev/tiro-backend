<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Schema-only support factory — see {@see CustomerVehicle}'s docblock. No
 * business logic exists to exercise beyond basic persistence yet.
 *
 * @extends Factory<CustomerVehicle>
 */
class CustomerVehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'rego' => null,
            'state' => null,
            'vin' => null,
            'vehicle_id' => null,
            'saved_fitment' => [],
        ];
    }
}
