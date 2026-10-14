<?php

namespace App\Services\CreditCard\Optimizer;

use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\SpendQualification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * CardScaffold — dữ liệu "hồ sơ" của một thẻ cho bộ tối ưu.
 *
 * Được build một lần cho mỗi thẻ trong phạm vi, rồi tái sử dụng qua nhiều lần
 * chạy (nhiều tiêu chí / nhiều config). Mọi dữ liệu phụ thuộc bậc được gom sẵn
 * theo version (rules theo tier, caps giao dịch theo tier, rule positive) để phần
 * đánh giá thực thi thuần như `CashbackCalculator` — không query DB.
 *
 * Scaffold CỐ Ý không mang giao dịch thực của kỳ: optimizer chạy trên kỳ mô phỏng
 * mới nên mọi hạn mức/trần cashback đều đầy đủ theo chính sách.
 */
final class CardScaffold
{
    public const HYPO_ID_OFFSET_OWNED = 900_000_000_000;

    public const HYPO_ID_OFFSET_MARKET = 800_000_000_000;

    public function __construct(
        public readonly string $key,
        public readonly bool $isOwned,
        public readonly string $name,
        public readonly ?string $bankName,
        public readonly ?int $cardId,
        public readonly ?int $templateId,
        public readonly ?PolicyVersion $version,
        /** @var Collection<int, \App\Models\CreditCard\PolicyTier> */
        public readonly Collection $tiers,
        /** @var array<int, array<int, array<string, mixed>>> tierId => rules */
        public readonly array $rulesByTier,
        /** @var array<int, array<int, array<string, mixed>>> tierId => caps giao dịch */
        public readonly array $capsByTier,
        /** @var array<int, array<string, mixed>> */
        public readonly array $allEnabledRules,
        public readonly ?CarbonImmutable $periodEnd,
        public readonly float $minTotalSpend,
        public readonly string $roundingMode,
        public readonly ?SpendQualification $qualification,
        public readonly ?string $qualificationNotice,
        public readonly string $marketAssumption,
        public readonly int $idNumber,
        /** Vị trí instance (1-based) khi cùng một sản phẩm được dùng nhiều bản. */
        public readonly int $instanceIndex = 1,
        /** Khoá nhóm bản sao của cùng một sản phẩm (mặc định = key). */
        public readonly ?string $productKey = null,
        /** Slug sản phẩm (policy template) — dùng để nhận diện sản phẩm đặc thù. */
        public readonly ?string $templateSlug = null,
    ) {
    }

    /** Khoá nhóm bản sao — dùng để nhận diện nhiều thẻ CÙNG sản phẩm. */
    public function groupKey(): string
    {
        return $this->productKey ?? $this->key;
    }

    public static function hypoIdBase(bool $isOwned): int
    {
        return $isOwned ? self::HYPO_ID_OFFSET_OWNED : self::HYPO_ID_OFFSET_MARKET;
    }
}