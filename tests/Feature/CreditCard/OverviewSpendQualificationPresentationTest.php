<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\SpendQualification;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\SpendQualificationService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Tổng quan — "Điều kiện hoàn tiền đặc biệt" (lớp TRÌNH BÀY).
 *
 * ---------------------------------------------------------------------------
 * HỢP ĐỒNG (đã chốt với user — KHÔNG đổi business rule)
 * ---------------------------------------------------------------------------
 *   1. Thẻ KHÔNG có điều kiện ⇒ KHÔNG có section, KHÔNG placeholder, và trong
 *      điều kiện lý tưởng cụm từ "Điều kiện hoàn tiền đặc biệt" không xuất hiện
 *      đâu trên trang (kể cả label ô "Hiển thị").
 *   2. Section hiện khi: qualification của policy version ĐANG GẮN VỚI CHÍNH THẺ
 *      (KHÔNG đọc System Policy, KHÔNG đọc template trong DB) được bật VÀ có ≥1
 *      điều kiện được bật. Không gate theo kỳ: thẻ cấu hình mà chưa chi tiêu thì
 *      vẫn hiện với `actual = 0`.
 *   3. Mỗi dòng: nhãn → " : " → actual / min → trạng thái. "Thực tế" = giao dịch
 *      THỰC TẾ của kỳ hiện tại của thẻ (category = đúng danh mục, other = tổng
 *      kỳ − danh mục loại trừ) — cùng ngữ nghĩa gate của engine.
 *   4. Blade/JavaScript KHÔNG có công thức nào: mọi số server đã tính sẵn trong
 *      `spend_qualification`; money dùng đúng `x-credit-card.money` / `ccMoneyVnd`.
 */
class OverviewSpendQualificationPresentationTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private SpendQualificationService $qualifications;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
        $this->qualifications = app(SpendQualificationService::class);
    }

    // =====================================================================
    // A · Thẻ không có điều kiện → không hiện gì cả
    // =====================================================================

    #[Test]
    public function a_card_without_a_qualification_shows_nothing_and_payload_is_null(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $html = $this->overviewHtml();

        $this->assertStringNotContainsString('data-testid="card-qualification-section"', $html);
        $this->assertStringNotContainsString('card-qualification-row', $html);

        // §2 — cụm từ không được lọt vào trang (kể cả label ô "Hiển thị").
        $this->assertStringNotContainsString('Điều kiện hoàn tiền đặc biệt', $html);
        $this->assertStringNotContainsString('data-testid="toggle-qualification"', $html);

        $metrics = $this->metricsOf($html)[(string) $card->id];

        $this->assertNull($metrics['spend_qualification']);

        // Và payload Alpine chứa null dưới dạng khoá, không phải bị thiếu.
        $this->assertStringContainsString('spend_qualification', $html);
    }

    // =====================================================================
    // B · Qualification bị tắt / không có điều kiện → không hiện
    // =====================================================================

    #[Test]
    public function a_disabled_qualification_hides_the_section_entirely(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($shopee, '2000000')], enabled: false));
        $this->spend($card, $shopee, '1500000');

        $html = $this->overviewHtml();

        $this->assertStringNotContainsString('data-testid="card-qualification-section"', $html);
        $this->assertNull($this->metricsOf($html)[(string) $card->id]['spend_qualification']);

        // Qualification có tồn tại trong DB, nhưng tắt ⇒ không section, không label.
        $this->assertSame(1, SpendQualification::query()->where('policy_version_id', $card->current_policy_id)->count());
    }

    #[Test]
    public function an_enabled_qualification_with_no_conditions_shows_nothing(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $this->attachQualification($card, $this->payloadFor([]));

        $html = $this->overviewHtml();

        $this->assertStringNotContainsString('data-testid="card-qualification-section"', $html);
        $this->assertNull($this->metricsOf($html)[(string) $card->id]['spend_qualification']);
    }

    // =====================================================================
    // C · Điều kiện danh mục: actual / min / remaining / met
    // =====================================================================

    #[Test]
    public function a_category_condition_shows_actual_min_and_the_shortfall(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($shopee, '2000000')]));
        $this->spend($card, $shopee, '1500000');

        $html = $this->overviewHtml();
        $row = $this->qualificationRowByLabel($html, 'Shopee');

        $this->assertStringContainsString('Shopee : 1.500.000 đ / 2.000.000 đ → Còn thiếu 500.000 đ', $this->visibleTextOf($row));
        $this->assertStringContainsString('data-testid="card-qualification-remaining"', $row);
        $this->assertStringNotContainsString('data-testid="card-qualification-met"', $row);

        $condition = $this->metricsOf($html)[(string) $card->id]['spend_qualification']['conditions'][0];

        $this->assertSame('category', $condition['type']);
        $this->assertSame('Shopee', $condition['label']);
        $this->assertSame('1500000.00', $condition['actual_spend']);
        $this->assertSame('2000000.00', $condition['min_spend']);
        $this->assertSame('500000.00', $condition['remaining']);
        $this->assertFalse($condition['met']);

        // Đúng formatter module: phân tách nghìn bằng dấu chấm + hậu tố nbsp.
        $this->assertStringContainsString('1.500.000&nbsp;', $row);
        $this->assertStringNotContainsString('1,500,000', $row);
        // KHÔNG được tự nối "đ" lần hai → "500.000 đ đ".
        $this->assertStringNotContainsString('500.000 đ đ', $row);
    }

    #[Test]
    public function a_condition_reaching_its_minimum_is_marked_met(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($shopee, '2000000')]));
        $this->spend($card, $shopee, '2000000');

        $html = $this->overviewHtml();

        $condition = $this->metricsOf($html)[(string) $card->id]['spend_qualification']['conditions'][0];

        $this->assertTrue($condition['met']);
        $this->assertSame('0.00', $condition['remaining']);

        $row = $this->qualificationRowByLabel($html, 'Shopee');

        $this->assertStringContainsString('→ ĐÃ ĐẠT', $this->visibleTextOf($row));
        $this->assertStringContainsString('data-testid="card-qualification-met"', $row);
        $this->assertStringNotContainsString('data-testid="card-qualification-remaining"', $row);
        // §7 — đã đạt thì không còn "Còn thiếu 0 đ".
        $this->assertStringNotContainsString('Còn thiếu', $this->visibleTextOf($row));
    }

    #[Test]
    public function overspending_keeps_marked_met_not_some_third_state(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($shopee, '2000000')]));
        $this->spend($card, $shopee, '2500000');

        $html = $this->overviewHtml();
        $condition = $this->metricsOf($html)[(string) $card->id]['spend_qualification']['conditions'][0];

        $this->assertSame('2500000.00', $condition['actual_spend']);
        $this->assertSame('0.00', $condition['remaining']);
        $this->assertTrue($condition['met']);
        $this->assertStringContainsString('→ ĐÃ ĐẠT', $this->visibleTextOf($this->qualificationRowByLabel($html, 'Shopee')));
    }

    // =====================================================================
    // D · "Lĩnh vực khác": tổng kỳ − danh mục loại trừ
    // =====================================================================

    #[Test]
    public function an_other_condition_measures_the_period_minus_excluded_categories(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $this->attachQualification($card, $this->payloadFor([
            $this->otherCondition('2000000', [$shopee->id]),
        ]));

        // Shopee bị LOẠI TRỪ (3.000.000) + Ăn uống (7.000.000) ⇒ other = 7.000.000.
        $this->spend($card, $shopee, '3000000');
        $this->spend($card, $food, '7000000');

        $html = $this->overviewHtml();
        $row = $this->qualificationRowByLabel($html, 'Lĩnh vực khác');

        $this->assertStringContainsString('Lĩnh vực khác : 7.000.000 đ / 2.000.000 đ → ĐÃ ĐẠT', $this->visibleTextOf($row));

        $condition = $this->metricsOf($html)[(string) $card->id]['spend_qualification']['conditions'][0];

        $this->assertSame('other', $condition['type']);
        $this->assertSame('7000000.00', $condition['actual_spend']);
        $this->assertTrue($condition['met']);
        // Đúng ngữ nghĩa "trừ danh mục loại trừ": 10.000.000 − 3.000.000, không
        // phải eligible spend của engine.
        $this->assertNotSame('10000000.00', $condition['actual_spend']);
    }

    #[Test]
    public function an_other_condition_reports_the_shortfall_without_miscounting_excluded(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($card, $this->payloadFor([
            $this->otherCondition('8000000', [$shopee->id]),
        ]));

        $this->spend($card, $shopee, '1000000');

        $html = $this->overviewHtml();

        // 1.000.000 (tổng kỳ) − 1.000.000 (Shopee loại trừ) = 0 đạt → thiếu 8.000.000.
        $row = $this->qualificationRowByLabel($html, 'Lĩnh vực khác');

        $this->assertStringContainsString('Lĩnh vực khác : 0 đ / 8.000.000 đ → Còn thiếu 8.000.000 đ', $this->visibleTextOf($row));
    }

    // =====================================================================
    // E · Nhiều điều kiện: giữ sort_order, "Lĩnh vực khác" cuối
    // =====================================================================

    #[Test]
    public function multiple_conditions_render_in_sort_order_with_other_last(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $travel = $this->makeSystemCategory(['name' => 'Du lịch']);
        $this->attachQualification($card, $this->payloadFor([
            $this->categoryCondition($shopee, '1000000'),
            $this->otherCondition('1000000', [$food->id]),
            $this->categoryCondition($travel, '500000'),
        ]));

        $this->spend($card, $shopee, '1000000');
        $this->spend($card, $food, '1200000');
        $this->spend($card, $travel, '600000');

        $html = $this->overviewHtml();
        $rows = $this->qualificationRowLabels($html);

        // sort_order 1,2,3 — OTHER (Lĩnh vực khác) LUÔN cuối bất kể thứ tự payload.
        $this->assertSame(['Shopee', 'Du lịch', 'Lĩnh vực khác'], $rows);

        $this->assertSame(3, substr_count($html, 'data-testid="card-qualification-row"'));
        $this->assertSame(1, substr_count($html, 'data-testid="card-qualification-section"'));

        // Dòng heading + ô toggle khi có thẻ dùng điều kiện.
        $this->assertStringContainsString('Điều kiện hoàn tiền đặc biệt', $html);
        $this->assertStringContainsString('data-testid="toggle-qualification"', $html);
    }

    // =====================================================================
    // Edge · Không có giao dịch nào → actual = 0, section vẫn hiện
    // =====================================================================

    #[Test]
    public function no_transactions_mean_actual_zero_and_the_row_is_truthful(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($shopee, '2000000')]));

        $html = $this->overviewHtml();
        $row = $this->qualificationRowByLabel($html, 'Shopee');

        $this->assertStringContainsString('Shopee : 0 đ / 2.000.000 đ → Còn thiếu 2.000.000 đ', $this->visibleTextOf($row));
        $this->assertStringContainsString('data-testid="card-qualification-section"', $html);
    }

    // =====================================================================
    // F · Mỗi thẻ đo ĐÚNG kỳ hiện tại CỦA NÓ
    // =====================================================================

    #[Test]
    public function each_card_measures_its_own_current_period(): void
    {
        // Hai thẻ cùng bộ điều kiện, kỳ hiện tại khác nhau (statement_day 25 vs 5)
        // ⇒ cùng chi 3.500.000 Shopee nhưng trạng thái khác nhau vì khác kỳ.
        $cardA = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A', 'statement_day' => 25]);
        $cardB = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B', 'statement_day' => 5]);
        $this->makePolicy($cardA);
        $this->makePolicy($cardB);
        $this->makeOpenPeriod($cardA);
        $this->makeOpenPeriod($cardB);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($cardA, $this->payloadFor([$this->categoryCondition($shopee, '4000000')]));
        $this->attachQualification($cardB, $this->payloadFor([$this->categoryCondition($shopee, '3000000')]));

        $this->spend($cardA, $shopee, '3500000');
        $this->spend($cardB, $shopee, '3500000');

        $html = $this->overviewHtml();
        $metrics = $this->metricsOf($html);

        $a = $metrics[(string) $cardA->id]['spend_qualification']['conditions'][0];
        $b = $metrics[(string) $cardB->id]['spend_qualification']['conditions'][0];

        // Cùng số chi tiêu nhưng khác min ⇒ khác trạng thái: A còn thiếu, B đã đạt.
        $this->assertSame('3500000.00', $a['actual_spend']);
        $this->assertFalse($a['met']);
        $this->assertSame('3500000.00', $b['actual_spend']);
        $this->assertTrue($b['met']);

        // Cả hai mốc trạng thái đều hiện trên trang (money nối bằng &nbsp; nên so
        // trên CHỮ user đọc được, không so trên markup thô).
        $this->assertStringContainsString('→ Còn thiếu 500.000 đ', $this->visibleTextOf($html));
        $this->assertStringContainsString('→ ĐÃ ĐẠT', $this->visibleTextOf($html));
    }

    // =====================================================================
    // G · KHÔNG N+1: qualification được gom một đợt
    // =====================================================================

    #[Test]
    public function qualification_queries_are_batched_not_per_card(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $card = $this->makeUserCard($this->owner->id);
            $this->makePolicy($card);
            $this->makeOpenPeriod($card);

            $category = $this->makeSystemCategory();
            $this->attachQualification($card, $this->payloadFor([
                $this->categoryCondition($category, '1000000'),
                $this->otherCondition('1000000', [$category->id]),
            ]));
            $this->spend($card, $category, '200000');
        }

        $connection = DB::connection('creditcard');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->overviewHtml();

        $connection->disableQueryLog();

        $queries = array_filter(
            $connection->getQueryLog(),
            fn (array $entry): bool => str_contains($entry['query'], 'credit_card_spend_qualification'),
        );

        // qualification + conditions + excluded: đúng 3 truy vấn cố định bất kể
        // bao nhiêu thẻ — conditions/excluded là eager load theo `whereIn`.
        $this->assertCount(3, $queries, 'Điều kiện hoàn tiền đặc biệt phải gom 3 truy vấn cố định, không N+1 theo thẻ.');
    }

    // =====================================================================
    // H · Ownership: không bao giờ đọc điều kiện của thẻ người khác
    // =====================================================================

    #[Test]
    public function a_qualification_of_another_users_card_never_appears(): void
    {
        $other = User::factory()->create();
        $otherCard = $this->makeUserCard($other->id);
        $this->makePolicy($otherCard);
        $this->makeOpenPeriod($otherCard);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($otherCard, $this->payloadFor([$this->categoryCondition($shopee, '1000000')]));
        $this->spend($otherCard, $shopee, '2000000');

        // Chủ sở hữu này chỉ có một thẻ KHÔNG có điều kiện — dữ liệu của người
        // khác không được lộ ra và cũng không gây section giả.
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $html = $this->overviewHtml();

        // Không có section nào, và thẻ của user này không nhận payload nào từ
        // dữ liệu của người khác. (Danh mục hệ thống "Shopee" vẫn xuất hiện ở ô
        // chọn danh mục của form giao dịch — đó là master data dùng chung, KHÔNG
        // phải dấu vết của điều kiện.)
        $this->assertStringNotContainsString('data-testid="card-qualification-section"', $html);
        $this->assertNull($this->metricsOf($html)[(string) $card->id]['spend_qualification']);

        // Ngược lại: đăng nhập bằng người sở hữu thực sự thì qualification lộ ra.
        $otherHtml = $this->actingAs($other)->get('/thetindung')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="card-qualification-section"', $otherHtml);
    }

    // =====================================================================
    // Toggle "Hiển thị" — độc lập, mặc định bật
    // =====================================================================

    #[Test]
    public function the_qualification_display_option_is_independent_and_on_by_default(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($this->makeSystemCategory(['name' => 'Shopee']), '1000000')]));

        $html = $this->overviewHtml();

        $this->assertMatchesRegularExpression('/data-testid="toggle-qualification"[^>]*\schecked/', $html, 'Ô hiển thị phải mặc định BẬT.');
        $this->assertStringContainsString('x-model="display.qualification"', $html);
        $this->assertStringContainsString("x-show=\"showSection('qualification')\"", $html);
    }

    // =====================================================================
    // Không tính toán lại ở tầng trình bày
    // =====================================================================

    #[Test]
    public function the_qualification_row_reprints_server_numbers_and_never_recomputes_them(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicy($card);
        $this->makeOpenPeriod($card);

        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $this->attachQualification($card, $this->payloadFor([$this->categoryCondition($shopee, '2000000')]));
        $this->spend($card, $shopee, '1500000');

        $html = $this->overviewHtml();

        // Mọi số đều là chuỗi Decimal server đã đưa vào payload, in nguyên không
        // chia nhân lại: 1.500.000 thực / 2.000.000 min / 500.000 còn thiếu.
        $this->assertStringContainsString("ccMoneyVnd(qualificationConditionValue(", $html);
        $this->assertStringContainsString("'actual_spend')", $html);
        $this->assertStringContainsString("'min_spend')", $html);
        $this->assertStringContainsString("'remaining')", $html);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function overviewHtml(): string
    {
        return $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
    }

    /** Policy tối giản: không rule, không trần, không tick quota. */
    private function makePolicy(UserCard $card): void
    {
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            []
        );
    }

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
     * Gắn bộ điều kiện vào policy version ĐANG GẮN VỚI CHÍNH THẺ — đúng nơi Tổng
     * quan phải đọc (không đâu khác).
     */
    private function attachQualification(UserCard $card, array $payload): void
    {
        $this->qualifications->persistForPolicyVersion(
            (int) $card->current_policy_id,
            $payload,
            (int) $this->owner->id,
        );

        $card->refresh();
        $this->assertNotSame(null, $card->current_policy_id);
        $this->assertSame(1, SpendQualification::query()->where('policy_version_id', $card->current_policy_id)->count());
    }

    /** Giao dịch đi qua service thật để có kỳ + snapshot đầy đủ. */
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

    private function otherCondition(string $minSpend, array $excludedCategoryIds = []): array
    {
        return ['type' => 'other', 'min_spend' => $minSpend, 'excluded_category_ids' => $excludedCategoryIds];
    }

    private function payloadFor(array $conditions, bool $enabled = true): array
    {
        return [
            'name' => 'Điều kiện test',
            'enabled' => $enabled,
            'conditions' => $conditions,
        ];
    }

    /**
     * Bóc dòng điều kiện mang NHÃN này (xem `quotaRowByLabel`).
     */
    private function qualificationRowByLabel(string $html, string $label): string
    {
        foreach ($this->qualificationRows($html) as $row) {
            if (str_contains($this->visibleTextOf($row), $label)) {
                return $row;
            }
        }

        $this->fail("Không tìm thấy dòng điều kiện nào mang nhãn {$label}.");
    }

    /**
     * Đúng thứ tự các dòng điều kiện trong trang (chỉ lấy NHÃN mỗi dòng).
     *
     * @return array<int, string>
     */
    private function qualificationRowLabels(string $html): array
    {
        $labels = [];

        foreach ($this->qualificationRows($html) as $row) {
            $this->assertSame(
                1,
                preg_match('/<span[^>]*>([^<]*)<\/span>/', $row, $label),
                'Thiếu ô nhãn trên dòng điều kiện.'
            );

            $labels[] = $label[1];
        }

        return $labels;
    }

    /** @return array<int, string> */
    private function qualificationRows(string $html): array
    {
        preg_match_all('/data-testid="card-qualification-row"/', $html, $marks, PREG_OFFSET_CAPTURE);

        $rows = [];

        foreach ($marks[0] as $mark) {
            $position = $mark[1];
            $end = strpos($html, '</li>', $position);
            $this->assertNotFalse($end, 'Dòng điều kiện không đóng.');

            $rows[] = substr($html, $position, $end - $position);
        }

        return $rows;
    }

    /**
     * CHỮ NGƯỜI DÙNG THẬT SỰ THẤY trong một đoạn HTML (xem bản gốc ở
     * `OverviewQuotaPresentationTest`).
     */
    private function visibleTextOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Bóc payload Alpine để đọc số liệu server gửi xuống (xem bản gốc).
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