<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardStatementService;
use App\Services\CreditCard\CreditCardUserSettingService;
use App\Support\CreditCard\CreditCardMoneyFormatter;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Thiết lập "Đơn vị số tiền" (VND | THOUSAND_VND) — test A–I theo spec.
 *
 * Bất biến TRUNG TÂM của cả tính năng:
 *   - DB LUÔN lưu VND. `money_unit` chỉ đổi CÁCH ĐỌC số trên màn hình, không bao
 *     giờ tham gia tính và không bao giờ được sửa cột tiền nào.
 *   - Có đúng MỘT nguồn định dạng phía server (`CreditCardMoneyFormatter`) và
 *     MỘT phía client (`partials/money-js`), và hai bên phải khớp nhau.
 *   - Đọc đơn vị KHÔNG được tạo dòng thiết lập (mở trang không sinh dữ liệu).
 *   - Ghi đơn vị phải flush memo của formatter để request kế đọc đúng giá trị.
 */
class MoneyUnitSettingTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();

        // Formatter memo đơn vị theo user trong đời process — bắt đầu sạch để
        // mỗi test tự tuyên bố đơn vị nó cần chứ không tái dùng đơn vị của test
        // chạy trước trong cùng process.
        CreditCardMoneyFormatter::flushAll();
    }

    /**
     * KHÔNG để memo THOUSAND_VND rò sang test khác.
     *
     * Memo tĩnh theo `user_id` sống qua rollback của RefreshDatabase (id tái
     * sử dụng trong cùng process), nên test đặt THOUSAND mà không flush thì
     // lớp test kế render overview với nghìn thay vì VND mặc định.
     */
    protected function tearDown(): void
    {
        CreditCardMoneyFormatter::flushAll();

        parent::tearDown();
    }

    private function settingsService(): CreditCardUserSettingService
    {
        return app(CreditCardUserSettingService::class);
    }

    /** Đặt đơn vị qua DẤU VÀO CHÍNH THỨC (endpoint), như người dùng bấm radio. */
    private function switchUnit(string $unit): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [
                'money_unit' => $unit,
            ])
            ->assertOk();
    }

    /** Ghi sao kê VND cho thẻ ở kỳ đã kết thúc gần nhất. */
    private function writeStatement(UserCard $card, string $spend, string $reward): void
    {
        app(CreditCardStatementService::class)->upsertForPeriodStart(
            $card,
            app(CreditCardStatementService::class)->defaultPeriodStart($card),
            ['actual_spend' => $spend, 'actual_reward' => $reward],
        );
    }

    // =====================================================================
    // A. Trang Cài đặt liệt kê đúng hai đơn vị + radio của đơn vị đang chọn
    // =====================================================================

    /** Mặc định (chưa từng đổi) là Đồng, và trang có đủ hai lựa chọn. */
    #[Test]
    public function settings_page_lists_both_money_units_with_vnd_selected_by_default(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Đơn vị số tiền', $html);
        $this->assertStringContainsString('name="cc-money-unit"', $html);
        $this->assertStringContainsString('value="VND"', $html);
        $this->assertStringContainsString('value="THOUSAND_VND"', $html);
        $this->assertStringContainsString("@change=\"save('VND')\"", $html);
        $this->assertStringContainsString("@change=\"save('THOUSAND_VND')\"", $html);
        $this->assertStringContainsString('Đồng (đ)', $html);
        $this->assertStringContainsString('Nghìn đồng', $html);

        // Đơn vị mặc định khởi tạo của Alpine là Đồng.
        $this->assertStringContainsString("moneyUnit: 'VND'", $html);
    }

    /** Sau khi đổi sang Nghìn đồng, radio tương ứng được chọn. */
    #[Test]
    public function settings_page_checks_the_saved_money_unit(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("moneyUnit: 'THOUSAND_VND'", $html);
    }

    // =====================================================================
    // B. Endpoint cập nhật đơn vị: lưu, trả về chuẩn hoá, và chặn giá trị lạ
    // =====================================================================

    /** PATCH hợp lệ ghi dòng thiết lập và trả về đơn vị server đang giữ. */
    #[Test]
    public function update_money_unit_endpoint_persists_and_returns_the_unit(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $setting = CreditCardUserSetting::query()
            ->where('user_id', $this->owner->id)
            ->first();

        $this->assertNotNull($setting);
        $this->assertSame(CreditCardUserSetting::MONEY_UNIT_THOUSAND, $setting->money_unit);
    }

    /** Endpoint không chấp nhận đơn vị lạ hay thiếu — trả 422 với lý do rõ. */
    #[Test]
    public function update_money_unit_endpoint_rejects_unknown_and_missing_units(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [
                'money_unit' => 'USD',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['money_unit']);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['money_unit']);
    }

    /** Cập nhật đơn vị không đụng tới `payment_reminder_days` đã lưu. */
    #[Test]
    public function update_money_unit_keeps_payment_reminder_days_untouched(): void
    {
        $this->settingsService()->updateReminderDays((int) $this->owner->id, 3);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $this->assertSame(3, $this->settingsService()->reminderDaysFor((int) $this->owner->id));
    }

    // =====================================================================
    // C. Đọc đơn vị KHÔNG ghi — user mới mặc định VND
    // =====================================================================

    /** Chưa từng đổi đơn vị ⇒ `VND`, và không có dòng thiết lập nào được tạo. */
    #[Test]
    public function a_fresh_user_reads_vnd_without_creating_a_setting_row(): void
    {
        $this->assertSame(
            CreditCardUserSetting::MONEY_UNIT_VND,
            $this->settingsService()->moneyUnitFor((int) $this->owner->id),
        );
        $this->assertSame(
            CreditCardUserSetting::MONEY_UNIT_VND,
            CreditCardMoneyFormatter::unit((int) $this->owner->id),
        );

        $this->assertSame(
            0,
            CreditCardUserSetting::query()->where('user_id', $this->owner->id)->count(),
        );
    }

    // =====================================================================
    // D. Ở đơn vị nghìn, mọi màn hình server render theo nghìn
    // =====================================================================

    /** Quản lý thẻ: hạn mức 50.000.000 đ hiển thị "50.000 nghìn". */
    #[Test]
    public function manage_page_renders_credit_limit_in_thousand_when_chosen(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 50000000]);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('50.000&nbsp;<span>nghìn</span>', $html);
        $this->assertStringNotContainsString('50.000.000&nbsp;<span>đ</span>', $html);
    }

    /** Tổng quan: đơn vị nghìn được cắt vào JS và hậu tố là "nghìn". */
    #[Test]
    public function overview_page_uses_thousand_unit_for_client_amounts(): void
    {
        $this->makeUserCard($this->owner->id);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('window.ccMoneyUnit = "THOUSAND_VND"', $html);
        $this->assertStringContainsString("return window.ccMoneyUnit === 'THOUSAND_VND' ? 'nghìn' : 'đ';", $html);
        $this->assertStringContainsString('ccMoneyVnd(summary.total_credit_limit)', $html);
    }

    // =====================================================================
    // E. Formatter PHP: format theo đơn vị + giữ chính xác ở ô nhập
    // =====================================================================

    /** Ở đơn vị VND: phân cách nghìn, không thập phân, hậu tố "đ". */
    #[Test]
    public function formatter_formats_vnd_with_grouping_and_dong_suffix(): void
    {
        $userId = (int) $this->owner->id;

        $this->assertSame('VND', CreditCardMoneyFormatter::unit($userId));
        $this->assertSame('đ', CreditCardMoneyFormatter::suffix($userId));
        $this->assertSame('4.237.000', CreditCardMoneyFormatter::number('4237000', $userId));
        $this->assertSame("4.237.000\u{00A0}đ", CreditCardMoneyFormatter::money('4237000', $userId));
        $this->assertSame('4.237.500', CreditCardMoneyFormatter::input('4237500', $userId));
        $this->assertSame('250.000', CreditCardMoneyFormatter::input('250000', $userId));
        $this->assertSame('0,01', CreditCardMoneyFormatter::input('0.01', $userId));
        $this->assertSame('', CreditCardMoneyFormatter::input(null, $userId));
        $this->assertSame('', CreditCardMoneyFormatter::input('', $userId));
    }

    /** Ở đơn vị nghìn: chia cho 1000, tối đa 3 thập phân, hậu tố "nghìn". */
    #[Test]
    public function formatter_formats_thousand_with_three_decimals_and_nghin_suffix(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);
        CreditCardMoneyFormatter::flushAll();

        $userId = (int) $this->owner->id;

        $this->assertSame('THOUSAND_VND', CreditCardMoneyFormatter::unit($userId));
        $this->assertSame('nghìn', CreditCardMoneyFormatter::suffix($userId));
        $this->assertSame('4.237', CreditCardMoneyFormatter::number('4237000', $userId));
        $this->assertSame('103,4', CreditCardMoneyFormatter::number('103400', $userId));
        $this->assertSame('103,5', CreditCardMoneyFormatter::number('103500', $userId));
        $this->assertSame('50.000', CreditCardMoneyFormatter::number('50000000', $userId));
        $this->assertSame("4.237,5\u{00A0}nghìn", CreditCardMoneyFormatter::money('4237500', $userId));

        // Giá trị điền vào ô nhập phải đọc lại được CHÍNH XÁC giá trị VND.
        $this->assertSame('4.237,5', CreditCardMoneyFormatter::input('4237500', $userId));
        $this->assertSame('250', CreditCardMoneyFormatter::input('250000', $userId));
        $this->assertSame('', CreditCardMoneyFormatter::input(null, $userId));
    }

    /** Round-trip: chuỗi nghìn hiển thị → VND đúng, không mất chữ số. */
    #[Test]
    public function thousand_display_round_trips_to_exact_vnd(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);
        CreditCardMoneyFormatter::flushAll();

        $userId = (int) $this->owner->id;

        // Cùng công thức số nguyên của `ccMoneyParseInput`:
        //   "4.237,5" (nghìn) ⇒ 4237 × 1000 + 5 × 100 = 4.237.500 đ
        //   "103,4"    (nghìn) ⇒ 103  × 1000 + 4 × 100 = 103.400 đ
        foreach ([
            '4237500' => '4.237,5',
            '103400' => '103,4',
            '250000' => '250',
            '999' => '0,999',
        ] as $vnd => $display) {
            $this->assertSame(
                $display,
                CreditCardMoneyFormatter::input($vnd, $userId),
                "input($vnd) phải ra chuỗi nghìn chính xác.",
            );
        }
    }

    // =====================================================================
    // F. Trang Sao kê: ô nhập chứa sẵn chuỗi đúng đơn vị + hậu tố bám theo
    // =====================================================================

    /** Ở đơn vị nghìn, chi tiêu 4.237.500 đ điền sẵn "4.237,5" với hậu tố "nghìn". */
    #[Test]
    public function statement_form_prefills_thousand_values_and_suffix(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->writeStatement($card, '4237500', '250000');

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="4.237,5"', $html);
        $this->assertStringContainsString('value="250"', $html);
        $this->assertStringContainsString('>nghìn</span>', $html);
        $this->assertStringContainsString('window.ccMoneyUnit = "THOUSAND_VND"', $html);
    }

    /** Ở đơn vị VND (mặc định), cùng số liệu điền sẵn chuỗi VND với hậu tố "đ". */
    #[Test]
    public function statement_form_prefills_vnd_values_and_suffix_by_default(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->writeStatement($card, '4237500', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="4.237.500"', $html);
        $this->assertStringContainsString('value="250.000"', $html);
        $this->assertStringContainsString('>đ</span>', $html);
    }

    // =====================================================================
    // G. money-js là nguồn DUY NHẤT phía client — mỗi trang MỘT script
    // =====================================================================

    /**
     * Dòng gán `window.ccMoneyUnit` chỉ được phát MỘT lần mỗi trang, kể cả trang
     * Quản lý thẻ vốn include money-js cả trực tiếp lẫn qua policy-editor.
     */
    #[Test]
    public function money_js_assignment_is_emitted_exactly_once_per_page(): void
    {
        $this->makeUserCard($this->owner->id);

        foreach ([
            route('credit-cards.index'),
            route('credit-cards.manage'),
            route('credit-cards.statements'),
        ] as $page) {
            $html = $this->actingAs($this->owner)
                ->get($page)
                ->assertOk()
                ->getContent();

            $this->assertSame(
                1,
                substr_count($html, 'window.ccMoneyUnit = '),
                "Trang $page phải có đúng MỘT money-js.",
            );
        }
    }

    // =====================================================================
    // H. Đổi đơn vị KHÔNG đổi một đồng nào trong dữ liệu
    // =====================================================================

    /** Payload API vẫn là VND dù đơn vị hiển thị là nghìn. */
    #[Test]
    public function switching_unit_never_changes_stored_amounts(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->writeStatement($card, '4237500', '250000');

        // Giao dịch nhập tay vẫn gửi VND (ô nhập ghi `expr = ccMoneyParseInput(...)`)
        // và được lưu y nguyên.
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'transaction_date' => '2026-09-20',
                'amount' => '500000',
            ])
            ->assertCreated();

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        // Giao dịch thứ hai được nhập SAU khi đổi đơn vị — endpoint vẫn nhận VND
        // (ô nhập đã đổi sang nghìn, `expr = ccMoneyParseInput(...)` đổi lại).
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'transaction_date' => '2026-09-21',
                'amount' => '750000',
            ])
            ->assertCreated();

        $storedTransaction = DB::connection('creditcard')
            ->table('credit_card_transactions')
            ->where('user_card_id', $card->id)
            ->orderBy('id')
            ->get();
        $this->assertSame([500000.0, 750000.0], $storedTransaction->map(fn ($row) => (float) $row->amount)->all());

        $storedStatement = DB::connection('creditcard')
            ->table('credit_card_statements')
            ->where('user_card_id', $card->id)
            ->first();
        $this->assertNotNull($storedStatement);
        $this->assertSame(4237500.0, (float) $storedStatement->actual_spend);
        $this->assertSame(250000.0, (float) $storedStatement->actual_reward);
    }

    // =====================================================================
    // I. Ghi đơn vị phải flush memo — request kế đọc giá trị MỚI
    // =====================================================================

    /** Sau một lần GET (đã memo VND), PATCH sang nghìn phải đọc lại được nghìn. */
    #[Test]
    public function updating_the_unit_flushes_the_memoized_formatter(): void
    {
        $this->makeUserCard($this->owner->id);

        // Request đầu memo hoá đơn vị VND của user này.
        $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk();

        // Cửa ghi chính thức phải flush memo — nếu không, formatter vẫn trả VND.
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $userId = (int) $this->owner->id;
        $this->assertSame(
            CreditCardUserSetting::MONEY_UNIT_THOUSAND,
            CreditCardMoneyFormatter::unit($userId),
        );
        $this->assertSame(
            "4.237,5\u{00A0}nghìn",
            CreditCardMoneyFormatter::money('4237500', $userId),
        );
    }
}