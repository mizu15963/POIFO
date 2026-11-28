<?php

namespace App\Services\Referrals;

use RuntimeException;

/** 友達紹介の操作ができないとき（メッセージはそのまま管理者に見せる） */
class ReferralException extends RuntimeException {}
