<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Cải tiến form nhập giao dịch tại /thetindung:
 *   - Hai lối lưu: "Lưu" (nhập tiếp) + "Lưu và đóng".
 *   - Cảnh báo TRÙNG: cùng thẻ + ngày + số tiền (VND thô) + danh mục ⇒ 409 cho
 *     tới khi client xác nhận; token xác nhận gắn với ĐÚNG tập trùng hiện tại.
 *   - Chống double-submit bằng `submission_id`.
 *   - Ô chọn thẻ/danh mục là combobox tìm kiếm được.
 */
class TransactionEntryImprovementsTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        // Cache `array` sống theo process test; dọn để khoá `submission_id`
        // không rò rỉ giữa các test (sqlite :memory: tái dùng id).
        Cache::flush();

        $this->owner = User::factory()->create();
    }

    private function storeUrl(): string
    {
        return route('credit-cards.api.transactions.store');
    }

    // =====================================================================
    // Cảnh báo trùng
    // =====================================================================

    #[Test]
    public function an_identical_transaction_is_blocked_with_a_duplicate_warning(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $date = CarbonImmutable::now()->toDateString();

        $payload = [
            'transaction_date' => $date,
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '1555000',
        ];

        $this->actingAs($this->owner)->postJson($this->storeUrl(), $payload)->assertCreated();
        $this->assertSame(1, Transaction::query()->count());

        // Lần hai y hệt: KHÔNG lưu, trả 409 kèm token + mô tả giao dịch nghi trùng.
        $response = $this->actingAs($this->owner)->postJson($this->storeUrl(), $payload);

        $response->assertStatus(409)
            ->assertJsonPath('duplicate', true);

        $this->assertIsString($response->json('duplicate_token'));
        $this->assertNotSame('', $response->json('duplicate_token'));
        $this->assertCount(1, $response->json('duplicates'));
        $this->assertSame((float) '1555000', (float) $response->json('duplicates.0.amount'));
        $this->assertSame($category->name, $response->json('duplicates.0.category_name'));

        // Chưa xác nhận ⇒ không tạo thêm.
        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function confirming_the_warning_saves_the_transaction(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $payload = [
            'transaction_date' => CarbonImmutable::now()->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '200000',
        ];

        $this->actingAs($this->owner)->postJson($this->storeUrl(), $payload)->assertCreated();

        $token = $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $payload)
            ->assertStatus(409)
            ->json('duplicate_token');

        // Xác nhận "vẫn lưu" bằng token ⇒ ghi giao dịch thứ hai.
        $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $payload + ['duplicate_ack' => $token])
            ->assertCreated();

        $this->assertSame(2, Transaction::query()->count());
    }

    #[Test]
    public function a_token_is_rejected_when_the_duplicate_set_changes(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $attributes = [
            'transaction_date' => CarbonImmutable::now()->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '300000',
        ];

        $this->actingAs($this->owner)->postJson($this->storeUrl(), $attributes)->assertCreated();

        $token = $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $attributes)
            ->assertStatus(409)
            ->json('duplicate_token');

        // Một giao dịch trùng KHÁC xuất hiện sau khi server phát token.
        app(CreditCardTransactionService::class)->create($card, $attributes);

        $this->assertSame(2, Transaction::query()->count());

        // Token cũ không còn khớp tập trùng hiện tại ⇒ 409 lần nữa, KHÔNG lưu.
        $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $attributes + ['duplicate_ack' => $token])
            ->assertStatus(409);

        $this->assertSame(2, Transaction::query()->count());
    }

    #[Test]
    public function a_one_dong_difference_is_not_a_duplicate(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $base = [
            'transaction_date' => CarbonImmutable::now()->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
        ];

        $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $base + ['amount' => '100000'])
            ->assertCreated();

        // Lệch 1 đồng ⇒ KHÔNG trùng (so số VND thô, không làm tròn).
        $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $base + ['amount' => '100001'])
            ->assertCreated();

        $this->assertSame(2, Transaction::query()->count());
    }

    #[Test]
    public function a_different_category_or_date_or_card_is_not_a_duplicate(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $otherCard = $this->makeUserCard($this->owner->id);
        $categoryA = $this->makeSystemCategory();
        $categoryB = $this->makeSystemCategory();
        $today = CarbonImmutable::now();
        $yesterday = $today->subDay();

        $this->actingAs($this->owner)->postJson($this->storeUrl(), [
            'transaction_date' => $today->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $categoryA->id,
            'amount' => '500000',
        ])->assertCreated();

        // Danh mục khác.
        $this->actingAs($this->owner)->postJson($this->storeUrl(), [
            'transaction_date' => $today->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $categoryB->id,
            'amount' => '500000',
        ])->assertCreated();

        // Ngày khác.
        $this->actingAs($this->owner)->postJson($this->storeUrl(), [
            'transaction_date' => $yesterday->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $categoryA->id,
            'amount' => '500000',
        ])->assertCreated();

        // Thẻ khác.
        $this->actingAs($this->owner)->postJson($this->storeUrl(), [
            'transaction_date' => $today->toDateString(),
            'user_card_id' => $otherCard->id,
            'category_id' => $categoryA->id,
            'amount' => '500000',
        ])->assertCreated();

        $this->assertSame(4, Transaction::query()->count());
    }

    #[Test]
    public function a_soft_deleted_transaction_does_not_trigger_the_warning(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $attributes = [
            'transaction_date' => CarbonImmutable::now()->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '900000',
        ];

        $this->actingAs($this->owner)->postJson($this->storeUrl(), $attributes)->assertCreated();

        Transaction::query()->sole()->delete();

        // Giao dịch đã xoá mềm không tính là trùng ⇒ lưu thẳng.
        $this->actingAs($this->owner)->postJson($this->storeUrl(), $attributes)->assertCreated();

        $this->assertSame(1, Transaction::query()->count());
    }

    // =====================================================================
    // Chống double-submit
    // =====================================================================

    #[Test]
    public function the_same_submission_id_never_creates_two_transactions(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $payload = [
            'transaction_date' => CarbonImmutable::now()->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '700000',
            'submission_id' => 'sub-'.uniqid('', true),
        ];

        $first = $this->actingAs($this->owner)->postJson($this->storeUrl(), $payload);
        $first->assertCreated();
        $firstId = $first->json('data.id');

        // Gửi lại y hệt (retry mạng/double click) ⇒ trả lại kết quả lần đầu.
        $second = $this->actingAs($this->owner)->postJson($this->storeUrl(), $payload);
        $second->assertOk()->assertJsonPath('duplicate_submission', true);

        $this->assertSame($firstId, $second->json('data.id'));
        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function a_new_submission_id_creates_a_new_transaction(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $base = [
            'transaction_date' => CarbonImmutable::now()->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '700000',
        ];

        $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $base + ['submission_id' => 'sid-a'])
            ->assertCreated();

        // `submission_id` mới (người dùng chủ động thêm) + xác nhận trùng ⇒ ghi thêm.
        $token = $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $base + ['submission_id' => 'sid-b'])
            ->assertStatus(409)
            ->json('duplicate_token');

        $this->actingAs($this->owner)
            ->postJson($this->storeUrl(), $base + ['submission_id' => 'sid-c', 'duplicate_ack' => $token])
            ->assertCreated();

        $this->assertSame(2, Transaction::query()->count());
    }

    #[Test]
    public function the_save_intent_is_echoed_back(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)->postJson($this->storeUrl(), [
            'transaction_date' => CarbonImmutable::now()->toDateString(),
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '100000',
            'save_intent' => 'save_and_continue',
        ])->assertCreated()->assertJsonPath('save_intent', 'save_and_continue');
    }

    // =====================================================================
    // HTML: nút lưu, modal trùng, combobox tìm kiếm
    // =====================================================================

    #[Test]
    public function the_overview_offers_both_save_actions_and_a_duplicate_dialog(): void
    {
        $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="save-transaction-button"', $html);
        $this->assertStringContainsString('data-testid="save-and-close-button"', $html);
        $this->assertStringContainsString('Lưu và đóng', $html);

        // Modal cảnh báo trùng + hai lựa chọn.
        $this->assertStringContainsString('x-show="duplicateOpen"', $html);
        $this->assertStringContainsString('Hủy bỏ', $html);
        $this->assertStringContainsString('Vẫn lưu giao dịch', $html);
        $this->assertStringContainsString('duplicateSummary(item)', $html);

        // Gửi `submission_id` khi lưu.
        $this->assertStringContainsString('submission_id: this.submissionId', $html);
    }

    #[Test]
    public function the_card_and_category_are_searchable_comboboxes(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_ACTIVE]);
        $this->makeSystemCategory();

        $html = $this->actingAs($this->owner)->get('/thetindung')->assertOk()->getContent();

        // Component dùng chung, gắn hai chiều bằng `x-modelable`.
        $this->assertStringContainsString('x-modelable="value"', $html);
        $this->assertStringContainsString('ccSearchableSelect(', $html);

        // Giữ nguyên id + x-model của hai ô (test cũ phụ thuộc).
        $this->assertStringContainsString('id="tx-card"', $html);
        $this->assertStringContainsString('x-model="form.user_card_id"', $html);
        $this->assertStringContainsString('id="tx-category"', $html);
        $this->assertStringContainsString('x-model="form.category_id"', $html);

        // Nút trigger (không còn <select>) mở dropdown có ô tìm kiếm.
        $this->assertStringNotContainsString('<select id="tx-card"', $html);
        $this->assertStringNotContainsString('<select id="tx-category"', $html);
        $this->assertStringContainsString('Tìm theo tên thẻ, ngân hàng, 4 số cuối…', $html);
        $this->assertStringContainsString('Tìm danh mục…', $html);

        // Nhãn của thẻ được đưa xuống component (tên + ngân hàng + 4 số cuối).
        $this->assertStringContainsString($card->name, $html);
    }

    #[Test]
    public function the_history_edit_form_uses_the_searchable_category_combobox(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => CarbonImmutable::now()->toDateString(),
            'category_id' => $category->id,
            'amount' => '45000',
            'note' => 'Cà phê',
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.transactions', ['userCard' => $card->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('x-modelable="value"', $html);
        $this->assertStringContainsString('x-model="form.category_id"', $html);
        // KHÔNG cho đổi thẻ trong form sửa.
        $this->assertStringNotContainsString('x-model="form.user_card_id"', $html);
    }
}
