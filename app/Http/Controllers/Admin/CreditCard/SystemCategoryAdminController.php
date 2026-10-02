<?php

namespace App\Http\Controllers\Admin\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreditCard\ReorderSystemCategoriesRequest;
use App\Http\Requests\Admin\CreditCard\StoreSystemCategoryRequest;
use App\Http\Requests\Admin\CreditCard\UpdateSystemCategoryRequest;
use App\Models\CreditCard\Category;
use App\Services\CreditCard\SystemCategoryService;
use App\Support\CreditCard\CategoryIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Trang vận hành (Blade) + JSON API quản trị "Danh mục hệ thống".
 *
 * CHỈ 3 thao tác: thêm mới, đổi tên, sắp xếp lại. KHÔNG có xoá/ẩn/khôi phục.
 *
 * Đảm bảo bất biến "category_id là định danh, name là thuộc tính hiện tại":
 * mọi ghi đi qua `SystemCategoryService` và chỉ chạm đúng bản ghi `Category`;
 * `category_id` của policy rule / giao dịch không bao giờ đổi ⇒ template cũ,
 * user policy và dữ liệu lịch sử tự hiển thị tên mới (không snapshot).
 */
class SystemCategoryAdminController extends Controller
{
    public function __construct(private readonly SystemCategoryService $categories) {}

    public function index(): View
    {
        return view('admin.system-categories.index', [
            'categories' => $this->categories->all()
                ->map(fn (Category $category) => $this->present($category))
                ->values(),
        ]);
    }

    public function store(StoreSystemCategoryRequest $request): JsonResponse
    {
        try {
            $category = $this->categories->create($request->payload());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($category)], 201);
    }

    public function update(UpdateSystemCategoryRequest $request, Category $category): JsonResponse
    {
        if (! $category->isSystem()) {
            abort(404);
        }

        try {
            $updated = $this->categories->update($category, $request->payload());
        } catch (\InvalidArgumentException $exception) {
            // Slug trùng / rỗng: trả 422 kèm message đọc được thay vì lỗi 500.
            // Request đã chặn trùng bằng `Rule::unique`, đây là lưới an toàn cho
            // mọi call path khác đi vào service.
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($updated)]);
    }

    public function reorder(ReorderSystemCategoriesRequest $request): JsonResponse
    {
        try {
            $this->categories->reorder($request->validated('ordered_ids'));
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['saved' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_active' => (bool) $category->is_active,
            'sort_order' => (int) $category->sort_order,
            'icon' => CategoryIcon::for($category),
        ];
    }
}
