<?php

namespace App\Services\Alerts;

use App\Mail\AdminAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 管理者にメールで異常を知らせる。
 * 同じ種類は一定時間に1通だけ送る（判定は共有のキャッシュで行うので、サーバが複数台でも1通）。
 */
class AdminAlerter
{
    /** @param array<string, scalar|null> $details */
    public function notify(string $kind, string $title, array $details = []): void
    {
        Log::warning('Admin alert: '.$kind, $details);

        $to = config('poifo.alert_emails');
        if ($to === []) {
            return;
        }

        $interval = max(1, (int) config('poifo.alert_interval_seconds'));
        if (! Cache::add('admin-alert:'.$kind, true, $interval)) {
            return;
        }

        try {
            Mail::to($to)->queue(new AdminAlert($title, $details));
        } catch (Throwable $e) {
            // 通知に失敗しても成果通知の処理は止めない
            Log::error('Failed to queue admin alert', ['kind' => $kind, 'exception' => $e]);
        }
    }
}
