<?php

namespace App\Services\Members;

use RuntimeException;

/** 会員の操作のルールに反する操作。メッセージはそのまま画面に出してよい文面にする */
class MemberException extends RuntimeException {}
