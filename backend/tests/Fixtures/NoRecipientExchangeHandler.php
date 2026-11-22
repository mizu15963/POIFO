<?php

namespace Tests\Fixtures;

use App\Models\User;
use App\Services\Exchange\ExchangeMethodHandler;

/** テスト用の交換先（受け取り情報が要らない。例: その場で発行するギフト券） */
class NoRecipientExchangeHandler implements ExchangeMethodHandler
{
    public function requiresRecipient(): bool
    {
        return false;
    }

    public function hasRecipient(User $user): bool
    {
        return true;
    }

    public function recipientSnapshot(User $user): array
    {
        return [];
    }
}
