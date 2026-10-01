<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 20/10 — Cho phép rule cashback trỏ vào COMBO thay vì chỉ một danh mục.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO THÊM CỘT THAY VÌ POLYMORPHIC `target_type` + `target_id`
 * ---------------------------------------------------------------------------
 * Đây là QUYẾT ĐỊNH có ý thức, không phải lựa chọn ngẫu nhiên:
 *
 *   - `credit_card_policy_tier_categories.category_id` đã tồn tại từ migration
 *     000008 và đang được đọc bởi MỌI đường: `CategoryRuleService` (validate +
 *     chống trùng), `TierResolverService` (hydrate), `CashbackCalculator`
 *     (`pickRule` lọc theo `category_id`), presenter, view editor, và báo cáo.
 *   - Nếu chuyển sang `target_type`/`target_id` thì phải backfill toàn bộ dữ liệu
 *     cũ + sửa lại từng chỗ đọc `category_id` ⇒ rủi ro hồi quy cao trên dữ liệu
 *     cashback đã ghi.
 *   - Thêm cột nullable giữ nguyên 100% hành vi của rule cũ: mọi rule hiện có
 *     có `combo_id = NULL` nên `pickRule()` không đổi một dòng nào.
 *
 * ---------------------------------------------------------------------------
 * BA DẠNG RULE SAU MIGRATION NÀY
 * ---------------------------------------------------------------------------
 *   | scope_type | category_id | combo_id | Ý nghĩa                             |
 *   |------------|-------------|----------|-------------------------------------|
 *   | category   | CÓ          | NULL     | rule danh mục cụ thể (không đổi)   |
 *   | category   | NULL        | CÓ       | rule combo (MỚI)                    |
 *   | other      | NULL        | NULL     | fallback "Các danh mục còn lại"    |
 *
 * Bất biến "một trong hai target, không bao giờ cả hai" được chặn ở
 * `CategoryRuleService` (giống cách bất biến "đúng một fallback mỗi bậc" hiện
 * vốn không chặn được bằng UNIQUE index vì NULL lặp được trong MySQL).
 *
 * ---------------------------------------------------------------------------
 * BAND CỦA RULE COMBO
 * ---------------------------------------------------------------------------
 * `spend_from`/`spend_to` của rule combo đo trên TỔNG chi tiêu eligible của
 * TOÀN BỘ danh mục thành viên trong kỳ (đã chốt ở audit). Cùng nguyên tắc
 * retroactive như rule danh mục và như tier: một mức áp cho cả nhóm.
 *
 * FK `combo_id` RESTRICT: combo đã được rule tham chiếu thì không xoá cứng được.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_tier_categories', function (Blueprint $table) {
            $table->foreignId('combo_id')
                ->nullable()
                ->after('category_id')
                ->constrained('credit_card_category_combos', 'id')
                ->restrictOnDelete()
                ->comment('Target combo; NULL cho rule danh mục và fallback');
        });
    }

    public function down(): void
    {
        // Xoá rule combo trước khi bỏ FK, để không đụng dữ liệu lịch sử.
        DB::connection($this->connection)
            ->table('credit_card_policy_tier_categories')
            ->whereNotNull('combo_id')
            ->delete();

        Schema::connection($this->connection)->table('credit_card_policy_tier_categories', function (Blueprint $table) {
            $table->dropForeign(['combo_id']);
            $table->dropColumn('combo_id');
        });
    }
};
