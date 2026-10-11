<?php

namespace App\Models\CreditCard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * MccCode — mã Merchant Category Code (chuẩn quốc tế).
 *
 * Là NGUỒN THAM CHIẾU duy nhất cho phần "Tra cứu MCC". KHÔNG lưu lại thông tin
 * chính sách ở đây; quan hệ với chính sách nằm ở {@see CardPolicyMccMapping}.
 *
 * Trạng thái xác minh mặc định là `unverified`: dữ liệu nhập từ file tham chiếu
 * hoặc PDF chỉ được coi là đã xác minh khi có người xác nhận tường minh.
 *
 * @property int $id
 * @property string $mcc
 * @property string|null $description_en
 * @property string|null $description_vi
 * @property string|null $keywords
 * @property string|null $source
 * @property string $verification_status
 * @property CarbonImmutable|null $verified_at
 */
class MccCode extends CreditCardModel
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $table = 'credit_card_mcc_codes';

    protected $fillable = [
        'mcc',
        'description_en',
        'description_vi',
        'keywords',
        'source',
        'verification_status',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'mcc' => 'string',
            'verified_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::STATUS_VERIFIED;
    }

    public function scopeForCode(Builder $query, string $code): Builder
    {
        return $query->where('mcc', $code);
    }
}
