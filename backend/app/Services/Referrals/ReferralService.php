<?php

namespace App\Services\Referrals;

use App\Models\Campaign;
use App\Models\Conversion;
use App\Models\PointTransaction;
use App\Models\ReferralRank;
use App\Models\ReferralReward;
use App\Models\ReferralSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 友達紹介（docs/specs/functional-spec.md 15章）。
 *
 * - 紹介コードの発行と、新規登録のときの紹介元のひもづけ
 * - 友達の「案件の成果」の台帳の状態（判定中・確定・否認）に合わせた紹介報酬
 * - 初回特典（友達が登録から決まった日数のうちに、決まったポイント以上確定したら一度だけ）
 * - ランク（有効な紹介の人数から毎回計算。特別パートナーは運営が付ける）
 *
 * 紹介報酬の台帳の行は ReferralReward と組で持ち、ここ以外で作らない・状態を変えない。
 */
class ReferralService
{
    /** 紛らわしい 0・O・1・I は使わない */
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    public static function isValidCodeFormat(?string $code): bool
    {
        return is_string($code) && preg_match('/^['.self::CODE_CHARS.']{'.self::CODE_LENGTH.'}$/', $code) === 1;
    }

    public static function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /** 紹介コード（なければ発行する） */
    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        return DB::transaction(function () use ($user) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $locked->referral_code) {
                do {
                    $code = '';
                    for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                        $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
                    }
                } while (User::where('referral_code', $code)->exists());
                $locked->forceFill(['referral_code' => $code])->save();
            }
            $user->referral_code = $locked->referral_code;

            return $locked->referral_code;
        });
    }

    public function inviteUrl(string $code): string
    {
        return config('poifo.front_url').'/invite/?code='.$code;
    }

    /** 紹介に使えるコードの持ち主（存在しない・退会・停止中・紹介を無効にされた会員は null） */
    public function findReferrer(?string $code): ?User
    {
        $code = self::normalizeCode($code);
        if (! self::isValidCodeFormat($code)) {
            return null;
        }

        $user = User::firstWhere('referral_code', $code);

        return $user && $user->isActive() && $user->referral_disabled_at === null ? $user : null;
    }

    /**
     * 新しく会員になったときだけ紹介元を記録する。すでに紹介元がある・自分のコードのときは何もしない。
     *
     * @return bool 記録したか
     */
    public function attachOnRegistration(User $newUser, ?string $code): bool
    {
        $referrer = $this->findReferrer($code);
        if (! $referrer || $referrer->id === $newUser->id || $newUser->referred_by_user_id !== null) {
            return false;
        }

        $newUser->forceFill(['referred_by_user_id' => $referrer->id])->save();

        return true;
    }

    /** ランク（特別パートナー以外は必要な人数の少ない順） */
    public function generalRanks(): Collection
    {
        return ReferralRank::query()->where('code', '!=', ReferralRank::PARTNER)
            ->orderBy('min_valid_referrals')->orderBy('sort_order')->get();
    }

    public function partnerRank(): ?ReferralRank
    {
        return ReferralRank::firstWhere('code', ReferralRank::PARTNER);
    }

    /**
     * 有効な紹介の人数（紹介した友達のうち、初回特典の条件を満たした人。退会・停止中の人は数えない）。
     * 運営が紹介を無効にした友達（サブアカウントでの自己紹介など）も数えない。
     */
    public function validReferralCount(User $user): int
    {
        return ReferralReward::query()
            ->where('referral_rewards.referrer_user_id', $user->id)
            ->where('referral_rewards.type', ReferralReward::TYPE_INITIAL)
            ->join('users', 'users.id', '=', 'referral_rewards.referee_user_id')
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereNull('users.referral_disabled_at')
            ->join('point_transactions', 'point_transactions.id', '=', 'referral_rewards.point_transaction_id')
            ->where('point_transactions.status', PointTransaction::STATUS_CONFIRMED)
            ->count();
    }

    /**
     * 今のランク。
     *
     * @return array{code: string, name: string, rate_bps: int, is_partner: bool, valid_referrals: int, next: ?array{code: string, name: string, rate_bps: int, min_valid_referrals: int, remaining: int}}
     */
    public function rankFor(User $user): array
    {
        $valid = $this->validReferralCount($user);

        if ($user->referral_partner_rate !== null) {
            $partner = $this->partnerRank();
            $cap = $partner?->rateBps() ?? PHP_INT_MAX;

            return [
                'code' => ReferralRank::PARTNER,
                'name' => $partner?->name ?? '特別パートナー',
                'rate_bps' => min(ReferralRank::toBps($user->referral_partner_rate), $cap),
                'is_partner' => true,
                'valid_referrals' => $valid,
                'next' => null,
            ];
        }

        $ranks = $this->generalRanks();
        $current = $ranks->filter(fn (ReferralRank $r) => $r->min_valid_referrals <= $valid)->last() ?? $ranks->first();
        $next = $ranks->first(fn (ReferralRank $r) => $r->min_valid_referrals > $valid);

        return [
            'code' => $current?->code ?? 'none',
            'name' => $current?->name ?? '-',
            'rate_bps' => $current?->rateBps() ?? 0,
            'is_partner' => false,
            'valid_referrals' => $valid,
            'next' => $next ? [
                'code' => $next->code,
                'name' => $next->name,
                'rate_bps' => $next->rateBps(),
                'min_valid_referrals' => $next->min_valid_referrals,
                'remaining' => $next->min_valid_referrals - $valid,
            ] : null,
        ];
    }

    /**
     * 成果の台帳の行に合わせて紹介報酬を付ける・状態をそろえる。成果を処理したトランザクションの中で呼ぶ。
     * 確定・否認になった紹介報酬は変えない（率は確定した時点のランクで決める）。
     */
    public function syncForConversion(Conversion $conversion): void
    {
        $source = $conversion->pointTransaction()->first();
        $referee = $conversion->user_id ? User::find($conversion->user_id) : null;
        if (! $source || ! $referee || ! $referee->referred_by_user_id) {
            return;
        }

        // 紹介した人の行をロックして、同じ人への報酬（ランクの計算・初回特典）を順番に処理する
        $referrer = User::query()->lockForUpdate()->find($referee->referred_by_user_id);
        if (! $referrer) {
            return;
        }

        $reward = ReferralReward::query()
            ->where('conversion_id', $conversion->id)
            ->where('type', ReferralReward::TYPE_ONGOING)
            ->lockForUpdate()
            ->first();
        $transaction = $reward?->point_transaction_id ? PointTransaction::find($reward->point_transaction_id) : null;

        if ($transaction && $transaction->status !== PointTransaction::STATUS_PENDING) {
            return;
        }

        $campaign = Campaign::query()
            ->where('provider', $conversion->provider)
            ->where('external_id', (string) $conversion->external_campaign_id)
            ->first();

        $eligible = $referrer->isActive()
            && $referrer->referral_disabled_at === null
            && $referee->referral_disabled_at === null
            && ! ($campaign?->referral_excluded ?? false);

        $status = $eligible ? $source->status : PointTransaction::STATUS_REJECTED;

        $rank = $this->rankFor($referrer);
        $rateBps = $rank['rate_bps'];
        if ($campaign?->referral_rate_cap !== null) {
            $rateBps = min($rateBps, ReferralRank::toBps($campaign->referral_rate_cap));
        }
        $base = max(0, (int) $source->points);
        // 1P 未満は切り捨て（整数で計算する）
        $points = intdiv($base * $rateBps, 10000);

        if (! $transaction && ($status === PointTransaction::STATUS_REJECTED || $points <= 0)) {
            // 対象外・0P のものは行を作らない
            return;
        }

        $transaction ??= new PointTransaction(['user_id' => $referrer->id, 'type' => PointTransaction::TYPE_REFERRAL]);
        $transaction->fill([
            'status' => $status,
            'points' => $points,
            'description' => '紹介した友達の成果（'.ReferralRank::formatBps($rateBps).'%）',
            'confirmed_at' => $status === PointTransaction::STATUS_CONFIRMED ? now() : null,
        ])->save();

        $reward ??= new ReferralReward([
            'referrer_user_id' => $referrer->id,
            'referee_user_id' => $referee->id,
            'type' => ReferralReward::TYPE_ONGOING,
            'conversion_id' => $conversion->id,
        ]);
        $reward->fill([
            'base_points' => $base,
            'rate' => $rateBps / 100,
            'rank' => $rank['code'],
            'rank_name' => $rank['name'],
            'points' => $points,
            'point_transaction_id' => $transaction->id,
        ])->save();

        if ($source->status === PointTransaction::STATUS_CONFIRMED) {
            $this->grantInitialBonusIfReached($referrer, $referee);
        }
    }

    /** 友達の確定した「案件の成果」の合計が、登録から決まった日数のうちに条件に届いたら初回特典を一度だけ付ける */
    private function grantInitialBonusIfReached(User $referrer, User $referee): void
    {
        if (! $referrer->isActive() || $referrer->referral_disabled_at !== null || $referee->referral_disabled_at !== null) {
            return;
        }

        $exists = ReferralReward::query()
            ->where('referee_user_id', $referee->id)
            ->where('type', ReferralReward::TYPE_INITIAL)
            ->exists();
        if ($exists) {
            return;
        }

        $settings = ReferralSetting::current();
        $deadline = $referee->created_at->copy()->addDays($settings->initial_days);
        if (now()->greaterThan($deadline) || $settings->initial_bonus_points <= 0) {
            return;
        }

        $total = (int) PointTransaction::query()
            ->where('user_id', $referee->id)
            ->where('type', PointTransaction::TYPE_CONVERSION)
            ->where('status', PointTransaction::STATUS_CONFIRMED)
            ->where('confirmed_at', '<=', $deadline)
            ->sum('points');
        if ($total < $settings->initial_threshold_points) {
            return;
        }

        $rank = $this->rankFor($referrer);
        $transaction = PointTransaction::create([
            'user_id' => $referrer->id,
            'type' => PointTransaction::TYPE_REFERRAL_INITIAL,
            'status' => PointTransaction::STATUS_CONFIRMED,
            'points' => $settings->initial_bonus_points,
            'description' => '紹介した友達が条件を達成',
            'confirmed_at' => now(),
        ]);
        ReferralReward::create([
            'referrer_user_id' => $referrer->id,
            'referee_user_id' => $referee->id,
            'type' => ReferralReward::TYPE_INITIAL,
            'base_points' => $total,
            'rank' => $rank['code'],
            'rank_name' => $rank['name'],
            'points' => $settings->initial_bonus_points,
            'point_transaction_id' => $transaction->id,
        ]);
    }

    /**
     * 管理者が紹介を無効にする。この会員が紹介した人・紹介された人のどちらとしても、
     * それ以降の報酬は付かず、判定中の報酬は否認にする。
     *
     * @return int 否認にした判定中の報酬の件数
     */
    public function disable(User $user): int
    {
        return DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->referral_disabled_at !== null) {
                throw new ReferralException('すでに紹介を無効にしています。');
            }
            $user->forceFill(['referral_disabled_at' => now()])->save();

            $ids = ReferralReward::query()
                ->where(fn ($q) => $q->where('referrer_user_id', $user->id)->orWhere('referee_user_id', $user->id))
                ->whereNotNull('point_transaction_id')
                ->pluck('point_transaction_id');

            return PointTransaction::query()
                ->whereIn('id', $ids)
                ->where('status', PointTransaction::STATUS_PENDING)
                ->update(['status' => PointTransaction::STATUS_REJECTED, 'updated_at' => now()]);
        });
    }

    /** 管理者が紹介の無効を取り消す（否認にした報酬は戻さない） */
    public function enable(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->referral_disabled_at === null) {
                throw new ReferralException('紹介は無効になっていません。');
            }
            $user->forceFill(['referral_disabled_at' => null])->save();
        });
    }

    /** 特別パートナーにする（率 %）・外す（null）。率は特別パートナーのランクの上限まで */
    public function setPartnerRate(User $user, ?string $rate): void
    {
        if ($rate !== null) {
            $cap = $this->partnerRank()?->rateBps() ?? 10000;
            $bps = ReferralRank::toBps($rate);
            if ($bps < 0 || $bps > $cap) {
                throw new ReferralException('特別パートナーの率は0〜'.ReferralRank::formatBps($cap).'%で指定してください。');
            }
            $rate = (string) ($bps / 100);
        }

        $user->forceFill(['referral_partner_rate' => $rate])->save();
    }

    /**
     * 会員向けの集計（紹介ページ S25）。
     *
     * @return array{total: int, valid: int, this_month: int, confirmed_this_month: int, pending: int}
     */
    public function summaryFor(User $user, int $validCount): array
    {
        $monthStart = now()->startOfMonth();
        $ledger = fn () => PointTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('type', [PointTransaction::TYPE_REFERRAL, PointTransaction::TYPE_REFERRAL_INITIAL]);

        return [
            'total' => User::where('referred_by_user_id', $user->id)->count(),
            'valid' => $validCount,
            'this_month' => User::where('referred_by_user_id', $user->id)->where('created_at', '>=', $monthStart)->count(),
            'confirmed_this_month' => (int) $ledger()->where('status', PointTransaction::STATUS_CONFIRMED)->where('confirmed_at', '>=', $monthStart)->sum('points'),
            'pending' => (int) $ledger()->where('status', PointTransaction::STATUS_PENDING)->sum('points'),
        ];
    }

    /** 紹介ランクの新しいコード（管理画面で追加したランク用。変えない・使い回さない） */
    public static function newRankCode(): string
    {
        do {
            $code = 'r'.Str::lower(Str::random(7));
        } while (ReferralRank::where('code', $code)->exists());

        return $code;
    }
}
