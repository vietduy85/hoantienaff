<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * Ba trường `annual_fee_primary` / `annual_fee_supplementary` /
 * `annual_spend_for_annual_fee_waiver` lưu PHÍ THƯỜNG NIÊN (VNĐ/năm, `decimal(16,2)`,
 * nullable) — CHỈ LÀ THÔNG TIN THAM KHẢO của hồ sơ, KHÔNG ảnh hưởng tính cashback,
 * blueprint hay ví tiền. NULL = chưa có thông tin đáng tin cậy; không tự điền 0.
 *
 * @property int $id
 * @property int $policy_template_id
 * @property string $name
 * @property string|null $product_name
 * @property string|null $note
 * @property string|null $annual_fee_primary
 * @property string|null $annual_fee_supplementary
 * @property string|null $annual_spend_for_annual_fee_waiver
 */
class PolicyMccProfile extends CreditCardModel
{
    protected $table = 'credit_card_policy_mcc_profiles';

    protected $fillable = [
        'policy_template_id',
        'name',
        'product_name',
        'note',
        'annual_fee_primary',
        'annual_fee_supplementary',
        'annual_spend_for_annual_fee_waiver',
    ];

    protected function casts(): array
    {
        return [
            'policy_template_id' => 'integer',
            'annual_fee_primary' => 'decimal:2',
            'annual_fee_supplementary' => 'decimal:2',
            'annual_spend_for_annual_fee_waiver' => 'decimal:2',
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

    /**
     * Liên kết tới các template DÙNG CHUNG hồ sơ (ngoài template sở hữu).
     *
     * @return HasMany<PolicyMccProfileTemplateLink, $this>
     */
    public function templateLinks(): HasMany
    {
        return $this->hasMany(PolicyMccProfileTemplateLink::class, 'policy_mcc_profile_id');
    }

    /**
     * Các template dùng chung hồ sơ này (không gồm template sở hữu).
     *
     * @return BelongsToMany<PolicyTemplate, $this>
     */
    public function sharedTemplates(): BelongsToMany
    {
        return $this->belongsToMany(
            PolicyTemplate::class,
            'credit_card_policy_mcc_profile_template_links',
            'policy_mcc_profile_id',
            'policy_template_id',
        );
    }

    /**
     * Hồ sơ áp dụng cho một template: hồ sơ SỞ HỮU template đó
     * (`policy_template_id`) HOẶC hồ sơ liên kết dùng chung qua bảng pivot.
     */
    public function scopeForTemplate(Builder $query, int $templateId): Builder
    {
        return $query->where(function (Builder $inner) use ($templateId): void {
            $inner->where('policy_template_id', $templateId)
                ->orWhereHas('templateLinks', fn (Builder $links) => $links->where('policy_template_id', $templateId));
        });
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
     * Tất cả template mà hồ sơ áp dụng: template SỞ HỮU + các template dùng chung.
     *
     * Dùng để mở rộng phạm vi một quy tắc (vd loại trừ MCC) cho MỌI hạng thẻ của
     * cùng chương trình, không chỉ hạng sở hữu hồ sơ.
     *
     * @return array<int, int>
     */
    public function allTemplateIds(): array
    {
        return collect([(int) $this->policy_template_id])
            ->merge($this->templateLinks->pluck('policy_template_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
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
