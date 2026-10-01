<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CreditCard\Concerns\ResolvesCardResources;
use App\Http\Requests\CreditCard\CloneTierRequest;
use App\Http\Requests\CreditCard\StoreTierRequest;
use App\Http\Requests\CreditCard\UpdateTierRequest;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\TierService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API bậc chi tiêu (Phase 1C).
 *
 * ---------------------------------------------------------------------------
 * RETROACTIVE, KHÔNG PROGRESSIVE
 * ---------------------------------------------------------------------------
 * Bậc được chọn theo TỔNG chi tiêu cả kỳ và mỗi bậc có một tỷ lệ cố định. Không
 * có endpoint nào nhận `application_mode` / `progressive`: nếu có, cashback sẽ
 * phụ thuộc thứ tự các lần chi và cùng một tập giao dịch sẽ cho kết quả khác nhau
 * tuỳ cách hệ thống lưu bản ghi.
 *
 * ---------------------------------------------------------------------------
 * NHÂN BẢN BẬC = DEEP CLONE
 * ---------------------------------------------------------------------------
 * `clone` gọi CẢ `TierService::cloneTo()` (khoảng + tên) VÀ
 * `CategoryRuleService::cloneAllTo()` (toàn bộ rule). Chỉ gọi `cloneTo()` sẽ tạo
 * ra một bậc rỗng — cấu hình bị mất mà không có dấu hiệu nào cho biết.
 */
class TierController extends Controller
{
    use AuthorizesRequests, ResolvesCardResources;

    public function __construct(
        private readonly TierService $tiers,
        private readonly CategoryRuleService $rules,
    ) {}

    public function index(string $policy): JsonResponse
    {
        $version = $this->versionAsParent($policy);

        return response()->json([
            'data' => $this->tiers->listFor($version)->map(fn (PolicyTier $tier) => $this->present($tier)),
        ]);
    }

    public function store(StoreTierRequest $request, string $policy): JsonResponse
    {
        $version = $this->versionAsParent($policy);

        $this->authorize('create', [PolicyTier::class, $version]);

        $tier = $this->tiers->create($version, $request->payload());

        return response()->json(['data' => $this->present($tier)], 201);
    }

    public function update(UpdateTierRequest $request, string $tier): JsonResponse
    {
        $model = PolicyTier::query()->whereKey((int) $tier)->firstOrFail();

        $this->authorize('update', $model);

        $updated = $this->tiers->update($model->id, $request->payload());

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * Xoá bậc. Bậc còn rule sẽ bị chặn 409 (xem `TierService::delete()`) để không
     * âm thầm mất cấu hình cashback.
     */
    public function destroy(Request $request, string $tier): JsonResponse
    {
        $model = PolicyTier::query()->whereKey((int) $tier)->firstOrFail();

        $this->authorize('delete', $model);

        $this->tiers->delete($model->id);

        return response()->json(['deleted' => true, 'id' => $model->id]);
    }

    /**
     * Nhân bản bậc + toàn bộ rule sang một version khác.
     */
    public function clone(CloneTierRequest $request, string $tier): JsonResponse
    {
        $source = PolicyTier::query()->whereKey((int) $tier)->firstOrFail();

        $this->authorize('clone', $source);

        // Bản ghi đích phải được authorize RIÊNG: nó có thể thuộc thẻ khác.
        $target = Policy::query()->whereKey($request->targetPolicyId())->firstOrFail();

        $this->assertNotBlueprint($target);
        $this->authorize('create', [PolicyTier::class, $target]);

        $copy = $this->tiers->cloneTo($source, $target, $request->targetSortOrder());
        $this->rules->cloneAllTo($source, $copy);

        return response()->json([
            'data' => $this->present($copy->refresh(), withRules: true),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PolicyTier $tier, bool $withRules = false): array
    {
        $data = [
            'id' => $tier->id,
            'policy_id' => $tier->policy_id,
            'name' => $tier->name,
            'sort_order' => (int) $tier->sort_order,
            'min_total_spend' => (float) $tier->min_total_spend,
            'max_total_spend' => $tier->max_total_spend === null ? null : (float) $tier->max_total_spend,
        ];

        if (! $withRules) {
            return $data;
        }

        return $data + [
            'rules' => $this->rules->listFor($tier)->map(fn ($rule) => [
                'id' => $rule->id,
                'category_id' => $rule->category_id,
                'scope_type' => $rule->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY,
                'counts_toward_tier_cap' => (bool) ($rule->counts_toward_tier_cap ?? ! $rule->isFallback()),
                'name' => $rule->name,
                'spend_from' => (float) $rule->spend_from,
                'spend_to' => $rule->spend_to === null ? null : (float) $rule->spend_to,
                'cashback_percent' => (float) $rule->cashback_percent,
                'is_enabled' => (bool) $rule->is_enabled,
            ]),
        ];
    }
}
