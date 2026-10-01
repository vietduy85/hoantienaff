<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\PolicyTier;
use App\Models\User;
use App\Services\CreditCard\CategoryComboService;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\PolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Module USER "🍱 Combo danh mục của tôi".
 *
 * Bảo vệ cốt lõi:
 *   1. User thấy combo HỆ THỐNG (dùng chung, ai cũng gắn vào policy thẻ mình
 *      được) nhưng KHÔNG sửa được chúng ⇒ 403.
 *   2. User chỉ tạo/sửa được combo RIÊNG của mình; combo của user khác ⇒ 403.
 *   3. Combo riêng chỉ gồm danh mục hệ thống + danh mục RIÊNG CỦA CHÍNH mình
 *      (không được lấy danh mục của người khác).
 *   4. Combo phải có ≥1 danh mục, không trùng thành viên.
 *   5. KHÔNG có route xoá combo.
 *   6. Sửa membership không được phá bất biến "mỗi bậc chỉ một combo rule cho
 *      danh mục" ⇒ 422.
 */
class CategoryComboTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    // =====================================================================
    // Route + phân quyền
    // =====================================================================

    #[Test]
    public function routes_exist_and_there_is_no_delete_route(): void
    {
        $this->assertTrue(Route::has('credit-cards.api.combos.index'));
        $this->assertTrue(Route::has('credit-cards.api.combos.store'));
        $this->assertTrue(Route::has('credit-cards.api.combos.update'));

        $this->assertFalse(
            Route::has('credit-cards.api.combos.destroy'),
            'Combo chưa có xoá trong phase này — không được đăng ký route xoá.'
        );
    }

    #[Test]
    public function guest_cannot_touch_combo_api(): void
    {
        $this->getJson(route('credit-cards.api.combos.index'))->assertUnauthorized();

        $this->postJson(route('credit-cards.api.combos.store'), [
            'name' => 'X',
            'category_ids' => [1],
        ])->assertUnauthorized();
    }

    #[Test]
    public function user_cannot_update_or_touch_combo_of_another_user(): void
    {
        $combo = $this->makeUserCombo($this->stranger->id, [
            'name' => 'Combo của người khác',
        ]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.combos.update', $combo->id), [
                'name' => 'Bị hack',
                'category_ids' => [$combo->items()->value('category_id')],
            ])
            ->assertForbidden();

        $this->assertSame('Combo của người khác', $combo->refresh()->name);
    }

    #[Test]
    public function user_cannot_update_system_combo(): void
    {
        $combo = $this->makeSystemCombo(['name' => 'Combo hệ thống']);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.combos.update', $combo->id), [
                'name' => 'Sửa từ phía user',
                'category_ids' => [$combo->items()->value('category_id')],
            ])
            ->assertForbidden();

        $this->assertSame('Combo hệ thống', $combo->refresh()->name);
    }

    #[Test]
    public function user_cannot_create_system_scoped_combo_by_passing_scope(): void
    {
        $category = $this->makeSystemCategory();

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.combos.store'), [
                'name' => 'Cố làm combo hệ thống',
                'category_ids' => [$category->id],
                'scope' => 'system',
                'owner_user_id' => 0,
                'slug' => 'tu-dinh-slug',
            ])
            ->assertCreated();

        $combo = CategoryCombo::findOrFail($response->json('data.id'));

        $this->assertFalse($combo->isSystem());
        $this->assertSame($this->owner->id, (int) $combo->owner_user_id);
        $this->assertNotSame('tu-dinh-slug', $combo->slug, 'Slug do service sinh, không tin input của user.');
    }

    // =====================================================================
    // Đọc
    // =====================================================================

    #[Test]
    public function index_returns_system_combos_and_own_combos_only(): void
    {
        $this->makeSystemCombo(['name' => 'Combo chung']);
        $mine = $this->makeUserCombo($this->owner->id, ['name' => 'Combo của tôi']);
        $this->makeUserCombo($this->stranger->id, ['name' => 'Combo kia']);

        $response = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.combos.index'))
            ->assertOk();

        $response->assertJsonPath('system_combos.0.name', 'Combo chung');
        $response->assertJsonCount(1, 'system_combos');
        $response->assertJsonCount(1, 'user_combos');
        $response->assertJsonPath('user_combos.0.id', $mine->id);
        $response->assertJsonPath('user_combos.0.scope', CategoryCombo::SCOPE_USER);

        $names = array_merge(
            array_column($response->json('system_combos'), 'name'),
            array_column($response->json('user_combos'), 'name')
        );
        $this->assertNotContains('Combo kia', $names);
    }

    #[Test]
    public function index_exposes_membership_for_rendering(): void
    {
        $a = $this->makeSystemCategory(['name' => 'Ăn uống', 'slug' => 'an-uong']);
        $b = $this->makeUserCategory($this->owner->id, ['name' => 'Chi tiêu riêng', 'slug' => 'chi-tieu-rieng']);

        $this->makeUserCombo($this->owner->id, ['category_ids' => [$a->id, $b->id]]);

        $response = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.combos.index'))
            ->assertOk();

        $combo = $response->json('user_combos.0');

        $this->assertSame([$a->id, $b->id], array_map('intval', $combo['category_ids']));
        $this->assertSame('Ăn uống', $combo['items'][0]['category_name']);
        $this->assertSame('an-uong', $combo['items'][0]['category_slug']);
        $this->assertSame('Chi tiêu riêng', $combo['items'][1]['category_name']);
    }

    // =====================================================================
    // Tạo
    // =====================================================================

    #[Test]
    public function user_can_create_combo_from_system_and_own_categories(): void
    {
        $system = $this->makeSystemCategory();
        $own = $this->makeUserCategory($this->owner->id);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.combos.store'), [
                'name' => 'Combo chi tiêu của tôi',
                'description' => 'Ăn uống + cá nhân.',
                'category_ids' => [$system->id, $own->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Combo chi tiêu của tôi')
            ->assertJsonPath('data.scope', CategoryCombo::SCOPE_USER);

        $combo = CategoryCombo::findOrFail($response->json('data.id'));

        $this->assertSame($this->owner->id, (int) $combo->owner_user_id);
        $this->assertSame([$system->id, $own->id], $combo->items()->orderBy('sort_order')->pluck('category_id')->map(fn ($i) => (int) $i)->all());
    }

    #[Test]
    public function user_cannot_add_category_of_another_user_to_combo(): void
    {
        $own = $this->makeUserCategory($this->owner->id);
        $foreign = $this->makeUserCategory($this->stranger->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.combos.store'), [
                'name' => 'Combo lấn sang',
                'category_ids' => [$own->id, $foreign->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids.1');

        $this->assertSame(0, CategoryCombo::query()->userOwned($this->owner->id)->count());
    }

    #[Test]
    public function user_cannot_add_inactive_category_to_combo(): void
    {
        $inactive = $this->makeUserCategory($this->owner->id, ['is_active' => false]);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.combos.store'), [
                'name' => 'Combo danh mục ẩn',
                'category_ids' => [$inactive->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids.0');
    }

    #[Test]
    public function combo_requires_at_least_one_category_and_no_duplicate(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.combos.store'), ['name' => 'Rỗng', 'category_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids');

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.combos.store'), [
                'name' => 'Trùng',
                'category_ids' => [$category->id, $category->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_ids');

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.combos.store'), ['name' => '  ', 'category_ids' => [$category->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame(0, CategoryCombo::query()->userOwned($this->owner->id)->count());
    }

    // =====================================================================
    // Sửa
    // =====================================================================

    #[Test]
    public function user_can_rename_combo_and_slug_follows_name(): void
    {
        $category = $this->makeSystemCategory();
        $combo = $this->makeUserCombo($this->owner->id, ['name' => 'Combo cũ', 'category_ids' => [$category->id]]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.combos.update', $combo->id), [
                'name' => 'Combo mới',
                'category_ids' => [$category->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Combo mới')
            ->assertJsonPath('data.slug', 'combo-moi');
    }

    #[Test]
    public function user_can_replace_membership_and_old_items_are_removed(): void
    {
        $a = $this->makeSystemCategory();
        $b = $this->makeUserCategory($this->owner->id);
        $c = $this->makeUserCategory($this->owner->id);

        $combo = $this->makeUserCombo($this->owner->id, ['category_ids' => [$a->id, $b->id]]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.combos.update', $combo->id), [
                'name' => $combo->name,
                'category_ids' => [$b->id, $c->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.category_ids', [$b->id, $c->id]);

        $this->assertSame(
            [$b->id, $c->id],
            CategoryComboItem::query()->where('combo_id', $combo->id)->orderBy('sort_order')->pluck('category_id')->map(fn ($i) => (int) $i)->all()
        );
        $this->assertSame(
            0,
            CategoryComboItem::query()->where('combo_id', $combo->id)->where('category_id', $a->id)->count()
        );
    }

    #[Test]
    public function update_to_empty_membership_is_rejected_and_keeps_old_membership(): void
    {
        $a = $this->makeSystemCategory();
        $combo = $this->makeUserCombo($this->owner->id, ['category_ids' => [$a->id]]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.combos.update', $combo->id), [
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
    public function two_users_may_use_the_same_system_category_in_different_combos(): void
    {
        // Danh mục hệ thống là master data dùng chung: KHÔNG có ràng buộc
        // "mỗi danh mục chỉ nằm trong một combo" giữa các user.
        $system = $this->makeSystemCategory();

        $mine = $this->makeUserCombo($this->owner->id, ['category_ids' => [$system->id]]);
        $theirs = $this->makeUserCombo($this->stranger->id, ['category_ids' => [$system->id]]);

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame([$system->id], array_map('intval', $mine->items()->pluck('category_id')->all()));
        $this->assertSame([$system->id], array_map('intval', $theirs->items()->pluck('category_id')->all()));
    }

    #[Test]
    public function two_users_may_name_their_combo_identically(): void
    {
        // Slug unique theo (scope, owner) ⇒ trùng tên giữa hai user vẫn OK.
        $category = $this->makeSystemCategory();

        $mine = $this->makeUserCombo($this->owner->id, ['name' => 'Combo ăn uống', 'category_ids' => [$category->id]]);
        $theirs = $this->makeUserCombo($this->stranger->id, ['name' => 'Combo ăn uống', 'category_ids' => [$category->id]]);

        $this->assertSame('combo-an-uong', $mine->slug);
        $this->assertSame('combo-an-uong', $theirs->slug);
    }

    // =====================================================================
    // UI: màn "Combo danh mục của tôi" trong /thetindung/danh-muc
    // =====================================================================

    #[Test]
    public function the_categories_page_shows_the_combo_section(): void
    {
        $response = $this->actingAs($this->owner)->get(route('credit-cards.categories'));

        $response->assertOk();
        $response->assertSee('COMBO DANH MỤC');
        $response->assertSee('+ Tạo combo');
        $response->assertSee('Bạn chưa tạo combo nào.');
    }

    #[Test]
    public function the_combo_modal_exposes_a_searchable_member_picker_without_an_empty_state_claim(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.categories'))
            ->assertOk()
            ->getContent();

        // Modal combo có ô lọc danh mục thành viên + nhãn tiếng Việt.
        $this->assertStringContainsString('Danh mục thành phần', $html);
        $this->assertStringContainsString('Lọc danh mục', $html);
        $this->assertStringContainsString('Mỗi danh mục chỉ được chọn một lần', $html);
        $this->assertStringContainsString('Tạo combo danh mục', $html);

        // Không có nút xoá combo (phase này chỉ Create/Edit).
        $this->assertStringNotContainsString('Xoá combo', $html);
    }

    #[Test]
    public function the_categories_page_wires_the_combo_api_endpoint(): void
    {
        // Combo được nạp client-side từ API cùng origin (một đường đọc duy nhất,
        // không render chênh lệch với API) — trang phải trỏ đúng endpoint.
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.categories'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/thetindung/api/combo', $html);

        // Cô lập dữ liệu được bảo vệ ở tầng API (xem
        // `index_returns_system_combos_and_own_combos_only`) chứ không phải bằng
        // việc ẩn/hiện text trong HTML.
        $this->assertStringNotContainsString('Combo rieng cua toi B', $html);
    }

    #[Test]
    public function the_category_page_still_works_when_combo_api_is_empty(): void
    {
        // Combo rỗng KHÔNG được làm hỏng màn danh mục (load song song 2 API).
        $this->actingAs($this->owner)
            ->get(route('credit-cards.categories'))
            ->assertOk()
            ->assertSee('DANH MỤC CỦA TÔI');
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

        $comboA = $this->makeUserCombo($this->owner->id, ['name' => 'Combo A', 'category_ids' => [$a->id]]);
        $comboB = $this->makeUserCombo($this->owner->id, ['name' => 'Combo B', 'category_ids' => [$b->id]]);

        $this->makeComboRuleTierForUser($this->owner->id, [$comboA, $comboB]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.combos.update', $comboB->id), [
                'name' => 'Combo B',
                'category_ids' => [$b->id, $a->id],
            ])
            ->assertUnprocessable();

        $this->assertSame(
            [$b->id],
            CategoryComboItem::query()->where('combo_id', $comboB->id)->orderBy('sort_order')->pluck('category_id')->map(fn ($i) => (int) $i)->all()
        );

        // Danh mục chưa dùng ở đâu thì vẫn thêm được.
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.combos.update', $comboB->id), [
                'name' => 'Combo B',
                'category_ids' => [$b->id, $c->id],
            ])
            ->assertOk();
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

        return app(CategoryComboService::class)->createSystemCombo([
            'name' => $attributes['name'] ?? 'Combo hệ thống '.($counter + 1000),
            'category_ids' => $attributes['category_ids'] ?? [$this->makeSystemCategory()->id],
        ]);
    }

    private function makeUserCombo(int $userId, array $attributes = []): CategoryCombo
    {
        static $counter = 0;
        $counter++;

        return app(CategoryComboService::class)->createUserCombo($userId, [
            'name' => $attributes['name'] ?? 'Combo riêng '.$counter,
            'category_ids' => $attributes['category_ids'] ?? [$this->makeSystemCategory()->id],
        ]);
    }

    /**
     * Tạo policy của user + một tier có combo rule cho từng combo.
     *
     * @param  array<int, CategoryCombo>  $combos
     */
    private function makeComboRuleTierForUser(int $userId, array $combos): void
    {
        $tier = $this->makeUserPolicyTier($userId);

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

    private function makeUserPolicyTier(int $userId): PolicyTier
    {
        $userCard = $this->makeUserCard($userId);

        $policy = app(PolicyService::class)->createFromScratch(
            $userCard,
            CarbonImmutable::parse('2026-09-01'),
            [
                'name' => 'Chính sách '.uniqid(),
                'tiers' => [
                    [
                        'name' => 'Bậc cơ bản',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [],
                    ],
                ],
            ]
        );

        return $policy->tiers()->firstOrFail();
    }
}
