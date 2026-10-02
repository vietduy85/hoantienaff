<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 23/10 — Cờ "Tính hạn mức chi tiêu còn lại" trên rule cashback.
 *
 * ---------------------------------------------------------------------------
 * Ý NGHĨA
 * ---------------------------------------------------------------------------
 * Rule được TICK ⇒ cashback của danh mục/combo đó là CƠ SỞ tính số tiền user
 * còn có thể chi thêm để nhận tối đa hoàn tiền:
 *
 *   cashback còn lại  = cashback tối đa của rule − cashback rule đã dùng trong kỳ
 *   chi thêm còn lại  ≈ cashback còn lại ÷ tỷ lệ % của rule
 *
 * Đây là CẤU HÌNH của Policy Version, không phải cấu hình engine: KHÔNG cột nào
 * ở đây được `CashbackCalculator` đọc. Tiền hoàn thật vẫn do engine tự tính.
 *
 * ---------------------------------------------------------------------------
 * PHẠM VI TICK ĐƯỢC PHÉP
 * ---------------------------------------------------------------------------
 *   | target          | tick được? | lý do                                   |
 *   |-----------------|------------|------------------------------------------|
 *   | danh mục        | CÓ         | "Ăn uống" là một mục tiêu chi tiêu rõ ràng |
 *   | combo           | CÓ         | combo cũng là một nhóm chi tiêu có mức   |
 *   | fallback "còn lại"| KHÔNG     | không phải một danh mục cụ thể            |
 *
 * Bất biến này KHÔNG thể diễn đạt bằng index ⇒ chặn ở `CategoryRuleService`.
 *
 * ---------------------------------------------------------------------------
 * MỘT BẬC DUY NHẤT TRONG MỘT POLICY VERSION
 * ---------------------------------------------------------------------------
 * Các rule được tick phải thuộc CÙNG một bậc (xem `CategoryRuleService::
 * assertQuotaCategoryTierUniqueness()`), vì quota Dashboard chỉ xác định MỘT bậc
 * từ `UserCard.desired_spend` rồi đọc các rule được tick của bậc đó. Cho phép
 * tick ở hai bậc ⇒ mọi bậc sau đó không xác định được quota lấy từ đâu.
 *
 * Default `false`: KHÔNG tự động tick rule cũ. Policy đã cấu hình (và đã sinh
 * cashback) giữ nguyên hành vi hiện tại sau khi deploy.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_tier_categories', function (Blueprint $table) {
            $table->boolean('is_quota_category')->default(false)
                ->after('counts_toward_tier_cap')
                ->comment('Rule này có tính hạn mức chi tiêu còn lại không (false = không); fallback luôn false');

            // Quota Dashboard luôn lọc "bậc này có rule nào được tick không" ⇒ index
            // phủ đúng điều kiện đó, không đụng unique index của tier/category.
            $table->index(['tier_id', 'is_quota_category'], 'cc_tier_categories_tier_quota_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_tier_categories', function (Blueprint $table) {
            $table->dropIndex('cc_tier_categories_tier_quota_idx');
            $table->dropColumn('is_quota_category');
        });
    }
};
