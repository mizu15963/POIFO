<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\WithdrawalRequest;
use App\Services\Admin\AuditLogger;
use App\Services\Exchange\ExchangeMethod;
use App\Services\Exchange\ExchangeMethods;
use App\Services\Withdrawals\WithdrawalException;
use App\Services\Withdrawals\WithdrawalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 交換（換金）申請の月次確認。月末に管理者が申請を確認（承認・却下）→ 交換先ごとの方法で処理 → 完了にする。
 * 銀行振込は CSV を出して銀行で振り込み、振込済みにする。
 */
class WithdrawalController extends Controller
{
    public function __construct(private WithdrawalService $withdrawals, private AuditLogger $audit, private ExchangeMethods $methods) {}

    public function index(Request $request): View
    {
        [$month, $status, $method] = $this->filters($request);
        $requests = $this->query($month, $status, $method)->with('user')->get();

        return view('admin.withdrawals.index', [
            'month' => $month,
            'status' => $status,
            'method' => $method,
            'methodLabels' => $this->methods->all()->map(fn (ExchangeMethod $m) => $m->name)->all(),
            'requests' => $requests,
            'warnings' => $this->duplicateWarnings($requests),
            'totals' => [
                'count' => $requests->count(),
                'amount' => $requests->sum(fn (WithdrawalRequest $r) => $r->transferYen()),
            ],
            'statusLabels' => WithdrawalRequest::STATUS_LABELS,
        ]);
    }

    /** チェックした申請をまとめて承認・却下・振込済みにする */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'paid'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['ids.required' => '対象の申請を選んでください。']);

        $admin = Auth::guard('admin')->user();
        $done = 0;
        $errors = [];

        foreach (WithdrawalRequest::whereIn('id', $data['ids'])->orderBy('id')->get() as $withdrawal) {
            try {
                match ($data['action']) {
                    'approve' => $this->withdrawals->approve($withdrawal, $admin),
                    'reject' => $this->withdrawals->reject($withdrawal, $admin, $data['note'] ?? null),
                    'paid' => $this->withdrawals->markPaid($withdrawal, $admin),
                };
                $this->audit->log($admin, 'withdrawal.'.$data['action'], 'withdrawal_request', $withdrawal->id, array_filter(['note' => $data['note'] ?? null]));
                $done++;
            } catch (WithdrawalException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $label = ['approve' => '承認', 'reject' => '却下', 'paid' => '振込済みに'][$data['action']];

        $response = back()->with('status', "{$done} 件を{$label}しました。");

        return $errors ? $response->withErrors($errors) : $response;
    }

    /**
     * 振込用の CSV（Excel で開けるよう UTF-8 BOM 付き）。画面の絞り込みと同じ条件で、銀行振込の申請だけを出す。
     * 個人情報を含むので、出力したことを操作ログに残す。
     */
    public function export(Request $request): StreamedResponse
    {
        [$month, $status] = $this->filters($request, defaultStatus: WithdrawalRequest::STATUS_APPROVED);
        $requests = $this->query($month, $status, ExchangeMethod::BANK_TRANSFER)->with('user')->get();
        $warnings = $this->duplicateWarnings($requests);

        $this->audit->log(Auth::guard('admin')->user(), 'withdrawal.export', payload: [
            'month' => $month->format('Y-m'),
            'status' => $status,
            'exchange_method' => ExchangeMethod::BANK_TRANSFER,
            'count' => $requests->count(),
        ]);

        $filename = sprintf('poifo-withdrawals-%s-%s.csv', $month->format('Y-m'), $status ?: 'all');

        return response()->streamDownload(function () use ($requests, $warnings) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['申請ID', '申請日時', '会員ID', '氏名', '口座名義（カナ）', '銀行コード', '銀行名', '支店コード', '支店名', '口座種別', '口座番号', '換金ポイント', '換金額（円）', '手数料（円）', '振込額（円）', '状態', '注意'], escape: '', eol: "\r\n");

            foreach ($requests as $r) {
                $bank = $r->recipient_snapshot ?? [];
                fputcsv($out, array_map(self::csvCell(...), [
                    $r->id,
                    $r->created_at->format('Y-m-d H:i'),
                    $r->user?->uid,
                    $bank['holder_name'] ?? '',
                    $bank['account_holder_kana'] ?? '',
                    // 先頭の 0 が消えないよう文字列のまま出す（Excel で開くときは列を文字列にする）
                    $bank['bank_code'] ?? '',
                    $bank['bank_name'] ?? '',
                    $bank['branch_code'] ?? '',
                    $bank['branch_name'] ?? '',
                    BankAccount::accountTypeLabel($bank['account_type'] ?? null),
                    $bank['account_number'] ?? '',
                    $r->points,
                    $r->amount_yen,
                    $r->fee_yen,
                    $r->transferYen(),
                    $r->statusLabel(),
                    implode(' / ', $warnings[$r->id] ?? []),
                ]), escape: '', eol: "\r\n");
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * CSV の数式インジェクション対策。氏名・銀行名などは会員の入力値なので、Excel で開いたときに
     * 数式として実行されないよう、先頭が = + - @ タブ CR の文字列には ' を付ける。
     */
    public static function csvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /** @return array{0: CarbonImmutable, 1: ?string, 2: ?string} */
    private function filters(Request $request, ?string $defaultStatus = null): array
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in(array_merge(array_keys(WithdrawalRequest::STATUS_LABELS), ['all']))],
            // 設定から消した交換先の申請も絞り込めるよう、値は文字列なら受け付ける
            'method' => ['nullable', 'string', 'max:40'],
        ]);

        $month = isset($data['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $data['month'])
            : CarbonImmutable::now()->startOfMonth();
        $status = $data['status'] ?? $defaultStatus;

        $method = $data['method'] ?? null;

        return [$month, $status === 'all' ? null : $status, $method === 'all' ? null : $method];
    }

    /** 申請日がその月の申請（月末の締めで、その月に出た申請をまとめて確認する） */
    private function query(CarbonImmutable $month, ?string $status, ?string $method = null): Builder
    {
        return WithdrawalRequest::query()
            ->whereBetween('created_at', [$month->startOfMonth(), $month->endOfMonth()])
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->when($method, fn (Builder $q) => $q->where('exchange_method', $method))
            ->orderBy('id');
    }

    /**
     * 不正対策: 同じ口座・同じ名義が、ほかの会員にも登録されていないか（銀行振込の申請だけ）。
     *
     * @param  Collection<int, WithdrawalRequest>  $requests
     * @return array<int, list<string>>
     */
    private function duplicateWarnings(Collection $requests): array
    {
        $requests = $requests->filter(fn (WithdrawalRequest $r) => $r->isBankTransfer());
        $fingerprints = $requests->map(fn ($r) => $r->recipient_snapshot['account_fingerprint'] ?? null)->filter()->unique()->values();
        $names = $requests->map(fn ($r) => $r->recipient_snapshot['holder_name_key'] ?? null)->filter()->unique()->values();

        $accounts = BankAccount::query()
            ->whereIn('account_fingerprint', $fingerprints)
            ->orWhereIn('holder_name_key', $names)
            ->get(['user_id', 'account_fingerprint', 'holder_name_key']);

        $warnings = [];

        foreach ($requests as $r) {
            $others = $accounts->where('user_id', '!=', $r->user_id);

            if ($others->where('account_fingerprint', $r->recipient_snapshot['account_fingerprint'] ?? '')->isNotEmpty()) {
                $warnings[$r->id][] = '同じ口座がほかの会員にも登録されています';
            }

            if ($others->where('holder_name_key', $r->recipient_snapshot['holder_name_key'] ?? '')->isNotEmpty()) {
                $warnings[$r->id][] = '同じ口座名義の会員がいます';
            }
        }

        return $warnings;
    }
}
