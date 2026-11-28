<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会員の連絡用メールアドレス（機能仕様 3.5、2026-11-28 決定）。ログインには使わない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 確認済みのアドレスだけを入れる（確認中のものは email_verifications）。確認済みの中で一意
            $table->string('email')->nullable()->unique()->after('status');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            // お得情報のメール（特定電子メール法により、本人が選んだときだけ送る）
            $table->boolean('email_marketing_opt_in')->default(false)->after('email_verified_at');
        });

        Schema::create('email_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('email');
            // リンクのトークンは SHA-256 だけを持つ（DB が漏れてもリンクを作れないように）
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verifications');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'email_verified_at', 'email_marketing_opt_in']);
        });
    }
};
