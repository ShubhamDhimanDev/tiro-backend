<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Customer;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notifiable_type' => Customer::class,
            'notifiable_id' => Customer::factory(),
            'type' => 'booking.confirmed',
            'channel' => NotificationChannel::Mail,
            'status' => NotificationDeliveryStatus::Sent,
            'recipient' => fake()->safeEmail(),
            'provider_message_id' => null,
            'error_message' => null,
            'related_type' => null,
            'related_id' => null,
        ];
    }
}
