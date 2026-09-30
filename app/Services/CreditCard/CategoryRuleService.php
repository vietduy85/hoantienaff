<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * CategoryRuleService — CRUD quy tắc cashback của một danh mục trong một bậc.
 *
 * ---------------------------------------------------------------------------
 * CASHBACK SỐNG Ở ĐÂY, KHÔNG Ở CATEGORY MASTER
 * ---------------------------------------------------------------------------
 * `credit_card_categories` KHÔNG có cột rate/cap. Mọi thông tin cashback nằm ở
 * `credit_card_policy_tier_categories`:
 *
 *   category_id                          ← danh mục chi tiêu
 *   cashback_percent                     ← tỷ lệ %
 *   max_cashback_per_transaction         ← cap mỗi giao dịch
 *   max_cashback_per_category_per_period ← cap mỗi danh mục mỗi kỳ
 *   min_transaction_amount               ← ngưỡng giao dịch tối thiểu
 *   spend_from / spend_to                ← khoảng chi tiêu danh mục trong kỳ
 *
 * Nhờ vậy cùng một danh mục có thể có tỷ lệ khác nhau theo bậc, theo khoảng chi
 * tiêu, và theo từng thẻ.
 *
 * ---------------------------------------------------------------------------
 * MỘT DANH MỤC CÓ THỂ CÓ NHIỀU MỨC
 * ---------------------------------------------------------------------------
 * Ví dụ một rule cho Shopee trong cùng bậc:
 *   [0, 5.000.000)  → 2,5%
 *   [5.000.000, ∞)  → 4,0%
 * Khoảng là nửa mở `[spend_from, spend_to)` và `spend_to = NULL` nghĩa là mở
 * vô hạn.
 */
class CategoryRuleService
{
    /**
     * Rule của một bậc.
     *
     * @return Collection<int, PolicyTierCategory>
     */
    public function listFor(PolicyTier $tier)
    {
        return $tier->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Rule phải thuộc bậc của policy version CHƯA bị đóng.
     */
    public function findEditable(int $ruleId): PolicyTierCategory
    {
        $rule = PolicyTierCategory::query()->whereKey($ruleId)->first();

        if ($rule === null) {
            throw new InvalidArgumentException("Rule #{$ruleId} không tồn tại.");
        }

        $tier = PolicyTier::query()->whereKey($rule->tier_id)->first();

        if ($tier === null) {
            throw new LogicException('Rule không thuộc bậc nào.');
        }

        $policy = Policy::query()->whereKey($tier->policy_id)->first();

        if ($policy === null) {
            throw new LogicException('Bậc không thuộc policy version nào.');
        }

        $this->assertMutable($policy);

        return $rule;
    }

    /**
     * Tạo rule trong một bậc.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(PolicyTier $tier, array $attributes): PolicyTierCategory
    {
        $policy = $this->policyOfTier($tier);
        $this->assertMutable($policy);

        $categoryId = (int) $attributes['category_id'];

        // Kiểm tra TRƯỚC khi insert: `credit_card_categories` không có bản ghi
        // thì insert sẽ chết vì FK, còn "đã bị ẩn" thì phải báo đúng nguyên nhân.
        $this->assertCategoryUsable($categoryId);

        return DB::connection('creditcard')->transaction(function () use ($tier, $attributes, $categoryId): PolicyTierCategory {
            $rule = PolicyTierCategory::create([
                'tier_id' => $tier->id,
                'category_id' => $categoryId,
                'name' => $attributes['name'] ?? null,
                'sort_order' => (int) ($attributes['sort_order'] ?? $this->nextSortOrder($tier->id)),
                'spend_from' => $this->normalizeMoney($attributes['spend_from'] ?? 0),
                'spend_to' => $this->normalizeMoney($attributes['spend_to'] ?? null),
                'cashback_percent' => $this->normalizePercent($attributes['cashback_percent'] ?? 0),
                'max_cashback_per_transaction' => $this->normalizeMoney($attributes['max_cashback_per_transaction'] ?? null),
                'max_cashback_per_category_per_period' => $this->normalizeMoney($attributes['max_cashback_per_category_per_period'] ?? null),
                'min_transaction_amount' => $this->normalizeMoney($attributes['min_transaction_amount'] ?? null),
                'is_enabled' => (bool) ($attributes['is_enabled'] ?? true),
                'note' => $attributes['note'] ?? null,
            ]);

            $this->assertBandIsSane($rule);

            return $rule->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(int $ruleId, array $attributes): PolicyTierCategory
    {
        $rule = $this->findEditable($ruleId);

        return DB::connection('creditcard')->transaction(function () use ($rule, $attributes): PolicyTierCategory {
            if (array_key_exists('category_id', $attributes)) {
                $rule->category_id = (int) $attributes['category_id'];
            }

            if (array_key_exists('name', $attributes)) {
                $rule->name = $attributes['name'];
            }

            if (array_key_exists('sort_order', $attributes)) {
                $rule->sort_order = (int) $attributes['sort_order'];
            }

            if (array_key_exists('spend_from', $attributes)) {
                $rule->spend_from = $this->normalizeMoney($attributes['spend_from']);
            }

            if (array_key_exists('spend_to', $attributes)) {
                $rule->spend_to = $this->normalizeMoney($attributes['spend_to']);
            }

            if (array_key_exists('cashback_percent', $attributes)) {
                $rule->cashback_percent = $this->normalizePercent($attributes['cashback_percent']);
            }

            if (array_key_exists('max_cashback_per_transaction', $attributes)) {
                $rule->max_cashback_per_transaction = $this->normalizeMoney($attributes['max_cashback_per_transaction']);
            }

            if (array_key_exists('max_cashback_per_category_per_period', $attributes)) {
                $rule->max_cashback_per_category_per_period = $this->normalizeMoney($attributes['max_cashback_per_category_per_period']);
            }

            if (array_key_exists('min_transaction_amount', $attributes)) {
                $rule->min_transaction_amount = $this->normalizeMoney($attributes['min_transaction_amount']);
            }

            if (array_key_exists('is_enabled', $attributes)) {
                $rule->is_enabled = (bool) $attributes['is_enabled'];
            }

            if (array_key_exists('note', $attributes)) {
                $rule->note = $attributes['note'];
            }

            $this->assertBandIsSane($rule);
            $this->assertCategoryUsable($rule->category_id);

            $rule->save();

            return $rule->refresh();
        });
    }

    /**
     * Xoá rule.
     *
     * Rule thuộc version lịch sử vẫn KHÔNG xoá được (xem `findEditable`), vì giao
     * dịch đã snapshot `policy_tier_category_id` trỏ tới chính rule này.
     */
    public function delete(int $ruleId): void
    {
        $rule = $this->findEditable($ruleId);

        DB::connection('creditcard')->transaction(function () use ($rule): void {
            $rule->delete();
        });
    }

    /**
     * Nhân bản một rule sang bậc khác (cùng hoặc khác policy version).
     *
     * Dùng khi: copy cấu hình sang version mới, hoặc copy bậc mẫu sang bậc thật.
     * Bản sao là bản ghi RIÊNG — sửa bản ghi nguồn không ảnh hưởng bản sao.
     */
    public function cloneRuleTo(PolicyTierCategory $source, PolicyTier $targetTier, ?int $sortOrder = null): PolicyTierCategory
    {
        $policy = $this->policyOfTier($targetTier);
        $this->assertMutable($policy);

        return DB::connection('creditcard')->transaction(function () use ($source, $targetTier, $sortOrder): PolicyTierCategory {
            return PolicyTierCategory::create([
                'tier_id' => $targetTier->id,
                'category_id' => $source->category_id,
                'name' => $source->name,
                'sort_order' => $sortOrder ?? $this->nextSortOrder($targetTier->id),
                'spend_from' => $source->spend_from,
                'spend_to' => $source->spend_to,
                'cashback_percent' => $source->cashback_percent,
                'max_cashback_per_transaction' => $source->max_cashback_per_transaction,
                'max_cashback_per_category_per_period' => $source->max_cashback_per_category_per_period,
                'min_transaction_amount' => $source->min_transaction_amount,
                'is_enabled' => $source->is_enabled,
                'note' => $source->note,
            ]);
        });
    }

    /**
     * Copy TOÀN BỘ rule của một bậc sang bậc khác — tiện cho "clone cấu hình".
     *
     * @return Collection<int, PolicyTierCategory>
     */
    public function cloneAllTo(PolicyTier $source, PolicyTier $targetTier)
    {
        $copied = collect();

        DB::connection('creditcard')->transaction(function () use ($source, $targetTier, &$copied): void {
            foreach ($this->listFor($source) as $rule) {
                $copied->push($this->cloneRuleTo($rule, $targetTier, (int) $rule->sort_order));
            }
        });

        return $copied;
    }

    /**
     * Danh mục có thể dùng trong rule không?
     *
     * Phải tồn tại và đang active. Danh mục inactive vẫn còn trong rule cũ (đó là
     * chủ ý — dữ liệu lịch sử phải đọc được), nhưng không được thêm mới.
     */
    private function assertCategoryUsable(int $categoryId): void
    {
        $exists = Category::query()->active()->whereKey($categoryId)->exists();

        if (! $exists) {
            throw new InvalidArgumentException("Danh mục #{$categoryId} không tồn tại hoặc đã bị ẩn.");
        }
    }

    private function policyOfTier(PolicyTier $tier): Policy
    {
        $policy = Policy::query()->whereKey($tier->policy_id)->first();

        if ($policy === null) {
            throw new LogicException('Bậc không thuộc policy version nào.');
        }

        return $policy;
    }

    private function assertMutable(Policy $policy): void
    {
        if ($policy->is_locked) {
            throw new LogicException('Policy version đang bị khoá (kỳ đã finalize), không được sửa rule.');
        }

        if ($policy->status === Policy::STATUS_SUPERSEDED) {
            throw new LogicException('Policy version đã bị thay thế, không được sửa rule. Hãy tạo version mới.');
        }
    }

    /**
     * Khoảng chi tiêu của rule phải hợp lý: min ≥ 0 và max > min (nếu có).
     */
    private function assertBandIsSane(PolicyTierCategory $rule): void
    {
        $from = (float) $rule->spend_from;
        $to = $rule->spend_to === null ? null : (float) $rule->spend_to;

        if ($from < 0) {
            throw new InvalidArgumentException('spend_from không được âm.');
        }

        if ($to !== null && $to <= $from) {
            throw new InvalidArgumentException('spend_to phải lớn hơn spend_from.');
        }
    }

    private function nextSortOrder(int $tierId): int
    {
        return ((int) PolicyTierCategory::query()->where('tier_id', $tierId)->max('sort_order')) + 1;
    }

    private function normalizePercent(mixed $value): string
    {
        $percent = (float) $value;

        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('cashback_percent phải từ 0 đến 100.');
        }

        return number_format($percent, 3, '.', '');
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
