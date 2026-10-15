<?php

namespace App\Models\CreditCard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CumulativeSpendUpdate — một lần user đổi MỐC CHI TIÊU LŨY KẾ KỲ TRƯỚC của thẻ.
 *
 * ---------------------------------------------------------------------------
 * CHỈ GHI, KHÔNG PHẢI NGUỒN SỰ THẬT
 * ---------------------------------------------------------------------------
 * Giá trị "đang có" nằm trên `credit_card_user_cards.cumulative_spend_baseline`.
 * Bảng này chỉ là NHẬT KÝ: mỗi lần sửa thêm một dòng với giá trị cũ/mới + ngày +
 * ghi chú. Mọi phép tính tiến độ đọc cột trên `UserCard`, KHÔNG cộng dồn nhật ký.
 *
 * `user_id` là logical reference sang `hoantienaff.users` (khác database) — không
 * có FK vật lý, xem `CreditCardModel`.
 *
 * @property int $id
 * @property int $user_card_id
 * @property int $user_id
 * @property string|null $old_baseline
 * @property string|null $new_baseline
 * @property CarbonImmutable|null $baseline_date
 * @property string|null $note
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class CumulativeSpendUpdate extends CreditCardModel
{
    protected $table = 'credit_card_cumulative_spend_updates';

    protected $fillable = [
        'user_card_id',
        'user_id',
        'old_baseline',
        'new_baseline',
        'baseline_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'user_card_id' => 'integer',
            'user_id' => 'integer',
            'old_baseline' => 'decimal:2',
            'new_baseline' => 'decimal:2',
            'baseline_date' => 'date',
        ];
    }

    public function userCard(): BelongsTo
    {
        return $this->belongsTo(UserCard::class, 'user_card_id');
    }
}
