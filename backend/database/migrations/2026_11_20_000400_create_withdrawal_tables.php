<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 振込先口座。口座番号は暗号化して保存し、重複検知は fingerprint（HMAC）で行う
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->char('bank_code', 4);
            $table->string('bank_name')->nullable();
            $table->char('branch_code', 3);
            $table->string('branch_name')->nullable();
            $table->string('account_type', 16);           // ordinary（普通） / checking（当座） / savings（貯蓄）
            $table->text('account_number');               // 暗号化
            $table->string('account_holder_kana');        // 全銀の半角カナに正規化して保存
            $table->char('account_fingerprint', 64)->index(); // 同一口座の複数ユーザー利用の検知
            $table->string('holder_name_key')->index();       // 口座名義の重複検知（空白を除いた名義）
            $table->timestamps();
        });

        // 換金申請。申請 → 審査（承認／却下）→ 振込（全銀CSV出力・振込済み）
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('points');
            $table->unsignedInteger('amount_yen');
            $table->unsignedInteger('fee_yen')->default(0);
            $table->string('status', 16)->default('requested'); // requested / approved / rejected / paid
            $table->json('bank_snapshot');                       // 申請時点の口座情報（暗号化）
            $table->foreignId('reviewed_by')->nullable()->constrained('admins');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('reviewable_after')->nullable();   // 審査期間（この日時まで承認しない）
            $table->string('export_batch', 32)->nullable()->index(); // 全銀CSVの出力単位
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('bank_accounts');
    }
};
