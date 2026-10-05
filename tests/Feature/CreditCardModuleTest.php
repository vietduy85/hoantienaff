<?php

namespace Tests\Feature;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
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
            'credit-cards.statements',
            // Trang lịch sử giao dịch của một thẻ (đọc + sửa nhanh). Thêm giao
            // dịch nằm ở Tổng quan nên không có route trang cho việc đó.
            'credit-cards.transactions',
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
            // API sao kê thực tế (statements)
            'credit-cards.api.statements.index',
            'credit-cards.api.statements.store',
            'credit-cards.api.statements.update',
            'credit-cards.api.statements.destroy',
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
            // API combo danh mục (Phase 2) — KHÔNG có destroy: combo chỉ tạo/sửa.
            'credit-cards.api.combos.index',
            'credit-cards.api.combos.store',
            'credit-cards.api.combos.update',
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

        // Sidebar / module menu đủ 7 mục
        foreach ([
            'Tổng quan',
            'Quản lý thẻ',
            'Chính sách',
            'Danh mục chi tiêu',
            'Báo cáo',
            'So sánh thẻ',
            'Cài đặt',
        ] as $label) {
            $response->assertSee($label);
        }

        // Chưa có thẻ => thông báo chuẩn. Nút "+ Thêm thẻ" đã BỎ khỏi tổng quan
        // (nút nằm ở trang Quản lý thẻ) — xem `overview_has_no_add_card_button`.
        $response->assertSee('Bạn chưa thêm thẻ tín dụng nào.');
        $response->assertSee('Chưa có dữ liệu');
    }

    /** TEST 6b: Tổng quan KHÔNG còn nút "+ Thêm thẻ" (nút nằm ở trang Quản lý thẻ). */
    #[Test]
    public function overview_has_no_add_card_button(): void
    {
        $user = User::factory()->create();

        $doc = $this->domOf($this->actingAs($user)->get('/thetindung')->assertOk());

        $this->assertSame(
            0,
            $this->addCardButtons($doc)->length,
            'Tổng quan không được có nút "+ Thêm thẻ" (nút thuộc trang Quản lý thẻ).'
        );
    }

    /**
     * TEST 7: Trang Quản lý thẻ có nút "+ Thêm thẻ" nối tới form hiện có.
     *
     * Cố tình KHÔNG grep chuỗi "Thêm thẻ": lỗi thật trước đây là nút CÓ trong HTML
     * nhưng không dùng được, mà grep chuỗi vẫn xanh. Test này phải xác nhận đủ:
     *   1. nút tồn tại theo selector,
     *   2. nằm cùng hàng với tiêu đề "Thẻ của bạn" (đầu section, không cuối trang),
     *   3. gọi `openCreate()` — dùng lại form sẵn có, không tạo form thứ hai,
     *   4. không bị ẩn sẵn bởi CSS (`style="display:none"`).
     */
    #[Test]
    public function manage_page_offers_the_add_card_form(): void
    {
        $user = User::factory()->create();

        // Ô chọn ngân hàng CHỈ hữu ích khi server gửi kèm tên / mã / alias.
        // Seed sẵn 2 bank active (một bank có alias) + 1 bank ngừng hoạt động để
        // kiểm tra cả payload lẫn việc loại bank không chọn được.
        $this->makeBank(['name' => 'Sacombank', 'short_name' => 'STB', 'slug' => 'stb', 'aliases' => ['scb']]);
        $this->makeBank(['name' => 'Quốc tế VIB', 'short_name' => 'VIB', 'slug' => 'vib']);
        $this->makeBank([
            'name' => 'Ngân hàng Đã Ngừng Hoạt Động',
            'short_name' => 'OFF',
            'slug' => 'ngan-hang-da-ngung-hoat-dong',
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->get(route('credit-cards.manage'))->assertOk();
        $doc = $this->domOf($response);
        $responseHtml = $response->getContent();

        $buttons = $this->addCardButtons($doc);
        $this->assertSame(1, $buttons->length, 'Trang Quản lý thẻ phải có đúng một nút "+ Thêm thẻ".');

        /** @var DOMElement $button */
        $button = $buttons->item(0);

        // Nút mở form sẵn có, không phải form/URL mới.
        $this->assertSame('button', $button->tagName);
        $this->assertSame('', $button->getAttribute('href'), 'Nút phải là <button>, không được điều hướng.');

        // Chỉ ẩn khi form đang mở; không được ẩn sẵn bằng CSS.
        $this->assertSame('! form.open', trim($button->getAttribute('x-show')));
        $this->assertStringNotContainsString('display:none', (string) $button->getAttribute('style'));

        // `@click` KHÔNG đọc được qua DOM: libxml bỏ attribute bắt đầu bằng `@`.
        // Vì thế assert trên đúng markup của NÚT này, không grep cả trang.
        $buttonHtml = $this->addCardButtonHtml($responseHtml);
        $this->assertStringContainsString('@click="openCreate()"', $buttonHtml);
        $this->assertStringNotContainsString('display:none', $buttonHtml);

        // Nút nằm trong section "Thẻ của bạn" — tức đầu section danh sách.
        // PHẢI scope vào section của nút: `getElementsByTagName('h3')->item(0)` sẽ
        // lấy h3 đầu tiên CỦA TRANG, tức chrome dùng chung (không phải của màn này).
        $section = $this->closestSection($button);
        $this->assertNotNull($section, 'Nút phải nằm trong một <section>.');

        $xpath = new DOMXPath($doc);

        // Nút và tiêu đề phải chung một hàng: h3 là hậu duệ của cha nút.
        // (Nút là anh em với `<div class="min-w-0">` bọc h3, không cùng cha trực tiếp.)
        $row = $button->parentNode;
        $headings = $xpath->query('.//h3', $row);
        $this->assertGreaterThan(0, $headings->length, 'Nút phải chung hàng với tiêu đề section.');
        $this->assertSame('Thẻ của bạn', trim($headings->item(0)->textContent));

        // Hàng tiêu đề phải là phần tử ĐẦU của section => nút ở đầu danh sách.
        // `previousElementSibling` chứ không phải `previousSibling`: Blade để lại
        // node khoảng trắng nên `previousSibling` không bao giờ null.
        $this->assertNull($row->previousElementSibling, 'Nút phải ở đầu section, không phải cuối trang.');

        // Cùng MỘT form thẻ: nút bấm vào, form hiện ra.
        // KHÔNG đếm toàn bộ <form> của trang: layout dùng chung có sẵn 2 form
        // `logout`, nên phải lọc theo chính form của màn quản lý thẻ.
        $cardForms = $xpath->query('//form[@x-show="form.open"]');
        $this->assertSame(1, $cardForms->length, 'Chỉ được có MỘT form thẻ, không tạo form trùng.');

        // Form đó phải là form nhập thẻ thật, không phải form nào đó tình cờ.
        $this->assertGreaterThan(
            0,
            $xpath->query('.//*[@id="cc-name"]', $cardForms->item(0))->length,
            'Form bị ẩn khi mở phải là form nhập thẻ.'
        );

        // Các trường form bắt buộc của màn hình — tra theo `id`, không grep.
        foreach (['cc-name', 'cc-period-start', 'cc-due-day', 'cc-deadline-day', 'cc-desired', 'cc-promo', 'cc-note'] as $field) {
            $this->assertSame(
                1,
                $xpath->query('//*[@id="'.$field.'"]')->length,
                "Thiếu ô nhập #{$field}."
            );
        }

        // Ô chọn ngân hàng: combo box có tìm kiếm, KHÔNG phải lưới nhiều cột.
        //
        // Grid nhiều cột là thủ phạm của thanh cuộn ngang trên mobile. Danh sách
        // ngân hàng nay là `<ul>` một cột cuộn dọc, nên phải khẳng định KHÔNG còn
        // lưới nhiều cột nào chứa các lựa chọn ngân hàng.
        $this->assertSame(
            1,
            $xpath->query('//*[@data-testid="bank-selector"]')->length,
            'Phải có đúng một ô chọn ngân hàng.'
        );

        $this->assertSame(
            'true',
            $xpath->query('//*[@data-testid="bank-selector"]')->item(0)->getAttribute('data-bank-searchable'),
            'Ô chọn ngân hàng phải bật tìm kiếm.'
        );

        // Khung bao quanh ô chọn (div `relative` chứa cả trigger lẫn dropdown).
        $bankScope = $xpath->query('//*[@data-testid="bank-selector"]/ancestor::div[contains(concat(" ", normalize-space(@class), " "), " relative ")][1]');
        $this->assertSame(1, $bankScope->length, 'Không tìm thấy khung của ô chọn ngân hàng.');
        $bankScopeNode = $bankScope->item(0);

        // Danh sách ngân hàng KHÔNG được nằm trong lưới nhiều cột.
        $multiColumn = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " grid-cols-2 ")
               or contains(concat(" ", normalize-space(@class), " "), " grid-cols-3 ")
               or contains(concat(" ", normalize-space(@class), " "), " sm:grid-cols-2 ")
               or contains(concat(" ", normalize-space(@class), " "), " sm:grid-cols-3 ")]',
            $bankScopeNode
        );
        $this->assertSame(0, $multiColumn->length, 'Lựa chọn ngân hàng không được nằm trong lưới nhiều cột (gây cuộn ngang).');

        // Ô tìm kiếm gắn với `bankQuery` — nguồn lọc phía client.
        $search = $xpath->query('.//*[@data-testid="bank-search"]', $bankScopeNode);
        $this->assertSame(1, $search->length, 'Thiếu ô tìm ngân hàng.');
        $this->assertSame(
            'bankQuery',
            $search->item(0)->getAttribute('x-model'),
            'Ô tìm kiếm phải gắn với bankQuery.'
        );

        // Danh sách lựa chọn là `<ul>` một cột, cuộn DỌC, và dropdown được
        // `absolute` + `z-50` nên không bị container cha cắt mất.
        $list = $xpath->query('.//ul[@role="listbox"]', $bankScopeNode);
        $this->assertSame(1, $list->length, 'Danh sách ngân hàng phải là một listbox duy nhất.');
        $this->assertStringContainsString(
            'overflow-y-auto',
            $list->item(0)->getAttribute('class'),
            'Danh sách ngân hàng phải cuộn dọc.'
        );

        $dropdown = $xpath->query('.//div[@x-show="bankPickerOpen"]', $bankScopeNode);
        $this->assertSame(1, $dropdown->length, 'Thiếu dropdown ngân hàng.');
        $dropdownClass = $dropdown->item(0)->getAttribute('class');
        $this->assertStringContainsString('absolute', $dropdownClass, 'Dropdown phải nổi (absolute) để không đẩy layout.');
        $this->assertStringContainsString('z-50', $dropdownClass, 'Dropdown phải nằm trên các phần tử khác.');

        // Hai lựa chọn "chưa biết ngân hàng" — cùng lưu `bank_id = NULL`,
        // KHÔNG tạo bank giả.
        $scopeText = $bankScopeNode->textContent;
        $this->assertStringContainsString(
            'Chưa biết / Không chọn',
            $scopeText,
            'Thiếu lựa chọn "Chưa biết / Không chọn".'
        );
        $this->assertSame(
            1,
            $xpath->query('.//*[@data-testid="bank-other-option"]', $bankScopeNode)->length,
            'Thiếu lựa chọn "Ngân hàng khác".'
        );
        $this->assertStringContainsString(
            'Ngân hàng khác',
            $scopeText,
            'Thiếu nhãn "Ngân hàng khác".'
        );

        // Giá trị chọn đi vào payload qua input ẩn `bank_id`.
        $this->assertSame(
            1,
            $xpath->query('//input[@name="bank_id"][@type="hidden"]')->length,
            'Thiếu input ẩn bank_id để gửi giá trị đã chọn.'
        );

        // Payload phía client phải có đủ dữ liệu để tìm theo TÊN, MÃ và ALIAS
        // (gõ "scb" vẫn ra Sacombank vì "scb" nằm trong `aliases`).
        //
        // Chọn ĐÚNG component quản lý thẻ: layout dùng chung có nhiều `x-data`
        // khác (menu, hỗ trợ…), nên phải lọc theo tên hàm.
        $component = $xpath->query('//*[contains(@x-data, "creditCardManager")]')->item(0);
        $this->assertNotNull($component, 'Thiếu component Alpine của màn quản lý thẻ.');
        $initialState = $component->getAttribute('x-data');
        $this->assertStringContainsString('creditCardManager', $initialState);

        foreach (['short_name', 'aliases', 'slug'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $initialState,
                "Payload ngân hàng thiếu trường [{$needle}] để tìm kiếm."
            );
        }

        // Bank active phải vào payload kèm mã viết tắt và alias; bank ngừng
        // hoạt động thì không (đừng cho chọn ngân hàng không dùng nữa).
        $this->assertStringContainsString('Sacombank', $initialState);
        $this->assertStringContainsString('STB', $initialState);
        $this->assertStringContainsString('scb', $initialState, 'Alias "scb" phải được gửi để gõ mã cũ vẫn ra Sacombank.');
        $this->assertStringContainsString('VIB', $initialState);
        $this->assertStringNotContainsString(
            'ngan-hang-da-ngung-hoat-dong',
            $initialState,
            'Bank ngừng hoạt động không được đưa vào danh sách chọn.'
        );

        // Hành vi lọc/tìm kiếm nằm trong <script> của component, không nằm trong
        // `x-data`. `@click`/`x-text` không đọc được qua DOM nên assert trên HTML thô.
        foreach (['filteredBanks', 'bankQuery', 'pickBank', 'bankKeyword'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $responseHtml,
                "Component quản lý thẻ thiếu logic tìm ngân hàng [{$needle}]."
            );
        }
    }

    /**
     * TEST 7b: Nút "+ Thêm thẻ" hiện ngay cả khi user CHƯA có thẻ nào.
     *
     * Đây là lỗi đã gặp: nút bị nhốt trong nhánh điều kiện theo danh sách thẻ nên
     * người dùng mới không có cách nào bắt đầu.
     */
    #[Test]
    public function the_add_card_button_is_present_when_the_user_has_no_cards(): void
    {
        $user = User::factory()->create();

        $doc = $this->domOf($this->actingAs($user)->get(route('credit-cards.manage'))->assertOk());

        // Empty state vẫn phải còn. Dùng `textContent` chứ không phải `saveHTML()`:
        // `saveHTML()` escape non-ASCII thành entity nên không so được tiếng Việt.
        $this->assertStringContainsString('Chưa có thẻ nào', $doc->textContent);

        $buttons = $this->addCardButtons($doc);
        $this->assertSame(1, $buttons->length, 'Chưa có thẻ thì nút "+ Thêm thẻ" vẫn phải hiện.');
        $this->assertStringNotContainsString('display:none', (string) $buttons->item(0)->getAttribute('style'));
    }

    /**
     * TEST 7c: Component Alpine phải khởi tạo được — không gọi `this.<method>()` lúc
     * khai báo state.
     *
     * `x-data="creditCardManager({...})"` gọi hàm như HÀM TRẦN nên `this` không phải
     * component. `form: this.blankForm()` trong object literal vì thế ném `TypeError`
     * ngay lúc Alpine khởi tạo, giết chết toàn bộ trang kể cả nút "+ Thêm thẻ".
     * Test này chặn đúng lỗi đó.
     */
    #[Test]
    public function the_manage_component_does_not_call_itself_while_building_state(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('credit-cards.manage'))->assertOk()->getContent();

        // Chỉ soi initializer của `form:`, không cấm chuỗi `this.blankForm(` ở đâu
        // khác — nếu cấm rộng thì chính comment giải thích trong view cũng fail.
        $this->assertDoesNotMatchRegularExpression(
            '/form:\s*this\.\w+\(/',
            $html,
            'Gọi `this.<method>()` khi khai báo state sẽ ném TypeError vì Alpine gọi hàm như hàm trần.'
        );

        // `form` phải khai bằng hàm đứng riêng, và `open` phải là boolean thật —
        // thiếu `open` thì nút luôn hiện còn form không bao giờ mở.
        $this->assertMatchesRegularExpression('/form:\s*ccBlankCardForm\(\)/', $html);
        $this->assertMatchesRegularExpression(
            '/function ccBlankCardForm\(\)\s*\{\s*return\s*\{\s*open:\s*false,/',
            $html
        );
        $this->assertMatchesRegularExpression('/openCreate\(\)\s*\{.*?open:\s*true/s', $html);
    }

    /**
     * TEST 7d: Nút "+ Thêm thẻ" vẫn hiện khi user ĐÃ có thẻ — không được chỉ hiện ở
     * empty state rồi biến mất, cũng không được đổi chức năng "Sửa" đang có.
     */
    #[Test]
    public function the_add_card_button_is_still_present_when_the_user_has_cards(): void
    {
        $user = User::factory()->create();
        $this->makeUserCard($user->id, ['name' => 'Thẻ đã có']);

        $doc = $this->domOf($this->actingAs($user)->get(route('credit-cards.manage'))->assertOk());

        $buttons = $this->addCardButtons($doc);
        $this->assertSame(1, $buttons->length, 'Đã có thẻ thì nút "+ Thêm thẻ" vẫn phải hiện.');

        // Danh sách thẻ + nút "Sửa" vẫn render như cũ.
        $this->assertStringContainsString('Thẻ đã có', $doc->textContent);
        $this->assertStringNotContainsString('Chưa có thẻ nào', $doc->textContent);

        // Nút "Sửa" từng thẻ. Không lọc bằng `@click` vì DOM bỏ attribute `@...`.
        $editButtons = (new DOMXPath($doc))->query('//button[normalize-space(.)="Sửa"]');
        $this->assertGreaterThan(0, $editButtons->length, 'Nút "Sửa" từng thẻ phải còn nguyên.');
    }

    /**
     * Parse HTML thành DOM để assert theo selector thật, không theo chuỗi thô.
     */
    private function domOf(TestResponse $response): DOMDocument
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc;
    }

    /**
     * NodeList các nút "+ Thêm thẻ" trên trang (dùng `data-testid` làm hook ổn định).
     *
     * @return DOMNodeList<int, DOMElement>
     */
    private function addCardButtons(DOMDocument $doc): DOMNodeList
    {
        $xpath = new DOMXPath($doc);
        $buttons = $xpath->query('//button[@data-testid="add-card-button"]');

        $this->assertNotFalse($buttons, 'XPath không chạy được.');

        return $buttons;
    }

    /**
     * Markup thô của đúng nút "+ Thêm thẻ", dùng để kiểm `@click` / `x-show`.
     *
     * Tách riêng vì DOMDocument BỎ attribute bắt đầu bằng `@` (không hợp lệ theo
     * HTML nên libxml âm thầm bỏ), trong khi `@click="openCreate()"` chính là thứ
     * quyết định nút có hoạt động hay không.
     */
    private function addCardButtonHtml(string $html): string
    {
        $matched = preg_match(
            '#<button\b[^>]*\bdata-testid="add-card-button".*?</button>#s',
            $html,
            $matches
        );

        $this->assertSame(1, $matched, 'Không tìm thấy markup nút "+ Thêm thẻ".');

        return $matches[0];
    }

    /**
     * Section gần nhất chứa nút, dùng để scope assert (không đụng chrome chung).
     */
    private function closestSection(DOMElement $element): ?DOMElement
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->tagName === 'section') {
                return $node;
            }
        }

        return null;
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

    /**
     * TEST 9: Các trang placeholder hiển thị tên module + thông báo giai đoạn 2.
     *
     * `/thetindung/quan-ly-the` KHÔNG còn nằm trong danh sách này — nó đã thành
     * màn hình thật (xem `manage_page_offers_the_add_card_form`).
     */
    #[Test]
    public function placeholder_pages_show_module_name_and_next_phase_notice(): void
    {
        $user = User::factory()->create();

        $cases = [
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

    /**
     * TEST 9b: Trang /thetindung/danh-muc là màn hình quản lý danh mục CHI TIÊU
     * (không còn placeholder) — tiêu đề, subtitle, 2 nhóm và empty state.
     */
    #[Test]
    public function categories_page_renders_category_management_ui(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/thetindung/danh-muc');

        $response->assertOk();
        $response->assertSee('Danh mục chi tiêu');
        $response->assertSee('Quản lý các nhóm chi tiêu dùng cho thẻ tín dụng và hoàn tiền');
        $response->assertSee('DANH MỤC HỆ THỐNG');
        $response->assertSee('DANH MỤC CỦA TÔI');
        $response->assertSee('Bạn chưa tạo danh mục chi tiêu riêng.');
        $response->assertSee('+ Thêm danh mục');
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
        $response->assertSee('50.000.000');

        // Danh sách thẻ chỉ giữ tên thẻ + 4 số cuối; tên ngân hàng không lặp lại
        // ở đây (nó chỉ nằm trong payload của ô chọn thẻ để phân biệt khi có
        // nhiều thẻ).
        $response->assertSee('•••• 1234');
        $response->assertDontSee('Ngân hàng ABC');
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
     * TEST 17c: Kỳ đã tồn tại thì trang tổng quan hiện cashback dự kiến theo
     * kỳ hiện tại của thẻ.
     *
     * Không còn kỳ vọng ngày chốt/đến hạn: ô tổng KHÔNG in một khoảng ngày
     * nữa, vì mỗi thẻ một `statement_day` nên mỗi thẻ một kỳ — một khoảng duy
     * nhất chỉ đúng với thẻ đầu tiên và sai với các thẻ còn lại.
     */
    #[Test]
    public function index_shows_current_period_cashback_and_statement_date(): void
    {
        $user = User::factory()->create();
        $category = $this->makeSystemCategory();
        $userCard = $this->makeUserCard($user->id, [
            'statement_day' => 15,
            'payment_due_day' => 25,
            'desired_spend' => '5000000',
        ]);

        // Bậc đích theo `desired_spend` 5.000.000, rate 5%. "Dự kiến" nhân với
        // CHI TIÊU THỰC TẾ 4.000.000 ⇒ 200.000 (không phải 5.000.000 × 5%).
        $this->makePolicyForCard(
            $userCard,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $period = $this->makeStatementPeriod($userCard, [
            'period_start' => '2026-09-16',
            'period_end' => '2026-10-15',
            'statement_date' => '2026-10-15',
            'payment_due_date' => '2026-10-25',
            'total_cashback' => 200000,
        ]);

        $period->transactions()->create([
            'user_card_id' => $userCard->id,
            'category_id' => $category->id,
            'amount' => '4000000.00',
            'transaction_date' => '2026-10-01',
            'statement_period_id' => $period->id,
            'is_eligible' => true,
        ]);

        $response = $this->actingAs($user)->get('/thetindung');

        $response->assertOk();
        $response->assertSee('200.000');

        // Cả hai ô tổng nói đúng nguyên tắc, không bịa khoảng ngày chung.
        $this->assertSame(
            2,
            substr_count($response->getContent(), 'Theo kỳ sao kê hiện tại của từng thẻ')
        );
        $response->assertDontSee('15/10');
        $response->assertDontSee('25/10/2026');

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
