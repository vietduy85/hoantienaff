<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\SpendQualification;
use App\Models\CreditCard\SpendQualificationCondition;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * "Lựa chọn thẻ" (/thetindung/so-sanh + GET /thetindung/so-sanh/de-xuat).
 *
 * Khóa các bất biến của `CardRecommendationService`:
 *   - Endpoint JSON chỉ đọc, scope theo `auth()->id()`, validate input.
 *   - "Thẻ của tôi": kỳ HIỆN TẠI + giao dịch giả định, tái dùng engine (tier
 *     retroactive, cap, qualification).
 *   - "Thẻ trên thị trường": template hệ thống đang bật, KHÔNG áp qualification.
 *   - Thứ tự đề xuất: của tôi = tiền hoàn → tỷ lệ → tên; thị trường = tỷ lệ →
 *     tiền hoàn → tên.
 */
class CardRecommendationTest extends TestCase
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
    // 0. Trang HTML: render + danh mục trong phạm vi
    // =====================================================================

    #[Test]
    public function the_page_renders_with_the_users_selectable_categories(): void
    {
        $system = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $own = $this->makeUserCategory($this->owner->id, ['name' => 'Danh mục của tôi']);
        $foreign = $this->makeUserCategory($this->stranger->id, ['name' => 'Danh mục người khác']);

        $response = $this->actingAs($this->owner)->get(route('credit-cards.compare'));

        $response->assertOk();
        $response->assertSee('Lựa chọn thẻ');

        $ids = array_column($response->viewData('categories'), 'id');

        $this->assertContains($system->id, $ids);
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    // =====================================================================
    // 1-3. Endpoint: xác thực + validate + phạm vi danh mục
    // =====================================================================

    #[Test]
    public function the_recommendation_endpoint_requires_authentication(): void
    {
        $this->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => 1,
        ]))->assertUnauthorized();
    }

    #[Test]
    public function it_validates_amount_and_category(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.recommend', ['category_id' => $category->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.recommend', ['amount' => 0, 'category_id' => $category->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.recommend', ['amount' => 1000000]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    #[Test]
    public function it_rejects_a_category_outside_the_users_scope(): void
    {
        $foreign = $this->makeUserCategory($this->stranger->id);

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.recommend', [
                'amount' => 1000000,
                'category_id' => $foreign->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    // =====================================================================
    // 4-6. "Thẻ của tôi": hoàn tiền / lý do không hoàn tiền
    // =====================================================================

    #[Test]
    public function it_recommends_a_card_with_cashback_for_the_hypothetical_transaction(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ chính']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '2.000']]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.scenario.amount', '1000000.00');
        $response->assertJsonPath('data.scenario.category_id', $category->id);
        $response->assertJsonPath('data.scenario.category_name', $category->name);

        $response->assertJsonPath('data.my_cards.0.card_id', $card->id);
        $response->assertJsonPath('data.my_cards.0.eligible', true);
        $response->assertJsonPath('data.my_cards.0.cashback', '20000.00');
        $this->assertSame(2.0, (float) $response->json('data.my_cards.0.rate'));
    }

    #[Test]
    public function it_reports_no_policy_for_a_card_without_a_policy(): void
    {
        $category = $this->makeSystemCategory();
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ chưa có chính sách']);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.eligible', false);
        $response->assertJsonPath('data.my_cards.0.reason', Transaction::REASON_NO_POLICY);
        $response->assertJsonPath('data.my_cards.0.cashback', '0.00');
    }

    #[Test]
    public function it_reports_below_minimum_spend(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '2.000']],
            ['min_total_spend' => 5000000]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.eligible', false);
        $response->assertJsonPath('data.my_cards.0.reason', Transaction::REASON_BELOW_MINIMUM_SPEND);
    }

    // =====================================================================
    // 7-10. "Thẻ của tôi": thứ tự, tier retroactive, phạm vi
    // =====================================================================

    #[Test]
    public function it_ranks_my_cards_by_cashback_then_rate_then_name(): void
    {
        $category = $this->makeSystemCategory();

        foreach ([['B', '2.000'], ['A', '2.000'], ['C', '3.000']] as [$name, $percent]) {
            $card = $this->makeUserCard($this->owner->id, ['name' => $name]);

            $this->makePolicyForCard(
                $card,
                [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
                [['category_id' => $category->id, 'percent' => $percent]]
            );
        }

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        // C (3% → 30k) đứng đầu; A và B bằng tiền hoàn + tỷ lệ ⇒ tên A trước B.
        $this->assertSame(
            ['C', 'A', 'B'],
            array_column($response->json('data.my_cards'), 'card_name')
        );
    }

    #[Test]
    public function it_includes_current_period_spend_when_resolving_the_tier(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000],
                ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '1.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '5.000', 'only_tier' => 1],
            ]
        );

        // Giao dịch thực trong kỳ đẩy tổng vượt mốc 10.000.000 ⇒ bậc 2 (5%).
        $this->makeTransaction($card, $category->id, '20000000.00');

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.tier_name', 'Bậc 2');
        $response->assertJsonPath('data.my_cards.0.cashback', '50000.00');
        $this->assertSame(5.0, (float) $response->json('data.my_cards.0.rate'));
    }

    #[Test]
    public function it_ignores_transactions_outside_the_current_period(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000],
                ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '1.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '5.000', 'only_tier' => 1],
            ]
        );

        // Cùng khoản chi nhưng nằm NGOÀI kỳ hiện tại ⇒ không tính vào tổng.
        Transaction::create([
            'user_card_id' => $card->id,
            'category_id' => $category->id,
            'amount' => '20000000.00',
            'transaction_date' => '2000-01-01',
            'is_eligible' => true,
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.tier_name', 'Bậc 1');
        $response->assertJsonPath('data.my_cards.0.cashback', '10000.00');
    }

    #[Test]
    public function it_only_uses_the_users_active_cards(): void
    {
        $category = $this->makeSystemCategory();

        $active = $this->makeUserCard($this->owner->id, ['name' => 'Đang dùng']);
        $this->makePolicyForCard(
            $active,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '2.000']]
        );

        // Thẻ đã đóng (closed_at) và thẻ trạng thái khác active ⇒ loại.
        $this->makeUserCard($this->owner->id, [
            'name' => 'Đã đóng',
            'status' => UserCard::STATUS_ACTIVE,
            'closed_at' => CarbonImmutable::now(),
        ]);
        $this->makeUserCard($this->owner->id, [
            'name' => 'Tạm dừng',
            'status' => 'closed',
        ]);

        // Thẻ của người khác ⇒ không bao giờ lọt vào.
        $this->makeUserCard($this->stranger->id, ['name' => 'Thẻ người khác']);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $this->assertSame(
            ['Đang dùng'],
            array_column($response->json('data.my_cards'), 'card_name')
        );
    }

    // =====================================================================
    // 7b-8b. "Thẻ của tôi": qualification + cap (tái dùng engine)
    // =====================================================================

    #[Test]
    public function it_applies_the_qualification_gate_to_my_cards(): void
    {
        $spendCategory = $this->makeSystemCategory(['name' => 'Chi tiêu']);
        $gateCategory = $this->makeSystemCategory(['name' => 'Danh mục điều kiện']);
        $card = $this->makeUserCard($this->owner->id);

        $policy = $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $spendCategory->id, 'percent' => '2.000']]
        );

        $qualification = SpendQualification::create([
            'policy_version_id' => $policy->id,
            'name' => 'Điều kiện kỳ',
            'enabled' => true,
        ]);

        SpendQualificationCondition::create([
            'qualification_id' => $qualification->id,
            'condition_type' => SpendQualificationCondition::TYPE_CATEGORY,
            'category_id' => $gateCategory->id,
            'min_spend' => '5000000.00',
            'is_enabled' => true,
            'sort_order' => 0,
        ]);

        // Nhu cầu ở `spendCategory` không thoả điều kiện chi ở `gateCategory`.
        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $spendCategory->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.eligible', false);
        $response->assertJsonPath('data.my_cards.0.reason', Transaction::REASON_QUALIFICATION_NOT_MET);
    }

    #[Test]
    public function the_hypothetical_transaction_consumes_only_the_remaining_period_cap(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        // Trần toàn kỳ 60.000; rule 10%. Giao dịch thực 500.000 đã tiêu 50.000,
        // nên giao dịch giả định chỉ còn 10.000 phần cap.
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '60000.00']],
            [['category_id' => $category->id, 'percent' => '10.000']]
        );

        $this->makeTransaction($card, $category->id, '500000.00');

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 500000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.cashback', '10000.00');
        $this->assertContains(
            'per_period_total',
            $response->json('data.my_cards.0.caps_applied')
        );
    }

    // =====================================================================
    // 9-11. "Thẻ trên thị trường": lọc + thứ tự + bỏ qua qualification
    // =====================================================================

    #[Test]
    public function it_lists_market_templates_sorted_by_rate_then_cashback(): void
    {
        $category = $this->makeSystemCategory();
        $otherCategory = $this->makeSystemCategory();

        $lower = $this->makeMarketTemplate('Thẻ 2%', $category->id, '2.000', sortOrder: 0);
        $higher = $this->makeMarketTemplate('Thẻ 3%', $category->id, '3.000', sortOrder: 1);
        // Không hoàn tiền cho danh mục đang hỏi ⇒ bị loại.
        $this->makeMarketTemplate('Thẻ danh mục khác', $otherCategory->id, '10.000', sortOrder: 2);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        $this->assertSame(
            [$higher->id, $lower->id],
            array_column($response->json('data.market_cards'), 'template_id')
        );
        $response->assertJsonPath('data.market_cards.0.rate', 3);
        $response->assertJsonPath('data.market_cards.0.cashback', '30000.00');
    }

    #[Test]
    public function it_skips_market_templates_without_a_blueprint(): void
    {
        $category = $this->makeSystemCategory();
        $this->makeSystemTemplate(['name' => 'Template không blueprint']);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonCount(0, 'data.market_cards');
    }

    #[Test]
    public function it_does_not_apply_the_qualification_gate_to_market_templates(): void
    {
        $category = $this->makeSystemCategory();
        $gateCategory = $this->makeSystemCategory(['name' => 'Điều kiện thị trường']);

        $template = $this->makeMarketTemplate('Thẻ có điều kiện', $category->id, '2.000');

        $qualification = SpendQualification::create([
            'policy_version_id' => $template->currentBlueprint()->id,
            'name' => 'Điều kiện',
            'enabled' => true,
        ]);

        SpendQualificationCondition::create([
            'qualification_id' => $qualification->id,
            'condition_type' => SpendQualificationCondition::TYPE_CATEGORY,
            'category_id' => $gateCategory->id,
            'min_spend' => '100000000.00',
            'is_enabled' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data.market_cards');
        $response->assertJsonPath('data.market_cards.0.template_id', $template->id);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function makeTransaction(UserCard $card, int $categoryId, string $amount): Transaction
    {
        return Transaction::create([
            'user_card_id' => $card->id,
            'category_id' => $categoryId,
            'amount' => $amount,
            'transaction_date' => $this->currentPeriodStart($card)->toDateString(),
            'is_eligible' => true,
        ]);
    }

    private function currentPeriodStart(UserCard $card): CarbonImmutable
    {
        [$start] = app(StatementPeriodService::class)->currentBoundaries($card);

        return $start;
    }

    /**
     * Template hệ thống + blueprint 1 bậc / 1 quy tắc danh mục.
     */
    private function makeMarketTemplate(
        string $name,
        int $categoryId,
        string $percent,
        ?string $capPeriod = null,
        int $sortOrder = 0,
    ): PolicyTemplate {
        $template = $this->makeSystemTemplate(['name' => $name, 'sort_order' => $sortOrder]);

        $blueprint = Policy::create([
            'template_id' => $template->id,
            'user_card_id' => null,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $name,
            'effective_from' => '2000-01-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);
        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        $tier = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Bậc cơ bản',
            'sort_order' => 0,
            'min_total_spend' => 0,
            'max_total_spend' => null,
            'max_cashback_per_period' => $capPeriod,
        ]);

        PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $categoryId,
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'spend_from' => 0,
            'cashback_percent' => $percent,
            'is_enabled' => true,
        ]);

        return $template->refresh();
    }
}
