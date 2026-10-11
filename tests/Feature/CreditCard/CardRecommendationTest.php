<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\SpendQualification;
use App\Models\CreditCard\SpendQualificationCondition;
use App\Models\CreditCard\StatementPeriod;
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
 *   - Ngưỡng hiển thị: chỉ bậc có tỷ lệ >= 2% mới hiện; thẻ không còn bậc nào
 *     đạt ngưỡng thì bị ẩn (áp cho cả hai nhóm; bậc đúng 2% vẫn GIỮ).
 *   - Ngoại lệ "Thẻ của tôi": khi KHÔNG còn bậc nào > 2% cho cả nhóm, chuyển sang
 *     chế độ tối ưu mốc chi tiêu — KHÔNG lọc ngưỡng, ưu tiên thẻ đang thiếu chi
 *     tiêu hợp lệ. "Thẻ trên thị trường" luôn giữ ngưỡng 2%.
 *   - Thứ tự đề xuất: của tôi = tiền hoàn thực → tỷ lệ → tên; thị trường = tỷ lệ
 *     cao nhất mọi bậc → tiền hoàn tiềm năng → tên.
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

    #[Test]
    public function the_page_highlights_the_max_spend_advice_and_market_tier_list(): void
    {
        $response = $this->actingAs($this->owner)->get(route('credit-cards.compare'));

        $response->assertOk();
        // Nhãn "Nên chi tối đa" được nhấn mạnh trong markup (khối riêng, đậm, nền).
        $response->assertSee('Nên chi tối đa');
        // Mỗi thẻ thị trường liệt kê đủ các bậc, không gộp.
        $response->assertSee('Các bậc hoàn tiền cho danh mục này');
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
            [['category_id' => $category->id, 'percent' => '3.000']]
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
        $response->assertJsonPath('data.my_cards.0.cashback', '30000.00');
        $this->assertSame(3.0, (float) $response->json('data.my_cards.0.rate'));
    }

    #[Test]
    public function it_hides_a_card_without_a_policy(): void
    {
        $category = $this->makeSystemCategory();
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ chưa có chính sách']);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        // Không xác định được bậc >= ngưỡng hiển thị ⇒ thẻ bị ẩn hoàn toàn.
        $response->assertJsonCount(0, 'data.my_cards');
    }

    #[Test]
    public function it_reports_below_minimum_spend(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '3.000']],
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

        foreach ([['B', '3.000'], ['A', '3.000'], ['C', '4.000']] as [$name, $percent]) {
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

        // C (4% → 40k) đứng đầu; A và B bằng tiền hoàn + tỷ lệ ⇒ tên A trước B.
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
            [['category_id' => $category->id, 'percent' => '3.000']]
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
            [['category_id' => $spendCategory->id, 'percent' => '3.000']]
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
    public function it_ranks_market_cards_by_the_highest_eligible_tier_not_the_applied_tier(): void
    {
        $category = $this->makeSystemCategory();
        $otherCategory = $this->makeSystemCategory();

        // Thẻ nhiều bậc: bậc 1 (3%) đang áp dụng cho khoản 1tr, nhưng còn bậc 2 (6%).
        $tiered = $this->makeMarketTemplateWithTiers('Thẻ nhiều bậc', $category->id, [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000, 'percent' => '3.000'],
            ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null, 'percent' => '6.000'],
        ]);
        // Thẻ phẳng 4%: chỉ một bậc nhưng cao hơn bậc đang áp dụng của thẻ kia.
        $flat = $this->makeMarketTemplate('Thẻ phẳng 4%', $category->id, '4.000', sortOrder: 1);
        // Không hoàn tiền cho danh mục đang hỏi ⇒ bị loại.
        $this->makeMarketTemplate('Thẻ danh mục khác', $otherCategory->id, '10.000', sortOrder: 2);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        // Tỷ lệ cao nhất MỌI bậc: nhiều bậc (6%) trước phẳng (4%), dù bậc đang áp
        // dụng của thẻ nhiều bậc (3%) thấp hơn thẻ phẳng.
        $this->assertSame(
            [$tiered->id, $flat->id],
            array_column($response->json('data.market_cards'), 'template_id')
        );
        $response->assertJsonPath('data.market_cards.0.rate', 6);
        $response->assertJsonPath('data.market_cards.0.applies', false);
    }

    #[Test]
    public function it_breaks_market_ties_by_potential_cashback_then_name(): void
    {
        $category = $this->makeSystemCategory();

        // Cùng 5% nhưng cap khác ⇒ tiền hoàn tiềm năng khác (20.000 vs 50.000).
        $capped = $this->makeMarketTemplate('Thẻ cap thấp', $category->id, '5.000', capPeriod: '20000.00', sortOrder: 0);
        $uncapped = $this->makeMarketTemplate('Thẻ không cap', $category->id, '5.000', sortOrder: 1);
        // Cùng 5% + cùng tiềm năng ⇒ so tên tăng dần.
        $alpha = $this->makeMarketTemplate('A thẻ 5%', $category->id, '5.000', sortOrder: 2);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $this->assertSame(
            [$alpha->id, $uncapped->id, $capped->id],
            array_column($response->json('data.market_cards'), 'template_id')
        );
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

        $template = $this->makeMarketTemplate('Thẻ có điều kiện', $category->id, '3.000');

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
    // 11b-11e. Thị trường: blueprint mặc định superseded + liệt kê mọi bậc
    // =====================================================================

    #[Test]
    public function it_lists_a_market_template_whose_default_blueprint_is_superseded(): void
    {
        // Tình trạng MB Ultimate JCB: blueprint của template đã bị "superseded"
        // nhưng vẫn là bản MẶC ĐỊNH để cấp cho user mới. Nguồn thị trường phải
        // theo `defaultBlueprint()` nên thẻ KHÔNG được biến mất.
        $category = $this->makeSystemCategory(['name' => 'Y tế & Bệnh viện']);

        $template = $this->makeMarketTemplateWithTiers(
            'MB Ultimate JCB',
            $category->id,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'percent' => '3.000']],
            blueprintStatus: Policy::STATUS_SUPERSEDED,
            pointDefaultVersion: true,
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 5000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data.market_cards');
        $response->assertJsonPath('data.market_cards.0.template_id', $template->id);
        $response->assertJsonPath('data.market_cards.0.cashback', '150000.00');
    }

    #[Test]
    public function it_still_skips_a_market_template_without_any_default_blueprint(): void
    {
        $category = $this->makeSystemCategory();

        // Template không có blueprint nào (default_version_id NULL) ⇒ loại.
        $this->makeSystemTemplate(['name' => 'Không có blueprint mặc định']);

        // Template có blueprint nhưng KHÔNG đánh dấu mặc định và HP đã bị huỷ.
        $this->makeMarketTemplateWithTiers(
            'Blueprint bị huỷ',
            $category->id,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'percent' => '2.000']],
            blueprintStatus: Policy::STATUS_SUPERSEDED,
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonCount(0, 'data.market_cards');
    }

    #[Test]
    public function it_removes_a_market_tier_just_below_the_display_threshold(): void
    {
        $category = $this->makeSystemCategory();

        $template = $this->makeMarketTemplateWithTiers('Thẻ nhiều bậc', $category->id, [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000, 'percent' => '1.990'],
            ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null, 'percent' => '15.000'],
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 5000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.market_cards.0.template_id', $template->id);

        // Bậc 1 (1,99%) dưới ngưỡng hiển thị ⇒ bị loại; chỉ còn Bậc 2 (15%).
        $response->assertJsonCount(1, 'data.market_cards.0.tiers');
        $tiers = collect($response->json('data.market_cards.0.tiers'))->keyBy('name');

        $this->assertArrayNotHasKey('Bậc 1', $tiers->all());
        $this->assertSame(15.0, (float) $tiers['Bậc 2']['cashback_percent']);
        $this->assertSame('750000.00', $tiers['Bậc 2']['cashback']);
        $this->assertStringContainsString('10.000.000đ', $tiers['Bậc 2']['condition']);

        // Top-level = bậc TIỀM NĂNG cao nhất (Bậc 2), chưa đạt vì 5tr < 10tr.
        $response->assertJsonPath('data.market_cards.0.rate', 15);
        $response->assertJsonPath('data.market_cards.0.applies', false);
        $response->assertJsonPath('data.market_cards.0.status', 'not_met');
        $response->assertJsonPath('data.market_cards.0.tiers.0.shortfall', '5000000.00');
    }

    #[Test]
    public function it_shows_an_unmet_higher_tier_as_untagged_potential_with_shortfall(): void
    {
        // Kịch bản LPBank Ultimate JCB: chi 5.000.000đ ở "Y tế & Bệnh viện".
        // Bậc 2 hoàn 15% có điều kiện tổng chi 8.000.000đ ⇒ CHƯA đạt.
        $category = $this->makeSystemCategory(['name' => 'Y tế & Bệnh viện']);

        $this->makeMarketTemplateWithTiers('LPBank Ultimate JCB', $category->id, [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => 8000000, 'percent' => '3.000'],
            ['name' => 'Bậc 2', 'min' => 8000000, 'max' => null, 'percent' => '15.000'],
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 5000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        $tiers = collect($response->json('data.market_cards.0.tiers'))->keyBy('name');

        // Bậc 15% PHẢI hiển thị dù chưa đạt...
        $this->assertTrue($tiers->has('Bậc 2'));
        $this->assertSame(15.0, (float) $tiers['Bậc 2']['cashback_percent']);

        // ...nhưng KHÔNG được gán nhãn "đang áp dụng" (applied).
        $this->assertFalse($tiers['Bậc 2']['applies']);
        $this->assertSame('not_met', $tiers['Bậc 2']['status']);

        // Nêu rõ số tiền còn thiếu để đạt bậc (8.000.000 − 5.000.000).
        $this->assertSame('3000000.00', $tiers['Bậc 2']['shortfall']);

        // Tiền hoàn là TIỀM NĂNG (15% × 5tr), không phải khoản chắc chắn.
        $this->assertSame('750000.00', $tiers['Bậc 2']['cashback']);

        // Bậc 1 (3%) đang áp dụng vẫn được đánh dấu applied.
        $this->assertTrue($tiers['Bậc 1']['applies']);
        $this->assertSame('applied', $tiers['Bậc 1']['status']);
    }

    #[Test]
    public function it_does_not_limit_market_cashback_by_the_users_used_quota(): void
    {
        $category = $this->makeSystemCategory();

        // Cùng chính sách 10% + trần 50.000 cho cả thẻ thị trường lẫn thẻ user.
        $this->makeMarketTemplate('Thẻ trần kỳ', $category->id, '10.000', capPeriod: '50000.00');

        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => '50000.00']],
            [['category_id' => $category->id, 'percent' => '10.000']]
        );
        // Thẻ user đã dùng HẾT trần cả kỳ.
        $this->makeTransaction($card, $category->id, '500000.00');

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        // Thẻ của tôi: hết quota ⇒ 0 cho giao dịch thêm.
        $response->assertJsonPath('data.my_cards.0.cashback', '0.00');

        // Thị trường: KHÔNG bị quota user giới hạn ⇒ vẫn ăn đủ trần chính sách.
        $response->assertJsonPath('data.market_cards.0.cashback', '50000.00');
        $this->assertContains(
            'per_period_total',
            $response->json('data.market_cards.0.caps_applied')
        );
    }

    // =====================================================================
    // 12-16. "Nên chi tối đa" (max_spend)
    // =====================================================================

    #[Test]
    public function it_advises_a_max_spend_from_the_remaining_category_quota(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        // Trần hoàn tiền mỗi danh mục 50.000; rule 10% ⇒ chi 500.000 là điểm đầy.
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000', 'cap_cat' => '50000.00']]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.max_spend.amount', '500000.00');
        $response->assertJsonPath('data.my_cards.0.max_spend.limiting_factor', 'per_category');
        $response->assertJsonPath('data.my_cards.0.max_spend.category_quota_remaining', '50000.00');
        $response->assertJsonPath('data.my_cards.0.max_spend.over_limit', '500000.00');
        $response->assertJsonPath('data.my_cards.0.max_spend.reason', null);
    }

    #[Test]
    public function it_advises_a_max_spend_from_the_per_transaction_cap(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        // Trần hoàn mỗi giao dịch 20.000; rule 10% ⇒ chi 200.000 là điểm đầy.
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000', 'cap_tx' => '20000.00']]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.max_spend.amount', '200000.00');
        $response->assertJsonPath('data.my_cards.0.max_spend.limiting_factor', 'per_transaction');
        $response->assertJsonPath('data.my_cards.0.max_spend.per_transaction_limit', '20000.00');
    }

    #[Test]
    public function it_reports_no_max_spend_when_the_quota_is_exhausted(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000', 'cap_cat' => '50000.00']]
        );

        // Khoản chi thực đã dùng hết quota danh mục 50.000.
        $this->makeTransaction($card, $category->id, '500000.00');

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.max_spend.amount', null);
        $response->assertJsonPath('data.my_cards.0.max_spend.reason', 'quota_exhausted');
        $response->assertJsonPath('data.my_cards.0.needs_spend', false);
    }

    #[Test]
    public function it_does_not_invent_a_max_spend_without_a_cap(): void
    {
        $category = $this->makeSystemCategory();

        // Rule 10% nhưng KHÔNG có trần ⇒ không suy ra được "nên chi tối đa".
        $noCap = $this->makeUserCard($this->owner->id, ['name' => 'Không giới hạn']);
        $this->makePolicyForCard(
            $noCap,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000']]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        $rows = collect($response->json('data.my_cards'))->keyBy('card_name');

        $this->assertNull($rows['Không giới hạn']['max_spend']['amount']);
        $this->assertSame('no_cap', $rows['Không giới hạn']['max_spend']['limiting_factor']);
    }

    #[Test]
    public function it_does_not_emit_max_spend_for_an_ineligible_card(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Chưa đủ chi tiêu']);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_cat' => '50000.00']],
            [['category_id' => $category->id, 'percent' => '3.000']],
            ['min_total_spend' => 5000000]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        // Thẻ vẫn hiển thị (có bậc >= ngưỡng) nhưng chưa đủ điều kiện ⇒ không gợi ý
        // "nên chi tối đa".
        $response->assertJsonPath('data.my_cards.0.eligible', false);
        $response->assertJsonPath('data.my_cards.0.reason', Transaction::REASON_BELOW_MINIMUM_SPEND);
        $response->assertJsonPath('data.my_cards.0.max_spend.amount', null);
        $response->assertJsonPath('data.my_cards.0.max_spend.reason', 'not_applicable');
    }

    // =====================================================================
    // 17. Nhãn bậc không lặp tiền tố
    // =====================================================================

    #[Test]
    public function it_does_not_double_the_tier_prefix(): void
    {
        $category = $this->makeSystemCategory();

        // Tên bậc đã có tiền tố ⇒ giữ nguyên; tên chỉ có tên gọi ⇒ mới thêm "Bậc".
        $prefixed = $this->makeUserCard($this->owner->id, ['name' => 'Bậc sẵn']);
        $this->makePolicyForCard(
            $prefixed,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '3.000']]
        );

        $bare = $this->makeUserCard($this->owner->id, ['name' => 'Tên trần']);
        $this->makePolicyForCard(
            $bare,
            [['name' => 'Bạch kim', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '3.000']]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        $rows = collect($response->json('data.my_cards'))->keyBy('card_name');
        $this->assertSame('Bậc 1', $rows['Bậc sẵn']['tier_label']);
        $this->assertSame('Bạch kim', $rows['Tên trần']['tier_name']);
        $this->assertSame('Bậc Bạch kim', $rows['Tên trần']['tier_label']);

        // Thị trường cũng dùng nhãn đã chuẩn hoá (không có "Bậc Bậc …").
        $this->makeMarketTemplate('Thẻ thị trường', $category->id, '3.000');

        $market = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $this->assertSame(
            'Bậc cơ bản',
            $market->json('data.market_cards.0.tier_label')
        );
    }

    // =====================================================================
    // 18-21. "Mốc chi tiêu" + ngưỡng hiển thị + không nâng bậc chưa đạt
    // =====================================================================

    #[Test]
    public function it_reports_how_far_the_transaction_is_from_the_next_tier(): void
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

        // Chưa chi gì; khoản 1.000.000 còn thiếu 9.000.000 để chạm Bậc 2.
        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.spend_goal.status', 'short');
        $response->assertJsonPath('data.my_cards.0.spend_goal.next_tier_label', 'Bậc 2');
        $response->assertJsonPath('data.my_cards.0.spend_goal.remaining', '9000000.00');
    }

    #[Test]
    public function it_includes_the_hypothetical_spend_when_checking_the_next_tier(): void
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
                ['category_id' => $category->id, 'percent' => '3.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '3.000', 'only_tier' => 1],
            ]
        );

        // Đã chi 9.500.000; thêm 1.000.000 ⇒ 10.500.000 vượt mốc Bậc 2.
        $this->makeTransaction($card, $category->id, '9500000.00');

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.spend_goal.status', 'short');
        $response->assertJsonPath('data.my_cards.0.spend_goal.remaining', '0.00');
        $this->assertStringContainsString(
            'giúp đạt Bậc 2',
            (string) $response->json('data.my_cards.0.spend_goal.message')
        );
    }

    #[Test]
    public function it_does_not_count_an_ineligible_transaction_toward_the_tier(): void
    {
        $category = $this->makeSystemCategory();
        $gateCategory = $this->makeSystemCategory(['name' => 'Điều kiện']);
        $card = $this->makeUserCard($this->owner->id);

        $policy = $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000],
                ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '3.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '3.000', 'only_tier' => 1],
            ]
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
            'min_spend' => '100000000.00',
            'is_enabled' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.my_cards.0.eligible', false);
        $response->assertJsonPath('data.my_cards.0.spend_goal.status', 'not_counted');
        $response->assertJsonPath('data.my_cards.0.needs_spend', false);
    }

    #[Test]
    public function it_does_not_promote_an_unmet_tier_when_ranking_my_cards(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ của tôi']);

        $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000],
                ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '3.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '15.000', 'only_tier' => 1],
            ]
        );

        // Khoản 1.000.000 chưa chạm mốc Bậc 2 (10.000.000).
        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        // Tiền hoàn THỰC vẫn theo bậc đang áp dụng (3% ⇒ 30.000), KHÔNG nâng lên 15%.
        $response->assertJsonPath('data.my_cards.0.tier_name', 'Bậc 1');
        $response->assertJsonPath('data.my_cards.0.cashback', '30000.00');
        $this->assertSame(3.0, (float) $response->json('data.my_cards.0.cashback_percent'));

        // Bậc cao hơn chỉ xuất hiện dạng tiềm năng, không bị gán "đang áp dụng".
        $tiers = collect($response->json('data.my_cards.0.tiers'))->keyBy('name');
        $this->assertFalse($tiers['Bậc 2']['applies']);
        $this->assertSame('not_met', $tiers['Bậc 2']['status']);
        $this->assertSame('9000000.00', $tiers['Bậc 2']['shortfall']);
    }

    #[Test]
    public function it_keeps_a_tier_at_exactly_two_percent(): void
    {
        $category = $this->makeSystemCategory();

        // Bậc 2% PHẢI giữ; thêm một thẻ 5% để cả nhóm ở chế độ thường.
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ 2%']);
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '2.000']]
        );

        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ 5%']);
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]
        );

        // Thị trường: 2% hiện, 1.99% ẩn.
        $marketTwo = $this->makeMarketTemplate('Thị trường 2%', $category->id, '2.000', sortOrder: 0);
        $this->makeMarketTemplate('Thị trường 1.99%', $category->id, '1.990', sortOrder: 1);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        $mine = collect($response->json('data.my_cards'))->keyBy('card_name');
        $this->assertTrue($mine->has('Thẻ 2%'));
        $this->assertSame('20000.00', $mine['Thẻ 2%']['cashback']);
        $this->assertFalse($mine['Thẻ 2%']['low_rate_regime']);

        $this->assertSame(
            [$marketTwo->id],
            array_column($response->json('data.market_cards'), 'template_id')
        );
    }

    #[Test]
    public function it_removes_a_my_card_tier_just_below_two_percent_under_the_normal_regime(): void
    {
        $category = $this->makeSystemCategory();

        // Có bậc > 2% ⇒ chế độ thường cho cả nhóm.
        $higher = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ 3%']);
        $this->makePolicyForCard(
            $higher,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '3.000']]
        );

        $below = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ 1.99%']);
        $this->makePolicyForCard(
            $below,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '1.990']]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        // Thẻ chỉ có 1.99% bị ẩn dưới ngưỡng thông thường.
        $this->assertSame(
            ['Thẻ 3%'],
            array_column($response->json('data.my_cards'), 'card_name')
        );
    }

    #[Test]
    public function it_shows_only_tiers_at_or_above_two_percent_when_a_higher_tier_exists(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        // Một thẻ có cả bậc 1% lẫn bậc 5% ⇒ chỉ bậc >= 2% được hiển thị.
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

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        $tiers = collect($response->json('data.my_cards.0.tiers'))->keyBy('name');
        $this->assertCount(1, $tiers);
        $this->assertTrue($tiers->has('Bậc 2'));
        $this->assertFalse($tiers->has('Bậc 1'));
    }

    #[Test]
    public function it_switches_my_cards_to_spend_targeting_when_all_tiers_are_at_most_two_percent(): void
    {
        $category = $this->makeSystemCategory();

        foreach ([['Thẻ 0.5%', '0.500'], ['Thẻ 1.99%', '1.990']] as [$name, $percent]) {
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

        // Không có bậc nào > 2% ⇒ KHÔNG lọc ngưỡng: thẻ dưới 2% vẫn hiện.
        $names = array_column($response->json('data.my_cards'), 'card_name');
        sort($names);
        $this->assertSame(['Thẻ 0.5%', 'Thẻ 1.99%'], $names);
        $this->assertTrue($response->json('data.my_cards.0.low_rate_regime'));
    }

    #[Test]
    public function it_prioritises_a_card_that_needs_valid_spend_toward_the_next_tier_in_low_rate_mode(): void
    {
        $category = $this->makeSystemCategory();

        // Thẻ "gần mốc": bậc 1 (1%) → bậc 2 (2%) mốc 10tr; chi thực 5tr + 1tr < 10tr.
        $nearTier = $this->makeUserCard($this->owner->id, ['name' => 'Gần mốc']);
        $this->makePolicyForCard(
            $nearTier,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000],
                ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '1.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '2.000', 'only_tier' => 1],
            ]
        );
        $this->makeTransaction($nearTier, $category->id, '5000000.00');

        // Thẻ "phẳng" 2%: hoàn nhiều hơn nhưng không tiến gần mốc nào.
        $flat = $this->makeUserCard($this->owner->id, ['name' => 'Phẳng']);
        $this->makePolicyForCard(
            $flat,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '2.000']]
        );

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        $rows = collect($response->json('data.my_cards'))->keyBy('card_name');

        $this->assertTrue($rows['Gần mốc']['needs_spend']);
        $this->assertFalse($rows['Phẳng']['needs_spend']);

        // Ưu tiên thẻ đang thiếu chi tiêu dù tiền hoàn thấp hơn (10k < 20k).
        $this->assertSame(
            ['Gần mốc', 'Phẳng'],
            array_column($response->json('data.my_cards'), 'card_name')
        );
    }

    #[Test]
    public function it_does_not_falsely_prioritise_exhausted_or_ineligible_cards_in_low_rate_mode(): void
    {
        $category = $this->makeSystemCategory();
        $gateCategory = $this->makeSystemCategory(['name' => 'Điều kiện']);

        // Thẻ hợp lệ: 2% phẳng.
        $valid = $this->makeUserCard($this->owner->id, ['name' => 'Hợp lệ']);
        $this->makePolicyForCard(
            $valid,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '2.000']]
        );

        // Thẻ "hết quota": đã dùng hết trần danh mục, dù còn thiếu mốc bậc kế tiếp.
        $exhausted = $this->makeUserCard($this->owner->id, ['name' => 'Hết quota']);
        $this->makePolicyForCard(
            $exhausted,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000],
                ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '2.000', 'only_tier' => 0, 'cap_cat' => '50000.00'],
                ['category_id' => $category->id, 'percent' => '2.000', 'only_tier' => 1],
            ]
        );
        $this->makeTransaction($exhausted, $category->id, '3000000.00');

        // Thẻ "không đủ điều kiện": gate chưa đạt dù còn thiếu mốc.
        $ineligible = $this->makeUserCard($this->owner->id, ['name' => 'Không đủ điều kiện']);
        $policy = $this->makePolicyForCard(
            $ineligible,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 10000000],
                ['name' => 'Bậc 2', 'min' => 10000000, 'max' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '2.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '2.000', 'only_tier' => 1],
            ]
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

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        $rows = collect($response->json('data.my_cards'))->keyBy('card_name');

        $this->assertTrue($rows->has('Hợp lệ'));
        $this->assertTrue($rows->has('Hết quota'));
        $this->assertTrue($rows->has('Không đủ điều kiện'));

        // Không thẻ nào bị ưu tiên "needs_spend" giả.
        $this->assertFalse($rows['Hết quota']['needs_spend']);
        $this->assertFalse($rows['Không đủ điều kiện']['needs_spend']);

        // Thẻ hợp lệ hoàn nhiều nhất đứng đầu; thẻ ineligible/không sinh hoàn bị xếp sau.
        $this->assertSame('Hợp lệ', $response->json('data.my_cards.0.card_name'));
    }

    #[Test]
    public function it_keeps_the_market_threshold_at_two_percent_even_when_all_my_cards_are_low(): void
    {
        $category = $this->makeSystemCategory();

        // Toàn bộ thẻ cá nhân 1% ⇒ chế độ tối ưu cho "Thẻ của tôi".
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ 1%']);
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '1.000']]
        );

        // Thị trường: 1.99% ẩn (dưới ngưỡng 2%), 2% hiện — KHÔNG theo chế độ của tôi.
        $this->makeMarketTemplate('Thị trường 1.99%', $category->id, '1.990', sortOrder: 0);
        $marketTwo = $this->makeMarketTemplate('Thị trường 2%', $category->id, '2.000', sortOrder: 1);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        // "Thẻ của tôi" ở chế độ tối ưu ⇒ vẫn hiện thẻ 1%.
        $this->assertCount(1, $response->json('data.my_cards'));
        $this->assertTrue($response->json('data.my_cards.0.low_rate_regime'));

        // Thị trường chỉ còn đúng thẻ 2%.
        $this->assertSame(
            [$marketTwo->id],
            array_column($response->json('data.market_cards'), 'template_id')
        );
    }

    #[Test]
    public function it_orders_market_cards_by_highest_rate_then_potential_cashback_then_name(): void
    {
        $category = $this->makeSystemCategory();

        $capLow = $this->makeMarketTemplate('B cap thấp', $category->id, '6.000', capPeriod: '10000.00', sortOrder: 0);
        $noCap = $this->makeMarketTemplate('C không cap', $category->id, '6.000', sortOrder: 1);
        $alpha = $this->makeMarketTemplate('A 6%', $category->id, '6.000', sortOrder: 2);
        $lower = $this->makeMarketTemplate('Cao nhất thật', $category->id, '5.000', sortOrder: 3);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]));

        $response->assertOk();

        // 6% trước 5%; trong nhóm 6%: tiềm năng 60k (A, C) trước 10k (B); đồng hạng ⇒ tên.
        $this->assertSame(
            [$alpha->id, $noCap->id, $capLow->id, $lower->id],
            array_column($response->json('data.market_cards'), 'template_id')
        );
    }

    // =====================================================================
    // 20. Chỉ đọc — không thay đổi dữ liệu thật
    // =====================================================================

    #[Test]
    public function it_does_not_change_transactions_or_create_periods(): void
    {
        $category = $this->makeSystemCategory();
        $card = $this->makeUserCard($this->owner->id);

        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '2.000']]
        );

        $this->makeTransaction($card, $category->id, '500000.00');

        $snapshot = Transaction::query()
            ->orderBy('id')
            ->get(['id', 'amount', 'cashback_amount_snapshot', 'is_eligible', 'statement_period_id'])
            ->toArray();
        $periodsBefore = StatementPeriod::query()->count();

        $this->actingAs($this->owner)->getJson(route('credit-cards.compare.recommend', [
            'amount' => 1000000,
            'category_id' => $category->id,
        ]))->assertOk();

        $this->assertSame(
            $snapshot,
            Transaction::query()
                ->orderBy('id')
                ->get(['id', 'amount', 'cashback_amount_snapshot', 'is_eligible', 'statement_period_id'])
                ->toArray()
        );
        $this->assertSame($periodsBefore, StatementPeriod::query()->count());
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

    /**
     * Template hệ thống có blueprint NHIỀU bậc, tuỳ chọn trạng thái blueprint và
     * việc đánh dấu nó làm bản mặc định (mô phỏng template "superseded default").
     *
     * @param  array<int, array{name?: string, min: int, max: int|null, percent: string, cap_period?: string|null, cap_cat?: string|null}>  $tiers
     */
    private function makeMarketTemplateWithTiers(
        string $name,
        int $categoryId,
        array $tiers,
        int $sortOrder = 0,
        string $blueprintStatus = Policy::STATUS_ACTIVE,
        bool $pointDefaultVersion = false,
    ): PolicyTemplate {
        $template = $this->makeSystemTemplate(['name' => $name, 'sort_order' => $sortOrder]);

        $blueprint = Policy::create([
            'template_id' => $template->id,
            'user_card_id' => null,
            'version_no' => 1,
            'status' => $blueprintStatus,
            'name' => $name,
            'effective_from' => '2026-10-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);
        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        foreach ($tiers as $index => $tier) {
            $tierModel = PolicyTier::create([
                'policy_id' => $blueprint->id,
                'name' => $tier['name'] ?? 'Bậc '.($index + 1),
                'sort_order' => $index,
                'min_total_spend' => $tier['min'],
                'max_total_spend' => $tier['max'] ?? null,
                'max_cashback_per_period' => $tier['cap_period'] ?? null,
            ]);

            PolicyTierCategory::create([
                'tier_id' => $tierModel->id,
                'category_id' => $categoryId,
                'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                'spend_from' => 0,
                'cashback_percent' => $tier['percent'],
                'max_cashback_per_category_per_period' => $tier['cap_cat'] ?? null,
                'is_enabled' => true,
            ]);
        }

        if ($pointDefaultVersion) {
            $template->forceFill(['default_version_id' => $blueprint->id])->save();
        }

        return $template->refresh();
    }
}
