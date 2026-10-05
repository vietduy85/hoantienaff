<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardOverviewService;
use App\Services\CreditCard\StatementPeriodService;
use App\Support\CreditCard\Decimal;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * "Cashback dự kiến" trên Tổng quan — PHẢI TÍNH THEO TỪNG CATEGORY RULE.
 *
 * ---------------------------------------------------------------------------
 * CASE THẬT ĐÃ SAI: MB ULTIMATE JCB VIET DUY
 * ---------------------------------------------------------------------------
 * Thẻ có `statement_day = 1`, policy một bậc với các rule:
 *
 *   • Y tế & Bệnh viện → 10%, trần danh mục 800.000
 *   • Bảo hiểm           → 10%, trần danh mục 400.000
 *   • fallback           → 0%
 *   • trần tổng của bậc: 800.000
 *
 * Công thức cũ là
 * `tổng chi tiêu × rate CAO NHẤT của bậc`, kẹp trần chung của bậc:
 *
 *   9.000.000 × 10% = 900.000 → kẹp trần bậc 800.000 ⇒ 800.000đ
 *
 * Sai ở hai chỗ, cùng đi ra số TO HƠN thật:
 *   1. Nhân cả tổng chi tiêu với MỘT rate duy nhất — tiền chi của danh mục
 *      rate thấp bị gán sang rate cao.
 *   2. Bỏ qua `max_cashback_per_category_per_period` của từng rule.
 *
 * Đúng phải là: 9.000.000 × 10% = 900.000 → kẹp TRẦN DANH MỤC 400.000 ⇒ 400.000đ
 * (trần bậc 800.000 không đụng tới nên giữ 400.000đ — KHÔNG nổi lên 800.000đ).
 *
 * ---------------------------------------------------------------------------
 * BA ĐIỀU CỐT LÕI ĐƯỢC KHOÁ Ở ĐÂY
 * ---------------------------------------------------------------------------
 *   1. Rate và trần lấy theo TỪNG rule của danh mục/combo mà giao dịch rơi vào,
 *      rồi CỘNG LẠI. Không bao giờ `tổng × một rate`.
 *   2. Trần danh mục áp TRƯỚC; trần tổng của bậc áp SAU, và chỉ kẹp xuống —
 *      không bao giờ nâng con số lên bằng trần.
 *   3. "Dự kiến" là phép TÍNH LẠI ở BẬC ĐÍCH (`desired_spend`), KHÔNG phải số
 *      snapshot của engine. Test cuối cùng cố tình làm hai thứ khác nhau để chứng
 *      minh điều đó.
 *
 * Kỳ trong các test được tạo qua `StatementPeriodService::resolvePeriodForDate()`
 * — tức CHÍNH logic xác định chu kỳ sao kê mà ứng dụng dùng, không ghi tay
 * ranh giới ngày. Nhờ vậy test không thể "đúng" một cách giả tạo rồi lệch với
 * thực tế.
 */
class OverviewExpectedCashbackTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    /** Đúng ngày đang chạy khi phát hiện lỗi trên dữ liệu thật. */
    private const TODAY = '2026-10-05 09:00:00';

    /** Ngày của hai giao dịch trong báo cáo. */
    private const EARLIER = '2026-09-16';

    private const LATER = '2026-10-04';

    private User $owner;

    private StatementPeriodService $periods;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
        $this->periods = app(StatementPeriodService::class);

        $this->atDate(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // =====================================================================
    // KỲ SAO KẾ: anchor quyết định gì?
    // =====================================================================

    /**
     * anchor = 7 ⇒ chu kỳ 07/09 → 06/10. Hôm nay 05/10/2026 nằm trong đó.
     *
     * Cả 16/09 và 04/10 đều thuộc cùng kỳ ⇒ tổng chi tiêu 10.000.000.
     */
    #[Test]
    public function anchor_7_puts_both_mb_dates_in_one_current_period(): void
    {
        $health = $this->category('Y tế & Bệnh viện');
        $insurance = $this->category('Bảo hiểm');

        $card = $this->mbCard(7, [
            ['category' => $health, 'percent' => '10.000', 'cap_cat' => '800000'],
            ['category' => $insurance, 'percent' => '10.000', 'cap_cat' => '400000'],
        ]);
        $this->seedTwoTransactions($card, $health, $insurance);

        $earlierPeriod = $this->periodOf($card, self::EARLIER);
        $laterPeriod = $this->periodOf($card, self::LATER);

        $this->assertSame($earlierPeriod->id, $laterPeriod->id);
        $this->assertSame('2026-09-07', $earlierPeriod->period_start->toDateString());
        $this->assertSame('2026-10-06', $earlierPeriod->period_end->toDateString());

        $current = $this->currentPeriodOf($card);
        $this->assertSame($earlierPeriod->id, $current->id);
        $this->assertSame(2, $current->transactions()->count());

        $this->assertSame('10000000.00', $this->cardNumber($card->id, 'spent'));
        $this->assertSame('500000.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    /**
     * Khi `statement_period_start` (ô "Ngày bắt đầu" của form) khác
     * `statement_day`, khoảng đang chạy phải theo NGÀY BẮT ĐẦU.
     *
     * Đây chính là bug: form ghi cột này nhưng service bỏ qua, nên 12 thẻ rơi
     * về `statement_day` mặc định = 1 và dùng chung một kỳ `02/10 → 01/11`.
     */
    #[Test]
    public function the_configured_period_start_wins_over_the_default_statement_day(): void
    {
        $card = $this->mbCard(1);
        $this->assertSame(1, $card->statement_day);

        // Form lưu "Ngày bắt đầu" = 07/10/2026 (chu kỳ 7 → 6).
        $card->forceFill([
            'statement_period_start' => '2026-10-07',
            'statement_period_end' => '2026-11-06',
        ])->save();

        [$start, $end] = $this->periods->currentBoundaries($card, CarbonImmutable::parse(self::TODAY));

        $this->assertSame(7, $this->periods->anchorDay($card));
        $this->assertSame('2026-09-07', $start->toDateString());
        $this->assertSame('2026-10-06', $end->toDateString());
    }

    /**
     * Giao dịch nằm ở kỳ đã kết thúc thì bị loại khỏi TỔNG và khỏi cashback
     * dự kiến — kể cả khi nó vẫn hiện trong lịch sử giao dịch.
     *
     * Dùng anchor = 1 để 16/09 rơi vào kỳ [01/09 … 30/09] đã kết thúc, còn
     * 04/10 thuộc kỳ hiện tại [01/10 … 31/10].
     */
    #[Test]
    public function a_transaction_in_a_closed_period_is_excluded_from_both_totals(): void
    {
        $health = $this->category('Y tế & Bệnh viện');
        $insurance = $this->category('Bảo hiểm');

        $card = $this->mbCard(1, [
            ['category' => $health, 'percent' => '10.000', 'cap_cat' => '800000'],
            ['category' => $insurance, 'percent' => '10.000', 'cap_cat' => '400000'],
        ]);
        $this->seedTwoTransactions($card, $health, $insurance);

        $this->assertSame('2026-09-01', $this->periodOf($card, self::EARLIER)->period_start->toDateString());
        $this->assertSame('2026-09-30', $this->periodOf($card, self::EARLIER)->period_end->toDateString());

        // Lịch sử vẫn thấy đủ 2 giao dịch — không được "sửa" bằng cách xoá.
        $this->assertSame(2, Transaction::query()->where('user_card_id', $card->id)->count());

        // Nhưng kỳ hiện tại chỉ chứa giao dịch 04/10.
        $this->assertSame('9000000.00', $this->cardNumber($card->id, 'spent'));

        // Y tế (16/09) nằm ngoài kỳ nên không đóng góp gì: chỉ còn Bảo hiểm.
        $this->assertSame('400000.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    // =====================================================================
    // CAP DANH MỤC: lỗi 800.000 của thẻ MB
    // =====================================================================

    /**
     * 9.000.000 × 10% = 900.000 nhưng rule Bảo hiểm có trần 400.000/kỳ
     * ⇒ 400.000. KHÔNG phải 900.000, cũng không phải 800.000.
     */
    #[Test]
    public function a_category_cap_cuts_the_expected_cashback_of_that_category(): void
    {
        $insurance = $this->category('Bảo hiểm');
        // Trần bậc = null để quan sát được trần DANH MỤC một cách riêng biệt.
        $card = $this->mbCard(1, [
            ['category' => $insurance, 'percent' => '10.000', 'cap_cat' => '400000'],
        ], null);

        $this->seedTransaction($card, self::LATER, $insurance, '9000000.00');

        $this->assertSame('9000000.00', $this->cardNumber($card->id, 'spent'));
        $this->assertSame('400000.00', $this->cardNumber($card->id, 'expected_cashback'));

        // Bỏ trần danh mục thì con số trả lại 900.000 ⇒ chứng minh 400.000 đến từ
        // TRẦN, không phải do nhân sai rate.
        $rule = PolicyTierCategory::query()->where('category_id', $insurance->id)->firstOrFail();
        $rule->forceFill(['max_cashback_per_category_per_period' => null])->save();

        $this->assertSame('900000.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    /**
     * Tổng là CỘNG từng rule, mỗi rule đã kẹp trần riêng.
     *
     * Với case MB (kỳ phủ cả 16/09 và 04/10):
     *   Bảo hiểm 9.000.000 × 10% → kẹp 400.000 = 400.000
     *   Y tế     1.000.000 × 10% → trần 800.000, không đủ tới ⇒ 100.000
     *   Tổng = 500.000
     *
     * KHÔNG phải 1.000.000 (10.000.000 × 10%), không phải 900.000, không phải
     * 800.000 (trần tổng của bậc).
     */
    #[Test]
    public function the_total_is_the_sum_of_each_rule_after_its_own_cap(): void
    {
        $health = $this->category('Y tế & Bệnh viện');
        $insurance = $this->category('Bảo hiểm');

        $card = $this->mbCard(15, [
            ['category' => $health, 'percent' => '10.000', 'cap_cat' => '800000'],
            ['category' => $insurance, 'percent' => '10.000', 'cap_cat' => '400000'],
        ]);

        $this->seedTwoTransactions($card, $health, $insurance);

        $this->assertSame('10000000.00', $this->cardNumber($card->id, 'spent'));
        $this->assertSame('500000.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    /**
     * Khóa lỗi "một rate chung": hai danh mục có rate khác nhau thì phải cộng
     * từng cái.
     *
     *   A 6.000.000 × 10% = 600.000
     *   B 4.000.000 ×  5% = 200.000
     *   Tổng               = 800.000
     *
     * KHÔNG phải 10.000.000 × 10% = 1.000.000, cũng không phải × 5% = 500.000.
     * Ở đây cố tình để `tổng × 10%` khác `tổng × 5%` để test bắt được cả hai
     * cách rút gọn sai.
     */
    #[Test]
    public function different_categories_use_their_own_rate(): void
    {
        $tenPercent = $this->category('A');
        $fivePercent = $this->category('B');

        $card = $this->mbCard(15, [
            ['category' => $tenPercent, 'percent' => '10.000'],
            ['category' => $fivePercent, 'percent' => '5.000'],
        ], null);

        $this->seedTransaction($card, self::EARLIER, $tenPercent, '6000000.00');
        $this->seedTransaction($card, self::LATER, $fivePercent, '4000000.00');

        $this->assertSame('10000000.00', $this->cardNumber($card->id, 'spent'));
        $this->assertSame('800000.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    // =====================================================================
    // Trần tổng của bậc: áp SAU, và chỉ kẹp xuống
    // =====================================================================

    /**
     * Trần bậc không được "nâng" con số lên.
     *
     *   Bảo hiểm 9.000.000 × 10% → kẹp 400.000
     *   Y tế     1.000.000 × 10% → 100.000
     *   Tổng = 500.000, trần bậc = 800.000
     *
     * ⇒ 500.000. Biến thành 800.000 chỉ vì trần bậc bằng 800.000 là sai — trần là
     * giới hạn, không phải mục tiêu.
     */
    #[Test]
    public function the_tier_cap_never_inflates_the_sum_up_to_the_cap(): void
    {
        $health = $this->category('Y tế & Bệnh viện');
        $insurance = $this->category('Bảo hiểm');

        $card = $this->mbCard(15, [
            ['category' => $health, 'percent' => '10.000'],
            ['category' => $insurance, 'percent' => '10.000', 'cap_cat' => '400000'],
        ]);

        $this->seedTwoTransactions($card, $health, $insurance);

        $this->assertSame('500000.00', $this->cardNumber($card->id, 'expected_cashback'));

        // Hạ trần bậc xuống dưới tổng thì mới bị kẹp — chứng minh con số trên
        // không phải do sẵn đã chạm trần.
        $this->setTierCap($card, '300000.00');
        $this->assertSame('300000.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    /**
     * Ngược lại: khi tổng các rule VƯỢT trần bậc thì phải bị kẹp.
     */
    #[Test]
    public function the_tier_cap_still_cuts_a_sum_that_exceeds_it(): void
    {
        $health = $this->category('Y tế & Bệnh viện');
        $insurance = $this->category('Bảo hiểm');

        $card = $this->mbCard(15, [
            ['category' => $health, 'percent' => '10.000'],
            ['category' => $insurance, 'percent' => '10.000'],
        ]);

        $this->seedTwoTransactions($card, $health, $insurance);

        // Không trần danh mục: 9.000.000 × 10% + 1.000.000 × 10% = 1.000.000.
        $this->setTierCap($card, null);
        $this->assertSame('1000000.00', $this->cardNumber($card->id, 'expected_cashback'));

        $this->setTierCap($card, '800000.00');
        $this->assertSame('800000.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    // =====================================================================
    // Dự kiến ≠ snapshot
    // =====================================================================

    /**
     * Cashback dự kiến là phép TÍNH LẠI ở BẬC ĐÍCH, không phải số snapshot.
     *
     * Dựng hai tầng để bậc đích và bậc engine LỆCH NHAU:
     *   • Bậc 1 (min 0)          Bảo hiểm 10%, trần 400.000
     *   • Bậc 2 (min 15.000.000) Bảo hiểm 20%, trần 2.000.000
     *
     * `desired_spend = 20.000.000` ⇒ bậc đích = Bậc 2. Chi tiêu thật 9.000.000
     * ⇒ engine chọn Bậc 1 và snapshot 400.000.
     *
     * Dự kiến phải theo Bậc 2: 9.000.000 × 20% = 1.800.000 (dưới trần 2.000.000).
     * Nếu nó đọc snapshot thì ra 400.000 — khác hẳn, nên test bắt được.
     */
    #[Test]
    public function expected_cashback_is_recomputed_at_the_target_tier_and_ignores_the_snapshot(): void
    {
        $insurance = $this->category('Bảo hiểm');

        $card = $this->makeUserCard($this->owner->id, [
            'name' => 'MB Ultimate JCB',
            'statement_day' => 1,
            'payment_due_day' => 5,
            'desired_spend' => '20000000',
        ]);

        $this->makePolicyForCard(
            $card,
            [
                // Hai tầng phải KHÔNG chồng nhau: `resolveTierFromTiers()` trả về
                // tầng đầu tiên khớp, nên để cả hai cùng `max = null` thì Bậc 1
                // nuốt hết và Bậc 2 không bao giờ được chọn.
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 15000000, 'cap_period' => 800000],
                ['name' => 'Bậc 2', 'min' => 15000000, 'max' => null, 'cap_period' => 2000000],
            ],
            [
                ['category_id' => $insurance->id, 'percent' => '10.000', 'cap_cat' => 400000, 'only_tier' => 0],
                ['category_id' => $insurance->id, 'percent' => '20.000', 'cap_cat' => 2000000, 'only_tier' => 1],
            ],
        );

        $transaction = $this->seedTransaction($card, self::LATER, $insurance, '9000000.00');
        $current = $this->currentPeriodOf($card);

        // Giả lập engine đã chốt kỳ ở Bậc 1 (bậc theo chi tiêu thực tế).
        $transaction->forceFill(['cashback_amount_snapshot' => '400000.00'])->save();
        $current->forceFill(['total_cashback' => '400000.00', 'total_eligible_spend' => '9000000.00'])->save();

        // Số THỰC TẾ của engine.
        $this->assertSame('400000.00', $this->cardNumber($card->id, 'cashback'));

        // Số DỰ KIẾN: bậc đích (Bậc 2) × rate của Bậc 2, không phải snapshot.
        $this->assertSame('1800000.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    /**
     * Tổng của cả trang cũng cộng theo từng thẻ, không dùng chung một rate.
     */
    #[Test]
    public function the_page_total_also_sums_each_card_own_rules(): void
    {
        $high = $this->category('A');
        $low = $this->category('B');

        $expensive = $this->mbCard(15, [['category' => $high, 'percent' => '10.000']], null);
        $cheap = $this->mbCard(15, [['category' => $low, 'percent' => '5.000']], null);

        $this->seedTransaction($expensive, self::LATER, $high, '9000000.00');
        $this->seedTransaction($cheap, self::LATER, $low, '1000000.00');

        $summary = $this->overview()['summary'];

        // 900.000 + 50.000. Nếu gộp rồi nhân một rate sẽ là 1.000.000 hoặc 500.000.
        $this->assertSame('950000.00', $summary['expected_cashback']);
    }

    /**
     * Thẻ chưa có giao dịch nào trong kỳ hiện tại ⇒ 0, không phải số âm hay lỗi.
     */
    #[Test]
    public function a_card_with_no_current_transactions_expects_zero(): void
    {
        $insurance = $this->category('Bảo hiểm');
        $card = $this->mbCard(1, [['category' => $insurance, 'percent' => '10.000', 'cap_cat' => '400000']]);

        // Chỉ có giao dịch ở kỳ ĐÃ KẾT THÚC.
        $this->seedTransaction($card, self::EARLIER, $insurance, '9000000.00');

        $this->assertSame('0.00', $this->cardNumber($card->id, 'spent'));
        $this->assertSame('0.00', $this->cardNumber($card->id, 'expected_cashback'));
    }

    // =====================================================================
    // Fixture
    // =====================================================================

    private function category(string $name): Category
    {
        return $this->makeSystemCategory(['name' => $name]);
    }

    /**
     * Thẻ MB: `statementDay` truyền vào để test chọn được chu kỳ mong muốn.
     *
     * `$tierCap` mặc định 800.000 — y hệt dữ liệu thật, và là mẫu số "800.000 đ"
     * trong ảnh chụp màn hình. Test muốn CÔ LẬP hành vi của trần danh mục thì
     * truyền `null`, nếu không trần bậc sẽ cắt ngang trước khi kịp quan sát.
     *
     * @param  array<int, array{category: Category, percent: string, cap_cat?: string|null}>  $rules
     */
    private function mbCard(int $statementDay, array $rules = [], ?string $tierCap = '800000'): UserCard
    {
        $card = $this->makeUserCard($this->owner->id, [
            'name' => 'MB Ultimate JCB',
            'statement_day' => $statementDay,
            'payment_due_day' => 5,
            'desired_spend' => '8000000',
        ]);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => $tierCap]],
            array_map(
                fn (array $rule): array => array_filter([
                    'category_id' => $rule['category']->id,
                    'percent' => $rule['percent'],
                    'cap_cat' => $rule['cap_cat'] ?? null,
                ], fn ($value): bool => $value !== null),
                $rules,
            ),
        );

        // Fallback 0% cho mọi danh mục còn lại, đúng như policy thật.
        $tier = $card->currentPolicy->tiers()->orderBy('sort_order')->firstOrFail();
        PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'scope_type' => PolicyTierCategory::SCOPE_OTHER,
            'category_id' => null,
            'cashback_percent' => '0.000',
            'spend_from' => '0.00',
            'spend_to' => null,
            'is_enabled' => true,
            'counts_toward_tier_cap' => false,
            'is_quota_category' => false,
        ]);

        return $card;
    }

    /** Đúng hai giao dịch trong báo cáo, mỗi giao dịch vào kỳ của chính nó. */
    private function seedTwoTransactions(UserCard $card, Category $earlierCategory, Category $laterCategory): void
    {
        $this->seedTransaction($card, self::EARLIER, $earlierCategory, '1000000.00');
        $this->seedTransaction($card, self::LATER, $laterCategory, '9000000.00');
    }

    /** Giao dịch được gắn vào kỳ mà `StatementPeriodService` suy ra từ ngày của nó. */
    private function seedTransaction(UserCard $card, string $date, Category $category, string $amount): Transaction
    {
        $period = $this->periodOf($card, $date);

        return $period->transactions()->create([
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'transaction_date' => $date,
            'statement_period_id' => $period->id,
            'is_eligible' => true,
        ]);
    }

    /** Kỳ theo đúng ngày, tạo bằng logic thật của ứng dụng. */
    private function periodOf(UserCard $card, string $date): StatementPeriod
    {
        return $this->periods->resolvePeriodForDate($card, CarbonImmutable::parse($date));
    }

    /** Kỳ đang mở chứa hôm nay — cùng tiêu chí mà Overview dùng để lọc. */
    private function currentPeriodOf(UserCard $card): StatementPeriod
    {
        return $this->periodOf($card, CarbonImmutable::parse(self::TODAY));
    }

    private function setTierCap(UserCard $card, ?string $cap): void
    {
        $card->currentPolicy->tiers()->orderBy('sort_order')->firstOrFail()
            ->forceFill(['max_cashback_per_period' => $cap])
            ->save();
    }

    private function overview(): array
    {
        return app(CreditCardOverviewService::class)->forPage((int) $this->owner->id);
    }

    private function cardNumber(int $cardId, string $key): string
    {
        return Decimal::money($this->overview()['cards'][$cardId][$key] ?? '0');
    }

    private function atDate(string $when): void
    {
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }
}
