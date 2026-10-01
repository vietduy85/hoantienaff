<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * CategoryComboService — quản lý COMBO (nhóm danh mục chi tiêu).
 *
 * ---------------------------------------------------------------------------
 * MỘT SERVICE, HAI ĐƯỜNG GHI TƯỢNG MINH
 * ---------------------------------------------------------------------------
 * Khác với Category (tách `SystemCategoryService` / `CategoryService` vì user
 * hoàn toàn READ-ONLY với danh mục hệ thống), Combo dùng CHUNG một code path cho
 * cả hai scope — chỉ khác ở tập danh mục được phép ghép và ai được sửa. Nên
 * service này có các method tách biệt theo scope (`createSystemCombo` /
 * `createUserCombo`, `updateSystemCombo` / `updateUserCombo`) thay vì một
 * `create($scope, ...)` để không bao giờ phải truyền scope tùy tiện từ controller.
 *
 * ---------------------------------------------------------------------------
 * INVARIANT
 * ---------------------------------------------------------------------------
 *   1. Combo có ÍT NHẤT 1 danh mục — combo rỗng vô nghĩa và không thể khớp giao
 *      dịch nào.
 *   2. Không trùng thành viên trong cùng combo (đã có UNIQUE ở DB, service
 *      báo lỗi rõ ràng trước khi chết ở tầng DB).
 *   3. Combo KHÔNG chứa combo — chỉ nhận `category_id`; không có đường nào nhận
 *      id combo.
 *   4. Ownership thành viên:
 *        - combo hệ thống → chỉ danh mục HỆ THỐNG (admin không được nhét danh
 *          mục riêng của user vào combo hệ thống, vì combo hệ thống được mọi
 *          policy thẻ dùng).
 *        - combo riêng của user → danh mục hệ thống + danh mục RIÊNG CỦA CHÍNH
 *          user đó. Không bao giờ danh mục của người khác.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG CÓ XOÁ
 * ---------------------------------------------------------------------------
 * Task này không triển khai xoá combo. Ngoài yêu cầu, lý do kỹ thuật là combo đã
 * được rule tham chiếu thì FK `combo_id` là RESTRICT, và các bản ghi lịch sử đọc
 * membership qua combo để hiển thị nhãn. Để xoá an toàn sau này cần chính sách
 * "đã dùng ⇒ ẩn", giống `CategoryService::deleteUserCategory()` — sẽ làm ở task
 * riêng chứ không gộp vào đây.
 *
 * ---------------------------------------------------------------------------
 * SNAPSHOT KHI DEEP-CLONE POLICY (Q3/Q4 đã chốt)
 * ---------------------------------------------------------------------------
 * `cloneForUser()` tạo bản sao combo ở scope `user` rồi copy membership. Đây là
 * câu trả lời cho "sửa membership combo hệ thống có làm đổi cashback của user
 * policy đã clone không": KHÔNG, vì user policy trỏ vào bản sao riêng.
 *
 * Lý do KHÔNG dùng chung bản ghi (như Category làm): `Category` là danh tính bất
 * biến — đổi tên không đổi hành vi. Membership combo thì là nội dung mang
 * behavior; dùng chung bản ghi sẽ phá đúng bất biến deep clone §13 ("sửa policy
 * của A ⇒ B đổi theo — không ai muốn điều này").
 */
class CategoryComboService
{
    // =====================================================================
    // ĐỌC
    // =====================================================================

    /**
     * Toàn bộ combo hệ thống, kèm membership — dùng cho màn quản trị admin.
     *
     * @return Collection<int, CategoryCombo>
     */
    public function systemCombos(): Collection
    {
        return CategoryCombo::query()
            ->system()
            ->with(['items.category'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Combo user được phép thấy/được chọn: combo hệ thống + combo riêng của họ.
     *
     * @return Collection<int, CategoryCombo>
     */
    public function selectableFor(int $userId, ?string $keyword = null): Collection
    {
        return CategoryCombo::query()
            ->selectableBy($userId)
            ->with(['items.category'])
            ->when($keyword !== null && trim($keyword) !== '', function (Builder $query) use ($keyword): void {
                $needle = trim($keyword);
                $query->where(function (Builder $inner) use ($needle): void {
                    $inner->where('name', 'like', '%'.$needle.'%')
                        ->orWhere('slug', 'like', '%'.$needle.'%');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Toàn bộ combo user nhìn thấy (kể cả đã inactive) — dùng cho màn quản lý.
     *
     * @return Collection<int, CategoryCombo>
     */
    public function allVisibleTo(int $userId): Collection
    {
        return CategoryCombo::query()
            ->where(function (Builder $inner) use ($userId): void {
                $inner->where('scope', CategoryCombo::SCOPE_SYSTEM)
                    ->orWhere(function (Builder $user) use ($userId): void {
                        $user->where('scope', CategoryCombo::SCOPE_USER)
                            ->where('owner_user_id', $userId);
                    });
            })
            ->with(['items.category'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Combo mà user ĐƯỢC PHÉP chạm tới (dùng cho cả đọc lẫn validate).
     *
     * Ném exception nếu là combo riêng của user khác ⇒ lớp phòng thủ thứ hai sau
     * Policy, áp dụng cả khi service được gọi ngoài HTTP.
     */
    public function findOwnedOrSystem(int $comboId, int $userId): CategoryCombo
    {
        $combo = CategoryCombo::query()->whereKey($comboId)->first();

        if ($combo === null) {
            throw new InvalidArgumentException("Combo #{$comboId} không tồn tại.");
        }

        if ($combo->isSystem()) {
            return $combo;
        }

        if ((int) $combo->owner_user_id !== $userId) {
            throw new InvalidArgumentException('Combo này không thuộc về bạn.');
        }

        return $combo;
    }

    /**
     * Combo có dùng được cho policy này không (dùng bởi `CategoryRuleService`).
     *
     * Cùng nguyên tắc phạm vi như `assertCategoryUsable()`:
     *   - Policy hệ thống (blueprint, `user_card_id = NULL`): CHỈ combo hệ thống.
     *   - Policy của thẻ: combo hệ thống + combo riêng của CHÍNH user sở hữu thẻ.
     */
    public function assertUsableForPolicy(int $comboId, Policy $policy): void
    {
        $query = CategoryCombo::query()->active()->whereKey($comboId);

        if ($policy->isBlueprint()) {
            $query->where('scope', CategoryCombo::SCOPE_SYSTEM);
        } else {
            $query->where(function (Builder $systemOrOwn) use ($policy): void {
                $systemOrOwn->where('scope', CategoryCombo::SCOPE_SYSTEM)
                    ->orWhere(function (Builder $own) use ($policy): void {
                        $own->where('scope', CategoryCombo::SCOPE_USER)
                            ->where('owner_user_id', (int) ($policy->userCard?->user_id));
                    });
            });
        }

        if (! $query->exists()) {
            throw new InvalidArgumentException(
                'Combo #'.$comboId.' không tồn tại, đã bị ẩn hoặc không thuộc phạm vi cho phép.'
            );
        }
    }

    // =====================================================================
    // GHI — HỆ THỐNG (admin)
    // =====================================================================

    /**
     * Tạo combo hệ thống. Slug chuẩn hoá (kebab-case) và duy nhất trong scope
     * system. Thứ tự ban đầu: đặt ở cuối danh sách hiện có.
     *
     * @param  array{name:string, slug?:string|null, description?:string|null, sort_order?:int|null, category_ids:array<int, mixed>}  $attributes
     */
    public function createSystemCombo(array $attributes): CategoryCombo
    {
        return DB::connection('creditcard')->transaction(function () use ($attributes): CategoryCombo {
            $name = $this->requireName($attributes['name'] ?? null);
            $slug = $this->resolveSlug($attributes['slug'] ?? null, $name, CategoryCombo::SCOPE_SYSTEM, CategoryCombo::SYSTEM_OWNER_ID);
            $categoryIds = $this->requireCategoryIds($attributes['category_ids'] ?? null);

            $this->assertCategoriesBelongToSystemCombo($categoryIds);

            $combo = CategoryCombo::create([
                'scope' => CategoryCombo::SCOPE_SYSTEM,
                'owner_user_id' => CategoryCombo::SYSTEM_OWNER_ID,
                'name' => $name,
                'slug' => $slug,
                'description' => $attributes['description'] ?? null,
                'is_active' => true,
                'sort_order' => $attributes['sort_order'] ?? $this->nextSystemSortOrder(),
            ]);

            $this->writeItems($combo, $categoryIds);

            return $combo->refresh()->load('items.category');
        });
    }

    /**
     * Sửa combo hệ thống. Chặn sửa combo của user.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateSystemCombo(CategoryCombo $combo, array $attributes): CategoryCombo
    {
        if (! $combo->isSystem()) {
            throw new LogicException('Module combo hệ thống chỉ quản lý combo system, không quản lý combo riêng của user.');
        }

        return DB::connection('creditcard')->transaction(function () use ($combo, $attributes): CategoryCombo {
            if (array_key_exists('name', $attributes)) {
                $name = $this->requireName($attributes['name']);
                $combo->name = $name;
                // Đổi tên ⇒ sinh lại slug để không tồn tại 2 combo cùng slug.
                $combo->slug = $this->resolveSlug(null, $name, $combo->scope, (int) $combo->owner_user_id, $combo->id);
            }

            if (array_key_exists('description', $attributes)) {
                $combo->description = $attributes['description'] === '' ? null : $attributes['description'];
            }

            if (array_key_exists('is_active', $attributes)) {
                $combo->is_active = (bool) $attributes['is_active'];
            }

            if (array_key_exists('sort_order', $attributes)) {
                $combo->sort_order = (int) $attributes['sort_order'];
            }

            $combo->save();

            if (array_key_exists('category_ids', $attributes)) {
                $categoryIds = $this->requireCategoryIds($attributes['category_ids']);
                $this->assertCategoriesBelongToSystemCombo($categoryIds);
                $this->assertMembershipKeepsTierRulesValid($combo, $categoryIds);
                $this->writeItems($combo, $categoryIds);
            }

            return $combo->refresh()->load('items.category');
        });
    }

    // =====================================================================
    // GHI — RIÊNG CỦA USER
    // =====================================================================

    /**
     * Tạo combo riêng của user. Không có đường nào tạo combo hệ thống từ phía user.
     *
     * @param  array{name:string, description?:string|null, sort_order?:int|null, category_ids:array<int, mixed>}  $attributes
     */
    public function createUserCombo(int $userId, array $attributes): CategoryCombo
    {
        return DB::connection('creditcard')->transaction(function () use ($userId, $attributes): CategoryCombo {
            $name = $this->requireName($attributes['name'] ?? null);
            $slug = $this->resolveSlug(null, $name, CategoryCombo::SCOPE_USER, $userId);
            $categoryIds = $this->requireCategoryIds($attributes['category_ids'] ?? null);

            $this->assertCategoriesBelongToUserCombo($categoryIds, $userId);

            $combo = CategoryCombo::create([
                'scope' => CategoryCombo::SCOPE_USER,
                'owner_user_id' => $userId,
                'name' => $name,
                'slug' => $slug,
                'description' => $attributes['description'] ?? null,
                'is_active' => true,
                'sort_order' => (int) ($attributes['sort_order'] ?? 0),
            ]);

            $this->writeItems($combo, $categoryIds);

            return $combo->refresh()->load('items.category');
        });
    }

    /**
     * Sửa combo riêng. Combo hệ thống bị chặn ở đây (không chỉ ở Policy).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateUserCombo(int $userId, int $comboId, array $attributes): CategoryCombo
    {
        return DB::connection('creditcard')->transaction(function () use ($userId, $comboId, $attributes): CategoryCombo {
            $combo = $this->findOwnedOrSystem($comboId, $userId);

            if ($combo->isSystem()) {
                throw new LogicException('Combo hệ thống là master data, không được sửa từ luồng user.');
            }

            if (array_key_exists('name', $attributes)) {
                $name = $this->requireName($attributes['name']);
                $combo->name = $name;
                $combo->slug = $this->resolveSlug(null, $name, $combo->scope, (int) $combo->owner_user_id, $combo->id);
            }

            if (array_key_exists('description', $attributes)) {
                $combo->description = $attributes['description'] === '' ? null : $attributes['description'];
            }

            if (array_key_exists('is_active', $attributes)) {
                $combo->is_active = (bool) $attributes['is_active'];
            }

            $combo->save();

            if (array_key_exists('category_ids', $attributes)) {
                $categoryIds = $this->requireCategoryIds($attributes['category_ids']);
                $this->assertCategoriesBelongToUserCombo($categoryIds, $userId);
                $this->assertMembershipKeepsTierRulesValid($combo, $categoryIds);
                $this->writeItems($combo, $categoryIds);
            }

            return $combo->refresh()->load('items.category');
        });
    }

    // =====================================================================
    // SNAPSHOT CHO DEEP CLONE
    // =====================================================================

    /**
     * Tạo bản sao combo ở scope `user` (membership snapshot) cho deep-clone policy.
     *
     * Gọi bởi `PolicyCloneService` khi copy rule từ blueprint hệ thống sang user
     * policy. Bản sao là bản ghi RIÊNG: sau khi clone, sửa combo hệ thống KHÔNG
     * còn tác động tới user policy nữa.
     *
     * Thành viên được copy NGUYÊN VĂN (kể cả danh mục hệ thống) vì
     * `credit_card_categories` là danh tính bất biến — dùng chung master row ở
     * đây là đúng, và làm vậy giữ combo của user "nhẹ" hơn.
     *
     * Cùng combo + cùng owner chỉ clone MỘT bản (idempotent trong một lần
     * clone nhiều bậc): sau đó các bậc khác dùng lại bản sao đó.
     *
     * @param  array<string, CategoryCombo>  $cache  các bản sao đã tạo trong cùng lượt clone
     */
    public function cloneForUser(CategoryCombo $source, int $userId, string $nameSuffix, array &$cache = []): CategoryCombo
    {
        $key = $source->id.'|'.$userId;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $clone = CategoryCombo::create([
            'scope' => CategoryCombo::SCOPE_USER,
            'owner_user_id' => $userId,
            'name' => $nameSuffix,
            'slug' => $this->resolveSlug(null, $nameSuffix, CategoryCombo::SCOPE_USER, $userId),
            'description' => $source->description,
            'is_active' => (bool) $source->is_active,
            'sort_order' => (int) $source->sort_order,
        ]);

        $categoryIds = $source->items()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->writeItems($clone, $categoryIds);

        $cache[$key] = $clone;

        return $clone->refresh();
    }

    // =====================================================================
    // NỘI BỘ
    // =====================================================================

    /**
     * Sửa membership có làm HAI COMBO RULE trong cùng bậc bắt đầu chung danh mục không?
     *
     * Bất biến "mỗi bậc chỉ một combo rule chứa danh mục" đang được
     * `CategoryRuleService` canh khi tạo/sửa RULE. Nhưng membership nằm ở đây:
     * thêm danh mục X vào combo B có thể phá bất biến đã hợp lệ ở một bậc nào đó
     * mà không có lần gọi service nào ở luồng ghi rule. Nếu không canh, một giao
     * dịch sẽ khớp 2 rule ⇒ kết quả phụ thuộc thứ tự duyệt.
     *
     * Chỉ kiểm tra các combo KHÁC đang có rule trong cùng bậc với combo này — đúng
     * phạm vi lỗi, không chặn combo chưa được dùng ở đâu (đó là hợp lệ).
     *
     * @param  array<int, int>  $newCategoryIds
     * @return array<int, int> các danh mục gây xung đột (rỗng = an toàn)
     */
    public function conflictingMemberCategoryIds(CategoryCombo $combo, array $newCategoryIds): array
    {
        $tierIds = PolicyTierCategory::query()
            ->comboSpecific()
            ->where('combo_id', (int) $combo->id)
            ->pluck('tier_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        if ($tierIds === []) {
            return [];
        }

        // Trong từng bậc đang dùng combo này, combo nào khác cũng có rule không?
        $otherComboIds = PolicyTierCategory::query()
            ->comboSpecific()
            ->whereIn('tier_id', $tierIds)
            ->where('combo_id', '!=', (int) $combo->id)
            ->pluck('combo_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($otherComboIds->isEmpty()) {
            return [];
        }

        return CategoryComboItem::query()
            ->whereIn('combo_id', $otherComboIds->all())
            ->whereIn('category_id', $newCategoryIds)
            ->distinct()
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Danh mục thuộc combo, theo thứ tự hiển thị.
     *
     * Dùng bởi `CategoryRuleService` (chặn 2 combo rule trong cùng bậc không được
     * chia sẻ danh mục) và báo cáo.
     *
     * @return array<int, int>
     */
    public function memberCategoryIds(int $comboId): array
    {
        return CategoryComboItem::query()
            ->where('combo_id', $comboId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Ghi lại membership của combo.
     *
     * Xoá toàn bộ item cũ rồi insert lại theo thứ tự gửi lên. Đơn giản hơn việc
     * diff vì combo có tối đa vài chục thành viên, và luôn bảo đảm không còn thành
     * viên "ma" sau khi sửa.
     *
     * @param  array<int, int>  $categoryIds  đã chuẩn hoá (unique, >0)
     */
    private function writeItems(CategoryCombo $combo, array $categoryIds): void
    {
        CategoryComboItem::query()->where('combo_id', $combo->id)->delete();

        $now = now();

        $rows = [];
        $sort = 0;

        foreach ($categoryIds as $categoryId) {
            $rows[] = [
                'combo_id' => (int) $combo->id,
                'category_id' => $categoryId,
                'sort_order' => ++$sort,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        CategoryComboItem::query()->insert($rows);
    }

    /**
     * Chuẩn hoá danh sách `category_ids` từ input: ép về int, bỏ rỗng, giữ nguyên
     * thứ tự gửi lên.
     *
     * Trùng thành viên trong CÙNG combo bị TỪ CHỐI chứ không âm thầm loại bỏ: đã chốt
     * "không cho phép danh mục trùng", và nếu im lặng dedupe thì admin gửi
     * `['1','1','2']` sẽ không bao giờ biết mình đang tạo dữ liệu sai — thứ tự
     * hiển thị combo cũng lệch so với cái họ nhìn thấy. Báo lỗi tường minh giống
     * hành vi ở `CategoryService`.
     *
     * @return array<int, int>
     */
    private function requireCategoryIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException('Combo phải có danh sách danh mục.');
        }

        $ids = [];
        $seen = [];

        foreach ($raw as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $id = (int) $value;

            if ($id <= 0) {
                throw new InvalidArgumentException('Danh mục không hợp lệ trong combo.');
            }

            if (isset($seen[$id])) {
                throw new InvalidArgumentException("Danh mục #$id bị lặp trong combo. Mỗi danh mục chỉ được xuất hiện một lần.");
            }

            $seen[$id] = true;
            $ids[] = $id;
        }

        if ($ids === []) {
            throw new InvalidArgumentException('Combo phải có ít nhất một danh mục.');
        }

        return $ids;
    }

    /**
     * Membership mới không được làm bất biến "mỗi bậc một combo rule cho danh mục"
     * bị vỡ — xem `conflictingMemberCategoryIds()`.
     *
     * @param  array<int, int>  $categoryIds
     */
    private function assertMembershipKeepsTierRulesValid(CategoryCombo $combo, array $categoryIds): void
    {
        $conflicts = $this->conflictingMemberCategoryIds($combo, $categoryIds);

        if ($conflicts !== []) {
            throw new LogicException(
                'Danh mục đã nằm trong combo khác có rule trong cùng bậc ('
                .'#'.implode(', #', $conflicts).'). Gỡ danh mục khỏi combo khác trước khi thêm vào combo này.'
            );
        }
    }

    /**
     * Combo hệ thống chỉ được chứa danh mục hệ thống ĐANG HOẠT ĐỘNG.
     *
     * @param  array<int, int>  $categoryIds
     */
    private function assertCategoriesBelongToSystemCombo(array $categoryIds): void
    {
        $valid = Category::query()
            ->system()
            ->active()
            ->whereIn('id', $categoryIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertAllResolved($categoryIds, $valid, 'Combo hệ thống chỉ được gồm danh mục hệ thống đang hoạt động.');
    }

    /**
     * Combo riêng của user chỉ được chứa danh mục hệ thống đang hoạt động +
     * danh mục RIÊNG CỦA CHÍNH user đó (không được danh mục user khác).
     *
     * @param  array<int, int>  $categoryIds
     */
    private function assertCategoriesBelongToUserCombo(array $categoryIds, int $userId): void
    {
        $valid = Category::query()
            ->active()
            ->where(function (Builder $inner) use ($userId): void {
                $inner->where('scope', Category::SCOPE_SYSTEM)
                    ->orWhere(function (Builder $own) use ($userId): void {
                        $own->where('scope', Category::SCOPE_USER)
                            ->where('owner_user_id', $userId);
                    });
            })
            ->whereIn('id', $categoryIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertAllResolved($categoryIds, $valid, 'Combo chỉ được gồm danh mục hệ thống và danh mục của chính bạn (đang hoạt động).');
    }

    /**
     * @param  array<int, int>  $requested
     * @param  array<int, int>  $valid
     */
    private function assertAllResolved(array $requested, array $valid, string $message): void
    {
        $missing = array_values(array_diff($requested, $valid));

        if ($missing !== []) {
            throw new InvalidArgumentException($message.' Không hợp lệ: #'.implode(', #', $missing).'.');
        }
    }

    private function requireName(mixed $name): string
    {
        $trimmed = trim((string) $name);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Tên combo không được để trống.');
        }

        return $trimmed;
    }

    /**
     * Slug chuẩn hoá + duy nhất TRONG PHẠM VI (scope, owner) — vì unique index là
     * (scope, owner_user_id, slug).
     */
    private function resolveSlug(
        ?string $requested,
        string $name,
        string $scope,
        int $ownerUserId,
        ?int $ignoreId = null,
    ): string {
        $base = Str::slug($requested !== null && trim($requested) !== '' ? $requested : $name) ?: 'combo';
        $slug = $base;
        $suffix = 1;

        while (CategoryCombo::query()
            ->where('scope', $scope)
            ->where('owner_user_id', $ownerUserId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function nextSystemSortOrder(): int
    {
        $max = CategoryCombo::query()->system()->max('sort_order');

        return ($max === null ? 0 : (int) $max) + 10;
    }
}
