<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CategoryComboService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Phase 1C — HTTP layer cho cấu hình policy cashback.
 *
 * Những điều tuyệt đối được bảo vệ:
 *   1. Route luôn trong middleware `auth` + `throttle:credit-card-api`.
 *   2. Không endpoint nào nhận `user_id` / `cashback_amount` từ request.
 *   3. Truy cập chéo user bị chặn 403 (hoặc 404 khi scope theo thẻ), không lộ dữ liệu.
 *   4. Version khoá / superseded bất biến ⇒ mọi request sửa vào đó trả 403.
 *   5. Nhân bản bậc là deep clone: cả rule cashback đều được copy.
 *   6. Rate limit theo USER, không theo IP, và trả JSON khi vượt.
 *
 * Policy version lấy từ dịch vụ `PolicyService`, tier/rule từ `TierService` /
 * `CategoryRuleService`. Tầng này chỉ chứng minh Controller + Policy + Route
 * đúng; nghiệp vụ nằm bên dưới và đã được `CardManagementServicesTest` cover.
 */
class Phase1cHttpTest extends TestCase
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
    public function every_phase1c_endpoint_requires_authentication(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();
        $rule = $this->makeRuleFor($tier);
        $template = $this->makeTemplateWithBlueprint($this->owner, PolicyTemplate::SCOPE_USER);

        $this->getJson(route('credit-cards.api.policies.index', $card))->assertUnauthorized();
        $this->postJson(route('credit-cards.api.policies.store', $card), [])->assertUnauthorized();
        $this->getJson(route('credit-cards.api.policies.show', [$card, $policy]))->assertUnauthorized();
        $this->patchJson(route('credit-cards.api.policies.update', [$card, $policy]), [])->assertUnauthorized();
        $this->postJson(route('credit-cards.api.policies.versions.store', $card), [])->assertUnauthorized();
        $this->postJson(route('credit-cards.api.policies.templates.store', $card), [])->assertUnauthorized();
        $this->getJson(route('credit-cards.api.templates.index'))->assertUnauthorized();
        $this->getJson(route('credit-cards.api.templates.show', $template))->assertUnauthorized();
        $this->getJson(route('credit-cards.api.tiers.index', $policy))->assertUnauthorized();
        $this->postJson(route('credit-cards.api.tiers.store', $policy), [])->assertUnauthorized();
        $this->patchJson(route('credit-cards.api.tiers.update', $tier), [])->assertUnauthorized();
        $this->deleteJson(route('credit-cards.api.tiers.destroy', $tier))->assertUnauthorized();
        $this->postJson(route('credit-cards.api.tiers.clone', $tier), [])->assertUnauthorized();
        $this->getJson(route('credit-cards.api.rules.index', $tier))->assertUnauthorized();
        $this->postJson(route('credit-cards.api.rules.store', $tier), [])->assertUnauthorized();
        $this->patchJson(route('credit-cards.api.rules.update', $rule), [])->assertUnauthorized();
        $this->deleteJson(route('credit-cards.api.rules.destroy', $rule))->assertUnauthorized();
        $this->postJson(route('credit-cards.api.rules.clone', $rule), [])->assertUnauthorized();
    }

    // =====================================================================
    // Tạo policy version 1
    // =====================================================================

    #[Test]
    public function a_scratch_policy_creates_version_one_and_pins_the_card(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'scratch',
                'effective_from' => '2026-10-01',
                'name' => 'Chính sách 2026',
                'min_total_spend' => 0,
                'max_cashback_total_per_period' => 5000000,
                'tiers' => [
                    [
                        'name' => 'Phổ thông',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                    ],
                ],
            ])
            ->assertCreated();

        $policy = Policy::findOrFail($response->json('data.id'));

        $this->assertSame(1, (int) $policy->version_no);
        $this->assertSame(Policy::STATUS_ACTIVE, $policy->status);
        $this->assertSame((int) $card->id, (int) $policy->user_card_id);
        $this->assertSame((int) $card->id, (int) $card->fresh()->current_policy_id);
        $this->assertSame(1, $policy->tiers()->count());
    }

    #[Test]
    public function a_clone_system_policy_copies_the_whole_blueprint(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $template = $this->makeTemplateWithBlueprint($this->owner, PolicyTemplate::SCOPE_SYSTEM, [
            ['name' => 'Bậc hệ thống', 'min_total_spend' => 0, 'max_total_spend' => null],
        ], [
            ['category_id' => $category->id, 'cashback_percent' => 12.5, 'spend_from' => 0, 'spend_to' => null],
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'clone_system',
                'effective_from' => '2026-10-01',
                'template_id' => $template->id,
            ])
            ->assertCreated();

        $policy = Policy::findOrFail($response->json('data.id'));

        $this->assertSame((int) $card->id, (int) $policy->user_card_id);
        $this->assertSame(1, $policy->tiers()->count());
        $ruleCount = $policy->tiers()->with('tierCategoryRules')->get()
            ->pluck('tierCategoryRules')->flatten()->count();
        $this->assertSame(1, $ruleCount);
    }

    #[Test]
    public function a_user_template_can_only_be_cloned_by_its_owner(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $mine = $this->makeTemplateWithBlueprint($this->owner, PolicyTemplate::SCOPE_USER, [
            ['name' => 'Bậc riêng', 'min_total_spend' => 0, 'max_total_spend' => null],
        ], [
            ['category_id' => $category->id, 'cashback_percent' => 8.5, 'spend_from' => 0, 'spend_to' => null],
        ]);
        $theirs = $this->makeTemplateWithBlueprint($this->stranger, PolicyTemplate::SCOPE_USER);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'clone_user',
                'effective_from' => '2026-10-01',
                'template_id' => $mine->id,
            ])
            ->assertCreated();

        // Template của user khác phải bị chặn ngay từ service (422), không tạo policy.
        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'clone_user',
                'effective_from' => '2026-10-01',
                'template_id' => $theirs->id,
            ])
            ->assertStatus(422);

        $this->assertStringContainsString(
            'Bạn chỉ được dùng template của chính mình',
            $response->json('message')
        );
    }

    #[Test]
    public function a_closed_card_cannot_receive_a_policy(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['status' => UserCard::STATUS_INACTIVE]);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'scratch',
                'effective_from' => '2026-10-01',
                'name' => 'Thẻ đã đóng',
            ])
            ->assertForbidden();
    }

    // =====================================================================
    // Version mới (N+1)
    // =====================================================================

    #[Test]
    public function creating_a_version_supersedes_the_previous_one(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $old = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.versions.store', $card), [
                'effective_from' => '2026-10-01',
                'name' => 'Phiên bản nâng cấp',
                'min_total_spend' => 1000000,
            ])
            ->assertCreated();

        $new = Policy::findOrFail($response->json('data.id'));

        $this->assertSame(2, (int) $new->version_no);
        $this->assertSame(Policy::STATUS_ACTIVE, $new->status);
        $this->assertSame((int) $old->root_policy_id, (int) $new->root_policy_id);

        $old->refresh();
        $this->assertSame(Policy::STATUS_SUPERSEDED, $old->status);
        $this->assertSame((int) $new->id, (int) $card->fresh()->current_policy_id);
    }

    // =====================================================================
    // Đổi tên / khoá
    // =====================================================================

    #[Test]
    public function a_version_name_can_only_be_renamed_by_its_owner(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.policies.update', [$card, $policy]), [
                'name' => 'Tên mới',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Tên mới');

        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.policies.update', [$card, $policy]), [
                'name' => 'Đổi trộm',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_locked_version_cannot_be_renamed(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $policy->forceFill(['is_locked' => true])->save();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.policies.update', [$card, $policy]), [
                'name' => 'Không được',
            ])
            ->assertStatus(409);
    }

    // =====================================================================
    // Lưu thành mẫu riêng
    // =====================================================================

    #[Test]
    public function current_configuration_can_be_saved_as_a_user_template(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.templates.store', $card), [
                'name' => 'Mẫu của tôi',
            ])
            ->assertCreated();

        $template = PolicyTemplate::findOrFail($response->json('data.id'));

        $this->assertSame(PolicyTemplate::SCOPE_USER, $template->scope);
        $this->assertSame((int) $this->owner->id, (int) $template->owner_user_id);
        $this->assertNotNull($template->blueprint);
    }

    // =====================================================================
    // Bậc chi tiêu
    // =====================================================================

    #[Test]
    public function a_tier_can_be_created_but_not_over_lapped(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => 1000000]], []);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.tiers.store', $policy), [
                'name' => 'Bậc cao',
                'min_total_spend' => 2000000,
                'max_total_spend' => null,
            ])
            ->assertCreated();

        // Chồng lấn khoảng [500000, 1000000] với bậc cũ ⇒ 409.
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.tiers.store', $policy), [
                'name' => 'Chồng',
                'min_total_spend' => 500000,
                'max_total_spend' => 900000,
            ])
            ->assertStatus(409);
    }

    #[Test]
    public function a_tier_with_rules_cannot_be_deleted_but_one_without_can(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => 1000000]], [
            ['category_id' => $category->id, 'percent' => '5.000'],
        ]);
        $busyTier = $policy->tiers()->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.tiers.destroy', $busyTier))
            ->assertStatus(409);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.tiers.store', $policy), [
                'name' => 'Bậc trống',
                'min_total_spend' => 2000000,
                'max_total_spend' => null,
            ])
            ->assertCreated();

        $emptyTier = PolicyTier::query()
            ->where('policy_id', $policy->id)
            ->where('name', 'Bậc trống')
            ->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.tiers.destroy', $emptyTier))
            ->assertOk();

        $this->assertNull(PolicyTier::find($emptyTier->id));
    }

    #[Test]
    public function cloning_a_tier_deep_copies_its_rules(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $categoryA = $this->makeSystemCategory();
        $categoryB = $this->makeSystemCategory();
        $policy = $this->makePolicyForCard($card, [
            ['name' => 'Nguồn', 'min' => 0, 'max' => 1000000],
        ], [
            ['category_id' => $categoryA->id, 'percent' => '5.000'],
            ['category_id' => $categoryB->id, 'percent' => '3.000', 'cap_tx' => 50000],
        ]);
        $source = $policy->tiers()->firstOrFail();

        // Version đích: tạo version N+1 cùng thẻ.
        $newVersion = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.versions.store', $card), [
                'effective_from' => '2026-10-01',
            ])
            ->assertCreated()
            ->json('data.id');

        $clonedTierId = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.tiers.clone', $source), [
                'target_policy_id' => $newVersion,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', $source->name)
            ->assertJsonCount(3, 'data.rules')
            ->json('data.id');

        $this->assertSame(3, PolicyTierCategory::where('tier_id', $clonedTierId)->count());
    }

    // =====================================================================
    // Quy tắc cashback
    // =====================================================================

    #[Test]
    public function a_rule_can_be_created_but_ignores_cashback_amount_input(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.rules.store', $tier), [
                'category_id' => $category->id,
                'cashback_percent' => 7.5,
                // Có gửi kèm ô "số tiền hoàn" — phải bị BỎ QUA, không ghi vào DB.
                'cashback_amount' => 999999,
                'calculated_cashback' => 123456,
                'final_cashback' => 654321,
            ])
            ->assertCreated();

        $rule = PolicyTierCategory::findOrFail($response->json('data.id'));

        $this->assertSame('7.500', $rule->cashback_percent);
        $this->assertFalse(collect($rule->getAttributes())->has('cashback_amount'));
    }

    #[Test]
    public function a_strangers_category_cannot_be_used_in_a_rule(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();
        $theirs = $this->makeUserCategory($this->stranger->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.rules.store', $tier), [
                'category_id' => $theirs->id,
                'cashback_percent' => 5,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_percent_above_100_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.rules.store', $tier), [
                'category_id' => $category->id,
                'cashback_percent' => 150,
            ])
            ->assertStatus(422);
    }

    // =====================================================================
    // Quy tắc combo (Phase 2)
    // =====================================================================

    #[Test]
    public function a_combo_rule_can_be_created(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();
        $combo = $this->makeComboForUser($this->owner->id);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.rules.store', $tier), [
                'scope_type' => 'category',
                'combo_id' => $combo->id,
                'cashback_percent' => 4,
            ])
            ->assertCreated();

        $rule = PolicyTierCategory::findOrFail($response->json('data.id'));

        $this->assertSame($combo->id, (int) $rule->combo_id);
        $this->assertNull($rule->category_id, 'Rule combo KHÔNG được có category_id.');
        $this->assertTrue($rule->isComboSpecific());
        $this->assertSame('combo', $response->json('data.target_type'));
        $this->assertSame($combo->name, $response->json('data.combo_name'));
    }

    #[Test]
    public function a_strangers_combo_cannot_be_used_in_a_rule(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();
        $theirs = $this->makeComboForUser($this->stranger->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.rules.store', $tier), [
                'scope_type' => 'category',
                'combo_id' => $theirs->id,
                'cashback_percent' => 5,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_rule_cannot_target_both_a_category_and_a_combo(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();
        $combo = $this->makeComboForUser($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.rules.store', $tier), [
                'scope_type' => 'category',
                'category_id' => $category->id,
                'combo_id' => $combo->id,
                'cashback_percent' => 5,
            ])
            ->assertStatus(422);

        $this->assertSame(
            0,
            PolicyTierCategory::where('tier_id', $tier->id)->count(),
            'Payload mơ hồ không được tạo rule nào.'
        );
    }

    #[Test]
    public function the_rule_form_meta_exposes_combos_and_target_type(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();
        $combo = $this->makeComboForUser($this->owner->id);

        $response = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.rules.index', $tier))
            ->assertOk();

        $combos = collect($response->json('meta.combos'));

        $this->assertTrue(
            $combos->contains(fn (array $item) => (int) $item['id'] === $combo->id),
            'meta.combos phải chứa combo user được phép chọn.'
        );
    }

    // =====================================================================
    // IDOR / truy cập chéo user
    // =====================================================================

    #[Test]
    public function a_stranger_is_denied_every_policy_endpoint_of_an_owners_card(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();
        $rule = $this->makeRuleFor($tier);

        $this->actingAs($this->stranger)
            ->getJson(route('credit-cards.api.policies.index', $card))
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'scratch',
                'effective_from' => '2026-10-01',
                'name' => 'Policy của nạn nhân',
            ])
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->postJson(route('credit-cards.api.policies.versions.store', $card), ['effective_from' => '2026-10-01'])
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->getJson(route('credit-cards.api.tiers.index', $policy))
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->postJson(route('credit-cards.api.tiers.store', $policy), ['name' => 'x', 'min_total_spend' => 0])
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.tiers.update', $tier), ['name' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->deleteJson(route('credit-cards.api.tiers.destroy', $tier))
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->getJson(route('credit-cards.api.rules.index', $tier))
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->postJson(route('credit-cards.api.rules.store', $tier), ['category_id' => 1, 'cashback_percent' => 1])
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.rules.update', $rule), ['cashback_percent' => 10])
            ->assertForbidden();
        $this->actingAs($this->stranger)
            ->deleteJson(route('credit-cards.api.rules.destroy', $rule))
            ->assertForbidden();
    }

    #[Test]
    public function a_version_of_another_card_is_not_found_via_the_card_scoped_url(): void
    {
        $cardA = $this->makeUserCard($this->owner->id);
        $cardB = $this->makeUserCard($this->owner->id);

        $policyB = $this->makePolicyForCard($cardB, [['min' => 0, 'max' => null]], []);

        // Hỏi qua URL của THẺ A nhưng dùng id version của THẺ B ⇒ 404, không phải 403.
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.policies.show', [$cardA, $policyB]))
            ->assertNotFound();
    }

    #[Test]
    public function a_strangers_template_is_not_visible(): void
    {
        $theirs = $this->makeTemplateWithBlueprint($this->stranger, PolicyTemplate::SCOPE_USER);

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.templates.show', $theirs))
            ->assertForbidden();
    }

    #[Test]
    public function template_index_lists_only_system_and_own_templates(): void
    {
        $this->makeSystemTemplate();
        $mine = $this->makeTemplateWithBlueprint($this->owner, PolicyTemplate::SCOPE_USER);
        $theirs = $this->makeTemplateWithBlueprint($this->stranger, PolicyTemplate::SCOPE_USER);

        $ids = collect($this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.templates.index'))
            ->assertOk()
            ->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    // =====================================================================
    // Version bất biến (khoá / superseded)
    // =====================================================================

    #[Test]
    public function locked_and_superseded_versions_reject_tier_and_rule_changes(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], [
            ['category_id' => $category->id, 'percent' => '5.000'],
        ]);
        $tier = $policy->tiers()->firstOrFail();
        $rule = $tier->tierCategoryRules()->firstOrFail();

        foreach ([['is_locked' => true], ['status' => Policy::STATUS_SUPERSEDED]] as $changes) {
            $policy->forceFill($changes)->save();
            $policy->refresh();

            $this->actingAs($this->owner)
                ->postJson(route('credit-cards.api.tiers.store', $policy), ['name' => 'x', 'min_total_spend' => 0])
                ->assertForbidden();
            $this->actingAs($this->owner)
                ->patchJson(route('credit-cards.api.tiers.update', $tier), ['name' => 'x'])
                ->assertForbidden();
            $this->actingAs($this->owner)
                ->postJson(route('credit-cards.api.rules.store', $tier), ['category_id' => $category->id, 'cashback_percent' => 1])
                ->assertForbidden();
            $this->actingAs($this->owner)
                ->patchJson(route('credit-cards.api.rules.update', $rule), ['cashback_percent' => 1])
                ->assertForbidden();
        }
    }

    // =====================================================================
    // Rate limit: theo USER, JSON 429, không đụng route khác
    // =====================================================================

    #[Test]
    public function credit_card_api_is_rate_limited_per_user_with_json_429(): void
    {
        // Ghi đè limiter trước khi request để không phải gửi 60 yêu cầu thật.
        RateLimiter::for('credit-card-api', fn (): Limit => Limit::perMinute(2)
            ->by(request()->user()?->getAuthIdentifier() ?? request()->ip())
            ->response(function ($request, array $headers) {
                return response()->json(['message' => 'Vượt giới hạn tốc độ.'], 429, $headers);
            }));

        $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)->getJson(route('credit-cards.api.cards.index'))->assertOk();
        $this->actingAs($this->owner)->getJson(route('credit-cards.api.cards.index'))->assertOk();
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.cards.index'))
            ->assertStatus(429)
            ->assertJsonPath('message', 'Vượt giới hạn tốc độ.');

        // Người dùng khác vẫn còn quota (đếm theo USER, không theo IP).
        $this->makeUserCard($this->stranger->id);
        $this->actingAs($this->stranger)->getJson(route('credit-cards.api.cards.index'))->assertOk();
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * Tạo template có blueprint đầy đủ (bậc + rule) để có thứ clone.
     */
    private function makeTemplateWithBlueprint(
        User $owner,
        string $scope,
        array $tiers = [['name' => 'Bậc cơ bản', 'min_total_spend' => 0, 'max_total_spend' => null]],
        array $rules = [],
    ): PolicyTemplate {
        $template = PolicyTemplate::create([
            'scope' => $scope,
            'owner_user_id' => $scope === PolicyTemplate::SCOPE_SYSTEM ? PolicyTemplate::SYSTEM_OWNER_ID : $owner->id,
            'name' => 'Template '.uniqid(),
            'slug' => 'template-'.uniqid(),
            'is_builtin' => $scope === PolicyTemplate::SCOPE_SYSTEM,
            'is_active' => true,
        ]);

        $blueprint = Policy::create([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'root_policy_id' => null,
            'status' => Policy::STATUS_ACTIVE,
            'name' => 'Blueprint '.$template->name,
            'effective_from' => '2000-01-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);
        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        foreach (array_values($tiers) as $index => $tier) {
            $tierModel = PolicyTier::create([
                'policy_id' => $blueprint->id,
                'name' => $tier['name'],
                'sort_order' => $index,
                'min_total_spend' => $tier['min_total_spend'],
                'max_total_spend' => $tier['max_total_spend'],
            ]);

            foreach ($rules as $rule) {
                PolicyTierCategory::create([
                    'tier_id' => $tierModel->id,
                    'category_id' => $rule['category_id'],
                    'sort_order' => $rule['sort_order'] ?? 0,
                    'spend_from' => $rule['spend_from'] ?? 0,
                    'spend_to' => $rule['spend_to'] ?? null,
                    'cashback_percent' => $rule['cashback_percent'],
                    'is_enabled' => true,
                ]);
            }
        }

        return $template->refresh();
    }

    private function makeRuleFor(PolicyTier $tier): PolicyTierCategory
    {
        return PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $this->makeSystemCategory()->id,
            'sort_order' => 0,
            'spend_from' => 0,
            'spend_to' => null,
            'cashback_percent' => 5,
            'is_enabled' => true,
        ]);
    }

    private function makeComboForUser(int $userId): CategoryCombo
    {
        return app(CategoryComboService::class)->createUserCombo($userId, [
            'name' => 'Combo test '.uniqid(),
            'category_ids' => [$this->makeSystemCategory()->id],
        ]);
    }
}
