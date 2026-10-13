<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\User;
use App\Services\CreditCard\Optimizer\PortfolioOptimizerService;
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
    // 0. Trang HTML: hai tab + binding nhập tiền hợp lệ
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

    #[Test]
    public function the_first_tab_is_renamed_to_spending_need(): void
    {
        $this->makeSystemCategory(['name' => 'Ăn uống']);

        $this->actingAs($this->owner)
            ->get(route('credit-cards.compare'))
            ->assertOk()
            ->assertSee('Nhu cầu chi tiêu')
            ->assertDontSee('Nhu cầu thẻ');
    }

    #[Test]
    public function the_category_money_inputs_bind_to_a_valid_alpine_expression(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $online = $this->makeSystemCategory(['name' => 'Mua sắm trực tuyến']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.compare'))
            ->assertOk()
            ->getContent();

        // NGUYÊN NHÂN GỐC của lỗi "chưa nhập danh mục nào": `@js(...)` bên trong
        // thuộc tính của Blade component KHÔNG được biên dịch, để lại biểu thức
        // Alpine sai (`budget.amounts[@js(...)]`) khiến input không ghi vào state.
        $this->assertStringNotContainsString('budget.amounts[@js(', $html);

        // Mỗi ô tiền phải trỏ ĐÚNG state `budget.amounts[<category_id>]` theo id
        // danh mục và ghi ngược qua `ccMoneyParseInput` (chuẩn hóa VND).
        foreach ([$food->id, $online->id] as $id) {
            $this->assertStringContainsString('budget.amounts['.$id.'] = ccMoneyParseInput(ccRaw)', $html);
        }
    }

    #[Test]
    public function both_tabs_stay_mounted_so_state_survives_switching(): void
    {
        $this->makeSystemCategory(['name' => 'Ăn uống']);

        $response = $this->actingAs($this->owner)
            ->get(route('credit-cards.compare'))
            ->assertOk()
            ->assertSee('x-show="tab === \'need\'"', false)
            ->assertSee('x-show="tab === \'optimize\'"', false);

        // Nếu là `x-if`, đổi tab sẽ unmount state và mất số tiền đã nhập.
        $response->assertDontSee('x-if="tab', false);
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
    public function max_cashback_criterion_finds_the_highest_cashback_plan_first(): void
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

        // "Tối đa hoàn tiền" phải trả về đúng phương án hoàn CAO NHẤT tìm được —
        // kể cả khi phải TÁCH danh mục (750k) — chứ không ép về một thẻ (500k).
        $response->assertJsonPath('data.best_cashback', '750000.00');
        $response->assertJsonPath('data.plans.0.cards_count', 2);
        $response->assertJsonPath('data.plans.0.total_cashback', '750000.00');

        // Vẫn giữ một phương án ít thẻ hơn (1 thẻ, 500k) làm lựa chọn thay thế.
        $response->assertJsonPath('data.plans.1.cards_count', 1);
        $response->assertJsonPath('data.plans.1.total_cashback', '500000.00');
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

    // =====================================================================
    // 7. Payload nhiều danh mục: nhận đúng từng khoản + tổng khớp
    // =====================================================================

    #[Test]
    public function it_receives_every_category_amount_and_total_matches_the_sum(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $online = $this->makeSystemCategory(['name' => 'Mua sắm trực tuyến']);
        $travel = $this->makeSystemCategory(['name' => 'Di chuyển']);

        $this->makeTemplate('Thẻ siêu thị 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '10.000']]);
        $this->makeTemplate('Thẻ online 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $online->id, 'percent' => '10.000']]);
        $this->makeTemplate('Thẻ di chuyển 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $travel->id, 'percent' => '10.000']]);

        $sent = [
            $food->id => 1000000,
            $online->id => 2000000,
            $travel->id => 3000000,
        ];

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => $sent,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.total_input_number', 6000000);
        $response->assertJsonPath('data.total_input', '6000000.00');

        // Mỗi danh mục giao hết cho thẻ có rule tương ứng ⇒ khoản nhận đúng bằng
        // số tiền đã gửi, và tổng các khoản khớp tổng input.
        $received = [];
        foreach ($response->json('data.plans.0.cards') as $card) {
            foreach ($card['categories'] as $row) {
                $received[(int) $row['category_id']] = $row['amount'];
            }
        }

        foreach ($sent as $categoryId => $amount) {
            $this->assertArrayHasKey($categoryId, $received);
            $this->assertSame(number_format($amount, 2, '.', ''), $received[$categoryId]);
        }

        $this->assertSame(6000000.0, (float) array_sum(array_map(
            fn (string $money): float => (float) $money,
            $received,
        )));
    }

    #[Test]
    public function it_accepts_a_mix_of_zero_and_positive_amounts(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $empty = $this->makeSystemCategory(['name' => 'Bảo hiểm']);

        $this->makeTemplate('Thẻ ăn uống 12%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '12.000']]);

        // Một danh mục để 0 (hoặc rỗng) cạnh một danh mục có chi tiêu ⇒ hợp lệ.
        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$category->id => 1000000, $empty->id => 0],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.total_input_number', 1000000);
        $response->assertJsonPath('data.plans.0.total_cashback', '120000.00');
    }

    #[Test]
    public function it_keeps_collecting_all_entered_categories_even_when_the_filter_hides_some(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $insurance = $this->makeSystemCategory(['name' => 'Bảo hiểm']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.compare'))
            ->assertOk()
            ->getContent();

        // Bộ lọc ẩn dòng bằng `x-show` (KHÔNG unmount) nên state `budget.amounts`
        // của các danh mục đã nhập vẫn còn và vẫn được gửi.
        $this->assertStringContainsString('x-show="! budget.filter', $html);
        $this->assertStringNotContainsString('x-if="! budget.filter', $html);

        // `optimizeRun` duyệt toàn bộ state, không duyệt DOM đang hiển thị.
        $this->assertStringContainsString('Object.entries(this.budget.amounts)', $html);

        // Mọi danh mục đều có binding riêng, kể cả danh mục có thể bị lọc.
        foreach ([$food->id, $insurance->id] as $id) {
            $this->assertStringContainsString('budget.amounts['.$id.']', $html);
        }
    }

    // =====================================================================
    // 8. Nhất quán số liệu phân bổ (Phase H)
    // =====================================================================

    /**
     * Kịch bản "một danh mục bị TÁCH cho hai thẻ" + một danh mục gọn một thẻ.
     *
     *   catA = 10tr: thẻ A 10% (trần danh mục 500k) + thẻ B 5% ⇒ tách 5tr/5tr = 750k
     *   catB = 2tr : thẻ C 5% ⇒ 100k
     *
     * @return array{0: Category, 1: Category}
     */
    private function makeSplitThenWholeScenario(): array
    {
        $catA = $this->makeSystemCategory(['name' => 'Mua sắm trực tuyến']);
        $catB = $this->makeSystemCategory(['name' => 'Bảo hiểm']);

        $this->makeTemplate('Thẻ A 10% (trần 500k)',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $catA->id, 'percent' => '10.000', 'cap_cat' => 500000]]);
        $this->makeTemplate('Thẻ B 5%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $catA->id, 'percent' => '5.000']]);
        $this->makeTemplate('Thẻ C bảo hiểm 5%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $catB->id, 'percent' => '5.000']]);

        return [$catA, $catB];
    }

    #[Test]
    public function it_exposes_category_names_for_every_entered_category(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ẩm thực & Ăn uống']);
        $grocery = $this->makeSystemCategory(['name' => 'Siêu thị']);

        $this->makeTemplate('Thẻ ăn uống 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '10.000']]);
        $this->makeTemplate('Thẻ siêu thị 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $grocery->id, 'percent' => '10.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$food->id => 3000000, $grocery->id => 5000000],
        ]));

        $response->assertOk();

        // Danh mục đầu vào: giữ nguyên THỨ TỰ request, có ID + TÊN + số tiền.
        $categories = $response->json('data.categories');
        $this->assertSame([$food->id, $grocery->id], array_column($categories, 'id'));
        $this->assertSame(['Ẩm thực & Ăn uống', 'Siêu thị'], array_column($categories, 'name'));
        $this->assertSame(['3000000.00', '5000000.00'], array_column($categories, 'amount'));

        // Mỗi dòng phân bổ trên thẻ phải mang TÊN danh mục chuẩn (không phải id trần).
        foreach ($response->json('data.plans.0.cards') as $card) {
            foreach ($card['categories'] as $row) {
                $this->assertNotSame('', $row['category_name']);
                $this->assertNotSame('Danh mục #'.$row['category_id'], $row['category_name']);
            }
        }
    }

    #[Test]
    public function each_category_is_allocated_fully_across_all_cards(): void
    {
        [$catA, $catB] = $this->makeSplitThenWholeScenario();

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$catA->id => 10000000, $catB->id => 2000000],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.total_input_number', 12000000);

        $plan = $response->json('data.plans.0');

        $byCategory = [];

        foreach ($plan['cards'] as $card) {
            foreach ($card['categories'] as $row) {
                $id = (int) $row['category_id'];
                $byCategory[$id] = ($byCategory[$id] ?? 0.0) + (float) $row['amount'];
            }
        }

        // Tổng phân bổ của MỖI danh mục qua TẤT CẢ thẻ = nhu cầu ban đầu.
        $this->assertEqualsWithDelta(10000000.0, $byCategory[$catA->id], 0.01);
        $this->assertEqualsWithDelta(2000000.0, $byCategory[$catB->id], 0.01);
    }

    #[Test]
    public function per_card_and_plan_allocated_totals_are_consistent(): void
    {
        [$catA, $catB] = $this->makeSplitThenWholeScenario();

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$catA->id => 10000000, $catB->id => 2000000],
        ]));

        $response->assertOk();
        $totalInput = (float) $response->json('data.total_input_number');

        foreach ($response->json('data.plans') as $plan) {
            $planSum = 0.0;

            foreach ($plan['cards'] as $card) {
                $cardSum = 0.0;

                foreach ($card['categories'] as $row) {
                    $cardSum += (float) $row['amount'];
                    $this->assertGreaterThan(0.0, (float) $row['amount']);
                }

                $this->assertEqualsWithDelta($cardSum, (float) $card['allocated_total'], 0.01);
                $planSum += $cardSum;
            }

            $this->assertEqualsWithDelta($planSum, (float) $plan['total_allocated'], 0.01);

            // Kịch bản được kiểm soát: mọi danh mục đều có thẻ ⇒ phân bổ đủ nhu cầu.
            $this->assertEqualsWithDelta($totalInput, (float) $plan['total_allocated'], 0.01);
        }
    }

    #[Test]
    public function the_proposed_plan_summary_totals_match_the_plan_and_input(): void
    {
        [$catA, $catB] = $this->makeSplitThenWholeScenario();

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$catA->id => 10000000, $catB->id => 2000000],
        ]));

        $response->assertOk();

        $plan = $response->json('data.plans.0');

        $owned = 0;
        $new = 0;

        foreach ($plan['cards'] as $card) {
            $card['is_owned'] ? $owned++ : $new++;
        }

        // Khối "Phương án đề xuất" đọc trực tiếp các trường này.
        $this->assertSame($plan['cards_count'], count($plan['cards']));
        $this->assertSame($plan['cards_count'], $owned + $new);
        $this->assertSame($owned, $plan['owned_cards']);
        $this->assertSame($new, $plan['new_cards']);
        $this->assertEqualsWithDelta(12000000.0, (float) $plan['total_allocated'], 0.01);
        // catA tách 2 thẻ (750k) + catB (100k)
        $this->assertSame('850000.00', $response->json('data.best_cashback'));
    }

    #[Test]
    public function it_returns_multiple_meaningful_plans_when_a_distinct_allocation_exists(): void
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

        // Hai phân bổ thực sự khác nhau: tách 2 thẻ (750k) và gộp 1 thẻ (500k).
        $this->assertSame(2, $response->json('data.plan_count'));
        $this->assertCount(2, $response->json('data.plans'));
        $this->assertSame(3, $response->json('data.max_plans'));
        $this->assertLessThanOrEqual((int) $response->json('data.max_plans'), count($response->json('data.plans')));
    }

    #[Test]
    public function it_reports_a_single_plan_when_only_one_allocation_is_viable(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Ăn uống']);

        $this->makeTemplate('Thẻ duy nhất 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$category->id => 1000000],
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->json('data.plan_count'));
        $this->assertCount(1, $response->json('data.plans'));
    }

    #[Test]
    public function the_result_view_shows_category_names_and_reuses_the_shared_money_formatter(): void
    {
        $this->makeSystemCategory(['name' => 'Ăn uống']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.compare'))
            ->assertOk()
            ->getContent();

        // Khối tóm tắt nhu cầu + tổng kết đánh giá.
        $this->assertStringContainsString('Tóm tắt nhu cầu chi tiêu tháng', $html);
        $this->assertStringContainsString('Tổng kết và đánh giá phương án', $html);

        // Dòng phân bổ theo thẻ hiển thị TÊN danh mục (không còn "amount → thẻ này").
        $this->assertStringContainsString('cat.category_name', $html);
        $this->assertStringNotContainsString('→ thẻ này', $html);

        // Mọi số tiền đi qua formatter dùng chung `ccMoneyVnd`.
        $this->assertStringContainsString('ccMoneyVnd(cat.amount)', $html);
        $this->assertStringContainsString('ccMoneyVnd(card.allocated_total)', $html);
        $this->assertStringContainsString('ccMoneyVnd(proposed().total_allocated)', $html);
        $this->assertStringContainsString('ccMoneyVnd(optimizeResult.total_input_number)', $html);
    }

    #[Test]
    public function cashback_and_input_totals_are_consistent_across_rows_cards_and_plan(): void
    {
        [$catA, $catB] = $this->makeSplitThenWholeScenario();

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$catA->id => 10000000, $catB->id => 2000000],
        ]));

        $response->assertOk();

        // Tổng nhu cầu ban đầu = tổng số tiền các danh mục đầu vào.
        $inputSum = array_sum(array_map(
            fn (array $row): float => (float) $row['amount'],
            $response->json('data.categories'),
        ));
        $this->assertEqualsWithDelta((float) $response->json('data.total_input_number'), $inputSum, 0.01);

        foreach ($response->json('data.plans') as $plan) {
            $planCashback = 0.0;

            foreach ($plan['cards'] as $card) {
                $cardCashback = 0.0;

                foreach ($card['categories'] as $row) {
                    $cardCashback += (float) $row['cashback'];
                }

                // Tổng hoàn của thẻ = tổng hoàn các dòng danh mục của thẻ.
                $this->assertEqualsWithDelta($cardCashback, (float) $card['total_cashback'], 0.01);
                $planCashback += $cardCashback;
            }

            // Tổng hoàn của phương án = tổng hoàn các thẻ.
            $this->assertEqualsWithDelta($planCashback, (float) $plan['total_cashback'], 0.01);
        }
    }

    // =====================================================================
    // 9. Phân bổ theo LỢI ÍCH BIÊN: không bỏ sót thẻ hoàn biên cao
    // =====================================================================

    /**
     * Tái hiện đúng phản ví dụ audit: MSB mDigi Mastercard ẩm thực 20% nhưng trần
     * danh mục/kỳ chỉ 300k ⇒ khi "dồn cả danh mục" chỉ được 300k (xếp hạng thấp)
     * và bị bộ tối ưu cũ loại khỏi top-3/top-2. Thuật toán theo lợi ích biên phải
     * dùng mDigi cho phần ẩm thực đầu tiên để vượt mốc 1.700.000.
     */
    #[Test]
    public function it_does_not_miss_a_high_marginal_20_percent_card_with_a_low_total(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ẩm thực & Ăn uống']);
        $shopee = $this->makeSystemCategory(['name' => 'Shopee']);
        $med = $this->makeSystemCategory(['name' => 'Y tế & Bệnh viện']);

        $this->makeTemplate('MSB mDigi Mastercard',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => 300000]],
            [['category_id' => $food->id, 'percent' => '20.000', 'cap_cat' => 300000]]);

        $this->makeTemplate('VPBank Z Card',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '15.000', 'cap_cat' => 500000]]);

        $this->makeTemplate('VIB Elite',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [
                ['category_id' => $shopee->id, 'percent' => '10.000', 'cap_cat' => 500000],
                ['category_id' => $food->id, 'percent' => '10.000'],
            ]);

        $this->makeTemplate('MB Ultimate JCB',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $med->id, 'percent' => '8.000', 'cap_cat' => 400000]]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$food->id => 5000000, $shopee->id => 5000000, $med->id => 5000000],
        ]));

        $response->assertOk();

        $this->assertGreaterThanOrEqual(
            1700000.0,
            (float) $response->json('data.best_cashback'),
            'Bộ tối ưu theo lợi ích biên phải đạt ≥ 1.700.000.',
        );

        $usedDigiForFood = false;

        foreach ($response->json('data.plans.0.cards') as $card) {
            if (! str_contains((string) $card['name'], 'mDigi')) {
                continue;
            }

            foreach ($card['categories'] as $row) {
                if ((int) $row['category_id'] === $food->id && (float) $row['amount'] > 0) {
                    $usedDigiForFood = true;
                }
            }
        }

        $this->assertTrue($usedDigiForFood, 'Phương án dẫn đầu phải dùng MSB mDigi cho ẩm thực.');
    }

    // =====================================================================
    // 10. Nhiều bản (instance) của CÙNG sản phẩm — max_copies_per_card
    // =====================================================================

    #[Test]
    public function copies_multiply_per_card_caps_when_enabled(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ẩm thực']);

        $this->makeTemplate('Thẻ 20% trần 200k',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '20.000', 'cap_cat' => 200000]]);

        $amounts = [$food->id => 5000000];

        $one = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all', 'criterion' => 'max_cashback',
            'max_copies_per_card' => 1, 'amounts' => $amounts,
        ]));
        $one->assertOk();
        $this->assertSame('200000.00', $one->json('data.best_cashback'));

        $two = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all', 'criterion' => 'max_cashback',
            'max_copies_per_card' => 2, 'amounts' => $amounts,
        ]));
        $two->assertOk();
        $this->assertSame(2, (int) $two->json('data.max_copies_per_card'));
        $this->assertSame('400000.00', $two->json('data.best_cashback'));

        $three = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all', 'criterion' => 'max_cashback',
            'max_copies_per_card' => 3, 'amounts' => $amounts,
        ]));
        $three->assertOk();
        $this->assertSame('600000.00', $three->json('data.best_cashback'));
    }

    #[Test]
    public function it_validates_max_copies_per_card_bounds(): void
    {
        $category = $this->makeSystemCategory();

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.optimize', [
                'max_copies_per_card' => 0,
                'amounts' => [$category->id => 1000000],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_copies_per_card');

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.compare.optimize', [
                'max_copies_per_card' => PortfolioOptimizerService::MAX_COPIES_LIMIT + 1,
                'amounts' => [$category->id => 1000000],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('max_copies_per_card');
    }

    /**
     * Trần theo KỲ nằm ở BẬC của từng thẻ. Mô hình policy hiện hành không có
     * trường trần cấp khách hàng/sản phẩm, nên nhiều bản = nhiều lần trần; kết quả
     * phải nêu rõ giới hạn phạm vi trần dùng chung để người dùng tự kiểm chứng.
     */
    #[Test]
    public function copies_apply_period_caps_per_card_and_flag_shared_scope_limitation(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ẩm thực']);

        $this->makeTemplate('Thẻ 20% trần kỳ 300k',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => 300000]],
            [['category_id' => $food->id, 'percent' => '20.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all', 'criterion' => 'max_cashback',
            'max_copies_per_card' => 2,
            'amounts' => [$food->id => 5000000],
        ]));

        $response->assertOk();
        $this->assertSame('600000.00', $response->json('data.best_cashback'));

        foreach ($response->json('data.plans.0.cards') as $card) {
            $this->assertLessThanOrEqual(300000.0, (float) $card['total_cashback']);
        }

        $notes = implode(' ', $response->json('data.notes'));
        $this->assertStringContainsString('TỪNG thẻ', $notes);
    }

    // =====================================================================
    // 11. Test A — trần thấp, tỷ lệ cao: phân bổ theo lợi ích BIÊN
    // =====================================================================

    /**
     * Test A: một danh mục 5tr có thẻ A 20% (trần danh mục 300k) và thẻ B 10%
     * (không trần). Phân bổ tối ưu là A nhận 1.5tr (chạm trần 300k) + B nhận 3.5tr
     * (350k) = 650k — KHÔNG phải dồn cả danh mục cho thẻ nào. Kết quả phải độc lập
     * với THỨ TỰ danh mục gửi lên và KHÔNG phụ thuộc tên sản phẩm.
     */
    #[Test]
    public function test_a_it_fills_a_low_cap_high_rate_card_up_to_its_cap_then_uses_the_next_card(): void
    {
        $main = $this->makeSystemCategory(['name' => 'Mua sắm trực tuyến']);
        $other = $this->makeSystemCategory(['name' => 'Bảo hiểm']);

        $this->makeTemplate('Thẻ A 20% (trần 300k)',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $main->id, 'percent' => '20.000', 'cap_cat' => 300000]]);

        $this->makeTemplate('Thẻ B 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $main->id, 'percent' => '10.000']]);

        $this->makeTemplate('Thẻ C bảo hiểm 5%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $other->id, 'percent' => '5.000']]);

        $run = fn (array $amounts) => $this->actingAs($this->owner)->getJson(
            route('credit-cards.compare.optimize', [
                'scope' => 'all',
                'criterion' => 'max_cashback',
                'amounts' => $amounts,
            ]),
        )->assertOk();

        $forward = $run([$main->id => 5000000, $other->id => 2000000]);
        $reverse = $run([$other->id => 2000000, $main->id => 5000000]);

        // Danh mục chính trị giá 650k (300k + 350k); cộng 100k bảo hiểm ⇒ 750k tổng.
        $this->assertSame('750000.00', $forward->json('data.best_cashback'));
        $this->assertSame('750000.00', $reverse->json('data.best_cashback'));

        $this->assertEqualsWithDelta(
            650000.0,
            $this->categoryCashback($forward->json('data.plans.0.cards'), $main->id),
            0.01,
        );

        $expected = [
            'Thẻ A 20% (trần 300k)' => 1500000.0,
            'Thẻ B 10%' => 3500000.0,
        ];

        $forwardPortions = $this->portionsForCategory($forward->json('data.plans.0.cards'), $main->id);
        $reversePortions = $this->portionsForCategory($reverse->json('data.plans.0.cards'), $main->id);

        ksort($forwardPortions);
        ksort($reversePortions);
        ksort($expected);

        $this->assertEqualsWithDelta($expected, $forwardPortions, 0.01);
        $this->assertEqualsWithDelta($expected, $reversePortions, 0.01);
    }

    // =====================================================================
    // 12. Test B — thẻ tỷ lệ cao phải được dùng TỚI TRẦN khi có lợi
    // =====================================================================

    /**
     * Test B: khi thuật toán dùng một thẻ tỷ lệ cao (20%) cho một danh mục, thẻ đó
     * phải được cấp đủ để ĐẠT TRẦN (không dừng lưng chừng). Kết quả tổng do
     * evaluator quyết định; không bắt buộc phải dùng thẻ này nếu bất lợi.
     */
    #[Test]
    public function test_b_a_high_rate_card_is_used_up_to_its_cap_when_it_is_part_of_the_plan(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ẩm thực & Ăn uống']);

        $this->makeTemplate('MSB mDigi Mastercard',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => 300000]],
            [['category_id' => $food->id, 'percent' => '20.000', 'cap_cat' => 300000]]);

        $this->makeTemplate('VPBank Z Card',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '15.000', 'cap_cat' => 500000]]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$food->id => 5000000],
        ]));

        $response->assertOk();

        // mDigi 1.5tr → 300k (trần) + Z trần 500k = 800k > mọi phương án một thẻ.
        $this->assertSame('800000.00', $response->json('data.best_cashback'));

        $cards = $response->json('data.plans.0.cards');
        $digiCashback = $this->cashbackForCategory($cards, 'MSB mDigi Mastercard', $food->id);

        $this->assertNotNull($digiCashback, 'Phương án 800k buộc phải dùng MSB mDigi cho ẩm thực.');
        $this->assertEqualsWithDelta(300000.0, $digiCashback, 0.01, 'mDigi phải đạt trần 300k.');
    }

    // =====================================================================
    // 13. Test C — trần TỔNG KỲ + trần DANH MỤC cùng lúc
    // =====================================================================

    #[Test]
    public function test_c_it_respects_both_the_period_cap_and_the_category_cap(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Mua sắm trực tuyến']);

        // 20% với trần danh mục 300k NHƯNG trần kỳ (bậc) chỉ 200k ⇒ cap hiệu dụng 200k.
        $this->makeTemplate('Thẻ A 20% (trần kỳ 200k)',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'cap_period' => 200000]],
            [['category_id' => $category->id, 'percent' => '20.000', 'cap_cat' => 300000]]);

        $this->makeTemplate('Thẻ B 10%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '10.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$category->id => 5000000],
        ]));

        $response->assertOk();

        // A dừng ở 1tr (chạm trần kỳ 200k), B nhận 4tr (400k) ⇒ 600k.
        $this->assertSame('600000.00', $response->json('data.best_cashback'));

        $cards = $response->json('data.plans.0.cards');

        $this->assertEqualsWithDelta(1000000.0, $this->amountForCategory($cards, 'Thẻ A 20% (trần kỳ 200k)', $category->id), 0.01);
        $this->assertEqualsWithDelta(200000.0, $this->cashbackForCategory($cards, 'Thẻ A 20% (trần kỳ 200k)', $category->id), 0.01);

        // Không được vượt cả trần kỳ (200k) lẫn trần danh mục (300k).
        foreach ($cards as $card) {
            if ($card['name'] === 'Thẻ A 20% (trần kỳ 200k)') {
                $this->assertLessThanOrEqual(200000.0, (float) $card['total_cashback']);
            }
        }
    }

    // =====================================================================
    // 14. Test D — ngưỡng BẬC (retroactive min_total_spend)
    // =====================================================================

    #[Test]
    public function test_d_it_respects_retroactive_tier_thresholds(): void
    {
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);

        // Bậc 1 [0, 1tr): 5% · Bậc 2 [1tr, ∞): 20%. Ngưỡng là RETROACTIVE theo tổng
        // chi tiêu của thẻ ⇒ giao hết 2tr cho thẻ D vượt ngưỡng và hưởng 20% cả 2tr.
        $this->makeTemplate('Thẻ D bậc thang',
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 1000000],
                ['name' => 'Bậc 2', 'min' => 1000000, 'max' => null],
            ],
            [
                ['category_id' => $category->id, 'percent' => '5.000', 'only_tier' => 0],
                ['category_id' => $category->id, 'percent' => '20.000', 'only_tier' => 1],
            ]);

        $this->makeTemplate('Thẻ B 15%',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $category->id, 'percent' => '15.000']]);

        $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
            'scope' => 'all',
            'criterion' => 'max_cashback',
            'amounts' => [$category->id => 2000000],
        ]));

        $response->assertOk();

        // D giữ trọn 2tr ⇒ tổng ≥ 1tr ⇒ Bậc 2 (20%) = 400k > B (15% = 300k).
        $this->assertSame('400000.00', $response->json('data.best_cashback'));

        $cards = $response->json('data.plans.0.cards');
        $this->assertEqualsWithDelta(2000000.0, $this->amountForCategory($cards, 'Thẻ D bậc thang', $category->id), 0.01);
        $this->assertEqualsWithDelta(400000.0, $this->cashbackForCategory($cards, 'Thẻ D bậc thang', $category->id), 0.01);
    }

    // =====================================================================
    // 15. Test E — nhiều bản (1/2/3) đều đạt trần RIÊNG của mình
    // =====================================================================

    #[Test]
    public function test_e_each_copy_reaches_its_own_cap_for_copies_1_2_and_3(): void
    {
        $food = $this->makeSystemCategory(['name' => 'Ẩm thực']);

        $this->makeTemplate('Thẻ 20% trần danh mục 200k',
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $food->id, 'percent' => '20.000', 'cap_cat' => 200000]]);

        $amounts = [$food->id => 5000000];

        $expected = [1 => '200000.00', 2 => '400000.00', 3 => '600000.00'];

        foreach ($expected as $copies => $best) {
            $response = $this->actingAs($this->owner)->getJson(route('credit-cards.compare.optimize', [
                'scope' => 'all',
                'criterion' => 'max_cashback',
                'max_copies_per_card' => $copies,
                'amounts' => $amounts,
            ]));

            $response->assertOk();
            $this->assertSame($best, $response->json('data.best_cashback'));

            $plan = $response->json('data.plans.0');
            $this->assertSame($copies, (int) $plan['cards_count']);
            $this->assertSame(1, (int) $plan['distinct_products']);

            // MỖI bản phải đạt trần danh mục RIÊNG của nó (200k).
            foreach ($plan['cards'] as $card) {
                $this->assertEqualsWithDelta(
                    200000.0,
                    $this->cashbackRowForCard($card, $food->id),
                    0.01,
                    'Mỗi bản phải chạm trần danh mục riêng.',
                );
            }
        }
    }

    // =====================================================================
    // Helpers — đọc phân bổ/hoàn tiền theo thẻ × danh mục từ payload plan
    // =====================================================================

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<string, float> tên thẻ => tiền phân bổ cho danh mục
     */
    private function portionsForCategory(array $cards, int $categoryId): array
    {
        $out = [];

        foreach ($cards as $card) {
            foreach ($card['categories'] as $row) {
                if ((int) $row['category_id'] === $categoryId) {
                    $out[$card['name']] = (float) $row['amount'];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function amountForCategory(array $cards, string $cardName, int $categoryId): ?float
    {
        foreach ($cards as $card) {
            if ($card['name'] !== $cardName) {
                continue;
            }

            foreach ($card['categories'] as $row) {
                if ((int) $row['category_id'] === $categoryId) {
                    return (float) $row['amount'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function cashbackForCategory(array $cards, string $cardName, int $categoryId): ?float
    {
        foreach ($cards as $card) {
            if ($card['name'] !== $cardName) {
                continue;
            }

            foreach ($card['categories'] as $row) {
                if ((int) $row['category_id'] === $categoryId) {
                    return (float) $row['cashback'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function categoryCashback(array $cards, int $categoryId): float
    {
        $sum = 0.0;

        foreach ($cards as $card) {
            foreach ($card['categories'] as $row) {
                if ((int) $row['category_id'] === $categoryId) {
                    $sum += (float) $row['cashback'];
                }
            }
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function cashbackRowForCard(array $card, int $categoryId): float
    {
        foreach ($card['categories'] as $row) {
            if ((int) $row['category_id'] === $categoryId) {
                return (float) $row['cashback'];
            }
        }

        return 0.0;
    }
}
