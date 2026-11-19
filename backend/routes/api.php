<?php

use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

// 会員向け API（https://api.<domain>/v1/...）。フロントは fetch(..., { credentials: 'include' }) で呼ぶ
Route::prefix('v1')->group(function () {
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/{event}', [EventController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/me/points', [MeController::class, 'pointHistory']);
        Route::post('/logout', [MeController::class, 'logout']);
    });
});
