<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Authorization của module Thẻ tín dụng.
 *
 * Nguyên tắc được bảo vệ ở đây:
 *   1. Mọi resource thuộc thẻ ⇒ chỉ CHỦ THẺ được đọc/sửa.
 *   2. Bản ghi đã chốt (`finalized` period, `superseded` version) là bất biến.
 *   3. System template chỉ admin sửa được; builtin template không xoá được.
 *
 * Các policy nằm ở `App\Policies\CreditCard\*Policy` và được Laravel 12
 * auto-discover theo namespace của model (`App\Models\CreditCard\*`).
 */
class CreditCardAuthorizationTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private User $stranger;

    private User $admin;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::create(['name' => 'Admin']);

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    // =====================================================================
    // UserCard
    // =====================================================================

    #[Test]
    public function only_the_card_owner_can_view_or_change_a_card(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->assertTrue($this->owner->can('view', $card));
        $this->assertTrue($this->owner->can('update', $card));
        $this->assertTrue($this->owner->can('delete', $card));

        $this->assertFalse($this->stranger->can('view', $card));
        $this->assertFalse($this->stranger->can('update', $card));
        $this->assertFalse($this->stranger->can('delete', $card));

        // Admin cũng KHÔNG tự động có quyền trên thẻ của người khác.
        $this->assertFalse($this->admin->can('view', $card));
    }

    // =====================================================================
    // Transaction (thuộc thẻ => thuộc user)
    // =====================================================================

    #[Test]
    public function only_the_card_owner_can_view_a_transaction(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $transaction = $this->makeTransactionFor($card);

        $this->assertTrue($this->owner->can('view', $transaction));
        $this->assertFalse($this->stranger->can('view', $transaction));
    }

    #[Test]
    public function a_transaction_in_a_finalized_period_is_immutable(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $period = $this->makeStatementPeriod($card, ['status' => StatementPeriod::STATUS_FINALIZED]);
        $transaction = $this->makeTransactionFor($card, $period);

        // Chủ thẻ vẫn XEM được, nhưng không sửa/xoá được.
        $this->assertTrue($this->owner->can('view', $transaction));
        $this->assertFalse($this->owner->can('update', $transaction));
        $this->assertFalse($this->owner->can('delete', $transaction));
    }

    #[Test]
    public function a_transaction_in_an_open_period_stays_editable_for_its_owner_only(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $period = $this->makeStatementPeriod($card, ['status' => StatementPeriod::STATUS_OPEN]);
        $transaction = $this->makeTransactionFor($card, $period);

        $this->assertTrue($this->owner->can('update', $transaction));
        $this->assertTrue($this->owner->can('delete', $transaction));

        $this->assertFalse($this->stranger->can('update', $transaction));
        $this->assertFalse($this->stranger->can('delete', $transaction));
    }

    // =====================================================================
    // Policy
    // =====================================================================

    #[Test]
    public function only_the_card_owner_can_view_a_card_policy(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);

        $this->assertTrue($this->owner->can('view', $policy));
        $this->assertFalse($this->stranger->can('view', $policy));
    }

    #[Test]
    public function a_superseded_policy_version_cannot_be_edited_even_by_its_owner(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);

        $this->assertTrue($this->owner->can('update', $policy));

        $policy->forceFill(['status' => Policy::STATUS_SUPERSEDED])->save();
        $policy->refresh();

        $this->assertFalse(
            $this->owner->can('update', $policy),
            'Bản ghi superseded là bản ghi lịch sử, không được sửa.'
        );
    }

    #[Test]
    public function a_policy_can_only_be_deleted_while_it_is_a_draft(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);

        $this->assertFalse($this->owner->can('delete', $policy), 'Policy active không được xoá trực tiếp.');

        $policy->forceFill(['status' => Policy::STATUS_DRAFT])->save();

        $this->assertTrue($this->owner->can('delete', $policy->refresh()));
    }

    #[Test]
    public function a_stranger_can_never_delete_a_policy(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);

        $policy->forceFill(['status' => Policy::STATUS_DRAFT])->save();

        $this->assertFalse($this->stranger->can('delete', $policy));
    }

    // =====================================================================
    // PolicyTemplate
    // =====================================================================

    #[Test]
    public function everyone_can_read_a_system_template_but_only_admin_can_edit_it(): void
    {
        $template = $this->makeSystemTemplate();

        $this->assertTrue($this->owner->can('view', $template));
        $this->assertTrue($this->stranger->can('view', $template));

        $this->assertFalse($this->owner->can('update', $template));
        $this->assertFalse($this->stranger->can('update', $template));

        $this->assertTrue($this->admin->can('update', $template));
    }

    #[Test]
    public function a_user_template_is_editable_only_by_its_owner(): void
    {
        $template = PolicyTemplate::create([
            'scope' => PolicyTemplate::SCOPE_USER,
            'owner_user_id' => $this->owner->id,
            'name' => 'Template riêng',
            'slug' => 'template-rieng-'.$this->owner->id,
            'is_builtin' => false,
            'is_active' => true,
        ]);

        $this->assertTrue($this->owner->can('update', $template));
        $this->assertFalse($this->stranger->can('update', $template));
        $this->assertFalse($this->stranger->can('view', $template));
    }

    #[Test]
    public function a_builtin_template_can_never_be_deleted(): void
    {
        $template = $this->makeSystemTemplate(['is_builtin' => true]);

        $this->assertFalse($this->owner->can('delete', $template));
        $this->assertFalse($this->admin->can('delete', $template), 'Kể cả admin cũng không xoá được builtin template.');
    }

    #[Test]
    public function a_non_builtin_system_template_can_be_deleted_by_admin_only(): void
    {
        $template = $this->makeSystemTemplate(['is_builtin' => false]);

        $this->assertTrue($this->admin->can('delete', $template));
        $this->assertFalse($this->owner->can('delete', $template));
    }

    #[Test]
    public function a_user_template_can_be_deleted_by_its_owner_only(): void
    {
        $template = PolicyTemplate::create([
            'scope' => PolicyTemplate::SCOPE_USER,
            'owner_user_id' => $this->owner->id,
            'name' => 'Template xoá được',
            'slug' => 'template-xoa-duoc-'.$this->owner->id,
            'is_builtin' => false,
            'is_active' => true,
        ]);

        $this->assertTrue($this->owner->can('delete', $template));
        $this->assertFalse($this->stranger->can('delete', $template));
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function makeTransactionFor(UserCard $card, ?StatementPeriod $period = null): Transaction
    {
        $period ??= $this->makeStatementPeriod($card, ['status' => StatementPeriod::STATUS_OPEN]);

        return Transaction::create([
            'user_card_id' => $card->id,
            'statement_period_id' => $period->id,
            'category_id' => $this->makeSystemCategory()->id,
            'transaction_date' => '2026-09-10',
            'amount' => '1000000',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
    }
}
