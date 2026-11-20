<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Campaign;
use App\Models\Conversion;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\PointTransaction;
use App\Models\PostbackLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 管理画面の案件・イベント・会員・成果通知（docs/specs/admin-and-member-spec.md）
class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::factory()->create();
    }

    private function admin(): static
    {
        return $this->actingAs($this->admin, 'admin')->asAdminHost();
    }

    private function campaign(array $attrs = []): Campaign
    {
        return Campaign::create(array_merge([
            'external_id' => 'gfr'.(Campaign::count() + 1), 'name' => 'テスト案件', 'click_url' => 'https://example.com/c',
            'external_status' => 'started', 'category' => 'アプリ',
        ], $attrs));
    }

    public function test_pages_require_admin_and_are_not_on_the_api_host(): void
    {
        $this->asAdminHost()->get('/members')->assertRedirect('/login');
        $this->actingAs($this->admin, 'admin')->asApiHost()->get('/members')->assertNotFound();

        foreach (['/', '/members', '/campaigns', '/events', '/events/create', '/postbacks', '/conversions/held'] as $path) {
            $this->admin()->get($path)->assertOk();
        }
    }

    public function test_campaign_display_settings_are_saved_and_audited(): void
    {
        $campaign = $this->campaign();

        $this->admin()->put("/campaigns/{$campaign->id}", ['is_hidden' => '1', 'sort_order' => 5])->assertRedirect();

        $campaign->refresh();
        $this->assertTrue($campaign->is_hidden);
        $this->assertFalse($campaign->is_featured);
        $this->assertSame(5, $campaign->sort_order);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'campaign.update_display', 'target_id' => $campaign->id]);
        $this->asApiHost()->getJson('/v1/campaigns')->assertJsonCount(0, 'data');
        $this->admin()->get('/campaigns?filter=hidden')->assertSee('テスト案件');
    }

    public function test_events_are_created_validated_and_only_unused_drafts_can_be_deleted(): void
    {
        $this->admin()->post('/events', [
            'title' => '新しいイベント', 'starts_at' => now()->addDays(2)->format('Y-m-d\TH:i'), 'status' => 'published',
            'reward_points' => 50, 'sort_order' => 0, 'capacity' => 10, 'openchat_url' => 'https://line.me/ti/g2/x',
        ])->assertRedirect();
        $event = Event::firstWhere('title', '新しいイベント');
        $this->assertSame(10, $event->capacity);

        $this->admin()->post('/events', [
            'title' => 'だめ', 'starts_at' => now()->format('Y-m-d\TH:i'), 'status' => 'draft', 'reward_points' => 0, 'sort_order' => 0,
            'openchat_url' => 'http://insecure.example.com', 'ends_at' => now()->subDay()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors(['openchat_url', 'ends_at']);

        $this->admin()->delete("/events/{$event->id}")->assertSessionHasErrors('event');
        $this->assertModelExists($event);

        $draft = Event::create(['title' => '下書き', 'starts_at' => now()->addDay(), 'status' => 'draft']);
        $this->admin()->delete("/events/{$draft->id}")->assertRedirect('/events');
        $this->assertModelMissing($draft);
    }

    public function test_capacity_cannot_go_below_current_participants(): void
    {
        $event = Event::create(['title' => 'E', 'starts_at' => now()->addDay(), 'status' => 'published', 'capacity' => 5]);
        foreach (User::factory()->count(3)->create() as $u) {
            EventParticipant::create(['event_id' => $event->id, 'user_id' => $u->id, 'status' => 'joined', 'joined_at' => now()]);
        }

        $this->admin()->put("/events/{$event->id}", [
            'title' => 'E', 'starts_at' => $event->starts_at->format('Y-m-d\TH:i'), 'status' => 'published',
            'reward_points' => 0, 'sort_order' => 0, 'capacity' => 2,
        ])->assertSessionHasErrors('capacity');

        $csv = $this->admin()->get("/events/{$event->id}/participants/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('参加予定', $csv);
    }

    public function test_member_search_and_detail(): void
    {
        $user = User::factory()->create(['display_name' => 'ポイ活太郎']);
        User::factory()->create(['display_name' => '別の人']);

        $this->admin()->get('/members?q=活太')->assertSee('ポイ活太郎')->assertDontSee('別の人');
        $this->admin()->get('/members?q='.strtoupper($user->uid))->assertSee('ポイ活太郎');
        $this->admin()->get("/members/{$user->id}")->assertOk()->assertSee($user->uid);
    }

    public function test_suspending_a_member_ends_their_session_and_resume_restores_it(): void
    {
        $user = User::factory()->create();

        $this->admin()->post("/members/{$user->id}/suspend", [])->assertSessionHasErrors('reason');
        $this->admin()->post("/members/{$user->id}/suspend", ['reason' => '不正の疑い'])->assertRedirect();
        $this->assertSame('suspended', $user->fresh()->status);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'member.suspend', 'target_id' => $user->id]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh(), 'web')->asApiHost()->getJson('/v1/me')->assertUnauthorized();

        $this->admin()->post("/members/{$user->id}/resume")->assertRedirect();
        $this->assertSame('active', $user->fresh()->status);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh(), 'web')->asApiHost()->getJson('/v1/me')->assertOk();
    }

    public function test_points_adjustment_cannot_make_balance_negative(): void
    {
        $user = User::factory()->create();

        $this->admin()->post("/members/{$user->id}/points", ['points' => 300, 'reason' => 'お詫び'])->assertRedirect();
        $this->admin()->post("/members/{$user->id}/points", ['points' => -500, 'reason' => '誤付与の取り消し'])->assertSessionHasErrors('member');
        $this->admin()->post("/members/{$user->id}/points", ['points' => -300, 'reason' => '誤付与の取り消し'])->assertRedirect();
        $this->admin()->post("/members/{$user->id}/points", ['points' => 0, 'reason' => 'x'])->assertSessionHasErrors('points');

        $this->assertSame(0, (int) PointTransaction::where('user_id', $user->id)->where('status', 'confirmed')->sum('points'));
        $this->assertSame(2, PointTransaction::where('type', 'adjustment')->whereNotNull('admin_id')->count());
        $this->assertSame(2, AdminAuditLog::where('action', 'points.adjust')->count());

        $withdrawn = User::factory()->withdrawn()->create();
        $this->admin()->post("/members/{$withdrawn->id}/points", ['points' => 100, 'reason' => 'x'])->assertSessionHasErrors('member');
    }

    public function test_postback_logs_hide_the_hash_and_held_conversions_can_be_released(): void
    {
        $log = PostbackLog::create(['method' => 'GET', 'params' => ['cv_id' => 'cv-9', 'hash' => 'secret-hash-value'], 'cv_id' => 'cv-9', 'result' => 'held']);
        $this->admin()->get('/postbacks?result=held')->assertSee('cv-9');
        $this->admin()->get("/postbacks/{$log->id}")->assertOk()->assertDontSee('secret-hash-value');

        $user = User::factory()->create();
        $conversion = Conversion::create([
            'cv_id' => 'cv-9', 'user_id' => $user->id, 'external_user_id' => $user->uid, 'status' => 'approved',
            'hold_reason' => 'amount_mismatch', 'held_at' => now(), 'points' => 300, 'expected_points' => 200, 'last_payload' => [],
        ]);
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'pending', 'points' => 300, 'conversion_id' => $conversion->id]);

        $this->admin()->get('/conversions/held')->assertSee('cv-9');
        $this->admin()->post("/conversions/{$conversion->id}/release")->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($conversion->fresh()->hold_reason);
        $this->assertDatabaseHas('point_transactions', ['conversion_id' => $conversion->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'conversion.release_hold', 'target_id' => $conversion->id]);

        $this->admin()->post("/conversions/{$conversion->id}/release")->assertSessionHasErrors('conversion');
    }
}
