<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ポストバックの受信ログ（正常・異常すべて）。問い合わせ対応と不正調査のため全件残す
        Schema::create('postback_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('gf_rewards');
            $table->string('method', 8);
            $table->string('ip_address', 45)->nullable();
            $table->json('params');
            $table->string('cv_id', 128)->nullable()->index();
            $table->boolean('hash_valid')->default(false);
            $table->string('result', 32);          // granted / pending / rejected / duplicate / unknown_user / withdrawn_user / invalid_hash / invalid_params / error
            $table->unsignedTinyInteger('response_code')->nullable(); // ASP に返した本文 0 / 1 / 2
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // 成果（cv_id ごとに1行）。状態は ASP の cv_status に合わせて pending → approved / rejected
        Schema::create('conversions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('gf_rewards');
            $table->string('cv_id', 128);
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('external_user_id', 128);
            $table->string('external_campaign_id', 64)->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('cv_action_id', 64)->nullable();
            $table->string('cv_action_name')->nullable();
            $table->string('status', 16);           // pending / approved / rejected
            $table->unsignedInteger('points');      // incentive_amount（承認時に付与するポイント）
            $table->decimal('net_cost', 12, 2)->nullable(); // 自社への報酬額
            $table->timestamp('cv_at')->nullable();
            $table->timestamp('authenticated_at')->nullable();
            $table->json('last_payload');
            $table->timestamps();

            $table->unique(['provider', 'cv_id']);
        });

        // ポイント台帳。残高は確定（confirmed）分の合計。判定中（pending）は別に表示する
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type', 32);             // conversion / adjustment / withdrawal / withdrawal_refund / expiry
            $table->string('status', 16);           // pending / confirmed / rejected
            $table->integer('points');              // 付与は正、利用（換金）は負
            $table->string('description')->nullable();
            $table->foreignId('conversion_id')->nullable()->unique()->constrained();
            $table->unsignedBigInteger('withdrawal_request_id')->nullable()->index();
            $table->foreignId('admin_id')->nullable()->constrained();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('conversions');
        Schema::dropIfExists('postback_logs');
    }
};
