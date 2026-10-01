<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * SystemCategoryService — module ADMIN quản lý danh mục HỆ THỐNG.
 *
 * Đây là đường ghi DUY NHẤT cho 19+ danh mục hệ thống (master data). Service
 * riêng, tách khỏi `CategoryService` của user vì danh mục hệ thống ở đó là
 * READ-ONLY (user không được sửa bằng bất kỳ cách nào).
 *
 * CHỈ 3 THAO TÁC (§ section "Quản lý danh mục hệ thống"):
 *   - tạo mới (`create`)
 *   - đổi tên (`update`)
 *   - sắp xếp lại (`reorder`)
 * TUYỆT ĐỐI KHÔNG có xoá / ẩn / khôi phục / lưu trữ.
 *
 * Invariant: `category_id` là ĐỊNH DANH, `name` là THUỘC TÍNH HIỆN TẠI. Đổi tên
 * KHÔNG tạo version mới, KHÔNG snapshot tên, KHÔNG đổi `category_id` ⇒ mọi tham
 * chiếu (template hệ thống, user policy rule, giao dịch, báo cáo) tự hiển thị
 * tên mới qua relation tới bảng danh mục.
 */
class SystemCategoryService
{
    /**
     * Mọi danh mục hệ thống, theo thứ tự hiển thị.
     *
     * @return Collection<int, Category>
     */
    public function all(): Collection
    {
        return Category::query()
            ->system()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Tạo danh mục hệ thống mới. Slug được chuẩn hoá (kebab-case) và đảm bảo
     * duy nhất trong scope system. Thứ tự ban đầu: đặt ở cuối danh sách hiện có.
     *
     * @param  array{name:string, slug:string, description?:string|null}  $attributes
     */
    public function create(array $attributes): Category
    {
        return DB::connection('creditcard')->transaction(function () use ($attributes): Category {
            $name = trim((string) $attributes['name']);
            $slug = Str::slug((string) $attributes['slug']);

            if ($name === '' || $slug === '') {
                throw new InvalidArgumentException('Tên danh mục và slug không được để trống.');
            }

            if ($this->slugExists($slug)) {
                throw new InvalidArgumentException(sprintf('Slug "%s" đã tồn tại trong danh mục hệ thống.', $slug));
            }

            $max = Category::query()->system()->max('sort_order');

            return Category::create([
                'scope' => Category::SCOPE_SYSTEM,
                'owner_user_id' => Category::SYSTEM_OWNER_ID,
                'name' => $name,
                'slug' => $slug,
                'description' => $attributes['description'] ?? null,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => ($max === null ? 0 : (int) $max) + 10,
            ]);
        });
    }

    /**
     * Đổi tên (mô tả) danh mục hệ thống.
     *
     * CHỈ trên cùng bản ghi `Category` đã có. `category_id` bất biến; không tạo
     * danh mục mới; không tạo version policy mới. Slug giữ nguyên (slug là khoá
     * ánh xạ của `CategoryIcon` và khớp seeder).
     *
     * @param  array{name?:string, description?:string|null}  $attributes
     */
    public function update(Category $category, array $attributes): Category
    {
        $this->assertSystem($category);

        return DB::connection('creditcard')->transaction(function () use ($category, $attributes): Category {
            if (array_key_exists('name', $attributes)) {
                $name = trim((string) $attributes['name']);

                if ($name === '') {
                    throw new InvalidArgumentException('Tên danh mục không được để trống.');
                }

                $category->name = $name;
            }

            if (array_key_exists('description', $attributes)) {
                $description = $attributes['description'];

                $category->description = $description === '' ? null : $description;
            }

            $category->save();

            return $category->refresh();
        });
    }

    /**
     * Sắp xếp lại toàn bộ danh mục hệ thống theo thứ tự id được gửi lên.
     *
     * Ranh giới an toàn (fail loudly nếu sai):
     *   - danh sách phải đúng và đủ mọi danh mục hệ thống (không xoá, không thừa);
     *   - mọi id đều phải thuộc scope system (admin KHÔNG được đụng danh mục user);
     *   - nguyên tử trong 1 transaction; `sort_order` chuẩn hoá 1..N;
     *   - `category_id` không đổi ⇒ mọi rule/giao dịch giữ nguyên tham chiếu.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        DB::connection('creditcard')->transaction(function () use ($orderedIds): void {
            $ids = collect($orderedIds)->map(fn ($id) => (int) $id)->values()->all();
            $ids = array_values(array_unique($ids));

            if ($ids === []) {
                throw new InvalidArgumentException('Danh sách thứ tự không được rỗng.');
            }

            $system = Category::query()->system()->get()->keyBy('id');

            if (count($ids) !== $system->count()
                || collect($ids)->diff($system->keys())->isNotEmpty()) {
                throw new InvalidArgumentException('Không thể lưu thứ tự: danh sách gửi lên phải đứng đủ mọi danh mục hệ thống và không được thừa.');
            }

            foreach ($ids as $index => $id) {
                $system[$id]->forceFill(['sort_order' => $index + 1])->save();
            }
        });
    }

    private function assertSystem(Category $category): void
    {
        if (! $category->isSystem()) {
            throw new LogicException('Module danh mục hệ thống chỉ quản lý danh mục system, không quản lý danh mục riêng của user.');
        }
    }

    private function slugExists(string $slug): bool
    {
        return Category::query()
            ->system()
            ->where('slug', $slug)
            ->exists();
    }
}
