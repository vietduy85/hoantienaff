<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CardPolicyMccMapping;
use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileDocument;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccProfileRowCode;
use App\Models\CreditCard\PolicyMccProfileTemplateLink;
use App\Models\CreditCard\PolicyMccRowBenefit;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\User;
use App\Services\CreditCard\PolicyMccProfileService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    private function makeIncludedProfile(PolicyTemplate $template, string $categoryName, array $codes, array $attributes = []): PolicyMccProfile
    {
        $profile = PolicyMccProfile::create(array_merge([
            'policy_template_id' => $template->id,
            'name' => 'Hồ sơ '.$template->name,
        ], $attributes));

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
     * Tạo tài liệu hồ sơ kèm file vật lý trên disk. Test gọi `Storage::fake`
     * TRƯỚC khi tạo tài liệu — file nằm trên disk private, không có URL tĩnh.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeDocument(PolicyMccProfile $profile, string $name, string $content = 'pdf-bytes', array $attributes = []): PolicyMccProfileDocument
    {
        $disk = PolicyMccProfileService::DOCUMENT_DISK;

        $path = 'credit-card-mcc/'.Str::uuid().'.pdf';

        Storage::disk($disk)->put($path, $content);

        return PolicyMccProfileDocument::create(array_merge([
            'policy_mcc_profile_id' => $profile->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $name,
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($content),
            'uploaded_by' => null,
        ], $attributes));
    }

    /**
     * Tạo bản ghi tài liệu KHÔNG có file vật lý trên disk (link chết).
     */
    private function makeDocumentRecordWithoutFile(PolicyMccProfile $profile, string $name): PolicyMccProfileDocument
    {
        return PolicyMccProfileDocument::create([
            'policy_mcc_profile_id' => $profile->id,
            'disk' => PolicyMccProfileService::DOCUMENT_DISK,
            'path' => 'credit-card-mcc/khong-ton-tai.pdf',
            'original_name' => $name,
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 5,
            'uploaded_by' => null,
        ]);
    }

    /**
     * Hồ sơ MCC có MỘT nhóm included mang tên danh mục cụ thể — dùng để kiểm tra
     * việc khớp tên nhóm hồ sơ với rule danh mục trong blueprint.
     *
     * @param  array<int, string>  $codes
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

    /**
     * Gắn dữ liệu hoàn tiền trích tài liệu cho MỘT dòng MCC của hồ sơ.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    private function addRowBenefits(PolicyMccProfile $profile, array $tiers): void
    {
        $row = $profile->includedRows()->firstOrFail();

        foreach (array_values($tiers) as $order => $tier) {
            PolicyMccRowBenefit::create(array_merge([
                'policy_mcc_row_id' => $row->id,
                'tier_order' => $order,
            ], $tier));
        }
    }

    #[Test]
    public function lookup_mcc_shows_highest_documented_rate_and_condition_for_included_row(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('6300');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ bảo hiểm']);

        $profile = $this->makeIncludedProfile($template, 'Bảo hiểm', ['6300']);

        // Hai bậc trích tài liệu gốc: bậc thấp 7.5% và bậc cao 15% (trần 400.000).
        // Tra cứu phải chọn ĐÚNG bậc cao nhất + điều kiện của nó.
        $this->addRowBenefits($profile, [
            [
                'cashback_percent' => '7.500',
                'cap_amount' => '150000',
                'cap_period' => 'kỳ sao kê',
                'cap_unit' => 'VND',
                'condition_note' => 'Chi tiêu dưới 15.000.000đ/kỳ sao kê',
            ],
            [
                'cashback_percent' => '15.000',
                'cap_amount' => '400000',
                'cap_period' => 'kỳ sao kê',
                'cap_unit' => 'VND',
                'condition_note' => 'Chi tiêu từ 15.000.000đ/kỳ sao kê',
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '6300']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.cashback.status', 'available');
        $response->assertJsonPath('data.matches.0.cashback.category_name', 'Bảo hiểm');

        // Bậc cao nhất (15%) + điều kiện của chính bậc đó.
        $response->assertJsonPath('data.matches.0.cashback.highest.cashback_percent', '15.000');
        $response->assertJsonPath('data.matches.0.cashback.highest.cap_amount', '400000.00');
        $response->assertJsonPath('data.matches.0.cashback.highest.note', 'Chi tiêu từ 15.000.000đ/kỳ sao kê');
    }

    #[Test]
    public function lookup_mcc_shows_label_only_when_document_gives_no_numeric_rate(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5812');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ ẩm thực']);

        $profile = $this->makeIncludedProfile($template, 'Ăn uống', ['5812']);

        // Tài liệu chỉ nêu nhãn ("tới 20%"), không có tỷ lệ số ⇒ không bịa số.
        $this->addRowBenefits($profile, [
            ['rate_label' => 'tới 20%', 'cap_amount' => '300000', 'cap_period' => 'kỳ sao kê', 'cap_unit' => 'điểm'],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5812']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.cashback.status', 'available');
        $response->assertJsonPath('data.matches.0.cashback.highest', null);
        $response->assertJsonPath('data.matches.0.cashback.tiers.0.rate_label', 'tới 20%');
        $response->assertJsonPath('data.matches.0.cashback.tiers.0.name', 'tới 20%');
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

    #[Test]
    public function lookup_mcc_groups_shared_product_variants_into_one_representative_result(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5252');

        // Hai hạng thẻ của CÙNG một sản phẩm dùng chung tài liệu.
        $starter = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Starter']);
        $elite = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Elite']);

        $starterProfile = $this->makeIncludedProfile($starter, 'Mua sắm', ['5252'], ['product_name' => 'VIB Max Card Visa']);
        $eliteProfile = $this->makeIncludedProfile($elite, 'Mua sắm', ['5252'], ['product_name' => 'VIB Max Card Visa']);

        $this->addRowBenefits($starterProfile, [
            ['cashback_percent' => '0.500', 'condition_note' => 'Gói Starter'],
        ]);
        $this->addRowBenefits($eliteProfile, [
            ['cashback_percent' => '10.000', 'condition_note' => 'Gói Elite'],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5252']))
            ->assertOk();

        $matches = $response->json('data.matches');
        $this->assertCount(1, $matches);
        $this->assertSame('VIB Max Card Visa', $matches[0]['policy_name']);
        $this->assertSame('10.000', $matches[0]['cashback']['highest']['cashback_percent']);
        $this->assertSame('Gói Elite', $matches[0]['cashback']['highest']['note']);
        $this->assertCount(2, $matches[0]['cashback']['tiers']);
    }

    #[Test]
    public function lookup_mcc_shows_representative_product_name_and_preserves_every_tier_difference(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('4511');

        // Hồ sơ dùng chung tài liệu cho 4 hạng; tên template là hạng "vận chuyển".
        $template = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Starter']);
        $profile = $this->makeIncludedProfile($template, 'Du lịch', ['4511'], ['product_name' => 'VIB Max Card Visa']);

        $this->addRowBenefits($profile, [
            ['cashback_percent' => '0.500', 'condition_note' => 'Gói Starter'],
            ['cashback_percent' => '10.000', 'condition_note' => 'Gói Elite'],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '4511']))
            ->assertOk();

        // Kết quả mang TÊN SẢN PHẨM, không phải tên hạng thẻ.
        $response->assertJsonPath('data.matches.0.policy_name', 'VIB Max Card Visa');
        $response->assertJsonPath('data.matches.0.product_name', 'VIB Max Card Visa');
        // Tỷ lệ cao nhất (Elite 10%) + điều kiện của chính hạng đó.
        $response->assertJsonPath('data.matches.0.cashback.highest.cashback_percent', '10.000');
        $response->assertJsonPath('data.matches.0.cashback.highest.note', 'Gói Elite');
        // TOÀN BỘ bậc (mọi hạng) vẫn được giữ — không khẳng định một mức chung.
        $response->assertJsonCount(2, 'data.matches.0.cashback.tiers');
    }

    #[Test]
    public function lookup_mcc_keeps_template_name_when_policy_has_no_shared_product(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ riêng lẻ']);

        $this->makeIncludedProfile($template, 'Mua sắm', ['5411']);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5411']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.policy_name', 'Thẻ riêng lẻ');
        $response->assertJsonPath('data.matches.0.product_name', null);
    }

    #[Test]
    public function lookup_mcc_keeps_representative_product_name_for_excluded_rows(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('9311');
        $template = $this->makeSystemTemplate(['name' => 'VIB One Card Visa - Gold']);

        $profile = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'Hồ sơ '.$template->name,
            'product_name' => 'VIB One Card Visa',
        ]);

        $row = PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_EXCLUDED,
            'category_name' => 'Loại trừ',
        ]);

        PolicyMccProfileRowCode::create([
            'policy_mcc_row_id' => $row->id,
            'mcc' => '9311',
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '9311']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.policy_name', 'VIB One Card Visa');
        $response->assertJsonPath('data.matches.0.relation', 'excluded');
        $response->assertJsonPath('data.matches.0.cashback.status', 'excluded');
    }

    // =====================================================================
    // Tài liệu hồ sơ MCC trong "Tra cứu thẻ"
    // =====================================================================

    #[Test]
    public function policy_lookup_lists_documents_under_the_correct_profile(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ nhiều hồ sơ']);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $profileA = $this->makeProfile($template, [], [], ['name' => 'Hồ sơ A']);
        $profileB = $this->makeProfile($template, [], [], ['name' => 'Hồ sơ B']);

        $docA = $this->makeDocument($profileA, 'dieu-kien-A.pdf', 'noi dung A');
        $docB = $this->makeDocument($profileB, 'dieu-kien-B.pdf', 'noi dung B');

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        )->assertOk();

        // Mỗi hồ sơ chỉ liệt kê tài liệu CỦA CHÍNH NÓ — không trộn lẫn.
        $response->assertJsonPath('data.mcc_profiles.0.name', 'Hồ sơ A');
        $response->assertJsonCount(1, 'data.mcc_profiles.0.documents');
        $response->assertJsonPath('data.mcc_profiles.0.documents.0.id', $docA->id);
        $response->assertJsonPath('data.mcc_profiles.0.documents.0.name', 'dieu-kien-A.pdf');
        $response->assertJsonPath('data.mcc_profiles.0.documents.0.size', strlen('noi dung A'));
        $response->assertJsonPath('data.mcc_profiles.0.documents.0.human_size', '10 B');

        $response->assertJsonPath('data.mcc_profiles.1.name', 'Hồ sơ B');
        $response->assertJsonCount(1, 'data.mcc_profiles.1.documents');
        $response->assertJsonPath('data.mcc_profiles.1.documents.0.id', $docB->id);
        $response->assertJsonPath('data.mcc_profiles.1.documents.0.name', 'dieu-kien-B.pdf');

        // URL tải là endpoint kiểm tra quyền, KHÔNG lộ đường dẫn file private.
        $urlA = (string) $response->json('data.mcc_profiles.0.documents.0.url');
        $this->assertStringContainsString('/tra-cuu/tai-lieu/'.$profileA->id.'/', $urlA);
        $this->assertStringNotContainsString('credit-card-mcc', $urlA);
        $this->assertStringNotContainsString('storage/app', $urlA);
    }

    #[Test]
    public function policy_lookup_hides_documents_whose_file_is_missing(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ tài liệu mất']);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $profile = $this->makeProfile($template);
        $this->makeDocumentRecordWithoutFile($profile, 'missing.pdf');

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        )->assertOk();

        // Không tạo link chết: tài liệu không còn file bị bỏ ra khỏi payload.
        $response->assertJsonPath('data.mcc_profiles.0.documents', []);
    }

    #[Test]
    public function policy_lookup_reports_empty_documents_when_profile_has_none(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ không tài liệu']);

        $this->makeProfile($template, ['5411']);

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        )->assertOk();

        $response->assertJsonPath('data.mcc_profiles.0.included.0.codes', ['5411']);
        $response->assertJsonPath('data.mcc_profiles.0.documents', []);
    }

    // =====================================================================
    // Phí thường niên theo hồ sơ MCC trong "Tra cứu thẻ"
    // =====================================================================

    #[Test]
    public function policy_lookup_reports_annual_fees_per_profile(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ phí thường niên']);

        // Hai hồ sơ khác nhau với mức phí khác nhau — không trộn số liệu.
        $this->makeProfile($template, [], [], [
            'name' => 'Hồ sơ phí A',
            'annual_fee_primary' => '1200000',
            'annual_fee_supplementary' => '600000',
            'annual_spend_for_annual_fee_waiver' => '120000000',
        ]);
        $this->makeProfile($template, [], [], [
            'name' => 'Hồ sơ phí B',
            'annual_fee_primary' => '2500000',
        ]);

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        )->assertOk();

        $response->assertJsonPath('data.mcc_profiles.0.annual_fee_primary', '1200000.00');
        $response->assertJsonPath('data.mcc_profiles.0.annual_fee_supplementary', '600000.00');
        $response->assertJsonPath('data.mcc_profiles.0.annual_spend_for_annual_fee_waiver', '120000000.00');

        $response->assertJsonPath('data.mcc_profiles.1.annual_fee_primary', '2500000.00');
        $response->assertJsonPath('data.mcc_profiles.1.annual_fee_supplementary', null);
        $response->assertJsonPath('data.mcc_profiles.1.annual_spend_for_annual_fee_waiver', null);
    }

    #[Test]
    public function policy_lookup_reports_null_annual_fees_when_profile_has_none(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ không phí']);

        $this->makeProfile($template, ['5411']);

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        )->assertOk();

        $response->assertJsonPath('data.mcc_profiles.0.annual_fee_primary', null);
        $response->assertJsonPath('data.mcc_profiles.0.annual_fee_supplementary', null);
        $response->assertJsonPath('data.mcc_profiles.0.annual_spend_for_annual_fee_waiver', null);
    }

    #[Test]
    public function lookup_page_always_renders_annual_fee_block_with_placeholder(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'Thẻ phí thường niên']);

        $html = $this->actingAs($user)->get(route('credit-cards.lookup'))->assertOk()->getContent();

        // Khối "Phí thường niên" LUÔN có trong template hồ sơ (không bị ẩn theo dữ liệu),
        // với đủ ba dòng: thẻ chính / thẻ phụ / chi tiêu trong năm.
        $this->assertStringContainsString('Phí thường niên', $html);
        $this->assertStringContainsString('Thẻ chính:', $html);
        $this->assertStringContainsString('Thẻ phụ:', $html);
        $this->assertStringContainsString('Chi tiêu trong năm để được hoàn/miễn phí:', $html);

        // Mỗi dòng thiếu dữ liệu đều có nhãn "Chưa cập nhật" (3 dòng) — không hiển thị "0 đ".
        $this->assertSame(3, substr_count($html, 'Chưa cập nhật'));

        // Hồi quy: bản cũ gắn x-show="ccProfileHasFees(profile)" + x-cloak lên chính khối phí
        // khiến nó bị ẩn khi cả ba trường NULL. Đảm bảo không tái xuất hiện.
        $this->assertStringNotContainsString('x-show="ccProfileHasFees(profile)" x-cloak', $html);
    }

    #[Test]
    public function lookup_policy_section_uses_readable_font_sizes(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'Thẻ cỡ chữ']);

        $html = $this->actingAs($user)->get(route('credit-cards.lookup'))->assertOk()->getContent();

        // Tiêu đề section 18px, tên hồ sơ 16px, nhóm & nội dung 14px (class lõi Tailwind
        // đã có trong CSS build; không dùng giá trị arbitrary chưa build như text-[17px]).
        $this->assertStringContainsString('text-lg leading-snug', $html);
        $this->assertStringContainsString('text-base leading-snug', $html);
        $this->assertStringContainsString('text-sm font-semibold text-gray-600 mb-1">Phí thường niên', $html);
        $this->assertStringContainsString('text-sm leading-relaxed text-gray-600', $html);
    }

    // =====================================================================
    // Tải tài liệu qua endpoint "Tra cứu thẻ" (disk private, kiểm tra quyền)
    // =====================================================================

    #[Test]
    public function document_download_streams_file_for_a_selectable_template(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ tải tài liệu']);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $profile = $this->makeProfile($template);
        $document = $this->makeDocument($profile, 'bang-dieu-kien.pdf', 'noi dung pdf');

        $response = $this->actingAs($user)->get(
            route('credit-cards.lookup.document', [$profile->id, $document->id])
        );

        $response->assertOk();
        $this->assertSame('noi dung pdf', $response->streamedContent());
        $this->assertStringContainsString(
            'attachment; filename=bang-dieu-kien.pdf',
            (string) $response->headers->get('content-disposition')
        );
    }

    #[Test]
    public function document_download_is_denied_for_a_template_the_user_cannot_see(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $private = PolicyTemplate::create([
            'scope' => PolicyTemplate::SCOPE_USER,
            'owner_user_id' => $owner->id,
            'name' => 'Chính sách riêng',
            'slug' => 'chinh-sach-rieng-mcc',
            'is_active' => true,
        ]);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $profile = $this->makeProfile($private);
        $document = $this->makeDocument($profile, 'doc-bi-mat.pdf', 'bi mat');

        // 404 — không tiết lộ sự tồn tại của tài liệu.
        $this->actingAs($stranger)
            ->get(route('credit-cards.lookup.document', [$profile->id, $document->id]))
            ->assertNotFound();
    }

    #[Test]
    public function document_download_returns_404_when_document_belongs_to_another_profile(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ tráo tài liệu']);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $profileA = $this->makeProfile($template, [], [], ['name' => 'Hồ sơ A']);
        $profileB = $this->makeProfile($template, [], [], ['name' => 'Hồ sơ B']);

        $documentB = $this->makeDocument($profileB, 'doc-B.pdf', 'noi dung B');

        // Ghép sai {profile}/{document}: cùng endpoint → 404.
        $this->actingAs($user)
            ->get(route('credit-cards.lookup.document', [$profileA->id, $documentB->id]))
            ->assertNotFound();
    }

    #[Test]
    public function document_download_returns_404_when_file_is_missing_on_disk(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ file mất']);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $profile = $this->makeProfile($template);
        $document = $this->makeDocumentRecordWithoutFile($profile, 'khong-ton-tai.pdf');

        $this->actingAs($user)
            ->get(route('credit-cards.lookup.document', [$profile->id, $document->id]))
            ->assertNotFound();
    }

    // =====================================================================
    // Hồ sơ MÔ TẢ không mã (VPBank Super Shopee) — dòng chỉ có tên/mô tả.
    // =====================================================================

    #[Test]
    public function policy_lookup_reports_descriptive_row_without_codes(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'VPBank Super Shopee']);

        // Dòng included mô tả quyền lợi nhưng KHÔNG gắn mã MCC nào.
        $this->makeIncludedProfile($template, 'Giao dịch tại Shopee, ShopeeFood', []);

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        )->assertOk();

        $response->assertJsonPath('data.mcc_profiles.0.included.0.category_name', 'Giao dịch tại Shopee, ShopeeFood');
        $response->assertJsonPath('data.mcc_profiles.0.included.0.codes', []);
    }

    #[Test]
    public function lookup_page_shows_placeholder_for_rows_without_mcc_codes(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'VPBank Super Shopee']);

        $html = $this->actingAs($user)->get(route('credit-cards.lookup'))->assertOk()->getContent();

        // Dòng không có mã MCC phải hiển thị nhãn "Chưa có thông tin mã MCC",
        // KHÔNG để trống gây hiểu nhầm là đã kiểm tra đầy đủ.
        $this->assertStringContainsString('Chưa có thông tin mã MCC', $html);
    }

    #[Test]
    public function lookup_page_removed_the_duplicate_mcc_related_block(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'Hoàn tiền siêu thị']);

        $response = $this->actingAs($user)->get(route('credit-cards.lookup'));

        $response->assertOk();
        $response->assertDontSee('MCC liên quan');
        $response->assertDontSee('Chưa nhập danh sách MCC cho chính sách này.');
        $response->assertSee('Tài liệu tham khảo');
    }

    // =====================================================================
    // Hồ sơ MCC DÙNG CHUNG nhiều template (VIB Max Card / VIB One Card)
    // =====================================================================

    #[Test]
    public function policy_lookup_returns_a_shared_profile_for_every_variant_template(): void
    {
        $user = User::factory()->create();

        // 4 HẠNG thẻ là 4 template RIÊNG nhưng dùng CHUNG một chương trình/hồ sơ.
        $starter = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Starter', 'slug' => 'shared-vib-max-starter']);
        $plus = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Plus', 'slug' => 'shared-vib-max-plus']);
        $pro = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Pro', 'slug' => 'shared-vib-max-pro']);
        $elite = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Elite', 'slug' => 'shared-vib-max-elite']);

        // Hồ sơ CHỈ thuộc template Starter (nguồn chính); các hạng khác liên kết.
        $profile = $this->makeIncludedProfile($starter, 'Mua sắm', ['5252'], ['product_name' => 'VIB Max Card Visa']);

        foreach ([$starter, $plus, $pro, $elite] as $template) {
            PolicyMccProfileTemplateLink::create([
                'policy_mcc_profile_id' => $profile->id,
                'policy_template_id' => $template->id,
            ]);
        }

        // MỌI hạng đều thấy ĐÚNG một hồ sơ — không nhân bản.
        foreach ([$starter, $plus, $pro, $elite] as $template) {
            $response = $this->actingAs($user)->getJson(
                route('credit-cards.lookup.policy', ['template_id' => $template->id])
            )->assertOk();

            $response->assertJsonCount(1, 'data.mcc_profiles');
            $response->assertJsonPath('data.mcc_profiles.0.id', $profile->id);
            $response->assertJsonPath('data.mcc_profiles.0.included.0.codes', ['5252']);
        }
    }

    #[Test]
    public function mcc_lookup_returns_one_result_for_a_shared_profile_across_linked_templates(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5252');

        $starter = $this->makeSystemTemplate(['name' => 'VIB One Card Visa - Gold', 'slug' => 'shared-one-gold']);
        $linked = $this->makeSystemTemplate(['name' => 'VIB One Card Visa - Platinum', 'slug' => 'shared-one-platinum']);

        $profile = $this->makeIncludedProfile($starter, 'Mua sắm', ['5252'], ['product_name' => 'VIB One Card Visa']);

        PolicyMccProfileTemplateLink::create([
            'policy_mcc_profile_id' => $profile->id,
            'policy_template_id' => $linked->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5252']))
            ->assertOk();

        // Một chương trình dùng chung ⇒ MỘT kết quả đại diện, KHÔNG trùng.
        $matches = $response->json('data.matches');
        $this->assertCount(1, $matches);
        $this->assertSame('VIB One Card Visa', $matches[0]['policy_name']);
        $this->assertSame('policy_mcc_profile', $matches[0]['source']);
    }

    #[Test]
    public function shared_profile_exclusion_applies_to_linked_templates(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('7995');

        $primary = $this->makeSystemTemplate(['name' => 'VIB One Card Visa - Gold', 'slug' => 'shared-excl-primary']);
        $linked = $this->makeSystemTemplate(['name' => 'VIB One Card Visa - Signature', 'slug' => 'shared-excl-linked']);

        // Loại trừ mã nằm ở hồ sơ thuộc template chính.
        $profile = $this->makeProfile($primary, [], ['7995'], ['product_name' => 'VIB One Card Visa']);

        PolicyMccProfileTemplateLink::create([
            'policy_mcc_profile_id' => $profile->id,
            'policy_template_id' => $linked->id,
        ]);

        // Mapping import cho CÙNG mã nằm ở template LIÊN KẾT — phải bị loại trừ theo
        // phạm vi chương trình dùng chung, không lòi ra như đang áp dụng.
        CardPolicyMccMapping::create([
            'policy_template_id' => $linked->id,
            'match_type' => CardPolicyMccMapping::MATCH_DIRECT,
            'mcc' => '7995',
            'application_status' => 'applied',
            'verification_status' => MccCode::STATUS_VERIFIED,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '7995']))
            ->assertOk();

        $matches = $response->json('data.matches');
        $this->assertCount(1, $matches);
        $this->assertSame('excluded', $matches[0]['relation']);
        $this->assertSame('policy_mcc_profile', $matches[0]['source']);
    }

    // =====================================================================
    // Tab "Tra cứu MCC" — giao diện "Các thẻ hoàn tiền" + liên kết tài liệu
    // =====================================================================

    #[Test]
    public function mcc_lookup_result_section_is_renamed_and_hides_internal_profile_labels(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'Thẻ giao diện']);

        $html = $this->actingAs($user)->get(route('credit-cards.lookup'))->assertOk()->getContent();

        // (1) Tiêu đề mới "Các thẻ hoàn tiền" + số lượng kết quả thực tế.
        $this->assertStringContainsString('Các thẻ hoàn tiền', $html);
        $this->assertStringContainsString('mccResult.matches.length', $html);
        $this->assertStringNotContainsString('Chính sách liên quan', $html);

        // (2) Bỏ nhãn quan hệ "Thuộc nhóm MCC" (chỉ ẩn khi relation = group).
        $this->assertStringContainsString("row.relation !== 'group'", $html);
        $this->assertStringNotContainsString('Thuộc nhóm MCC', $html);

        // (3) Bỏ thông tin hồ sơ nội bộ khỏi giao diện.
        $this->assertStringNotContainsString('Hồ sơ MCC:', $html);
        $this->assertStringNotContainsString('Nhóm trong hồ sơ:', $html);

        // (4) Thay dòng "Nguồn: <source_pdf>" bằng liên kết gạch chân "File liên quan".
        $this->assertStringNotContainsString("'Nguồn: ' + row.source_pdf", $html);
        $this->assertStringContainsString('File liên quan', $html);
        $this->assertStringContainsString('underline text-blue-600', $html);
        $this->assertStringContainsString(':href="doc.url"', $html);

        // Không có tài liệu ⇒ báo "Chưa có thông tin", không tạo liên kết giả.
        $this->assertStringContainsString('Chưa có thông tin', $html);
    }

    #[Test]
    public function mcc_lookup_match_exposes_downloadable_document_for_its_profile(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ có tài liệu']);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $profile = $this->makeIncludedProfile($template, 'Mua sắm', ['5411']);
        $document = $this->makeDocument($profile, 'dieu-kien.pdf', 'noi dung');

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5411']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.source', 'policy_mcc_profile');
        $response->assertJsonPath('data.matches.0.documents.0.id', $document->id);
        $response->assertJsonPath('data.matches.0.documents.0.name', 'dieu-kien.pdf');

        // URL trỏ tới endpoint kiểm tra quyền, KHÔNG lộ đường dẫn disk private.
        $url = (string) $response->json('data.matches.0.documents.0.url');
        $this->assertStringContainsString('/tra-cuu/tai-lieu/'.$profile->id.'/', $url);
        $this->assertStringNotContainsString('credit-card-mcc', $url);
        $this->assertStringNotContainsString('storage/app', $url);

        // Nhấp liên kết tải ĐÚNG tài liệu của hồ sơ.
        $download = $this->actingAs($user)->get($url);
        $download->assertOk();
        $this->assertSame('noi dung', $download->streamedContent());
        $this->assertStringContainsString(
            'attachment; filename=dieu-kien.pdf',
            (string) $download->headers->get('content-disposition')
        );
    }

    #[Test]
    public function mcc_lookup_reports_no_document_when_profile_has_none(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ không tài liệu']);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $this->makeIncludedProfile($template, 'Mua sắm', ['5411']);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5411']))
            ->assertOk();

        // Không có tài liệu ⇒ danh sách rỗng, giao diện hiển thị "Chưa có thông tin".
        $response->assertJsonPath('data.matches.0.source', 'policy_mcc_profile');
        $response->assertJsonPath('data.matches.0.documents', []);
    }

    #[Test]
    public function mcc_lookup_hides_document_whose_file_is_missing(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');
        $template = $this->makeSystemTemplate(['name' => 'Thẻ file mất']);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $profile = $this->makeIncludedProfile($template, 'Mua sắm', ['5411']);
        $this->makeDocumentRecordWithoutFile($profile, 'missing.pdf');

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5411']))
            ->assertOk();

        // Không tạo link chết: tài liệu không còn file bị bỏ ra khỏi payload.
        $response->assertJsonPath('data.matches.0.documents', []);
    }

    #[Test]
    public function mcc_lookup_returns_each_policys_own_document_without_duplication(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $templateA = $this->makeSystemTemplate(['name' => 'Thẻ A']);
        $templateB = $this->makeSystemTemplate(['name' => 'Thẻ B']);

        $profileA = $this->makeIncludedProfile($templateA, 'Mua sắm', ['5411']);
        $profileB = $this->makeIncludedProfile($templateB, 'Mua sắm', ['5411']);
        $this->makeDocument($profileA, 'A.pdf', 'noi dung A');
        $this->makeDocument($profileB, 'B.pdf', 'noi dung B');

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5411']))
            ->assertOk();

        $matches = $response->json('data.matches');
        $this->assertCount(2, $matches);

        $documentNameByPolicy = [];

        foreach ($matches as $match) {
            // Mỗi thẻ CHỈ mang tài liệu của hồ sơ tương ứng — không trộn/lặp.
            $this->assertCount(1, $match['documents']);
            $documentNameByPolicy[$match['policy_name']] = $match['documents'][0]['name'];
        }

        $this->assertSame('A.pdf', $documentNameByPolicy['Thẻ A']);
        $this->assertSame('B.pdf', $documentNameByPolicy['Thẻ B']);
    }

    #[Test]
    public function mcc_lookup_shows_shared_product_document_only_once(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5252');

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $starter = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Starter']);
        $elite = $this->makeSystemTemplate(['name' => 'VIB Max Card Visa - Elite']);

        $starterProfile = $this->makeIncludedProfile($starter, 'Mua sắm', ['5252'], ['product_name' => 'VIB Max Card Visa']);
        $this->makeIncludedProfile($elite, 'Mua sắm', ['5252'], ['product_name' => 'VIB Max Card Visa']);
        $this->makeDocument($starterProfile, 'shared.pdf', 'noi dung');

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '5252']))
            ->assertOk();

        // Sản phẩm dùng chung ⇒ MỘT kết quả, tài liệu hiển thị MỘT lần (không nhân bản).
        $matches = $response->json('data.matches');
        $this->assertCount(1, $matches);
        $this->assertCount(1, $matches[0]['documents']);
    }
}
