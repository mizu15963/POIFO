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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
