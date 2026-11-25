<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * リダイレクト先などの URL を、閲覧者がアクセスしたホスト（api. / admin.）で作る。
 *
 * CloudFront からオリジンへの Host はオリジン名（origin.<domain>）なので、そのままだと route() や redirect() が
 * https://origin.<domain>/... を返し、閲覧者を CloudFront を通らない入口に飛ばしてしまう
 * （nginx が秘密ヘッダーなしとして 403 にする。管理画面のログイン後や LINE ログインの戻り先で起きる）。
 * CloudFront Function が付ける x-poifo-viewer-host が設定済みのホスト名と一致するときだけ置き換える。
 */
class UseViewerHostForUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('x-poifo-viewer-host');

        if ($header !== null) {
            $host = strtolower((string) preg_replace('/:\d+$/', '', $header));
            $known = array_map('strtolower', array_filter([config('poifo.hosts.api'), config('poifo.hosts.admin')]));

            if (in_array($host, $known, true)) {
                // Host ごと置き換え、$request->url() / fullUrl()（ログイン後に戻る先の記録など）も揃える
                $request->headers->set('host', $host);
                $request->server->set('HTTP_HOST', $host);
                URL::forceRootUrl('https://'.$host);
                URL::forceScheme('https');
            }
        }

        return $next($request);
    }
}
