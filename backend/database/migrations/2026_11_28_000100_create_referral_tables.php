<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 友達紹介（機能仕様 15章、テーブル定義 4.16）。
 * ランクと初回特典の数字は管理画面（A17）で変えるので DB に持つ。ここで入れる値は仮（公開前に確定する）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 初めて紹介ページを開いたときに発行する
            $table->string('referral_code', 16)->nullable()->unique()->after('uid');
            // 登録のときだけ入れ、あとで変えない
            $table->foreignId('referred_by_user_id')->nullable()->after('referral_code')->constrained('users');
            $table->timestamp('referral_disabled_at')->nullable()->after('referred_by_user_id');
            // 特別パートナーの率（%）。空なら一般のランク
            $table->decimal('referral_partner_rate', 5, 2)->nullable()->after('referral_disabled_at');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('referral_excluded')->default(false);
            $table->decimal('referral_rate_cap', 5, 2)->nullable();
        });

        Schema::create('referral_ranks', function (Blueprint $table) {
            $table->id();
            // partner は特別パートナー用で消せない（rate は率の上限として使う）
            $table->string('code', 16)->unique();
            $table->string('name', 32);
            $table->decimal('rate', 5, 2);
            $table->unsignedInteger('min_valid_referrals')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // 1行だけの設定
        Schema::create('referral_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('initial_bonus_points');
            $table->unsignedInteger('initial_threshold_points');
            $table->unsignedInteger('initial_days');
            $table->timestamps();
        });

        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users');
            $table->foreignId('referee_user_id')->constrained('users');
            $table->string('type', 16);  // ongoing / initial
            $table->foreignId('conversion_id')->nullable()->constrained();
            $table->unsignedInteger('base_points')->default(0);
            $table->decimal('rate', 5, 2)->nullable();
            $table->string('rank', 16)->nullable();
            // ランクは消せるので、そのときの表示名も残す
            $table->string('rank_name', 32)->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->foreignId('point_transaction_id')->nullable()->unique()->constrained();
            $table->timestamps();

            // 同じ成果から二重に付けない（初回特典は conversion_id が空なので、アプリでロックして確かめる）
            $table->unique(['conversion_id', 'type']);
            $table->index(['referee_user_id', 'type']);
            $table->index(['referrer_user_id', 'created_at']);
        });

        $now = now();
        DB::table('referral_ranks')->insert([
            ['code' => 'regular', 'name' => 'レギュラー', 'rate' => 5, 'min_valid_referrals' => 0, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'gold', 'name' => 'ゴールド', 'rate' => 8, 'min_valid_referrals' => 30, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'platinum', 'name' => 'プラチナ', 'rate' => 10, 'min_valid_referrals' => 100, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'partner', 'name' => '特別パートナー', 'rate' => 15, 'min_valid_referrals' => 0, 'sort_order' => 99, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('referral_settings')->insert([
            'initial_bonus_points' => 100, 'initial_threshold_points' => 1000, 'initial_days' => 180, 'created_at' => $now, 'updated_at' => $now,
        ]);

    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referral_settings');
        Schema::dropIfExists('referral_ranks');
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn(['referral_excluded', 'referral_rate_cap']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referral_disabled_at', 'referral_partner_rate']);
        });
    }
};
