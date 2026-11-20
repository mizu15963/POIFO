<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 振込先の氏名（漢字）。月末の振込確認用 CSV に出す。口座名義（カナ）とは別に持つ
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('holder_name')->nullable()->after('account_holder_kana');
        });

        // 二要素認証のコードの使い回し（リプレイ）を防ぐため、最後に使った時間枠を覚えておく
        Schema::table('admins', function (Blueprint $table) {
            $table->unsignedBigInteger('two_factor_last_timestep')->nullable()->after('two_factor_confirmed_at');
        });

        // 振込済みにした管理者
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('admins');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
        });
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_timestep');
        });
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('holder_name');
        });
    }
};
