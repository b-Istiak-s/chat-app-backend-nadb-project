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
     * Mark the user as token-bearing (post OTP verification, BDApps
     * mid-charge). `subscription_status = 'pending'`. This is the
     * state the user lands in right after a successful verify when
     * the gateway hasn't yet confirmed REGISTERED.
     */
    public function subscribed(): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_status' => 'pending',
            'subscribed_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * Mark the user as fully subscribed — BDApps has confirmed
     * REGISTERED, money taken, full feature access (chat, APK).
     * `subscription_status = 'registered'`.
     */
    public function registered(): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_status' => 'registered',
            'subscribed_at' => now(),
            'phone_verified_at' => now(),
        ]);
    }
}