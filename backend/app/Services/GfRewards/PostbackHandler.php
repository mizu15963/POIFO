<?php

namespace App\Services\GfRewards;

use App\Models\Campaign;
use App\Models\Conversion;
use App\Models\PointTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * GF Rewards の成果通知（ポストバック）を処理する。
 *
 * - hash（HMAC-SHA256）を検証する
 * - cv_id で重複・状態遷移を管理する（未承認 → 承認／否認。確定後の変更は受け付けない）
 * - 未承認は「判定中」、承認で「確定」、否認で「否認」としてポイント台帳に反映する
 * - 会員・案件・成果地点が見つからない、またはポイント数が案件APIで取得済みの値と合わない通知は
 *   「保留」にしてポイントを確定しない（判定中のまま。管理者が確認して扱いを決める）
 *
 * 応答コード（ASP に返す本文）: 0 = ポイント未付与 / 1 = ポイント付与 / 2 = 未付与（退会ユーザー）
 */
class PostbackHandler
{
    public const RESPONSE_NOT_GRANTED = 0;

    public const RESPONSE_GRANTED = 1;

    public const RESPONSE_WITHDRAWN = 2;

    private const CV_STATUS_MAP = [
        0 => Conversion::STATUS_PENDING,
        1 => Conversion::STATUS_APPROVED,
        2 => Conversion::STATUS_REJECTED,
    ];

    public function verifyHash(array $params): bool
    {
        $key = (string) config('poifo.gf_rewards.api_key');
        $hash = $params['hash'] ?? null;

        if ($key === '' || ! is_string($hash)) {
            return false;
        }

        return hash_equals(self::hash($key, $params), strtolower($hash));
    }

    /** 「user_id:cv_action_id:cv_datetime」を API キーで HMAC-SHA256 した16進小文字 */
    public static function hash(string $key, array $params): string
    {
        $message = implode(':', [
            (string) ($params['user_id'] ?? ''),
            (string) ($params['cv_action_id'] ?? ''),
            (string) ($params['cv_datetime'] ?? ''),
        ]);

        return hash_hmac('sha256', $message, $key);
    }

    /**
     * ハッシュ検証済みのパラメータを処理する。
     *
     * @return array{result: string, response: int, hold_reason?: string}
     */
    public function handle(array $params): array
    {
        $cvId = (string) ($params['cv_id'] ?? '');
        $newStatus = self::CV_STATUS_MAP[(int) ($params['cv_status'] ?? -1)] ?? null;

        if ($cvId === '' || $newStatus === null || ! isset($params['user_id'])) {
            return ['result' => 'invalid_params', 'response' => self::RESPONSE_NOT_GRANTED];
        }

        $user = User::firstWhere('uid', (string) $params['user_id']);
        $points = max(0, (int) floor((float) ($params['incentive_amount'] ?? 0)));
        [$holdReason, $expectedPoints] = $user
            ? $this->checkAgainstCampaign($params, $points)
            : ['unknown_user', null];

        return DB::transaction(function () use ($params, $cvId, $newStatus, $user, $points, $holdReason, $expectedPoints) {
            $conversion = Conversion::query()
                ->where('provider', 'gf_rewards')
                ->where('cv_id', $cvId)
                ->lockForUpdate()
                ->first();

            if ($conversion && $conversion->status !== Conversion::STATUS_PENDING) {
                // 承認・否認が確定した成果への再通知（リトライなど）。何も変えずに前回と同じ結果を返す
                return [
                    'result' => 'duplicate',
                    'response' => $this->responseFor($conversion->status, $conversion->user, $conversion->isHeld()),
                ];
            }

            if ($conversion && $conversion->status === $newStatus) {
                return ['result' => 'duplicate', 'response' => $this->responseFor($newStatus, $conversion->user, $conversion->isHeld())];
            }

            $conversion ??= new Conversion(['provider' => 'gf_rewards', 'cv_id' => $cvId]);

            // 一度保留にした成果は、あとの通知で条件が合っても自動では解除しない（管理者が確認する）。
            // 否認はポイントが動かないので保留にしない
            $holdReason = $conversion->hold_reason
                ?? ($newStatus === Conversion::STATUS_REJECTED ? null : $holdReason);

            $conversion->fill([
                'user_id' => $user?->id,
                'external_user_id' => (string) $params['user_id'],
                'external_campaign_id' => $params['campaign_id'] ?? null,
                'campaign_name' => $params['campaign_name'] ?? null,
                'cv_action_id' => $params['cv_action_id'] ?? null,
                'cv_action_name' => $params['cv_action_name'] ?? null,
                'status' => $newStatus,
                'hold_reason' => $holdReason,
                'held_at' => $holdReason ? ($conversion->held_at ?? now()) : null,
                'points' => $points,
                'expected_points' => $expectedPoints ?? $conversion->expected_points,
                'net_cost' => isset($params['net_cost']) ? (float) $params['net_cost'] : null,
                'cv_at' => self::parseDate($params['cv_datetime'] ?? null),
                'authenticated_at' => self::parseDate($params['cv_authenticated_datetime'] ?? null),
                'last_payload' => $params,
            ])->save();

            if (! $user) {
                return ['result' => 'unknown_user', 'response' => self::RESPONSE_NOT_GRANTED, 'hold_reason' => 'unknown_user'];
            }

            if ($user->status === User::STATUS_WITHDRAWN) {
                return ['result' => 'withdrawn_user', 'response' => self::RESPONSE_WITHDRAWN];
            }

            $this->syncPointTransaction($conversion, $user);

            if ($conversion->isHeld()) {
                return ['result' => 'held', 'response' => self::RESPONSE_NOT_GRANTED, 'hold_reason' => $conversion->hold_reason];
            }

            return [
                'result' => match ($newStatus) {
                    Conversion::STATUS_APPROVED => 'granted',
                    Conversion::STATUS_PENDING => 'pending',
                    Conversion::STATUS_REJECTED => 'rejected',
                },
                'response' => $this->responseFor($newStatus, $user),
            ];
        });
    }

    /**
     * 管理者が確認した保留を解除する。ASP 側の状態（承認なら確定、未承認なら判定中、否認なら否認）を台帳に反映する。
     * 会員が見つからない・退会済みの成果は付与先がないので解除できない。
     *
     * @return array{conversion: Conversion, transaction_status: string} 解除後の成果と台帳の状態
     *
     * @throws \RuntimeException 解除できないとき
     */
    public function releaseHold(int $conversionId): array
    {
        return DB::transaction(function () use ($conversionId) {
            $conversion = Conversion::query()->lockForUpdate()->find($conversionId);

            if (! $conversion) {
                throw new \RuntimeException('成果が見つかりません。');
            }
            if (! $conversion->isHeld()) {
                throw new \RuntimeException('この成果は保留になっていません。');
            }

            $user = $conversion->user_id ? User::query()->lockForUpdate()->find($conversion->user_id) : null;
            if (! $user) {
                throw new \RuntimeException('付与先の会員が見つからないため解除できません。');
            }
            if ($user->status === User::STATUS_WITHDRAWN) {
                throw new \RuntimeException('退会済みの会員の成果は解除できません。');
            }

            $conversion->forceFill(['hold_reason' => null])->save();
            $this->syncPointTransaction($conversion, $user);

            return [
                'conversion' => $conversion,
                'transaction_status' => $conversion->pointTransaction()->value('status'),
            ];
        });
    }

    /**
     * 通知の案件・成果地点・ポイント数を、案件APIで取得済みの情報と突き合わせる。
     *
     * @return array{0: ?string, 1: ?int} [保留の理由（問題なければ null）, 案件情報のポイント数（定額案件のみ）]
     */
    private function checkAgainstCampaign(array $params, int $points): array
    {
        $campaign = Campaign::query()
            ->where('provider', 'gf_rewards')
            ->where('external_id', (string) ($params['campaign_id'] ?? ''))
            ->first();

        if (! $campaign) {
            return ['unknown_campaign', null];
        }

        $action = collect($campaign->conversion_actions ?? [])
            ->first(fn ($a) => is_array($a) && (string) ($a['conversion_action_id'] ?? '') === (string) ($params['cv_action_id'] ?? ''));

        if (! $action) {
            return ['unknown_action', null];
        }

        $amount = $action['incentive']['amount'] ?? null;

        // 定額案件: 成果地点のポイント数と一致すること
        if ($amount !== null) {
            $expected = (int) floor((float) $amount);

            return [$expected === $points ? null : 'amount_mismatch', $expected];
        }

        // 定率案件: 金額が決まっていないので、上限があれば超えていないことだけ確かめる
        $limit = $action['incentive_cost_limit'] ?? null;

        return [$limit !== null && $points > (int) $limit ? 'amount_over_limit' : null, null];
    }

    private function syncPointTransaction(Conversion $conversion, User $user): void
    {
        $status = match ($conversion->status) {
            // 保留中は承認の通知が来ても確定しない（判定中のまま）
            Conversion::STATUS_APPROVED => $conversion->isHeld() ? PointTransaction::STATUS_PENDING : PointTransaction::STATUS_CONFIRMED,
            Conversion::STATUS_PENDING => PointTransaction::STATUS_PENDING,
            Conversion::STATUS_REJECTED => PointTransaction::STATUS_REJECTED,
        };

        $transaction = PointTransaction::firstOrNew(['conversion_id' => $conversion->id]);
        $transaction->fill([
            'user_id' => $user->id,
            'type' => PointTransaction::TYPE_CONVERSION,
            'status' => $status,
            'points' => $conversion->points,
            'description' => trim(($conversion->campaign_name ?? '').' '.($conversion->cv_action_name ?? '')) ?: null,
            'confirmed_at' => $status === PointTransaction::STATUS_CONFIRMED ? now() : null,
        ])->save();
    }

    /**
     * ASP に返す本文。承認でポイントを確定したときだけ 1（付与）。判定中・否認・保留は 0、退会者は 2。
     * 停止中の会員の成果も台帳には記録する（調査のあとで運営が扱いを決める）。
     */
    private function responseFor(string $status, ?User $user, bool $held = false): int
    {
        if (! $user) {
            return self::RESPONSE_NOT_GRANTED;
        }

        if ($user->status === User::STATUS_WITHDRAWN) {
            return self::RESPONSE_WITHDRAWN;
        }

        return $status === Conversion::STATUS_APPROVED && ! $held ? self::RESPONSE_GRANTED : self::RESPONSE_NOT_GRANTED;
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
