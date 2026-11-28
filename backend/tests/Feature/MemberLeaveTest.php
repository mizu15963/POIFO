<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 会員の退会（仕様書 6章）とカテゴリ一覧（7章）
class MemberLeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_leaves_after_agreeing(): void
    {
        $user = User::factory()->create(['display_name' => '太郎']);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/leave')->assertStatus(422)->assertJsonValidationErrors('agree');
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/leave', ['agree' => true])->assertOk();

        $user->refresh();
        $this->assertSame('withdrawn', $user->status);
        $this->assertNotNull($user->withdrawn_at);
        $this->assertNull($user->display_name);
        $this->assertNotNull($user->line_user_id); // 再登録防止のため残す
    }

    public function test_member_cannot_leave_with_a_withdrawal_in_progress(): void
    {
        $user = User::factory()->create();
        WithdrawalRequest::create([
            'user_id' => $user->id, 'points' => 1000, 'amount_yen' => 1000, 'fee_yen' => 0,
            'status' => WithdrawalRequest::STATUS_APPROVED, 'recipient_snapshot' => [], 'reviewable_after' => now(),
        ]);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/leave', ['agree' => true])
            ->assertStatus(422)->assertJsonValidationErrors('leave');
        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_categories_of_listed_campaigns(): void
    {
        foreach ([['アプリ', 'started'], ['アプリ', 'started'], ['買い物', 'started'], ['旅行', 'paused']] as $i => [$category, $status]) {
            Campaign::create(['external_id' => "c{$i}", 'name' => "案件{$i}", 'click_url' => 'https://example.com', 'category' => $category, 'external_status' => $status]);
        }

        $this->asApiHost()->getJson('/v1/campaign-categories')->assertOk()
            ->assertExactJson(['data' => [['name' => 'アプリ', 'label' => 'アプリ', 'count' => 2, 'in_menu' => true], ['name' => '買い物', 'label' => '買い物', 'count' => 1, 'in_menu' => true]]]);
    }
}
