<?php

use App\Services\GfRewards\CampaignSynchronizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// 案件の取得（GF Rewards 案件連携API は1時間60回まで。ここでは15分ごと）
Artisan::command('gf:sync-campaigns', function (CampaignSynchronizer $sync) {
    $result = $sync->sync();
    $this->info("案件を同期しました: 更新 {$result['upserted']} 件 / 掲載終了 {$result['removed']} 件");
})->purpose('GF Rewards の案件一覧を取得して campaigns テーブルに保存する');

Schedule::command('gf:sync-campaigns')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
