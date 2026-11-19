<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * 管理画面のユーザー（運営スタッフ）。ガード admin で認証する。
 */
class Admin extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'two_factor_secret', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
