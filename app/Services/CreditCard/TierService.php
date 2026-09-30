<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * TierService — CRUD bậc chi tiêu. RETROACTIVE, KHÔNG có progressive.
 *
 * ---------------------------------------------------------------------------
 * RETROACTIVE LÀ BẤT BIẾN, KHÔNG PHẢI TUỲ CHỌN
 * ---------------------------------------------------------------------------
 * Bậc được chọn theo TỔNG eligible spend của CẢ kỳ; khi tổng đổi, bậc của mọi
 * giao dịch trong kỳ cũng đổi theo (`CashbackRecordService::calculatePeriod()`
 * luôn tính lại toàn bộ kỳ).
 *
 * Vì vậy service này:
 *   - KHÔNG có tham số `tier_application_mode` / `progressive` ở đâu cả,
 *   - KHÔNG có cột nào như vậy trong DB,
 *   - KHÔNG chấp nhận input nào mang tên `progressive`.
 *
 * Nếu sau này cần nhiều chế độ, phải là MỘT feature riêng có migration + tính
 * lại pipeline, không được bật "progressive" như một cờ.
 *
 * ---------------------------------------------------------------------------
 * BẤT BIẾN LỊCH SỬ
 * ---------------------------------------------------------------------------
 * Bậc thuộc một policy VERSION. Nếu version đó `superseded` hoặc `is_locked`
 * (kỳ đã finalize) thì KHÔNG sửa/xoá được: giao dịch lịch sử đã snapshot tier
 * đó. Muốn đổi cấu hình ⇒ tạo version mới qua `PolicyService::createVersion()`.
 */
class TierService
{
    /**
     * Bậc của một version, theo thứ tự chi tiêu.
     *
     * @return Collection<int, PolicyTier>
     */
    public function listFor(Policy $policy)
    {
        return $policy->tiers()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Bậc phải thuộc policy version CHƯA bị đóng. Ném exception nếu thuộc version
     * lịch sử — đây là lớp phòng thủ sau Policy.
     */
    public function findEditable(int $tierId): PolicyTier
    {
        $tier = PolicyTier::query()->whereKey($tierId)->first();

        if ($tier === null) {
            throw new InvalidArgumentException("Bậc #{$tierId} không tồn tại.");
        }

        $policy = Policy::query()->whereKey($tier->policy_id)->first();

        if ($policy === null) {
            throw new LogicException('Bậc không thuộc policy version nào.');
        }

        $this->assertMutable($policy);

        return $tier;
    }

    /**
     * Tạo bậc mới trong một policy version.
     *
     * @param  array{name:string, sort_order?:int|null, min_total_spend?:mixed, max_total_spend?:mixed}  $attributes
     */
    public function create(Policy $policy, array $attributes): PolicyTier
    {
        $this->assertMutable($policy);

        return DB::connection('creditcard')->transaction(function () use ($policy, $attributes): PolicyTier {
            $tier = PolicyTier::create([
                'policy_id' => $policy->id,
                'name' => $this->normalizeName($attributes['name']),
                'sort_order' => (int) ($attributes['sort_order'] ?? $this->nextSortOrder($policy->id)),
                'min_total_spend' => $this->normalizeMoney($attributes['min_total_spend'] ?? 0),
                'max_total_spend' => $this->normalizeMoney($attributes['max_total_spend'] ?? null),
            ]);

            // Khoảng đảo ngược (min > max) hoặc chồng lấn bậc đã có ⇒ chặn.
            // Kiểm sau insert nhưng trong transaction: ném exception sẽ rollback,
            // và sau khi insert mới đọc được đủ bậc của policy để so sánh chồng lấn.
            $this->assertBandIsSane($tier);
            $this->assertNoOverlappingBands($policy);

            return $tier->refresh();
        });
    }

    /**
     * @param  array{name?:string, sort_order?:int, min_total_spend?:mixed, max_total_spend?:mixed}  $attributes
     */
    public function update(int $tierId, array $attributes): PolicyTier
    {
        $tier = $this->findEditable($tierId);

        return DB::connection('creditcard')->transaction(function () use ($tier, $attributes): PolicyTier {
            if (array_key_exists('name', $attributes)) {
                $tier->name = $this->normalizeName($attributes['name']);
            }

            if (array_key_exists('sort_order', $attributes)) {
                $tier->sort_order = (int) $attributes['sort_order'];
            }

            if (array_key_exists('min_total_spend', $attributes)) {
                $tier->min_total_spend = $this->normalizeMoney($attributes['min_total_spend']);
            }

            if (array_key_exists('max_total_spend', $attributes)) {
                $tier->max_total_spend = $this->normalizeMoney($attributes['max_total_spend']);
            }

            $this->assertBandIsSane($tier);

            $tier->save();

            return $tier->refresh();
        });
    }

    /**
     * Xoá bậc.
     *
     * Chặn khi còn cashback rule bên trong: xoá bậc mà mang rule sẽ âm thầm làm
     * mất cấu hình cashback. Bắt buộc xoá/xoá-mềm rule trước.
     */
    public function delete(int $tierId): void
    {
        $tier = $this->findEditable($tierId);

        $ruleCount = PolicyTierCategory::query()->where('tier_id', $tier->id)->count();

        if ($ruleCount > 0) {
            throw new LogicException(
                "Bậc còn {$ruleCount} rule cashback. Xoá các rule trước khi xoá bậc."
            );
        }

        DB::connection('creditcard')->transaction(function () use ($tier): void {
            $tier->delete();
        });
    }

    /**
     * Nhân bản cấu trúc bậc (khoảng + tên) sang một policy version khác.
     *
     * KHÔNG copy rule: `CategoryRuleService::cloneRuleTo()` làm việc đó. Tách
     * hai bước để khi copy cấu hình, caller chủ động chọn danh mục nào muốn mang
     * sang — tránh nhân bản cả rule của danh mục đã bị gỡ.
     */
    public function cloneTo(PolicyTier $source, Policy $targetPolicy, ?int $sortOrder = null): PolicyTier
    {
        $this->assertMutable($targetPolicy);

        return DB::connection('creditcard')->transaction(function () use ($source, $targetPolicy, $sortOrder): PolicyTier {
            return PolicyTier::create([
                'policy_id' => $targetPolicy->id,
                'name' => $source->name,
                'sort_order' => $sortOrder ?? $this->nextSortOrder($targetPolicy->id),
                'min_total_spend' => $source->min_total_spend,
                'max_total_spend' => $source->max_total_spend,
            ]);
        });
    }

    /**
     * Kiểm tra các khoảng bậc không chồng lấn.
     *
     * Khoảng là nửa mở `[min, max)`. Hai bậc chồng nhau làm `TierResolverService`
     * phải đoán, và kết quả sẽ phụ thuộc thứ tự id — tức là cashback của user
     * thay đổi theo cách hệ thống lưu bản ghi, không phải theo cấu hình.
     */
    public function assertNoOverlappingBands(Policy $policy): void
    {
        $bands = PolicyTier::query()
            ->where('policy_id', $policy->id)
            ->orderBy('min_total_spend')
            ->orderBy('id')
            ->get();

        $previousMax = null;
        $previousName = null;

        foreach ($bands as $band) {
            $min = (float) $band->min_total_spend;

            if ($previousMax !== null && $min < $previousMax) {
                throw new LogicException(sprintf(
                    'Khoảng bậc "%s" (%s) chồng lấn với bậc "%s" (%s). Các khoảng bậc phải không giao nhau.',
                    $band->name,
                    $this->describeBand($band),
                    $previousName,
                    $previousMax
                ));
            }

            $previousMax = $band->max_total_spend === null
                ? PHP_FLOAT_MAX
                : (float) $band->max_total_spend;
            $previousName = $band->name;
        }
    }

    /**
     * Version đã bị đóng thì business rule bất biến.
     */
    private function assertMutable(Policy $policy): void
    {
        if ($policy->is_locked) {
            throw new LogicException('Policy version đang bị khoá (kỳ đã finalize), không được sửa bậc.');
        }

        if ($policy->status === Policy::STATUS_SUPERSEDED) {
            throw new LogicException('Policy version đã bị thay thế, không được sửa bậc. Hãy tạo version mới.');
        }
    }

    private function assertBandIsSane(PolicyTier $tier): void
    {
        $min = (float) $tier->min_total_spend;
        $max = $tier->max_total_spend === null ? null : (float) $tier->max_total_spend;

        if ($min < 0) {
            throw new InvalidArgumentException('min_total_spend không được âm.');
        }

        if ($max !== null && $max <= $min) {
            throw new InvalidArgumentException('max_total_spend phải lớn hơn min_total_spend.');
        }
    }

    private function describeBand(PolicyTier $tier): string
    {
        $max = $tier->max_total_spend === null ? '∞' : (string) $tier->max_total_spend;

        return "[{$tier->min_total_spend}, {$max})";
    }

    private function nextSortOrder(int $policyId): int
    {
        return ((int) PolicyTier::query()->where('policy_id', $policyId)->max('sort_order')) + 1;
    }

    private function normalizeName(string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Tên bậc không được để trống.');
        }

        return $name;
    }

    private function normalizeMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = (float) $value;

        if ($amount < 0) {
            throw new InvalidArgumentException('Số tiền không được âm.');
        }

        return number_format($amount, 2, '.', '');
    }
}
