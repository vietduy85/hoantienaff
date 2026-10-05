<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardStatementService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Sao kê thực tế — /thetindung/sao-ke.
 *
 * Bảy bất biến được khoá ở đây:
 *   1. MỞ TRANG KHÔNG ĐƯỢC GHI. Thẻ chưa có kỳ vẫn hiện dòng, nhưng mở trang
 *      không được sinh bản ghi kỳ — xem `CreditCardModuleTest` cho cùng nguyên tắc.
 *   2. MỖI THẺ MỘT DÒNG, kể cả thẻ chưa nhập sao kê: để so sánh được ngay.
 *   3. CHỈ THẺ CỦA CHÍNH MÌNH. Không thấy thẻ người khác kể cả khi đoán đúng id.
 *   4. MỖI CẶP (THẺ, KỲ) MỘT DÒNG. Nhập lại ghi đè, không sinh dòng thứ hai.
 *   5. `closing_balance` LUÔN DO SERVER TÍNH. Client gửi lên cũng bị bỏ qua.
 *   6. SỬA THEO TỪNG PHẦN. Sửa riêng `actual_reward` không được làm
 *      `actual_spend` rơi về 0 — mất tiền thật.
 *   7. KỲ ĐÃ CHỐT là bản ghi lịch sử: sửa và xoá đều bị chặn, và UI nói rõ lý do.
 *
 * Sao kê thực tế KHÁC lịch sử giao dịch: nó không chạy engine cashback, nên test
 * này cố tình KHÔNG dựng policy/tier/rule cho thẻ — nếu sao kê có chạm vào
 * engine, số liệu ở đây đã sai và test sẽ đỏ.
 */
class StatementPageTest extends TestCase
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
    // Quyền truy cập
    // =====================================================================

    #[Test]
    public function it_requires_authentication(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->get(route('credit-cards.statements'))
            ->assertRedirect(route('login'));

        $this->getJson(route('credit-cards.api.statements.index'))
            ->assertUnauthorized();

        $this->postJson(route('credit-cards.api.statements.store', $card->id), [
            'actual_spend' => '1000',
            'actual_reward' => '0',
        ])->assertUnauthorized();
    }

    #[Test]
    public function it_never_lists_another_users_card(): void
    {
        $mine = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ của tôi']);
        $theirs = $this->makeUserCard($this->stranger->id, ['name' => 'Thẻ của người khác']);

        $this->createStatement($mine, '1000000', '50000');
        $this->createStatement($theirs, '9000000', '450000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Thẻ của tôi', $html);
        $this->assertStringNotContainsString('Thẻ của người khác', $html);
        $this->assertStringNotContainsString('9.000.000', $html);

        // API phải khoá cùng phạm vi với HTML — cùng một hàm dựng dữ liệu.
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Thẻ của tôi');
    }

    #[Test]
    public function another_user_cannot_write_a_statement_of_my_card(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        // 422 chứ không phải 403: `bootstrap/app.php` cố ý ánh xạ
        // `InvalidArgumentException` của service thành 422 cho `credit-cards.api.*`
        // — "thẻ của người khác" là dữ liệu sai, còn 403 dành cho đã xác định
        // được quyền rồi mới bị từ chối. Cùng cách với thẻ/sao kê khác trong module.
        $this->actingAs($this->stranger)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '1',
                'actual_reward' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Thẻ tín dụng không tồn tại hoặc không thuộc về bạn.');

        $this->assertSame(0, CreditCardStatement::query()->count());
    }

    // =====================================================================
    // Mở trang không được ghi
    // =====================================================================

    #[Test]
    public function opening_the_page_never_creates_a_statement_period(): void
    {
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A']);
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);

        $this->assertSame(0, StatementPeriod::query()->count());

        $this->actingAs($this->owner)->get(route('credit-cards.statements'))->assertOk();
        $this->actingAs($this->owner)->getJson(route('credit-cards.api.statements.index'))->assertOk();

        $this->assertSame(
            0,
            StatementPeriod::query()->count(),
            'Chỉ đọc thì không được sinh bản ghi kỳ.',
        );
    }

    #[Test]
    public function every_card_gets_a_row_with_its_current_period_even_without_a_statement(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ chưa nhập']);

        [$start, $end] = app(StatementPeriodService::class)->currentBoundaries(
            $card,
            CarbonImmutable::now(),
        );

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // Chưa có bản ghi kỳ, nhưng vẫn phải nói rõ kỳ đang ở trong kỳ nào.
            ->assertJsonPath('data.0.period', null)
                ->assertJsonPath('data.0.statement', null)
                ->assertJsonPath('data.0.period_bounds.start', $start->toDateString())
                ->assertJsonPath('data.0.period_bounds.end', $end->toDateString());
    }

    // =====================================================================
    // Ghi sao kê
    // =====================================================================

    #[Test]
    public function it_stores_the_current_period_and_computes_the_closing_balance(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '5000000',
                'actual_reward' => '250000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.actual_spend', '5000000.00')
            ->assertJsonPath('data.actual_reward', '250000.00')
            // 5.000.000 − 250.000
            ->assertJsonPath('data.closing_balance', '4750000.00');

        // Ghi vào KỲ HIỆN TẠI, và chỉ một dòng cho cặp (thẻ, kỳ).
        $period = StatementPeriod::query()->where('user_card_id', $card->id)->firstOrFail();

        $this->assertSame(1, CreditCardStatement::query()->count());
        $this->assertSame(
            '4750000.00',
            (string) CreditCardStatement::query()->firstOrFail()->closing_balance,
        );
        $this->assertSame($period->id, CreditCardStatement::query()->firstOrFail()->statement_period_id);
    }

    #[Test]
    public function a_closing_balance_sent_by_the_client_is_ignored(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '1000000',
                'actual_reward' => '0',
                // Client tự tính và gửi số không: phải bị bỏ qua.
                'closing_balance' => '0.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.closing_balance', '1000000.00');

        $this->assertSame(
            '1000000.00',
            (string) CreditCardStatement::query()->firstOrFail()->closing_balance,
        );
    }

    #[Test]
    public function storing_twice_updates_the_same_row_instead_of_adding_one(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '1000000',
                'actual_reward' => '0',
            ])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '2000000',
                'actual_reward' => '100000',
            ])
            ->assertOk();

        $this->assertSame(
            1,
            CreditCardStatement::query()->count(),
            'Sửa một kỳ phải ghi đè, không sinh dòng thứ hai.',
        );

        $this->assertSame('2000000.00', (string) CreditCardStatement::query()->firstOrFail()->actual_spend);
    }

    #[Test]
    public function a_reward_greater_than_the_spend_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '1000',
                'actual_reward' => '2000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('actual_reward');

        $this->assertSame(0, CreditCardStatement::query()->count());
    }

    #[Test]
    public function negative_amounts_are_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '-1000',
                'actual_reward' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('actual_spend');
    }

    // =====================================================================
    // Sửa
    // =====================================================================

    #[Test]
    public function editing_only_the_reward_keeps_the_spend(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.statements.update', $statement->id), [
                'actual_reward' => '500000',
            ])
            ->assertOk()
            ->assertJsonPath('data.actual_spend', '5000000.00')
            ->assertJsonPath('data.actual_reward', '500000.00')
            ->assertJsonPath('data.closing_balance', '4500000.00');

        $statement->refresh();

        $this->assertSame('5000000.00', (string) $statement->actual_spend);
    }

    #[Test]
    public function another_user_cannot_edit_or_delete_my_statement(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.statements.update', $statement->id), [
                'actual_spend' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->deleteJson(route('credit-cards.api.statements.destroy', $statement->id))
            ->assertForbidden();

        $this->assertSame('5000000.00', (string) $statement->refresh()->actual_spend);
    }

    #[Test]
    public function it_deletes_a_statement(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.statements.destroy', $statement->id))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, CreditCardStatement::query()->count());

        // Thẻ vẫn còn, chỉ mất dòng sao kê.
        $this->assertNotNull(UserCard::query()->find($card->id));
    }

    // =====================================================================
    // Kỳ đã chốt
    // =====================================================================

    #[Test]
    public function a_statement_of_a_finalized_period_cannot_be_edited_or_deleted(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $statement->statementPeriod
            ->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])
            ->save();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.statements.update', $statement->id), [
                'actual_spend' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.statements.destroy', $statement->id))
            ->assertForbidden();

        $this->assertSame('5000000.00', (string) $statement->refresh()->actual_spend);
        $this->assertSame(1, CreditCardStatement::query()->count());
    }

    #[Test]
    public function a_finalized_period_row_is_locked_and_explains_why(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $statement->statementPeriod
            ->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])
            ->save();

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Nút sửa không còn, lý do nói rõ — không chỉ làm mờ nút.
        $this->assertStringNotContainsString(
            "data-testid=\"statement-edit-{$card->id}\"",
            $html,
        );
        $this->assertStringContainsString('Kỳ đã chốt, không sửa được', $html);
    }

    // =====================================================================
    // Bảo mật hiển thị
    // =====================================================================

    #[Test]
    public function it_never_shows_a_full_card_number(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['card_number_last4' => '9876']);
        $this->createStatement($card, '1000000', '0');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('•••• 9876', $html);
        // Module không lưu số thẻ đầy đủ ở đâu cả; chặn thêm ở tầng trình bày.
        $this->assertStringNotContainsString('card_number"', $html);
    }

    #[Test]
    public function it_does_not_leak_cashback_calculation_internals(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '5000000', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Sao kê thực tế KHÔNG chạy engine: không có %/rule/tier/eligible ở đây.
        $this->assertStringNotContainsString('cashback_percent', $html);
        $this->assertStringNotContainsString('policy_tier', $html);
        $this->assertStringNotContainsString('eligible_spend', $html);
        $this->assertStringNotContainsString('rounding_mode', $html);
    }

    #[Test]
    public function the_browser_never_computes_the_closing_balance_itself(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '5000000', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Không có phép trừ nào giữa hai ô nhập trong JavaScript: `closing_balance` chỉ
        // được đọc từ số server gửi. Hai nơi cùng công thức là chỗ sinh lệch.
        $this->assertStringContainsString(
            'Số tiền còn phải trả sẽ được hệ thống tính',
            $html,
            'Form phải nói trước số dư được tính ở server, không phải ngay trên trang.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/actual_spend[\s\S]{0,240}?\s-\s[\s\S]{0,240}?actual_reward/',
            $this->codeOf($this->scriptOf($html)),
            'Không được tự tính số dư còn phải trả trong trình duyệt.',
        );
    }

    #[Test]
    public function a_row_records_the_statement_id_the_server_just_created(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '5000000', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $script = $this->codeOf($this->scriptOf($html));

        // Sau lần NHẬP đầu tiên, dòng phải nhớ id server vừa cấp. Nếu không ghi,
        // `statementUrl()` vẫn trả POST ở lần sửa kế tiếp và đụng UNIQUE
        // (mỗi cặp thẻ/kỳ chỉ được có một dòng) — tức nhập xong sửa tiếp là lỗi.
        $this->assertMatchesRegularExpression(
            '/closing\.dataset\.statementId\s*=\s*String\(\s*statement\.id/',
            $script,
            'Dòng phải ghi lại id do server trả về sau khi nhập.',
        );
    }

    #[Test]
    public function clearing_a_row_forgets_the_id_so_the_next_entry_posts_again(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '5000000', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $script = $this->codeOf($this->scriptOf($html));

        // Xoá xong mà còn sót id thì lần mở kế tiếp sẽ PATCH tới dòng không còn
        // tồn tại. Ô nhập, nhãn nút và nút "Xoá" cũng phải về trạng thái "chưa
        // nhập" để mô tả đúng dòng.
        $this->assertMatchesRegularExpression(
            '/closing\.dataset\.statementId\s*=\s*\'\'/',
            $script,
            'Xoá sao kê phải xoá luôn id trên DOM.',
        );
        $this->assertMatchesRegularExpression(
            '/input\.value\s*=\s*\'\'/',
            $script,
            'Xoá sao kê phải xoá luôn số đã nhập trong hai ô.',
        );
        $this->assertMatchesRegularExpression(
            '/edit\.textContent\s*=\s*\'Nhập sao kê\'/',
            $script,
            'Nhãn nút phải trở lại "Nhập sao kê".',
        );
        // Regex cố tình không khoá cách viết dấu nháy bên trong selector.
        $this->assertMatchesRegularExpression(
            '/statement-delete-[\s\S]*?\?\.remove\(\)/',
            $script,
            'Nút "Xoá" phải biến mất khi dòng không còn sao kê.',
        );
    }

    #[Test]
    public function the_page_renders_each_amount_with_exactly_one_currency_suffix(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '7310000', '365500');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $text = $this->visibleTextOf($html);

        // 7.310.000 − 365.500 = 6.944.500
        $this->assertStringContainsString('7.310.000', $text);
        $this->assertStringContainsString('365.500', $text);
        $this->assertStringContainsString('6.944.500', $text);

        // "đ đ" là text node xen giữa các thẻ <span> nên phải bóc thẻ mới thấy.
        $this->assertStringNotContainsString('đ đ', $text);
    }

    #[Test]
    public function a_missing_statement_reads_as_not_entered_rather_than_zero(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Chưa có sao kê ≠ sao kê bằng 0.
        $this->assertStringContainsString('Chưa nhập', $html);
        $this->assertStringNotContainsString('Còn phải trả 0', $html);
    }

    // =====================================================================
    // Sắp xếp
    // =====================================================================

    #[Test]
    public function it_honours_the_requested_sort_mode_and_rejects_an_unknown_one(): void
    {
        $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index', ['sort' => 'payment_due']))
            ->assertOk()
            ->assertJsonPath('sort_mode', 'payment_due');

        // Giá trị lạ (sửa tay URL) rơi về `manual`, KHÔNG ném lỗi — hỏng thứ tự
        // hiển thị thì tệ hơn là trắng trang.
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index', ['sort' => 'xóa-hết-mọi-thứ']))
            ->assertOk()
            ->assertJsonPath('sort_mode', 'manual');
    }

    #[Test]
    public function previewing_another_sort_mode_does_not_change_the_saved_order(): void
    {
        $first = $this->makeUserCard($this->owner->id, ['sort_order' => 1]);
        $second = $this->makeUserCard($this->owner->id, ['sort_order' => 2]);

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index', ['sort' => 'payment_due']))
            ->assertOk();

        // Ba chế độ tự động chỉ để XEM TRƯỚC: `sort_order` của user giữ nguyên,
        // nếu không thì chỉ cần xem sai một lần là mất thứ tự đã sắp.
        $this->assertSame(1, (int) $first->refresh()->sort_order);
        $this->assertSame(2, (int) $second->refresh()->sort_order);
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * CHỮ NGƯỜI DÙNG THẬT SỰ THẤY trong HTML: bóc thẻ, giải thực thể rồi gộp
     * khoảng trắng.
     *
     * `&nbsp;` đổi về space thường để kỳ vọng viết được như người đọc — hành vi
     * "không đứt dòng" của nó do helper hiển thị tiền lo, không thuộc test này.
     */
    private function visibleTextOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Nội dung mọi thẻ `<script>` trong trang, nối lại.
     *
     * Test nào cần khẳng định "JavaScript KHÔNG làm gì đó" thì soi chỗ này, không
     * soi cả HTML: class CSS hay câu chữ tiếng Việt rất dễ chứa ký tự trùng với
     * biểu thức cần tìm và làm test xanh nhầm.
     */
    private function scriptOf(string $html): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches);

        return implode("\n", $matches[1] ?? []);
    }

    /**
     * JavaScript đã bỏ comment.
     *
     * Comment trong module giải thích *vì sao* không làm việc X, nên nó chứa đúng
     * những chữ mà test đang săn ("cộng/trừ", "sau khi lưu"). Đọc cả comment thì
     * test đỏ vì chính lời giải thích.
     */
    private function codeOf(string $script): string
    {
        $code = preg_replace('#/\*.*?\*/#s', ' ', $script) ?? $script;
        $code = preg_replace('#(^|\s)//[^\n]*#', ' ', $code) ?? $code;

        return $code;
    }

    /**
     * Ghi một dòng sao kê cho kỳ hiện tại qua service (đúng đường đi của app).
     */
    private function createStatement(UserCard $card, string $spend, string $reward): CreditCardStatement
    {
        $period = app(StatementPeriodService::class)->currentPeriod($card);

        return app(CreditCardStatementService::class)->upsert($card, $period, [
            'actual_spend' => $spend,
            'actual_reward' => $reward,
        ]);
    }
}
