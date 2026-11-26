<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Campaign;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// お気に入り（docs/specs/functional-spec.md 4.5）
class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(array $attrs = []): Campaign
    {
        return Campaign::create(array_merge([
            'external_id' => 'gfr'.(Campaign::count() + 1), 'name' => 'テスト案件', 'click_url' => 'https://example.com/c',
            'external_status' => 'started', 'max_points' => 500,
        ], $attrs));
    }

    public function test_favorites_require_login(): void
    {
        $campaign = $this->campaign();

        $this->asApiHost()->getJson('/v1/me/favorites')->assertUnauthorized();
        $this->asApiHost()->putJson("/v1/me/favorites/{$campaign->id}")->assertUnauthorized();
    }

    public function test_add_twice_is_not_an_error_and_remove_works(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaign();

        $this->actingAs($user)->asApiHost()->putJson("/v1/me/favorites/{$campaign->id}")->assertOk()->assertJsonPath('data.is_favorite', true);
        $this->actingAs($user)->asApiHost()->putJson("/v1/me/favorites/{$campaign->id}")->assertOk();
        $this->assertSame(1, Favorite::where('user_id', $user->id)->count());

        $this->actingAs($user)->asApiHost()->deleteJson("/v1/me/favorites/{$campaign->id}")->assertOk()->assertJsonPath('data.is_favorite', false);
        $this->actingAs($user)->asApiHost()->deleteJson("/v1/me/favorites/{$campaign->id}")->assertOk();
        $this->assertSame(0, Favorite::where('user_id', $user->id)->count());
    }

    public function test_cannot_add_unlisted_campaign(): void
    {
        $user = User::factory()->create();
        $hidden = $this->campaign(['is_hidden' => true]);

        $this->actingAs($user)->asApiHost()->putJson("/v1/me/favorites/{$hidden->id}")->assertNotFound();
    }

    public function test_limit_is_200(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < Favorite::LIMIT; $i++) {
            Favorite::create(['user_id' => $user->id, 'campaign_id' => $this->campaign()->id, 'created_at' => now()]);
        }
        $next = $this->campaign();

        $this->actingAs($user)->asApiHost()->putJson("/v1/me/favorites/{$next->id}")
            ->assertUnprocessable()->assertJsonPath('errors.favorite.0', 'お気に入りは200件までです。');

        // 登録済みの案件をもう一度登録するのは上限でもエラーにしない
        $first = Favorite::where('user_id', $user->id)->first();
        $this->actingAs($user)->asApiHost()->putJson("/v1/me/favorites/{$first->campaign_id}")->assertOk();
    }

    public function test_list_is_newest_first_and_keeps_ended_campaigns(): void
    {
        $user = User::factory()->create();
        $old = $this->campaign(['name' => '古い']);
        $ended = $this->campaign(['name' => '終了']);
        Favorite::create(['user_id' => $user->id, 'campaign_id' => $old->id, 'created_at' => now()->subDays(2)]);
        Favorite::create(['user_id' => $user->id, 'campaign_id' => $ended->id, 'created_at' => now()->subDay()]);
        $ended->update(['external_status' => 'removed']);
        // ほかの会員のお気に入りは出ない
        Favorite::create(['user_id' => User::factory()->create()->id, 'campaign_id' => $old->id, 'created_at' => now()]);

        $this->actingAs($user)->asApiHost()->getJson('/v1/me/favorites')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', '終了')
            ->assertJsonPath('data.0.listed', false)
            ->assertJsonPath('data.1.name', '古い')
            ->assertJsonPath('data.1.listed', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 200);
    }

    public function test_campaign_api_tells_whether_it_is_a_favorite(): void
    {
        $user = User::factory()->create();
        $liked = $this->campaign(['name' => '登録済み', 'sort_order' => 1]);
        $other = $this->campaign(['name' => '未登録', 'sort_order' => 2]);
        Favorite::create(['user_id' => $user->id, 'campaign_id' => $liked->id, 'created_at' => now()]);

        $this->actingAs($user)->asApiHost()->getJson('/v1/campaigns')
            ->assertJsonPath('data.0.is_favorite', true)
            ->assertJsonPath('data.1.is_favorite', false);
        $this->actingAs($user)->asApiHost()->getJson("/v1/campaigns/{$liked->id}")->assertJsonPath('data.is_favorite', true);
        $this->actingAs($user)->asApiHost()->getJson("/v1/campaigns/{$other->id}")->assertJsonPath('data.is_favorite', false);
    }

    public function test_campaign_api_for_guests_says_not_favorite(): void
    {
        $campaign = $this->campaign();

        $this->asApiHost()->getJson('/v1/campaigns')->assertJsonPath('data.0.is_favorite', false);
        $this->asApiHost()->getJson("/v1/campaigns/{$campaign->id}")->assertJsonPath('data.is_favorite', false);
    }

    public function test_suspended_member_cannot_change_favorites(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_SUSPENDED]);
        $campaign = $this->campaign();

        $this->actingAs($user)->asApiHost()->putJson("/v1/me/favorites/{$campaign->id}")->assertStatus(401);
        $this->assertSame(0, Favorite::count());
    }

    public function test_admin_campaign_list_shows_favorite_count(): void
    {
        $campaign = $this->campaign(['name' => '人気の案件']);
        foreach (User::factory()->count(3)->create() as $user) {
            Favorite::create(['user_id' => $user->id, 'campaign_id' => $campaign->id, 'created_at' => now()]);
        }

        $this->actingAs(Admin::factory()->create(), 'admin')->asAdminHost()->get('/campaigns')
            ->assertOk()->assertSee('お気に入り')->assertSeeInOrder(['人気の案件', '3']);
    }
}
