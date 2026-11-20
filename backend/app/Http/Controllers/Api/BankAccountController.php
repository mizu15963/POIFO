<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 会員の振込先口座。会員向け API では口座番号を末尾3桁以外伏せて返す。
 */
class BankAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $bank = $request->user()->bankAccount;

        return response()->json(['data' => $bank ? self::present($bank) : null]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isActive(), 403);

        $data = $request->validate([
            'bank_code' => ['required', 'digits:4'],
            'bank_name' => ['required', 'string', 'max:60'],
            'branch_code' => ['required', 'digits:3'],
            'branch_name' => ['required', 'string', 'max:60'],
            'account_type' => ['required', Rule::in(array_keys(BankAccount::ACCOUNT_TYPES))],
            'account_number' => ['required', 'regex:/^\d{1,7}$/'],
            // 口座名義はカナ（全角・半角どちらでも）。全銀で使える記号だけ許す
            'account_holder_kana' => ['required', 'string', 'max:60', 'regex:/^[ァ-ヶーｦ-ﾟ\x{3000} ()（）.．\-‐]+$/u'],
            'holder_name' => ['required', 'string', 'max:60'],
        ], [
            'account_holder_kana.regex' => '口座名義はカタカナで入力してください。',
            'account_number.regex' => '口座番号は7桁以内の数字で入力してください。',
        ], [
            'bank_code' => '金融機関コード', 'bank_name' => '金融機関名', 'branch_code' => '支店コード', 'branch_name' => '支店名',
            'account_type' => '口座種別', 'account_number' => '口座番号', 'account_holder_kana' => '口座名義（カナ）', 'holder_name' => '氏名',
        ]);

        $data['account_number'] = str_pad($data['account_number'], 7, '0', STR_PAD_LEFT);

        // 同じ口座を複数の会員で使うことはできない（ASP の不正対策の要請）
        $fingerprint = BankAccount::fingerprint($data['bank_code'], $data['branch_code'], $data['account_type'], $data['account_number']);

        if (BankAccount::where('account_fingerprint', $fingerprint)->where('user_id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages(['account_number' => 'この口座はほかのアカウントで登録されているため、使えません。']);
        }

        $bank = BankAccount::register($user, $data);

        return response()->json(['data' => self::present($bank)]);
    }

    public static function present(BankAccount $bank): array
    {
        return [
            'bank_code' => $bank->bank_code,
            'bank_name' => $bank->bank_name,
            'branch_code' => $bank->branch_code,
            'branch_name' => $bank->branch_name,
            'account_type' => $bank->account_type,
            'account_type_label' => BankAccount::accountTypeLabel($bank->account_type),
            'account_number_masked' => str_repeat('*', 4).substr((string) $bank->account_number, -3),
            'account_holder_kana' => $bank->account_holder_kana,
            'holder_name' => $bank->holder_name,
        ];
    }
}
