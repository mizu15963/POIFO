<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // イベント（みんなでポイ活）。参加管理はしない。参加ボタンは LINE オープンチャットへ遷移するだけ
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('format', 64)->nullable();       // オンライン など
            $table->string('thumbnail_url', 1024)->nullable();
            $table->string('openchat_url', 1024)->nullable();
            $table->text('notes')->nullable();              // 注意事項
            $table->string('status', 16)->default('draft'); // draft / published / ended
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
