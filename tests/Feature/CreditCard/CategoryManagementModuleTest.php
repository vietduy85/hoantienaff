<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\User;
use App\Services\CreditCard\CategoryService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Module "Danh mục chi tiêu" — màn hình quản lý /thetindung/danh-muc.
 *
 * Bảo vệ 4 mặt:
 *   1. UI: trang mở được, sidebar đúng nhãn + active, 2 nhóm hiển thị.
 *   2. API index phân biệt `system_categories` (19 hệ thống, chỉ đọc) và
 *      `user_categories` (chỉ của mình, gồm cả đã ẩn).
 *   3. CRUD: quyền sở hữu, system read-only, ẩn thay vì xoá khi đã dùng.
 *   4. Component chọn danh mục dùng chung không rò rỉ danh mục của người khác.
 */
class CategoryManagementModuleTest extends TestCase
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
    // UI: trang + sidebar
    // =====================================================================

    #[Test]
    public function the_category_page_opens_and_shows_sections(): void
    {
        $response = $this->actingAs($this->owner)->get(route('credit-cards.categories'));

        $response->assertOk();
        $response->assertSee('Danh mục chi tiêu');
        $response->assertSee('Quản lý các nhóm chi tiêu dùng cho thẻ tín dụng và hoàn tiền');
        $response->assertSee('DANH MỤC HỆ THỐNG');
        $response->assertSee('DANH MỤC CỦA TÔI');
        $response->assertSee('Bạn chưa tạo danh mục chi tiêu riêng.');
    }

    #[Test]
    public function sidebar_labels_the_item_danh_muc_chi_tieu(): void
    {
        $html = $this->actingAs($this->owner)->get(route('credit-cards.categories'))->getContent();

        $this->assertStringContainsString('Danh mục chi tiêu', $html);
        $this->assertStringContainsString('href="'.route('credit-cards.categories').'"', $html);
    }

    #[Test]
    public function sidebar_marks_categories_as_active(): void
    {
        $html = $this->actingAs($this->owner)->get(route('credit-cards.categories'))->getContent();

        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*aria-current="page")(?=[^>]*href="'.preg_quote(route('credit-cards.categories'), '/').'")[^>]*>/',
            $html,
            'Menu "Danh mục chi tiêu" phải được đánh active.'
        );
    }

    // =====================================================================
    // API index: phân biệt system / user
    // =====================================================================

    #[Test]
    public function index_returns_exactly_the_19_system_categories_each_with_an_icon(): void
    {
        $canonical = [
            'am-thuc-an-uong' => 'Ẩm thực & Ăn uống',
            'sieu-thi-tien-loi' => 'Siêu thị & Cửa hàng tiện lợi',
            'mua-sam-truc-tuyen' => 'Mua sắm trực tuyến',
            'xang-dau' => 'Xăng dầu',
            'goi-xe-di-chuyen' => 'Ứng dụng gọi xe & Di chuyển',
            've-may-bay-du-lich' => 'Vé máy bay & Du lịch',
            'khach-san-luu-tru' => 'Khách sạn & Lưu trú',
            'bao-hiem' => 'Bảo hiểm',
            'y-te-benh-vien' => 'Y tế & Bệnh viện',
            'giao-duc-hoc-phi' => 'Giáo dục & Học phí',
            'hoa-don-dien-nuoc-internet' => 'Hóa đơn điện, nước, internet',
            'chi-tieu-nuoc-ngoai' => 'Chi tiêu nước ngoài / Ngoại tệ',
            'ung-dung-so-dich-vu-giai-tri' => 'Ứng dụng số & Dịch vụ giải trí trực tuyến',
            'thoi-trang' => 'Thời trang',
            'shopee' => 'Shopee',
            'tiki' => 'Tiki',
            'lazada' => 'Lazada',
            'tiktok' => 'Tiktok',
            'san-thuong-mai-dien-tu' => 'Sàn thương mại điện tử',
        ];

        $index = 0;
        foreach ($canonical as $slug => $name) {
            $this->makeSystemCategory([
                'slug' => $slug,
                'name' => $name,
                'sort_order' => (++$index) * 10,
            ]);
        }

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.api.categories.index'));

        $response->assertOk()
            ->assertJsonCount(19, 'system_categories')
            ->assertJsonCount(0, 'user_categories');

        $slugs = collect($response->json('system_categories'))->pluck('slug')->all();
        $this->assertSame(array_keys($canonical), $slugs, 'Danh sách slug hệ thống phải khớp danh sách chuẩn.');

        // 4 sàn + "Sàn thương mại điện tử" là các danh mục RIÊNG BIỆT.
        $this->assertSame(5, count(array_intersect($slugs, [
            'shopee', 'tiki', 'lazada', 'tiktok', 'san-thuong-mai-dien-tu',
        ])));

        // Icon trình bày được gắn ở tầng present().
        $icons = collect($response->json('system_categories'))->pluck('icon', 'slug');
        $this->assertSame('🍜', $icons['am-thuc-an-uong']);
        $this->assertSame('🛍️', $icons['shopee']);
        $this->assertSame('🎵', $icons['tiktok']);
    }

    #[Test]
    public function index_inactive_system_categories_are_excluded_from_system_list(): void
    {
        $this->makeSystemCategory(['name' => 'Ẩm thực', 'slug' => 'am-thuc-an-uong', 'is_active' => true]);
        $this->makeSystemCategory(['name' => 'Xăng dầu', 'slug' => 'xang-dau', 'is_active' => false]);

        $system = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.categories.index'))
            ->assertOk()
            ->json('system_categories');

        $this->assertCount(1, $system);
        $this->assertSame('am-thuc-an-uong', $system[0]['slug']);
    }

    #[Test]
    public function index_user_categories_only_contains_my_own_categories(): void
    {
        $mine = $this->makeUserCategory($this->owner->id, ['name' => 'Cà phê văn phòng']);
        $theirs = $this->makeUserCategory($this->stranger->id, ['name' => 'Bí mật của người khác']);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.api.categories.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'user_categories');

        $response->assertJsonPath('user_categories.0.id', $mine->id);
        $response->assertJsonPath('user_categories.0.name', 'Cà phê văn phòng');
        $this->assertSame(0, collect($response->json('user_categories'))->where('id', $theirs->id)->count());

        // Trang HTML (server-render) không chứa tên danh mục của người khác.
        $html = $this->actingAs($this->owner)->get(route('credit-cards.categories'))->getContent();
        $this->assertStringNotContainsString('Bí mật của người khác', $html);
    }

    // =====================================================================
    // Create: server tự gán scope + owner
    // =====================================================================

    #[Test]
    public function creating_a_category_assigns_scope_user_and_owner_automatically(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.categories.store'), [
                'name' => 'Gia đình',
                'description' => 'Chi tiêu cho gia đình',
                // Cố tình ép scope/owner khác: phải bị bỏ qua.
                'scope' => Category::SCOPE_SYSTEM,
                'owner_user_id' => $this->stranger->id,
            ])
            ->assertCreated();

        $category = Category::findOrFail($response->json('data.id'));

        $this->assertSame(Category::SCOPE_USER, $category->scope);
        $this->assertSame((int) $this->owner->id, (int) $category->owner_user_id);
        $this->assertTrue((bool) $category->is_active);
        $this->assertSame('Gia đình', $category->name);
    }

    // =====================================================================
    // Update / delete: quyền sở hữu + system read-only
    // =====================================================================

    #[Test]
    public function owner_can_update_their_own_category(): void
    {
        $category = $this->makeUserCategory($this->owner->id, ['name' => 'Cũ']);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.categories.update', $category->id), [
                'name' => 'Mới',
                'description' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Mới')
            ->assertJsonPath('data.description', null);

        $this->assertSame('Mới', $category->refresh()->name);
    }

    #[Test]
    public function a_stranger_cannot_update_my_category(): void
    {
        $category = $this->makeUserCategory($this->owner->id, ['name' => 'Của tôi']);

        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.categories.update', $category->id), ['name' => 'Cướp'])
            ->assertForbidden();

        $this->assertSame('Của tôi', $category->refresh()->name);
    }

    #[Test]
    public function a_stranger_cannot_delete_my_category(): void
    {
        $category = $this->makeUserCategory($this->owner->id);

        $this->actingAs($this->stranger)
            ->deleteJson(route('credit-cards.api.categories.destroy', $category->id))
            ->assertForbidden();

        $this->assertTrue(Category::whereKey($category->id)->exists());
    }

    #[Test]
    public function system_categories_cannot_be_updated_or_deleted(): void
    {
        $system = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.categories.update', $system->id), ['name' => 'Đổi'])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.categories.destroy', $system->id))
            ->assertForbidden();

        $this->assertTrue(Category::whereKey($system->id)->exists());
        $this->assertSame('Ăn uống', $system->refresh()->name);
    }

    #[Test]
    public function deleting_an_unused_category_hard_deletes_it(): void
    {
        $category = $this->makeUserCategory($this->owner->id);

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.categories.destroy', $category->id))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertFalse(Category::whereKey($category->id)->exists());
    }

    #[Test]
    public function deleting_a_used_category_only_hides_it(): void
    {
        $category = $this->makeUserCategory($this->owner->id);
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicyForCard($card, [['name' => 'T1', 'min' => 0, 'max' => null]], [
            ['category_id' => $category->id, 'percent' => '5.000'],
        ]);

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.categories.destroy', $category->id))
            ->assertOk()
            ->assertJsonPath('deleted', false)
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue(Category::whereKey($category->id)->exists(), 'Danh mục đã dùng phải được giữ lại.');
    }

    // =====================================================================
    // Selectability: inactive không chọn được
    // =====================================================================

    #[Test]
    public function an_inactive_user_category_remains_visible_to_its_owner_but_is_not_selectable(): void
    {
        $category = $this->makeUserCategory($this->owner->id, ['name' => 'Đã ẩn']);
        $category->forceFill(['is_active' => false])->save();

        // API quản lý vẫn trả về để hiện badge "Đã ẩn".
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.categories.index'))
            ->assertOk()
            ->assertJsonCount(1, 'user_categories');

        // Nhưng không được nằm trong danh sách chọn.
        $selectable = app(CategoryService::class)->selectableFor((int) $this->owner->id);
        $this->assertTrue($selectable->every(fn (Category $c) => (int) $c->id !== (int) $category->id));
    }

    #[Test]
    public function selectable_by_scope_only_returns_active_system_and_own_active_categories(): void
    {
        $this->makeSystemCategory(['name' => 'Ẩm thực', 'slug' => 'am-thuc-an-uong', 'is_active' => true]);
        $this->makeSystemCategory(['name' => 'Khoá', 'slug' => 'khoa', 'is_active' => false]);
        $mine = $this->makeUserCategory($this->owner->id, ['name' => 'Của tôi', 'is_active' => true]);
        $mineHidden = $this->makeUserCategory($this->owner->id, ['name' => 'Tôi ẩn', 'is_active' => false]);
        $theirs = $this->makeUserCategory($this->stranger->id, ['name' => 'Người khác', 'is_active' => true]);

        $selectable = Category::query()->selectableBy((int) $this->owner->id)->get();

        $this->assertTrue($selectable->contains(fn (Category $c) => (int) $c->id === (int) $mine->id));
        $this->assertTrue($selectable->contains(fn (Category $c) => $c->isSystem() && $c->name === 'Ẩm thực'));
        $this->assertFalse($selectable->contains(fn (Category $c) => $c->name === 'Khoá'));
        $this->assertFalse($selectable->contains(fn (Category $c) => (int) $c->id === (int) $mineHidden->id));
        $this->assertFalse($selectable->contains(fn (Category $c) => (int) $c->id === (int) $theirs->id));
    }

    // =====================================================================
    // Đặt tên trùng giữa các user
    // =====================================================================

    #[Test]
    public function two_users_can_create_categories_with_the_same_name(): void
    {
        $a = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.categories.store'), ['name' => 'Ăn trưa'])
            ->assertCreated()
            ->json('data');

        $b = $this->actingAs($this->stranger)
            ->postJson(route('credit-cards.api.categories.store'), ['name' => 'Ăn trưa'])
            ->assertCreated()
            ->json('data');

        $this->assertNotSame($a['id'], $b['id']);
        // Slug là duy nhất TRONG PHẠM VI từng user (unique (scope, owner, slug)),
        // nên hai user tên trùng nhau vẫn cùng slug — không được nhầm thành conflict.
        $this->assertSame('an-trua', $a['slug']);
        $this->assertSame('an-trua', $b['slug']);
        $this->assertSame(2, Category::query()->where('name', 'Ăn trưa')->count());
    }

    // =====================================================================
    // Component chọn danh mục dùng chung (không rò rỉ)
    // =====================================================================

    #[Test]
    public function category_selector_component_does_not_leak_other_users_categories(): void
    {
        $this->makeSystemCategory(['name' => 'Ẩm thực & Ăn uống', 'slug' => 'am-thuc-an-uong']);
        $mine = $this->makeUserCategory($this->owner->id, ['name' => 'Cà phê của tôi']);
        $theirs = $this->makeUserCategory($this->stranger->id, ['name' => 'Bí mật của stranger']);

        $view = $this->actingAs($this->owner)
            ->blade('<x-credit-card.category-selector name="category_id" :selected="$selected" />', [
                'selected' => (string) $mine->id,
            ]);

        $view->assertSee('Danh mục hệ thống');
        $view->assertSee('Danh mục của tôi');
        $view->assertSee('Ẩm thực & Ăn uống');
        $view->assertSee('Cà phê của tôi');
        $view->assertSeeHtml("value=\"{$mine->id}\" selected");
        $view->assertDontSee('Bí mật của stranger');
    }
}
