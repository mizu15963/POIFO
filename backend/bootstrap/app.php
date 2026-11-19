<?php

use App\Http\Middleware\RestrictViewerHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // api. と admin. は同じ Laravel で動かし、閲覧者のホスト名でルートを分ける（RestrictViewerHost）
        then: function () {
            // 会員向け API（フロントの Astro から Cookie 付きで呼ぶ）
            Route::middleware(['api', 'viewer.host:api'])->group(base_path('routes/api.php'));
            // LINE ログイン（ブラウザ遷移。セッションが要るので web グループ）
            Route::middleware(['web', 'viewer.host:api'])->group(base_path('routes/auth.php'));
            // ASP からの成果通知。WAF で送信元 IP を制限し、ここではハッシュを検証する
            Route::middleware(['viewer.host:api'])->prefix('postback')->group(base_path('routes/postback.php'));
            // 管理画面（admin. からだけ。WAF と nginx で IP 制限）
            Route::middleware(['web', 'viewer.host:admin'])->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['viewer.host' => RestrictViewerHost::class]);
        // フロント（別オリジン）からの Cookie 認証（Sanctum の SPA 認証）
        $middleware->statefulApi();
        // CloudFront 経由なので、閲覧者の IP は X-Forwarded-For から取る（nginx は CloudFront 以外を通さない）。
        // 先頭の値は閲覧者が偽装できるので、記録用に留める。IP による制限は WAF で行う
        $middleware->trustProxies(at: '*');
        // ログイン後の戻り先などはフロント側。未ログインで認証必須の API を呼んだら 401 を返す
        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() ? null : config('poifo.front_url').'/login/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );
    })->create();
