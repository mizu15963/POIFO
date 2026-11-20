<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberWithdrawalApiTest extends TestCase
{
    use RefreshDatabase;

    private array $bank = [
        'bank_code' => '0001',
        'bank_name' => 'みずほ銀行',
        'branch_code' => '001',
        'branch_name' => '東京営業部',
        'account_type' => 'ordinary',
        'account_number' => '123456',
        'account_holder_kana' => 'ﾔﾏﾀﾞ ﾀﾛｳ',
        'holder_name' => '山田 太郎',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['poifo.points.min_withdrawal_points' => 1000]);
    }

    public function test_member_registers_bank_account_and_sees_it_masked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->asApiHost()->putJson('/v1/me/bank-account', $this->bank)
            ->assertOk()
            ->assertJsonPath('data.account_number_masked', '****456')
            ->assertJsonPath('data.account_holder_kana', 'ヤマダ タロウ')
            ->assertJsonMissingPath('data.account_number');

        // 7桁に0埋めして保存（暗号化）
        $this->assertSame('0123456', $user->bankAccount->account_number);

        $this->actingAs($user)->asApiHost()->getJson('/v1/me/bank-account')
            ->assertOk()->assertJsonPath('data.holder_name', '山田 太郎');
    }

    public function test_invalid_bank_input_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->asApiHost()
            ->putJson('/v1/me/bank-account', array_merge($this->bank, ['bank_code' => '12', 'account_holder_kana' => 'Yamada']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bank_code', 'account_holder_kana']);
    }

    public function test_same_account_cannot_be_used_by_another_member(): void
    {
        BankAccount::register(User::factory()->create(), array_merge($this->bank, ['account_number' => '0123456']));

        $this->actingAs(User::factory()->create())->asApiHost()
            ->putJson('/v1/me/bank-account', $this->bank)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_number');
    }

    public function test_member_requests_withdrawal_and_sees_history(): void
    {
        $user = User::factory()->create();
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'confirmed', 'points' => 2500]);
        BankAccount::register($user, $this->bank);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 2000])
            ->assertCreated()->assertJsonPath('data.amount_yen', 2000);

        $this->actingAs($user)->asApiHost()->getJson('/v1/me/withdrawals')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'requested')
            ->assertJsonPath('meta.available_points', 500)
            ->assertJsonPath('meta.has_bank_account', true);
    }

    public function test_withdrawal_errors_are_returned_as_validation_errors(): void
    {
        $user = User::factory()->create();
        PointTransaction::create(['user_id' => $user->id, 'type' => 'conversion', 'status' => 'confirmed', 'points' => 2500]);

        $this->actingAs($user)->asApiHost()->postJson('/v1/me/withdrawals', ['points' => 2000])
            ->assertUnprocessable()
            ->assertJsonPath('errors.points.0', '振込先の口座を登録してください。');
    }

    public function test_withdrawal_api_requires_login(): void
    {
        $this->asApiHost()->getJson('/v1/me/withdrawals')->assertUnauthorized();
        $this->asApiHost()->putJson('/v1/me/bank-account', $this->bank)->assertUnauthorized();
    }
}
