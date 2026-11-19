<?php

namespace Tests\Feature;

use App\Models\Conversion;
use App\Models\PointTransaction;
use App\Models\PostbackLog;
use App\Models\User;
use App\Services\GfRewards\PostbackHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostbackTest extends TestCase
{
    use RefreshDatabase;

    private function params(User $user, array $overrides = []): array
    {
        $params = array_merge([
            'user_id' => $user->uid,
            'cv_id' => 'cv-001',
            'cv_action_id' => 'gfr100',
            'cv_action_name' => 'アプリインストール',
            'campaign_id' => 'gfr1',
            'campaign_name' => 'テスト案件',
            'cv_datetime' => '2026-11-20T10:00:00+09:00',
            'cv_authenticated_datetime' => '',
            'cv_status' => 0,
            'incentive_amount' => 300,
            'net_cost' => 400,
        ], $overrides);
        $params['hash'] = PostbackHandler::hash('test', $params);

        return $params;
    }

    private function sendGet(array $params)
    {
        return $this->asApiHost()->get('/postback/gf-rewards?'.http_build_query($params));
    }

    public function test_pending_then_approved_conversion_confirms_points(): void
    {
        $user = User::factory()->create();

        $this->sendGet($this->params($user))->assertOk()->assertContent('0');
        $this->assertDatabaseHas('point_transactions', ['user_id' => $user->id, 'status' => 'pending', 'points' => 300]);

        $this->sendGet($this->params($user, ['cv_status' => 1, 'cv_authenticated_datetime' => '2026-11-21T10:00:00+09:00']))
            ->assertOk()->assertContent('1');

        $this->assertSame(1, PointTransaction::count());
        $this->assertDatabaseHas('point_transactions', ['user_id' => $user->id, 'status' => 'confirmed', 'points' => 300]);
        $this->assertSame(Conversion::STATUS_APPROVED, Conversion::first()->status);
    }

    public function test_post_json_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->asApiHost()->postJson('/postback/gf-rewards', $this->params($user, ['cv_status' => 1]))
            ->assertOk()->assertContent('1');
    }

    public function test_retried_approval_does_not_grant_twice(): void
    {
        $user = User::factory()->create();
        $params = $this->params($user, ['cv_status' => 1]);

        $this->sendGet($params)->assertContent('1');
        $this->sendGet($params)->assertContent('1');

        $this->assertSame(1, PointTransaction::count());
        $this->assertSame('duplicate', PostbackLog::latest('id')->first()->result);
    }

    public function test_status_cannot_change_after_it_is_final(): void
    {
        $user = User::factory()->create();

        $this->sendGet($this->params($user, ['cv_status' => 2]))->assertContent('0');
        $this->sendGet($this->params($user, ['cv_status' => 1]))->assertContent('0');

        $this->assertDatabaseHas('point_transactions', ['status' => 'rejected']);
        $this->assertDatabaseMissing('point_transactions', ['status' => 'confirmed']);
    }

    public function test_invalid_hash_is_logged_and_not_granted(): void
    {
        $user = User::factory()->create();
        $params = $this->params($user, ['cv_status' => 1]);
        $params['hash'] = str_repeat('0', 64);

        $this->sendGet($params)->assertOk()->assertContent('0');

        $this->assertSame(0, Conversion::count());
        $this->assertDatabaseHas('postback_logs', ['result' => 'invalid_hash', 'hash_valid' => false]);
    }

    public function test_withdrawn_user_gets_response_2(): void
    {
        $user = User::factory()->withdrawn()->create();

        $this->sendGet($this->params($user, ['cv_status' => 1]))->assertContent('2');
        $this->assertSame(0, PointTransaction::count());
    }

    public function test_unknown_user_is_recorded_but_not_granted(): void
    {
        $params = ['user_id' => 'MEDIA_UNAVAILABLE', 'cv_id' => 'cv-x', 'cv_action_id' => 'a', 'cv_datetime' => 'd', 'cv_status' => 1, 'incentive_amount' => 10];
        $params['hash'] = PostbackHandler::hash('test', $params);

        $this->sendGet($params)->assertContent('0');
        $this->assertDatabaseHas('conversions', ['cv_id' => 'cv-x', 'user_id' => null]);
    }

    public function test_postback_is_not_reachable_from_admin_host(): void
    {
        $user = User::factory()->create();
        $this->asAdminHost()->get('/postback/gf-rewards?'.http_build_query($this->params($user)))->assertNotFound();
    }
}
