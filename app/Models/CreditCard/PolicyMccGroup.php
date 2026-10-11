<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * PolicyMccGroup — một NHÓM MCC theo tài liệu của một chính sách hoàn tiền.
 *
 * Dữ liệu ADMIN nhập tay cho module "Quản lý MCC". Khác với
 * {@see CardPolicyMccMapping} (bản ghi import, append-only):
 *
 *   - `reference_name`: tên danh mục THAM KHẢO dạng text tự do. KHÔNG liên kết
 *     `credit_card_categories`, không tạo danh mục hệ thống, không đổi cashback.
 *   - `scope_mode`:
 *       list — áp dụng đúng danh sách mã ở `codes` (role = applied).
 *       all  — "Tất cả MCC" (không liệt kê), chỉ loại trừ các mã role = excluded.
 *   - `display_order`: thứ tự hiển thị theo tài liệu.
 *
 * Một mã trong cùng một nhóm chỉ có MỘT vai trò (UNIQUE [group, mcc]): không thể
 * vừa nằm trong danh sách áp dụng vừa nằm trong danh sách loại trừ riêng.
 *
 * @property int $id
 * @property int|null $policy_template_id
 * @property string|null $reference_name
 * @property string $scope_mode
 * @property string|null $description
 * @property string|null $note
 * @property int $display_order
 */
class PolicyMccGroup extends CreditCardModel
{
    public const SCOPE_LIST = 'list';

    public const SCOPE_ALL = 'all';

    protected $table = 'credit_card_policy_mcc_groups';

    protected $fillable = [
        'policy_template_id',
        'reference_name',
        'scope_mode',
        'description',
        'note',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'policy_template_id' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function isAllScope(): bool
    {
        return $this->scope_mode === self::SCOPE_ALL;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PolicyTemplate::class, 'policy_template_id');
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PolicyMccGroupCode::class, 'policy_mcc_group_id')
            ->orderBy('mcc');
    }

    public function appliedCodes(): HasMany
    {
        return $this->codes()->where('role', PolicyMccGroupCode::ROLE_APPLIED);
    }

    public function excludedCodes(): HasMany
    {
        return $this->hasMany(PolicyMccGroupCode::class, 'policy_mcc_group_id')
            ->where('role', PolicyMccGroupCode::ROLE_EXCLUDED);
    }

    public function scopeForTemplate(Builder $query, int $templateId): Builder
    {
        return $query->where('policy_template_id', $templateId);
    }

    /**
     * Danh sách mã (chuỗi 4 ký tự) theo vai trò.
     *
     * @return Collection<int, string>
     */
    public function codesByRole(string $role): Collection
    {
        return $this->codes
            ->where('role', $role)
            ->pluck('mcc')
            ->map(fn ($code): string => (string) $code)
            ->values();
    }
}
