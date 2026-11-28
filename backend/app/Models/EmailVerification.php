<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 確認中のメールアドレス（機能仕様 3.5）。確認できたら users.email に移して行を消す。
 * 期限が切れても行は残す（「確認待ち」のまま再送できるように。次に送るときに置き換える）。
 */
class EmailVerification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
