<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 換金申請。requested → approved / rejected → paid
 */
class WithdrawalRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bank_snapshot' => 'encrypted:array',
            'reviewed_at' => 'datetime',
            'reviewable_after' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** 月末の確認・CSV 出力で、いま何をすべき申請かを判断するための表示名 */
    public const STATUS_LABELS = [
        self::STATUS_REQUESTED => '申請中',
        self::STATUS_APPROVED => '承認済み（振込待ち）',
        self::STATUS_REJECTED => '却下',
        self::STATUS_PAID => '振込済み',
    ];

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** 振込額（換金額 − 手数料） */
    public function transferYen(): int
    {
        return max(0, $this->amount_yen - $this->fee_yen);
    }

    /** 審査期間が終わっていて承認できるか */
    public function isReviewable(): bool
    {
        return $this->reviewable_after === null || $this->reviewable_after->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
