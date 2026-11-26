<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * お気に入り（機能仕様 4.5）・お知らせ（機能仕様 11.1）・案件の利用履歴（機能仕様 3.3）の分。2026-11-26 決定。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('campaign_id')->constrained();
            $table->timestamp('created_at')->useCurrent();
            // 同じ案件を2回登録しない（2回目は何もしない）
            $table->unique(['user_id', 'campaign_id']);
        });

        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('is_important')->default(false);
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // 最後にお知らせ一覧を開いた日時。未読の数はこれより後に公開されたお知らせの件数（1件ずつの既読は持たない）
            $table->timestamp('news_seen_at')->nullable()->after('last_login_at');
        });

        Schema::table('campaign_clicks', function (Blueprint $table) {
            // 会員が自分の利用履歴（直近90日）を見るため
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('campaign_clicks', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('news_seen_at');
        });
        Schema::dropIfExists('news');
        Schema::dropIfExists('favorites');
    }
};
