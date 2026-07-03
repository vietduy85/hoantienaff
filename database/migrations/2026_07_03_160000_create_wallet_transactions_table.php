<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            // === Transaction Identity ===

            // Mã giao dịch hiển thị cho User. Định dạng: WT + YYYYMMDD + 4-digit sequential.
            // Ví dụ: WT202607030001, WT202607030002, ...
            // Unique toàn bảng. Không dùng id làm mã hiển thị.
            $table->string('running_no', 30)->unique()->comment('Mã giao dịch (WT + ngày + số thứ tự)');

            // === User ===
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('username', 100);
            $table->index('username');

            // === Platform (Shopee, Lazada, TikTok, ...) ===
            $table->string('platform', 30)->index();

            // === Transaction Classification ===
            $table->enum('type', ['cashback', 'withdraw', 'refund', 'adjustment'])->index();
            $table->enum('direction', ['credit', 'debit']);

            // === Amount (luôn dương, chiều xác định bằng direction) ===
            $table->decimal('amount', 16, 2);

            // === Reference (liên kết đến bảng nội bộ) ===
            // reference_type: affiliate_order_item | withdraw_request | manual
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id')->nullable()->index();

            // === Description ===
            $table->string('description', 255);

            // === Status ===
            // pending:   giao dịch chờ xử lý — không ảnh hưởng số dư
            // completed: giao dịch hoàn tất — ảnh hưởng số dư
            // cancelled: giao dịch bị hủy — không ảnh hưởng số dư
            // failed:    giao dịch thất bại — không ảnh hưởng số dư
            $table->enum('status', ['pending', 'completed', 'cancelled', 'failed'])->index()->default('completed');

            // === Timestamps ===
            $table->datetime('completed_at')->nullable()->comment('Thời điểm transaction hoàn tất (khác created_at)');

            $table->timestamps();

            // === Internal Note ===
            $table->text('note')->nullable();

            // === Audit ===
            // NULL = system tạo, có giá trị = admin xử lý
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();

            // === Metadata ===
            $table->json('metadata')->nullable()->comment('Dữ liệu mở rộng (order_id, bank, account_number, ...)');

            // === Indexes ===
            $table->index('created_at');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
