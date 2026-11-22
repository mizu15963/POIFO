<?php

namespace App\Services\Exchange;

use App\Models\User;
use App\Services\Withdrawals\WithdrawalException;

/**
 * 交換先ごとに違う処理。申請・審査・却下・台帳の流れは WithdrawalService で共通にし、ここには差し替える部分だけを置く。
 *
 * 交換先を足すときは、このインターフェイスを実装して config/poifo.php の exchange_methods に登録する。
 * 受け取り情報が要る交換先は、その交換先用のテーブルを足す（銀行口座の bank_accounts に混ぜない）。
 */
interface ExchangeMethodHandler
{
    /** 申請に受け取り情報（口座など）の登録が必要か */
    public function requiresRecipient(): bool;

    /** 会員が受け取り情報を登録済みか（不要な交換先は常に true） */
    public function hasRecipient(User $user): bool;

    /**
     * 申請時点の受け取り情報の控え。あとで会員が情報を変えても、処理に使うのは申請時のもの。
     * withdrawal_requests.recipient_snapshot に暗号化して保存する。
     *
     * @throws WithdrawalException 受け取り情報が未登録のとき
     */
    public function recipientSnapshot(User $user): array;
}
