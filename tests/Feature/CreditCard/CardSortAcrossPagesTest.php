<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Thứ tự thẻ phải GIỐNG NHAU trên cả ba trang cho cùng một lựa chọn sắp xếp.
 *
 * Ba trang (Tổng quan `/thetindung`, Quản lý thẻ, Sao kê) dùng chung
 * `CreditCardCardSortService`, nên đây kiểm tra ở tầng HTTP đúng cái mà người
 * dùng thấy: thứ tự dòng trong HTML, không chỉ kết quả của service.
 *
 * Thời gian được ghim để kết quả không phụ thuộc ngày chạy thật.
 */
class CardSortAcrossPagesTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    /**
     * Hôm nay 09/10/2026.
     *
     * Ba thẻ cố tình đặt cấu hình sao cho thứ tự `payment_due` KHÁC hẳn
     * `statement_period`, để chứng minh mỗi chế độ thực sự dùng đúng logic của nó
     * (nếu nhầm/copy công thức thì hai test sẽ không cùng đúng được):
     *
     *   A: anchor 20/10 (kỳ Sep20→Oct19), hạn 25/10
     *   B: anchor 01/10 (kỳ Oct01→Oct31), hạn 10/10  ← hạn gần nhất
     *   C: anchor 11/10 (kỳ Sep11→Oct10), hạn 05/11
     *
     *   statement_period (chốt sớm nhất trước): C (10/10), A (19/10), B (31/10)
     *   payment_due      (hạn gần nhất trước) : B (10/10), A (25/10), C (05/11)
     */
    private User $owner;

    private UserCard $a;

    private UserCard $b;

    private UserCard $c;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        Carbon::setTestNow('2026-10-09');
        CarbonImmutable::setTestNow('2026-10-09');

        $this->owner = User::factory()->create();

        // `sort_order` đặt ngược hẳn với cả hai thứ tự tự động: nếu trang nào bỏ
        // qua `?sort=` và rơi về `manual`, thứ tự sẽ là A, B, C và test đỏ ngay.
        $this->a = $this->makeCard('CARD_A', 1, '2026-10-20', 25);
        $this->b = $this->makeCard('CARD_B', 2, '2026-10-01', 10);
        $this->c = $this->makeCard('CARD_C', 3, '2026-10-11', 5);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // =====================================================================
    // Cùng một lựa chọn sắp xếp ⇒ cùng một thứ tự trên ba trang
    // =====================================================================

    #[Test]
    public function payment_due_order_matches_across_all_three_pages(): void
    {
        $expected = [$this->b->id, $this->a->id, $this->c->id];

        $overview = $this->overviewOrder('payment_due');
        $manage = $this->manageOrder('payment_due');
        $statements = $this->statementsOrder('payment_due');

        $this->assertSame($expected, $overview, 'Tổng quan sai thứ tự theo hạn thanh toán.');
        $this->assertSame($expected, $manage, 'Quản lý thẻ sai thứ tự theo hạn thanh toán.');
        $this->assertSame($expected, $statements, 'Sao kê sai thứ tự theo hạn thanh toán.');
    }

    #[Test]
    public function statement_period_order_matches_across_all_three_pages(): void
    {
        $expected = [$this->c->id, $this->a->id, $this->b->id];

        $this->assertSame($expected, $this->overviewOrder('statement_period'));
        $this->assertSame($expected, $this->manageOrder('statement_period'));
        $this->assertSame($expected, $this->statementsOrder('statement_period'));
    }

    #[Test]
    public function all_three_pages_render_every_card_for_both_automatic_modes(): void
    {
        $allIds = [$this->a->id, $this->b->id, $this->c->id];
        sort($allIds);

        foreach (['payment_due', 'statement_period'] as $mode) {
            foreach ([
                'Tổng quan' => $this->overviewOrder($mode),
                'Quản lý thẻ' => $this->manageOrder($mode),
                'Sao kê' => $this->statementsOrder($mode),
            ] as $page => $order) {
                $sorted = $order;
                sort($sorted);

                $this->assertSame($allIds, $sorted, "{$page} thiếu/thừa thẻ ở chế độ {$mode}.");
            }
        }
    }

    // =====================================================================
    // Sắp xếp chỉ đổi thứ tự hiển thị — không ghi dữ liệu, không tạo kỳ
    // =====================================================================

    #[Test]
    public function sorting_does_not_touch_sort_order_or_create_statement_periods(): void
    {
        $before = UserCard::whereIn('id', [$this->a->id, $this->b->id, $this->c->id])
            ->pluck('sort_order', 'id')
            ->all();

        foreach (['payment_due', 'statement_period'] as $mode) {
            $this->overviewOrder($mode);
            $this->manageOrder($mode);
            $this->statementsOrder($mode);
        }

        $after = UserCard::whereIn('id', [$this->a->id, $this->b->id, $this->c->id])
            ->pluck('sort_order', 'id')
            ->all();

        $this->assertSame($before, $after, 'Ba chế độ tự động KHÔNG được ghi lại `sort_order`.');

        // Mở trang chỉ được ĐỌC kỳ, tuyệt đối không tạo bản ghi kỳ.
        $this->assertSame(
            0,
            StatementPeriod::whereIn('user_card_id', [$this->a->id, $this->b->id, $this->c->id])->count(),
            'Mở trang sắp xếp không được tạo bản ghi kỳ sao kê.',
        );
    }

    // =====================================================================
    // Fixture + bóc thứ tự từ HTML
    // =====================================================================

    private function makeCard(string $name, int $sortOrder, string $periodStart, int $dueDay): UserCard
    {
        // `statement_period_end` là cột thật: trang Quản lý thẻ in `->format()` lên
        // nó nên không được để null (dù sort chỉ đọc `start`/`statement_day`).
        $periodEnd = CarbonImmutable::parse($periodStart)->addMonthNoOverflow()->subDay();

        return $this->makeUserCard($this->owner->id, [
            'name' => $name,
            'sort_order' => $sortOrder,
            'statement_period_start' => $periodStart,
            'statement_period_end' => $periodEnd->toDateString(),
            'payment_due_day' => $dueDay,
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function overviewOrder(string $mode): array
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.index', ['sort' => $mode]))
            ->assertOk()
            ->getContent();

        return $this->extract('/data-testid="card-row"\s+data-card-id="(\d+)"/', $html, 'Tổng quan');
    }

    /**
     * @return array<int, int>
     */
    private function manageOrder(string $mode): array
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage', ['sort' => $mode]))
            ->assertOk()
            ->getContent();

        return $this->extract('/openEdit\((\d+)\)/', $html, 'Quản lý thẻ');
    }

    /**
     * @return array<int, int>
     */
    private function statementsOrder(string $mode): array
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['sort' => $mode]))
            ->assertOk()
            ->getContent();

        return $this->extract('/data-testid="statement-row"\s+data-card-id="(\d+)"/', $html, 'Sao kê');
    }

    /**
     * Bóc danh sách id theo ĐÚNG thứ tự xuất hiện trong HTML.
     *
     * @return array<int, int>
     */
    private function extract(string $pattern, string $html, string $page): array
    {
        preg_match_all($pattern, $html, $matches);

        $ids = array_map('intval', $matches[1]);

        $this->assertNotSame([], $ids, "Không bóc được dòng thẻ nào từ HTML trang {$page}.");

        // Giữ nguyên thứ tự nhưng loại trùng: một thẻ chỉ xuất hiện một dòng.
        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            "Trang {$page} render trùng thẻ — không đo được thứ tự.",
        );

        return $ids;
    }
}
