<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Report;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardReportService;
use App\Services\CreditCard\StatementPeriodService;
use App\Support\CreditCard\CreditCardMoneyFormatter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Loại trừ danh mục trong báo cáo "Chi tiêu theo danh mục".
 *
 * Bất biến cần giữ:
 *   1. Danh mục bị loại trừ KHÔNG xuất hiện trong bảng và KHÔNG được tính vào
 *      bất kỳ tổng nào (theo thẻ / theo danh mục / tổng tất cả) hay tỷ lệ.
 *   2. "Chưa phân loại" (category_id = NULL) không bao giờ bị loại trừ.
 *   3. Loại trừ được LƯU cùng báo cáo và áp dụng lại ở trang xem lẫn file Excel.
 *   4. Loại trừ bị BỎ QUA với báo cáo "theo thẻ".
 *   5. Chỉ được loại trừ danh mục hệ thống hoặc của chính user; ID của người khác
 *      bị chặn ở validation và bị service lọc bỏ (phòng thủ hai lớp).
 *   6. Báo cáo vẫn dùng chung một nguồn tính (`CreditCardReportService`).
 */
class CreditCardReportExcludedCategoryTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $user;

    private UserCard $cardA;

    private UserCard $cardB;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00'));

        $this->user = User::factory()->create();
        $this->cardA = $this->makeUserCard($this->user->id, ['name' => 'Thẻ A']);
        $this->cardB = $this->makeUserCard($this->user->id, ['name' => 'Thẻ B']);
    }

    protected function tearDown(): void
    {
        CreditCardMoneyFormatter::flushAll();

        parent::tearDown();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function periodFor(UserCard $card, int $offset = 0): StatementPeriod
    {
        $boundaries = app(StatementPeriodService::class)
            ->selectableBoundaries($card, CarbonImmutable::now());

        [$start, $end] = $boundaries[$offset];

        return $this->makeStatementPeriod($card, [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'statement_date' => $end->toDateString(),
            'payment_due_date' => $end->toDateString(),
        ]);
    }

    private function makeTxn(
        UserCard $card,
        StatementPeriod $period,
        string $amount,
        ?string $cashback,
        ?int $categoryId = null,
    ): Transaction {
        return Transaction::create([
            'user_card_id' => $card->id,
            'statement_period_id' => $period->id,
            'category_id' => $categoryId,
            'amount' => $amount,
            'transaction_date' => $period->period_start->toDateString(),
            'source' => Transaction::SOURCE_MANUAL,
            'cashback_amount_snapshot' => $cashback,
            'is_eligible' => true,
            'calc_basis' => $period->period_start->toDateString(),
        ]);
    }

    private function makeReport(string $type = Report::TYPE_BY_CATEGORY, array $cardIds = [], array $excluded = []): Report
    {
        $report = Report::create([
            'user_id' => $this->user->id,
            'name' => 'Báo cáo thử',
            'type' => $type,
            'excluded_category_ids' => $excluded,
        ]);

        if ($cardIds !== []) {
            $report->cards()->sync($cardIds);
        }

        return $report->refresh();
    }

    private function pageWith(Report $report, array $query = []): string
    {
        return $this->actingAs($this->user)
            ->get(route('credit-cards.reports.show', ['report' => $report->id] + $query))
            ->assertOk()
            ->getContent();
    }

    private function sheetOf(TestResponse $response): Worksheet
    {
        $tmp = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        return $sheet;
    }

    private function exportSheet(Report $report, array $query = []): Worksheet
    {
        $response = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.export', ['report' => $report->id] + $query));

        $response->assertOk();

        return $this->sheetOf($response);
    }

    // =====================================================================
    // Lưu cấu hình
    // =====================================================================

    #[Test]
    public function the_form_renders_exclusion_chips_for_selectable_categories(): void
    {
        $system = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="report-excluded-categories"', $html);
        $this->assertStringContainsString('data-testid="exclude-category-'.$system->id.'"', $html);
        $this->assertStringContainsString('Loại trừ các danh mục', $html);
    }

    #[Test]
    public function store_persists_the_chosen_excluded_categories(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $move = $this->makeSystemCategory(['name' => 'Di chuyển']);

        $this->actingAs($this->user)
            ->post(route('credit-cards.reports.store'), [
                'name' => 'Báo cáo danh mục',
                'type' => Report::TYPE_BY_CATEGORY,
                'card_ids' => [$this->cardA->id],
                'excluded_category_ids' => [$eat->id],
            ])
            ->assertRedirect();

        $report = Report::query()->where('user_id', $this->user->id)->firstOrFail();

        $this->assertSame([$eat->id], $report->excludedCategoryIds());
        $this->assertNotContains($move->id, $report->excludedCategoryIds());
    }

    #[Test]
    public function update_replaces_the_excluded_category_list(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $move = $this->makeSystemCategory(['name' => 'Di chuyển']);
        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id], [$eat->id]);

        $this->actingAs($this->user)
            ->patch(route('credit-cards.reports.update', ['report' => $report->id]), [
                'name' => $report->name,
                'type' => Report::TYPE_BY_CATEGORY,
                'card_ids' => [$this->cardA->id],
                'excluded_category_ids' => [$move->id],
            ])
            ->assertRedirect();

        $this->assertSame([$move->id], $report->fresh()->excludedCategoryIds());
    }

    #[Test]
    public function store_rejects_a_category_of_another_user(): void
    {
        $other = User::factory()->create();
        $foreign = $this->makeUserCategory($other->id);

        $this->actingAs($this->user)
            ->post(route('credit-cards.reports.store'), [
                'name' => 'Báo cáo lạ',
                'type' => Report::TYPE_BY_CATEGORY,
                'card_ids' => [$this->cardA->id],
                'excluded_category_ids' => [$foreign->id],
            ])
            ->assertSessionHasErrors('excluded_category_ids.0');

        $this->assertSame(0, Report::query()->where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function store_rejects_a_category_that_does_not_exist(): void
    {
        $this->actingAs($this->user)
            ->post(route('credit-cards.reports.store'), [
                'name' => 'Báo cáo lạ',
                'type' => Report::TYPE_BY_CATEGORY,
                'card_ids' => [$this->cardA->id],
                'excluded_category_ids' => [999999],
            ])
            ->assertSessionHasErrors('excluded_category_ids.0');
    }

    #[Test]
    public function the_service_drops_a_foreign_category_id_as_the_second_defence_layer(): void
    {
        $other = User::factory()->create();
        $foreign = $this->makeUserCategory($other->id);
        $own = $this->makeUserCategory($this->user->id, ['name' => 'Của tôi']);

        $report = app(CreditCardReportService::class)->create($this->user->id, [
            'name' => 'Báo cáo',
            'type' => Report::TYPE_BY_CATEGORY,
            'card_ids' => [$this->cardA->id],
            'excluded_category_ids' => [$foreign->id, $own->id],
        ]);

        $this->assertSame([$own->id], $report->excludedCategoryIds());
    }

    #[Test]
    public function the_edit_form_still_shows_a_stored_inactive_category_so_it_can_be_removed(): void
    {
        $category = $this->makeUserCategory($this->user->id, ['name' => 'Danh mục riêng cũ']);
        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id], [$category->id]);

        // Sau khi bị ẩn, danh mục biến khỏi danh sách chọn mới nhưng báo cáo vẫn
        // đang loại trừ nó — form phải render chip để user gỡ bỏ.
        $category->forceFill(['is_active' => false])->save();

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.edit', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="exclude-category-'.$category->id.'"', $html);
        $this->assertStringContainsString('Danh mục riêng cũ', $html);
        $this->assertStringContainsString('(đã ẩn)', $html);
    }

    #[Test]
    public function the_form_keeps_the_just_submitted_exclusions_after_a_validation_error(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);

        // Lỗi validation (thiếu card_ids) → quay lại form kèm `old()` — các chip
        // vừa chọn phải còn tick đúng như user vừa thao tác.
        $this->actingAs($this->user)
            ->post(route('credit-cards.reports.store'), [
                'name' => 'Báo cáo danh mục',
                'type' => Report::TYPE_BY_CATEGORY,
                'card_ids' => [],
                'excluded_category_ids' => [$eat->id],
            ])
            ->assertSessionHasErrors('card_ids');

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.create'))
            ->assertOk()
            ->getContent();

        $xpath = new DOMXPath($this->domOf($html));
        $input = $xpath->query('//input[@data-testid="exclude-category-'.$eat->id.'"]')->item(0);
        $this->assertNotNull($input);
        $this->assertSame('excluded_category_ids[]', $input->getAttribute('name'));
        $this->assertSame((string) $eat->id, $input->getAttribute('value'));
        $this->assertSame('checked', $input->getAttribute('checked'));
    }

    // =====================================================================
    // Form widget — chips & Chọn tất cả (DOM)
    // =====================================================================

    #[Test]
    public function each_chip_is_a_real_submitted_checkbox_wrapped_in_a_label(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $move = $this->makeSystemCategory(['name' => 'Di chuyển']);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.create'))
            ->assertOk()
            ->getContent();

        $xpath = new DOMXPath($this->domOf($html));

        foreach ([$eat, $move] as $category) {
            $input = $xpath->query('//input[@data-testid="exclude-category-'.$category->id.'"]')->item(0);

            // Checkbox THẬT, không phải phần tử trang trí: có name để submit.
            $this->assertNotNull($input);
            $this->assertSame('checkbox', $input->getAttribute('type'));
            $this->assertSame('excluded_category_ids[]', $input->getAttribute('name'));
            $this->assertSame((string) $category->id, $input->getAttribute('value'));
            $this->assertStringContainsString('sr-only', $input->getAttribute('class'));

            // Được bọc trong `<label>` ⇒ nhấp bất kỳ đâu trên chip (nhãn) cũng
            // chuyển trạng thái theo cơ chế gốc của trình duyệt.
            $label = $input->parentNode;
            $this->assertNotNull($label);
            $this->assertSame('label', $label->nodeName);
            $this->assertSame('excluded-chip', $label->getAttribute('data-role'));

            // Trong label phải có span hiển thị tên danh mục.
            $span = $xpath->query('./span[@data-role="chip-label"]', $label)->item(0);
            $this->assertNotNull($span);
            $this->assertStringContainsString($category->name, $span->textContent);
        }
    }

    #[Test]
    public function unchosen_chips_render_off_colors_and_select_all_is_a_ui_only_control(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.create'))
            ->assertOk()
            ->getContent();

        $xpath = new DOMXPath($this->domOf($html));

        $input = $xpath->query('//input[@data-testid="exclude-category-'.$eat->id.'"]')->item(0);
        $this->assertNotNull($input);
        $this->assertFalse($input->hasAttribute('checked'));

        // Chip chưa chọn: màu "trắng/xám" mặc định, không chứa màu đã chọn.
        $span = $xpath->query('//input[@data-testid="exclude-category-'.$eat->id.'"]/parent::label/span[@data-role="chip-label"]')->item(0);
        $this->assertNotNull($span);
        $classes = ' '.trim($span->getAttribute('class')).' ';
        $this->assertStringContainsString(' bg-white ', $classes);
        $this->assertStringContainsString(' text-gray-600 ', $classes);
        $this->assertStringContainsString(' border-gray-200 ', $classes);
        $this->assertStringNotContainsString('bg-emerald-600', $classes);

        // Điều khiển Chọn tất cả chỉ là UI thuần tuý: KHÔNG có `name` → không
        // được gửi lên server, không lẫn vào `excluded_category_ids[]`.
        $selectAll = $xpath->query('//input[@id="exclude-all-categories"]')->item(0);
        $this->assertNotNull($selectAll);
        $this->assertFalse($selectAll->hasAttribute('name'));
        $this->assertFalse($selectAll->hasAttribute('checked'));

        $label = $xpath->query('//span[@data-testid="exclude-all-label"]')->item(0);
        $this->assertNotNull($label);
        $this->assertSame('Chọn tất cả danh mục để loại trừ', trim($label->textContent));
    }

    #[Test]
    public function the_edit_form_restores_saved_exclusions_and_keeps_chips_enabled(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $move = $this->makeSystemCategory(['name' => 'Di chuyển']);
        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id], [$eat->id]);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.edit', ['report' => $report->id]))
            ->assertOk()
            ->getContent();

        $xpath = new DOMXPath($this->domOf($html));

        $chosen = $xpath->query('//input[@data-testid="exclude-category-'.$eat->id.'"]')->item(0);
        $this->assertNotNull($chosen);
        $this->assertSame('checked', $chosen->getAttribute('checked'));

        $unchosen = $xpath->query('//input[@data-testid="exclude-category-'.$move->id.'"]')->item(0);
        $this->assertNotNull($unchosen);
        $this->assertFalse($unchosen->hasAttribute('checked'));

        // Kể cả khi user đổi kiểu sang "theo thẻ" (section ẩn bằng `hidden`),
        // checkbox vẫn KHÔNG bị `disabled` ⇒ vẫn được submit ⇒ không mất lựa chọn.
        $section = $xpath->query('//div[@id="report-excluded-section"]')->item(0);
        $this->assertNotNull($section);
        foreach ([$chosen, $unchosen] as $input) {
            $this->assertFalse($input->hasAttribute('disabled'));
        }
    }

    #[Test]
    public function the_page_script_drives_the_widget_from_the_checkbox_state(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $html = $this->actingAs($this->user)
            ->get(route('credit-cards.reports.create'))
            ->assertOk()
            ->getContent();

        // Hiện/ẩn theo kiểu báo cáo PHẢI dùng `hidden` (giữ lựa chọn), không disable.
        $this->assertStringContainsString('section.hidden =', $html);
        $this->assertStringContainsString('checked.value !== \'by_category\'', $html);

        // JS lấy checkbox THẬT làm nguồn chân lý để tô màu chip và đồng bộ chọn tất cả.
        $this->assertStringContainsString('input[name="excluded_category_ids[]"]', $html);
        $this->assertStringContainsString('getElementById(\'exclude-all-categories\')', $html);
        $this->assertStringContainsString("'bg-emerald-600'", $html);
        $this->assertStringContainsString('data-chosen', $html);
        $this->assertStringContainsString('Bỏ chọn tất cả danh mục để loại trừ', $html);
    }

    private function domOf(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return $dom;
    }

    // =====================================================================
    // Tính toán — service
    // =====================================================================

    #[Test]
    public function by_category_removes_excluded_rows_and_totals(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $move = $this->makeSystemCategory(['name' => 'Di chuyển']);
        $period = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $eat->id);
        $this->makeTxn($this->cardA, $period, '2000000', '40000.00', $move->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
            [$eat->id],
        );

        $names = array_column($data['rows'], 'name');
        $this->assertNotContains('Ăn uống', $names);
        $this->assertContains('Di chuyển', $names);

        $this->assertSame('2000000.00', $data['grand']['spend']);
        $this->assertSame('40000.00', $data['grand']['cashback']);
        $this->assertSame('2.00', $data['grand']['percent']);

        // Tổng theo thẻ cũng phải tính lại trên tập còn lại.
        $this->assertSame('2000000.00', $data['card_totals'][$this->cardA->id]['spend']);
        $this->assertSame('40000.00', $data['card_totals'][$this->cardA->id]['cashback']);

        $this->assertTrue($data['had_data']);
    }

    #[Test]
    public function by_category_without_exclusions_still_lists_everything(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $eat->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
            [],
        );

        $this->assertSame('1000000.00', $data['grand']['spend']);
        $this->assertContains('Ăn uống', array_column($data['rows'], 'name'));
    }

    #[Test]
    public function by_category_all_excluded_yields_no_rows_but_still_had_data(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $eat->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
            [$eat->id],
        );

        $this->assertSame([], $data['rows']);
        $this->assertSame('0.00', $data['grand']['spend']);
        $this->assertSame('0.00', $data['grand']['cashback']);
        $this->assertNull($data['grand']['percent']);
        // Có giao dịch trong kỳ nhưng bị loại trừ hết ⇒ vẫn báo "có dữ liệu".
        $this->assertTrue($data['had_data']);
    }

    #[Test]
    public function by_category_never_excludes_uncategorized_row(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $period = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $period, '400000', '4000.00', null);
        $this->makeTxn($this->cardA, $period, '600000', '6000.00', $eat->id);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
            [$eat->id],
        );

        $rows = collect($data['rows'])->keyBy('name');
        $this->assertArrayHasKey('Chưa phân loại', $rows->all());
        $this->assertArrayNotHasKey('Ăn uống', $rows->all());
        $this->assertSame('400000.00', $rows['Chưa phân loại']['spend_total']);
        $this->assertSame('400000.00', $data['grand']['spend']);
    }

    #[Test]
    public function by_category_without_transactions_reports_no_data(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $data = app(CreditCardReportService::class)->byCategory(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
            [$eat->id],
        );

        $this->assertSame([], $data['rows']);
        $this->assertFalse($data['had_data']);
    }

    #[Test]
    public function by_card_ignores_stored_exclusions(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $eat->id);

        $data = app(CreditCardReportService::class)->byCard(
            collect([$this->cardA]),
            CreditCardReportService::PERIOD_CURRENT,
        );

        $this->assertSame('1000000.00', $data['total_spend']);

        // Báo cáo "theo thẻ" vẫn lưu danh sách loại trừ nhưng không hề dùng tới.
        $report = $this->makeReport(Report::TYPE_BY_CARD, [$this->cardA->id], [$eat->id]);
        $html = $this->pageWith($report);

        $this->assertStringContainsString('>1.000.000', $html);
        $this->assertStringNotContainsString('report-excluded-summary', $html);
    }

    // =====================================================================
    // Trang kết quả
    // =====================================================================

    #[Test]
    public function the_show_page_hides_excluded_categories_and_summarizes_them(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $move = $this->makeSystemCategory(['name' => 'Di chuyển']);
        $period = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $eat->id);
        $this->makeTxn($this->cardA, $period, '2000000', '40000.00', $move->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id], [$eat->id]);
        $html = $this->pageWith($report);

        $this->assertStringNotContainsString('category-row-'.$eat->id, $html);
        $this->assertStringContainsString('category-row-'.$move->id, $html);
        $this->assertStringContainsString('report-excluded-summary', $html);
        $this->assertStringContainsString('Ăn uống', $html);
        // Tổng tất cả chỉ còn phần của danh mục giữ lại.
        $this->assertStringContainsString('>2.000.000', $html);
    }

    #[Test]
    public function the_show_page_explains_when_every_category_is_excluded(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $period = $this->periodFor($this->cardA);
        $this->makeTxn($this->cardA, $period, '1000000', '10000.00', $eat->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id], [$eat->id]);
        $html = $this->pageWith($report);

        $this->assertStringContainsString('Không có danh mục phù hợp với bộ lọc loại trừ.', $html);
        $this->assertStringNotContainsString('Kỳ này chưa có giao dịch nào.', $html);
    }

    #[Test]
    public function the_show_page_uses_the_plain_empty_message_when_there_is_no_data(): void
    {
        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id], []);
        $html = $this->pageWith($report);

        $this->assertStringContainsString('Kỳ này chưa có giao dịch nào.', $html);
        $this->assertStringNotContainsString('Không có danh mục phù hợp với bộ lọc loại trừ.', $html);
    }

    // =====================================================================
    // Xuất Excel
    // =====================================================================

    #[Test]
    public function export_hides_excluded_categories_and_matches_the_screen(): void
    {
        $eat = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $move = $this->makeSystemCategory(['name' => 'Di chuyển']);
        $periodA = $this->periodFor($this->cardA);

        $this->makeTxn($this->cardA, $periodA, '1000000', '10000.00', $eat->id);
        $this->makeTxn($this->cardA, $periodA, '2000000', '40000.00', $move->id);

        $report = $this->makeReport(Report::TYPE_BY_CATEGORY, [$this->cardA->id], [$eat->id]);
        $sheet = $this->exportSheet($report);
        $rows = $sheet->toArray(null, true, false);

        $names = array_map(fn (array $row): string => (string) ($row[0] ?? ''), $rows);
        $this->assertNotContains('Ăn uống', $names);
        $this->assertContains('Di chuyển', $names);

        $grand = collect($rows)->first(fn (array $row): bool => $row[0] === 'Tổng tất cả');

        $this->assertNotNull($grand);
        $this->assertEqualsWithDelta(2000000.0, $grand[1], 0.001);
        $this->assertEqualsWithDelta(40000.0, $grand[2], 0.001);
    }
}
