<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\PointTransaction;
use App\Services\Members\MemberException;
use App\Services\Members\MemberService;
use App\Services\Points\PointBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    /**
     * ログイン中の会員の情報。
     *
     * uid は会員ID（問い合わせのときに伝えてもらう）。unread_news_count はお知らせの未読の数（ヘッダーのバッジ）。
     */
    public function show(Request $request, PointBalance $balance): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'uid' => $user->uid,
                'display_name' => $user->display_name,
                'avatar_url' => $user->avatar_url,
                'registered_at' => $user->created_at?->toIso8601String(),
                'points' => $balance->for($user),
                'unread_news_count' => $user->unreadNewsCount(),
                'line_friend_url' => config('poifo.line.official_friend_url'),
            ],
        ]);
    }

    /**
     * 案件の利用履歴（「この案件に進む」を押した記録。直近90日、新しい順に20件ずつ）。
     *
     * 成果がつかないときの問い合わせの手がかりにする。掲載が終わった案件は listed が false（案件詳細には進ませない）。
     */
    public function clicks(Request $request): JsonResponse
    {
        $page = CampaignClick::query()
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->with('campaign:id,name,icon_url')
            ->latest('created_at')->latest('id')
            ->paginate(20);
        $listedIds = Campaign::listed()->whereIn('id', collect($page->items())->pluck('campaign_id'))->pluck('id')->flip();

        return response()->json([
            'data' => collect($page->items())->map(fn (CampaignClick $c) => [
                'id' => $c->id,
                'campaign_id' => $c->campaign_id,
                'campaign_name' => $c->campaign?->name,
                'icon_url' => $c->campaign?->icon_url,
                'listed' => isset($listedIds[$c->campaign_id]),
                'clicked_at' => $c->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function pointHistory(Request $request): JsonResponse
    {
        $page = PointTransaction::query()
            ->where('user_id', $request->user()->id)
            ->with('withdrawalRequest:id,exchange_method')
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (PointTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'type_label' => PointTransaction::TYPE_LABELS[$t->type] ?? $t->type,
                'status' => $t->status,
                'points' => $t->points,
                'description' => $t->description,
                // 交換（換金）とその取り消しの行だけ。どの交換先か
                'exchange_method' => $t->withdrawalRequest?->exchange_method,
                'exchange_method_name' => $t->withdrawalRequest?->exchangeMethodName(),
                'created_at' => $t->created_at?->toIso8601String(),
                'confirmed_at' => $t->confirmed_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * 退会する。注意事項への同意（`agree`）が必要。手続き中の換金申請があるときはできない。
     * 残りのポイントは失効し、同じ LINE アカウントでは再登録できない。
     */
    public function leave(Request $request, MemberService $members): JsonResponse
    {
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => '注意事項に同意してください。']);

        try {
            $members->leave($request->user());
        } catch (MemberException $e) {
            throw ValidationException::withMessages(['leave' => $e->getMessage()]);
        }

        return $this->logout($request);
    }
}
