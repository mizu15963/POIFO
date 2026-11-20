<?php

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Services\GfRewards\CampaignSynchronizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

// 案件の取得（GF Rewards 案件連携API は1時間60回まで。ここでは15分ごと）
Artisan::command('gf:sync-campaigns', function (CampaignSynchronizer $sync) {
    $result = $sync->sync();
    $this->info("案件を同期しました: 更新 {$result['upserted']} 件 / 掲載終了 {$result['removed']} 件");
})->purpose('GF Rewards の案件一覧を取得して campaigns テーブルに保存する');

Schedule::command('gf:sync-campaigns')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// 管理者の追加（最初の1人もこれで作る）。パスワードは対話で入力し、コマンド履歴に残さない
Artisan::command('admin:create {email} {name}', function (string $email, string $name) {
    if (Admin::where('email', Str::lower($email))->exists()) {
        $this->error('このメールアドレスの管理者は既にいます。');

        return 1;
    }

    $password = $this->secret('パスワード（12文字以上）');

    if (mb_strlen((string) $password) < 12 || $password !== $this->secret('もう一度入力')) {
        $this->error('パスワードが短いか、一致しません。');

        return 1;
    }

    Admin::create(['email' => Str::lower($email), 'name' => $name, 'password' => $password, 'is_active' => true]);
    $this->info('管理者を作成しました。初回ログイン時に二要素認証を登録します。');
})->purpose('管理画面の管理者を追加する');

// スマホ紛失などで二要素認証をやり直すとき。次回ログイン時に登録画面が出る
Artisan::command('admin:reset-two-factor {email}', function (string $email) {
    $admin = Admin::firstWhere('email', Str::lower($email));

    if (! $admin) {
        $this->error('管理者が見つかりません。');

        return 1;
    }

    $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_timestep' => null])->save();
    AdminAuditLog::create(['admin_id' => $admin->id, 'action' => 'two_factor.reset_by_cli', 'target_type' => 'admin', 'target_id' => $admin->id]);
    $this->info('二要素認証をリセットしました。');
})->purpose('管理者の二要素認証をリセットする');
