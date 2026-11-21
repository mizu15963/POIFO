<?php

namespace Tests\Feature;

use Tests\TestCase;

// API 仕様書は POIFO_API_DOCS_ENABLED=true のときだけ api. に出る（本番では出さない）
class ApiDocsTest extends TestCase
{
    protected function tearDown(): void
    {
        // phpunit.xml の既定（false）に戻す
        $this->setDocsEnv('false');
        parent::tearDown();
    }

    public function test_docs_are_not_served_by_default(): void
    {
        $this->asApiHost()->get('/docs/api')->assertNotFound();
        $this->asApiHost()->getJson('/docs/api.json')->assertNotFound();
        // デバッグ用ログインも同じフラグで止まる
        $this->asApiHost()->get('/auth/debug/login')->assertNotFound();
    }

    public function test_docs_are_served_on_the_api_host_when_enabled(): void
    {
        $this->setDocsEnv('true');
        $this->refreshApplication();

        $this->asApiHost()->get('/docs/api')->assertOk()->assertSee('POIFO API');

        $paths = array_keys($this->asApiHost()->getJson('/docs/api.json')->assertOk()->json('paths'));
        $this->assertContains('/v1/campaigns', $paths);
        $this->assertContains('/v1/me/withdrawals', $paths);
        $this->assertContains('/postback/{token}/gf-rewards', $paths);
        // 管理画面のルートは載せない
        $this->assertNotContains('/withdrawals/export', $paths);
        $this->assertNotContains('/login', $paths);
    }

    public function test_docs_are_not_reachable_from_the_admin_host_even_when_enabled(): void
    {
        $this->setDocsEnv('true');
        $this->refreshApplication();

        $this->asAdminHost()->get('/docs/api')->assertNotFound();
        $this->get('/docs/api')->assertNotFound();
    }

    private function setDocsEnv(string $value): void
    {
        putenv("POIFO_API_DOCS_ENABLED={$value}");
        $_ENV['POIFO_API_DOCS_ENABLED'] = $_SERVER['POIFO_API_DOCS_ENABLED'] = $value;
    }
}
