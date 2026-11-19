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
