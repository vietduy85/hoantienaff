<?php

namespace App\Models\CreditCard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CardPolicyMccMapping — ánh xạ MCC ↔ chính sách hoàn tiền của thẻ.
 *
 * KHÔNG lưu lại tên/mức hoàn tiền của chính sách: chỉ tham chiếu tới dữ liệu đã
 * có (`policy_template_id`, `category_id`, `policy_tier_category_id`).
 *
 * ---------------------------------------------------------------------------
 * match_type — BIỂU DIỄN QUY TẮC
 * ---------------------------------------------------------------------------
 *   direct     : một MCC ở `mcc`.
 *   group      : nhóm MCC — mã thành viên ở `mcc_codes`.
 *   all        : mọi MCC (không liệt kê).
 *   all_except : mọi MCC trừ các mã ngoại lệ ở `mcc_codes`.
 *
 * `fingerprint` (UNIQUE) được sinh tự động khi lưu nên import chạy lại không tạo
 * bản ghi trùng.
 *
 * @property int $id
 * @property int|null $policy_template_id
 * @property int|null $category_id
 * @property int|null $policy_tier_category_id
 * @property string $match_type
 * @property string|null $mcc
 * @property string|null $mcc_group
 * @property array<int, string>|null $mcc_codes
 * @property string $application_status
 * @property string|null $cashback_percent
 * @property string|null $condition_note
 * @property string|null $exclusion_reason
 * @property string|null $source_pdf
 * @property string|null $source_page
 * @property string $verification_status
 * @property CarbonImmutable|null $verified_at
 * @property string $fingerprint
 */
class CardPolicyMccMapping extends CreditCardModel
{
    public const MATCH_DIRECT = 'direct';

    public const MATCH_GROUP = 'group';

    public const MATCH_ALL = 'all';

    public const MATCH_ALL_EXCEPT = 'all_except';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_EXCLUDED = 'excluded';

    public const STATUS_UNVERIFIED = 'unverified';

    protected $table = 'credit_card_policy_mcc_mappings';

    protected $fillable = [
        'policy_template_id',
        'category_id',
        'policy_tier_category_id',
        'match_type',
        'mcc',
        'mcc_group',
        'mcc_codes',
        'application_status',
        'cashback_percent',
        'condition_note',
        'exclusion_reason',
        'source_pdf',
        'source_page',
        'verification_status',
        'verified_at',
        'fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'policy_template_id' => 'integer',
            'category_id' => 'integer',
            'policy_tier_category_id' => 'integer',
            'match_type' => 'string',
            'mcc_codes' => 'array',
            'application_status' => 'string',
            'cashback_percent' => 'decimal:3',
            'verification_status' => 'string',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $mapping->fingerprint = $mapping->buildFingerprint();
        });
    }

    /**
     * Khoá tự nhiên đã băm — dùng để import idempotent.
     *
     * Bao gồm mọi cột định danh một quy tắc: chính sách, loại quy tắc, MCC đơn,
     * nhãn nhóm, danh sách mã, và danh mục. KHÔNG bao gồm mức hoàn tiền/ghi chú
     * vì đó là thuộc tính có thể cập nhật.
     */
    public function buildFingerprint(): string
    {
        $codes = $this->mcc_codes ?? [];

        if (is_array($codes)) {
            sort($codes);
        }

        return sha1(implode('|', [
            (string) ($this->policy_template_id ?? ''),
            (string) ($this->match_type ?? ''),
            (string) ($this->mcc ?? ''),
            (string) ($this->mcc_group ?? ''),
            json_encode($codes),
            (string) ($this->category_id ?? ''),
        ]));
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PolicyTemplate::class, 'policy_template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function tierCategory(): BelongsTo
    {
        return $this->belongsTo(PolicyTierCategory::class, 'policy_tier_category_id');
    }

    public function isExcluded(): bool
    {
        return $this->application_status === self::STATUS_EXCLUDED;
    }

    public function scopeForTemplate(Builder $query, int $templateId): Builder
    {
        return $query->where('policy_template_id', $templateId);
    }
}
