<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ASP の案件連携API から定期取得した案件のキャッシュ（API は回数制限があるので画面表示ごとに呼ばない）
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('gf_rewards');
            $table->string('external_id', 64);                    // ASP のキャンペーンID
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('conversion_condition')->nullable();
            $table->string('category', 64)->nullable();
            $table->string('platform', 16)->nullable();           // ios / android / web / others
            $table->json('user_platforms')->nullable();
            $table->string('icon_url', 1024)->nullable();
            $table->string('click_url', 2048);
            $table->string('revenue_type', 32)->nullable();       // fixed_amount / fixed_rate
            $table->unsignedInteger('max_points')->nullable();    // 成果地点のうち最大の付与ポイント（定額案件のみ）
            $table->string('external_status', 32)->nullable();    // not_started / started / paused
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('conversion_actions')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            // 運営側の設定（同期では上書きしない）
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['external_status', 'is_hidden']);
        });

        // 案件リンクのクリック記録（問い合わせ対応・不正調査用）
        Schema::create('campaign_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('campaign_id')->constrained();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_clicks');
        Schema::dropIfExists('campaigns');
    }
};
