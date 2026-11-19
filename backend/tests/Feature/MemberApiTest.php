<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Event;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberApiTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(array $attrs = []): Campaign
    {
        return Campaign::create(array_merge([
            'external_id' => 'gfr'.fake()->unique()->numberBetween(1, 99999),
            'name' => 'テスト案件',
            'click_url' => 'https://measurement.example.com/click/abc',
            'external_status' => 'started',
            'max_points' => 500,
        ], $attrs));
    }

    public function test_me_requires_login(): void
    {
        $this->asApiHost()->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_me_returns_available_and_pending_points(): void
    {
        $user = User::factory()->create();
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'confirmed', 'points' => 300]);
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'pending', 'points' => 200]);
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'rejected', 'points' => 999]);

        $this->actingAs($user)->asApiHost()->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.points.available', 300)
            ->assertJsonPath('data.points.pending', 200);
    }

    public function test_campaign_list_shows_only_listed_campaigns(): void
    {
        $this->campaign(['name' => '配信中']);
        $this->campaign(['name' => '停止中', 'external_status' => 'paused']);
        $this->campaign(['name' => '非表示', 'is_hidden' => true]);

        $this->asApiHost()->getJson('/v1/campaigns')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '配信中')
            ->assertJsonMissingPath('data.0.click_url');
    }

    public function test_go_redirects_to_tracking_link_with_member_uid(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaign();

        $this->actingAs($user)->asApiHost()->get("/go/{$campaign->id}")
            ->assertRedirect('https://measurement.example.com/click/abc?user_id='.$user->uid);

        $this->assertDatabaseHas('campaign_clicks', ['user_id' => $user->id, 'campaign_id' => $campaign->id]);
    }

    public function test_go_sends_guests_to_front_login(): void
    {
        $campaign = $this->campaign();

        $this->asApiHost()->get("/go/{$campaign->id}")->assertRedirect('https://poifo.test/login/');
    }

    public function test_events_hide_drafts(): void
    {
        Event::create(['title' => '公開', 'starts_at' => now()->addDay(), 'status' => 'published', 'openchat_url' => 'https://line.me/ti/g2/x']);
        $draft = Event::create(['title' => '下書き', 'starts_at' => now()->addDay(), 'status' => 'draft']);

        $this->asApiHost()->getJson('/v1/events?upcoming=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', '公開');
        $this->asApiHost()->getJson("/v1/events/{$draft->id}")->assertNotFound();
    }
}
