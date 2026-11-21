<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// イベントの参加（docs/specs/functional-spec.md 8.2）
class EventParticipationTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $attrs = []): Event
    {
        return Event::create(array_merge([
            'title' => 'みんなでポイ活',
            'starts_at' => now()->addDays(3),
            'status' => Event::STATUS_PUBLISHED,
            'openchat_url' => 'https://line.me/ti/g2/example',
            'capacity' => 2,
            'reward_points' => 100,
        ], $attrs));
    }

    private function join(User $user, Event $event)
    {
        return $this->actingAs($user)->asApiHost()->postJson("/v1/events/{$event->id}/participation");
    }

    public function test_member_joins_and_sees_the_openchat_url_only_after_joining(): void
    {
        $event = $this->event();
        $user = User::factory()->create();

        $this->asApiHost()->getJson("/v1/events/{$event->id}")
            ->assertOk()->assertJsonPath('data.openchat_url', null)->assertJsonPath('data.remaining_seats', 2)->assertJsonPath('data.accepting', true);

        $this->join($user, $event)->assertOk()
            ->assertJsonPath('data.participation', 'joined')
            ->assertJsonPath('data.openchat_url', 'https://line.me/ti/g2/example')
            ->assertJsonPath('data.remaining_seats', 1);

        // 二度押ししても1人分
        $this->join($user, $event)->assertOk();
        $this->assertSame(1, EventParticipant::count());
    }

    public function test_capacity_is_enforced_and_cancelling_frees_a_seat(): void
    {
        $event = $this->event();
        [$a, $b, $c] = User::factory()->count(3)->create();

        $this->join($a, $event)->assertOk();
        $this->join($b, $event)->assertOk();
        $this->join($c, $event)->assertStatus(422)->assertJsonPath('errors.event.0', '満員のため参加できません。');

        $this->actingAs($a)->asApiHost()->deleteJson("/v1/events/{$event->id}/participation")
            ->assertOk()->assertJsonPath('data.participation', 'cancelled')->assertJsonPath('data.openchat_url', null);
        $this->join($c, $event)->assertOk();
        $this->join($a, $event)->assertStatus(422);
    }

    public function test_cannot_join_after_start_or_when_inactive_or_logged_out(): void
    {
        $this->asApiHost()->postJson("/v1/events/{$this->event()->id}/participation")->assertUnauthorized();

        $started = $this->event(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $this->join(User::factory()->create(), $started)->assertStatus(422);

        $draft = $this->event(['status' => Event::STATUS_DRAFT]);
        $this->join(User::factory()->create(), $draft)->assertNotFound();

        $this->join(User::factory()->create(['status' => User::STATUS_SUSPENDED]), $this->event())->assertUnauthorized();
    }

    public function test_cannot_cancel_after_start(): void
    {
        $event = $this->event();
        $user = User::factory()->create();
        $this->join($user, $event)->assertOk();

        $this->travel(4)->days();
        $this->actingAs($user)->asApiHost()->deleteJson("/v1/events/{$event->id}/participation")->assertStatus(422);
    }

    public function test_past_published_event_is_shown_as_ended(): void
    {
        $past = $this->event(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $this->event(['title' => '開催予定']);

        $this->asApiHost()->getJson("/v1/events/{$past->id}")->assertJsonPath('data.status', 'ended')->assertJsonPath('data.accepting', false);
        $this->asApiHost()->getJson('/v1/events?upcoming=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', '開催予定');
        $this->asApiHost()->getJson('/v1/events')->assertJsonCount(2, 'data');
    }

    public function test_admin_marks_attendance_and_reward_is_granted_once(): void
    {
        $admin = Admin::factory()->create();
        $event = $this->event();
        [$a, $b] = User::factory()->count(2)->create();
        $this->join($a, $event);
        $this->join($b, $event);
        $pa = EventParticipant::firstWhere('user_id', $a->id);
        $pb = EventParticipant::firstWhere('user_id', $b->id);

        $this->actingAs($admin, 'admin')->asAdminHost()
            ->post("/events/{$event->id}/participants/check", ['action' => 'attended', 'ids' => [$pa->id]])->assertRedirect();
        $this->actingAs($admin, 'admin')->asAdminHost()
            ->post("/events/{$event->id}/participants/check", ['action' => 'absent', 'ids' => [$pb->id]])->assertRedirect();
        // もう一度押しても二重に付与しない・不参加を参加済みに変えない
        $this->actingAs($admin, 'admin')->asAdminHost()
            ->post("/events/{$event->id}/participants/check", ['action' => 'attended', 'ids' => [$pa->id, $pb->id]])->assertRedirect();

        $this->assertSame('attended', $pa->fresh()->status);
        $this->assertSame('absent', $pb->fresh()->status);
        $this->assertSame(1, PointTransaction::where('type', 'event_reward')->count());
        $this->assertDatabaseHas('point_transactions', [
            'user_id' => $a->id, 'type' => 'event_reward', 'status' => 'confirmed', 'points' => 100, 'event_participant_id' => $pa->id,
        ]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'event_participant.attended', 'target_id' => $pa->id]);

        // テストではアプリが使い回されて認証の結果が残るので、いったん捨ててから会員として呼ぶ
        $this->app['auth']->forgetGuards();
        $this->actingAs($a, 'web')->asApiHost()->getJson('/v1/me/points')->assertJsonPath('data.0.type_label', 'イベント参加');
    }
}
