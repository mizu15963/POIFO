<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 案件の利用履歴（マイページの通帳のタブ。docs/specs/functional-spec.md 3.3）と、登録情報に使う /v1/me の項目
class CampaignClickHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(array $attrs = []): Campaign
    {
        return Campaign::create(array_merge([
            'external_id' => 'gfr'.(Campaign::count() + 1), 'name' => 'テスト案件', 'click_url' => 'https://example.com/c',
            'external_status' => 'started',
        ], $attrs));
    }

    public function test_requires_login(): void
    {
        $this->asApiHost()->getJson('/v1/me/clicks')->assertUnauthorized();
    }

    public function test_shows_own_clicks_in_the_last_90_days_newest_first(): void
    {
        $user = User::factory()->create();
        $a = $this->campaign(['name' => '案件A']);
        $b = $this->campaign(['name' => '案件B', 'external_status' => 'removed']);
        CampaignClick::create(['user_id' => $user->id, 'campaign_id' => $a->id, 'created_at' => now()->subDays(91)]);
        CampaignClick::create(['user_id' => $user->id, 'campaign_id' => $a->id, 'created_at' => now()->subDays(3)]);
        CampaignClick::create(['user_id' => $user->id, 'campaign_id' => $b->id, 'created_at' => now()->subDay()]);
        CampaignClick::create(['user_id' => User::factory()->create()->id, 'campaign_id' => $a->id, 'created_at' => now()]);

        $this->actingAs($user)->asApiHost()->getJson('/v1/me/clicks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.campaign_name', '案件B')
            ->assertJsonPath('data.0.listed', false)
            ->assertJsonPath('data.1.campaign_name', '案件A')
            ->assertJsonPath('data.1.listed', true)
            ->assertJsonMissingPath('data.0.ip_address')
            ->assertJsonMissingPath('data.0.user_agent');
    }

    public function test_me_returns_member_id_and_registration_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->asApiHost()->getJson('/v1/me')
            ->assertJsonPath('data.uid', $user->uid)
            ->assertJsonPath('data.registered_at', $user->created_at->toIso8601String());
    }
}
