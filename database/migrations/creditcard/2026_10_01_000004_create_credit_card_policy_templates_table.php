<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 4/10 — Policy Template (công thức mẫu để sao chép, KHÔNG gắn thẻ nào).
 *
 * Template KHÔNG bao giờ được thẻ nào tham chiếu trực tiếp. Khi user chọn
 * "Dùng template", `PolicyCloneService` DEEP CLONE sang
 * `credit_card_policies` (bản ghi đó có `user_card_id` khác NULL).
 *
 * Template có "policy blueprint" là một bản ghi `credit_card_policies`
 * với `user_card_id = NULL` + `template_id` trỏ về chính nó.
 *
 * Phân quyền sửa (xem PolicyTemplatePolicy):
 *   - scope = 'system' → admin/system, user KHÔNG sửa được
 *   - scope = 'user'   → chủ sở hữu (`owner_user_id`)
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public const SYSTEM_OWNER_ID = 0;

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_policy_templates', function (Blueprint $table) {
            $table->id();

            $table->enum('scope', ['system', 'user'])->default('user');
            $table->unsignedBigInteger('owner_user_id')->default(self::SYSTEM_OWNER_ID)
                ->comment('0 = hệ thống; >0 = users.id ở hoantienaff.users (logical reference, không FK)');

            $table->string('name', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();

            $table->boolean('is_builtin')->default(false)
                ->comment('Template hệ thống mẫu: không cho phép xoá');
            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['scope', 'owner_user_id', 'slug'], 'credit_card_templates_scope_owner_slug_unique');
            $table->index(['scope', 'is_active'], 'credit_card_templates_scope_active_index');
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_policy_templates');
    }
};
