<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 運営からのお知らせ（docs/specs/functional-spec.md 11.1）。一覧・詳細はログインしなくても見られる。
 */
class NewsController extends Controller
{
    /**
     * お知らせの一覧（公開日時の新しい順に20件ずつ）。
     *
     * `important=1` で重要なものだけ（トップ・マイページの上に出す1件は `important=1&per_page=1`）。本文は返さない。
     */
    public function index(Request $request): JsonResponse
    {
        $page = News::visible()
            ->when($request->boolean('important'), fn ($q) => $q->where('is_important', true))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(max(1, min((int) $request->query('per_page', 20), 50)));

        return response()->json([
            'data' => collect($page->items())->map(fn (News $n) => self::summary($n)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * お知らせの詳細。
     *
     * 本文はプレーンテキスト（HTML は含めない）。表示する側で改行を保ち、URL をリンクにする。公開していない・公開が終わったものは 404。
     */
    public function show(News $news): JsonResponse
    {
        abort_unless($news->isVisible(), 404);

        return response()->json(['data' => self::summary($news) + ['body' => $news->body]]);
    }

    /**
     * お知らせ一覧を開いたことを記録する（未読の数を0にする）。
     */
    public function seen(Request $request): JsonResponse
    {
        $request->user()->forceFill(['news_seen_at' => now()])->save();

        return response()->json(['data' => ['unread_news_count' => 0]]);
    }

    /** @return array{id: int, title: string, is_important: bool, published_at: string|null} */
    private static function summary(News $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title,
            'is_important' => $n->is_important,
            'published_at' => $n->published_at?->toIso8601String(),
        ];
    }
}
