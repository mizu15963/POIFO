<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * HTML で返すエラー画面（resources/views/errors）。
 * 会員が開く api. の画面（LINE ログイン・/go/{id}）はキャラクター付きでフロントのトップへ、管理画面はキャラクターなしで管理画面のトップへ案内する。
 */
class ErrorPageTest extends TestCase
{
    public function test_member_side_404_is_japanese_and_links_to_the_front(): void
    {
        $this->asApiHost()->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('ページが見つかりません')
            ->assertSee('https://poifo.test/', false)
            ->assertSee('https://poifo.test/images/mascot/poifox-point.webp', false)
            ->assertDontSee('Not Found');
    }

    public function test_admin_side_404_has_no_mascot_and_links_to_the_admin_top(): void
    {
        $this->asAdminHost()->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('ページが見つかりません')
            ->assertSee('管理画面のトップへ')
            ->assertDontSee('poifox-', false);
    }

    public function test_server_errors_use_the_japanese_pages(): void
    {
        Route::get('/_test/error/{code}', fn (int $code) => abort($code));

        $this->asApiHost()->get('/_test/error/500')->assertStatus(500)->assertSee('ただいまつながりにくくなっています')->assertSee('poifox-guide.webp', false);
        $this->asApiHost()->get('/_test/error/503')->assertStatus(503)->assertSee('メンテナンス中');
        // 個別の画面がないコードは 4xx / 5xx の画面になる
        $this->asApiHost()->get('/_test/error/502')->assertStatus(502)->assertSee('ただいまつながりにくくなっています');
        $this->asApiHost()->get('/_test/error/400')->assertStatus(400)->assertSee('ページを表示できません');
    }

    public function test_json_requests_still_get_json_errors(): void
    {
        $this->asApiHost()->getJson('/v1/no-such-endpoint')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
