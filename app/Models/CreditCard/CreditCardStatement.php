<?php

namespace App\Models\CreditCard;

use App\Support\CreditCard\Decimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CreditCardStatement — SAO KÊ THỰC TẾ của MỘT kỳ, khác với `StatementPeriod`.
 *
 * ---------------------------------------------------------------------------
 * PHÂN BIỆT HAI THỨ
 * ---------------------------------------------------------------------------
 * `StatementPeriod` là KỲ do hệ thống suy ra từ anchor của thẻ: mở khi nào, chốt
 * khi nào, hạn thanh toán khi nào. Nó là DỮ LIỆU SUY RA, không phải thứ user nhập.
 *
 * `CreditCardStatement` là CON SỐ THẬT mà user đọc trên bảng sao kê của ngân hàng:
 * thực tế chi tiêu và thực tế nhận hoàn/thưởng. Số này KHÔNG suy ra được từ giao
 * dịch nhập tay, nên phải do user nhập — và vì mỗi thẻ một kỳ riêng, mỗi cặp
 * (thẻ, kỳ) chỉ có MỘT dòng sao kê (ràng buộc UNIQUE ở migration).
 *
 * ---------------------------------------------------------------------------
 * `closing_balance` LUÔN ĐƯỢC SERVER TÍNH
 * ---------------------------------------------------------------------------
 * Cột này KHÔNG có trong `$fillable` và request KHÔNG nhận nó: client gửi lên
 * cũng bị bỏ qua. Công thức duy nhất là `actual_spend - actual_reward`, đặt trong
 * {@see CreditCardStatement::recalculateClosingBalance()}. Nếu để client tự gửi,
 * một request sửa lệch số tiền còn nợ là lỗi tiền thật.
 *
 * ---------------------------------------------------------------------------
 * `payment_status` THUỘC KỲ; NHẮC THANH TOÁN THUỘC USER
 * ---------------------------------------------------------------------------
 * Một thẻ có nhiều kỳ, mỗi kỳ một hạn trả riêng: kỳ trước có thể đã trả trong khi
 * kỳ sau chưa. Nên "đã trả chưa" là câu hỏi của TỪNG DÒNG sao kê — đặt ở
 * `UserCard` sẽ là một số chung cho mọi kỳ, tức sai ngay khi thẻ có từ hai kỳ trở
 * lên.
 *
 * Số ngày nhắc thì NGƯỢC LẠI và không nằm ở đây: đó là lựa chọn của người dùng về
 * CÁCH HỌ MUỐN ĐƯỢC NHẮC, dùng chung cho mọi thẻ và mọi kỳ, nên thuộc
 * `credit_card_user_settings` — xem `CreditCardUserSetting`. Trước đây bảng này có
 * `payment_reminder_enabled` + `payment_reminder_days`; cả hai đã bị gỡ khỏi mã
 * nguồn và khỏi migration.
 *
 * Ngày đến hạn cũng KHÔNG nằm ở đây: nó thuộc kỳ
 * (`StatementPeriod::payment_due_date`) và đã được suy ra từ `payment_due_day` của
 * thẻ. Sao chép thêm vào đây sẽ tạo hai nguồn sự thật cho cùng một ngày.
 *
 * @property int $id
 * @property int $user_card_id
 * @property int $statement_period_id
 * @property string $actual_spend
 * @property string $actual_reward
 * @property string $closing_balance
 * @property string $payment_status
 */
class CreditCardStatement extends CreditCardModel
{
    public const PAYMENT_STATUS_UNPAID = 'unpaid';

    public const PAYMENT_STATUS_PAID = 'paid';

    /**
     * Tên hiển thị — đặt ở model vì nó là nhãn của CHÍNH cột này, và cần dùng ở cả
     * màn Sao kê lẫn Tổng quan. Nếu để ở Blade, hai màn sẽ dễ lệch chữ.
     */
    public const PAYMENT_STATUS_LABELS = [
        self::PAYMENT_STATUS_UNPAID => 'Chưa thanh toán',
        self::PAYMENT_STATUS_PAID => 'Đã thanh toán',
    ];

    protected $table = 'credit_card_statements';

    /**
     * `closing_balance` cố ý KHÔNG nằm ở đây — xem docblock class.
     *
     * Cột nhắc thanh toán cũng KHÔNG nằm ở đây: số ngày nhắc thuộc
     * `CreditCardUserSetting` (thiết lập chung của user), không thuộc từng kỳ.
     */
    protected $fillable = [
        'user_card_id',
        'statement_period_id',
        'actual_spend',
        'actual_reward',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'user_card_id' => 'integer',
            'statement_period_id' => 'integer',
            'actual_spend' => 'decimal:2',
            'actual_reward' => 'decimal:2',
            'closing_balance' => 'decimal:2',
        ];
    }

    public function userCard(): BelongsTo
    {
        return $this->belongsTo(UserCard::class, 'user_card_id');
    }

    public function statementPeriod(): BelongsTo
    {
        return $this->belongsTo(StatementPeriod::class, 'statement_period_id');
    }

    /**
     * Số dư còn phải trả = chi tiêu thực tế − hoàn/thưởng thực tế.
     *
     * Dùng `Decimal` (bcmath) chứ không `bcsub`/`float` trực tiếp: cột là
     * `decimal(18,2)` nên cộng dồn nhiều kỳ mà rơi về float là lệch tiền thật.
     *
     * Giữ dấu, KHÔNG kẹp về 0: hoàn/thưởng lớn hơn chi tiêu là chuyện hiểm —
     * hiển thị số âm mới nói thật với user, kẹp 0 thì giấu mất.
     */
    public function closingBalance(): string
    {
        return Decimal::subtract(
            Decimal::money($this->actual_spend),
            Decimal::money($this->actual_reward),
        );
    }

    /**
     * Ghi lại `closing_balance` theo công thức duy nhất.
     *
     * Gọi `forceFill` vì cột này nằm ngoài `$fillable` (xem docblock class) —
     * đây là chỗ DUY NHẤT được phép ghi nó.
     */
    public function recalculateClosingBalance(): self
    {
        $this->forceFill(['closing_balance' => $this->closingBalance()]);

        return $this;
    }

    /**
     * Kỳ này đã trả hay chưa.
     *
     * So sánh với hằng, không dùng cast boolean: `payment_status` là CỘT chữ, và
     * `'0'`/`'false'`/chuỗi lạ là những giá trị sai đã lọt vào được. Giá trị lạ
     * cố ý rơi về "chưa trả" — hiển thị cảnh báo thừa còn hơn giấu một khoản
     * chưa trả, và request có `Rule::in()` chặn giá trị lạ ngay từ đầu.
     */
    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS_PAID;
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUS_LABELS[$this->payment_status] ?? self::PAYMENT_STATUS_LABELS[self::PAYMENT_STATUS_UNPAID];
    }
}
