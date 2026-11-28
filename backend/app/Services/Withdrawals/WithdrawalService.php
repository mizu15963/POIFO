<?php

namespace App\Services\Withdrawals;

use App\Models\Admin;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Exchange\ExchangeMethod;
use App\Services\Exchange\ExchangeMethods;
use App\Services\Points\PointBalance;
use Illuminate\Support\Facades\DB;

/**
 * ポイント交換（換金）の共通の流れ。交換先（銀行振込など）ごとに違う部分は ExchangeMethodHandler に分けている。
 *
 *   申請（requested）── 承認 ──> 承認済み（approved）── 処理後 ──> 完了（paid）
 *        └──────────── 却下（rejected）<────┘   ※却下するとポイントを戻す
 *
 * ポイントは申請の時点で台帳から引く（二重に申請・利用されないように）。
 * 銀行振込は月1回、管理者が申請を確認して CSV を出し、人の手で振り込む。
 */
class WithdrawalService
{
    public function __construct(private PointBalance $balance, private ExchangeMethods $methods) {}

    /** 会員の交換申請（会員向け画面・API から使う）。交換先を省略したら銀行振込 */
    public function request(User $user, int $points, string $method = ExchangeMethod::BANK_TRANSFER): WithdrawalRequest
    {
        return DB::transaction(function () use ($user, $points, $method) {
            // 同じ会員の同時申請で残高を超えないよう、会員の行をロックしてから残高を見る
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if (! $user->isActive()) {
                throw new WithdrawalException('現在このアカウントでは換金できません。');
            }

            // 換金の連絡を送れるよう、確認済みのメールアドレスが必要（機能仕様 3.5）
            if (! $user->hasVerifiedEmail()) {
                throw new WithdrawalException('ポイント交換の申請には、メールアドレスの確認が必要です。登録情報から確認してください。', 'email');
            }

            $exchange = $this->methods->acceptingOrFail($method);
            // 受け取り情報が未登録なら、ここで例外になる
            $recipient = $exchange->handler->recipientSnapshot($user);

            $exchange->assertPoints($points);

            if ($points > $this->balance->for($user)['available']) {
                throw new WithdrawalException('利用可能なポイントが足りません。');
            }

            // 申請した時点のレート・手数料・受け取り情報を申請に残す（あとで設定を変えても影響しない）
            $amountYen = $exchange->amountYen($points);

            $request = WithdrawalRequest::create([
                'user_id' => $user->id,
                'exchange_method' => $exchange->code,
                'points' => $points,
                'yen_per_point' => $exchange->yenPerPoint,
                'amount_yen' => $amountYen,
                'fee_yen' => $exchange->feeYen($amountYen),
                'status' => WithdrawalRequest::STATUS_REQUESTED,
                'recipient_snapshot' => $recipient,
                'reviewable_after' => now()->addDays($exchange->reviewDays),
            ]);

            PointTransaction::create([
                'user_id' => $user->id,
                'type' => PointTransaction::TYPE_WITHDRAWAL,
                'status' => PointTransaction::STATUS_CONFIRMED,
                'points' => -$points,
                'description' => "ポイント交換（{$exchange->shortName}）",
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
                'description' => 'ポイント交換の取り消し（返却）',
                'withdrawal_request_id' => $r->id,
                'admin_id' => $admin->id,
                'confirmed_at' => now(),
            ]);
        });
    }

    /** 交換先ごとの方法で処理し終えたあとに記録する（銀行振込なら振り込んだあと） */
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
}
