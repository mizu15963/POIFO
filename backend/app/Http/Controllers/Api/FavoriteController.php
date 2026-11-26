<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Favorite;
use App\Services\Favorites\FavoriteException;
use App\Services\Favorites\FavoriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 会員のお気に入り案件（docs/specs/functional-spec.md 4.5）。
 */
class FavoriteController extends Controller
{
    /**
     * お気に入りの一覧（登録の新しい順に20件ずつ）。
     *
     * 掲載が終わった・非表示の案件も残し、listed を false にして返す（会員サイトでは「掲載終了」と出し、案件詳細には進ませない）。
     * meta の total は登録数、limit は登録できる上限。
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = Favorite::query()->where('user_id', $user->id)->with('campaign')
            ->latest('created_at')->latest('id')->paginate(20);
        $listedIds = Campaign::listed()->whereIn('id', collect($page->items())->pluck('campaign_id'))->pluck('id')->flip();

        return response()->json([
            'data' => collect($page->items())->map(fn (Favorite $f) => CampaignController::summary($f->campaign) + [
                'is_favorite' => true,
                'listed' => isset($listedIds[$f->campaign_id]),
                'favorited_at' => $f->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'limit' => Favorite::LIMIT,
            ],
        ]);
    }

    /**
     * お気に入りに登録する。
     *
     * 登録済みでもエラーにしない。掲載中の案件だけ登録できる。上限（200件）を超えると 422。
     */
    public function store(Request $request, Campaign $campaign, FavoriteService $favorites): JsonResponse
    {
        abort_unless(Campaign::listed()->whereKey($campaign->id)->exists(), 404);

        try {
            $favorites->add($request->user(), $campaign);
        } catch (FavoriteException $e) {
            throw ValidationException::withMessages(['favorite' => $e->getMessage()]);
        }

        return response()->json(['data' => ['campaign_id' => $campaign->id, 'is_favorite' => true]]);
    }

    /**
     * お気に入りから外す。
     *
     * 登録していなくてもエラーにしない。掲載が終わった案件も外せる。
     */
    public function destroy(Request $request, Campaign $campaign, FavoriteService $favorites): JsonResponse
    {
        $favorites->remove($request->user(), $campaign);

        return response()->json(['data' => ['campaign_id' => $campaign->id, 'is_favorite' => false]]);
    }
}
