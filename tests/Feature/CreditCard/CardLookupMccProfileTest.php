<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CardPolicyMccMapping;
use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccProfileRowCode;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Tích hợp module "Quản lý MCC" (HỒ SƠ MCC chính sách) vào "Tra cứu".
 *
 * Đảm bảo: hồ sơ MCC admin nhập xuất hiện đúng trong tra cứu, và MCC loại trừ
 * theo hồ sơ THẮNG áp dụng (kể cả mapping import) của cùng chính sách.
 */
class CardLookupMccProfileTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();
    }

    private function seedMcc(string $code): void
    {
        MccCode::create([
            'mcc' => $code,
            'description_vi' => 'Mô tả '.$code,
            'verification_status' => MccCode::STATUS_UNVERIFIED,
        ]);
    }

    /**
     * @param  array<int, string>  $included
     * @param  array<int, string>  $excluded
     */
    private function makeProfile(PolicyTemplate $template, array $included = [], array $excluded = [], array $attributes = []): PolicyMccProfile
    {
        $profile = PolicyMccProfile::create(array_merge([
            'policy_template_id' => $template->id,
            'name' => 'MCC '.$template->name,
        ], $attributes));

        foreach ([PolicyMccProfileRow::TYPE_INCLUDED => $included, PolicyMccProfileRow::TYPE_EXCLUDED => $excluded] as $type => $codes) {
            if ($codes === []) {
                continue;
            }

            $row = PolicyMccProfileRow::create([
                'policy_mcc_profile_id' => $profile->id,
                'row_type' => $type,
                'category_name' => $type === PolicyMccProfileRow::TYPE_INCLUDED ? 'Danh mục' : 'Loại trừ',
            ]);

            foreach ($codes as $code) {
                PolicyMccProfileRowCode::create([
                    'policy_mcc_row_id' => $row->id,
                    'mcc' => $code,
                ]);
            }
        }

        return $profile;
    }

    #[Test]
    public function policy_lookup_includes_admin_profiles_with_included_and_excluded_rows(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Hoàn tiền A']);
        $this->seedMcc('5411');
        $this->seedMcc('7995');

        $this->makeProfile($template, ['5411'], ['7995'], ['name' => 'MCC Hoàn tiền A']);

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        )->assertOk();

        $response->assertJsonPath('data.mcc_profiles.0.name', 'MCC Hoàn tiền A');
        $response->assertJsonPath('data.mcc_profiles.0.included.0.codes', ['5411']);
        $response->assertJsonPath('data.mcc_profiles.0.excluded.0.codes', ['7995']);
    }

    /**
     * Hồ sơ MCC có MỘT nhóm included mang tên danh mục cụ thể — dùng để kiểm tra
     * việc khớp tên nhóm hồ sơ với rule danh mục trong blueprint.
     *
     * @param  array<int, string>  $codes
     */
    private function makeIncludedProfile(PolicyTemplate $template, string $categoryName, array $codes): PolicyMccProfile
    {
        $profile = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'Hồ sơ '.$template->name,
        ]);

        $row = PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_INCLUDED,
            'category_name' => $categoryName,
        ]);

        foreach ($codes as $code) {
            PolicyMccProfileRowCode::create([
                'policy_mcc_row_id' => $row->id,
                'mcc' => $code,
            ]);
        }

        return $profile;
    }

    /**
     * Blueprint nhiều bậc + rule danh mục / fallback để kiểm tra phần hiển thị
     * tỷ lệ hoàn tiền. Mỗi rule `category_id = null` là rule fallback.
     *
     * @param  array<int, array{name:string, min:float, max:float|null, rules:array<int, array{category_id?:int|null, percent:string, cap?:string|null}>}>  $tiers
     */
    private function makeBlueprintWithTiers(PolicyTemplate $template, array $tiers): Policy
    {
        $blueprint = Policy::create([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $template->name,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'max_cashback_total_per_period' => 800000,
            'rounding_mode' => 'round',
        ]);

        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        foreach ($tiers as $tierIndex => $tierData) {
            $tier = PolicyTier::create([
                'policy_id' => $blueprint->id,
                'name' => $tierData['name'],
                'sort_order' => $tierIndex,
                'min_total_spend' => $tierData['min'],
                'max_total_spend' => $tierData['max'],
            ]);

            foreach ($tierData['rules'] as $ruleIndex => $rule) {
                $categoryId = $rule['category_id'] ?? null;

                PolicyTierCategory::create([
                    'tier_id' => $tier->id,
                    'category_id' => $categoryId,
                    'scope_type' => $categoryId === null
                        ? PolicyTierCategory::SCOPE_OTHER
                        : PolicyTierCategory::SCOPE_CATEGORY,
                    'name' => $rule['name'] ?? null,
                    'sort_order' => $ruleIndex,
                    'spend_from' => 0,
                    'cashback_percent' => $rule['percent'],
                    'max_cashback_per_category_per_period' => $rule['cap'] ?? null,
                    'is_enabled' => true,
                    'counts_toward_tier_cap' => true,
                ]);
            }
        }

        return $blueprint;
    }

    #[Test]
    public function lookup_mcc_shows_real_blueprint_rate_for_included_category(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('6300');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ bảo hiểm']);
        $category = $this->makeSystemCategory(['name' => 'Bảo hiểm']);

        // Bậc 1 (dưới 8tr): chỉ có fallback 0%. Bậc 2 (từ 8tr): Bảo hiểm 15%,
        // trần 400.000/kỳ — đúng cấu hình thật của template LPBank JCB Ultimate.
        $this->makeBlueprintWithTiers($template, [
            ['name' => 'Bậc 1 - Dưới 8tr', 'min' => 0, 'max' => 8000000, 'rules' => [
                ['percent' => '0.000'],
            ]],
            ['name' => 'Bậc 2 - Từ 8tr', 'min' => 8000000, 'max' => null, 'rules' => [
                ['category_id' => $category->id, 'percent' => '15.000', 'cap' => '400000'],
                ['percent' => '0.000'],
            ]],
        ]);

        // Hồ sơ MCC nhập tay: nhóm "Bảo hiểm" khớp theo TÊN với danh mục blueprint.
        $this->makeIncludedProfile($template, 'Bảo hiểm', ['6300']);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '6300']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.cashback.status', 'available');
        $response->assertJsonPath('data.matches.0.cashback.category_name', 'Bảo hiểm');

        // Bậc 1 không áp dụng cho danh mục này…
        $response->assertJsonPath('data.matches.0.cashback.tiers.0.has_rule', false);

        // …bậc 2 áp dụng đúng 15% + trần 400.000.
        $response->assertJsonPath('data.matches.0.cashback.tiers.1.has_rule', true);
        $response->assertJsonPath('data.matches.0.cashback.tiers.1.cashback_percent', '15.000');
        $response->assertJsonPath('data.matches.0.cashback.tiers.1.max_cashback_per_category_per_period', '400000.00');
    }

    #[Test]
    public function lookup_mcc_marks_excluded_profile_row_as_not_refundable(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('9311');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ loại trừ']);

        $this->makeProfile($template, [], ['9311']);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '9311']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.relation', 'excluded');
        $response->assertJsonPath('data.matches.0.cashback.status', 'excluded');
        $response->assertJsonPath('data.matches.0.cashback.tiers', []);
    }

    #[Test]
    public function lookup_mcc_does_not_fabricate_a_rate_when_category_not_in_blueprint(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ siêu thị']);
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);

        $this->makeBlueprintWithTiers($template, [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => null, 'rules' => [
                ['category_id' => $category->id, 'percent' => '3.000'],
            ]],
        ]);

        // Nhóm trong hồ sơ ("Mua sắm khác") KHÔNG khớp danh mục nào của blueprint.
        $this->makeIncludedProfile($template, 'Mua sắm khác', ['5411']);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5411']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.cashback.status', 'missing');
        $response->assertJsonPath('data.matches.0.cashback.tiers', []);
    }

    #[Test]
    public function lookup_mcc_reports_profile_application(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ nhóm']);

        $this->makeProfile($template, ['5411']);

        $response = $this->actingAs($user)->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5411']))->assertOk();

        $response->assertJsonPath('data.matches.0.relation', 'group');
        $response->assertJsonPath('data.matches.0.source', 'policy_mcc_profile');
        $response->assertJsonPath('data.matches.0.profile_name', 'MCC Thẻ nhóm');
    }

    #[Test]
    public function lookup_mcc_reports_profile_exclusion_as_policy_scope(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('7995');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ loại trừ']);

        $this->makeProfile($template, [], ['7995']);

        $response = $this->actingAs($user)->getJson(route('credit-cards.lookup.mcc', ['mcc' => '7995']))->assertOk();

        $response->assertJsonPath('data.matches.0.relation', 'excluded');
        $response->assertJsonPath('data.matches.0.exclusion_scope', 'policy');
    }

    #[Test]
    public function profile_exclusion_overrides_imported_mapping_for_same_policy(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('7995');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ có ngoại lệ']);

        CardPolicyMccMapping::create([
            'policy_template_id' => $template->id,
            'match_type' => CardPolicyMccMapping::MATCH_DIRECT,
            'mcc' => '7995',
            'application_status' => 'applied',
            'verification_status' => MccCode::STATUS_VERIFIED,
        ]);
        $this->makeProfile($template, [], ['7995']);

        $response = $this->actingAs($user)->getJson(route('credit-cards.lookup.mcc', ['mcc' => '7995']))->assertOk();

        $matches = $response->json('data.matches');
        $this->assertCount(1, $matches);
        $this->assertSame('excluded', $matches[0]['relation']);
        $this->assertSame('policy_mcc_profile', $matches[0]['source']);
    }
}
