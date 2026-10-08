<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CashbackRecordService;
use App\Services\CreditCard\CreditCardStatementService;
use App\Services\CreditCard\CreditCardUserSettingService;
use App\Services\CreditCard\StatementPeriodService;
use App\Support\CreditCard\CreditCardMoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Thiết lập "Đơn vị số tiền" (VND | THOUSAND_VND) + "Ký tự đại diện"
 * (`money_unit_symbol`) — test A–N theo spec.
 *
 * Bất biến TRUNG TÂM của cả tính năng:
 *   - DB LUÔN lưu VND. `money_unit` chỉ đổi CÁCH ĐỌC số trên màn hình, không bao
 *     giờ tham gia tính và không bao giờ được sửa cột tiền nào.
 *   - `money_unit_symbol` có 3 trạng thái phân biệt được:
 *       NULL  = CHƯA cấu hình ⇒ ký tự default của đơn vị ("đ" / "nghìn");
 *       ""    = CHỦ ĐỘNG bỏ hậu tố ⇒ không hiển thị gì (cũng không NBSP treo);
 *       khác  = ký tự tuỳ chỉnh.
 *     `resolver = CreditCardUserSettingService::resolveMoneyUnitSymbol()` là nơi
 *     DUY NHẤT quy định ánh xạ này — cả formatter PHP lẫn view data và response
 *     đều đi qua nó.
 *   - Có đúng MỘT nguồn định dạng phía server (`CreditCardMoneyFormatter`) và
 *     MỘT phía client (`partials/money-js`), và hai bên phải khớp nhau.
 *   - Đọc đơn vị KHÔNG được tạo dòng thiết lập (mở trang không sinh dữ liệu).
 *   - Ghi đơn vị/ký tự phải flush memo của formatter để request kế đọc đúng.
 *   - Chỉ nút "Lưu" mới PATCH (payload LUÔN kèm symbol đã resolve); đổi radio chỉ
 *     sửa draft trên UI, KHÔNG gọi API, KHÔNG reload.
 */
class MoneyUnitSettingTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();

        // Formatter memo theo user trong đời process (kể cả `money_unit_symbol`) —
        // bắt đầu sạch để mỗi test tự tuyên bố đơn vị nó cần chứ không tái dùng
        // trạng thái của test chạy trước trong cùng process.
        CreditCardMoneyFormatter::flushAll();
    }

    /**
     * KHÔNG để memo THOUSAND_VND (hay symbol tuỳ chỉnh) rò sang test khác.
     *
     * Memo tĩnh theo `user_id` sống qua rollback của RefreshDatabase (id tái
     * sử dụng trong cùng process), nên test đặt đơn vị mà không flush thì lớp
     * test kế render với đơn vị/ký tự cũ thay vì VND mặc định.
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

    /**
     * Ghi đơn vị (+ ký tự đại diện) qua CỬA VÀO CHÍNH THỨC (endpoint), như người
     * dùng bấm "Lưu".
     *
     * Bỏ qua `$symbol` (= null) giả lập payload LEGACY không kèm symbol — khi đó
     * server phải GIỮ NULL (="chưa cấu hình", dùng default), không tự ghi ký tự.
     * Ngược lại, gửi `''` để XOÁ ký tự, và gửi chuỗi khác cho ký tự tuỳ chỉnh.
     */
    private function switchUnit(string $unit, ?string $symbol = null): void
    {
        $payload = ['money_unit' => $unit];

        if ($symbol !== null) {
            $payload['money_unit_symbol'] = $symbol;
        }

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), $payload)
            ->assertOk();
    }

    /** Dòng thiết lập money của owner trong DB (hoặc null nếu chưa từng ghi). */
    private function settingRow(): ?CreditCardUserSetting
    {
        return CreditCardUserSetting::query()
            ->where('user_id', $this->owner->id)
            ->first();
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
    // A. Trang Cài đặt: hai đơn vị + ô ký tự của đơn vị đang chọn, Lưu là
    //    cửa ghi DUY NHẤT (không autosave, không reload)
    // =====================================================================

    /**
     * Mặc định (chưa từng đổi) là Đồng: radio VND, ô ký tự nhận default "đ",
     * hai lựa chọn đủ mặt, và ngoài nút Lưu không còn cửa ghi nào.
     */
    #[Test]
    public function settings_page_lists_both_money_units_with_vnd_selected_by_default(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Đơn vị tiền', $html);
        $this->assertStringContainsString('name="cc-money-unit"', $html);
        $this->assertStringContainsString('value="VND"', $html);
        $this->assertStringContainsString('value="THOUSAND_VND"', $html);
        $this->assertStringContainsString("@change=\"pickUnit('VND')\"", $html);
        $this->assertStringContainsString("@change=\"pickUnit('THOUSAND_VND')\"", $html);
        $this->assertStringContainsString('>Đồng</span>', $html);
        $this->assertStringContainsString('Nghìn đồng', $html);

        // Đơn vị mặc định khởi tạo của Alpine là Đồng, và ô ký tự nhận sẵn giá
        // trị default đã resolve (DB NULL vẫn hiển thị "đ").
        $this->assertStringContainsString('moneyUnit: \'VND\'', $html);
        $this->assertStringContainsString('savedMoneyUnitSymbol: '.Js::from('đ'), $html);
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from('đ'), $html);
        $this->assertStringContainsString('Ký tự đại diện:', $html);
        $this->assertSame(2, substr_count($html, 'maxlength="20"'));

        // Đổi radio chạy `pickUnit(...)` — chép draft, KHÔNG gọi API.
        $this->assertStringNotContainsString('@change="save(', $html);

        // Chỉ nút "Lưu" gọi API (đúng MỘT lần trong trang), và không reload.
        $this->assertStringContainsString('@click="save"', $html);
        $this->assertSame(
            1,
            substr_count($html, route('credit-cards.api.settings.money-unit.update')),
        );
        $this->assertStringNotContainsString('window.location.reload', $html);
    }

    /**
     * B. Sau khi đổi sang Nghìn đồng (payload cũ, KHÔNG kèm symbol), radio
     *    tương ứng được chọn, ô ký tự lấy default "nghìn", DB vẫn NULL.
     */
    #[Test]
    public function settings_page_checks_the_saved_money_unit(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('moneyUnit: \'THOUSAND_VND\'', $html);

        // DB NULL ⇒ ô nhập vẫn hiển thị default của đơn vị mới ("nghìn").
        $this->assertStringContainsString('savedMoneyUnitSymbol: '.Js::from('nghìn'), $html);
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from('nghìn'), $html);

        $setting = $this->settingRow();
        $this->assertNotNull($setting);
        $this->assertSame(CreditCardUserSetting::MONEY_UNIT_THOUSAND, $setting->money_unit);
        $this->assertNull($setting->money_unit_symbol, 'Payload không kèm symbol phải GIỮ NULL.');
    }

    // =====================================================================
    // C. Endpoint cập nhật đơn vị: lưu, trả về chuẩn hoá, và chặn giá trị lạ
    // =====================================================================

    /** PATCH hợp lệ ghi dòng thiết lập và trả về đơn vị server đang giữ. */
    #[Test]
    public function update_money_unit_endpoint_persists_and_returns_the_unit(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $setting = $this->settingRow();

        $this->assertNotNull($setting);
        $this->assertSame(CreditCardUserSetting::MONEY_UNIT_THOUSAND, $setting->money_unit);
        $this->assertNull($setting->money_unit_symbol);
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

    /**
     * Cập nhật đơn vị + ký tự không đụng tới `payment_reminder_days` đã lưu.
     */
    #[Test]
    public function update_money_unit_keeps_payment_reminder_days_untouched(): void
    {
        $this->settingsService()->updateReminderDays((int) $this->owner->id, 3);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_VND, 'VND');

        $this->assertSame(3, $this->settingsService()->reminderDaysFor((int) $this->owner->id));

        $setting = $this->settingRow();
        $this->assertNotNull($setting);
        $this->assertSame('VND', $setting->money_unit_symbol);
    }

    // =====================================================================
    // D. Đọc đơn vị KHÔNG ghi — user mới mặc định VND (+ ký tự "đ")
    // =====================================================================

    /** Chưa từng đổi đơn vị ⇒ `VND` / `đ`, và không có dòng thiết lập nào được tạo. */
    #[Test]
    public function a_fresh_user_reads_vnd_without_creating_a_setting_row(): void
    {
        $userId = (int) $this->owner->id;

        $this->assertSame(CreditCardUserSetting::MONEY_UNIT_VND, $this->settingsService()->moneyUnitFor($userId));
        $this->assertSame(CreditCardUserSetting::MONEY_UNIT_VND, CreditCardMoneyFormatter::unit($userId));

        // NULL chưa cấu hình ⇒ resolver trả default; đọc không sinh dữ liệu.
        $this->assertNull($this->settingsService()->moneyUnitSymbolFor($userId));
        $this->assertSame('đ', CreditCardMoneyFormatter::suffix($userId));
        $this->assertSame(
            'đ',
            CreditCardUserSettingService::resolveMoneyUnitSymbol(CreditCardUserSetting::MONEY_UNIT_VND, null),
        );
        $this->assertSame(
            'nghìn',
            CreditCardUserSettingService::resolveMoneyUnitSymbol(CreditCardUserSetting::MONEY_UNIT_THOUSAND, null),
        );

        $this->assertNull($this->settingRow());
    }

    // =====================================================================
    // E. Ở đơn vị nghìn, ký tự tuỳ chỉnh render đúng trên các màn hình
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

    /** Tổng quan: đơn vị nghìn được cắt vào JS (cả symbol đã resolve). */
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
        // `@json` escape ký tự không-ASCII giống `json_encode` (flags mặc định).
        $this->assertStringContainsString(
            'window.ccMoneySymbol = '.json_encode('nghìn', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
            $html,
        );
        $this->assertStringContainsString("return window.ccMoneyUnit === 'THOUSAND_VND' ? 'nghìn' : 'đ';", $html);
        $this->assertStringContainsString('ccMoneyVnd(summary.total_credit_limit)', $html);
    }

    // =====================================================================
    // F. Formatter PHP: format theo đơn vị + giữ chính xác ở ô nhập
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

    /** Ở đơn vị nghìn: chia 1000 rồi floor về số nguyên, hậu tố "nghìn". */
    #[Test]
    public function formatter_formats_thousand_as_floored_integer_and_nghin_suffix(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);
        CreditCardMoneyFormatter::flushAll();

        $userId = (int) $this->owner->id;

        $this->assertSame('THOUSAND_VND', CreditCardMoneyFormatter::unit($userId));
        $this->assertSame('nghìn', CreditCardMoneyFormatter::suffix($userId));
        $this->assertSame('4.237', CreditCardMoneyFormatter::number('4237000', $userId));

        // Floor, KHÔNG làm tròn lên: 103.400 ⇒ 103, 103.500 ⇒ 103 (không phải 104).
        $this->assertSame('103', CreditCardMoneyFormatter::number('103400', $userId));
        $this->assertSame('103', CreditCardMoneyFormatter::number('103500', $userId));
        $this->assertSame('499', CreditCardMoneyFormatter::number('499999', $userId));
        $this->assertSame('50.000', CreditCardMoneyFormatter::number('50000000', $userId));
        $this->assertSame("4.237\u{00A0}nghìn", CreditCardMoneyFormatter::money('4237500', $userId));

        // Ô nhập prefill cùng chuỗi FLOOR người dùng thấy trên màn hình.
        $this->assertSame('4.237', CreditCardMoneyFormatter::input('4237500', $userId));
        $this->assertSame('250', CreditCardMoneyFormatter::input('250000', $userId));
        $this->assertSame('', CreditCardMoneyFormatter::input(null, $userId));
    }

    /**
     * Round-trip theo NGHĨA LƯU TRỮ: chuỗi nhập parse về đúng VND, và prefill
     * đọc lại đúng chuỗi đã hiển thị — số lẻ dưới 1000đ hiển thị xuống dưới
     * đồng thì chấp nhận mất (floor), không được bịa số thập phân trở lại.
     */
    #[Test]
    public function thousand_display_round_trips_exactly_for_full_thousands_and_floors_the_rest(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);
        CreditCardMoneyFormatter::flushAll();

        $userId = (int) $this->owner->id;

        // Bội số 1000 ⇒ chuỗi nghìn parse ngược về VND ĐÚNG (INPUT → DB exact).
        foreach ([
            '4237000' => '4.237',
            '103000' => '103',
            '250000' => '250',
            '3900000' => '3.900',
        ] as $vnd => $display) {
            $this->assertSame(
                $display,
                CreditCardMoneyFormatter::input($vnd, $userId),
                "input($vnd) phải ra chuỗi nghìn chính xác.",
            );
            $this->assertSame(
                (string) self::parseThousandInput($display),
                (string) $vnd,
                "parse($display) phải về đúng $vnd.",
            );
        }

        // Số LẺ dưới 1000đ: hiển thị floor ⇒ prefill là số nguyên đã floor.
        foreach ([
            '4237500' => '4.237',
            '103400' => '103',
            '999' => '0',
            '3900' => '3',
        ] as $vnd => $display) {
            $this->assertSame(
                $display,
                CreditCardMoneyFormatter::input($vnd, $userId),
                "input($vnd) phải floor xuống số nguyên.",
            );
        }
    }

    /**
     * Cùng công thức số nguyên của `ccMoneyParseInput` — dùng để kiểm chứng
     * chuỗi nghìn parse ngược về VND đúng (INPUT → DB không mất đồng nào).
     *
     * Bắt chuỗi phải NHÓM 3 BẰNG DẤU CHẤM (`4.237`) trước: nếu `input()` lỡ
     * trả dạng thập phân (`4,237`) thì regex fail thay vì hai chữ số bị gộp
     * nhầm thành 4237 rồi "đúng" giả tạo.
     */
    private static function parseThousandInput(string $display): int
    {
        if (preg_match('/^\d{1,3}(\.\d{3})*$/', $display) !== 1) {
            throw new \RuntimeException("Chuỗi nghìn phải là số nguyên dạng nhóm 3: {$display}");
        }

        return (int) str_replace('.', '', $display) * 1000;
    }

    /**
     * Ma trận floor đầy đủ: mọi giá trị lẻ đều CẮT xuống nhóm nghìn nguyên,
     * chưa bao giờ làm tròn lên (499.999 ⇒ 499 chứ không phải 500).
     */
    #[Test]
    public function thousand_number_floors_the_whole_odd_value_matrix(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);
        CreditCardMoneyFormatter::flushAll();

        $userId = (int) $this->owner->id;

        $matrix = [
            '400000' => '400',
            '456383' => '456',
            '456999' => '456',
            '499999' => '499',
            '800000' => '800',
            '2290783' => '2.290',
            '5333333' => '5.333',
            '7503550' => '7.503',
            '4237500' => '4.237',
            '103400' => '103',
            '3900000' => '3.900',
            '999' => '0',
        ];

        foreach ($matrix as $vnd => $expected) {
            $this->assertSame($expected, CreditCardMoneyFormatter::number($vnd, $userId), "number($vnd)");
            $this->assertSame($expected, CreditCardMoneyFormatter::input($vnd, $userId), "input($vnd)");
        }
    }

    /**
     * Memo theo user: đơn vị nghìn của user A không được rò sang user B đang
     * ở VND — cả khi hai người cùng đọc trong một đời request (memo đầy).
     */
    #[Test]
    public function one_users_thousand_floor_never_leaks_into_another_users_vnd_view(): void
    {
        $other = User::factory()->create();

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);
        CreditCardMoneyFormatter::flushAll();

        $ownerId = (int) $this->owner->id;
        $otherId = (int) $other->id;

        // Đọc ĐẦY memo cả hai user trong cùng request, xen kẽ nhiều lần.
        $this->assertSame('456', CreditCardMoneyFormatter::number('456383', $ownerId));
        $this->assertSame('456.383', CreditCardMoneyFormatter::number('456383', $otherId));
        $this->assertSame('4.237', CreditCardMoneyFormatter::number('4237500', $ownerId));
        $this->assertSame('4.237.500', CreditCardMoneyFormatter::number('4237500', $otherId));
        $this->assertSame('456', CreditCardMoneyFormatter::number('456383', $ownerId));
        $this->assertSame('456.383', CreditCardMoneyFormatter::number('456383', $otherId));

        // User B chưa từng cấu hình ⇒ suffix mặc định "đ", không phải "nghìn".
        $this->assertSame('đ', CreditCardMoneyFormatter::suffix($otherId));
        $this->assertSame('nghìn', CreditCardMoneyFormatter::suffix($ownerId));
    }

    // =====================================================================
    // G. Trang Sao kê: ô nhập chứa sẵn chuỗi đúng đơn vị + hậu tố bám theo
    // =====================================================================

    /** Ở đơn vị nghìn, chi tiêu 4.237.500 đ điền sẵn "4.237" (floor) với hậu tố "nghìn". */
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

        $this->assertStringContainsString('value="4.237"', $html);
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
    // H. money-js là nguồn DUY NHẤT phía client — mỗi trang MỘT script
    // =====================================================================

    /**
     * Các dòng gán `window.ccMoneyUnit`/`window.ccMoneySymbol` chỉ phát MỘT lần
     * mỗi trang, kể cả trang Quản lý thẻ vốn include money-js cả trực tiếp lẫn
     * qua policy-editor.
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
            $this->assertSame(
                1,
                substr_count($html, 'window.ccMoneySymbol = '),
                "Trang $page phải cắt đúng MỘT ký tự đại diện.",
            );
        }
    }

    // =====================================================================
    // I. Đổi đơn vị KHÔNG đổi một đồng nào trong dữ liệu
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
    // J. Ghi đơn vị phải flush memo — request kế đọc giá trị MỚI
    // =====================================================================

    /** Sau một lần GET (đã memo VND), PATCH sang nghìn phải đọc lại được nghìn. */
    #[Test]
    public function updating_the_unit_flushes_the_memoized_formatter(): void
    {
        $this->makeUserCard($this->owner->id);

        // Request đầu memo hoá đơn vị VND (symbol "đ") của user này.
        $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk();

        // Cửa ghi chính thức phải flush memo — nếu không, formatter vẫn trả VND.
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'k');

        $userId = (int) $this->owner->id;
        $this->assertSame(CreditCardUserSetting::MONEY_UNIT_THOUSAND, CreditCardMoneyFormatter::unit($userId));
        $this->assertSame('k', CreditCardMoneyFormatter::suffix($userId));
        $this->assertSame("4.237\u{00A0}k", CreditCardMoneyFormatter::money('4237500', $userId));
    }

    // =====================================================================
    // J2. Ký tự tuỳ chỉnh — C/D/E: ghi, đọc lại, render mọi màn hình
    // =====================================================================

    /** C. Ký tự VND tuỳ chỉnh: DB đúng, trang Cài đặt + Quản lý thẻ render đúng. */
    #[Test]
    public function custom_vnd_symbol_is_persisted_and_rendered_everywhere(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 2000000]);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_VND, 'VND');

        $setting = $this->settingRow();
        $this->assertNotNull($setting);
        $this->assertSame('VND', $setting->money_unit);
        $this->assertSame('VND', $setting->money_unit_symbol);

        // Đọc lại trang Cài đặt: ô ký tự chứa đúng giá trị đã lưu.
        $settingsHtml = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString("moneyUnit: 'VND'", $settingsHtml);
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from('VND'), $settingsHtml);
        $this->assertStringContainsString('savedMoneyUnitSymbol: '.Js::from('VND'), $settingsHtml);

        // Quản lý thẻ: "2.000.000 VND" — NBSP giữa số và ký tự tuỳ chỉnh.
        $manageHtml = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('2.000.000&nbsp;<span>VND</span>', $manageHtml);
        $this->assertSame(1, substr_count($manageHtml, 'window.ccMoneySymbol = "VND"'));
    }

    /** D. Ký tự nghìn tuỳ chỉnh "k": cũng được ghi, đọc lại và render "… k". */
    #[Test]
    public function custom_thousand_symbol_is_persisted_and_rendered_everywhere(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 2000000]);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'k');

        $setting = $this->settingRow();
        $this->assertNotNull($setting);
        $this->assertSame('THOUSAND_VND', $setting->money_unit);
        $this->assertSame('k', $setting->money_unit_symbol);

        $settingsHtml = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString("moneyUnit: 'THOUSAND_VND'", $settingsHtml);
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from('k'), $settingsHtml);

        $manageHtml = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('2.000&nbsp;<span>k</span>', $manageHtml);
        $this->assertStringNotContainsString('2.000&nbsp;<span>nghìn</span>', $manageHtml);
    }

    /**
     * E. Ký tự RỖNG là "chủ động bỏ hậu tố": hết NBSP, hết span suffix, ô nhập
     *    trống trên màn hình — nhưng DB lưu `''`, KHÔNG phải null.
     */
    #[Test]
    public function explicit_empty_symbol_keeps_db_empty_and_hides_the_suffix(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['credit_limit' => 2000000]);
        $this->writeStatement($card, '4237500', '250000');

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_VND, '');

        $setting = $this->settingRow();
        $this->assertNotNull($setting);
        $this->assertSame('', $setting->money_unit_symbol, 'Xoá ký tự phải lưu "" chứ không được về null.');

        $userId = (int) $this->owner->id;
        $this->assertSame('', CreditCardMoneyFormatter::suffix($userId));
        $this->assertSame('2.000.000', CreditCardMoneyFormatter::money('2000000', $userId));
        $this->assertStringNotContainsString("\u{00A0}", CreditCardMoneyFormatter::money('2000000', $userId));

        // Trang Cài đặt đọc lại: ô ký tự trống trơn (người dùng đã xoá).
        $settingsHtml = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from(''), $settingsHtml);
        $this->assertStringContainsString('savedMoneyUnitSymbol: '.Js::from(''), $settingsHtml);

        // Quản lý thẻ: chỉ còn con số, không span hậu tố, không NBSP treo.
        $manageHtml = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('>2.000.000</span>', $manageHtml);
        $this->assertStringNotContainsString('>2.000.000&nbsp;<span>', $manageHtml);
        $this->assertStringNotContainsString('>đ</span>', $manageHtml);

        // Sao kê: ô nhập không còn khoảng trống dành chỗ suffix (`pr-10` bỏ đi).
        $statementsHtml = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('value="4.237.500"', $statementsHtml);
        $this->assertStringNotContainsString('pr-10 rounded-xl', $statementsHtml);
        $this->assertStringNotContainsString('>đ</span>', $statementsHtml);

        // Đơn vị nghìn + rỗng: "2.000.000 đ" ⇒ "2.000", không hậu tố.
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND, '');
        $this->assertSame('2.000', CreditCardMoneyFormatter::money('2000000', $userId));
        $this->assertStringNotContainsString("\u{00A0}", CreditCardMoneyFormatter::money('2000000', $userId));
        $this->assertSame('', CreditCardMoneyFormatter::suffix($userId));
    }

    /**
     * F. Bấm radio CHỈ đổi draft trên UI — chưa bấm Lưu thì không ghi DB và
     *    reload lại vẫn là trạng thái đã lưu trước đó.
     */
    #[Test]
    public function settings_radio_change_is_draft_only_until_saved(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_VND, 'đ');

        $first = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("moneyUnit: 'VND'", $first);
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from('đ'), $first);

        // Hai radio chỉ chạy `pickUnit(...)` (chép draft), và endpoint money-unit
        // chỉ xuất hiện MỘT lần — bên trong `save()`.
        $this->assertStringContainsString("@change=\"pickUnit('THOUSAND_VND')\"", $first);
        $this->assertStringContainsString("@change=\"pickUnit('VND')\"", $first);
        $this->assertSame(1, substr_count($first, route('credit-cards.api.settings.money-unit.update')));

        // Dù user "đã bấm" Nghìn rồi tải lại (chưa Lưu), màn hình vẫn là Đồng đã lưu.
        $second = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString("moneyUnit: 'VND'", $second);
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from('đ'), $second);

        // DB vẫn nguyên sau các lần đọc.
        $setting = $this->settingRow();
        $this->assertNotNull($setting);
        $this->assertSame('VND', $setting->money_unit);
        $this->assertSame('đ', $setting->money_unit_symbol);
    }

    /**
     * G. Ký tự khi "Lưu" từ trạng thái chưa cấu hình là chính default đã resolve
     *    (ô nhập chưa bao giờ trống), nên nó được persist thật chứ không phải null.
     */
    #[Test]
    public function saving_persists_the_resolved_default_symbol(): void
    {
        $this->assertNull($this->settingRow());

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [
                'money_unit' => CreditCardUserSetting::MONEY_UNIT_VND,
                'money_unit_symbol' => 'đ',
            ])
            ->assertOk()
            ->assertJsonPath('data.money_unit', 'VND')
            ->assertJsonPath('data.money_unit_symbol', 'đ');

        $this->assertSame('đ', $this->settingRow()->money_unit_symbol);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'nghìn');
        $this->assertSame('nghìn', $this->settingRow()->money_unit_symbol);
    }

    /**
     * H. `pickUnit` phải reset ký tự draft về default của đơn vị MỚI — 2 radio
     *    đều gọi nó, không nơi nào tự gọi API.
     */
    #[Test]
    public function pick_unit_resets_the_draft_symbol_to_the_unit_default(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            "/defaultSymbol\(unit\)\s*\{\s*return unit === 'THOUSAND_VND' \? 'nghìn' : 'đ';\s*\}/",
            $html,
        );
        $this->assertStringContainsString('this.moneyUnitSymbol = this.defaultSymbol(unit);', $html);
        $this->assertStringContainsString("@change=\"pickUnit('VND')\"", $html);
        $this->assertStringContainsString("@change=\"pickUnit('THOUSAND_VND')\"", $html);
    }

    /** I. `money_unit_symbol` chuẩn hoá khoảng trắng, quá 20 ký tự là 422. */
    #[Test]
    public function whitespace_symbol_normalizes_and_max_length_is_enforced(): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [
                'money_unit' => CreditCardUserSetting::MONEY_UNIT_VND,
                'money_unit_symbol' => '   ',
            ])
            ->assertOk();

        $this->assertSame('', $this->settingRow()->money_unit_symbol);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_VND, ' k ');
        $this->assertSame('k', $this->settingRow()->money_unit_symbol);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [
                'money_unit' => CreditCardUserSetting::MONEY_UNIT_VND,
                'money_unit_symbol' => str_repeat('x', 21),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['money_unit_symbol']);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [
                'money_unit' => CreditCardUserSetting::MONEY_UNIT_VND,
                'money_unit_symbol' => ['đ'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['money_unit_symbol']);
    }

    /** J2. Ma trận 8 tổ hợp đơn vị × ký tự — formatter luôn ra đúng suffix. */
    #[Test]
    public function formatter_covers_every_unit_and_symbol_combination(): void
    {
        $userId = (int) $this->owner->id;

        // [unit, symbol, suffix kỳ vọng, money('4237000') kỳ vọng]
        $cases = [
            [CreditCardUserSetting::MONEY_UNIT_VND, null, 'đ', "4.237.000\u{00A0}đ"],
            [CreditCardUserSetting::MONEY_UNIT_VND, 'đ', 'đ', "4.237.000\u{00A0}đ"],
            [CreditCardUserSetting::MONEY_UNIT_VND, 'VND', 'VND', "4.237.000\u{00A0}VND"],
            [CreditCardUserSetting::MONEY_UNIT_VND, '', '', '4.237.000'],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, null, 'nghìn', "4.237\u{00A0}nghìn"],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'nghìn', 'nghìn', "4.237\u{00A0}nghìn"],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'k', 'k', "4.237\u{00A0}k"],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, '', '', '4.237'],
        ];

        foreach ($cases as [$unit, $symbol, $suffix, $money]) {
            $this->switchUnit($unit, $symbol);
            CreditCardMoneyFormatter::flushAll();

            $this->assertSame($suffix, CreditCardMoneyFormatter::suffix($userId), "[$unit][$symbol] suffix");
            $this->assertSame($money, CreditCardMoneyFormatter::money('4237000', $userId), "[$unit][$symbol] money");
        }
    }

    // =====================================================================
    // K. Parser số (PHP `input()` + JS `ccMoneyParseInput`) KHÔNG phụ thuộc
    //    ký tự đại diện — chuỗi trong ô nhập chỉ theo ĐƠN VỊ
    // =====================================================================

    /** Cùng đơn vị, mọi trạng thái ký tự đều ra chuỗi nhập y hệt nhau. */
    #[Test]
    public function input_parsing_is_independent_of_the_symbol(): void
    {
        $userId = (int) $this->owner->id;

        $combos = [
            [CreditCardUserSetting::MONEY_UNIT_VND, null],
            [CreditCardUserSetting::MONEY_UNIT_VND, 'đ'],
            [CreditCardUserSetting::MONEY_UNIT_VND, 'VND'],
            [CreditCardUserSetting::MONEY_UNIT_VND, ''],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, null],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'nghìn'],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'k'],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, ''],
        ];

        foreach ($combos as [$unit, $symbol]) {
            $this->switchUnit($unit, $symbol);
            CreditCardMoneyFormatter::flushAll();

            if ($unit === CreditCardUserSetting::MONEY_UNIT_VND) {
                $this->assertSame('2.000.000', CreditCardMoneyFormatter::input('2000000', $userId));
                $this->assertSame('0,01', CreditCardMoneyFormatter::input('0.01', $userId));
            } else {
                // Nhập "103" nghìn = 103.000 đ; "4.237" = 4.237.000 đ (floor).
                $this->assertSame('2.000', CreditCardMoneyFormatter::input('2000000', $userId));
                $this->assertSame('103', CreditCardMoneyFormatter::input('103400', $userId));
                $this->assertSame('4.237', CreditCardMoneyFormatter::input('4237500', $userId));
            }
        }
    }

    /**
     * Bản JS của parser chỉ tham chiếu `ccMoneyUnit` — không được dính chữ
     * Symbol/Suffix, vì input là dữ liệu chứ không phải hiển thị.
     */
    #[Test]
    public function client_parser_never_references_the_symbol(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/function ccMoneyParseInput\(raw\) \{.*?\n    \}/s', $html, $match),
        );

        $parser = $match[0];
        $this->assertStringContainsString('ccMoneyUnit', $parser);
        $this->assertStringNotContainsString('Symbol', $parser);
        $this->assertStringNotContainsString('Suffix', $parser);
    }

    /** Giao dịch endpoint: dù đang hiển thị nghìn + "k", DB lưu VND nguyên vẹn. */
    #[Test]
    public function storing_a_transaction_is_also_symbol_independent(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'k');

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'transaction_date' => '2026-09-20',
                'amount' => '2000000',
            ])
            ->assertCreated();

        $stored = DB::connection('creditcard')
            ->table('credit_card_transactions')
            ->where('user_card_id', $card->id)
            ->value('amount');
        $this->assertSame(2000000.0, (float) $stored);
    }

    // =====================================================================
    // L. Mọi phép tính (cashback, hạn mức, sao kê) như nhau với 6 tổ hợp đơn vị
    //    × ký tự — đơn vị chỉ là CÁCH ĐỌC số, không chạm tính toán
    // =====================================================================

    #[Test]
    public function business_calculations_are_identical_across_unit_and_symbol_combos(): void
    {
        $records = app(CashbackRecordService::class);
        $periods = app(StatementPeriodService::class);

        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 31]);

        // Giao dịch 6tr @10% (bậc 2) = 600k thô, cap toàn kỳ 500k ⇒ đủ chạm quota.
        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null, 'cap_period' => 500000],
            ],
            [
                ['category_id' => $category->id, 'percent' => '2.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '10.000', 'only_tier' => 1],
            ],
        );

        $period = $periods->resolvePeriodForDate($card, CarbonImmutable::parse('2026-09-10'));

        $transaction = Transaction::create([
            'user_card_id' => $card->id,
            'statement_period_id' => $period->id,
            'category_id' => $category->id,
            'transaction_date' => '2026-09-05',
            'amount' => '6000000',
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        $records->calculatePeriod($card, $period);
        $transaction->refresh();
        $period->refresh();

        $baseline = [
            'cashback' => $transaction->cashback_amount_snapshot,
            'percent' => $transaction->cashback_percent_snapshot,
            'eligible' => $period->total_eligible_spend,
            'total' => $period->total_cashback,
        ];
        $this->assertNotSame('0.00', $baseline['total'], 'Cashback phải được tính thật để so sánh các tổ hợp.');

        $combos = [
            [CreditCardUserSetting::MONEY_UNIT_VND, 'đ'],
            [CreditCardUserSetting::MONEY_UNIT_VND, 'VND'],
            [CreditCardUserSetting::MONEY_UNIT_VND, ''],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'nghìn'],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'k'],
            [CreditCardUserSetting::MONEY_UNIT_THOUSAND, ''],
        ];

        foreach ($combos as [$unit, $symbol]) {
            $this->switchUnit($unit, $symbol);
            CreditCardMoneyFormatter::flushAll();

            // Tính lại từ đầu — đơn vị/ký tự không được làm lệch kết quả.
            $records->calculatePeriod($card, $period);
            $transaction->refresh();
            $period->refresh();

            $label = "$unit + '$symbol'";
            $this->assertSame($baseline['cashback'], $transaction->cashback_amount_snapshot, "[$label] cashback giao dịch đổi.");
            $this->assertSame($baseline['percent'], $transaction->cashback_percent_snapshot, "[$label] tỷ lệ cashback đổi.");
            $this->assertSame($baseline['eligible'], $period->total_eligible_spend, "[$label] chi tiêu hợp lệ đổi.");
            $this->assertSame($baseline['total'], $period->total_cashback, "[$label] tổng hoàn tiền kỳ đổi.");
        }

        // Sao kê vẫn là VND sau mọi tổ hợp.
        $this->writeStatement($card, '4237500', '250000');
        $statement = DB::connection('creditcard')
            ->table('credit_card_statements')
            ->where('user_card_id', $card->id)
            ->first();
        $this->assertNotNull($statement);
        $this->assertSame(4237500.0, (float) $statement->actual_spend);
        $this->assertSame(250000.0, (float) $statement->actual_reward);
    }

    // =====================================================================
    // M. Đơn vị + ký tự CÔ LẬP giữa các user — kể cả memo của formatter
    // =====================================================================

    #[Test]
    public function money_settings_are_isolated_between_users(): void
    {
        $other = User::factory()->create();

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'k');

        // Cùng process: memo khoá theo user, không rò từ user này sang user kia.
        $ownerId = (int) $this->owner->id;
        $otherId = (int) $other->id;
        CreditCardMoneyFormatter::flushAll();
        $this->assertSame('k', CreditCardMoneyFormatter::suffix($ownerId));
        $this->assertSame('đ', CreditCardMoneyFormatter::suffix($otherId));
        $this->assertSame('THOUSAND_VND', CreditCardMoneyFormatter::unit($ownerId));
        $this->assertSame('VND', CreditCardMoneyFormatter::unit($otherId));

        // Trang Cài đặt của mỗi người phản ánh đúng trạng thái riêng.
        $ownerHtml = $this->actingAs($this->owner)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString("moneyUnit: 'THOUSAND_VND'", $ownerHtml);
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from('k'), $ownerHtml);

        $otherHtml = $this->actingAs($other)
            ->get(route('credit-cards.settings'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString("moneyUnit: 'VND'", $otherHtml);
        $this->assertStringContainsString('moneyUnitSymbol: '.Js::from('đ'), $otherHtml);

        // Đổi ký tự của người này không bao giờ làm đổi người kia.
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND, 'nghìn');
        CreditCardMoneyFormatter::flushAll();
        $this->assertSame('nghìn', CreditCardMoneyFormatter::suffix($ownerId));
        $this->assertSame('đ', CreditCardMoneyFormatter::suffix($otherId));
        $this->assertNull((new CreditCardUserSettingService())->moneyUnitSymbolFor($otherId));
    }
}