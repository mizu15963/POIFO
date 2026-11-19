<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'line_user_id' => 'U'.Str::lower(Str::random(32)),
            'display_name' => fake()->name(),
            'status' => User::STATUS_ACTIVE,
        ];
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_WITHDRAWN, 'withdrawn_at' => now()]);
    }
}
