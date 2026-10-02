<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\User;
use App\Services\CreditCard\CategoryComboService;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\PolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Js;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * PHASE 1 — cờ "Tính hạn mức chi tiêu còn lại" (`is_quota_category`).
 *
 * ---------------------------------------------------------------------------
 * HỢP ĐỒNG ĐANG KIỂM TRA
 * ---------------------------------------------------------------------------
 * 1. Mặc định `false` — KHÔNG tự tick rule cũ, policy đang dùng giữ nguyên hành vi.
 * 2. Tick được cho rule DANH MỤC và rule COMBO.
 * 3. Nhiều rule được tick trong CÙNG một bậc là hợp lệ, mỗi rule một quota riêng.
 * 4. Fallback "📦 Các danh mục còn lại" KHÔNG tick được (backend từ chối).
 * 5. Tick ở hai bậc khác nhau của cùng một policy version ⇒ 422 kèm đúng message
 *    `CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE`.
 * 6. Cờ SỐNG SÓT qua mọi đường nhân bản: clone thẻ từ template, tạo version mới,
 *    clone tier/rule, clone System Policy từ Policy Editor.
 *
 * Bất biến 5 có hai đường vào khác nhau nên cả hai đều phải chặn:
 *   - payload nhiều bậc (Policy Editor)      → `assertQuotaCategoryTierUniqueness()`
 *   - sửa MỘT rule (API rule lẻ)            → `assertQuotaCategoryTierIsUniqueIn()`
 */
class QuotaCategoryFlagTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        Role::create(['name' => 'Admin'])->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);

        $this->owner = User::factory()->create();
        // `PolicyTemplatePolicy` chặn ghi lên system template nếu user không có
        // role Admin — permission đơn lẻ không đủ cho các route quản trị.
        $this->admin = User::factory()->create()->assignRole('Admin');
    }

    // =====================================================================
    // Mặc định
    // =====================================================================

    #[Test]
    public function a_new_rule_is_not_a_quota_category_by_default(): void
    {
        $rule = app(CategoryRuleService::class)->create($this->cardTier(), [
            'category_id' => $this->makeSystemCategory()->id,
            'cashback_percent' => 5,
        ]);

        $this->assertFalse($rule->is_quota_category);
        $this->assertFalse($rule->refresh()->is_quota_category);
    }

    #[Test]
    public function the_database_default_is_false_so_existing_rules_are_not_ticked(): void
    {
        $tier = $this->cardTier();
        $category = $this->makeSystemCategory();

        // Insert y hệt code cũ (chưa có cờ) ⇒ cột phải tự về false.
        $rule = PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $category->id,
            'cashback_percent' => 3,
        ]);

        $this->assertFalse($rule->refresh()->is_quota_category);
        $this->assertFalse(PolicyTierCategory::query()->quotaCategory()->exists());
    }

    #[Test]
    public function the_fallback_rule_never_counts_as_a_quota_category(): void
    {
        $fallback = app(CategoryRuleService::class)->ensureSingleFallback($this->cardTier());

        $this->assertTrue($fallback->isFallback());
        $this->assertFalse($fallback->canBeQuotaCategory());
        $this->assertFalse($fallback->isQuotaCategory());
    }

    // =====================================================================
    // Tick category / combo / nhiều rule cùng bậc
    // =====================================================================

    #[Test]
    public function a_category_rule_can_be_marked_as_a_quota_category(): void
    {
        $rules = app(CategoryRuleService::class);

        $rule = $rules->create($this->cardTier(), [
            'category_id' => $this->makeSystemCategory()->id,
            'cashback_percent' => 5,
            'is_quota_category' => true,
        ]);

        $this->assertTrue($rule->is_quota_category);
        $this->assertTrue($rule->refresh()->isQuotaCategory());
    }

    #[Test]
    public function a_combo_rule_can_be_marked_as_a_quota_category(): void
    {
        $rule = app(CategoryRuleService::class)->create($this->cardTier(), [
            'combo_id' => $this->makeCombo([$this->makeSystemCategory()->id])->id,
            'cashback_percent' => 7,
            'is_quota_category' => true,
        ]);

        $this->assertTrue($rule->refresh()->isQuotaCategory());
        $this->assertNull($rule->category_id, 'Rule combo không được mang category_id.');
    }

    #[Test]
    public function several_rules_in_one_tier_can_all_be_quota_categories(): void
    {
        $tier = $this->cardTier();
        $rules = app(CategoryRuleService::class);

        // Ví dụ §1.4: ăn uống, siêu thị, mua sắm trực tuyến và combo đều là
        // quota của CÙNG một bậc — mỗi dòng một hạn mức riêng.
        $targets = [
            ['category_id' => $this->makeSystemCategory()->id],
            ['category_id' => $this->makeSystemCategory()->id],
            ['category_id' => $this->makeSystemCategory()->id],
            ['combo_id' => $this->makeCombo([$this->makeSystemCategory()->id])->id],
        ];

        foreach ($targets as $target) {
            $rules->create($tier, $target + ['cashback_percent' => 5, 'is_quota_category' => true]);
        }

        $this->assertSame(4, $tier->tierCategoryRules()->quotaCategory()->count());
    }

    #[Test]
    public function a_rule_can_be_unticked_afterwards(): void
    {
        $rules = app(CategoryRuleService::class);
        $rule = $rules->create($this->cardTier(), [
            'category_id' => $this->makeSystemCategory()->id,
            'cashback_percent' => 5,
            'is_quota_category' => true,
        ]);

        $this->assertFalse($rules->update($rule->id, ['is_quota_category' => false])->is_quota_category);
    }

    // =====================================================================
    // Fallback không tick được
    // =====================================================================

    #[Test]
    public function the_fallback_rule_cannot_be_marked_as_a_quota_category(): void
    {
        $rules = app(CategoryRuleService::class);
        $fallback = $rules->ensureSingleFallback($this->cardTier());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('không thể tính hạn mức chi tiêu còn lại');

        $rules->update($fallback->id, ['is_quota_category' => true]);
    }

    #[Test]
    public function ticking_the_fallback_through_the_rule_api_returns_422(): void
    {
        $tier = $this->cardTier();
        $fallback = app(CategoryRuleService::class)->ensureSingleFallback($tier);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.rules.update', $fallback->id), ['is_quota_category' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'không thể tính hạn mức chi tiêu còn lại'));

        $this->assertFalse($fallback->refresh()->is_quota_category);
    }

    #[Test]
    public function a_policy_payload_marking_the_fallback_as_quota_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('không thể tính hạn mức chi tiêu còn lại');

        app(CategoryRuleService::class)->assertQuotaCategoryTierUniqueness([
            [
                'rules' => [
                    ['scope_type' => PolicyTierCategory::SCOPE_OTHER, 'is_quota_category' => true],
                ],
            ],
        ]);
    }

    // =====================================================================
    // Bất biến "cùng một bậc"
    // =====================================================================

    #[Test]
    public function marking_rules_in_two_tiers_of_a_new_policy_returns_422(): void
    {
        $categoryA = $this->makeSystemCategory();
        $categoryB = $this->makeSystemCategory();

        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'name' => 'Thẻ cấu hình lỗi',
            'policy' => [
                'effective_from' => '2026-10-01',
                'tiers' => [
                    $this->tierPayload('Bậc 1', 0, 5000000, $categoryA->id, 5),
                    $this->tierPayload('Bậc 2', 5000000, null, $categoryB->id, 7),
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE);

        $this->assertSame(0, PolicyTierCategory::query()->quotaCategory()->count());
    }

    #[Test]
    public function marking_rules_in_two_tiers_of_an_existing_policy_returns_422_and_keeps_the_config(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
            ],
            [
                ['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000', 'only_tier' => 0],
                ['category_id' => $this->makeSystemCategory()->id, 'percent' => '7.000', 'only_tier' => 1],
            ]
        );

        $tiers = $policy->tiers()->orderBy('sort_order')->get();
        $rules = $tiers->map(fn (PolicyTier $tier): PolicyTierCategory => $tier->tierCategoryRules()->firstOrFail());

        $response = $this->actingAs($this->owner)->patchJson(route('credit-cards.api.cards.update', $card->id), [
            'policy' => [
                'effective_from' => '2026-10-01',
                'tiers' => [
                    $this->tierPayload('Bậc 1', 0, 5000000, $rules[0]->category_id, 5, $tiers[0]->id, $rules[0]->id),
                    $this->tierPayload('Bậc 2', 5000000, null, $rules[1]->category_id, 7, $tiers[1]->id, $rules[1]->id),
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE);

        // `syncTiers()` XOÁ rule cũ rồi tạo lại — kiểm PHẢI chạy trước đó, nếu
        // không payload sai sẽ xoá sạch cấu hình rồi mới ném lỗi.
        $this->assertSame(0, PolicyTierCategory::query()->quotaCategory()->count());
        $this->assertSame(2, PolicyTier::query()->where('policy_id', $policy->id)->count());
        $this->assertSame(
            ['5.000', '7.000'],
            PolicyTierCategory::query()
                ->whereIn('tier_id', PolicyTier::query()->where('policy_id', $policy->id)->select('id'))
                ->orderBy('sort_order')
                ->get()
                ->reject(fn (PolicyTierCategory $rule): bool => $rule->isFallback())
                ->map(fn (PolicyTierCategory $rule): string => $rule->cashback_percent)
                ->values()
                ->all(),
        );
    }

    #[Test]
    public function ticking_a_second_rule_in_another_tier_through_the_single_rule_api_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
            ],
            [
                ['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000', 'only_tier' => 0],
                ['category_id' => $this->makeSystemCategory()->id, 'percent' => '7.000', 'only_tier' => 1],
            ]
        );

        $tiers = $policy->tiers()->orderBy('sort_order')->get();
        $firstRule = $tiers[0]->tierCategoryRules()->firstOrFail();
        $secondRule = $tiers[1]->tierCategoryRules()->firstOrFail();

        // Bậc 1 tick trước — hợp lệ.
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.rules.update', $firstRule->id), ['is_quota_category' => true])
            ->assertOk();
        $this->assertTrue($firstRule->refresh()->is_quota_category);

        // Bậc 2 tick sau: đường rule lẻ chỉ thấy MỘT bậc, phải hỏi DB mới thấy
        // bậc kia đang tick.
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.rules.update', $secondRule->id), ['is_quota_category' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE);

        $this->assertFalse($secondRule->refresh()->is_quota_category);
    }

    #[Test]
    public function quota_rules_of_another_policy_version_do_not_conflict(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
            ],
            [
                ['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000', 'only_tier' => 0],
                ['category_id' => $this->makeSystemCategory()->id, 'percent' => '7.000', 'only_tier' => 1],
            ]
        );

        $tiers = $policy->tiers()->orderBy('sort_order')->get();
        $rules = $tiers->map(fn (PolicyTier $tier): PolicyTierCategory => $tier->tierCategoryRules()->firstOrFail());

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.rules.update', $rules[0]->id), ['is_quota_category' => true])
            ->assertOk();

        // Version MỚI copy cờ của version cũ rồi thêm quota ở bậc khác: bản chất
        // "cùng một bậc" là trong phạm vi MỘT version, nên version mới bắt buộc
        // phải tự gọt lại cờ trước khi áp override.
        $next = app(PolicyCloneService::class)
            ->createNextVersion($card->refresh(), CarbonImmutable::parse('2026-11-01'));

        $nextTiers = PolicyTier::query()->where('policy_id', $next->id)->orderBy('sort_order')->get();

        $this->assertSame(2, $nextTiers->count());
        $this->assertSame(
            1,
            PolicyTierCategory::query()
                ->whereIn('tier_id', PolicyTier::query()->where('policy_id', $next->id)->select('id'))
                ->quotaCategory()
                ->count(),
            'Version mới phải copy đúng cấu hình của version nguồn.'
        );
    }

    #[Test]
    public function a_second_rule_in_the_same_tier_can_still_be_ticked(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [
                ['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000'],
                ['category_id' => $this->makeSystemCategory()->id, 'percent' => '6.000'],
            ]
        );

        $rules = $policy->tiers()->firstOrFail()->tierCategoryRules()->orderBy('id')->get();

        foreach ($rules as $rule) {
            $this->actingAs($this->owner)
                ->patchJson(route('credit-cards.api.rules.update', $rule->id), ['is_quota_category' => true])
                ->assertOk();
        }

        $this->assertSame(2, PolicyTierCategory::query()->quotaCategory()->count());
    }

    // =====================================================================
    // Nhân bản giữ cờ
    // =====================================================================

    #[Test]
    public function cloning_a_quota_rule_into_another_tier_of_the_same_version_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
            ],
            [['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000', 'only_tier' => 0]]
        );

        $tiers = $policy->tiers()->orderBy('sort_order')->get();
        $source = $tiers[0]->tierCategoryRules()->firstOrFail();
        $source->forceFill(['is_quota_category' => true])->save();

        // Nhân bản sang bậc 2 của CÙNG version ⇒ thành hai bậc cùng có quota ⇒
        // vi phạm bất biến, phải chặn.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE);

        app(CategoryRuleService::class)->cloneRuleTo($source->refresh(), $tiers[1]);
    }

    #[Test]
    public function cloning_a_plain_rule_into_another_tier_keeps_it_unticked(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
            ],
            [['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000', 'only_tier' => 0]]
        );

        $tiers = $policy->tiers()->orderBy('sort_order')->get();
        $source = $tiers[0]->tierCategoryRules()->firstOrFail();

        $copy = app(CategoryRuleService::class)->cloneRuleTo($source, $tiers[1]);

        $this->assertFalse($copy->is_quota_category);
        $this->assertSame((int) $tiers[1]->id, (int) $copy->tier_id);
    }

    #[Test]
    public function cloning_a_tier_with_a_quota_rule_into_another_tier_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [
                ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
                ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
            ],
            [['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000', 'only_tier' => 0]]
        );

        $tiers = $policy->tiers()->orderBy('sort_order')->get();
        $tiers[0]->tierCategoryRules()->firstOrFail()->forceFill(['is_quota_category' => true])->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE);

        app(CategoryRuleService::class)->cloneAllTo($tiers[0], $tiers[1]);
    }

    #[Test]
    public function cloning_a_tier_keeps_the_flag_on_every_rule_of_a_fresh_version(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000']]
        );

        $categoryId = (int) $policy->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail()->category_id;
        $policy->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail()
            ->forceFill(['is_quota_category' => true])
            ->save();

        $next = app(PolicyCloneService::class)
            ->createNextVersion($card->refresh(), CarbonImmutable::parse('2026-11-01'));

        $copied = PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::query()->where('policy_id', $next->id)->select('id'))
            ->quotaCategory()
            ->get();

        $this->assertCount(1, $copied);
        $this->assertSame($categoryId, (int) $copied->first()->category_id);
    }

    #[Test]
    public function creating_the_next_policy_version_keeps_the_flag_and_leaves_the_old_one_untouched(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard(
            $card,
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            [['category_id' => $this->makeSystemCategory()->id, 'percent' => '5.000']]
        );

        $policy->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail()
            ->forceFill(['is_quota_category' => true])
            ->save();

        $next = app(PolicyCloneService::class)
            ->createNextVersion($card->refresh(), CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(1, $this->quotaRuleCountOf($next->id));
        $this->assertSame(1, $this->quotaRuleCountOf($policy->id), 'Version cũ phải giữ nguyên (append-only).');
    }

    #[Test]
    public function attaching_a_system_template_to_a_card_keeps_the_flag(): void
    {
        $template = $this->makeSystemPolicyWithQuotaRule();

        $version = app(PolicyCloneService::class)->attachTemplateToCard(
            $this->makeUserCard($this->owner->id),
            $template,
            CarbonImmutable::parse('2026-10-01'),
        );

        $copied = PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::query()->where('policy_id', $version->id)->select('id'))
            ->quotaCategory()
            ->get();

        $this->assertCount(1, $copied);
        $this->assertNotSame(
            (int) $template->blueprint->tiers->firstOrFail()->tierCategoryRules->firstOrFail()->id,
            (int) $copied->first()->id,
            'Phải là bản SAO chứ không phải dòng dùng chung với blueprint.'
        );
    }

    #[Test]
    public function cloning_a_system_policy_from_the_editor_keeps_the_flag(): void
    {
        $template = $this->makeSystemPolicyWithQuotaRule();

        $this->actingAs($this->admin)->postJson(
            route('admin.credit-card-policies.api.clone', $template),
            [
                'name' => 'Bản sao có quota '.uniqid(),
                'status' => 'published',
                'effective_from' => '2026-11-01',
            ],
        )->assertCreated();

        $clone = PolicyTemplate::query()
            ->where('name', 'like', 'Bản sao có quota%')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame(1, $this->quotaRuleCountOf($clone->blueprint->id));
    }

    #[Test]
    public function saving_a_system_policy_version_from_the_editor_keeps_the_flag(): void
    {
        $template = $this->makeSystemPolicyWithQuotaRule();
        $blueprint = $template->blueprint;
        $sourceTier = $blueprint->tiers->firstOrFail();
        $sourceRule = $sourceTier->tierCategoryRules->firstOrFail();

        $this->actingAs($this->admin)->postJson(
            route('admin.credit-card-policies.api.versions.store', $template),
            [
                'effective_from' => '2026-12-01',
                'source_version_id' => $blueprint->id,
                'tiers' => [
                    [
                        'name' => $sourceTier->name,
                        'sort_order' => $sourceTier->sort_order,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            [
                                'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                                'category_id' => $sourceRule->category_id,
                                'cashback_percent' => (float) $sourceRule->cashback_percent,
                                'is_quota_category' => true,
                            ],
                        ],
                    ],
                ],
            ],
        )->assertCreated();

        $newest = $template->refresh()->currentBlueprint();

        $this->assertNotSame((int) $blueprint->id, (int) $newest->id);
        $this->assertSame(1, $this->quotaRuleCountOf($newest->id));
        $this->assertSame(1, $this->quotaRuleCountOf($blueprint->id), 'Version cũ phải giữ nguyên.');
        $this->assertNotSame((int) $sourceTier->id, (int) $newest->tiers->firstOrFail()->id);
    }

    #[Test]
    public function the_system_policy_editor_payload_rejects_quota_rules_in_two_tiers(): void
    {
        $template = $this->makeSystemPolicyWithQuotaRule();
        $categoryA = $this->makeSystemCategory();
        $categoryB = $this->makeSystemCategory();

        $this->actingAs($this->admin)->postJson(
            route('admin.credit-card-policies.api.versions.store', $template),
            [
                'effective_from' => '2026-12-01',
                'tiers' => [
                    $this->tierPayload('Bậc 1', 0, 5000000, $categoryA->id, 5),
                    $this->tierPayload('Bậc 2', 5000000, null, $categoryB->id, 7),
                ],
            ],
        )->assertStatus(422)
            ->assertJsonPath('message', CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE);

        $this->assertSame(1, PolicyTemplate::query()->where('id', $template->id)->count(), 'Không sinh version rác.');
    }

    #[Test]
    public function the_rule_payload_reports_the_flag_back_to_the_client(): void
    {
        $tier = $this->cardTier();
        $rules = app(CategoryRuleService::class);

        $ticked = $rules->create($tier, [
            'category_id' => $this->makeSystemCategory()->id,
            'cashback_percent' => 5,
            'is_quota_category' => true,
        ]);
        $plain = $rules->create($tier, [
            'category_id' => $this->makeSystemCategory()->id,
            'cashback_percent' => 6,
        ]);
        $fallback = $rules->ensureSingleFallback($tier);

        $payload = collect($this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.rules.index', $tier->id))
            ->assertOk()
            ->json('data'))
            ->keyBy('id');

        $this->assertTrue($payload[$ticked->id]['is_quota_category']);
        $this->assertFalse($payload[$plain->id]['is_quota_category']);
        $this->assertFalse($payload[$fallback->id]['is_quota_category']);
    }

    #[Test]
    public function a_dirty_quota_flag_on_a_fallback_is_still_reported_as_false(): void
    {
        $fallback = app(CategoryRuleService::class)->ensureSingleFallback($this->cardTier());
        $fallback->forceFill(['is_quota_category' => true])->save();

        $this->assertFalse($fallback->refresh()->isQuotaCategory(), 'Cột bẩn không được lọt vào phần quota.');
    }

    // =====================================================================
    // Policy Editor
    // =====================================================================

    #[Test]
    public function the_editor_offers_the_quota_checkbox_with_the_agreed_wording(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.credit-card-policies.edit', $this->makeSystemPolicyWithQuotaRule()))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Tính hạn mức chi tiêu còn lại', $html);
        $this->assertStringContainsString(
            'Dùng danh mục này để tính số tiền bạn còn có thể chi thêm',
            $html
        );
        $this->assertStringContainsString('x-model="rule.is_quota_category"', $html);
        // Fallback không có mục tiêu chi tiêu cụ thể ⇒ không được hiện ô tick.
        $this->assertStringContainsString("x-if=\"rule.target_scope !== 'other'\"", $html);
    }

    #[Test]
    public function the_editor_reuses_the_backend_message_for_the_tier_conflict(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.credit-card-policies.edit', $this->makeSystemPolicyWithQuotaRule()))
            ->assertOk()
            ->getContent();

        // Hằng số JS phải trùng hằng số PHP, nếu lệch thì admin thấy một câu còn
        // backend trả câu khác. `@js()` escape Unicode ⇒ so sánh trên dạng đã
        // encode.
        $this->assertStringContainsString(
            Js::from(CategoryRuleService::QUOTA_TIER_CONFLICT_MESSAGE),
            $html
        );
    }

    #[Test]
    public function the_read_only_page_marks_a_quota_rule(): void
    {
        $template = $this->makeSystemPolicyWithQuotaRule();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Tính hạn mức chi tiêu còn lại', $html);
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    private function tierPayload(
        string $name,
        float $min,
        ?float $max,
        int $categoryId,
        float $percent,
        ?int $tierId = null,
        ?int $ruleId = null,
    ): array {
        return [
            'id' => $tierId,
            'name' => $name,
            'sort_order' => 1,
            'min_total_spend' => $min,
            'max_total_spend' => $max,
            'rules' => [[
                'id' => $ruleId,
                'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                'category_id' => $categoryId,
                'cashback_percent' => $percent,
                'is_quota_category' => true,
            ]],
        ];
    }

    private function cardTier(): PolicyTier
    {
        $policy = $this->makePolicyForCard(
            $this->makeUserCard($this->owner->id),
            [['name' => 'Bậc 1', 'min' => 0, 'max' => null]],
            []
        );

        return $policy->tiers()->firstOrFail();
    }

    /**
     * @param  array<int, int>  $categoryIds
     */
    private function makeCombo(array $categoryIds): CategoryCombo
    {
        return app(CategoryComboService::class)->createSystemCombo([
            'name' => 'Combo quota '.uniqid(),
            'category_ids' => $categoryIds,
        ]);
    }

    /**
     * Chính sách hệ thống một bậc, có đúng một rule được tick quota.
     */
    private function makeSystemPolicyWithQuotaRule(): PolicyTemplate
    {
        $template = app(PolicyService::class)->createSystemTemplate(
            'Chính sách quota '.uniqid(),
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'is_quota_category' => true,
                            ],
                        ],
                    ],
                ],
            ],
            true,
        );

        $this->assertSame(1, $this->quotaRuleCountOf($template->blueprint->id), 'Fixture phải tick được rule.');

        return $template;
    }

    private function quotaRuleCountOf(int $policyId): int
    {
        return PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::query()->where('policy_id', $policyId)->select('id'))
            ->quotaCategory()
            ->count();
    }
}
