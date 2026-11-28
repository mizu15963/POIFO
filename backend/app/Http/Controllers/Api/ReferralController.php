<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointTransaction;
use App\Models\ReferralRank;
use App\Models\ReferralSetting;
use App\Services\Referrals\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 友達紹介（docs/specs/functional-spec.md 15章、画面 S25・S26）。
 * 紹介した友達の名前・LINE の情報・使った案件は返さない。
 */
class ReferralController extends Controller
{
    public function __construct(private ReferralService $referrals) {}

    /**
     * 紹介ページの情報。紹介コード（なければ発行）・紹介URL・ランク・人数と紹介報酬の集計・ランク一覧・初回特典の条件。
     *
     * 率は % の数値（例: 8.5）。next_rank は次のランク（いちばん上・特別パートナーは null）。
     * disabled が true のときは運営が紹介を無効にしている（紹介報酬は付かない）。
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = $this->referrals->codeFor($user);
        $rank = $this->referrals->rankFor($user);
        $settings = ReferralSetting::current();
        $general = $this->referrals->generalRanks();
        $partner = $this->referrals->partnerRank();
        $rates = $general->map(fn (ReferralRank $r) => $r->rateBps())->push($partner?->rateBps() ?? 0);

        return response()->json([
            'data' => [
                'code' => $code,
                'url' => $this->referrals->inviteUrl($code),
                'disabled' => $user->referral_disabled_at !== null,
                'rank' => [
                    'code' => $rank['code'],
                    'name' => $rank['name'],
                    'rate' => $rank['rate_bps'] / 100,
                    'is_partner' => $rank['is_partner'],
                ],
                'next_rank' => $rank['next'] ? [
                    'name' => $rank['next']['name'],
                    'rate' => $rank['next']['rate_bps'] / 100,
                    'min_valid_referrals' => $rank['next']['min_valid_referrals'],
                    'remaining' => $rank['next']['remaining'],
                ] : null,
                'summary' => $this->referrals->summaryFor($user, $rank['valid_referrals']),
                'ranks' => $general->map(fn (ReferralRank $r) => [
                    'code' => $r->code,
                    'name' => $r->name,
                    'rate' => $r->rateBps() / 100,
                    'min_valid_referrals' => $r->min_valid_referrals,
                ])->values()->push([
                    'code' => ReferralRank::PARTNER,
                    'name' => $partner?->name ?? '特別パートナー',
                    // 特別パートナーは個別の率。ここは上限
                    'rate' => ($partner?->rateBps() ?? 0) / 100,
                    'min_valid_referrals' => null,
                ]),
                'rate_range' => ['min' => $rates->min() / 100, 'max' => $rates->max() / 100],
                'initial_bonus' => [
                    'points' => $settings->initial_bonus_points,
                    'threshold_points' => $settings->initial_threshold_points,
                    'days' => $settings->initial_days,
                ],
            ],
        ]);
    }

    /** 紹介報酬の履歴（紹介報酬・紹介の初回特典。新しい順に20件ずつ） */
    public function rewards(Request $request): JsonResponse
    {
        $page = PointTransaction::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('type', [PointTransaction::TYPE_REFERRAL, PointTransaction::TYPE_REFERRAL_INITIAL])
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (PointTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'type_label' => PointTransaction::TYPE_LABELS[$t->type] ?? $t->type,
                'status' => $t->status,
                'points' => $t->points,
                'created_at' => $t->created_at?->toIso8601String(),
                'confirmed_at' => $t->confirmed_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * 紹介コードが使えるか（紹介ページ S26 用）。紹介した人の名前などは返さない。
     */
    public function check(string $code): JsonResponse
    {
        return response()->json(['data' => ['valid' => $this->referrals->findReferrer($code) !== null]]);
    }
}
