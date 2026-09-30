<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Phase 1B — HTTP layer (form request + thin controller + policy).
 *
 * Điểm cần bảo vệ ở tầng này:
 *   1. Route nằm trong middleware `auth`.
 *   2. Không endpoint nào nhận `user_id` từ request ⇒ không đổi được danh tính.
 *   3. Truy cập chéo user bị chặn (403), không rò dữ liệu.
 *   4. Danh mục hệ thống không sửa/xoá được qua API.
 *   5. `cashback_amount` gửi lên bị bỏ qua — không bao giờ nhập tay.
 */
class Phase1bHttpTest extends TestCase
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
    // Auth
    // =====================================================================

    #[Test]
    public function every_phase1b_endpoint_requires_authentication(): void
    {
        $this->getJson(route('credit-cards.api.cards.index'))->assertUnauthorized();
        $this->postJson(route('credit-cards.api.cards.store'), [])->assertUnauthorized();
        $this->patchJson(route('credit-cards.api.cards.update', 1), [])->assertUnauthorized();
        $this->deleteJson(route('credit-cards.api.cards.destroy', 1))->assertUnauthorized();
        $this->getJson(route('credit-cards.api.categories.index'))->assertUnauthorized();
        $this->postJson(route('credit-cards.api.categories.store'), [])->assertUnauthorized();
        $this->patchJson(route('credit-cards.api.categories.update', 1), [])->assertUnauthorized();
        $this->deleteJson(route('credit-cards.api.categories.destroy', 1))->assertUnauthorized();
        $this->getJson(route('credit-cards.api.transactions.index', 1))->assertUnauthorized();
        $this->postJson(route('credit-cards.api.transactions.store'), [])->assertUnauthorized();
        $this->patchJson(route('credit-cards.api.transactions.update', 1), [])->assertUnauthorized();
        $this->deleteJson(route('credit-cards.api.transactions.destroy', 1))->assertUnauthorized();
    }

    // =====================================================================
    // Cards
    // =====================================================================

    #[Test]
    public function card_index_returns_only_the_authenticated_users_cards(): void
    {
        $mine = $this->makeUserCard($this->owner->id);
        $theirs = $this->makeUserCard($this->stranger->id);

        $response = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    #[Test]
    public function storing_a_card_uses_the_authenticated_user_not_request_input(): void
    {
        $bank = $this->makeBank();

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.cards.store'), [
                'bank_id' => $bank->id,
                'name' => 'MB JCB',
                // Cố gắng gán thẻ cho user khác — phải bị bỏ qua.
                'user_id' => $this->stranger->id,
            ])
            ->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));

        $this->assertSame((int) $this->owner->id, (int) $card->user_id);
        $this->assertSame($bank->id, (int) $card->bank_id);
        $this->assertNull($card->product_id);
    }

    #[Test]
    public function storing_a_card_with_an_inactive_bank_is_rejected(): void
    {
        $bank = $this->makeBank(['is_active' => false]);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.cards.store'), [
                'bank_id' => $bank->id,
                'name' => 'Thẻ hạn',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_id');
    }

    #[Test]
    public function storing_a_card_validates_the_last4_format(): void
    {
        $bank = $this->makeBank();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.cards.store'), [
                'bank_id' => $bank->id,
                'name' => 'Thẻ hạn',
                'card_number_last4' => '12345',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('card_number_last4');
    }

    #[Test]
    public function a_stranger_cannot_update_or_close_someone_elses_card(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Tên gốc']);

        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['name' => 'Cướp'])
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->deleteJson(route('credit-cards.api.cards.destroy', $card->id))
            ->assertForbidden();

        $this->assertSame('Tên gốc', $card->refresh()->name);
        $this->assertTrue($card->refresh()->isActive());
    }

    #[Test]
    public function closing_a_card_keeps_the_row(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.cards.destroy', $card->id))
            ->assertOk()
            ->assertJsonPath('data.is_usable', false);

        $this->assertTrue(UserCard::whereKey($card->id)->exists(), 'Không được xoá cứng thẻ.');
        $this->assertTrue(UserCard::findOrFail($card->id)->isClosed());
    }

    #[Test]
    public function updating_status_reopens_a_closed_card(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $card->forceFill(['status' => UserCard::STATUS_INACTIVE, 'closed_at' => '2026-09-30'])->save();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'status' => UserCard::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_usable', true);

        $this->assertNull($card->refresh()->closed_at);
    }

    #[Test]
    public function reorder_rejects_duplicate_ids(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.reorder'), ['order' => [$card->id, $card->id]])
            ->assertStatus(422)
            // Rule `distinct` báo lỗi theo từng phần tử, không phải ở `order`.
            ->assertJsonValidationErrors(['order.0', 'order.1']);
    }

    // =====================================================================
    // Categories
    // =====================================================================

    #[Test]
    public function a_user_category_is_always_owned_by_the_creator(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.categories.store'), [
                'name' => 'Cà phê',
                // Cố tạo danh mục hệ thống.
                'scope' => 'system',
                'owner_user_id' => $this->stranger->id,
            ])
            ->assertCreated();

        $category = Category::findOrFail($response->json('data.id'));

        $this->assertSame(Category::SCOPE_USER, $category->scope);
        $this->assertSame((int) $this->owner->id, (int) $category->owner_user_id);
    }

    #[Test]
    public function a_system_category_cannot_be_updated_or_deleted_through_the_api(): void
    {
        $system = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.categories.update', $system->id), ['name' => 'Đổi tên'])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.categories.destroy', $system->id))
            ->assertForbidden();

        $this->assertTrue(Category::whereKey($system->id)->exists());
        $this->assertSame('Ăn uống', $system->refresh()->name);
    }

    #[Test]
    public function a_stranger_cannot_touch_another_users_category(): void
    {
        $category = $this->makeUserCategory($this->owner->id);

        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.categories.update', $category->id), ['name' => 'Cướp'])
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->deleteJson(route('credit-cards.api.categories.destroy', $category->id))
            ->assertForbidden();
    }

    #[Test]
    public function deleting_a_used_category_reports_that_it_was_only_hidden(): void
    {
        $category = $this->makeUserCategory($this->owner->id);
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicyForCard($card, [['name' => 'T1', 'min' => 0, 'max' => null]], [
            ['category_id' => $category->id, 'percent' => '5.000'],
        ]);

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.categories.destroy', $category->id))
            ->assertOk()
            ->assertJsonPath('deleted', false)
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue(Category::whereKey($category->id)->exists());
    }

    // =====================================================================
    // Transactions
    // =====================================================================

    #[Test]
    public function storing_a_transaction_returns_the_computed_cashback(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 31]);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'category_id' => $category->id,
                'transaction_date' => '2026-09-10',
                'amount' => '1000000',
                'merchant' => 'Shopee',
            ])
            ->assertCreated();

        // JSON trả số nguyên khi giá trị không có phần thập → dùng assertEquals.
        $this->assertEquals(50000.0, $response->json('data.cashback_amount'));
        $this->assertEquals(5.0, $response->json('data.cashback_percent'));
        $this->assertSame(Transaction::SOURCE_MANUAL, $response->json('data.source'));
    }

    #[Test]
    public function a_cashback_field_in_the_payload_is_ignored(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 31]);
        $category = $this->makeSystemCategory();
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'category_id' => $category->id,
                'transaction_date' => '2026-09-10',
                'amount' => '1000000',
                // Cố ép cashback 999.999.999 — phải bị bỏ qua hoàn toàn.
                'cashback_amount' => 999999999,
                'cashback_percent' => 99,
            ])
            ->assertCreated();

        $this->assertEquals(50000.0, $response->json('data.cashback_amount'), 'Cashback phải do hệ thống tính.');
    }

    #[Test]
    public function a_transaction_cannot_be_added_to_another_users_card(): void
    {
        $card = $this->makeUserCard($this->stranger->id);
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'category_id' => $category->id,
                'transaction_date' => '2026-09-10',
                'amount' => '1000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_card_id');
    }

    #[Test]
    public function a_transaction_cannot_use_another_users_category(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 31]);
        $foreign = $this->makeUserCategory($this->stranger->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.transactions.store'), [
                'user_card_id' => $card->id,
                'category_id' => $foreign->id,
                'transaction_date' => '2026-09-10',
                'amount' => '1000000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    #[Test]
    public function transaction_index_is_scoped_to_the_cards_owner(): void
    {
        $mine = $this->makeUserCard($this->owner->id, ['statement_day' => 31]);
        $theirs = $this->makeUserCard($this->stranger->id, ['statement_day' => 31]);
        $category = $this->makeSystemCategory();

        $service = app(CreditCardTransactionService::class);
        $mineTransaction = $service->create($mine, [
            'transaction_date' => '2026-09-10',
            'amount' => '1000000',
            'category_id' => $category->id,
        ]);
        $foreignTransaction = $service->create($theirs, [
            'transaction_date' => '2026-09-10',
            'amount' => '2000000',
            'category_id' => $category->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.transactions.index', $mine->id))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mineTransaction->id));
        $this->assertFalse($ids->contains($foreignTransaction->id));
    }

    #[Test]
    public function a_stranger_cannot_update_or_delete_someone_elses_transaction(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 31]);
        $category = $this->makeSystemCategory();
        $transaction = app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => '2026-09-10',
            'amount' => '1000000',
            'category_id' => $category->id,
        ]);

        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.transactions.update', $transaction->id), ['amount' => '9999999'])
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->deleteJson(route('credit-cards.api.transactions.destroy', $transaction->id))
            ->assertForbidden();

        $this->assertSame('1000000.00', $transaction->refresh()->amount);
    }

    #[Test]
    public function a_transaction_in_a_finalized_period_cannot_be_changed(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['statement_day' => 31]);
        $category = $this->makeSystemCategory();
        $transaction = app(CreditCardTransactionService::class)->create($card, [
            'transaction_date' => '2026-09-10',
            'amount' => '1000000',
            'category_id' => $category->id,
        ]);

        $transaction->statementPeriod->forceFill(['status' => 'finalized'])->save();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.transactions.update', $transaction->id), ['amount' => '2000000'])
            ->assertForbidden();

        $this->assertSame('1000000.00', $transaction->refresh()->amount);
    }
}
