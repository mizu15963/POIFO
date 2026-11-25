<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// API 仕様書は管理画面（admin.）の中にあり、管理者のログイン後だけ見られる。api. には出さない
class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_the_admin_login(): void
    {
        $this->asAdminHost()->get('/docs/api')->assertRedirect(route('admin.login'));
        $this->asAdminHost()->getJson('/docs/api.json')->assertUnauthorized();
    }

    public function test_logged_in_admin_can_read_the_docs(): void
    {
        $admin = Admin::factory()->withTwoFactor('JBSWY3DPEHPK3PXP')->create();

        $this->actingAs($admin, 'admin')->asAdminHost()->get('/docs/api')->assertOk()->assertSee('POIFO API');

        $spec = $this->actingAs($admin, 'admin')->asAdminHost()->getJson('/docs/api.json')->assertOk()->json();
        $paths = array_keys($spec['paths']);
        $this->assertContains('/v1/campaigns', $paths);
        $this->assertContains('/v1/me/withdrawals', $paths);
        $this->assertContains('/postback/{token}/gf-rewards', $paths);
        // 管理画面のルートは載せない
        $this->assertNotContains('/withdrawals/export', $paths);
        $this->assertNotContains('/login', $paths);
        // Try It は api.（APP_URL）へ送る
        $this->assertSame(rtrim(config('app.url'), '/'), $spec['servers'][0]['url']);
    }

    public function test_docs_are_not_served_on_the_api_host(): void
    {
        $this->asApiHost()->get('/docs/api')->assertNotFound();
        $this->asApiHost()->getJson('/docs/api.json')->assertNotFound();
        $this->get('/docs/api')->assertNotFound();
    }

    public function test_member_api_allows_cors_from_the_admin_host_for_try_it(): void
    {
        $origin = 'https://admin.poifo.test';

        $this->asApiHost()->withHeader('Origin', $origin)->getJson('/v1/campaigns')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
