<?php

namespace App\Http\Controllers\Admin\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreditCard\StoreSystemComboRequest;
use App\Http\Requests\Admin\CreditCard\UpdateSystemComboRequest;
use App\Models\CreditCard\CategoryCombo;
use App\Services\CreditCard\CategoryComboService;
use App\Services\CreditCard\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

/**
 * Admin: Quản lý Combo danh mục hệ thống.
 *
 * - Combo hệ thống chỉ chứa SYSTEM CATEGORY (đang hoạt động).
 * - Chỉ admin có `credit-cards.manage` mới ghi.
 * - KHÔNG xoá ở phase này (tương tự Category): FK `combo_id` là RESTRICT và rule
 *   đã tạo phải giữ nguyên ý nghĩa. Sẽ làm ở task riêng nếu cần.
 */
class SystemComboAdminController extends Controller
{
    public function __construct(
        private readonly CategoryComboService $combos,
        private readonly CategoryService $categories,
    ) {}

    public function index(): View
    {
        $combos = $this->combos->systemCombos()
            ->map(fn (CategoryCombo $combo) => self::present($combo))
            ->values();

        $systemCategories = $this->categories->systemCategories()
            ->values()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all();

        return view('admin.system-combos.index', [
            'combos' => $combos,
            'systemCategories' => $systemCategories,
        ]);
    }

    public function store(StoreSystemComboRequest $request): JsonResponse
    {
        try {
            $combo = $this->combos->createSystemCombo($request->payload());
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        } catch (LogicException $e) {
            return $this->invalid($e->getMessage());
        }

        return response()->json(['data' => self::present($combo)], 201);
    }

    public function update(UpdateSystemComboRequest $request, CategoryCombo $combo): JsonResponse
    {
        // Combo của user KHÔNG phải việc của màn này ⇒ 404 (không rò rỉ sự tồn tại).
        if (! $combo->isSystem()) {
            abort(404);
        }

        try {
            $updated = $this->combos->updateSystemCombo($combo, $request->payload());
        } catch (InvalidArgumentException $e) {
            return $this->invalid($e->getMessage());
        } catch (LogicException $e) {
            return $this->invalid($e->getMessage());
        }

        return response()->json(['data' => self::present($updated)]);
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => ['category_ids' => [$message]],
        ], 422);
    }

    /**
     * Payload dùng chung cho màn hình Blade lẫn response JSON.
     *
     * @return array<string, mixed>
     */
    public static function present(CategoryCombo $combo): array
    {
        $combo->loadMissing('items.category');

        return [
            'id' => $combo->id,
            'name' => $combo->name,
            'slug' => $combo->slug,
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
