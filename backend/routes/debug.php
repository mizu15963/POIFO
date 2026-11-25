<?php

use App\Http\Controllers\Auth\DebugLoginController;
use Illuminate\Support\Facades\Route;

// 開発の確認中だけ（POIFO_DEBUG_LOGIN_ENABLED=true のときだけ bootstrap/app.php から読み込む）。
// LINE を通さずにデバッグ用の会員でログインする: https://api.<domain>/auth/debug/login?user=tester
Route::get('/auth/debug/login', [DebugLoginController::class, 'login'])->name('auth.debug.login');
