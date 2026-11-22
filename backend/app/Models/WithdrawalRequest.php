<?php

namespace App\Models;

use App\Services\Exchange\ExchangeMethod;
use App\Services\Exchange\ExchangeMethods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ポイント交換（換金）の申請。requested → approved / rejected → paid。状態の種類は全交換先で共通。
 * exchange_method が交換先コード（config/poifo.php の exchange_methods のキー）。
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
            // 申請時点の受け取り情報（銀行振込なら口座。BankTransferHandler::snapshot）
            'recipient_snapshot' => 'encrypted:array',
            'yen_per_point' => 'float',
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

    /** 交換先の設定（設定から消えた交換先は null） */
    public function exchangeMethod(): ?ExchangeMethod
    {
        return app(ExchangeMethods::class)->find($this->exchange_method);
    }

    public function exchangeMethodName(): string
    {
        return app(ExchangeMethods::class)->nameOf($this->exchange_method ?? ExchangeMethod::BANK_TRANSFER);
    }

    public function isBankTransfer(): bool
    {
        return ($this->exchange_method ?? ExchangeMethod::BANK_TRANSFER) === ExchangeMethod::BANK_TRANSFER;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
