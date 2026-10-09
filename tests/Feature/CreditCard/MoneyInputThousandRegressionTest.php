<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\User;
use App\Support\CreditCard\CreditCardMoneyFormatter;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Regression cho bug "nhập 3900 dưới đơn vị nghìn → UI hiện 390.390".
 *
 * Nguyên nhân: `ccMoneyParseInput()` (money-js) tách phần nguyên/thập phân bằng
 * `text.lastIndexOf('.')`; khi chuỗi KHÔNG có dấu chấm, `lastIndexOf('.')` trả
 * `-1` và `text.slice(0, -1)` cắt mất chữ số CUỐI (3900 → "390"), còn
 * `text.slice(dot + 1)` = `text.slice(0)` trả cả chuỗi làm "phần thập phân"
 * (3900 → "3900") ⇒ `(390 × 100000 + 39000) / 100 = 390.390` thay vì 3.900.000.
 * Hệ quả kèm theo: `ccMoneyToDisplay()` không chia /1000 khi điền sẵn giá trị
 * VND vào ô nhập dưới đơn vị nghìn, nên chuỗi hiển thị không nghịch đảo đúng
 * parser (3.900.000 đ hiện "3.900.000" thay vì "3.900").
 *
 * Các test sau chụp CẢ HAI vùng lỗi: bản JS thật đang được render (chạy qua
 * Node để kiểm tra HÀNH VI, không chỉ chuỗi nguồn), bản PHP `input()` (chr) và
 * vòng dữ liệu đầy đủ trên trang Lịch sử giao dịch.
 */
class MoneyInputThousandRegressionTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();

        CreditCardMoneyFormatter::flushAll();
    }

    protected function tearDown(): void
    {
        CreditCardMoneyFormatter::flushAll();

        parent::tearDown();
    }

    /** Đổi đơn vị qua endpoint — cùng cửa vào chính thức với người dùng bấm Lưu. */
    private function switchUnit(string $unit): void
    {
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.settings.money-unit.update'), [
                'money_unit' => $unit,
            ])
            ->assertOk();

        CreditCardMoneyFormatter::flushAll();
    }

    /** Lấy HTML trang Tổng quan (chứa money-js) cho owner này. */
    private function renderIndexHtml(): string
    {
        $this->makeUserCard($this->owner->id);

        return $this->actingAs($this->owner)
            ->get(route('credit-cards.index'))
            ->assertOk()
            ->getContent();
    }

    /** Trích NGUYÊN VĂN một hàm từ script money-js đang được render. */
    private function extractJsFunction(string $html, string $signature): string
    {
        $quoted = preg_quote($signature, '/');

        if (preg_match('/function '.$quoted.' \{.*?\n    \}/s', $html, $match) !== 1) {
            $this->fail('Không tìm thấy hàm JS `'.$signature.'` trong trang.');
        }

        return $match[0];
    }

    /** Chạy script JS trên Node, trả về [output, exit code]. */
    private function runNode(string $script): array
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-money-js-'.uniqid().'.mjs';
        file_put_contents($path, $script);

        try {
            exec('node '.escapeshellarg($path).' 2>&1', $output, $code);
        } finally {
            @unlink($path);
        }

        if ($code === 127 || str_contains(implode("\n", $output), 'not recognized')) {
            $this->markTestSkipped('Node.js không có trong PATH — bỏ qua test hành vi JS.');
        }

        return [$output, $code];
    }

    // =====================================================================
    // Bug chính: parser JS + display JS phải xử lý đúng chuỗi nguyên (không
    // dấu chấm) dưới đơn vị nghìn — chạy NGUYÊN VĂN mã đang render.
    // =====================================================================

    #[Test]
    public function client_amount_parse_and_display_match_the_reported_regression(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $html = $this->renderIndexHtml();
        $display = $this->extractJsFunction($html, 'ccMoneyToDisplay(value)');
        $parse = $this->extractJsFunction($html, 'ccMoneyParseInput(raw)');

        $script = <<<JS
const window = { ccMoneyUnit: 'THOUSAND_VND' };

__CC_DISPLAY__

__CC_PARSE__

let failed = 0;
function eq(actual, expected, label) {
    if (actual !== expected) {
        failed++;
        console.log('FAIL ' + label + ' -> ' + JSON.stringify(actual) + ' expected ' + JSON.stringify(expected));
    }
}

// Kịch bản báo lỗi gốc: nhập 3900 dưới đơn vị nghìn rồi blur.
eq(ccMoneyParseInput('3900'), 3900000, 'parse 3900 => 3.900.000 đ');
eq(ccMoneyToDisplay(ccMoneyParseInput('3900')), '3.900', 'blur hiển thị 3.900');

// Ma trận chuỗi NGUYÊN (§6) — vùng từng biến thành 390.390.
for (const [raw, vnd, shown] of [
    ['1', 1000, '1'],
    ['10', 10000, '10'],
    ['100', 100000, '100'],
    ['250', 250000, '250'],
    ['3900', 3900000, '3.900'],
    ['5000', 5000000, '5.000'],
    ['10000', 10000000, '10.000'],
    ['103,4', 103400, '103'],
    ['4237,5', 4237500, '4.237'],
]) {
    eq(ccMoneyParseInput(raw), vnd, 'parse ' + raw);
    eq(ccMoneyToDisplay(vnd), shown, 'display ' + vnd);
}

// "3.900" (nhóm nghìn) phải KHÁC "3,9" (thập phân).
eq(ccMoneyParseInput('3.900'), 3900000, 'parse 3.900');
eq(ccMoneyParseInput('3,9'), 3900, 'parse 3,9');

// Display đơn vị nghìn FLOOR về số nguyên (§17): 456.999 ⇒ 456, 499.999 ⇒ 499 —
// không bao giờ làm tròn lên 457/500.
eq(ccMoneyToDisplay(456999), '456', 'floor 456999');
eq(ccMoneyToDisplay(499999), '499', 'floor 499999');
eq(ccMoneyToDisplay(999), '0', 'floor 999');
eq(ccMoneyToDisplay(2290783), '2.290', 'floor 2290783');

// Round-trip display → parse = floor về bội số 1000 gần nhất (§20): số lẻ dưới
// 1000đ hiển thị xuống dưới đồng thì chấp nhận mất, không được bịa thập phân.
function floored(vnd) {
    const rounded = Math.round(vnd >= 0 ? vnd : -vnd) * (vnd >= 0 ? 1 : -1);
    return Math.floor(rounded / 1000) * 1000;
}
for (const vnd of [3900000, 4237500, 103400, 100, 3900, 250000, 5000000, 10000000, 100000, 1419000, 100.5, 499999, 456999]) {
    eq(ccMoneyParseInput(ccMoneyToDisplay(vnd)), floored(vnd), 'roundtrip ' + vnd);
}

// Đơn vị VND không đổi hành vi (§15).
window.ccMoneyUnit = 'VND';
eq(ccMoneyParseInput('3900'), 3900, 'VND parse 3900');
eq(ccMoneyToDisplay(3900), '3.900', 'VND display 3900');
eq(ccMoneyParseInput(ccMoneyToDisplay(3900)), 3900, 'VND roundtrip 3900');

console.log(failed === 0 ? 'ALL PASS' : failed + ' FAILURES');
process.exit(failed === 0 ? 0 : 1);
JS;

        $script = str_replace(['__CC_DISPLAY__', '__CC_PARSE__'], [$display, $parse], $script);

        [$output, $code] = $this->runNode($script);

        $this->assertSame([0, 'ALL PASS'], [$code, trim(end($output))], implode("\n", $output));
    }

    /**
     * Chốt NGUỒN lỗi nếu không có Node: parser phải có nhánh `dot === -1`
     * (nguyên chuỗi làm phần nguyên) và display phải FLOOR VND về số nguyên
     * nghìn (đổi VND → đơn vị TRƯỚC, rồi cắt phần lẻ — không chia trần).
     */
    #[Test]
    public function client_amount_parser_keeps_the_no_dot_branch_in_source(): void
    {
        $html = $this->renderIndexHtml();

        $parser = $this->extractJsFunction($html, 'ccMoneyParseInput(raw)');
        $display = $this->extractJsFunction($html, 'ccMoneyToDisplay(value)');

        // Nhánh "không có dấu chấm" phải giữ nguyên chuỗi — KHÔNG được về `slice(0, -1)`.
        $this->assertStringContainsString('if (dot === -1) {', $parser);
        $this->assertStringContainsString('integer = text;', $parser);
        $this->assertStringContainsString('decimal = \'\';', $parser);
        $this->assertStringNotContainsString('.slice(0, -1)', $parser);
        $this->assertStringContainsString('integer = text.slice(0, dot);', $parser);

        // Display đổi VND → nghìn (nghịch đảo của parser) và FLOOR về số nguyên:
        // làm tròn đồng trước, chia 1000, cắt phần lẻ — không bao giờ giữ thập phân.
        $this->assertStringContainsString("const rounded = Math.round(amount >= 0 ? amount : -amount) * (amount >= 0 ? 1 : -1);", $display);
        $this->assertStringContainsString('Math.floor(rounded / 1000)', $display);
        $this->assertStringContainsString("{ maximumFractionDigits: 0 }", $display);
        $this->assertStringNotContainsString('? amount / 1000 : amount', $display);
        $this->assertStringNotContainsString('maximumFractionDigits: 5', $display);
    }

    // =====================================================================
    // JS (`ccMoney`/`ccMoneyToDisplay`) và PHP (`number`) là HAI nguồn định
    // dạng phải floor cùng một ma trận — lệch một chữ số là hai màn hình
    // khác nhau (§23).
    // =====================================================================

    #[Test]
    public function js_and_php_floor_the_exact_same_matrix(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $userId = (int) $this->owner->id;
        $html = $this->renderIndexHtml();
        $ccMoney = $this->extractJsFunction($html, 'ccMoney(value)');
        $ccMoneyToDisplay = $this->extractJsFunction($html, 'ccMoneyToDisplay(value)');

        $matrix = [
            400000 => '400',
            456383 => '456',
            456999 => '456',
            499999 => '499',
            800000 => '800',
            2290783 => '2.290',
            5333333 => '5.333',
            7503550 => '7.503',
            4237500 => '4.237',
            103400 => '103',
            3900000 => '3.900',
            999 => '0',
        ];

        $lines = [];

        foreach ($matrix as $vnd => $expected) {
            // PHP: number() là bản server.
            $this->assertSame($expected, CreditCardMoneyFormatter::number($vnd, $userId), "PHP number($vnd)");

            // JS: cả prefill lẫn render thường đều cùng floor.
            $lines[] = "eq(ccMoney($vnd), '$expected', 'ccMoney $vnd');";
            $lines[] = "eq(ccMoneyToDisplay($vnd), '$expected', 'ccMoneyToDisplay $vnd');";
        }

        $lines[] = "window.ccMoneyUnit = 'VND';";
        $lines[] = "eq(ccMoney(456383), '456.383', 'VND ccMoney giữ đủ đồng');";
        $lines[] = "eq(ccMoneyToDisplay(456383), '456.383', 'VND ccMoneyToDisplay giữ đủ đồng');";
        $lines[] = "eq(ccMoneyToDisplay(100.5), '100,5', 'VND prefill giữ 2 chữ số thập phân');";

        $jsLines = implode("\n", $lines);

        $script = <<<JS
const window = { ccMoneyUnit: 'THOUSAND_VND' };

__CC_MONEY__

__CC_DISPLAY__

let failed = 0;
function eq(actual, expected, label) {
    if (actual !== expected) {
        failed++;
        console.log('FAIL ' + label + ' -> ' + JSON.stringify(actual) + ' expected ' + JSON.stringify(expected));
    }
}

{$jsLines}

console.log(failed === 0 ? 'ALL PASS' : failed + ' FAILURES');
process.exit(failed === 0 ? 0 : 1);
JS;

        $script = str_replace(['__CC_MONEY__', '__CC_DISPLAY__'], [$ccMoney, $ccMoneyToDisplay], $script);

        [$output, $code] = $this->runNode($script);

        $this->assertSame([0, 'ALL PASS'], [$code, trim(end($output))], implode("\n", $output));
    }

    // =====================================================================
    // Bản PHP (`CreditCardMoneyFormatter::input`) phải trùng chuỗi hiển thị
    // mà client parse để ô nhập và sao kê hiển thị giống nhau.
    // =====================================================================

    #[Test]
    public function php_formatter_input_matches_the_whole_number_matrix(): void
    {
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        $userId = (int) $this->owner->id;

        // §6: giá trị VND → chuỗi nghìn (kiểm tra kèm `input(...)` đọc lại được).
        // Số lẻ dưới 1000đ floor xuống số nguyên — cùng chuỗi màn hình hiển thị.
        foreach ([
            '1000' => '1',
            '10000' => '10',
            '100000' => '100',
            '250000' => '250',
            '3900000' => '3.900',
            '5000000' => '5.000',
            '10000000' => '10.000',
            '103400' => '103',
            '4237500' => '4.237',
            '499999' => '499',
        ] as $vnd => $display) {
            $this->assertSame($display, CreditCardMoneyFormatter::input($vnd, $userId), "input($vnd) không ra chuỗi nghìn.");
        }

        // 3.900 đ floor xuống 0 nhóm nghìn ⇒ prefill "3" (không còn "3,9").
        $this->assertSame('3', CreditCardMoneyFormatter::input('3900', $userId));

        // Đơn vị VND giữ nguyên chuỗi đầy đủ.
        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_VND);
        $this->assertSame('3.900', CreditCardMoneyFormatter::input('3900', $userId));
    }

    // =====================================================================
    // Vòng đầy đủ: create → DB VND 3900000 → trang Lịch sử nhúng row bằng VND
    // (edit mở ra `form.amount` VND mà không bao giờ nuốt 390390).
    // =====================================================================

    #[Test]
    public function transaction_history_stores_and_embeds_thousand_input_as_exact_vnd(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        // Người dùng nhập "3900" → client parse thành 3.900.000 đ rồi gửi API.
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'transaction_date' => '2026-09-20',
                'amount' => '3900000',
            ])
            ->assertCreated();

        // DB phải là 3.900.000 đ — không phải 3900, càng không phải 390390.
        $stored = DB::connection('creditcard')
            ->table('credit_card_transactions')
            ->where('user_card_id', $card->id)
            ->value('amount');
        $this->assertSame(3900000.0, (float) $stored);

        // Trang Lịch sử: đơn vị nghìn được cấu hình, state rows (mà `openEdit()`
        // copy vào `form.amount`) luôn mang VND đủ.
        $history = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('window.ccMoneyUnit = "THOUSAND_VND"', $history);
        $this->assertStringContainsString('ccMoneyToDisplay(form.amount)', $history);

        // `@js` escape dấu nháy theo json_encode (`\u0022`), nên amount trong state
        // rows hiện là chuỗi `\u00223900000.00\u0022` — VND đủ, không phải 3900.
        $this->assertStringContainsString(
            '\\u00223900000.00\\u0022',
            $history,
            'State rows phải giữ amount 3900000 (VND) để edit prefill ra "3.900".',
        );

        // Danh sách render sẵn hiển thị đúng chuỗi nghìn của 3.900.000 đ.
        $this->assertStringContainsString('<span>3.900&nbsp;<span>nghìn</span></span>', $history);

        $this->assertStringNotContainsString('390390', $history);
    }

    #[Test]
    public function editing_a_transaction_under_thousand_unit_keeps_the_stored_vnd_exact(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        // Tạo với đúng con số lẻ đã ghi trong regression: 4.237.500đ.
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'transaction_date' => '2026-09-20',
                'amount' => '4237500',
            ])
            ->assertCreated();

        $transactionId = DB::connection('creditcard')
            ->table('credit_card_transactions')
            ->where('user_card_id', $card->id)
            ->value('id');

        $this->switchUnit(CreditCardUserSetting::MONEY_UNIT_THOUSAND);

        // Form sửa điền sẵn "4.237" (đã floor); client parse chuỗi hiển thị đó về
        // 4.237.500 rồi gửi VND lên API — đúng cửa người dùng bấm Lưu.
        $this->assertSame('4.237', CreditCardMoneyFormatter::input('4237500.00', $this->owner->id));

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.transactions.update', $transactionId), [
                'amount' => '4237500',
            ])
            ->assertOk();

        $stored = DB::connection('creditcard')
            ->table('credit_card_transactions')
            ->where('id', $transactionId)
            ->value('amount');

        // Round-trip mở-dưới-nghìn → lưu: số trong DB phải là 4.237.500 — không
        // nhân 1000, không chia 1000, không làm tròn thành 4.237.000.
        $this->assertSame(4237500.0, (float) $stored);
    }
}