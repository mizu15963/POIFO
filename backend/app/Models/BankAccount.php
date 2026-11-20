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

    public const ACCOUNT_TYPES = [
        'ordinary' => '普通',
        'checking' => '当座',
        'savings' => '貯蓄',
    ];

    /**
     * 口座を登録・変更する（会員の口座登録画面から使う）。重複検知用の値もここで作る。
     *
     * @param  array{bank_code: string, bank_name?: ?string, branch_code: string, branch_name?: ?string, account_type: string, account_number: string, account_holder_kana: string, holder_name?: ?string}  $data
     */
    public static function register(User $user, array $data): self
    {
        $kana = self::normalizeKana($data['account_holder_kana']);

        return self::updateOrCreate(['user_id' => $user->id], [
            'bank_code' => $data['bank_code'],
            'bank_name' => $data['bank_name'] ?? null,
            'branch_code' => $data['branch_code'],
            'branch_name' => $data['branch_name'] ?? null,
            'account_type' => $data['account_type'],
            'account_number' => $data['account_number'],
            'account_holder_kana' => $kana,
            'holder_name' => $data['holder_name'] ?? null,
            'account_fingerprint' => self::fingerprint($data['bank_code'], $data['branch_code'], $data['account_type'], $data['account_number']),
            'holder_name_key' => str_replace(' ', '', $kana),
        ]);
    }

    /** 口座名義を全角カナ・全角スペース区切りなしの形にそろえる（表記ゆれで名義の重複検知が漏れないように） */
    public static function normalizeKana(string $kana): string
    {
        $kana = mb_convert_kana($kana, 'KVCs');

        return trim(preg_replace('/\s+/u', ' ', $kana) ?? $kana);
    }

    public static function accountTypeLabel(?string $type): string
    {
        return self::ACCOUNT_TYPES[$type] ?? (string) $type;
    }

    /** 同じ口座かどうかを、口座番号を平文で持たずに比べるための値 */
    public static function fingerprint(string $bankCode, string $branchCode, string $accountType, string $accountNumber): string
    {
        return hash_hmac('sha256', implode(':', [$bankCode, $branchCode, $accountType, ltrim($accountNumber, '0')]), (string) config('app.key'));
    }
}
