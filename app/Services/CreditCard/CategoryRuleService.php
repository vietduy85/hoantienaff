<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * CategoryRuleService — CRUD quy tắc cashback của một bậc.
 *
 * ---------------------------------------------------------------------------
 * CASHBACK SỐNG Ở ĐÂY, KHÔNG Ở CATEGORY MASTER
 * ---------------------------------------------------------------------------
 * `credit_card_categories` KHÔNG có cột rate/cap. Mọi thông tin cashback nằm ở
 * `credit_card_policy_tier_categories`:
 *
 *   category_id                          ← danh mục chi tiêu (NULL = fallback)
 *   cashback_percent                     ← tỷ lệ %
 *   max_cashback_per_transaction         ← cap mỗi giao dịch
 *   max_cashback_per_category_per_period ← cap mỗi danh mục mỗi kỳ
 *   min_transaction_amount               ← ngưỡng giao dịch tối thiểu
 *   spend_from / spend_to                ← khoảng chi tiêu danh mục trong kỳ
 *   scope_type                           ← category | other (fallback)
 *   counts_toward_tier_cap               ← có tính vào trần hoàn của Bậc/kỳ không
 *
 * ---------------------------------------------------------------------------
 * BA DẠNG TARGET (combo thêm ở migration 000020)
 * ---------------------------------------------------------------------------
 *   | scope_type | category_id | combo_id | Ý nghĩa                           |
 *   |------------|-------------|----------|-----------------------------------|
 *   | category   | CÓ          | NULL     | rule danh mục cụ thể              |
 *   | category   | NULL        | CÓ       | rule combo (một rule, nhiều mục)  |
 *   | other      | NULL        | NULL     | fallback "Các danh mục còn lại"   |
 *
 * Rule combo có `spend_from`/`spend_to` đo trên TỔNG chi tiêu eligible của toàn bộ
 * danh mục thành viên trong kỳ.
 *
 * ---------------------------------------------------------------------------
 * FALLBACK "📦 CÁC DANH MỤC CÒN LẠI" (BẤT BIẾN)
 * ---------------------------------------------------------------------------
 * Mỗi BẬC bắt buộc có ĐÚNG MỘT rule `scope_type = other`:
 *   - `category_id = NULL`, `combo_id = NULL`, `cashback_percent = 0`,
 *     `counts_toward_tier_cap = false`.
 * Khi giao dịch không khớp rule danh mục cụ thể nào (hoặc chỉ khớp qua fallback),
 * nó được đánh giá theo fallback. 0% vẫn LÀ một rule hợp lệ (không phải "không có
 * rule"): giao dịch đủ điều kiện nhưng nhận 0đ.
 *
 * Fallback KHÔNG xoá được; chỉ có tối đa một fallback mỗi bậc — bất biến này được
 * bảo vệ ở tầng service (mọi đường ghi đều đi qua đây) vì UNIQUE (tier_id,
 * category_id, spend_from) không chặn được trùng NULL category_id.
 *
 * ---------------------------------------------------------------------------
 * MỘT DANH MỤC CHỈ ĐƯỢC GÁN MỘT RULE TRONG MỘT BẬC
 * ---------------------------------------------------------------------------
 * Đây là bất biến mà `CashbackCalculator` dựa vào: nó gán MỘT rule cho mỗi
 * danh mục (`$ruleByCategory[$categoryId]`). Nó phải được bảo vệ ở đây cho mọi
 * dạng target:
 *
 *   - rule danh mục: chặn trùng `category_id` (xem `assertNoDuplicateCategory`).
 *   - rule combo: hai combo rule trong cùng bậc KHÔNG được chia sẻ danh mục
 *     (xem `assertNoSharedCategoryBetweenCombos`). Một danh mục ĐƯỢC nằm trong
 *     nhiều combo ở tầng dữ liệu, nhưng không được xuất hiện trong hai rule
 *     combo của cùng một bậc — nếu không thì một giao dịch sẽ khớp 2 rule và
 *     kết quả cashback tuỳ thứ tự, vi phạm tính tất định.
 *
 * Lưu ý: một danh mục vừa có rule danh mục vừa nằm trong combo rule ĐƯỢC phép —
 * engine ưu tiên rule danh mục (CATEGORY > COMBO > FALLBACK), nên vẫn tất định.
 */
class CategoryRuleService
{
    public function __construct(
        private readonly CategoryComboService $combos,
    ) {}

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
     * Tự suy target theo input (mặc định `scope_type = category` cho caller cũ):
     *   - `other`  ⇒ fallback; `category_id`/`combo_id` bắt buộc NULL; tối đa
     *     1/bậc; `counts_toward_tier_cap` mặc định false.
     *   - truyền `combo_id` ⇒ RULE COMBO: `category_id` bỏ trống, combo phải
     *     thuộc phạm vi policy và không được chia sẻ danh mục với combo rule khác
     *     trong bậc.
     *   - còn lại ⇒ rule danh mục: `category_id` bắt buộc khớp danh mục dùng
     *     được + KHÔNG trùng category với rule khác trong cùng bậc.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(PolicyTier $tier, array $attributes): PolicyTierCategory
    {
        $policy = $this->policyOfTier($tier);
        $this->assertMutable($policy);

        $scope = $attributes['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY;
        $this->assertScopeValue($scope);

        $categoryId = null;
        $comboId = null;

        if ($scope === PolicyTierCategory::SCOPE_OTHER) {
            $this->assertNoExistingFallback($tier);
        } elseif (($attributes['combo_id'] ?? null) !== null && $attributes['combo_id'] !== '') {
            $comboId = $this->assertComboId($attributes['combo_id']);
            $this->assertComboUsable($comboId, $policy);
            $this->assertNoSharedCategoryBetweenCombos($tier->id, $comboId);
        } else {
            $categoryId = $this->assertCategoryId($attributes['category_id'] ?? null);

            // Kiểm tra TRƯỚC khi insert: `credit_card_categories` không có bản ghi
            // thì insert sẽ chết vì FK, còn "đã bị ẩn" thì phải báo đúng nguyên nhân.
            $this->assertCategoryUsable($categoryId, $policy);
            $this->assertNoDuplicateCategory($tier, $categoryId);
        }

        $countsTowardTierCap = $this->booleanOr(
            $attributes['counts_toward_tier_cap'] ?? null,
            $scope === PolicyTierCategory::SCOPE_OTHER ? false : true,
        );

        return DB::connection('creditcard')->transaction(function () use ($tier, $attributes, $scope, $categoryId, $comboId, $countsTowardTierCap): PolicyTierCategory {
            $rule = PolicyTierCategory::create([
                'tier_id' => $tier->id,
                'category_id' => $categoryId,
                'combo_id' => $comboId,
                'scope_type' => $scope,
                'counts_toward_tier_cap' => $countsTowardTierCap,
                'name' => $scope === PolicyTierCategory::SCOPE_OTHER
                    ? ($attributes['name'] ?? PolicyTierCategory::FALLBACK_NAME)
                    : ($attributes['name'] ?? null),
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
        $policy = $this->policyOfTier(PolicyTier::query()->whereKey($rule->tier_id)->firstOrFail());

        $tierId = (int) $rule->tier_id;
        $scope = array_key_exists('scope_type', $attributes)
            ? (string) $attributes['scope_type']
            : ($rule->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY);
        $this->assertScopeValue($scope);

        if ($scope === PolicyTierCategory::SCOPE_OTHER) {
            // Chuyển rule này (nếu đang là category/combo) thành fallback: chặn
            // khi bậc đã có fallback KHÁC với chính rule này.
            $this->assertNoOtherFallback($tierId, $ruleId);
        }

        return DB::connection('creditcard')->transaction(function () use ($rule, $tierId, $scope, $attributes, $policy): PolicyTierCategory {
            if ($scope === PolicyTierCategory::SCOPE_OTHER) {
                $rule->scope_type = PolicyTierCategory::SCOPE_OTHER;
                $rule->category_id = null;
                $rule->combo_id = null;
            } else {
                $this->applyTarget($rule, $tierId, $attributes, $policy);
            }

            if ($scope === PolicyTierCategory::SCOPE_OTHER) {
                $rule->counts_toward_tier_cap = $this->booleanOr(
                    $attributes['counts_toward_tier_cap'] ?? null,
                    false,
                );
            } elseif (array_key_exists('counts_toward_tier_cap', $attributes)) {
                $rule->counts_toward_tier_cap = (bool) $attributes['counts_toward_tier_cap'];
            }

            if (array_key_exists('name', $attributes)) {
                $rule->name = $attributes['name'];
            } elseif ($scope === PolicyTierCategory::SCOPE_OTHER && $rule->name === null) {
                $rule->name = PolicyTierCategory::FALLBACK_NAME;
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

            $rule->save();

            return $rule->refresh();
        });
    }

    /**
     * Xoá rule.
     *
     * Rule thuộc version lịch sử vẫn KHÔNG xoá được (xem `findEditable`), vì giao
     * dịch đã snapshot `policy_tier_category_id` trỏ tới chính rule này.
     *
     * Rule fallback ("Các danh mục còn lại") KHÔNG BAO GIỜ bị xoá: nó là mặc định
     * của mỗi bậc.
     */
    public function delete(int $ruleId): void
    {
        $rule = $this->findEditable($ruleId);

        if ($rule->isFallback()) {
            throw new LogicException(
                'Quy tắc "'.PolicyTierCategory::FALLBACK_NAME.'" là quy tắc mặc định của mỗi bậc và không thể xóa.'
            );
        }

        DB::connection('creditcard')->transaction(function () use ($rule): void {
            $rule->delete();
        });
    }

    /**
     * Đảm bảo bậc có ĐÚNG MỘT fallback và trả về nó.
     *
     * Bất biến được duy trì ở MỌI đường ghi cấu hình:
     *   - chưa có ⇒ tạo fallback mặc định (có thể phủ cấu hình từ `$config`);
     *   - đã có nhiều hơn một ⇒ giữ bản đầu tiên, xoá các bản thừa;
     *   - không thay đổi cấu hình fallback đã có (trừ khi `$config` được truyền).
     *
     * @param  array<string, mixed>|null  $config  cấu hình fallback muốn phủ (từ payload editor)
     */
    public function ensureSingleFallback(PolicyTier $tier, ?array $config = null): PolicyTierCategory
    {
        return DB::connection('creditcard')->transaction(function () use ($tier, $config): PolicyTierCategory {
            $fallbacks = PolicyTierCategory::query()
                ->where('tier_id', $tier->id)
                ->fallback()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $kept = $fallbacks->first();

            foreach ($fallbacks->slice(1) as $duplicate) {
                $duplicate->delete();
            }

            if ($kept === null) {
                $kept = PolicyTierCategory::create([
                    'tier_id' => $tier->id,
                    'category_id' => null,
                    'combo_id' => null,
                    'scope_type' => PolicyTierCategory::SCOPE_OTHER,
                    'counts_toward_tier_cap' => (bool) ($config['counts_toward_tier_cap'] ?? false),
                    'name' => $config['name'] ?? PolicyTierCategory::FALLBACK_NAME,
                    'sort_order' => (int) ($config['sort_order'] ?? $this->nextSortOrder($tier->id)),
                    'spend_from' => $this->normalizeMoney($config['spend_from'] ?? 0),
                    'spend_to' => $this->normalizeMoney($config['spend_to'] ?? null),
                    'cashback_percent' => $this->normalizePercent($config['cashback_percent'] ?? 0),
                    'max_cashback_per_transaction' => $this->normalizeMoney($config['max_cashback_per_transaction'] ?? null),
                    'max_cashback_per_category_per_period' => $this->normalizeMoney($config['max_cashback_per_category_per_period'] ?? null),
                    'min_transaction_amount' => $this->normalizeMoney($config['min_transaction_amount'] ?? null),
                    'is_enabled' => (bool) ($config['is_enabled'] ?? true),
                    'note' => $config['note'] ?? null,
                ]);
            } elseif ($config !== null) {
                $kept->forceFill([
                    'counts_toward_tier_cap' => (bool) ($config['counts_toward_tier_cap'] ?? $kept->counts_toward_tier_cap),
                    'name' => array_key_exists('name', $config)
                        ? ($config['name'] ?? PolicyTierCategory::FALLBACK_NAME)
                        : $kept->name,
                    'sort_order' => array_key_exists('sort_order', $config)
                        ? (int) $config['sort_order']
                        : $kept->sort_order,
                    'spend_from' => array_key_exists('spend_from', $config)
                        ? $this->normalizeMoney($config['spend_from'])
                        : $kept->spend_from,
                    'spend_to' => array_key_exists('spend_to', $config)
                        ? $this->normalizeMoney($config['spend_to'])
                        : $kept->spend_to,
                    'cashback_percent' => array_key_exists('cashback_percent', $config)
                        ? $this->normalizePercent($config['cashback_percent'])
                        : $kept->cashback_percent,
                    'max_cashback_per_transaction' => array_key_exists('max_cashback_per_transaction', $config)
                        ? $this->normalizeMoney($config['max_cashback_per_transaction'])
                        : $kept->max_cashback_per_transaction,
                    'max_cashback_per_category_per_period' => array_key_exists('max_cashback_per_category_per_period', $config)
                        ? $this->normalizeMoney($config['max_cashback_per_category_per_period'])
                        : $kept->max_cashback_per_category_per_period,
                    'min_transaction_amount' => array_key_exists('min_transaction_amount', $config)
                        ? $this->normalizeMoney($config['min_transaction_amount'])
                        : $kept->min_transaction_amount,
                    'is_enabled' => (bool) ($config['is_enabled'] ?? $kept->is_enabled),
                    'note' => array_key_exists('note', $config) ? $config['note'] : $kept->note,
                ])->save();
            }

            return $kept->refresh();
        });
    }

    /**
     * Nhân bản một rule sang bậc khác (cùng hoặc khác policy version).
     *
     * Bản sao là bản ghi RIÊNG — sửa bản ghi nguồn không ảnh hưởng bản sao. Copy
     * đủ `scope_type` + `counts_toward_tier_cap`; fallback giữ
     * `category_id = combo_id = NULL`; rule combo giữ `combo_id` và được chặn
     * nếu chia sẻ danh mục với combo rule đã có trong bậc đích.
     *
     * Combo KHÔNG được clone ở đây: bản sao vẫn nằm trong CÙNG policy scope
     * (cùng thẻ hoặc cùng blueprint) nên tham chiếu chung combo là đúng. Việc
     * snapshot combo sang scope `user` là đặc thù của deep-clone hệ thống → thẻ và
     * do `PolicyCloneService` đảm nhiệm.
     */
    public function cloneRuleTo(PolicyTierCategory $source, PolicyTier $targetTier, ?int $sortOrder = null): PolicyTierCategory
    {
        $policy = $this->policyOfTier($targetTier);
        $this->assertMutable($policy);

        // Nhân bản fallback sẽ vi phạm bất biến "đúng một fallback mỗi bậc".
        if ($source->isFallback()) {
            $this->assertNoExistingFallback($targetTier);
        } elseif ($source->combo_id !== null) {
            $this->assertComboUsable((int) $source->combo_id, $policy);
            $this->assertNoSharedCategoryBetweenCombos($targetTier->id, (int) $source->combo_id);
        } elseif ($source->category_id !== null) {
            $this->assertNoDuplicateCategory($targetTier, (int) $source->category_id);
        }

        return DB::connection('creditcard')->transaction(function () use ($source, $targetTier, $sortOrder): PolicyTierCategory {
            $copy = PolicyTierCategory::create([
                'tier_id' => $targetTier->id,
                'category_id' => $source->category_id,
                'combo_id' => $source->combo_id,
                'scope_type' => $source->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY,
                'counts_toward_tier_cap' => $source->scope_type === PolicyTierCategory::SCOPE_OTHER
                    ? false
                    : (bool) ($source->counts_toward_tier_cap ?? true),
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

            return $copy->refresh();
        });
    }

    /**
     * Copy TOÀN BỘ rule của một bậc sang bậc khác — tiện cho "clone cấu hình".
     *
     * Fallback không được nhân đôi: bậc đích sẽ có ĐÚNG MỘT fallback, cấu hình của
     * fallback nguồn được áp lên nó.
     *
     * @return Collection<int, PolicyTierCategory>
     */
    public function cloneAllTo(PolicyTier $source, PolicyTier $targetTier)
    {
        $copied = collect();

        DB::connection('creditcard')->transaction(function () use ($source, $targetTier, &$copied): void {
            $sourceFallback = null;

            foreach ($this->listFor($source) as $rule) {
                if ($rule->isFallback()) {
                    if ($sourceFallback === null) {
                        $sourceFallback = $rule;
                    }

                    continue;
                }

                $copied->push($this->cloneRuleTo($rule, $targetTier, (int) $rule->sort_order));
            }

            $config = $sourceFallback === null ? null : [
                'counts_toward_tier_cap' => false,
                'cashback_percent' => $sourceFallback->cashback_percent,
                'max_cashback_per_transaction' => $sourceFallback->max_cashback_per_transaction,
                'max_cashback_per_category_per_period' => $sourceFallback->max_cashback_per_category_per_period,
                'min_transaction_amount' => $sourceFallback->min_transaction_amount,
                'is_enabled' => (bool) $sourceFallback->is_enabled,
                'note' => $sourceFallback->note,
            ];

            $copied->push($this->ensureSingleFallback($targetTier, $config));
        });

        return $copied;
    }

    /**
     * BACKFILL (migration 000015): với tier CHƯA có fallback, tạo đúng MỘT fallback.
     *
     * Idempotent — chạy lại không tạo thêm. KHÔNG sửa rule hiện có. Các bất thường
     * chỉ được GHI NHẬN để vận hành xử lý thủ công, không tự sửa.
     *
     * @return array{backfilled: int, existing: int, anomalies: array<int, array<string, mixed>>, manual_review: array<int, int>}
     */
    public function backfillFallbackRules(): array
    {
        $backfilled = 0;
        $existing = 0;
        $anomalies = [];
        $manualReview = [];

        foreach (PolicyTier::query()->orderBy('id')->get() as $tier) {
            $fallbacks = PolicyTierCategory::query()
                ->where('tier_id', $tier->id)
                ->fallback()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            if ($fallbacks->isEmpty()) {
                $this->ensureSingleFallback($tier);
                $backfilled++;

                continue;
            }

            $existing++;

            if ($fallbacks->count() > 1) {
                $anomalies[] = [
                    'tier_id' => (int) $tier->id,
                    'issue' => 'multiple_fallbacks',
                    'count' => $fallbacks->count(),
                ];
                $manualReview[] = (int) $tier->id;
            }

            foreach ($fallbacks as $fallback) {
                if ($fallback->category_id !== null) {
                    $anomalies[] = [
                        'tier_id' => (int) $tier->id,
                        'rule_id' => (int) $fallback->id,
                        'issue' => 'fallback_has_category_id',
                    ];
                    $manualReview[] = (int) $tier->id;
                }
            }

            $categoryRuleWithoutCategory = PolicyTierCategory::query()
                ->where('tier_id', $tier->id)
                ->categorySpecific()
                ->whereNull('category_id')
                ->whereNull('combo_id')
                ->first();

            if ($categoryRuleWithoutCategory !== null) {
                $anomalies[] = [
                    'tier_id' => (int) $tier->id,
                    'rule_id' => (int) $categoryRuleWithoutCategory->id,
                    'issue' => 'category_rule_without_category_id',
                ];
                $manualReview[] = (int) $tier->id;
            }

            // Rule có CẢ category_id lẫn combo_id là dữ liệu hỏng (engine sẽ
            // chọn mơ hồ). Chỉ ghi nhận, không tự sửa.
            $ambiguous = PolicyTierCategory::query()
                ->where('tier_id', $tier->id)
                ->whereNotNull('category_id')
                ->whereNotNull('combo_id')
                ->first();

            if ($ambiguous !== null) {
                $anomalies[] = [
                    'tier_id' => (int) $tier->id,
                    'rule_id' => (int) $ambiguous->id,
                    'issue' => 'rule_with_both_category_and_combo',
                ];
                $manualReview[] = (int) $tier->id;
            }

            // Hai combo rule trong cùng bậc chia sẻ danh mục ⇒ giao dịch khớp 2
            // rule, kết quả phụ thuộc thứ tự. Cũng chỉ ghi nhận.
            $comboRuleIds = PolicyTierCategory::query()
                ->where('tier_id', $tier->id)
                ->comboSpecific()
                ->pluck('combo_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($comboRuleIds) > 1) {
                $members = CategoryComboItem::query()
                    ->whereIn('combo_id', $comboRuleIds)
                    ->selectRaw('category_id, COUNT(DISTINCT combo_id) as combo_count')
                    ->groupBy('category_id')
                    ->havingRaw('combo_count > 1')
                    ->pluck('category_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                foreach ($members as $categoryId) {
                    $anomalies[] = [
                        'tier_id' => (int) $tier->id,
                        'category_id' => $categoryId,
                        'issue' => 'category_shared_by_multiple_combo_rules',
                    ];
                    $manualReview[] = (int) $tier->id;
                }
            }
        }

        return [
            'backfilled' => $backfilled,
            'existing' => $existing,
            'anomalies' => $anomalies,
            'manual_review' => array_values(array_unique($manualReview)),
        ];
    }

    // =====================================================================
    // §23 — GIỚI HẠN HOÀN TIỀN THEO GIÁ TRỊ GIAO DỊCH (CAP ĐỘNG)
    // =====================================================================
    // Từ migration 000017, cap động là tài sản của BẬC (`TierService::`), không
    // còn của rule. Rule chỉ giữ `max_cashback_per_transaction` tĩnh.

    /**
     * Gán target của một rule không-fallback (danh mục hoặc combo) khi sửa.
     *
     * Loại target được suy từ input:
     *   - có `combo_id` ⇒ rule COMBO, `category_id` bị xoá;
     *   - có `category_id` ⇒ rule DANH MỤC, `combo_id` bị xoá;
     *   - không có gì ⇒ giữ target hiện tại (sửa band/cap không đổi target).
     *
     * Cột target còn lại LUÔN bị xoá khi đổi loại, để không bao giờ tồn tại rule
     * có cả `category_id` lẫn `combo_id` (sẽ làm engine chọn mơ hồ).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function applyTarget(PolicyTierCategory $rule, int $tierId, array $attributes, Policy $policy): void
    {
        $hasCombo = array_key_exists('combo_id', $attributes)
            && $attributes['combo_id'] !== null
            && $attributes['combo_id'] !== '';
        $hasCategory = array_key_exists('category_id', $attributes)
            && $attributes['category_id'] !== null
            && $attributes['category_id'] !== '';

        if ($hasCombo && $hasCategory) {
            throw new LogicException('Một quy tắc chỉ được trỏ vào MỘT mục tiêu: hoặc danh mục, hoặc combo — không được cả hai.');
        }

        $rule->scope_type = PolicyTierCategory::SCOPE_CATEGORY;

        if ($hasCombo) {
            $rule->combo_id = $this->assertComboId($attributes['combo_id']);
            $rule->category_id = null;

            $this->assertComboUsable((int) $rule->combo_id, $policy);
            $this->assertNoSharedCategoryBetweenCombos($tierId, (int) $rule->combo_id, (int) $rule->id);

            return;
        }

        if ($hasCategory) {
            $rule->category_id = $this->assertCategoryId($attributes['category_id']);
            $rule->combo_id = null;

            $this->assertCategoryUsable((int) $rule->category_id, $policy);
            $this->assertNoDuplicateCategoryForUpdate($tierId, (int) $rule->id, (int) $rule->category_id);

            return;
        }

        // Không gửi target nào ⇒ giữ nguyên target hiện tại, chỉ validate lại cho
        // chắc (ví dụ combo đã bị ẩn sau khi rule được tạo).
        if ($rule->combo_id !== null) {
            $this->assertComboUsable((int) $rule->combo_id, $policy);
            $this->assertNoSharedCategoryBetweenCombos($tierId, (int) $rule->combo_id, (int) $rule->id);

            return;
        }

        $rule->category_id = $this->assertCategoryId($rule->category_id);
        $this->assertCategoryUsable((int) $rule->category_id, $policy);
        $this->assertNoDuplicateCategoryForUpdate($tierId, (int) $rule->id, (int) $rule->category_id);
    }

    /**
     * Rule combo phải trỏ vào MỘT combo thật — không có fake id (0 / -1 / 999999...)
     * và không trỏ nhầm sang id combo.
     */
    private function assertComboId(mixed $comboId): int
    {
        if ($comboId === null || $comboId === '' || (int) $comboId <= 0) {
            throw new InvalidArgumentException('Quy tắc combo phải chọn một combo cụ thể.');
        }

        return (int) $comboId;
    }

    /**
     * Combo có dùng được trong policy này không? (phạm vi scope, xem
     * `CategoryComboService::assertUsableForPolicy`).
     */
    private function assertComboUsable(int $comboId, Policy $policy): void
    {
        $this->combos->assertUsableForPolicy($comboId, $policy);
    }

    /**
     * HAI COMBO RULE TRONG CÙNG BẬC KHÔNG ĐƯỢC CHIA SẺ DANH MỤC.
     *
     * Đây là hệ quả trực tiếp của việc `CashbackCalculator` gán MỘT rule cho mỗi
     * danh mục. Nếu combo A và combo B cùng chứa danh mục X và cả hai đều có rule
     * trong bậc, giao dịch của X sẽ khớp 2 rule ⇒ kết quả phụ thuộc thứ tự duyệt
     * và vi phạm tính tất định (cùng dữ liệu phải cho cùng kết quả).
     *
     * Bị chặn ở đây (service) chứ không ở UNIQUE index vì membership combo nằm ở
     * bảng khác — UNIQUE không thể biểu đạt "hai tập danh mục rời nhau".
     *
     * Lưu ý: combo CÙNG chứa danh mục với một rule DANH MỤC thì vẫn hợp lệ, vì
     * engine ưu tiên rule danh mục (CATEGORY > COMBO > FALLBACK).
     */
    private function assertNoSharedCategoryBetweenCombos(int $tierId, int $comboId, ?int $ignoreRuleId = null): void
    {
        $members = $this->combos->memberCategoryIds($comboId);

        if ($members === []) {
            return;
        }

        $otherComboRuleIds = PolicyTierCategory::query()
            ->where('tier_id', $tierId)
            ->comboSpecific()
            ->where('combo_id', '!=', $comboId)
            ->when($ignoreRuleId !== null, fn ($query) => $query->whereKeyNot($ignoreRuleId))
            ->pluck('combo_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($otherComboRuleIds === []) {
            return;
        }

        $shared = CategoryComboItem::query()
            ->whereIn('combo_id', $otherComboRuleIds)
            ->whereIn('category_id', $members)
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($shared !== []) {
            throw new LogicException(
                'Combo này có danh mục đã nằm trong combo khác có rule trong bậc ('
                .'#'.implode(', #', $shared).').'
            );
        }
    }

    /**
     * Danh mục có thể dùng trong rule của policy này không?
     *
     * Luôn phải tồn tại và đang active. Danh mục inactive vẫn còn trong rule cũ
     * (đó là chủ ý — dữ liệu lịch sử phải đọc được), nhưng không được thêm mới.
     *
     * Phạm vi danh mục theo §10:
     *   - Policy hệ thống (blueprint, `user_card_id = NULL`): CHỈ danh mục hệ thống
     *     (scope = system).
     *   - Policy của thẻ (user): danh mục hệ thống + danh mục riêng của CHÍNH user
     *     sở hữu thẻ. Không bao giờ dùng danh mục của người khác.
     */
    private function assertCategoryUsable(int $categoryId, Policy $policy): void
    {
        $query = Category::query()->active()->whereKey($categoryId);

        if ($policy->isBlueprint()) {
            $query->where('scope', Category::SCOPE_SYSTEM);
        } else {
            $query->where(function ($systemOrOwn) use ($policy): void {
                $systemOrOwn->where('scope', Category::SCOPE_SYSTEM)
                    ->orWhere(function ($own) use ($policy): void {
                        $own->where('scope', Category::SCOPE_USER)
                            ->where('owner_user_id', (int) ($policy->userCard?->user_id));
                    });
            });
        }

        if (! $query->exists()) {
            throw new InvalidArgumentException('Danh mục #'.$categoryId.' không tồn tại, đã bị ẩn hoặc không thuộc phạm vi cho phép.');
        }
    }

    /**
     * Rule danh mục cụ thể phải trỏ vào MỘT danh mục thật — không có fake id
     * (0 / -1 / 999999...) để giả dạng fallback.
     */
    private function assertCategoryId(mixed $categoryId): int
    {
        if ($categoryId === null || $categoryId === '' || (int) $categoryId <= 0) {
            throw new InvalidArgumentException('Quy tắc danh mục phải chọn một danh mục cụ thể.');
        }

        return (int) $categoryId;
    }

    private function assertNoExistingFallback(PolicyTier $tier): void
    {
        if (PolicyTierCategory::query()->where('tier_id', $tier->id)->fallback()->exists()) {
            throw new LogicException('Chỉ được có một quy tắc "'.PolicyTierCategory::FALLBACK_NAME.'" trong mỗi bậc.');
        }
    }

    private function assertNoOtherFallback(int $tierId, int $currentRuleId): void
    {
        if (PolicyTierCategory::query()
            ->where('tier_id', $tierId)
            ->fallback()
            ->whereKeyNot($currentRuleId)
            ->exists()) {
            throw new LogicException('Chỉ được có một quy tắc "'.PolicyTierCategory::FALLBACK_NAME.'" trong mỗi bậc.');
        }
    }

    private function assertNoDuplicateCategory(PolicyTier $tier, int $categoryId): void
    {
        if (PolicyTierCategory::query()
            ->where('tier_id', $tier->id)
            ->categorySpecific()
            ->where('category_id', $categoryId)
            ->exists()) {
            throw new LogicException('Danh mục #'.$categoryId.' đã có rule trong bậc này.');
        }
    }

    private function assertNoDuplicateCategoryForUpdate(int $tierId, int $currentRuleId, int $categoryId): void
    {
        if (PolicyTierCategory::query()
            ->where('tier_id', $tierId)
            ->categorySpecific()
            ->where('category_id', $categoryId)
            ->whereKeyNot($currentRuleId)
            ->exists()) {
            throw new LogicException('Danh mục #'.$categoryId.' đã có rule trong bậc này.');
        }
    }

    private function assertScopeValue(string $scope): void
    {
        if (! in_array($scope, [PolicyTierCategory::SCOPE_CATEGORY, PolicyTierCategory::SCOPE_OTHER], true)) {
            throw new InvalidArgumentException('scope_type phải là "category" hoặc "other".');
        }
    }

    private function booleanOr(mixed $value, bool $default): bool
    {
        return $value === null ? $default : (bool) $value;
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
