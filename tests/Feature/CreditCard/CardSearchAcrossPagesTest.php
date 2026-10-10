<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use DOMDocument;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Ô tìm kiếm thẻ trên BA màn — Tổng quan, Quản lý thẻ, Sao kê.
 *
 * Mục tiêu không phải "thêm được một ô nhập", mà là ba màn dùng CÙNG một cách
 * tìm: cùng `ccNormalizeSearch` (bỏ dấu, `đ`→`d`, chữ thường), cùng chuỗi
 * `data-card-search` server in sẵn, cùng `x-show="cardMatches($el)"`, cùng empty
 * state "Không tìm thấy thẻ phù hợp". Người dùng gõ "the vp" phải ra cùng kết
 * quả dù đang ở trang nào.
 *
 * Các bất biến bị khoá ở đây:
 *   1. Lọc là CLIENT-SIDE trên danh sách server đã sắp: rỗng ⇒ hiện đủ, gõ ⇒ ẩn
 *      dòng không khớp. KHÔNG gọi mạng, KHÔNG đổi thứ tự, KHÔNG lọc ở server.
 *   2. `data-card-search` = tên thẻ + ngân hàng đã chuẩn hoá — nguồn sự thật để
 *      ba màn không tự nối chuỗi theo cách riêng.
 *   3. Ô nhập KHÔNG nằm trong form sắp xếp và KHÔNG có `name`: tìm kiếm không
 *      biến thành query string, không đụng cơ chế sort đang có.
 *   4. Marker dòng thẻ (`data-testid="card-row"` ở Tổng quan/Quản lý thẻ,
 *      `data-testid="statement-row"` + `data-card-id` ở Sao kê) GIỮ NGUYÊN để
 *      các test sắp xếp/statement đang đọc vẫn đúng.
 *   5. Mở trang Sao kê kèm tìm kiếm KHÔNG được ghi bất kỳ bản ghi kỳ nào.
 */
class CardSearchAcrossPagesTest extends TestCase
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
    // Quản lý thẻ (/thetindung/quan-ly-the)
    // =====================================================================

    #[Test]
    public function the_manage_page_offers_the_search_box_above_the_card_list_when_cards_exist(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->manageHtml();

        $this->assertStringContainsString('data-testid="card-search-input"', $html);
        $this->assertStringContainsString('x-model="cardQuery"', $html);
        $this->assertStringContainsString('placeholder="Tìm tên thẻ hoặc ngân hàng..."', $html);

        // Ô nhập phải nằm TRƯỚC danh sách thẻ để đọc theo thứ tự trên xuống.
        $this->assertLessThan(
            strpos($html, 'x-ref="cardList"'),
            strpos($html, 'data-testid="card-search-input"'),
        );
    }

    #[Test]
    public function the_manage_page_hides_the_search_box_when_there_are_no_cards(): void
    {
        $html = $this->manageHtml();

        $this->assertStringNotContainsString('data-testid="card-search-input"', $html);
        $this->assertStringContainsString('Chưa có thẻ nào', $html);
    }

    #[Test]
    public function a_manage_card_row_exposes_normalized_diacritic_free_search_text(): void
    {
        $bank = $this->makeBank(['name' => 'Ngân Hàng Á Châu', 'slug' => 'ngan-hang-a-chau']);
        $card = $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ Đặc Biệt',
            'bank_id' => $bank->id,
        ]);

        $html = $this->manageHtml();

        // "Thẻ Đặc Biệt" + "Ngân Hàng Á Châu" ⇒ bỏ dấu, chữ thường, gộp space.
        $this->assertStringContainsString(
            'data-card-search="the dac biet ngan hang a chau"',
            $html,
        );

        // Marker dòng thẻ + bài test sắp xếp (`openEdit(id)`) không bị phá.
        $this->assertMatchesRegularExpression(
            '/data-testid="card-row"\s+data-card-search="[^"]*"/',
            $html,
        );
        $this->assertStringContainsString('openEdit('.$card->id.')', $html);
    }

    #[Test]
    public function the_manage_empty_state_only_appears_while_filtering(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->manageHtml();

        $this->assertStringContainsString('data-testid="card-search-empty"', $html);
        $this->assertStringContainsString('Không tìm thấy thẻ phù hợp', $html);
        $this->assertStringContainsString("cardQuery.trim() !== ''", $html);
        $this->assertStringContainsString('cardMatchCount === 0', $html);
    }

    #[Test]
    public function the_manage_search_is_driven_by_the_shared_client_side_helpers(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->manageHtml();
        $script = $this->scriptOf($html);

        $this->assertStringContainsString('function ccNormalizeSearch(value)', $script);
        $this->assertStringContainsString('function ccCardSearchMatchCount(component, testId)', $script);
        $this->assertStringContainsString("cardQuery: ''", $script);
        $this->assertStringContainsString('x-ref="cardList"', $html);
        $this->assertStringContainsString('x-show="cardMatches($el)"', $html);
        $this->assertStringContainsString("ccCardSearchMatchCount(this, 'card-row')", $html);
    }

    #[Test]
    public function the_manage_search_input_is_not_part_of_the_sort_form(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->manageHtml();
        $dom = $this->domOf($html);
        $xpath = new DOMXPath($dom);

        $inputs = $xpath->query('//input[@data-testid="card-search-input"]');
        $this->assertSame(1, $inputs->length);

        /** @var DOMNode $input */
        $input = $inputs->item(0);

        // Không nằm trong bất kỳ <form> nào ⇒ không bị submit kèm tham số sắp xếp.
        $this->assertSame('input', strtolower($input->nodeName));
        $this->assertFalse($input->hasAttribute('name'), 'Ô tìm kiếm không được có name để tránh thành query string.');

        for ($node = $input->parentNode; $node !== null; $node = $node->parentNode) {
            $this->assertNotSame('form', strtolower($node->nodeName));
        }

        // Còn cơ chế sắp xếp thật vẫn nằm trong GET form (đối chứng).
        $sorts = $xpath->query('//select[@name="sort"]');
        $this->assertSame(1, $sorts->length);
    }

    #[Test]
    public function the_manage_rows_keep_their_edit_buttons_and_closed_badges(): void
    {
        $open = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ đang dùng']);
        $closed = $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ đã đóng',
            'status' => UserCard::STATUS_INACTIVE,
        ]);

        $html = $this->manageHtml();

        // Đúng một nút Sửa cho mỗi thẻ, id chuẩn (cũng là marker test sắp xếp).
        preg_match_all('/openEdit\((\d+)\)/', $html, $matches);
        $this->assertEqualsCanonicalizing(
            [$open->id, $closed->id],
            array_map('intval', $matches[1]),
        );

        // Thẻ không ở trạng thái active vẫn giữ nhãn "Đã đóng" ngay trong dòng.
        $closedRow = $this->rowOf($html, 'data-testid="card-row"', $closed->name);
        $this->assertStringContainsString('Đã đóng', $this->visibleTextOf($closedRow));
    }

    #[Test]
    public function the_search_never_filters_rows_server_side(): void
    {
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ Một']);
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ Hai']);

        // Từ khoá trên query string KHÔNG được lọc ở server: luôn render đủ 2 thẻ.
        // Đếm bằng regex khớp ĐÚNG dòng thẻ (marker + thuộc tính thật), không đếm
        // chuỗi trần — chuỗi đó còn xuất hiện trong chú thích/JS của partial.
        $this->assertSame(2, $this->countManageRows($this->manageHtml()));
        $this->assertSame(2, $this->countManageRows($this->manageHtml(['q' => 'khong-ton-tai'])));

        $this->assertSame(2, $this->countStatementRows($this->statementsHtml()));
        $this->assertSame(2, $this->countStatementRows($this->statementsHtml(['q' => 'khong-ton-tai'])));
    }

    // =====================================================================
    // Sao kê (/thetindung/sao-ke)
    // =====================================================================

    #[Test]
    public function the_statements_page_offers_the_search_box_when_rows_exist(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->statementsHtml();

        $this->assertStringContainsString('data-testid="card-search-input"', $html);
        $this->assertStringContainsString('x-model="cardQuery"', $html);
        $this->assertStringContainsString('placeholder="Tìm tên thẻ hoặc ngân hàng..."', $html);
    }

    #[Test]
    public function the_statements_page_hides_the_search_box_when_there_are_no_cards(): void
    {
        $html = $this->statementsHtml();

        $this->assertStringNotContainsString('data-testid="card-search-input"', $html);
        $this->assertStringContainsString('Bạn chưa có thẻ tín dụng nào để lấy sao kê.', $html);
    }

    #[Test]
    public function a_statement_row_exposes_normalized_search_text_and_keeps_the_marker_order(): void
    {
        $bank = $this->makeBank(['name' => 'Ngân Hàng Á Châu', 'slug' => 'ngan-hang-a-chau']);
        $first = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ Đặc Biệt', 'bank_id' => $bank->id]);
        $second = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ Thường']);

        $html = $this->statementsHtml();

        // `data-testid="statement-row"` PHẢI dính liền `data-card-id` — regex mà
        // `CardSortAcrossPagesTest` đọc không được đổi. Thuộc tính tìm kiếm thêm SAU.
        preg_match_all(
            '/data-testid="statement-row"\s+data-card-id="(\d+)"\s+data-card-search="([^"]*)"/',
            $html,
            $matches,
        );

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_map('intval', $matches[1]),
        );
        $this->assertContains('the dac biet ngan hang a chau', $matches[2]);
    }

    #[Test]
    public function the_statements_empty_state_only_appears_while_filtering(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->statementsHtml();

        $this->assertStringContainsString('data-testid="card-search-empty"', $html);
        $this->assertStringContainsString('Không tìm thấy thẻ phù hợp', $html);
        $this->assertStringContainsString("cardQuery.trim() !== ''", $html);
        $this->assertStringContainsString('cardMatchCount === 0', $html);
    }

    #[Test]
    public function the_statements_search_is_driven_by_the_shared_client_side_helpers(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->statementsHtml();
        $script = $this->scriptOf($html);

        $this->assertStringContainsString('function ccNormalizeSearch(value)', $script);
        $this->assertStringContainsString('function ccCardSearchMatchCount(component, testId)', $script);
        $this->assertStringContainsString("cardQuery: ''", $script);
        $this->assertStringContainsString('x-ref="cardList"', $html);
        $this->assertStringContainsString('x-show="cardMatches($el)"', $html);
        $this->assertStringContainsString("ccCardSearchMatchCount(this, 'statement-row')", $html);
    }

    #[Test]
    public function the_statements_search_leaves_the_action_controls_untouched(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ có sao kê']);

        $html = $this->statementsHtml();

        // Các điều khiển hành động của dòng vẫn còn nguyên sau khi thêm thuộc
        // tính tìm kiếm: chọn kỳ, trạng thái thanh toán, nút sửa.
        $this->assertStringContainsString('data-testid="statement-period-form-'.$card->id.'"', $html);
        $this->assertStringContainsString('data-testid="payment-controls-'.$card->id.'"', $html);
        $this->assertStringContainsString('data-testid="statement-edit-'.$card->id.'"', $html);
    }

    #[Test]
    public function opening_statements_with_search_still_creates_no_statement_period(): void
    {
        $this->makeUserCard($this->owner->id);

        $this->assertSame(0, StatementPeriod::query()->count());

        $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['q' => 'the']))
            ->assertOk();

        // Mở trang vẫn chỉ ĐỌC: ô tìm kiếm không kéo theo việc tạo kỳ nào.
        $this->assertSame(0, StatementPeriod::query()->count());
    }

    // =====================================================================
    // Tổng quan (/thetindung) — hồi quy
    // =====================================================================

    #[Test]
    public function the_overview_search_box_still_uses_the_shared_helpers(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->overviewHtml();
        $script = $this->scriptOf($html);

        $this->assertStringContainsString('data-testid="card-search-input"', $html);
        $this->assertStringContainsString('x-ref="cardList"', $html);
        $this->assertStringContainsString('function ccNormalizeSearch(value)', $script);
        $this->assertStringContainsString("ccCardSearchMatchCount(this, 'card-row')", $html);
    }

    #[Test]
    public function the_overview_row_marker_order_is_preserved(): void
    {
        $first = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A']);
        $second = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);

        $html = $this->overviewHtml();

        preg_match_all('/data-testid="card-row"\s+data-card-id="(\d+)"/', $html, $matches);
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_map('intval', $matches[1]),
        );
    }

    // =====================================================================
    // Xuyên trang
    // =====================================================================

    #[Test]
    public function the_shared_search_helper_is_rendered_exactly_once_per_page(): void
    {
        $this->makeUserCard($this->owner->id);

        foreach ([$this->overviewHtml(), $this->manageHtml(), $this->statementsHtml()] as $html) {
            $this->assertSame(1, substr_count($html, 'function ccNormalizeSearch(value)'));
            $this->assertSame(1, substr_count($html, 'function ccCardSearchMatchCount(component, testId)'));
            $this->assertSame(1, substr_count($html, 'x-model="cardQuery"'));
        }
    }

    #[Test]
    public function the_search_keyword_never_becomes_a_query_string_or_a_form_field(): void
    {
        $this->makeUserCard($this->owner->id);

        foreach ([$this->manageHtml(), $this->statementsHtml()] as $html) {
            $this->assertStringNotContainsString('name="cardQuery"', $html);
            $this->assertStringNotContainsString('name="q"', $html);

            $dom = $this->domOf($html);
            $xpath = new DOMXPath($dom);
            $input = $xpath->query('//input[@data-testid="card-search-input"]')->item(0);

            $this->assertNotNull($input);
            $this->assertFalse($input->hasAttribute('name'));
            $this->assertSame('cardQuery', $input->getAttribute('x-model'));
        }
    }

    #[Test]
    public function the_search_state_is_never_persisted_across_pages(): void
    {
        $this->makeUserCard($this->owner->id);

        // Từ khoá tìm kiếm sống trong component Alpine của TỪNG màn, không lưu ra
        // localStorage: Quản lý thẻ và Sao kê không dùng khoá hiển thị của Tổng
        // quan, và không có khoá lưu riêng cho tìm kiếm.
        $manage = $this->manageHtml();
        $statements = $this->statementsHtml();

        $this->assertStringNotContainsString('cc.overview.display', $manage);
        $this->assertStringNotContainsString('cc.overview.display', $statements);
        $this->assertStringNotContainsString('ccSearchStorageKey', $manage);
        $this->assertStringNotContainsString('ccSearchStorageKey', $statements);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @param  array<string, string>  $query
     */
    private function manageHtml(array $query = []): string
    {
        return $this->actingAs($this->owner)
            ->get(route('credit-cards.manage', $query))
            ->assertOk()
            ->getContent();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function statementsHtml(array $query = []): string
    {
        return $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', $query))
            ->assertOk()
            ->getContent();
    }

    private function overviewHtml(): string
    {
        return $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
    }

    private function countManageRows(string $html): int
    {
        return preg_match_all('/data-testid="card-row"\s+data-card-search="[^"]*"/', $html, $matches);
    }

    private function countStatementRows(string $html): int
    {
        return preg_match_all('/data-testid="statement-row"\s+data-card-id="\d+"/', $html, $matches);
    }

    private function domOf(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return $dom;
    }

    /**
     * Đoạn HTML của dòng chứa `marker` và kết thúc ở thẻ đóng `</li>` — đủ để
     * khẳng định "nhãn này nằm trong dòng thẻ kia".
     */
    private function rowOf(string $html, string $marker, string $label): string
    {
        $offset = 0;

        while (($position = strpos($html, $marker, $offset)) !== false) {
            $end = strpos($html, '</li>', $position);

            if ($end === false) {
                break;
            }

            $row = substr($html, $position, $end - $position);

            if (str_contains($this->visibleTextOf($row), $label)) {
                return $row;
            }

            $offset = $position + strlen($marker);
        }

        $this->fail("Không tìm thấy dòng thẻ nào mang nhãn {$label}.");
    }

    /**
     * CHỮ NGƯỜI DÙNG THẬT SỰ THẤY trong một đoạn HTML.
     */
    private function visibleTextOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Nội dung mọi thẻ `<script>` trong trang, nối lại.
     */
    private function scriptOf(string $html): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches);

        return implode("\n", $matches[1] ?? []);
    }
}
