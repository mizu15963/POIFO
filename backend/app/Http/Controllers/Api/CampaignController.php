<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\Favorite;
use App\Models\User;
use App\Support\ClientIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    /**
     * 掲載中の案件の一覧（20件ずつ、最大50件）。
     *
     * ログイン中なら各案件の is_favorite（お気に入り登録済みか）を返す。未ログインでは常に false。
     *
     * トップの案件メニュー（機能仕様 4.2）から: featured=1 でおすすめだけ、tag= で特集の印が付いた案件だけ、
     * sort=points でポイントの多い順（高還元。ポイントが決まっていない定率の案件は最後）。
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tag' => ['nullable', 'string', Rule::in(array_keys(Campaign::TAGS))],
            'sort' => ['nullable', 'string', Rule::in(['points'])],
        ]);

        $query = Campaign::listed();
        if ($request->query('sort') === 'points') {
            // MySQL・SQLite とも NULL は昇順で先に来るので、NULL かどうかで先に並べて定率を最後にする
            $query->orderByRaw('max_points IS NULL')->orderByDesc('max_points');
        }
        $query->orderByDesc('is_featured')->orderBy('sort_order')->orderByDesc('id');

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($tag = $request->query('tag')) {
            $query->tagged($tag);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $page = $query->paginate(min((int) $request->query('per_page', 20), 50));
        $favoriteIds = self::favoriteIdsOf(self::member($request), collect($page->items())->pluck('id')->all());

        return response()->json([
            'data' => collect($page->items())->map(fn (Campaign $c) => self::summary($c) + ['is_favorite' => isset($favoriteIds[$c->id])]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** 掲載中の案件のカテゴリ（件数つき）。一覧のタブに使う */
    public function categories(): JsonResponse
    {
        $rows = Campaign::listed()->whereNotNull('category')->where('category', '!=', '')
            ->selectRaw('category, COUNT(*) as count')->groupBy('category')->orderByDesc('count')->orderBy('category')
            ->get();

        return response()->json(['data' => $rows->map(fn ($r) => ['name' => $r->category, 'count' => (int) $r->count])]);
    }

    /** 案件の詳細。ログイン中なら is_favorite（お気に入り登録済みか）を返す */
    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless(Campaign::listed()->whereKey($campaign->id)->exists(), 404);
        $favoriteIds = self::favoriteIdsOf(self::member($request), [$campaign->id]);

        return response()->json(['data' => self::summary($campaign) + [
            'is_favorite' => isset($favoriteIds[$campaign->id]),
            'description' => $campaign->description,
            'conversion_condition' => $campaign->conversion_condition,
            'actions' => collect($campaign->conversion_actions ?? [])->map(fn (array $a) => [
                'name' => $a['name'] ?? null,
                'description' => $a['description'] ?? null,
                'points' => isset($a['incentive']['amount']) ? (int) floor($a['incentive']['amount']) : null,
                'approve_days' => $a['approve_conversion_limit_days'] ?? null,
            ])->values(),
        ]]);
    }

    /**
     * 案件へ遷移する。ASP の計測リンクに自社の会員ID（uid）を付けてリダイレクトする。
     * ログインしていないとポイントが付かないので、認証必須。
     */
    public function click(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless(Campaign::listed()->whereKey($campaign->id)->exists(), 404);

        $user = $request->user();
        abort_unless($user->isActive(), 403);

        CampaignClick::create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
            'ip_address' => ClientIp::forLog($request),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        $separator = str_contains($campaign->click_url, '?') ? '&' : '?';

        return redirect()->away($campaign->click_url.$separator.http_build_query(['user_id' => $user->uid]));
    }

    /** 公開の API でも、Cookie でログインしていれば会員を返す（停止・退会した会員は未ログイン扱い） */
    private static function member(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User && $user->isActive() ? $user : null;
    }

    /**
     * 指定した案件のうち、会員がお気に入りにしているものの ID（キーが案件 ID）
     *
     * @param  list<int>  $campaignIds
     * @return array<int, int>
     */
    private static function favoriteIdsOf(?User $user, array $campaignIds): array
    {
        if (! $user || $campaignIds === []) {
            return [];
        }

        return Favorite::query()->where('user_id', $user->id)->whereIn('campaign_id', $campaignIds)
            ->pluck('campaign_id')->flip()->all();
    }

    /**
     * 案件カードに出す項目（お気に入り一覧でも使う）
     *
     * @return array{id: int, name: string, category: string|null, platform: string|null, icon_url: string|null, points: int|null, is_featured: bool, ends_at: string|null}
     */
    public static function summary(Campaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'category' => $c->category,
            'platform' => $c->platform,
            'icon_url' => $c->icon_url,
            'points' => $c->max_points,
            'is_featured' => $c->is_featured,
            'ends_at' => $c->ends_at?->toIso8601String(),
        ];
    }
}
