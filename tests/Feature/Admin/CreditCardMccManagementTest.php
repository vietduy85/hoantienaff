<?php

namespace Tests\Feature\Admin;

use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccProfileRowCode;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Module "Quản lý MCC" (admin) — mô hình HỒ SƠ MCC chính sách.
 *
 * Bao phủ:
 *   - menu + phân quyền (view đọc, manage ghi);
 *   - tạo/xem/sửa hồ sơ với hai bảng dòng included / excluded;
 *   - validate: mã trùng trong một danh sách, mã không tồn tại, mã vừa included
 *     vừa excluded trong cùng hồ sơ;
 *   - tra cứu MCC master cho ô nhập.
 *
 * KHÔNG có chức năng xoá hồ sơ (spec §3 chỉ yêu cầu xem/sửa).
 */
class CreditCardMccManagementTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $viewer;

    private User $manager;

    private User $member;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        $this->viewer = User::factory()->create()->givePermissionTo('credit-cards.view');
        $this->manager = User::factory()->create()->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);
        $this->member = User::factory()->create();
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function seedMcc(array $codes): void
    {
        foreach ($codes as $code) {
            MccCode::create([
                'mcc' => $code,
                'description_vi' => 'Mô tả '.$code,
                'description_en' => 'Description '.$code,
                'verification_status' => MccCode::STATUS_UNVERIFIED,
            ]);
        }
    }

    // =====================================================================
    // Menu + phân quyền
    // =====================================================================

    #[Test]
    public function menu_entry_is_visible_for_viewer_and_hidden_for_member(): void
    {
        $viewerHtml = $this->actingAs($this->viewer)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Quản lý MCC', $viewerHtml);
        $this->assertStringContainsString('href="'.route('admin.credit-card.mcc.index').'"', $viewerHtml);

        $memberHtml = $this->actingAs($this->member)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Quản lý MCC', $memberHtml);
    }

    #[Test]
    public function index_requires_view_permission(): void
    {
        $this->actingAs($this->member)->get(route('admin.credit-card.mcc.index'))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('admin.credit-card.mcc.index'))->assertOk();
    }

    #[Test]
    public function create_and_store_require_manage_permission(): void
    {
        $template = $this->makeSystemTemplate();

        $this->actingAs($this->viewer)->get(route('admin.credit-card.mcc.create'))->assertForbidden();

        $this->actingAs($this->viewer)
            ->post(route('admin.credit-card.mcc.store'), [
                'policy_template_id' => $template->id,
                'name' => 'MCC Test',
            ])
            ->assertForbidden();
    }

    // =====================================================================
    // Tạo hồ sơ
    // =====================================================================

    #[Test]
    public function manager_can_create_profile_with_included_and_excluded_rows(): void
    {
        $this->seedMcc(['5411', '6011', '7995']);
        $template = $this->makeSystemTemplate(['name' => 'VIB Super Card']);

        $this->actingAs($this->manager)
            ->post(route('admin.credit-card.mcc.store'), [
                'policy_template_id' => $template->id,
                'name' => 'MCC VIB Super Card',
                'note' => 'Trích từ tài liệu 2024',
                'included' => [
                    ['category_name' => 'Siêu thị', 'description' => 'Chi tiêu siêu thị', 'codes' => ['5411', '6011']],
                ],
                'excluded' => [
                    ['category_name' => 'Cờ bạc', 'codes' => ['7995']],
                ],
            ])
            ->assertRedirect();

        $profile = PolicyMccProfile::query()->firstOrFail();
        $this->assertSame('MCC VIB Super Card', $profile->name);
        $this->assertSame($template->id, $profile->policy_template_id);

        $this->assertSame(1, $profile->rows()->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)->count());
        $this->assertSame(1, $profile->rows()->where('row_type', PolicyMccProfileRow::TYPE_EXCLUDED)->count());
        $this->assertSame(3, PolicyMccProfileRowCode::query()->count());

        // Mã master KHÔNG bị tạo mới cũng không bị xoá.
        $this->assertSame(3, MccCode::query()->count());
    }

    #[Test]
    public function store_rejects_duplicate_and_unknown_and_overlapping_codes(): void
    {
        $this->seedMcc(['5411', '7995']);
        $template = $this->makeSystemTemplate();

        $base = ['policy_template_id' => $template->id, 'name' => 'MCC Test'];

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card.mcc.create'))
            ->post(route('admin.credit-card.mcc.store'), $base + [
                'included' => [['codes' => ['5411', '5411']]],
            ])
            ->assertSessionHasErrors('included');

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card.mcc.create'))
            ->post(route('admin.credit-card.mcc.store'), $base + [
                'included' => [['codes' => ['9999']]],
            ])
            ->assertSessionHasErrors('included');

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card.mcc.create'))
            ->post(route('admin.credit-card.mcc.store'), $base + [
                'included' => [['codes' => ['5411']]],
                'excluded' => [['codes' => ['5411']]],
            ])
            ->assertSessionHasErrors('included');

        $this->assertSame(0, PolicyMccProfile::query()->count());
        $this->assertSame(2, MccCode::query()->count());
    }

    #[Test]
    public function profile_is_created_without_any_mcc_row(): void
    {
        $template = $this->makeSystemTemplate();

        $this->actingAs($this->manager)
            ->post(route('admin.credit-card.mcc.store'), [
                'policy_template_id' => $template->id,
                'name' => 'MCC rỗng',
            ])
            ->assertRedirect();

        $profile = PolicyMccProfile::query()->firstOrFail();
        $this->assertSame(0, $profile->rows()->count());
    }

    #[Test]
    public function manager_can_create_profile_with_annual_fees(): void
    {
        $template = $this->makeSystemTemplate();

        $this->actingAs($this->manager)
            ->post(route('admin.credit-card.mcc.store'), [
                'policy_template_id' => $template->id,
                'name' => 'MCC có phí',
                'annual_fee_primary' => '1200000',
                'annual_fee_supplementary' => '600000',
                'annual_spend_for_annual_fee_waiver' => '120000000',
            ])
            ->assertRedirect();

        $profile = PolicyMccProfile::query()->firstOrFail();
        $this->assertSame('1200000.00', $profile->annual_fee_primary);
        $this->assertSame('600000.00', $profile->annual_fee_supplementary);
        $this->assertSame('120000000.00', $profile->annual_spend_for_annual_fee_waiver);
    }

    #[Test]
    public function annual_fees_default_to_null_when_omitted_or_blank(): void
    {
        $template = $this->makeSystemTemplate();

        $this->actingAs($this->manager)
            ->post(route('admin.credit-card.mcc.store'), [
                'policy_template_id' => $template->id,
                'name' => 'MCC không phí',
                'annual_fee_primary' => '',
                'annual_fee_supplementary' => null,
                'annual_spend_for_annual_fee_waiver' => '',
            ])
            ->assertRedirect();

        $profile = PolicyMccProfile::query()->firstOrFail();
        $this->assertNull($profile->annual_fee_primary);
        $this->assertNull($profile->annual_fee_supplementary);
        $this->assertNull($profile->annual_spend_for_annual_fee_waiver);
    }

    #[Test]
    public function store_rejects_negative_or_non_numeric_annual_fees(): void
    {
        $template = $this->makeSystemTemplate();
        $base = ['policy_template_id' => $template->id, 'name' => 'MCC Test'];

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card.mcc.create'))
            ->post(route('admin.credit-card.mcc.store'), $base + [
                'annual_fee_primary' => '-1',
            ])
            ->assertSessionHasErrors('annual_fee_primary');

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card.mcc.create'))
            ->post(route('admin.credit-card.mcc.store'), $base + [
                'annual_fee_supplementary' => 'abc',
            ])
            ->assertSessionHasErrors('annual_fee_supplementary');

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card.mcc.create'))
            ->post(route('admin.credit-card.mcc.store'), $base + [
                'annual_spend_for_annual_fee_waiver' => '-100',
            ])
            ->assertSessionHasErrors('annual_spend_for_annual_fee_waiver');

        $this->assertSame(0, PolicyMccProfile::query()->count());
    }

    // =====================================================================
    // Xem / sửa
    // =====================================================================

    #[Test]
    public function show_page_lists_profile_codes(): void
    {
        $this->seedMcc(['5411', '7995']);
        $template = $this->makeSystemTemplate();

        $profile = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'MCC Xem',
        ]);
        $row = PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_INCLUDED,
            'category_name' => 'Siêu thị',
        ]);
        PolicyMccProfileRowCode::create(['policy_mcc_row_id' => $row->id, 'mcc' => '5411']);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card.mcc.show', $profile->id))
            ->assertOk()
            ->assertSee('MCC Xem')
            ->assertSee('5411');
    }

    #[Test]
    public function manager_can_update_profile_rows(): void
    {
        $this->seedMcc(['5411', '6011', '7995']);
        $template = $this->makeSystemTemplate();

        $profile = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'MCC Cũ',
        ]);
        $row = PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_INCLUDED,
        ]);
        PolicyMccProfileRowCode::create(['policy_mcc_row_id' => $row->id, 'mcc' => '5411']);

        $this->actingAs($this->manager)
            ->patch(route('admin.credit-card.mcc.update', $profile->id), [
                'name' => 'MCC Mới',
                'included' => [
                    ['category_name' => 'Ăn uống', 'codes' => ['6011']],
                ],
                'excluded' => [
                    ['category_name' => 'Cờ bạc', 'codes' => ['7995']],
                ],
            ])
            ->assertRedirect(route('admin.credit-card.mcc.show', $profile->id));

        $profile->refresh();
        $this->assertSame('MCC Mới', $profile->name);

        $codes = PolicyMccProfileRowCode::query()->orderBy('mcc')->pluck('mcc')->all();
        $this->assertSame(['6011', '7995'], $codes);

        $includedCount = $profile->rows()->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)->count();
        $excludedCount = $profile->rows()->where('row_type', PolicyMccProfileRow::TYPE_EXCLUDED)->count();
        $this->assertSame(1, $includedCount);
        $this->assertSame(1, $excludedCount);
    }

    #[Test]
    public function show_page_displays_annual_fees_and_empty_state(): void
    {
        $template = $this->makeSystemTemplate();

        $withFees = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'MCC có phí',
            'annual_fee_primary' => '1200000',
            'annual_fee_supplementary' => '600000',
            'annual_spend_for_annual_fee_waiver' => '120000000',
        ]);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card.mcc.show', $withFees->id))
            ->assertOk()
            ->assertSee('Phí thường niên thẻ chính')
            ->assertSee('1.200.000')
            ->assertSee('600.000')
            ->assertSee('120.000.000');

        $withoutFees = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'MCC không phí',
        ]);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card.mcc.show', $withoutFees->id))
            ->assertOk()
            ->assertSee('Chưa có thông tin phí thường niên.');
    }

    #[Test]
    public function manager_can_update_and_clear_annual_fees(): void
    {
        $template = $this->makeSystemTemplate();

        $profile = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'MCC phí',
            'annual_fee_primary' => '1200000',
            'annual_fee_supplementary' => '600000',
            'annual_spend_for_annual_fee_waiver' => '120000000',
        ]);

        $this->actingAs($this->manager)
            ->patch(route('admin.credit-card.mcc.update', $profile->id), [
                'name' => 'MCC phí mới',
                'annual_fee_primary' => '1500000',
                'annual_fee_supplementary' => '',
                'annual_spend_for_annual_fee_waiver' => '',
            ])
            ->assertRedirect(route('admin.credit-card.mcc.show', $profile->id));

        $profile->refresh();
        $this->assertSame('1500000.00', $profile->annual_fee_primary);
        $this->assertNull($profile->annual_fee_supplementary);
        $this->assertNull($profile->annual_spend_for_annual_fee_waiver);
    }

    // =====================================================================
    // Tra cứu MCC master
    // =====================================================================

    #[Test]
    public function master_search_returns_matching_codes(): void
    {
        $this->seedMcc(['5411', '5412', '7995']);

        $response = $this->actingAs($this->manager)
            ->getJson(route('admin.credit-card.mcc.master', ['q' => '541']))
            ->assertOk();

        $codes = array_column($response->json('data'), 'mcc');
        $this->assertContains('5411', $codes);
        $this->assertContains('5412', $codes);
        $this->assertNotContains('7995', $codes);
    }

    #[Test]
    public function creating_profile_does_not_touch_legacy_group_tables(): void
    {
        $this->seedMcc(['5411']);
        $template = $this->makeSystemTemplate();

        $this->actingAs($this->manager)
            ->post(route('admin.credit-card.mcc.store'), [
                'policy_template_id' => $template->id,
                'name' => 'MCC Mới',
                'included' => [['codes' => ['5411']]],
            ])
            ->assertRedirect();

        $this->assertSame(1, PolicyMccProfile::query()->count());
        $this->assertTrue(PolicyTemplate::query()->whereKey($template->id)->exists());
    }
}
