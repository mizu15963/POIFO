<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Campaign;
use App\Models\CampaignTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// トップの案件メニュー（docs/specs/functional-spec.md 4.2・4.3）: おすすめ・はじめて向け・高還元・ゲーム案件特集・みんなで挑戦中
class CampaignMenuTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(string $id, array $attrs = []): Campaign
    {
        return Campaign::create($attrs + ['external_id' => $id, 'name' => "案件{$id}", 'click_url' => 'https://example.com/c', 'external_status' => 'started']);
    }

    public function test_tag_filter_returns_only_tagged_listed_campaigns(): void
    {
        $game = $this->campaign('g1');
        $game->syncTags(['game', 'beginner']);
        $hidden = $this->campaign('g2', ['is_hidden' => true]);
        $hidden->syncTags(['game']);
        $this->campaign('x1');

        $this->asApiHost()->getJson('/v1/campaigns?tag=game')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $game->id);
        $this->asApiHost()->getJson('/v1/campaigns?tag=beginner')->assertOk()->assertJsonCount(1, 'data');
        $this->asApiHost()->getJson('/v1/campaigns?tag=together')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unknown_tag_or_sort_is_rejected(): void
    {
        $this->asApiHost()->getJson('/v1/campaigns?tag=unknown')->assertUnprocessable()->assertJsonValidationErrors('tag');
        $this->asApiHost()->getJson('/v1/campaigns?sort=name')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_sort_by_points_puts_high_points_first_and_rate_campaigns_last(): void
    {
        $rate = $this->campaign('r', ['max_points' => null, 'is_featured' => true]);
        $low = $this->campaign('l', ['max_points' => 100, 'is_featured' => true]);
        $high = $this->campaign('h', ['max_points' => 8000]);
        $mid = $this->campaign('m', ['max_points' => 1200]);

        $ids = collect($this->asApiHost()->getJson('/v1/campaigns?sort=points')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$high->id, $mid->id, $low->id, $rate->id], $ids);

        // 指定しなければ今までどおりおすすめが先
        $ids = collect($this->asApiHost()->getJson('/v1/campaigns')->json('data'))->pluck('id')->take(2)->all();
        $this->assertEqualsCanonicalizing([$rate->id, $low->id], $ids);
    }

    public function test_admin_can_set_tags_and_it_is_logged(): void
    {
        $admin = Admin::factory()->create();
        $c = $this->campaign('a1');
        $c->syncTags(['together']);

        $this->actingAs($admin, 'admin')->asAdminHost()->get('/campaigns')->assertOk()->assertSee('ゲーム案件特集');

        $this->actingAs($admin, 'admin')->asAdminHost()->from('/campaigns')
            ->put("/campaigns/{$c->id}", ['sort_order' => 0, 'tags' => ['game', 'beginner']])
            ->assertRedirect('/campaigns');

        $this->assertEqualsCanonicalizing(['beginner', 'game'], CampaignTag::where('campaign_id', $c->id)->pluck('tag')->all());
        $log = AdminAuditLog::where('action', 'campaign.update_display')->latest('id')->first();
        $this->assertSame(['together'], $log->payload['before']['tags']);
        $this->assertSame(['beginner', 'game'], $log->payload['after']['tags']);

        // 全部外す
        $this->actingAs($admin, 'admin')->asAdminHost()->put("/campaigns/{$c->id}", ['sort_order' => 0])->assertRedirect();
        $this->assertSame(0, CampaignTag::where('campaign_id', $c->id)->count());
    }

    public function test_admin_rejects_unknown_tag(): void
    {
        $admin = Admin::factory()->create();
        $c = $this->campaign('a2');
        $this->actingAs($admin, 'admin')->asAdminHost()->put("/campaigns/{$c->id}", ['sort_order' => 0, 'tags' => ['vip']])
            ->assertSessionHasErrors('tags.0');
        $this->assertSame(0, CampaignTag::count());
    }

    public function test_dummies_have_tags_and_delete_removes_them(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->asAdminHost()->post('/campaigns/dummies');
        foreach (array_keys(Campaign::TAGS) as $tag) {
            $this->assertNotEmpty($this->asApiHost()->getJson("/v1/campaigns?tag={$tag}")->json('data'), $tag);
        }

        $this->actingAs($admin, 'admin')->asAdminHost()->delete('/campaigns/dummies');
        $this->assertSame(0, CampaignTag::count());
    }
}
