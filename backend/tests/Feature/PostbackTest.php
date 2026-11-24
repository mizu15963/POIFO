<?php

namespace Tests\Feature;

use App\Mail\AdminAlert;
use App\Models\Campaign;
use App\Models\Conversion;
use App\Models\PointTransaction;
use App\Models\PostbackLog;
use App\Models\User;
use App\Services\GfRewards\PostbackHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PostbackTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/postback/test-path-token/gf-rewards';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // 案件APIで取得済みの案件（定額 300 ポイントの成果地点 gfr100、定率・上限 1000 の成果地点 gfr200）
        Campaign::create([
            'external_id' => 'gfr1',
            'name' => 'テスト案件',
            'click_url' => 'https://example.com/click',
            'conversion_actions' => [
                ['conversion_action_id' => 'gfr100', 'incentive' => ['amount' => 300]],
                ['conversion_action_id' => 'gfr200', 'incentive' => ['rate' => 0.5, 'amount' => null], 'incentive_cost_limit' => 1000],
            ],
        ]);
    }

    /** ASP の送信元（CloudFront Function が x-poifo-viewer-ip に入れて渡す） */
    private function fromAsp(string $ip = '192.0.2.10'): static
    {
        return $this->asApiHost()->withHeader('x-poifo-viewer-ip', $ip);
    }

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
        return $this->fromAsp()->get(self::PATH.'?'.http_build_query($params));
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

    public function test_postback_is_accepted_during_maintenance(): void
    {
        $user = User::factory()->create();
        $this->app->maintenanceMode()->activate([]);

        try {
            $this->sendGet($this->params($user))->assertOk()->assertContent('0');
            // 会員向け API はメンテナンス中
            $this->asApiHost()->getJson('/v1/campaigns')->assertStatus(503);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }

        $this->assertDatabaseHas('point_transactions', ['user_id' => $user->id, 'status' => 'pending', 'points' => 300]);
    }

    public function test_post_json_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->fromAsp()->postJson(self::PATH, $this->params($user, ['cv_status' => 1]))
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
        $this->asAdminHost()->withHeader('x-poifo-viewer-ip', '192.0.2.10')
            ->get(self::PATH.'?'.http_build_query($this->params($user)))->assertNotFound();
    }

    public function test_wrong_path_token_is_rejected_logged_and_alerted(): void
    {
        $user = User::factory()->create();

        $this->fromAsp()->get('/postback/wrong-token/gf-rewards?'.http_build_query($this->params($user, ['cv_status' => 1])))
            ->assertNotFound();
        $this->fromAsp()->get('/postback/gf-rewards?'.http_build_query($this->params($user, ['cv_status' => 1])))
            ->assertNotFound();

        $this->assertSame(0, Conversion::count());
        $this->assertDatabaseHas('postback_logs', ['result' => 'invalid_path', 'ip_address' => '192.0.2.10']);
        Mail::assertQueued(AdminAlert::class, fn ($mail) => $mail->hasTo('alerts@poifo.test'));
    }

    public function test_source_ip_outside_the_allow_list_is_rejected(): void
    {
        $user = User::factory()->create();
        $query = '?'.http_build_query($this->params($user, ['cv_status' => 1]));

        $this->fromAsp('203.0.113.5')->get(self::PATH.$query)->assertNotFound();
        // X-Forwarded-For は偽装できるので見ない
        $this->asApiHost()->withHeader('X-Forwarded-For', '192.0.2.10')->get(self::PATH.$query)->assertNotFound();

        $this->assertSame(0, Conversion::count());
        $this->assertSame(2, PostbackLog::where('result', 'ip_denied')->count());
        Mail::assertQueued(AdminAlert::class);
    }

    public function test_empty_allow_list_rejects_everything_in_production(): void
    {
        $user = User::factory()->create();
        config(['poifo.postback.allowed_ips' => []]);
        $this->app['env'] = 'production';

        $this->fromAsp()->get(self::PATH.'?'.http_build_query($this->params($user, ['cv_status' => 1])))
            ->assertNotFound();

        $this->assertSame(0, Conversion::count());
        $this->assertDatabaseHas('postback_logs', ['result' => 'ip_denied']);
    }

    public function test_source_ip_in_an_allowed_range_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->fromAsp('198.51.100.77')->get(self::PATH.'?'.http_build_query($this->params($user, ['cv_status' => 1])))
            ->assertOk()->assertContent('1');
    }

    public function test_amount_different_from_the_campaign_is_held_without_granting(): void
    {
        $user = User::factory()->create();

        $this->sendGet($this->params($user, ['incentive_amount' => 3000]))->assertContent('0');
        $this->sendGet($this->params($user, ['incentive_amount' => 3000, 'cv_status' => 1]))->assertContent('0');

        $conversion = Conversion::first();
        $this->assertSame('amount_mismatch', $conversion->hold_reason);
        $this->assertSame(300, $conversion->expected_points);
        $this->assertNotNull($conversion->held_at);
        $this->assertDatabaseHas('point_transactions', ['user_id' => $user->id, 'status' => 'pending', 'points' => 3000]);
        $this->assertDatabaseMissing('point_transactions', ['status' => 'confirmed']);
        $this->assertSame('held', PostbackLog::latest('id')->first()->result);
        Mail::assertQueued(AdminAlert::class, fn ($mail) => str_contains($mail->title, '保留'));

        // 確定済みへの再通知でも付与扱いにしない
        $this->sendGet($this->params($user, ['incentive_amount' => 3000, 'cv_status' => 1]))->assertContent('0');
    }

    public function test_hold_is_not_released_by_a_later_matching_notification(): void
    {
        $user = User::factory()->create();

        $this->sendGet($this->params($user, ['incentive_amount' => 3000]))->assertContent('0');
        $this->sendGet($this->params($user, ['cv_status' => 1]))->assertContent('0');

        $this->assertSame('amount_mismatch', Conversion::first()->hold_reason);
        $this->assertDatabaseMissing('point_transactions', ['status' => 'confirmed']);
    }

    public function test_unknown_campaign_or_action_is_held(): void
    {
        $user = User::factory()->create();

        $this->sendGet($this->params($user, ['cv_status' => 1, 'campaign_id' => 'gfr999']))->assertContent('0');
        $this->sendGet($this->params($user, ['cv_status' => 1, 'cv_id' => 'cv-002', 'cv_action_id' => 'gfr999']))->assertContent('0');

        $this->assertSame(['unknown_campaign', 'unknown_action'], Conversion::orderBy('id')->pluck('hold_reason')->all());
        $this->assertDatabaseMissing('point_transactions', ['status' => 'confirmed']);
    }

    public function test_rate_based_action_is_checked_against_its_limit(): void
    {
        $user = User::factory()->create();

        $this->sendGet($this->params($user, ['cv_status' => 1, 'cv_action_id' => 'gfr200', 'incentive_amount' => 800]))
            ->assertContent('1');
        $this->sendGet($this->params($user, ['cv_status' => 1, 'cv_id' => 'cv-002', 'cv_action_id' => 'gfr200', 'incentive_amount' => 1500]))
            ->assertContent('0');

        $this->assertSame('amount_over_limit', Conversion::firstWhere('cv_id', 'cv-002')->hold_reason);
    }

    public function test_rejection_of_a_mismatched_conversion_is_not_held(): void
    {
        $user = User::factory()->create();

        $this->sendGet($this->params($user, ['cv_status' => 2, 'incentive_amount' => 3000]))->assertContent('0');

        $this->assertNull(Conversion::first()->hold_reason);
        $this->assertDatabaseHas('point_transactions', ['status' => 'rejected']);
    }

    public function test_alerts_of_the_same_kind_are_grouped(): void
    {
        $user = User::factory()->create();

        foreach (['cv-a', 'cv-b', 'cv-c'] as $cvId) {
            $params = $this->params($user, ['cv_id' => $cvId]);
            $params['hash'] = str_repeat('0', 64);
            $this->sendGet($params)->assertContent('0');
        }

        Mail::assertQueuedCount(1);
    }

    public function test_burst_of_postbacks_is_alerted(): void
    {
        config(['poifo.postback.burst_per_minute' => 2]);
        $user = User::factory()->create();

        foreach (['cv-a', 'cv-b', 'cv-c'] as $cvId) {
            $this->sendGet($this->params($user, ['cv_id' => $cvId]))->assertContent('0');
        }

        Mail::assertQueued(AdminAlert::class, fn ($mail) => str_contains($mail->title, '大量'));
    }
}
