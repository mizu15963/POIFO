<?php

namespace App\Models;

use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * 管理画面のユーザー（運営スタッフ）。ガード admin で認証する。
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'two_factor_secret', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestep' => 'integer',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
