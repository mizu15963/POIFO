<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\User;
use App\Services\Events\EventParticipationException;
use App\Services\Events\EventParticipationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * イベント。サイトは静的なので、一覧・詳細はブラウザから API で取得して表示する（管理画面の更新がすぐ反映される）。
 * 参加は event_participants で受け付け、オープンチャットの URL は参加した会員にだけ返す。
 */
class EventController extends Controller
{
    /** イベント一覧（`?upcoming=1` で開催予定だけ） */
    public function index(Request $request): JsonResponse
    {
        $query = $request->boolean('upcoming') ? Event::upcoming() : Event::published();
        $events = $query->orderBy('sort_order')->orderBy('starts_at')
            ->withCount(['participants as counted_participants' => fn ($q) => $q->whereIn('status', EventParticipant::COUNTED_STATUSES)])
            ->limit(min((int) $request->query('limit', 50), 100))
            ->get();

        return response()->json(['data' => $events->map(fn (Event $e) => $this->summary($e))]);
    }

    /** イベント詳細。ログイン中なら自分の参加状況も返す */
    public function show(Request $request, Event $event): JsonResponse
    {
        abort_if($event->status === Event::STATUS_DRAFT, 404);

        $event->loadCount(['participants as counted_participants' => fn ($q) => $q->whereIn('status', EventParticipant::COUNTED_STATUSES)]);
        $participation = $this->participationOf($event, $this->member($request));
        $joined = $participation && $participation->isCounted();

        return response()->json(['data' => $this->summary($event) + [
            'body' => $event->body,
            'notes' => $event->notes,
            'participation' => $participation?->status,
            // オープンチャットは参加した会員にだけ見せる（終了後は出さない）
            'openchat_url' => $joined && $event->displayStatus() === Event::STATUS_PUBLISHED ? $event->openchat_url : null,
        ]]);
    }

    /** イベントに参加する（開始前・上限人数まで） */
    public function join(Request $request, Event $event, EventParticipationService $service): JsonResponse
    {
        abort_if($event->status === Event::STATUS_DRAFT, 404);

        try {
            $service->join($event, $request->user());
        } catch (EventParticipationException $e) {
            throw ValidationException::withMessages(['event' => $e->getMessage()]);
        }

        return $this->show($request, $event->refresh());
    }

    /** 参加を取り消す（開始前だけ） */
    public function cancel(Request $request, Event $event, EventParticipationService $service): JsonResponse
    {
        abort_if($event->status === Event::STATUS_DRAFT, 404);

        try {
            $service->cancel($event, $request->user());
        } catch (EventParticipationException $e) {
            throw ValidationException::withMessages(['event' => $e->getMessage()]);
        }

        return $this->show($request, $event->refresh());
    }

    private function member(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User && $user->isActive() ? $user : null;
    }

    private function participationOf(Event $event, ?User $user): ?EventParticipant
    {
        return $user ? EventParticipant::query()->where('event_id', $event->id)->where('user_id', $user->id)->first() : null;
    }

    private function summary(Event $e): array
    {
        $status = $e->displayStatus();

        return [
            'id' => $e->id,
            'title' => $e->title,
            'summary' => $e->summary,
            'starts_at' => $e->starts_at->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'format' => $e->format,
            'thumbnail_url' => $e->thumbnail_url,
            'status' => $status,
            'capacity' => $e->capacity,
            'remaining_seats' => $e->remainingSeats($e->counted_participants ?? null),
            'reward_points' => $e->reward_points,
            'accepting' => $status === Event::STATUS_PUBLISHED && $e->isOpenForEntry(),
        ];
    }
}
