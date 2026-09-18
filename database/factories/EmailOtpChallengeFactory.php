<?php

namespace Database\Factories;

use App\Models\EmailOtpChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailOtpChallenge>
 */
class EmailOtpChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'code_hash' => EmailOtpChallenge::hashCode((string) fake()->numerify('######')),
            'purpose' => fake()->randomElement(['registration', 'login', 'password_reset']),
            'pending_password_hash' => null,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'consumed_at' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    /**
     * Indicate a specific plaintext code, hashed the same way the real
     * verify flow hashes submitted codes — lets tests assert against a code
     * they know.
     */
    public function withCode(string $code): static
    {
        return $this->state(fn (array $attributes) => [
            'code_hash' => EmailOtpChallenge::hashCode($code),
        ]);
    }
}
