<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3/10 — Spending Category (danh mục chi tiêu).
 *
 * Một bảng cho cả 2 loại, phân biệt bằng `scope`:
 *   - `scope = 'system'` + `owner_user_id = 0`  → danh mục hệ thống (Online, Dining, ...)
 *   - `scope = 'user'`   + `owner_user_id = N`  → danh mục riêng của user
 *
 * Vì sao dùng sentinel `0` cho system thay vì NULL:
 *   - NULL trong UNIQUE index MySQL/MariaDB cho phép nhiều dòng NULL trùng nhau
 *     ⇒ không bảo vệ được tính duy nhất của danh mục hệ thống.
 *   - `users.id` là `bigint unsigned AUTO_INCREMENT`, giá trị 0 không bao giờ tồn tại.
 *   - NOT NULL ⇒ unique index thật sự được MySQL áp dụng.
 *
 * KHÔNG cross-database foreign key tới `hoantienaff.users` (user ở DB khác).
 * `owner_user_id` là logical reference, được validate ở tầng service/policy.
 *
 * KHÔNG có `cashback_percent` và KHÔNG có quota: danh mục chỉ là "nhãn chi tiêu".
 * Toàn bộ cashback nằm ở Policy Version → Tier → Tier Category Rule.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    /**
     * Sentinel cho `owner_user_id` khi category thuộc hệ thống (scope = 'system').
     */
    public const SYSTEM_OWNER_ID = 0;

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_categories', function (Blueprint $table) {
            $table->id();

            $table->enum('scope', ['system', 'user'])->default('system');
            $table->unsignedBigInteger('owner_user_id')->default(self::SYSTEM_OWNER_ID)
                ->comment('0 = hệ thống; >0 = users.id ở hoantienaff.users (logical reference, không FK)');

            $table->string('name', 150);
            $table->string('slug', 150);
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false)->comment('Danh mục mặc định khi tạo giao dịch mới');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['scope', 'owner_user_id', 'slug'], 'credit_card_categories_scope_owner_slug_unique');
            $table->index(['scope', 'owner_user_id', 'is_active'], 'credit_card_categories_scope_owner_active_index');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_categories');
    }
};
