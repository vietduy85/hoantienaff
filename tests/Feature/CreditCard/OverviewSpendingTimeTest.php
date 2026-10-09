<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Support\CreditCard\CreditCardMoneyFormatter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Tổng quan — tùy chọn "Thời gian chi tiêu" (§11).
 *
 * ---------------------------------------------------------------------------
 * BỐN BẤT BIẾN ĐƯỢC KHOÁ Ở ĐÂY
 * ---------------------------------------------------------------------------
 *   1. NGÀY: khoảng hiển thị là ĐÚNG kỳ sao kê hiện tại (đầu kỳ → hạn chót nếu
 *      thẻ có cấu hình, ngược lại là ngày cuối kỳ). Ranh giới lấy từ
 *      `StatementPeriodService`, KHÔNG vẽ lại công thức kỳ ở Blade/JS.
 *   2. SỐ NGÀY: đếm theo NGÀY LỊCH, không âm; hôm nay đúng hạn là "Hạn chót hôm
 *      nay", sau hạn là "Đã hết hạn chi tiêu" — mỗi câu một test.
 *   3. SỐ TIỀN: `max(0, mục tiêu − chi tiêu TRONG KỲ hiện tại)` tính trên VND
 *      THÔ (không lấy số đã floor của đơn vị nghìn), không âm, và không đụng
 *      hạn mức tín dụng / cashback / quota.
 *   4. TRÌNH BÀY: bật/tắt là tùy chọn client (Alpine + `localStorage`), nên khoá
 *      bằng cơ chế: mặc định bật, đúng một `x-show="showSection('spending_time')"`
 *      cho mỗi mảnh. Mở trang KHÔNG được sinh bản ghi kỳ/giao dịch.
 *
 * Các test ở đây chạy HTML server-render, đóng băng thời gian bằng `setTestNow`
 * nên khoảng ngày và số ngày là xác định.
 */
class OverviewSpendingTimeTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        CreditCardMoneyFormatter::flushAll();

        parent::tearDown();
    }

    // =====================================================================
    // §11.1 · Ô bật/tắt + cơ chế trạng thái
    // =====================================================================

    #[Test]
    public function the_display_strip_offers_the_spending_time_toggle(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('data-testid="overview-display-options"', $html);
        $this->assertStringContainsString('data-testid="toggle-spending-time"', $html);
        $this->assertStringContainsString('x-model="display.spending_time"', $html);
    }

    #[Test]
    public function the_toggle_is_a_client_preference_that_defaults_to_on(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->overviewHtml();

        // Khoá mới nằm trong danh sách mặc định ⇒ người dùng cũ (chưa có khoá này
        // trong `localStorage`) vẫn thấy thông tin, `init()` merge giữ mặc định bật.
        $this->assertStringContainsString(
            'spending_time: true',
            $html,
            'Khoá spending_time phải có trong ccDefaultDisplay() để mặc định BẬT.'
        );

        // Cả hai mảnh (khoảng ngày + hàng số ngày/số tiền) đều điều khiển bằng
        // ĐÚNG khoá của nó — đây là cơ chế ẩn/hiện thật, không chỉ là markup.
        $this->assertStringContainsString("showSection('spending_time')", $html);
    }

    // =====================================================================
    // §11.2 · Khoảng ngày: lấy từ kỳ, hạn chót cấu hình, clamp ngày cuối tháng
    // =====================================================================

    #[Test]
    public function the_range_covers_the_current_statement_period(): void
    {
        $this->atDate('2026-10-20');

        // statement_day = 15 ⇒ kỳ 15/10 → 14/11. Không cấu hình hạn chót ⇒
        // ngày kết thúc = ngày cuối kỳ.
        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('data-testid="card-spending-range"', $html);
        $this->assertStringContainsString('15/10 – 14/11', $this->visibleTextOf($html));
    }

    #[Test]
    public function the_range_ends_at_the_cards_configured_spending_deadline(): void
    {
        $this->atDate('2026-10-20');

        // Kỳ vẫn 15/10 → 14/11, nhưng hạn chót chi tiêu là ngày 10 ⇒ 10/11.
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 10,
        ]);

        $this->assertStringContainsString('15/10 – 10/11', $this->visibleTextOf($this->overviewHtml()));
    }

    #[Test]
    public function a_deadline_on_a_missing_day_is_clamped_to_the_month_end(): void
    {
        $this->atDate('2026-02-10');

        // anchor = 1 ⇒ kỳ 01/02 → 28/02 (2026 không nhuận). Hạn chót 31 không
        // được tràn sang 03/03 mà kẹp về 28/02.
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 1,
            'spending_deadline_day' => 31,
        ]);

        $this->assertStringContainsString('01/02 – 28/02', $this->visibleTextOf($this->overviewHtml()));
    }

    #[Test]
    public function opening_the_overview_creates_no_period_or_transaction(): void
    {
        $this->atDate('2026-10-20');

        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $this->overviewHtml();

        // Tính khoảng ngày là việc CHỈ ĐỌC: mở trang không được sinh kỳ/giao dịch.
        $this->assertSame(0, StatementPeriod::query()->where('user_card_id', $card->id)->count());
        $this->assertSame(0, Transaction::query()->where('user_card_id', $card->id)->count());
    }

    // =====================================================================
    // §11.3 · Số ngày: đếm lùi, câu chữ ở biên, không âm
    // =====================================================================

    #[Test]
    public function the_days_label_counts_down_to_the_period_end(): void
    {
        $this->atDate('2026-10-20');

        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        // 20/10 → 14/11 = 25 ngày.
        $this->assertStringContainsString('Còn 25 ngày', $this->visibleTextOf($this->overviewHtml()));
    }

    #[Test]
    public function the_days_label_uses_natural_wording_for_the_final_day(): void
    {
        $this->atDate('2026-11-13');

        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        // 13/11 → 14/11 = 1 ngày.
        $this->assertStringContainsString('Còn 1 ngày', $this->visibleTextOf($this->overviewHtml()));
    }

    #[Test]
    public function the_days_label_calls_out_the_deadline_day_itself(): void
    {
        $this->atDate('2026-11-01');

        // Kỳ 15/10 → 14/11; hạn chót ngày 1 ⇒ 01/11, đúng hôm nay.
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 1,
        ]);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('Hạn chót hôm nay', $this->visibleTextOf($html));
        $this->assertStringNotContainsString('Đã hết hạn chi tiêu', $this->visibleTextOf($html));
    }

    #[Test]
    public function the_days_label_goes_overdue_without_going_negative(): void
    {
        $this->atDate('2026-11-05');

        // Kỳ 15/10 → 14/11; hạn chót 01/11 đã qua ⇒ không hiện số âm.
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 1,
        ]);

        $text = $this->visibleTextOf($this->overviewHtml());

        $this->assertStringContainsString('Đã hết hạn chi tiêu', $text);
        $this->assertStringNotContainsString('Còn -', $text);
        $this->assertStringNotContainsString('Còn 0 ngày', $text);
    }

    // =====================================================================
    // §11.4 · Số tiền còn cần chi: mục tiêu − chi tiêu trong kỳ, VND thô
    // =====================================================================

    #[Test]
    public function remaining_is_the_goal_minus_the_current_period_spend(): void
    {
        $this->atDate('2026-10-20');

        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '10000000']);
        $this->spend($card, '2700000');

        $this->assertStringContainsString(
            'Còn cần chi 7.300.000 đ',
            $this->visibleTextOf($this->overviewHtml()),
        );
    }

    #[Test]
    public function remaining_only_counts_spending_inside_the_current_period(): void
    {
        $this->atDate('2026-10-20');

        // Kỳ hiện tại 15/10 → 14/11; kỳ trước 15/09 → 14/10.
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '10000000']);
        $this->spend($card, '2000000', '2026-10-20');
        $this->spend($card, '5000000', '2026-09-20');

        // Chỉ 2.000.000 thuộc kỳ hiện tại ⇒ còn cần chi 8.000.000, KHÔNG trừ khoản
        // 5.000.000 của kỳ trước.
        $this->assertStringContainsString(
            'Còn cần chi 8.000.000 đ',
            $this->visibleTextOf($this->overviewHtml()),
        );
    }

    #[Test]
    public function remaining_is_computed_on_raw_vnd_even_under_the_thousand_unit(): void
    {
        $this->atDate('2026-10-20');

        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '10000000']);
        // 2.700.499 hiển thị nghìn là "2.700"; nếu remaining lấy từ SỐ ĐÃ HIỆN thì
        // sẽ ra 7.300.000 → "7.300 nghìn". Đúng phải là 10.000.000 − 2.700.499 =
        // 7.299.501 → "7.299 nghìn".
        $this->spend($card, '2700499');

        $this->switchToThousandUnit();

        $expected = CreditCardMoneyFormatter::money('7299501', (int) $this->owner->id);
        $this->assertStringContainsString(
            'Còn cần chi '.$this->visibleTextOf($expected),
            $this->visibleTextOf($this->overviewHtml()),
        );
        $this->assertStringNotContainsString('Còn cần chi 7.300 nghìn', $this->visibleTextOf($this->overviewHtml()));
    }

    #[Test]
    public function a_reached_goal_reads_as_met_not_a_negative_number(): void
    {
        $this->atDate('2026-10-20');

        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '5000000']);
        $this->spend($card, '5000000');

        $this->assertStringContainsString('Đã đạt mục tiêu', $this->visibleTextOf($this->overviewHtml()));
    }

    #[Test]
    public function an_exceeded_goal_never_shows_a_negative_amount(): void
    {
        $this->atDate('2026-10-20');

        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '5000000']);
        $this->spend($card, '8000000');

        $text = $this->visibleTextOf($this->overviewHtml());

        $this->assertStringContainsString('Đã đạt mục tiêu', $text);
        $this->assertStringNotContainsString('Còn cần chi -', $text);
    }

    #[Test]
    public function a_card_without_a_goal_says_so_instead_of_showing_a_number(): void
    {
        $this->atDate('2026-10-20');

        $this->makeUserCard($this->owner->id, ['desired_spend' => null]);

        $html = $this->overviewHtml();

        // Hàng "Thời gian chi tiêu" vẫn hiện (nằm NGOÀI khối thanh tiến độ), chỉ
        // thay con số bằng lời nhắc đặt mục tiêu.
        $this->assertStringContainsString('data-testid="card-spending-time-summary"', $html);
        $this->assertStringContainsString('Chưa đặt mục tiêu', $this->visibleTextOf($html));
    }

    #[Test]
    public function an_overdue_unmet_goal_says_short_instead_of_inviting_more_spend(): void
    {
        $this->atDate('2026-11-05');

        // Hạn chót 01/11 đã qua ⇒ không mời "cần chi" trong kỳ đã khép lại, mà
        // nói thẳng số còn thiếu.
        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 1,
            'desired_spend' => '10000000',
        ]);
        $this->spend($card, '2700000');

        $text = $this->visibleTextOf($this->overviewHtml());

        $this->assertStringContainsString('Còn thiếu 7.300.000 đ', $text);
        $this->assertStringNotContainsString('Còn cần chi', $text);
    }

    // =====================================================================
    // §11.5 · Màu sắc & cỡ chữ
    // =====================================================================

    #[Test]
    public function the_summary_line_uses_the_required_size_and_weight(): void
    {
        $this->atDate('2026-10-20');

        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $tag = $this->tagForTestId($this->overviewHtml(), 'card-spending-time-summary');

        // 14px (`text-sm`) + weight medium, đúng yêu cầu kích thước.
        $this->assertMatchesRegularExpression('/\btext-sm\b/', $tag);
        $this->assertMatchesRegularExpression('/\bfont-medium\b/', $tag);

        // An toàn ở khung hẹp (375/390px): được phép xuống dòng (`flex-wrap`) và co
        // lại (`min-w-0`) nên không đẩy trang tràn ngang.
        $this->assertMatchesRegularExpression('/\bflex-wrap\b/', $tag);
        $this->assertMatchesRegularExpression('/\bmin-w-0\b/', $tag);
    }

    #[Test]
    public function the_countdown_reads_in_dark_blue(): void
    {
        $this->atDate('2026-10-20');

        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $tag = $this->tagForTestId($this->overviewHtml(), 'card-spending-days');

        $this->assertMatchesRegularExpression('/\btext-blue-700\b/', $tag);
        $this->assertDoesNotMatchRegularExpression('/\btext-gray-500\b/', $tag);
    }

    #[Test]
    public function the_deadline_day_reads_in_amber(): void
    {
        $this->atDate('2026-11-01');

        // Hạn chót 01/11 đúng hôm nay ⇒ màu trạng thái HỔ PHÁCH, không xanh dương.
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 1,
        ]);

        $tag = $this->tagForTestId($this->overviewHtml(), 'card-spending-days');

        $this->assertMatchesRegularExpression('/\btext-amber-600\b/', $tag);
    }

    #[Test]
    public function an_overdue_day_reads_in_red(): void
    {
        $this->atDate('2026-11-05');

        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 1,
        ]);

        $tag = $this->tagForTestId($this->overviewHtml(), 'card-spending-days');

        $this->assertMatchesRegularExpression('/\btext-red-600\b/', $tag);
    }

    #[Test]
    public function the_remaining_amount_is_emerald_while_spending_is_needed(): void
    {
        $this->atDate('2026-10-20');

        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '10000000']);
        $this->spend($card, '2000000');

        $tag = $this->tagForTestId($this->overviewHtml(), 'card-spending-remaining');

        $this->assertMatchesRegularExpression('/\btext-emerald-600\b/', $tag);
    }

    #[Test]
    public function a_reached_goal_reads_in_purple_not_blue(): void
    {
        $this->atDate('2026-10-20');

        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '5000000']);
        $this->spend($card, '5000000');

        $tag = $this->tagForTestId($this->overviewHtml(), 'card-spending-remaining');

        $this->assertMatchesRegularExpression('/\btext-purple-600\b/', $tag);
        $this->assertDoesNotMatchRegularExpression('/\btext-blue-700\b/', $tag);
    }

    #[Test]
    public function an_overdue_shortfall_reads_in_red(): void
    {
        $this->atDate('2026-11-05');

        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'spending_deadline_day' => 1,
            'desired_spend' => '10000000',
        ]);
        $this->spend($card, '2700000');

        $tag = $this->tagForTestId($this->overviewHtml(), 'card-spending-remaining');

        $this->assertMatchesRegularExpression('/\btext-red-600\b/', $tag);
    }

    // =====================================================================
    // Fixture helpers
    // =====================================================================

    private function overviewHtml(): string
    {
        return $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
    }

    /** Thẻ MỞ của phần tử mang `data-testid` — để soi class mà không soi cả trang. */
    private function tagForTestId(string $html, string $testId): string
    {
        preg_match(
            '/<(?:span|div)\b[^>]*data-testid="'.preg_quote($testId, '/').'"[^>]*>/',
            $html,
            $match,
        );

        return $match[0] ?? '';
    }

    /**
     * Giao dịch đi qua service thật — để gắn đúng kỳ theo NGÀY giao dịch.
     *
     * `$date` mặc định là hôm nay (đã đóng băng). Truyền ngày ở kỳ trước để chứng
     * minh số liệu Tổng quan chỉ tính giao dịch của kỳ hiện tại.
     */
    private function spend(UserCard $card, string $amount, ?string $date = null): void
    {
        $category = $this->makeSystemCategory();

        app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $date ?? CarbonImmutable::now()->toDateString(),
            'amount' => $amount,
            'category_id' => $category->id,
        ]);
    }

    private function switchToThousandUnit(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [
                'money_unit' => CreditCardUserSetting::MONEY_UNIT_THOUSAND,
            ])
            ->assertOk();

        CreditCardMoneyFormatter::flushAll();
    }

    private function atDate(string $when): void
    {
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }

    /**
     * CHỮ NGƯỜI DÙNG THẬT SỰ THẤY — bóc thẻ rồi gộp khoảng trắng/NBSP.
     *
     * Số tiền nằm xen giữa các `<span>` nên assert trên markup thô sẽ bỏ sót đúng
     * lỗi cần bắt ("7.300.000 đ" tách hậu tố, "2.700 nghìn" vs "7.300 nghìn").
     */
    private function visibleTextOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
