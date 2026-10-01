<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\User;
use App\Services\CreditCard\CategoryComboService;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\PolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Module ADMIN "🍱 Quản lý Combo danh mục hệ thống".
 *
 * Bảo vệ cốt lõi:
 *   1. Phân quyền: đọc = `credit-cards.view`, ghi = `credit-cards.manage`.
 *   2. Combo hệ thống CHỈ gồm danh mục HỆ THỐNG đang hoạt động — không nhét được
 *      danh mục riêng của user (vì combo hệ thống được mọi policy thẻ dùng).
 *   3. Combo phải có ≥1 danh mục, không trùng thành viên.
 *   4. KHÔNG có route xoá.
 *   5. Admin module không được chạm vào combo riêng của user ⇒ 404.
 *   6. Sửa membership không được phá bất biến "mỗi bậc chỉ một combo rule cho
 *      danh mục" ⇒ trả 422 chứ không tạo dữ liệu mơ hồ.
 */
class SystemCategoryComboTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $member;

    private User $viewer;

    private User $manager;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        $admin = Role::create(['name' => 'Admin']);
        $admin->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);

        $this->member = User::factory()->create();
        $this->viewer = User::factory()->create()->givePermissionTo('credit-cards.view');
        $this->manager = User::factory()->create();
        $this->manager->assignRole('Admin');
    }

    // =====================================================================
    // Route + phân quyền
    // =====================================================================

    #[Test]
    public function routes_exist_and_there_is_no_delete_route(): void
    {
        $this->assertTrue(Route::has('admin.credit-card.system-combos.index'));
        $this->assertTrue(Route::has('admin.credit-card.system-combos.store'));
        $this->assertTrue(Route::has('admin.credit-card.system-combos.update'));

        $this->assertFalse(
            Route::has('admin.credit-card.system-combos.destroy'),
            'Module combo hệ thống KHÔNG được có route xoá.'
        );
    }

    #[Test]
    public function guest_is_redirected_from_page_and_gets_401_on_json(): void
    {
        $combo = $this->makeSystemCombo();

        $this->get(route('admin.credit-card.system-combos.index'))->assertRedirect(route('login'));

        $this->postJson(route('admin.credit-card.system-combos.store'), [
            'name' => 'X',
            'category_ids' => [$this->makeSystemCategory()->id],
        ])->assertUnauthorized();

        $this->patchJson(route('admin.credit-card.system-combos.update', $combo), [
            'name' => 'Y',
            'category_ids' => [$this->makeSystemCategory()->id],
        ])->assertUnauthorized();
    }

    #[Test]
    public function member_without_permission_is_forbidden(): void
    {
        $combo = $this->makeSystemCombo();

        $this->actingAs($this->member)
            ->get(route('admin.credit-card.system-combos.index'))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'X',
                'category_ids' => [$this->makeSystemCategory()->id],
            ])
            ->assertForbidden();

        $this->actingAs($this->member)
            ->patchJson(route('admin.credit-card.system-combos.update', $combo), [
                'name' => 'Y',
                'category_ids' => [$this->makeSystemCategory()->id],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function viewer_with_read_only_permission_cannot_write(): void
    {
        $combo = $this->makeSystemCombo();

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card.system-combos.index'))
            ->assertOk();

        $this->actingAs($this->viewer)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'X',
                'category_ids' => [$this->makeSystemCategory()->id],
            ])
            ->assertForbidden();

        $this->actingAs($this->viewer)
            ->patchJson(route('admin.credit-card.system-combos.update', $combo), [
                'name' => 'Y',
                'category_ids' => [$this->makeSystemCategory()->id],
            ])
            ->assertForbidden();
    }

    // =====================================================================
    // Trang index
    // =====================================================================

    #[Test]
    public function index_lists_system_combos_with_members_and_search_box(): void
    {
        $a = $this->makeSystemCategory(['name' => 'Ăn uống', 'slug' => 'an-uong']);
        $b = $this->makeSystemCategory(['name' => 'Mua sắm', 'slug' => 'mua-sam']);
        $this->makeSystemCombo(['name' => 'Combo an uong', 'category_ids' => [$a->id, $b->id]]);

        $response = $this->actingAs($this->manager)
            ->get(route('admin.credit-card.system-combos.index'))
            ->assertOk();

        // Nội dung danh sách được render client-side từ JSON nên chữ có dấu bị
        // escape thành \uXXXX — khẳng định trên slug (ASCII) + text tĩnh trong Blade.
        $response->assertSee('Quản lý Combo danh mục hệ thống');
        $response->assertSee('Combo an uong');
        $response->assertSee('an-uong');
        $response->assertSee('Tìm Combo theo tên');
        $response->assertSee('+ Tạo Combo');

        // Danh mục thành phần cũng phải có mặt trong payload để UI hiển thị.
        $response->assertSee('mua-sam');
    }

    #[Test]
    public function index_does_not_list_user_combos(): void
    {
        $category = $this->makeSystemCategory();
        $this->makeUserCombo($this->member->id, [
            'name' => 'Combo rieng cua toi',
            'category_ids' => [$category->id],
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.credit-card.system-combos.index'))
            ->assertOk()
            ->assertDontSee('Combo rieng cua toi');
    }

    // =====================================================================
    // Tạo mới
    // =====================================================================

    #[Test]
    public function admin_can_create_system_combo_from_system_categories(): void
    {
        $a = $this->makeSystemCategory(['name' => 'Ăn uống', 'slug' => 'an-uong']);
        $b = $this->makeSystemCategory(['name' => 'Mua sắm', 'slug' => 'mua-sam']);

        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Combo chi tiêu thiết yếu',
                'description' => 'Ăn uống + mua sắm.',
                'category_ids' => [$a->id, $b->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Combo chi tiêu thiết yếu');

        $combo = CategoryCombo::findOrFail($response->json('data.id'));

        $this->assertTrue($combo->isSystem());
        $this->assertSame(CategoryCombo::SYSTEM_OWNER_ID, (int) $combo->owner_user_id);
        $this->assertSame('combo-chi-tieu-thiet-yeu', $combo->slug);
        $this->assertTrue($combo->is_active);
        $this->assertSame(
            [$a->id, $b->id],
            $combo->items()->orderBy('sort_order')->pluck('category_id')->map(fn ($i) => (int) $i)->all()
        );
    }

    #[Test]
    public function slug_is_generated_from_name_and_deduplicated(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Y tế & Bệnh viện',
                'category_ids' => [$category->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'y-te-benh-vien');

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Y tế & Bệnh viện',
                'category_ids' => [$category->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'y-te-benh-vien-2');
    }

    #[Test]
    public function combo_must_have_at_least_one_category(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Combo rỗng',
                'category_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids');

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Thiếu danh mục',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids');

        $this->assertSame(0, CategoryCombo::query()->system()->count());
    }

    #[Test]
    public function duplicate_category_in_same_combo_is_rejected(): void
    {
        $a = $this->makeSystemCategory();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Combo trùng',
                'category_ids' => [$a->id, $a->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids');

        $this->assertSame(0, CategoryCombo::query()->system()->count());
    }

    #[Test]
    public function empty_name_is_rejected(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => '   ',
                'category_ids' => [$category->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    // =====================================================================
    // Ràng buộc ownership thành viên
    // =====================================================================

    #[Test]
    public function system_combo_cannot_contain_user_category(): void
    {
        $userCategory = $this->makeUserCategory($this->member->id);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Combo lẫn danh mục riêng',
                'category_ids' => [$userCategory->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids.0');

        $this->assertSame(0, CategoryCombo::query()->system()->count());
    }

    #[Test]
    public function system_combo_cannot_contain_inactive_system_category(): void
    {
        $inactive = $this->makeSystemCategory(['is_active' => false]);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Combo danh mục ẩn',
                'category_ids' => [$inactive->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids.0');
    }

    #[Test]
    public function system_combo_cannot_contain_another_user_category_of_other_user(): void
    {
        $otherOwner = User::factory()->create();
        $foreign = $this->makeUserCategory($otherOwner->id);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-combos.store'), [
                'name' => 'Combo người khác',
                'category_ids' => [$foreign->id],
            ])
            ->assertUnprocessable();
    }

    // =====================================================================
    // Sửa
    // =====================================================================

    #[Test]
    public function admin_can_rename_combo_and_slug_follows_name(): void
    {
        $category = $this->makeSystemCategory();
        $combo = $this->makeSystemCombo([
            'name' => 'Combo cũ',
            'category_ids' => [$category->id],
        ]);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-combos.update', $combo), [
                'name' => 'Combo mới',
                'category_ids' => [$category->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Combo mới')
            ->assertJsonPath('data.slug', 'combo-moi')
            ->assertJsonPath('data.id', $combo->id);
    }

    #[Test]
    public function admin_can_replace_combo_membership(): void
    {
        $a = $this->makeSystemCategory();
        $b = $this->makeSystemCategory();
        $c = $this->makeSystemCategory();
        $combo = $this->makeSystemCombo(['category_ids' => [$a->id, $b->id]]);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-combos.update', $combo), [
                'name' => $combo->name,
                'category_ids' => [$b->id, $c->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.category_ids', [$b->id, $c->id]);

        $this->assertSame(
            [$b->id, $c->id],
            CategoryComboItem::query()
                ->where('combo_id', $combo->id)
                ->orderBy('sort_order')
                ->pluck('category_id')
                ->map(fn ($i) => (int) $i)
                ->all()
        );

        // Danh mục đã bị gỡ không còn item "ma".
        $this->assertSame(
            0,
            CategoryComboItem::query()
                ->where('combo_id', $combo->id)
                ->where('category_id', $a->id)
                ->count()
        );
    }

    #[Test]
    public function update_to_empty_membership_is_rejected_and_keeps_old_membership(): void
    {
        $a = $this->makeSystemCategory();
        $combo = $this->makeSystemCombo(['category_ids' => [$a->id]]);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-combos.update', $combo), [
                'name' => $combo->name,
                'category_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids');

        $this->assertSame(
            [$a->id],
            CategoryComboItem::query()->where('combo_id', $combo->id)->pluck('category_id')->map(fn ($i) => (int) $i)->all()
        );
    }

    #[Test]
    public function update_cannot_introduce_user_category(): void
    {
        $system = $this->makeSystemCategory();
        $userCategory = $this->makeUserCategory($this->member->id);
        $combo = $this->makeSystemCombo(['category_ids' => [$system->id]]);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-combos.update', $combo), [
                'name' => $combo->name,
                'category_ids' => [$system->id, $userCategory->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids.1');

        $this->assertSame(
            [$system->id],
            CategoryComboItem::query()->where('combo_id', $combo->id)->pluck('category_id')->map(fn ($i) => (int) $i)->all()
        );
    }

    // =====================================================================
    // Cô lập với combo riêng của user
    // =====================================================================

    #[Test]
    public function admin_module_cannot_touch_user_combo(): void
    {
        $category = $this->makeSystemCategory();
        $userCombo = $this->makeUserCombo($this->member->id, [
            'name' => 'Combo của tôi',
            'category_ids' => [$category->id],
        ]);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-combos.update', $userCombo), [
                'name' => 'Bị hack',
                'category_ids' => [$category->id],
            ])
            ->assertNotFound();

        $this->assertSame('Combo của tôi', $userCombo->refresh()->name);
    }

    // =====================================================================
    // Bất biến "mỗi bậc chỉ một combo rule cho danh mục"
    // =====================================================================

    #[Test]
    public function membership_edit_is_rejected_when_it_would_make_two_combos_share_a_category_in_one_tier(): void
    {
        $a = $this->makeSystemCategory();
        $b = $this->makeSystemCategory();
        $c = $this->makeSystemCategory();

        $comboA = $this->makeSystemCombo(['name' => 'Combo A', 'category_ids' => [$a->id]]);
        $comboB = $this->makeSystemCombo(['name' => 'Combo B', 'category_ids' => [$b->id]]);

        // Cùng một bậc có rule cho CẢ combo A và combo B.
        $this->makeComboRuleTier([$comboA, $comboB]);

        // Thêm danh mục A (đang thuộc combo A có rule trong bậc này) vào combo B
        // ⇒ hai combo rule trong cùng bậc sẽ cùng chứa danh mục A.
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-combos.update', $comboB), [
                'name' => 'Combo B',
                'category_ids' => [$b->id, $a->id],
            ])
            ->assertUnprocessable();

        $this->assertSame(
            [$b->id],
            CategoryComboItem::query()->where('combo_id', $comboB->id)->orderBy('sort_order')->pluck('category_id')->map(fn ($i) => (int) $i)->all()
        );
    }

    #[Test]
    public function membership_edit_to_unused_category_is_allowed(): void
    {
        $a = $this->makeSystemCategory();
        $b = $this->makeSystemCategory();
        $c = $this->makeSystemCategory();

        $comboA = $this->makeSystemCombo(['name' => 'Combo A', 'category_ids' => [$a->id]]);
        $comboB = $this->makeSystemCombo(['name' => 'Combo B', 'category_ids' => [$b->id]]);
        $this->makeComboRuleTier([$comboA, $comboB]);

        // Danh mục C không nằm trong combo nào có rule ⇒ thêm vào combo B an toàn.
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-combos.update', $comboB), [
                'name' => 'Combo B',
                'category_ids' => [$b->id, $c->id],
            ])
            ->assertOk();

        $this->assertTrue(
            CategoryComboItem::query()
                ->where('combo_id', $comboB->id)
                ->where('category_id', $c->id)
                ->exists()
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeSystemCategory(array $attributes = []): Category
    {
        static $counter = 0;
        $counter++;

        return Category::create(array_merge([
            'scope' => Category::SCOPE_SYSTEM,
            'owner_user_id' => Category::SYSTEM_OWNER_ID,
            'name' => 'Danh mục hệ thống '.$counter,
            'slug' => 'danh-muc-he-thong-'.$counter,
            'is_active' => true,
            'sort_order' => $counter * 10,
        ], $attributes));
    }

    private function makeUserCategory(int $userId, array $attributes = []): Category
    {
        static $counter = 0;
        $counter++;

        return Category::create(array_merge([
            'scope' => Category::SCOPE_USER,
            'owner_user_id' => $userId,
            'name' => 'Danh mục riêng '.$counter,
            'slug' => 'danh-muc-rieng-'.$counter,
            'is_active' => true,
            'sort_order' => 0,
        ], $attributes));
    }

    private function makeSystemCombo(array $attributes = []): CategoryCombo
    {
        static $counter = 0;
        $counter++;

        $categoryIds = $attributes['category_ids'] ?? [$this->makeSystemCategory()->id];

        return app(CategoryComboService::class)->createSystemCombo([
            'name' => $attributes['name'] ?? 'Combo hệ thống '.$counter,
            'description' => $attributes['description'] ?? null,
            'category_ids' => $categoryIds,
        ]);
    }

    private function makeUserCombo(int $userId, array $attributes = []): CategoryCombo
    {
        static $counter = 0;
        $counter++;

        $categoryIds = $attributes['category_ids'] ?? [$this->makeSystemCategory()->id];

        return app(CategoryComboService::class)->createUserCombo($userId, [
            'name' => $attributes['name'] ?? 'Combo riêng '.$counter,
            'description' => $attributes['description'] ?? null,
            'category_ids' => $categoryIds,
        ]);
    }

    /**
     * Tạo một tier và gắn combo rule cho TỪNG combo trong danh sách, dùng để dựng
     * bất biến "mỗi bậc chỉ một combo rule cho danh mục".
     *
     * @param  array<int, CategoryCombo>  $combos
     */
    private function makeComboRuleTier(array $combos): void
    {
        $policy = $this->makeSystemPolicy();
        $tier = $policy->blueprint()->firstOrFail()->tiers()->firstOrFail();

        $service = app(CategoryRuleService::class);

        foreach ($combos as $combo) {
            $service->create($tier, [
                'scope_type' => 'category',
                'combo_id' => $combo->id,
                'cashback_percent' => 2,
                'spend_from' => 0,
                'spend_to' => null,
            ]);
        }
    }

    private function makeSystemPolicy(): PolicyTemplate
    {
        return $this->app->make(PolicyService::class)->createSystemTemplate(
            'Chính sách hệ thống '.uniqid(),
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'tiers' => [
                    [
                        'name' => 'Bậc cơ bản',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [],
                    ],
                ],
            ],
            true,
        );
    }
}
