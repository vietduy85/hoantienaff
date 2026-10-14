<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileDocument;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccProfileRowCode;
use App\Models\CreditCard\PolicyMccRowBenefit;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\User;
use App\Services\CreditCard\CardPolicyLookupService;
use App\Services\CreditCard\PolicyMccProfileService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Tab "Tra cứu danh mục" — gom danh mục từ HỒ SƠ MCC và tra các thẻ hoàn tiền.
 *
 * Nguyên tắc: chỉ dùng dòng included, không nhân bản thẻ, giữ khác biệt bậc, và
 * không tự gán danh mục cho dòng chỉ mang tính KÊNH / mô tả.
 */
class CardLookupCategoryTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();
    }

    /**
     * Tạo hồ sơ MCC với danh sách dòng; mỗi dòng có thể kèm mã MCC và các bậc.
     *
     * @param  array<int, array{category?:?string, type?:string, codes?:array<int,string>, benefits?:array<int,array<string,mixed>>}>  $rows
     * @param  array<string, mixed>  $attributes
     */
    private function makeProfile(PolicyTemplate $template, array $rows, array $attributes = []): PolicyMccProfile
    {
        $profile = PolicyMccProfile::create(array_merge([
            'policy_template_id' => $template->id,
            'name' => 'Hồ sơ '.$template->name,
        ], $attributes));

        foreach ($rows as $index => $definition) {
            $row = PolicyMccProfileRow::create([
                'policy_mcc_profile_id' => $profile->id,
                'row_type' => $definition['type'] ?? PolicyMccProfileRow::TYPE_INCLUDED,
                'category_name' => $definition['category'] ?? null,
                'display_order' => $index,
            ]);

            foreach (($definition['codes'] ?? []) as $code) {
                PolicyMccProfileRowCode::create([
                    'policy_mcc_row_id' => $row->id,
                    'mcc' => $code,
                ]);
            }

            foreach (array_values($definition['benefits'] ?? []) as $tierIndex => $benefit) {
                PolicyMccRowBenefit::create(array_merge([
                    'policy_mcc_row_id' => $row->id,
                    'tier_order' => $tierIndex + 1,
                ], $benefit));
            }
        }

        return $profile;
    }

    // =====================================================================
    // Giao diện tab
    // =====================================================================

    #[Test]
    public function lookup_page_has_category_tab_alongside_the_two_existing_tabs(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'Thẻ danh mục']);

        $html = $this->actingAs($user)->get(route('credit-cards.lookup'))->assertOk()->getContent();

        // Ba tab cùng tồn tại; hai tab cũ KHÔNG bị đổi.
        $this->assertStringContainsString('cc-lookup-tab-policy', $html);
        $this->assertStringContainsString('cc-lookup-tab-mcc', $html);
        $this->assertStringContainsString('cc-lookup-tab-category', $html);
        $this->assertStringContainsString('Tra cứu danh mục', $html);

        // Nội dung tab danh mục: tiêu đề, dropdown, nút, ghi chú chỉ đọc.
        $this->assertStringContainsString('Chọn danh mục', $html);
        $this->assertStringContainsString('cc-lookup-category-select', $html);
        $this->assertStringContainsString('cc-lookup-category-submit', $html);
        $this->assertStringContainsString('Chỉ tra cứu — không thay đổi dữ liệu.', $html);
    }

    #[Test]
    public function category_tab_follows_existing_responsive_tab_markup(): void
    {
        $user = User::factory()->create();
        $this->makeSystemTemplate(['name' => 'Thẻ responsive']);

        $html = $this->actingAs($user)->get(route('credit-cards.lookup'))->assertOk()->getContent();

        // Dùng lại đúng markup tab cũ (role=tab + flex-1 + h-11) và card responsive.
        $this->assertSame(3, substr_count($html, 'role="tab"'));
        $this->assertStringContainsString('data-testid="cc-lookup-tab-category"', $html);
        $this->assertStringContainsString('flex-1 inline-flex items-center justify-center gap-1.5 px-3 h-11', $html);
        $this->assertStringContainsString('sm:p-4', $html);
        $this->assertStringContainsString('space-y-4 sm:space-y-5', $html);
    }

    #[Test]
    public function category_lookup_requires_authentication(): void
    {
        $this->get(route('credit-cards.lookup.category', ['category' => 'supermarket']))
            ->assertRedirect();
    }

    // =====================================================================
    // Nguồn danh mục + chuẩn hoá/gom nhóm
    // =====================================================================

    #[Test]
    public function category_options_are_built_from_profile_included_rows(): void
    {
        $template = $this->makeSystemTemplate(['name' => 'Thẻ siêu thị']);
        $this->makeProfile($template, [
            ['category' => 'Siêu thị, cửa hàng tiện lợi'],
            ['category' => 'Y tế'],
        ]);

        $options = app(CardPolicyLookupService::class)->categoryOptions();
        $ids = array_column($options, 'id');

        $this->assertContains('supermarket', $ids);
        $this->assertContains('health', $ids);

        $user = User::factory()->create();
        $html = $this->actingAs($user)->get(route('credit-cards.lookup'))->getContent();

        // Dropdown render từ chính dữ liệu hồ sơ (id chuẩn hoá xuất hiện trong options).
        $this->assertStringContainsString('supermarket', $html);
        $this->assertStringContainsString('categoryEndpoint', $html);
    }

    #[Test]
    public function category_options_ignore_excluded_rows(): void
    {
        $template = $this->makeSystemTemplate(['name' => 'Thẻ chỉ loại trừ']);
        $this->makeProfile($template, [
            ['type' => PolicyMccProfileRow::TYPE_EXCLUDED, 'category' => 'Chứng khoán'],
        ]);

        $ids = array_column(app(CardPolicyLookupService::class)->categoryOptions(), 'id');

        $this->assertNotContains('raw:chung khoan', $ids);
    }

    #[Test]
    public function category_options_group_similar_names_under_one_canonical(): void
    {
        $template = $this->makeSystemTemplate(['name' => 'Thẻ nhóm y tế']);
        $this->makeProfile($template, [
            ['category' => 'Y tế'],
            ['category' => 'Bệnh viện'],
            ['category' => 'Sức khỏe'],
            ['category' => 'Chăm sóc sức khỏe'],
            ['category' => 'Y tế sức khỏe'],
        ]);

        $options = app(CardPolicyLookupService::class)->categoryOptions();
        $ids = array_column($options, 'id');

        // 5 tên khác nhau ⇒ CHỈ MỘT lựa chọn "health".
        $health = array_values(array_filter($options, fn (array $option): bool => $option['id'] === 'health'));
        $this->assertCount(1, $health);
        $this->assertSame('Y tế và sức khỏe', $health[0]['label']);

        foreach (['raw:benh vien', 'raw:suc khoe', 'raw:y te suc khoe', 'raw:cham soc suc khoe'] as $unexpected) {
            $this->assertNotContains($unexpected, $ids);
        }
    }

    #[Test]
    public function category_options_keep_unknown_names_as_their_own_option(): void
    {
        $template = $this->makeSystemTemplate(['name' => 'Thẻ danh mục lạ']);
        $this->makeProfile($template, [
            ['category' => 'Danh mục hoàn toàn mới'],
        ]);

        $ids = array_column(app(CardPolicyLookupService::class)->categoryOptions(), 'id');

        // Không tự gộp danh mục chưa khai báo — giữ riêng dưới khoá raw.
        $this->assertContains('raw:danh muc hoan toan moi', $ids);
    }

    // =====================================================================
    // Tra cứu theo danh mục
    // =====================================================================

    #[Test]
    public function category_lookup_returns_included_cards_for_the_category(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ A']);
        $this->makeProfile($template, [
            ['category' => 'Siêu thị', 'codes' => ['5411'], 'benefits' => [['cashback_percent' => 3]]],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'supermarket']))
            ->assertOk();

        $response->assertJsonPath('data.found', true);
        $response->assertJsonCount(1, 'data.matches');
        $response->assertJsonPath('data.matches.0.policy_name', 'Thẻ A');
        $response->assertJsonPath('data.matches.0.category_name', 'Siêu thị');
        $response->assertJsonPath('data.matches.0.mcc_codes', ['5411']);
        $response->assertJsonPath('data.matches.0.cashback.status', 'available');
    }

    #[Test]
    public function category_lookup_does_not_return_excluded_only_cards(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ loại trừ']);
        $this->makeProfile($template, [
            ['type' => PolicyMccProfileRow::TYPE_EXCLUDED, 'category' => 'Siêu thị'],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'supermarket']))
            ->assertOk();

        $response->assertJsonPath('data.found', false);
        $response->assertJsonCount(0, 'data.matches');
    }

    #[Test]
    public function category_lookup_shows_a_card_once_when_it_has_multiple_rows_in_the_category(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ nhiều dòng']);
        $this->makeProfile($template, [
            ['category' => 'Ăn uống', 'benefits' => [['cashback_percent' => 1]]],
            ['category' => 'Ẩm thực', 'benefits' => [['cashback_percent' => 2]]],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'food']))
            ->assertOk();

        // Nhiều dòng cùng danh mục ⇒ MỘT kết quả thẻ.
        $response->assertJsonCount(1, 'data.matches');
        $this->assertSame('Thẻ nhiều dòng', $response->json('data.matches.0.policy_name'));
    }

    #[Test]
    public function category_lookup_preserves_distinct_tiers_from_multiple_rows(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ nhiều bậc']);
        $this->makeProfile($template, [
            ['category' => 'Ăn uống', 'benefits' => [['cashback_percent' => 1, 'rate_label' => '1%']]],
            ['category' => 'Ẩm thực', 'benefits' => [['cashback_percent' => 2, 'rate_label' => '2%']]],
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'food']))
            ->assertOk();

        $tiers = $response->json('data.matches.0.cashback.tiers');
        $this->assertCount(2, $tiers);
    }

    #[Test]
    public function category_lookup_merges_shared_product_and_preserves_tier_differences(): void
    {
        $user = User::factory()->create();
        $starter = $this->makeSystemTemplate(['name' => 'VIB Max - Starter']);
        $plus = $this->makeSystemTemplate(['name' => 'VIB Max - Plus']);

        $this->makeProfile($starter, [
            ['category' => 'Mua sắm', 'benefits' => [['cashback_percent' => 1, 'rate_label' => '1%']]],
        ], ['product_name' => 'VIB Max Card Visa']);

        $this->makeProfile($plus, [
            ['category' => 'Mua sắm', 'benefits' => [['cashback_percent' => 2, 'rate_label' => '2%']]],
        ], ['product_name' => 'VIB Max Card Visa']);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'shopping']))
            ->assertOk();

        // Chương trình dùng chung ⇒ MỘT kết quả, giữ đủ mọi khác biệt bậc.
        $response->assertJsonCount(1, 'data.matches');
        $response->assertJsonPath('data.matches.0.policy_name', 'VIB Max Card Visa');
        $this->assertCount(2, $response->json('data.matches.0.cashback.tiers'));
    }

    #[Test]
    public function category_lookup_reports_empty_state_for_a_category_without_matches(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ khác']);
        $this->makeProfile($template, [['category' => 'Siêu thị']]);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'travel']))
            ->assertOk();

        $response->assertJsonPath('data.found', false);
        $response->assertJsonCount(0, 'data.matches');
    }

    #[Test]
    public function category_lookup_validates_the_category_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    // =====================================================================
    // Hồ sơ theo KÊNH / mô tả không mã
    // =====================================================================

    #[Test]
    public function category_lookup_keeps_channel_only_rows_out_of_unrelated_categories(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ kênh']);
        $this->makeProfile($template, [
            ['category' => 'Chi tiêu trực tuyến nước ngoài', 'benefits' => [['cashback_percent' => 1]]],
        ]);

        // Không đủ dữ liệu để gán vào "Chi tiêu trực tuyến" (online) ⇒ không kết quả.
        $online = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'online']))
            ->assertOk();
        $online->assertJsonPath('data.found', false);

        // Chỉ xuất hiện dưới CHÍNH danh mục kênh của nó.
        $raw = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'raw:chi tieu truc tuyen nuoc ngoai']))
            ->assertOk();
        $raw->assertJsonPath('data.found', true);
        $raw->assertJsonCount(1, 'data.matches');
    }

    #[Test]
    public function category_lookup_marks_descriptive_rows_without_codes(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'VPBank Super Shopee']);
        $this->makeProfile($template, [
            ['category' => 'Giao dịch tại Shopee, ShopeeFood', 'benefits' => [['cashback_percent' => 1]]],
        ], ['note' => 'Chưa công bố danh sách MCC']);

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'ecommerce']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.cashback.status', 'available');
        // Không có mã ⇒ có ghi chú mô tả, KHÔNG tạo mã giả.
        $response->assertJsonPath('data.matches.0.mcc_codes', []);
        $this->assertNotEmpty($response->json('data.matches.0.descriptive_note'));
    }

    #[Test]
    public function category_lookup_exposes_downloadable_document_for_its_profile(): void
    {
        $user = User::factory()->create();
        $template = $this->makeSystemTemplate(['name' => 'Thẻ có tài liệu danh mục']);
        $profile = $this->makeProfile($template, [
            ['category' => 'Siêu thị', 'codes' => ['5411'], 'benefits' => [['cashback_percent' => 2]]],
        ]);

        Storage::fake(PolicyMccProfileService::DOCUMENT_DISK);

        $document = PolicyMccProfileDocument::create([
            'policy_mcc_profile_id' => $profile->id,
            'disk' => PolicyMccProfileService::DOCUMENT_DISK,
            'path' => 'credit-card-mcc/danh-muc.pdf',
            'original_name' => 'dieu-kien-danh-muc.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 10,
            'uploaded_by' => null,
        ]);

        Storage::disk(PolicyMccProfileService::DOCUMENT_DISK)
            ->put('credit-card-mcc/danh-muc.pdf', 'noi dung');

        $response = $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => 'supermarket']))
            ->assertOk();

        $response->assertJsonPath('data.matches.0.documents.0.id', $document->id);
        $response->assertJsonPath('data.matches.0.documents.0.name', 'dieu-kien-danh-muc.pdf');

        $url = (string) $response->json('data.matches.0.documents.0.url');
        $this->assertStringContainsString('/tra-cuu/tai-lieu/'.$profile->id.'/', $url);
    }
}
