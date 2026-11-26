<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Withdrawals\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\NoRecipientExchangeHandler;
use Tests\TestCase;

/**
 * 交換先を増やせる作り（docs/specs/functional-spec.md 7.5）。
 * 銀行振込だけのあいだは、会員の画面・API の使い方が変わらないこと。
 */
class ExchangeMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'poifo.exchange_methods.bank_transfer.min_points' => 1000,
            'poifo.exchange_methods.bank_transfer.yen_per_point' => 1,
            'poifo.exchange_methods.bank_transfer.review_days' => 7,
        ]);
    }

    /** テスト用の2つ目の交換先を足す */
    private function addGiftMethod(array $overrides = []): void
    {
        config(['poifo.exchange_methods.gift' => array_merge([
            'name' => 'テスト用ギフト券',
            'short_name' => 'ギフト券',
            'description' => 'テスト用',
            'icon' => null,
            'status' => 'open',
            'sort' => 2,
            'min_points' => 500,
            'step_points' => 500,
            'max_points' => 3000,
            'yen_per_point' => 0.5,
            'fee' => ['type' => 'fixed', 'amount_yen' => 100],
            'review_days' => 0,
            'handler' => NoRecipientExchangeHandler::class,
        ], $overrides)]);
    }

    private function member(int $points = 5000, bool $withBank = true): User
    {
        $user = User::factory()->create();
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'confirmed', 'points' => $points]);

        if ($withBank) {
            BankAccount::register($user, [
                'bank_code' => '0001', 'bank_name' => 'みずほ銀行', 'branch_code' => '001', 'branch_name' => '東京営業部',
                'account_type' => 'ordinary', 'account_number' => '0123456', 'account_holder_kana' => 'ﾔﾏﾀﾞ ﾀﾛｳ', 'holder_name' => '山田 太郎',
            ]);
        }

        return $user;
    }

    public function test_exchange_methods_lists_bank_transfer_with_current_conditions(): void
    {
        $this->asApiHost()->getJson('/v1/exchange-methods')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'bank_transfer')
            ->assertJsonPath('data.0.name', '銀行振込（現金）')
            ->assertJsonPath('data.0.category', '現金')
            ->assertJsonPath('data.0.delivery_note', '月1回まとめて振込')
            ->assertJsonPath('data.0.accepting', true)
            ->assertJsonPath('data.0.min_points', 1000)
            ->assertJsonPath('data.0.step_points', 1)
            ->assertJsonPath('data.0.max_points', null)
            ->assertJsonPath('data.0.fee.type', 'none')
            ->assertJsonPath('data.0.review_days', 7)
            ->assertJsonPath('data.0.requires_recipient', true);
    }

    public function test_exchange_methods_are_sorted_and_hidden_ones_are_left_out(): void
    {
        $this->addGiftMethod(['sort' => 0, 'status' => 'suspended']);
        config(['poifo.exchange_methods.secret' => array_merge(config('poifo.exchange_methods.gift'), ['status' => 'hidden'])]);

        $this->asApiHost()->getJson('/v1/exchange-methods')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'gift')
            ->assertJsonPath('data.0.accepting', false)
            ->assertJsonPath('data.1.code', 'bank_transfer');
    }

    public function test_request_without_method_is_bank_transfer_and_keeps_the_same_api(): void
    {
        $user = $this->member(5000);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 3000])
            ->assertCreated()
            ->assertJsonPath('data.points', 3000)
            ->assertJsonPath('data.amount_yen', 3000)
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.exchange_method', 'bank_transfer');

        $request = WithdrawalRequest::sole();
        $this->assertSame('bank_transfer', $request->exchange_method);
        $this->assertSame(1.0, $request->yen_per_point);
        $this->assertSame('0123456', $request->recipient_snapshot['account_number']);
        $this->assertDatabaseHas('point_transactions', [
            'withdrawal_request_id' => $request->id, 'points' => -3000, 'description' => 'ポイント交換（銀行振込）',
        ]);

        // 履歴: 既存の項目はそのまま、交換先を足す
        $this->actingAs($user)->asApiHost()->getJson('/v1/me/withdrawals')
            ->assertOk()
            ->assertJsonPath('data.0.amount_yen', 3000)
            ->assertJsonPath('data.0.exchange_method', 'bank_transfer')
            ->assertJsonPath('data.0.exchange_method_name', '銀行振込（現金）')
            ->assertJsonPath('meta.min_points', 1000)
            ->assertJsonPath('meta.yen_per_point', 1)
            ->assertJsonPath('meta.has_bank_account', true)
            ->assertJsonPath('meta.available_points', 2000);

        // ポイント履歴から交換先をたどれる
        $this->actingAs($user)->asApiHost()->getJson('/v1/me/points')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'withdrawal')
            ->assertJsonPath('data.0.exchange_method', 'bank_transfer')
            ->assertJsonPath('data.0.exchange_method_name', '銀行振込（現金）')
            ->assertJsonPath('data.1.exchange_method', null);
    }

    public function test_bank_transfer_still_needs_a_bank_account_and_the_minimum(): void
    {
        $user = $this->member(5000, withBank: false);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 3000])
            ->assertUnprocessable()->assertJsonValidationErrors(['points' => '振込先の口座を登録してください。']);

        $user = $this->member(5000);
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 999, 'method' => 'bank_transfer'])
            ->assertUnprocessable()->assertJsonValidationErrors(['points' => '換金できるのは 1,000 ポイントからです。']);
    }

    public function test_another_method_uses_its_own_conditions_and_snapshots_rate_and_fee(): void
    {
        $this->addGiftMethod();
        $user = $this->member(5000, withBank: false);

        // 刻み・上限はその交換先の条件で見る
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 700, 'method' => 'gift'])
            ->assertUnprocessable()->assertJsonValidationErrors(['points' => '500 ポイント単位で申請してください。']);
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 3500, 'method' => 'gift'])
            ->assertUnprocessable()->assertJsonValidationErrors(['points' => '1回に申請できるのは 3,000 ポイントまでです。']);

        // 受け取り情報が要らない交換先は、口座がなくても申請できる。レート 0.5円・手数料 100円
        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 1000, 'method' => 'gift'])
            ->assertCreated()
            ->assertJsonPath('data.exchange_method', 'gift')
            ->assertJsonPath('data.amount_yen', 400);

        $request = WithdrawalRequest::sole();
        $this->assertSame(500, $request->amount_yen);
        $this->assertSame(100, $request->fee_yen);
        $this->assertTrue($request->isReviewable());
        $this->assertDatabaseHas('point_transactions', ['withdrawal_request_id' => $request->id, 'description' => 'ポイント交換（ギフト券）']);

        // あとで条件を変えても、出た申請には影響しない
        config(['poifo.exchange_methods.gift.yen_per_point' => 2]);
        $this->assertSame(500, $request->fresh()->amount_yen);
        $this->assertSame(0.5, $request->fresh()->yen_per_point);

        // ほかの交換先を足しても、銀行振込の条件は変わらない
        $this->asApiHost()->getJson('/v1/exchange-methods')
            ->assertJsonPath('data.0.code', 'bank_transfer')
            ->assertJsonPath('data.0.min_points', 1000)
            ->assertJsonPath('data.0.yen_per_point', 1);
    }

    public function test_suspended_hidden_or_unknown_method_cannot_be_requested(): void
    {
        $this->addGiftMethod(['status' => 'suspended']);
        config(['poifo.exchange_methods.secret' => array_merge(config('poifo.exchange_methods.gift'), ['status' => 'hidden'])]);
        $user = $this->member(5000);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 1000, 'method' => 'gift'])
            ->assertUnprocessable()->assertJsonValidationErrors(['method' => '「テスト用ギフト券」は現在受付を停止しています。']);

        foreach (['secret', 'nope'] as $method) {
            $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 1000, 'method' => $method])
                ->assertUnprocessable()->assertJsonValidationErrors(['method' => 'この交換先は選べません。']);
        }

        $this->assertSame(0, WithdrawalRequest::count());
        $this->assertSame(5000, (int) PointTransaction::where('user_id', $user->id)->sum('points'));
    }

    public function test_requests_in_progress_finish_after_the_method_is_suspended(): void
    {
        $this->addGiftMethod();
        $user = $this->member(5000, withBank: false);
        $request = app(WithdrawalService::class)->request($user, 1000, 'gift');

        config(['poifo.exchange_methods.gift.status' => 'suspended']);
        $admin = Admin::factory()->create();
        app(WithdrawalService::class)->approve($request, $admin);
        app(WithdrawalService::class)->markPaid($request, $admin);

        $this->assertSame(WithdrawalRequest::STATUS_PAID, $request->fresh()->status);
    }

    public function test_admin_list_filters_by_method_and_csv_has_bank_transfer_only(): void
    {
        $this->addGiftMethod();
        $this->travelTo(now()->startOfMonth()->addDays(1));
        $bankRequest = app(WithdrawalService::class)->request($this->member(5000), 3000);
        $giftRequest = app(WithdrawalService::class)->request($this->member(5000, withBank: false), 1000, 'gift');
        $month = $bankRequest->created_at->format('Y-m');
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')->asAdminHost()->get("/withdrawals?month={$month}")
            ->assertOk()->assertSee('銀行振込（現金）')->assertSee('テスト用ギフト券')->assertSee('山田 太郎');

        $this->actingAs($admin, 'admin')->asAdminHost()->get("/withdrawals?month={$month}&method=gift")
            ->assertOk()->assertSee('テスト用ギフト券')->assertDontSee('山田 太郎');

        $csv = $this->actingAs($admin, 'admin')->asAdminHost()
            ->get("/withdrawals/export?month={$month}&status=all")
            ->assertOk()->streamedContent();

        $this->assertStringContainsString("\r\n{$bankRequest->id},", $csv);
        $this->assertStringNotContainsString("\r\n{$giftRequest->id},", $csv);
    }
}
