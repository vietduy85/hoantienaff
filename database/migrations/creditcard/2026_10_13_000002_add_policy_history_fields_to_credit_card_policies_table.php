<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->table('credit_card_policies', function (Blueprint $table) {
            // Lịch sử chính sách hoàn tiền CỦA THẺ: mỗi version/root là một dòng
            // lịch sử (append-only). `recorded_by_user_id` ghi ai tạo/đổi version
            // đó. Logical reference sang `hoantienaff.users` (giống các cột khác),
            // NULL cho blueprint hệ thống/template và dữ liệu seed trước feature.
            $table->unsignedBigInteger('recorded_by_user_id')->nullable()
                ->after('note')
                ->comment('Logical reference `hoantienaff.users.id`; người tạo/đổi phiên bản policy này. NULL = blueprint/template hệ thống hoặc dữ liệu cũ.');
        });

        // Truy vấn lịch sử luôn lọc theo thẻ rồi sort theo thời gian tạo ⇒ thêm
        // index dẫn đường (chống filesort trên bảng append-only lớn dần).
        Schema::connection($this->connection)->table('credit_card_policies', function (Blueprint $table) {
            $table->index(['user_card_id', 'created_at'], 'credit_card_policies_user_card_created_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('credit_card_policies', function (Blueprint $table) {
            $table->dropIndex('credit_card_policies_user_card_created_index');
            $table->dropColumn('recorded_by_user_id');
        });
    }
};