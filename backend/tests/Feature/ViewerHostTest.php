<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewerHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_routes_are_not_reachable_from_the_api_host(): void
    {
        $this->asApiHost()->get('/login')->assertNotFound();
        $this->asApiHost()->get('/withdrawals')->assertNotFound();
        $this->asAdminHost()->get('/login')->assertOk()->assertSee('POIFO 管理画面');
    }

    public function test_api_routes_are_not_reachable_from_the_admin_host(): void
    {
        $this->asAdminHost()->getJson('/v1/campaigns')->assertNotFound();
        $this->asApiHost()->getJson('/v1/campaigns')->assertOk();
    }

    public function test_requests_without_the_viewer_host_header_are_rejected(): void
    {
        $this->getJson('/v1/campaigns')->assertNotFound();
        $this->get('/login')->assertNotFound();
    }

    public function test_health_check_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
