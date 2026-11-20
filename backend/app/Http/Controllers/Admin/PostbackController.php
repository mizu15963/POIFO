<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversion;
use App\Models\PostbackLog;
use App\Services\Admin\AuditLogger;
use App\Services\GfRewards\PostbackHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

/**
 * 成果通知の受信ログと、保留した成果の確認・解除（仕様書 5章）。
 */
class PostbackController extends Controller
{
    public const RESULT_LABELS = [
        'granted' => '付与',
        'pending' => '判定中',
        'rejected' => '否認',
        'duplicate' => '重複（前回と同じ応答）',
        'held' => '保留',
        'unknown_user' => '会員不明（保留）',
        'withdrawn_user' => '退会済みの会員',
        'invalid_hash' => '署名不一致',
        'invalid_params' => 'パラメータ不足',
        'invalid_path' => 'パス違い',
        'ip_denied' => '送信元 IP 不一致',
        'error' => '処理失敗',
    ];

    public function index(Request $request): View
    {
        $result = array_key_exists($request->query('result'), self::RESULT_LABELS) ? $request->query('result') : null;
        $cvId = trim((string) $request->query('cv_id', ''));
        $date = $request->date('date');

        $logs = PostbackLog::query()
            ->when($result, fn ($q) => $q->where('result', $result))
            ->when($cvId !== '', fn ($q) => $q->where('cv_id', $cvId))
            ->when($date, fn ($q) => $q->whereBetween('created_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.postbacks.index', [
            'logs' => $logs, 'result' => $result, 'cvId' => $cvId, 'date' => $date, 'labels' => self::RESULT_LABELS,
        ]);
    }

    public function show(PostbackLog $log): View
    {
        return view('admin.postbacks.show', ['log' => $log, 'labels' => self::RESULT_LABELS]);
    }

    public function held(): View
    {
        return view('admin.postbacks.held', [
            'conversions' => Conversion::query()->with('user')->whereNotNull('hold_reason')->orderBy('id')->get(),
        ]);
    }

    public function release(Conversion $conversion, PostbackHandler $handler, AuditLogger $audit): RedirectResponse
    {
        $reason = $conversion->hold_reason;

        try {
            $result = $handler->releaseHold($conversion->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['conversion' => $e->getMessage()]);
        }

        $audit->log(Auth::guard('admin')->user(), 'conversion.release_hold', 'conversion', $conversion->id, [
            'hold_reason' => $reason, 'points' => $conversion->points, 'transaction_status' => $result['transaction_status'],
        ]);

        return back()->with('status', "成果 {$conversion->cv_id} の保留を解除しました（台帳: ".($result['transaction_status'] === 'confirmed' ? '確定' : '判定中').'）。');
    }
}
