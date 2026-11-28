<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Campaign;
use App\Models\CampaignCategorySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// カテゴリの表示（docs/specs/functional-spec.md 4.7）: メニューのボタンの表示名・並び・出すかどうか
class CampaignCategorySettingTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(string $id, string $category, array $attrs = []): Campaign
    {
        return Campaign::create($attrs + ['external_id' => $id, 'name' => "案件{$id}", 'category' => $category, 'click_url' => 'https://example.com/c', 'external_status' => 'started']);
    }

    private function admin(): Admin
    {
        return Admin::factory()->create();
    }

    public function test_categories_have_default_labels_and_are_ordered_by_count(): void
    {
        $this->campaign('a1', 'アプリインストール');
        $this->campaign('a2', 'アプリインストール');
        $this->campaign('b1', '口座開設');
        $this->campaign('c1', '新しいカテゴリ');
        // 掲載していない案件は数えない
        $this->campaign('x1', '資料請求', ['is_hidden' => true]);

        $this->asApiHost()->getJson('/v1/campaign-categories')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0', ['name' => 'アプリインストール', 'label' => 'アプリ', 'count' => 2, 'in_menu' => true])
            ->assertJsonPath('data.1.name', '口座開設')
            // 設定がないカテゴリは ASP の名前のまま
            ->assertJsonPath('data.2', ['name' => '新しいカテゴリ', 'label' => '新しいカテゴリ', 'count' => 1, 'in_menu' => true]);
    }

    public function test_sort_order_and_in_menu_are_applied(): void
    {
        $this->campaign('a1', 'アプリインストール');
        $this->campaign('a2', 'アプリインストール');
        $this->campaign('b1', '口座開設');
        CampaignCategorySetting::where('category', '口座開設')->update(['sort_order' => 1, 'label' => '口座']);
        CampaignCategorySetting::where('category', 'アプリインストール')->update(['in_menu' => false]);

        $this->asApiHost()->getJson('/v1/campaign-categories')->assertOk()
            ->assertJsonPath('data.0', ['name' => '口座開設', 'label' => '口座', 'count' => 1, 'in_menu' => true])
            ->assertJsonPath('data.1.in_menu', false);
    }

    public function test_admin_can_update_settings_and_it_is_logged(): void
    {
        $this->campaign('c1', '新しいカテゴリ');
        $admin = $this->admin();

        $this->asAdminHost()->actingAs($admin, 'admin')->get('/campaign-categories')->assertOk()->assertSee('新しいカテゴリ')->assertSee('アプリインストール');

        $this->asAdminHost()->actingAs($admin, 'admin')->put('/campaign-categories', ['categories' => [
            ['category' => '新しいカテゴリ', 'label' => '新しい', 'sort_order' => '2', 'in_menu' => '1'],
            ['category' => 'アプリインストール', 'label' => 'アプリ', 'sort_order' => '', 'in_menu' => '0'],
            // 変えていない行は記録しない
            ['category' => '口座開設', 'label' => '口座開設', 'sort_order' => '', 'in_menu' => '1'],
        ]])->assertRedirect('/campaign-categories');

        $this->assertDatabaseHas('campaign_category_settings', ['category' => '新しいカテゴリ', 'label' => '新しい', 'sort_order' => 2, 'in_menu' => true]);
        $this->assertDatabaseHas('campaign_category_settings', ['category' => 'アプリインストール', 'in_menu' => false]);
        $log = AdminAuditLog::where('action', 'campaign_category.update')->sole();
        $this->assertCount(2, $log->payload['changes']);
    }

    public function test_label_is_limited_and_admin_host_only(): void
    {
        $admin = $this->admin();
        $this->asAdminHost()->actingAs($admin, 'admin')->put('/campaign-categories', ['categories' => [
            ['category' => '口座開設', 'label' => str_repeat('あ', 33)],
        ]])->assertSessionHasErrors('categories.0.label');

        // 会員向けの api. からは届かない
        $this->asApiHost()->actingAs($admin, 'admin')->get('/campaign-categories')->assertNotFound();
    }
}
