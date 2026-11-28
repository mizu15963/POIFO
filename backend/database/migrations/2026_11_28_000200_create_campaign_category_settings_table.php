<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * カテゴリの表示設定（機能仕様 4.7、2026-11-28 ハンバーガーメニューのデザイン更新）。
 * ASP のカテゴリ名は長いので、メニューのボタンに出す短い表示名・並び・出すかどうかを管理画面（A18）で決める。
 * カテゴリそのものは ASP の値のまま（案件の行は変えない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_category_settings', function (Blueprint $table) {
            $table->id();
            // campaigns.category と同じ長さ
            $table->string('category', 64)->unique();
            $table->string('label', 32)->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('in_menu')->default(true);
            $table->timestamps();
        });

        // 見本（brand/redesign/menu/）の短い名前を初期値にする。管理画面で変えられる
        $now = now();
        $defaults = [
            'アプリインストール' => 'アプリ',
            'アプリ複数条件達成' => 'アプリ条件達成',
            'クレジットカード（申込・発券）' => 'カード発行',
            '口座開設' => '口座開設',
            '商品購入（定率）' => '買い物',
            '有料会員登録（再登録不可）' => '有料登録',
            '無料会員登録' => '無料登録',
            '見積もり/査定' => '見積もり・査定',
            '資料請求' => '資料請求',
        ];
        DB::table('campaign_category_settings')->insert(array_map(
            fn ($category, $label) => ['category' => $category, 'label' => $label, 'sort_order' => null, 'in_menu' => true, 'created_at' => $now, 'updated_at' => $now],
            array_keys($defaults),
            $defaults,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_category_settings');
    }
};
