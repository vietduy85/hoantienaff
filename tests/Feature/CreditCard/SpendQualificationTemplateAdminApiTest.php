<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\SpendQualificationTemplate;
use App\Models\CreditCard\SpendQualificationTemplateCondition;
use App\Models\User;
use App\Services\CreditCard\SpendQualificationService;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * JSON API quản trị "Mẫu điều kiện hoàn tiền đặc biệt" + trang chỉnh sửa.
 *
 * BUGFIX: `UpdateSpendQualificationTemplateRequest::spendQualification(): ?array`
 * override không tương thích kiểu trả về với lớp cha (`array`) ⇒ PHP fatal error
 * ngay khi class được autoload, khiến MỌI thao tác lưu mẫu (PATCH) chết. Bộ test
 * này khoá lại:
 *   - trang chỉnh sửa mở được và JS nằm trong thẻ <script>;
 *   - PATCH cập nhật metadata + bộ điều kiện hợp lệ ⇒ lưu đúng;
 *   - PATCH CHỈ gửi name/slug (bỏ `spend_qualification`) ⇒ GIỮ NGUYÊN điều kiện;
 *   - PATCH gửi bộ điều kiện mới ⇒ GHI ĐÈ;
 *   - validation lỗi trả 422 JSON (không phải 500 fatal);
 *   - POST tạo mẫu vẫn hoạt động sau khi nới kiểu trả về ở lớp cha.
 */
class SpendQualificationTemplateAdminApiTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $admin;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        $admin = Role::create(['name' => 'Admin']);
        $admin->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);

        $this->admin = User::factory()->create()->assignRole('Admin');
    }

    // =====================================================================
    // Trang chỉnh sửa
    // =====================================================================

    #[Test]
    public function the_edit_page_opens_and_registers_its_javascript_in_a_script_tag(): void
    {
        $template = $this->makeTemplate('Mẫu để sửa', 'mau-de-sua', true, 10);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.credit-card.spend-qualifications.edit', $template))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'window.ccSpendQualificationTemplateEditor = function',
            (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html),
            'JS của trang chỉnh sửa phải nằm trong thẻ <script>, không in ra body.',
        );

        $this->assertStringContainsString('ccSpendQualificationTemplateEditor', $html);
        $this->assertStringContainsString('mau-de-sua', $html);
    }

    // =====================================================================
    // PATCH — cập nhật đầy đủ
    // =====================================================================

    #[Test]
    public function updating_with_valid_data_saves_metadata_and_conditions(): void
    {
        $template = $this->makeTemplate('Tên cũ', 'ten-cu', true, 5);
        $category = $this->makeSystemCategory();

        $payload = [
            'name' => 'Tên mới',
            'slug' => 'ten-moi',
            'description' => 'Mô tả mới',
            'note' => 'Ghi chú mới',
            'is_active' => false,
            'sort_order' => 42,
            'spend_qualification' => [
                'conditions' => [
                    [
                        'type' => 'category',
                        'category_id' => $category->id,
                        'min_spend' => 3000000,
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)
            ->patchJson(route('admin.credit-card.spend-qualifications.api.update', $template), $payload)
            ->assertOk();

        $this->assertSame('Tên mới', $response->json('data.name'));
        $this->assertSame('ten-moi', $response->json('data.slug'));
        $this->assertFalse($response->json('data.is_active'));

        $template->refresh();
        $this->assertSame('Tên mới', $template->name);
        $this->assertSame('ten-moi', $template->slug);
        $this->assertSame(42, (int) $template->sort_order);
        $this->assertFalse($template->isActive());

        $qualification = app(SpendQualificationService::class)->payloadForTemplate((int) $template->id);
        $this->assertCount(1, $qualification['conditions']);
        $this->assertSame('category', $qualification['conditions'][0]['type']);
        $this->assertSame($category->id, $qualification['conditions'][0]['category_id']);
        $this->assertSame(3000000.0, $qualification['conditions'][0]['min_spend']);
    }

    // =====================================================================
    // PATCH từng phần — bỏ `spend_qualification` ⇒ giữ nguyên điều kiện
    // =====================================================================

    #[Test]
    public function updating_only_name_and_slug_keeps_the_existing_conditions(): void
    {
        $template = $this->makeTemplate('Giữ điều kiện', 'giu-dieu-kien', true, 5);
        $category = $this->makeSystemCategory();

        SpendQualificationTemplateCondition::create([
            'template_id' => $template->id,
            'condition_type' => SpendQualificationTemplateCondition::TYPE_CATEGORY,
            'category_id' => $category->id,
            'min_spend' => 1500000,
            'is_enabled' => true,
            'sort_order' => 1,
        ]);

        $before = app(SpendQualificationService::class)->payloadForTemplate((int) $template->id);
        $this->assertCount(1, $before['conditions']);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.credit-card.spend-qualifications.api.update', $template), [
                'name' => 'Đổi tên thôi',
                'slug' => 'doi-ten-thoi',
            ])
            ->assertOk();

        $template->refresh();
        $this->assertSame('Đổi tên thôi', $template->name);
        $this->assertSame('doi-ten-thoi', $template->slug);

        $after = app(SpendQualificationService::class)->payloadForTemplate((int) $template->id);
        $this->assertCount(1, $after['conditions'], 'Bỏ spend_qualification ⇒ giữ nguyên điều kiện.');
        $this->assertSame(1500000.0, $after['conditions'][0]['min_spend']);
        $this->assertSame($category->id, $after['conditions'][0]['category_id']);
    }

    // =====================================================================
    // PATCH — gửi bộ điều kiện mới ⇒ ghi đè
    // =====================================================================

    #[Test]
    public function sending_new_conditions_replaces_the_existing_ones(): void
    {
        $template = $this->makeTemplate('Ghi đè điều kiện', 'ghi-de-dieu-kien', true, 5);
        $oldCategory = $this->makeSystemCategory();

        SpendQualificationTemplateCondition::create([
            'template_id' => $template->id,
            'condition_type' => SpendQualificationTemplateCondition::TYPE_CATEGORY,
            'category_id' => $oldCategory->id,
            'min_spend' => 1000000,
            'is_enabled' => true,
            'sort_order' => 1,
        ]);

        $newCategory = $this->makeSystemCategory();
        $excludedA = $this->makeSystemCategory();
        $excludedB = $this->makeSystemCategory();

        $this->actingAs($this->admin)
            ->patchJson(route('admin.credit-card.spend-qualifications.api.update', $template), [
                'spend_qualification' => [
                    'conditions' => [
                        [
                            'type' => 'category',
                            'category_id' => $newCategory->id,
                            'min_spend' => 2000000,
                        ],
                        [
                            'type' => 'other',
                            'min_spend' => 5000000,
                            'excluded_category_ids' => [$excludedA->id, $excludedB->id],
                        ],
                    ],
                ],
            ])
            ->assertOk();

        $payload = app(SpendQualificationService::class)->payloadForTemplate((int) $template->id);

        $this->assertCount(2, $payload['conditions']);
        $this->assertSame('category', $payload['conditions'][0]['type']);
        $this->assertSame($newCategory->id, $payload['conditions'][0]['category_id']);
        $this->assertSame('other', $payload['conditions'][1]['type']);
        $this->assertSame(5000000.0, $payload['conditions'][1]['min_spend']);
        $this->assertSame([$excludedA->id, $excludedB->id], $payload['conditions'][1]['excluded_category_ids']);
    }

    // =====================================================================
    // Validation — trả 422 JSON, KHÔNG fatal
    // =====================================================================

    #[Test]
    public function invalid_update_returns_422_json_instead_of_a_php_fatal_error(): void
    {
        $template = $this->makeTemplate('Lỗi validate', 'loi-validate', true, 5);

        $response = $this->actingAs($this->admin)
            ->patchJson(route('admin.credit-card.spend-qualifications.api.update', $template), [
                'name' => 'Tên hợp lệ',
                'spend_qualification' => [
                    'conditions' => [],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['spend_qualification.conditions']]);
    }

    #[Test]
    public function store_requires_a_spend_qualification_payload(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.credit-card.spend-qualifications.api.store'), [
                'name' => 'Thiếu điều kiện',
                'slug' => 'thieu-dieu-kien',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['spend_qualification']]);
    }

    /**
     * Khẳng định lại đường "xoá điều kiện" thực tế: gửi `spend_qualification = null`
     * bị luật `required` của lớp cha chặn (422), KHÔNG phải đường xoá. Vắng khoá mới
     * là "giữ nguyên" (đã test ở trên).
     */
    #[Test]
    public function explicit_null_spend_qualification_is_rejected_by_validation(): void
    {
        $template = $this->makeTemplate('Null điều kiện', 'null-dieu-kien', true, 5);

        $this->actingAs($this->admin)
            ->patchJson(route('admin.credit-card.spend-qualifications.api.update', $template), [
                'spend_qualification' => null,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['spend_qualification']]);
    }

    // =====================================================================
    // POST — tạo mẫu vẫn hoạt động sau khi nới kiểu ở lớp cha
    // =====================================================================

    #[Test]
    public function store_creates_a_template_with_conditions(): void
    {
        $category = $this->makeSystemCategory();

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.credit-card.spend-qualifications.api.store'), [
                'name' => 'Mẫu mới',
                'slug' => 'mau-moi',
                'is_active' => true,
                'spend_qualification' => [
                    'conditions' => [
                        [
                            'type' => 'category',
                            'category_id' => $category->id,
                            'min_spend' => 4000000,
                        ],
                    ],
                ],
            ])
            ->assertCreated();

        $id = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $id);

        $template = SpendQualificationTemplate::query()->findOrFail($id);
        $this->assertSame('mau-moi', $template->slug);

        $payload = app(SpendQualificationService::class)->payloadForTemplate($id);
        $this->assertCount(1, $payload['conditions']);
        $this->assertSame($category->id, $payload['conditions'][0]['category_id']);
    }

    // =====================================================================
    // Fixture
    // =====================================================================

    private function makeTemplate(string $name, string $slug, bool $active, int $sortOrder): SpendQualificationTemplate
    {
        return SpendQualificationTemplate::create([
            'name' => $name,
            'slug' => $slug,
            'description' => 'Mô tả '.$name,
            'is_active' => $active,
            'sort_order' => $sortOrder,
        ]);
    }
}
