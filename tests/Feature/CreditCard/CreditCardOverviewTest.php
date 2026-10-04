<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Tổng quan (/thetindung) — 4 chỉ số + form nhập giao dịch tại chỗ.
 *
 * Bốn bất biến được khoá ở đây:
 *   1. Bốn chỉ số đến từ `CreditCardOverviewService` (aggregate SQL), và
 *      "cashback dự kiến" là SỐ ENGINE ĐÃ GHI vào `StatementPeriod.total_cashback`
 *      — không có công thức cashback nào ở trang hay ở service.
 *   2. "Tổng chi tiêu" = kỳ sao kê HIỆN TẠI (kỳ `open` chứa hôm nay), không phải
 *      30 ngày gần nhất, không phải cả lịch sử.
 *   3. Form chỉ gửi 4 field; ngày giao dịch mặc định là HÔM NAY do server đặt.
 *   4. Người khác không bao giờ thấy thẻ, giao dịch hay hạn mức của user này.
 */
class CreditCardOverviewTest extends TestCase
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
    // 4 chỉ số
    // =====================================================================

    #[Test]
    public function the_overview_renders_exactly_four_metric_tiles(): void
    {
        $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        foreach ([
            'stat-total-cards',
            'stat-total-limit',
            'stat-total-spend',
            'stat-expected-cashback',
        ] as $testId) {
            $this->assertStringContainsString('data-testid="'.$testId.'"', $html);
        }

        // Đúng 4 ô chỉ số — không dựng thêm ô thứ 5.
        $this->assertSame(4, substr_count($html, 'data-testid="stat-'));
    }

    #[Test]
    public function the_overview_counts_cards_and_sums_their_credit_limits(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 50000000]);
        $this->makeUserCard($this->owner->id, ['credit_limit' => 100000000]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // 150.000.000 = 50tr + 100tr (tiền Việt Nam phân cách nghìn bằng dấu chấm).
        $this->assertStringContainsString('150.000.000', $html);
    }

    #[Test]
    public function the_overview_does_not_leak_another_users_cards_or_limits(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 50000000]);
        $this->makeUserCard($this->stranger->id, ['credit_limit' => 900000000]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('50.000.000', $html);
        $this->assertStringNotContainsString('900.000.000', $html);
    }

    #[Test]
    public function the_cards_api_carries_the_same_four_metrics(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 70000000]);
        $this->makeUserCard($this->stranger->id, ['credit_limit' => 800000000]);

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        // Trang Tổng quan làm mới 4 chỉ số bằng chính endpoint này ⇒ phải khớp.
        $this->assertSame(1, $summary['total_cards']);
        $this->assertSame('70000000.00', $summary['total_credit_limit']);
        $this->assertSame('0.00', $summary['total_spend']);
        $this->assertSame('0.00', $summary['expected_cashback']);

        // Chỉ 4 chỉ số — không kéo theo model kỳ sao kê vào payload JSON.
        $this->assertSame(
            ['total_cards', 'total_credit_limit', 'total_spend', 'expected_cashback'],
            array_keys($summary)
        );
    }

    // =====================================================================
    // Kỳ sao kê hiện tại
    // =====================================================================

    #[Test]
    public function spending_and_cashback_only_count_the_current_statement_period(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $current = $this->makeCurrentPeriod($card);
        $previous = $this->makeStatementPeriod($card, [
            'period_start' => CarbonImmutable::now()->subMonths(2)->startOfMonth()->toDateString(),
            'period_end' => CarbonImmutable::now()->subMonth()->subDay()->toDateString(),
            'statement_date' => CarbonImmutable::now()->subMonth()->subDay()->toDateString(),
            'payment_due_date' => CarbonImmutable::now()->subMonth()->toDateString(),
            'status' => StatementPeriod::STATUS_FINALIZED,
            'total_cashback' => 999000,
        ]);

        // 1.000.000 trong kỳ cũ (đã đóng) + 250.000 trong kỳ hiện tại.
        $this->createTransaction($card, $category, '1000000', $previous->id, CarbonImmutable::now()->subMonths(2)->subDay()->subDays(3)->toDateString());
        $this->createTransaction($card, $category, '250000', $current->id, CarbonImmutable::now()->toDateString());

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        // Chỉ kỳ HIỆN TẠI: 250.000, không phải 1.250.000 cả lịch sử.
        $this->assertSame('250000.00', $summary['total_spend']);
    }

    #[Test]
    public function expected_cashback_applies_the_target_tier_rate_to_the_actual_spend(): void
    {
        $category = $this->makeSystemCategory();

        // Mục tiêu 10.000.000 ⇒ BẬC ĐÍCH là "Bậc cao" (> 3.000.000, 10%).
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '10000000']);

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc thấp', 'min' => 0, 'max' => 3000000, 'cap_period' => null],
                ['name' => 'Bậc cao', 'min' => 3000000, 'max' => null, 'cap_period' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '0.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '10.000', 'only_tier' => 1],
            ],
        );

        // Chi tiêu THỰC TẾ mới 2.000.000 ⇒ engine chạy ở "Bậc thấp" (0%) và
        // snapshot `total_cashback` bằng 0. Số "dự kiến" KHÔNG được theo đó.
        $current = $this->makeCurrentPeriod($card);
        $current->forceFill([
            'total_eligible_spend' => '2000000.00',
            'total_cashback' => '0.00',
        ])->save();

        $this->createTransaction($card, $category, '2000000', $current->id, CarbonImmutable::now()->toDateString());

        $payload = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta');

        $metrics = $payload['card_metrics'][(string) $card->id];

        // Bậc đích vẫn do MỤC TIÊU chọn ("Bậc cao"), nhưng rate 10% của bậc đó được
        // nhân với CHI TIÊU THỰC TẾ: 2.000.000 × 10% = 200.000 — không phải
        // 10.000.000 × 10% = 1.000.000 như công thức cũ.
        $this->assertSame('Bậc cao', $metrics['quota']['tier_name']);
        $this->assertSame('2000000.00', $metrics['spent']);
        $this->assertSame('200000.00', $metrics['expected_cashback']);

        // `summary` cộng đúng các số dự kiến theo thẻ.
        $this->assertSame('200000.00', $payload['summary']['expected_cashback']);

        // Tiền THỰC TẾ vẫn nằm ở chỗ cũ, không bị đổi ý nghĩa.
        $this->assertSame('0.00', $metrics['cashback']);
    }

    #[Test]
    public function expected_cashback_grows_with_actual_spend_not_with_the_goal(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '10000000']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc cao', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000']],
        );

        $current = $this->makeCurrentPeriod($card);
        $date = CarbonImmutable::now()->toDateString();

        // Mục tiêu giữ nguyên 10.000.000 trong cả hai lần đo.
        $this->createTransaction($card, $category, '1000000', $current->id, $date);

        $before = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.card_metrics.'.(string) $card->id.'.expected_cashback');

        $this->createTransaction($card, $category, '4000000', $current->id, $date);

        $after = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.card_metrics.'.(string) $card->id.'.expected_cashback');

        // Chi tiêu tăng 1.000.000 → 5.000.000 thì "dự kiến" phải TĂNG theo. Công
        // thức cũ (nhân `desired_spend`) trả hai số bằng nhau vì mục tiêu đứng yên.
        $this->assertSame('100000.00', $before);
        $this->assertSame('500000.00', $after);
    }

    #[Test]
    public function expected_cashback_is_capped_by_the_target_tier_ceiling(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '20000000']);

        // Rate 10% trên chi tiêu thực tế 15.000.000 ⇒ 1.500.000, nhưng trần bậc chỉ
        // 1.000.000 ⇒ phải kẹp, không hiện "1.500.000 / 1.000.000".
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc cao', 'min' => 0, 'max' => null, 'cap_period' => '1000000.00']],
            [['category_id' => $category->id, 'percent' => '10.000']],
        );

        $current = $this->makeCurrentPeriod($card);
        $this->createTransaction($card, $category, '15000000', $current->id, CarbonImmutable::now()->toDateString());

        $metrics = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.card_metrics.'.(string) $card->id);

        $this->assertSame('1000000.00', $metrics['quota']['tier_cashback_max']);
        $this->assertSame('1000000.00', $metrics['expected_cashback']);
    }

    #[Test]
    public function a_card_without_a_goal_has_no_expected_cashback(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makeCurrentPeriod($card)->forceFill(['total_cashback' => 250000])->save();

        // Không có `desired_spend` ⇒ không có bậc đích ⇒ không có "dự kiến".
        // Snapshot engine vẫn là tiền thực tế và vẫn được giữ nguyên ở `cashback`.
        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        $this->assertSame('0.00', $summary['expected_cashback']);
    }

    #[Test]
    public function a_refund_reduces_the_total_spend(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $current = $this->makeCurrentPeriod($card);
        $category = $this->makeSystemCategory();

        $this->createTransaction($card, $category, '1000000', $current->id, CarbonImmutable::now()->toDateString());
        $this->createTransaction($card, $category, '-150000', $current->id, CarbonImmutable::now()->toDateString());

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        $this->assertSame('850000.00', $summary['total_spend']);
    }

    #[Test]
    public function expected_cashback_is_summed_across_all_of_the_users_cards(): void
    {
        $category = $this->makeSystemCategory();

        // Mỗi thẻ một mục tiêu ⇒ mỗi thẻ một bậc đích ⇒ cộng dồn số "dự kiến".
        $first = $this->makeUserCard($this->owner->id, ['desired_spend' => '1000000']);
        $second = $this->makeUserCard($this->owner->id, ['desired_spend' => '2000000']);

        foreach ([$first, $second] as $card) {
            $this->makePolicyForCard(
                $card,
                [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
                [['category_id' => $category->id, 'percent' => '10.000']]
            );
        }

        $firstPeriod = $this->makeCurrentPeriod($first);
        $secondPeriod = $this->makeCurrentPeriod($second);
        $date = CarbonImmutable::now()->toDateString();

        $this->createTransaction($first, $category, '400000', $firstPeriod->id, $date);
        $this->createTransaction($second, $category, '1000000', $secondPeriod->id, $date);

        $firstPeriod->forceFill(['total_cashback' => 10000])->save();
        $secondPeriod->forceFill(['total_cashback' => 20000])->save();

        $summary = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta.summary');

        // 400.000 × 10% + 1.000.000 × 10% = 40.000 + 100.000.
        $this->assertSame('140000.00', $summary['expected_cashback']);
    }

    #[Test]
    public function opening_the_overview_never_creates_a_statement_period(): void
    {
        $this->makeUserCard($this->owner->id);

        $this->assertSame(0, StatementPeriod::query()->count());

        $this->actingAs($this->owner)->get('/thetindung')->assertOk();
        $this->actingAs($this->owner)->get('/thetindung')->assertOk();
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk();

        $this->assertSame(0, StatementPeriod::query()->count());
    }

    /**
     * TEST: Trang tổng quan KHÔNG in khoảng ngày của một kỳ sao kê nữa.
     *
     * Mỗi thẻ một `statement_day` nên mỗi thẻ một kỳ — một khoảng ngày duy nhất
     * chỉ đúng với thẻ đầu tiên và sai với các thẻ còn lại. Hai ô tổng giờ nói
     * đúng nguyên tắc thay cho khoảng ngày.
     */
    #[Test]
    public function the_overview_explains_the_totals_follow_each_card_instead_of_one_date_range(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '1000000']);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );
        $this->makeCurrentPeriod($card, [
            'period_start' => '2026-09-16',
            'period_end' => '2026-10-15',
            'statement_date' => '2026-10-15',
            'payment_due_date' => '2026-10-25',
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Số dự kiến theo mục tiêu: 1.000.000 × 5% = 50.000.
        $this->assertStringContainsString('50.000', $html);

        $this->assertSame(2, substr_count($html, 'Theo kỳ sao kê hiện tại của từng thẻ'));
        $this->assertStringNotContainsString('15/10', $html);
        $this->assertStringNotContainsString('25/10/2026', $html);
    }

    // =====================================================================
    // Ràng buộc trang
    // =====================================================================

    #[Test]
    public function the_overview_has_the_add_transaction_cta_but_no_add_card_button(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="add-transaction-button"', $html);
        $this->assertStringContainsString('Nhập giao dịch', $html);

        // "+ Thêm thẻ" chỉ nằm ở Quản lý thẻ.
        $this->assertStringNotContainsString('data-testid="add-card-button"', $html);
        $this->assertStringNotContainsString('+ Thêm thẻ', $html);
    }

    #[Test]
    public function the_transaction_form_has_exactly_five_inputs(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="transaction-form"', $html);

        // Năm ô: NGÀY → SỐ TIỀN → THẺ → DANH MỤC → GHI CHÚ.
        foreach (['tx-transaction-date', 'tx-amount', 'tx-card', 'tx-category', 'tx-note'] as $field) {
            $this->assertStringContainsString('id="'.$field.'"', $html, 'Thiếu ô '.$field);
        }

        // Ngày đứng ĐẦU TIÊN: mọi thứ khác (kỳ sao kê, bậc, quota) đều bám ngày.
        $this->assertLessThan(
            strpos($html, 'id="tx-amount"'),
            strpos($html, 'id="tx-transaction-date"'),
            'Ô ngày phải đứng trước ô số tiền',
        );

        // Ô ngày là `type="date"` và KHÔNG bị khoá: không `min`/`max` theo kỳ sao kê,
        // không `min="today"` — người dùng chọn được BẤT KỲ ngày nào, kể cả PC.
        $this->assertMatchesRegularExpression('/id="tx-transaction-date"[^>]*type="date"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="tx-transaction-date"[^>]*\bmin=/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="tx-transaction-date"[^>]*\bmax=/', $html);

        // KHÔNG ô nào cho nhập cashback / chính sách / bậc / cap / chọn kỳ sao kê.
        foreach (['cashback', 'policy', 'tier', 'cap', 'statement-period'] as $forbidden) {
            $this->assertStringNotContainsString('id="tx-'.$forbidden.'"', $html);
        }
    }

    #[Test]
    public function the_transaction_date_defaults_to_today_inside_the_current_period(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $state = $this->overviewState($html);

        // Hôm nay đưa xuống làm giá trị mặc định của ô ngày.
        $this->assertSame(CarbonImmutable::now()->toDateString(), $state['today']);

        // Ranh giới kỳ hiện tại đi kèm từng thẻ để chặn ngày ngoài kỳ ngay trên máy.
        $card = $state['cards'][0];
        $this->assertNotNull($card['period_start']);
        $this->assertNotNull($card['period_end']);
        // Hôm nay phải nằm GIỮA ranh giới kỳ hiện tại của thẻ.
        $this->assertLessThanOrEqual($card['period_end'], $state['today']);
        $this->assertGreaterThanOrEqual($card['period_start'], $state['today']);
    }

    #[Test]
    public function the_overview_no_longer_shows_the_spending_reminder(): void
    {
        $this->makeUserCard($this->owner->id, [
            'status' => UserCard::STATUS_ACTIVE,
            'spending_deadline_day' => 20,
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Ngày nhắc là metadata phụ, còn kỳ sao kê do `statement_day` quyết định —
        // hiện nó dễ bị hiểu nhầm là hạn chức năng. Ô ngày + ranh giới kỳ đã thay thế.
        $this->assertStringNotContainsString('deadlineWarnings', $html);
        $this->assertStringNotContainsString('Nhắc chi tiêu', $html);
    }

    #[Test]
    public function the_transaction_form_is_mobile_first(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Chiều cao nút bấm ≥ 44px cho ngón tay; ô nhập cao h-12.
        $this->assertStringContainsString('data-testid="add-transaction-button"', $html);
        $this->assertMatchesRegularExpression('/data-testid="add-transaction-button"[^>]*h-1[24]/', $html);

        // Các ô nhập full-width và có `min-w-0` ở ô chỉ số để không tràn ngang.
        $this->assertMatchesRegularExpression('/id="tx-amount"[^>]*w-full/', $html);
        $this->assertStringContainsString('min-w-0', $html);
    }

    #[Test]
    public function the_transaction_form_opens_as_a_full_screen_overlay(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Màn hình nổi phải PHỦ KÍN viewport, không phải modal hộp giữa màn hình:
        // `fixed inset-0` + nền đục + cao theo khung nhìn thật của điện thoại.
        $overlays = $xpath->query("//div[@x-show='formOpen' and @role='dialog']");
        $this->assertSame(1, $overlays->length, 'Form giao dịch phải nằm trong đúng một màn hình nổi.');

        $overlay = $overlays->item(0);
        $this->assertSame('true', $overlay->getAttribute('aria-modal'));

        $classes = $overlay->getAttribute('class');
        foreach (['fixed', 'inset-0', 'w-full', 'h-[100dvh]', 'bg-white'] as $needle) {
            $this->assertStringContainsString($needle, $classes, "Overlay thiếu lớp {$needle}.");
        }

        // KHÔNG được giới hạn bề rộng ở desktop thành hộp nổi giữa màn hình.
        $this->assertStringNotContainsString('sm:max-w', $classes);
        $this->assertStringNotContainsString('sm:mx-auto', $classes);

        // z-index phải vượt header dính và bottom-sheet (z-50) của bố cục chung.
        preg_match('/z-\[(\d+)\]/', $classes, $z);
        $this->assertNotEmpty($z, 'Overlay phải có z-index tường minh.');
        $this->assertGreaterThan(50, (int) $z[1], 'Overlay phải nằm trên header dính z-50.');

        // Form nằm TRỰC TIẾP trong overlay, và bên trong nó có vùng cuộn riêng.
        $form = $xpath->query("//form[@x-show='formOpen']")->item(0);
        $this->assertNotNull($form);
        $this->assertSame($overlay, $form->parentNode, 'Form phải là con trực tiếp của overlay.');

        $scroller = $form->firstElementChild;
        $this->assertStringContainsString('overflow-y-auto', $scroller->getAttribute('class'));

        // Nút quay lại ở header: `type="button"` để bấm không phát sinh request.
        $back = $xpath->query("//*[@data-testid='close-transaction-overlay']");
        $this->assertSame(1, $back->length, 'Overlay phải có nút quay lại.');

        $backButton = $back->item(0);
        $this->assertSame('button', $backButton->getAttribute('type'));
        $this->assertStringContainsString('Quay lại', $backButton->textContent);

        // `@click` không đọc được qua DOM: libxml bỏ attribute bắt đầu bằng `@`.
        $this->assertStringContainsString('@click="closeForm()"', $html);

        // Escape đóng được overlay.
        $this->assertStringContainsString('@keydown.escape.window="if (formOpen) closeForm()"', $html);

        // ---- Ràng buộc iPhone Safari ----

        // `dvh` là viewport động, `max-h` chặn tràn; `overscroll-contain` chặn
        // cuộn lan (scroll chaining) sang trang phía sau.
        $this->assertStringContainsString('max-h-[100dvh]', $classes);
        $this->assertStringContainsString('overscroll-contain', $classes);
        $this->assertStringContainsString('overscroll-y-contain', $scroller->getAttribute('class'));

        // `100vh` chỉ được làm DỰ PHÒNG cho Safari cũ chưa biết `dvh`; nguồn
        // chính phải là `100dvh` (khai báo sau, nên thắng).
        $style = $overlay->getAttribute('style');
        $this->assertStringContainsString('height: 100vh', $style);
        $this->assertMatchesRegularExpression('/height:\s*100vh;\s*height:\s*100dvh;/', $style);

        // Header nằm NGOÀI vùng cuộn ⇒ không bao giờ bị cuộn đi.
        $header = $xpath->query("//*[@data-testid='close-transaction-overlay']/ancestor::div[1]")->item(0);
        $this->assertSame(
            0,
            $xpath->query("ancestor::div[contains(@class,'overflow-y-auto')]", $header)->length,
            'Header không được nằm trong vùng cuộn.'
        );

        // Footer nằm NGOÀI vùng cuộn và có đệm safe-area để Safari không che nút Lưu.
        $save = $xpath->query("//*[@data-testid='save-transaction-button']")->item(0);
        $this->assertNotNull($save);
        $this->assertNotSame(
            $scroller,
            $save->parentNode,
            'Nút lưu phải nằm ngoài vùng cuộn để luôn thấy.'
        );
        $this->assertSame(
            1,
            $xpath->query("ancestor::div[contains(@style,'safe-area-inset-bottom')]", $save)->length,
            'Thanh nút phải đệm safe-area-inset-bottom cho iPhone.'
        );

        // Khoá cuộn phía sau: `overflow: hidden` đơn thuần KHÔNG đủ trên iOS
        // Safari, phải ghim body bằng `position: fixed` và nhớ vị trí cuộn.
        $this->assertMatchesRegularExpression(
            '/lockPageScroll\(\)\s*\{.*?savedScrollY = window\.scrollY.*?position = \'fixed\';/s',
            $html,
            'Khoá cuộn phải ghim body bằng position:fixed, không chỉ overflow:hidden.'
        );
        $this->assertMatchesRegularExpression(
            '/unlockPageScroll\(\)\s*\{.*?window\.scrollTo\(0, this\.savedScrollY/s',
            $html,
            'Mở khoá phải trả lại đúng vị trí cuộn trước khi khoá.'
        );

        // Thông báo lưu thành công phải còn thấy được sau khi overlay đã đóng,
        // nên nó nằm ở tầng trang chứ không nằm trong form.
        $this->assertStringContainsString('data-testid="transaction-notice"', $html);
    }

    #[Test]
    public function saving_a_transaction_closes_the_overlay_and_refreshes_the_overview(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Lưu xong phải đóng màn hình nổi rồi mới nạp lại số liệu Tổng quan.
        $this->assertMatchesRegularExpression(
            '/closeForm\(\);\s*this\.notice = .*?await this\.refreshOverview\(\);/s',
            $html,
            'Lưu giao dịch phải đóng overlay rồi refresh Tổng quan.'
        );

        // `closeForm()` phải mở khoá cuộn trang, nếu không trang phía sau bị kẹt.
        $this->assertMatchesRegularExpression(
            '/closeForm\(\)\s*\{.*?unlockPageScroll\(\);/s',
            $html
        );
        $this->assertMatchesRegularExpression('/openForm\(\)\s*\{.*?lockPageScroll\(\);/s', $html);
    }

    #[Test]
    public function the_overview_offers_a_usable_transaction_card(): void
    {
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB chính',
            'status' => UserCard::STATUS_ACTIVE,
            'card_number_last4' => '9876',
        ]);
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ đã đóng',
            'status' => UserCard::STATUS_INACTIVE,
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $state = $this->overviewState($html);

        // Ô chọn thẻ của form CHỈ có thẻ còn nhận giao dịch.
        $this->assertCount(1, $state['cards']);
        $this->assertSame('Thẻ VCB chính', $state['cards'][0]['name']);
        $this->assertSame('9876', $state['cards'][0]['last4']);

        // Danh sách thẻ bên dưới vẫn hiện thẻ đã đóng để xem lịch sử.
        $this->assertStringContainsString('Thẻ đã đóng', $html);
    }

    #[Test]
    public function the_transaction_card_options_never_show_a_full_card_number(): void
    {
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB chính',
            'status' => UserCard::STATUS_ACTIVE,
            'card_number_last4' => '9876',
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $card = $this->overviewState($html)['cards'][0];

        // Payload chỉ mang 4 số cuối — không có `card_number`/`card_number_masked`.
        // Thêm `period_start`/`period_end` để ô ngày giới hạn theo kỳ của thẻ.
        $this->assertSame(
            ['id', 'name', 'bank', 'last4', 'period_start', 'period_end'],
            array_keys($card),
        );
        $this->assertSame('9876', $card['last4']);
        $this->assertArrayNotHasKey('card_number', $card);
    }

    #[Test]
    public function an_empty_overview_invites_the_user_to_manage_cards(): void
    {
        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('Bạn chưa thêm thẻ tín dụng nào.', $html);

        // Không có thẻ thì không hiện nút nhập giao dịch chết; dẫn sang Quản lý thẻ.
        $this->assertStringNotContainsString('data-testid="add-transaction-button"', $html);
        $this->assertStringContainsString(route('credit-cards.manage'), $html);
    }

    #[Test]
    public function the_card_list_links_to_each_cards_history(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB chính',
            'status' => UserCard::STATUS_ACTIVE,
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="card-history-link"', $html);
        $this->assertStringContainsString(route('credit-cards.transactions', ['userCard' => $card->id]), $html);
    }

    #[Test]
    public function the_card_list_hides_the_bank_name_but_keeps_the_last_four(): void
    {
        $bank = $this->makeBank(['name' => 'Ngân hàng ABC', 'slug' => 'ngan-hang-abc']);
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB chính',
            'status' => UserCard::STATUS_ACTIVE,
            'bank_id' => $bank->id,
            'card_number_last4' => '9876',
        ]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Ô CHỌN thẻ vẫn giữ ngân hàng vì cần để phân biệt khi có nhiều thẻ —
        // nó đi vào trang qua payload Alpine.
        $this->assertSame('Ngân hàng ABC', $this->overviewState($html)['cards'][0]['bank']);

        // Danh sách thẻ không lặp tên ngân hàng (trùng giữa các thẻ, không giúp
        // gì trên điện thoại); chỉ giữ tên thẻ + 4 số cuối.
        $this->assertStringContainsString('•••• 9876', $html);
        $this->assertStringNotContainsString('Ngân hàng ABC', $html);
    }

    #[Test]
    public function the_overview_requires_authentication(): void
    {
        $this->get('/thetindung')->assertRedirect(route('login'));
    }

    // =====================================================================
    // Nhập giao dịch từ form Tổng quan
    // =====================================================================

    #[Test]
    public function a_transaction_cannot_be_created_without_sending_a_date(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1200000',
            'note' => 'Cà phê sáng',
        ])->assertStatus(422)->assertJsonValidationErrors('transaction_date');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_transaction_lands_in_the_current_statement_period(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        // Ngày hợp lệ bất kỳ trong kỳ hiện tại — không nhất thiết hôm nay.
        $date = $this->dateInsideCurrentPeriod($card, -3);

        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'transaction_date' => $date->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1200000',
            'note' => 'Cà phê sáng',
        ]);

        $response->assertCreated();

        $transaction = Transaction::findOrFail($response->json('data.id'));

        $this->assertSame($date->toDateString(), $transaction->transaction_date->toDateString());
        $this->assertSame(Transaction::SOURCE_MANUAL, $transaction->source);
        $this->assertSame('Cà phê sáng', $transaction->note);

        // Giao dịch tự gắn vào kỳ sao kê HIỆN TẠI của chính thẻ đó.
        $period = StatementPeriod::query()->where('user_card_id', $card->id)->firstOrFail();
        $this->assertSame($period->id, (int) $transaction->statement_period_id);
        $this->assertTrue($period->contains($date));
    }

    #[Test]
    public function a_transaction_from_any_past_date_is_accepted(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 10]);
        $category = $this->makeSystemCategory();

        // Kỳ hiện tại của thẻ chốt hàng 10. Ngày ở kỳ đã qua vẫn phải nhập được:
        // `statement_period_id` do `StatementPeriodService` suy ra, không phải do
        // người dùng chọn, nên form KHÔNG khoá ngày theo kỳ hiện tại.
        $past = CarbonImmutable::now()->subMonths(2)->startOfMonth();

        $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'transaction_date' => $past->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1200000',
        ])->assertCreated();

        $transaction = Transaction::query()->sole();
        $this->assertSame($past->toDateString(), $transaction->transaction_date->toDateString());

        // Ngày đã chọn tự rơi vào KỲ SAO KẾ chứa nó — đúng logic sẵn có.
        $period = $transaction->statementPeriod;
        $this->assertNotNull($period);
        $this->assertTrue($period->contains($past));
    }

    #[Test]
    public function a_negative_amount_is_rejected_by_the_manual_form(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        // Số âm là hoàn tiền và thuộc luồng import, không phải form nhập tay.
        $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'transaction_date' => $this->dateInsideCurrentPeriod($card, 0)->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '-50000',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * Ngày nằm trong kỳ sao kê hiện tại của thẻ, lệch `offset` ngày so với hôm nay.
     *
     * Tính bằng chính ranh giới mà `StatementPeriodService` dùng nên test không
     * phụ thuộc việc thẻ dùng `statement_day` nào.
     */
    private function dateInsideCurrentPeriod(UserCard $card, int $offset = 0): CarbonImmutable
    {
        [$start, $end] = app(StatementPeriodService::class)->currentBoundaries(
            $card,
            CarbonImmutable::now(),
        );

        $date = CarbonImmutable::now()->addDays($offset);

        // Kẹp vào trong khoảng hợp lệ nếu offset trượt ra ngoài.
        if ($date->lessThan($start)) {
            $date = $start;
        }

        if ($date->greaterThan($end)) {
            $date = $end;
        }

        return $date;
    }

    #[Test]
    public function a_new_transaction_moves_the_overview_numbers(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => '1000000']);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'transaction_date' => $this->dateInsideCurrentPeriod($card, 0)->toDateString(),
                'user_card_id' => $card->id,
                'category_id' => $category->id,
                'amount' => '1000000',
            ])
            ->assertCreated();

        $meta = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk()
            ->json('meta');

        // `total_spend` là CHI TIÊU THỰC TẾ nên nhảy theo giao dịch vừa lưu.
        $this->assertSame('1000000.00', $meta['summary']['total_spend']);

        // `expected_cashback` là con số THEO MỤC TIÊU: 1.000.000 × 5% = 50.000.
        // Nó không đổi khi thêm/bớt giao dịch — đó là điểm của việc tách nó khỏi
        // tiền thực tế.
        $this->assertSame('50000.00', $meta['summary']['expected_cashback']);
        $this->assertSame(
            '50000.00',
            $meta['card_metrics'][(string) $card->id]['expected_cashback'],
        );
    }

    #[Test]
    public function a_cashback_field_in_the_overview_form_payload_is_still_ignored(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.transactions.store'), [
            'transaction_date' => $this->dateInsideCurrentPeriod($card, 0)->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1000000',
            'cashback_amount' => 999999999,
            'cashback_percent' => 99,
        ]);

        $response->assertCreated();
        $this->assertEquals(50000.0, $response->json('data.cashback_amount'));
    }

    #[Test]
    public function a_zero_amount_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'category_id' => $category->id,
                'amount' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function the_form_cannot_record_a_transaction_on_another_users_card(): void
    {
        $foreignCard = $this->makeUserCard($this->stranger->id);
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $foreignCard->id,
                'category_id' => $category->id,
                'amount' => '1000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_card_id');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function the_form_cannot_use_another_users_category(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $foreignCategory = $this->makeUserCategory($this->stranger->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'category_id' => $foreignCategory->id,
                'amount' => '1000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function the_overview_only_offers_the_users_own_categories(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);
        $this->makeSystemCategory(['name' => 'Ăn uống hệ thống']);
        $this->makeUserCategory($this->owner->id, ['name' => 'Du lịch của tôi']);
        $this->makeUserCategory($this->stranger->id, ['name' => 'Sở thích của người khác']);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();
        $names = array_column($this->overviewState($html)['categories'], 'name');

        // Danh mục hệ thống + danh mục RIÊNG của user; không có của người khác.
        // `assertEqualsCanonicalizing` vì `sort()` so sánh theo byte nên tiếng Việt
        // có dấu không sort như mong đợi.
        $this->assertEqualsCanonicalizing(['Ăn uống hệ thống', 'Du lịch của tôi'], $names);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Bóc payload Alpine của trang Tổng quan ra mảng thật.
     *
     * `@js()` phát ra `JSON.parse('…')` với chuỗi đã escape HAI lớp (Laravel escape
     * `\` và `"` để an toàn trong string literal của JS), nên phải giải mã hai
     * lần: string literal → văn bản JSON → mảng. Assert thẳng lên `$html` sẽ
     * so sánh chuỗi đã escape (`Th\\u1ebb`) và luôn fail.
     *
     * @return array<string, mixed>
     */
    private function overviewState(string $html): array
    {
        $this->assertSame(
            1,
            preg_match("/x-data=\"creditCardOverview\(JSON\.parse\('(.*)'\)\)\"/", $html, $matches),
            'Không tìm thấy payload Alpine của trang Tổng quan.'
        );

        $json = json_decode('"'.$matches[1].'"', true);
        $this->assertIsString($json, 'Chuỗi JS của payload phải giải mã được.');

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, 'Payload Alpine phải decode được thành JSON hợp lệ.');

        return $decoded;
    }

    /**
     * Kỳ `open` chứa HÔM NAY — đúng định nghĩa "kỳ hiện tại" của module
     * (`StatementPeriod::contains()`).
     */
    private function makeCurrentPeriod(UserCard $card, array $attributes = []): StatementPeriod
    {
        $today = CarbonImmutable::now();

        return $this->makeStatementPeriod($card, array_merge([
            'period_start' => $today->subDays(10)->toDateString(),
            'period_end' => $today->addDays(10)->toDateString(),
            'statement_date' => $today->addDays(10)->toDateString(),
            'payment_due_date' => $today->addDays(20)->toDateString(),
            'status' => StatementPeriod::STATUS_OPEN,
        ], $attributes));
    }

    private function createTransaction(
        UserCard $card,
        $category,
        string $amount,
        int $periodId,
        string $date,
    ): Transaction {
        $transaction = app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $date,
            'amount' => $amount,
            'category_id' => $category->id,
            'statement_period_id' => $periodId,
        ]);

        // `CreditCardTransactionService` tự resolve kỳ theo ngày giao dịch; test
        // này cố ý ép kỳ để kiểm tra đúng việc "chỉ tính kỳ hiện tại".
        $transaction->forceFill(['statement_period_id' => $periodId])->save();

        return $transaction->fresh();
    }
}
