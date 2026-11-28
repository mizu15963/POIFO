<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\BankAccount;
use App\Models\Campaign;
use App\Models\Conversion;
use App\Models\PointTransaction;
use App\Models\ReferralRank;
use App\Models\ReferralReward;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Services\GfRewards\PostbackHandler;
use App\Services\Referrals\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// 友達紹介（docs/specs/functional-spec.md 15章）
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private int $cv = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Campaign::create([
            'external_id' => 'gfr1',
            'name' => 'テスト案件',
            'click_url' => 'https://example.com/click',
            'conversion_actions' => [['conversion_action_id' => 'gfr100', 'incentive' => ['amount' => null], 'incentive_cost_limit' => 100000]],
        ]);
    }

    private function service(): ReferralService
    {
        return app(ReferralService::class);
    }

    /** @return array{0: User, 1: User} [紹介した人, 紹介した友達] */
    private function pair(): array
    {
        $referrer = User::factory()->create();
        $friend = User::factory()->create(['referred_by_user_id' => $referrer->id]);

        return [$referrer, $friend];
    }

    /** 成果通知（status: 0 未承認 / 1 承認 / 2 否認） */
    private function postback(User $user, int $points, int $status, ?string $cvId = null): string
    {
        $cvId ??= 'cv-'.(++$this->cv);
        app(PostbackHandler::class)->handle([
            'user_id' => $user->uid, 'cv_id' => $cvId, 'cv_action_id' => 'gfr100', 'campaign_id' => 'gfr1',
            'campaign_name' => 'テスト案件', 'cv_datetime' => '2026-11-20T10:00:00+09:00', 'cv_status' => $status, 'incentive_amount' => $points,
        ]);

        return $cvId;
    }

    private function referralRows(User $referrer)
    {
        return PointTransaction::where('user_id', $referrer->id)->where('type', PointTransaction::TYPE_REFERRAL)->get();
    }

    public function test_code_is_issued_once_with_unambiguous_characters(): void
    {
        $user = User::factory()->create();
        $code = $this->service()->codeFor($user);

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);
        $this->assertSame($code, $this->service()->codeFor($user->fresh()));
        $this->assertTrue(ReferralService::isValidCodeFormat($code));
    }

    public function test_line_login_attaches_referrer_only_for_new_members(): void
    {
        config(['poifo.line.channel_id' => '1', 'poifo.line.channel_secret' => 's', 'poifo.line.redirect_uri' => 'https://api.poifo.test/auth/line/callback']);
        $referrer = User::factory()->create();
        $code = $this->service()->codeFor($referrer);

        // 入口で受け取ったコードはセッションに入る（小文字でも受け付ける）
        $this->asApiHost()->get('/auth/line/redirect?ref='.strtolower($code))->assertRedirect();
        $this->assertSame($code, session('line_login.ref'));

        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'x']),
            'api.line.me/oauth2/v2.1/verify' => Http::sequence()
                ->push(['sub' => 'Unew', 'name' => '新しい人'])
                ->push(['sub' => 'Uold', 'name' => 'すでに会員']),
        ]);

        $this->asApiHost()->withSession(['line_login' => ['state' => 'st', 'nonce' => 'nn', 'ref' => $code]])
            ->get('/auth/line/callback?code=c&state=st')->assertRedirect('https://poifo.test/auth/complete/?new=1');
        $this->assertSame($referrer->id, User::firstWhere('line_user_id', 'Unew')->referred_by_user_id);

        // すでに会員の人には後から付けない
        User::factory()->create(['line_user_id' => 'Uold']);
        $this->asApiHost()->withSession(['line_login' => ['state' => 'st', 'nonce' => 'nn', 'ref' => $code]])
            ->get('/auth/line/callback?code=c&state=st')->assertRedirect('https://poifo.test/auth/complete/');
        $this->assertNull(User::firstWhere('line_user_id', 'Uold')->referred_by_user_id);
    }

    public function test_invalid_codes_are_not_attached(): void
    {
        $suspended = User::factory()->create(['status' => User::STATUS_SUSPENDED]);
        $code = $this->service()->codeFor($suspended);
        $new = User::factory()->create();

        $this->assertFalse($this->service()->attachOnRegistration($new, $code));
        $this->assertFalse($this->service()->attachOnRegistration($new, 'ZZZZZZZZ'));
        $own = $this->service()->codeFor($new);
        $this->assertFalse($this->service()->attachOnRegistration($new, $own));
        $this->assertNull($new->fresh()->referred_by_user_id);
    }

    public function test_reward_follows_conversion_status_and_rank_rate(): void
    {
        [$referrer, $friend] = $this->pair();

        // 判定中 → 判定中の紹介報酬（レギュラー5%、1P未満切り捨て）
        $cv = $this->postback($friend, 1010, 0);
        $row = $this->referralRows($referrer)->sole();
        $this->assertSame(['pending', 50], [$row->status, $row->points]);

        // 確定 → 確定。重複の通知でも二重に付けない
        $this->postback($friend, 1010, 1, $cv);
        $this->postback($friend, 1010, 1, $cv);
        $row = $this->referralRows($referrer)->sole();
        $this->assertSame(['confirmed', 50], [$row->status, $row->points]);
        $this->assertSame(1, ReferralReward::where('type', 'ongoing')->count());

        // 否認の成果からは付かない
        $cv2 = $this->postback($friend, 500, 0);
        $this->postback($friend, 500, 2, $cv2);
        $this->assertSame(['confirmed', 'rejected'], $this->referralRows($referrer)->pluck('status')->all());

        // 紹介した人の残高に入り、友達の残高には入らない
        $this->assertSame(50 + 100, PointTransaction::where('user_id', $referrer->id)->where('status', 'confirmed')->sum('points'));
        $this->assertSame(1010, (int) PointTransaction::where('user_id', $friend->id)->where('status', 'confirmed')->sum('points'));
    }

    public function test_rate_is_decided_at_confirmation_and_not_changed_afterwards(): void
    {
        [$referrer, $friend] = $this->pair();
        $cv = $this->postback($friend, 1000, 0);
        ReferralRank::where('code', 'regular')->update(['rate' => 7]);
        $this->postback($friend, 1000, 1, $cv);
        $this->assertSame(70, $this->referralRows($referrer)->sole()->points);

        ReferralRank::where('code', 'regular')->update(['rate' => 9]);
        $this->assertSame(70, $this->referralRows($referrer)->sole()->fresh()->points);
    }

    public function test_excluded_campaign_and_rate_cap(): void
    {
        [$referrer, $friend] = $this->pair();
        Campaign::where('external_id', 'gfr1')->update(['referral_rate_cap' => 2]);
        $this->postback($friend, 1000, 1);
        $this->assertSame(20, $this->referralRows($referrer)->sole()->points);

        Campaign::where('external_id', 'gfr1')->update(['referral_excluded' => true]);
        $this->postback($friend, 1000, 1);
        $this->assertCount(1, $this->referralRows($referrer));
    }

    public function test_no_reward_for_inactive_referrer_or_two_levels(): void
    {
        [$referrer, $friend] = $this->pair();
        $friendOfFriend = User::factory()->create(['referred_by_user_id' => $friend->id]);

        $this->postback($friendOfFriend, 1000, 1);
        $this->assertCount(0, $this->referralRows($referrer));
        $this->assertCount(1, $this->referralRows($friend));

        $referrer->forceFill(['status' => User::STATUS_WITHDRAWN])->save();
        $this->postback($friend, 1000, 1);
        $this->assertCount(0, $this->referralRows($referrer));
    }

    public function test_initial_bonus_once_and_valid_count_raises_rank(): void
    {
        ReferralRank::where('code', 'gold')->update(['min_valid_referrals' => 1]);
        [$referrer, $friend] = $this->pair();

        $this->postback($friend, 600, 1);
        $this->assertSame(0, ReferralReward::where('type', 'initial')->count());
        $this->postback($friend, 400, 1);
        $this->postback($friend, 2000, 1);

        $initial = PointTransaction::where('user_id', $referrer->id)->where('type', PointTransaction::TYPE_REFERRAL_INITIAL)->sole();
        $this->assertSame(['confirmed', 100], [$initial->status, $initial->points]);

        // 有効な紹介1人でゴールド（8%）になり、そのあと確定した分から8%
        $rank = $this->service()->rankFor($referrer);
        $this->assertSame(['gold', 800, 1], [$rank['code'], $rank['rate_bps'], $rank['valid_referrals']]);
        $this->assertSame([30, 20, 160], $this->referralRows($referrer)->pluck('points')->all());

        // 紹介された側の紹介を無効にすると、有効紹介人数から外れてランクも戻る
        $this->service()->disable($friend);
        $this->assertSame(['regular', 0], [$this->service()->rankFor($referrer)['code'], $this->service()->rankFor($referrer)['valid_referrals']]);
    }

    public function test_initial_bonus_not_given_after_deadline(): void
    {
        [$referrer, $friend] = $this->pair();
        $friend->forceFill(['created_at' => now()->subDays(181)])->save();
        $this->postback($friend, 5000, 1);

        $this->assertSame(0, ReferralReward::where('type', 'initial')->count());
    }

    public function test_partner_rate_is_capped(): void
    {
        [$referrer, $friend] = $this->pair();
        $referrer->forceFill(['referral_partner_rate' => 12])->save();
        ReferralRank::where('code', 'partner')->update(['rate' => 10]);

        $this->postback($friend, 1000, 1);
        $this->assertSame(100, $this->referralRows($referrer)->sole()->points);
        $this->assertTrue($this->service()->rankFor($referrer->fresh())['is_partner']);
    }

    public function test_held_conversion_reward_follows_release(): void
    {
        [$referrer, $friend] = $this->pair();
        // 未取得の成果地点で保留 → 承認でも判定中のまま
        app(PostbackHandler::class)->handle([
            'user_id' => $friend->uid, 'cv_id' => 'held', 'cv_action_id' => 'unknown', 'campaign_id' => 'gfr1',
            'cv_datetime' => '2026-11-20T10:00:00+09:00', 'cv_status' => 1, 'incentive_amount' => 1000,
        ]);
        $this->assertSame('pending', $this->referralRows($referrer)->sole()->status);

        app(PostbackHandler::class)->releaseHold(Conversion::firstWhere('cv_id', 'held')->id);
        $this->assertSame('confirmed', $this->referralRows($referrer)->sole()->fresh()->status);
    }

    public function test_member_api_returns_referral_page_data_without_friend_details(): void
    {
        [$referrer, $friend] = $this->pair();
        $this->postback($friend, 1000, 0);
        $this->asApiHost()->getJson('/v1/me/referral')->assertUnauthorized();

        $res = $this->actingAs($referrer)->asApiHost()->getJson('/v1/me/referral')->assertOk();
        $code = $res->json('data.code');
        $res->assertJsonPath('data.url', 'https://poifo.test/invite/?code='.$code)
            ->assertJsonPath('data.rank.name', 'レギュラー')
            ->assertJsonPath('data.rank.rate', 5)
            ->assertJsonPath('data.next_rank.remaining', 30)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.pending', 50)
            ->assertJsonPath('data.initial_bonus.points', 100)
            ->assertJsonPath('data.rate_range.max', 15);
        $this->assertStringNotContainsString((string) $friend->display_name, $res->getContent());

        $this->actingAs($referrer)->asApiHost()->getJson('/v1/me/referral/rewards')
            ->assertOk()->assertJsonPath('data.0.type_label', '紹介報酬')->assertJsonPath('data.0.status', 'pending');

        // 紹介ページ用のコード確認は未ログインでも使え、名前を返さない
        $this->asApiHost()->getJson('/v1/referral/codes/'.$code)->assertExactJson(['data' => ['valid' => true]]);
        $this->asApiHost()->getJson('/v1/referral/codes/AAAAAAAA')->assertExactJson(['data' => ['valid' => false]]);
        $this->actingAs($referrer)->asAdminHost()->getJson('/v1/me/referral')->assertNotFound();
    }

    public function test_admin_settings_change_ranks_and_initial_bonus(): void
    {
        $admin = Admin::factory()->create();
        $regular = ReferralRank::firstWhere('code', 'regular');
        $gold = ReferralRank::firstWhere('code', 'gold');
        $platinum = ReferralRank::firstWhere('code', 'platinum');

        $this->actingAs($admin, 'admin')->asAdminHost()->get('/referral-settings')->assertOk()->assertSee('プラチナ');
        $this->actingAs($admin, 'admin')->asApiHost()->get('/referral-settings')->assertNotFound();

        $this->actingAs($admin, 'admin')->asAdminHost()->put('/referral-settings', [
            'ranks' => [
                ['id' => $regular->id, 'name' => 'ブロンズ', 'rate' => '4.5', 'min_valid_referrals' => 0],
                ['id' => $gold->id, 'name' => 'ゴールド', 'rate' => '8', 'min_valid_referrals' => 20],
                ['id' => $platinum->id, 'name' => 'プラチナ', 'rate' => '10', 'min_valid_referrals' => 100, 'delete' => '1'],
                ['name' => 'ダイヤ', 'rate' => '12', 'min_valid_referrals' => 50],
            ],
            'partner' => ['name' => '特別パートナー', 'rate' => '20'],
            'initial_bonus_points' => 200, 'initial_threshold_points' => 500, 'initial_days' => 90,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['ブロンズ', 'ゴールド', 'ダイヤ'], $this->service()->generalRanks()->pluck('name')->all());
        $this->assertSame(450, ReferralRank::firstWhere('code', 'regular')->rateBps());
        $this->assertSame([200, 500, 90], [ReferralSetting::current()->initial_bonus_points, ReferralSetting::current()->initial_threshold_points, ReferralSetting::current()->initial_days]);
        $this->assertTrue(AdminAuditLog::where('action', 'referral.settings_update')->exists());

        // 0人のランクがなくなる変更は受け付けない
        $this->actingAs($admin, 'admin')->asAdminHost()->put('/referral-settings', [
            'ranks' => [['id' => $regular->id, 'name' => 'ブロンズ', 'rate' => '4.5', 'min_valid_referrals' => 0, 'delete' => '1'], ['id' => $gold->id, 'name' => 'ゴールド', 'rate' => '8', 'min_valid_referrals' => 20]],
            'partner' => ['name' => '特別パートナー', 'rate' => '20'],
            'initial_bonus_points' => 200, 'initial_threshold_points' => 500, 'initial_days' => 90,
        ])->assertSessionHasErrors('ranks');
        $this->assertNotNull(ReferralRank::find($regular->id));
    }

    public function test_admin_partner_disable_rewards_list_and_campaign_settings(): void
    {
        $admin = Admin::factory()->create();
        [$referrer, $friend] = $this->pair();
        $this->postback($friend, 1000, 0);
        foreach ([$referrer, $friend] as $u) {
            BankAccount::register($u, ['bank_code' => '0001', 'bank_name' => 'テスト銀行', 'branch_code' => '001', 'branch_name' => '本店', 'account_type' => 'ordinary', 'account_number' => '1234567', 'account_holder_kana' => 'ﾃｽﾄ', 'holder_name' => 'テスト']);
        }

        $this->actingAs($admin, 'admin')->asAdminHost()->get('/members/'.$referrer->id)->assertOk()->assertSee('友達紹介');
        $this->actingAs($admin, 'admin')->asAdminHost()->get('/referral-rewards')->assertOk()->assertSee('同じ振込先口座');
        $csv = $this->actingAs($admin, 'admin')->asAdminHost()->get('/referral-rewards/export?status=pending')->assertOk()->streamedContent();
        $this->assertStringContainsString($friend->uid, $csv);

        $this->actingAs($admin, 'admin')->asAdminHost()->post("/members/{$referrer->id}/referral/partner", ['partner' => '1', 'rate' => '20', 'reason' => '契約'])
            ->assertSessionHasErrors('referral');
        $this->actingAs($admin, 'admin')->asAdminHost()->post("/members/{$referrer->id}/referral/partner", ['partner' => '1', 'rate' => '12', 'reason' => '契約'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1200, ReferralRank::toBps($referrer->fresh()->referral_partner_rate));

        // 紹介を無効にすると判定中の紹介報酬は否認になり、そのあとは付かない
        $this->actingAs($admin, 'admin')->asAdminHost()->post("/members/{$referrer->id}/referral/disable", ['reason' => '不正の疑い'])->assertSessionHasNoErrors();
        $this->assertSame(['rejected'], $this->referralRows($referrer)->pluck('status')->all());
        $this->postback($friend, 1000, 1);
        $this->assertCount(1, $this->referralRows($referrer));

        $campaign = Campaign::firstWhere('external_id', 'gfr1');
        $this->actingAs($admin, 'admin')->asAdminHost()->put('/campaigns/'.$campaign->id, ['sort_order' => 0, 'referral_excluded' => '1', 'referral_rate_cap' => '3'])->assertSessionHasNoErrors();
        $campaign->refresh();
        $this->assertTrue($campaign->referral_excluded);
        $this->assertSame(300, ReferralRank::toBps($campaign->referral_rate_cap));
    }
}
