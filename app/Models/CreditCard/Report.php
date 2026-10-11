<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Report — CẤU HÌNH báo cáo do user lưu lại (KHÔNG phải kết quả báo cáo).
 *
 * ---------------------------------------------------------------------------
 * KHÔNG LƯU SỐ LIỆU
 * ---------------------------------------------------------------------------
 * Model này chỉ giữ "xem gì": tên, kiểu, và danh sách thẻ. Mọi tổng chi tiêu /
 * cashback được tính lại mỗi lần mở, từ `credit_card_transactions` và snapshot
 * cashback của chính giao dịch — xem `CreditCardReportService`. Nhờ vậy báo cáo
 * không bao giờ lệch với lịch sử giao dịch đang có.
 *
 * `user_id` là logical reference sang `hoantienaff.users` (không FK cross-DB),
 * kế thừa từ {@see CreditCardModel}.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $type
 * @property array<int, int>|null $excluded_category_ids
 */
class Report extends CreditCardModel
{
    /** Chi tiêu theo THẺ: một dòng một thẻ. */
    public const TYPE_BY_CARD = 'by_card';

    /** Chi tiêu theo DANH MỤC: một dòng một danh mục, mỗi thẻ hai cột. */
    public const TYPE_BY_CATEGORY = 'by_category';

    protected $table = 'credit_card_reports';

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'excluded_category_ids',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'excluded_category_ids' => 'array',
        ];
    }

    /**
     * Danh sách kiểu báo cáo hợp lệ — nguồn DUY NHẤT cho validation và view.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::TYPE_BY_CARD, self::TYPE_BY_CATEGORY];
    }

    /**
     * Nhãn hiển thị của kiểu báo cáo.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_BY_CATEGORY => 'Chi tiêu theo danh mục',
            default => 'Chi tiêu theo thẻ',
        };
    }

    /**
     * ID các danh mục bị LOẠI TRỪ khi tính báo cáo theo danh mục.
     *
     * Chuẩn hoá thành danh sách int duy nhất, sẵn sàng để so với `category_id`
     * của giao dịch. Báo cáo cũ (cột `excluded_category_ids` chưa có / chưa ghi)
     * đọc ra `NULL` ⇒ coi như không loại trừ danh mục nào.
     *
     * @return array<int, int>
     */
    public function excludedCategoryIds(): array
    {
        $ids = $this->excluded_category_ids ?? [];

        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    /**
     * Thẻ nằm trong báo cáo. Bảng nối cùng connection `creditcard` nên quan hệ
     * bình thường; `withTimestamps()` khớp cột `created_at/updated_at` của bảng nối.
     */
    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(
            UserCard::class,
            'credit_card_report_cards',
            'report_id',
            'user_card_id',
        )->withTimestamps();
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
