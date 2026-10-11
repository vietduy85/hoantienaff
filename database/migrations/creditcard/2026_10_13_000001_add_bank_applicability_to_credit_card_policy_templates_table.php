<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_templates', function (Blueprint $table) {
            // Phạm vi áp dụng của template cho THẺ. Đây là quy tắc nghiệp vụ,
            // KHÔNG phải FK: bank bị vô hiệu hoá (is_active = false) vẫn còn hàng
            // trăm thẻ cũ hợp lệ dùng template này, và template không được khoá
            // theo vòng đời của bank.
            $table->unsignedBigInteger('applicable_bank_id')->nullable()
                ->after('description')
                ->comment('Logical reference `credit_card_banks.id`; không null ⇒ template CHỈ áp dụng cho thẻ thuộc bank này. NULL = mọi bank.');
            $table->string('applicable_product_match', 191)->nullable()
                ->after('applicable_bank_id')
                ->comment('Không null ⇒ tên thẻ (`credit_card_user_cards.name`) phải chứa chuỗi này (không phân biệt hoa/thường) mới áp dụng được. NULL = không ràng buộc tên.');

            $table->index(['applicable_bank_id', 'applicable_product_match'], 'credit_card_templates_applicability_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_policy_templates', function (Blueprint $table) {
            $table->dropIndex('credit_card_templates_applicability_index');
            $table->dropColumn(['applicable_bank_id', 'applicable_product_match']);
        });
    }
};