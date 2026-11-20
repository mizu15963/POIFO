<?php

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Conversion;
use App\Services\GfRewards\CampaignSynchronizer;
use App\Services\GfRewards\PostbackHandler;
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

// 保留中の成果（ポイントを確定していない成果通知）の一覧。管理画面ができるまでの運用用
Artisan::command('postback:held', function () {
    $rows = Conversion::query()->whereNotNull('hold_reason')->orderBy('id')->get()
        ->map(fn (Conversion $c) => [
            $c->id,
            $c->cv_id,
            $c->user_id ?? '-',
            $c->external_campaign_id.' / '.$c->cv_action_id,
            $c->status,
            $c->points,
            $c->expected_points ?? '-',
            Conversion::HOLD_REASONS[$c->hold_reason] ?? $c->hold_reason,
            $c->held_at?->format('Y-m-d H:i'),
        ]);

    $this->table(['ID', 'cv_id', '会員ID', '案件 / 成果地点', '状態', '通知ポイント', '案件情報のポイント', '保留の理由', '保留日時'], $rows);
})->purpose('保留中の成果を一覧する');

// 管理者が確認した保留を解除し、ASP 側の状態を台帳に反映する（承認済みならポイントを確定）。操作は監査ログに残す
Artisan::command('postback:release {conversion : 成果のID（postback:held の ID 列）} {--by= : 確認した管理者のメールアドレス}', function (PostbackHandler $handler) {
    $admin = Admin::firstWhere('email', Str::lower((string) $this->option('by')));
    if (! $admin) {
        $this->error('--by に管理者のメールアドレスを指定してください。');

        return 1;
    }

    $conversion = Conversion::find((int) $this->argument('conversion'));
    if (! $conversion) {
        $this->error('成果が見つかりません。');

        return 1;
    }

    $this->table(['cv_id', '会員ID', '状態', '通知ポイント', '案件情報のポイント', '保留の理由'], [[
        $conversion->cv_id, $conversion->user_id ?? '-', $conversion->status, $conversion->points,
        $conversion->expected_points ?? '-', Conversion::HOLD_REASONS[$conversion->hold_reason] ?? ($conversion->hold_reason ?? '-'),
    ]]);

    if (! $this->confirm('この成果の保留を解除しますか（承認済みなら通知ポイントで確定します）', false)) {
        return 1;
    }

    $reason = $conversion->hold_reason;

    try {
        $result = $handler->releaseHold($conversion->id);
    } catch (RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    AdminAuditLog::create([
        'admin_id' => $admin->id,
        'action' => 'conversion.release_hold_by_cli',
        'target_type' => 'conversion',
        'target_id' => $conversion->id,
        'payload' => ['hold_reason' => $reason, 'points' => $conversion->points, 'transaction_status' => $result['transaction_status']],
    ]);

    $this->info('保留を解除しました（台帳: '.$result['transaction_status'].'）。');
})->purpose('保留中の成果を確認のうえ解除する');
