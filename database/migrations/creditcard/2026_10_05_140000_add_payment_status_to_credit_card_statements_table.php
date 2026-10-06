<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trạng thái thanh toán cho TỪNG KỲ SAO KÊ.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO NẰM Ở `credit_card_statements`, KHÔNG PHẢI `credit_card_user_cards`
 * ---------------------------------------------------------------------------
 * Một thẻ có nhiều kỳ, mỗi kỳ một hạn thanh toán riêng. "Đã trả kỳ này" là câu hỏi
 * của TỪNG KỲ: kỳ 09/08 có thể đã trả trong khi kỳ 09/09 chưa. Đặt ở
 * `credit_card_user_cards` sẽ khiến thẻ mang một trạng thái chung — tức sai ngay khi
 * thẻ có từ hai kỳ trở lên.
 *
 * ---------------------------------------------------------------------------
 * NHẮC THANH TOÁN KHÔNG NẰM Ở ĐÂY
 * ---------------------------------------------------------------------------
 * Số ngày nhắc là thiết lập CHUNG của người dùng cho MỌI thẻ và MỌI kỳ, nên nó
 * thuộc `credit_card_user_settings` (migration `..._create_credit_card_user_settings_table`),
 * không phải từng dòng sao kê. Trước đây migration này dựng thêm
 * `payment_reminder_enabled` + `payment_reminder_days` ở đây; cả hai đã bị gỡ khỏi
 * mã nguồn và không được tạo ra ở đây. Xem `CreditCardUserSetting`.
 *
 * ---------------------------------------------------------------------------
 * `payment_due_date` KHÔNG NHÂN BẢN SAO Ở ĐÂY
 * ---------------------------------------------------------------------------
 * Hạn thanh toán thuộc KỲ (`credit_card_statement_periods.payment_due_date`) và đã
 * được suy ra từ `payment_due_day` của thẻ. Sao chép nó sang đây sẽ tạo hai nguồn
 * sự thật cho cùng một ngày. Cột này chỉ giữ trạng thái của kỳ, phần ngày thuộc kỳ.
 *
 * ---------------------------------------------------------------------------
 * MIGRATION BỔ SUNG, KHÔNG SỬA MIGRATION ĐÃ CHẠY
 * ---------------------------------------------------------------------------
 * Bảng đã tồn tại ở môi trường đã chạy, nên bổ sung cột bằng migration mới thay vì
 * chỉnh `create_credit_card_statements_table`.
 *
 * ---------------------------------------------------------------------------
 * DEFAULT `unpaid` — DỮ LIỆU CŨ KHÔNG BỊ ĐỔI
 * ---------------------------------------------------------------------------
 * Bảng đang có dữ liệu thật, nên `up()` PHẢI chạy được trên bảng đã có bản ghi:
 * `payment_status` có default `'unpaid'` nên mọi dòng cũ tự nhận "chưa thanh toán"
 * thay vì NULL. Không có dòng nào "mặc nhiên" là đã trả, và không cần UPDATE hàng
 * loạt — đúng thứ mà `migrate:fresh` hoặc `dropColumn` sẽ phá.
 *
 * `hasColumn()` bao quanh để migration chạy được cả ở nơi bảng đã kịp có cột:
 * migration đã chạy một lần thì chạy lại không nên ném lỗi.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    private const STATUS_INDEX = 'credit_card_statements_card_status_index';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasColumn('credit_card_statements', 'payment_status')) {
            $schema->table('credit_card_statements', function (Blueprint $table) {
                // Const string, không dùng ENUM của DB: enum cứng khó mở rộng và
                // khác với convention `status` của `StatementPeriod` (const + scope).
                $table->string('payment_status', 16)
                    ->default('unpaid')
                    ->comment('Trạng thái thanh toán của kỳ này: unpaid | paid');
            });
        }

        // Màn Sao kê luôn lọc theo trạng thái thanh toán (để tìm dòng "chưa trả"),
        // nên index nằm trên cột hay đọc, không phải cột hiển thị.
        if (! $schema->hasIndex('credit_card_statements', self::STATUS_INDEX)) {
            $schema->table('credit_card_statements', function (Blueprint $table) {
                $table->index(['user_card_id', 'payment_status'], self::STATUS_INDEX);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasIndex('credit_card_statements', self::STATUS_INDEX)) {
            $schema->table('credit_card_statements', function (Blueprint $table) {
                $table->dropIndex(self::STATUS_INDEX);
            });
        }

        if ($schema->hasColumn('credit_card_statements', 'payment_status')) {
            $schema->table('credit_card_statements', function (Blueprint $table) {
                $table->dropColumn('payment_status');
            });
        }
    }
};