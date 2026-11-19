<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\HomeController;
use App\Http\Controllers\Admin\WithdrawalController;
use Illuminate\Support\Facades\Route;

// 管理画面（https://admin.<domain>/）。WAF と nginx で IP 制限、ログインはパスワード＋二要素認証。
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
    });
});
