<?php

namespace App\Services\Events;

use App\Models\Admin;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * イベントの参加（docs/specs/functional-spec.md 8.2）。
 *
 *   参加予定（joined）──取り消し──> 取り消し（cancelled）──再参加──> 参加予定
 *        └──管理者が確認──> 参加済み（attended、参加ポイントを付与）／不参加（absent）
 *
 * 上限人数は、イベントの行をロックしてから数える（同時に押されても超えない。複数台のサーバでも DB で排他）。
 */
class EventParticipationService
{
    public function join(Event $event, User $user): EventParticipant
    {
        return DB::transaction(function () use ($event, $user) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            if (! $user->isActive()) {
                throw new EventParticipationException('現在このアカウントではイベントに参加できません。');
            }
            if (! $event->isOpenForEntry()) {
                throw new EventParticipationException('このイベントは参加を受け付けていません。');
            }

            $participant = EventParticipant::query()
                ->where('event_id', $event->id)->where('user_id', $user->id)->lockForUpdate()->first();

            if ($participant?->isCounted()) {
                return $participant; // 参加済みなら何もしない（ボタンの二度押し）
            }

            if ($event->remainingSeats() === 0) {
                throw new EventParticipationException('満員のため参加できません。');
            }

            $participant ??= new EventParticipant(['event_id' => $event->id, 'user_id' => $user->id]);
            $participant->fill([
                'status' => EventParticipant::STATUS_JOINED,
                'joined_at' => now(),
                'cancelled_at' => null,
            ])->save();

            return $participant;
        });
    }

    public function cancel(Event $event, User $user): void
    {
        DB::transaction(function () use ($event, $user) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);
            $participant = EventParticipant::query()
                ->where('event_id', $event->id)->where('user_id', $user->id)->lockForUpdate()->first();

            if (! $participant || $participant->status !== EventParticipant::STATUS_JOINED) {
                throw new EventParticipationException('このイベントには参加予定になっていません。');
            }
            if ($event->starts_at->isPast()) {
                throw new EventParticipationException('開始後は取り消せません。');
            }

            $participant->update(['status' => EventParticipant::STATUS_CANCELLED, 'cancelled_at' => now()]);
        });
    }

    /**
     * 管理者が参加済み／不参加を付ける。参加済みにしたとき、参加ポイントがあれば1回だけ確定で付与する。
     *
     * @return bool 状態を変えたか（取り消し・確認済みの参加者は変えない）
     */
    public function check(EventParticipant $participant, Admin $admin, bool $attended): bool
    {
        return DB::transaction(function () use ($participant, $admin, $attended) {
            $participant = EventParticipant::query()->with('event', 'user')->lockForUpdate()->findOrFail($participant->id);

            // 確認できるのは参加予定の人だけ（付けた結果は戻さない。ポイントの二重付与・取り消し漏れを防ぐ）
            if ($participant->status !== EventParticipant::STATUS_JOINED) {
                return false;
            }

            $participant->update([
                'status' => $attended ? EventParticipant::STATUS_ATTENDED : EventParticipant::STATUS_ABSENT,
                'checked_at' => now(),
                'checked_by' => $admin->id,
            ]);

            $event = $participant->event;
            if ($attended && $event->reward_points > 0 && $participant->user->status !== User::STATUS_WITHDRAWN) {
                PointTransaction::create([
                    'user_id' => $participant->user_id,
                    'type' => PointTransaction::TYPE_EVENT_REWARD,
                    'status' => PointTransaction::STATUS_CONFIRMED,
                    'points' => $event->reward_points,
                    'description' => mb_substr('イベント参加: '.$event->title, 0, 255),
                    'event_participant_id' => $participant->id,
                    'admin_id' => $admin->id,
                    'confirmed_at' => now(),
                ]);
            }

            return true;
        });
    }
}
