<?php

use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Middleware\EnsureMemberIsActive;
use Illuminate\Support\Facades\Route;

// 会員向け API（https://api.<domain>/v1/...）。フロントは fetch(..., { credentials: 'include' }) で呼ぶ
Route::prefix('v1')->middleware('throttle:member-api')->group(function () {
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::get('/campaign-categories', [CampaignController::class, 'categories']);
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show']);

    Route::middleware(['auth:sanctum', EnsureMemberIsActive::class])->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/me/points', [MeController::class, 'pointHistory']);
        Route::post('/logout', [MeController::class, 'logout']);
        // 退会（手続き中の換金申請があるときはできない。docs/specs/admin-and-member-spec.md 6章）
        Route::post('/me/leave', [MeController::class, 'leave'])->middleware('throttle:withdrawal-request');

        // 振込先口座と換金申請（更新系は CSRF トークンが必要。フロントは withCsrf() を通す）。
        // 回数制限は会員単位（AppServiceProvider::configureRateLimiting）
        Route::get('/me/bank-account', [BankAccountController::class, 'show']);
        Route::put('/me/bank-account', [BankAccountController::class, 'update'])->middleware('throttle:bank-account-update');
        Route::get('/me/withdrawals', [WithdrawalController::class, 'index']);

        // イベントへの参加・取り消し（docs/specs/admin-and-member-spec.md 3.1）
        Route::post('/events/{event}/participation', [EventController::class, 'join'])->middleware('throttle:event-participation');
        Route::delete('/events/{event}/participation', [EventController::class, 'cancel'])->middleware('throttle:event-participation');
        Route::post('/me/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:withdrawal-request');
    });
});
