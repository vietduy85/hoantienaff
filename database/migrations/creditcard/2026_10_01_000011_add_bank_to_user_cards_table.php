<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 000011 — CHUYỂN UserCard SANG "BANK + TÊN THẺ GỢI NHỚ".
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CÓ MIGRATION NÀY (điều chỉnh kiến trúc Phase 1B)
 * ---------------------------------------------------------------------------
 * Phase 1A bắt user chọn một "sản phẩm thẻ" chuẩn hoá (`credit_card_products`).
 * Nghiệp vụ thực tế không cần: ngân hàng phát hành rất nhiều dòng thẻ, mỗi user
 * tự đặt tên gợi nhớ. Bắt chọn catalog sẽ tạo ma sát và dữ liệu sai.
 *
 * Luồng mới:  Bank (master data hệ thống) + `name` tự nhập.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG DROP `product_id`
 * ---------------------------------------------------------------------------
 * `credit_card_products` và `product_id` KHÔNG bị xoá:
 *   - bảng `credit_card_products` giữ nguyên (migration 000002 không đụng),
 *   - `product_id` chuyển thành NULLABLE và đánh dấu deprecated.
 *
 * Lý do: `credit_cards`/`user_credit_cards` legacy đã chạy trên DB chính, và đây
 * là schema chưa commit. Giữ cột nullable là phương án ít rủi ro nhất — không mất
 * dữ liệu, không cần rollback phức tạp, và Phase sau còn muốn quyết định có bỏ
 * hẳn Product catalog hay không.
 *
 * Code mới KHÔNG được đọc/ghi `product_id` (xem `UserCard::product()`).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        // ---- 1. Thêm cột mới ----
        $schema->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->unsignedBigInteger('bank_id')->nullable()->after('user_id');
            $table->date('opened_at')->nullable()->after('statement_date_basis');
            $table->date('closed_at')->nullable()->after('opened_at');
            $table->unsignedInteger('sort_order')->default(0)->after('closed_at');
            $table->text('note')->nullable()->after('credit_limit');
        });

        // ---- 2. Backfill bank_id từ product_id (giữ dữ liệu Phase 1A nếu có) ----
        if (DB::connection($this->connection)->table('credit_card_user_cards')->whereNotNull('product_id')->exists()) {
            DB::connection($this->connection)->statement(
                'UPDATE credit_card_user_cards uc
                 INNER JOIN credit_card_products p ON p.id = uc.product_id
                 SET uc.bank_id = p.bank_id
                 WHERE uc.bank_id IS NULL'
            );
        }

        // ---- 3. bank_id thành NOT NULL + FK ----
        $schema->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->unsignedBigInteger('bank_id')->nullable(false)->change();

            $table->foreign('bank_id')
                ->references('id')
                ->on('credit_card_banks')
                ->restrictOnDelete();

            $table->index(['bank_id', 'status'], 'credit_card_user_cards_bank_status_index');

            // product_id thành nullable: luồng mới không dùng nữa nhưng cột vẫn còn.
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->dropForeign(['bank_id']);
            $table->dropIndex('credit_card_user_cards_bank_status_index');
            $table->dropColumn(['bank_id', 'opened_at', 'closed_at', 'sort_order', 'note']);
        });

        // product_id trở lại NOT NULL — chỉ khả thi nếu mọi dòng đều đã có product.
        $schema->table('credit_card_user_cards', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }
};
