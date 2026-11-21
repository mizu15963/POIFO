<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // イベントの参加上限と参加ポイント（docs/specs/functional-spec.md 8章）
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable()->after('openchat_url');   // 空なら上限なし
            $table->unsignedInteger('reward_points')->default(0)->after('capacity');  // 参加を確認した会員に付与
        });

        // イベントの参加（イベントと会員の組で1行。参加したかどうかはこの表で判定する）
        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('status', 16);                 // joined / cancelled / attended / absent
            $table->timestamp('joined_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('checked_at')->nullable();  // 管理者が参加済み／不参加を付けた日時
            $table->foreignId('checked_by')->nullable()->constrained('admins');
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['event_id', 'status']);
        });

        // 参加ポイントは参加1件につき1回だけ（二重付与を DB でも防ぐ）
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->foreignId('event_participant_id')->nullable()->unique()->after('withdrawal_request_id')->constrained();
        });

        // 会員の停止理由（会員には表示しない）
        Schema::table('users', function (Blueprint $table) {
            $table->string('suspended_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('suspended_reason'));
        Schema::table('point_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_participant_id');
        });
        Schema::dropIfExists('event_participants');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['capacity', 'reward_points']));
    }
};
