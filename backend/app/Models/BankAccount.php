<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 振込先口座。口座番号は暗号化。同一口座・同一名義の検知は account_fingerprint / holder_name_key で行う。
 */
class BankAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['account_number', 'account_fingerprint'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted'];
    }

    /** 同じ口座かどうかを、口座番号を平文で持たずに比べるための値 */
    public static function fingerprint(string $bankCode, string $branchCode, string $accountType, string $accountNumber): string
    {
        return hash_hmac('sha256', implode(':', [$bankCode, $branchCode, $accountType, ltrim($accountNumber, '0')]), (string) config('app.key'));
    }
}
