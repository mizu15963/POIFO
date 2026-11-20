<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\Withdrawals\WithdrawalService;
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

        // 換金申請の確認画面用のサンプル会員（口座登録・ポイントあり・申請済み）
        foreach ([['山田 太郎', 'ﾔﾏﾀﾞ ﾀﾛｳ', '1234567'], ['佐藤 花子', 'ｻﾄｳ ﾊﾅｺ', '7654321']] as $i => [$name, $kana, $number]) {
            $user = User::firstOrCreate(['line_user_id' => 'Usample'.$i], ['display_name' => 'サンプル会員'.($i + 1), 'status' => User::STATUS_ACTIVE]);

            if ($user->wasRecentlyCreated) {
                PointTransaction::create(['user_id' => $user->id, 'type' => 'adjustment', 'status' => 'confirmed', 'points' => 5000, 'description' => 'サンプル付与']);
                BankAccount::register($user, ['bank_code' => '0001', 'bank_name' => 'みずほ銀行', 'branch_code' => '001', 'branch_name' => '東京営業部', 'account_type' => 'ordinary', 'account_number' => $number, 'account_holder_kana' => $kana, 'holder_name' => $name]);
                app(WithdrawalService::class)->request($user, 3000);
            }
        }
    }
}
