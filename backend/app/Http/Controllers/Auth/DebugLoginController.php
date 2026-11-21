<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 開発確認用のログイン（LINE を通さずに会員としてログインする）。
 *
 * POIFO_API_DOCS_ENABLED=true のときだけルートを作る（API 仕様書と同じフラグ。本番は必ず false）。
 * ログインできるのはこの機能で作ったデバッグ用の会員（line_user_id が「debug:」で始まる）だけで、
 * 実際の会員にはなりすませない。
 */
class DebugLoginController extends Controller
{
    /** LINE の会員ID と重ならないよう、デバッグ用の会員にはこの接頭辞を付ける */
    public const LINE_USER_ID_PREFIX = 'debug:';

    /**
     * デバッグ用の会員としてログインする。
     *
     * ブラウザで開くと、user（英数字・_・-、32文字まで。省略時 tester）ごとのデバッグ用会員を
     * 初回に作ってログインし、フロントの /auth/complete/ へ戻す。同じ user なら同じ会員になる。
     */
    public function login(Request $request): RedirectResponse
    {
        $name = (string) ($request->query('user') ?: 'tester');
        // ブラウザで開くので、入力の誤りはリダイレクトせずその場で 422 を返す
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,32}$/', $name) === 1, 422, 'user は英数字・_・- の32文字までで指定してください');
        $user = User::firstWhere('line_user_id', self::LINE_USER_ID_PREFIX.$name);
        $isNew = $user === null;

        // 停止・退会の確認もできるよう、LINE ログインと同じ扱いにする
        if ($user && $user->status === User::STATUS_WITHDRAWN) {
            return $this->fail('withdrawn');
        }

        if ($user && $user->status === User::STATUS_SUSPENDED) {
            return $this->fail('suspended');
        }

        $user ??= new User([
            'line_user_id' => self::LINE_USER_ID_PREFIX.$name,
            'status' => User::STATUS_ACTIVE,
            'display_name' => 'デバッグ '.$name,
        ]);
        $user->fill(['last_login_at' => now()])->save();

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->away(config('poifo.front_url').'/auth/complete/'.($isNew ? '?new=1' : ''));
    }

    private function fail(string $reason): RedirectResponse
    {
        return redirect()->away(config('poifo.front_url').'/auth/error/?reason='.$reason);
    }
}
