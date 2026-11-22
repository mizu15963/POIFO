<?php

namespace App\Services\Exchange;

use App\Models\BankAccount;
use App\Models\User;
use App\Services\Withdrawals\WithdrawalException;

/**
 * 銀行振込（現金）。月に1回、管理者が承認済みの申請を CSV に出し、人の手で振り込んでから「振込済み」にする。
 */
class BankTransferHandler implements ExchangeMethodHandler
{
    public function requiresRecipient(): bool
    {
        return true;
    }

    public function hasRecipient(User $user): bool
    {
        return $user->bankAccount()->exists();
    }

    public function recipientSnapshot(User $user): array
    {
        $bank = $user->bankAccount;

        if (! $bank) {
            throw new WithdrawalException('振込先の口座を登録してください。');
        }

        return self::snapshot($bank);
    }

    /** 申請時点の口座情報（あとで口座が変更されても、振り込む先は申請時のもの） */
    public static function snapshot(BankAccount $bank): array
    {
        return [
            'holder_name' => $bank->holder_name,
            'account_holder_kana' => $bank->account_holder_kana,
            'bank_code' => $bank->bank_code,
            'bank_name' => $bank->bank_name,
            'branch_code' => $bank->branch_code,
            'branch_name' => $bank->branch_name,
            'account_type' => $bank->account_type,
            'account_number' => $bank->account_number,
            'account_fingerprint' => $bank->account_fingerprint,
            'holder_name_key' => $bank->holder_name_key,
        ];
    }
}
