<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'correct-horse-battery',
            'is_active' => true,
        ];
    }

    /** 二要素認証を登録済みにする（テストでは secret を渡して使う） */
    public function withTwoFactor(string $secret): static
    {
        return $this->state(fn () => ['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);
    }
}
