<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyTierCategoryTransactionCap — điều kiện "giới hạn hoàn tiền theo giá trị
 * giao dịch" của MỘT BẬC (tier) cashback.
 *
 * Mỗi dòng = một khoảng giá trị giao dịch [min, max] (cả hai đầu đóng) kèm một
 * cap áp cho giao dịch rơi vào khoảng đó. Khi khớp, cap này THAY THẾ
 * `max_cashback_per_transaction` của bậc cho MỌI rule trong bậc; không khớp
 * khoảng nào thì quay về cap cố định của rule (xem CashbackCalculator::pickTransactionCap).
 *
 * Không có quan hệ với bảng khác ngoài bậc chủ: thuần là cấu hình con của bậc.
 *
 * @property int $id
 * @property int $policy_tier_id
 * @property string $min_transaction_amount
 * @property string|null $max_transaction_amount
 * @property string $max_cashback_per_transaction
 * @property int $sort_order
 */
class PolicyTierCategoryTransactionCap extends CreditCardModel
{
    protected $table = 'credit_card_policy_tier_category_transaction_caps';

    protected $fillable = [
        'policy_tier_id',
        'min_transaction_amount',
        'max_transaction_amount',
        'max_cashback_per_transaction',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'policy_tier_id' => 'integer',
            'min_transaction_amount' => 'decimal:2',
            'max_transaction_amount' => 'decimal:2',
            'max_cashback_per_transaction' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(PolicyTier::class, 'policy_tier_id');
    }
}
