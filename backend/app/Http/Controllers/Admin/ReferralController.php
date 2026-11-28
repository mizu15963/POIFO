<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\PointTransaction;
use App\Models\ReferralRank;
use App\Models\ReferralReward;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use App\Services\Referrals\ReferralException;
use App\Services\Referrals\ReferralService;
use App\Services\Referrals\ReferralSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 友達紹介の管理（機能仕様 15.7、画面 A16 紹介報酬・A17 紹介の設定、会員詳細の紹介の操作）。
 */
class ReferralController extends Controller
{
    public function __construct(private ReferralService $referrals, private AuditLogger $audit) {}

    /** A16 紹介報酬 */
    public function rewards(Request $request): View
    {
        $filters = $this->filters($request);

        $rewards = $this->query($filters)->with(['referrer', 'referee', 'pointTransaction', 'conversion'])
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.referrals.rewards', [
            'rewards' => $rewards,
            'filters' => $filters,
            'sameBank' => $this->sameBankPairs(collect($rewards->items())),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $rewards = $this->query($filters)->with(['referrer', 'referee', 'pointTransaction', 'conversion'])->latest('id')->get();
        $sameBank = $this->sameBankPairs($rewards);

        $this->audit->log(Auth::guard('admin')->user(), 'referral.export', payload: array_filter($filters) + ['count' => $rewards->count()]);

        return response()->streamDownload(function () use ($rewards, $sameBank) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['報酬ID', '日時', '紹介した人（会員ID）', '紹介した友達（会員ID）', '種類', '元の成果（cv_id）', '元にしたポイント', '率（%）', 'ランク', 'ポイント', '状態', '確定日時', '注意'], escape: '', eol: "\r\n");
            foreach ($rewards as $r) {
                fputcsv($out, array_map(WithdrawalController::csvCell(...), [
                    $r->id,
                    $r->created_at?->format('Y-m-d H:i'),
                    $r->referrer?->uid,
                    $r->referee?->uid,
                    ReferralReward::TYPE_LABELS[$r->type] ?? $r->type,
                    $r->conversion?->cv_id ?? '',
                    $r->base_points,
                    $r->rate !== null ? ReferralRank::formatBps(ReferralRank::toBps($r->rate)) : '',
                    $r->rank_name ?? $r->rank,
                    $r->points,
                    PointTransaction::STATUS_LABELS[$r->pointTransaction?->status] ?? '',
                    $r->pointTransaction?->confirmed_at?->format('Y-m-d H:i') ?? '',
                    isset($sameBank[$r->referrer_user_id.':'.$r->referee_user_id]) ? '同じ振込先口座' : '',
                ]), escape: '', eol: "\r\n");
            }
            fclose($out);
        }, 'poifo-referral-rewards-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A17 紹介の設定 */
    public function settings(): View
    {
        return view('admin.referrals.settings', [
            'ranks' => $this->referrals->generalRanks(),
            'partner' => $this->referrals->partnerRank(),
            'settings' => ReferralSetting::current(),
        ]);
    }

    public function updateSettings(Request $request, ReferralSettingsService $service): RedirectResponse
    {
        $rate = ['required', 'numeric', 'between:0,100', 'decimal:0,2'];
        $data = $request->validate([
            'ranks' => ['required', 'array', 'max:20'],
            'ranks.*.id' => ['nullable', 'integer'],
            'ranks.*.delete' => ['nullable', 'boolean'],
            'ranks.*.name' => ['required_unless:ranks.*.delete,1', 'nullable', 'string', 'max:32'],
            'ranks.*.rate' => ['required_unless:ranks.*.delete,1', 'nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'ranks.*.min_valid_referrals' => ['required_unless:ranks.*.delete,1', 'nullable', 'integer', 'between:0,1000000'],
            'partner.name' => ['required', 'string', 'max:32'],
            'partner.rate' => $rate,
            'initial_bonus_points' => ['required', 'integer', 'between:0,100000'],
            'initial_threshold_points' => ['required', 'integer', 'between:0,10000000'],
            'initial_days' => ['required', 'integer', 'between:1,3650'],
        ], [], [
            'ranks.*.name' => 'ランクの名前',
            'ranks.*.rate' => 'ランクの率',
            'ranks.*.min_valid_referrals' => '必要な有効紹介人数',
            'partner.name' => '特別パートナーの名前',
            'partner.rate' => '特別パートナーの率の上限',
            'initial_bonus_points' => '初回特典のポイント',
            'initial_threshold_points' => '初回特典の条件のポイント',
            'initial_days' => '初回特典の期限の日数',
        ]);

        // 画面の「追加」欄が空のままなら無視する
        $ranks = array_values(array_filter($data['ranks'], fn ($r) => ! empty($r['id']) || ! empty($r['delete'])
            || ($r['name'] ?? '') !== '' || ($r['rate'] ?? '') !== '' || ($r['min_valid_referrals'] ?? '') !== ''));
        foreach ($ranks as $r) {
            if (empty($r['delete']) && (($r['name'] ?? '') === '' || ($r['rate'] ?? '') === '' || ($r['min_valid_referrals'] ?? '') === '')) {
                return back()->withErrors(['ranks' => '追加するランクは名前・率・必要な人数をすべて入れてください。'])->withInput();
            }
        }

        try {
            $diff = $service->save($ranks, $data['partner'], $data);
        } catch (ReferralException $e) {
            return back()->withErrors(['ranks' => $e->getMessage()])->withInput();
        }

        $this->audit->log(Auth::guard('admin')->user(), 'referral.settings_update', payload: $diff);

        return back()->with('status', '紹介の設定を保存しました。確定済みの紹介報酬は変わりません。');
    }

    /** 会員を特別パートナーにする・外す */
    public function partner(Request $request, User $member): RedirectResponse
    {
        $data = $request->validate([
            'partner' => ['required', Rule::in(['1', '0'])],
            'rate' => ['required_if:partner,1', 'nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['rate' => '特別パートナーの率', 'reason' => '理由']);

        $before = $member->referral_partner_rate;
        $rate = $data['partner'] === '1' ? (string) $data['rate'] : null;

        try {
            $this->referrals->setPartnerRate($member, $rate);
        } catch (ReferralException $e) {
            return back()->withErrors(['referral' => $e->getMessage()])->withInput();
        }

        $this->audit->log(Auth::guard('admin')->user(), 'referral.partner', 'user', $member->id, [
            'before' => $before, 'after' => $member->referral_partner_rate, 'reason' => $data['reason'],
        ]);

        return back()->with('status', $rate === null ? '特別パートナーを外しました。' : '特別パートナーにしました（率 '.$rate.'%）。');
    }

    /** 紹介を無効にする（理由必須）・無効を取り消す */
    public function disable(Request $request, User $member): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => '理由']);

        try {
            $rejected = $this->referrals->disable($member);
        } catch (ReferralException $e) {
            return back()->withErrors(['referral' => $e->getMessage()]);
        }

        $this->audit->log(Auth::guard('admin')->user(), 'referral.disable', 'user', $member->id, ['reason' => $data['reason'], 'rejected' => $rejected]);

        return back()->with('status', "紹介を無効にしました（判定中の紹介報酬 {$rejected}件を否認）。");
    }

    public function enable(Request $request, User $member): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => '理由']);

        try {
            $this->referrals->enable($member);
        } catch (ReferralException $e) {
            return back()->withErrors(['referral' => $e->getMessage()]);
        }

        $this->audit->log(Auth::guard('admin')->user(), 'referral.enable', 'user', $member->id, ['reason' => $data['reason']]);

        return back()->with('status', '紹介の無効を取り消しました。否認にした紹介報酬は戻りません。');
    }

    /** @return array{referrer: string, referee: string, type: ?string, status: ?string, from: ?string, to: ?string} */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : null;

        return [
            'referrer' => strtolower(trim((string) $request->query('referrer', ''))),
            'referee' => strtolower(trim((string) $request->query('referee', ''))),
            'type' => array_key_exists((string) $request->query('type'), ReferralReward::TYPE_LABELS) ? (string) $request->query('type') : null,
            'status' => array_key_exists((string) $request->query('status'), PointTransaction::STATUS_LABELS) ? (string) $request->query('status') : null,
            'from' => $date('from'),
            'to' => $date('to'),
        ];
    }

    private function query(array $f): Builder
    {
        return ReferralReward::query()
            ->when($f['referrer'] !== '', fn ($q) => $q->whereHas('referrer', fn ($u) => $u->where('uid', $f['referrer'])))
            ->when($f['referee'] !== '', fn ($q) => $q->whereHas('referee', fn ($u) => $u->where('uid', $f['referee'])))
            ->when($f['type'], fn ($q) => $q->where('type', $f['type']))
            ->when($f['status'], fn ($q) => $q->whereHas('pointTransaction', fn ($t) => $t->where('status', $f['status'])))
            ->when($f['from'], fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse($f['from'])->startOfDay()))
            ->when($f['to'], fn ($q) => $q->where('created_at', '<=', CarbonImmutable::parse($f['to'])->endOfDay()));
    }

    /**
     * 紹介した人と紹介した友達の振込先口座が同じ（同じ口座・同じ名義）組（機能仕様 15.6）。
     *
     * @return array<string, true> キーは「紹介した人のid:友達のid」
     */
    public static function sameBankPairs(Collection $rewards): array
    {
        $userIds = $rewards->flatMap(fn ($r) => [$r->referrer_user_id, $r->referee_user_id])->unique()->values();
        $banks = BankAccount::query()->whereIn('user_id', $userIds)->get(['user_id', 'account_fingerprint', 'holder_name_key'])->keyBy('user_id');

        $pairs = [];
        foreach ($rewards as $r) {
            $a = $banks[$r->referrer_user_id] ?? null;
            $b = $banks[$r->referee_user_id] ?? null;
            if ($a && $b && ($a->account_fingerprint === $b->account_fingerprint || ($a->holder_name_key && $a->holder_name_key === $b->holder_name_key))) {
                $pairs[$r->referrer_user_id.':'.$r->referee_user_id] = true;
            }
        }

        return $pairs;
    }
}
