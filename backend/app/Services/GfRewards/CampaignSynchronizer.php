<?php

namespace App\Services\GfRewards;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 案件連携API の結果を campaigns テーブルに反映する。
 * API に含まれなくなった案件は external_status = 'removed' にして一覧から外す（行は残す）。
 */
class CampaignSynchronizer
{
    public function __construct(private CampaignClient $client) {}

    /** @return array{upserted: int, removed: int} */
    public function sync(): array
    {
        $items = $this->client->fetchAll();
        $now = now();
        $seen = [];

        DB::transaction(function () use ($items, $now, &$seen) {
            foreach ($items as $item) {
                if (empty($item['campaign_id']) || empty($item['click_url'])) {
                    continue;
                }

                $seen[] = (string) $item['campaign_id'];

                Campaign::updateOrCreate(
                    ['provider' => 'gf_rewards', 'external_id' => (string) $item['campaign_id']],
                    [
                        'name' => (string) ($item['name'] ?? ''),
                        'description' => $item['description'] ?? null,
                        'conversion_condition' => $item['conversion_condition'] ?? null,
                        'category' => $item['category'] ?? null,
                        'platform' => $item['platform'] ?? null,
                        'user_platforms' => $item['user_platform'] ?? [],
                        'icon_url' => $item['icon_url'] ?? null,
                        'click_url' => (string) $item['click_url'],
                        'revenue_type' => $item['revenue_type'] ?? null,
                        'max_points' => self::maxPoints($item['conversion_actions'] ?? []),
                        'external_status' => $item['status'] ?? null,
                        'starts_at' => self::date($item['start_time'] ?? null),
                        'ends_at' => self::date($item['end_time'] ?? null),
                        'conversion_actions' => $item['conversion_actions'] ?? [],
                        'raw' => $item,
                        'synced_at' => $now,
                    ],
                );
            }
        });

        $removed = Campaign::query()
            ->where('provider', 'gf_rewards')
            ->whereNotIn('external_id', $seen)
            ->where('external_status', '!=', 'removed')
            ->update(['external_status' => 'removed', 'synced_at' => $now]);

        return ['upserted' => count($seen), 'removed' => $removed];
    }

    /** 定額案件の成果地点のうち最大の付与ポイント。定率案件は null */
    private static function maxPoints(array $actions): ?int
    {
        $amounts = array_filter(array_map(fn ($a) => $a['incentive']['amount'] ?? null, $actions), fn ($v) => $v !== null);

        return $amounts ? (int) floor(max($amounts)) : null;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->setTimezone(config('app.timezone')) : null;
    }
}
