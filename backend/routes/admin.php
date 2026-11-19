<?php

use Illuminate\Support\Facades\Route;

// 管理画面（https://admin.<domain>/）。WAF と nginx で IP 制限、ログインはパスワード＋二要素認証（これから実装）。
// 予定: 会員検索・停止、ポイント手動調整（理由必須・操作ログ）、換金申請の審査と全銀CSV出力、
//       ポストバック受信ログ、案件の表示設定（おすすめ・表示順・非表示）、イベント登録
Route::get('/', fn () => view('admin.home'))->name('admin.home');
