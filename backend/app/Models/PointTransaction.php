<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ポイント台帳の1行。残高は status=confirmed の合計。
 */
class PointTransaction extends Model
{
    public const TYPE_CONVERSION = 'conversion';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const TYPE_WITHDRAWAL_REFUND = 'withdrawal_refund';

    public const TYPE_EXPIRY = 'expiry';

    public const TYPE_EVENT_REWARD = 'event_reward';

    /** 会員・管理画面に出す種類の名前 */
    public const TYPE_LABELS = [
        self::TYPE_CONVERSION => '案件の成果',
        self::TYPE_ADJUSTMENT => '運営による調整',
        self::TYPE_WITHDRAWAL => '換金',
        self::TYPE_WITHDRAWAL_REFUND => '換金の取り消し（返却）',
        self::TYPE_EXPIRY => '失効',
        self::TYPE_EVENT_REWARD => 'イベント参加',
    ];

    public const STATUS_LABELS = [
        'pending' => '判定中',
        'confirmed' => '確定',
        'rejected' => '否認',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'points' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }
}
