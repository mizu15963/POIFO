<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * イベント。サイトは静的なので、一覧・詳細はブラウザから API で取得して表示する（管理画面の更新がすぐ反映される）。
 */
class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Event::published()->orderBy('sort_order')->orderBy('starts_at');

        if ($request->boolean('upcoming')) {
            $query->where('status', Event::STATUS_PUBLISHED)
                ->where(fn ($q) => $q->where('ends_at', '>=', now())->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>=', now()->startOfDay())));
        }

        $events = $query->limit(min((int) $request->query('limit', 50), 100))->get();

        return response()->json(['data' => $events->map(fn (Event $e) => $this->summary($e))]);
    }

    public function show(Event $event): JsonResponse
    {
        abort_if($event->status === Event::STATUS_DRAFT, 404);

        return response()->json(['data' => $this->summary($event) + [
            'body' => $event->body,
            'notes' => $event->notes,
            'openchat_url' => $event->status === Event::STATUS_PUBLISHED ? $event->openchat_url : null,
        ]]);
    }

    private function summary(Event $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'summary' => $e->summary,
            'starts_at' => $e->starts_at->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'format' => $e->format,
            'thumbnail_url' => $e->thumbnail_url,
            'status' => $e->status,
        ];
    }
}
