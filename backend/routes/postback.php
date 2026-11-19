<?php

use App\Http\Controllers\PostbackController;
use Illuminate\Support\Facades\Route;

// https://api.<domain>/postback/gf-rewards をGF Rewards の管理画面に登録する
// （/postback/ 配下は WAF で ASP の送信元 IP だけを許可している）
Route::match(['get', 'post'], '/gf-rewards', [PostbackController::class, 'gfRewards']);
