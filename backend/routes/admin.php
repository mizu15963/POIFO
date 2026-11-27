<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\NewsController;
use App\Http\Controllers\Admin\PostbackController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Models\PostbackLog;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// 管理画面（https://admin.<domain>/）。WAF と nginx で IP 制限、ログインはパスワード＋二要素認証。
// 機能の仕様は docs/specs/functional-spec.md 9章、画面は docs/specs/screen-spec.md 4章
Route::model('member', User::class);
Route::model('log', PostbackLog::class);

Route::name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/two-factor/challenge', [AuthController::class, 'showChallenge'])->name('two-factor.challenge');
    Route::post('/two-factor/challenge', [AuthController::class, 'challenge']);
    Route::get('/two-factor/setup', [AuthController::class, 'showSetup'])->name('two-factor.setup');
    Route::post('/two-factor/setup', [AuthController::class, 'setup']);

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', HomeController::class)->name('home');

        // 換金申請（月末に確認 → CSV を出して銀行で振り込む → 振込済みにする）
        Route::get('/withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::post('/withdrawals/bulk', [WithdrawalController::class, 'bulk'])->name('withdrawals.bulk');
        Route::get('/withdrawals/export', [WithdrawalController::class, 'export'])->name('withdrawals.export');

        // 案件の表示設定（中身は ASP から取り込むので変えない）
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
        // 動作確認用のダミー案件（機能仕様 4.6、公開前に消す）
        Route::post('/campaigns/dummies', [CampaignController::class, 'storeDummies'])->name('campaigns.dummies.store');
        Route::delete('/campaigns/dummies', [CampaignController::class, 'destroyDummies'])->name('campaigns.dummies.destroy');

        // イベントと参加者
        Route::resource('events', EventController::class);
        Route::post('/events/{event}/participants/check', [EventController::class, 'check'])->name('events.check');
        Route::get('/events/{event}/participants/export', [EventController::class, 'export'])->name('events.export');

        // お知らせ（docs/specs/functional-spec.md 11.1）
        Route::resource('news', NewsController::class)->except(['show'])->parameters(['news' => 'news']);

        // 会員
        Route::get('/members', [MemberController::class, 'index'])->name('members.index');
        Route::get('/members/{member}', [MemberController::class, 'show'])->name('members.show');
        Route::post('/members/{member}/suspend', [MemberController::class, 'suspend'])->name('members.suspend');
        Route::post('/members/{member}/resume', [MemberController::class, 'resume'])->name('members.resume');
        Route::post('/members/{member}/points', [MemberController::class, 'adjustPoints'])->name('members.points');

        // 成果通知の受信ログと保留
        Route::get('/postbacks', [PostbackController::class, 'index'])->name('postbacks.index');
        Route::get('/postbacks/{log}', [PostbackController::class, 'show'])->name('postbacks.show');
        Route::get('/conversions/held', [PostbackController::class, 'held'])->name('conversions.held');
        Route::post('/conversions/{conversion}/release', [PostbackController::class, 'release'])->name('conversions.release');
    });
});
