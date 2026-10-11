<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CardPolicyMccMapping;
use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\User;
use App\Services\CreditCard\CardPolicyLookupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Module "Tra cứu" (Thẻ tín dụng): /thetindung/tra-cuu.
 *
 * Kiểm tra:
 *   - trang render 2 tab + danh sách chính sách scope theo user;
 *   - API tra cứu thẻ (tóm tắt + mapping) và tra cứu MCC (mô tả + phân loại);
 *   - phân biệt áp dụng trực tiếp / nhóm / loại trừ / chưa xác minh;
 *   - giữ số 0 đứng đầu của mã MCC + validate 4 chữ số;
 *   - import idempotent (chạy lại không tạo trùng).
 */
class CardLookupTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();
    }

    // =====================================================================
    // Trang giao diện
    // =====================================================================

    #[Test]
    public function lookup_page_renders_both_tabs_and_policy_picker(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'Hoàn tiền siêu thị']);

        $response = $this->actingAs($user)->get(route('credit-cards.lookup'));

        $response->assertOk();
        $response->assertSee('Tra cứu thẻ');
        $response->assertSee('Tra cứu MCC');

        // Tab "Tra cứu thẻ": tiêu đề "Chọn thẻ", nhãn "Thẻ", nút "Xem thẻ".
        $response->assertSee('Chọn thẻ');
        $response->assertSee('>Thẻ</label>', false);
        $response->assertSee('Xem thẻ');

        // Không còn dòng mô tả dài dòng / nhãn cũ "chính sách".
        $response->assertDontSee('Tra cứu thông tin chính sách và các MCC liên quan đã trích xuất từ tài liệu của thẻ.');
        $response->assertDontSee('Chọn chính sách hoàn tiền');
        $response->assertDontSee('Xem chính sách');

        $response->assertSee('cc-lookup-policy-select', false);
        $response->assertSee('cc-lookup-mcc-input', false);
    }

    #[Test]
    public function policy_picker_only_offers_policies_the_user_can_see(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $private = PolicyTemplate::create([
            'scope' => PolicyTemplate::SCOPE_USER,
            'owner_user_id' => $owner->id,
            'name' => 'Chính sách riêng tư',
            'slug' => 'chinh-sach-rieng-tu',
            'is_active' => true,
        ]);

        $service = app(CardPolicyLookupService::class);

        $this->assertTrue($service->selectableTemplates($owner->id)->contains('id', $private->id));
        $this->assertFalse($service->selectableTemplates($stranger->id)->contains('id', $private->id));

        // Trang của người khác KHÔNG chứa tên (đã JSON-escape) của chính sách riêng.
        $this->actingAs($stranger)
            ->get(route('credit-cards.lookup'))
            ->assertOk()
            ->assertDontSee(json_encode('Chính sách riêng tư'), false);
    }

    #[Test]
    public function lookup_requires_authentication(): void
    {
        $this->get(route('credit-cards.lookup'))->assertRedirect('/login');
        $this->getJson(route('credit-cards.lookup.policy', ['template_id' => 1]))->assertUnauthorized();
    }

    #[Test]
    public function mcc_tables_exist_after_migration(): void
    {
        $this->assertTrue(Schema::connection('creditcard')->hasTable('credit_card_mcc_codes'));
        $this->assertTrue(Schema::connection('creditcard')->hasTable('credit_card_policy_mcc_mappings'));
    }

    // =====================================================================
    // Tra cứu thẻ
    // =====================================================================

    #[Test]
    public function policy_lookup_returns_blueprint_without_any_mcc_mapping(): void
    {
        $user = User::factory()->create();
        $category = $this->makeSystemCategory(['name' => 'Ăn uống']);
        $template = $this->makeSystemTemplate(['name' => 'Chính sách không mapping']);

        $this->makeBlueprint($template, 'Chính sách không mapping', [
            ['name' => 'Ẩm thực', 'category_id' => $category->id, 'percent' => '2.000'],
        ]);

        // KHÔNG tạo bản ghi nào trong credit_card_policy_mcc_mappings.
        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        );

        $response->assertOk();
        $response->assertJsonPath('data.policy.name', 'Chính sách không mapping');
        $response->assertJsonPath('data.policy.has_blueprint', true);
        $response->assertJsonPath('data.policy.tiers.0.rules.0.cashback_percent', '2.000');
        $response->assertJsonPath('data.mcc_mappings', []);
    }

    #[Test]
    public function policy_lookup_returns_summary_and_mcc_mappings(): void
    {
        $user = User::factory()->create();
        $category = $this->makeSystemCategory(['name' => 'Siêu thị']);
        $template = $this->makeSystemTemplate(['name' => 'Chính sách A']);

        $this->makeBlueprint($template, 'Chính sách A', [
            ['name' => 'Siêu thị', 'category_id' => $category->id, 'percent' => '3.000'],
        ]);

        CardPolicyMccMapping::create([
            'policy_template_id' => $template->id,
            'category_id' => $category->id,
            'match_type' => CardPolicyMccMapping::MATCH_DIRECT,
            'mcc' => '5411',
            'application_status' => CardPolicyMccMapping::STATUS_APPLIED,
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.policy', ['template_id' => $template->id])
        );

        $response->assertOk();
        $response->assertJsonPath('data.policy.name', 'Chính sách A');
        $response->assertJsonPath('data.policy.has_blueprint', true);
        $response->assertJsonPath('data.policy.tiers.0.name', 'Bậc 1');
        $response->assertJsonPath('data.policy.tiers.0.rules.0.name', 'Siêu thị');
        $response->assertJsonPath('data.policy.tiers.0.rules.0.cashback_percent', '3.000');
        $response->assertJsonPath('data.mcc_mappings.0.mcc', '5411');
        $response->assertJsonPath('data.mcc_mappings.0.relation', 'direct');
    }

    #[Test]
    public function policy_lookup_rejects_a_policy_the_user_cannot_see(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $template = PolicyTemplate::create([
            'scope' => PolicyTemplate::SCOPE_USER,
            'owner_user_id' => $owner->id,
            'name' => 'Chính sách riêng',
            'slug' => 'chinh-sach-rieng',
            'is_active' => true,
        ]);

        $this->actingAs($stranger)
            ->getJson(route('credit-cards.lookup.policy', ['template_id' => $template->id]))
            ->assertStatus(422);
    }

    #[Test]
    public function policy_lookup_validates_template_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.policy'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('template_id');
    }

    // =====================================================================
    // Tra cứu MCC
    // =====================================================================

    #[Test]
    public function mcc_lookup_returns_master_description_and_direct_match(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ B']);

        MccCode::create([
            'mcc' => '5411',
            'description_vi' => 'Siêu thị / tạp hóa',
            'description_en' => 'Grocery Stores, Supermarkets',
            'source' => 'MCC_reference_Vietnamese.csv',
            'verification_status' => 'verified',
        ]);

        CardPolicyMccMapping::create([
            'policy_template_id' => $template->id,
            'match_type' => CardPolicyMccMapping::MATCH_DIRECT,
            'mcc' => '5411',
            'application_status' => CardPolicyMccMapping::STATUS_APPLIED,
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.mcc', ['mcc' => '5411'])
        );

        $response->assertOk();
        $response->assertJsonPath('data.code', '5411');
        $response->assertJsonPath('data.known', true);
        $response->assertJsonPath('data.master.description_vi', 'Siêu thị / tạp hóa');
        $response->assertJsonPath('data.matches.0.relation', 'direct');
        $response->assertJsonPath('data.matches.0.policy_name', 'Thẻ B');
    }

    #[Test]
    public function mcc_lookup_shows_description_even_without_any_mapping(): void
    {
        $user = User::factory()->create();

        MccCode::create([
            'mcc' => '0742',
            'description_vi' => 'Dịch vụ thú y',
            'description_en' => 'Veterinary Services',
            'verification_status' => 'unverified',
        ]);

        // KHÔNG có bản ghi mapping nào cho 0742.
        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.mcc', ['mcc' => '0742'])
        );

        $response->assertOk();
        $response->assertJsonPath('data.code', '0742');
        $response->assertJsonPath('data.known', true);
        $response->assertJsonPath('data.master.description_vi', 'Dịch vụ thú y');
        $response->assertJsonPath('data.master.description_en', 'Veterinary Services');
        $response->assertJsonPath('data.matches', []);
        $response->assertJsonPath('data.note', 'Chưa có dữ liệu mapping nào cho MCC này. Kết quả có thể chưa đầy đủ.');
    }

    #[Test]
    public function mcc_lookup_distinguishes_exclusion_from_broad_application(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ C']);

        // "Mọi MCC TRỪ 5411" — phải lưu đúng dạng all_except + danh sách ngoại lệ.
        CardPolicyMccMapping::create([
            'policy_template_id' => $template->id,
            'match_type' => CardPolicyMccMapping::MATCH_ALL_EXCEPT,
            'mcc_codes' => ['5411'],
            'application_status' => CardPolicyMccMapping::STATUS_APPLIED,
            'verification_status' => 'verified',
            'exclusion_reason' => 'Không áp dụng cho siêu thị',
        ]);

        // Một mapping "chưa xác minh" để phân biệt rõ trạng thái thiếu dữ liệu.
        CardPolicyMccMapping::create([
            'policy_template_id' => $template->id,
            'match_type' => CardPolicyMccMapping::MATCH_DIRECT,
            'mcc' => '5812',
            'application_status' => CardPolicyMccMapping::STATUS_UNVERIFIED,
            'verification_status' => 'unverified',
        ]);

        // 5411 → bị loại trừ; 5999 → áp dụng rộng; 5812 → chưa xác minh.
        $excluded = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.mcc', ['mcc' => '5411'])
        );
        $excluded->assertOk();
        $excluded->assertJsonPath('data.matches.0.relation', 'excluded');
        $excluded->assertJsonPath('data.matches.0.exclusion_reason', 'Không áp dụng cho siêu thị');

        $broad = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.mcc', ['mcc' => '5999'])
        );
        $broad->assertOk();
        $broad->assertJsonPath('data.matches.0.relation', 'broad');

        $unverified = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.mcc', ['mcc' => '5812'])
        );
        $unverified->assertOk();
        // 5812 vừa khớp quy tắc "áp dụng rộng" vừa có mapping trực tiếp chưa xác
        // minh — cả hai phải xuất hiện, và cái chưa xác minh giữ đúng nhãn.
        $unverified->assertJsonFragment(['relation' => 'broad']);
        $unverified->assertJsonFragment(['relation' => 'unverified', 'match_type' => 'direct']);
    }

    #[Test]
    public function mcc_lookup_reports_unknown_code_without_fabricating_results(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(
            route('credit-cards.lookup.mcc', ['mcc' => '9999'])
        );

        $response->assertOk();
        $response->assertJsonPath('data.known', false);
        $response->assertJsonPath('data.master', null);
        $response->assertJsonPath('data.matches', []);
    }

    #[Test]
    public function mcc_lookup_preserves_leading_zero_and_validates_four_digits(): void
    {
        $user = User::factory()->create();

        MccCode::create([
            'mcc' => '0111',
            'description_vi' => 'Nông nghiệp',
            'verification_status' => 'verified',
        ]);

        $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => '0111']))
            ->assertOk()
            ->assertJsonPath('data.code', '0111')
            ->assertJsonPath('data.known', true);

        foreach (['abc', '541', '54112', ''] as $invalid) {
            $this->actingAs($user)
                ->getJson(route('credit-cards.lookup.mcc', ['mcc' => $invalid]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('mcc');
        }
    }

    // =====================================================================
    // Import / seeder idempotent
    // =====================================================================

    #[Test]
    public function import_command_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'Chính sách Import']);
        $this->makeSystemCategory(['name' => 'Ẩm thực']);

        $path = $this->makeReferenceFile([
            ['mcc', 'description_vi', 'policy', 'category', 'match_type', 'application_status', 'verification_status'],
            ['5411', 'Siêu thị', 'Chính sách Import', 'Ẩm thực', 'direct', 'applied', 'verified'],
            ['5812', 'Nhà hàng', 'Chính sách Import', 'Ẩm thực', 'direct', 'applied', 'verified'],
        ]);

        Artisan::call('credit-card:import-mcc', ['file' => $path]);
        $this->assertSame(2, MccCode::query()->count());
        $this->assertSame(2, CardPolicyMccMapping::query()->count());

        // Chạy lại cùng file: KHÔNG tạo bản ghi trùng.
        Artisan::call('credit-card:import-mcc', ['file' => $path]);
        $this->assertSame(2, MccCode::query()->count());
        $this->assertSame(2, CardPolicyMccMapping::query()->count());

        @unlink($path);
    }

    #[Test]
    public function import_command_reads_xlsx_auto_detected_sheet_and_vietnamese_headers(): void
    {
        $path = $this->makeReferenceXlsx([
            ['MCC', 'Mô tả tiếng Anh', 'Mô tả tiếng Việt'],
            ['0742', 'Veterinary Services', 'Dịch vụ thú y'],
            ['5411', 'Grocery Stores and Supermarkets', 'Cửa hàng tạp hóa và siêu thị'],
        ], 'MCC_Tham_khao', withDecoy: true);

        Artisan::call('credit-card:import-mcc', ['file' => $path]);
        $output = Artisan::output();

        // Tự dò đúng sheet dữ liệu, bỏ qua sheet ghi chú.
        $this->assertStringContainsString('MCC_Tham_khao', $output);
        $this->assertSame(2, MccCode::query()->count());

        // Số 0 đứng đầu phải được giữ nguyên.
        $row = MccCode::query()->where('mcc', '0742')->firstOrFail();
        $this->assertSame('Dịch vụ thú y', $row->description_vi);
        $this->assertSame('Veterinary Services', $row->description_en);
        $this->assertSame(MccCode::STATUS_UNVERIFIED, $row->verification_status);

        @unlink($path);
    }

    #[Test]
    public function import_command_is_idempotent_for_xlsx(): void
    {
        $path = $this->makeReferenceXlsx([
            ['MCC', 'Mô tả tiếng Anh', 'Mô tả tiếng Việt'],
            ['0742', 'Veterinary Services', 'Dịch vụ thú y'],
        ]);

        Artisan::call('credit-card:import-mcc', ['file' => $path]);
        Artisan::call('credit-card:import-mcc', ['file' => $path]);

        $this->assertSame(1, MccCode::query()->count());

        @unlink($path);
    }

    #[Test]
    public function import_command_does_not_overwrite_good_values_with_blank(): void
    {
        MccCode::create([
            'mcc' => '5411',
            'description_vi' => 'Siêu thị',
            'verification_status' => MccCode::STATUS_VERIFIED,
        ]);

        // File chỉ có cột MCC — không được xoá mô tả/nguồn đã có.
        $path = $this->makeReferenceXlsx([
            ['MCC'],
            ['5411'],
        ]);

        Artisan::call('credit-card:import-mcc', ['file' => $path]);

        $row = MccCode::query()->where('mcc', '5411')->firstOrFail();
        $this->assertSame('Siêu thị', $row->description_vi);
        $this->assertSame(MccCode::STATUS_VERIFIED, $row->verification_status);

        @unlink($path);
    }

    #[Test]
    public function import_command_merges_additive_visa_workbook_without_deleting_or_touching_mappings(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ Visa']);

        // Dữ liệu cũ: một mã sẽ được cập nhật + một mã KHÔNG có trong file mới.
        MccCode::create([
            'mcc' => '5411',
            'description_vi' => 'Mô tả cũ',
            'verification_status' => MccCode::STATUS_VERIFIED,
        ]);
        MccCode::create([
            'mcc' => '1111',
            'description_vi' => 'Chỉ có trong DB cũ',
            'verification_status' => MccCode::STATUS_VERIFIED,
        ]);

        // Mapping cũ phải được giữ nguyên (importer KHÔNG được đụng vào).
        $mapping = CardPolicyMccMapping::create([
            'policy_template_id' => $template->id,
            'match_type' => CardPolicyMccMapping::MATCH_DIRECT,
            'mcc' => '5411',
            'application_status' => CardPolicyMccMapping::STATUS_APPLIED,
            'verification_status' => 'verified',
        ]);

        // Workbook đúng cấu trúc Visa tháng 4/2026 (header tiếng Việt).
        $path = $this->makeReferenceXlsx([
            ['MCC', 'Tên MCC tiếng Anh theo Visa', 'Tên tiếng Việt chuẩn hóa'],
            ['5411', 'Grocery Stores and Supermarkets', 'Cửa hàng tạp hóa và siêu thị'],
            ['0742', 'Veterinary Services', 'Dịch vụ thú y'],
        ]);

        Artisan::call('credit-card:import-mcc', ['file' => $path]);

        // 5411 cập nhật (giữ trạng thái verified), 0742 tạo mới, 1111 KHÔNG bị xoá.
        $this->assertSame(3, MccCode::query()->count());

        $existing = MccCode::query()->where('mcc', '5411')->firstOrFail();
        $this->assertSame('Cửa hàng tạp hóa và siêu thị', $existing->description_vi);
        $this->assertSame(MccCode::STATUS_VERIFIED, $existing->verification_status);

        $added = MccCode::query()->where('mcc', '0742')->firstOrFail();
        $this->assertSame('Dịch vụ thú y', $added->description_vi);

        // Không xoá mã cũ không xuất hiện trong file mới.
        $this->assertDatabaseHas('credit_card_mcc_codes', ['mcc' => '1111'], 'creditcard');

        // Mapping không bị thay đổi.
        $this->assertSame(1, CardPolicyMccMapping::query()->count());
        $this->assertSame(
            $mapping->fingerprint,
            CardPolicyMccMapping::query()->firstOrFail()->fingerprint,
        );

        // Chạy lại: không tạo trùng, không đổi gì.
        Artisan::call('credit-card:import-mcc', ['file' => $path]);
        $this->assertSame(3, MccCode::query()->count());
        $this->assertSame(1, CardPolicyMccMapping::query()->count());

        @unlink($path);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * Tạo blueprint (PolicyVersion rời) + 1 bậc + các rule cho một template.
     *
     * @param  array<int, array{name?:string, category_id:int, percent:string}>  $rules
     */
    private function makeBlueprint(PolicyTemplate $template, string $name, array $rules): Policy
    {
        $blueprint = Policy::create([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $name,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'min_total_spend' => 0,
            'max_cashback_total_per_period' => 300000,
            'rounding_mode' => 'round',
        ]);

        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        $tier = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Bậc 1',
            'sort_order' => 0,
            'min_total_spend' => 0,
            'max_total_spend' => null,
        ]);

        foreach ($rules as $index => $rule) {
            PolicyTierCategory::create([
                'tier_id' => $tier->id,
                'category_id' => $rule['category_id'],
                'name' => $rule['name'] ?? null,
                'sort_order' => $index,
                'spend_from' => 0,
                'cashback_percent' => $rule['percent'],
                'is_enabled' => true,
                'counts_toward_tier_cap' => true,
            ]);
        }

        return $blueprint;
    }

    /**
     * Ghi tạm file CSV tham chiếu trong thư mục temp của hệ thống.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function makeReferenceFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mcc_ref_').'.csv';
        $handle = fopen($path, 'wb');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }

    /**
     * Ghi tạm workbook XLSX tham chiếu. `withDecoy` thêm một sheet ghi chú
     * đứng TRƯỚC để kiểm tra bước tự dò sheet dữ liệu.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function makeReferenceXlsx(array $rows, string $sheet = 'MCC_Tham_khao', bool $withDecoy = false): string
    {
        $spreadsheet = new Spreadsheet;
        $active = $spreadsheet->getActiveSheet();

        if ($withDecoy) {
            $active->setTitle('Nguon_va_luu_y');
            $active->fromArray([['Mục', 'Nội dung'], ['Nguồn', 'Visa Merchant Data Standards Manual']]);

            $target = $spreadsheet->createSheet();
            $target->setTitle($sheet);
        } else {
            $active->setTitle($sheet);
            $target = $active;
        }

        $target->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'mcc_ref_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
