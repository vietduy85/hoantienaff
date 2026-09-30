<?php

namespace Tests\Feature;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Module Thẻ tín dụng — Phase 1A.
 *
 * Kiểm tra phần dựng nền tảng: routing, auth, layout/sidebar, migration, model
 * relationships và cách trang tổng quan scope dữ liệu theo user.
 *
 * GHI CHÚ LỊCH SỬ (đã migrate, xem docs/CREDIT_CARD_PHASE_1A_IMPLEMENTATION.md):
 *   TEST 15/16/17 từng dùng 4 bảng legacy trong DB chính
 *   (`credit_cards`, `user_credit_cards`, model root `App\Models\CreditCard*`).
 *   Kiến trúc Phase 1A chuyển module sang database riêng `hoantien_creditcard`
 *   với các bảng `credit_card_user_cards` / `credit_card_products` / model
 *   namespaced `App\Models\CreditCard\*`. Ba test này được viết lại theo kiến
 *   trúc mới, GIỮ NGUYÊN các tính chất nghiệp vụ đang kiểm tra
 *   (quan hệ 2 chiều, không lộ thẻ của user khác, hiển thị tổng hạn mức).
 *   4 bảng legacy vẫn được giữ nguyên trong DB chính như đã cam kết.
 */
class CreditCardModuleTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();
    }

    /** TEST 1: 6 route đúng URI + đúng tên namespace credit-cards. */
    #[Test]
    public function credit_card_routes_are_registered_with_expected_uris_and_names(): void
    {
        $expected = [
            'credit-cards.index' => 'thetindung',
            'credit-cards.manage' => 'thetindung/quan-ly-the',
            'credit-cards.categories' => 'thetindung/danh-muc',
            'credit-cards.reports' => 'thetindung/bao-cao',
            'credit-cards.compare' => 'thetindung/so-sanh',
            'credit-cards.settings' => 'thetindung/cai-dat',
            'credit-cards.policies' => 'thetindung/chinh-sach',
        ];

        foreach ($expected as $name => $uri) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Thiếu route: {$name}");
            $this->assertSame($uri, $route->uri(), "URI sai cho route {$name}");
            $this->assertContains('GET', $route->methods());
        }
    }

    /** TEST 2: Route không đè lên bất kỳ route hiện tại nào. */
    #[Test]
    public function credit_card_routes_do_not_override_existing_routes(): void
    {
        $existing = [
            'dashboard',
            'wallet.index',
            'referrals.index',
            'orders.index',
            'guide.index',
            'profile.edit',
            'price-comparison.index',
            'promotion-news.index',
            'link-requests.store',
        ];

        foreach ($existing as $name) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                "Route hiện tại bị mất: {$name}"
            );
        }

        // URI mới của module không trùng URI của các route đang chạy.
        $existingUris = collect(['dashboard', 'wallet', 'referrals', 'orders', 'guide', 'profile', 'so-sanh-gia', 'tin-tuc-khuyen-mai', 'link-requests']);

        $moduleUris = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'credit-cards.'))
            ->map(fn ($route) => $route->uri());

        // So với TẬP TÊN route đã biết, không so số lượng. Phase 1B có chủ ý
        // thêm 13 API route (thẻ/danh mục/giao dịch) nên con số 6 của Phase 1A
        // không còn đúng; đặt số cứng thì mọi lần thêm route hợp lệ sau này lại
        // phải sửa test, còn đặt sai số thì test vẫn xanh. Liệt kê tên rõ ràng
        // vừa chặn route thừa vừa tự mô tả bề mặt API của module.
        $expectedModuleRoutes = [
            // 7 trang Phase 1A/1C
            'credit-cards.index',
            'credit-cards.manage',
            'credit-cards.categories',
            'credit-cards.reports',
            'credit-cards.compare',
            'credit-cards.settings',
            'credit-cards.policies',
            // API thẻ tín dụng (Phase 1B)
            'credit-cards.api.cards.index',
            'credit-cards.api.cards.store',
            'credit-cards.api.cards.update',
            'credit-cards.api.cards.destroy',
            'credit-cards.api.cards.reorder',
            // API danh mục (Phase 1B)
            'credit-cards.api.categories.index',
            'credit-cards.api.categories.store',
            'credit-cards.api.categories.update',
            'credit-cards.api.categories.destroy',
            // API giao dịch (Phase 1B)
            'credit-cards.api.transactions.index',
            'credit-cards.api.transactions.store',
            'credit-cards.api.transactions.update',
            'credit-cards.api.transactions.destroy',
            // API cấu hình policy (Phase 1C)
            'credit-cards.api.policies.index',
            'credit-cards.api.policies.store',
            'credit-cards.api.policies.show',
            'credit-cards.api.policies.update',
            'credit-cards.api.policies.versions.store',
            'credit-cards.api.policies.templates.store',
            'credit-cards.api.templates.index',
            'credit-cards.api.templates.show',
            'credit-cards.api.tiers.index',
            'credit-cards.api.tiers.store',
            'credit-cards.api.tiers.update',
            'credit-cards.api.tiers.destroy',
            'credit-cards.api.tiers.clone',
            'credit-cards.api.rules.index',
            'credit-cards.api.rules.store',
            'credit-cards.api.rules.update',
            'credit-cards.api.rules.destroy',
            'credit-cards.api.rules.clone',
        ];

        $actualModuleRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'credit-cards.'))
            ->map(fn ($route) => (string) $route->getName());

        $this->assertSame(
            collect($expectedModuleRoutes)->sort()->values()->all(),
            $actualModuleRoutes->sort()->values()->all(),
            'Tập route của module Thẻ tín dụng không khớp danh sách đã chốt.'
        );

        foreach ($moduleUris as $uri) {
            $this->assertFalse(
                $existingUris->contains($uri),
                "URI module Thẻ tín dụng đè lên route hiện tại: {$uri}"
            );
        }
    }

    /** TEST 3: Không chặn route hiện tại của Cashback/Affiliate. */
    #[Test]
    public function credit_card_routes_do_not_hijack_existing_urls(): void
    {
        $routes = collect(Route::getRoutes())->keyBy('uri');

        $this->assertTrue($routes->has('dashboard'));
        $this->assertTrue($routes->has('link-requests'));
        $this->assertTrue($routes->has('wallet'));
        $this->assertTrue($routes->has('api/link-request/{id}'));
        $this->assertTrue($routes->has('csrf-token'));
    }

    /** TEST 4: Tất cả 6 trang trả 200 cho user đã đăng nhập. */
    #[Test]
    public function all_credit_card_pages_load_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $urls = [
            '/thetindung',
            '/thetindung/quan-ly-the',
            '/thetindung/danh-muc',
            '/thetindung/bao-cao',
            '/thetindung/so-sanh',
            '/thetindung/cai-dat',
        ];

        foreach ($urls as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    /** TEST 5: Guest bị chuyển tới login (dùng auth hiện tại, không tạo auth mới). */
    #[Test]
    public function credit_card_pages_require_authentication(): void
    {
        foreach ([
            '/thetindung',
            '/thetindung/quan-ly-the',
            '/thetindung/danh-muc',
            '/thetindung/bao-cao',
            '/thetindung/so-sanh',
            '/thetindung/cai-dat',
        ] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    /** TEST 6: Trang tổng quan có tiêu đề, subtitle, menu sidebar và placeholder. */
    #[Test]
    public function index_page_renders_module_title_subtitle_and_sidebar(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/thetindung');

        $response->assertOk();
        $response->assertSee('Thẻ tín dụng');
        $response->assertSee('Quản lý và theo dõi các thẻ tín dụng của bạn');

        // Sidebar / module menu đủ 6 mục
        foreach ([
            'Tổng quan',
            'Quản lý thẻ',
            'Danh mục',
            'Báo cáo',
            'So sánh thẻ',
            'Cài đặt',
        ] as $label) {
            $response->assertSee($label);
        }

        // Chưa có thẻ => thông báo chuẩn + nút thêm thẻ
        $response->assertSee('Bạn chưa thêm thẻ tín dụng nào.');
        $response->assertSee('Thêm thẻ');
        $response->assertSee('Chưa có dữ liệu');
    }

    /** TEST 7: Nút "+ Thêm thẻ" dẫn tới /thetindung/quan-ly-the. */
    #[Test]
    public function add_card_button_points_to_manage_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/thetindung');

        $response->assertOk();
        $this->assertStringContainsString(
            'href="'.route('credit-cards.manage').'"',
            $response->getContent()
        );
    }

    /** TEST 8: Active state của sidebar đổi theo route. */
    #[Test]
    public function sidebar_marks_the_current_page_as_active(): void
    {
        $user = User::factory()->create();

        $cases = [
            '/thetindung' => 'credit-cards.index',
            '/thetindung/quan-ly-the' => 'credit-cards.manage',
            '/thetindung/chinh-sach' => 'credit-cards.policies',
            '/thetindung/danh-muc' => 'credit-cards.categories',
            '/thetindung/bao-cao' => 'credit-cards.reports',
            '/thetindung/so-sanh' => 'credit-cards.compare',
            '/thetindung/cai-dat' => 'credit-cards.settings',
        ];

        foreach ($cases as $url => $activeRoute) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            // Thẻ <a> của route đang active phải có aria-current="page"
            // (không phụ thuộc thứ tự attribute trong HTML).
            $this->assertMatchesRegularExpression(
                '/<a(?=[^>]*aria-current="page")(?=[^>]*href="'.preg_quote(route($activeRoute), '/').'")[^>]*>/',
                $html,
                "Active state không áp dụng cho menu {$activeRoute} trên {$url}"
            );
        }
    }

    /** TEST 9: Các trang placeholder hiển thị tên module + thông báo giai đoạn 2. */
    #[Test]
    public function placeholder_pages_show_module_name_and_next_phase_notice(): void
    {
        $user = User::factory()->create();

        $cases = [
            '/thetindung/quan-ly-the' => 'Quản lý thẻ',
            '/thetindung/danh-muc' => 'Danh mục',
            '/thetindung/bao-cao' => 'Báo cáo',
            '/thetindung/so-sanh' => 'So sánh thẻ',
            '/thetindung/cai-dat' => 'Cài đặt',
        ];

        foreach ($cases as $url => $moduleName) {
            $response = $this->actingAs($user)->get($url);

            $response->assertOk();
            $response->assertSee($moduleName);
            $response->assertSee('Module này sẽ được triển khai ở giai đoạn tiếp theo.');
        }
    }

    /** TEST 10: Tab "Thẻ tín dụng" có trên navigation hiện tại và trỏ đúng /thetindung. */
    #[Test]
    public function navigation_contains_credit_card_tab(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('Thẻ tín dụng', $html);
        $this->assertStringContainsString('href="'.route('credit-cards.index').'"', $html);

        // Menu hiện tại không bị mất
        foreach (['Trang chủ', 'So sánh giá', 'Tin tức KM', 'Dashboard'] as $item) {
            $this->assertStringContainsString($item, $html, "Thiếu menu hiện tại: {$item}");
        }
    }

    /** TEST 11: Tab "Thẻ tín dụng" active khi đang ở /thetindung/*. */
    #[Test]
    public function navigation_tab_is_active_inside_credit_card_module(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/thetindung/quan-ly-the')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*aria-current="page")(?=[^>]*href="'.preg_quote(route('credit-cards.index'), '/').'")[^>]*>/',
            $html
        );
    }

    /** TEST 12: Layout module kế thừa layout chính (header/nav hiện tại vẫn có). */
    #[Test]
    public function credit_card_layout_reuses_existing_app_navigation(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/thetindung')->assertOk()->getContent();

        // Header/navigation hiện tại vẫn được tái sử dụng nguyên vẹn
        $this->assertStringContainsString('Trang chủ', $html);
        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('Tài khoản', $html);
        $this->assertStringContainsString('Ví tiền', $html);
        $this->assertStringContainsString('Tra cứu đơn hàng', $html);
        $this->assertStringContainsString('Hướng dẫn', $html);

        // Layout module dùng chung app layout (không tạo layout riêng cho site)
        $this->assertStringContainsString('min-h-screen bg-gray-100', $html);
        $this->assertStringContainsString('<main>', $html);
    }

    /** TEST 13: Migration tạo đủ 4 bảng. */
    #[Test]
    public function migration_creates_the_four_credit_card_tables(): void
    {
        foreach ([
            'credit_card_banks',
            'credit_card_categories',
            'credit_cards',
            'user_credit_cards',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Thiếu bảng: {$table}");
        }
    }

    /** TEST 14: Cấu trúc bảng đúng Giai đoạn 1 + không lưu số thẻ đầy đủ. */
    #[Test]
    public function credit_card_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('credit_card_banks', [
            'id', 'name', 'slug', 'logo', 'is_active', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('credit_card_categories', [
            'id', 'name', 'slug', 'description', 'is_active', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('credit_cards', [
            'id', 'bank_id', 'category_id', 'name', 'slug', 'image', 'annual_fee',
            'description', 'is_active', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('user_credit_cards', [
            'id', 'user_id', 'credit_card_id', 'card_number_last4', 'credit_limit',
            'statement_day', 'payment_due_day', 'is_active', 'created_at', 'updated_at',
        ]));

        // Bảng users hiện tại KHÔNG bị thay đổi.
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumn('users', 'email'));

        // Không có cột lưu số thẻ đầy đủ / CVV / ngày hết hạn.
        foreach (Schema::getColumnListing('user_credit_cards') as $column) {
            $this->assertNotSame('card_number', $column);
            $this->assertNotSame('cvv', $column);
            $this->assertNotSame('exp_date', $column);
        }
    }

    /** TEST 15: Model Phase 1A load được + relationships 2 chiều hoạt động. */
    #[Test]
    public function credit_card_models_and_relationships_work(): void
    {
        $bank = $this->makeBank(['name' => 'Ngân hàng Test', 'slug' => 'ngan-hang-test']);

        $product = $this->makeProduct($bank, [
            'name' => 'Thẻ Test',
            'slug' => 'the-test',
        ]);

        $category = $this->makeSystemCategory([
            'name' => 'Online shopping',
            'slug' => 'online-shopping',
        ]);

        $user = User::factory()->create();

        $userCard = $this->makeUserCard($user->id, [
            'bank_id' => $bank->id,
            'name' => 'Thẻ của tôi',
        ]);

        $policy = $this->makePolicyForCard($userCard, [
            ['name' => 'Cơ bản', 'min' => 0, 'max' => null],
        ], [
            ['category_id' => $category->id, 'percent' => '2.000'],
        ]);

        $tier = $policy->tiers()->first();
        $rule = $tier->tierCategoryRules()->first();

        // Bank -> products (catalog cũ vẫn còn quan hệ 2 chiều)
        $this->assertTrue($bank->products->contains($product));

        // Product -> bank
        $this->assertTrue($bank->is($product->bank));

        // UserCard -> bank TRỰC TIẾP (Phase 1B) / user (cross-DB, không FK)
        $this->assertTrue($bank->is($userCard->bank));
        $this->assertTrue($user->is($userCard->user));

        // `product_id` còn nullable + deprecated: card mới KHÔNG gắn product nữa,
        // nên catalog cũ không tự "nuôi" thẻ.
        $this->assertNull($userCard->product_id);
        $this->assertNull($userCard->product);
        $this->assertFalse($product->userCards->contains($userCard));

        // UserCard -> policy (current) + policy -> userCard + tiers -> rules
        $this->assertTrue($policy->is($userCard->currentPolicy));
        $this->assertTrue($userCard->is($policy->userCard));
        $this->assertTrue($policy->is($tier->policyVersion));
        $this->assertTrue($tier->is($rule->tier));
        $this->assertTrue($category->is($rule->category));
        $this->assertTrue($category->tierCategoryRules->contains($rule));

        // Root policy trỏ về chính nó + scope theo thẻ
        $this->assertTrue($policy->isRoot());
        $this->assertSame($policy->id, $policy->root_policy_id);
        $this->assertTrue(Policy::query()->forCard($userCard->id)->get()->contains($policy));

        // User -> user credit cards (cross-DB, không FK)
        $this->assertTrue($user->userCreditCards->contains($userCard));

        // Casts
        $this->assertIsBool($bank->is_active);
        $this->assertSame(25, $userCard->payment_due_day);
        $this->assertSame(UserCard::BASIS_TRANSACTION_DATE, $userCard->statement_date_basis);
        $this->assertSame(UserCard::STATUS_ACTIVE, $userCard->status);

        // Bảo mật: 4 số cuối không serialize ra ngoài.
        $this->assertArrayNotHasKey('card_number_last4', $userCard->fresh()->toArray());
    }

    /** TEST 16: Dữ liệu thẻ luôn scope theo user đang đăng nhập. */
    #[Test]
    public function credit_card_data_is_scoped_to_the_authenticated_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->makeUserCard($userA->id, ['credit_limit' => 1000000]);
        $this->makeUserCard($userB->id, ['credit_limit' => 9000000]);

        // Trang của user A KHÔNG lộ hạn mức của user B.
        $html = $this->actingAs($userA)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('1.000.000', $html);
        $this->assertStringNotContainsString('9.000.000', $html);

        // Ngược lại, trang của user B chỉ thấy hạn mức của B.
        $htmlB = $this->actingAs($userB)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('9.000.000', $htmlB);
        $this->assertStringNotContainsString('1.000.000', $htmlB);

        // Số thẻ đếm đúng cho từng user.
        $this->assertSame(1, $userA->userCreditCards()->count());
        $this->assertSame(1, $userB->userCreditCards()->count());

        // Scope `ownedBy` dùng chung với controller.
        $this->assertSame(
            1,
            UserCard::query()->ownedBy($userA->id)->count()
        );
    }

    /** TEST 17: Tổng hạn mức + tên sản phẩm/ngân hàng hiển thị khi user có thẻ. */
    #[Test]
    public function index_shows_total_limit_when_user_has_cards(): void
    {
        $bank = $this->makeBank(['name' => 'Ngân hàng ABC', 'slug' => 'abc']);

        $user = User::factory()->create();

        $this->makeUserCard($user->id, [
            // Phase 1B: chỉ cần bank + tên thẻ do user đặt.
            'bank_id' => $bank->id,
            'name' => 'Thẻ ABC',
            'card_number_last4' => '1234',
            'credit_limit' => 50000000,
        ]);

        $response = $this->actingAs($user)->get('/thetindung');

        $response->assertOk();
        $response->assertSee('Thẻ ABC');
        $response->assertSee('Ngân hàng ABC');
        $response->assertSee('50.000.000');
        $response->assertDontSee('Bạn chưa thêm thẻ tín dụng nào.');
    }

    /**
     * TEST 17b: Trang tổng quan KHÔNG ghi bản ghi kỳ sao kê (GET phải read-only).
     *
     * Bảo vệ nguyên tắc: mở trang không được sinh `credit_card_statement_periods`.
     */
    #[Test]
    public function index_does_not_create_statement_periods(): void
    {
        $user = User::factory()->create();
        $this->makeUserCard($user->id);

        $this->assertSame(0, StatementPeriod::query()->count());

        $this->actingAs($user)->get('/thetindung')->assertOk();
        $this->actingAs($user)->get('/thetindung')->assertOk();

        $this->assertSame(0, StatementPeriod::query()->count());
    }

    /**
     * TEST 17c: Kỳ đã tồn tại thì trang tổng quan hiện cashback + ngày chốt kỳ.
     */
    #[Test]
    public function index_shows_current_period_cashback_and_statement_date(): void
    {
        $user = User::factory()->create();
        $userCard = $this->makeUserCard($user->id, [
            'statement_day' => 15,
            'payment_due_day' => 25,
        ]);

        $period = $this->makeStatementPeriod($userCard, [
            'period_start' => '2026-09-16',
            'period_end' => '2026-10-15',
            'statement_date' => '2026-10-15',
            'payment_due_date' => '2026-10-25',
            'total_cashback' => 250000,
        ]);

        $response = $this->actingAs($user)->get('/thetindung');

        $response->assertOk();
        $response->assertSee('250.000');
        $response->assertSee('15/10');
        $response->assertSee('25/10/2026');

        // Chỉ hiện kỳ đang mở của chính user này.
        $this->assertSame(1, StatementPeriod::query()->where('user_card_id', $userCard->id)->count());
        $this->assertSame($period->id, $period->fresh()->id);
    }

    /** TEST 18: User model / auth hiện tại không bị ảnh hưởng. */
    #[Test]
    public function existing_user_auth_still_works(): void
    {
        $user = User::factory()->create([
            'email' => 'creditcard-regression@example.com',
        ]);

        $this->actingAs($user)->get('/wallet')->assertOk();
        $this->actingAs($user)->get('/referrals')->assertOk();
        $this->actingAs($user)->get('/orders')->assertOk();
        $this->actingAs($user)->get('/guide')->assertOk();
    }
}
