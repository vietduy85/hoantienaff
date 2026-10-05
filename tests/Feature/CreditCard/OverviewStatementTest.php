<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\StatementPeriod;
use App\Models\User;
use App\Services\CreditCard\CreditCardStatementService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Khối "Sao kê kỳ vừa kết thúc" trên trang Tổng quan (/thetindung).
 *
 * ---------------------------------------------------------------------------
 * BẤT BIẾN ĐƯỢC KHOÁ Ở ĐÂY
 * ---------------------------------------------------------------------------
 *   1. CHỈ ĐỌC. Sao kê là số tiền thực tế trên bảng kê ngân hàng, KHÔNG được
 *      cộng vào tổng chi tiêu, cashback dự kiến, quota hay thanh tiến độ.
 *      Bỏ khối này đi thì các số đó không được đổi một đồng nào.
 *   2. Đọc KỲ ĐÃ KẾT THÚC GẦN NHẤT của chính thẻ đó — suy ra từ
 *      `StatementPeriodService`, không theo tháng lịch và không theo kỳ
 *      hiện tại.
 *   3. Từng thẻ một kỳ riêng: hai thẻ lệch chu kỳ không được dùng chung kỳ.
 *   4. Hạn thanh toán lấy từ KỲ ĐANG ĐỌC, không phải kỳ hiện tại.
 *   5. Mở trang KHÔNG tạo bản ghi kỳ.
 */
class OverviewStatementTest extends TestCase
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
    // Tùy chọn hiển thị
    // =====================================================================

    #[Test]
    public function the_display_options_offer_a_statement_toggle_enabled_by_default(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="toggle-statement"', $html);
        // Bật sẵn: đây là khối người dùng muốn thấy, chỉ có thể tắt khi họ chủ
        // động bỏ.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*data-testid="toggle-statement"[^>]*>/',
            $html,
        );

        $form = $this->inputTagOf($html, 'data-testid="toggle-statement"');
        $this->assertStringContainsString('checked', $form);
        $this->assertStringContainsString('x-model="display.statement"', $form);
        // Lựa chọn được nhớ qua localStorage như ba tùy chọn còn lại.
        $this->assertStringContainsString('display.statement', $html);
    }

    // =====================================================================
    // Dữ liệu kỳ đã kết thúc gần nhất
    // =====================================================================

    #[Test]
    public function it_shows_the_latest_completed_period_of_the_card(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        [$start, $end] = $this->completedBoundaries($card);

        $this->assertStringContainsString(
            "Kỳ {$start->format('d/m/Y')} &ndash; {$end->format('d/m/Y')}",
            $html,
        );
        $this->assertStringContainsString('5.000.000', $html);
        $this->assertStringContainsString('250.000', $html);
        // 5.000.000 − 250.000, đọc bằng công thức của model.
        $this->assertStringContainsString('4.750.000', $html);
    }

    #[Test]
    public function it_never_uses_a_calendar_month_instead_of_the_card_cycle(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_period_start' => '2026-01-05',
            'statement_day' => 5,
        ]);

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card->refresh()));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        [$start, $end] = $this->completedBoundaries($card->refresh());

        // Ranh giới do service suy ra: [clamp(5, M), clamp(5, M+1) − 1] — mở ngày 5,
        // kết thúc ngày 4. Không phải [01, 30] của tháng lịc.
        $this->assertStringContainsString("Kỳ {$start->format('d/m/Y')}", $html);
        $this->assertSame('05', $start->format('d'), 'Kỳ phải mở theo anchor, không theo ngày 1.');
        $this->assertSame('04', $end->format('d'), 'Kỳ phải kết thúc ngày trước anchor, không theo ngày cuối tháng.');
        $this->assertStringContainsString(
            "Kỳ {$start->format('d/m/Y')} &ndash; {$end->format('d/m/Y')}",
            $html,
        );
    }

    #[Test]
    public function a_card_without_a_statement_reads_as_not_entered_rather_than_zero(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="card-statement-empty"', $html);
        $this->assertStringContainsString('Chưa nhập sao kê', $html);
        // Vẫn nói rõ kỳ nào là "kỳ vừa kết thúc" — nếu không, người dùng
        // không biết đang thiếu số của kỳ nào.
        [$start] = $this->completedBoundaries($card);
        $this->assertStringContainsString($start->format('d/m/Y'), $html);
    }

    #[Test]
    public function a_statement_in_the_current_period_does_not_stand_in_for_the_completed_one(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->writeStatement($card, '5000000', '250000', $this->currentPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Kỳ hiện tại chưa kết thúc ⇒ không phải sao kê "vừa kết thúc".
        $this->assertStringContainsString('data-testid="card-statement-empty"', $html);
        $this->assertStringNotContainsString('4.750.000', $html);
    }

    #[Test]
    public function each_card_shows_its_own_latest_completed_period(): void
    {
        $cardA = $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ A',
            'statement_period_start' => '2026-01-05',
            'statement_day' => 5,
        ]);
        $cardB = $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ B',
            'statement_period_start' => '2026-01-20',
            'statement_day' => 20,
        ]);

        $this->writeStatement($cardA, '1000000', '100000', $this->completedPeriodStart($cardA));
        $this->writeStatement($cardB, '7000000', '700000', $this->completedPeriodStart($cardB->refresh()));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Hai kỳ khác nhau, hai dòng số khác nhau — dùng chung một kỳ sẽ gộp
        // số của thẻ này vào thẻ kia.
        [$startA, $endA] = $this->completedBoundaries($cardA);
        [$startB, $endB] = $this->completedBoundaries($cardB->refresh());

        $this->assertStringContainsString("Kỳ {$startA->format('d/m/Y')} &ndash; {$endA->format('d/m/Y')}", $html);
        $this->assertStringContainsString("Kỳ {$startB->format('d/m/Y')} &ndash; {$endB->format('d/m/Y')}", $html);
        $this->assertNotSame($startA->toDateString(), $startB->toDateString());

        $this->assertStringContainsString('900.000', $html);   // 1.000.000 − 100.000
        $this->assertStringContainsString('6.300.000', $html); // 7.000.000 − 700.000
    }

    #[Test]
    public function the_payment_due_date_comes_from_the_period_being_shown(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_period_start' => '2026-01-05',
            'statement_day' => 5,
            'payment_due_day' => 12,
        ]);

        $periodStart = $this->completedPeriodStart($card);
        $this->writeStatement($card, '5000000', '250000', $periodStart);

        $period = StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->whereDate('period_start', $periodStart)
            ->firstOrFail();

        // Hạn của kỳ vừa kết thúc = clamp(12) của tháng KẾT THỜI kỳ đó.
        $expectedDue = $period->period_end->addMonth()->day(12);

        $this->assertSame(
            $expectedDue->toDateString(),
            $period->payment_due_date?->toDateString(),
            'Bản ghi kỳ lưu hạn theo kỳ của nó, không theo kỳ hiện tại.',
        );

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString("Đến hạn {$expectedDue->format('d/m/Y')}", $html);
    }

    // =====================================================================
    // Chỉ đọc — không động vào số liệu khác
    // =====================================================================

    #[Test]
    public function the_statement_is_not_counted_into_the_overview_totals(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['credit_limit' => '10000000']);

        $before = $this->summaryOf('/thetindung');

        // Ghi một kỳ đã kết thúc với số tiền rất lớn.
        $this->writeStatement($card, '8000000', '400000', $this->completedPeriodStart($card));

        $after = $this->summaryOf('/thetindung');

        // Sao kê là tiền ĐÃ CHI theo bảng kê, không phải giao dịch trong kỳ
        // hiện tại — nên tổng chi tiêu, cashback dự kiến và hạn mức không đổi.
        $this->assertSame($before, $after, 'Khối Sao kê chỉ đọc, không được cộng vào chỉ số Tổng quan.');
    }

    #[Test]
    public function the_statement_block_has_no_write_controls(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Khối này CHỈ hiển thị: nhập/sửa/xoá thuộc trang Sao kê. Nếu có nút
        // ghi ở đây thì đã mở đường sửa tiền ngoài phạm vi.
        $this->assertStringNotContainsString('data-testid="statement-edit-', $html);
        $this->assertStringNotContainsString('data-testid="statement-delete-', $html);
        $this->assertStringNotContainsString('name="actual_spend"', $html);
        $this->assertStringNotContainsString('name="actual_reward"', $html);
    }

    #[Test]
    public function opening_the_overview_never_creates_a_statement_period(): void
    {
        $this->makeUserCard($this->owner->id);
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);

        $this->assertSame(0, StatementPeriod::query()->count());

        $this->actingAs($this->owner)->get('/thetindung')->assertOk();

        $this->assertSame(
            0,
            StatementPeriod::query()->count(),
            'Chỉ đọc thì không được sinh bản ghi kỳ.',
        );
    }

    #[Test]
    public function it_never_shows_another_users_statement(): void
    {
        $mine = $this->makeUserCard($this->owner->id);
        $theirs = $this->makeUserCard($this->stranger->id);

        $this->writeStatement($theirs, '9000000', '0', $this->completedPeriodStart($theirs));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringNotContainsString('9.000.000', $html);
        $this->assertStringContainsString('data-testid="card-statement-empty"', $html);

        // Thẻ của chính mình vẫn bình thường.
        $this->writeStatement($mine, '1000000', '0', $this->completedPeriodStart($mine));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $this->assertStringContainsString('1.000.000', $html);
        $this->assertStringNotContainsString('9.000.000', $html);
    }

    #[Test]
    public function the_block_renders_the_closing_balance_from_the_server(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Số "còn phải trả" đọc bằng công thức của model, không phải cột do
        // client gửi lên — không có ô nhập nào trong khối này.
        $this->assertStringContainsString('4.750.000', $html);
        $this->assertStringNotContainsString('name="actual_spend"', $html);
        $this->assertStringNotContainsString('name="actual_reward"', $html);
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function completedBoundaries($card): array
    {
        return app(StatementPeriodService::class)->completedBoundaries($card, CarbonImmutable::now());
    }

    private function completedPeriodStart($card): string
    {
        [$start] = $this->completedBoundaries($card);

        return $start->toDateString();
    }

    private function currentPeriodStart($card): string
    {
        [$start] = app(StatementPeriodService::class)->currentBoundaries($card, CarbonImmutable::now());

        return $start->toDateString();
    }

    private function writeStatement($card, string $spend, string $reward, string $periodStart): CreditCardStatement
    {
        return app(CreditCardStatementService::class)->upsertForPeriodStart($card, $periodStart, [
            'actual_spend' => $spend,
            'actual_reward' => $reward,
        ]);
    }

    /**
     * Bốn chỉ số của trang Tổng quan, dạng mảng để so sánh nguyên tử.
     *
     * @return array<string, string>
     */
    private function summaryOf(string $url): array
    {
        $html = $this->actingAs($this->owner)->get($url)->assertOk()->getContent();

        $values = [];

        foreach ([
            'stat-total-cards',
            'stat-total-limit',
            'stat-total-spend',
            'stat-expected-cashback',
        ] as $testId) {
            $values[$testId] = $this->textOfTestId($html, $testId);
        }

        return $values;
    }

    private function textOfTestId(string $html, string $testId): string
    {
        $this->assertStringContainsString("data-testid=\"{$testId}\"", $html);

        $start = strpos($html, "data-testid=\"{$testId}\"");
        $tagEnd = strpos($html, '>', $start);
        $close = strpos($html, '</', $tagEnd);
        $raw = substr($html, $tagEnd + 1, $close - $tagEnd - 1);

        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function inputTagOf(string $html, string $needle): string
    {
        $position = strpos($html, $needle);

        $this->assertNotFalse($position, "Không tìm thấy {$needle}");

        $tagStart = strrpos(substr($html, 0, $position), '<');
        $tagEnd = strpos($html, '>', $position);

        return substr($html, (int) $tagStart, $tagEnd - $tagStart + 1);
    }
}
