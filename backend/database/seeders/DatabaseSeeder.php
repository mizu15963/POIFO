<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Event;
use Illuminate\Database\Seeder;

/**
 * ローカル開発用のサンプルデータ（php artisan db:seed）。本番では実行しない。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('本番ではサンプルデータを入れません。');

            return;
        }

        foreach ([['サンプル アプリインストール', 'アプリインストール', 300, true], ['サンプル 無料会員登録', '無料会員登録', 500, true], ['サンプル クレジットカード発行', 'クレジットカード（申込・発券）', 8000, false]] as $i => [$name, $category, $points, $featured]) {
            Campaign::updateOrCreate(['provider' => 'sample', 'external_id' => 'sample-'.$i], [
                'name' => $name,
                'category' => $category,
                'click_url' => 'https://example.com/click/sample-'.$i,
                'external_status' => 'started',
                'max_points' => $points,
                'is_featured' => $featured,
                'description' => 'ローカル開発用のサンプル案件です。',
                'conversion_condition' => '新規の方のみ対象。',
                'conversion_actions' => [['name' => $category, 'incentive' => ['amount' => $points], 'approve_conversion_limit_days' => 30]],
            ]);
        }

        Event::updateOrCreate(['title' => 'みんなでゲーム案件に挑戦（サンプル）'], [
            'summary' => 'オープンチャットでみんなで進めるイベントのサンプルです。',
            'starts_at' => now()->addDays(7)->setTime(20, 0),
            'ends_at' => now()->addDays(7)->setTime(21, 0),
            'format' => 'オンライン（LINEオープンチャット）',
            'openchat_url' => 'https://line.me/ti/g2/sample',
            'status' => Event::STATUS_PUBLISHED,
        ]);
    }
}
