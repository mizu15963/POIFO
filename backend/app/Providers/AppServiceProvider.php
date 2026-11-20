<?php

namespace App\Providers;

use App\Http\Middleware\RestrictViewerHost;
use App\Support\ClientIp;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();

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

    /**
     * 会員単位の回数制限（WAF の IP 単位の制限に加える）。カウントは共有のキャッシュ（DB）に置くので全台で共通。
     * 静的フロントから API を直接呼ぶ構成なので、お金や本人確認に関わる操作は特に厳しくする。
     */
    private function configureRateLimiting(): void
    {
        $key = fn (Request $request) => $request->user()
            ? 'user:'.$request->user()->getAuthIdentifier()
            : 'ip:'.ClientIp::forLog($request);

        // 会員 API 全体
        RateLimiter::for('member-api', fn (Request $request) => Limit::perMinute(120)->by($key($request)));

        // 換金申請: 連打と、短時間に何度も申請して審査をすり抜ける試みを抑える
        RateLimiter::for('withdrawal-request', fn (Request $request) => [
            Limit::perMinute(3)->by('withdrawal-min:'.$key($request)),
            Limit::perDay(10)->by('withdrawal-day:'.$key($request)),
        ]);

        // 振込先口座の登録・変更: 他人の口座を総当たりで試す・頻繁に付け替えるのを抑える
        RateLimiter::for('bank-account-update', fn (Request $request) => [
            Limit::perMinute(3)->by('bank-min:'.$key($request)),
            Limit::perDay(5)->by('bank-day:'.$key($request)),
        ]);
    }
}
