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

        // 仕様書は管理画面（admin.）に置くので、Try It の送り先は api.（APP_URL）にする。
        // 会員の Cookie で呼べるよう、CORS と Sanctum の stateful に管理画面を足している
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->servers = [Server::make(rtrim((string) config('app.url'), '/'))];
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
     * 会員単位・IP 単位の回数制限（WAF の IP 単位の全体レート制限に加える）。カウントは共有のキャッシュ（DB）に置くので全台で共通。
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

        // イベントの参加・取り消し: 繰り返して枠を押さえたり外したりするのを抑える
        RateLimiter::for('event-participation', fn (Request $request) => [
            Limit::perMinute(10)->by('event-min:'.$key($request)),
            Limit::perDay(50)->by('event-day:'.$key($request)),
        ]);

        // LINE ログインの入口（/auth/line/redirect・callback）: 未ログインなので IP 単位。
        // 同一 IP から5分に50回まで（以前 WAF でかけていた値。CloudFront 定額プラン Free ではパスで絞った WAF ルールが使えないのでアプリで行う）。
        // キーは CloudFront Function が付ける x-poifo-viewer-ip。取れないときは偽装できる X-Forwarded-For に落とさず、1つの枠にまとめる
        RateLimiter::for('line-login', fn (Request $request) => Limit::perMinutes(5, 50)
            ->by('line-login:'.(ClientIp::trusted($request) ?? 'unknown')));

        // 案件への遷移（/go/{id}）: ログイン済みの会員単位（未ログインは IP）。計測リンクの連打を抑える
        RateLimiter::for('campaign-click', fn (Request $request) => Limit::perMinute(30)->by('campaign-click:'.$key($request)));

        // お気に入りの登録・解除: 連打で DB を叩き続けるのを抑える（ふつうの使い方では届かない値）
        RateLimiter::for('favorite-update', fn (Request $request) => Limit::perMinute(60)->by('favorite:'.$key($request)));

        // 振込先口座の登録・変更: 他人の口座を総当たりで試す・頻繁に付け替えるのを抑える
        RateLimiter::for('bank-account-update', fn (Request $request) => [
            Limit::perMinute(3)->by('bank-min:'.$key($request)),
            Limit::perDay(5)->by('bank-day:'.$key($request)),
        ]);
    }
}
