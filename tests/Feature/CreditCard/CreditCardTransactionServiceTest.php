<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\UserCardService;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Phase 1B — vòng đời giao dịch nhập tay.
 *
 * Ba bất biến:
 *   1. Cashback KHÔNG nhập tay: service chỉ tạo giao dịch rồi để pipeline
 *      `calculateTransaction()` tự gắn kỳ + chạy lại toàn kỳ.
 *   2. Ownership: không đọc/sửa/xoá được giao dịch của user khác.
 *   3. Kỳ finalized bất biến; xoá là soft-delete và phải tính lại kỳ.
 */
class CreditCardTransactionServiceTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private CreditCardTransactionService $service;

    private UserCardService $cards;

    private User $user;

    private UserCard $card;

    private Category $category;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->service = app(CreditCardTransactionService::class);
        $this->cards = app(UserCardService::class);

        $this->user = User::factory()->create();
        // Anchor = 31: kỳ mở 30/09 kết thúc 29/10 (tháng 9 chỉ có 30 ngày nên
        // clamp), kỳ kế tiếp mở 31/10 kết thúc 29/11.
        $this->card = $this->makeUserCard($this->user->id, ['statement_day' => 31]);
        $this->category = $this->makeSystemCategory();

        $this->seedTwoTiers();
    }

    // =====================================================================
    // Create
    // =====================================================================

    #[Test]
    public function creating_a_manual_transaction_attaches_a_period_and_computes_cashback(): void
    {
        $transaction = $this->service->create($this->card, [
            'transaction_date' => '2026-09-10',
            'amount' => '6000000',
            'category_id' => $this->category->id,
            'merchant' => 'Shopee',
        ]);

        $this->assertSame(Transaction::SOURCE_MANUAL, $transaction->source);
        $this->assertNotNull($transaction->statement_period_id, 'Phải tự gắn kỳ sao kê.');
        $this->assertSame('600000.00', $transaction->cashback_amount_snapshot, '6tr ⇒ bậc 2 ⇒ 10%.');
        $this->assertSame('10.000', $transaction->cashback_percent_snapshot);
        $this->assertTrue((bool) $transaction->is_eligible);
    }

    #[Test]
    public function a_transaction_cannot_be_created_with_a_category_of_another_user(): void
    {
        $other = User::factory()->create();
        $foreign = $this->makeUserCategory($other->id);

        $this->expectException(InvalidArgumentException::class);

        $this->service->create($this->card, [
            'transaction_date' => '2026-09-10',
            'amount' => '1000000',
            'category_id' => $foreign->id,
        ]);
    }

    #[Test]
    public function a_transaction_cannot_be_created_on_a_closed_card(): void
    {
        $this->cards->deactivate($this->user->id, $this->card->id);

        $this->expectException(LogicException::class);

        $this->service->create($this->card->refresh(), [
            'transaction_date' => '2026-09-10',
            'amount' => '1000000',
        ]);
    }

    #[Test]
    public function a_zero_amount_transaction_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create($this->card, [
            'transaction_date' => '2026-09-10',
            'amount' => '0',
        ]);
    }

    #[Test]
    public function an_unparsable_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->create($this->card, [
            'transaction_date' => '10/09/2026',
            'amount' => '1000000',
        ]);
    }

    #[Test]
    public function a_negative_amount_is_kept_as_a_refund(): void
    {
        $transaction = $this->service->create($this->card, [
            'transaction_date' => '2026-09-10',
            'amount' => '-500000',
            'category_id' => $this->category->id,
        ]);

        $this->assertSame('-500000.00', (string) $transaction->amount);
    }

    // =====================================================================
    // Ownership
    // =====================================================================

    #[Test]
    public function a_user_cannot_read_another_users_transaction(): void
    {
        $intruder = User::factory()->create();
        $transaction = $this->makeTransaction();

        $this->expectException(InvalidArgumentException::class);

        $this->service->findOwned($transaction->id, $intruder->id);
    }

    #[Test]
    public function a_user_cannot_update_another_users_transaction(): void
    {
        $intruder = User::factory()->create();
        $transaction = $this->makeTransaction('1000000');

        $this->expectException(InvalidArgumentException::class);

        $this->service->update($intruder->id, $transaction->id, ['amount' => '9999999']);
    }

    #[Test]
    public function a_user_cannot_delete_another_users_transaction(): void
    {
        $intruder = User::factory()->create();
        $transaction = $this->makeTransaction();

        $this->expectException(InvalidArgumentException::class);

        $this->service->delete($intruder->id, $transaction->id);
    }

    // =====================================================================
    // Update
    // =====================================================================

    #[Test]
    public function updating_the_amount_recalculates_cashback_retroactively(): void
    {
        // 1tr ⇒ bậc 1 ⇒ 2%.
        $transaction = $this->makeTransaction('1000000');
        $this->assertSame('20000.00', $transaction->cashback_amount_snapshot);

        // Sửa lên 6tr ⇒ bậc 2 ⇒ 10%.
        $updated = $this->service->update($this->user->id, $transaction->id, ['amount' => '6000000']);

        $this->assertSame('600000.00', $updated->cashback_amount_snapshot);
        $this->assertSame('600000.00', $updated->statementPeriod->refresh()->total_cashback);
        $this->assertSame('6000000.00', $updated->statementPeriod->refresh()->total_eligible_spend);
    }

    #[Test]
    public function changing_the_date_moves_the_transaction_and_recalculates_both_periods(): void
    {
        $transaction = $this->makeTransaction('1000000');
        $septemberPeriodId = (int) $transaction->statement_period_id;

        $moved = $this->service->update($this->user->id, $transaction->id, [
            // 15/10 >= anchor 31/10? Không. Dùng 01/11 để chắc chắn sang kỳ mới.
            'transaction_date' => '2026-11-01',
        ]);

        $october = $moved->statementPeriod;

        $this->assertNotSame($septemberPeriodId, (int) $moved->statement_period_id, 'Phải sang kỳ khác.');

        // Anchor 31: 01/11 < 30/11 nên thuộc kỳ mở 31/10, kết thúc 29/11.
        $this->assertSame('2026-10-31', $october->period_start->toDateString());
        $this->assertSame('2026-11-29', $october->period_end->toDateString());

        // Kỳ cũ không còn giao dịch này ⇒ tổng phải về 0.
        $september = StatementPeriod::find($septemberPeriodId);
        $this->assertSame('0.00', $september->refresh()->total_cashback);

        // Kỳ mới đã ghi cashback.
        $this->assertSame('1000000.00', $october->refresh()->total_eligible_spend);
    }

    #[Test]
    public function a_transaction_in_a_finalized_period_cannot_be_updated(): void
    {
        $transaction = $this->makeTransaction();
        $transaction->statementPeriod->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])->save();

        $this->expectException(LogicException::class);

        $this->service->update($this->user->id, $transaction->id, ['amount' => '9000000']);
    }

    #[Test]
    public function a_transaction_in_a_finalized_period_cannot_be_deleted(): void
    {
        $transaction = $this->makeTransaction();
        $transaction->statementPeriod->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])->save();

        $this->expectException(LogicException::class);

        $this->service->delete($this->user->id, $transaction->id);
    }

    // =====================================================================
    // Delete
    // =====================================================================

    #[Test]
    public function deleting_a_transaction_is_soft_and_recalculates_the_period(): void
    {
        $transaction = $this->makeTransaction('1000000');
        $period = $transaction->statementPeriod;

        $this->service->delete($this->user->id, $transaction->id);

        $this->assertSoftDeleted($transaction);
        $this->assertSame('0.00', $period->refresh()->total_eligible_spend, 'Kỳ phải bỏ giao dịch đã xoá.');
        $this->assertSame('0.00', $period->refresh()->total_cashback);
    }

    // =====================================================================
    // List
    // =====================================================================

    #[Test]
    public function listing_can_filter_by_period(): void
    {
        $september = $this->makeTransaction('1000000');

        $this->assertCount(1, $this->service->listFor($this->card, ['period_id' => $september->statement_period_id]));

        $this->assertCount(0, $this->service->listFor($this->card, ['period_id' => $september->statement_period_id + 999]));
    }

    #[Test]
    public function listing_can_filter_by_keyword(): void
    {
        $this->makeTransaction('1000000', ['merchant' => 'Shopee']);
        $this->makeTransaction('2000000', ['merchant' => 'Lotte Mart']);

        $this->assertCount(1, $this->service->listFor($this->card, ['keyword' => 'Shopee']));
        $this->assertCount(2, $this->service->listFor($this->card));
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function makeTransaction(string $amount = '1000000', array $attributes = []): Transaction
    {
        return $this->service->create($this->card, array_merge([
            'transaction_date' => '2026-09-10',
            'amount' => $amount,
            'category_id' => $this->category->id,
        ], $attributes));
    }

    private function seedTwoTiers(): void
    {
        $this->makePolicyForCard(
            $this->card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
            ],
            [
                ['category_id' => $this->category->id, 'percent' => '2.000', 'only_tier' => 0],
                ['category_id' => $this->category->id, 'percent' => '10.000', 'only_tier' => 1],
            ]
        );
    }
}
