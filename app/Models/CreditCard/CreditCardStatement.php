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
 * @property int $id
 * @property int $user_card_id
 * @property int $statement_period_id
 * @property string $actual_spend
 * @property string $actual_reward
 * @property string $closing_balance
 */
class CreditCardStatement extends CreditCardModel
{
    protected $table = 'credit_card_statements';

    /**
     * `closing_balance` cố ý KHÔNG nằm ở đây — xem docblock class.
     */
    protected $fillable = [
        'user_card_id',
        'statement_period_id',
        'actual_spend',
        'actual_reward',
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
}
