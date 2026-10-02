<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CreditCard\Concerns\ResolvesCardResources;
use App\Http\Requests\CreditCard\CloneCategoryRuleRequest;
use App\Http\Requests\CreditCard\StoreCategoryRuleRequest;
use App\Http\Requests\CreditCard\UpdateCategoryRuleRequest;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\PolicyTierCategory;
use App\Services\CreditCard\CategoryComboService;
use App\Services\CreditCard\CategoryRuleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API quy tắc cashback theo danh mục trong một bậc (Phase 1C).
 *
 * ---------------------------------------------------------------------------
 * RULE CHỈ MÔ TẢ CẤU HÌNH, KHÔNG MANG KẾT QUẢ
 * ---------------------------------------------------------------------------
 * Response ở đây CỐ Ý không có `cashback_amount` / `calculated_cashback` /
 * `final_cashback`. Ở tầng service, `present()` chỉ đọc cột cấu hình; giá trị
 * tiền thật nằm ở `credit_card_transactions` và do `CashbackRecordService` quyết
 * định. Nếu response có trường kết quả thì UI sẽ dễ dùng nhầm số đó thay vì số
 * hệ thống tính.
 */
class CategoryRuleController extends Controller
{
    use AuthorizesRequests, ResolvesCardResources;

    public function __construct(
        private readonly CategoryRuleService $rules,
        private readonly CategoryComboService $combos,
    ) {}

    /**
     * Rule của một bậc + mục tiêu user được phép chọn (cho form thêm rule).
     *
     * `meta` gồm CẢ danh mục lẫn combo: rule có ba loại target (danh mục | combo |
     * fallback) nên form cần danh sách combo để user không phải tự dò id.
     */
    public function index(Request $request, string $tier): JsonResponse
    {
        $model = $this->tierAsParent($tier);

        $userId = (int) $request->user()->id;

        return response()->json([
            'data' => $this->rules->listFor($model)->map(fn (PolicyTierCategory $rule) => $this->present($rule)),
            'meta' => [
                'categories' => Category::query()
                    ->selectableBy($userId)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Category $category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'scope' => $category->scope,
                    ]),
                'combos' => $this->combos->selectableFor($userId)
                    ->map(fn (CategoryCombo $combo) => [
                        'id' => $combo->id,
                        'name' => $combo->name,
                        'slug' => $combo->slug,
                        'scope' => $combo->scope,
                        'is_active' => (bool) $combo->is_active,
                        'category_ids' => $combo->items->pluck('category_id')->map(fn ($id) => (int) $id)->all(),
                        'category_count' => $combo->items->count(),
                    ])
                    ->values(),
            ],
        ]);
    }

    public function store(StoreCategoryRuleRequest $request, string $tier): JsonResponse
    {
        $model = $this->tierAsParent($tier);

        $this->authorize('create', [PolicyTierCategory::class, $model]);

        $rule = $this->rules->create($model, $request->payload());

        return response()->json(['data' => $this->present($rule)], 201);
    }

    public function update(UpdateCategoryRuleRequest $request, string $rule): JsonResponse
    {
        $model = PolicyTierCategory::query()->whereKey((int) $rule)->firstOrFail();

        $this->authorize('update', $model);

        $updated = $this->rules->update($model->id, $request->payload());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, string $rule): JsonResponse
    {
        $model = PolicyTierCategory::query()->whereKey((int) $rule)->firstOrFail();

        $this->authorize('delete', $model);

        $this->rules->delete($model->id);

        return response()->json(['deleted' => true, 'id' => $model->id]);
    }

    /**
     * Nhân bản rule sang bậc khác (cùng version hoặc version khác).
     */
    public function clone(CloneCategoryRuleRequest $request, string $rule): JsonResponse
    {
        $source = PolicyTierCategory::query()->whereKey((int) $rule)->firstOrFail();

        $this->authorize('clone', $source);

        // Bậc đích thuộc version nào, thẻ nào — authorize riêng.
        $target = $this->tierAsParent((string) $request->targetTierId());

        $this->authorize('create', [PolicyTierCategory::class, $target]);

        $copy = $this->rules->cloneRuleTo($source, $target, $request->targetSortOrder());

        return response()->json(['data' => $this->present($copy)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PolicyTierCategory $rule): array
    {
        $rule->loadMissing('category', 'combo');

        return [
            'id' => $rule->id,
            'tier_id' => $rule->tier_id,
            // Target: đúng một trong `category_id` / `combo_id` có giá trị, hoặc
            // cả hai null khi rule là fallback.
            'target_type' => $rule->isFallback()
                ? 'other'
                : ($rule->combo_id !== null ? 'combo' : 'category'),
            'category_id' => $rule->category_id,
            'category_name' => $rule->category?->name,
            'combo_id' => $rule->combo_id,
            'combo_name' => $rule->combo?->name,
            'scope_type' => $rule->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY,
            'counts_toward_tier_cap' => (bool) ($rule->counts_toward_tier_cap ?? ! $rule->isFallback()),
            'is_quota_category' => $rule->isQuotaCategory(),
            'name' => $rule->name,
            'sort_order' => (int) $rule->sort_order,
            'spend_from' => (float) $rule->spend_from,
            'spend_to' => $rule->spend_to === null ? null : (float) $rule->spend_to,
            'cashback_percent' => (float) $rule->cashback_percent,
            'max_cashback_per_transaction' => $rule->max_cashback_per_transaction === null
                ? null
                : (float) $rule->max_cashback_per_transaction,
            'max_cashback_per_category_per_period' => $rule->max_cashback_per_category_per_period === null
                ? null
                : (float) $rule->max_cashback_per_category_per_period,
            'min_transaction_amount' => $rule->min_transaction_amount === null
                ? null
                : (float) $rule->min_transaction_amount,
            'is_enabled' => (bool) $rule->is_enabled,
            'note' => $rule->note,
        ];
    }
}
