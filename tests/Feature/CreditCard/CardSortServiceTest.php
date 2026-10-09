<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardCardSortService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * `CreditCardCardSortService` — thứ tự thẻ trên Tổng quan, Quản lý thẻ, Sao kê.
 *
 * Mười một bất biến được khoá ở đây:
 *   1. `manual` là thứ tự `sort_order` (nút lên/xuống ở Quản lý thẻ).
 *   2. Ba chế độ tự động KHÔNG ghi `sort_order` — xem thử không được làm mất thứ
 *      tự user đã sắp.
 *   3. Giá trị lạ trong query/localStorage rơi về `manual`, KHÔNG ném lỗi: hỏng
 *      thứ tự hiển thị thì tệ hơn là trắng trang.
 *   4. `min_spend`: còn thiếu NHIỀU nhất đứng trước; đã đạt mục tiêu đứng sau;
 *      không có mục tiêu đứng cuối.
 *   5. Mục tiêu lấy từ policy của CHÍNH thẻ, chi tiêu lấy từ KỲ HIỆN TẠI của
 *      chính thẻ đó — không cộng chung, mỗi thẻ một mục tiêu và một kỳ riêng.
 *   6. `statement_period`: kỳ HIỆN TẠI chốt sớm nhất đứng trước, suy từ cấu hình
 *      (không cần bản ghi kỳ); thẻ không suy được kỳ đứng cuối.
 *   7. `payment_due`: NGÀY ĐẾN HẠN KẾ TIẾP (chưa qua) gần nhất đứng trước; hạn
 *      đúng hôm nay tính là gần nhất; thẻ không có `payment_due_day` đứng cuối.
 *   8. Trùng giá trị phân định bằng `sort_order` rồi `id` — thứ tự ổn định.
 *   9. KHÔNG mutate collection đầu vào.
 *  10. `statement_period`/`payment_due` KHÔNG đọc bảng kỳ (0 truy vấn); `min_spend`
 *      lấy kỳ của MỌI thẻ trong MỘT truy vấn (không N+1); và KHÔNG tạo kỳ khi đọc.
 *  11. Thẻ không thuộc user không được lọt vào kết quả (phải scope ở tầng truy vấn).
 */
class CardSortServiceTest extends TestCase
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
    // Chuẩn hoá mode
    // =====================================================================

    #[Test]
    public function it_falls_back_to_manual_for_an_unknown_mode(): void
    {
        $service = app(CreditCardCardSortService::class);

        // Lý do: `?sort=` lấy từ query string và `localStorage` — cả hai đều có
        // thể mang giá trị lạ (sửa tay URL, dữ liệu cũ). Hỏng thứ tự hiển thị thì
        // tệ hơn là trắng trang.
        $this->assertSame('manual', $service->normalizeMode(null));
        $this->assertSame('manual', $service->normalizeMode(''));
        $this->assertSame('manual', $service->normalizeMode('xóa-hết-mọi-thứ'));
        $this->assertSame('manual', $service->normalizeMode(['manual']));
        $this->assertSame('manual', $service->normalizeMode(42));

        // Mode hợp lệ đi qua nguyên vẹn, kể cả khi viết HOA / có khoảng trắng.
        $this->assertSame('min_spend', $service->normalizeMode('min_spend'));
        $this->assertSame('payment_due', $service->normalizeMode('payment_due'));
        $this->assertSame('statement_period', $service->normalizeMode('  statement_period '));
    }

    // =====================================================================
    // Manual
    // =====================================================================

    #[Test]
    public function manual_mode_keeps_the_order_the_user_built(): void
    {
        $third = $this->makeCard('C', 30);
        $first = $this->makeCard('A', 10);
        $second = $this->makeCard('B', 20);

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            $this->sortedIds('manual', [$third, $first, $second]),
        );
    }

    #[Test]
    public function manual_mode_breaks_a_sort_order_tie_by_id(): void
    {
        // Cùng `sort_order`: nếu phân định bằng thứ tự truy vấn thì mỗi lần tải
        // trang có thể ra một thứ tự khác nhau.
        $a = $this->makeCard('A', 5);
        $b = $this->makeCard('B', 5);
        $c = $this->makeCard('C', 5);

        $this->assertSame(
            [$a->id, $b->id, $c->id],
            $this->sortedIds('manual', [$c, $b, $a]),
        );
    }

    // =====================================================================
    // Min spend
    // =====================================================================

    #[Test]
    public function min_spend_puts_the_card_that_needs_the_most_first(): void
    {
        // Mục tiêu 1.000.000 cho cả ba; còn thiếu lần lượt 800k / 300k / 100k. Còn
        // thiếu NHIỀU nhất đứng trước ⇒ 800k, 300k, rồi 100k.
        $almost = $this->makeCardWithTarget('Gần đạt', 1000000, 200000, 30);
        $needMost = $this->makeCardWithTarget('Cần nhiều nhất', 1000000, 200000, 10);
        $needLess = $this->makeCardWithTarget('Cần ít', 1000000, 900000, 20);

        // `almost` và `needMost` trùng số tiền thiếu (800k) nên phân định bằng
        // `sort_order`: `needMost` (10) đứng trước `almost` (30). `needLess` chỉ
        // thiếu 100k nên đứng CUỐI, dù nó nằm giữa hai thẻ kia về `sort_order`.
        $this->assertSame(
            [$needMost->id, $almost->id, $needLess->id],
            $this->sortedIds('min_spend', [$almost, $needLess, $needMost]),
        );
    }

    #[Test]
    public function min_spend_ranks_a_card_that_already_hit_its_target_after_one_still_trying(): void
    {
        $reached = $this->makeCardWithTarget('Đã đạt', 1000000, 1500000, 20);
        $trying = $this->makeCardWithTarget('Còn thiếu ít', 1000000, 950000, 10);

        $this->assertSame(
            [$trying->id, $reached->id],
            $this->sortedIds('min_spend', [$reached, $trying]),
        );
    }

    #[Test]
    public function min_spend_puts_a_card_without_a_target_last(): void
    {
        // Không có policy, hoặc mục tiêu = 0 ⇒ không có gì để "còn thiếu", nên
        // không thể xếp theo mục tiêu: đứng cuối thay vì đứng nhầm vào giữa.
        $withTarget = $this->makeCardWithTarget('Có mục tiêu', 1000000, 0, 10);
        $zeroTarget = $this->makeCardWithTarget('Mục tiêu 0', 0, 0, 20);
        $noPolicy = $this->makeCard('Không policy', 30);

        $this->assertSame(
            [$withTarget->id, $zeroTarget->id, $noPolicy->id],
            $this->sortedIds('min_spend', [$noPolicy, $zeroTarget, $withTarget]),
        );
    }

    #[Test]
    public function min_spend_measures_each_card_against_its_own_target_and_own_period(): void
    {
        // Hai thẻ, mục tiêu khác nhau, kỳ riêng. Nếu ai đó gộp chi tiêu chung hoặc
        // dùng chung một mục tiêu thì thứ tự sẽ đảo.
        $target1M = $this->makeCardWithTarget('Mục tiêu 1 triệu', 1000000, 900000, 10);
        $target10M = $this->makeCardWithTarget('Mục tiêu 10 triệu', 10000000, 1000000, 20);

        // Thiếu: 100.000 (thẻ 1) và 9.000.000 (thẻ 2). "Còn thiếu nhiều nhất đứng
        // trước" ⇒ thẻ mục tiêu 10 triệu đứng trước, dù `sort_order` của nó lớn hơn.
        $this->assertSame(
            [$target10M->id, $target1M->id],
            $this->sortedIds('min_spend', [$target1M, $target10M]),
        );
    }

    #[Test]
    public function min_spend_counts_each_card_only_in_its_own_period(): void
    {
        $card = $this->makeCardWithTarget('Một thẻ một kỳ', 1000000, 0, 10);

        // Kỳ CŨ đã đạt mục tiêu, kỳ HIỆN TẠI (đã seed ở trên) thì chưa ⇒ vẫn phải
        // xếp vào nhóm "còn thiếu". Gộp mọi kỳ lại sẽ làm nó biến mất khỏi danh
        // sách việc cần làm.
        $this->makeStatementPeriod($card, [
            'period_start' => '2020-01-01',
            'period_end' => '2020-01-31',
            'statement_date' => '2020-01-31',
            'total_eligible_spend' => 5000000,
        ]);

        $this->assertSame([$card->id], $this->sortedIds('min_spend', [$card]));
    }

    // =====================================================================
    // Statement period
    // =====================================================================

    #[Test]
    public function statement_period_puts_the_earliest_closing_period_first(): void
    {
        // Ngày chốt kỳ phụ thuộc lịch, nên không đoán "ngày chốt nhỏ thì kỳ chốt sớm":
        // ngày 28 và ngày 3 cho hai kỳ chốt theo hai hướng ngược nhau. Fixture
        // được tự tính lại rồi dùng chính dữ liệu đó làm chuẩn — test khoá đúng
        // quy tắc "xếp theo ngày chốt tăng dần", không khoá lịch.
        $day28 = $this->makeCard('Chốt ngày 28', 10, ['statement_day' => 28]);
        $day3 = $this->makeCard('Chốt ngày 3', 20, ['statement_day' => 3]);
        $day15 = $this->makeCard('Chốt ngày 15', 30, ['statement_day' => 15]);

        $cards = [$day28, $day3, $day15];

        foreach ($cards as $card) {
            $this->seedCurrentPeriod($card, ['payment_due_date' => null]);
        }

        $ends = [];

        foreach ($cards as $card) {
            [, $end] = $this->boundsOf($card);
            $ends[(int) $card->id] = $end->timestamp;
        }

        $this->assertCount(
            3,
            array_unique($ends),
            'Ba thẻ phải có ba ngày chốt khác nhau, nếu không test là vô nghĩa.',
        );

        $expected = collect($cards)
            ->sortBy(fn (UserCard $card): int => $ends[(int) $card->id])
            ->map(fn (UserCard $card): int => (int) $card->id)
            ->values()
            ->all();

        $this->assertSame($expected, $this->sortedIds('statement_period', $cards));
    }

    #[Test]
    public function statement_period_does_not_require_a_stored_period(): void
    {
        // KHÔNG seed bản ghi kỳ nào: ngày chốt vẫn phải suy được từ cấu hình thẻ.
        // Đây là lỗi gốc — bản cũ chỉ đọc `period_end` từ DB nên thẻ chưa có bản
        // ghi kỳ bị đẩy xuống cuối và thứ tự gần như ngẫu nhiên.
        $early = $this->makeCard('Chốt sớm', 20, ['statement_day' => 5]);
        $late = $this->makeCard('Chốt muộn', 10, ['statement_day' => 25]);

        $today = CarbonImmutable::parse('2026-10-09');

        $earlyEnd = app(StatementPeriodService::class)->currentBoundaries($early, $today)[1];
        $lateEnd = app(StatementPeriodService::class)->currentBoundaries($late, $today)[1];

        // Khoá đúng theo ngày chốt SUY RA, không theo `sort_order` (ngược nhau).
        $expected = $earlyEnd->lessThan($lateEnd)
            ? [$early->id, $late->id]
            : [$late->id, $early->id];

        $this->assertSame($expected, $this->sortedIdsAt('statement_period', [$late, $early], $today));
    }

    #[Test]
    public function statement_period_puts_a_card_without_period_config_last(): void
    {
        $configured = $this->makeCard('Có cấu hình', 10, ['statement_day' => 10]);

        // Không có CẢ `statement_period_start` lẫn `statement_day`: không thể suy
        // kỳ. Gán trong bộ nhớ vì cột `statement_day` là NOT NULL ở schema.
        $broken = $this->makeCard('Thiếu cấu hình', 1);
        $broken->statement_day = null;
        $broken->statement_period_start = null;

        $this->assertSame(
            [$configured->id, $broken->id],
            $this->sortedIds('statement_period', [$broken, $configured]),
        );
    }

    #[Test]
    public function statement_period_handles_periods_ending_in_different_months(): void
    {
        // anchor 25: hôm nay 09/10 < 25 ⇒ kỳ mở 25/09 → chốt 24/10.
        // anchor 05: hôm nay ≥ 05 ⇒ kỳ mở 05/10 → chốt 04/11.
        // Kỳ chốt trong tháng 10 phải đứng trước kỳ chốt sang tháng 11.
        $thisMonth = $this->makeCard('Chốt 24/10', 20, ['statement_day' => 25]);
        $nextMonth = $this->makeCard('Chốt 04/11', 10, ['statement_day' => 5]);

        $this->assertSame(
            [$thisMonth->id, $nextMonth->id],
            $this->sortedIdsAt('statement_period', [$nextMonth, $thisMonth], CarbonImmutable::parse('2026-10-09')),
        );
    }

    #[Test]
    public function statement_period_handles_a_month_end_anchor(): void
    {
        // anchor 31 ở tháng 2 bị clamp về 28 (2026 không nhuận): kỳ hiện tại
        // 31/01 → 27/02. anchor 5 cho kỳ 05/02 → 04/03. Kỳ tháng 2 đứng trước.
        $monthEnd = $this->makeCard('Anchor 31', 20, ['statement_day' => 31]);
        $normal = $this->makeCard('Anchor 5', 10, ['statement_day' => 5]);

        $this->assertSame(
            [$monthEnd->id, $normal->id],
            $this->sortedIdsAt('statement_period', [$normal, $monthEnd], CarbonImmutable::parse('2026-02-15')),
        );
    }

    #[Test]
    public function statement_period_breaks_an_equal_end_tie_by_sort_order_then_id(): void
    {
        // Cùng `statement_period_start` ⇒ cùng ngày chốt; phân định bằng
        // `sort_order` để thứ tự ổn định giữa các lần tải.
        $b = $this->makeCard('B', 5, ['statement_period_start' => '2026-10-01']);
        $a = $this->makeCard('A', 5, ['statement_period_start' => '2026-10-01']);

        $this->assertSame(
            [$b->id, $a->id],
            $this->sortedIdsAt('statement_period', [$a, $b], CarbonImmutable::parse('2026-10-09')),
        );
    }

    // =====================================================================
    // Payment due
    // =====================================================================

    #[Test]
    public function payment_due_orders_by_the_next_upcoming_due_date(): void
    {
        // Ví dụ đúng trong yêu cầu: hôm nay 09/10/2026, cùng anchor mùng 1.
        //   A hạn 05/10 (đã qua)  ⇒ hạn kế tiếp 05/11
        //   B hạn 12/10            ⇒ 12/10
        //   C hạn 20/10            ⇒ 20/10
        //   D hạn 05/11            ⇒ 05/11
        // Kỳ vọng: B, C, A, D — A và D cùng ngày, phân định bằng `sort_order`.
        $today = CarbonImmutable::parse('2026-10-09');

        $a = $this->makeCard('A hạn 05/10', 10, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 5]);
        $b = $this->makeCard('B hạn 12/10', 20, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 12]);
        $c = $this->makeCard('C hạn 20/10', 30, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 20]);
        $d = $this->makeCard('D hạn 05/11', 5, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 5]);

        $this->assertSame(
            [$b->id, $c->id, $d->id, $a->id],
            $this->sortedIdsAt('payment_due', [$a, $d, $c, $b], $today),
        );
    }

    #[Test]
    public function payment_due_puts_a_due_date_today_first(): void
    {
        // Hạn ĐÚNG HÔM NAY vẫn là gần nhất (0 ngày nữa), không bị coi là "đã qua".
        $today = CarbonImmutable::parse('2026-10-09');

        $dueToday = $this->makeCard('Đến hạn hôm nay', 30, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 9]);
        $dueNextWeek = $this->makeCard('Đến hạn 16/10', 10, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 16]);

        $this->assertSame(
            [$dueToday->id, $dueNextWeek->id],
            $this->sortedIdsAt('payment_due', [$dueNextWeek, $dueToday], $today),
        );
    }

    #[Test]
    public function payment_due_ignores_a_stale_stored_due_date(): void
    {
        $today = CarbonImmutable::parse('2026-10-09');

        $card = $this->makeCard('A', 10, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 12]);
        // Ghi hạn CŨ/mâu thuẫn vào bản ghi kỳ: sort không được đọc nó.
        $this->seedCurrentPeriod($card, ['payment_due_date' => '2020-01-01']);

        $other = $this->makeCard('B', 20, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 20]);

        // Hạn THẬT kế tiếp của A là 12/10 nên A đứng trước B, bất kể bản ghi cũ.
        $this->assertSame(
            [$card->id, $other->id],
            $this->sortedIdsAt('payment_due', [$other, $card], $today),
        );
    }

    #[Test]
    public function payment_due_puts_a_card_without_a_due_day_last(): void
    {
        $today = CarbonImmutable::parse('2026-10-09');

        // `payment_due_day` là NOT NULL ở schema nên gán null trong bộ nhớ để mô
        // phỏng dữ liệu hỏng; sort chỉ đọc thuộc tính nên phép thử vẫn hợp lệ.
        $noDue = $this->makeCard('Không có hạn', 1, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 12]);
        $noDue->payment_due_day = null;

        $soon = $this->makeCard('Sắp đến hạn', 30, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 20]);

        $this->assertSame(
            [$soon->id, $noDue->id],
            $this->sortedIdsAt('payment_due', [$noDue, $soon], $today),
        );
    }

    #[Test]
    public function payment_due_never_uses_spending_deadline_day(): void
    {
        $today = CarbonImmutable::parse('2026-10-09');

        // `spending_deadline_day` cố tình đặt NGƯỢC với thứ tự hạn thanh toán:
        // nếu code nhầm dùng nó thì thứ tự sẽ đảo.
        $later = $this->makeCard('Hạn 20/10', 10, [
            'statement_period_start' => '2026-10-01',
            'payment_due_day' => 20,
            'spending_deadline_day' => 1,
        ]);
        $sooner = $this->makeCard('Hạn 10/10', 20, [
            'statement_period_start' => '2026-10-01',
            'payment_due_day' => 10,
            'spending_deadline_day' => 28,
        ]);

        $this->assertSame(
            [$sooner->id, $later->id],
            $this->sortedIdsAt('payment_due', [$later, $sooner], $today),
        );
    }

    #[Test]
    public function payment_due_clamps_the_due_day_to_the_last_day_of_a_short_month(): void
    {
        // Hôm nay 10/02/2026 (không nhuận). Kỳ trước mở 01/01 → chốt 31/01.
        // Hạn nằm ở tháng 2 (28 ngày):
        //   due 27 → 27/02
        //   due 31 → clamp 28/02
        //   due 30 → clamp 28/02
        // 31 và 30 cùng 28/02 ⇒ phân định `sort_order` (31 có sort_order nhỏ hơn).
        // Nếu KHÔNG clamp thì 30→02/03, 31→03/03 và thứ tự sẽ là 27,30,31.
        $day27 = $this->makeCard('Hạn 27', 10, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 27]);
        $day31 = $this->makeCard('Hạn 31', 5, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 31]);
        $day30 = $this->makeCard('Hạn 30', 6, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 30]);

        $this->assertSame(
            [$day27->id, $day31->id, $day30->id],
            $this->sortedIdsAt('payment_due', [$day30, $day31, $day27], CarbonImmutable::parse('2026-02-10')),
        );
    }

    #[Test]
    public function payment_due_uses_the_29th_in_leap_february(): void
    {
        // Hôm nay 10/02/2028 (nhuận). Hạn 28/02 dù `payment_due_day` = 31 (clamp
        // 29/02) phải đứng SAU ngày 28/02 — nếu code cứng 28 sẽ hoà và sort_order
        // (31 có sort_order nhỏ hơn) đẩy 31 lên trước, làm test đỏ.
        $day28 = $this->makeCard('Hạn 28', 10, ['statement_period_start' => '2028-01-01', 'payment_due_day' => 28]);
        $day31 = $this->makeCard('Hạn 31', 5, ['statement_period_start' => '2028-01-01', 'payment_due_day' => 31]);

        $this->assertSame(
            [$day28->id, $day31->id],
            $this->sortedIdsAt('payment_due', [$day31, $day28], CarbonImmutable::parse('2028-02-10')),
        );
    }

    #[Test]
    public function payment_due_is_stable_for_equal_due_dates(): void
    {
        $today = CarbonImmutable::parse('2026-10-09');

        $second = $this->makeCard('B', 20, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 12]);
        $first = $this->makeCard('A', 10, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 12]);

        // Hai lần sort với thứ tự đầu vào khác nhau cho CÙNG kết quả.
        $this->assertSame(
            [$first->id, $second->id],
            $this->sortedIdsAt('payment_due', [$second, $first], $today),
        );
        $this->assertSame(
            [$first->id, $second->id],
            $this->sortedIdsAt('payment_due', [$first, $second], $today),
        );
    }

    // =====================================================================
    // Không mutate, không N+1, không tạo kỳ
    // =====================================================================

    #[Test]
    public function previewing_a_sort_mode_changes_nothing_on_disk(): void
    {
        $a = $this->makeCard('A', 1);
        $b = $this->makeCard('B', 2);
        $this->seedCurrentPeriod($b, ['payment_due_date' => CarbonImmutable::now()->subDay()->toDateString()]);

        $service = app(CreditCardCardSortService::class);
        $input = collect([$a->fresh(), $b->fresh()]);

        foreach (CreditCardCardSortService::modes() as $mode => $_label) {
            $service->sort($mode, $input);
        }

        // `sort_order` trong DB giữ nguyên: xem thử một chế độ không được âm thầm
        // ghi đè thứ tự user đã sắp ở Quản lý thẻ.
        $this->assertSame(1, (int) $a->refresh()->sort_order);
        $this->assertSame(2, (int) $b->refresh()->sort_order);

        $this->assertSame([$a->id, $b->id], $input->map(fn (UserCard $c) => (int) $c->id)->all());
    }

    #[Test]
    public function sorting_returns_a_new_collection_and_leaves_the_input_alone(): void
    {
        // `makeCardWithTarget` đã seed kỳ hiện tại với số chi tiêu cho trước.
        $a = $this->makeCardWithTarget('A', 1000000, 0, 10);
        $b = $this->makeCardWithTarget('B', 10000000, 0, 20);

        $service = app(CreditCardCardSortService::class);
        $input = collect([$a, $b]);

        $result = $service->sort('min_spend', $input);

        // `$input` vẫn giữ thứ tự cũ (B thiếu nhiều hơn nên phải đứng trước khi
        // sắp xếp, nhưng collection đầu vào không được bị đổi).
        $this->assertSame([$a->id, $b->id], $input->map(fn (UserCard $c) => (int) $c->id)->all());
        $this->assertSame([$b->id, $a->id], $result->map(fn (UserCard $c) => (int) $c->id)->all());
        $this->assertSame([0, 1], $result->keys()->all(), 'Kết quả phải được reindex.');
    }

    #[Test]
    public function statement_period_and_payment_due_read_no_statement_period_rows(): void
    {
        // Hai chế độ này suy ngày từ CẤU HÌNH thẻ nên không cần chạm bảng kỳ —
        // nhờ vậy thẻ chưa có bản ghi kỳ vẫn đúng và mở trang không sinh bản ghi.
        $cards = collect();

        for ($i = 1; $i <= 6; $i++) {
            $cards->push($this->makeCard("Thẻ {$i}", $i, ['statement_period_start' => '2026-10-01', 'payment_due_day' => 5 + $i]));
        }

        $service = app(CreditCardCardSortService::class);

        $connection = DB::connection('creditcard');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $service->sort('statement_period', $cards);
        $service->sort('payment_due', $cards);

        $connection->disableQueryLog();

        $periodQueries = array_filter(
            $connection->getQueryLog(),
            fn (array $entry): bool => str_contains($entry['query'], 'credit_card_statement_periods'),
        );

        $this->assertCount(0, $periodQueries, 'Hai chế độ này không được đọc bảng kỳ.');
    }

    #[Test]
    public function min_spend_reads_every_card_period_in_one_query(): void
    {
        $cards = [];

        for ($i = 1; $i <= 6; $i++) {
            $card = $this->makeCard("Thẻ {$i}", $i, ['statement_day' => 10 + $i]);
            $this->seedCurrentPeriod($card);
            $cards[] = $card;
        }

        $service = app(CreditCardCardSortService::class);

        $collection = UserCard::query()
            ->whereIn('id', array_map(fn (UserCard $card): int => (int) $card->id, $cards))
            ->with('currentPolicy')
            ->get();

        // Nạp quan hệ/`min_spend` một lượt trước, rồi bật query log để chỉ tính
        // truy vấn của lần sắp xếp.
        $service->sort('min_spend', $collection);

        $connection = DB::connection('creditcard');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $service->sort('min_spend', $collection);

        $connection->disableQueryLog();

        $periodQueries = array_filter(
            $connection->getQueryLog(),
            fn (array $entry): bool => str_contains($entry['query'], 'credit_card_statement_periods'),
        );

        // Sáu thẻ, sáu ranh giới kỳ khác nhau. Nếu mỗi thẻ một truy vấn thì ở đây
        // là 6; phải gộp còn MỘT.
        $this->assertCount(
            1,
            $periodQueries,
            'Kỳ hiện tại của mọi thẻ phải lấy trong MỘT truy vấn, không N+1.',
        );
    }

    // =====================================================================
    // Nhãn UI khớp logic
    // =====================================================================

    #[Test]
    public function mode_labels_describe_the_implemented_order(): void
    {
        $modes = CreditCardCardSortService::modes();

        // Bốn lựa chọn dùng chung cho cả ba trang.
        $this->assertSame(
            ['manual', 'min_spend', 'statement_period', 'payment_due'],
            array_keys($modes),
        );

        // Nhãn phản ánh đúng logic: kỳ sao kê "chốt" (ngày kết thúc kỳ) và "hạn
        // thanh toán" (không phải hạn chót chi tiêu).
        $this->assertStringContainsString('chốt', $modes['statement_period']);
        $this->assertStringContainsString('thanh toán', $modes['payment_due']);
    }

    #[Test]
    public function sorting_never_creates_a_statement_period(): void
    {
        $card = $this->makeCard('Chưa có kỳ', 10);
        $service = app(CreditCardCardSortService::class);

        $this->assertSame(0, StatementPeriod::query()->count());

        foreach (CreditCardCardSortService::modes() as $mode => $_label) {
            $service->sort($mode, collect([$card]));
        }

        // Chỉ ĐỌC thứ tự, không được sinh bản ghi kỳ — cùng nguyên tắc với mở
        // trang Tổng quan và Sao kê.
        $this->assertSame(0, StatementPeriod::query()->count());
    }

    // =====================================================================
    // Phạm vi dữ liệu
    // =====================================================================

    #[Test]
    public function a_strangers_card_is_never_in_a_sorted_list(): void
    {
        $mine = $this->makeCard('Thẻ của tôi', 1);

        // Thẻ của user KHÁC — phải tạo trực tiếp, đừng qua `makeCard` (helper đó
        // luôn gán cho owner và sẽ biến test này thành xanh nhầm).
        $theirs = $this->makeUserCard((int) $this->stranger->id, [
            'name' => 'Thẻ người khác',
            'sort_order' => 0,
        ]);

        $result = $this->ownedCards()
            ->pipe(fn ($cards) => app(CreditCardCardSortService::class)->sort('min_spend', $cards));

        $ids = $result->map(fn (UserCard $card): int => (int) $card->id)->all();

        $this->assertContains((int) $mine->id, $ids);
        $this->assertNotContains((int) $theirs->id, $ids);
    }

    #[Test]
    public function an_empty_list_stays_empty(): void
    {
        $result = app(CreditCardCardSortService::class)->sort('min_spend', collect());

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->all());
    }

    // =====================================================================
    // Helper
    // =====================================================================

    private function ownedCards()
    {
        return UserCard::query()
            ->ownedBy((int) $this->owner->id)
            ->with('currentPolicy')
            ->ordered()
            ->get();
    }

    /**
     * @param  array<int, UserCard>  $cards
     * @return array<int, int>
     */
    private function sortedIds(string $mode, array $cards): array
    {
        return app(CreditCardCardSortService::class)
            ->sort($mode, collect($cards))
            ->map(fn (UserCard $card): int => (int) $card->id)
            ->all();
    }

    /**
     * Như `sortedIds()` nhưng cố định "hôm nay" để test không phụ thuộc đồng hồ
     * chạy thực tế.
     *
     * @param  array<int, UserCard>  $cards
     * @return array<int, int>
     */
    private function sortedIdsAt(string $mode, array $cards, CarbonImmutable $today): array
    {
        return app(CreditCardCardSortService::class)
            ->sort($mode, collect($cards), $today)
            ->map(fn (UserCard $card): int => (int) $card->id)
            ->all();
    }

    private function makeCard(string $name, int $sortOrder, array $attributes = []): UserCard
    {
        return $this->makeUserCard($this->owner->id, array_merge([
            'name' => $name,
            'sort_order' => $sortOrder,
        ], $attributes));
    }

    /**
     * Thẻ đã có policy với `min_total_spend` cho trước và kỳ hiện tại có số chi
     * tiêu tương ứng.
     *
     * `sortOrder` PHẢI truyền tường minh: phần lớn nhóm của `min_spend` phân định
     * bằng `sort_order`, nên để ngẫu nhiên là test chạy lúc xanh lúc đỏ.
     */
    private function makeCardWithTarget(string $name, int $target, float $eligibleSpend, int $sortOrder): UserCard
    {
        $card = $this->makeCard($name, $sortOrder);
        $category = $this->makeSystemCategory();

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']],
            ['min_total_spend' => $target],
        );

        $this->seedCurrentPeriod($card, [
            'total_eligible_spend' => $eligibleSpend,
        ]);

        return $card;
    }

    /**
     * Tạo bản ghi kỳ TRÙNG với ranh giới `currentBoundaries()` của thẻ.
     *
     * Fixture `makeStatementPeriod` mặc định dùng khoảng ngày cứng nên không khớp
     * kỳ hiện tại của thẻ — mà bộ lọc một truy vấn của sort service chỉ nhận kỳ có
     * `period_start`/`period_end` khớp CHÍNH XÁC. Fixture sai chỗ này thì mọi thẻ
     * rơi vào nhóm "chưa có kỳ" và test xanh nhầm.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function seedCurrentPeriod(UserCard $card, array $attributes = []): StatementPeriod
    {
        [$start, $end] = $this->boundsOf($card);

        return $this->makeStatementPeriod($card, array_merge([
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'statement_date' => $end->toDateString(),
            'payment_due_date' => $end->addDays(10)->toDateString(),
        ], $attributes));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function boundsOf(UserCard $card): array
    {
        return app(StatementPeriodService::class)->currentBoundaries($card, CarbonImmutable::now());
    }
}
