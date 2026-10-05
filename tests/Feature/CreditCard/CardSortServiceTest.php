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
 *   6. `statement_period`: kỳ chốt sớm nhất đứng trước; chưa có kỳ đứng cuối.
 *   7. `payment_due`: hạn sớm nhất đứng trước (hạn đã trôi qua nằm trước hạn
 *      tương lai); chưa có hạn đứng cuối.
 *   8. Trùng giá trị phân định bằng `sort_order` rồi `id` — thứ tự ổn định.
 *   9. KHÔNG mutate collection đầu vào.
 *  10. Một truy vấn lấy kỳ cho MỌI thẻ (không N+1) và KHÔNG tạo kỳ khi đọc.
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
    public function statement_period_puts_a_card_without_a_period_last(): void
    {
        $noPeriod = $this->makeCard('Chưa có kỳ', 10);
        $withPeriod = $this->makeCard('Có kỳ', 20);
        $this->seedCurrentPeriod($withPeriod);

        $this->assertSame(
            [$withPeriod->id, $noPeriod->id],
            $this->sortedIds('statement_period', [$noPeriod, $withPeriod]),
        );
    }

    // =====================================================================
    // Payment due
    // =====================================================================

    #[Test]
    public function payment_due_puts_an_overdue_card_before_a_future_one(): void
    {
        $today = CarbonImmutable::now();

        $overdue = $this->makeCard('Quá hạn', 10);
        $this->seedCurrentPeriod($overdue, ['payment_due_date' => $today->subDays(3)->toDateString()]);

        $future = $this->makeCard('Còn hạn', 20);
        $this->seedCurrentPeriod($future, ['payment_due_date' => $today->addDays(5)->toDateString()]);

        // Hạn đã TRÔN qua phải đứng trước, không phải đẩy xuống cuối như "chưa có hạn".
        $this->assertSame(
            [$overdue->id, $future->id],
            $this->sortedIds('payment_due', [$future, $overdue]),
        );
    }

    #[Test]
    public function payment_due_puts_a_card_without_a_due_date_last(): void
    {
        $today = CarbonImmutable::now();

        $noDue = $this->makeCard('Chưa có hạn', 10);
        $this->seedCurrentPeriod($noDue, ['payment_due_date' => null]);

        $noPeriod = $this->makeCard('Chưa có kỳ', 20);

        $soon = $this->makeCard('Sắp đến hạn', 30);
        $this->seedCurrentPeriod($soon, ['payment_due_date' => $today->addDays(2)->toDateString()]);

        $this->assertSame(
            [$soon->id, $noDue->id, $noPeriod->id],
            $this->sortedIds('payment_due', [$noPeriod, $noDue, $soon]),
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
    public function sorting_reads_every_current_period_in_one_query(): void
    {
        $cards = [];

        for ($i = 1; $i <= 6; $i++) {
            $card = $this->makeCard("Thẻ {$i}", $i, ['statement_day' => 10 + $i]);
            $this->seedCurrentPeriod($card);
            $cards[] = $card;
        }

        $service = app(CreditCardCardSortService::class);

        // Lấy đúng thứ màn hình thật dùng: `EloquentCollection` có `currentPolicy`
        // eager-load sẵn.
        $collection = UserCard::query()
            ->whereIn('id', array_map(fn (UserCard $card): int => (int) $card->id, $cards))
            ->with('currentPolicy')
            ->get();

        // Nạp quan hệ trước rồi bật query log, để không tính nhầm truy vấn của
        // bản thân `get()`/`load()` vào số truy vấn của việc sắp xếp.
        $service->sort('payment_due', $collection);

        $connection = DB::connection('creditcard');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $service->sort('statement_period', $collection);

        $connection->disableQueryLog();

        $periodQueries = array_filter(
            $connection->getQueryLog(),
            fn (array $entry): bool => str_contains($entry['query'], 'credit_card_statement_periods'),
        );

        // Sáu thẻ, mỗi thẻ một `statement_day` khác nhau nên ranh giới kỳ khác
        // nhau. Nếu mỗi thẻ một truy vấn thì ở đây là 6.
        $this->assertCount(
            1,
            $periodQueries,
            'Kỳ hiện tại của mọi thẻ phải lấy trong MỘT truy vấn, không N+1.',
        );
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
