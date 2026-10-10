<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\SpendQualificationService;
use App\Services\CreditCard\StatementPeriodService;
use App\Support\CreditCard\CreditCardMoneyFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Tổng quan — hai cải tiến trình bày:
 *
 *   A. Nhãn "Điều kiện hoàn tiền đặc biệt" phải đọc là "→ ĐÃ ĐẠT ĐIỀU KIỆN"
 *      (không còn "Còn thiếu 0 đ") và phải đổi theo CÙNG nguồn `met` server
 *      tính khi `refreshOverview()` thay `card_metrics` sau khi lưu giao dịch —
 *      nhánh `@if` server KHÔNG re-render nên việc đổi nhãn do Alpine lo.
 *
 *   B. Ô tìm kiếm thẻ (lọc client-side theo tên thẻ HOẶC ngân hàng, bỏ dấu,
 *      không phân biệt hoa/thường) ngay trên danh sách thẻ — KHÔNG đổi thứ tự,
 *      KHÔNG đụng số tổng hợp toàn trang, KHÔNG gọi mạng theo từng ký tự.
 */
class OverviewCardSearchAndQualificationStatusTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private SpendQualificationService $qualifications;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
        $this->qualifications = app(SpendQualificationService::class);

        CreditCardMoneyFormatter::flushAll();
    }

    protected function tearDown(): void
    {
        CreditCardMoneyFormatter::flushAll();

        parent::tearDown();
    }

    // =====================================================================
    // A · Nhãn trạng thái điều kiện
    // =====================================================================

    #[Test]
    public function a_met_condition_reads_as_met_and_is_drawn_by_alpine(): void
    {
        $card = $this->makeCardWithCondition('Shopee', '2000000', '3000000');

        $html = $this->overviewHtml();
        $row = $this->qualificationRowByLabel($html, 'Shopee');

        $this->assertStringContainsString('→ ĐÃ ĐẠT ĐIỀU KIỆN', $this->visibleTextOf($row));
        $this->assertStringContainsString('data-testid="card-qualification-met"', $row);
        $this->assertStringNotContainsString('data-testid="card-qualification-remaining"', $row);
        $this->assertStringNotContainsString('Còn thiếu', $this->visibleTextOf($row));

        // Nhãn phải do Alpine vẽ lại (x-text) từ cờ `met` chung — không phải chữ
        // tĩnh server render rồi đứng yên sau refresh.
        $this->assertStringContainsString('x-text="qualificationConditionStatus(', $row);
        $this->assertStringContainsString('qualificationConditionMet(', $row);
    }

    #[Test]
    public function a_shortfall_condition_shows_the_missing_amount_and_stays_reactive(): void
    {
        $card = $this->makeCardWithCondition('Shopee', '2000000', '1500000');

        $html = $this->overviewHtml();
        $row = $this->qualificationRowByLabel($html, 'Shopee');

        $this->assertStringContainsString('→ Còn thiếu 500.000 đ', $this->visibleTextOf($row));
        $this->assertStringContainsString('data-testid="card-qualification-remaining"', $row);
        $this->assertStringNotContainsString('data-testid="card-qualification-met"', $row);
        $this->assertStringContainsString('x-text="qualificationConditionStatus(', $row);
    }

    #[Test]
    public function the_status_helper_reads_the_shared_met_flag_and_never_guesses(): void
    {
        $card = $this->makeCardWithCondition('Shopee', '2000000', '1500000');

        $html = $this->overviewHtml();

        // Cờ `met` server tính đi kèm số liệu, cùng nguồn mà nhãn đọc.
        $condition = $this->metricsOf($html)[(string) $card->id]['spend_qualification']['conditions'][0];
        $this->assertFalse($condition['met']);

        // Helper đọc thẳng `met` và chỉ coi ĐẠT khi đúng true (thiếu dữ liệu ⇒ false).
        $this->assertStringContainsString('qualificationConditionMet(id, index)', $html);
        $this->assertStringContainsString("qualificationConditionValue(id, index, 'met') === true", $html);

        // Nhãn dựng từ cùng nguồn, số tiền còn thiếu vẫn qua formatter chung.
        $this->assertStringContainsString("'→ ĐÃ ĐẠT ĐIỀU KIỆN'", $html);
        $this->assertStringContainsString("ccMoneyVnd(this.qualificationConditionValue(id, index, 'remaining'))", $html);
        $this->assertStringContainsString('→ Còn thiếu ', $html);
    }

    #[Test]
    public function the_refresh_payload_carries_the_met_flag_so_the_label_can_flip(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($shopee, '2000000')]));
        $this->spend($card, $shopee, '1500000');

        $before = $this->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json("meta.card_metrics.{$card->id}.spend_qualification.conditions.0.met");

        $this->assertFalse($before);

        // Người dùng chi thêm để vượt mức tối thiểu; làm mới đọc lại đúng nguồn
        // `forPage()` mà `refreshOverview()` dùng.
        $this->spend($card, $shopee, '600000');

        $after = $this->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json("meta.card_metrics.{$card->id}.spend_qualification.conditions.0.met");

        $this->assertTrue($after);
    }

    #[Test]
    public function the_met_flag_compares_raw_vnd_even_under_the_thousand_unit(): void
    {
        $this->switchToThousandUnit();

        // 1.999.000 < 2.000.000 về VND thô ⇒ CHƯA đạt, dù hiển thị nghìn làm tròn.
        $shy = $this->makeCardWithCondition('Shopee', '2000000', '1999000', 'Thẻ sát ngưỡng');

        $html = $this->overviewHtml();
        $condition = $this->metricsOf($html)[(string) $shy->id]['spend_qualification']['conditions'][0];

        $this->assertFalse($condition['met']);
        $this->assertSame('1000.00', $condition['remaining']);
        $this->assertStringContainsString('→ Còn thiếu 1 nghìn', $this->visibleTextOf($this->qualificationRowByLabel($html, 'Shopee')));
    }

    // =====================================================================
    // B · Ô tìm kiếm thẻ
    // =====================================================================

    #[Test]
    public function the_card_search_box_is_offered_above_the_card_list(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('data-testid="card-search-input"', $html);
        $this->assertStringContainsString('x-model="cardQuery"', $html);
        $this->assertStringContainsString('placeholder="Tìm tên thẻ hoặc ngân hàng..."', $html);
    }

    #[Test]
    public function every_card_row_exposes_normalized_search_text_without_leaking_the_bank(): void
    {
        $bank = $this->makeBank(['name' => 'Ngân hàng ABC', 'slug' => 'ngan-hang-abc']);
        $card = $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ vàng VCB',
            'bank_id' => $bank->id,
        ]);

        $html = $this->overviewHtml();

        // Chuỗi tìm kiếm = tên thẻ + ngân hàng, đã bỏ dấu và về chữ thường.
        $this->assertStringContainsString('data-card-search="the vang vcb ngan hang abc"', $html);
        $this->assertStringContainsString('data-card-name="Thẻ vàng VCB"', $html);

        // Luật cũ vẫn giữ: danh sách KHÔNG in tên ngân hàng có dấu.
        $this->assertStringNotContainsString('Ngân hàng ABC', $html);
    }

    #[Test]
    public function the_search_box_is_hidden_when_the_user_has_no_cards(): void
    {
        $html = $this->overviewHtml();

        $this->assertStringNotContainsString('data-testid="card-search-input"', $html);
        $this->assertStringContainsString('Bạn chưa thêm thẻ tín dụng nào.', $html);
    }

    #[Test]
    public function an_empty_filter_state_is_available_and_only_while_filtering(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->overviewHtml();

        $this->assertStringContainsString('data-testid="card-search-empty"', $html);
        $this->assertStringContainsString('Không tìm thấy thẻ phù hợp', $html);
        $this->assertStringContainsString("cardQuery.trim() !== ''", $html);
        $this->assertStringContainsString('cardMatchCount === 0', $html);
    }

    #[Test]
    public function the_filter_helpers_are_client_side_and_keep_the_row_markers(): void
    {
        $first = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A']);
        $second = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);

        $html = $this->overviewHtml();

        // Hàm chuẩn hoá + lọc thuần client, danh sách đọc qua `x-ref`.
        $this->assertStringContainsString('function ccNormalizeSearch(value)', $html);
        $this->assertStringContainsString('cardMatches(el)', $html);
        $this->assertStringContainsString('x-ref="cardList"', $html);
        $this->assertStringContainsString('x-show="cardMatches($el)"', $html);

        // Marker dòng thẻ (regex mà các test sắp xếp/statement đọc) không đổi —
        // thuộc tính tìm kiếm được thêm SAU `data-card-id`.
        preg_match_all('/data-testid="card-row"\s+data-card-id="(\d+)"/', $html, $matches);
        $this->assertCount(2, $matches[1]);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_map('intval', $matches[1]));
    }

    #[Test]
    public function the_search_never_hides_rows_server_side_or_touches_the_summary(): void
    {
        $cardA = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A']);
        $this->makePolicy($cardA);
        $this->makeOpenPeriod($cardA);

        $cardB = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);
        $this->makePolicy($cardB);
        $this->makeOpenPeriod($cardB);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->spend($cardA, $shopee, '1000000');

        $html = $this->overviewHtml();

        // Lọc là việc của trình duyệt: server LUÔN render đủ mọi thẻ và bốn chỉ
        // số tổng hợp không được lọc theo từ khoá.
        preg_match_all('/data-testid="card-row"/', $html, $rows);
        $this->assertCount(2, $rows[0]);

        $summary = $this->metricsOf($html);
        $this->assertCount(2, $summary);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeCardWithCondition(string $categoryName, string $min, string $spend, ?string $cardName = null): UserCard
    {
        $card = $this->makeUserCard($this->owner->id, $cardName === null ? [] : ['name' => $cardName]);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $category = $this->makeSystemCategory(['name' => $categoryName]);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($category, $min)]));
        $this->spend($card, $category, $spend);

        return $card;
    }

    private function overviewHtml(): string
    {
        return $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
    }

    /** Policy tối giản: không rule, không trần, không tick quota. */
    private function makePolicy(UserCard $card): void
    {
        $this->makePolicyForCard($card, [['name' => 'Bậc 1', 'min' => 0, 'max' => null]], []);
    }

    private function makeOpenPeriod(UserCard $card): void
    {
        $today = CarbonImmutable::now();

        $this->makeStatementPeriod($card, [
            'period_start' => $today->subDays(10)->toDateString(),
            'period_end' => $today->addDays(10)->toDateString(),
            'statement_date' => $today->addDays(10)->toDateString(),
            'payment_due_date' => $today->addDays(20)->toDateString(),
            'status' => StatementPeriod::STATUS_OPEN,
        ]);
    }

    private function attachQualification(UserCard $card, array $payload): void
    {
        $this->qualifications->persistForPolicyVersion(
            (int) $card->current_policy_id,
            $payload,
            (int) $this->owner->id,
        );

        $card->refresh();
    }

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

    private function categoryCondition(Category $category, string $minSpend): array
    {
        return ['type' => 'category', 'category_id' => $category->id, 'min_spend' => $minSpend];
    }

    private function payloadFor(array $conditions): array
    {
        return ['name' => 'Điều kiện test', 'enabled' => true, 'conditions' => $conditions];
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

    /**
     * CHỮ NGƯỜI DÙNG THẬT SỰ THẤY trong một đoạn HTML.
     */
    private function visibleTextOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Bóc dòng điều kiện mang NHÃN này (tới `</li>`). */
    private function qualificationRowByLabel(string $html, string $label): string
    {
        preg_match_all('/data-testid="card-qualification-row"/', $html, $marks, PREG_OFFSET_CAPTURE);

        foreach ($marks[0] as $mark) {
            $position = $mark[1];
            $end = strpos($html, '</li>', $position);
            $this->assertNotFalse($end);

            $row = substr($html, $position, $end - $position);

            if (str_contains($this->visibleTextOf($row), $label)) {
                return $row;
            }
        }

        $this->fail("Không tìm thấy dòng điều kiện nào mang nhãn {$label}.");
    }

    /**
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
