<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 停止・退会した会員のセッションを無効にする。
 * 管理画面で停止しても、ログイン中のセッションは残るので、次の API 呼び出しでログアウトさせる。
 */
class EnsureMemberIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(401, 'ログインできない状態です。');
        }

        return $next($request);
    }
}
