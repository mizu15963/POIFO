<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Line\LineLoginClient;
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
 */
class LineLoginController extends Controller
{
    public function __construct(private LineLoginClient $line) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $request->session()->put('line_login', ['state' => $state, 'nonce' => $nonce]);

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

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->away(config('poifo.front_url').'/auth/complete/'.($isNew ? '?new=1' : ''));
    }

    private function fail(string $reason): RedirectResponse
    {
        return redirect()->away(config('poifo.front_url').'/auth/error/?reason='.$reason);
    }
}
