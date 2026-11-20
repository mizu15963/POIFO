<?php

use App\Http\Controllers\PostbackController;
use App\Http\Middleware\VerifyPostbackSource;
use Illuminate\Support\Facades\Route;

// https://api.<domain>/postback/<POIFO_POSTBACK_PATH_TOKEN>/gf-rewards を GF Rewards の管理画面に登録する。
// /postback/ 配下は WAF で ASP の送信元 IP だけを許可し、アプリでも秘密の文字列と送信元 IP を確かめる
Route::match(['get', 'post'], '/{token}/gf-rewards', [PostbackController::class, 'gfRewards'])
    ->middleware(VerifyPostbackSource::class)
    ->where('token', '[A-Za-z0-9_-]{1,128}');
