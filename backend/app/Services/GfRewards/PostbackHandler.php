<?php

namespace App\Services\GfRewards;

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
     * @return array{result: string, response: int}
     */
    public function handle(array $params): array
    {
        $cvId = (string) ($params['cv_id'] ?? '');
        $newStatus = self::CV_STATUS_MAP[(int) ($params['cv_status'] ?? -1)] ?? null;

        if ($cvId === '' || $newStatus === null || ! isset($params['user_id'])) {
            return ['result' => 'invalid_params', 'response' => self::RESPONSE_NOT_GRANTED];
        }

        $user = User::firstWhere('uid', (string) $params['user_id']);

        return DB::transaction(function () use ($params, $cvId, $newStatus, $user) {
            $conversion = Conversion::query()
                ->where('provider', 'gf_rewards')
                ->where('cv_id', $cvId)
                ->lockForUpdate()
                ->first();

            if ($conversion && $conversion->status !== Conversion::STATUS_PENDING) {
                // 承認・否認が確定した成果への再通知（リトライなど）。何も変えずに前回と同じ結果を返す
                return [
                    'result' => 'duplicate',
                    'response' => $this->responseFor($conversion->status, $conversion->user),
                ];
            }

            if ($conversion && $conversion->status === $newStatus) {
                return ['result' => 'duplicate', 'response' => $this->responseFor($newStatus, $conversion->user)];
            }

            $conversion ??= new Conversion(['provider' => 'gf_rewards', 'cv_id' => $cvId]);
            $conversion->fill([
                'user_id' => $user?->id,
                'external_user_id' => (string) $params['user_id'],
                'external_campaign_id' => $params['campaign_id'] ?? null,
                'campaign_name' => $params['campaign_name'] ?? null,
                'cv_action_id' => $params['cv_action_id'] ?? null,
                'cv_action_name' => $params['cv_action_name'] ?? null,
                'status' => $newStatus,
                'points' => max(0, (int) floor((float) ($params['incentive_amount'] ?? 0))),
                'net_cost' => isset($params['net_cost']) ? (float) $params['net_cost'] : null,
                'cv_at' => self::parseDate($params['cv_datetime'] ?? null),
                'authenticated_at' => self::parseDate($params['cv_authenticated_datetime'] ?? null),
                'last_payload' => $params,
            ])->save();

            if (! $user) {
                return ['result' => 'unknown_user', 'response' => self::RESPONSE_NOT_GRANTED];
            }

            if ($user->status === User::STATUS_WITHDRAWN) {
                return ['result' => 'withdrawn_user', 'response' => self::RESPONSE_WITHDRAWN];
            }

            $this->syncPointTransaction($conversion, $user);

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

    private function syncPointTransaction(Conversion $conversion, User $user): void
    {
        $status = match ($conversion->status) {
            Conversion::STATUS_APPROVED => PointTransaction::STATUS_CONFIRMED,
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
     * ASP に返す本文。承認でポイントを確定したときだけ 1（付与）。判定中・否認は 0、退会者は 2。
     * 停止中の会員の成果も台帳には記録する（調査のあとで運営が扱いを決める）。
     */
    private function responseFor(string $status, ?User $user): int
    {
        if (! $user) {
            return self::RESPONSE_NOT_GRANTED;
        }

        if ($user->status === User::STATUS_WITHDRAWN) {
            return self::RESPONSE_WITHDRAWN;
        }

        return $status === Conversion::STATUS_APPROVED ? self::RESPONSE_GRANTED : self::RESPONSE_NOT_GRANTED;
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
