<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 成果通知の内容が案件情報と合わないとき、ポイントを確定せずに保留にする（管理者が確認して扱いを決める）
        Schema::table('conversions', function (Blueprint $table) {
            $table->string('hold_reason', 32)->nullable()->after('status'); // unknown_user / unknown_campaign / unknown_action / amount_mismatch / amount_over_limit
            $table->unsignedInteger('expected_points')->nullable()->after('points'); // 案件APIで取得済みの成果地点のポイント（定額案件）
            $table->timestamp('held_at')->nullable()->after('hold_reason');
            $table->index('hold_reason');
        });

        Schema::table('postback_logs', function (Blueprint $table) {
            $table->index('created_at'); // 短時間の大量受信の検知に使う
        });
    }

    public function down(): void
    {
        Schema::table('postback_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('conversions', function (Blueprint $table) {
            $table->dropIndex(['hold_reason']);
            $table->dropColumn(['hold_reason', 'expected_points', 'held_at']);
        });
    }
};
