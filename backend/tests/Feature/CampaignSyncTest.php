<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_upserts_campaigns_and_keeps_admin_settings(): void
    {
        config(['poifo.gf_rewards.ad_channel_id' => 'ch1']);
        $existing = Campaign::create(['external_id' => 'gfr1', 'name' => '旧名', 'click_url' => 'https://old', 'is_featured' => true, 'sort_order' => 5]);
        Campaign::create(['external_id' => 'gone', 'name' => '終了', 'click_url' => 'https://x', 'external_status' => 'started']);

        Http::fake(['api.gf-rewards.com/*' => Http::response(['code' => 200, 'data' => [[
            'campaign_id' => 'gfr1',
            'name' => '新名',
            'click_url' => 'https://measurement.example.com/click/1',
            'status' => 'started',
            'platform' => 'web',
            'start_time' => '2026-01-01T00:00:00.000+09:00',
            'end_time' => null,
            'conversion_actions' => [
                ['conversion_action_id' => 'a1', 'incentive' => ['amount' => 100.0]],
                ['conversion_action_id' => 'a2', 'incentive' => ['amount' => 350.5]],
            ],
        ]]])]);

        $this->artisan('gf:sync-campaigns')->assertSuccessful();

        Http::assertSent(fn ($r) => $r->hasHeader('api-key', 'test') && str_contains($r->url(), 'ad_channel_id=ch1'));
        $existing->refresh();
        $this->assertSame('新名', $existing->name);
        $this->assertSame(350, $existing->max_points);
        $this->assertTrue($existing->is_featured);
        $this->assertSame(5, $existing->sort_order);
        $this->assertSame('removed', Campaign::firstWhere('external_id', 'gone')->external_status);
    }
}
