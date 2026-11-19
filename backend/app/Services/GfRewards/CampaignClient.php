<?php

namespace App\Services\GfRewards;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GF Rewards 案件連携API。1時間あたり60回までなので、スケジューラから定期的に呼んで DB に保存する。
 */
class CampaignClient
{
    /** @return list<array<string, mixed>> */
    public function fetchAll(): array
    {
        $apiKey = config('poifo.gf_rewards.api_key');
        $channel = config('poifo.gf_rewards.ad_channel_id');

        if (! $apiKey || ! $channel) {
            throw new RuntimeException('GF_REWARDS_API_KEY と GF_REWARDS_AD_CHANNEL_ID を設定してください。');
        }

        $response = Http::withHeaders(['api-key' => $apiKey])
            ->acceptJson()
            ->timeout(30)
            ->get(config('poifo.gf_rewards.campaigns_url'), ['ad_channel_id' => $channel]);

        if (! $response->successful() || (int) $response->json('code') !== 200) {
            throw new RuntimeException('GF Rewards campaigns API failed: HTTP '.$response->status().' code '.$response->json('code'));
        }

        return $response->json('data') ?? [];
    }
}
