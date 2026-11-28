<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Line\LineLoginClient;
use App\Services\Referrals\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * LINE ログイン。会員登録とログインは同じ流れ（初回ログイン時に会員を作る）。
 *
 * フロントの「LINEでログイン」→ GET /auth/line/redirect → LINE → GET /auth/line/callback
 * → セッションを作ってフロントへ戻す（/auth/complete/ または /auth/error/）。
 *
 * 紹介URLから来た人は、フロントが入口に ?ref=<紹介コード> を付ける。新しく会員になったときだけ紹介元として記録する（機能仕様 15.2）。
 */
class LineLoginController extends Controller
{
    public function __construct(private LineLoginClient $line, private ReferralService $referrals) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $ref = ReferralService::normalizeCode((string) $request->query('ref', ''));
        $request->session()->put('line_login', [
            'state' => $state,
            'nonce' => $nonce,
            // 形の合わないコードは捨てる（存在するかは登録のときに確かめる）
            'ref' => ReferralService::isValidCodeFormat($ref) ? $ref : null,
        ]);

        return redirect()->away($this->line->authorizeUrl($state, $nonce));
    }

    public function callback(Request $request): RedirectResponse
    {
        $saved = $request->session()->pull('line_login');

        if (! $saved || ! hash_equals($saved['state'], (string) $request->query('state'))) {
            return $this->fail('invalid_state');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            // 利用者がLINEの同意画面でキャンセルした場合など
            return $this->fail('cancelled');
        }

        try {
            $profile = $this->line->fetchProfile($request->query('code'), $saved['nonce']);
        } catch (Throwable $e) {
            Log::warning('LINE login failed', ['message' => $e->getMessage()]);

            return $this->fail('line_error');
        }

        $user = User::firstWhere('line_user_id', $profile['sub']);
        $isNew = $user === null;

        if ($user && $user->status === User::STATUS_WITHDRAWN) {
            // 退会者の再入会は今は受け付けない（不正対策。再入会の扱いは運用で決める）
            return $this->fail('withdrawn');
        }

        if ($user && $user->status === User::STATUS_SUSPENDED) {
            return $this->fail('suspended');
        }

        $user ??= new User(['line_user_id' => $profile['sub'], 'status' => User::STATUS_ACTIVE]);
        $user->fill([
            'display_name' => $profile['name'] ?? $user->display_name,
            'avatar_url' => $profile['picture'] ?? $user->avatar_url,
            'last_login_at' => now(),
        ])->save();

        if ($isNew && ! empty($saved['ref'])) {
            $this->referrals->attachOnRegistration($user, $saved['ref']);
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        // メールアドレスを入れていない会員は、ログインのたびに初回登録（S27）へ（機能仕様 3.5）
        $page = $user->hasRegisteredEmail() ? '/auth/complete/' : '/welcome/';

        return redirect()->away(config('poifo.front_url').$page.($isNew ? '?new=1' : ''));
    }

    private function fail(string $reason): RedirectResponse
    {
        return redirect()->away(config('poifo.front_url').'/auth/error/?reason='.$reason);
    }
}
