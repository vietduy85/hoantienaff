<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardOverviewService;
use App\Support\CreditCard\Decimal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Tổng quan — hai ô TỔNG (Tổng chi tiêu, Cashback dự kiến).
 *
 * ---------------------------------------------------------------------------
 * NGUYÊN TẮC ĐANG KHOÁ
 * ---------------------------------------------------------------------------
 * Mỗi thẻ có `statement_day` riêng nên mỗi thẻ một kỳ sao kê hiện tại. Hai ô
 * tổng phải CỘNG ĐÚNG KỲ HIỆN TẠI CỦA TỪNG THẺ:
 *
 *   • KHÔNG phải tháng dương lịch.
 *   • KHÔNG phải một kỳ chung cho cả module.
 *   • KHÔNG phải tổng toàn bộ lịch sử giao dịch.
 *
 * `Cashback dự kiến` cộng `expected_cashback` của từng thẻ, mỗi thẻ giữ nguyên
 * logic của nó (bậc đích theo `desired_spend`, rate của bậc đó, cap của bậc đó) —
 * KHÔNG phải `tổng chi tiêu × một rate chung`, vì rate thuộc về từng thẻ.
 *
 * Vì các kỳ hiện tại được chọn BẰNG NGÀY (`period_start ≤ hôm nay ≤ period_end`),
 * chuyển kỳ xảy ra tự động theo thời điểm hiện tại, không cần người dùng chọn kỳ.
 *
 * ---------------------------------------------------------------------------
 * VỀ SAO CÓ TEST BẢNG SỐ CHIỀU RỘNG
 * ---------------------------------------------------------------------------
 * Không có JS test runner trong project, nên không đo được bề rộng thật trong
 * trình duyệt. Thay vào đó test khoá lại HỢP ĐỒNG của CSS: `nowrap`, không cắt
 * chữ, và công thức `clamp()` chia cho bề rộng chuỗi dài nhất — rồi tự tính bảng
 * đó ở đúng các viewport mà yêu cầu nêu. Đổi padding/lớp mà không cập nhật số
 * 7.9941 (hay đổi công thức) sẽ làm test này đỏ.
 */
class OverviewSummaryTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    /**
     * Ngày "hôm nay" của các test. Khoá cứng để test không đổi kết quả theo
     * ngày chạy thật — 04/10/2026 nằm trong kỳ của cả ba thẻ bên dưới.
     */
    private const TODAY = '2026-10-04 10:00:00';

    /** Ngày sau khi Sacom (chốt 05) đã sang kỳ mới. */
    private const AFTER_SACOM_ROLLOVER = '2026-10-06 10:00:00';

    /**
     * Bề rộng của chuỗi tiền DÀI NHẤT mà formatter của module in được, tính bằng
     * advance width thật của Inter Bold (unitsPerEm 2048) cộng `tracking-tight`
     * = −0.025em: 10 chữ số × 0.6743 + 3 dấu chấm × 0.3340 + &nbsp; × 0.2368
     * + đ × 0.6304 − 15 × 0.025 = 7.9941em. Phải khớp số trong `app.css`.
     */
    private const LONGEST_MONEY_EM = 7.9941;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();

        $this->atDate(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // =====================================================================
    // §1 · Tổng chi tiêu = tổng kỳ hiện tại của từng thẻ
    // =====================================================================

    #[Test]
    public function total_spend_adds_up_the_current_period_of_every_card(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        // Ba thẻ, BA kỳ hiện tại khác nhau — đúng ví dụ trong yêu cầu.
        $sacom = $this->cardWithPolicy('Sacom', $shopee, 5, 10.0, '5000000');
        $mb = $this->cardWithPolicy('MB', $shopee, 8, 5.0, '5000000');
        $msb = $this->cardWithPolicy('MSB', $shopee, 20, 5.0, '5000000');

        $this->period($sacom, '2026-09-06', '2026-10-05');
        $this->period($mb, '2026-09-09', '2026-10-08');
        $this->period($msb, '2026-09-21', '2026-10-20');

        $this->spendIn($sacom, $shopee, '2026-09-06', '2026-10-05', '5000000.00');
        $this->spendIn($mb, $shopee, '2026-09-09', '2026-10-08', '3000000.00');
        // MSB có kỳ hiện tại nhưng chưa chi gì → 0, vẫn phải được cộng (không lỗi).

        // 5.000.000 + 3.000.000 + 0
        $this->assertSame('8000000.00', $this->summaryNumber('total_spend'));
    }

    #[Test]
    public function total_spend_ignores_the_periods_that_are_not_current(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $card = $this->cardWithPolicy('Sacom', $shopee, 5, 10.0, '5000000');

        $current = $this->period($card, '2026-09-06', '2026-10-05');
        $this->period($card, '2026-06-06', '2026-07-05');
        $this->period($card, '2026-07-06', '2026-08-05');

        $this->spendIn($card, $shopee, '2026-09-06', '2026-10-05', '5000000.00');

        // Lịch sử có sẵn ở hai kỳ cũ — không được lọt vào tổng.
        $this->addTransaction($card, $shopee, '2026-06-20', '9000000.00', $this->period($card, '2026-06-06', '2026-07-05'));
        $this->addTransaction($card, $shopee, '2026-07-20', '7000000.00', $this->period($card, '2026-07-06', '2026-08-05'));

        $this->assertSame('5000000.00', $this->summaryNumber('total_spend'));

        // Chứng minh tổng lấy đúng kỳ đang mở quanh hôm nay, không phải kỳ mới
        // nhất theo ngày tạo. Đúng tiêu chí mà service dùng:
        // `open` và `period_start <= hôm nay <= period_end`.
        $today = CarbonImmutable::parse(self::TODAY)->toDateString();
        $this->assertTrue($current->isOpen());
        $this->assertTrue($current->period_start->toDateString() <= $today);
        $this->assertTrue($current->period_end->toDateString() >= $today);
    }

    #[Test]
    public function total_spend_is_not_a_shared_period_or_a_calendar_month(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        // Cùng `statement_day` ⇒ cùng kỳ, để so sánh với "tháng dương lịch".
        $first = $this->cardWithPolicy('MB 1', $shopee, 8, 5.0, '5000000');
        $second = $this->cardWithPolicy('MB 2', $shopee, 8, 5.0, '5000000');

        $this->period($first, '2026-09-09', '2026-10-08');
        $this->period($second, '2026-09-09', '2026-10-08');

        // Một giao dịch TRONG tháng 10 nhưng NGOÀI kỳ (sau 08/10) — tháng dương
        // lịch sẽ tính, kỳ sao kê thì không.
        $this->spendIn($first, $shopee, '2026-09-09', '2026-10-08', '4000000.00');
        $this->addTransaction(
            $second,
            $shopee,
            '2026-10-09',
            '3000000.00',
            $this->period($second, '2026-10-09', '2026-11-08')
        );

        $this->assertSame('4000000.00', $this->summaryNumber('total_spend'));
    }

    // =====================================================================
    // §3 · Cashback dự kiến = tổng của từng thẻ, giữ logic của từng thẻ
    // =====================================================================

    #[Test]
    public function expected_cashback_sums_each_card_s_own_target_tier_and_rate(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        // Rate khác nhau để phân biệt "cộng từng thẻ" với "tổng × một rate chung".
        $sacom = $this->cardWithPolicy('Sacom', $shopee, 5, 10.0, '5000000');
        $mb = $this->cardWithPolicy('MB', $shopee, 8, 5.0, '5000000');

        $this->period($sacom, '2026-09-06', '2026-10-05');
        $this->period($mb, '2026-09-09', '2026-10-08');

        $this->spendIn($sacom, $shopee, '2026-09-06', '2026-10-05', '5000000.00');
        $this->spendIn($mb, $shopee, '2026-09-09', '2026-10-08', '3000000.00');

        // 5.000.000 × 10% + 3.000.000 × 5% = 500.000 + 150.000.
        //
        // Nếu ai đó nhân TỔNG chi tiêu với một rate chung sẽ ra 800.000 (10%)
        // hoặc 400.000 (5%) — đều khác, nên con số này bắt được lỗi đó.
        $this->assertSame('650000.00', $this->summaryNumber('expected_cashback'));
    }

    // =====================================================================
    // §10 · Chuyển kỳ tự động theo ngày, từng thẻ một kỳ
    // =====================================================================

    #[Test]
    public function a_card_moves_to_its_next_period_on_its_own_statement_day(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        $sacom = $this->cardWithPolicy('Sacom', $shopee, 5, 10.0, '5000000');
        $mb = $this->cardWithPolicy('MB', $shopee, 8, 5.0, '5000000');

        // Sacom chốt 05: kỳ cũ 06/09–05/10, kỳ mới 06/10–05/11.
        $sacomOld = $this->period($sacom, '2026-09-06', '2026-10-05');
        $sacomNew = $this->period($sacom, '2026-10-06', '2026-11-05');

        // MB chốt 08: kỳ của nó phủ CẢ 04/10 lẫn 06/10 nên không đổi.
        $mbPeriod = $this->period($mb, '2026-09-09', '2026-10-08');

        $this->spendIn($sacom, $shopee, '2026-09-06', '2026-10-05', '5000000.00');
        $this->spendIn($sacom, $shopee, '2026-10-06', '2026-11-05', '1000000.00');
        $this->spendIn($mb, $shopee, '2026-09-09', '2026-10-08', '3000000.00');

        // ── Trước ngày chốt: lấy kỳ CŨ của Sacom ──
        $this->atDate(self::TODAY);
        $this->assertSame('8000000.00', $this->summaryNumber('total_spend'));
        // 5.000.000 × 10% + 3.000.000 × 5% = 650.000 — KHÔNG phải 8.000.000 × 10%.
        $this->assertSame('650000.00', $this->summaryNumber('expected_cashback'));

        // ── Sang 06/10: Sacom tự sang kỳ mới, MB giữ nguyên ──
        $this->atDate(self::AFTER_SACOM_ROLLOVER);
        $this->assertSame(
            '4000000.00',
            $this->summaryNumber('total_spend'),
            'Sacom phải lấy kỳ mới (1.000.000) + MB giữ kỳ cũ (3.000.000); giao dịch kỳ cũ của Sacom không được cộng lại.'
        );
        $this->assertSame('250000.00', $this->summaryNumber('expected_cashback'));

        // Bằng chứng là bộ lọc theo ngày, không phải cờ trạng thái: hai kỳ của
        // Sacom đều `open`, chỉ khoảng ngày quyết định.
        $this->assertTrue($sacomOld->isOpen());
        $this->assertTrue($sacomNew->isOpen());
        $this->assertTrue($mbPeriod->isOpen());
    }

    #[Test]
    public function a_card_without_a_current_period_contributes_nothing(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        $withPeriod = $this->cardWithPolicy('MB', $shopee, 8, 5.0, '5000000');
        $stale = $this->cardWithPolicy('Cũ', $shopee, 8, 5.0, '5000000');

        $this->period($withPeriod, '2026-09-09', '2026-10-08');
        $this->spendIn($withPeriod, $shopee, '2026-09-09', '2026-10-08', '3000000.00');

        // Kỳ đã kết thúc từ lâu: không phải kỳ hiện tại ⇒ không cộng.
        $this->spendIn($stale, $shopee, '2026-01-09', '2026-02-08', '9000000.00');

        $this->assertSame('3000000.00', $this->summaryNumber('total_spend'));
        $this->assertSame('150000.00', $this->summaryNumber('expected_cashback'));
    }

    // =====================================================================
    // §2/§4 · Hai dòng mô tả
    // =====================================================================

    #[Test]
    public function both_total_tiles_explain_they_follow_each_card_s_own_period(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $card = $this->cardWithPolicy('Sacom', $shopee, 5, 10.0, '5000000');
        $this->period($card, '2026-09-06', '2026-10-05');
        $this->spendIn($card, $shopee, '2026-09-06', '2026-10-05', '5000000.00');

        $html = $this->overviewHtml();

        // Khoá đúng VÙNG hai ô tổng. Quét cả trang sẽ bắt nhầm chữ ở nơi khác trên
        // trang — ví dụ option "Đến hạn thanh toán sớm nhất" của ô Sắp xếp thẻ,
        // không liên quan gì tới kỳ nào dưới ô tổng.
        $tiles = $this->overviewSummaryTiles($html);

        $this->assertSame(
            2,
            substr_count($tiles, 'Theo kỳ sao kê hiện tại của từng thẻ'),
            'Cả "Tổng chi tiêu" và "Cashback dự kiến" phải mang cùng một dòng mô tả.'
        );

        // KHÔNG còn khoảng ngày đơn lẻ nào dưới hai ô tổng: một khoảng duy nhất
        // chỉ đúng với thẻ đầu tiên nên sẽ sai với các thẻ còn lại.
        $this->assertStringNotContainsString('Kỳ 06/09', $tiles);
        $this->assertStringNotContainsString('Chốt 05/10', $tiles);
        $this->assertStringNotContainsString('Đến hạn', $tiles);

        // Tiêu đề giữ nguyên, không đổi thành "Tổng chi tiêu hiện tại".
        $this->assertStringContainsString('💸 Tổng chi tiêu', $html);
        $this->assertStringNotContainsString('Tổng chi tiêu hiện tại', $html);
        $this->assertStringContainsString('🎁 Cashback dự kiến', $html);
    }

    #[Test]
    public function the_tiles_never_print_a_single_period_range(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        // Hai thẻ lệch kỳ nhau — nếu view còn in khoảng ngày thì sẽ chỉ ra kỳ của
        // thẻ đầu tiên, tức mâu thuẫn ngay trong trang.
        $sacom = $this->cardWithPolicy('Sacom', $shopee, 5, 10.0, '5000000');
        $mb = $this->cardWithPolicy('MB', $shopee, 8, 5.0, '5000000');
        $this->period($sacom, '2026-09-06', '2026-10-05');
        $this->period($mb, '2026-09-09', '2026-10-08');
        $this->spendIn($sacom, $shopee, '2026-09-06', '2026-10-05', '5000000.00');
        $this->spendIn($mb, $shopee, '2026-09-09', '2026-10-08', '3000000.00');

        $html = $this->overviewHtml();

        foreach (['06/09', '05/10/2026', '09/09', '08/10/2026'] as $range) {
            $this->assertStringNotContainsString($range, $html);
        }
    }

    // =====================================================================
    // §5–§8 · Số tiền không được xuống dòng, cỡ chữ co theo ô
    // =====================================================================

    #[Test]
    public function every_total_amount_is_marked_never_to_wrap(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $card = $this->cardWithPolicy('Sacom', $shopee, 5, 10.0, '5000000');
        $this->period($card, '2026-09-06', '2026-10-05');
        $this->spendIn($card, $shopee, '2026-09-06', '2026-10-05', '5000000.00');

        $html = $this->overviewHtml();

        foreach (['stat-total-cards', 'stat-total-limit', 'stat-total-spend', 'stat-expected-cashback'] as $testId) {
            $markup = $this->markupOfTestId($html, $testId);

            $this->assertStringContainsString('cc-stat-amount', $markup);

            // KHÔNG được cắt chữ để "giấu" phần thừa: số bị cắt dở thì đọc sai
            // hậu tố, tệ hơn cả khi số nhỏ đi một chút.
            foreach (['break-words', 'truncate', 'text-ellipsis', 'overflow-hidden'] as $banned) {
                $this->assertStringNotContainsString($banned, $markup);
            }

            $this->assertStringNotContainsString('text-overflow', $markup);
            $this->assertStringNotContainsString('style=', $markup);
        }
    }

    #[Test]
    public function the_amount_stylesheet_forbids_wrapping_and_bans_every_way_of_hiding_the_number(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        // Những thứ BẮT BUỘC có.
        $this->assertStringContainsString('white-space: nowrap', $css);
        $this->assertStringContainsString('font-variant-numeric: tabular-nums', $css);
        $this->assertStringContainsString('container-type: inline-size', $css);
        $this->assertStringContainsString('calc((100cqw - 3px) / '.self::LONGEST_MONEY_EM.')', $css);

        // Những cách "giải quyết" sai bị cấm trong chính rule của số tiền.
        $amountRules = $this->cssRulesFor($css, '.cc-stat-amount');

        foreach (['text-overflow', 'overflow: hidden', 'overflow:hidden', 'white-space: normal'] as $banned) {
            $this->assertStringNotContainsString($banned, $amountRules);
        }
    }

    /**
     * Bảng số: cỡ chữ mà công thức trong CSS tạo ra phải vừa chuỗi tiền DÀI NHẤT
     * ở đúng các viewport mà yêu cầu nêu, đồng thời vẫn nổi bật.
     *
     * @param  array{0: int, 1: float, 2: float, 3: float}  $case
     *                                                             [viewport px, bề rộng content của ô, cỡ chữ nhỏ nhất chấp nhận, cỡ chữ lớn nhất chấp nhận]
     */
    #[Test]
    #[DataProvider('viewportCases')]
    public function the_longest_money_string_fits_on_one_line_at_every_viewport(
        int $viewport,
        float $tileContentWidth,
        float $minFontPx,
        float $maxFontPx,
    ): void {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));
        [$min, $max, $buffer, $perEm] = $this->clampValues($css);

        // clamp(min, calc((100cqw - buffer) / perEm), max)
        $fontPx = min($max, max($min, ($tileContentWidth - $buffer) / $perEm));
        $rendered = $fontPx * $perEm;

        $this->assertLessThanOrEqual(
            $tileContentWidth,
            $rendered,
            "Viewport {$viewport}px: số dài nhất vượt khỏi ô (cỡ chữ {$fontPx}px → {$rendered}px > {$tileContentWidth}px)."
        );

        $this->assertGreaterThanOrEqual($minFontPx, $fontPx, "Viewport {$viewport}px: số bị thu nhỏ quá nhiều.");
        $this->assertLessThanOrEqual($maxFontPx, $fontPx, "Viewport {$viewport}px: số to quá mức cần thiết.");

        // Ô phải rộng hơn số một chút, không sát mép hoàn toàn.
        $this->assertGreaterThan(2.0, $tileContentWidth - $rendered, "Viewport {$viewport}px: không còn lề an toàn.");
    }

    /**
     * @return array<string, array{0: int, 1: float, 2: float, 3: float}>
     */
    public static function viewportCases(): array
    {
        return [
            // [viewport, content width of one tile, min font px, max font px]
            //
            // Bề rộng ô suy ra từ layout, mỗi hằng số gắn với đúng lớp CSS:
            //   mobile  (<640px): page `px-4` 16 + grid `gap-3` 12 + ô `p-4` 16×2
            //                     ⇒ (vw − 32 − 12)/2 − 32 = vw/2 − 54
            //   tablet  (≥640px): page `sm:px-6` 24 + `sm:gap-4` 16 + `p-4` 16×2
            //                     ⇒ (vw − 48 − 16)/2 − 32 = vw/2 − 64
            //   desktop (≥1024px): `max-w-7xl` 1280 + `lg:px-8` 32×2
            //                     + sidebar 260 + `lg:gap-6` 24 + `lg:gap-4` 16
            //                     + `p-4` 16×2 ⇒ min(1280, vw)/2 − 214
            'iPhone SE 320px' => [320, 320 / 2 - 54, 12.0, 15.0],
            'Android 360px' => [360, 360 / 2 - 54, 14.0, 17.0],
            'iPhone 375px' => [375, 375 / 2 - 54, 15.0, 18.0],
            'iPhone 390px' => [390, 390 / 2 - 54, 15.0, 20.0],
            'iPhone 430px' => [430, 430 / 2 - 54, 17.0, 21.0],
            'tablet 640px' => [640, 640 / 2 - 64, 28.0, 30.0],
            'desktop 1280px' => [1280, 1280 / 2 - 214, 28.0, 30.0],
        ];
    }

    #[Test]
    public function the_money_format_keeps_one_space_and_one_suffix(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $card = $this->cardWithPolicy('Sacom', $shopee, 5, 10.0, '5000000');
        $this->period($card, '2026-09-06', '2026-10-05');
        $this->spendIn($card, $shopee, '2026-09-06', '2026-10-05', '5000000.00');

        $html = $this->overviewHtml();

        // Đủ khoảng trắng trước "đ", không dính, không lặp.
        $this->assertStringContainsString('5.000.000&nbsp;', $html);
        $this->assertStringNotContainsString('5.000.000 đ đ', $html);
        $this->assertStringNotContainsString('5.000.000 đ</span>', $html);
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /** [min px, max px, buffer px, em per full container width] đọc từ chính app.css. */
    private function clampValues(string $css): array
    {
        $this->assertSame(
            1,
            preg_match('/clamp\(\s*([\d.]+)px\s*,\s*calc\(\(100cqw\s*-\s*([\d.]+)px\)\s*\/\s*([\d.]+)\)\s*,\s*([\d.]+)px\s*\)/', $css, $m),
            'Không tìm thấy công thức clamp() của số tiền trong resources/css/app.css.'
        );

        return [(float) $m[1], (float) $m[4], (float) $m[2], (float) $m[3]];
    }

    /** Toàn bộ khối CSS khai báo cho một selector (để soi lỗi trong đúng rule đó). */
    private function cssRulesFor(string $css, string $selector): string
    {
        preg_match_all('/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/', $css, $m);

        return implode("\n", $m[1]);
    }

    /**
     * Thẻ đã có policy 1 bậc, 1 rule với rate cho trước và mục tiêu đủ để
     * `expected_cashback` resolve được bậc đích.
     *
     * `$statementCloseDay` là ngày CHỐT mà các test vẫn kể ("Sacom chốt 05"),
     * còn model lưu `statement_day` = anchor = ngày MỞ kỳ — kỳ "chốt 05" là
     * 06/09–05/10 ⇒ anchor 06. Kỳ fixture trong file này đều mở ở close + 1.
     */
    private function cardWithPolicy(
        string $name,
        $category,
        int $statementCloseDay,
        float $rate,
        string $desiredSpend,
    ): UserCard {
        $card = $this->makeUserCard($this->owner->id, [
            'name' => $name,
            'statement_day' => $statementCloseDay + 1,
            'payment_due_day' => $statementCloseDay + 10,
            'desired_spend' => $desiredSpend,
        ]);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => 1000000]],
            [['category_id' => $category->id, 'percent' => number_format($rate, 3, '.', '')]],
        );

        return $card;
    }

    /** Kỳ `[$start, $end]` của thẻ, tạo nếu chưa có. */
    private function period(UserCard $card, string $start, string $end): StatementPeriod
    {
        return $this->periodFor($card, $start, $end);
    }

    /** Một giao dịch trong kỳ `[$start, $end]` của thẻ (tạo kỳ nếu chưa có). */
    private function spendIn(UserCard $card, $category, string $start, string $end, string $amount): void
    {
        $this->addTransaction($card, $category, $start, $amount, $this->periodFor($card, $start, $end));
    }

    private function addTransaction(
        UserCard $card,
        $category,
        string $date,
        string $amount,
        StatementPeriod $period,
    ): Transaction {
        return $period->transactions()->create([
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'transaction_date' => $date,
            'statement_period_id' => $period->id,
            'is_eligible' => true,
        ]);
    }

    /**
     * Kỳ theo đúng khoảng ngày, tạo nếu chưa có.
     *
     * Phải so NGÀY chứ không so chuỗi: cột lưu datetime nên `2026-09-06` không
     * bằng `2026-09-06 00:00:00` — `firstOrCreate` theo chuỗi sẽ không thấy kỳ
     * đã có rồi cố insert lại và đụng UNIQUE(user_card_id, period_start, period_end).
     * Service cũng lọc bằng `whereDate`, nên test dùng đúng cách đó.
     */
    private function periodFor(UserCard $card, string $start, string $end): StatementPeriod
    {
        $period = StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->first();

        if ($period instanceof StatementPeriod) {
            return $period;
        }

        return $this->makeStatementPeriod($card, [
            'period_start' => $start,
            'period_end' => $end,
            'statement_date' => $end,
            'payment_due_date' => CarbonImmutable::parse($end)->addDays(10)->toDateString(),
        ]);
    }

    /**
     * Giá trị một chỉ số trong `summary`, đọc thẳng từ service để test về SỐ.
     *
     * Đọc service thay vì bóc HTML: hai ô tổng còn một bản render sau khi Alpine
     * chạy, và test này cần khẳng định giá trị số chứ không phải bố cục.
     */
    private function summaryNumber(string $key): string
    {
        $summary = app(CreditCardOverviewService::class)
            ->forPage($this->owner->id)['summary'];

        return Decimal::money($summary[$key]);
    }

    private function overviewHtml(): string
    {
        return $this->actingAs($this->owner)
            ->get(route('credit-cards.index'))
            ->assertOk()
            ->getContent();
    }

    /** Bóc markup của phần tử có `data-testid`, kể cả khi thuộc tính nằm dòng riêng. */
    private function markupOfTestId(string $html, string $testId): string
    {
        $position = strpos($html, 'data-testid="'.$testId.'"');

        $this->assertNotFalse($position, "Không tìm thấy data-testid={$testId}.");

        $open = strrpos(substr($html, 0, (int) $position), '<');
        $this->assertNotFalse($open);

        $close = strpos($html, '>', (int) $position);
        $this->assertNotFalse($close);

        return substr($html, (int) $open, $close - (int) $open + 1);
    }

    /**
 * Markup bên trong vùng ô tổng (`data-testid="overview-summary"`).
 *
 * Tách riêng để các kiểm tra "dưới ô tổng không được in khoảng ngày của riêng
 * một thẻ" chỉ soi đúng chỗ đó, thay vì quét cả trang rồi bắt nhầm chữ ở menu,
 * bộ lọc hay danh sách thẻ.
 */
private function overviewSummaryTiles(string $html): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $node = $xpath->query("//*[@data-testid='overview-summary']")->item(0);
    $this->assertNotNull($node, 'Không tìm thấy vùng ô tổng trên trang Tổng quan.');

    // `saveHTML($node)` chứ không nối `.//*`: nối từng phần tử con sẽ in lại phần
    // chữ bên trong, làm mọi phép đếm chuỗi nhân đôi.
    return (string) $dom->saveHTML($node);
}

private function atDate(string $when): void
    {
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }
}
