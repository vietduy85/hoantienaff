<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreCategoryComboRequest;
use App\Http\Requests\CreditCard\UpdateCategoryComboRequest;
use App\Models\CreditCard\CategoryCombo;
use App\Services\CreditCard\CategoryComboService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

/**
 * API mỏng cho COMBO danh mục của chính user.
 *
 * ---------------------------------------------------------------------------
 * COI LÒI SCOPE SYSTEM LÀ "KHÔNG THỂ SỬA", KHÔNG PHẢI "KHÔNG THẤY"
 * ---------------------------------------------------------------------------
 * `index` trả CẢ combo hệ thống lẫn combo riêng: user CẦN thấy combo hệ thống
 * để gắn vào policy của thẻ mình (combo hệ thống là master data dùng chung, ai
 * cũng được chọn). Chỉ phần GHI mới bị chặn: `update`/`destroy` trả 403 vì
 * `CategoryComboPolicy::update()` trả false cho combo hệ thống.
 *
 * Không nhận `user_id` từ request — scope lấy từ `auth()->id()`.
 */
class CategoryComboController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CategoryComboService $combos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CategoryCombo::class);

        $userId = (int) $request->user()->id;

        $combos = $this->combos->allVisibleTo($userId);

        return response()->json([
            // Combo hệ thống: chỉ đọc, ai cũng được dùng.
            'system_combos' => $combos
                ->filter(fn (CategoryCombo $c) => $c->isSystem())
                ->values()
                ->map(fn (CategoryCombo $c) => $this->present($c)),
            // Combo riêng của user (kể cả đã ẩn).
            'user_combos' => $combos
                ->filter(fn (CategoryCombo $c) => ! $c->isSystem())
                ->values()
                ->map(fn (CategoryCombo $c) => $this->present($c)),
        ]);
    }

    public function store(StoreCategoryComboRequest $request): JsonResponse
    {
        $this->authorize('create', CategoryCombo::class);

        try {
            $combo = $this->combos->createUserCombo((int) $request->user()->id, $request->payload());
        } catch (InvalidArgumentException|LogicException $e) {
            return $this->invalid($e->getMessage());
        }

        return response()->json(['data' => $this->present($combo)], 201);
    }

    public function update(UpdateCategoryComboRequest $request, string $combo): JsonResponse
    {
        // Tra cứu không lọc owner: Policy trả 403 cho combo của user khác VÀ cho
        // combo hệ thống (master data). Service kiểm tra lại lần nữa.
        $model = CategoryCombo::findOrFail((int) $combo);

        $this->authorize('update', $model);

        try {
            $updated = $this->combos->updateUserCombo(
                (int) $request->user()->id,
                $model->id,
                $request->payload()
            );
        } catch (InvalidArgumentException|LogicException $e) {
            return $this->invalid($e->getMessage());
        }

        return response()->json(['data' => $this->present($updated)]);
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => ['category_ids' => [$message]],
        ], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CategoryCombo $combo): array
    {
        $combo->loadMissing('items.category');

        return [
            'id' => $combo->id,
            'name' => $combo->name,
            'slug' => $combo->slug,
            'scope' => $combo->scope,
            'description' => $combo->description,
            'is_active' => (bool) $combo->is_active,
            'sort_order' => (int) $combo->sort_order,
            'category_ids' => $combo->items->pluck('category_id')->map(fn ($id) => (int) $id)->all(),
            'items' => $combo->items->map(fn ($item) => [
                'id' => $item->id,
                'category_id' => $item->category_id,
                'category_name' => $item->category?->name,
                'category_slug' => $item->category?->slug,
            ])->values(),
        ];
    }
}
