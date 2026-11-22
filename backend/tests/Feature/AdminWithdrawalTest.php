<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Points\PointBalance;
use App\Services\Withdrawals\WithdrawalException;
use App\Services\Withdrawals\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::factory()->create();
        config(['poifo.exchange_methods.bank_transfer.min_points' => 1000, 'poifo.exchange_methods.bank_transfer.review_days' => 7]);
    }

    /** 口座登録済み・ポイントありの会員 */
    private function member(int $points = 5000, array $bank = []): User
    {
        $user = User::factory()->create();
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'confirmed', 'points' => $points]);
        BankAccount::register($user, array_merge([
            'bank_code' => '0001',
            'bank_name' => 'みずほ銀行',
            'branch_code' => '001',
            'branch_name' => '東京営業部',
            'account_type' => 'ordinary',
            'account_number' => '0123456',
            'account_holder_kana' => 'ﾔﾏﾀﾞ ﾀﾛｳ',
            'holder_name' => '山田 太郎',
        ], $bank));

        return $user;
    }

    private function service(): WithdrawalService
    {
        return app(WithdrawalService::class);
    }

    public function test_request_deducts_points_and_snapshots_the_bank_account(): void
    {
        $user = $this->member(5000);

        $request = $this->service()->request($user, 3000);

        $this->assertSame(2000, app(PointBalance::class)->for($user)['available']);
        $this->assertSame(3000, $request->amount_yen);
        $this->assertSame('0123456', $request->recipient_snapshot['account_number']);
        $this->assertSame('ヤマダ タロウ', $request->recipient_snapshot['account_holder_kana']);
        // 口座番号は DB には暗号化して入っている
        $this->assertDatabaseMissing('bank_accounts', ['account_number' => '0123456']);
    }

    public function test_request_over_balance_or_below_minimum_is_refused(): void
    {
        $user = $this->member(1500);

        $this->expectException(WithdrawalException::class);
        $this->service()->request($user, 2000);
    }

    public function test_reject_returns_points_and_approve_respects_review_period(): void
    {
        $user = $this->member(5000);
        $request = $this->service()->request($user, 3000);

        try {
            $this->service()->approve($request, $this->admin);
            $this->fail('審査期間中は承認できないはず');
        } catch (WithdrawalException) {
        }

        $this->service()->reject($request, $this->admin, '本人確認できず');

        $this->assertSame(5000, app(PointBalance::class)->for($user)['available']);
        $this->assertSame(WithdrawalRequest::STATUS_REJECTED, $request->fresh()->status);
    }

    public function test_admin_can_list_approve_export_and_mark_paid(): void
    {
        $user = $this->member(5000);
        $this->travelTo(now()->startOfMonth()->addDays(1));
        $request = $this->service()->request($user, 3000);
        $this->travel(8)->days();

        $month = $request->created_at->format('Y-m');

        $this->actingAs($this->admin, 'admin')->asAdminHost()
            ->get("/withdrawals?month={$month}")
            ->assertOk()->assertSee('山田 太郎')->assertSee('0123456');

        $this->actingAs($this->admin, 'admin')->asAdminHost()
            ->post('/withdrawals/bulk', ['action' => 'approve', 'ids' => [$request->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame(WithdrawalRequest::STATUS_APPROVED, $request->fresh()->status);

        $csv = $this->actingAs($this->admin, 'admin')->asAdminHost()
            ->get("/withdrawals/export?month={$month}&status=approved")
            ->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF申請ID,", $csv);
        // 口座番号・コードは先頭の 0 を保ったまま出る
        $this->assertStringContainsString('"山田 太郎","ヤマダ タロウ",0001,みずほ銀行,001,東京営業部,普通,0123456,3000,3000,0,3000,', $csv);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'withdrawal.export', 'admin_id' => $this->admin->id]);

        $this->actingAs($this->admin, 'admin')->asAdminHost()
            ->post('/withdrawals/bulk', ['action' => 'paid', 'ids' => [$request->id]]);
        $this->assertSame(WithdrawalRequest::STATUS_PAID, $request->fresh()->status);
        $this->assertSame($this->admin->id, $request->fresh()->paid_by);
    }

    public function test_csv_neutralizes_formula_like_values(): void
    {
        $user = $this->member(5000, ['holder_name' => '=HYPERLINK("https://evil.example","x")', 'bank_name' => '+SUM(1,1)', 'branch_name' => '@cmd']);
        $request = $this->service()->request($user, 3000);

        $csv = $this->actingAs($this->admin, 'admin')->asAdminHost()
            ->get('/withdrawals/export?status=all&month='.$request->created_at->format('Y-m'))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('"\'=HYPERLINK(""https://evil.example"",""x"")"', $csv);
        $this->assertStringContainsString("'+SUM(1,1)", $csv);
        $this->assertStringContainsString("'@cmd", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function test_paid_request_cannot_be_rejected(): void
    {
        $user = $this->member(5000);
        $request = $this->service()->request($user, 3000);
        $this->travel(8)->days();
        $this->service()->approve($request, $this->admin);
        $this->service()->markPaid($request, $this->admin);

        $this->actingAs($this->admin, 'admin')->asAdminHost()
            ->post('/withdrawals/bulk', ['action' => 'reject', 'ids' => [$request->id]])
            ->assertSessionHasErrors();

        $this->assertSame(WithdrawalRequest::STATUS_PAID, $request->fresh()->status);
        $this->assertSame(2000, app(PointBalance::class)->for($user)['available']);
    }

    public function test_same_account_or_holder_name_on_another_member_is_flagged(): void
    {
        $user = $this->member(5000);
        $this->member(0, ['bank_code' => '0005', 'account_number' => '9999999']); // 名義が同じ
        $request = $this->service()->request($user, 3000);

        $this->actingAs($this->admin, 'admin')->asAdminHost()
            ->get('/withdrawals?month='.$request->created_at->format('Y-m'))
            ->assertSee('同じ口座名義の会員がいます');
    }

    public function test_withdrawal_pages_are_not_reachable_from_api_host(): void
    {
        $this->actingAs($this->admin, 'admin')->asApiHost()->get('/withdrawals/export')->assertNotFound();
    }
}
