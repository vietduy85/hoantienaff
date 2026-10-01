<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 19/10 — Thành viên của Category Combo.
 *
 * Mỗi dòng là MỘT danh mục chi tiêu thuộc về một combo. Bảng này là toàn bộ
 * định nghĩa "combo gồm những danh mục nào" — không có bảng nào khác chứa danh
 * sách này, nên không thể lệch dữ liệu.
 *
 * ---------------------------------------------------------------------------
 * RÀNG BUỘC DB
 * ---------------------------------------------------------------------------
 *   - `UNIQUE (combo_id, category_id)`: một danh mục không được xuất hiện 2 lần
 *     trong CÙNG một combo. Đây là bất biến "không trùng thành viên".
 *
 *     LƯU Ý: ràng buộc này KHÔNG (và không thể) chặn một danh mục nằm trong NHIỀU
 *     combo khác nhau — đó là chủ ý cho phép ở tầng dữ liệu. Việc chặn trùng khi
 *     GÁN RULE được thực hiện ở `CategoryRuleService` (một category chỉ được gán
 *     1 rule trong 1 bậc), vì đó là ràng buộc nghiệp vụ chứ không phải ràng buộc
 *     cấu trúc dữ liệu.
 *
 *   - `FK combo_id` CASCADE: xoá combo thì mất hết thành viên (module này không
 *     triển khai xoá combo, nhưng FK vẫn phải đúng ngữ nghĩa).
 *
 *   - `FK category_id` RESTRICT: danh mục đã nằm trong combo thì KHÔNG xoá cứng
 *     được — cùng lý do với `credit_card_policy_tier_categories.category_id`.
 *     `CategoryService::usageOf()` đã tính thêm combo item nên danh mục này rơi
 *     vào nhánh soft-delete (`is_active = false`) thay vì xoá cứng.
 *
 * KHÔNG có cột trỏ tới combo khác ⇒ không thể biểu diễn combo chứa combo.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_category_combo_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('combo_id')
                ->constrained('credit_card_category_combos', 'id')
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('credit_card_categories', 'id')
                ->restrictOnDelete();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['combo_id', 'category_id'], 'credit_card_combo_items_combo_category_unique');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_category_combo_items');
    }
};
