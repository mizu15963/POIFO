<?php

namespace App\Services\Events;

use RuntimeException;

/** イベント参加のルールに反する操作。メッセージはそのまま画面に出してよい文面にする */
class EventParticipationException extends RuntimeException {}
