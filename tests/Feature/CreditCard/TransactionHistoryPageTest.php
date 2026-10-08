<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang lịch sử giao dịch của một thẻ — /thetindung/the/{userCard}/giao-dich.
 *
 * Bốn bất biến được khoá ở đây:
 *   1. Chỉ thấy giao dịch của CHÍNH THẺ đang mở — không lẫn thẻ khác, không lẫn
 *      user khác (kể cả khi đoán đúng id).
 *   2. Chỉ sửa được 4 ô: ngày · số tiền · danh mục · ghi chú. KHÔNG đổi thẻ, KHÔNG
 *      đổi `statement_period_id` — kỳ do ngày quyết định, không chọn tay.
 *   3. Giao dịch trong kỳ đã chốt là bản ghi lịch sử: sửa và xoá đều bị chặn.
 *   4. Sửa đi qua API `update` nên cashback được engine tính lại và kỳ cũ/kỳ mới
 *      được tính lại cùng lúc — trang không tự tính bất cứ thứ gì.
 */
class TransactionHistoryPageTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        // `setUpCreditCardTestCase()` tự gọi `parent::setUp()`.
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    // =====================================================================
    // Phạm vi dữ liệu
    // =====================================================================

    #[Test]
    public function it_lists_the_transactions_of_the_opened_card(): void
    {
        $card = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();

        $this->createTransaction($card, $category, '45000', 'Cà phê sáng');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cà phê sáng', $html);
        $this->assertStringContainsString('45.000', $html);
        $this->assertStringContainsString('data-testid="transaction-row"', $html);
        $this->assertStringContainsString('1 giao dịch', $html);
    }

    #[Test]
    public function it_never_shows_transactions_of_another_card(): void
    {
        $first = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A']);
        $second = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);
        $category = $this->makeSystemCategory();

        $this->createTransaction($first, $category, '45000', 'Giao dịch của thẻ A');
        $this->createTransaction($second, $category, '99000', 'Giao dịch của thẻ B');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $first->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Giao dịch của thẻ A', $html);
        $this->assertStringNotContainsString('Giao dịch của thẻ B', $html);
        $this->assertStringContainsString('1 giao dịch', $html);
    }

    #[Test]
    public function another_users_card_is_not_found(): void
    {
        $card = $this->makeUserCard($this->stranger->id);

        // 404 chứ không phải 403: không lộ ra việc id tồn tại.
        $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertNotFound();
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function an_empty_history_points_back_to_the_overview(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Thẻ này chưa có giao dịch nào.', $html);
        $this->assertStringContainsString('Nhập giao dịch ở Tổng quan', $html);
    }

    // =====================================================================
    // Bộ lọc
    // =====================================================================

    #[Test]
    public function it_can_be_filtered_by_period(): void
    {
        $card = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();

        $today = CarbonImmutable::now();
        $oldDate = $today->subMonths(2)->startOfMonth()->addDays(2);
        $oldPeriod = $this->createTransaction($card, $category, '45000', 'Kỳ cũ', $oldDate)->statementPeriod;
        $this->createTransaction($card, $category, '99000', 'Kỳ này');

        $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', [
                'userCard' => $card->id,
                'period_id' => $oldPeriod->id,
            ]))
            ->assertOk()
            ->assertSee('Kỳ cũ')
            ->assertDontSee('Kỳ này');
    }

    #[Test]
    public function a_period_of_another_card_cannot_be_used_as_a_filter(): void
    {
        $mine = $this->cardWithTransactions();
        $other = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();

        $this->createTransaction($other, $category, '99000', 'Kỳ của thẻ khác');

        $otherPeriod = StatementPeriod::query()
            ->where('user_card_id', $other->id)
            ->orderByDesc('period_start')
            ->firstOrFail();

        // `period_id` phải là kỳ của CHÍNH thẻ đang mở, nên bị từ chối.
        $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', [
                'userCard' => $mine->id,
                'period_id' => $otherPeriod->id,
            ]))
            ->assertSessionHasErrors('period_id');
    }

    // =====================================================================
    // Sửa
    // =====================================================================

    #[Test]
    public function it_offers_exactly_the_four_editable_fields(): void
    {
        $card = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();
        $transaction = $this->createTransaction($card, $category, '45000', 'Cà phê');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="edit-transaction"', $html);
        $this->assertStringContainsString('x-model="form.transaction_date"', $html);
        $this->assertStringContainsString('x-model="form.amount"', $html);
        $this->assertStringContainsString('x-model="form.category_id"', $html);
        $this->assertStringContainsString('x-model="form.note"', $html);

        // KHÔNG cho đổi thẻ và KHÔNG cho chọn kỳ sao kê tay: kỳ do ngày quyết định.
        $this->assertStringNotContainsString('x-model="form.user_card_id"', $html);
        $this->assertStringNotContainsString('x-model="form.statement_period_id"', $html);

        // Không có ô nhập cashback: engine tính, user không gõ.
        $this->assertStringNotContainsString('x-model="form.cashback', $html);

        $this->assertSame(
            $transaction->id,
            $this->rowId($html),
            'Form sửa phải gắn với đúng giao dịch.',
        );
    }

    #[Test]
    public function editing_a_transaction_recalculates_the_period_totals(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTransactions($category);

        $transaction = $this->createTransaction($card, $category, '1000000', 'Trước khi sửa');

        // Assert theo cột ENGINE ghi (`total_eligible_spend`), không phải tổng
        // chi tiêu tự cộng tay.
        $this->assertSame('1000000.00', (string) $this->currentPeriodOf($card)->total_eligible_spend);
        $this->assertSame('50000.00', (string) $this->currentPeriodOf($card)->total_cashback);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.transactions.update', $transaction->id), [
                'amount' => '2500000',
                'note' => 'Sau khi sửa',
            ])
            ->assertOk()
            // JSON trả số nguyên khi giá trị không có phần thập ⇒ assertJsonPath
            // dùng so sánh chặt, phải trùng kiểu.
            ->assertJsonPath('data.amount', 2500000)
            ->assertJsonPath('data.note', 'Sau khi sửa');

        // Kỳ được tính lại (không cộng dồn 1.000.000 + 2.500.000).
        $this->assertSame('2500000.00', (string) $this->currentPeriodOf($card)->total_eligible_spend);
        $this->assertSame('125000.00', (string) $this->currentPeriodOf($card)->total_cashback);
        $this->assertSame('2500000.00', (string) $transaction->refresh()->amount);
    }

    #[Test]
    public function editing_a_date_to_any_past_date_moves_it_to_that_statement_period(): void
    {
        $card = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();
        $transaction = $this->createTransaction($card, $category, '45000', 'Cà phê');

        // Ngày ở kỳ đã qua vẫn sửa được — ngày giao dịch độc lập với kỳ hiện tại.
        $past = CarbonImmutable::now()->subMonths(2)->startOfMonth();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.transactions.update', $transaction->id), [
                'transaction_date' => $past->toDateString(),
            ])
            ->assertOk();

        $transaction->refresh();

        $this->assertSame($past->toDateString(), $transaction->transaction_date->toDateString());

        // Kỳ được suy ra lại từ ngày mới, không phải kỳ cũ.
        $period = $transaction->statementPeriod;
        $this->assertNotNull($period);
        $this->assertTrue($period->contains($past));
    }

    #[Test]
    public function editing_a_transaction_to_a_negative_amount_is_rejected(): void
    {
        $card = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();
        $transaction = $this->createTransaction($card, $category, '45000', 'Cà phê');

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.transactions.update', $transaction->id), ['amount' => '-45000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame('45000.00', (string) $transaction->refresh()->amount);
    }

    #[Test]
    public function another_user_cannot_edit_a_transaction(): void
    {
        $card = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();
        $transaction = $this->createTransaction($card, $category, '45000', 'Cà phê');

        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.transactions.update', $transaction->id), ['amount' => '1'])
            ->assertForbidden();

        $this->assertSame('45000.00', (string) $transaction->refresh()->amount);
    }

    // =====================================================================
    // Kỳ đã chốt
    // =====================================================================

    #[Test]
    public function a_transaction_of_a_finalized_period_cannot_be_edited_or_deleted(): void
    {
        $card = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();
        $transaction = $this->createTransaction($card, $category, '45000', 'Cà phê');

        $transaction->statementPeriod->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])->save();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.transactions.update', $transaction->id), ['amount' => '1'])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.transactions.destroy', $transaction->id))
            ->assertForbidden();

        $this->assertSame('45000.00', (string) $transaction->refresh()->amount);

        // UI cũng khoá nút và nói rõ lý do, không chỉ im lặng.
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-testid="edit-transaction"', $html);
        $this->assertStringContainsString('Kỳ đã chốt bảng kê', $html);
    }

    #[Test]
    public function a_deleted_transaction_is_recalculated_out_of_the_period(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTransactions($category);

        $first = $this->createTransaction($card, $category, '1000000', 'Giữ lại');
        $second = $this->createTransaction($card, $category, '2000000', 'Xoá đi');

        // 3.000.000 × 5% = 150.000.
        $this->assertSame('150000.00', (string) $this->currentPeriodOf($card)->total_cashback);

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.transactions.destroy', $second->id))
            ->assertOk();

        // Còn 1.000.000 × 5% = 50.000.
        $this->assertSame('1000000.00', (string) $this->currentPeriodOf($card)->total_eligible_spend);
        $this->assertSame('50000.00', (string) $this->currentPeriodOf($card)->total_cashback);
    }

    // =====================================================================
    // Bảo mật hiển thị
    // =====================================================================

    #[Test]
    public function it_never_shows_a_full_card_number(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['card_number_last4' => '9876']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('•••• 9876', $html);
        // Module không lưu số thẻ đầy đủ ở đâu cả; chặn thêm ở tầng trình bày.
        $this->assertStringNotContainsString('card_number"', $html);
    }

    #[Test]
    public function it_does_not_leak_cashback_calculation_internals(): void
    {
        $card = $this->cardWithTransactions();
        $category = $this->makeSystemCategory();
        $this->createTransaction($card, $category, '45000', 'Cà phê');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        // Trang hiện snapshot engine đã ghi, không hiện %/rule/tier.
        $this->assertStringNotContainsString('cashback_percent', $html);
        $this->assertStringNotContainsString('policy_tier', $html);
    }

    #[Test]
    public function a_row_prints_each_amount_with_exactly_one_currency_suffix(): void
    {
        // Truyền CHÍNH danh mục mà policy gắn rule: giao dịch trên danh mục khác
        // sẽ không eligible, cashback = 0 và dòng "Hoàn tiền" không render — test
        // sẽ pass nhầm vì chỉ kiểm tra số tiền giao dịch.
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTransactions($category);

        // 7.310.000 × 5% = 365.500 ⇒ có cashback để dòng "Hoàn tiền" hiện ra.
        $this->createTransaction($card, $category, '7310000', 'Ăn mừng');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $text = $this->visibleTextOf($html);

        $this->assertStringContainsString('Hoàn tiền 365.500 đ', $text);
        $this->assertStringContainsString('7.310.000 đ', $text);

        // `x-credit-card.money` đã tự render hậu tố. Nối thêm " đ" ở chỗ gọi sẽ ra
        // "365.500 đ đ" — assert trên HTML thô không bắt được vì "đ đ" là text node
        // nằm xen giữa các thẻ <span>, nên phải bóc thẻ rồi mới thấy.
        $this->assertStringNotContainsString('đ đ', $text);

        // Và không chỗ nào trong view được tự nối hậu tố ngoài component nữa.
        $this->assertStringNotContainsString(
            '/> đ',
            $html,
            'Chỗ gọi `x-credit-card.money` không được nối thêm " đ" — component đã có sẵn.',
        );
    }

    // =====================================================================
    // Dòng lịch sử: danh mục · ghi chú · cập nhật tại chỗ
    // =====================================================================

    #[Test]
    public function the_category_name_is_printed_on_the_transaction_row(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Nhà hàng Padaria']);
        $card = $this->cardWithTransactions($category);

        $this->createTransaction($card, $category, '45000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        // Regex bám `data-field="category"` để không khớp nhầm `<option>` trong
        // form sửa — option cũng in đúng tên danh mục này.
        $this->assertMatchesRegularExpression(
            '/data-field="category"[^>]*>\s*Nhà hàng Padaria\s*</u',
            $html,
        );
    }

    #[Test]
    public function the_note_is_printed_on_the_transaction_row(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTransactions($category);

        $this->createTransaction($card, $category, '45000', 'Cà phê sáng');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-field="note"[^>]*>\s*Cà phê sáng\s*</u',
            $html,
        );
    }

    #[Test]
    public function a_transaction_without_a_note_keeps_the_note_element_hidden(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTransactions($category);

        $this->createTransaction($card, $category, '45000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        // Giữ sẵn phần tử ẩn để `renderRow()` điền vào sau khi lưu — không in
        // placeholder chữ "Không có ghi chú" user sẽ thấy mãi.
        $this->assertStringContainsString('data-field="note" hidden', $html);
        $this->assertStringNotContainsString('Không có ghi chú', $html);
    }

    #[Test]
    public function deleting_a_transaction_removes_its_row_and_the_count_without_reloading(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->cardWithTransactions($category);

        $keep = $this->createTransaction($card, $category, '45000', 'Giữ lại');
        $remove = $this->createTransaction($card, $category, '99000', 'Xoá đi');

        $before = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('2 giao dịch', $before);
        $this->assertStringContainsString('data-transaction-id="'.$remove->id.'"', $before);

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.transactions.destroy', $remove->id))
            ->assertOk();

        $after = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1 giao dịch', $after);
        $this->assertStringNotContainsString('2 giao dịch', $after);
        $this->assertStringNotContainsString('data-transaction-id="'.$remove->id.'"', $after);
        $this->assertStringContainsString('data-transaction-id="'.$keep->id.'"', $after);

        // Trang tự gỡ dòng + trừ bộ đếm tại chỗ: wiring có đủ và KHÔNG có
        // lệnh reload cả trang.
        $this->assertStringContainsString('renderRow(', $after);
        $this->assertStringContainsString('x-text="total + \' giao dịch\'"', $after);
        $this->assertStringContainsString('this.total = Math.max(0, Number(this.total) - 1)', $after);
        $this->assertStringNotContainsString('window.location.reload', $after);
    }

    #[Test]
    public function the_update_payload_is_complete_and_editable_flips_when_the_period_is_finalized(): void
    {
        $category = $this->makeSystemCategory();
        $other = $this->makeSystemCategory();
        $card = $this->cardWithTransactions($category);
        $transaction = $this->createTransaction($card, $category, '45000', 'Ghi chú cũ');

        // Payload phải trả đủ khoá mà row dùng để gộp ngược sau khi lưu:
        // `category_id` mở lại form, `category_name` in dòng, `editable` nút Sửa.
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.transactions.update', $transaction->id), [
                'note' => 'Ghi chú sau sửa',
                'category_id' => $other->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.category_id', $other->id)
            ->assertJsonPath('data.category_name', $other->name)
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.note', 'Ghi chú sau sửa');

        // Trang render lại là thấy NGAY dữ liệu vừa lưu.
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-field="category"[^>]*>\s*'.preg_quote($other->name, '/').'\s*</u',
            $html,
        );
        $this->assertMatchesRegularExpression('/data-field="note"[^>]*>\s*Ghi chú sau sửa\s*</u', $html);

        // Kỳ chốt ⇒ `editable` false: payload API nói đúng và trang không còn nút Sửa.
        $transaction->statementPeriod->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])->save();

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.transactions.index', ['userCard' => $card->id]))
            ->assertOk()
            ->assertJsonPath('data.0.editable', false);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-testid="edit-transaction"', $html);
        $this->assertStringContainsString('Kỳ đã chốt bảng kê', $html);
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * CHỮ NGƯỜI DÙNG THẬT SỰ THẤY trong HTML: bóc thẻ, giải thực thể rồi gộp
     * khoảng trắng.
     *
     * `&nbsp;` đổi về space thường để kỳ vọng viết được như người đọc — hành vi
     * "không đứt dòng" của nó do component lo, không thuộc test này.
     */
    private function visibleTextOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Thẻ đã có policy 5% và MỘT kỳ hiện tại đã gắn version (tạo qua pipeline
     * `create()` nên trạng thái khớp lúc chạy thật).
     *
     * `$category` phải là danh mục MÀ policy gắn rule, nếu không giao dịch sẽ
     * không eligible và cashback luôn bằng 0 — test sẽ pass nhầm vì chỉ kiểm tra
     * số tiền.
     */
    private function cardWithTransactions($category = null): UserCard
    {
        $category ??= $this->makeSystemCategory();

        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => 5000000]);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => 1000000]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        // Giao dịch "mồi" tạo kỳ + gắn policy, rồi xoá để lịch sử bắt đầu sạch.
        $seed = $this->createTransaction($card, $category, '1', null);
        app(CreditCardTransactionService::class)->delete($this->owner->id, $seed->id);

        return $card->refresh();
    }

    private function createTransaction(
        UserCard $card,
        $category,
        string $amount,
        ?string $note = null,
        ?CarbonImmutable $date = null,
    ): Transaction {
        $date ??= $this->dateInsideCurrentPeriod($card);

        return app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => $date->toDateString(),
            'amount' => $amount,
            'category_id' => $category->id,
            'note' => $note,
        ]);
    }

    private function dateInsideCurrentPeriod(UserCard $card): CarbonImmutable
    {
        [$start, $end] = app(StatementPeriodService::class)->currentBoundaries(
            $card,
            CarbonImmutable::now(),
        );

        $date = CarbonImmutable::now();

        if ($date->lessThan($start)) {
            $date = $start;
        }

        if ($date->greaterThan($end)) {
            $date = $end;
        }

        return $date;
    }

    /** Id giao dịch đầu tiên mà trang render ra. */
    private function rowId(string $html): int
    {
        $this->assertSame(
            1,
            preg_match('/data-testid="transaction-row"\s+data-transaction-id="(\d+)"/', $html, $matches),
            'Không tìm thấy dòng giao dịch trong HTML.',
        );

        return (int) $matches[1];
    }

    /**
     * Kỳ sao kê hiện tại, đọc THẲNG từ DB.
     *
     * Không dùng `$transaction->statementPeriod` vì quan hệ đó đã được nạp trước
     * khi engine ghi tổng của kỳ, nên model trong bộ nhớ vẫn còn `total_spend`
     * cũ (rỗng) và assert sẽ đo sai.
     */
    private function currentPeriodOf(UserCard $card): StatementPeriod
    {
        return StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->open()
            ->orderByDesc('period_start')
            ->firstOrFail();
    }
}
