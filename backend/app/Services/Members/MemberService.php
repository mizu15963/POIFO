<?php

namespace App\Services\Members;

use App\Models\Admin;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\Points\PointBalance;
use Illuminate\Support\Facades\DB;

/**
 * 会員の停止・再開・ポイントの手動調整・退会（docs/specs/functional-spec.md 3.2・3.4・5.2）。
 * 会員の status はここだけで変える。
 */
class MemberService
{
    public const MAX_ADJUSTMENT = 100000;

    public function __construct(private PointBalance $balance) {}

    public function suspend(User $user, string $reason): void
    {
        DB::transaction(function () use ($user, $reason) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->status !== User::STATUS_ACTIVE) {
                throw new MemberException('有効な会員だけ停止できます。');
            }

            $user->forceFill(['status' => User::STATUS_SUSPENDED, 'suspended_at' => now(), 'suspended_reason' => $reason])->save();
        });
    }

    public function resume(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->status !== User::STATUS_SUSPENDED) {
                throw new MemberException('停止中の会員だけ再開できます。');
            }

            $user->forceFill(['status' => User::STATUS_ACTIVE, 'suspended_at' => null, 'suspended_reason' => null])->save();
        });
    }

    /** 管理者によるポイントの付与（正）・減算（負）。すぐに確定する */
    public function adjustPoints(User $user, Admin $admin, int $points, string $reason): PointTransaction
    {
        if ($points === 0 || abs($points) > self::MAX_ADJUSTMENT) {
            throw new MemberException('調整できるのは ±1〜'.number_format(self::MAX_ADJUSTMENT).' ポイントです。');
        }

        return DB::transaction(function () use ($user, $admin, $points, $reason) {
            // 換金申請と同時に減算しても残高がマイナスにならないよう、会員の行をロックしてから残高を見る
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->status === User::STATUS_WITHDRAWN) {
                throw new MemberException('退会済みの会員のポイントは調整できません。');
            }
            if ($points < 0 && $this->balance->for($user)['available'] + $points < 0) {
                throw new MemberException('利用可能ポイントがマイナスになるため減算できません。');
            }

            return PointTransaction::create([
                'user_id' => $user->id,
                'type' => PointTransaction::TYPE_ADJUSTMENT,
                'status' => PointTransaction::STATUS_CONFIRMED,
                'points' => $points,
                'description' => $reason,
                'admin_id' => $admin->id,
                'confirmed_at' => now(),
            ]);
        });
    }

    /**
     * 会員の退会。手続き中の換金申請があるときはできない。
     * 残りのポイントは失効扱い（台帳はそのまま残し、退会者には使わせない）。LINE のユーザーID と口座は不正対策のため残す。
     */
    public function leave(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->status === User::STATUS_WITHDRAWN) {
                return;
            }

            $inProgress = WithdrawalRequest::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [WithdrawalRequest::STATUS_REQUESTED, WithdrawalRequest::STATUS_APPROVED])
                ->exists();

            if ($inProgress) {
                throw new MemberException('手続き中の換金申請があるため退会できません。振込が終わってからもう一度お試しください。');
            }

            $user->forceFill([
                'status' => User::STATUS_WITHDRAWN,
                'withdrawn_at' => now(),
                'display_name' => null,
                'avatar_url' => null,
                'remember_token' => null,
            ])->save();
        });
    }
}
