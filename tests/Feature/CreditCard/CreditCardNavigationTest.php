<?php

namespace Tests\Feature\CreditCard;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Điều hướng module Thẻ tín dụng sau khi tái cấu trúc.
 *
 * Thanh chính đúng 6 mục: 🏠 Tổng quan · 🧾 Sao kê · 📊 Báo cáo · 🔎 Lựa chọn thẻ · 📖 Tra cứu · ⚙️ Cài đặt.
 * Ba mục cũ (Quản lý thẻ / Chính sách hoàn tiền / Danh mục) rời thanh chính và nằm
 * trong menu xổ xuống của "Cài đặt" cùng "Đơn vị tiền" (trang cài đặt hiện tại):
 * 💳 Quản lý thẻ · 💱 Đơn vị tiền · 🎯 Chính sách hoàn tiền · 📁 Danh mục.
 *
 * Ghi chú: không route/URL/DB nào bị đổi — đây thuần tuý là tái bố cục điều hướng.
 */
class CreditCardNavigationTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
    }

    // =====================================================================
    // Cấu trúc thanh chính
    // =====================================================================

    #[Test]
    public function main_nav_has_exactly_six_items_in_expected_order(): void
    {
        $xpath = $this->xpath($this->getContent(route('credit-cards.index')));

        $items = $xpath->query($this->mainNavSelector().'/a | '.$this->mainNavSelector().'/button');

        $labels = [];
        foreach ($items as $item) {
            $labels[] = $this->labelOf($xpath, $item);
        }

        $this->assertSame(
            ['Tổng quan', 'Sao kê', 'Báo cáo', 'Lựa chọn thẻ', 'Tra cứu', 'Cài đặt'],
            $labels,
            'Thanh chính phải có đúng 6 mục theo thứ tự.'
        );
    }

    #[Test]
    public function main_nav_contains_only_the_primary_links(): void
    {
        $xpath = $this->xpath($this->getContent(route('credit-cards.index')));

        $hrefs = [];
        foreach ($xpath->query($this->mainNavSelector().'/a') as $link) {
            $hrefs[] = $link->getAttribute('href');
        }

        $this->assertSame([
            route('credit-cards.index'),
            route('credit-cards.statements'),
            route('credit-cards.reports'),
            route('credit-cards.compare'),
            route('credit-cards.lookup'),
        ], $hrefs);

        // Ba mục cũ KHÔNG còn là link trên thanh chính.
        $this->assertNotContains(route('credit-cards.manage'), $hrefs);
        $this->assertNotContains(route('credit-cards.policies'), $hrefs);
        $this->assertNotContains(route('credit-cards.categories'), $hrefs);
    }

    #[Test]
    public function main_nav_has_no_top_level_link_to_settings_or_moved_pages(): void
    {
        $html = $this->getContent(route('credit-cards.index'));

        // "Cài đặt" là NÚT mở menu, không phải <a>.
        $this->assertStringContainsString('data-testid="cc-settings-trigger"', $html);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*data-testid="cc-settings-trigger"[^>]*>/',
            $html
        );
    }

    // =====================================================================
    // Menu xổ xuống "Cài đặt"
    // =====================================================================

    #[Test]
    public function settings_menu_lists_four_items_in_expected_order(): void
    {
        $xpath = $this->xpath($this->getContent(route('credit-cards.index')));

        $labels = [];
        $hrefs = [];
        foreach ($xpath->query("//ul[@data-testid='cc-settings-menu']/li/a") as $link) {
            $labels[] = $this->labelOf($xpath, $link);
            $hrefs[] = $link->getAttribute('href');
        }

        $this->assertSame(
            ['Quản lý thẻ', 'Đơn vị tiền', 'Chính sách hoàn tiền', 'Danh mục'],
            $labels
        );
        $this->assertSame([
            route('credit-cards.manage'),
            route('credit-cards.settings'),
            route('credit-cards.policies'),
            route('credit-cards.categories'),
        ], $hrefs);
    }

    #[Test]
    public function settings_menu_is_closed_on_load_and_wired_for_accessibility(): void
    {
        $html = $this->getContent(route('credit-cards.index'));

        // Đóng mặc định: không mở khi tải trang.
        $this->assertStringContainsString('settingsOpen: false', $html);
        $this->assertStringContainsString('x-cloak', $html);
        $this->assertStringContainsString('x-show="settingsOpen"', $html);

        // Mở bằng CLICK (không phải hover-only) + thông tin trợ năng.
        $this->assertStringContainsString('aria-haspopup="true"', $html);
        $this->assertStringContainsString('aria-expanded', $html);
        $this->assertStringContainsString('@click.outside', $html);
        $this->assertStringContainsString('keydown.escape', $html);
    }

    #[Test]
    public function every_nav_link_is_a_real_route_not_a_placeholder(): void
    {
        $xpath = $this->xpath($this->getContent(route('credit-cards.index')));

        $links = $xpath->query("//nav[@aria-label='Menu module Thẻ tín dụng']//a");
        $this->assertGreaterThanOrEqual(9, $links->length, 'Nav phải có đủ 5 link chính + 4 link menu Cài đặt.');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');

            $this->assertNotSame('', $href, 'Nav không được có link rỗng.');
            $this->assertNotSame('#', $href, 'Nav không được có link "#".');
            $this->assertStringContainsString('/thetindung', $href);
        }
    }

    // =====================================================================
    // Active state theo từng trang
    // =====================================================================

    /**
     * @return array<string, array{0:string, 1:?string, 2:bool, 3:?string}>
     */
    public static function activePageProvider(): array
    {
        return [
            'tổng quan' => ['credit-cards.index', 'Tổng quan', false, null],
            'sao kê' => ['credit-cards.statements', 'Sao kê', false, null],
            'danh sách báo cáo' => ['credit-cards.reports', 'Báo cáo', false, null],
            'tạo báo cáo' => ['credit-cards.reports.create', 'Báo cáo', false, null],
            'so sánh / lựa chọn thẻ' => ['credit-cards.compare', 'Lựa chọn thẻ', false, null],
            'tra cứu' => ['credit-cards.lookup', 'Tra cứu', false, null],
            'tra cứu' => ['credit-cards.lookup', 'Tra cứu', false, null],
            'quản lý thẻ' => ['credit-cards.manage', null, true, 'Quản lý thẻ'],
            'đơn vị tiền' => ['credit-cards.settings', null, true, 'Đơn vị tiền'],
            'chính sách hoàn tiền' => ['credit-cards.policies', null, true, 'Chính sách hoàn tiền'],
            'danh mục chi tiêu' => ['credit-cards.categories', null, true, 'Danh mục'],
        ];
    }

    #[Test]
    #[DataProvider('activePageProvider')]
    public function the_correct_nav_item_is_active_for(
        string $routeName,
        ?string $expectedMainLabel,
        bool $settingsActive,
        ?string $expectedSubLabel
    ): void {
        $xpath = $this->xpath($this->getContent(route($routeName)));

        // Mục CHÍNH active: đúng 0 hoặc 1 tuỳ trang.
        $mainActive = [];
        foreach ($xpath->query($this->mainNavSelector()."/a[@aria-current='page']") as $link) {
            $mainActive[] = $this->labelOf($xpath, $link);
        }

        if ($expectedMainLabel === null) {
            $this->assertSame([], $mainActive, 'Trang con Cài đặt không được đánh dấu mục chính nào ngoài Cài đặt.');
        } else {
            $this->assertSame([$expectedMainLabel], $mainActive);
        }

        // Nút "Cài đặt" sáng khi đang ở nhóm Cài đặt.
        $trigger = $xpath->query("//*[@data-testid='cc-settings-trigger']")->item(0);
        $this->assertNotNull($trigger);

        if ($settingsActive) {
            $this->assertSame('true', $trigger->getAttribute('data-active'));
            $this->assertStringContainsString('bg-emerald-500', $trigger->getAttribute('class'));
        } else {
            $this->assertFalse($trigger->hasAttribute('data-active'));
        }

        // Đúng một mục con trong menu active (nếu là trang con Cài đặt).
        $subActive = [];
        foreach ($xpath->query("//ul[@data-testid='cc-settings-menu']/li/a[@aria-current='page']") as $link) {
            $subActive[] = $this->labelOf($xpath, $link);
        }

        if ($expectedSubLabel === null) {
            $this->assertSame([], $subActive);
        } else {
            $this->assertSame([$expectedSubLabel], $subActive);
        }
    }

    #[Test]
    public function transaction_history_marks_overview_active(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $xpath = $this->xpath($this->getContent(route('credit-cards.transactions', $card)));

        $mainActive = [];
        foreach ($xpath->query($this->mainNavSelector()."/a[@aria-current='page']") as $link) {
            $mainActive[] = $this->labelOf($xpath, $link);
        }

        $this->assertSame(['Tổng quan'], $mainActive);
    }

    // =====================================================================
    // Hồi quy: chức năng Cài đặt (đổi tên hiển thị thành "Đơn vị tiền")
    // =====================================================================

    #[Test]
    public function settings_page_still_renders_money_unit_controls_under_new_title(): void
    {
        $response = $this->actingAs($this->owner)->get(route('credit-cards.settings'));

        $response->assertOk();
        $response->assertSee('Đơn vị tiền');
        $response->assertSee('Đồng');
        $response->assertSee('Nghìn đồng');
        // Route/URL không đổi.
        $response->assertSee(route('credit-cards.settings'), false);
    }

    /**
     * "Danh mục" trên menu trỏ tới trang quản lý danh mục chi tiêu (giữ nguyên chức năng).
     */
    #[Test]
    public function categories_menu_item_points_to_category_management_page(): void
    {
        $response = $this->actingAs($this->owner)->get(route('credit-cards.categories'));

        $response->assertOk();
        $response->assertSee('DANH MỤC HỆ THỐNG');
        $response->assertSee('DANH MỤC CỦA TÔI');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function getContent(string $url): string
    {
        return $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
    }

    private function mainNavSelector(): string
    {
        return "//nav[@aria-label='Menu module Thẻ tín dụng']/div[1]";
    }

    private function labelOf(DOMXPath $xpath, \DOMNode $item): string
    {
        $label = $xpath->query('./span[last()]', $item)->item(0);

        return trim($label->textContent);
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }
}
