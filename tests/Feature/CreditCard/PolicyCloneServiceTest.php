<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\PolicyCloneService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * §9, §13 — PolicyCloneService.
 *
 * Hai bất biến được bảo vệ ở đây:
 *   1. DEEP CLONE: dùng template cho thẻ B không bao giờ khiến thẻ B dùng chung
 *      bản ghi với thẻ A hay với blueprint.
 *   2. APPEND-ONLY: version mới không sửa business rule của version cũ.
 */
class PolicyCloneServiceTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private PolicyCloneService $service;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->service = app(PolicyCloneService::class);
    }

    // =====================================================================
    // Deep clone từ template
    // =====================================================================

    #[Test]
    public function attaching_a_template_creates_a_copy_not_a_shared_reference(): void
    {
        [$user, $card, $template, $blueprint] = $this->seedTemplate();

        $version = $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        $this->assertNotSame($blueprint->id, $version->id, 'Phải tạo bản ghi policy mới.');
        $this->assertSame($card->id, (int) $version->user_card_id);
        $this->assertSame(1, (int) $version->version_no);
        $this->assertSame($version->id, (int) $version->root_policy_id, 'Root phải tự trỏ về chính nó.');

        $blueprintTierIds = $blueprint->tiers->pluck('id')->all();
        $clonedTierIds = $version->tiers->pluck('id')->all();

        $this->assertNotEmpty($blueprintTierIds);
        $this->assertSame([], array_intersect($blueprintTierIds, $clonedTierIds), 'Tier bị dùng chung ID.');

        $blueprintRuleIds = PolicyTierCategory::whereIn('tier_id', $blueprintTierIds)->pluck('id')->all();
        $clonedRuleIds = PolicyTierCategory::whereIn('tier_id', $clonedTierIds)->pluck('id')->all();

        $this->assertNotEmpty($blueprintRuleIds);
        $this->assertSame([], array_intersect($blueprintRuleIds, $clonedRuleIds), 'Rule bị dùng chung ID.');

        $this->assertSame(
            $version->id,
            (int) $card->refresh()->current_policy_id,
            'Thẻ phải trỏ về policy vừa clone, không phải blueprint.'
        );
    }

    #[Test]
    public function editing_a_cloned_policy_does_not_touch_the_template_blueprint(): void
    {
        [$user, $card, $template, $blueprint] = $this->seedTemplate();

        $version = $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        // Admin sửa rate trên bản clone.
        $rule = $version->tiers->first()->tierCategoryRules->first();
        $rule->forceFill(['cashback_percent' => 99])->save();

        $blueprint->refresh();
        $blueprintRule = $blueprint->tiers->first()->tierCategoryRules->first();

        $this->assertSame(
            2.0,
            (float) $blueprintRule->cashback_percent,
            'Sửa bản clone đã làm đổi blueprint của template.'
        );
    }

    #[Test]
    public function two_cards_using_the_same_template_get_independent_policies(): void
    {
        [$user, $cardA, $template] = $this->seedTemplate();
        $cardB = $this->makeUserCard($user->id);

        $versionA = $this->service->attachTemplateToCard($cardA, $template, CarbonImmutable::parse('2026-10-01'));
        $versionB = $this->service->attachTemplateToCard($cardB, $template, CarbonImmutable::parse('2026-10-01'));

        $this->assertNotSame($versionA->id, $versionB->id);

        $ruleA = $versionA->tiers->first()->tierCategoryRules->first();
        $ruleB = $versionB->tiers->first()->tierCategoryRules->first();

        $this->assertNotSame($ruleA->id, $ruleB->id);

        $ruleA->forceFill(['cashback_percent' => 50])->save();

        $this->assertSame(2.0, (float) $ruleB->refresh()->cashback_percent);
    }

    #[Test]
    public function a_template_without_a_blueprint_cannot_be_attached(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $template = $this->makeSystemTemplate();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/chưa có policy blueprint/');

        $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));
    }

    // =====================================================================
    // Lưu thành template
    // =====================================================================

    #[Test]
    public function saving_a_card_policy_as_a_template_produces_a_reusable_blueprint(): void
    {
        [$user, $card, $template] = $this->seedTemplate();

        $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        $newTemplate = $this->service->saveAsTemplate($card, $user->id, 'Template của tôi');

        $this->assertSame(PolicyTemplate::SCOPE_USER, $newTemplate->scope);
        $this->assertSame($user->id, (int) $newTemplate->owner_user_id);
        $this->assertFalse($newTemplate->is_builtin);
        $this->assertSame('template-cua-toi', $newTemplate->slug);

        $blueprint = $newTemplate->blueprint;
        $this->assertNotNull($blueprint);
        $this->assertNull($blueprint->user_card_id, 'Blueprint phải rời (user_card_id = NULL).');

        // Template mới chưa được thẻ nào dùng ⇒ chưa có bản clone nào.
        $this->assertCount(0, $newTemplate->clonedPolicies()->get());

        // Dùng template mới cho một thẻ khác thì sinh bản clone độc lập.
        $otherCard = $this->makeUserCard($user->id);
        $cloned = $this->service->attachTemplateToCard(
            $otherCard,
            $newTemplate,
            CarbonImmutable::parse('2026-10-01')
        );

        $this->assertCount(1, $newTemplate->refresh()->clonedPolicies()->get());
        $this->assertNotSame($blueprint->id, $cloned->id);
    }

    #[Test]
    public function template_slugs_are_unique_even_with_the_same_name(): void
    {
        [$user, $card, $template] = $this->seedTemplate();
        $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        $first = $this->service->saveAsTemplate($card, $user->id, 'Trùng tên');
        $second = $this->service->saveAsTemplate($card, $user->id, 'Trùng tên');

        $this->assertNotSame($first->slug, $second->slug);
    }

    #[Test]
    public function a_card_without_a_policy_cannot_be_saved_as_a_template(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/chưa có policy/');

        $this->service->saveAsTemplate($card, $user->id, 'Không có gì để lưu');
    }

    // =====================================================================
    // §9.2 — Version append-only
    // =====================================================================

    #[Test]
    public function creating_a_new_version_does_not_modify_the_previous_version_rules(): void
    {
        [$user, $card, $template] = $this->seedTemplate();

        $v1 = $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        $v1RuleBefore = $v1->tiers->first()->tierCategoryRules->first();
        $percentBefore = (float) $v1RuleBefore->cashback_percent;
        $tierCountBefore = PolicyTier::where('policy_id', $v1->id)->count();

        $v2 = $this->service->createNextVersion($card, CarbonImmutable::parse('2026-11-01'), [
            'name' => 'Policy đã nâng cấp',
        ]);

        $this->assertNotSame($v1->id, $v2->id);
        $this->assertSame(2, (int) $v2->version_no);
        $this->assertSame($v1->id, (int) $v2->root_policy_id);

        // Business rule của v1 BẤT BIẾN.
        $v1->refresh();
        $v1RuleAfter = PolicyTierCategory::find($v1RuleBefore->id);

        $this->assertSame(
            $percentBefore,
            (float) $v1RuleAfter->cashback_percent,
            'Business rule của version cũ đã bị sửa — vi phạm append-only.'
        );
        $this->assertSame($tierCountBefore, PolicyTier::where('policy_id', $v1->id)->count());
        $this->assertSame(Policy::STATUS_SUPERSEDED, $v1->status);
    }

    #[Test]
    public function the_previous_version_is_closed_the_day_before_the_new_one_starts(): void
    {
        [$user, $card, $template] = $this->seedTemplate();

        $v1 = $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));
        $this->service->createNextVersion($card, CarbonImmutable::parse('2026-11-01'));

        $v1->refresh();

        $this->assertSame('2026-10-31', $v1->effective_to->toDateString());
        $this->assertSame(Policy::STATUS_SUPERSEDED, $v1->status);
    }

    #[Test]
    public function version_numbers_increase_without_gaps_or_duplicates(): void
    {
        [$user, $card, $template] = $this->seedTemplate();

        $v1 = $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        foreach ([2, 3, 4] as $offset) {
            $this->service->createNextVersion(
                $card,
                CarbonImmutable::parse('2026-10-01')->addMonths($offset)
            );
        }

        $chain = PolicyVersion::where(function ($query) use ($v1): void {
            $query->where('id', $v1->id)->orWhere('root_policy_id', $v1->id);
        })->orderBy('version_no')->get();

        $this->assertCount(4, $chain);
        $this->assertSame([1, 2, 3, 4], $chain->pluck('version_no')->map('intval')->all());
        $this->assertSame(1, $chain->where('status', Policy::STATUS_ACTIVE)->count());
    }

    #[Test]
    public function each_version_gets_its_own_copy_of_the_tiers(): void
    {
        [$user, $card, $template] = $this->seedTemplate();

        $v1 = $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));
        $v1TierIds = $v1->tiers->pluck('id')->all();

        $v2 = $this->service->createNextVersion($card, CarbonImmutable::parse('2026-11-01'));

        $v2TierIds = $v2->tiers->pluck('id')->all();

        $this->assertSame([], array_intersect($v1TierIds, $v2TierIds));
    }

    /**
     * REGRESSION: version N+1 phải kế thừa cấu hình của version N, KHÔNG phải
     * của `root` (version 1).
     *
     * Trước khi sửa, `createNextVersion()` gọi `copyChildren($root->id, ...)`, nên
     * mọi chỉnh sửa làm ở version 2 (ở đây là rate 7.5%) bị mất hoàn toàn khi tạo
     * version 3 — version 3 quay về rate 2% của version 1.
     */
    #[Test]
    public function a_new_version_inherits_the_previous_version_rules_not_the_root(): void
    {
        [$user, $card, $template] = $this->seedTemplate();
        $categoryId = $this->seedTemplateWithRates()['category_id'];

        $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        // Version 2: đổi rate 2% -> 7.5%
        $v2 = $this->service->createNextVersion($card, CarbonImmutable::parse('2026-11-01'), [
            'tiers' => [
                [
                    'name' => 'Bậc 2 đã chỉnh',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'categories' => [
                        [
                            'category_id' => $categoryId,
                            'cashback_percent' => 7.5,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            7.5,
            (float) $v2->tiers->first()->tierCategoryRules->first()->cashback_percent
        );

        // Version 3: KHÔNG truyền `tiers` ⇒ phải kế thừa version 2 (7.5%),
        // không phải version 1 (2%).
        $v3 = $this->service->createNextVersion($card, CarbonImmutable::parse('2026-12-01'));

        $this->assertSame(
            7.5,
            (float) $v3->tiers->first()->tierCategoryRules->first()->cashback_percent,
            'Version 3 phải kế thừa rate của version 2.'
        );

        // Metadata cũng phải kế thừa từ version 2.
        $this->assertSame($v2->min_total_spend, $v3->min_total_spend);
        $this->assertSame($v2->rounding_mode, $v3->rounding_mode);
    }

    /**
     * REGRESSION: `saveAsTemplate()` phải lấy tiers từ version đang áp dụng.
     *
     * Trước khi sửa, metadata lấy từ `$source` (version N) nhưng tiers lại lấy từ
     * `root` (version 1) ⇒ template sinh ra có cấu hình lộn xộn.
     */
    #[Test]
    public function saving_as_a_template_uses_the_current_version_rules(): void
    {
        [$user, $card, $template] = $this->seedTemplate();
        $categoryId = $this->seedTemplateWithRates()['category_id'];

        $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));

        $this->service->createNextVersion($card, CarbonImmutable::parse('2026-11-01'), [
            'tiers' => [
                [
                    'name' => 'Bậc 2 đã chỉnh',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'categories' => [
                        [
                            'category_id' => $categoryId,
                            'cashback_percent' => 9.25,
                        ],
                    ],
                ],
            ],
        ]);

        $saved = $this->service->saveAsTemplate($card, $user->id, 'Từ version 2');

        $this->assertSame(
            9.25,
            (float) $saved->blueprint->tiers->first()->tierCategoryRules->first()->cashback_percent,
            'Template phải mang cấu hình của version đang áp dụng.'
        );
    }

    #[Test]
    public function overrides_only_change_the_new_version(): void
    {
        [$user, $card, $template] = $this->seedTemplate();
        $categoryId = $this->seedTemplateWithRates()['category_id'];

        $v1 = $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));
        $v1Rate = (float) $v1->tiers->first()->tierCategoryRules->first()->cashback_percent;

        $v2 = $this->service->createNextVersion($card, CarbonImmutable::parse('2026-11-01'), [
            'min_total_spend' => 10000000,
            'tiers' => [
                [
                    'name' => 'Bậc mới',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'categories' => [
                        [
                            'category_id' => $categoryId,
                            'cashback_percent' => 7.5,
                            'spend_from' => 0,
                            'spend_to' => null,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('10000000.00', $v2->min_total_spend);
        $this->assertSame(7.5, (float) $v2->tiers->first()->tierCategoryRules->first()->cashback_percent);
        $this->assertSame($v1Rate, (float) $v1->refresh()->tiers->first()->tierCategoryRules->first()->cashback_percent);
    }

    #[Test]
    public function a_locked_policy_refuses_to_create_a_new_version(): void
    {
        [$user, $card, $template] = $this->seedTemplate();

        $v1 = $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));
        $v1->forceFill(['is_locked' => true])->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/bị khoá/');

        $this->service->createNextVersion($card, CarbonImmutable::parse('2026-11-01'));
    }

    #[Test]
    public function attaching_a_template_is_all_or_nothing(): void
    {
        [$user, $card, $template] = $this->seedTemplate();

        $policiesBefore = DB::connection('creditcard')->table('credit_card_policies')->count();

        // Cố tình huỷ giữa chừng bằng cách truyền effective date sai kiểu để
        // transaction rollback sạch: dùng template đã bị xoá.
        $template->delete();
        $template = $this->makeSystemTemplate();

        try {
            $this->service->attachTemplateToCard($card, $template, CarbonImmutable::parse('2026-10-01'));
        } catch (LogicException) {
            // Bỏ qua — kiểm tra phía dưới.
        }

        $this->assertSame(
            $policiesBefore,
            DB::connection('creditcard')->table('credit_card_policies')->count(),
            'Transaction phải rollback sạch khi clone thất bại.'
        );
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * @return array{0: User, 1: UserCard, 2: PolicyTemplate, 3: Policy}
     */
    private function seedTemplate(): array
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);

        $category = $this->makeSystemCategory();
        $template = $this->makeSystemTemplate();

        $blueprint = Policy::create([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => 'Blueprint test',
            'effective_from' => '2026-01-01',
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);
        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        $tier = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Bậc cơ bản',
            'sort_order' => 1,
            'min_total_spend' => 0,
            'max_total_spend' => null,
        ]);

        PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $category->id,
            'spend_from' => 0,
            'spend_to' => null,
            'cashback_percent' => 2.0,
            'is_enabled' => true,
        ]);

        return [$user, $card, $template, $blueprint->refresh()];
    }

    /**
     * @return array{category_id: int}
     */
    private function seedTemplateWithRates(): array
    {
        [, , $template] = $this->seedTemplate();

        return [
            'category_id' => (int) $template->blueprint->tiers->first()->tierCategoryRules->first()->category_id,
        ];
    }
}
