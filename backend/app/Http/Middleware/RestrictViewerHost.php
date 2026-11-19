<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ルートを「閲覧者がアクセスしたホスト（api. / admin.）」で制限する。
 *
 * 本番では CloudFront → Lightsail のリクエストの Host はオリジン名（origin.<domain>）になるため、
 * 閲覧者のホスト名は CloudFront Function が付ける x-poifo-viewer-host ヘッダーで判断する
 * （閲覧者が同名ヘッダーを送っても CloudFront で上書きされる）。
 * 管理画面のルートが api. から見えないようにするのが目的。
 *
 * 使い方: ->middleware('viewer.host:admin')
 */
class RestrictViewerHost
{
    public function handle(Request $request, Closure $next, string $site): Response
    {
        $expected = config("poifo.hosts.{$site}");

        if (! $expected || self::viewerHost($request) !== strtolower($expected)) {
            abort(404);
        }

        return $next($request);
    }

    public static function viewerHost(Request $request): ?string
    {
        $host = $request->headers->get('x-poifo-viewer-host');

        if ($host === null && config('poifo.hosts.allow_host_header_fallback')) {
            $host = $request->getHost();
        }

        return $host === null ? null : strtolower(preg_replace('/:\d+$/', '', $host));
    }
}
