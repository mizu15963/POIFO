<?php

use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\WithdrawalController;
use Illuminate\Support\Facades\Route;

// 会員向け API（https://api.<domain>/v1/...）。フロントは fetch(..., { credentials: 'include' }) で呼ぶ
Route::prefix('v1')->middleware('throttle:member-api')->group(function () {
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/me/points', [MeController::class, 'pointHistory']);
        Route::post('/logout', [MeController::class, 'logout']);

        // 振込先口座と換金申請（更新系は CSRF トークンが必要。フロントは withCsrf() を通す）。
        // 回数制限は会員単位（AppServiceProvider::configureRateLimiting）
        Route::get('/me/bank-account', [BankAccountController::class, 'show']);
        Route::put('/me/bank-account', [BankAccountController::class, 'update'])->middleware('throttle:bank-account-update');
        Route::get('/me/withdrawals', [WithdrawalController::class, 'index']);
        Route::post('/me/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:withdrawal-request');
    });
});
