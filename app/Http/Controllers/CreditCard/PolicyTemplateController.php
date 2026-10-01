<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTierCategory;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\TierService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API xem mẫu chính sách (Phase 1C).
 *
 * ---------------------------------------------------------------------------
 * CHỈ ĐỌC
 * ---------------------------------------------------------------------------
 * Không có `store` / `update` / `destroy` ở đây cố ý. Mẫu được tạo bằng
 * `POST /the/{card}/chinh-sach/mau` (`PolicyService::saveAsUserTemplate()`) chứ
 * không phải nhập tay: nhập tay nghĩa là phải chọn danh mục và viết tỷ lệ, dễ
 * tạo ra cấu hình không khớp với thẻ nào. Mẫu hệ thống do admin quản lý, không
 * sửa được từ màn hình user.
 *
 * ---------------------------------------------------------------------------
 * DANH SÁCH ĐÃ LỌC SẴN
 * ---------------------------------------------------------------------------
 * `PolicyTemplate::scopeSelectableBy()` trả về template HỆ THỐNG + template RIÊNG
 * của chính user. Lọc ở tầng truy vấn thay vì lọc tay trong PHP để không bao giờ
 * lọt một bản ghi ngoài ý muốn ra response.
 */
class PolicyTemplateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly TierService $tiers,
        private readonly CategoryRuleService $rules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PolicyTemplate::class);

        $templates = PolicyTemplate::query()
            ->selectableBy((int) $request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (PolicyTemplate $template) => $this->present($template)),
        ]);
    }

    /**
     * Blueprint của một mẫu: bậc + rule, để user xem trước khi chọn.
     */
    public function show(Request $request, string $template): JsonResponse
    {
        $model = PolicyTemplate::query()->whereKey((int) $template)->firstOrFail();

        $this->authorize('view', $model);

        return response()->json(['data' => $this->present($model, withDetails: true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PolicyTemplate $template, bool $withDetails = false): array
    {
        // Bản "đang phát hành cho user mới" = version mặc định (nguồn clone).
        $blueprint = $template->defaultBlueprint();

        $data = [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'scope' => $template->scope,
            'is_system' => $template->isSystemScope(),
            'is_active' => (bool) $template->is_active,
            'version_no' => $blueprint?->version_no,
            'effective_from' => $blueprint?->effective_from?->toDateString(),
            'min_total_spend' => $blueprint?->min_total_spend === null ? null : (float) $blueprint->min_total_spend,
            'max_cashback_total_per_period' => $blueprint?->max_cashback_total_per_period === null
                ? null
                : (float) $blueprint->max_cashback_total_per_period,
            'rounding_mode' => $blueprint?->rounding_mode,
            'tiers_count' => 0,
            'categories_count' => 0,
        ];

        if ($blueprint !== null) {
            $tiers = $blueprint->tiers()->get();
            $data['tiers_count'] = $tiers->count();
            $data['categories_count'] = $tiers->sum(fn ($tier) => $tier->tierCategoryRules()->categorySpecific()->count());
        }

        if (! $withDetails) {
            return $data;
        }

        if ($blueprint === null) {
            return $data + ['tiers' => []];
        }

        return $data + [
            'tiers' => $this->tiers->listFor($blueprint)->map(fn ($tier) => [
                'name' => $tier->name,
                'sort_order' => (int) $tier->sort_order,
                'min_total_spend' => (float) $tier->min_total_spend,
                'max_total_spend' => $tier->max_total_spend === null ? null : (float) $tier->max_total_spend,
                'rules' => $this->rules->listFor($tier)->map(fn ($rule) => [
                    'category_id' => $rule->category_id,
                    'category_name' => $rule->category?->name,
                    'scope_type' => $rule->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY,
                    'counts_toward_tier_cap' => (bool) ($rule->counts_toward_tier_cap ?? ! $rule->isFallback()),
                    'spend_from' => (float) $rule->spend_from,
                    'spend_to' => $rule->spend_to === null ? null : (float) $rule->spend_to,
                    'cashback_percent' => (float) $rule->cashback_percent,
                    'max_cashback_per_transaction' => $rule->max_cashback_per_transaction === null
                        ? null
                        : (float) $rule->max_cashback_per_transaction,
                    'max_cashback_per_category_per_period' => $rule->max_cashback_per_category_per_period === null
                        ? null
                        : (float) $rule->max_cashback_per_category_per_period,
                    'is_enabled' => (bool) $rule->is_enabled,
                ]),
            ]),
        ];
    }
}
