<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * 閲覧者（送信元）の IP アドレスを取る。
 *
 * 本番は CloudFront 経由で届くので、$request->ip()（X-Forwarded-For の先頭）は送信者が偽装できる。
 * API 用の CloudFront Function が event.viewer.ip を上書きで付けるヘッダー（既定 x-poifo-viewer-ip）を読む。
 * このヘッダーを信用できるのは、nginx が CloudFront の秘密のオリジンヘッダーを検証して
 * CloudFront 以外からのリクエストを落としているから（直接アクセスなら誰でも付けられる）。
 */
class ClientIp
{
    /** 信用できる送信元 IP。ヘッダーがない・読めないときは null */
    public static function trusted(Request $request): ?string
    {
        $header = (string) config('poifo.client_ip.header');
        $value = $header !== '' ? trim((string) $request->headers->get($header, '')) : '';

        if ($value !== '') {
            return self::parse($value);
        }

        // ローカル開発ではヘッダーが付かないので、接続元の IP で代用する（本番は false）
        return config('poifo.client_ip.allow_remote_addr_fallback') ? $request->server('REMOTE_ADDR') : null;
    }

    /** 記録用。信用できる IP がなければ $request->ip() を使う */
    public static function forLog(Request $request): ?string
    {
        return self::trusted($request) ?? $request->ip();
    }

    /**
     * 「IP」または「IP:ポート」（CloudFront-Viewer-Address の形。IPv6 は角かっこなしで末尾に :ポート）を IP にする
     */
    public static function parse(string $value): ?string
    {
        $value = trim($value, " \t[]");

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }

        $pos = strrpos($value, ':');
        if ($pos === false) {
            return null;
        }

        $ip = trim(substr($value, 0, $pos), '[]');

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }
}
