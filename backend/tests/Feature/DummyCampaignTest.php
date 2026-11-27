<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\Favorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 動作確認用のダミー案件（docs/specs/functional-spec.md 4.6）
class DummyCampaignTest extends TestCase
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

    public function test_buttons_require_admin_and_are_not_on_the_api_host(): void
    {
        $this->asAdminHost()->post('/campaigns/dummies')->assertRedirect('/login');
        $this->asAdminHost()->delete('/campaigns/dummies')->assertRedirect('/login');
        $this->actingAs($this->admin, 'admin')->asApiHost()->post('/campaigns/dummies')->assertNotFound();
        $this->assertSame(0, Campaign::count());
    }

    public function test_insert_adds_ten_dummies_shown_on_the_member_api(): void
    {
        $this->admin()->get('/campaigns')->assertOk()->assertSee('ダミー案件を入れる');

        $this->admin()->from('/campaigns')->post('/campaigns/dummies')->assertRedirect('/campaigns')->assertSessionHas('status');

        $this->assertSame(10, Campaign::where('provider', 'dummy')->count());
        $this->assertSame(0, Campaign::where('provider', 'dummy')->where('name', 'not like', '【ダミー】%')->count());
        $this->assertSame(0, Campaign::where('provider', 'dummy')->where('click_url', '!=', 'https://example.com/')->count());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'campaign.dummy_insert', 'admin_id' => $this->admin->id]);

        // 1件は掲載期間が終わっているので、会員サイトには9件
        $this->asApiHost()->getJson('/v1/campaigns?per_page=50')->assertOk()->assertJsonCount(9, 'data');
        $this->assertSame(1, Campaign::where('provider', 'dummy')->where('ends_at', '<', now())->count());

        // 成果地点が複数ある案件・定率の案件も詳細に出る
        $multi = Campaign::where('external_id', 'dummy-02')->first();
        $this->asApiHost()->getJson("/v1/campaigns/{$multi->id}")->assertOk()->assertJsonCount(3, 'data.actions')
            ->assertJsonPath('data.points', 1200);
        $rate = Campaign::where('revenue_type', 'fixed_rate')->first();
        $this->asApiHost()->getJson("/v1/campaigns/{$rate->id}")->assertOk()->assertJsonPath('data.points', null);
    }

    public function test_insert_twice_does_not_add_more(): void
    {
        $this->admin()->post('/campaigns/dummies');
        $this->admin()->post('/campaigns/dummies')->assertSessionHasErrors('dummy');

        $this->assertSame(10, Campaign::count());
        $this->assertSame(1, AdminAuditLog::where('action', 'campaign.dummy_insert')->count());
    }

    public function test_delete_removes_only_dummies_with_their_favorites_and_clicks(): void
    {
        $real = Campaign::create(['external_id' => 'gfr1', 'name' => '本物の案件', 'click_url' => 'https://example.com/c', 'external_status' => 'started']);
        $this->admin()->post('/campaigns/dummies');
        $dummy = Campaign::where('provider', 'dummy')->first();
        $user = User::factory()->create();
        Favorite::create(['user_id' => $user->id, 'campaign_id' => $dummy->id]);
        Favorite::create(['user_id' => $user->id, 'campaign_id' => $real->id]);
        CampaignClick::create(['user_id' => $user->id, 'campaign_id' => $dummy->id]);
        CampaignClick::create(['user_id' => $user->id, 'campaign_id' => $real->id]);

        $this->admin()->from('/campaigns')->delete('/campaigns/dummies')->assertRedirect('/campaigns')->assertSessionHas('status');

        $this->assertSame([$real->id], Campaign::pluck('id')->all());
        $this->assertSame([$real->id], Favorite::pluck('campaign_id')->all());
        $this->assertSame([$real->id], CampaignClick::pluck('campaign_id')->all());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'campaign.dummy_delete', 'admin_id' => $this->admin->id]);

        // 消したあとはまた入れられる
        $this->admin()->post('/campaigns/dummies')->assertSessionHasNoErrors();
        $this->assertSame(10, Campaign::where('provider', 'dummy')->count());
    }

    public function test_click_on_dummy_goes_to_example_com(): void
    {
        $this->admin()->post('/campaigns/dummies');
        $dummy = Campaign::where('external_id', 'dummy-01')->first();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->asApiHost()->get("/go/{$dummy->id}");

        $this->assertStringStartsWith('https://example.com/?user_id=', $response->headers->get('Location'));
    }
}
