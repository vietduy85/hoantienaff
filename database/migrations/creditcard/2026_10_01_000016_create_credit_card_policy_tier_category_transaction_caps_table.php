<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 16/10 — "Giới hạn hoàn tiền theo giá trị giao dịch" (cap động).
 *
 * Mỗi rule (`credit_card_policy_tier_categories`) có thể khai một DANH SÁCH điều
 * kiện theo giá trị của TỪNG giao dịch. Khi giao dịch khớp một khoảng, cap của
 * khoảng đó THAY THẾ `max_cashback_per_transaction` (cap cố định):
 *
 *   - 100.000đ @10% khớp khoảng [0, 199999.99) cap 10.000đ → hoàn 10.000đ
 *   - 300.000đ @10% khớp khoảng [200000, NULL) cap 50.000đ → hoàn 30.000đ
 *
 * Khoảng là ĐÓNG ở cả hai đầu: `amount >= min AND amount <= max`.
 * `max_transaction_amount = NULL` ⇒ không trần trên.
 *
 * Bất biến (bảo vệ ở CategoryRuleService, §23):
 *   - `min_transaction_amount >= 0`, `max_cashback_per_transaction >= 0`;
 *   - `max_transaction_amount >= min_transaction_amount` (nếu có);
 *   - các khoảng TRONG CÙNG một rule KHÔNG được chồng lấn / trùng nhau.
 *
 * Mỗi dòng con thuộc ĐÚNG MỘT rule và cascade khi rule bị xoá.
 * UNIQUE (category_rule_id, min_transaction_amount) chặn trùng mốc "Từ" (mạng
 * lưới phòng thủ thứ hai sau tầng service).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policy_tier_category_transaction_caps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_rule_id');

            // Tên constraint 64 ký tự — đặt TƯỜNG MINH vì tên mặc định
            // (`credit_card_policy_tier_category_transaction_caps_category_rule_id_foreign`,
            // 71 ký tự) vượt giới hạn MySQL 1059 trên production.
            $table->foreign('category_rule_id', 'cc_transaction_caps_category_rule_id_foreign')
                ->references('id')->on('credit_card_policy_tier_categories')
                ->cascadeOnDelete();

            // Điều kiện giá trị giao dịch (đóng hai đầu): [min, max]
            $table->decimal('min_transaction_amount', 16, 2)->default(0)->comment('Giá trị giao dịch tối thiểu (>=)');
            $table->decimal('max_transaction_amount', 16, 2)->nullable()->comment('Giá trị giao dịch tối đa (<=); NULL = không trần');

            // Cap áp cho giao dịch khớp khoảng này — THAY THẾ max_cashback_per_transaction.
            $table->decimal('max_cashback_per_transaction', 16, 2)->comment('Hoàn tối đa mỗi giao dịch trong khoảng này');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['category_rule_id', 'min_transaction_amount'], 'cc_transaction_caps_rule_min_unique');
            $table->index(['category_rule_id', 'sort_order'], 'cc_transaction_caps_rule_sort_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_tier_category_transaction_caps');
    }
};
