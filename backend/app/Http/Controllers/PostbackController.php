<?php

namespace App\Http\Controllers;

use App\Models\Conversion;
use App\Models\PostbackLog;
use App\Services\Alerts\AdminAlerter;
use App\Services\GfRewards\PostbackHandler;
use App\Support\ClientIp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GF Rewards の成果通知を受ける（GET クエリ、または POST の JSON）。
 *
 * - URL の秘密の文字列と送信元 IP は VerifyPostbackSource で確かめる（WAF の IP 制限と二重）
 * - 応答は常に HTTP 200 と本文 0 / 1 / 2 だけ（空白・改行なし）
 * - 処理中の例外だけ 500 を返す（ASP がリトライする。最大計5回・10秒でタイムアウト）
 * - 署名の不一致・保留・短時間の大量受信は管理者にメールで知らせる（送信はキュー）
 */
class PostbackController extends Controller
{
    public function gfRewards(Request $request, PostbackHandler $handler, AdminAlerter $alerter): Response
    {
        $params = $request->isMethod('post') ? $request->json()->all() : $request->query();
        $params = array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $params);

        $log = new PostbackLog([
            'provider' => 'gf_rewards',
            'method' => $request->method(),
            'ip_address' => ClientIp::forLog($request),
            'params' => $params,
            'cv_id' => isset($params['cv_id']) ? substr((string) $params['cv_id'], 0, 128) : null,
        ]);

        try {
            if (! $handler->verifyHash($params)) {
                $outcome = ['result' => 'invalid_hash', 'response' => PostbackHandler::RESPONSE_NOT_GRANTED];
            } else {
                $log->hash_valid = true;
                $outcome = $handler->handle($params);
            }
        } catch (Throwable $e) {
            Log::error('Postback failed', ['cv_id' => $log->cv_id, 'exception' => $e]);
            $log->fill(['result' => 'error', 'error' => substr($e->getMessage(), 0, 2000)])->save();
            $alerter->notify('postback.error', '成果通知の処理に失敗しました', ['cv_id' => $log->cv_id]);

            return response('error', 500)->header('Content-Type', 'text/plain');
        }

        $log->fill(['result' => $outcome['result'], 'response_code' => $outcome['response']])->save();

        $this->alertIfNeeded($alerter, $log, $outcome);

        return response((string) $outcome['response'], 200)->header('Content-Type', 'text/plain');
    }

    private function alertIfNeeded(AdminAlerter $alerter, PostbackLog $log, array $outcome): void
    {
        $details = ['cv_id' => $log->cv_id, '送信元IP' => $log->ip_address];

        if ($outcome['result'] === 'invalid_hash') {
            $alerter->notify('postback.invalid_hash', '署名（hash）が一致しない成果通知が届きました', $details);
        }

        if (isset($outcome['hold_reason'])) {
            $reason = Conversion::HOLD_REASONS[$outcome['hold_reason']] ?? $outcome['hold_reason'];
            $alerter->notify('postback.held.'.$outcome['hold_reason'], '成果通知を保留にしました（'.$reason.'）', $details);
        }

        $limit = (int) config('poifo.postback.burst_per_minute');
        if ($limit > 0 && PostbackLog::where('created_at', '>=', now()->subMinute())->count() > $limit) {
            $alerter->notify('postback.burst', '成果通知が短時間に大量に届いています', ['直近1分の件数の上限' => $limit]);
        }
    }
}
