<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Services\Admin\AuditLogger;
use App\Services\Events\EventParticipationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * イベントの登録・編集と参加者の管理（仕様書 3章）。
 */
class EventController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $events = Event::query()
            ->withCount(['participants as counted_participants' => fn ($q) => $q->whereIn('status', EventParticipant::COUNTED_STATUSES)])
            ->orderByDesc('starts_at')->paginate(30);

        return view('admin.events.index', ['events' => $events]);
    }

    public function create(): View
    {
        return view('admin.events.form', ['event' => new Event(['status' => Event::STATUS_DRAFT, 'sort_order' => 0, 'reward_points' => 0])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $event = Event::create($this->validated($request));
        $this->audit->log(Auth::guard('admin')->user(), 'event.create', 'event', $event->id);

        return redirect()->route('admin.events.show', $event)->with('status', 'イベントを登録しました。');
    }

    public function show(Request $request, Event $event): View
    {
        $status = array_key_exists($request->query('status'), EventParticipant::STATUS_LABELS) ? $request->query('status') : null;

        return view('admin.events.show', [
            'event' => $event,
            'participants' => $event->participants()->with('user')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderBy('joined_at')->get(),
            'counted' => $event->countedParticipants(),
            'status' => $status,
        ]);
    }

    public function edit(Event $event): View
    {
        return view('admin.events.form', ['event' => $event]);
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $data = $this->validated($request, $event);
        $event->update($data);
        $this->audit->log(Auth::guard('admin')->user(), 'event.update', 'event', $event->id, ['changed' => array_keys($event->getChanges())]);

        return redirect()->route('admin.events.show', $event)->with('status', 'イベントを保存しました。');
    }

    public function destroy(Event $event): RedirectResponse
    {
        // 会員がリンクを持っている可能性があるので、消せるのは下書きで参加者がいないものだけ
        if ($event->status !== Event::STATUS_DRAFT || $event->participants()->exists()) {
            return back()->withErrors(['event' => '削除できるのは、参加者がいない下書きのイベントだけです。']);
        }

        $this->audit->log(Auth::guard('admin')->user(), 'event.delete', 'event', $event->id, ['title' => $event->title]);
        $event->delete();

        return redirect()->route('admin.events.index')->with('status', 'イベントを削除しました。');
    }

    /** 参加者に「参加済み（参加ポイントを付与）」か「不参加」を付ける */
    public function check(Request $request, Event $event, EventParticipationService $service): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['attended', 'absent'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], ['ids.required' => '対象の参加者を選んでください。']);

        $admin = Auth::guard('admin')->user();
        $done = 0;

        foreach ($event->participants()->whereIn('id', $data['ids'])->orderBy('id')->get() as $participant) {
            if ($service->check($participant, $admin, $data['action'] === 'attended')) {
                $this->audit->log($admin, 'event_participant.'.$data['action'], 'event_participant', $participant->id, [
                    'event_id' => $event->id, 'reward_points' => $data['action'] === 'attended' ? $event->reward_points : 0,
                ]);
                $done++;
            }
        }

        $label = $data['action'] === 'attended' ? '参加済みに' : '不参加に';

        return back()->with('status', "{$done} 人を{$label}しました（参加予定の人だけが対象です）。");
    }

    /** 参加者一覧の CSV（会員ID・表示名・状態・日時。個人情報は含めない） */
    public function export(Event $event): StreamedResponse
    {
        $this->audit->log(Auth::guard('admin')->user(), 'event.export_participants', 'event', $event->id);
        $participants = $event->participants()->with('user')->orderBy('joined_at')->get();

        return response()->streamDownload(function () use ($participants) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $row = fn (array $cols) => fwrite($out, implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', WithdrawalController::csvCell((string) $v)).'"', $cols))."\r\n");
            $row(['会員ID', '表示名', '状態', '参加日時', '取り消し日時', '確認日時']);
            foreach ($participants as $p) {
                $row([
                    $p->user?->uid, $p->user?->display_name, EventParticipant::STATUS_LABELS[$p->status] ?? $p->status,
                    $p->joined_at?->format('Y-m-d H:i'), $p->cancelled_at?->format('Y-m-d H:i'), $p->checked_at?->format('Y-m-d H:i'),
                ]);
            }
            fclose($out);
        }, 'event-'.$event->id.'-participants.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function validated(Request $request, ?Event $event = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'summary' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'format' => ['nullable', 'string', 'max:50'],
            'thumbnail_url' => ['nullable', 'url:https', 'max:1024'],
            'openchat_url' => ['nullable', 'url:https', 'max:1024'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'reward_points' => ['required', 'integer', 'min:0', 'max:100000'],
            'sort_order' => ['required', 'integer', 'between:-9999,9999'],
            'status' => ['required', Rule::in(array_keys(Event::STATUS_LABELS))],
        ], [], [
            'title' => 'タイトル', 'starts_at' => '開始日時', 'ends_at' => '終了日時', 'thumbnail_url' => 'サムネイル URL',
            'openchat_url' => 'オープンチャット URL', 'capacity' => '参加上限人数', 'reward_points' => '参加ポイント',
        ]);

        // 上限人数は、すでに参加している人数より少なくしない
        if ($event && isset($data['capacity']) && $data['capacity'] < $event->countedParticipants()) {
            throw ValidationException::withMessages(['capacity' => '参加上限人数は、いまの参加人数（'.$event->countedParticipants().'人）以上にしてください。']);
        }

        return $data;
    }
}
