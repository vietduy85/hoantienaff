<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\BankService;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\CategoryService;
use App\Services\CreditCard\PolicyService;
use App\Services\CreditCard\TierResolverService;
use App\Services\CreditCard\TierService;
use App\Services\CreditCard\UserCardService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Phase 1B — các service domain của Card Management.
 *
 * Bốn bất biến được bảo vệ ở đây:
 *   1. `UserCard = bank_id + name`, KHÔNG đi qua `product_id`.
 *   2. Ownership: user A không chạm được dữ liệu của user B ở MỌI entry point.
 *   3. Historical integrity: đã dùng thì KHÔNG hard-delete / KHÔNG sửa label.
 *   4. Versioning append-only: sửa cấu hình sinh version mới, không đụng version cũ.
 */
class CardManagementServicesTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private BankService $banks;

    private UserCardService $cards;

    private CategoryService $categories;

    private PolicyService $policies;

    private TierService $tiers;

    private CategoryRuleService $rules;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->banks = app(BankService::class);
        $this->cards = app(UserCardService::class);
        $this->categories = app(CategoryService::class);
        $this->policies = app(PolicyService::class);
        $this->tiers = app(TierService::class);
        $this->rules = app(CategoryRuleService::class);
    }

    // =====================================================================
    // BankService — read-only + alias
    // =====================================================================

    #[Test]
    public function bank_service_resolves_an_alias_to_the_same_canonical_record(): void
    {
        $bank = $this->makeBank(['name' => 'Sacombank', 'slug' => 'stb', 'short_name' => 'Sacombank', 'aliases' => ['scb']]);

        $this->assertTrue($this->banks->resolveByCode('scb')->is($bank), 'Alias phải ra đúng bank canonical.');
        $this->assertTrue($this->banks->resolveByCode('STB')->is($bank), 'Slug canonical phải ra bank.');
    }

    #[Test]
    public function bank_service_returns_null_for_a_partial_code_match(): void
    {
        $this->makeBank(['name' => 'Sacombank', 'slug' => 'stb', 'short_name' => 'Sacombank', 'aliases' => ['scb']]);

        $this->assertNull($this->banks->resolveByCode('s'), 'Mã một ký tự không được khớp bank nào.');
        $this->assertNull($this->banks->resolveByCode('   '));
    }

    #[Test]
    public function an_inactive_bank_is_hidden_from_assignment_but_still_readable(): void
    {
        $bank = $this->makeBank(['is_active' => false]);

        // Vẫn đọc được: thẻ cũ đã gắn bank này không được mất tên hiển thị.
        $this->assertTrue($this->banks->find($bank->id)->is($bank));

        $this->expectException(InvalidArgumentException::class);
        $this->banks->findActiveForAssignment($bank->id);
    }

    // =====================================================================
    // UserCardService — bank_id trực tiếp, ownership, lifecycle
    // =====================================================================

    #[Test]
    public function creating_a_card_uses_bank_id_directly_and_never_product_id(): void
    {
        $user = User::factory()->create();
        $bank = $this->makeBank(['name' => 'MB', 'slug' => 'mb']);

        $card = $this->cards->create($user->id, [
            'bank_id' => $bank->id,
            'name' => 'MB JCB Ultimate',
            'card_number_last4' => '1234',
            'credit_limit' => 100000000,
        ]);

        $this->assertSame($bank->id, (int) $card->bank_id);
        $this->assertNull($card->product_id, 'product_id phải NULL ở luồng Phase 1B.');
        $this->assertTrue($card->bank->is($bank));
        $this->assertSame('1234', $card->card_number_last4);
        $this->assertTrue($card->isUsable());
    }

    #[Test]
    public function creating_a_card_rejects_an_inactive_bank(): void
    {
        $user = User::factory()->create();
        $bank = $this->makeBank(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);

        $this->cards->create($user->id, ['bank_id' => $bank->id, 'name' => 'Thẻ hạn']);
    }

    #[Test]
    public function card_number_last4_must_be_exactly_four_digits(): void
    {
        $user = User::factory()->create();
        $bank = $this->makeBank();

        $this->expectException(InvalidArgumentException::class);

        $this->cards->create($user->id, [
            'bank_id' => $bank->id,
            'name' => 'Thẻ hạn',
            'card_number_last4' => '123456',
        ]);
    }

    #[Test]
    public function statement_day_out_of_range_is_rejected(): void
    {
        $user = User::factory()->create();
        $bank = $this->makeBank();

        $this->expectException(InvalidArgumentException::class);

        $this->cards->create($user->id, [
            'bank_id' => $bank->id,
            'name' => 'Thẻ hạn',
            'statement_day' => 32,
        ]);
    }

    #[Test]
    public function a_user_cannot_read_another_users_card(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $card = $this->makeUserCard($owner->id);

        $this->expectException(InvalidArgumentException::class);

        $this->cards->findOwned($card->id, $intruder->id);
    }

    #[Test]
    public function a_user_cannot_update_another_users_card(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $card = $this->makeUserCard($owner->id, ['name' => 'Tên gốc']);

        $this->expectException(InvalidArgumentException::class);

        $this->cards->update($intruder->id, $card->id, ['name' => 'Tên của kẻ xâm nhập']);
    }

    #[Test]
    public function deactivating_a_card_keeps_the_row_and_its_history(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $cardId = $card->id;

        $closed = $this->cards->deactivate($user->id, $cardId);

        $this->assertTrue(UserCard::whereKey($cardId)->exists(), 'Không được xoá cứng thẻ đã dùng.');
        $this->assertFalse($closed->isActive());
        $this->assertTrue($closed->isClosed());
        $this->assertNotNull($closed->closed_at);
        $this->assertFalse($this->cards->hasUsableCard($user->id));
    }

    #[Test]
    public function a_closed_card_cannot_be_edited_until_it_is_reactivated(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $this->cards->deactivate($user->id, $card->id);

        $this->expectException(LogicException::class);

        $this->cards->update($user->id, $card->id, ['name' => 'Sửa sau khi đóng']);
    }

    #[Test]
    public function reactivating_a_card_makes_it_usable_again(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $this->cards->deactivate($user->id, $card->id);

        $reopened = $this->cards->reactivate($user->id, $card->id);

        $this->assertTrue($reopened->isUsable());
        $this->assertNull($reopened->closed_at);
    }

    #[Test]
    public function reorder_ignores_card_ids_belonging_to_other_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $first = $this->makeUserCard($owner->id, ['sort_order' => 1]);
        $second = $this->makeUserCard($owner->id, ['sort_order' => 2]);
        $foreign = $this->makeUserCard($intruder->id, ['sort_order' => 7]);

        $result = $this->cards->reorder($owner->id, [$second->id, $foreign->id, $first->id]);

        $this->assertCount(2, $result, 'Chỉ được sắp xếp thẻ của chính mình.');
        $this->assertSame($second->id, $result->first()->id, 'Thẻ đưa lên đầu phải lên vị trí 1.');
        $this->assertSame($first->id, $result->last()->id);
        $this->assertSame(1, (int) $result->first()->sort_order);
        $this->assertSame(2, (int) $result->last()->sort_order);

        // Thẻ của user khác phải giữ nguyên sort_order cũ.
        $this->assertSame(7, (int) $foreign->fresh()->sort_order, 'Thẻ của user khác không bị đụng.');
    }

    #[Test]
    public function total_active_limit_ignores_deactivated_cards(): void
    {
        $user = User::factory()->create();
        $this->makeUserCard($user->id, ['credit_limit' => 10000000]);
        $closing = $this->makeUserCard($user->id, ['credit_limit' => 70000000]);

        $this->assertEquals(80000000.0, $this->cards->totalActiveLimit($user->id));

        $this->cards->deactivate($user->id, $closing->id);

        $this->assertEquals(10000000.0, $this->cards->totalActiveLimit($user->id));
    }

    // =====================================================================
    // CategoryService — system read-only, ownership, in-use guard
    // =====================================================================

    #[Test]
    public function system_categories_are_visible_to_everyone_but_only_readable(): void
    {
        $user = User::factory()->create();
        $system = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $ids = $this->categories->selectableFor($user->id)->pluck('id');
        $this->assertTrue($ids->contains($system->id));

        $this->expectException(LogicException::class);
        $this->categories->updateUserCategory($user->id, $system->id, ['name' => 'Ăn uống đổi tên']);
    }

    #[Test]
    public function system_categories_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $system = $this->makeSystemCategory();

        $this->expectException(LogicException::class);

        $this->categories->deleteUserCategory($user->id, $system->id);
    }

    #[Test]
    public function a_user_cannot_touch_another_users_category(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $category = $this->makeUserCategory($owner->id);

        $this->expectException(InvalidArgumentException::class);

        $this->categories->updateUserCategory($intruder->id, $category->id, ['name' => 'Cướp']);
    }

    #[Test]
    public function a_user_cannot_see_another_users_category_in_the_picker(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->makeUserCategory($user->id);
        $theirs = $this->makeUserCategory($other->id);

        $ids = $this->categories->selectableFor($user->id)->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    #[Test]
    public function creating_two_categories_with_the_same_name_gets_distinct_slugs(): void
    {
        $user = User::factory()->create();

        $first = $this->categories->createUserCategory($user->id, ['name' => 'Cà phê']);
        $second = $this->categories->createUserCategory($user->id, ['name' => 'Cà phê']);

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame('ca-phe', $first->slug);
    }

    #[Test]
    public function an_unused_category_is_hard_deleted(): void
    {
        $user = User::factory()->create();
        $category = $this->categories->createUserCategory($user->id, ['name' => 'Rác']);
        $categoryId = $category->id;

        $result = $this->categories->deleteUserCategory($user->id, $categoryId);

        $this->assertTrue($result['deleted']);
        $this->assertFalse(Category::whereKey($categoryId)->exists());
    }

    #[Test]
    public function a_category_used_by_a_cashback_rule_is_only_deactivated_not_deleted(): void
    {
        $user = User::factory()->create();
        $category = $this->categories->createUserCategory($user->id, ['name' => 'Xăng']);
        $card = $this->makeUserCard($user->id);
        $policy = $this->makePolicyForCard($card, [['name' => 'T1', 'min' => 0, 'max' => null]], [
            ['category_id' => $category->id, 'percent' => '5.000'],
        ]);

        $this->assertTrue($this->categories->isInUse($category));

        $result = $this->categories->deleteUserCategory($user->id, $category->id);

        $this->assertFalse($result['deleted'], 'Danh mục đã dùng không được xoá cứng.');
        $this->assertTrue(Category::whereKey($category->id)->exists());
        $this->assertFalse($category->refresh()->is_active);

        // Rule cũ vẫn tra được tên danh mục.
        $this->assertSame('Xăng', $category->name);
        $this->assertNotNull($policy->id);
    }

    #[Test]
    public function an_in_use_category_can_be_hidden_but_not_renamed(): void
    {
        $user = User::factory()->create();
        $category = $this->categories->createUserCategory($user->id, ['name' => 'Cafe']);
        $card = $this->makeUserCard($user->id);
        $this->makePolicyForCard($card, [['name' => 'T1', 'min' => 0, 'max' => null]], [
            ['category_id' => $category->id, 'percent' => '3.000'],
        ]);

        // Ẩn thì được.
        $hidden = $this->categories->updateUserCategory($user->id, $category->id, ['is_active' => false]);
        $this->assertFalse($hidden->is_active);

        // Đổi tên thì KHÔNG được — nhãn lịch sử đã bị ghi.
        $this->expectException(LogicException::class);
        $this->categories->updateUserCategory($user->id, $category->id, ['name' => 'Cafe mới']);
    }

    // =====================================================================
    // PolicyService — versioning append-only
    // =====================================================================

    #[Test]
    public function creating_a_policy_from_scratch_wires_the_card_to_it(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();

        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'Policy tự dựng',
            'tiers' => [[
                'name' => 'Bậc 1',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [['category_id' => $category->id, 'cashback_percent' => '2.500']],
            ]],
        ]);

        $this->assertSame(1, (int) $version->version_no);
        $this->assertSame($version->id, (int) $version->root_policy_id);
        $this->assertSame($version->id, (int) $card->refresh()->current_policy_id);
        $this->assertCount(1, $version->tiers);
        $tier = $version->tiers->first();
        $this->assertCount(1, $tier->tierCategoryRules()->categorySpecific()->get());
        $this->assertCount(1, $tier->tierCategoryRules()->fallback()->get(), 'Mỗi bậc có đúng 1 fallback mặc định.');
    }

    #[Test]
    public function creating_a_new_version_does_not_mutate_the_previous_one(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();

        $first = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [[
                'name' => 'Bậc 1',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [['category_id' => $category->id, 'cashback_percent' => '2.500']],
            ]],
        ]);

        $second = $this->policies->createVersion(
            $card->refresh(),
            CarbonImmutable::parse('2026-11-01'),
            ['name' => 'V2']
        );

        $this->assertSame(2, (int) $second->version_no);
        $this->assertSame($first->id, (int) $second->root_policy_id);

        // Version cũ phải còn nguyên tên, tỷ lệ và trạng thái.
        $firstRefreshed = $first->refresh();
        $this->assertSame('V1', $firstRefreshed->name);
        $this->assertSame(Policy::STATUS_SUPERSEDED, $firstRefreshed->status);
        $this->assertNotNull($firstRefreshed->effective_to);
        $this->assertSame(
            '2.500',
            $firstRefreshed->tiers->first()->tierCategoryRules()->whereNotNull('category_id')->first()->cashback_percent
        );

        // Version mới có bản ghi rule RIÊNG, không dùng chung id.
        $this->assertNotSame(
            $firstRefreshed->tiers->first()->tierCategoryRules->first()->id,
            $second->tiers->first()->tierCategoryRules->first()->id
        );
    }

    #[Test]
    public function a_user_template_cannot_be_used_by_another_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        // Thẻ của kẻ xâm nhậm: user id lấy từ thẻ ⇒ không thể "khai sai".
        $card = $this->makeUserCard($intruder->id);

        $template = PolicyTemplate::create([
            'scope' => PolicyTemplate::SCOPE_USER,
            'owner_user_id' => $owner->id,
            'name' => 'Template riêng của owner',
            'slug' => 'template-rieng',
            'is_builtin' => false,
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);

        $this->policies->cloneUserTemplate($card, $template->id, CarbonImmutable::parse('2026-10-01'));
    }

    #[Test]
    public function the_system_template_path_rejects_a_user_template(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = $this->makeUserCard($other->id);

        $template = PolicyTemplate::create([
            'scope' => PolicyTemplate::SCOPE_USER,
            'owner_user_id' => $owner->id,
            'name' => 'Template riêng của owner',
            'slug' => 'template-rieng-2',
            'is_builtin' => false,
            'is_active' => true,
        ]);

        // Đường vào khác phải chặn riêng. Nếu `cloneSystemTemplate()` không kiểm
        // scope thì kẻ xâm nhậm chỉ cần gọi đúng tên hàm "hệ thống" là đọc được
        // template riêng của người khác, né được kiểm tra sở hữu của đường kia.
        $this->expectException(InvalidArgumentException::class);

        $this->policies->cloneSystemTemplate($card, $template->id, CarbonImmutable::parse('2026-10-01'));
    }

    #[Test]
    public function a_policy_built_from_scratch_cannot_use_an_inactive_category(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory(['is_active' => false]);

        // Policy dựng từ đầu phải đi qua cùng bộ kiểm tra với đường tạo rule thủ
        // công, nếu không thì policy tạo bằng tay sẽ nhận cấu hình mà đường
        // thường vốn không cho phép.
        $this->expectException(InvalidArgumentException::class);

        $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [[
                'name' => 'Bậc 1',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [[
                    'category_id' => $category->id,
                    'cashback_percent' => '5.000',
                ]],
            ]],
        ]);
    }

    #[Test]
    public function creating_a_tier_rejects_an_inverted_band(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), ['name' => 'V1']);
        $policy = PolicyVersion::query()->findOrFail($version->id);

        $this->expectException(InvalidArgumentException::class);

        $this->tiers->create($policy, [
            'name' => 'Bậc sai',
            'min_total_spend' => 100,
            'max_total_spend' => 50,
        ]);
    }

    #[Test]
    public function creating_a_tier_rejects_a_band_that_overlaps_an_existing_one(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [['name' => 'Bậc 1', 'min_total_spend' => 0, 'max_total_spend' => 1000000]],
        ]);
        $policy = PolicyVersion::query()->findOrFail($version->id);

        $this->expectException(LogicException::class);

        // Khoảng 500k..2tr nằm lọt trong 0..1tr của bậc đã có.
        $this->tiers->create($policy, [
            'name' => 'Bậc chồng',
            'min_total_spend' => 500000,
            'max_total_spend' => 2000000,
        ]);
    }

    #[Test]
    public function saving_a_policy_as_a_template_assigns_it_to_the_cards_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $card = $this->makeUserCard($owner->id);
        $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), ['name' => 'V1']);

        $template = $this->policies->saveAsUserTemplate($card, 'Template của tôi');

        $this->assertTrue($template->isOwnedBy($owner->id), 'Template phải thuộc user sở hữu thẻ.');
        $this->assertFalse($template->isOwnedBy($other->id));
    }

    #[Test]
    public function saving_a_card_policy_as_a_template_deep_clones_the_configuration(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();

        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'Cấu hình gốc',
            'tiers' => [[
                'name' => 'Bậc 1',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [['category_id' => $category->id, 'cashback_percent' => '4.000']],
            ]],
        ]);

        $template = $this->policies->saveAsUserTemplate($card, 'Template của tôi');

        $this->assertFalse($template->isSystemScope());
        $this->assertTrue($template->isOwnedBy($user->id));
        $this->assertNull($template->blueprint->user_card_id, 'Blueprint không thuộc thẻ nào.');
        $this->assertNotSame($version->id, $template->blueprint->id);
        $this->assertNotSame(
            $version->tiers->first()->tierCategoryRules->first()->id,
            $template->blueprint->tiers->first()->tierCategoryRules->first()->id
        );
    }

    #[Test]
    public function a_locked_version_cannot_be_renamed(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), ['name' => 'V1']);

        $this->policies->lock($version);

        $this->expectException(LogicException::class);
        $this->policies->rename($card->refresh(), $version->id, 'Đổi tên sau khi khoá');
    }

    #[Test]
    public function detaching_a_policy_keeps_the_version_rows(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), ['name' => 'V1']);

        $this->policies->detachFromCard($card->refresh());

        $this->assertNull($card->refresh()->current_policy_id);
        $this->assertTrue(PolicyVersion::whereKey($version->id)->exists(), 'Không được xoá version lịch sử.');
    }

    // =====================================================================
    // TierService / CategoryRuleService
    // =====================================================================

    #[Test]
    public function a_tier_with_rules_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [[
                'name' => 'Bậc 1',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [['category_id' => $category->id, 'cashback_percent' => '2.000']],
            ]],
        ]);

        $tier = $version->tiers->first();

        $this->expectException(LogicException::class);
        $this->tiers->delete($tier->id);
    }

    #[Test]
    public function overlapping_tier_bands_are_rejected(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);

        // Bậc chồng lấn bị chặn NGAY khi dựng policy, không phải đợi tới lúc
        // resolve. Nếu chỉ kiểm sau, policy sai vẫn tồn tại và cashback của user
        // sẽ phụ thuộc thứ tự id ⇒ tiền user đổi theo cách hệ thống ghi bản ghi.
        try {
            $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
                'name' => 'V1',
                'tiers' => [
                    ['name' => 'Thấp', 'min_total_spend' => 0, 'max_total_spend' => 10000000],
                    ['name' => 'Cao', 'min_total_spend' => 5000000, 'max_total_spend' => null],
                ],
            ]);

            $this->fail('Khoảng bậc chồng lấn phải bị từ chối.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('chồng lấn', $e->getMessage());
        }

        // Toàn bộ transaction phải rollback: không để lại policy nửa vời.
        $this->assertSame(0, Policy::query()->where('user_card_id', $card->id)->count());
        $this->assertNull($card->refresh()->current_policy_id, 'Thẻ không được gắn policy sai.');
    }

    #[Test]
    public function non_overlapping_tier_bands_pass_validation(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [
                ['name' => 'Thấp', 'min_total_spend' => 0, 'max_total_spend' => 10000000],
                ['name' => 'Cao', 'min_total_spend' => 10000000, 'max_total_spend' => null],
            ],
        ]);

        $this->tiers->assertNoOverlappingBands($version);
        $this->assertTrue(true, 'Hai khoảng nửa mở liền nhau là hợp lệ.');
    }

    #[Test]
    public function a_tier_band_must_be_sane(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [['name' => 'Bậc 1', 'min_total_spend' => 0, 'max_total_spend' => null]],
        ]);

        $tier = $version->tiers->first();

        $this->expectException(InvalidArgumentException::class);
        $this->tiers->update($tier->id, ['min_total_spend' => 100, 'max_total_spend' => 50]);
    }

    #[Test]
    public function a_rule_cannot_point_at_an_inactive_category(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory(['is_active' => false]);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), ['name' => 'V1']);
        $tier = $version->tiers->first();

        $this->expectException(InvalidArgumentException::class);
        $this->rules->create($tier, ['category_id' => $category->id, 'cashback_percent' => '5.000']);
    }

    #[Test]
    public function cashback_percent_is_bounded_to_100(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), ['name' => 'V1']);
        $tier = $version->tiers->first();

        $this->expectException(InvalidArgumentException::class);
        $this->rules->create($tier, ['category_id' => $category->id, 'cashback_percent' => '120']);
    }

    #[Test]
    public function cloning_a_rule_produces_an_independent_record(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [
                ['name' => 'Bậc 1', 'min_total_spend' => 0, 'max_total_spend' => 10000000],
                ['name' => 'Bậc 2', 'min_total_spend' => 10000000, 'max_total_spend' => null],
            ],
        ]);

        $source = $version->tiers->first();
        $rule = $this->rules->create($source, [
            'category_id' => $category->id,
            'cashback_percent' => '2.000',
            'spend_from' => 0,
        ]);

        $target = $version->tiers->last();
        $copy = $this->rules->cloneRuleTo($rule, $target);

        $this->assertNotSame($rule->id, $copy->id);
        $this->assertSame((int) $target->id, (int) $copy->tier_id);
        $this->assertSame('2.000', $copy->cashback_percent);

        // Sửa bản ghi nguồn không kéo bản sao đi theo.
        $this->rules->update($rule->id, ['cashback_percent' => '9.000']);
        $this->assertSame('2.000', $copy->refresh()->cashback_percent);
    }

    #[Test]
    public function rules_of_a_superseded_version_are_frozen(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [['name' => 'Bậc 1', 'min_total_spend' => 0, 'max_total_spend' => null]],
        ]);
        $rule = $this->rules->create($version->tiers->first(), [
            'category_id' => $category->id,
            'cashback_percent' => '2.000',
        ]);

        $this->policies->createVersion($card->refresh(), CarbonImmutable::parse('2026-11-01'));

        $this->expectException(LogicException::class);
        $this->rules->update($rule->id, ['cashback_percent' => '8.000']);
    }

    #[Test]
    public function tier_and_rule_listing_is_ordered(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [
                ['name' => 'Thấp', 'min_total_spend' => 0, 'max_total_spend' => 10000000],
                ['name' => 'Cao', 'min_total_spend' => 10000000, 'max_total_spend' => null],
            ],
        ]);

        $tiers = $this->tiers->listFor($version);
        $this->assertSame(['Thấp', 'Cao'], $tiers->pluck('name')->all());
        $listed = $this->rules->listFor($tiers->first());
        $this->assertCount(0, $listed->reject(fn ($rule) => $rule->isFallback()));
        $this->assertCount(1, $listed->filter(fn ($rule) => $rule->isFallback()));
    }

    // =====================================================================
    // §15 — FALLBACK "📦 CÁC DANH MỤC CÒN LẠI"
    // =====================================================================

    #[Test]
    public function a_new_tier_automatically_gets_the_catch_all_fallback(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [
                ['name' => 'Bậc 1', 'min_total_spend' => 0, 'max_total_spend' => 10000000],
            ],
        ]);

        $tier = $this->tiers->create($version, [
            'name' => 'Bậc mới',
            'min_total_spend' => 10000000,
            'max_total_spend' => null,
        ]);

        $fallbacks = $tier->tierCategoryRules()->fallback()->get();

        $this->assertCount(1, $fallbacks, 'Mỗi bậc có đúng 1 fallback mặc định.');
        $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallbacks->first()->scope_type);
        $this->assertNull($fallbacks->first()->category_id);
        $this->assertSame('0.000', $fallbacks->first()->cashback_percent);
        $this->assertFalse((bool) $fallbacks->first()->counts_toward_tier_cap);
        $this->assertTrue((bool) $fallbacks->first()->is_enabled);
        $this->assertSame(PolicyTierCategory::FALLBACK_NAME, $fallbacks->first()->name);
    }

    #[Test]
    public function the_fallback_rule_sorts_after_the_specific_rules(): void
    {
        $category = $this->makeSystemCategory();
        $template = $this->policies->createSystemTemplate(
            'Policy fallback cuối',
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            ['category_id' => $category->id, 'cashback_percent' => '2.000'],
                        ],
                    ],
                ],
            ],
            true,
        );

        $ordered = $this->rules->listFor($template->defaultBlueprint()->tiers()->firstOrFail());

        $this->assertCount(1, $ordered->reject(fn ($rule) => $rule->isFallback()));
        $this->assertTrue($ordered->last()->isFallback(), 'Fallback render sau các rule cụ thể.');
        $this->assertLessThan((int) $ordered->last()->sort_order, (int) $ordered->first()->sort_order);
    }

    #[Test]
    public function ensure_single_fallback_is_idempotent(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), ['name' => 'V1']);
        $tier = $version->tiers->first();

        $first = $this->rules->ensureSingleFallback($tier);
        $second = $this->rules->ensureSingleFallback($tier);

        $this->assertSame((int) $first->id, (int) $second->id, 'Chạy lại không tạo fallback thứ hai.');
        $this->assertCount(1, $tier->tierCategoryRules()->fallback()->get());
    }

    #[Test]
    public function ensure_single_fallback_applies_provided_config_when_creating(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $policy = $this->makePolicyForCard($card, [['name' => 'Bậc 1', 'min' => 0, 'max' => null]], []);
        $tier = $policy->tiers->first();

        $fallback = $this->rules->ensureSingleFallback($tier, [
            'cashback_percent' => 5,
            'counts_toward_tier_cap' => true,
            'name' => 'Khác',
        ]);

        $this->assertSame('5.000', $fallback->cashback_percent);
        $this->assertTrue((bool) $fallback->counts_toward_tier_cap);
        $this->assertSame('Khác', $fallback->name);
        $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallback->scope_type);
        $this->assertNull($fallback->category_id);
    }

    #[Test]
    public function ensure_single_fallback_collapses_duplicate_fallbacks_keeping_the_first(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $policy = $this->makePolicyForCard($card, [['name' => 'Bậc 1', 'min' => 0, 'max' => null]], []);
        $tier = $policy->tiers->first();

        $first = PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => null,
            'scope_type' => PolicyTierCategory::SCOPE_OTHER,
            'counts_toward_tier_cap' => false,
            'sort_order' => 1,
            'cashback_percent' => '0.000',
        ]);
        PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => null,
            'scope_type' => PolicyTierCategory::SCOPE_OTHER,
            'counts_toward_tier_cap' => false,
            'sort_order' => 2,
            'cashback_percent' => '0.000',
        ]);

        $kept = $this->rules->ensureSingleFallback($tier);

        $this->assertSame((int) $first->id, (int) $kept->id, 'Giữ fallback đầu tiên theo sort_order.');
        $this->assertCount(1, $tier->tierCategoryRules()->fallback()->get());
    }

    #[Test]
    public function creating_a_second_fallback_rule_is_rejected(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $policy = $this->makePolicyForCard($card, [['name' => 'Bậc 1', 'min' => 0, 'max' => null]], []);
        $tier = $policy->tiers->first();

        $this->rules->create($tier, ['scope_type' => 'other']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Chỉ được có một quy tắc "'.PolicyTierCategory::FALLBACK_NAME.'" trong mỗi bậc.');
        $this->rules->create($tier, ['scope_type' => 'other']);
    }

    #[Test]
    public function a_category_rule_requires_a_category_id(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $policy = $this->makePolicyForCard($card, [['name' => 'Bậc 1', 'min' => 0, 'max' => null]], []);
        $tier = $policy->tiers->first();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quy tắc danh mục phải chọn một danh mục cụ thể.');
        $this->rules->create($tier, []);
    }

    #[Test]
    public function the_fallback_rule_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $policy = $this->makePolicyForCard($card, [['name' => 'Bậc 1', 'min' => 0, 'max' => null]], []);
        $tier = $policy->tiers->first();
        $fallback = $this->rules->ensureSingleFallback($tier);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Quy tắc "'.PolicyTierCategory::FALLBACK_NAME.'" là quy tắc mặc định của mỗi bậc và không thể xóa.');
        $this->rules->delete($fallback->id);
    }

    #[Test]
    public function a_specific_rule_cannot_be_converted_to_fallback_when_one_exists(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [
                [
                    'name' => 'Bậc 1',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [
                        ['category_id' => $category->id, 'cashback_percent' => '2.000'],
                    ],
                ],
            ],
        ]);
        $specific = $version->tiers->first()->tierCategoryRules()->whereNotNull('category_id')->first();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Chỉ được có một quy tắc "'.PolicyTierCategory::FALLBACK_NAME.'" trong mỗi bậc.');
        $this->rules->update($specific->id, ['scope_type' => 'other']);
    }

    #[Test]
    public function tier_resolver_flattens_the_fallback_scope_and_cap_flag(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [
                [
                    'name' => 'Bậc 1',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [
                        ['category_id' => $category->id, 'cashback_percent' => '2.000'],
                    ],
                ],
            ],
        ]);

        $rows = app(TierResolverService::class)->rulesForTier($version->tiers->first());
        $specific = collect($rows)->first(fn (array $rule): bool => $rule['scope_type'] === 'category');
        $fallback = collect($rows)->first(fn (array $rule): bool => $rule['scope_type'] === 'other');

        $this->assertNotNull($specific);
        $this->assertTrue($specific['counts_toward_tier_cap'], 'Rule cụ thể mặc định tính vào cap bậc.');
        $this->assertNotNull($fallback, 'Resolver phẳng phải chứa fallback.');
        $this->assertNull($fallback['category_id']);
        $this->assertFalse($fallback['counts_toward_tier_cap']);
        $this->assertSame(0.0, $fallback['cashback_percent']);
    }

    #[Test]
    public function all_enabled_rules_include_each_tiers_fallback_exactly_once(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $version = $this->policies->createFromScratch($card, CarbonImmutable::parse('2026-10-01'), [
            'name' => 'V1',
            'tiers' => [
                ['name' => 'Thấp', 'min_total_spend' => 0, 'max_total_spend' => 10000000],
                ['name' => 'Cao', 'min_total_spend' => 10000000, 'max_total_spend' => null],
            ],
        ]);

        $rows = app(TierResolverService::class)->allEnabledRulesFor($version);
        $fallbacks = collect($rows)->filter(fn (array $rule): bool => $rule['scope_type'] === 'other');

        $this->assertSame(2, $fallbacks->count(), 'Mỗi bậc đóng góp đúng 1 fallback.');
        $this->assertSame(
            collect($rows)->groupBy('tier_id')->count(),
            $fallbacks->unique('tier_id')->count(),
            'Mỗi tier chỉ có một fallback trong danh sách phẳng.',
        );

        foreach ($fallbacks as $fallback) {
            $this->assertNull($fallback['category_id']);
            $this->assertFalse($fallback['counts_toward_tier_cap']);
        }
    }
}
