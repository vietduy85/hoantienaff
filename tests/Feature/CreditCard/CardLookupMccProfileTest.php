<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CardPolicyMccMapping;
use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccProfileRowCode;
use App\Models\CreditCard\PolicyTemplate;
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
