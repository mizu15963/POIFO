<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Support\ClientIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Campaign::listed()->orderByDesc('is_featured')->orderBy('sort_order')->orderByDesc('id');

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $page = $query->paginate(min((int) $request->query('per_page', 20), 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (Campaign $c) => $this->summary($c)),
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

    public function show(Campaign $campaign): JsonResponse
    {
        abort_unless(Campaign::listed()->whereKey($campaign->id)->exists(), 404);

        return response()->json(['data' => $this->summary($campaign) + [
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

    private function summary(Campaign $c): array
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
