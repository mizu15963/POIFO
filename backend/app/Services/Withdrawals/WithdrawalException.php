<?php

namespace App\Services\Withdrawals;

use RuntimeException;

/** 換金の業務ルールに反する操作。メッセージはそのまま画面に出してよい文面にする */
class WithdrawalException extends RuntimeException
{
    /** @param string $field 会員 API でエラーを返す入力項目 */
    public function __construct(string $message, public readonly string $field = 'points')
    {
        parent::__construct($message);
    }
}
