<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * イベント（みんなでポイ活）。参加は event_participants で受け付け、オープンチャットは参加した会員にだけ見せる。
 */
class Event extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ENDED = 'ended';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '下書き',
        self::STATUS_PUBLISHED => '公開',
        self::STATUS_ENDED => '終了',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'reward_points' => 'integer',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    /** サイトに出すもの（公開・終了） */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_ENDED]);
    }

    /** 開催予定（公開中で、終了日時、なければ開始日の終わりを過ぎていない） */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(fn ($q) => $q->where('ends_at', '>=', now())
                ->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>=', now()->startOfDay())));
    }

    /** 終わった時刻（終了日時、なければ開始日の終わり） */
    public function finishesAt(): CarbonInterface
    {
        return $this->ends_at ?? $this->starts_at->copy()->endOfDay();
    }

    /**
     * 表示上の状態。公開中でも終わった時刻を過ぎていれば「終了」として扱う（手で終了にしなくてよい）
     */
    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_PUBLISHED && $this->finishesAt()->isPast()) {
            return self::STATUS_ENDED;
        }

        return $this->status;
    }

    /** 参加を受け付けているか（公開中で開始前） */
    public function isOpenForEntry(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->starts_at->isFuture();
    }

    /** 上限人数に数える参加者数 */
    public function countedParticipants(): int
    {
        return $this->participants()->whereIn('status', EventParticipant::COUNTED_STATUSES)->count();
    }

    /** 残りの枠。上限なしは null */
    public function remainingSeats(?int $counted = null): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, $this->capacity - ($counted ?? $this->countedParticipants()));
    }
}
