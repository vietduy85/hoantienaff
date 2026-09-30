<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * CategoryService — quản lý danh mục CHI TIÊU.
 *
 * ---------------------------------------------------------------------------
 * SYSTEM CATEGORY: READ-ONLY
 * ---------------------------------------------------------------------------
 * 19 danh mục hệ thống do owner chốt, seeded từ `CreditCardSeeder`. Service này
 * KHÔNG có hàm sửa/xoá danh mục hệ thống — kể cả khi gọi trực tiếp, không phải
 * chỉ dựa vào Policy. Lý do: danh mục hệ thống là khoá mà policy rule và dữ liệu
 * lịch sử tham chiếu; đổi tên/xoá sẽ làm mất khả năng truy vết cashback đã ghi.
 *
 * ---------------------------------------------------------------------------
 * USER CATEGORY: CRUD CÓ CHỦ SỞ HỮU
 * ---------------------------------------------------------------------------
 * `scope = 'user'`, `owner_user_id = N`. Mọi truy vấn đều scope theo user để
 * user A không bao giờ thấy danh mục của user B.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG HARD-DELETE (§8 historical integrity)
 * ---------------------------------------------------------------------------
 * Một danh mục có thể đã được tham chiếu bởi:
 *   - `credit_card_policy_tier_categories.category_id` (FK RESTRICT) — cấu hình cashback,
 *   - `credit_card_transactions.category_id` — dữ liệu lịch sử đã ghi cashback.
 *
 * Xoá cứng sẽ phá vỡ cả hai. Nên:
 *   1. Nếu CHƯA dùng ở đâu ⇒ xoá cứng được (dữ liệu rác, không cần giữ).
 *   2. Nếu ĐÃ dùng ⇒ chuyển `is_active = false` (soft). Danh mục biến khỏi
 *      dropdown chọn mới, nhưng giao dịch cũ và policy rule cũ vẫn đọc được và
 *      hiển thị đúng tên.
 */
class CategoryService
{
    /**
     * Danh mục user được phép thấy/được chọn: 19 system + của riêng họ.
     *
     * @return Collection<int, Category>
     */
    public function selectableFor(int $userId, ?string $keyword = null): Collection
    {
        return Category::query()
            ->selectableBy($userId)
            ->when($keyword !== null && trim($keyword) !== '', function ($query) use ($keyword): void {
                $needle = trim($keyword);
                $query->where(function ($inner) use ($needle): void {
                    $inner->where('name', 'like', '%'.$needle.'%')
                        ->orWhere('slug', 'like', '%'.$needle.'%');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Toàn bộ danh mục user nhìn thấy (kể cả đã inactive) — dùng cho màn hình
     * quản lý nơi cần biết danh mục riêng đã ẩn.
     *
     * @return Collection<int, Category>
     */
    public function allVisibleTo(int $userId): Collection
    {
        return Category::query()
            ->where(function (Builder $inner) use ($userId): void {
                $inner->where('scope', Category::SCOPE_SYSTEM)
                    ->orWhere(function (Builder $user) use ($userId): void {
                        $user->where('scope', Category::SCOPE_USER)
                            ->where('owner_user_id', $userId);
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function systemCategories(): Collection
    {
        return Category::query()
            ->system()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function userCategories(int $userId): Collection
    {
        return Category::query()
            ->userOwned($userId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Lấy một danh mục mà user ĐƯỢC PHÉP chạm tới.
     *
     * Ném exception nếu là danh mục riêng của user khác ⇒ đây là lớp phòng thủ
     * thứ hai sau Policy, áp dụng cả khi service được gọi ngoài HTTP.
     */
    public function findOwnedOrSystem(int $categoryId, int $userId): Category
    {
        $category = Category::query()->whereKey($categoryId)->first();

        if ($category === null) {
            throw new \InvalidArgumentException("Danh mục #{$categoryId} không tồn tại.");
        }

        if ($category->isSystem()) {
            return $category;
        }

        if ((int) $category->owner_user_id !== $userId) {
            throw new \InvalidArgumentException('Danh mục này không thuộc về bạn.');
        }

        return $category;
    }

    /**
     * Tạo danh mục RIÊNG của user. Không có đường tạo danh mục hệ thống.
     *
     * @param  array{name:string, description?:string|null, sort_order?:int|null}  $attributes
     */
    public function createUserCategory(int $userId, array $attributes): Category
    {
        return DB::connection('creditcard')->transaction(function () use ($userId, $attributes): Category {
            return Category::create([
                'scope' => Category::SCOPE_USER,
                'owner_user_id' => $userId,
                'name' => $attributes['name'],
                'slug' => $this->uniqueUserSlug($userId, $attributes['name']),
                'description' => $attributes['description'] ?? null,
                // Danh mục riêng mặc định không phải "mặc định" của hệ thống.
                'is_default' => false,
                'is_active' => true,
                'sort_order' => $attributes['sort_order'] ?? 0,
            ]);
        });
    }

    /**
     * Sửa danh mục riêng. Danh mục hệ thống bị chặn ở đây (không chỉ ở Policy).
     *
     * @param  array{name?:string, description?:string|null, sort_order?:int|null, is_active?:bool}  $attributes
     */
    public function updateUserCategory(int $userId, int $categoryId, array $attributes): Category
    {
        return DB::connection('creditcard')->transaction(function () use ($userId, $categoryId, $attributes): Category {
            $category = $this->findOwnedOrSystem($categoryId, $userId);

            if ($category->isSystem()) {
                throw new LogicException('Danh mục hệ thống là master data, không được sửa từ luồng user.');
            }

            $this->assertEditableFields($category, $attributes);

            if (array_key_exists('name', $attributes) && $attributes['name'] !== $category->name) {
                $category->name = $attributes['name'];
                // Đổi tên ⇒ đổi slug để không tồn tại 2 danh mục cùng slug.
                $category->slug = $this->uniqueUserSlug($userId, $attributes['name'], $category->id);
            }

            if (array_key_exists('description', $attributes)) {
                $category->description = $attributes['description'];
            }

            if (array_key_exists('sort_order', $attributes)) {
                $category->sort_order = (int) $attributes['sort_order'];
            }

            if (array_key_exists('is_active', $attributes)) {
                $category->is_active = (bool) $attributes['is_active'];
            }

            $category->save();

            return $category->refresh();
        });
    }

    /**
     * Xoá danh mục riêng.
     *
     * - CHƯA dùng ở giao dịch nào ⇒ xoá cứng (không để lại rác).
     * - ĐÃ dùng ⇒ soft-delete (`is_active = false`), giữ nguyên tên để dữ liệu
     *   lịch sử và policy rule cũ vẫn tra được.
     *
     * @return array{deleted: bool, category: Category}
     */
    public function deleteUserCategory(int $userId, int $categoryId): array
    {
        return DB::connection('creditcard')->transaction(function () use ($userId, $categoryId): array {
            $category = $this->findOwnedOrSystem($categoryId, $userId);

            if ($category->isSystem()) {
                throw new LogicException('Danh mục hệ thống không được xoá.');
            }

            $usage = $this->usageOf($category);

            // Chưa dùng ở đâu ⇒ xoá cứng an toàn, không cần giữ lại.
            if ($usage['transactions'] === 0 && $usage['rules'] === 0) {
                $category->delete();

                return ['deleted' => true, 'category' => $category];
            }

            // Đã dùng ⇒ chuyển inactive. Tuyệt đối KHÔNG xoá cứng.
            $category->forceFill(['is_active' => false])->save();

            return ['deleted' => false, 'category' => $category->refresh()];
        });
    }

    /**
     * Danh mục này đang được tham chiếu ở đâu?
     *
     * @return array{transactions: int, rules: int}
     */
    public function usageOf(Category $category): array
    {
        return [
            'transactions' => Transaction::query()
                ->where('category_id', $category->id)
                ->count(),
            'rules' => PolicyTierCategory::query()
                ->where('category_id', $category->id)
                ->count(),
        ];
    }

    public function isInUse(Category $category): bool
    {
        $usage = $this->usageOf($category);

        return $usage['transactions'] > 0 || $usage['rules'] > 0;
    }

    /**
     * Danh mục CHƯA dùng thì sửa thoải mái. ĐÃ dùng thì chỉ cho phép bật/tắt.
     *
     * Lý do: `is_active` chỉ quyết định danh mục có xuất hiện trong danh sách
     * chọn cho giao dịch MỚI hay không, nên thao tác này không đụng tới dữ liệu
     * cũ. Còn lại (đặc biệt `name`) là nhãn hiển thị của giao dịch và rule đã
     * ghi — đổi nó làm sai lịch sử. Muốn đổi nhãn thì ẩn danh mục cũ rồi tạo
     * danh mục mới.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertEditableFields(Category $category, array $attributes): void
    {
        if (! $this->isInUse($category)) {
            return;
        }

        $hardFields = array_diff(array_keys($attributes), ['is_active']);

        if ($hardFields !== []) {
            throw new LogicException(
                'Danh mục đang được dùng cho giao dịch hoặc rule cashback nên không sửa được ('
                .implode(', ', $hardFields).'). Hãy ẩn danh mục (inactive) và tạo danh mục mới thay thế.'
            );
        }
    }

    /**
     * Slug duy nhất TRONG PHẠM VI user (vì unique index là (scope, owner_user_id, slug)).
     */
    private function uniqueUserSlug(int $userId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'danh-muc';
        $slug = $base;
        $suffix = 1;

        while (Category::query()
            ->userOwned($userId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
