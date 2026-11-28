<?php

namespace App\Services\Email;

use RuntimeException;

/** メールアドレスの登録・確認のルールに反する操作。メッセージはそのまま画面に出してよい文面にする */
class EmailVerificationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'email')
    {
        parent::__construct($message);
    }
}
