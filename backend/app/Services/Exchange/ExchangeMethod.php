<?php

namespace App\Services\Exchange;

use App\Services\Withdrawals\WithdrawalException;

/**
 * 交換先1つ分の条件（config/poifo.php の exchange_methods の1件）。
 */
final class ExchangeMethod
{
    public const BANK_TRANSFER = 'bank_transfer';

    public const STATUS_OPEN = 'open';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_HIDDEN = 'hidden';

    public const FEE_NONE = 'none';

    public const FEE_FIXED = 'fixed';

    public const FEE_PERCENT = 'percent';

    /** @param array{type: string, amount_yen?: int, percent?: int|float} $fee */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $shortName,
        public readonly string $description,
        public readonly ?string $icon,
        public readonly string $status,
        public readonly int $sort,
        public readonly int $minPoints,
        public readonly int $stepPoints,
        public readonly ?int $maxPoints,
        public readonly float $yenPerPoint,
        public readonly array $fee,
        public readonly int $reviewDays,
        public readonly ExchangeMethodHandler $handler,
    ) {}

    public static function fromConfig(string $code, array $c): self
    {
        $handler = app($c['handler']);

        if (! $handler instanceof ExchangeMethodHandler) {
            throw new \LogicException("交換先 {$code} の handler が ExchangeMethodHandler を実装していません。");
        }

        $status = in_array($c['status'] ?? null, [self::STATUS_OPEN, self::STATUS_SUSPENDED, self::STATUS_HIDDEN], true)
            ? $c['status'] : self::STATUS_HIDDEN;

        return new self(
            code: $code,
            name: $c['name'],
            shortName: $c['short_name'] ?? $c['name'],
            description: $c['description'] ?? '',
            icon: $c['icon'] ?? null,
            status: $status,
            sort: (int) ($c['sort'] ?? 100),
            minPoints: max(1, (int) $c['min_points']),
            stepPoints: max(1, (int) ($c['step_points'] ?? 1)),
            maxPoints: isset($c['max_points']) ? (int) $c['max_points'] : null,
            yenPerPoint: (float) $c['yen_per_point'],
            fee: ($c['fee'] ?? null) ?: ['type' => self::FEE_NONE],
            reviewDays: max(0, (int) ($c['review_days'] ?? 0)),
            handler: $handler,
        );
    }

    public function isAccepting(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isVisible(): bool
    {
        return $this->status !== self::STATUS_HIDDEN;
    }

    /** 交換額（円未満切り捨て） */
    public function amountYen(int $points): int
    {
        return (int) floor($points * $this->yenPerPoint);
    }

    /** 手数料（交換額を超えない） */
    public function feeYen(int $amountYen): int
    {
        $fee = match ($this->fee['type'] ?? self::FEE_NONE) {
            self::FEE_FIXED => (int) ($this->fee['amount_yen'] ?? 0),
            self::FEE_PERCENT => (int) floor($amountYen * (float) ($this->fee['percent'] ?? 0) / 100),
            default => 0,
        };

        return min(max(0, $fee), $amountYen);
    }

    /**
     * 申請できるポイントの条件（最低・刻み・上限）。残高のチェックは WithdrawalService で行う。
     *
     * @throws WithdrawalException
     */
    public function assertPoints(int $points): void
    {
        if ($points < $this->minPoints) {
            throw new WithdrawalException('換金できるのは '.number_format($this->minPoints).' ポイントからです。');
        }

        if ($this->maxPoints !== null && $points > $this->maxPoints) {
            throw new WithdrawalException('1回に申請できるのは '.number_format($this->maxPoints).' ポイントまでです。');
        }

        if (($points - $this->minPoints) % $this->stepPoints !== 0) {
            throw new WithdrawalException(number_format($this->stepPoints).' ポイント単位で申請してください。');
        }
    }

    /**
     * 会員向け API で返す条件
     *
     * @return array{code: string, name: string, description: string, icon: string|null, status: 'open'|'suspended'|'hidden', accepting: bool, min_points: int, step_points: int, max_points: int|null, yen_per_point: float, fee: array{type: 'none'|'fixed'|'percent', amount_yen: int, percent: float}, review_days: int, requires_recipient: bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'status' => (string) $this->status,
            'accepting' => $this->isAccepting(),
            'min_points' => (int) $this->minPoints,
            'step_points' => (int) $this->stepPoints,
            'max_points' => $this->maxPoints,
            'yen_per_point' => $this->yenPerPoint,
            'fee' => [
                'type' => $this->fee['type'] ?? self::FEE_NONE,
                'amount_yen' => (int) ($this->fee['amount_yen'] ?? 0),
                'percent' => (float) ($this->fee['percent'] ?? 0),
            ],
            'review_days' => (int) $this->reviewDays,
            'requires_recipient' => $this->handler->requiresRecipient(),
        ];
    }
}
