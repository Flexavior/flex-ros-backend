<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'company' => $this->faker->company(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'source' => $this->faker->randomElement(['referral', 'viber', 'telegram', 'web', 'event']),
            'status' => 'new',
            'notes' => null,
            'owner_id' => User::factory(),
            'status_updated_at' => now(),
        ];
    }

    /** Lead with no status change for $days days (stale candidate). */
    public function stale(int $days = 6): static
    {
        return $this->state(fn () => [
            'status' => 'contacted',
            'status_updated_at' => now()->subDays($days),
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn () => [
            'status' => 'converted',
        ]);
    }
}
