<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Conversion;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 保留中の成果を管理者が確認して解除する（php artisan postback:release）
class ReleaseHeldConversionTest extends TestCase
{
    use RefreshDatabase;

    private function heldConversion(?User $user, string $status = Conversion::STATUS_APPROVED): Conversion
    {
        $conversion = Conversion::create([
            'cv_id' => 'cv-held-'.Conversion::count(),
            'user_id' => $user?->id,
            'external_user_id' => $user?->uid ?? 'unknown',
            'external_campaign_id' => 'gfr9',
            'cv_action_id' => 'gfr900',
            'status' => $status,
            'hold_reason' => 'unknown_campaign',
            'held_at' => now(),
            'points' => 500,
            'last_payload' => [],
        ]);

        if ($user) {
            PointTransaction::create([
                'user_id' => $user->id,
                'type' => PointTransaction::TYPE_CONVERSION,
                'status' => PointTransaction::STATUS_PENDING,
                'points' => 500,
                'conversion_id' => $conversion->id,
            ]);
        }

        return $conversion;
    }

    public function test_release_confirms_an_approved_conversion_and_is_audited(): void
    {
        $admin = Admin::factory()->create(['email' => 'ops@poifo.test']);
        $conversion = $this->heldConversion(User::factory()->create());

        $this->artisan('postback:release', ['conversion' => $conversion->id, '--by' => 'ops@poifo.test'])
            ->expectsConfirmation('この成果の保留を解除しますか（承認済みなら通知ポイントで確定します）', 'yes')
            ->assertSuccessful();

        $this->assertNull($conversion->fresh()->hold_reason);
        $this->assertDatabaseHas('point_transactions', ['conversion_id' => $conversion->id, 'status' => 'confirmed']);
        $this->assertNotNull(PointTransaction::firstWhere('conversion_id', $conversion->id)->confirmed_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id, 'action' => 'conversion.release_hold_by_cli', 'target_id' => $conversion->id,
        ]);
    }

    public function test_release_of_a_pending_conversion_keeps_it_pending_until_approval(): void
    {
        Admin::factory()->create(['email' => 'ops@poifo.test']);
        $conversion = $this->heldConversion(User::factory()->create(), Conversion::STATUS_PENDING);

        $this->artisan('postback:release', ['conversion' => $conversion->id, '--by' => 'ops@poifo.test'])
            ->expectsConfirmation('この成果の保留を解除しますか（承認済みなら通知ポイントで確定します）', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('point_transactions', ['conversion_id' => $conversion->id, 'status' => 'pending']);
    }

    public function test_release_is_refused_without_a_member_or_admin_or_confirmation(): void
    {
        Admin::factory()->create(['email' => 'ops@poifo.test']);
        $orphan = $this->heldConversion(null);
        $held = $this->heldConversion(User::factory()->create());

        $this->artisan('postback:release', ['conversion' => $orphan->id, '--by' => 'ops@poifo.test'])
            ->expectsConfirmation('この成果の保留を解除しますか（承認済みなら通知ポイントで確定します）', 'yes')
            ->expectsOutputToContain('会員が見つからない')
            ->assertFailed();

        $this->artisan('postback:release', ['conversion' => $held->id, '--by' => 'someone@poifo.test'])->assertFailed();

        $this->artisan('postback:release', ['conversion' => $held->id, '--by' => 'ops@poifo.test'])
            ->expectsConfirmation('この成果の保留を解除しますか（承認済みなら通知ポイントで確定します）', 'no')
            ->assertFailed();

        $this->assertSame('unknown_campaign', $orphan->fresh()->hold_reason);
        $this->assertSame('unknown_campaign', $held->fresh()->hold_reason);
        $this->assertDatabaseMissing('point_transactions', ['status' => 'confirmed']);
        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_held_list_shows_held_conversions(): void
    {
        $conversion = $this->heldConversion(User::factory()->create());

        $this->artisan('postback:held')->expectsOutputToContain($conversion->cv_id)->assertSuccessful();
    }
}
