<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreCategoryRequest;
use App\Http\Requests\CreditCard\UpdateCategoryRequest;
use App\Models\CreditCard\Category;
use App\Services\CreditCard\CategoryService;
use App\Support\CreditCard\CategoryIcon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API mỏng cho danh mục chi tiêu.
 *
 * Danh mục HỆ THỐNG chỉ đọc: không có endpoint sửa/xoá chúng, và
 * `StoreCategoryRequest` không nhận `scope`/`owner_user_id` nên không tạo được
 * danh mục hệ thống từ phía user.
 */
class CategoryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CategoryService $categories,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        $userId = (int) $request->user()->id;

        return response()->json([
            // 19 danh mục hệ thống đang hoạt động (read-only) — dùng cho màn hình quản lý.
            'system_categories' => $this->categories->systemCategories()
                ->filter(fn (Category $c) => $c->is_active)
                ->values()
                ->map(fn (Category $c) => $this->present($c)),
            // Danh mục RIÊNG của user, gồm cả danh mục đã ẩn (`is_active = false`).
            'user_categories' => $this->categories->userCategories($userId)->map(fn (Category $c) => $this->present($c)),
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $category = $this->categories->createUserCategory((int) $request->user()->id, $request->payload());

        return response()->json(['data' => $this->present($category)], 201);
    }

    public function update(UpdateCategoryRequest $request, string $category): JsonResponse
    {
        // Tra cứu không lọc owner: Policy trả 403 cho danh mục của user khác và
        // cho danh mục HỆ THỐNG (read-only). Service kiểm tra lại lần nữa.
        $model = Category::findOrFail((int) $category);

        $this->authorize('update', $model);

        $updated = $this->categories->updateUserCategory(
            (int) $request->user()->id,
            $model->id,
            $request->payload()
        );

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * Xoá danh mục riêng.
     *
     * Chưa dùng ở đâu ⇒ xoá cứng. Đã dùng ⇒ chỉ ẩn (`is_active = false`) và
     * response báo `deleted = false` để UI hiển thị đúng.
     */
    public function destroy(Request $request, string $category): JsonResponse
    {
        $model = Category::findOrFail((int) $category);

        $this->authorize('delete', $model);

        $result = $this->categories->deleteUserCategory((int) $request->user()->id, $model->id);

        return response()->json([
            'deleted' => $result['deleted'],
            'data' => $this->present($result['category']),
        ]);
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
            'scope' => $category->scope,
            'description' => $category->description,
            'is_active' => (bool) $category->is_active,
            'sort_order' => $category->sort_order,
            'icon' => CategoryIcon::for($category),
        ];
    }
}
