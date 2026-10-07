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

        // Tổng quan chỉ in DƯ NỢ CUỐI KỲ — 5.000.000 − 250.000, đọc bằng công
        // thức của model. Hai số thành phần là chi tiết của màn Sao kê; xem
        // `statement_only_does_not_show_actual_spend` / `..._actual_reward`.
        $this->assertStringContainsString('4.750.000', $html);
    }

    // =====================================================================
    // Khối sao kê ở Tổng quan chỉ cần DƯ NỢ + HẠN + TRẠNG THÁI
    // =====================================================================

    /** Số tiền chi tiêu thực tế KHÔNG được in ở khối sao kê của Tổng quan. */
    #[Test]
    public function statement_only_does_not_show_actual_spend(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        // 5.000.000 là số riêng của bản ghi sao kê: kỳ hiện tại chưa có giao
        // dịch nên ô "Số tiền đã chi tiêu" của Tổng quan không in ra nó.
        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringNotContainsString('Chi tiêu thực tế', $html);
        $this->assertStringNotContainsString('5.000.000', $html);
    }

    /** Số hoàn/thưởng thực tế KHÔNG được in ở khối sao kê của Tổng quan. */
    #[Test]
    public function statement_only_does_not_show_actual_reward(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringNotContainsString('Hoàn/thưởng thực tế', $html);
        $this->assertStringNotContainsString('250.000', $html);
    }

    /** Dư nợ cuối kỳ PHẢI còn, và đúng tên gọi mới. */
    #[Test]
    public function statement_only_shows_closing_balance(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('Dư nợ cuối kỳ', $html);
        $this->assertStringContainsString(
            '4.750.000',
            $this->textOfTestId($html, 'card-statement-closing-balance'),
        );
        // Tên cũ "Còn phải trả" là nhãn của màn Sao kê, không phải của Tổng quan.
        $this->assertStringNotContainsString('Còn phải trả', $html);
    }

    /** Hạn thanh toán của kỳ vẫn hiện, lấy đúng ngày hạn của kỳ đó. */
    #[Test]
    public function statement_only_shows_payment_due_date(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-17');

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('22/10/2026', $this->textOfTestId($html, 'card-statement-due'));
    }

    /** Trạng thái thanh toán của kỳ hiện ra, kể cả khi chưa có dòng sao kê. */
    #[Test]
    public function statement_only_shows_payment_status(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-17');

        // Cố ý KHÔNG ghi dòng sao kê: kỳ ảo phải vẫn báo trạng thái.
        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString(
            'Chưa thanh toán',
            $this->textOfTestId($html, 'card-statement-payment-status'),
        );

        $statement = $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));
        $this->markPaid($statement);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString(
            'ĐÃ THANH TOÁN',
            $this->textOfTestId($html, 'card-statement-payment-status'),
        );
    }

    /** Kỳ đã trả thì Tổng quan không được báo quá hạn. */
    #[Test]
    public function statement_paid_does_not_show_overdue_warning(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-25');

        $statement = $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));
        $this->markPaid($statement);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertAlertHidden($html);
        $this->assertStringNotContainsString('ĐÃ QUÁ HẠN THANH TOÁN', $html);
        $this->assertStringNotContainsString('Quá hạn 3 ngày', $html);
        // Ngày hạn vẫn hiện — người dùng cần biết hạn của kỳ là khi nào.
        $this->assertStringContainsString('Hạn thanh toán kỳ này: 22/10/2026', $html);
    }

    /** Kỳ CHƯA trả mà quá hạn thì phải báo. */
    #[Test]
    public function statement_unpaid_overdue_shows_overdue_warning(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-25');

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertAlertVisible($html);
        $this->assertStringContainsString('ĐÃ QUÁ HẠN THANH TOÁN', $html);
        $this->assertStringContainsString('Quá hạn 3 ngày', $html);
    }

    /** Kỳ CHƯA trả mà đang trong cửa sổ nhắc thì phải báo sắp đến hạn. */
    #[Test]
    public function statement_unpaid_reminder_shows_reminder(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-17');

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));
        $this->setReminder(5);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertAlertVisible($html);
        $this->assertStringContainsString('SẮP ĐẾN HẠN THANH TOÁN', $html);
        $this->assertStringContainsString('Còn 5 ngày', $html);
    }

    /** Bật/ tắt khối sao kê KHÔNG được đụng vào tổng của Tổng quan. */
    #[Test]
    public function statement_only_does_not_change_overview_totals(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-17');

        // Ghi một dòng sao kê vào kỳ ĐÃ CHỐT, không giao dịch nào ở kỳ hiện tại.
        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $totals = $this->summaryOf('/thetindung');

        // Tổng của Tổng quan là của KỲ HIỆN TẠI, nên dòng sao kê 5.000.000 của
        // kỳ đã chốt không được lọt vào ô tổng.
        $this->assertStringStartsWith('0', $totals['stat-total-spend']);
        $this->assertStringStartsWith('0', $totals['stat-expected-cashback']);
    }

    /** Tùy chọn "Sao kê" độc lập với tùy chọn "Số tiền đã chi tiêu". */
    #[Test]
    public function statement_display_preference_is_independent_from_spend_display(): void
    {
        // Cổng hiển thị nằm trong vòng lặp thẻ, nên cần ít nhất một thẻ thì trang
        // mới render ra `x-show` — không có thẹ thì trống, test sẽ pass vì lý do sai.
        $this->makeUserCard($this->owner->id);

        $html = $this->overviewHtml();

        // Bốn tùy chọn là BỐN khoá riêng: bỏ tick "Sao kê" không được kéo theo
        // việc ẩn "Số tiền đã chi tiêu" và ngược lại.
        $spend = $this->inputTagOf($html, 'data-testid="toggle-spend"');
        $statement = $this->inputTagOf($html, 'data-testid="toggle-statement"');

        $this->assertStringContainsString('x-model="display.spend"', $spend);
        $this->assertStringContainsString('x-model="display.statement"', $statement);
        $this->assertStringContainsString('checked', $spend);
        $this->assertStringContainsString('checked', $statement);

        // Cổng hiển thị cũng tách: hai khối có `showSection` riêng.
        $this->assertStringContainsString("showSection('spend')", $html);
        $this->assertStringContainsString("showSection('statement')", $html);
    }

    /** Tùy chọn "Sao kê" độc lập với tùy chọn "Cashback dự kiến". */
    #[Test]
    public function statement_display_preference_is_independent_from_cashback_display(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->overviewHtml();

        $cashback = $this->inputTagOf($html, 'data-testid="toggle-cashback"');
        $statement = $this->inputTagOf($html, 'data-testid="toggle-statement"');

        $this->assertStringContainsString('x-model="display.cashback"', $cashback);
        $this->assertStringContainsString('x-model="display.statement"', $statement);
        $this->assertStringContainsString("showSection('cashback')", $html);
        $this->assertStringContainsString("showSection('statement')", $html);

        // Khoá lưu trữ giữ các lựa chọn độc lập: cashback và statement phải nằm
        // trong cùng object mặc định (thêm khoá "Điều kiện hoàn tiền đặc biệt"
        // ở Phase sau không được phá hai khoá này).
        $this->assertStringContainsString(
            'cashback: true, quota: true, qualification: true, statement: true',
            $html,
        );
        $this->assertStringContainsString('spend: true, cashback: true', $html);
    }

    /**
     * Mở Tổng quan KHÔNG được sinh thêm một lần tải trang.
     *
     * Không có hạ tầng test trình duyệt trong dự án nên kiểm bằng đọc mã. Đây từng
     * là nguồn `GET /thetindung` thứ HAI: `ccSortPicker.init()` tự điều hướng để áp
     * lại chế độ đã nhớ, trong khi server chỉ đọc `?sort=` nên request đầu luôn về
     * mặc định. Nay server đọc cookie nên request đầu đã render đúng.
     *
     * So khớp trong THÂN HÀM, không so cả trang: trang này vốn có chữ giải thích
     * nhắc tới `location.replace`, và khẳng định "không được xuất hiện ở bất kỳ
     * đâu" sẽ đỏ vì chính dòng chú thích giải thích lý do.
     */
    #[Test]
    public function the_overview_does_not_navigate_a_second_time_on_load(): void
    {
        $html = $this->overviewHtml();

        $sortPicker = $this->stripJsComments($this->bodyOfFunction($html, 'ccSortPicker'));
        $persist = $this->stripJsComments($this->bodyOfFunction($html, 'creditCardOverview'));

        // KHÔNG điều hướng bằng `replace`/`reload`/`assign`/`href` — đây chính là
        // request thứ hai mà mỗi lần vào trang đều bắn ra.
        foreach (['location.replace', 'location.reload', 'location.assign', 'location.href ='] as $navigation) {
            $this->assertStringNotContainsString(
                $navigation,
                $sortPicker,
                "`{$navigation}` trong `ccSortPicker` sẽ khiến trang tải 2 lần.",
            );
        }

        // Đọc `location.href` để biết URL có `?sort=` hay không thì được; gán nó
        // thì không. Kiểm chứng đúng bằng cách đọc `href` nhưng không gán.
        $this->assertStringContainsString('window.location.href', $sortPicker);
        $this->assertStringNotContainsString('window.location.href =', $sortPicker);

        // Chế độ đã nhớ giờ đi qua cookie để server đọc được ở request đầu.
        $this->assertStringContainsString('rememberMode', $sortPicker);
        $this->assertStringContainsString('document.cookie', $sortPicker);

        // Tùy chọn hiển thị chỉ ghi `localStorage`, không điều hướng.
        $this->assertStringContainsString('persistDisplay', $persist);
        $this->assertStringNotContainsString('location', $persist);

        // Server đọc chế độ đã nhớ từ cookie nên không cần `?sort=`.
        $this->assertStringContainsString(
            "\$request->query('sort') ?? \$request->cookie('credit-card-overview-sort')",
            file_get_contents(app_path('Http/Controllers/CreditCard/CreditCardController.php')),
        );
    }

    /** Chế độ sắp xếp đã nhớ vẫn được tôn trọng, và đúng ngay ở request đầu. */
    #[Test]
    public function the_overview_honours_the_remembered_sort_mode_without_a_query_param(): void
    {
        // Thẻ nợ gần hạn nhất phải lên trước khi sắp theo "còn thiếu nhiều nhất".
        $this->makeUserCard($this->owner->id, ['credit_limit' => '10000000', 'desired_spend' => '20000000']);
        $this->makeUserCard($this->owner->id, ['credit_limit' => '50000000', 'desired_spend' => '50000000']);

        $byQuery = $this->orderOfCards(
            $this->actingAs($this->owner)->get('/thetindung?sort=min_spend')->assertOk()->getContent()
        );

        $byCookie = $this->orderOfCards(
            $this->actingAs($this->owner)
                ->withCookie('credit-card-overview-sort', 'min_spend')
                ->get('/thetindung')
                ->assertOk()
                ->getContent()
        );

        $this->assertNotEmpty($byCookie);
        $this->assertSame(
            $byQuery,
            $byCookie,
            'Cookie phải cho ra đúng thứ tự như `?sort=` đưa ra, ngay ở request đầu.',
        );
    }

    /** Nội dung trang Tổng quan. */
    private function overviewHtml(): string
    {
        return $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
    }

    /** Thứ tự id thẻ theo thứ tự xuất hiện trên trang. */
    private function orderOfCards(string $html): array
    {
        preg_match_all('/data-testid="card-row"\s+data-card-id="(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    /**
     * Thân một hàm JS trong HTML, cắt theo cặp ngoặc đầu tiên sau `function`.
     *
     * Cần khi muốn khẳng định về MÃ CHẠY CHỨ KHÔNG PHẢI về chữi giải thích: các
     * bình luận trong mã thường phải nhắc tên chính thứ đó để giải thích vì sao nó
     * bị bỏ, và khẳng định "không xuất hiện ở đâu cả" sẽ đỏ oang vì chính dòng
     * giải thích ấy.
     */
    private function bodyOfFunction(string $html, string $function): string
    {
        $start = strpos($html, 'function '.$function);

        $this->assertNotFalse($start, "Không tìm thấy hàm JS {$function}.");

        $open = strpos($html, '{', $start);

        $this->assertNotFalse($open);

        $depth = 0;
        $length = strlen($html);

        for ($i = $open; $i < $length; $i++) {
            if ($html[$i] === '{') {
                $depth++;
            } elseif ($html[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($html, $start, $i - $start + 1);
                }
            }
        }

        $this->fail("Hàm JS {$function} không cân bằng ngoặc.");
    }

    /**
     * Bỏ chú thích JS khỏi một đoạn mã trước khi khẳng định về nó.
     *
     * Bình luận trong mã PHẢI nhắc tên chính thứ của thứ đã bị bỏ — đó là cách
     * người sau không vô tình thêm lại. Nên khẳng định "không được xuất hiện ở đâu
     * cả" mà quét cả bình luận thì luôn đỏ, đúng cái giá của việc viết tốt.
     * Ở đây chỉ quan tâm MÃ CHẠY.
     */
    private function stripJsComments(string $code): string
    {
        $withoutBlocks = preg_replace('#/\*.*?\*/#s', '', $code);

        // `//` đứng ngay sau `:` là trong URL (`https://`), không phải chú thích.
        return preg_replace('#(?<!:)//[^\n]*#', '', (string) $withoutBlocks);
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
    // Trạng thái thanh toán + nhắc trên Tổng quan
    // =====================================================================

    #[Test]
    public function an_unpaid_statement_reads_as_unpaid_on_the_overview(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="card-statement-payment-status"', $html);
        $this->assertStringContainsString('Chưa thanh toán', $html);
        $this->assertStringNotContainsString('ĐÃ THANH TOÁN', $html);
    }

    #[Test]
    public function a_paid_statement_reads_as_paid_on_the_overview(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $statement = $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $this->markPaid($statement);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('ĐÃ THANH TOÁN', $html);
        $this->assertStringNotContainsString('Chưa thanh toán', $html);
    }

    #[Test]
    public function an_active_reminder_warns_on_the_overview(): void
    {
        // Hạn 22/10, nhắc trước 5 ngày ⇒ ngưỡng 17/10.
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-17');

        $statement = $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));
        $this->setReminder(5);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertAlertVisible($html);
        $this->assertStringContainsString('SẮP ĐẾN HẠN THANH TOÁN', $html);
        $this->assertStringContainsString('Còn 5 ngày', $html);
    }

    #[Test]
    public function a_paid_statement_never_warns_on_the_overview(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-17');

        $statement = $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));
        $this->setReminder(5);
        $this->markPaid($statement);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertAlertHidden($html);
        $this->assertStringNotContainsString('SẮP ĐẾN HẠN THANH TOÁN', $html);
    }

    #[Test]
    public function an_overdue_statement_warns_as_overdue_on_the_overview(): void
    {
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-25');

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertAlertVisible($html);
        $this->assertStringContainsString('ĐÃ QUÁ HẠN THANH TOÁN', $html);
        $this->assertStringContainsString('Quá hạn 3 ngày', $html);
    }

    #[Test]
    public function the_overview_warns_about_the_completed_period_not_the_current_one(): void
    {
        // Chốt ngày 1, hạn ngày 22: kỳ 01/09–30/09 có hạn 22/10. Kỳ hiện tại
        // (tháng 10) có hạn 22/11 — nếu controller lỡ lấy kỳ hiện tại thì hôm
        // 17/10 sẽ không có cảnh báo nào, và test này bắt đúng lỗi đó.
        $card = $this->cardWithDueDate('2026-10-22');
        $this->at('2026-10-17');

        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));
        $this->setReminder(5);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertAlertVisible($html);
        $this->assertStringContainsString('Hạn thanh toán: 22/10/2026', $html);
    }

    #[Test]
    public function marking_paid_does_not_move_the_overview_totals(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['credit_limit' => '10000000']);
        $this->writeStatement($card, '5000000', '250000', $this->completedPeriodStart($card));

        $before = $this->summaryOf('/thetindung');

        $this->markPaid($this->statementOf($card));

        $this->assertSame(
            $before,
            $this->summaryOf('/thetindung'),
            'Đánh dấu đã trả là thao tác trạng thái, không được đụng chỉ số.',
        );
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * Thẻ chốt ngày 1, hạn trả ngày 22 — kỳ 01/09–30/09 có hạn 22/10/2026.
     */
    private function cardWithDueDate(string $expectedDue): \App\Models\CreditCard\UserCard
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_period_start' => '2026-01-01',
            'statement_day' => 1,
            'payment_due_day' => 22,
        ]);

        // Khoá giả định của fixture: nếu `StatementPeriodService` đổi cách suy ra
        // hạn thì các test này phải đỏ, chứ không âm thầm kiểm tra nhầm ngày khác.
        $this->at('2026-10-17');
        $this->writeStatement($card, '1', '0', $this->completedPeriodStart($card));

        $period = StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->whereDate('period_start', $this->completedPeriodStart($card))
            ->firstOrFail();

        $this->assertSame($expectedDue, $period->payment_due_date?->toDateString());

        return $card;
    }

    private function at(string $today): void
    {
        $this->travelTo(CarbonImmutable::parse($today.' 09:00:00'));
    }

    private function statementOf($card, ?string $periodStart = null): CreditCardStatement
    {
        return CreditCardStatement::query()
            ->where('user_card_id', $card->id)
            ->whereHas('statementPeriod', fn ($query) => $query->whereDate(
                'period_start',
                $periodStart ?? $this->completedPeriodStart($card),
            ))
            ->firstOrFail();
    }

    private function markPaid(CreditCardStatement $statement): void
    {
        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.statements.payment.update', ['statement' => $statement->fresh()->id]),
            ['payment_status' => 'paid'],
        )->assertOk();
    }

    /**
     * Số ngày nhắc thuộc USER nên đổi qua endpoint thiết lập chung, không gửi kèm
     * lúc đánh dấu kỳ đã trả — nếu không thì màn Tổng quan sẽ cảnh báo theo một
     * con số mà màn Sao kê không lưu.
     */
    private function setReminder(int $days): void
    {
        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.settings.payment-reminder.update'),
            ['payment_reminder_days' => $days],
        )->assertOk();
    }

    /**
     * Khối cảnh báo luôn được render rồi ẩn bằng lớp `hidden` (xem Blade), nên
     * phải kiểm LỚP chứ không kiểm sự có mặt.
     */
    private function assertAlertVisible(string $html): void
    {
        $this->assertStringNotContainsString(
            'hidden',
            $this->tagOfTestId($html, 'card-statement-alert'),
            'Khối cảnh báo phải hiện.',
        );
    }

    private function assertAlertHidden(string $html): void
    {
        $this->assertStringContainsString(
            'hidden',
            $this->tagOfTestId($html, 'card-statement-alert'),
            'Khối cảnh báo phải ẩn.',
        );
    }

    private function tagOfTestId(string $html, string $testId): string
    {
        $needle = "data-testid=\"{$testId}\"";

        $position = strpos($html, $needle);

        $this->assertNotFalse($position, "Không tìm thấy {$needle}");

        $tagStart = strrpos(substr($html, 0, $position), '<');
        $tagEnd = strpos($html, '>', $position);

        return substr($html, (int) $tagStart, (int) $tagEnd - (int) $tagStart + 1);
    }

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
