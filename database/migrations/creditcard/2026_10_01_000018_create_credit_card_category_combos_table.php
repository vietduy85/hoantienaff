<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 18/10 — Category Combo (danh mục combo): NHÓM danh mục chi tiêu.
 *
 * Một Combo là tập hợp ≥ 1 danh mục chi tiêu, dùng làm TARGET của cashback rule
 * để "một rule, nhiều danh mục". KHÔNG có cashback ở đây — cashback vẫn nằm ở
 * `credit_card_policy_tier_categories` (thêm cột `combo_id`, migration 000020).
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO THÀNH BẢNG RIÊNG, KHÔNG NHỒI VÀO `credit_card_categories`
 * ---------------------------------------------------------------------------
 * Danh mục là NHÃN chi tiêu bất biến về danh tính (`id` không đổi khi đổi tên);
 * Combo là TẬP HỢP các nhãn đó và membership của nó là NỘI DUNG có thể sửa.
 * Trộn hai khái niệm này vào một bảng sẽ phá vỡ bất biến của Category Master và
 * làm mờ ranh giới system/user. Vì vậy Combo có bảng riêng, KHÔNG dùng
 * `category_ids` JSON, KHÔNG nested (combo không chứa combo).
 *
 * ---------------------------------------------------------------------------
 * HAI SCOPE, GIỐNG HỆT CÁCH `credit_card_categories` PHÂN QUYỀN
 * ---------------------------------------------------------------------------
 *   - `scope = 'system'`, `owner_user_id = 0` → combo hệ thống, admin quản lý.
 *   - `scope = 'user'`,   `owner_user_id = N` → combo riêng của user, chỉ chủ
 *     nhân được đọc/ghi.
 *
 * Sentinel `0` cho system thay vì NULL vì NULL trong UNIQUE index MySQL cho phép
 * nhiều dòng NULL trùng nhau ⇒ không bảo vệ được tính duy nhất của combo hệ
 * thống. `users.id` là AUTO_INCREMENT nên 0 không bao giờ là id hợp lệ.
 *
 * ---------------------------------------------------------------------------
 * INVARIANT
 * ---------------------------------------------------------------------------
 * KHÔNG có FK tới chính bảng này: Combo KHÔNG BAO GIỜ chứa Combo (chống nested
 * ở tầng service bằng cách chỉ chấp nhận `category_id` trong
 * `credit_card_category_combo_items`).
 *
 * KHÔNG cross-database FK tới `hoantienaff.users` — `owner_user_id` là logical
 * reference, validate ở tầng service/policy (giống `credit_card_categories`).
 *
 * KHÔNG có `is_default`: combo không phải lựa chọn mặc định khi nhập giao dịch.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    /**
     * Sentinel cho `owner_user_id` khi combo thuộc hệ thống (scope = 'system').
     */
    public const SYSTEM_OWNER_ID = 0;

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_category_combos', function (Blueprint $table) {
            $table->id();

            $table->enum('scope', ['system', 'user'])->default('system');
            $table->unsignedBigInteger('owner_user_id')->default(self::SYSTEM_OWNER_ID)
                ->comment('0 = hệ thống; >0 = users.id ở hoantienaff.users (logical reference, không FK)');

            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['scope', 'owner_user_id', 'slug'], 'credit_card_combos_scope_owner_slug_unique');
            $table->index(['scope', 'owner_user_id', 'is_active'], 'credit_card_combos_scope_owner_active_index');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_category_combos');
    }
};
