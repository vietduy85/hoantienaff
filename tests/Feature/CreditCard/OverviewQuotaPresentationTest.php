<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\CategoryComboItem;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Tổng quan — lớp TRÌNH BÀY (Phase 3).
 *
 * ---------------------------------------------------------------------------
 * BA BẤT BIẾN ĐƯỢC KHOÁ Ở ĐÂY
 * ---------------------------------------------------------------------------
 *   1. Thanh tiến độ CHỈ dùng số server đã tính, và Vạch-Min-Spend đọc thẳng từ
 *      policy RIÊNG của thẻ (`currentPolicy.min_total_spend`) chứ không đọc
 *      `calculation_meta` của kỳ. Cả ngưỡng Min lẫn mức chi tiêu mong muốn đều
 *      so trên `spent` — đúng số thanh đang đo và dòng "Chi tiêu" in ra — nên
 *      người dùng đối chiếu được bằng mắt: thanh chưa tới vạch đỏ thì CAM.
 *   2. Quota ĐỌC NGUYÊN VẸN output của Phase 2 (`CashbackQuotaService`), kể cả
 *      quy ước "không có trần riêng ⇒ bỏ phần `/ max` thay vì bịa số".
 *   3. Blade/JavaScript KHÔNG có công thức quota nào: vị trí vạch đỏ và bề rộng
 *      thanh đều lấy từ phần trăm server gửi xuống, không nhân/chia lại ở client.
 *
 * Các test ở đây kiểm tra HTML server-render. Việc bật/tắt ô "Hiển thị" là hành
 * vi Alpine phía client (project không có JS test runner), nên phần đó được khoá
 * bằng: mặc định bật đúng 3/3, và mỗi phần đúng một `x-show` trỏ tới khoá của
 * nó — tức cơ chế quyết định ẩn/hiện chứ không chỉ vẻ đẹp.
 */
class OverviewQuotaPresentationTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
    }

    // =====================================================================
    // §19.1–2 · Thanh tiến độ chỉ hiện khi có mục tiêu
    // =====================================================================

    #[Test]
    public function a_card_with_a_desired_spend_renders_a_progress_bar(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('data-testid="card-progress-track"', $html);
        $this->assertStringContainsString('data-testid="card-progress-bar"', $html);
    }

    #[Test]
    public function a_card_without_a_desired_spend_renders_no_progress_bar_and_no_placeholder(): void
    {
        $this->makeUserCard($this->owner->id, ['desired_spend' => null]);

        $html = $this->overviewHtml();

        // KHÔNG suy diễn chiều dài thanh, và cũng không dựng ô "chưa đặt mục tiêu".
        $this->assertStringNotContainsString('data-testid="card-progress-track"', $html);
        $this->assertStringNotContainsString('data-testid="card-progress-bar"', $html);
    }

    // =====================================================================
    // §19.3–4 · Vạch đỏ của mức chi tiêu tối thiểu
    // =====================================================================

    #[Test]
    public function the_minimum_marker_comes_from_the_cards_own_policy(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $this->setCardMinimumSpend($card, '3000000');

        $this->makeOpenPeriod($card, ['total_eligible_spend' => '8000000.00']);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('data-testid="card-minimum-marker"', $html);
        // Vạch đứng đúng tỉ lệ 3.000.000 / 15.000.000 = 20%.
        $this->assertMatchesRegularExpression(
            '/data-testid="card-minimum-marker"[^>]*style="left: 20%/',
            $html
        );
    }

    #[Test]
    public function no_minimum_marker_when_the_policy_has_no_minimum_threshold(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $this->setCardMinimumSpend($card, '0');

        $this->makeOpenPeriod($card, ['total_eligible_spend' => '8000000.00']);

        $html = $this->overviewHtml();

        // Thanh vẫn có (có mục tiêu), nhưng không vẽ vạch đỏ.
        $this->assertStringContainsString('data-testid="card-progress-track"', $html);
        $this->assertStringNotContainsString('data-testid="card-minimum-marker"', $html);

        // Không có vạch Min ⇒ không dùng Min để đổi màu: thanh xanh bình thường.
        $this->assertTrue($this->metricsOf($html)[(string) $card->id]['meets_minimum']);
    }

    #[Test]
    public function the_minimum_marker_survives_a_period_that_was_never_calculated(): void
    {
        // Vạch Min đọc policy của thẻ chứ không đọc `calculation_meta` của kỳ, nên
        // kỳ CHƯA BAO GIỜ được engine tính thì vẫn phải hiện vạch — cũng đúng lúc đó
        // `calculation_meta` còn rỗng, tức là mọi thứ Tổng quan đọc đều nằm trên thẻ.
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $this->setCardMinimumSpend($card, '3000000');

        $this->makeOpenPeriod($card, ['calculation_meta' => null]);

        $this->assertStringContainsString('data-testid="card-minimum-marker"', $this->overviewHtml());
    }

    #[Test]
    public function the_minimum_marker_never_borrows_the_engines_snapshot_threshold(): void
    {
        // Snapshot của kỳ vẫn còn ghi 4.000.000, nhưng policy RIÊNG của thẻ đã bị
        // sửa về 0. Tổng quan phải theo policy của thẻ (0 ⇒ không vạch, không đổi
        // màu) chứ không vẽ vạch theo snapshot cũ.
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $this->setCardMinimumSpend($card, '0');

        $this->makeOpenPeriod($card, [
            'total_eligible_spend' => '8000000.00',
            'calculation_meta' => ['min_total_spend' => 4000000.0],
        ]);

        $html = $this->overviewHtml();
        $metrics = $this->metricsOf($html)[(string) $card->id];

        $this->assertStringNotContainsString('data-testid="card-minimum-marker"', $html);
        $this->assertFalse($metrics['has_minimum']);
        $this->assertTrue($metrics['meets_minimum']);
    }

    // =====================================================================
    // §19.5–6 · Màu thanh theo Vạch-Min-Spend lưu trong policy của thẻ
    // =====================================================================

    #[Test]
    public function the_bar_is_amber_when_actual_spend_is_below_the_minimum(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();

        $this->setCardMinimumSpend($card, '3000000');
        $this->spend($card, $category, '2000000');

        $html = $this->overviewHtml();
        $metrics = $this->metricsOf($html)[(string) $card->id];

        $this->assertSame('2000000.00', $metrics['spent']);
        $this->assertFalse($metrics['meets_minimum']);
        $this->assertStringContainsString("'bg-amber-500'", $html);
    }

    #[Test]
    public function the_bar_is_green_once_actual_spend_reaches_the_minimum(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();

        $this->setCardMinimumSpend($card, '3000000');
        $this->spend($card, $category, '8000000');

        $html = $this->overviewHtml();
        $metrics = $this->metricsOf($html)[(string) $card->id];

        $this->assertTrue($metrics['meets_minimum']);
        $this->assertStringContainsString("'bg-emerald-500'", $html);
    }

    #[Test]
    public function the_bar_colour_follows_the_spend_that_is_drawn_on_the_bar(): void
    {
        // 2.000.000đ chi trên thanh, Vạch-Min-Spend 3.000.000 ⇒ thanh CAM. Màu
        // phải đọc đúng số đang được vẽ, nếu đọ số khác (ví dụ số engine ghi) thì
        // thanh vẫn tụt quá vạch đỏ mà lại đổi màu — người dùng không tin được.
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();

        $this->setCardMinimumSpend($card, '3000000');
        $this->spend($card, $category, '2000000');

        $html = $this->overviewHtml();
        $metrics = $this->metricsOf($html)[(string) $card->id];

        $this->assertSame('2000000.00', $metrics['spent']);
        // 2.000.000 / 15.000.000 = 13.33% ⇒ thanh mới dừng ở đây, trước vạch đỏ.
        $this->assertSame('13.33', $metrics['progress_percent']);
        $this->assertSame('20.00', $metrics['minimum_percent']);
        $this->assertFalse($metrics['meets_minimum']);
    }

    // =====================================================================
    // §19.7 · Vượt mục tiêu không được tràn ngang
    // =====================================================================

    #[Test]
    public function overspending_never_widens_the_bar_past_the_track(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '10000000']);
        $category = $this->makeSystemCategory();

        $this->spend($card, $category, '25000000');

        $html = $this->overviewHtml();

        // Vạch đạt 250% nhưng thanh kẹp 100% — không `width` nào vượt 100%.
        $this->assertStringContainsString('data-testid="card-progress-overflow"', $html);

        preg_match_all('/style="width: ([\d.]+)%"/', $html, $matches);
        $this->assertNotEmpty($matches[1], 'Không tìm thấy style width của thanh tiến độ.');

        $widths = array_map(static fn (string $w): float => (float) $w, $matches[1]);

        foreach ($widths as $width) {
            $this->assertLessThanOrEqual(100.0, $width, 'Thanh tiến độ bị kéo dài quá ô chứa.');
        }

        $this->assertContains(100.0, $widths, 'Vượt mục tiêu thì thanh phải đầy đúng 100%.');
    }

    #[Test]
    public function overspending_says_so_in_words(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '10000000']);
        $this->spend($card, $this->makeSystemCategory(), '25000000');

        $this->assertStringContainsString('Đã vượt mục tiêu', $this->overviewHtml());
    }

    // =====================================================================
    // §19.8–11 · Ba ô "Hiển thị"
    // =====================================================================

    #[Test]
    public function the_display_strip_offers_three_independent_options(): void
    {
        $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('data-testid="overview-display-options"', $html);

        foreach (['toggle-spend' => 'display.spend', 'toggle-cashback' => 'display.cashback', 'toggle-quota' => 'display.quota'] as $testId => $model) {
            $this->assertMatchesRegularExpression(
                '/data-testid="'.$testId.'"/',
                $html,
                'Thiếu ô hiển thị '.$testId
            );
            $this->assertStringContainsString('x-model="'.$model.'"', $html);
        }
    }

    #[Test]
    public function every_display_option_is_on_by_default(): void
    {
        $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);

        $html = $this->overviewHtml();

        foreach (['toggle-spend', 'toggle-cashback', 'toggle-quota'] as $testId) {
            $this->assertMatchesRegularExpression(
                '/data-testid="'.$testId.'"[^>]*\schecked/',
                $html,
                'Ô '.$testId.' phải mặc định BẬT'
            );
        }

        // Và phần tương ứng thực sự có trong HTML (không bị ẩn sẵn ở server).
        foreach (['card-spend-row', 'card-cashback-row'] as $testId) {
            $this->assertStringContainsString('data-testid="'.$testId.'"', $html);
        }
    }

    #[Test]
    public function each_display_option_governs_exactly_its_own_section(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000', 'quota' => true]]
        );

        $html = $this->overviewHtml();

        // Mỗi phần bị buộc bởi ĐÚNG khoá của nó — bỏ tick một ô không đụng phần
        // khác, nên ba lựa chọn thực sự độc lập chứ không phải ba cái chung.
        foreach (['spend' => 'card-spend-row', 'cashback' => 'card-cashback-row', 'quota' => 'card-quota-section'] as $key => $testId) {
            $this->assertSame(
                1,
                preg_match('/<(\w+)[^>]*data-testid="'.$testId.'"/', $html, $tag),
                'Thiếu phần '.$testId
            );

            $this->assertStringContainsString(
                "x-show=\"showSection('".$key."')\"",
                $tag[0],
                'Phần '.$testId.' phải do khoá showSection(\''.$key.'\') điều khiển'
            );
        }

        // Cả ba khoá đều là khoá độc lập trong `display`.
        foreach (['spend', 'cashback', 'quota'] as $key) {
            $this->assertStringContainsString($key.': ', $html);
        }
    }

    #[Test]
    public function the_display_choice_survives_a_page_change_via_local_storage(): void
    {
        $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);

        $html = $this->overviewHtml();

        // Không cần backend ⇒ không có cột DB, không có migration, không gọi API.
        $this->assertStringContainsString("ccDisplayStorageKey = 'cc.overview.display'", $html);
        $this->assertStringContainsString('window.localStorage.setItem(ccDisplayStorageKey', $html);
        $this->assertStringContainsString('window.localStorage.getItem(ccDisplayStorageKey)', $html);
        // Áp lại lựa chọn đã lưu khi component khởi tạo.
        $this->assertStringContainsString('ccReadStoredDisplay()', $html);
    }

    // =====================================================================
    // §19.12–13 · Bậc đích theo `desired_spend`
    // =====================================================================

    #[Test]
    public function the_cashback_ceiling_comes_from_the_tier_picked_by_desired_spend(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc thấp', 'min' => 0, 'max' => 10000000, 'cap_period' => '100000.00'],
                ['name' => 'Bậc cao', 'min' => 10000000, 'max' => null, 'cap_period' => '900000.00'],
            ],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        // Chi tiêu thực rất nhỏ — nếu chọn bậc theo chi tiêu thì ra "Bậc thấp".
        $period = $this->makeOpenPeriod($card, ['total_cashback' => '0.00']);
        $this->createTransaction($card, $category, '500000', $period);

        $html = $this->overviewHtml();

        // Bậc đích = bậc của `desired_spend` 15.000.000 ⇒ trần 900.000.
        $this->assertStringContainsString('900.000', $html);
        $this->assertStringNotContainsString('/ 100.000', $html);
    }

    #[Test]
    public function a_card_without_a_tier_ceiling_shows_no_denominator(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $html = $this->overviewHtml();
        $metrics = $this->metricsOf($html)[(string) $card->id];

        $this->assertFalse($metrics['quota']['has_tier_cashback_max']);
        $this->assertNull($metrics['quota']['tier_cashback_max']);

        // Bậc không đặt trần ⇒ dòng cashback chỉ còn số đã đạt, KHÔNG bịa mẫu số.
        $this->assertStringContainsString('Cashback dự kiến :', $html);
        $this->assertSame(
            0,
            preg_match('/Cashback dự kiến\s*:.*?<span class="text-gray-500 tabular-nums"/s', $html),
            'Không được vẽ dấu "/" mẫu số khi bậc không có trần.'
        );
    }

    // =====================================================================
    // §19.14–15 · Chỉ rule đã tick quota
    // =====================================================================

    #[Test]
    public function only_quota_categories_reach_the_overview(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [
                ['category_id' => $shopee->id, 'percent' => '5.000', 'quota' => true],
                // Danh mục thường — KHÔNG tick quota ⇒ không hiện.
                ['category_id' => $food->id, 'percent' => '3.000', 'quota' => false],
            ]
        );

        $html = $this->overviewHtml();

        $this->assertStringContainsString('Shopee', $html);
        $this->assertStringNotContainsString('Ăn uống', $html);
        $this->assertSame(1, substr_count($html, 'data-testid="card-quota-row"'));
    }

    #[Test]
    public function a_fallback_rule_never_reaches_the_overview(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            []
        );

        // Rule danh mục (tick quota) + rule fallback `scope_type = other`.
        $this->addRule($card, 0, [
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'category_id' => $shopee->id,
            'cashback_percent' => '5.000',
            'is_quota_category' => true,
        ]);
        $this->addRule($card, 0, [
            'scope_type' => PolicyTierCategory::SCOPE_OTHER,
            'category_id' => null,
            'cashback_percent' => '2.000',
        ]);

        $html = $this->overviewHtml();

        // Rule fallback không có danh mục cụ thể: không được sinh dòng quota,
        // cũng không hiện tỷ lệ 2% ở bất cứ đâu trên Tổng quan.
        $this->assertStringNotContainsString('2,00', $html);
        $this->assertSame(1, substr_count($html, 'data-testid="card-quota-row"'));
    }

    // =====================================================================
    // §19.16–17 · Định dạng dòng quota
    // =====================================================================

    #[Test]
    public function a_capped_quota_category_shows_used_over_max_and_the_spend_estimate(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        // Trần riêng 300.000đ nhỏ hơn trần chung 500.000đ ⇒ cái nhỏ hơn thắng.
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '500000.00']],
            [['category_id' => $shopee->id, 'percent' => '5.000', 'cap_cat' => '300000.00', 'quota' => true]]
        );

        $html = $this->overviewHtml();
        $rule = $this->metricsOf($html)[(string) $card->id]['quota']['rules'][0];

        $this->assertSame('0.00', $rule['cashback_used']);
        $this->assertSame('300000.00', $rule['cashback_max']);
        // 300.000đ hoàn / 5% ⇒ cần chi 6.000.000đ.
        $this->assertSame('6000000.00', $rule['spend_remaining_estimate']);

        $this->assertStringContainsString('Shopee', $html);
        $this->assertStringContainsString('300.000', $html);
        $this->assertStringContainsString('6.000.000', $html);
    }

    #[Test]
    public function an_uncapped_quota_category_never_shows_a_missing_max_as_a_number(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        // Không có trần riêng, nhưng bậc vẫn có trần chung 500.000đ.
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '500000.00']],
            [['category_id' => $shopee->id, 'percent' => '5.000', 'cap_cat' => null, 'quota' => true]]
        );

        $html = $this->overviewHtml();
        $metrics = $this->metricsOf($html)[(string) $card->id];
        $rule = $metrics['quota']['rules'][0];
        $row = $this->quotaRowOf($html);

        $this->assertNull($rule['cashback_max']);
        $this->assertFalse($rule['has_cashback_max']);

        // Vì không có trần riêng nên ngân sách chung vẫn còn hiệu lực: số tiền
        // chi thêm là CON SỐ chứ không phải null.
        $this->assertSame('500000.00', $rule['cashback_available_for_rule']);
        $this->assertSame('10000000.00', $rule['spend_remaining_estimate']);

        // Ô trống phải sạch: không "???", không chữ "null", và KHÔNG được bịa
        // trần riêng từ trần chung của bậc.
        // (Chỉ quét riêng dòng quota — payload Alpine chứa null ở nhiều chỗ vốn vậy.)
        $this->assertStringNotContainsString('???', $row);
        $this->assertStringNotContainsString('null', $row);

        // Trần chung KHÔNG BAO GIỜ được in theo kiểu trần riêng ("/ 500.000").
        $this->assertDoesNotMatchRegularExpression('/\/\s*500\.000/', $row, 'Trần chung không được in thành trần riêng.');

        // ĐÃ CHỐT — nhánh C (không trần riêng): `[đã dùng] → Có thể chi thêm ~[x]`.
        // KHÔNG còn "· còn X đ" (ngân sách chung in chung với dòng riêng làm user
        // tưởng đó là hạn mức của danh mục) và KHÔNG in trần chung ra dòng quota.
        $this->assertStringNotContainsString('· còn', $row);
        $this->assertStringNotContainsString('500.000', $row, 'Trần chung của bậc không được xuất hiện trên dòng quota.');
        $this->assertStringContainsString('Có thể chi thêm ~10.000.000', $this->visibleTextOf($row));

        // Toàn trang cũng không được còn dấu vết nhánh cũ.
        $this->assertStringNotContainsString('· còn', $html);
        $this->assertStringNotContainsString('card-quota-available', $html);
    }

    #[Test]
    public function a_quota_line_says_in_words_how_much_more_to_spend(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '500000.00']],
            [['category_id' => $shopee->id, 'percent' => '5.000', 'cap_cat' => '300000.00', 'quota' => true]]
        );

        $row = $this->quotaRowOf($this->overviewHtml());

        // "→ +2.000.000đ" là ký hiệu kỹ thuật, user không hiểu là phải chi bao
        // nhiêu. Dòng phải nói thẳng "cần chi thêm khoảng bao nhiêu".
        $this->assertStringContainsString('Có thể chi thêm', $row);
        $this->assertStringContainsString('~', $row, 'Số tiền ước tính phải có dấu "~" vì nó là ƯỚC TÍNH, không phải số chắc chắn.');
        $this->assertStringNotContainsString('→ +', $row);
        $this->assertStringContainsString('6.000.000', $row);

        // Dòng được phép xuống dòng trên màn 390px thay vì bị bóp nghẹt.
        preg_match(
            '/<li\b[^>]*data-testid="card-quota-row"/',
            $this->overviewHtml(),
            $liTag
        );

        $this->assertMatchesRegularExpression(
            '/flex flex-wrap items-baseline/',
            $liTag[0] ?? '',
            'Dòng quota phải wrap được trên màn hình hẹp.'
        );
    }

    #[Test]
    public function the_quota_section_sits_beside_the_cashback_section_not_inside_it(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000', 'quota' => true]]
        );

        $html = $this->overviewHtml();

        $cashbackAt = strpos($html, 'data-testid="card-cashback-row"');
        $quotaAt = strpos($html, 'data-testid="card-quota-section"');

        $this->assertIsInt($cashbackAt, 'Thiếu dòng cashback.');
        $this->assertIsInt($quotaAt, 'Thiếu phần quota.');

        // Thứ tự: tiền đã chi → cashback → quota.
        $this->assertLessThan($cashbackAt, strpos($html, 'data-testid="card-spend-row"'));
        $this->assertLessThan($quotaAt, $cashbackAt);

        // Ô cashback phải ĐÃ đóng trước khi ô quota mở ra. Nếu quota nằm bên
        // trong cashback thì bỏ tick "cashback" sẽ kéo mất quota ⇒ ba ô không
        // còn độc lập với nhau.
        $this->assertStringContainsString(
            '</div>',
            substr($html, $cashbackAt, $quotaAt - $cashbackAt),
            'Phần quota phải là anh em cùng cấp với dòng cashback, không lồng vào bên trong nó.'
        );
    }

    // =====================================================================
    // §19.18–19 · Combo và nhiều rule
    // =====================================================================

    #[Test]
    public function a_quota_combo_shows_the_combo_name(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $travel = $this->makeSystemCategory(['name' => 'Du lịch']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00']],
            []
        );

        $combo = CategoryCombo::create([
            'scope' => 'system',
            'name' => 'Ăn chơi tháng này',
            'slug' => 'an-choi-thang-nay',
            'is_active' => true,
        ]);

        CategoryComboItem::create(['combo_id' => $combo->id, 'category_id' => $food->id, 'sort_order' => 0]);
        CategoryComboItem::create(['combo_id' => $combo->id, 'category_id' => $travel->id, 'sort_order' => 1]);

        $this->addRule($card, 0, [
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'category_id' => null,
            'combo_id' => $combo->id,
            'cashback_percent' => '5.000',
            'max_cashback_per_category_per_period' => '400000.00',
            'is_quota_category' => true,
        ]);

        $html = $this->overviewHtml();

        // Combo là MỘT đơn vị tính tiền ⇒ một dòng, tên combo, không phải tên
        // từng danh mục thành viên.
        $this->assertStringContainsString('Ăn chơi tháng này', $html);
        $this->assertSame(1, substr_count($html, 'data-testid="card-quota-row"'));
        $this->assertStringNotContainsString('>Du lịch<', $html);

        $rule = $this->metricsOf($html)[(string) $card->id]['quota']['rules'][0];
        $this->assertSame('Ăn chơi tháng này', $rule['combo_name']);
        $this->assertSame([$food->id, $travel->id], $rule['scope_category_ids']);
    }

    #[Test]
    public function several_quota_rules_each_get_one_compact_line(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $travel = $this->makeSystemCategory(['name' => 'Du lịch']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00']],
            [
                ['category_id' => $shopee->id, 'percent' => '5.000', 'cap_cat' => '300000.00', 'quota' => true],
                ['category_id' => $food->id, 'percent' => '3.000', 'cap_cat' => '500000.00', 'quota' => true],
                ['category_id' => $travel->id, 'percent' => '2.000', 'cap_cat' => '200000.00', 'quota' => true],
            ]
        );

        $html = $this->overviewHtml();

        // Ba dòng, mỗi rule một dòng — không dựng ô vuông cho từng danh mục.
        $this->assertSame(3, substr_count($html, 'data-testid="card-quota-row"'));
        $this->assertSame(1, substr_count($html, 'data-testid="card-quota-section"'));
    }

    // =====================================================================
    // §19.20 · Không có rule quota thì không hiện mục rỗng
    // =====================================================================

    #[Test]
    public function a_card_with_no_quota_rule_shows_no_quota_heading_at_all(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000', 'quota' => false]]
        );

        $html = $this->overviewHtml();

        $this->assertStringNotContainsString('data-testid="card-quota-section"', $html);
        $this->assertStringNotContainsString('<p class="text-xs font-semibold text-gray-700 mb-0.5">Quota hoàn tiền</p>', $html);
    }

    #[Test]
    public function quota_is_a_faithful_mirror_of_the_cards_own_current_policy(): void
    {
        // REGRESSION — sự cố thật đã gặp trên dữ liệu thật.
        //
        // Người dùng tick ô quota trong TRÌNH SOẠN POLICY HỆ THỐNG (mẫu), nhưng
        // mỗi thẻ dùng bản SAO CHÉP riêng của nó. Bản sao được tạo ra ở một thời
        // điểm nào đó và không tự đồng bộ với mẫu, nên nếu tick mẫu SAU khi sao
        // chép thì bản sao vẫn còn 0 rule quota.
        //
        // Hai test này khoá lại CẢ HAI vế của hợp đồng:
        //  - vế có dữ liệu thì phải hiện  (`only_quota_categories_reach_the_overview`)
        //  - vế không có dữ liệu thì phải im, và KHÔNG được sinh tiêu đề rỗng.
        //
        // Nhờ vậy, lần sau gặp "quota không hiện" ta biết ngay tầng trình bày
        // vẫn đúng và phải đi kiểm tra policy của chính thẻ, chứ không mất công
        // sửa lại Blade.
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000', 'quota' => false]]
        );

        $html = $this->overviewHtml();

        // Policy ĐANG DÙNG của thẻ không có rule quota nào được tick.
        $this->assertSame([], $this->metricsOf($html)[(string) $card->id]['quota']['rules']);
        $this->assertStringNotContainsString('data-testid="card-quota-row"', $html);

        // Ô hiển thị "Quota hoàn tiền" vẫn còn BẬT (mặc định) — nghĩa là việc
        // không hiện KHÔNG phải do người dùng tắt, mà do đúng là không có dữ liệu.
        $this->assertMatchesRegularExpression(
            '/data-testid="toggle-quota"[^>]*\schecked/',
            $html
        );
    }

    // =====================================================================
    // §19.21 · Mobile: không tràn ngang
    // =====================================================================

    #[Test]
    public function the_card_row_cannot_push_the_page_sideways(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'name' => 'Một cái tên thẻ rất dài để kiểm tra việc cắt chữ trên điện thoại',
            'desired_spend' => '15000000',
        ]);

        $html = $this->overviewHtml();

        // Ô chứa tiền phải co lại được, và tên thẻ cắt bằng ellipsis.
        $this->assertStringContainsString('class="min-w-0 truncate"', $html);
        // Không có chiều rộng cố định nào có thể tràn khung ở 390px.
        $this->assertDoesNotMatchRegularExpression('/\bw-\[\d+px\]/', $html);
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*\bwidth:\s*\d+px/', $html);
        // Thanh tiến độ bị cắt phần vượt quá, nên kể cả vượt mục tiêu cũng không
        // thò ra ngoài ô chứa.
        $this->assertSame(
            1,
            preg_match('/data-testid="card-progress-track"/', $html),
            'Thiếu ô chứa thanh tiến độ.'
        );
        $this->assertMatchesRegularExpression(
            '/class="[^"]*overflow-hidden[^"]*"\s+data-testid="card-progress-track"/',
            $html,
            'Thanh tiến độ phải overflow-hidden để không tràn ngang.'
        );
        // Bảng số dùng `tabular-nums` để các dòng tiền không nhảy khi làm mới.
        $this->assertStringContainsString('tabular-nums', $html);
    }

    #[Test]
    public function the_card_name_is_truncated_rather_than_wrapped(): void
    {
        $this->makeUserCard($this->owner->id, [
            'name' => 'Một cái tên thẻ rất dài để kiểm tra việc cắt chữ trên điện thoại',
        ]);

        // Cắt chữ, KHÔNG xuống dòng: cần đủ cả `truncate` và `min-w-0` (trong một
        // flex, `min-w-0` mới cho phép phần tử co nhỏ lại được).
        $this->assertMatchesRegularExpression(
            '/data-testid="card-title"/',
            $this->overviewHtml(),
            'Thiếu ô tiêu đề thẻ.'
        );

        preg_match('/<p\b[^>]*data-testid="card-title"[^>]*>/', $this->overviewHtml(), $titleTag);

        $this->assertMatchesRegularExpression(
            '/\btruncate\b/',
            $titleTag[0] ?? '',
            'Tên thẻ phải cắt bằng ellipsis thay vì xuống dòng.'
        );
        $this->assertMatchesRegularExpression(
            '/\bmin-w-0\b/',
            $titleTag[0] ?? '',
            'Tên thẻ phải có min-w-0 để co được trong flex.'
        );
    }

    // =====================================================================
    // §19.23 · Header: dải màu pastel full-width
    // =====================================================================

    #[Test]
    public function each_card_header_is_a_flat_pastel_band_across_the_full_width(): void
    {
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ một']);

        $html = $this->overviewHtml();

        preg_match(
            '/<div\b[^>]*data-testid="card-header"[^>]*>(.*?)<\/div>/s',
            $html,
            $band
        );

        $this->assertNotEmpty($band, 'Thiếu dải màu của header thẻ.');

        // Thẻ mở đầu dải — lấy riêng thẻ mở để không tính nhầm class bên trong.
        preg_match('/<div\b[^>]*data-testid="card-header"[^>]*>/', $html, $opening);

        $tag = $opening[0] ?? '';

        // Full-width: âm margin để dải tràn hết bề ngang thẻ, phục vụ `sm`.
        $this->assertMatchesRegularExpression('/-mx-4\b/', $tag);
        $this->assertMatchesRegularExpression('/sm:-mx-5\b/', $tag);
        $this->assertMatchesRegularExpression('/-mt-4\b/', $tag, 'Dải phải áp sát mép trên, không hở padding.');

        // Có nền pastel.
        $this->assertMatchesRegularExpression(
            '/\bbg-(?:blue|emerald|amber|purple|pink)-100\b/',
            $tag,
            'Header phải có nền pastel lấy từ bảng màu.'
        );

        // KHÔNG phải một ô bo góc có viền/đổ bóng ⇒ không phải card lồng trong card.
        $this->assertDoesNotMatchRegularExpression('/\bborder\b/', $tag);
        $this->assertDoesNotMatchRegularExpression('/\brounded\b/', $tag);
        $this->assertDoesNotMatchRegularExpression('/\bshadow\b/', $tag);

        // Tương phản: chữ xám RẤT ĐẬM trên nền pastel RẤT NHẠT.
        preg_match('/<p\b[^>]*data-testid="card-title"[^>]*>/', $band[1] ?? '', $titleTag);

        $this->assertMatchesRegularExpression(
            '/\btext-gray-900\b/',
            $titleTag[0] ?? '',
            'Chữ trên nền pastel phải là xám rất đậm để đọc được ngoài trời.'
        );
    }

    #[Test]
    public function the_pastel_palette_repeats_and_neighbouring_cards_never_share_a_colour(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $name) {
            $this->makeUserCard($this->owner->id, ['name' => 'Thẻ '.$name]);
        }

        preg_match_all('/data-testid="card-header"/', $this->overviewHtml(), $bands);

        $this->assertCount(7, $bands[0], 'Mỗi thẻ phải có đúng một dải màu.');

        // Màu nằm trong `class`, thẻ `data-testid` đứng sau nên thứ tự phải đúng.
        preg_match_all(
            '/<div\b[^>]*\bbg-([a-z]+)-100\b[^>]*data-testid="card-header"/',
            $this->overviewHtml(),
            $colours
        );

        $this->assertCount(7, $colours[1], 'Mỗi dải phải mang một màu pastel.');
        // 7 thẻ > 5 màu ⇒ bảng màu PHẢI lặp lại.
        $this->assertSame($colours[1][0], $colours[1][5], 'Bảng màu phải lặp lại khi nhiều thẻ.');
        // Và hai thẻ cạnh nhau không bao giờ trùng màu.
        for ($i = 1, $n = count($colours[1]); $i < $n; $i++) {
            $this->assertNotSame(
                $colours[1][$i - 1],
                $colours[1][$i],
                'Hai thẻ liền nhau không được cùng màu.'
            );
        }
    }

    #[Test]
    public function the_detail_button_stays_on_the_header_row_next_to_the_name(): void
    {
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ một']);

        $html = $this->overviewHtml();

        preg_match('/<div\b[^>]*data-testid="card-header"[^>]*>(.*?)<\/div>/s', $html, $band);

        $this->assertNotEmpty($band, 'Thiếu dải màu của header thẻ.');

        // Nút nằm TRONG dải màu, cạnh tên, và không bị co lại.
        $this->assertStringContainsString('data-testid="card-title"', $band[1]);
        $this->assertStringContainsString('data-testid="card-history-link"', $band[1]);

        preg_match('/<a\b[^>]*data-testid="card-history-link"[^>]*>/', $band[1], $link);

        $this->assertMatchesRegularExpression('/\bshrink-0\b/', $link[0] ?? '', 'Nút "Chi tiết" phải giữ nguyên kích thước.');
        $this->assertMatchesRegularExpression('/\bwhitespace-nowrap\b/', $link[0] ?? '', 'Chữ nút không được xuống dòng.');
    }

    // =====================================================================
    // §19.22 · Nút cũ vẫn chạy
    // =====================================================================

    #[Test]
    public function the_card_row_keeps_its_detail_link_to_the_cards_own_page(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ VCB chính']);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('data-testid="card-history-link"', $html);
        $this->assertStringContainsString(route('credit-cards.transactions', ['userCard' => $card->id]), $html);
        $this->assertStringContainsString('Chi tiết', $html);
    }

    #[Test]
    public function the_overview_still_keeps_its_four_metric_tiles(): void
    {
        $this->makeUserCard($this->owner->id);

        $this->assertSame(4, substr_count($this->overviewHtml(), 'data-testid="stat-'));
    }

    // =====================================================================
    // Không tính lại ở tầng trình bày
    // =====================================================================

    #[Test]
    public function the_row_reprints_the_engine_numbers_and_recomputes_none_of_them(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '15000000']);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '500000.00']],
            [['category_id' => $category->id, 'percent' => '5.000', 'quota' => true]]
        );

        $html = $this->overviewHtml();
        $rule = $this->metricsOf($html)[(string) $card->id]['quota']['rules'][0];

        // 500.000đ hoàn / 5% ⇒ 10.000.000đ chi thêm. Số này do Phase 2 tính;
        // Blade chỉ in lại đúng nó, chứ không tự chia lần nữa.
        $this->assertSame('10000000.00', $rule['spend_remaining_estimate']);
        $this->assertSame('10.000.000', number_format((float) $rule['spend_remaining_estimate'], 0, ',', '.'));
        $this->assertStringContainsString('10.000.000', $html);
    }

    // =====================================================================
    // Số tiền đã chi phải được trừ khỏi phòng hoàn tiền của bậc đích
    // =====================================================================

    #[Test]
    public function the_row_reports_the_room_left_after_the_cashback_the_spend_already_took(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '4000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        // Bậc 1 là bậc engine chạy với 3.655.000đ (0% ⇒ snapshot 0); bậc 2 là bậc
        // ĐÍCH theo mục tiêu 4.000.000đ: 10%, trần riêng 400.000đ.
        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 3999999, 'cap_period' => '1000000.00'],
                ['name' => 'Bậc 2', 'min' => 4000000, 'max' => null, 'cap_period' => '1000000.00'],
            ],
            [
                ['category_id' => $shopee->id, 'percent' => '0.000', 'only_tier' => 0],
                ['category_id' => $shopee->id, 'percent' => '10.000', 'cap_cat' => '400000.00', 'quota' => true, 'only_tier' => 1],
            ]
        );

        $this->spend($card, $shopee, '3655000');

        $html = $this->overviewHtml();
        $rule = $this->metricsOf($html)[(string) $card->id]['quota']['rules'][0];

        // `cashback_used` GIỮ NGUYÊN nghĩa snapshot: engine chạy bậc 1 @0% nên
        // chưa trả đồng nào. Số IN RA là cashback của số tiền đã chi ở tỷ lệ bậc
        // đích: 3.655.000 × 10% = 365.500đ.
        $this->assertSame('0.00', $rule['cashback_used']);
        $this->assertSame('365500.00', $rule['cashback_used_display']);
        $this->assertSame('365500.00', $rule['cashback_expected_from_spend']);
        $this->assertSame('400000.00', $rule['cashback_max']);
        $this->assertSame('34500.00', $rule['cashback_room_remaining']);

        // 34.500/10% = 345.000đ — KHÔNG phải 400.000/10% = 4.000.000đ.
        $this->assertSame('345000.00', $rule['spend_remaining_estimate']);

        $row = $this->visibleTextOf($this->quotaRowOf($html));

        $this->assertStringContainsString('365.500 đ / 400.000 đ', $row);
        $this->assertStringContainsString('Có thể chi thêm ~345.000 đ', $row);

        // KHÔNG in 0đ (cashback engine đã trả) và KHÔNG có "đ" thừa: đúng 3 mốc
        // tiền trên dòng (đã dùng, trần, chi thêm) ⇒ đúng 3 chữ "đ".
        $this->assertDoesNotMatchRegularExpression('/:\s*0 đ\s*\//', $row, 'Dòng quota không được mở đầu bằng 0 đ.');
        $this->assertStringNotContainsString('4.000.000', $row);
        $this->assertStringNotContainsString('345.000đ', $row);
        $this->assertStringNotContainsString('345.000 đ đ', $row);
        $this->assertStringNotContainsString('Có thể chi thêm ~345.000đ', $row);
        $this->assertSame(3, substr_count($row, 'đ'), 'Dòng quota phải có đúng 3 hậu tố "đ".');
    }

    #[Test]
    public function a_rule_with_no_cashback_room_left_says_the_quota_is_exhausted(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '4000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 3999999, 'cap_period' => '1000000.00'],
                ['name' => 'Bậc 2', 'min' => 4000000, 'max' => null, 'cap_period' => '1000000.00'],
            ],
            [
                ['category_id' => $shopee->id, 'percent' => '0.000', 'only_tier' => 0],
                ['category_id' => $shopee->id, 'percent' => '10.000', 'cap_cat' => '400000.00', 'quota' => true, 'only_tier' => 1],
            ]
        );

        // 4.100.000 × 10% = 410.000đ > trần 400.000đ ⇒ không còn phòng.
        $this->spend($card, $shopee, '4100000');

        $html = $this->overviewHtml();
        $rule = $this->metricsOf($html)[(string) $card->id]['quota']['rules'][0];

        $this->assertSame('0.00', $rule['cashback_room_remaining']);
        $this->assertSame('0.00', $rule['spend_remaining_estimate']);
        $this->assertTrue($rule['is_exhausted']);

        // Số in ra kẹp theo trần: không bao giờ "410.000 đ / 400.000 đ".
        $this->assertSame('400000.00', $rule['cashback_used_display']);

        $row = $this->visibleTextOf($this->quotaRowOf($html));

        $this->assertStringContainsString('400.000 đ / 400.000 đ', $row);
        $this->assertStringContainsString('HẾT QUOTA', $row);
        $this->assertStringNotContainsString('Có thể chi thêm', $row);
        $this->assertStringNotContainsString('410.000', $row);
    }

    // =====================================================================
    // REGRESSION — hai sự cố thật trên dữ liệu thật (đã chốt xử lý)
    // =====================================================================

    #[Test]
    public function a_vpbank_shopee_quota_that_ate_past_its_cap_reads_fully_exhausted(): void
    {
        // Sự cố thật: Shopee tiêu 4.237.000 ⇒ cashback 423.700đ vượt trần riêng
        // 400.000đ. Dòng quota phải kẹp theo trần và nói HẾT QUOTA — không bao
        // giờ in "423.700 / 400.000", cũng không in "4.000.000" (trần stale) hay
        // nhánh "· còn".
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '5000000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        // Giống cấu hình thật: bậc KHÔNG đặt trần chung, trần nằm ở rule Shopee.
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $shopee->id, 'percent' => '10.000', 'cap_cat' => '400000.00', 'quota' => true]]
        );

        $this->spend($card, $shopee, '4237000');

        $html = $this->overviewHtml();
        $rule = $this->metricsOf($html)[(string) $card->id]['quota']['rules'][0];

        // Engine đã kẹp snapshot ở trần: 400.000đ, và trình bày cũng kẹp theo.
        $this->assertSame('400000.00', $rule['cashback_used']);
        $this->assertSame('400000.00', $rule['cashback_used_display']);
        $this->assertSame('400000.00', $rule['cashback_max']);
        $this->assertTrue($rule['is_exhausted']);

        $row = $this->visibleTextOf($this->quotaRowOf($html));

        // Nhánh B đã chốt: `[trần] / [trần] · HẾT QUOTA`.
        $this->assertStringContainsString('400.000 đ / 400.000 đ · HẾT QUOTA', $row);
        $this->assertStringNotContainsString('423.700', $row, 'Số vượt trần không được in ra.');
        $this->assertStringNotContainsString('4.000.000', $row);
        $this->assertStringNotContainsString('Có thể chi thêm', $row);
        $this->assertStringNotContainsString('· còn', $html);
    }

    #[Test]
    public function an_msb_mdigi_quota_line_reads_used_over_cap_then_the_spend_estimate(): void
    {
        // Sự cố thật: dòng MSB Mdigi in nhánh "· còn 196.600 đ" (payload thiếu
        // trần riêng do policy stale). Sau chốt: format DUY NHẤT
        // `Tên: [đã dùng] / [trần] → Có thể chi thêm ~[x]`, ước lượng giữ nguyên.
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '1500000']);
        $food = $this->makeSystemCategory(['name' => 'Ẩm thực & Ăn uống']);

        // Giống cấu hình thật: trần chung của bậc VÀ trần riêng đều 300.000đ.
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '300000.00']],
            [['category_id' => $food->id, 'percent' => '20.000', 'cap_cat' => '300000.00', 'quota' => true]]
        );

        $this->spend($card, $food, '517000');

        $html = $this->overviewHtml();
        $rule = $this->metricsOf($html)[(string) $card->id]['quota']['rules'][0];

        // 517.000 × 20% = 103.400đ; còn 196.600đ ⇒ 196.600 / 20% = 983.000đ.
        $this->assertSame('103400.00', $rule['cashback_used_display']);
        $this->assertSame('300000.00', $rule['cashback_max']);
        $this->assertSame('196600.00', $rule['cashback_available_for_rule']);
        $this->assertSame('983000.00', $rule['spend_remaining_estimate']);
        $this->assertFalse($rule['is_exhausted']);

        $row = $this->visibleTextOf($this->quotaRowByLabel($html, 'Ẩm thực & Ăn uống'));

        // Format A đã chốt — khớp đúng ví dụ user đưa ra.
        $this->assertStringContainsString(
            '103.400 đ / 300.000 đ → Có thể chi thêm ~983.000 đ',
            $row
        );
        $this->assertStringNotContainsString('· còn', $row);
    }

    #[Test]
    public function an_uncapped_rule_that_runs_out_of_tier_room_says_exhausted_with_no_denominator(): void
    {
        // Nhánh C khi hết phòng: `[đã dùng] · HẾT QUOTA` — nhất quán với nhánh B
        // (nói thẳng việc hết quota) nhưng KHÔNG bịa một "trần riêng" không tồn
        // tại để làm mẫu số.
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '3500000']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '300000.00']],
            [['category_id' => $shopee->id, 'percent' => '10.000', 'cap_cat' => null, 'quota' => true]]
        );

        // 3.500.000 × 10% = 350.000đ bị trần chung 300.000đ chặn ⇒ hết phòng.
        $this->spend($card, $shopee, '3500000');

        $html = $this->overviewHtml();
        $rule = $this->metricsOf($html)[(string) $card->id]['quota']['rules'][0];

        $this->assertNull($rule['cashback_max']);
        $this->assertTrue($rule['is_exhausted']);
        $this->assertSame('300000.00', $rule['cashback_used_display']);

        $row = $this->visibleTextOf($this->quotaRowByLabel($html, 'Shopee'));

        $this->assertStringContainsString('300.000 đ · HẾT QUOTA', $row);
        $this->assertStringNotContainsString('/', $row, 'Không có trần riêng thì không được có mẫu số.');
        $this->assertStringNotContainsString('· còn', $row);
        $this->assertStringNotContainsString('Có thể chi thêm', $row);
    }

    #[Test]
    public function every_quota_line_shows_its_own_target_tier_rate_in_the_number_before_the_max(): void
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '4000000']);

        // Bậc đích: Shopee 10%, Ăn uống 2% — KHÔNG dùng một rate chung cho cả bậc.
        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 3999999, 'cap_period' => '1000000.00'],
                ['name' => 'Bậc 2', 'min' => 4000000, 'max' => null, 'cap_period' => '1000000.00'],
            ],
            [
                ['category_id' => $shopee->id, 'percent' => '0.000', 'only_tier' => 0],
                ['category_id' => $food->id, 'percent' => '0.000', 'only_tier' => 0],
                ['category_id' => $shopee->id, 'percent' => '10.000', 'cap_cat' => '400000.00', 'quota' => true, 'only_tier' => 1],
                ['category_id' => $food->id, 'percent' => '2.000', 'cap_cat' => '400000.00', 'quota' => true, 'only_tier' => 1],
            ]
        );

        $this->spend($card, $shopee, '3655000');
        $this->spend($card, $food, '1000000');

        $html = $this->overviewHtml();
        $rules = collect($this->metricsOf($html)[(string) $card->id]['quota']['rules'])
            ->keyBy('category_id');

        // Shopee 3.655.000 × 10% = 365.500; Ăn uống 1.000.000 × 2% = 20.000.
        $this->assertSame('365500.00', $rules[(int) $shopee->id]['cashback_used_display']);
        $this->assertSame('20000.00', $rules[(int) $food->id]['cashback_used_display']);

        $shopeeRow = $this->visibleTextOf($this->quotaRowByLabel($html, 'Shopee'));
        $foodRow = $this->visibleTextOf($this->quotaRowByLabel($html, 'Ăn uống'));

        $this->assertStringContainsString('365.500 đ / 400.000 đ', $shopeeRow);
        $this->assertStringContainsString('20.000 đ / 400.000 đ', $foodRow);

        // Mỗi dòng một rate riêng: 345.000 = 34.500/10%, 19.000.000 = 380.000/2%.
        $this->assertStringContainsString('Có thể chi thêm ~345.000 đ', $shopeeRow);
        $this->assertStringContainsString('Có thể chi thêm ~19.000.000 đ', $foodRow);
    }

    // =====================================================================
    // Typography của dòng "Có thể chi thêm" trên mobile
    // =====================================================================

    #[Test]
    public function the_estimate_keeps_its_amount_typography_identical_to_the_other_amounts(): void
    {
        $fixture = $this->overviewHtmlForTwoTierQuota(3655000.0);

        $row = $this->quotaRowByLabel($fixture, 'Shopee');
        $estimate = $this->markupOfTestId($row, 'card-quota-estimate');

        // Số tiền phảI nằm trực tiếp trong span xanh, không có wrapper nào ép thêm
        // font-size/weight — chính wrapper đó làm "345.000" đậm hơn "đ" của nó.
        $this->assertStringContainsString('class="font-semibold text-emerald-600"', $estimate);
        $this->assertStringNotContainsString('font-bold', $estimate);

        $inner = substr($estimate, (int) strpos($estimate, '>') + 1);
        $this->assertStringNotContainsString(
            'class="',
            $inner,
            'Số tiền không được bọc trong span mang class riêng — nó phải kế thừa cả của cụm xanh.',
        );

        // Hậu tố "đ" kế thừa, không tự mang class cỡ chữ/độ đậm nào. Đây là nơi
        // duy nhất của module được phép thêm style cho hậu tố — và nay là không.
        foreach ($this->moneySuffixSpansOf($row) as $suffix) {
            $this->assertSame('', trim($suffix), 'Hậu tố "đ" không được mang class riêng.');
        }

        // Cả ba mốc tiền trên dòng cùng cỡ chữ và cùng độ đậm: số tiền của dòng
        // quota là 12px/600 (`font-semibold` trên `<li>` và span xanh), nên "đ"
        // bám theo số chứ không nhảy lên 16px.
        $this->assertSame(1, preg_match('/class="font-semibold text-emerald-600"[^>]*>/', $estimate));
    }

    #[Test]
    public function no_money_amount_on_a_quota_line_can_break_before_its_currency_suffix(): void
    {
        $fixture = $this->overviewHtmlForTwoTierQuota(3655000.0);
        $row = $this->quotaRowByLabel($fixture, 'Shopee');

        // "345.000 đ" là đơn vị duy nhất được phép xuống dòng, không bao giờ
        // tách ra "345.000" / "đ" — space thường là chỗ ngắt dòng duy nhất.
        $this->moneyAmountNeverBreaks($row, ['365.500', '400.000', '345.000']);
    }

    #[Test]
    public function a_long_estimate_still_keeps_its_amount_on_one_line(): void
    {
        // 3.000.000 × 10% = 300.000 ⇒ còn 100.000 hoàn tiền ⇒ 1.000.000đ.
        // Các số dài hơn ("2.666.667", "5.333.333") chỉ khác độ dài chuỗi, cùng
        // cơ chế: `&nbsp;` nối "đ" vào số nên cả cụm phải xuống dòng cùng nhau.
        $fixture = $this->overviewHtmlForTwoTierQuota(3000000.0);
        $row = $this->quotaRowByLabel($fixture, 'Shopee');

        $this->assertStringContainsString('Có thể chi thêm ~1.000.000 đ', $this->visibleTextOf($row));
        $this->moneyAmountNeverBreaks($row, ['1.000.000']);
    }

    #[Test]
    public function the_client_side_amounts_use_the_same_non_breaking_suffix(): void
    {
        $fixture = $this->overviewHtmlForTwoTierQuota(3655000.0);

        // `x-text` ghi đè nội dung phần tử, nên `&nbsp;` của bản render sẵn biến
        // mất sau khi Alpine chạy. Bản JS phải nối hậu tố bằng chính U+00A0 thì
        // "345.000" và "đ" mới không tách được ở cả hai thời điểm.
        $this->assertMatchesRegularExpression(
            '/function ccMoneyVnd\(value\)\s*\{\s*return `\$\{ccMoney\(value\)\}\\\\u00A0đ`;/',
            $fixture,
        );

        // Và không chỗ nào được tự nối hậu tố bằng space thường nữa — đó là chỗ
        // ngắt dòng duy nhất, và nó nằm ở markup chứ không phải ở CSS.
        preg_match_all('/x-text="([^"]*)"/', $fixture, $matches);

        $this->assertNotEmpty($matches[1], 'Overview phải còn dùng x-text để cập nhật số liệu.');

        foreach ($matches[1] as $expression) {
            if (! str_contains($expression, 'ccMoney')) {
                continue;
            }

            $this->assertStringContainsString(
                'ccMoneyVnd(',
                $expression,
                "x-text=\"{$expression}\" phải dùng ccMoneyVnd để hậu tố không bị ngắt dòng.",
            );
            $this->assertStringNotContainsString(
                "' đ'",
                $expression,
                "x-text=\"{$expression}\" tự nối hậu tố bằng space thường — số và đ sẽ tách dòng.",
            );
        }
    }

    /**
     * Bóc markup đúng một phần tử có `data-testid`, kể cả khi thuộc tính nằm
     * trên dòng riêng.
     */
    private function markupOfTestId(string $html, string $testId): string
    {
        $position = strpos($html, 'data-testid="'.$testId.'"');

        $this->assertNotFalse($position, "Không tìm thấy data-testid={$testId}.");

        $open = strrpos(substr($html, 0, (int) $position), '<');
        $this->assertNotFalse($open);

        $close = strpos($html, '</span>', $position);
        $this->assertNotFalse($close);

        return substr($html, (int) $open, $close - (int) $open);
    }

    /**
     * Mọi thẻ `<span>` đang BỌC hậu tố "đ" trong một đoạn HTML.
     *
     * @return array<int, string> Thuộc tính `class` của từng thẻ, rỗng nếu không có.
     */
    private function moneySuffixSpansOf(string $html): array
    {
        preg_match_all('/<span([^>]*)>\s*đ\s*<\/span>/u', $html, $matches);

        return array_map(
            fn (string $attributes): string => preg_match('/class="([^"]*)"/', $attributes, $class) === 1 ? $class[1] : '',
            $matches[1] ?? [],
        );
    }

    /**
     * Fixture Card #8 cho các test trình bày: mục tiêu 4.000.000 ⇒ bậc đích bậc 2
     *
     * @10% với trần 400.000; engine chạy bậc 1 @0% nên snapshot bằng 0.
     */
    private function overviewHtmlForTwoTierQuota(float $spend): string
    {
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '4000000']);

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 3999999, 'cap_period' => '1000000.00'],
                ['name' => 'Bậc 2', 'min' => 4000000, 'max' => null, 'cap_period' => '1000000.00'],
            ],
            [
                ['category_id' => $shopee->id, 'percent' => '0.000', 'only_tier' => 0],
                ['category_id' => $shopee->id, 'percent' => '10.000', 'cap_cat' => '400000.00', 'quota' => true, 'only_tier' => 1],
            ]
        );

        $this->spend($card, $shopee, (string) (int) $spend);

        return $this->overviewHtml();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function overviewHtml(): string
    {
        return $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
    }

    private function firstCard(): UserCard
    {
        return UserCard::query()->where('user_id', $this->owner->id)->orderBy('id')->firstOrFail();
    }

    /**
     * Kỳ `open` chứa hôm nay, với các số engine đã ghi sẵn.
     *
     * `total_eligible_spend` + `calculation_meta.min_total_spend` là đúng hai số
     * engine dùng để quyết định có hoàn tiền hay không — test đặt thẳng để kiểm tra
     * lớp trình bày, không cần chạy lại engine.
     */
    private function makeOpenPeriod(UserCard $card, array $attributes = []): StatementPeriod
    {
        $today = CarbonImmutable::now();

        return $this->makeStatementPeriod($card, array_merge([
            'period_start' => $today->subDays(10)->toDateString(),
            'period_end' => $today->addDays(10)->toDateString(),
            'statement_date' => $today->addDays(10)->toDateString(),
            'payment_due_date' => $today->addDays(20)->toDateString(),
            'status' => StatementPeriod::STATUS_OPEN,
        ], $attributes));
    }

    /**
     * Ghi số liệu engine vào kỳ đang mở của thẻ.
     *
     * Engine thực sự lưu `total_eligible_spend` + `calculation_meta.min_total_spend`
     * vào bản ghi `StatementPeriod`; Tổng quan chỉ đọc lại hai số đó. Test đặt
     * thẳng để kiểm tra lớp trình bày mà không cần chạy lại engine.
     */
    private function writeEngineSnapshot(UserCard $card, array $attributes): StatementPeriod
    {
        $period = StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->orderByDesc('period_start')
            ->firstOrFail();

        $period->forceFill($attributes)->save();

        return $period->refresh();
    }

    private function createTransaction(UserCard $card, Category $category, string $amount, StatementPeriod $period): void
    {
        $this->spend($card, $category, $amount);

        // `spend()` để service tự sinh kỳ; test này cần kỳ có sẵn với số liệu
        // engine ghi sẵn nên gắn giao dịch vào kỳ đó.
        Transaction::query()
            ->where('user_card_id', $card->id)
            ->latest('id')
            ->firstOrFail()
            ->forceFill(['statement_period_id' => $period->id])
            ->save();
    }

    /**
     * Thêm rule thẳng vào một bậc.
     *
     * `makePolicyForCard()` chỉ nhận rule theo DANH MỤC (`category_id`), còn
     * combo và rule fallback cần `combo_id` / `scope_type` nên phải tự tạo —
     * đúng như trong `CashbackQuotaTest`.
     */
    private function addRule(UserCard $card, int $tierIndex, array $attributes): PolicyTierCategory
    {
        $tier = PolicyTier::query()
            ->where('policy_id', $card->current_policy_id)
            ->orderBy('sort_order')
            ->skip($tierIndex)
            ->firstOrFail();

        return PolicyTierCategory::create(array_merge([
            'tier_id' => $tier->id,
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'category_id' => null,
            'cashback_percent' => '5.000',
            'spend_from' => '0.00',
            'spend_to' => null,
            'is_enabled' => true,
            'counts_toward_tier_cap' => true,
            'is_quota_category' => false,
        ], $attributes));
    }

    /**
     * Gắn Vạch-Min-Spend vào policy RIÊNG của thẻ — đúng nơi Tổng quan phải đọc.
     *
     * Thẻ chưa có policy thì tạo một bản tối giản (chỉ cần `min_total_spend`);
     * thẻ đã có thì sửa đúng bản đó. Cố tình KHÔNG ghi vào System Policy và cũng
     * không ghi `calculation_meta` của kỳ: test phải chứng minh Tổng quan đọc nơi
     * này chứ không đọc hai chỗ kia.
     */
    private function setCardMinimumSpend(UserCard $card, string $minimum): UserCard
    {
        $policy = Policy::query()->find($card->current_policy_id);

        if ($policy === null) {
            $policy = Policy::create([
                'user_card_id' => $card->id,
                'version_no' => 1,
                'status' => Policy::STATUS_ACTIVE,
                'name' => 'Policy '.$card->name,
                'effective_from' => '2000-01-01',
                'effective_to' => null,
                'min_total_spend' => $minimum,
                'rounding_mode' => 'floor',
            ]);

            $policy->forceFill(['root_policy_id' => $policy->id])->save();

            $card->forceFill(['current_policy_id' => $policy->id])->save();
        } else {
            $policy->forceFill(['min_total_spend' => $minimum])->save();
        }

        return $card->refresh();
    }

    /**
     * Giao dịch đi qua service thật để chắc chắn nó được gắn kỳ + snapshot chính
     * sách — tạo bản ghi `Transaction` trần sẽ không có những thứ đó và số liệu
     * Tổng quan sẽ lệch.
     */
    private function spend(UserCard $card, Category $category, string $amount): void
    {
        [$start] = app(StatementPeriodService::class)->currentBoundaries($card, CarbonImmutable::now());

        $date = CarbonImmutable::now()->subDay();

        if ($date->lessThan($start)) {
            $date = $start;
        }

        app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $date->toDateString(),
            'amount' => $amount,
            'category_id' => $category->id,
        ]);
    }

    /**
     * Bóc dòng quota mang NHÃN này.
     *
     * Thứ tự dòng trong HTML là thứ tự rule, nên test nhiều rule không được đoán
     * "dòng đầu là Shopee" — tìm theo nhãn mới đúng.
     */
    private function quotaRowByLabel(string $html, string $label): string
    {
        $offset = 0;

        while (true) {
            $position = strpos($html, 'data-testid="card-quota-row"', $offset);

            if ($position === false) {
                $this->fail("Không tìm thấy dòng quota nào mang nhãn {$label}.");
            }

            $end = strpos($html, '</li>', $position);
            $this->assertNotFalse($end, 'Dòng quota không đóng.');

            $row = substr($html, (int) $position, $end - (int) $position);
            $offset = $position + 1;

            if (str_contains($this->visibleTextOf($row), $label)) {
                return $row;
            }
        }
    }

    /**
     * CHỮ NGƯỜI DÙNG THẬT SỰ THẤY trong một đoạn HTML.
     *
     * Assert trên HTML thô sẽ bỏ sót đúng lỗi cần bắt: "345.000 đ đ" hay
     * "0 đ / 400.000 đ" đều là text node nằm xen giữa các thẻ `<span>`, nên
     * `assertStringContainsString` trên markup không bao giờ thấy chúng. Bóc thẻ
     * rồi gộp khoảng trắng lại là cách duy nhất kiểm tra được phần người đọc.
     *
     * `&nbsp;` bị đổi về space thường để kỳ vọng viết được như người đọc; hành vi
     * "không đứt dòng" của nó kiểm tra riêng bằng {@see moneyAmountNeverBreaks()}.
     */
    private function visibleTextOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Mọi mốc tiền phải là MỘT đơn vị: số và hậu tố "đ" nối bằng `&nbsp;`.
     *
     * Đây là thứ giữ "đ" không rơi xuống dòng riêng trên mobile. Chỉ kiểm tra text
     * đã bóc sẽ không bắt được: bóc xong space thường và `&nbsp;` giống nhau.
     *
     * @param  array<int, string>  $expectedAmounts  Chuỗi đã format, ví dụ `345.000`.
     */
    private function moneyAmountNeverBreaks(string $html, array $expectedAmounts): void
    {
        foreach ($expectedAmounts as $amount) {
            $this->assertStringContainsString(
                $amount.'&nbsp;',
                $html,
                "Số tiền {$amount} phải nối hậu tố bằng &nbsp; để không đứt dòng.",
            );
        }
    }

    /** Bóc đúng markup của dòng quota thứ `$index` để soi một dòng, không soi cả trang. */
    private function quotaRowOf(string $html, int $index = 0): string
    {
        $position = false;
        $offset = 0;

        for ($i = 0; $i <= $index; $i++) {
            $position = strpos($html, 'data-testid="card-quota-row"', $offset);
            $this->assertNotFalse($position, 'Không tìm thấy dòng quota #'.$index.'.');
            $offset = $position + 1;
        }

        $end = strpos($html, '</li>', (int) $position);
        $this->assertNotFalse($end, 'Dòng quota không đóng.');

        return substr($html, (int) $position, $end - (int) $position);
    }

    /**
     * Bóc payload Alpine để đọc số liệu từng thẻ server đã gửi xuống.
     *
     * `@js()` escape hai lớp nên phải giải mã: string literal → JSON → mảng.
     *
     * @return array<string, array<string, mixed>>
     */
    private function metricsOf(string $html): array
    {
        $this->assertSame(
            1,
            preg_match("/x-data=\"creditCardOverview\(JSON\.parse\('(.*)'\)\)\"/", $html, $matches),
            'Không tìm thấy payload Alpine của trang Tổng quan.'
        );

        $json = json_decode('"'.$matches[1].'"', true);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);

        return $decoded['card_metrics'];
    }
}
