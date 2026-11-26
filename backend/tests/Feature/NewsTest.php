<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// お知らせ（docs/specs/functional-spec.md 11.1）
class NewsTest extends TestCase
{
    use RefreshDatabase;

    private function news(array $attrs = []): News
    {
        return News::create(array_merge([
            'title' => 'お知らせ', 'body' => "本文\nhttps://example.com", 'status' => News::STATUS_PUBLISHED, 'published_at' => now()->subHour(),
        ], $attrs));
    }

    public function test_list_shows_only_visible_news_newest_first_without_login(): void
    {
        $this->news(['title' => '古い', 'published_at' => now()->subDays(2)]);
        $this->news(['title' => '新しい', 'published_at' => now()->subDay()]);
        $this->news(['title' => '下書き', 'status' => News::STATUS_DRAFT]);
        $this->news(['title' => '予約', 'published_at' => now()->addDay()]);
        $this->news(['title' => '公開終了', 'expires_at' => now()->subMinute()]);

        $this->asApiHost()->getJson('/v1/news')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', '新しい')
            ->assertJsonPath('data.1.title', '古い')
            ->assertJsonMissingPath('data.0.body');
    }

    public function test_important_filter(): void
    {
        $this->news(['title' => 'ふつう']);
        $this->news(['title' => '重要', 'is_important' => true, 'published_at' => now()->subDays(3)]);

        $this->asApiHost()->getJson('/v1/news?important=1&per_page=1')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', '重要')->assertJsonPath('data.0.is_important', true);
    }

    public function test_detail_returns_body_and_hides_invisible_ones(): void
    {
        $visible = $this->news();
        $draft = $this->news(['status' => News::STATUS_DRAFT]);
        $expired = $this->news(['expires_at' => now()->subMinute()]);

        $this->asApiHost()->getJson("/v1/news/{$visible->id}")->assertOk()->assertJsonPath('data.body', "本文\nhttps://example.com");
        $this->asApiHost()->getJson("/v1/news/{$draft->id}")->assertNotFound();
        $this->asApiHost()->getJson("/v1/news/{$expired->id}")->assertNotFound();
    }

    public function test_unread_count_is_reset_when_the_list_is_seen(): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(10)]);
        $this->news(['published_at' => now()->subDays(20)]); // 登録より前のものは未読に数えない
        $this->news(['published_at' => now()->subDays(5)]);
        $this->news(['published_at' => now()->subDay()]);
        $this->news(['status' => News::STATUS_DRAFT, 'published_at' => now()->subDay()]);

        $this->actingAs($user)->asApiHost()->getJson('/v1/me')->assertJsonPath('data.unread_news_count', 2);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/news/seen')->assertOk();
        $this->actingAs($user)->asApiHost()->getJson('/v1/me')->assertJsonPath('data.unread_news_count', 0);

        $this->travel(1)->minutes();
        $this->news(['published_at' => now()]);
        $this->actingAs($user)->asApiHost()->getJson('/v1/me')->assertJsonPath('data.unread_news_count', 1);
    }

    public function test_seen_requires_login(): void
    {
        $this->asApiHost()->postJson('/v1/me/news/seen')->assertUnauthorized();
    }

    public function test_admin_creates_edits_and_only_deletes_drafts(): void
    {
        $admin = Admin::factory()->create();
        $as = fn () => $this->actingAs($admin, 'admin')->asAdminHost();

        $as()->get('/news')->assertOk();
        $as()->get('/news/create')->assertOk();
        $as()->post('/news', ['title' => '', 'body' => '', 'status' => 'published'])->assertSessionHasErrors(['title', 'body', 'published_at']);
        $as()->post('/news', [
            'title' => 'メンテナンスのお知らせ', 'body' => '本文', 'status' => 'published', 'is_important' => '1',
            'published_at' => now()->subMinute()->format('Y-m-d\TH:i'),
        ])->assertRedirect('/news');

        $news = News::firstOrFail();
        $this->assertTrue($news->is_important);
        $this->assertSame($admin->id, $news->created_by);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'news.create', 'target_id' => $news->id]);

        $as()->get("/news/{$news->id}/edit")->assertOk()->assertSee('メンテナンスのお知らせ');
        $as()->put("/news/{$news->id}", [
            'title' => '変更後', 'body' => '本文', 'status' => 'published',
            'published_at' => now()->subMinute()->format('Y-m-d\TH:i'),
        ])->assertRedirect('/news');
        $this->assertSame('変更後', $news->fresh()->title);
        $this->assertFalse($news->fresh()->is_important);

        // 公開したものは消せない
        $as()->delete("/news/{$news->id}")->assertSessionHasErrors('news');
        $this->assertNotNull($news->fresh());

        $draft = $this->news(['status' => News::STATUS_DRAFT]);
        $as()->delete("/news/{$draft->id}")->assertRedirect('/news');
        $this->assertNull($draft->fresh());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'news.delete', 'target_id' => $draft->id]);
    }

    public function test_admin_news_is_not_on_the_api_host(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')->asApiHost()->get('/news/create')->assertNotFound();
    }
}
