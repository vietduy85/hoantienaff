<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdraw_requests', function (Blueprint $table) {
            $table->id();

            // === Request Identity ===
            $table->string('running_no', 30)->unique()->comment('Mã yêu cầu rút tiền (WR + YYYYMMDD + 4 số thứ tự)');

            // === User ===
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username', 100);
            $table->index('username');

            // === Platform ===
            $table->string('platform', 30);

            // === Amount ===
            $table->decimal('amount', 16, 2);

            // === Bank info ===
            $table->string('bank_name', 100);
            $table->string('bank_account', 50);
            $table->string('account_name', 150);

            // === Status ===
            // pending:  chờ admin duyệt
            // approved: đã duyệt, chờ thanh toán
            // paid:     đã thanh toán — tạo wallet_transaction type=withdraw, direction=debit
            // rejected: bị từ chối
            $table->enum('status', ['pending', 'approved', 'paid', 'rejected'])->index()->default('pending');

            // === Processing ===
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('processed_at')->nullable();

            // === Notes ===
            $table->text('note')->nullable();

            // === Metadata ===
            $table->json('metadata')->nullable()->comment('Dữ liệu mở rộng (bank_code, ip, ...)');

            $table->timestamps();

            // === Indexes ===
            $table->index('running_no');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdraw_requests');
    }
};
