<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 交換先を増やせるようにする（docs/specs/functional-spec.md 7.5）。
 *
 * - exchange_method: 交換先コード。既存の申請はすべて銀行振込
 * - recipient_snapshot: 申請時点の受け取り情報の控え（暗号化）。交換先に関係ない名前にし、bank_snapshot の中身を移す。
 *   暗号化した文字列は JSON ではないので text にする
 * - yen_per_point: 申請時点の交換レート（あとで設定を変えても、出た申請には影響しない）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->string('exchange_method', 40)->default('bank_transfer')->after('user_id');
            $table->text('recipient_snapshot')->nullable()->after('status');
            $table->decimal('yen_per_point', 12, 4)->nullable()->after('points');
            $table->index(['exchange_method', 'created_at']);
        });

        // 暗号化した値は同じ APP_KEY で読めるので、そのまま移す
        DB::table('withdrawal_requests')->update([
            'recipient_snapshot' => DB::raw('bank_snapshot'),
            'yen_per_point' => DB::raw('CASE WHEN points > 0 THEN amount_yen * 1.0 / points ELSE 1 END'),
        ]);

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('bank_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            // 暗号化した文字列は JSON ではないので、戻すときも text にする（json だと MySQL で入らない）
            $table->text('bank_snapshot')->nullable()->after('status');
        });

        DB::table('withdrawal_requests')->update(['bank_snapshot' => DB::raw('recipient_snapshot')]);

        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropIndex(['exchange_method', 'created_at']);
            $table->dropColumn(['exchange_method', 'recipient_snapshot', 'yen_per_point']);
        });
    }
};
