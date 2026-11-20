<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * イベントの参加（イベントと会員の組で1行）。参加したかどうかはこの表で判定する。
 * 状態は EventParticipationService だけで変える。
 */
class EventParticipant extends Model
{
    public const STATUS_JOINED = 'joined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ATTENDED = 'attended';

    public const STATUS_ABSENT = 'absent';

    /** 上限人数に数える状態（取り消し以外） */
    public const COUNTED_STATUSES = [self::STATUS_JOINED, self::STATUS_ATTENDED, self::STATUS_ABSENT];

    public const STATUS_LABELS = [
        self::STATUS_JOINED => '参加予定',
        self::STATUS_CANCELLED => '取り消し',
        self::STATUS_ATTENDED => '参加済み',
        self::STATUS_ABSENT => '不参加',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'cancelled_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rewardTransaction(): HasOne
    {
        return $this->hasOne(PointTransaction::class);
    }

    public function isCounted(): bool
    {
        return in_array($this->status, self::COUNTED_STATUSES, true);
    }
}
