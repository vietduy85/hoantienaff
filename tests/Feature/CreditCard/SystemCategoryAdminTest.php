<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\User;
use App\Services\CreditCard\PolicyService;
use Carbon\CarbonImmutable;
use Database\Seeders\CreditCardSeeder;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Module ADMIN "⚙️ Quản lý danh mục hệ thống".
 *
 * CHỈ 3 thao tác trên danh mục hệ thống (master data): thêm mới, đổi tên,
 * sắp xếp lại. KHÔNG có xoá / ẩn / khôi phục / lưu trữ.
 *
 * Bảo vệ cốt lõi:
 *   1. Phân quyền: đọc = `credit-cards.view`, ghi = `credit-cards.manage`.
 *   2. Bất biến "category_id là ĐỊNH DANH, name là THUỘC TÍNH HIỆN TẠI": đổi tên
 *      chỉ chạm đúng bản ghi Category; không tạo version policy mới; không
 *      snapshot tên ⇒ template cũ, user policy rule và dữ liệu lịch sử tự hiển
 *      thị tên mới qua relation.
 *   3. Reorder nguyên tử, chuẩn hoá `sort_order` 1..N, không đổi category_id.
 *   4. Seeder idempotent: chỉ tạo còn thiếu, KHÔNG reset tên / thứ tự admin chỉnh.
 *   5. Module ADMIN không làm hỏng module USER (user vẫn quản lý danh mục riêng,
 *      user KHÔNG đổi được tên danh mục hệ thống).
 */
class SystemCategoryAdminTest extends TestCase
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
    // Menu + phân quyền
    // =====================================================================

    #[Test]
    public function menu_visible_for_manager_hidden_for_member(): void
    {
        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card.system-categories.index'))
            ->assertOk()
            ->assertSee('⚙️ Quản lý danh mục hệ thống')
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('admin.credit-card.system-categories.index').'"',
            $html,
            'Menu "Quản lý danh mục hệ thống" phải trỏ đúng trang admin.'
        );

        $memberHtml = $this->actingAs($this->member)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Quản lý danh mục hệ thống', $memberHtml);
    }

    #[Test]
    public function only_three_write_routes_exist_and_no_delete_route_is_registered(): void
    {
        $this->assertTrue(Route::has('admin.credit-card.system-categories.index'));
        $this->assertTrue(Route::has('admin.credit-card.system-categories.store'));
        $this->assertTrue(Route::has('admin.credit-card.system-categories.update'));
        $this->assertTrue(Route::has('admin.credit-card.system-categories.reorder'));

        $this->assertFalse(
            Route::has('admin.credit-card.system-categories.destroy'),
            'Module danh mục hệ thống KHÔNG được có route xoá.'
        );
    }

    #[Test]
    public function guest_redirected_from_page_and_gets_401_on_json(): void
    {
        $category = $this->makeSystemCategory();

        $this->get(route('admin.credit-card.system-categories.index'))->assertRedirect(route('login'));

        $this->postJson(route('admin.credit-card.system-categories.store'), [
            'name' => 'X',
            'slug' => 'x',
        ])->assertUnauthorized();

        $this->patchJson(route('admin.credit-card.system-categories.update', $category), [
            'name' => 'Y',
        ])->assertUnauthorized();

        $this->postJson(route('admin.credit-card.system-categories.reorder'), [
            'ordered_ids' => [$category->id],
        ])->assertUnauthorized();
    }

    #[Test]
    public function member_without_permission_is_forbidden(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->member)->get(route('admin.credit-card.system-categories.index'))->assertForbidden();

        $this->actingAs($this->member)
            ->postJson(route('admin.credit-card.system-categories.store'), ['name' => 'X', 'slug' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->member)
            ->patchJson(route('admin.credit-card.system-categories.update', $category), ['name' => 'Y'])
            ->assertForbidden();

        $this->actingAs($this->member)
            ->postJson(route('admin.credit-card.system-categories.reorder'), ['ordered_ids' => [$category->id]])
            ->assertForbidden();
    }

    #[Test]
    public function viewer_with_read_only_permission_cannot_write(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->viewer)->get(route('admin.credit-card.system-categories.index'))->assertOk();

        $this->actingAs($this->viewer)
            ->postJson(route('admin.credit-card.system-categories.store'), ['name' => 'X', 'slug' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->viewer)
            ->patchJson(route('admin.credit-card.system-categories.update', $category), ['name' => 'Y'])
            ->assertForbidden();

        $this->actingAs($this->viewer)
            ->postJson(route('admin.credit-card.system-categories.reorder'), ['ordered_ids' => [$category->id]])
            ->assertForbidden();
    }

    // =====================================================================
    // Trang index
    // =====================================================================

    #[Test]
    public function index_lists_all_system_categories_with_name_slug_and_search(): void
    {
        for ($i = 1; $i <= 19; $i++) {
            $name = 'Danh mục hệ thống '.$i;
            $slug = 'danh-muc-he-thong-'.$i;
            $this->makeSystemCategory(['name' => $name, 'slug' => $slug, 'sort_order' => $i * 10]);
        }

        $response = $this->actingAs($this->manager)
            ->get(route('admin.credit-card.system-categories.index'))
            ->assertOk();

        $response->assertSee('⚙️ Quản lý danh mục hệ thống');
        $response->assertSee('Quản lý các danh mục chi tiêu dùng chung cho chính sách hoàn tiền thẻ tín dụng.');
        $response->assertSee('+ Thêm danh mục');

        for ($i = 1; $i <= 19; $i++) {
            $response->assertSee('danh-muc-he-thong-'.$i);
        }

        $response->assertSee('Tìm danh mục theo tên hoặc slug');
    }

    // =====================================================================
    // Thêm mới
    // =====================================================================

    #[Test]
    public function admin_can_add_new_system_category(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.store'), [
                'name' => 'Điện máy',
                'slug' => 'dien-may',
                'description' => 'Máy lạnh, tủ lạnh, tivi.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Điện máy')
            ->assertJsonPath('data.slug', 'dien-may');

        $id = $response->json('data.id');

        $category = Category::findOrFail($id);
        $this->assertSame('system', $category->scope);
        $this->assertSame(Category::SYSTEM_OWNER_ID, $category->owner_user_id);
        $this->assertTrue($category->is_active);
        $this->assertSame('Điện máy', $category->name);
        $this->assertSame('dien-may', $category->slug);
        $this->assertSame('Máy lạnh, tủ lạnh, tivi.', $category->description);
    }

    #[Test]
    public function slug_is_normalized_to_kebab_case_on_create(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.store'), [
                'name' => 'Y tế & Bệnh viện',
                'slug' => 'Y Tế & Bệnh viện',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'y-te-benh-vien');

        $this->assertTrue(
            Category::query()
                ->where('scope', Category::SCOPE_SYSTEM)
                ->where('owner_user_id', Category::SYSTEM_OWNER_ID)
                ->where('slug', 'y-te-benh-vien')
                ->exists()
        );
    }

    #[Test]
    public function duplicate_slug_within_system_scope_is_rejected(): void
    {
        $this->makeSystemCategory(['slug' => 'giong-nhau', 'name' => 'Danh mục A']);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.store'), [
                'name' => 'Danh mục B',
                'slug' => 'giong-nhau',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');

        $this->assertSame(1, Category::query()->system()->count());
    }

    #[Test]
    public function empty_name_or_slug_is_rejected(): void
    {
        $this->postJson(route('admin.credit-card.system-categories.store'), [
            'name' => '',
            'slug' => 'abc',
        ])->assertUnauthorized();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.store'), ['name' => '', 'slug' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.store'), ['name' => 'Hợp lệ', 'slug' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function new_category_appears_in_user_selector_automatically(): void
    {
        $created = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.store'), [
                'name' => 'Điện máy',
                'slug' => 'dien-may',
            ])
            ->assertCreated()
            ->json('data');

        $response = $this->actingAs($this->member)
            ->getJson(route('credit-cards.api.categories.index'))
            ->assertOk();

        $slugs = collect($response->json('system_categories'))->pluck('slug');
        $this->assertTrue($slugs->contains('dien-may'));
        $this->assertSame($created['id'], (int) collect($response->json('system_categories'))->firstWhere('slug', 'dien-may')['id']);
    }

    // =====================================================================
    // Đổi tên
    // =====================================================================

    #[Test]
    public function rename_keeps_category_identity_and_updates_master_name(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Y tế & Bệnh viện', 'slug' => 'y-te-benh-vien']);
        $rule = $this->makeRuleReferencing($category);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-categories.update', $category), [
                'name' => 'Y tế',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Y tế')
            ->assertJsonPath('data.id', $category->id);

        $refreshed = $category->refresh();
        $this->assertSame('Y tế', $refreshed->name);
        $this->assertSame('y-te-benh-vien', $refreshed->slug);
        $this->assertSame($category->id, $refreshed->id);
        $this->assertSame($category->id, $rule->refresh()->category_id, 'category_id của rule KHÔNG đổi.');
    }

    #[Test]
    public function rename_does_not_create_new_policy_or_touch_rules(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Ẩm thực & Ăn uống', 'slug' => 'am-thuc-an-uong']);

        $template = $this->makeSystemPolicy(percent: 2.0, categoryId: $category->id);

        $policyBlueprintId = $template->blueprint()->firstOrFail()->id;
        $policiesBefore = Policy::count();
        $rulesBefore = PolicyTierCategory::query()->where('category_id', $category->id)->count();

        $this->assertSame(1, $rulesBefore, 'Template phải có 1 rule tham chiếu danh mục.');

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-categories.update', $category), [
                'name' => 'Ẩm thực (đổi tên)',
            ])
            ->assertOk();

        $blueprint = Policy::findOrFail($policyBlueprintId);
        $this->assertSame(1, $blueprint->version_no, 'Không tạo version mới khi đổi tên danh mục.');

        $this->assertSame($policiesBefore, Policy::count(), 'Đổi tên danh mục không được tạo policy/version mới.');
        $this->assertSame($rulesBefore, PolicyTierCategory::query()->where('category_id', $category->id)->count());
    }

    #[Test]
    public function renamed_name_is_reflected_in_system_template_without_snapshot(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Mua sắm trực tuyến', 'slug' => 'mua-sam-truc-tuyen']);
        $template = $this->makeSystemPolicy(percent: 2.5, categoryId: $category->id);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-categories.update', $category), [
                'name' => 'Mua sắm online',
            ])
            ->assertOk();

        $show = $this->actingAs($this->manager)
            ->getJson(route('admin.credit-card-policies.api.show', $template))
            ->assertOk();

        $show->assertJsonPath('data.tiers.0.rules.0.category_id', $category->id);
        $show->assertJsonPath('data.tiers.0.rules.0.category_name', 'Mua sắm online');
    }

    #[Test]
    public function renamed_name_is_reflected_in_user_policy_rule_without_snapshot(): void
    {
        $owner = $this->member;
        $category = $this->makeSystemCategory(['name' => 'Shopee', 'slug' => 'shopee']);

        $card = $this->makeUserCard($owner->id);
        $policy = $this->makePolicyForCard($card, [
            ['min' => 0, 'max' => null],
        ], [
            ['category_id' => $category->id, 'percent' => '2.500', 'spend_from' => 0],
        ]);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-categories.update', $category), [
                'name' => 'Shopee Mall',
            ])
            ->assertOk();

        $tier = $policy->tiers()->firstOrFail();

        $show = $this->actingAs($owner)
            ->getJson(route('credit-cards.api.rules.index', $tier))
            ->assertOk();

        $show->assertJsonPath('data.0.category_id', $category->id);
        $show->assertJsonPath('data.0.category_name', 'Shopee Mall');
    }

    #[Test]
    public function user_flow_still_cannot_rename_system_categories(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->member)
            ->patchJson(route('credit-cards.api.categories.update', $category->id), ['name' => 'Đổi lén'])
            ->assertForbidden();
    }

    #[Test]
    public function admin_module_cannot_touch_user_categories(): void
    {
        $userCategory = $this->makeUserCategory($this->member->id, ['name' => 'Riêng của tôi']);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card.system-categories.update', $userCategory), ['name' => 'Hack'])
            ->assertNotFound();

        // User vẫn quản lý danh mục riêng bình thường.
        $this->actingAs($this->member)
            ->patchJson(route('credit-cards.api.categories.update', $userCategory->id), ['name' => 'Tên mới'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tên mới');

        $this->actingAs($this->member)
            ->getJson(route('credit-cards.api.categories.index'))
            ->assertOk()
            ->assertJsonPath('user_categories.0.name', 'Tên mới');
    }

    // =====================================================================
    // Sắp xếp lại
    // =====================================================================

    #[Test]
    public function reorder_normalizes_sort_order_and_keeps_identity(): void
    {
        $first = $this->makeSystemCategory(['name' => 'A', 'slug' => 'a', 'sort_order' => 10]);
        $second = $this->makeSystemCategory(['name' => 'B', 'slug' => 'b', 'sort_order' => 20]);
        $third = $this->makeSystemCategory(['name' => 'C', 'slug' => 'c', 'sort_order' => 30]);

        // Đảo ngược thứ tự: C, A, B.
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.reorder'), [
                'ordered_ids' => [$third->id, $first->id, $second->id],
            ])
            ->assertOk()
            ->assertJson(['saved' => true]);

        $ordered = Category::query()->system()->orderBy('sort_order')->pluck('slug')->all();
        $this->assertSame(['c', 'a', 'b'], $ordered);

        $this->assertSame(1, $third->refresh()->sort_order);
        $this->assertSame(2, $first->refresh()->sort_order);
        $this->assertSame(3, $second->refresh()->sort_order);

        $this->assertSame($first->id, $first->refresh()->id);
    }

    #[Test]
    public function reorder_rejects_partial_list_or_foreign_ids(): void
    {
        $first = $this->makeSystemCategory(['name' => 'A', 'slug' => 'a']);
        $second = $this->makeSystemCategory(['name' => 'B', 'slug' => 'b']);
        $userCategory = $this->makeUserCategory($this->member->id);

        // Thiếu 1 danh mục hệ thống.
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.reorder'), [
                'ordered_ids' => [$first->id],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['message']);

        // Có lẫn danh mục của user ⇒ từ chối.
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.reorder'), [
                'ordered_ids' => [$first->id, $userCategory->id, $second->id],
            ])
            ->assertUnprocessable();

        // Danh sách rỗng.
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.reorder'), ['ordered_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ordered_ids');

        // Không gửi gì.
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.reorder'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ordered_ids');
    }

    #[Test]
    public function reorder_keeps_rule_references(): void
    {
        $category = $this->makeSystemCategory(['name' => 'X', 'slug' => 'x']);
        $other = $this->makeSystemCategory(['name' => 'Y', 'slug' => 'y']);
        $rule = $this->makeRuleReferencing($category);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card.system-categories.reorder'), [
                'ordered_ids' => [$other->id, $category->id],
            ])
            ->assertOk();

        $refreshedRule = $rule->fresh();
        $this->assertSame($category->id, $refreshedRule->category_id, 'Reorder KHÔNG được đổi category_id của rule.');
        $this->assertTrue(
            PolicyTierCategory::query()
                ->whereKey($rule->id)
                ->where('category_id', $category->id)
                ->exists()
        );
    }

    // =====================================================================
    // Seeder: idempotent, không reset chỉnh sửa của admin
    // =====================================================================

    #[Test]
    public function seeder_is_idempotent_and_preserves_admin_edits(): void
    {
        $seeder = app(CreditCardSeeder::class);

        $seeder->run();

        $this->assertSame(19, Category::query()->system()->count(), 'Seeder tạo đủ 19 danh mục hệ thống.');

        // Admin đổi tên + sắp lại + thêm mới một danh mục.
        $shopee = Category::query()->system()->where('slug', 'shopee')->firstOrFail();
        $shopee->forceFill(['name' => 'Shopee (admin đổi tên)'])->save();

        $ordered = Category::query()->system()->orderBy('sort_order')->get();
        foreach (array_reverse($ordered->pluck('id')->all()) as $index => $id) {
            Category::query()->whereKey($id)->update(['sort_order' => $index + 1]);
        }

        $custom = Category::create([
            'scope' => Category::SCOPE_SYSTEM,
            'owner_user_id' => Category::SYSTEM_OWNER_ID,
            'name' => 'Danh mục admin thêm',
            'slug' => 'danh-muc-admin-them',
            'is_active' => true,
            'sort_order' => 100,
        ]);

        $this->assertSame(20, Category::query()->system()->count());

        // Chạy lại seeder: không nhân bản, không reset tên/thứ tự admin chỉnh.
        $seeder->run();

        $this->assertSame(20, Category::query()->system()->count(), 'Chạy lại seeder không nhân bản danh mục.');
        $this->assertSame(
            'Shopee (admin đổi tên)',
            Category::query()->system()->where('slug', 'shopee')->firstOrFail()->name,
            'Seeder KHÔNG được reset tên admin đã đổi.'
        );

        $this->assertNotNull(Category::query()->system()->where('slug', 'danh-muc-admin-them')->first());
        $this->assertSame(
            'Danh mục admin thêm',
            Category::query()->system()->where('slug', 'danh-muc-admin-them')->firstOrFail()->name
        );

        // Thứ tự admin đã đảo vẫn giữ nguyên sau khi re-seed, không về 10/20/30...
        $reSorted = Category::query()->system()->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame(1, $reSorted[0]);
        $this->assertNotSame(10, $reSorted[0]);

        // Slug không bị trùng sau nhiều lần seed.
        $duplicateSlugs = Category::query()->system()
            ->selectRaw('slug, count(*) as c')
            ->groupBy('slug')
            ->havingRaw('count(*) > 1')
            ->count();
        $this->assertSame(0, $duplicateSlugs);
    }

    #[Test]
    public function seeder_recreates_a_missing_canonical_category_without_overwriting_others(): void
    {
        $seeder = app(CreditCardSeeder::class);

        $seeder->run();
        $tikiBefore = Category::query()->system()->where('slug', 'tiki')->firstOrFail();

        $tikiBefore->forceFill(['name' => 'Tiki (admin)'])->save();

        // Giả lập ai đó xoá mất một danh mục gốc.
        Category::query()->system()->where('slug', 'tiki')->delete();

        $seeder->run();

        $tiki = Category::query()->system()->where('slug', 'tiki')->first();
        $this->assertNotNull($tiki, 'Seeder phải tạo lại danh mục gốc còn thiếu.');
        $this->assertSame('Tiki', $tiki->name);
        $this->assertSame(19, Category::query()->system()->count());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeRuleReferencing(Category $category): PolicyTierCategory
    {
        $policy = $this->makeSystemPolicy(percent: 1.5, categoryId: $category->id);

        $blueprint = $policy->blueprint()->firstOrFail();

        return $blueprint->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();
    }

    private function makeSystemPolicy(float $percent = 3.0, ?int $categoryId = null): PolicyTemplate
    {
        $categoryId ??= $this->makeSystemCategory()->id;

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
                        'rules' => [
                            [
                                'category_id' => $categoryId,
                                'cashback_percent' => $percent,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ],
            true,
        );
    }
}
