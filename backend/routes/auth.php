<?php

use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Auth\LineLoginController;
use Illuminate\Support\Facades\Route;

// api.<domain> で受ける。LINE Developers のコールバックURLには https://api.<domain>/auth/line/callback を登録する
Route::get('/auth/line/redirect', [LineLoginController::class, 'redirect'])->name('auth.line.redirect');
Route::get('/auth/line/callback', [LineLoginController::class, 'callback'])->name('auth.line.callback');

// 案件への遷移（ブラウザのリンクで開く）。計測リンクに会員IDを付けてリダイレクトする。未ログインならフロントのログイン画面へ
Route::get('/go/{campaign}', [CampaignController::class, 'click'])->middleware('auth')->name('campaigns.go');
