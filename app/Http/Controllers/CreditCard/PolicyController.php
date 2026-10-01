<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CreditCard\Concerns\ResolvesCardResources;
use App\Http\Requests\CreditCard\StorePolicyRequest;
use App\Http\Requests\CreditCard\StorePolicyVersionRequest;
use App\Http\Requests\CreditCard\StoreTemplateRequest;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\PolicyService;
use App\Services\CreditCard\TierService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API cấu hình chính sách cashback của MỘT thẻ (Phase 1C).
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO MỌI ROUTE VERSION ĐỀU SCOPE THEO THẺ
 * ---------------------------------------------------------------------------
 * URL mang cả `userCard` lẫn `policy`: `versionOfCard()` lọc `user_card_id` nên
 * version của thẻ khác trả 404 chứ không phải 403. Nếu chỉ dùng `/chinh-sach/{policy}`
 * thì việc chặn IDOR hoàn toàn dựa vào Policy — vẫn đúng, nhưng thêm một lớp lọc ở
 * tầng truy vấn rẻ hơn nhiều so với việc tin cậy việc không ai đổi Policy sau này.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO SỬA CẤU HÌNH ⇒ TẠO VERSION MỚI
 * ---------------------------------------------------------------------------
 * Giao dịch đã finalize snapshot `policy_version_id` / `policy_tier_category_id`.
 * Sửa version cũ làm cashback lịch sử không tái lập được. Endpoint duy nhất được
 * sửa version đang chạy là `update()`, và nó CHỈ đổi tên (metadata) — qua
 * `PolicyService::rename()` vốn đã chặn version khoá/superseded.
 */
class PolicyController extends Controller
{
    use AuthorizesRequests, ResolvesCardResources;

    public function __construct(
        private readonly PolicyService $policies,
        private readonly TierService $tiers,
        private readonly CategoryRuleService $rules,
    ) {}

    /**
     * Toàn bộ version của thẻ, version hiện hành trước.
     */
    public function index(Request $request, string $userCard): JsonResponse
    {
        $card = $this->cardFor($request, $userCard);

        $versions = $this->policies->versionsOf($card)
            ->map(fn (PolicyVersion $version) => $this->present($version));

        return response()->json([
            'data' => $versions,
            'meta' => [
                'current_version_id' => $card->currentPolicy?->id,
                'can_create_version' => $card->currentPolicy !== null,
            ],
        ]);
    }

    /**
     * Tạo version 1 theo một trong ba đường vào: `scratch`, `clone_system`,
     * `clone_user`. Phân nhánh nằm ở service, không ở controller.
     */
    public function store(StorePolicyRequest $request, string $userCard): JsonResponse
    {
        $card = $this->cardFor($request, $userCard);

        $this->authorize('create', [Policy::class, $card]);

        $effectiveFrom = $request->date('effective_from');

        $version = match ($request->mode()) {
            StorePolicyRequest::MODE_CLONE_SYSTEM => $this->policies->cloneSystemTemplate(
                $card,
                (int) $request->templateId(),
                $effectiveFrom,
            ),
            StorePolicyRequest::MODE_CLONE_USER => $this->policies->cloneUserTemplate(
                $card,
                (int) $request->templateId(),
                $effectiveFrom,
            ),
            default => $this->policies->createFromScratch(
                $card,
                $effectiveFrom,
                $request->scratchPayload(),
            ),
        };

        return response()->json(['data' => $this->present($version)], 201);
    }

    /**
     * Một version kèm bậc và rule — payload cho màn hình cấu hình.
     */
    public function show(Request $request, string $userCard, string $policy): JsonResponse
    {
        $card = $this->cardFor($request, $userCard);
        $version = $this->versionOfCard($card, $policy);

        $this->authorize('view', $version);

        return response()->json(['data' => $this->present($version, withDetails: true)]);
    }

    /**
     * Tạo version N+1: clone từ version hiện tại rồi áp override.
     */
    public function storeVersion(StorePolicyVersionRequest $request, string $userCard): JsonResponse
    {
        $card = $this->cardFor($request, $userCard);

        $this->authorize('create', [Policy::class, $card]);

        $version = $this->policies->createVersion(
            $card,
            $request->date('effective_from'),
            $request->overrides(),
        );

        return response()->json(['data' => $this->present($version, withDetails: true)], 201);
    }

    /**
     * Sửa version: CHỈ đổi tên.
     */
    public function update(Request $request, string $userCard, string $policy): JsonResponse
    {
        $card = $this->cardFor($request, $userCard);
        $version = $this->versionOfCard($card, $policy);

        $this->authorize('update', $version);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
        ]);

        $renamed = $this->policies->rename($card, $version->id, $validated['name']);

        return response()->json(['data' => $this->present($renamed)]);
    }

    /**
     * Lưu cấu hình hiện tại thành mẫu riêng của user.
     */
    public function storeTemplate(StoreTemplateRequest $request, string $userCard): JsonResponse
    {
        $card = $this->cardFor($request, $userCard);

        $this->authorize('create', [Policy::class, $card]);

        $template = $this->policies->saveAsUserTemplate(
            $card,
            $request->templateName(),
            $request->templateDescription(),
        );

        return response()->json([
            'data' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'scope' => $template->scope,
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PolicyVersion $version, bool $withDetails = false): array
    {
        $data = [
            'id' => $version->id,
            'user_card_id' => $version->user_card_id,
            'template_id' => $version->template_id,
            'version_no' => $version->version_no,
            'status' => $version->status,
            'is_locked' => (bool) $version->is_locked,
            'name' => $version->name,
            'effective_from' => $version->effective_from?->toDateString(),
            'effective_to' => $version->effective_to?->toDateString(),
            'min_total_spend' => (float) $version->min_total_spend,
            'max_cashback_total_per_period' => $version->max_cashback_total_per_period === null
                ? null
                : (float) $version->max_cashback_total_per_period,
            'rounding_mode' => $version->rounding_mode,
            'note' => $version->note,
        ];

        if (! $withDetails) {
            return $data;
        }

        $tiers = $this->tiers->listFor($version)->map(function ($tier) {
            return [
                'id' => $tier->id,
                'name' => $tier->name,
                'sort_order' => (int) $tier->sort_order,
                'min_total_spend' => (float) $tier->min_total_spend,
                'max_total_spend' => $tier->max_total_spend === null ? null : (float) $tier->max_total_spend,
                'rules' => $this->rules->listFor($tier)->map(fn ($rule) => [
                    'id' => $rule->id,
                    'category_id' => $rule->category_id,
                    'category_name' => $rule->category?->name,
                    'scope_type' => $rule->scope_type ?? PolicyTierCategory::SCOPE_CATEGORY,
                    'counts_toward_tier_cap' => (bool) ($rule->counts_toward_tier_cap ?? ! $rule->isFallback()),
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
                ]),
            ];
        });

        return $data + ['tiers' => $tiers];
    }
}
