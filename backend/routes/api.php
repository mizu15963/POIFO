<?php

use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExchangeMethodController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Middleware\EnsureMemberIsActive;
use Illuminate\Support\Facades\Route;

// 会員向け API（https://api.<domain>/v1/...）。フロントは fetch(..., { credentials: 'include' }) で呼ぶ
Route::prefix('v1')->middleware('throttle:member-api')->group(function () {
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::get('/campaign-categories', [CampaignController::class, 'categories']);
    Route::get('/events', [EventController::class, 'index']);
    // ポイントの交換先と条件（docs/specs/functional-spec.md 7章）
    Route::get('/exchange-methods', [ExchangeMethodController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show']);
    // お知らせ（ログインしなくても見られる。docs/specs/functional-spec.md 11.1）
    Route::get('/news', [NewsController::class, 'index']);
    Route::get('/news/{news}', [NewsController::class, 'show']);
    // 紹介URLのコードが使えるか（紹介ページ S26。総当たりを抑えるため IP 単位でも絞る）
    Route::get('/referral/codes/{code}', [ReferralController::class, 'check'])->middleware('throttle:referral-check');

    Route::middleware(['auth:sanctum', EnsureMemberIsActive::class])->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/me/points', [MeController::class, 'pointHistory']);
        // 案件の利用履歴（マイページの通帳のタブ。docs/specs/functional-spec.md 3.3）
        Route::get('/me/clicks', [MeController::class, 'clicks']);
        // お知らせ一覧を開いた日時（未読の数を0にする）
        Route::post('/me/news/seen', [NewsController::class, 'seen']);

        // 友達紹介（docs/specs/functional-spec.md 15章）
        Route::get('/me/referral', [ReferralController::class, 'show']);
        Route::get('/me/referral/rewards', [ReferralController::class, 'rewards']);

        // お気に入り（docs/specs/functional-spec.md 4.5）
        Route::get('/me/favorites', [FavoriteController::class, 'index']);
        Route::put('/me/favorites/{campaign}', [FavoriteController::class, 'store'])->middleware('throttle:favorite-update');
        Route::delete('/me/favorites/{campaign}', [FavoriteController::class, 'destroy'])->middleware('throttle:favorite-update');
        Route::post('/logout', [MeController::class, 'logout']);
        // 退会（手続き中の換金申請があるときはできない。docs/specs/functional-spec.md 3.4）
        Route::post('/me/leave', [MeController::class, 'leave'])->middleware('throttle:withdrawal-request');

        // 振込先口座と換金申請（更新系は CSRF トークンが必要。フロントは withCsrf() を通す）。
        // 回数制限は会員単位（AppServiceProvider::configureRateLimiting）
        Route::get('/me/bank-account', [BankAccountController::class, 'show']);
        Route::put('/me/bank-account', [BankAccountController::class, 'update'])->middleware('throttle:bank-account-update');
        Route::get('/me/withdrawals', [WithdrawalController::class, 'index']);

        // イベントへの参加・取り消し（docs/specs/functional-spec.md 8.2）
        Route::post('/events/{event}/participation', [EventController::class, 'join'])->middleware('throttle:event-participation');
        Route::delete('/events/{event}/participation', [EventController::class, 'cancel'])->middleware('throttle:event-participation');
        Route::post('/me/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:withdrawal-request');
    });
});
