<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function login(Admin $admin, string $password = 'correct-horse-battery')
    {
        return $this->asAdminHost()->post('/login', ['email' => $admin->email, 'password' => $password]);
    }

    public function test_guest_is_sent_to_admin_login(): void
    {
        $this->asAdminHost()->get('/withdrawals')->assertRedirect(route('admin.login'));
    }

    public function test_password_alone_does_not_log_in(): void
    {
        $secret = (new Google2FA)->generateSecretKey(32);
        $admin = Admin::factory()->withTwoFactor($secret)->create();

        $this->login($admin)->assertRedirect(route('admin.two-factor.challenge'));
        $this->assertGuest('admin');
        $this->asAdminHost()->get('/')->assertRedirect(route('admin.login'));
    }

    public function test_valid_code_logs_in_and_the_same_code_cannot_be_reused(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(32);
        $admin = Admin::factory()->withTwoFactor($secret)->create();
        $code = $google2fa->getCurrentOtp($secret);

        $this->login($admin);
        $this->asAdminHost()->post('/two-factor/challenge', ['code' => $code])->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertDatabaseHas('admin_audit_logs', ['admin_id' => $admin->id, 'action' => 'login']);

        $this->asAdminHost()->post('/logout');
        $this->login($admin);
        $this->asAdminHost()->post('/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('admin');
    }

    public function test_wrong_password_is_rejected_and_logged(): void
    {
        $admin = Admin::factory()->create();

        $this->login($admin, 'wrong-password')->assertSessionHasErrors('email');
        $this->assertDatabaseHas('admin_audit_logs', ['admin_id' => $admin->id, 'action' => 'login.failed']);
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $admin = Admin::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->login($admin, 'wrong');
        }

        // 正しいパスワードでもしばらくは入れない
        $this->login($admin)->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_first_login_requires_two_factor_setup(): void
    {
        $admin = Admin::factory()->create();

        $this->login($admin)->assertRedirect(route('admin.two-factor.setup'));
        $this->asAdminHost()->get('/two-factor/setup')->assertOk()->assertSee('<svg', false);

        $secret = session('admin_login.setup_secret');
        $this->asAdminHost()->post('/two-factor/setup', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('admin');

        $this->asAdminHost()->post('/two-factor/setup', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertTrue($admin->fresh()->hasTwoFactor());
    }

    public function test_inactive_admin_cannot_log_in(): void
    {
        $admin = Admin::factory()->create(['is_active' => false]);

        $this->login($admin)->assertSessionHasErrors('email');
    }
}
