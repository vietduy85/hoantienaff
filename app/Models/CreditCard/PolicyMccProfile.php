<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * PolicyMccProfile — "Hồ sơ MCC chính sách".
 *
 * Một hồ sơ thuộc về MỘT chính sách hoàn tiền ({@see PolicyTemplate}) và chứa:
 *   - tên RIÊNG của hồ sơ (`name`), không đổi tên template gốc;
 *   - ghi chú chung (`note`);
 *   - các DÒNG MCC ({@see PolicyMccProfileRow}) loại `included` / `excluded`;
 *   - tài liệu ngân hàng đính kèm ({@see PolicyMccProfileDocument}).
 *
 * KHÔNG lưu tỷ lệ hoàn tiền ở đây: tỷ lệ/hạn mức của DÒNG luôn đọc từ blueprint
 * hoặc từ bảng con `credit_card_policy_mcc_row_benefits` khi tra cứu.
 *
 * `product_name` — TÊN SẢN PHẨM ĐẠI DIỆN cho chương trình dùng chung nhiều hạng
 * thẻ (ví dụ VIB Max Card Visa / VIB One Card Visa). Khi có giá trị, "Tra cứu MCC"
 * gộp các kết quả của cùng sản phẩm về một kết quả mang tên này. NULL = giữ hành
 * vi cũ (lấy tên template).
 *
 * @property int $id
 * @property int $policy_template_id
 * @property string $name
 * @property string|null $product_name
 * @property string|null $note
 */
class PolicyMccProfile extends CreditCardModel
{
    protected $table = 'credit_card_policy_mcc_profiles';

    protected $fillable = [
        'policy_template_id',
        'name',
        'product_name',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'policy_template_id' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PolicyTemplate::class, 'policy_template_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PolicyMccProfileRow::class, 'policy_mcc_profile_id')
            ->orderBy('row_type')
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function includedRows(): HasMany
    {
        return $this->hasMany(PolicyMccProfileRow::class, 'policy_mcc_profile_id')
            ->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function excludedRows(): HasMany
    {
        return $this->hasMany(PolicyMccProfileRow::class, 'policy_mcc_profile_id')
            ->where('row_type', PolicyMccProfileRow::TYPE_EXCLUDED)
            ->orderBy('display_order')
            ->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PolicyMccProfileDocument::class, 'policy_mcc_profile_id');
    }

    public function scopeForTemplate(Builder $query, int $templateId): Builder
    {
        return $query->where('policy_template_id', $templateId);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->where('name', 'like', '%'.$term.'%')
                ->orWhereHas('template', fn (Builder $template) => $template->where('name', 'like', '%'.$term.'%'));
        });
    }

    /**
     * Danh sách mã MCC (4 ký tự) của hồ sơ theo loại dòng, đã bỏ trùng.
     *
     * @return Collection<int, string>
     */
    public function codeList(string $rowType): Collection
    {
        return PolicyMccProfileRowCode::query()
            ->whereHas('row', fn (Builder $query) => $query
                ->where('policy_mcc_profile_id', $this->id)
                ->where('row_type', $rowType))
            ->orderBy('mcc')
            ->pluck('mcc')
            ->map(fn ($code): string => (string) $code)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    public function includedCodes(): Collection
    {
        return $this->codeList(PolicyMccProfileRow::TYPE_INCLUDED);
    }

    /**
     * @return Collection<int, string>
     */
    public function excludedCodes(): Collection
    {
        return $this->codeList(PolicyMccProfileRow::TYPE_EXCLUDED);
    }
}
