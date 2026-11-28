<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationMail;
use App\Models\BankAccount;
use App\Models\Campaign;
use App\Models\EmailVerification;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\Members\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** 会員のメールアドレスの登録と確認（機能仕様 3.5） */
class MemberEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** 送られた確認メールのリンクからトークンを取り出す */
    private function sentToken(string $to): string
    {
        $token = null;
        Mail::assertQueued(EmailVerificationMail::class, function (EmailVerificationMail $mail) use ($to, &$token) {
            if (! $mail->hasTo($to)) {
                return false;
            }
            parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $q);
            $token = $q['token'] ?? null;

            return str_starts_with($mail->url, 'https://poifo.test/mypage/account/verify/?token=');
        });

        return (string) $token;
    }

    public function test_member_without_email_is_not_registered(): void
    {
        $user = User::factory()->withoutEmail()->create();

        $this->actingAs($user)->asApiHost()->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email_registered', false)
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.email', null);
    }

    public function test_register_sends_mail_and_link_verifies_without_login(): void
    {
        $user = User::factory()->withoutEmail()->create();

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => ' Taro@Example.com ', 'marketing_opt_in' => true])
            ->assertOk()
            ->assertJsonPath('data.email_registered', true)
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.pending_email', 'taro@example.com')
            ->assertJsonPath('data.email_marketing_opt_in', true);

        $token = $this->sentToken('taro@example.com');
        $this->assertNotEmpty($token);
        // トークンそのものは DB に持たない
        $this->assertDatabaseMissing('email_verifications', ['token_hash' => $token]);

        // リンクはメールアプリで開かれるので、ログインなしで確認できる
        $this->app['auth']->forgetGuards();
        $this->asApiHost()->postJson('/v1/email/verify', ['token' => $token])->assertOk();

        $user->refresh();
        $this->assertSame('taro@example.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(0, EmailVerification::count());

        // 同じリンクは2回使えない
        $this->asApiHost()->postJson('/v1/email/verify', ['token' => $token])
            ->assertUnprocessable()->assertJsonPath('errors.token.0', 'リンクが無効です。');
    }

    public function test_invalid_format_is_rejected_without_counting_toward_the_limit(): void
    {
        $user = User::factory()->withoutEmail()->create();

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => 'not-an-email'])
                ->assertUnprocessable()->assertJsonPath('errors.email.0', 'メールアドレスの形式が正しくありません。');
        }

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => 'ok@example.com'])->assertOk();
    }

    public function test_expired_link_is_rejected_and_can_be_resent(): void
    {
        $user = User::factory()->withoutEmail()->create();
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => 'a@example.com'])->assertOk();
        $token = $this->sentToken('a@example.com');

        $this->travel(25)->hours();
        $this->asApiHost()->postJson('/v1/email/verify', ['token' => $token])
            ->assertUnprocessable()->assertJsonPath('errors.token.0', 'リンクの有効期限が切れています。');

        // 期限が切れても確認待ちのまま（案件には進める）。再送すると古いリンクは使えなくなる
        $this->actingAs($user)->asApiHost()->getJson('/v1/me')->assertJsonPath('data.email_registered', true);
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email/resend')->assertOk();
        $this->assertSame(1, EmailVerification::where('user_id', $user->id)->count());
        $this->asApiHost()->postJson('/v1/email/verify', ['token' => $token])->assertUnprocessable();
    }

    public function test_sending_is_limited_to_three_per_hour(): void
    {
        $user = User::factory()->withoutEmail()->create();
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => 'a@example.com'])->assertOk();
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email/resend')->assertOk();
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email/resend')->assertOk();

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email/resend')
            ->assertUnprocessable()->assertJsonPath('errors.email.0', '確認メールの送信は1時間に3回までです。しばらくしてからお試しください。');

        $this->travel(61)->minutes();
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email/resend')->assertOk();
    }

    public function test_address_verified_by_another_member_cannot_be_used(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->withoutEmail()->create();

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => 'TAKEN@example.com'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'このメールアドレスは登録できません。');
        Mail::assertNothingQueued();
    }

    public function test_first_to_verify_wins_when_two_members_enter_the_same_address(): void
    {
        $a = User::factory()->withoutEmail()->create();
        $b = User::factory()->withoutEmail()->create();
        $this->actingAs($a)->asApiHost()->postJson('/v1/me/email', ['email' => 'same@example.com'])->assertOk();
        $this->actingAs($b)->asApiHost()->postJson('/v1/me/email', ['email' => 'same@example.com'])->assertOk();

        $tokens = [];
        Mail::assertQueued(EmailVerificationMail::class, function (EmailVerificationMail $mail) use (&$tokens) {
            parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $q);
            $tokens[] = $q['token'];

            return true;
        });

        $this->asApiHost()->postJson('/v1/email/verify', ['token' => $tokens[1]])->assertOk();
        $this->asApiHost()->postJson('/v1/email/verify', ['token' => $tokens[0]])
            ->assertUnprocessable()->assertJsonPath('errors.token.0', 'このメールアドレスは登録できません。');
        $this->assertNull($a->refresh()->email);
        $this->assertSame('same@example.com', $b->refresh()->email);
    }

    public function test_changing_keeps_the_old_address_until_verified(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email', 'old@example.com')
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.pending_email', 'new@example.com');

        $this->asApiHost()->postJson('/v1/email/verify', ['token' => $this->sentToken('new@example.com')])->assertOk();
        $this->assertSame('new@example.com', $user->refresh()->email);
    }

    public function test_marketing_setting_can_be_changed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->asApiHost()->putJson('/v1/me/email/settings', ['marketing_opt_in' => true])
            ->assertOk()->assertJsonPath('data.email_marketing_opt_in', true);
        $this->assertTrue($user->refresh()->email_marketing_opt_in);
    }

    public function test_line_login_sends_members_without_email_to_first_registration(): void
    {
        config(['poifo.line.channel_id' => '1234567890', 'poifo.line.channel_secret' => 'secret', 'poifo.line.redirect_uri' => 'https://api.poifo.test/auth/line/callback']);
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'dummy', 'access_token' => 'x']),
            'api.line.me/oauth2/v2.1/verify' => Http::response(['sub' => 'Uold', 'name' => 'ポイ太郎']),
        ]);
        // 公開前からの会員でメールアドレスがない人も、次のログインで初回登録へ
        User::factory()->withoutEmail()->create(['line_user_id' => 'Uold']);

        $this->asApiHost()
            ->withSession(['line_login' => ['state' => 'st', 'nonce' => 'nn']])
            ->get('/auth/line/callback?code=c&state=st')
            ->assertRedirect('https://poifo.test/welcome/');
    }

    public function test_line_login_with_email_goes_to_complete(): void
    {
        config(['poifo.line.channel_id' => '1234567890', 'poifo.line.channel_secret' => 'secret', 'poifo.line.redirect_uri' => 'https://api.poifo.test/auth/line/callback']);
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'dummy', 'access_token' => 'x']),
            'api.line.me/oauth2/v2.1/verify' => Http::response(['sub' => 'Uhas', 'name' => 'ポイ太郎']),
        ]);
        User::factory()->create(['line_user_id' => 'Uhas']);

        $this->asApiHost()
            ->withSession(['line_login' => ['state' => 'st', 'nonce' => 'nn']])
            ->get('/auth/line/callback?code=c&state=st')
            ->assertRedirect('https://poifo.test/auth/complete/');
    }

    public function test_go_sends_members_without_email_to_first_registration(): void
    {
        $campaign = Campaign::create([
            'external_id' => 'gfr1', 'name' => 'テスト案件', 'click_url' => 'https://measurement.example.com/click/abc',
            'external_status' => 'started', 'max_points' => 500,
        ]);
        $user = User::factory()->withoutEmail()->create();

        $this->actingAs($user)->asApiHost()->get("/go/{$campaign->id}")
            ->assertRedirect('https://poifo.test/welcome/?back=%2Fcampaigns%2Fdetail%2F%3Fid%3D'.$campaign->id);
        $this->assertDatabaseCount('campaign_clicks', 0);

        // 確認待ちでも案件には進める
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => 'a@example.com'])->assertOk();
        $this->actingAs($user)->asApiHost()->get("/go/{$campaign->id}")
            ->assertRedirect('https://measurement.example.com/click/abc?user_id='.$user->uid);
    }

    public function test_withdrawal_requires_verified_email(): void
    {
        config(['poifo.exchange_methods.bank_transfer.min_points' => 1000]);
        $user = User::factory()->withoutEmail()->create();
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'confirmed', 'points' => 2500]);
        BankAccount::register($user, [
            'bank_code' => '0001', 'bank_name' => 'みずほ銀行', 'branch_code' => '001', 'branch_name' => '東京営業部',
            'account_type' => 'ordinary', 'account_number' => '0123456', 'account_holder_kana' => 'ヤマダ タロウ', 'holder_name' => '山田 太郎',
        ]);
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/email', ['email' => 'a@example.com'])->assertOk();

        // 確認待ちでは申請できない
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 2000])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'ポイント交換の申請には、メールアドレスの確認が必要です。登録情報から確認してください。');
        $this->assertSame(1, PointTransaction::where('user_id', $user->id)->count());

        $this->asApiHost()->postJson('/v1/email/verify', ['token' => $this->sentToken('a@example.com')])->assertOk();
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 2000])->assertCreated();
    }

    public function test_leaving_clears_email(): void
    {
        $user = User::factory()->create(['email' => 'bye@example.com', 'email_marketing_opt_in' => true]);
        EmailVerification::create(['user_id' => $user->id, 'email' => 'next@example.com', 'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);

        app(MemberService::class)->leave($user);

        $user->refresh();
        $this->assertNull($user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->email_marketing_opt_in);
        $this->assertSame(0, EmailVerification::count());
        // 同じアドレスで別の会員が登録できる
        $other = User::factory()->withoutEmail()->create();
        $this->actingAs($other)->asApiHost()->postJson('/v1/me/email', ['email' => 'bye@example.com'])->assertOk();
    }

    public function test_api_is_not_served_on_admin_host(): void
    {
        $this->asAdminHost()->postJson('/v1/email/verify', ['token' => 'x'])->assertNotFound();
    }
}
