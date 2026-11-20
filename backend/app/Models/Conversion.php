<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 成果（cv_id ごとに1行）。
 */
class Conversion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** 保留の理由（ポイントを確定せず、管理者の確認を待つ） */
    public const HOLD_REASONS = [
        'unknown_user' => '存在しない会員',
        'unknown_campaign' => '未取得の案件',
        'unknown_action' => '未取得の成果地点',
        'amount_mismatch' => 'ポイント数が案件情報と不一致',
        'amount_over_limit' => 'ポイント数が上限を超過',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cv_at' => 'datetime',
            'authenticated_at' => 'datetime',
            'held_at' => 'datetime',
            'last_payload' => 'array',
            'net_cost' => 'decimal:2',
        ];
    }

    public function isHeld(): bool
    {
        return $this->hold_reason !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pointTransaction(): HasOne
    {
        return $this->hasOne(PointTransaction::class);
    }
}
