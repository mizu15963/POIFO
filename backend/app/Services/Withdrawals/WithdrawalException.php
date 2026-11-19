<?php

namespace App\Services\Withdrawals;

use RuntimeException;

/** 換金の業務ルールに反する操作。メッセージはそのまま画面に出してよい文面にする */
class WithdrawalException extends RuntimeException {}
