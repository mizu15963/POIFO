<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Email\EmailVerificationException;
use App\Services\Email\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 会員のメールアドレス（機能仕様 3.5）。連絡用で、ログインには使わない。
 */
class EmailController extends Controller
{
    public function __construct(private EmailVerificationService $emails) {}

    /**
     * メールアドレスを登録・変更し、確認メールを送る（初回登録 S27・登録情報 S21）。
     *
     * 確認メールのリンクを開くまでは「確認待ち」。確認待ちでも案件には進めるが、換金の申請には確認済みが必要。
     * 別の会員が確認済みで使っているアドレスは登録できない。確認メールの送信は会員ごとに1時間3回まで。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255', 'email:rfc,filter'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
        ], [
            'email.required' => 'メールアドレスを入力してください。',
            'email.email' => 'メールアドレスの形式が正しくありません。',
        ], ['email' => 'メールアドレス']);

        $user = $request->user();

        try {
            $this->emails->register($user, $data['email']);
        } catch (EmailVerificationException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        if (array_key_exists('marketing_opt_in', $data)) {
            $user->forceFill(['email_marketing_opt_in' => (bool) $data['marketing_opt_in']])->save();
        }

        return response()->json(['data' => self::present($user->refresh())]);
    }

    /** 確認待ちのアドレスに確認メールを送り直す（1時間3回まで。登録と合わせて数える） */
    public function resend(Request $request): JsonResponse
    {
        try {
            $this->emails->resend($request->user());
        } catch (EmailVerificationException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return response()->json(['data' => self::present($request->user()->refresh())]);
    }

    /** メールの受け取りの設定（お得情報のメールを受け取るか。既定は受け取らない） */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['marketing_opt_in' => ['required', 'boolean']]);
        $user = $request->user();
        $user->forceFill(['email_marketing_opt_in' => (bool) $data['marketing_opt_in']])->save();

        return response()->json(['data' => self::present($user)]);
    }

    /**
     * 確認メールのリンクのトークンでメールアドレスを確認する（S21 の確認ページから呼ぶ）。
     *
     * メールアプリの中のブラウザで開かれることが多いので、ログインしていなくても確認できる。
     * 失敗したときは token のエラーで「リンクが無効です」「リンクの有効期限が切れています」などを返す。
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        try {
            $this->emails->verify($data['token']);
        } catch (EmailVerificationException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return response()->json(['data' => ['verified' => true]]);
    }

    /**
     * GET /v1/me にも入れるメールアドレスの状態。
     *
     * @return array{email: ?string, email_verified: bool, pending_email: ?string, email_registered: bool, email_marketing_opt_in: bool}
     */
    public static function present(User $user): array
    {
        $pending = $user->pendingEmailVerification();

        return [
            // 確認済みのアドレス
            'email' => $user->email_verified_at ? $user->email : null,
            'email_verified' => $user->hasVerifiedEmail(),
            // 確認待ちのアドレス（確認済みのアドレスから変えている途中もここに入る）
            'pending_email' => $pending?->email,
            // false なら初回登録（S27）へ案内し、案件に進ませない
            'email_registered' => $user->email !== null || $pending !== null,
            'email_marketing_opt_in' => (bool) $user->email_marketing_opt_in,
        ];
    }
}
