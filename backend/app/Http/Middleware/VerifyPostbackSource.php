<?php

namespace App\Http\Middleware;

use App\Models\PostbackLog;
use App\Services\Alerts\AdminAlerter;
use App\Support\ClientIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * 成果通知の受信口で、URL の秘密の文字列と送信元 IP を確かめる（WAF の IP 制限に加えた二重のチェック）。
 * 通らなかったリクエストも postback_logs に残し、管理者に知らせる。応答は 404（受信口の存在を見せない）。
 */
class VerifyPostbackSource
{
    public function __construct(private AdminAlerter $alerter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('poifo.postback.path_token');
        $given = (string) $request->route('token');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return $this->deny($request, 'invalid_path', '成果通知の受信URLに誤ったパスでアクセスがありました');
        }

        if (! $this->ipAllowed(ClientIp::trusted($request))) {
            return $this->deny($request, 'ip_denied', '許可していない送信元IPから成果通知が届きました');
        }

        return $next($request);
    }

    private function ipAllowed(?string $ip): bool
    {
        $allowed = config('poifo.postback.allowed_ips');

        if ($allowed === []) {
            // 設定漏れで素通しにならないよう、本番では全て拒否する
            return ! app()->isProduction();
        }

        return $ip !== null && IpUtils::checkIp($ip, $allowed);
    }

    private function deny(Request $request, string $result, string $title): Response
    {
        $params = $request->isMethod('post') ? (array) $request->json()->all() : $request->query();
        $params = array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $params);
        $ip = ClientIp::forLog($request);

        PostbackLog::create([
            'provider' => 'gf_rewards',
            'method' => $request->method(),
            'ip_address' => $ip,
            'params' => $params,
            'cv_id' => isset($params['cv_id']) ? substr((string) $params['cv_id'], 0, 128) : null,
            'result' => $result,
        ]);

        $this->alerter->notify('postback.'.$result, $title, ['送信元IP' => $ip, 'メソッド' => $request->method()]);

        return response('', 404);
    }
}
