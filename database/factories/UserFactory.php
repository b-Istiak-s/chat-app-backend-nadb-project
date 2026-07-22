<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => '01'.fake()->numerify('########'),
            'subscription_status' => 'unverified',
        ];
    }

    /**
     * Mark the user as verified (post OTP verification, token
     * issued). In the new three-state model this is the
     * token-bearing state — `subscription_status = 'pending'`.
     */
    public function subscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_status' => 'pending',
            'subscribed_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }
}