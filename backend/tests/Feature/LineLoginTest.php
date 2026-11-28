<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LineLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['poifo.line.channel_id' => '1234567890', 'poifo.line.channel_secret' => 'secret', 'poifo.line.redirect_uri' => 'https://api.poifo.test/auth/line/callback']);
    }

    private function fakeLine(string $sub = 'Uabc'): void
    {
        Http::fake([
            'api.line.me/oauth2/v2.1/token' => Http::response(['id_token' => 'dummy', 'access_token' => 'x']),
            'api.line.me/oauth2/v2.1/verify' => Http::response(['sub' => $sub, 'name' => 'ポイ太郎', 'picture' => 'https://profile.line-scdn.net/x']),
        ]);
    }

    public function test_redirect_goes_to_line_with_state(): void
    {
        $response = $this->asApiHost()->get('/auth/line/redirect');

        $response->assertRedirectContains('https://access.line.me/oauth2/v2.1/authorize');
        $this->assertNotEmpty(session('line_login.state'));
    }

    public function test_first_login_creates_member_and_returns_to_front(): void
    {
        $this->fakeLine();

        $this->asApiHost()
            ->withSession(['line_login' => ['state' => 'st', 'nonce' => 'nn']])
            ->get('/auth/line/callback?code=c&state=st')
            ->assertRedirect('https://poifo.test/welcome/?new=1');

        $user = User::firstWhere('line_user_id', 'Uabc');
        $this->assertSame('ポイ太郎', $user->display_name);
        $this->assertNotEmpty($user->uid);
        $this->assertAuthenticatedAs($user);
    }

    public function test_state_mismatch_is_rejected(): void
    {
        $this->asApiHost()
            ->withSession(['line_login' => ['state' => 'st', 'nonce' => 'nn']])
            ->get('/auth/line/callback?code=c&state=other')
            ->assertRedirect('https://poifo.test/auth/error/?reason=invalid_state');

        $this->assertGuest();
    }

    public function test_withdrawn_member_cannot_log_in_again(): void
    {
        User::factory()->withdrawn()->create(['line_user_id' => 'Uabc']);
        $this->fakeLine();

        $this->asApiHost()
            ->withSession(['line_login' => ['state' => 'st', 'nonce' => 'nn']])
            ->get('/auth/line/callback?code=c&state=st')
            ->assertRedirect('https://poifo.test/auth/error/?reason=withdrawn');

        $this->assertGuest();
    }

    public function test_login_entry_is_rate_limited_per_viewer_ip(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->asApiHost()->withHeader('x-poifo-viewer-ip', '192.0.2.1')->get('/auth/line/redirect')->assertRedirect();
        }

        $this->asApiHost()->withHeader('x-poifo-viewer-ip', '192.0.2.1')->get('/auth/line/callback?code=c&state=st')->assertStatus(429);
        // X-Forwarded-For を変えても同じ枠
        $this->asApiHost()->withHeader('x-poifo-viewer-ip', '192.0.2.1')->withHeader('X-Forwarded-For', '198.51.100.9')
            ->get('/auth/line/redirect')->assertStatus(429);
        // 別の IP は影響を受けない
        $this->asApiHost()->withHeader('x-poifo-viewer-ip', '192.0.2.2')->get('/auth/line/redirect')->assertRedirect();
    }
}
