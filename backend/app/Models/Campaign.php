<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ASP の案件のキャッシュ。is_hidden / is_featured / sort_order は運営側の設定で、同期では上書きしない。
 */
class Campaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'user_platforms' => 'array',
            'conversion_actions' => 'array',
            'raw' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'synced_at' => 'datetime',
            'is_hidden' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /** 会員に見せてよい案件（配信中・非表示でない・期間内） */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('external_status', 'started')
            ->where('is_hidden', false)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }
}
