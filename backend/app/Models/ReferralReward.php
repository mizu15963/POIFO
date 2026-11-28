<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 紹介報酬の計算の根拠（機能仕様 15.3）。ポイントの増減と状態は point_transactions の行で持つ。
 */
class ReferralReward extends Model
{
    public const TYPE_ONGOING = 'ongoing';

    public const TYPE_INITIAL = 'initial';

    public const TYPE_LABELS = [
        self::TYPE_ONGOING => '紹介報酬',
        self::TYPE_INITIAL => '紹介の初回特典',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['base_points' => 'integer', 'points' => 'integer'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_user_id');
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }

    public function pointTransaction(): BelongsTo
    {
        return $this->belongsTo(PointTransaction::class);
    }
}
