<?php

namespace App\Http\Controllers;

use App\Models\PostbackLog;
use App\Services\GfRewards\PostbackHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GF Rewards の成果通知を受ける（GET クエリ、または POST の JSON）。
 *
 * - 送信元 IP の制限は WAF（POSTBACK_ALLOWED_CIDRS）で行う
 * - 応答は常に HTTP 200 と本文 0 / 1 / 2 だけ（空白・改行なし）
 * - 処理中の例外だけ 500 を返す（ASP がリトライする。最大計5回・10秒でタイムアウト）
 */
class PostbackController extends Controller
{
    public function gfRewards(Request $request, PostbackHandler $handler): Response
    {
        $params = $request->isMethod('post') ? $request->json()->all() : $request->query();
        $params = array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $params);

        $log = new PostbackLog([
            'provider' => 'gf_rewards',
            'method' => $request->method(),
            'ip_address' => $request->ip(),
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

            return response('error', 500)->header('Content-Type', 'text/plain');
        }

        $log->fill(['result' => $outcome['result'], 'response_code' => $outcome['response']])->save();

        return response((string) $outcome['response'], 200)->header('Content-Type', 'text/plain');
    }
}
