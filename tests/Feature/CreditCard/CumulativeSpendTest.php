<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CumulativeSpendUpdate;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardCumulativeSpendService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Mốc CHI TIÊU LŨY KẾ KỲ TRƯỚC + tab "Phí thường niên và chi tiêu tích lũy".
 *
 * Bất biến khoá ở đây:
 *   1. Ngày mốc do SERVER suy từ ngày chốt sao kê của KỲ TRƯỚC, KHÔNG nhận input
 *      và KHÔNG lấy ngày hiện tại.
 *   2. Nhãn hiển thị + nhãn form dùng định dạng `dd/mm/yyyy` và nói rõ khi chưa
 *      xác định được ngày chốt sao kê kỳ trước.
 *   3. Form cập nhật KHÔNG còn ô chọn ngày.
 *   4. Lưu mốc ghi đúng cột + một dòng nhật ký (old/new/date/user).
 *   5. Mốc/ngày đã lưu giữ nguyên khi kỳ chuyển sang kỳ mới (không cộng trùng,
 *      không mất mốc).
 *   6. Mở tab phí KHÔNG tạo `StatementPeriod`.
 *   7. Thẻ người khác ⇒ 403; mốc sai ⇒ 422.
 *   8. Nút lưu phải nhắm ĐÚNG dòng tab phí (`fee-row`), KHÔNG bắt nhầm dòng sao
 *      kê (bug cũ: query `[data-card-id]` trần ⇒ gửi mốc rỗng ⇒ 422). Số nguyên
 *      lưu nguyên vẹn, số 0 hợp lệ, chuỗi có dấu phân cách bị server từ chối vì
 *      client đã chuẩn hoá (test hành vi parse ở `tests/js/money-parse.test.mjs`).
 *   9. Nút "Cập nhật chi tiêu lũy kế" cạnh số liệu mốc, mỗi thẻ một nút.
 *  10. Khu vực tiến độ hiển thị `tổng lũy kế / ngưỡng` + `Đạt X%` + `Còn Y`;
 *      thiếu mốc/ngưỡng ⇒ "chưa đủ dữ liệu", KHÔNG vẽ tiến độ giả từ 0.
 *  11. `remaining` = ngưỡng − tổng, TỐI THIỂU 0; thanh kẹp ≤ 100% dù vượt ngưỡng.
 *  12. Số ngày còn lại tới ngày tính phí tính theo NGÀY LỊCH VN: còn nhiều ngày /
 *      đến hạn hôm nay / ngày đã qua (không số âm) / chưa khai; đúng qua năm nhuận.
 *      Dòng này hiển thị NGAY DƯỚI dòng phần trăm trong KHU VỰC TIẾN ĐỘ (không
 *      còn nằm trong ô "Ngày tính phí tiếp theo").
 *
 * Giờ test cố định (`travelTo`) để ranh giới kỳ tất định:
 *   anchor 15, hôm nay 20/10/2026 ⇒ kỳ trước 15/09 → 14/10/2026.
 *   anchor 5,  hôm nay 20/10/2026 ⇒ kỳ trước 05/09 → 04/10/2026.
 */
class CumulativeSpendTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();

        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
    }

    private function service(): CreditCardCumulativeSpendService
    {
        return app(CreditCardCumulativeSpendService::class);
    }

    // =====================================================================
    // Ngày chốt sao kê kỳ trước — xác định từ chu kỳ thẻ, không từ input
    // =====================================================================

    #[Test]
    public function the_baseline_date_is_derived_from_the_previous_statement_period(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $row = $this->service()->row($card, CarbonImmutable::parse('2026-10-20'));

        // Kỳ trước của anchor 15 là 15/09 → 14/10; ngày mốc = ngày chốt = 14/10.
        $this->assertSame('2026-10-14', $row['previous_period']['end']);
        $this->assertSame('14/10/2026', $row['baseline_date_label']);
        $this->assertSame('previous_period', $row['baseline_date_source']);
    }

    #[Test]
    public function each_card_derives_the_date_from_its_own_statement_cycle(): void
    {
        $card15 = $this->makeUserCard($this->owner->id, ['statement_day' => 15, 'name' => 'Thẻ anchor 15']);
        $card05 = $this->makeUserCard($this->owner->id, ['statement_day' => 5, 'name' => 'Thẻ anchor 5']);

        $today = CarbonImmutable::parse('2026-10-20');

        $this->assertSame('2026-10-14', $this->service()->row($card15, $today)['previous_period']['end']);
        $this->assertSame('2026-10-04', $this->service()->row($card05, $today)['previous_period']['end']);
    }

    #[Test]
    public function an_unconfigured_card_reports_an_unknown_previous_closing_date(): void
    {
        // Không đủ cấu hình kỳ ⇒ KHÔNG bịa ngày. Model chưa lưu là đủ để kiểm
        // nhánh phòng thủ này (schema luôn có `statement_day` nên đây là guard).
        $card = new UserCard([
            'statement_day' => null,
            'statement_period_start' => null,
        ]);

        $row = $this->service()->row($card, CarbonImmutable::parse('2026-10-20'));

        $this->assertNull($row['baseline_date_label']);
        $this->assertSame('unknown', $row['baseline_date_source']);
    }

    // =====================================================================
    // API cập nhật mốc
    // =====================================================================

    #[Test]
    public function saving_the_baseline_stores_the_server_derived_date(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $response = $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cumulative-spend.update', $card->id),
            ['cumulative_spend_baseline' => 5000000, 'cumulative_spend_note' => 'Sao kê tháng 9'],
        );

        $response->assertOk();

        $card->refresh();
        $this->assertSame('5000000.00', $card->cumulative_spend_baseline);
        $this->assertSame('2026-10-14', $card->cumulative_spend_baseline_date->toDateString());
        $this->assertSame('Sao kê tháng 9', $card->cumulative_spend_note);

        // Response tính lại từ server, kèm nhãn ngày đúng kỳ trước.
        $response->assertJsonPath('data.baseline_date_label', '14/10/2026');
    }

    #[Test]
    public function the_client_cannot_choose_or_override_the_baseline_date(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        // Gửi kèm ngày (kể cả rác): server PHẢI bỏ qua và vẫn suy từ kỳ sao kê.
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => 1000000,
                'cumulative_spend_baseline_date' => '2020-01-01',
                'cumulative_spend_note' => null,
            ])
            ->assertOk();

        $this->assertSame('2026-10-14', $card->fresh()->cumulative_spend_baseline_date->toDateString());
    }

    #[Test]
    public function saving_the_baseline_writes_a_history_row_with_the_derived_date(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'cumulative_spend_baseline' => 3000000,
            'cumulative_spend_baseline_date' => '2026-09-14',
        ]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => 4500000,
                'cumulative_spend_note' => 'Cập nhật',
            ])
            ->assertOk();

        $log = CumulativeSpendUpdate::query()->where('user_card_id', $card->id)->latest('id')->firstOrFail();

        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('3000000.00', $log->old_baseline);
        $this->assertSame('4500000.00', $log->new_baseline);
        $this->assertSame('2026-10-14', $log->baseline_date->toDateString());
        $this->assertSame('Cập nhật', $log->note);
    }

    #[Test]
    public function a_stranger_cannot_update_the_baseline(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $this->actingAs(User::factory()->create())
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => 999999,
            ])
            ->assertForbidden();

        $this->assertNull($card->fresh()->cumulative_spend_baseline);
    }

    #[Test]
    public function an_invalid_baseline_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cumulative_spend_baseline');

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cumulative_spend_baseline');
    }

    // =====================================================================
    // Kỳ chuyển tiếp — không cộng trùng, không mất mốc
    // =====================================================================

    #[Test]
    public function a_saved_baseline_keeps_its_date_when_the_period_rolls_over(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => 5000000,
            ])
            ->assertOk();

        // Sang kỳ mới: kỳ trước giờ là 15/10 → 14/11. Mốc KHÔNG bị đổi ngày hay
        // cộng thêm chi tiêu; nhãn vẫn gắn với ngày người dùng đã khai.
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00'));

        $row = $this->service()->row($card->fresh(), CarbonImmutable::parse('2026-11-20'));

        $this->assertSame('2026-11-14', $row['previous_period']['end']);
        $this->assertSame('2026-10-14', $row['baseline_date']);
        $this->assertSame('14/10/2026', $row['baseline_date_label']);
        $this->assertSame('5000000.00', $row['baseline']);
    }

    #[Test]
    public function the_cumulative_is_baseline_plus_current_period_spend(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'cumulative_spend_baseline' => 2000000,
            'annual_fee_waiver_spend' => 10000000,
        ]);

        $row = $this->service()->row($card, CarbonImmutable::parse('2026-10-20'));

        // Chưa có giao dịch kỳ hiện tại ⇒ tổng = mốc.
        $this->assertSame('0.00', $row['period_spend']);
        $this->assertSame('2000000.00', $row['cumulative']);
        $this->assertSame(20.0, $row['progress_percent']);
        $this->assertSame('8000000.00', $row['remaining']);
        $this->assertFalse($row['reached']);
    }

    // =====================================================================
    // Trang Sao kê — tab phí
    // =====================================================================

    #[Test]
    public function the_fee_form_has_no_date_picker_and_shows_the_new_label(): void
    {
        $this->makeUserCard($this->owner->id, ['statement_day' => 15, 'name' => 'Thẻ của tôi']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        // Không còn ô chọn ngày ở bất kỳ đâu trong trang.
        $this->assertStringNotContainsString('name="cumulative_spend_baseline_date"', $html);
        $this->assertStringNotContainsString('type="date"', $html);

        // Nhãn mới + ngày chốt kỳ trước đúng định dạng dd/mm/yyyy.
        $this->assertStringContainsString(
            'Chi tiêu lũy kế tính đến hết kỳ trước (đến ngày 14/10/2026)',
            $html
        );
    }

    #[Test]
    public function the_fee_row_shows_the_next_fee_date_in_dd_mm_yyyy(): void
    {
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'next_annual_fee_date' => '2026-12-15',
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('15/12/2026', $html);
    }

    #[Test]
    public function the_fees_tab_lists_only_the_owners_cards(): void
    {
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ của tôi']);

        $stranger = User::factory()->create();
        $this->makeUserCard($stranger->id, ['name' => 'Thẻ người khác']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Thẻ của tôi', $html);
        $this->assertStringNotContainsString('Thẻ người khác', $html);
    }

    #[Test]
    public function opening_the_fees_tab_creates_no_statement_period(): void
    {
        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk();

        $this->assertSame(0, StatementPeriod::query()->count());
    }

    #[Test]
    public function the_statements_tab_still_renders_the_existing_search(): void
    {
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ của tôi']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Ô tìm kiếm tab Sao kê giữ nguyên; tab phí vẫn tồn tại nhưng ẩn.
        $this->assertStringContainsString('x-model="cardQuery"', $html);
        $this->assertStringContainsString('data-testid="statements-tab-fees"', $html);
    }

    // =====================================================================
    // Sửa lỗi lưu mốc: chọn đúng dòng tab phí + giá trị số nguyên
    // =====================================================================

    #[Test]
    public function saving_a_plain_integer_baseline_stores_it_exactly(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => 28005700,
            ])
            ->assertOk()
            ->assertJsonPath('data.baseline', '28005700.00')
            ->assertJsonPath('data.cumulative', '28005700.00')
            ->assertJsonPath('data.baseline_date_label', '14/10/2026');

        $this->assertSame('28005700.00', $card->fresh()->cumulative_spend_baseline);

        // Lịch sử phản ánh đúng old/new + ngày chốt kỳ trước do server suy.
        $log = CumulativeSpendUpdate::query()->where('user_card_id', $card->id)->latest('id')->firstOrFail();
        $this->assertNull($log->old_baseline);
        $this->assertSame('28005700.00', $log->new_baseline);
        $this->assertSame('2026-10-14', $log->baseline_date->toDateString());
    }

    #[Test]
    public function saving_accepts_a_numeric_string_baseline(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => '28005700',
            ])
            ->assertOk();

        $this->assertSame('28005700.00', $card->fresh()->cumulative_spend_baseline);
    }

    #[Test]
    public function a_zero_baseline_is_accepted_and_stored(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => 0,
            ])
            ->assertOk();

        $this->assertSame('0.00', $card->fresh()->cumulative_spend_baseline);
    }

    #[Test]
    public function a_grouped_baseline_string_is_rejected_so_the_client_must_normalize(): void
    {
        // Tầng server KHÔNG nhận chuỗi còn dấu phân cách: client phải chuẩn hoá về
        // số nguyên (xem tests/js/money-parse.test.mjs). Nếu lọt tới đây, phải báo
        // lỗi rõ ràng ở đúng trường, KHÔNG âm thầm quy về 0.
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cumulative-spend.update', $card->id), [
                'cumulative_spend_baseline' => '28,005,700',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cumulative_spend_baseline')
            ->assertJsonPath(
                'errors.cumulative_spend_baseline.0',
                'Chi tiêu lũy kế tính đến hết kỳ trước không hợp lệ.'
            );

        $this->assertNull($card->fresh()->cumulative_spend_baseline);
    }

    #[Test]
    public function each_card_gets_a_baseline_edit_button_wired_to_its_own_form(): void
    {
        $a = $this->makeUserCard($this->owner->id, ['statement_day' => 15, 'name' => 'Thẻ A']);
        $b = $this->makeUserCard($this->owner->id, ['statement_day' => 15, 'name' => 'Thẻ B']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        // Mỗi thẻ ĐÚNG một nút riêng ở tab phí, và nút đó mở đúng form của thẻ.
        foreach ([$a, $b] as $card) {
            $needle = 'data-testid="fee-edit-'.$card->id.'"';
            $this->assertSame(1, substr_count($html, $needle), 'Mỗi thẻ chỉ có một nút cập nhật mốc.');

            // Trong đúng thẻ <button> đó phải gọi openEdit(id) của chính nó.
            $tag = substr($html, strpos($html, $needle), 300);
            $this->assertStringContainsString('openEdit('.$card->id.')', $tag);
        }
    }

    #[Test]
    public function the_progress_area_uses_the_new_labels_and_hides_it_without_a_baseline(): void
    {
        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        // Nhãn khu vực tiến độ theo mẫu.
        $this->assertStringContainsString('Chi tiêu lũy kế hiện tại / Chi tiêu cần đạt', $html);

        // Chưa có mốc ⇒ KHÔNG vẽ tiến độ từ số 0, nói rõ chưa đủ dữ liệu.
        $this->assertStringContainsString('Chưa đủ dữ liệu để tính tiến độ', $html);

        // Nút đã đổi tên; nhãn cũ không còn.
        $this->assertStringContainsString('Cập nhật chi tiêu lũy kế', $html);
        $this->assertStringNotContainsString('Cập nhật mốc lũy kế', $html);
    }

    // =====================================================================
    // Khu vực tiến độ — công thức tỷ lệ / còn thiếu / vượt ngưỡng
    // =====================================================================

    #[Test]
    public function the_progress_percent_and_remaining_match_the_baseline_over_target(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'cumulative_spend_baseline' => 71950098,
            'annual_fee_waiver_spend' => 120000000,
        ]);

        $row = $this->service()->row($card, CarbonImmutable::parse('2026-10-20'));

        // 71.950.098 / 120.000.000 = 59,9584…% — `Decimal::percent` CẮT về 2 chữ
        // số (bcmath), nên là 59,95% chứ không làm tròn lên.
        $this->assertSame(59.95, $row['progress_percent']);
        // Còn thiếu = 120.000.000 − 71.950.098 = 48.049.902.
        $this->assertSame('48049902.00', $row['remaining']);
        $this->assertFalse($row['reached']);
    }

    #[Test]
    public function the_progress_area_renders_the_real_numbers_on_the_page(): void
    {
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'cumulative_spend_baseline' => 71950098,
            'annual_fee_waiver_spend' => 120000000,
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        // Số liệu lấy từ dữ liệu thật, không ghi cứng ví dụ.
        $this->assertStringContainsString('71.950.098', $html);
        $this->assertStringContainsString('120.000.000', $html);
        $this->assertStringContainsString('Đạt 59,95%', $html);
        $this->assertStringContainsString('48.049.902', $html);
    }

    #[Test]
    public function exceeding_the_target_reports_reached_and_caps_the_bar_at_100(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'cumulative_spend_baseline' => 130000000,
            'annual_fee_waiver_spend' => 120000000,
        ]);

        $row = $this->service()->row($card, CarbonImmutable::parse('2026-10-20'));
        $this->assertTrue($row['reached']);
        $this->assertSame('0.00', $row['remaining']);
        $this->assertGreaterThan(100, $row['progress_percent']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Đã đạt ngưỡng chi tiêu', $html);
        // Thanh tiến độ kẹp 100% dù chi tiêu vượt ngưỡng.
        $this->assertStringContainsString('width: 100%', $html);
    }

    #[Test]
    public function a_missing_threshold_keeps_the_progress_in_insufficient_state(): void
    {
        // Có mốc nhưng CHƯA khai ngưỡng ⇒ không đủ dữ liệu, không giả định ngưỡng = 0.
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'cumulative_spend_baseline' => 5000000,
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Chưa khai ngưỡng', $html);
        $this->assertStringContainsString('Chưa đủ dữ liệu để tính tiến độ', $html);
    }

    // =====================================================================
    // Số ngày còn lại tới ngày tính phí thường niên
    // =====================================================================

    #[Test]
    public function it_counts_days_until_the_next_annual_fee(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            // Hôm nay test là 20/10/2026 ⇒ 5 ngày.
            'next_annual_fee_date' => '2026-10-25',
        ]);

        $row = $this->service()->row($card, CarbonImmutable::parse('2026-10-20'));
        $this->assertSame('upcoming', $row['next_annual_fee_status']);
        $this->assertSame(5, $row['next_annual_fee_days']);
    }

    #[Test]
    public function the_fee_date_today_is_reported_as_due_today(): void
    {
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'next_annual_fee_date' => '2026-10-20',
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        // Dòng ngày nằm trong KHU VỰC TIẾN ĐỘ (không còn ở ô ngày tính phí).
        $this->assertStringContainsString('Đến hạn tính phí thường niên hôm nay', $html);
        $this->assertStringNotContainsString('Còn 0 ngày', $html);
    }

    #[Test]
    public function the_days_until_the_next_fee_are_shown_below_the_progress_line(): void
    {
        $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'cumulative_spend_baseline' => 71950098,
            'annual_fee_waiver_spend' => 120000000,
            // Hôm nay test là 20/10/2026 ⇒ 5 ngày.
            'next_annual_fee_date' => '2026-10-25',
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Còn 5 ngày đến kỳ tính phí thường niên', $html);

        // Dòng ngày phải đứng SAU dòng phần trăm tiến độ (thứ tự trong DOM).
        $this->assertGreaterThan(
            strpos($html, 'Đạt 59,95%'),
            strpos($html, 'Còn 5 ngày đến kỳ tính phí thường niên'),
        );

        // Ô "Ngày tính phí tiếp theo" CHỈ còn ngày, không còn chuỗi "Còn N ngày".
        $this->assertStringNotContainsString('15/12/2026', $html);
        $this->assertStringContainsString('25/10/2026', $html);
    }

    #[Test]
    public function a_past_fee_date_shows_passed_and_never_a_negative_number(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'next_annual_fee_date' => '2026-10-01',
        ]);

        $row = $this->service()->row($card, CarbonImmutable::parse('2026-10-20'));
        $this->assertSame('past', $row['next_annual_fee_status']);
        $this->assertNull($row['next_annual_fee_days']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Đã qua ngày tính phí', $html);
        $this->assertStringNotContainsString('Còn -', $html);
    }

    #[Test]
    public function an_unset_fee_date_asks_the_user_to_declare_it(): void
    {
        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Chưa khai báo ngày tính phí', $html);
    }

    #[Test]
    public function the_day_count_is_correct_across_a_leap_year(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_day' => 15,
            'next_annual_fee_date' => '2028-03-01',
        ]);

        // 2028 là năm nhuận: 28/02 → 01/03 chỉ 2 ngày.
        $row = $this->service()->row($card, CarbonImmutable::parse('2028-02-28'));
        $this->assertSame(2, $row['next_annual_fee_days']);
    }

    #[Test]
    public function the_fee_save_is_scoped_to_the_fee_row(): void
    {
        // Bug cũ: `document.querySelector('[data-card-id="${cardId}"]')` bắt nhầm
        // dòng SAO KÊ (xuất hiện trước trong DOM), không có ô mốc ⇒ gửi rỗng ⇒ 422.
        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('[data-testid="fee-row"][data-card-id="${cardId}"]', $html);
    }

    #[Test]
    public function the_shared_parser_is_present_for_the_fee_input(): void
    {
        // Guard tồn tại + nhánh xử lý >2 dấu phẩy (chuẩn hoá phân cách nghìn).
        // Test HÀNH VI thật nằm ở tests/js/money-parse.test.mjs (chạy bằng Node).
        $this->makeUserCard($this->owner->id, ['statement_day' => 15]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['tab' => 'fees']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function ccMoneyParseInput(raw)', $html);
        $this->assertStringContainsString('parts.length > 2', $html);
    }
}
