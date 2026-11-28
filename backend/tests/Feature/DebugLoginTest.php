<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// デバッグ用ログインは POIFO_DEBUG_LOGIN_ENABLED=true のときだけ使える（API 仕様書とは別のスイッチ）
class DebugLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->setDebugEnv('true');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->setDebugEnv('false');
    }

    public function test_creates_a_debug_member_and_logs_in(): void
    {
        $this->asApiHost()->get('/auth/debug/login?user=alice')
            ->assertRedirect(config('poifo.front_url').'/welcome/?new=1');

        $user = User::firstWhere('line_user_id', 'debug:alice');
        $this->assertNotNull($user);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_same_name_logs_in_as_the_same_member(): void
    {
        $this->asApiHost()->get('/auth/debug/login');
        $this->app['auth']->forgetGuards();

        $this->asApiHost()->get('/auth/debug/login')
            ->assertRedirect(config('poifo.front_url').'/welcome/');

        $this->assertSame(1, User::where('line_user_id', 'debug:tester')->count());
    }

    public function test_cannot_log_in_as_a_real_member(): void
    {
        $real = User::factory()->create(['line_user_id' => 'U1234567890']);

        $this->asApiHost()->get('/auth/debug/login?user=U1234567890');

        $this->assertAuthenticatedAs(User::firstWhere('line_user_id', 'debug:U1234567890'), 'web');
        $this->assertNotSame($real->id, auth('web')->id());
    }

    public function test_rejects_invalid_names(): void
    {
        $this->asApiHost()->get('/auth/debug/login?user='.urlencode('debug:x'))->assertStatus(422);
        $this->asApiHost()->get('/auth/debug/login?user='.str_repeat('a', 33))->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_suspended_debug_member_cannot_log_in(): void
    {
        User::factory()->create(['line_user_id' => 'debug:bob', 'status' => User::STATUS_SUSPENDED]);

        $this->asApiHost()->get('/auth/debug/login?user=bob')
            ->assertRedirect(config('poifo.front_url').'/auth/error/?reason=suspended');
        $this->assertGuest('web');
    }

    public function test_api_docs_stay_closed_while_debug_login_is_enabled(): void
    {
        $this->asApiHost()->get('/docs/api')->assertNotFound();
    }

    public function test_not_reachable_from_the_admin_host(): void
    {
        $this->asAdminHost()->get('/auth/debug/login')->assertNotFound();
        $this->assertGuest('web');
    }

    private function setDebugEnv(string $value): void
    {
        putenv("POIFO_DEBUG_LOGIN_ENABLED={$value}");
        $_ENV['POIFO_DEBUG_LOGIN_ENABLED'] = $_SERVER['POIFO_DEBUG_LOGIN_ENABLED'] = $value;
    }
}
