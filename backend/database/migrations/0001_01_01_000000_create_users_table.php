<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 会員。ログインは LINE のみ（メール・パスワードは持たない）。
        // 退会しても行は消さず status=withdrawn にする（同じ LINE アカウントでの再入会・ポイント再取得を防ぐため）。
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // ASP（GF Rewards）の計測リンクに付ける user_id。LINE のユーザーIDは外部に出さない
            $table->ulid('uid')->unique();
            $table->string('line_user_id', 64)->unique();
            $table->string('display_name')->nullable();
            $table->string('avatar_url', 1024)->nullable();
            $table->string('status', 20)->default('active')->index(); // active / suspended / withdrawn
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
