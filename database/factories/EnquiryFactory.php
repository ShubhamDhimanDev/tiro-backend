<?php

namespace Database\Factories;

use App\Enums\EnquiryStatus;
use App\Enums\EnquiryType;
use App\Models\Enquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enquiry>
 */
class EnquiryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => Enquiry::generateReference(),
            'type' => EnquiryType::Contact,
            'status' => EnquiryStatus::New,
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => '0412345678',
            'message' => fake()->sentence(),
        ];
    }
}
