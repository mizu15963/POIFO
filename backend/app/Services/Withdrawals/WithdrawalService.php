<?php

namespace App\Services\Withdrawals;

use App\Models\Admin;
use App\Models\BankAccount;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Points\PointBalance;
use Illuminate\Support\Facades\DB;

/**
 * 換金（銀行振込）。当面は月1回、管理者が申請を確認して CSV を出し、人の手で振り込む。
 *
 *   申請（requested）── 承認 ──> 承認済み（approved）── 振込後 ──> 振込済み（paid）
 *        └──────────── 却下（rejected）<────┘   ※却下するとポイントを戻す
 *
 * ポイントは申請の時点で台帳から引く（二重に申請・利用されないように）。
 */
class WithdrawalService
{
    public function __construct(private PointBalance $balance) {}

    /** 会員の換金申請（会員向け画面・API から使う） */
    public function request(User $user, int $points): WithdrawalRequest
    {
        return DB::transaction(function () use ($user, $points) {
            // 同じ会員の同時申請で残高を超えないよう、会員の行をロックしてから残高を見る
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $user->isActive()) {
                throw new WithdrawalException('現在このアカウントでは換金できません。');
            }

            $bank = $user->bankAccount;

            if (! $bank) {
                throw new WithdrawalException('振込先の口座を登録してください。');
            }

            if ($points < config('poifo.points.min_withdrawal_points')) {
                throw new WithdrawalException('換金できるのは '.number_format(config('poifo.points.min_withdrawal_points')).' ポイントからです。');
            }

            if ($points > $this->balance->for($user)['available']) {
                throw new WithdrawalException('利用可能なポイントが足りません。');
            }

            $request = WithdrawalRequest::create([
                'user_id' => $user->id,
                'points' => $points,
                'amount_yen' => (int) floor($points * config('poifo.points.yen_per_point')),
                'fee_yen' => 0,
                'status' => WithdrawalRequest::STATUS_REQUESTED,
                'bank_snapshot' => self::snapshot($bank),
                'reviewable_after' => now()->addDays(config('poifo.points.withdrawal_review_days')),
            ]);

            PointTransaction::create([
                'user_id' => $user->id,
                'type' => PointTransaction::TYPE_WITHDRAWAL,
                'status' => PointTransaction::STATUS_CONFIRMED,
                'points' => -$points,
                'description' => '換金申請（銀行振込）',
                'withdrawal_request_id' => $request->id,
                'confirmed_at' => now(),
            ]);

            return $request;
        });
    }

    public function approve(WithdrawalRequest $request, Admin $admin): void
    {
        $this->transition($request, [WithdrawalRequest::STATUS_REQUESTED], function (WithdrawalRequest $r) use ($admin) {
            if (! $r->isReviewable()) {
                throw new WithdrawalException("申請 #{$r->id} は審査期間中（{$r->reviewable_after->format('n/j H:i')} まで）のため承認できません。");
            }

            $r->update(['status' => WithdrawalRequest::STATUS_APPROVED, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        });
    }

    public function reject(WithdrawalRequest $request, Admin $admin, ?string $note = null): void
    {
        $this->transition($request, [WithdrawalRequest::STATUS_REQUESTED, WithdrawalRequest::STATUS_APPROVED], function (WithdrawalRequest $r) use ($admin, $note) {
            $r->update([
                'status' => WithdrawalRequest::STATUS_REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            // 申請時に引いたポイントを戻す
            PointTransaction::create([
                'user_id' => $r->user_id,
                'type' => PointTransaction::TYPE_WITHDRAWAL_REFUND,
                'status' => PointTransaction::STATUS_CONFIRMED,
                'points' => $r->points,
                'description' => '換金申請の取り消し（ポイントを戻しました）',
                'withdrawal_request_id' => $r->id,
                'admin_id' => $admin->id,
                'confirmed_at' => now(),
            ]);
        });
    }

    /** 銀行で振り込んだあとに記録する */
    public function markPaid(WithdrawalRequest $request, Admin $admin): void
    {
        $this->transition($request, [WithdrawalRequest::STATUS_APPROVED], function (WithdrawalRequest $r) use ($admin) {
            $r->update(['status' => WithdrawalRequest::STATUS_PAID, 'paid_at' => now(), 'paid_by' => $admin->id]);
        });
    }

    /** @param list<string> $from */
    private function transition(WithdrawalRequest $request, array $from, callable $apply): void
    {
        DB::transaction(function () use ($request, $from, $apply) {
            $locked = WithdrawalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, $from, true)) {
                throw new WithdrawalException("申請 #{$locked->id} は「{$locked->statusLabel()}」のため、この操作はできません。");
            }

            $apply($locked);
            $request->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** 申請時点の口座情報（あとで口座が変更されても、振り込む先は申請時のもの） */
    public static function snapshot(BankAccount $bank): array
    {
        return [
            'holder_name' => $bank->holder_name,
            'account_holder_kana' => $bank->account_holder_kana,
            'bank_code' => $bank->bank_code,
            'bank_name' => $bank->bank_name,
            'branch_code' => $bank->branch_code,
            'branch_name' => $bank->branch_name,
            'account_type' => $bank->account_type,
            'account_number' => $bank->account_number,
            'account_fingerprint' => $bank->account_fingerprint,
            'holder_name_key' => $bank->holder_name_key,
        ];
    }
}
