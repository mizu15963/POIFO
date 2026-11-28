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
            // 既定はメールアドレス確認済み（未登録・確認待ちは withoutEmail() / ほかのテストで作る）
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
        ];
    }

    /** メールアドレスを入れていない会員（初回登録 S27 の前） */
    public function withoutEmail(): static
    {
        return $this->state(fn () => ['email' => null, 'email_verified_at' => null]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_WITHDRAWN, 'withdrawn_at' => now()]);
    }
}
