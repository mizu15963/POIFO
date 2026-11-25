<?php

use App\Http\Middleware\RestrictViewerHost;
use App\Http\Middleware\UseViewerHostForUrls;
use Dedoc\Scramble\Scramble;
use Illuminate\Cookie\Middleware\EncryptCookies;
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
            // API 仕様書（Scramble）。管理画面の中に置き、管理者のログイン（パスワード＋二要素認証）が済んだ人だけに見せる。
            // 成果通知の受け口も載るので、api. には出さない。Try It は api. へ送る（AppServiceProvider・config/cors.php）
            Route::middleware(['web', 'viewer.host:admin', 'auth:admin'])->group(function () {
                Scramble::registerUiRoute('docs/api')->name('admin.api-docs.ui');
                Scramble::registerJsonSpecificationRoute('docs/api.json')->name('admin.api-docs.json');
            });
            // デバッグ用ログイン（開発の確認中だけ。POIFO_DEBUG_LOGIN_ENABLED=true のときだけルートを作る）。
            // 仕様書は閉じたまま、スマホなどで会員画面を確かめたいことがあるので、仕様書とは別に切り替える
            if (config('poifo.debug_login_enabled')) {
                Route::middleware(['web', 'viewer.host:api'])->group(base_path('routes/debug.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['viewer.host' => RestrictViewerHost::class]);
        // リダイレクト先の URL をオリジン名（origin.<domain>）ではなく閲覧者のホスト名で作る
        $middleware->prepend(UseViewerHostForUrls::class);
        // ホストの判定はセッション・認証より先に行う（api. から管理画面の URL を叩いたとき、ログイン画面へ
        // リダイレクトせずに 404 を返し、管理画面の存在を見せないため）
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: RestrictViewerHost::class);
        // フロント（別オリジン）からの Cookie 認証（Sanctum の SPA 認証）
        $middleware->statefulApi();
        // CloudFront 経由なので、閲覧者の IP は X-Forwarded-For から取る（nginx は CloudFront 以外を通さない）。
        // 先頭の値は閲覧者が偽装できるので、記録用に留める。IP による制限は WAF で行う
        $middleware->trustProxies(at: '*');
        // メンテナンス中（artisan down）も成果通知だけは受け付ける。GF Rewards が 503 を再送するか未確認で、
        // 取りこぼすとポイント（お金）に直結するため。DB が止まっているときは処理中の例外で 500 になり、ASP が再送する
        $middleware->preventRequestsDuringMaintenance(except: ['postback/*']);
        // 未ログインで認証必須の API を呼んだら 401 を返す
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            // 管理画面は管理者のログイン画面へ、それ以外（会員）はフロントのログイン画面へ
            return RestrictViewerHost::viewerHost($request) === strtolower((string) config('poifo.hosts.admin'))
                ? route('admin.login')
                : config('poifo.front_url').'/login/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );
    })->create();
