<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * "Tối ưu bộ thẻ hoàn tiền" (/thetindung/so-sanh/toi-uu).
 *
 * Khóa bất biến của `PortfolioOptimizerService` + endpoint:
 *   - Endpoint JSON chỉ đọc, yêu cầu đăng nhập, validate scope/criterion/amounts.
 *   - Sinh TỐI ĐA 3 phương án THỰC KHÁC NHAU (fingerprint phân bổ).
 *   - Cho phép TÁCH một danh mục cho nhiều thẻ khi cải thiện hoàn tiền.
 *   - Mọi con số đều đi qua ĐÚNG engine hoàn tiền (`CashbackCalculator`).
 *   - Tiêu chí quyết định cách sinh + xếp hạng phương án.
 */
class CardPortfolioOptimizerTest extends TestCase
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
    // Fixture: blueprint template hệ thống (PolicyVersion rời + bậc + rule)
    // =====================================================================

    private function makeTemplate(string $name, array $tiers, array $rules): PolicyTemplate
    {
        $template = $this->makeSystemTemplate(['name' => $name]);

        $version = PolicyVersion::create([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $name,
            'effective_from' => '2000-01-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);

        // Root trỏ về chính nó ⇒ UNIQUE (root_policy_id, version_no) có hiệu lực.
        $version->forceFill(['root_policy_id' => $version->id])->save();

        foreach (array_values($tiers) as $index => $tier) {
            $tierModel = PolicyTier::create([
                'policy_id' => $version->id,
                'name' => $tier['name'] ?? 'Tier '.($index + 1),
                'sort_order' => $index,
                'min_total_spend' => $tier['min'],
                'max_total_spend' => $tier['max'] ?? null,
                'max_cashback_per_period' => $tier['cap_period'] ?? null,
            ]);

            foreach ($rules as $rule) {
                if (isset($rule['only_tier']) && $rule['only_tier'] !== $index) {
                    continue;
                }

                PolicyTierCategory::create([
                    'tier_id' => $tierModel->id,
                    'category_id' => $rule['category_id'],
                    'name' => $rule['name'] ?? null,
                    'sort_order' => $rule['sort_order'] ?? 0,
                    'spend_from' => $rule['spend_from'] ?? 0,
                    'spend_to' => $rule['spend_to'] ?? null,
                    'cashback_percent' => $rule['percent'],
                    'max_cashback_per_transaction' => $rule['cap_tx'] ?? null,
                    'max_cashback_per_category_per_period' => $rule['cap_cat'] ?? null,
                    'min_transaction_amount' => $rule['min_tx'] ?? null,
                    'is_enabled' => $rule['is_enabled'] ?? true,
                    'counts_toward_tier_cap' => $rule['counts_cap'] ?? true,
                    'is_quota_category' => $rule['quota'] ?? false,
                ]);
            }
        }

        $template->forceFill(['default_version_id' => $version->id])->save();

        return $template;
    }

    // =====================================================================
    // 0. Trang HTML: tab thứ hai hiện diện
    // =====================================================================

    #[Test]
    public function the_compare_page_exposes_the_optimizer_tab(): void
    {
        $this->makeSystemCategory(['name' => 'Ăn uống']);

        $this->actingAs($this->owner)
            ->get(route('credit-cards.compare'))
            ->assertOk()
            ->assertSee('Tối ưu bộ thẻ')
            ->assertSee('Lựa chọn thẻ');
    }

    // =====================================================================
    // 1. Xác thực + validate
    // =====================================================================

    #[Test]
    public function the_optimize_endpoint_requires_authentication(): void
    {
        $this->getJson(route('credit-cards.compare.optimize', [
            'amounts' => [1 => 1000000],
        ]))->assertUnauthorized();
    }

    #[Test]
    public function it_validates_scope_and_criterion(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.optimize', [
                'scope' => 'khong-ton-tai',
                'amounts' => [$category->id => 1000000],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.optimize', [
                'criterion' => 'la-lung',
                'amounts' => [$category->id => 1000000],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('criterion');
    }

    #[Test]
    public function it_validates_amounts_presence_and_positive_total(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.optimize'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amounts');

        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.optimize', [
                'amounts' => [$category->id => 0],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amounts');
    }

    #[Test]
    public function it_rejects_a_category_outside_the_users_scope(): void
    {
        $foreign = $this->makeUserCategory($this->stranger->id);

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.optimize', [
                'amounts' => [$foreign->id => 1000000],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amounts.'.$foreign->id);
    }

    // =====================================================================
    // 2. Hình dạng kết quả + tái sử dụng engine
    // =====================================================================

    #[Test]
    public function it_returns_a_shaped_plan_using_the_engine(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $fuel = $this->makeSystemCategory(['name' => 'Xăng dầu']);

        $this->makeTemplate('Thẻ ăn uống 20%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '20.000']]);

        $this->makeTemplate('Thẻ xăng dầu 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $fuel->id, 'percent' => '10.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$food->id => 1000000, $fuel->id => 2000000],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.scope', 'all');
        $response->assertJsonPath('data.criterion', 'max_cashback');
        $response->assertJsonPath('data.total_input', '3000000.00');
        $response->assertJsonPath('data.total_input_number', 3000000);
        $response->assertJsonPath('data.plans.0.total_cashback', '400000.00');
        $response->assertJsonPath('data.plans.0.cards_count', 2);

        $notes = implode(' ', $response->json('data.notes'));
        $this->assertStringContainsString('ước tính theo chính sách hoàn tiền hiện hành', $notes);
    }

    #[Test]
    public function owned_scope_ignores_system_templates(): void
    {
        $category = $this->makeSystemCategory();
        $this->makeTemplate('Thẻ thị trường 30%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '30.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'owned',
            'criterion' => 'max_cashback',
            'amounts' => [$category->id => 1000000],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.plans', []);
    }

    // =====================================================================
    // 3. Phạm vi compare: chọn thẻ tốt hơn giữa của tôi và hệ thống
    // =====================================================================

    #[Test]
    public function compare_scope_picks_the_higher_cashback_card(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);

        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ của tôi']);
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000']]
        );

        $this->makeTemplate('Thẻ thị trường 15%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '15.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'compare',
            'criterion' => 'max_cashback',
            'amounts' => [$category->id => 1000000],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.plans.0.total_cashback', '150000.00');
        $response->assertJsonPath('data.plans.0.cards.0.is_owned', false);
        $response->assertJsonPath('data.plans.0.cards.0.name', 'Thẻ thị trường 15%');
    }

    #[Test]
    public function my_cards_criterion_prefers_owned_cards_on_equal_cashback(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ của tôi']);
        $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000']]
        );

        // Template hệ thống cho CÙNG tỷ lệ ⇒ thẻ của tôi phải thắng theo tiêu chí.
        $this->makeTemplate('Thẻ thị trường 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'compare',
            'criterion' => 'my_cards',
            'amounts' => [$category->id => 1000000],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.plans.0.total_cashback', '100000.00');
        $response->assertJsonPath('data.plans.0.cards.0.is_owned', true);
        $response->assertJsonPath('data.plans.0.cards.0.name', 'Thẻ của tôi');
    }

    // =====================================================================
    // 4. Tách một danh mục cho nhiều thẻ khi cải thiện hoàn tiền
    // =====================================================================

    #[Test]
    public function it_splits_a_category_across_cards_when_it_improves_cashback(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Mua sắm trực tuyến']);

        // Thẻ A: 10% nhưng trần danh mục/kỳ 500k ⇒ giao hết chỉ được 500k.
        $this->makeTemplate('Thẻ A 10% (trần 500k)',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000', 'cap_cat' => 500000]]);

        // Thẻ B: 5% không trần ⇒ giao hết cũng được 500k (10tr × 5%).
        $this->makeTemplate('Thẻ B 5%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'balance',
            'amounts' => [$category->id => 10000000],
        ]));

        $response->assertOk();

        // Tách 5tr/5tr: A chạm trần 500k + B 250k = 750k > 500k (giao hết).
        $response->assertJsonPath('data.plans.0.total_cashback', '750000.00');
        $response->assertJsonPath('data.plans.0.cards_count', 2);

        $cards = $response->json('data.plans.0.cards');
        $this->assertCount(2, $cards);

        $portions = [];
        foreach ($cards as $card) {
            foreach ($card['categories'] as $row) {
                if ((int) $row['category_id'] === $category->id) {
                    $portions[] = $row['amount'];
                }
            }
        }

        $this->assertSame(['5000000.00', '5000000.00'], $portions);
    }

    #[Test]
    public function max_cashback_criterion_does_not_force_a_split(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Mua sắm trực tuyến']);

        $this->makeTemplate('Thẻ A 10% (trần 500k)',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000', 'cap_cat' => 500000]]);

        $this->makeTemplate('Thẻ B 5%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '5.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$category->id => 10000000],
        ]));

        $response->assertOk();
        // Tiêu chí "giao hết cho một thẻ": mọi phương án 1 thẻ, 500k.
        $response->assertJsonPath('data.plans.0.cards_count', 1);
        $response->assertJsonPath('data.plans.0.total_cashback', '500000.00');
    }

    // =====================================================================
    // 5. Tiêu chí "ít thẻ nhất"
    // =====================================================================

    #[Test]
    public function fewest_cards_criterion_keeps_everything_on_one_card(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $fuel = $this->makeSystemCategory(['name' => 'Xăng dầu']);

        $this->makeTemplate('Thẻ gộp 20/20',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [
                ['category_id' => $food->id, 'percent' => '20.000'],
                ['category_id' => $fuel->id, 'percent' => '20.000'],
            ]);

        $this->makeTemplate('Thẻ ăn uống 20%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '20.000']]);

        $this->makeTemplate('Thẻ xăng dầu 20%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $fuel->id, 'percent' => '20.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'fewest_cards',
            'amounts' => [$food->id => 1000000, $fuel->id => 1000000],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.plans.0.cards_count', 1);
        $response->assertJsonPath('data.plans.0.total_cashback', '400000.00');
    }

    // =====================================================================
    // 6. Bất biến: tối đa 3 phương án, distinct theo phân bổ
    // =====================================================================

    #[Test]
    public function it_returns_at_most_three_distinct_plans(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $fuel = $this->makeSystemCategory(['name' => 'Xăng dầu']);

        $this->makeTemplate('Thẻ A',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [
                ['category_id' => $food->id, 'percent' => '12.000'],
                ['category_id' => $fuel->id, 'percent' => '8.000'],
            ]);

        $this->makeTemplate('Thẻ B',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '10.000']]);

        $this->makeTemplate('Thẻ C',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $fuel->id, 'percent' => '10.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'balance',
            'amounts' => [$food->id => 3000000, $fuel->id => 2000000],
        ]));

        $response->assertOk();

        $plans = $response->json('data.plans');
        $this->assertNotEmpty($plans);
        $this->assertLessThanOrEqual(3, count($plans));

        // Fingerprint (phân bổ) giữa các phương án phải khác nhau.
        $fingerprints = array_map(function (array $plan): string {
            $parts = [];
            foreach ($plan['cards'] as $card) {
                $cats = array_map(
                    fn (array $row): string => $row['category_id'].'x'.$row['amount'],
                    $card['categories'],
                );
                sort($cats);
                $parts[] = $card['key'].'('.implode(',', $cats).')';
            }
            sort($parts);

            return implode(';', $parts);
        }, $plans);

        $this->assertSame(count($fingerprints), count(array_unique($fingerprints)));
    }
}
