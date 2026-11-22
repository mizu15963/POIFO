<?php

namespace App\Services\Exchange;

use App\Services\Withdrawals\WithdrawalException;
use Illuminate\Support\Collection;

/**
 * 交換先の一覧（config/poifo.php の exchange_methods）。設定は呼ぶたびに読み直す（テストで config を変えられるように）。
 */
class ExchangeMethods
{
    /** @return Collection<string, ExchangeMethod> 表示順。非表示も含む（管理画面・過去の申請の表示用） */
    public function all(): Collection
    {
        return collect(config('poifo.exchange_methods', []))
            ->map(fn (array $c, string $code) => ExchangeMethod::fromConfig($code, $c))
            ->sortBy([fn (ExchangeMethod $a, ExchangeMethod $b) => [$a->sort, $a->code] <=> [$b->sort, $b->code]]);
    }

    /** @return Collection<string, ExchangeMethod> 会員に見せる交換先（受付停止も含む） */
    public function visible(): Collection
    {
        return $this->all()->filter(fn (ExchangeMethod $m) => $m->isVisible());
    }

    public function find(?string $code): ?ExchangeMethod
    {
        return $code === null ? null : $this->all()->get($code);
    }

    /**
     * 申請を受け付けている交換先。
     *
     * @throws WithdrawalException
     */
    public function acceptingOrFail(string $code): ExchangeMethod
    {
        $method = $this->find($code);

        if (! $method || ! $method->isVisible()) {
            throw new WithdrawalException('この交換先は選べません。', 'method');
        }

        if (! $method->isAccepting()) {
            throw new WithdrawalException("「{$method->name}」は現在受付を停止しています。", 'method');
        }

        return $method;
    }

    /** 過去の申請の表示名（設定から消えた交換先はコードのまま） */
    public function nameOf(string $code): string
    {
        return $this->find($code)?->name ?? $code;
    }
}
