<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件の特集の印（機能仕様 4.3、2026-11-27 トップの案件メニュー）。
 * はじめて向け・ゲーム案件特集・みんなで挑戦中を管理画面で案件に付ける。ASP の取り込みでは触らない。
 * 特集を増やしても列を足さずに済むよう、案件ごとに印の行を持つ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 32);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['campaign_id', 'tag']);
            // 特集ごとの一覧で引く
            $table->index('tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_tags');
    }
};
