<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardStatementService;
use App\Services\CreditCard\CreditCardUserSettingService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trạng thái thanh toán + nhắc thanh toán — sau khi tách thành HAI miền riêng.
 *
 * ---------------------------------------------------------------------------
 * MIỀN NÀY BẢO TOÀN
 * ---------------------------------------------------------------------------
 * 1. `payment_status` thuộc DÒNG SAO KÊ. Một thẻ có nhiều kỳ, mỗi kỳ một hạn
 *    trả riêng và một trạng thái riêng. Các test ở đây cố tình dùng nhiều thẻ và
 *    nhiều kỳ — chỉ cần một thẻ một kỳ thì mọi lỗi "dùng chung kỳ" đều không lộ.
 * 2. Số ngày nhắc thuộc NGƯỜI DÙNG, không thuộc kỳ. Nó đọc một lần rồi áp cho
 *    mọi thẻ, mọi kỳ — đây là điều mà `GlobalReminderDaysTest` kiểm bên dưới.
 *
 * ---------------------------------------------------------------------------
 * HẠN TRẢ LẤY TỪ KỲ, KHÔNG TỰ TÍNH THEO THÁNG LỊCH
 * ---------------------------------------------------------------------------
 * Test dùng thẻ `statement_day = 1` ⇒ ranh giới kỳ trùng tháng lịch, kỳ
 * 01/09–30/09 có `statement_date` 30/09 và hạn trả = clamp(22) của tháng kế =
 * **22/10/2026**. Các mốc 17/21/22/23/10 kiểm được thẳng. Việc service KHÔNG tự
 * tính theo tháng lịc vẫn được khoá ở `StatementPeriodServiceTest` và
 * `OverviewStatementTest::it_never_uses_a_calendar_month_instead_of_the_card_cycle`.
 *
 * ---------------------------------------------------------------------------
 * NGÀY ĐƯỢC CHỐT Ở SERVER
 * ---------------------------------------------------------------------------
 * Test đóng băng đồng hồ rồi kiểm HTML render sẵn — không có JavaScript trong test,
 * nên "Blade tự đếm ngày" là loại lỗi mà test này bắt được: nếu Blade tự so sánh
 * ngày thì kết quả phải khác kết quả service.
 */
class StatementPaymentTest extends TestCase
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
    // Trạng thái thanh toán của kỳ — §4
    // =====================================================================

    #[Test]
    public function a_new_statement_is_unpaid(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');

        $statement = $this->writeStatement($card);

        $fresh = $statement->fresh();

        $this->assertSame(CreditCardStatement::PAYMENT_STATUS_UNPAID, $fresh->payment_status);
        $this->assertFalse($fresh->isPaid());
    }

    /**
     * Kỳ không còn hai cột nhắc nào — số ngày nhắc đã lên thiết lập của user.
     *
     * Kiểm bằng cách hỏi schema chứ không đoán: nếu cột còn sót thì model đọc được
     * và assert dưới đây sẽ pass, còn code mới đang cố tình không dùng tới nó.
     */
    #[Test]
    public function the_statement_table_no_longer_carries_reminder_columns(): void
    {
$schema = $this->creditCardSchema();

        $this->assertNotContains('payment_reminder_enabled', $schema);
        $this->assertNotContains('payment_reminder_days', $schema);

        // Cột trạng thái thì PHẢI có — nếu thiếu thì mọi test khác chỉ đang pass
        // vì SQLite tự chấp nhận, và production sẽ vỡ.
        $this->assertContains('payment_status', $schema);
    }

    #[Test]
    public function the_status_column_can_still_be_written(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');

        $statement = $this->writeStatement($card);

        // Nhập trực tiếp qua model (bỏ qua request) để xác nhận MIGRATION nhận đủ
        // cột — không chỉ chứng minh request ghi được.
        $statement->forceFill([
            'payment_status' => CreditCardStatement::PAYMENT_STATUS_PAID,
        ])->save();

        $this->assertSame('paid', $statement->fresh()->payment_status);
    }

    #[Test]
    public function the_page_shows_the_status_combo_without_any_per_card_reminder(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $this->writeStatement($card);

        $html = $this->statementsPage($card);

        // MỘT combo box chứa hai lựa chọn loại trừ nhau — không phải hai ô
        // "đã trả"/"chưa trả" để có thể cùng bật.
        $combo = $this->selectOfTestId($html, 'data-payment-status');

        $this->assertStringNotContainsString('type="checkbox"', $combo);
        $this->assertSame(2, substr_count($combo, '<option'), 'Combo chỉ có hai trạng thái.');
        $this->assertStringContainsString('value="unpaid"', $combo);
        $this->assertStringContainsString('value="paid"', $combo);

        $this->assertStringContainsString('Chưa thanh toán', $html);
        $this->assertStringContainsString('Đã thanh toán', $html);

// Không còn ô nhắc riêng cho từng thẻ: móc checkbox cũ phải biến mất, còn
        // Ô nhắc thì phải là MỘT node duy nhất — đếm theo `id` của DOM chứ không
        // đếm móc `data-*`, vì JavaScript cũng gọi móc đó ở vài chỗ.
        $this->assertStringNotContainsString('data-payment-reminder-enabled', $html);
        $this->assertSame(
            1,
            substr_count($html, 'id="payment-reminder-days"'),
            'Chỉ được có đúng một ô nhắc cho cả trang.',
        );
        $this->assertStringNotContainsString('Nhắc thanh toán trước', $html);
    }

    #[Test]
    public function the_status_can_be_switched_back_and_forth(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $statement = $this->writeStatement($card);

        $this->assertSame(CreditCardStatement::PAYMENT_STATUS_UNPAID, $statement->fresh()->payment_status);

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);
        $this->assertSame('paid', $statement->fresh()->payment_status);

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_UNPAID);
        $this->assertSame('unpaid', $statement->fresh()->payment_status);
    }

    /**
     * Endpoint chỉ nhận `payment_status` — gửi kèm hai khoá nhắc cũ vẫn OK nhưng
     * KHÔNG được đụng tới thiết lập chung, đó là điều request tách ra để chặn.
     */
    #[Test]
    public function saving_a_statement_never_overwrites_the_global_reminder(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $statement = $this->writeStatement($card);

        $this->saveReminderDays(4);

        $this->actingAs($this->owner)->patchJson($this->paymentUrl($statement->fresh()), [
            'payment_status' => 'paid',
            // Hai khoá này KHÔNG còn trong hợp đồng API.
            'payment_reminder_enabled' => true,
            'payment_reminder_days' => 9,
        ])->assertOk();

        $this->assertSame('paid', $statement->fresh()->payment_status);
        $this->assertSame(4, $this->storedReminderDays(), 'Lưu kỳ đã ghi đè thiết lập chung của user.');
    }

    #[Test]
    public function a_status_that_is_not_one_of_the_two_is_rejected(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $statement = $this->writeStatement($card);

        $this->actingAs($this->owner)->patchJson($this->paymentUrl($statement), [
            'payment_status' => 'pending',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_status');

        $this->assertSame('unpaid', $statement->fresh()->payment_status, 'Trạng thái rác đã được ghi.');
    }

    #[Test]
    public function the_status_is_required(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $statement = $this->writeStatement($card);

        $this->actingAs($this->owner)->patchJson($this->paymentUrl($statement), [
            'payment_reminder_days' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('payment_status');
    }

    #[Test]
    public function a_stranger_cannot_change_the_status_of_someone_elses_statement(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $statement = $this->writeStatement($card);

        $this->actingAs($this->stranger)->patchJson($this->paymentUrl($statement), [
            'payment_status' => 'paid',
        ])->assertForbidden();

        $this->assertSame('unpaid', $statement->fresh()->payment_status);
    }

    /**
     * Kỳ đã chốt vẫn phải đánh dấu được: người dùng trả tiền sau khi thẻ tính
     * sao kê là chuyện thường, và chặn ở đây sẽ khiến họ không bao giờ thấy
     * "đã trả" với kỳ cũ.
     */
    #[Test]
    public function a_statement_of_a_finalized_period_can_still_be_marked_paid(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $statement = $this->writeStatement($card);

        $statement->statementPeriod->forceFill([
            'status' => StatementPeriod::STATUS_FINALIZED,
            'finalized_at' => now(),
        ])->save();

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        $this->assertSame('paid', $statement->fresh()->payment_status);
    }

    /**
     * Endpoint nhận id của DÒNG SAO KÊ đã có, không nhận (thẻ, kỳ). Nếu cho tạo,
     * một thao tác đổi trạng thái sẽ âm thầm sinh dòng sao kê số 0.
     */
    #[Test]
    public function an_unknown_statement_is_not_created_on_the_fly(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $this->writeStatement($card);

        $before = CreditCardStatement::query()->count();

        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.statements.payment.update', ['statement' => 999999]),
            ['payment_status' => 'paid'],
        )->assertNotFound();

        $this->assertSame($before, CreditCardStatement::query()->count());
    }

    // =====================================================================
    // Nhắc theo kỳ — dùng số ngày của USER
    // =====================================================================

    #[Test]
    public function before_the_reminder_window_there_is_no_alert(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $statement = $this->writeStatement($card);

        $this->saveReminderDays(1);

        $html = $this->statementsPage($card);

        // Hạn 22/10, còn 5 ngày, ngưỡng của user là 1 ⇒ chưa tới 21/10.
        $this->assertHidden($html, 'payment-alert-'.$card->id);
        $this->assertSame('upcoming', $this->stateIn($html, $card->id)['state']);
    }

    #[Test]
    public function the_alert_appears_exactly_on_the_reminder_threshold(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $this->writeStatement($card);

        $this->saveReminderDays(5);

        $html = $this->statementsPage($card);

        // Hạn 22/10 − 5 = ngưỡng 17/10 ⇒ hôm nay 17/10 là ngày ĐẦU của cửa sổ.
        $this->assertVisible($html, 'payment-alert-'.$card->id);
        $this->assertStringContainsString(
            'SẮP ĐẾN HẠN THANH TOÁN',
            $this->paymentAlertOf($html, $card->id),
        );
        $this->assertStringContainsString(
            'Còn 5 ngày',
            $this->paymentAlertOf($html, $card->id),
        );
        $this->assertSame('reminding', $this->stateIn($html, $card->id)['state']);
    }

    #[Test]
    public function due_today_is_red_not_amber(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-22');
        $this->writeStatement($card);

        $html = $this->statementsPage($card);

        $this->assertVisible($html, 'payment-alert-'.$card->id);
        $this->assertStringContainsString(
            'HẠN THANH TOÁN NGÀY CUỐI CÙNG',
            $this->paymentAlertOf($html, $card->id),
        );

        // Hạn trả là NGÀY CUỐI CÙNG: tới ngày mà chưa trả thì cảnh báo phải đỏ.
        $tag = $this->tagOfTestId($html, 'payment-alert-'.$card->id);
        $this->assertStringContainsString('border-red-300', $tag, 'Đến hạn mà chưa trả phải đỏ.');
        $this->assertStringNotContainsString('border-amber-300', $tag);

        $this->assertSame('due_today', $this->stateIn($html, $card->id)['state']);
    }

    #[Test]
    public function overdue_counts_the_days_late(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-24');
        $this->writeStatement($card);

        $html = $this->statementsPage($card);

        $alert = $this->paymentAlertOf($html, $card->id);

        $this->assertStringContainsString('ĐÃ QUÁ HẠN THANH TOÁN', $alert);
        $this->assertStringContainsString('Quá hạn 2 ngày', $alert);
        $this->assertStringContainsString('22/10/2026', $alert);

$this->assertSame('overdue', $this->stateIn($html, $card->id)['state']);
    }

    // =====================================================================
    // `paid` OVERRIDE MỌI CẢNH BÁO — kể cả DÒNG HẠN
    //
    // Lỗi thật đã gặp: dòng hạn do `StatementController::dueState()` tính riêng,
    // một state machine THỨ HAI không hề biết `payment_status`. Kỳ đã trả mà quá
    // hạn thì hiện "Đến hạn … · quá hạn N ngày" NGAY CẠNH dấu ✓ ĐÃ THANH TOÁN.
    // Các test dưới đây ghim đúng điều đó: đã trả thì KHÔNG còn dấu vết cảnh báo,
    // nhưng NGÀY HẠN vẫn phải hiện.
    // =====================================================================

    #[Test]
    public function a_paid_statement_that_is_overdue_has_no_overdue_warning(): void
    {
        // Hạn 22/10, hôm nay 24/10 ⇒ quá hạn 2 ngày.
        $card = $this->makeCard();
        $this->at('2026-10-24');
        $statement = $this->writeStatement($card);

        // Trước khi trả thì ĐÚNG là phải quá hạn — không có cái này thì test sau
        // pass vì lý do sai (kỳ chưa tới hạn nên chẳng có gì để ẩn).
        $this->assertStringContainsString(
            'quá hạn 2 ngày',
            $this->dueLineOf($this->statementsPage($card)),
        );

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        $html = $this->statementsPage($card);
        $state = $this->stateIn($html, $card->id);
        $dueLine = $this->dueLineOf($html);

        $this->assertTrue($state['is_paid']);
        $this->assertSame('paid', $state['state'], 'paid phải là state cuối cùng.');
        $this->assertNull($state['alert'], 'Đã trả mà vẫn còn alert.');

        $this->assertHidden($html, 'payment-alert-'.$card->id);
        $this->assertVisible($html, 'payment-paid-'.$card->id);

        // Không được sót lại dấu vết cảnh báo nào trên dòng hạn.
        $this->assertStringNotContainsString('quá hạn', mb_strtolower($dueLine));
        $this->assertStringNotContainsString('Đến hạn', $dueLine);
        $this->assertStringNotContainsString('ĐÃ QUÁ HẠN', $html);

        // NHƯNG ngày hạn vẫn phải hiện, đúng câu quy định.
        $this->assertSame('Hạn thanh toán kỳ này: 22/10/2026', $dueLine);
    }

    #[Test]
    public function a_paid_statement_on_the_due_date_has_no_due_today_warning(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-22');
        $statement = $this->writeStatement($card);

        $this->assertStringContainsString(
            'hôm nay',
            $this->dueLineOf($this->statementsPage($card)),
            'Chưa trả mà đến hạn thì dòng hạn phải báo hôm nay.',
        );

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        $html = $this->statementsPage($card);

        $this->assertSame('paid', $this->stateIn($html, $card->id)['state']);
        $this->assertStringContainsString('ĐÃ THANH TOÁN', $html);
        $this->assertStringNotContainsString('HẠN THANH TOÁN NGÀY CUỐI CÙNG', $html);
        $this->assertHidden($html, 'payment-alert-'.$card->id);
        $this->assertSame('Hạn thanh toán kỳ này: 22/10/2026', $this->dueLineOf($html));
    }

    #[Test]
    public function a_paid_statement_inside_the_reminder_window_has_no_reminder_warning(): void
    {
        // Hạn 22/10, nhắc trước 5 ngày ⇒ ngưỡng 17/10; hôm nay 19/10 nằm trong
        // cửa sổ nhắc.
        $card = $this->makeCard();
        $this->at('2026-10-19');
        $statement = $this->writeStatement($card);

        $this->saveReminderDays(5);

        $this->assertStringContainsString(
            'SẮP ĐẾN HẠN THANH TOÁN',
            $this->statementsPage($card),
            'Chưa trả thì cửa sổ nhắc phải cảnh báo.',
        );

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        $html = $this->statementsPage($card);

        $this->assertSame('paid', $this->stateIn($html, $card->id)['state']);
        $this->assertStringContainsString('ĐÃ THANH TOÁN', $html);
        $this->assertStringNotContainsString('SẮP ĐẾN HẠN THANH TOÁN', $html);
        $this->assertHidden($html, 'payment-alert-'.$card->id);
    }

    #[Test]
    public function an_unpaid_overdue_statement_still_shows_overdue(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-24');
        $this->writeStatement($card);

        $html = $this->statementsPage($card);

        $this->assertVisible($html, 'payment-alert-'.$card->id);
        $this->assertStringContainsString('ĐÃ QUÁ HẠN THANH TOÁN', $this->paymentAlertOf($html, $card->id));
        $this->assertStringContainsString('quá hạn 2 ngày', $this->dueLineOf($html));
    }

    #[Test]
    public function an_unpaid_statement_due_today_still_shows_due_today(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-22');
        $this->writeStatement($card);

        $html = $this->statementsPage($card);

        $this->assertStringContainsString(
            'HẠN THANH TOÁN NGÀY CUỐI CÙNG',
            $this->paymentAlertOf($html, $card->id),
        );
        $this->assertSame('due_today', $this->stateIn($html, $card->id)['state']);
    }

    #[Test]
    public function picking_paid_in_the_dropdown_persists_and_clears_the_warning(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-24');
        $statement = $this->writeStatement($card);

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        // Tải lại trang như người dùng thực sự làm sau khi dropdown đã lưu.
        $html = $this->statementsPage($card);

        $this->assertSame(
            CreditCardStatement::PAYMENT_STATUS_PAID,
            $statement->fresh()->payment_status,
            'DB chưa ghi paid.',
        );
        $this->assertVisible($html, 'payment-paid-'.$card->id);
        $this->assertHidden($html, 'payment-alert-'.$card->id);
        $this->assertSame('Hạn thanh toán kỳ này: 22/10/2026', $this->dueLineOf($html));
    }

    #[Test]
    public function switching_a_paid_statement_back_to_unpaid_brings_the_overdue_warning_back(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-24');
        $statement = $this->writeStatement($card);

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);
        $this->assertHidden($this->statementsPage($card), 'payment-alert-'.$card->id);

        // Đổi ngược lại "chưa thanh toán": cảnh báo phải TÍNH LẠI từ hạn + hôm nay,
        // không phải còn mắc từ lần render trước.
        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_UNPAID);

        $html = $this->statementsPage($card);
        $state = $this->stateIn($html, $card->id);

        $this->assertSame('overdue', $state['state']);
        $this->assertNotNull($state['alert']);
        $this->assertVisible($html, 'payment-alert-'.$card->id);
        $this->assertStringContainsString('ĐÃ QUÁ HẠN THANH TOÁN', $this->paymentAlertOf($html, $card->id));
        $this->assertStringContainsString('quá hạn 2 ngày', $this->dueLineOf($html));
        $this->assertHidden($html, 'payment-paid-'.$card->id);
    }

    /**
     * Payload trả về cho JavaScript phải mang đủ để dựng lại UI mà không tự tính
     * ngày — nếu thiếu `due_line` thì `applyPayment()` không dập/tắt được dòng hạn.
     */
    #[Test]
    public function the_patch_response_carries_the_server_resolved_due_line(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-24');
        $statement = $this->writeStatement($card);

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        $this->actingAs($this->owner)->patchJson($this->paymentUrl($statement->fresh()), [
            'payment_status' => 'unpaid',
        ])->assertOk()
            ->assertJsonPath('payment.state', 'overdue')
            ->assertJsonPath('payment.is_paid', false)
            ->assertJsonPath('payment.due_line', 'Đến hạn 22/10/2026 · quá hạn 2 ngày')
            ->assertJsonPath('payment.due_tone', 'danger');
    }

    /**
     * Hạn trả vẫn lấy từ StatementPeriod của kỳ đang xem và KHÔNG đổi khi đánh
     * dấu đã trả — đổi trạng thái là đổi việc đã trả, không phải đổi hạn.
     */
    #[Test]
    public function the_due_date_never_changes_when_the_statement_is_marked_paid(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-24');
        $statement = $this->writeStatement($card);

        $dueBefore = $statement->statementPeriod->payment_due_date?->toDateString();

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        $this->assertSame(
            $dueBefore,
            $statement->fresh()->statementPeriod->payment_due_date?->toDateString(),
        );
        $this->assertSame(
            'Hạn thanh toán kỳ này: 22/10/2026',
            $this->dueLineOf($this->statementsPage($card)),
            'Ngày hạn vẫn phải hiện khi đã trả.',
        );
    }


    #[Test]
    public function a_paid_statement_never_shows_a_payment_alert(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-10');
        $statement = $this->writeStatement($card);

        // Ngay giữa cửa sổ nhắc (hạn 22/10, ngưỡng 21/10 ⇒ chưa tới) nên đổi sang
        // 13/10 để chắc chắn CÓ cảnh báo trước khi trả.
        $this->at('2026-10-22');
        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        $html = $this->statementsPage($card);

        $this->assertHidden($html, 'payment-alert-'.$card->id);
        $this->assertSame('paid', $this->stateIn($html, $card->id)['state']);
    }

    /**
     * `data-payment-state` phải khớp ĐÚNG kết quả service cho kỳ đó, kèm số ngày
     * của user. Blade chỉ in, không tự tính — nếu lệch thì đây là chỗ bắt.
     */
    #[Test]
    public function the_rendered_state_matches_the_service_exactly(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $statement = $this->writeStatement($card);

        $this->saveReminderDays(5);

        $html = $this->statementsPage($card);
        $decoded = $this->stateIn($html, $card->id);

        $this->assertSame('unpaid', $decoded['status']);
        $this->assertSame('2026-10-22', $decoded['due_date']);
        $this->assertSame(5, $decoded['days_to_due']);
        $this->assertSame(5, $decoded['reminder_days'], 'Số ngày phải là của user, không phải mặc định.');
        $this->assertSame('2026-10-17', $decoded['reminder_start_date']);
        $this->assertSame('reminding', $decoded['state']);

        // Phải khớp đúng sự thật server, không phải bản sao tay chép trong Blade:
        // lấy hạn trả từ chính kỳ đã lưu và "hôm nay" từ đồng hồ đã đóng băng.
        $fresh = $statement->fresh();

        $this->assertSame(
            app(CreditCardStatementService::class)->paymentState(
                $fresh,
                $fresh->statementPeriod->payment_due_date,
                5,
                CarbonImmutable::parse('2026-10-17'),
            ),
            $decoded,
        );
    }

    // =====================================================================
    // Thiết lập chung của user
    // =====================================================================

    #[Test]
    public function the_page_has_exactly_one_reminder_input(): void
    {
        $first = $this->makeCard('2026-01-01', 22);
        $second = $this->makeCard('2026-01-05', 25);

        $this->at('2026-10-17');
        $this->writeStatement($first);
        $this->writeStatement($second);

        $html = $this->statementsPage($first);

        $this->assertSame(
            1,
            substr_count($html, 'data-testid="payment-reminder-input"'),
            'Ô nhắc phải là của CẢ tài khoản nên chỉ có đúng một.',
        );
        $this->assertSame(2, substr_count($html, 'data-testid="statement-row"'));
    }

    #[Test]
    public function the_reminder_input_is_bounded_to_the_valid_range(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $this->writeStatement($card);

        $this->saveReminderDays(7);

        $tag = $this->tagOfTestId($this->statementsPage($card), 'payment-reminder-input');

        $this->assertStringContainsString('min="1"', $tag);
        $this->assertStringContainsString('max="10"', $tag);
        $this->assertStringContainsString('value="7"', $tag, 'Ô phải mở ra bằng số server đang giữ.');
    }

    #[Test]
    public function opening_a_page_does_not_create_a_setting_row(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $this->writeStatement($card);

        $this->statementsPage($card);

        $this->assertSame(0, CreditCardUserSetting::query()->count(), 'Mở trang đã sinh dữ liệu.');
    }

    #[Test]
    public function a_user_without_a_row_still_gets_the_default(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $this->writeStatement($card);

        $this->assertSame(
            CreditCardUserSetting::DEFAULT_PAYMENT_REMINDER_DAYS,
            app(CreditCardUserSettingService::class)->reminderDaysFor($this->owner->id),
        );

        $tag = $this->tagOfTestId($this->statementsPage($card), 'payment-reminder-input');
        $this->assertStringContainsString('value="1"', $tag);
    }

    #[Test]
    public function saving_the_reminder_creates_one_row_then_updates_it(): void
    {
        $this->saveReminderDays(3);

        $this->assertSame(1, CreditCardUserSetting::query()->count());
        $this->assertSame(3, $this->storedReminderDays());

        $this->saveReminderDays(6);

        $this->assertSame(1, CreditCardUserSetting::query()->count(), 'Lưu lần hai đã tạo dòng thứ hai.');
        $this->assertSame(6, $this->storedReminderDays());
    }

    #[Test]
    public function the_reminder_endpoint_echoes_the_saved_value_for_the_current_user(): void
    {
        $this->actingAs($this->owner)->patchJson($this->reminderUrl(), [
            'payment_reminder_days' => 9,
        ])->assertOk()->assertJson([
            'data' => [
                'user_id' => $this->owner->id,
                'payment_reminder_days' => 9,
            ],
        ]);
    }

    /**
     * `user_id` lấy từ phiên đăng nhập, không bao giờ từ request — nên gửi khoá đó
     * vào cũng chỉ ghi được dòng của chính mình.
     */
    #[Test]
    public function the_reminder_endpoint_cannot_write_another_users_row(): void
    {
        $this->actingAs($this->owner)->patchJson($this->reminderUrl(), [
            'user_id' => $this->stranger->id,
            'payment_reminder_days' => 8,
        ])->assertOk();

        $this->assertSame(0, CreditCardUserSetting::query()->where('user_id', $this->stranger->id)->count());
        $this->assertSame(8, $this->storedReminderDays());
    }

    #[Test]
    public function the_reminder_endpoint_requires_a_value(): void
    {
        $this->actingAs($this->owner)->patchJson($this->reminderUrl(), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_reminder_days');
    }

    public static function outOfRangeReminderProvider(): array
    {
        return [
            'không phải số' => ['abc'],
            'nhỏ hơn 1' => [0],
            'lớn hơn 10' => [11],
            'âm' => [-3],
        ];
    }

    #[Test]
    #[DataProvider('outOfRangeReminderProvider')]
    public function the_reminder_endpoint_rejects_values_outside_the_range(mixed $days): void
    {
        $this->actingAs($this->owner)->patchJson($this->reminderUrl(), [
            'payment_reminder_days' => $days,
        ])->assertStatus(422)->assertJsonValidationErrors('payment_reminder_days');

        $this->assertSame(0, CreditCardUserSetting::query()->count(), 'Giá trị sai đã được ghi.');
    }

    // =====================================================================
    // Một con số chung áp cho mọi thẻ, mọi kỳ
    // =====================================================================

    #[Test]
    public function the_global_setting_applies_to_every_card_and_period(): void
    {
        $cardA = $this->makeCard('2026-01-01', 22);
        $cardB = $this->makeCard('2026-01-05', 25);

        $this->at('2026-10-17');

        // Hai thẻ, hai hạn trả khác nhau; hai kỳ mỗi thẻ cũng khác nhau.
        $septemberA = $this->writeStatement($cardA);
        $augustA = $this->writeStatement($cardA, '2026-08-01');
        $septemberB = $this->writeStatement($cardB);

        $this->saveReminderDays(5);

        $html = $this->statementsPage($cardA);

        // Cùng một số ngày nhắc cho tất cả — nếu Blade/service tự suy ra số ngày
        // từ hạn trả thì ba kỳ này sẽ lệch nhau.
        $this->assertSame(5, $this->stateIn($html, $cardA->id)['reminder_days']);
        $this->assertSame(5, $this->stateIn($html, $cardB->id)['reminder_days']);
        $this->assertSame(5, $this->stateIn($html, $augustA->id)['reminder_days']);
    }

    #[Test]
    public function changing_the_global_setting_moves_every_threshold(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $this->writeStatement($card);

        $this->saveReminderDays(1);
        $this->assertSame('upcoming', $this->stateIn($this->statementsPage($card), $card->id)['state']);

        // Cùng một ngày, chỉ đổi thiết lập chung ⇒ phải bật cảnh báo lên.
        $this->saveReminderDays(5);

        $state = $this->stateIn($this->statementsPage($card), $card->id);

        $this->assertSame('reminding', $state['state']);
        $this->assertSame('2026-10-17', $state['reminder_start_date']);
    }

    #[Test]
    public function the_overview_uses_the_same_global_setting(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $this->writeStatement($card);

        $this->saveReminderDays(5);

        $this->actingAs($this->owner)->get('/thetindung')->assertOk()
            ->assertSee('SẮP ĐẾN HẠN THANH TOÁN');
    }

// =====================================================================
    // KỲ ĐÃ TRẢ NHƯNG ĐÃ QUÁ HẠN — regression
    //
    // Bug thật: dòng hạn được render HAI node (`data-payment-due-line`) và
    // `applyPayment()` chỉ dựng lại node đầu bằng `querySelector`. Đánh dấu "đã
    // trả" xong thì node thứ hai vẫn giữ chuỗi server render lúc đầu là "Đến
    // hạn 04/10/2026 · quá hạn 2 ngày" — đúng triệu chứng: đã có ✓ ĐÃ THANH TOÁN
    // mà vẫn còn dòng đỏ.
    // =====================================================================

    /**
     * TEST BẮT BUỘC — `paid_overdue_statement_does_not_render_overdue_due_line`.
     *
     * today = 06/10, hạn = 04/10 ⇒ trễ 2 ngày. Kỳ đã trả thì KHÔNG được mang
     * bất kỳ dòng nào có nghĩa đến hạn/quá hạn, chỉ còn "Hạn thanh toán kỳ này".
     */
    #[Test]
    public function paid_overdue_statement_does_not_render_overdue_due_line(): void
    {
        $card = $this->makeCard('2026-01-01', 4);
        $this->at('2026-10-06');

        $statement = $this->writeStatement($card, $this->completedPeriodStart($card, 1));

        // Hạn 04/10/2026, hôm nay 06/10 ⇒ quá hạn 2 ngày.
        $this->assertSame('2026-10-04', $this->dueDateFor($card));

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_PAID);

        $html = $this->statementsPage($card);

        $this->assertStringContainsString('ĐÃ THANH TOÁN', $html);
        $this->assertStringContainsString('Hạn thanh toán kỳ này: 04/10/2026', $html);

        $this->assertStringNotContainsString('quá hạn 2 ngày', $html);
        $this->assertStringNotContainsString('Đến hạn 04/10/2026', $html);
        $this->assertStringNotContainsString('ĐÃ QUÁ HẠN THANH TOÁN', $html);
        $this->assertStringNotContainsString('SẮP ĐẾN HẠN THANH TOÁN', $html);

        // SOI MỌI NODE dòng hạn, không chỉ node đầu — đây mới là chỗ bug lọt.
        $dueLines = $this->allDueLinesOf($html);

        // Dòng hạn là thông tin của KỲ nên chỉ có MỘT chỗ hiện. Trước đây có hai
        // (`data-payment-due-line` dưới tiêu đề thẻ và một cái nữa trong khối ô
        // chọn trạng thái), và `applyPayment()` chỉ dựng lại cái đầu. Khóa luôn
        // số lượng node để bản sao thứ hai không thể lặng lẽ quay lại.
        $this->assertCount(1, $dueLines, 'Dòng hạn phải xuất hiện đúng một lần trên trang.');

        foreach ($dueLines as $line) {
            $this->assertSame(
                'Hạn thanh toán kỳ này: 04/10/2026',
                $line,
                'Còn sót dòng hạn cũ ở một node khác: '.$line,
            );
        }

        // Không có màu đỏ/amber trên dòng hạn của kỳ đã trả.
        $this->assertStringNotContainsString('text-red-700', $this->dueLineTag($html));
        $this->assertStringNotContainsString('text-amber-700', $this->dueLineTag($html));
    }

    /**
     * TEST BẮT BUỘC (client) — `paid_payment_response_clears_previous_warning_state`.
     *
     * Payload server trả về khi đánh dấu "đã trả" phải tự nó dính đủ để client dựng
     * lại được: `due_line` mới, `tone = settled`, không còn `alert`, và không còn
     * dấu vết quá hạn trong bất kỳ trường nào. Không có cách nào để client phải
     * giữ lại chuỗi cũ — nếu payload còn sót "quá hạn" thì `applyPayment()` dù có
     * dựng lại mọi node vẫn in ra dòng đỏ.
     */
    #[Test]
    public function paid_payment_response_clears_previous_warning_state(): void
    {
        $card = $this->makeCard('2026-01-01', 4);
        $this->at('2026-10-06');

        // Trước khi trả: trang phải thật sự cảnh báo, nếu không test này pass vì
        // lý do sai (chẳng có gì để xoá).
        $before = $this->stateIn($this->statementsPage($card), $card->id);

        $this->assertSame('overdue', $before['state']);
        $this->assertSame('danger', $before['due_tone']);
        $this->assertSame(-2, $before['days_to_due']);
        $this->assertNotNull($before['alert']);
        $this->assertStringContainsString('quá hạn 2 ngày', $before['due_line']);
        $this->assertStringContainsString('ĐÃ QUÁ HẠN THANH TOÁN', $before['alert']['title']);

        // Chọn "Đã trả" trên kỳ chưa có dòng: hành động này tự tạo dòng, nên tới
        // đây mới có id để gọi endpoint mà `applyPayment()` gọi.
        $this->markPeriodPaid($card, $this->completedPeriodStart($card, 1));

        $response = $this->actingAs($this->owner)->patchJson(
            $this->paymentUrl(CreditCardStatement::query()->firstOrFail()),
            ['payment_status' => CreditCardStatement::PAYMENT_STATUS_PAID],
        )->assertOk();

        $payment = $response->json('payment');

        // Mọi trường cảnh báo phải được DỰNG LẠI, không phải chỉ thêm `is_paid`.
        $this->assertSame('paid', $payment['state']);
        $this->assertSame('settled', $payment['due_tone']);
        $this->assertSame('Hạn thanh toán kỳ này: 04/10/2026', $payment['due_line']);
        $this->assertNull($payment['alert']);
        $this->assertNull($payment['days_to_due'], 'Kỳ đã trả không có số đếm ngược nào.');
        $this->assertNull($payment['reminder_start_date'], 'Kỳ đã trả không có cửa sổ nhắc.');
        $this->assertNull($payment['reminder_start_label']);

        // Ngày hạn vẫn phải còn — người dùng cần biết hạn của kỳ là khi nào.
        $this->assertSame('2026-10-04', $payment['payment_due_date']);
        $this->assertSame('04/10/2026', $payment['due_label']);

        // Toàn bộ payload không được sót lại chữ "quá hạn" ở bất kỳ đâu.
        $encoded = json_encode($payment, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('quá hạn', mb_strtolower((string) $encoded));
        $this->assertStringNotContainsString('Đến hạn', (string) $encoded);
    }

    // =====================================================================
    // PHÍA CLIENT KHÔNG ĐƯỢC ĐỂ LỌT CHỖ NÀY LẦN NỮA
    //
    // Không có hạ tầng test trình duyệt trong dự án, nên test đọc mã. Đây là bài
    // học rút ra từ chính bug này: server đã đúng từ đầu, lỗi nằm ở client chỉ
    // dựng lại node hạn ĐẦU TIÊN. Test server xanh mà trình duyệt vẫn đỏ là bình
    // thường — nên phải có cái neo cho phía client.
    // =====================================================================

    /**
     * TEST BẮT BUỘC (client) — `client_payment_update_rebuilds_every_due_line_node`.
     *
     * `applyPayment()` phải dựng lại MỌI node dòng hạn, không phải node đầu tiên.
     * Dùng `querySelector` một lần nữa là bug quay lại y nguyên.
     */
    #[Test]
    public function client_payment_update_rebuilds_every_due_line_node(): void
    {
        $card = $this->makeCard('2026-01-01', 4);
        $this->at('2026-10-06');

        $statement = $this->writeStatement($card, $this->completedPeriodStart($card, 1));

        $this->setStatus($statement, CreditCardStatement::PAYMENT_STATUS_UNPAID);

        $html = $this->statementsPage($card);

        $apply = $this->stripJsComments($this->bodyOfFunction($html, 'applyPayment'));

        // `querySelectorAll` + `forEach`: mọi node đều được dựng lại.
        $this->assertStringContainsString("querySelectorAll('[data-payment-due-line]')", $apply);
        $this->assertStringContainsString('forEach', $apply);

        // Dạng một-node đã từng gây bug. Chặn rõ ràng.
        $this->assertStringNotContainsString(
            "querySelector('[data-payment-due-line]')",
            $apply,
            '`querySelector` chỉ dựng lại node đầu — node thứ hai giữ chuỗi quá hạn cũ.',
        );

        // Dựng lại node hạn phải gán cả CHỮ lẫn MÀU theo tone của payload; chỉ
        // gán chữ thì kỳ đã trả vẫn còn chữ đỏ của "quá hạn".
        $this->assertStringContainsString('dueLine.textContent = payment.due_line', $apply);
        $this->assertStringContainsString('dueLine.classList.toggle', $apply);

        foreach (['danger', 'warning', 'settled', 'neutral'] as $tone) {
            $this->assertStringContainsString(
                "payment.due_tone === '{$tone}'",
                $apply,
                "Thiếu tone {$tone} khi dựng lại dòng hạn.",
            );
        }
    }

    // =====================================================================
    // KỲ CHƯA NHẬP SAO KÊ VẪN CÓ TRẠNG THÁI THANH TOÁN
    //
    // Trạng thái thanh toán là sự thật về KỲ, không phụ thuộc đã nhập số liệu hay
    // chưa. Trước đây kỳ chưa có dòng thì ô điều khiển bị ẩn: phải nhập → lưu →
    // tải lại trang mới đánh dấu "đã trả" được. Dưới đây là kỳ ảo (0/0/0/unpaid
    // trong view model, KHÔNG có dòng trong DB) và đường ghi tự tạo dòng.
    // =====================================================================

    /** TEST 1 — Kỳ chưa có sao kê vẫn hiện ô trạng thái, mặc định "chưa thanh toán". */
    #[Test]
    public function period_without_statement_still_shows_payment_status(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');

        $this->assertSame(0, CreditCardStatement::query()->count(), 'Kỳ thử nghiệm phải chưa có dòng.');

        $html = $this->statementsPage($card);

        // Ô điều khiển PHẢI hiện — đây là điều trước đây sai.
        $this->assertVisible($html, 'payment-controls-'.$card->id);

        $state = $this->stateIn($html, $card->id);

        $this->assertSame('unpaid', $state['status']);
        $this->assertSame('unpaid', $state['payment_status']);
        $this->assertSame('Chưa thanh toán', $state['status_label']);
        $this->assertFalse($state['is_paid']);

        // Dropbox phải đứng ở "Chưa thanh toán".
        $select = $this->selectOfTestId($html, 'data-payment-status');

        $this->assertStringContainsString('<option value="unpaid" selected>', $select);
    }

    /** TEST 2 — Số tiền của kỳ ảo là 0/0/0, và vẫn phân biệt được với "đã nhập". */
    #[Test]
    public function period_without_statement_displays_zero_statement_values(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');

        $state = $this->stateIn($this->statementsPage($card), $card->id);

        $this->assertFalse($state['statement_exists']);
        $this->assertSame('0.00', $state['actual_spend']);
        $this->assertSame('0.00', $state['actual_reward']);
        $this->assertSame('0.00', $state['closing_balance']);

        // Dòng nhắc nhẹ phải hiện, và phải nói rõ 0 là mặc định chứ không phải
        // kết quả người dùng đã nhập.
        $this->assertFalse($state['statement_data_entered']);

        $html = $this->statementsPage($card);

        $this->assertVisible($html, 'statement-no-data-'.$card->id);
        $this->assertStringContainsString('Chưa nhập sao kê thực tế', $html);

        // Nút vẫn là "Nhập sao kê" — chưa có dòng thì chưa có gì để sửa.
        $this->assertStringContainsString('Nhập sao kê', $html);
    }

    /** TEST 7 — Đọc trang KHÔNG tạo dòng (và cũng không tạo bản ghi kỳ). */
    #[Test]
    public function unpaid_virtual_statement_does_not_create_db_row_on_read(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');

        $statementsBefore = CreditCardStatement::query()->count();
        $periodsBefore = StatementPeriod::query()->count();

        // Mở trang nhiều lần và xem API: tất cả đều là đường đọc.
        $this->statementsPage($card);
        $this->statementsPage($card);
        $this->actingAs($this->owner)->get('/thetindung/api/sao-ke')->assertOk();

        $this->assertSame(
            $statementsBefore,
            CreditCardStatement::query()->count(),
            'Xem trang không được sinh dòng sao kê.',
        );
        $this->assertSame(
            $periodsBefore,
            StatementPeriod::query()->count(),
            'Xem trang không được sinh bản ghi kỳ.',
        );
    }

    /** TEST 8 — Chỉ hành động của người dùng mới tạo dòng, và chỉ khi chọn "đã trả". */
    #[Test]
    public function paid_virtual_statement_is_persisted_only_on_user_action(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $periodStart = $this->completedPeriodStart($card, 1);

        $this->statementsPage($card);

        $this->assertSame(0, CreditCardStatement::query()->count(), 'GET không được tạo dòng.');

        // Chọn "đã trả" ⇒ hành động ghi ⇒ dòng được tạo.
        $this->markPeriodPaid($card, $periodStart);

        $this->assertSame(1, CreditCardStatement::query()->count());
    }

    /** TEST 3 — Chọn "đã trả" khi chưa có dòng: tạo dòng 0/0/0 mang trạng thái paid. */
    #[Test]
    public function select_paid_before_statement_exists_creates_zero_statement(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $periodStart = $this->completedPeriodStart($card, 1);

        $this->assertSame(0, CreditCardStatement::query()->count());

        $this->markPeriodPaid($card, $periodStart);

        $this->assertSame(1, CreditCardStatement::query()->count());

        $statement = CreditCardStatement::query()->firstOrFail();

        $this->assertSame('paid', $statement->payment_status);
        $this->assertSame('0.00', $statement->actual_spend);
        $this->assertSame('0.00', $statement->actual_reward);
        $this->assertSame('0.00', $statement->closing_balance);

        // Đúng cặp khoá, không tạo nhầm sang kỳ khác.
        $this->assertSame((int) $card->id, (int) $statement->user_card_id);

        $period = StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->findOrFail((int) $statement->statement_period_id);

        // Cột là DATETIME nên so sánh theo ngày, không so sánh chuỗi thô.
        $this->assertSame(
            $periodStart,
            CarbonImmutable::parse($period->period_start)->toDateString(),
        );

        // Gọi lần nữa KHÔNG sinh dòng thứ hai (UNIQUE + tra cứu trong transaction).
        $this->markPeriodPaid($card, $periodStart);

        $this->assertSame(1, CreditCardStatement::query()->count(), 'Không được tạo dòng trùng.');
    }

    /** TEST 4 — Response đủ để client vẽ lại ngay, không cần tải trang. */
    #[Test]
    public function select_paid_before_statement_exists_updates_ui_without_reload(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $periodStart = $this->completedPeriodStart($card, 1);

        // Trước khi bấm: hạn 22/10, hôm 17/10, nhắc mặc định 1 ngày ⇒ chưa tới
        // vùng nhắc nên là "sắp đến hạn" (vẫn chưa phải kỳ đã trả, vẫn chưa có dòng).
        $before = $this->stateIn($this->statementsPage($card), $card->id);

        $this->assertSame('upcoming', $before['state']);
        $this->assertFalse($before['statement_exists']);

        $response = $this->actingAs($this->owner)->patchJson(
            $this->paymentPeriodUrl($card),
            ['payment_status' => 'paid', 'period_start' => $periodStart],
        )->assertOk();

        $payment = $response->json('payment');

        // Đủ mọi thứ client cần để cập nhật tại chỗ.
        $this->assertSame('paid', $payment['payment_status']);
        $this->assertSame('Đã thanh toán', $payment['payment_status_label']);
        $this->assertSame('Hạn thanh toán kỳ này: 22/10/2026', $payment['due_line']);
        $this->assertSame('settled', $payment['due_tone']);
        $this->assertSame('0.00', $payment['actual_spend']);
        $this->assertSame('0.00', $payment['actual_reward']);
        $this->assertSame('0.00', $payment['closing_balance']);
        $this->assertSame('2026-10-22', $payment['payment_due_date']);
        $this->assertTrue($payment['statement_exists']);
        $this->assertNotNull($payment['statement_id']);

        // Dòng vừa tạo là dòng THẬT nhưng CHƯA có số liệu.
        $this->assertFalse($payment['statement_data_entered']);

        // Cảnh báo biến mất, dấu đã trả hiện lên — không tải lại trang.
        $this->assertNull($payment['alert']);
        $this->assertTrue($payment['is_paid']);

        $html = $this->statementsPage($card);

        $this->assertVisible($html, 'payment-paid-'.$card->id);
        $this->assertHidden($html, 'payment-alert-'.$card->id);
        // Vẫn nhắc chưa nhập số liệu: dòng 0/0/0 KHÔNG phải bảng kê đã nhập.
        $this->assertVisible($html, 'statement-no-data-'.$card->id);
    }

    /** TEST 5 — Đánh dấu trả TRƯỚC, nhập số SAU: số đổi, trạng thái giữ nguyên `paid`. */
    #[Test]
    public function enter_statement_after_pre_marking_paid_preserves_paid_status(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $periodStart = $this->completedPeriodStart($card, 1);

        $this->markPeriodPaid($card, $periodStart);

        $preMarked = CreditCardStatement::query()->firstOrFail();

        $this->assertSame('paid', $preMarked->payment_status);
        $this->assertSame('0.00', $preMarked->actual_spend);

        // Nay nhập số liệu thật.
        $this->writeStatement($card, $periodStart, '1600000', '0');

        $after = $preMarked->fresh();

        $this->assertSame('1600000.00', $after->actual_spend);
        $this->assertSame('1600000.00', $after->closing_balance);
        $this->assertSame(
            'paid',
            $after->payment_status,
            'Nhập số liệu KHÔNG được reset trạng thái về chưa thanh toán.',
        );

        $state = $this->stateIn($this->statementsPage($card), $card->id);

        $this->assertSame('paid', $state['status']);
        $this->assertTrue($state['is_paid']);
        $this->assertNull($state['alert']);
        $this->assertSame('1600000.00', $state['actual_spend']);
        // Đã có số liệu ⇒ lời nhắc "chưa nhập" phải tắt.
        $this->assertTrue($state['statement_data_entered']);
    }

    /** TEST 6 — Ngược lại: nhập số TRƯỚC, đánh dấu trả SAU: chỉ đổi trạng thái. */
    #[Test]
    public function enter_statement_first_then_mark_paid(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');
        $periodStart = $this->completedPeriodStart($card, 1);

        $statement = $this->writeStatement($card, $periodStart, '1600000', '0');

        $this->assertSame('unpaid', $statement->payment_status);

        $this->markPeriodPaid($card, $periodStart);

        $after = $statement->fresh();

        $this->assertSame('paid', $after->payment_status);

        // Số tiền phải y nguyên — đánh dấu trả không được đụng tiền.
        $this->assertSame('1600000.00', $after->actual_spend);
        $this->assertSame('0.00', $after->actual_reward);
        $this->assertSame('1600000.00', $after->closing_balance);
    }

    /** TEST 9 — Kỳ đã trả nhưng hạn đã quá: không cảnh báo, dù dòng là 0/0/0. */
    #[Test]
    public function paid_zero_statement_has_no_payment_warning(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-30');
        $periodStart = $this->completedPeriodStart($card, 1);

        // Hạn 22/10, hôm nay 30/10 ⇒ quá hạn 8 ngày.
        $unpaid = $this->stateIn($this->statementsPage($card), $card->id);

        $this->assertSame('overdue', $unpaid['state']);
        $this->assertNotNull($unpaid['alert'], 'Kỳ ảo chưa trà thì vẫn phải cảnh báo quá hạn.');

        $this->markPeriodPaid($card, $periodStart);

        $paid = $this->stateIn($this->statementsPage($card), $card->id);

        $this->assertSame('paid', $paid['state']);
        $this->assertNull($paid['alert']);
        $this->assertSame('Hạn thanh toán kỳ này: 22/10/2026', $paid['due_line']);
        $this->assertSame('settled', $paid['due_tone']);
    }

    /** TEST bổ sung — Giữ "chưa thanh toán" trên kỳ ảo thì KHÔNG tạo dòng. */
    #[Test]
    public function keeping_unpaid_on_a_virtual_period_creates_nothing(): void
    {
        $card = $this->makeCard();
        $this->at('2026-10-17');

        $this->actingAs($this->owner)->patchJson(
            $this->paymentPeriodUrl($card),
            [
                'payment_status' => 'unpaid',
                'period_start' => $this->completedPeriodStart($card, 1),
            ],
        )->assertOk();

        $this->assertSame(
            0,
            CreditCardStatement::query()->count(),
            'Giữ nguyên "chưa thanh toán" không phải hành động ghi — không tạo dòng rác.',
        );
    }

    /** TEST bổ sung — Không đổi được trạng thái kỳ của thẻ khác. */
    #[Test]
    public function a_user_cannot_mark_another_users_card_period_paid(): void
    {
        $ownerCard = $this->makeCard();
        $other = User::factory()->create();
        $otherCard = $this->makeUserCard($other->id);

        // `findOwned()` ném InvalidArgumentException ⇒ 422 (quy ước sẵn có của
        // `UserCardService::findOwned()`, không phải 403). Điều cần chứng minh
        // là KHÔNG có gì được ghi vào thẻ của người khác.
        $this->actingAs($ownerCard->user)
            ->patchJson($this->paymentPeriodUrl($otherCard), [
                'payment_status' => 'paid',
                'period_start' => $this->completedPeriodStart($otherCard, 1),
            ])
            ->assertStatus(422);

        $this->assertSame(0, CreditCardStatement::query()->count());
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * Đóng băng trạng thái "đã thanh toán" cho một kỳ — đúng cách giao diện làm:
     * PATCH theo KỲ qua endpoint dành riêng cho kỳ chưa có dòng.
     */
    private function markPeriodPaid($card, string $periodStart): void
    {
        $this->actingAs($this->owner)->patchJson($this->paymentPeriodUrl($card), [
            'payment_status' => 'paid',
            'period_start' => $periodStart,
        ])->assertOk();
    }

    private function paymentPeriodUrl($card): string
    {
        return route('credit-cards.api.statements.payment.update-period', ['userCard' => $card->id]);
    }


    /**
     * Thẻ chốt ngày 1, hạn trả ngày 22 ⇒ kỳ 01/09–30/09 có hạn 22/10/2026.
     */
    private function makeCard(
        string $periodStart = '2026-01-01',
        int $dueDay = 22,
    ): UserCard {
        return $this->makeUserCard($this->owner->id, [
            'statement_period_start' => $periodStart,
            'statement_day' => 1,
            'payment_due_day' => $dueDay,
        ]);
    }

    private function at(string $today): void
    {
        // Đóng băng đồng hồ ở giữa ngày: nếu dùng đầu ngày thì "còn N ngày" và
        // "đúng hạn" dễ lệch nhau một ngày khi test chạy qua nửa đêm.
        $this->travelTo(CarbonImmutable::parse($today.' 09:00:00'));
    }

    private function writeStatement(
        $card,
        ?string $periodStart = null,
        string $spend = '1000000',
        string $reward = '0',
    ): CreditCardStatement {
        return app(CreditCardStatementService::class)->upsertForPeriodStart(
            $card,
            $periodStart ?? $this->completedPeriodStart($card, 1),
            ['actual_spend' => $spend, 'actual_reward' => $reward],
        );
    }

    private function setStatus(CreditCardStatement $statement, string $status): void
    {
        $this->actingAs($this->owner)->patchJson($this->paymentUrl($statement->fresh()), [
            'payment_status' => $status,
        ])->assertOk();
    }

    private function saveReminderDays(int $days): void
    {
        $this->actingAs($this->owner)->patchJson($this->reminderUrl(), [
            'payment_reminder_days' => $days,
        ])->assertOk();
    }

    /** Số ngày đang LƯU trong DB, không phải số service chuẩn hoá trả về. */
    private function storedReminderDays(): ?int
    {
        $setting = CreditCardUserSetting::query()
            ->where('user_id', $this->owner->id)
            ->first();

        return $setting === null ? null : (int) $setting->payment_reminder_days;
    }

    private function paymentUrl(CreditCardStatement $statement): string
    {
        return route('credit-cards.api.statements.payment.update', ['statement' => $statement->id]);
    }

    private function reminderUrl(): string
    {
        return route('credit-cards.api.settings.payment-reminder.update');
    }

    private function statementsPage($card, ?string $periodStart = null): string
    {
        $url = '/thetindung/sao-ke';

        if ($periodStart !== null) {
            $url .= '?period['.$card->id.']='.$periodStart;
        }

        return $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
    }

    /**
     * Nội dung `data-payment-state` của một dòng, đã giải entity HTML.
     *
     * @return array<string, mixed>
     */
    private function stateIn(string $html, int $cardId): array
    {
        $position = strpos($html, 'data-payment-state=', $this->rowOffset($html, $cardId));

        $this->assertNotFalse($position, "Dòng {$cardId} không có data-payment-state.");

        $quote = strpos($html, '"', $position);
        $close = strpos($html, '"', $quote + 1);

        $raw = html_entity_decode(
            substr($html, $quote + 1, $close - $quote - 1),
            ENT_QUOTES | ENT_HTML5,
        );

        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded, 'data-payment-state phải là JSON hợp lệ.');

        return $decoded;
    }

/**
     * DÒNG HẠN (dòng chữ đã trả về), giải entity HTML.
     *
     * Đây chính là dòng từng hiện sai kiểu "Đến hạn … · quá hạn N ngày" bên cạnh
     * dấu ✓ ĐÃ THANH TOÁN, nên nó phải có test riêng chứ không được ngầm phụ thuộc.
     */
    private function dueLineOf(string $html): string
    {
        $position = strpos($html, 'data-payment-due-line');

        $this->assertNotFalse($position, 'Không tìm thấy dòng hạn.');

        $open = strpos($html, '>', $position);
        $close = strpos($html, '</span>', $position);

        $this->assertNotFalse($open);
        $this->assertNotFalse($close);

        return trim(html_entity_decode(
            substr($html, $open + 1, $close - $open - 1),
            ENT_QUOTES | ENT_HTML5,
        ));
    }

    /**
     * Thân một hàm/method JS trong HTML, cắt theo cặp ngoặc đầu tiên sau nó.
     *
     * Nhận cả dạng `function foo()` (hàm rời) lẫn dạng `foo() {` (method trong
     * Alpine component — dạng này không có từ khoá `function`).
     */
    private function bodyOfFunction(string $html, string $function): string
    {
        // Nhận cả dạng `function foo() {` (hàm rời) lẫn `foo() {` (method Alpine —
        // không có từ khoá `function`). Neo `^` + `m` để không dính nhầm vào lời
        // gọi hàm nào đó nằm giữa dòng, và bỏ qua khác biệt thụt lề/xuống dòng.
        $matched = preg_match(
            '/^[ \t]*(?:function[ \t]+)?'.preg_quote($function, '/').'[ \t]*\(/m',
            $html,
            $match,
            PREG_OFFSET_CAPTURE,
        );

        $this->assertSame(1, $matched, "Không tìm thấy định nghĩa hàm JS {$function}.");

        $start = $match[0][1];

        $open = strpos($html, '{', $start);

        $this->assertNotFalse($open);

        $depth = 0;

        for ($i = $open; $i < strlen($html); $i++) {
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
     * Bỏ chú thích JS trước khi khẳng định về mã.
     *
     * Bình luận trong mã PHẢI nhắc tên chính thứ của thứ từng bị bỏ — đó là cách
     * ngăn người sau vô tình thêm lại. Quét cả bình luận thì khẳng định "không
     * được dùng nữa" luôn đỏ, đúng cái giá của việc viết tốt.
     */
    private function stripJsComments(string $code): string
    {
        return (string) preg_replace('#(?<!:)//[^\n]*#', '', (string) preg_replace('#/\*.*?\*/#s', '', $code));
    }

    /** Ngày hạn trả `Y-m-d` mà server dùng cho kỳ đã kết thúc gần nhất. */
    private function dueDateFor($card): string
    {
        [, $end] = app(StatementPeriodService::class)->completedBoundaries($card, CarbonImmutable::now());

        return app(CreditCardStatementService::class)->dueDateFor($card, null, $end)->toDateString();
    }

    /** Thẻ mở đang chứa mọi node dòng hạn. */
    private function dueLineTag(string $html): string
    {
        $position = strpos($html, 'data-payment-due-line');

        $this->assertNotFalse($position, 'Không tìm thấy dòng hạn.');

        $tagStart = strrpos(substr($html, 0, $position), '<');
        $tagEnd = strpos($html, '>', $position);

        return substr($html, (int) $tagStart, $tagEnd - $tagStart + 1);
    }

    /**
     * MỌI node dòng hạn trên trang, chứ không phải node đầu tiên.
     *
     * Đây chính là cái bẫy đã làm bug lọt: dòng hạn từng được render HAI LẦN (một
     * node dưới tiêu đề thẻ, một node trong khối ô chọn trạng thái) trong khi
     * `applyPayment()` chỉ dựng lại node đầu tiên. Test chỉ đọc node đầu thì xanh
     * mà trình duyệt vẫn hiện dòng đỏ thứ hai. Vì vậy mọi khẳng định về dòng hạn
     * phải soi TẤT CẢ node.
     *
     * @return list<string>
     */
    private function allDueLinesOf(string $html): array
    {
        // Bỏ hẳn `<script>`: JS cũng nhắc tên `data-payment-due-line` trong
        // `querySelectorAll`, và mũi tên `=>` của nó sẽ bị nhầm là dấu `>` mở thẻ
        // nếu quét thẳng. Dòng hạn là MARKUP, không phải mã.
        $markup = preg_replace('#<script\b.*?</script>#s', '', (string) $html);

        $lines = [];
        $offset = 0;

        while (($position = strpos($markup, 'data-payment-due-line>', $offset)) !== false) {
            $open = $position + strlen('data-payment-due-line');
            $close = strpos($markup, '</', $open);

            $lines[] = trim(html_entity_decode(
                substr($markup, $open + 1, $close - $open - 1),
                ENT_QUOTES | ENT_HTML5,
            ));

            $offset = $close;
        }

        $this->assertNotEmpty($lines, 'Không tìm thấy dòng hạn nào.');

        return $lines;
    }

    /**
     * Bắt đầu từ thẻ chứa `data-card-id` để không đọc nhầm dòng của thẻ khác.
     */
    private function rowOffset(string $html, int $cardId): int
    {
        $needle = 'data-card-id="'.$cardId.'"';

        $position = strpos($html, $needle);

        $this->assertNotFalse($position, "Không tìm thấy thẻ {$cardId} trong HTML.");

        return $position;
    }

    /**
     * Bên trong khối cảnh báo của một dòng.
     *
     * Khối chỉ chứa hai thẻ `<p>` nên `</div>` kế tiếp là điểm đóng nó — không cần
     * viết bộ đếm thẻ HTML.
     */
    private function paymentAlertOf(string $html, int $cardId): string
    {
        $anchor = 'data-testid="payment-alert-'.$cardId.'"';

        $position = strpos($html, $anchor, $this->rowOffset($html, $cardId));

        $this->assertNotFalse($position, "Không tìm thấy khối cảnh báo của thẻ {$cardId}.");

        $close = strpos($html, '</div>', $position);

        $this->assertNotFalse($close);

        return html_entity_decode(
            substr($html, $position, $close - $position),
            ENT_QUOTES | ENT_HTML5,
        );
    }

    /**
     * `period_start` của kỳ đã kết thúc, lùi thêm `$back` kỳ nữa (1 = gần nhất).
     */
    private function completedPeriodStart($card, int $back = 1): string
    {
        $periods = app(StatementPeriodService::class);

        [$start] = $periods->completedBoundaries($card, CarbonImmutable::now());

        for ($i = 1; $i < $back; $i++) {
            $start = $start->subMonthNoOverflow();
        }

        return $start->toDateString();
    }

    /**
     * Thẻ trong HTML có đang hiện không.
     *
     * Khối cảnh báo luôn được render rồi ẩn bằng lớp `hidden` (xem Blade), nên phải
     * kiểm LỚP chứ không kiểm sự có mặt — node luôn có mặt.
     */
    private function assertVisible(string $html, string $testId): void
    {
        $tag = $this->tagOfTestId($html, $testId);

        $this->assertStringNotContainsString('hidden', $tag, "[{$testId}] phải hiện.");
    }

    private function assertHidden(string $html, string $testId): void
    {
        $tag = $this->tagOfTestId($html, $testId);

        $this->assertStringContainsString('hidden', $tag, "[{$testId}] phải ẩn.");
    }

    /**
     * Nội dung một thẻ `<select>` được đánh dấu bằng `$needle`.
     */
    private function selectOfTestId(string $html, string $needle): string
    {
        $position = strpos($html, $needle);

        $this->assertNotFalse($position, "Không tìm thấy {$needle}");

        $open = strrpos(substr($html, 0, $position), '<select');
        $close = strpos($html, '</select>', $position);

        $this->assertNotFalse($open);
        $this->assertNotFalse($close);

        return substr($html, (int) $open, (int) $close - (int) $open);
    }

    /**
     * Thẻ mở chứa `data-testid` — cắt từ `<` gần nhất tới `>` kế tiếp.
     */
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
     * Cột của bảng `credit_card_statements` trên connection thật.
     *
     * @return list<string>
     */
    private function creditCardSchema(): array
    {
        return app('db')->connection('creditcard')
            ->getSchemaBuilder()
            ->getColumnListing('credit_card_statements');
    }
}