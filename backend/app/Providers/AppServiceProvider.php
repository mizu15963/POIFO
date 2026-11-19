<?php

namespace App\Providers;

use App\Http\Middleware\RestrictViewerHost;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
