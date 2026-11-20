<?php

namespace App\Providers;

use App\Http\Middleware\RestrictViewerHost;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scramble 既定の /docs/api は使わない（env で出し分けたいので bootstrap/app.php で登録する）
        Scramble::ignoreDefaultRoutes();

        // Try It の送り先は開いているページと同じオリジン（相対パス）にする。
        // 本番構成では Host がオリジン名になるので、APP_URL や Host から作った絶対 URL は使わない
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->servers = [Server::make('/')];
            // 会員 API は Cookie（Sanctum）で認証し、POST/PUT は CSRF トークンを X-XSRF-TOKEN ヘッダーで送る。
            // Try It でそのヘッダーを入力できるようにする（値は /sanctum/csrf-cookie で発行される XSRF-TOKEN Cookie を URL デコードしたもの）
            $openApi->secure(
                SecurityScheme::apiKey('header', 'X-XSRF-TOKEN')
                    ->as('xsrf')
                    ->setDescription('POST/PUT のときだけ必要。/sanctum/csrf-cookie を開いて発行された XSRF-TOKEN Cookie の値（URL デコード後）を入れる。GET では空でよい'),
            );
        });
    }

    public function boot(): void
    {
        // 管理画面（admin.）は会員のセッション Cookie と分ける。
        // 会員側は SESSION_DOMAIN=.<domain> でフロントと共有するが、管理画面の Cookie は admin. 専用にする
        if (! $this->app->runningInConsole()) {
            $request = $this->app->make(Request::class);

            if (RestrictViewerHost::viewerHost($request) === strtolower((string) config('poifo.hosts.admin'))) {
                config([
                    'session.cookie' => 'poifo_admin_session',
                    'session.domain' => null,
                    'session.same_site' => 'strict',
                    'session.lifetime' => 60,
                ]);
            }
        }
    }
}
