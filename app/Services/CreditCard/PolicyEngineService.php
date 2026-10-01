<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * PolicyEngineService — điểm vào duy nhất để "thẻ này + ngày này dùng policy nào".
 *
 * Không giữ state giữa các lần gọi: mọi lần gọi đều resolve lại từ DB. Nhờ đó
 * `CashbackRecordService` có thể tính lại nhiều lần cho cùng một kỳ mà không
 * phải lo dữ liệu cache cũ làm sai kết quả.
 */
class PolicyEngineService
{
    public function __construct(
        private readonly TierResolverService $tiers,
    ) {}

    /**
     * Gắn policy version đã resolve vào một kỳ sao kê.
     *
     * Dùng `period_end` làm mốc hiệu lực: kỳ sao kê được tính theo business
     * rule của version đang hiệu lực TẠI LÚC KỲ KẾT THÚC.
     *
     * Kỳ đã finalize là bản ghi lịch sử ⇒ không đổi policy nữa.
     */
    public function attachPolicyToPeriod(UserCard $userCard, StatementPeriod $period): ?PolicyVersion
    {
        if ($period->isFinalized()) {
            return $period->policy;
        }

        $version = $this->tiers->resolvePolicyVersion($userCard, $period->period_end);

        if ((int) $period->user_card_id !== (int) $userCard->id) {
            throw new LogicException('Kỳ sao kê không thuộc thẻ này.');
        }

        $period->forceFill(['policy_id' => $version?->id])->save();

        return $version;
    }

    /**
     * Bộ tham số đầy đủ cho `CashbackCalculator` tại một ngày.
     *
     * Trả null khi thẻ chưa có policy version nào hiệu lực — khi đó mọi giao
     * dịch đều không cashback với lý do `no_policy_version`.
     */
    public function resolveFor(UserCard $userCard, DateTimeInterface $date): ?ResolvedPolicy
    {
        $version = $this->tiers->resolvePolicyVersion($userCard, $date);

        if ($version === null) {
            return null;
        }

        return new ResolvedPolicy(
            policyVersion: $version,
            root: $version->isRoot() ? $version : $version->root,
            minTotalSpend: (float) $version->min_total_spend,
            roundingMode: (string) $version->rounding_mode,
        );
    }

    /**
     * Tất cả version của thẻ, sắp xếp theo version_no.
     *
     * @return Collection<int, PolicyVersion>
     */
    public function versionsOf(UserCard $userCard)
    {
        $current = $userCard->currentPolicy;

        if ($current === null) {
            return PolicyVersion::query()
                ->where('user_card_id', $userCard->id)
                ->orderBy('version_no')
                ->get();
        }

        return $this->tiers->chainOf($current);
    }

    /**
     * Bậc + rule đã hydrate cho một version, theo tổng chi tiêu `total`.
     *
     * @return array{tier: ?PolicyTier, rules: array<int, array<string, mixed>>}
     */
    public function tierAndRules(PolicyVersion $version, float $total): array
    {
        $tier = $this->tiers->resolveTier($version, $total);

        return [
            'tier' => $tier,
            'rules' => $this->tiers->rulesForTier($tier),
        ];
    }

    /**
     * Danh mục nào đang được dùng bởi các version của thẻ (để biết danh mục nào
     * KHÔNG xoá cứng được).
     *
     * @return Collection<int, int>
     */
    public function usedCategoryIds(UserCard $userCard)
    {
        $versionIds = $this->versionsOf($userCard)->pluck('id');

        return PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::query()->whereIn('policy_id', $versionIds)->select('id'))
            ->distinct()
            ->pluck('category_id');
    }

    public function currentVersion(UserCard $userCard): ?PolicyVersion
    {
        return $this->versionsOf($userCard)
            ->first(fn (Policy $policy): bool => $policy->status === Policy::STATUS_ACTIVE);
    }

    /**
     * Khoá policy version (kỳ đã finalize) — chặn tính lại tự động.
     */
    public function lockVersion(PolicyVersion $version): void
    {
        DB::connection('creditcard')->transaction(function () use ($version): void {
            $version->forceFill(['is_locked' => true])->save();
        });
    }
}
