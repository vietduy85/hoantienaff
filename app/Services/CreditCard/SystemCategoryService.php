<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CardPolicyMccMapping;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\SpendQualificationCondition;
use App\Models\CreditCard\SpendQualificationConditionExcludedCategory;
use App\Models\CreditCard\SpendQualificationTemplateCondition;
use App\Models\CreditCard\SpendQualificationTemplateConditionExcludedCategory;
use App\Models\CreditCard\Transaction;
use Illuminate\Database\Eloquent\Builder;
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
 * CHỈ 3 THAO TÁC + 1 XOÁ CÓ HÀNG RÀO (§ section "Quản lý danh mục hệ thống"):
 *   - tạo mới (`create`)
 *   - đổi tên (`update`)
 *   - sắp xếp lại (`reorder`)
 *   - xoá CÓ HÀNG RÀO (`deleteSystemCategory`): CHỈ xoá được khi danh mục
 *     KHÔNG còn bất kỳ tham chiếu nào (rule / combo / giao dịch / spend-qual /
 *     MCC). Còn tham chiếu ⇒ từ chối kèm lý do cụ thể, KHÔNG ẩn, KHÔNG khôi phục.
 *
 * KHÔNG có ẩn / khôi phục / lưu trữ: danh mục hệ thống là master data, ẩn nó sẽ
 * làm giao dịch và rule cũ khó truy vết. Muốn bỏ thì phải gỡ hết tham chiếu rồi
 * xoá cứng qua `deleteSystemCategory`.
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
     * Sửa danh mục hệ thống: tên, slug, mô tả.
     *
     * CHỈ trên cùng bản ghi `Category` đã có. `category_id` bất biến; không tạo
     * danh mục mới; không tạo version policy mới. Slug CHỈ đổi khi payload có
     * khoá `slug` (tức người dùng thực sự gửi) — đổi tên KHÔNG tự sinh slug mới.
     *
     * `category_id` là tham chiếu của policy rule và combo item, còn `slug` chỉ là
     * nhãn/khoá ánh xạ hiển thị (`CategoryIcon`), nên đổi slug không làm hỏng tham
     * chiếu nào.
     *
     * @param  array{name?:string, slug?:string, description?:string|null}  $attributes
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

            if (array_key_exists('slug', $attributes)) {
                $category->slug = $this->normalizeSlug($attributes['slug'], $category);
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
     * Chuẩn hoá slug khi sửa: trim + lowercase + kebab-case, và chặn trùng.
     *
     * `Str::slug()` xử lý ký tự tiếng Việt (`Đại chúng` → `dai-chung`) nên người
     * dùng không phải tự gõ dấu `-`. Request đã chuẩn hoá + chặn trùng trước đó,
     * nhưng vẫn giữ `Str::slug()` ở đây làm lưới an toàn cho các call path khác
     * gọi thẳng service (đường ghi này KHÔNG được tin validation của request).
     *
     * Trùng được chặn ở TẦNG SERVICE (không chỉ ở request) vì đây là đường ghi duy
     * nhất: DB có unique `(scope, owner_user_id, slug)` nên thiếu check sẽ nổi
     * lỗi 1062 khó hiểu thay vì thông báo rõ ràng.
     */
    private function normalizeSlug(mixed $value, Category $category): string
    {
        $slug = Str::slug(mb_strtolower(trim((string) $value)));

        if ($slug === '') {
            throw new InvalidArgumentException('Slug danh mục không được để trống.');
        }

        // Chính nó thì không phải trùng — lưu lại slug cũ phải hợp lệ.
        if ($slug === $category->slug) {
            return $slug;
        }

        if ($this->slugExists($slug, $category)) {
            throw new InvalidArgumentException(sprintf('Slug "%s" đã tồn tại trong danh mục hệ thống.', $slug));
        }

        return $slug;
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

    /**
     * Xoá danh mục HỆ THỐNG — CHỈ khi không còn bất kỳ tham chiếu nào.
     *
     * Hàng rào (fail loudly thay vì để DB nổi lỗi FK 1451 khó hiểu):
     *   - còn rule cashback / combo / giao dịch / điều kiện chi tiêu / ánh xạ MCC
     *     ⇒ ném `LogicException` kèm danh sách tham chiếu cụ thể;
     *   - sạch tham chiếu ⇒ xoá cứng trong 1 transaction.
     *
     * KHÔNG soft-delete: master data bị ẩn khỏi dropdown nhưng vẫn còn trong mọi
     * báo cáo là trạng thái nửa vời; muốn bỏ hẳn thì gỡ tham chiếu rồi xoá.
     *
     * @return array{deleted: bool, category: Category}
     */
    public function deleteSystemCategory(Category $category): array
    {
        $this->assertSystem($category);

        return DB::connection('creditcard')->transaction(function () use ($category): array {
            $usage = $this->usageOf($category);

            if (array_sum($usage) > 0) {
                throw new LogicException($this->blockedMessage($category, $usage));
            }

            $category->delete();

            return ['deleted' => true, 'category' => $category];
        });
    }

    /**
     * Danh mục hệ thống đang được tham chiếu ở đâu?
     *
     * Đếm theo từng nhóm để vừa dựng hàng rào xoá, vừa đủ dữ liệu cho thông báo
     * "còn dùng ở đâu". Gồm cả các bảng FK RESTRICT (rule / combo item / điều
     * kiện chi tiêu) lẫn các bảng nullable nhưng cần giữ toàn vẹn (giao dịch, MCC).
     *
     * @return array<string, int>
     */
    public function usageOf(Category $category): array
    {
        $id = (int) $category->id;

        return [
            'rules' => PolicyTierCategory::query()->where('category_id', $id)->count(),
            'combo_items' => CategoryComboItem::query()->where('category_id', $id)->count(),
            'transactions' => Transaction::query()->where('category_id', $id)->count(),
            'spend_qualification_conditions' => SpendQualificationCondition::query()->where('category_id', $id)->count(),
            'spend_qualification_excluded' => SpendQualificationConditionExcludedCategory::query()->where('category_id', $id)->count(),
            'spend_qualification_template_conditions' => SpendQualificationTemplateCondition::query()->where('category_id', $id)->count(),
            'spend_qualification_template_excluded' => SpendQualificationTemplateConditionExcludedCategory::query()->where('category_id', $id)->count(),
            'mcc_mappings' => CardPolicyMccMapping::query()->where('category_id', $id)->count(),
        ];
    }

    /**
     * @param  array<string, int>  $usage
     */
    private function blockedMessage(Category $category, array $usage): string
    {
        $labels = [
            'rules' => 'rule cashback',
            'combo_items' => 'combo danh mục',
            'transactions' => 'giao dịch',
            'spend_qualification_conditions' => 'điều kiện chi tiêu của chính sách',
            'spend_qualification_excluded' => 'danh mục loại trừ của chính sách',
            'spend_qualification_template_conditions' => 'điều kiện chi tiêu của mẫu',
            'spend_qualification_template_excluded' => 'danh mục loại trừ của mẫu',
            'mcc_mappings' => 'ánh xạ MCC',
        ];

        $parts = [];
        foreach ($usage as $key => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.($labels[$key] ?? $key);
            }
        }

        return sprintf(
            'Không thể xoá danh mục hệ thống "%s" vì đang được tham chiếu: %s. Hãy gỡ các tham chiếu trước.',
            $category->name,
            implode(', ', $parts)
        );
    }

    private function assertSystem(Category $category): void
    {
        if (! $category->isSystem()) {
            throw new LogicException('Module danh mục hệ thống chỉ quản lý danh mục system, không quản lý danh mục riêng của user.');
        }
    }

    private function slugExists(string $slug, ?Category $except = null): bool
    {
        return Category::query()
            ->system()
            ->where('slug', $slug)
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->exists();
    }
}
