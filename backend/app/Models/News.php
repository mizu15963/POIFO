<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 運営から全会員へのお知らせ（機能仕様 11.1）。本文は HTML を使わず、改行と URL の自動リンクだけ（フロントで行う）。
 */
class News extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '下書き',
        self::STATUS_PUBLISHED => '公開',
    ];

    protected $table = 'news';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_important' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** 会員サイトに出してよいお知らせ（公開・公開日時を過ぎた・公開終了の前） */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isVisible(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null && $this->published_at->lte(now())
            && ($this->expires_at === null || $this->expires_at->gt(now()));
    }

    /** 管理画面での表示（予約・公開中・公開終了を分けて見せる） */
    public function displayStatus(): string
    {
        if ($this->status !== self::STATUS_PUBLISHED) {
            return '下書き';
        }
        if ($this->published_at?->gt(now())) {
            return '公開予約';
        }
        if ($this->expires_at !== null && $this->expires_at->lte(now())) {
            return '公開終了';
        }

        return '公開中';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
