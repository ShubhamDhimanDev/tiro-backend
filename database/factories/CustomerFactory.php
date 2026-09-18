<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A bare, unactivated customer row — matches the guest-checkout state
     * (`email_verified_at` and `password` both null) unless overridden.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => fake()->numerify('04########'),
            'password' => null,
            'email_verified_at' => null,
        ];
    }

    /**
     * Indicate that the customer has completed registration.
     */
    public function activated(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }
}
