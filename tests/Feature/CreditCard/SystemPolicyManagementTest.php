<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyTierCategoryTransactionCap;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\StatementPeriod;
use App\Models\User;
use App\Services\CreditCard\CategoryComboService;
use App\Services\CreditCard\CategoryRuleService;
use App\Services\CreditCard\PolicyService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * "Chính sách hoàn tiền hệ thống" — khu vực quản trị + cô lập với người dùng.
 *
 * Bảo vệ cốt lõi:
 *   1. Admin routes luôn trong group `auth` + `permission:credit-cards.*`.
 *   2. `PolicyTemplatePolicy` chặn ghi lên system template nếu user không phải Admin
 *      (lớp phòng thủ thứ hai sau permission middleware).
 *   3. Version blueprint append-only: đổi cấu hình hệ thống tạo version N+1, version
 *      cũ chỉ đóng metadata — không sửa business rule.
 *   4. Thẻ clone template hệ thống nhận bản SAO, không bao giờ tham chiếu blueprint;
 *      blueprint đổi sau đó không viết lại thẻ đã clone.
 *   5. Blueprint hệ thống chỉ dùng danh mục hệ thống đang active; policy của thẻ chỉ
 *      dùng danh mục hệ thống + danh mục riêng của chính chủ thẻ.
 */
class SystemPolicyManagementTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private User $stranger;

    private User $member;

    private User $viewer;

    private User $editor;

    private User $manager;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        $admin = Role::create(['name' => 'Admin']);
        $admin->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->member = User::factory()->create();
        $this->viewer = User::factory()->create()->givePermissionTo('credit-cards.view');
        $this->editor = User::factory()->create()
            ->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);
        $this->manager = $this->makeAdmin();
    }

    // =====================================================================
    // Auth / phân quyền
    // =====================================================================

    #[Test]
    public function guest_is_redirected_from_admin_policy_pages_and_gets_401_on_api(): void
    {
        $template = $this->makeSystemPolicy();

        $this->get(route('admin.credit-card-policies.index'))->assertRedirect(route('login'));
        $this->get(route('admin.credit-card-policies.create'))->assertRedirect(route('login'));
        $this->get(route('admin.credit-card-policies.show', $template))->assertRedirect(route('login'));
        $this->get(route('admin.credit-card-policies.edit', $template))->assertRedirect(route('login'));

        $this->getJson(route('admin.credit-card-policies.api.index'))->assertUnauthorized();
        $this->postJson(route('admin.credit-card-policies.api.store'), [])->assertUnauthorized();
        $this->getJson(route('admin.credit-card-policies.api.show', $template))->assertUnauthorized();
        $this->getJson(route('admin.credit-card-policies.api.versions', $template))->assertUnauthorized();
        $this->postJson(route('admin.credit-card-policies.api.versions.store', $template), [])->assertUnauthorized();
        $this->patchJson(route('admin.credit-card-policies.api.update', $template), [])->assertUnauthorized();
        $blueprint = $template->currentBlueprint();
        $this->patchJson(route('admin.credit-card-policies.api.versions.update', [$template, $blueprint->id]), [])->assertUnauthorized();
    }

    #[Test]
    public function member_without_permission_is_forbidden_from_admin_policy_area(): void
    {
        $template = $this->makeSystemPolicy();

        $this->actingAs($this->member)->get(route('admin.credit-card-policies.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.credit-card-policies.create'))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.credit-card-policies.show', $template))->assertForbidden();
        $this->actingAs($this->member)->get(route('admin.credit-card-policies.edit', $template))->assertForbidden();

        $this->actingAs($this->member)->getJson(route('admin.credit-card-policies.api.index'))->assertForbidden();
        $this->actingAs($this->member)->postJson(route('admin.credit-card-policies.api.store'), ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->member)->getJson(route('admin.credit-card-policies.api.show', $template))->assertForbidden();
        $this->actingAs($this->member)->getJson(route('admin.credit-card-policies.api.versions', $template))->assertForbidden();
        $this->actingAs($this->member)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), ['effective_from' => '2026-11-01'])
            ->assertForbidden();
        $this->actingAs($this->member)->patchJson(route('admin.credit-card-policies.api.update', $template), [])->assertForbidden();
        $blueprint = $template->currentBlueprint();
        $this->actingAs($this->member)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template, $blueprint->id]), [])
            ->assertForbidden();
    }

    #[Test]
    public function viewer_can_view_policies_but_cannot_manage_them(): void
    {
        $template = $this->makeSystemPolicy();

        $this->actingAs($this->viewer)->get(route('admin.credit-card-policies.index'))->assertOk();
        $this->actingAs($this->viewer)->get(route('admin.credit-card-policies.show', $template))->assertOk();
        $this->actingAs($this->viewer)->get(route('admin.credit-card-policies.create'))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('admin.credit-card-policies.edit', $template))->assertForbidden();

        $this->actingAs($this->viewer)->getJson(route('admin.credit-card-policies.api.index'))->assertOk();
        $this->actingAs($this->viewer)->getJson(route('admin.credit-card-policies.api.show', $template))->assertOk();
        $this->actingAs($this->viewer)->getJson(route('admin.credit-card-policies.api.versions', $template))->assertOk();
        $this->actingAs($this->viewer)
            ->postJson(route('admin.credit-card-policies.api.store'), ['name' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), ['effective_from' => '2026-11-01'])
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->patchJson(route('admin.credit-card-policies.api.update', $template), ['name' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template, $template->currentBlueprint()->id]), ['effective_from' => '2026-11-01'])
            ->assertForbidden();
    }

    #[Test]
    public function manage_permission_without_admin_role_cannot_edit_system_policy(): void
    {
        // `editor` có đủ quyền manage (vượt được middleware) nhưng KHÔNG có role
        // Admin → `PolicyTemplatePolicy::update` chặn chính sách hệ thống.
        $template = $this->makeSystemPolicy();

        $this->actingAs($this->editor)->get(route('admin.credit-card-policies.index'))->assertOk();

        $this->actingAs($this->editor)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), ['effective_from' => '2026-11-01'])
            ->assertForbidden();
        $this->actingAs($this->editor)
            ->patchJson(route('admin.credit-card-policies.api.update', $template), ['name' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->editor)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template, $template->currentBlueprint()->id]), ['effective_from' => '2026-11-01'])
            ->assertForbidden();
    }

    // =====================================================================
    // Admin tạo / sửa chính sách hệ thống
    // =====================================================================

    #[Test]
    public function manager_can_create_published_system_policy_with_tiers_and_rules(): void
    {
        $category = $this->makeSystemCategory();

        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB JCB Ultimate',
                'description' => 'Mẫu hoàn tiền cho JCB Ultimate',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'min_total_spend' => 0,
                'rounding_mode' => 'floor',
                'tiers' => [
                    [
                        'name' => 'Chi tiêu cơ bản',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'max_cashback_per_period' => 1000000,
                        'rules' => [
                            [
                                'category_id' => $category->id,
                                'cashback_percent' => 3,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'MB JCB Ultimate')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.version_no', 1)
            ->assertJsonPath('data.tiers_count', 1)
            ->assertJsonPath('data.tiers.0.max_cashback_per_period', 1000000);
        $this->assertEquals(3.0, $response->json('data.tiers.0.rules.0.cashback_percent'));

        $templateId = $response->json('data.id');
        $template = PolicyTemplate::query()->findOrFail($templateId);

        $this->assertTrue($template->isSystemScope());
        $this->assertSame(0, (int) $template->owner_user_id);
        $this->assertTrue((bool) $template->is_active);

        $blueprint = $template->currentBlueprint();
        $this->assertNotNull($blueprint);
        $this->assertSame(1, (int) $blueprint->version_no);
        $this->assertTrue($blueprint->isBlueprint());
        $this->assertSame(1, $blueprint->tiers()->count());
        $this->assertSame('1000000.00', $blueprint->tiers()->first()->max_cashback_per_period);
        $this->assertNull($blueprint->max_cashback_total_per_period, 'Trần hoàn đã chuyển về BẬC, policy không còn cap.');
    }

    #[Test]
    public function draft_status_marks_system_policy_inactive(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Nháp chưa dùng',
                'effective_from' => '2026-09-01',
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);

        $template = PolicyTemplate::query()->findOrFail($response->json('data.id'));
        $this->assertFalse((bool) $template->is_active);
    }

    #[Test]
    public function create_system_policy_requires_valid_payload(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), ['effective_from' => '2026-09-01', 'status' => 'published'])
            ->assertUnprocessable();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Tỷ lệ vượt 100',
                'effective_from' => 'bad-date',
                'status' => 'published',
            ])
            ->assertUnprocessable();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Status xấu',
                'effective_from' => '2026-09-01',
                'status' => 'shipped',
            ])
            ->assertUnprocessable();
    }

    #[Test]
    public function create_system_policy_rejects_categories_outside_system_scope(): void
    {
        $userCategory = $this->makeUserCategory($this->owner->id);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Dính danh mục riêng',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            ['category_id' => $userCategory->id, 'cashback_percent' => 5],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'danh mục hệ thống'));

        // Danh mục hệ thống nhưng đang ẩn cũng bị chặn.
        $inactiveSystem = $this->makeSystemCategory(['is_active' => false]);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Dính danh mục ẩn',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            ['category_id' => $inactiveSystem->id, 'cashback_percent' => 5],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function create_system_policy_without_tiers_creates_default_tier(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Chưa có cấu hình',
                'effective_from' => '2026-09-01',
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tiers_count', 1);

        $template = PolicyTemplate::query()->findOrFail($response->json('data.id'));
        $this->assertSame(1, $template->currentBlueprint()->tiers()->count());
    }

    #[Test]
    public function manager_can_create_new_version_and_old_blueprint_stays_intact(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->currentBlueprint();
        $v1Percent = (float) $v1->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail()->cashback_percent;

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
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
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 2)
            ->assertJsonPath('data.status', Policy::STATUS_ACTIVE);

        $v2 = $template->currentBlueprint();
        $this->assertNotNull($v2);
        $this->assertSame(2, (int) $v2->version_no);

        $v1->refresh();
        $this->assertSame(Policy::STATUS_SUPERSEDED, $v1->status);
        $this->assertSame('2026-10-31', $v1->effective_to?->toDateString());

        // Business rule của v1 BẤT BIẾN (append-only).
        $v1Rule = $v1->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();
        $this->assertEquals($v1Percent, (float) $v1Rule->cashback_percent);
    }

    #[Test]
    public function new_version_without_overrides_deep_copies_latest_config(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->currentBlueprint();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-12-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 2);

        $v2 = $template->currentBlueprint();
        $this->assertNotEquals($v1->id, $v2->id);
        $this->assertSame(1, $v2->tiers()->count());

        $rule = $v2->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();
        $this->assertEquals(3.0, (float) $rule->cashback_percent);
    }

    #[Test]
    public function store_version_can_update_template_metadata_too(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'name' => 'Tên mới sau nâng cấp',
                'description' => 'Mô tả mới',
                'status' => 'published',
                'effective_from' => '2026-11-01',
            ])
            ->assertCreated();

        $template->refresh();
        $this->assertSame('Tên mới sau nâng cấp', $template->name);
        $this->assertSame('Mô tả mới', $template->description);
        $this->assertTrue((bool) $template->is_active);
        $this->assertSame(2, $template->blueprints()->count());
    }

    #[Test]
    public function meta_update_does_not_create_new_blueprint(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.update', $template), [
                'name' => 'Chỉ đổi tên',
                'description' => 'Chỉ đổi mô tả',
                'status' => 'draft',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Chỉ đổi tên')
            ->assertJsonPath('data.is_active', false);

        $template->refresh();
        $this->assertSame(1, $template->blueprints()->count());
        $this->assertSame(1, (int) $template->currentBlueprint()->version_no);
    }

    #[Test]
    public function admin_pages_render_editor_for_management(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)->get(route('admin.credit-card-policies.index'))
            ->assertOk()
            ->assertSee('Chính sách hoàn tiền hệ thống')
            ->assertSee($template->name);

        $this->actingAs($this->manager)->get(route('admin.credit-card-policies.create'))
            ->assertOk()
            ->assertSee('Tạo chính sách hệ thống');

        $this->actingAs($this->manager)->get(route('admin.credit-card-policies.edit', $template))
            ->assertOk()
            ->assertSee('Lưu phiên bản mới');

        $this->actingAs($this->manager)->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->assertSee('Lịch sử phiên bản chính sách')
            ->assertSee($template->name);
    }

    // =====================================================================
    // User clone chính sách hệ thống + cô lập
    // =====================================================================

    #[Test]
    public function user_can_clone_system_template_into_own_card(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $card = $this->makeUserCard($this->owner->id);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'clone_system',
                'template_id' => $template->id,
                'effective_from' => '2026-10-01',
            ])
            ->assertCreated();

        $version = PolicyVersion::query()->findOrFail($response->json('data.id'));
        $this->assertSame((int) $card->id, (int) $version->user_card_id);
        $this->assertSame((int) $template->id, (int) $version->template_id);
        $this->assertSame(1, (int) $version->version_no);

        $rule = $version->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();
        $this->assertEquals(3.0, (float) $rule->cashback_percent);

        // Blueprint không bị sửa: thẻ nhận bản SAO độc lập.
        $template->refresh();
        $this->assertSame(1, $template->blueprints()->count());
        $this->assertSame($template->currentBlueprint()->id, $template->currentBlueprint()->id);
        $this->assertNotEquals($version->id, $template->currentBlueprint()->id);

        $card->refresh();
        $this->assertSame((int) $version->id, (int) $card->current_policy_id);
    }

    #[Test]
    public function user_clone_uses_default_blueprint_not_just_latest(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();
        $this->assertNotNull($v1);
        $this->assertSame(1, (int) $v1->version_no);

        // Admin tạo v2 (5%) nhưng CHƯA đặt làm mặc định → default vẫn là v1 (3%).
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 2);

        $v2 = $template->currentBlueprint();
        $this->assertNotNull($v2);
        $this->assertSame(2, (int) $v2->version_no);

        // User mới clone: nhận DEFAULT (v1), KHÔNG phải "latest" (v2).
        $cardA = $this->makeUserCard($this->owner->id);
        $responseA = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $cardA), [
                'mode' => 'clone_system',
                'template_id' => $template->id,
                'effective_from' => '2026-12-01',
            ])
            ->assertCreated();

        $ruleA = PolicyVersion::query()->findOrFail($responseA->json('data.id'))
            ->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();
        $this->assertEquals(3.0, (float) $ruleA->cashback_percent);

        // Đặt v2 làm mặc định → user mới tiếp theo nhận 5%; thẻ A không đổi.
        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $v2->id])
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('success');

        $template->refresh();
        $this->assertSame((int) $v2->id, (int) $template->default_version_id);

        $cardB = $this->makeUserCard($this->owner->id);
        $responseB = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $cardB), [
                'mode' => 'clone_system',
                'template_id' => $template->id,
                'effective_from' => '2026-12-02',
            ])
            ->assertCreated();

        $ruleB = PolicyVersion::query()->findOrFail($responseB->json('data.id'))
            ->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();
        $this->assertEquals(5.0, (float) $ruleB->cashback_percent);

        // Thẻ A cô lập: vẫn 3% dù default đã đổi.
        $clonedA = PolicyVersion::query()->findOrFail($responseA->json('data.id'));
        $ruleA->refresh();
        $this->assertEquals(3.0, (float) $ruleA->cashback_percent);
        $this->assertSame((int) $cardA->id, (int) $clonedA->user_card_id);
    }

    #[Test]
    public function new_admin_version_does_not_rewrite_existing_cloned_card(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $card = $this->makeUserCard($this->owner->id);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'clone_system',
                'template_id' => $template->id,
                'effective_from' => '2026-09-01',
            ])
            ->assertCreated();
        $clonedVersion = PolicyVersion::query()->findOrFail($response->json('data.id'));

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 9,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated();

        $clonedVersion->refresh();
        $rule = $clonedVersion->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();
        $this->assertEquals(3.0, (float) $rule->cashback_percent);
    }

    #[Test]
    public function system_blueprint_is_untouchable_via_user_write_endpoints(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $blueprint = $template->currentBlueprint();
        $tier = $blueprint->tiers()->firstOrFail();
        $rule = $tier->tierCategoryRules()->firstOrFail();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.tiers.store', $blueprint), [
                'name' => 'Bậc hacker',
                'min_total_spend' => 0,
            ])
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.rules.store', $tier), [
                'category_id' => $this->makeSystemCategory()->id,
                'cashback_percent' => 99,
            ])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.rules.destroy', $rule))
            ->assertForbidden();

        $blueprint->refresh();
        $this->assertSame(1, $blueprint->tiers()->count());
        $tier = $blueprint->tiers()->firstOrFail();
        $this->assertSame(1, $tier->tierCategoryRules()->categorySpecific()->count(), 'Chỉ 1 rule danh mục cụ thể.');
        $this->assertSame(1, $tier->tierCategoryRules()->fallback()->count(), 'Fallback mặc định của bậc vẫn tồn tại.');
    }

    #[Test]
    public function user_templates_index_excludes_inactive_system_templates_and_includes_counts(): void
    {
        $active = $this->makeSystemPolicy(3.0);
        $inactive = $this->makeSystemTemplate(['is_active' => false]);

        $response = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.templates.index'))
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains((int) $active->id, $ids);
        $this->assertNotContains((int) $inactive->id, $ids);

        $row = collect($response->json('data'))->firstWhere('id', (int) $active->id);
        $this->assertSame(true, $row['is_system']);
        $this->assertSame(true, $row['is_active']);
        $this->assertSame(1, $row['version_no']);
        $this->assertSame(1, $row['tiers_count']);
        $this->assertSame(1, $row['categories_count']);
    }

    #[Test]
    public function user_can_read_system_template_detail_with_tiers(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $response = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.templates.show', $template))
            ->assertOk()
            ->assertJsonPath('data.id', (int) $template->id)
            ->assertJsonPath('data.is_system', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.version_no', 1);
        $this->assertEquals(3.0, $response->json('data.tiers.0.rules.0.cashback_percent'));
    }

    #[Test]
    public function user_policies_page_uses_hoan_tien_label_and_shows_system_section(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)->get(route('credit-cards.policies'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Chính sách hoàn tiền', $html);
        $this->assertStringContainsString('Chính sách hoàn tiền hệ thống', $html);
        $this->assertStringContainsString('Chọn mẫu hoàn tiền do hệ thống cung cấp', $html);
        $this->assertSame($html, $html);
    }

    // =====================================================================
    // Phạm vi danh mục (tầng service — app bất kể HTTP dùng)
    // =====================================================================

    #[Test]
    public function category_rule_service_blocks_user_category_on_system_blueprint(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $blueprint = $template->currentBlueprint();
        $tier = $blueprint->tiers()->firstOrFail();
        $ownCategory = $this->makeUserCategory($this->owner->id);

        $service = $this->app->make(CategoryRuleService::class);

        try {
            $service->create($tier, ['category_id' => $ownCategory->id, 'cashback_percent' => 5]);
            $this->fail('Blueprint hệ thống KHÔNG được dùng danh mục riêng của user.');
        } catch (InvalidArgumentException) {
            // kỳ vọng.
        }

        $this->assertSame(1, $tier->tierCategoryRules()->categorySpecific()->count(), 'Không thêm rule trái phép.');
        $this->assertSame(1, $tier->tierCategoryRules()->fallback()->count(), 'Fallback mặc định của bậc vẫn tồn tại.');
    }

    #[Test]
    public function category_rule_service_blocks_stranger_category_on_cloned_card_policy(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $card = $this->makeUserCard($this->owner->id);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'clone_system',
                'template_id' => $template->id,
                'effective_from' => '2026-10-01',
            ])
            ->assertCreated();

        $version = PolicyVersion::query()->findOrFail($response->json('data.id'));
        $tier = $version->tiers()->firstOrFail();

        $strangerCategory = $this->makeUserCategory($this->stranger->id);
        $service = $this->app->make(CategoryRuleService::class);

        try {
            $service->create($tier, ['category_id' => $strangerCategory->id, 'cashback_percent' => 5]);
            $this->fail('User KHÔNG được dùng danh mục riêng của người khác.');
        } catch (InvalidArgumentException) {
            // kỳ vọng.
        }

        $this->assertSame(1, $tier->tierCategoryRules()->categorySpecific()->count(), 'Không thêm rule trái phép.');
        $this->assertSame(1, $tier->tierCategoryRules()->fallback()->count(), 'Fallback mặc định của bậc vẫn tồn tại.');
    }

    #[Test]
    public function category_rule_service_allows_own_category_and_system_category_on_card_policy(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $policy = $this->makePolicyForCard($card, [['min' => 0, 'max' => null]], []);
        $tier = $policy->tiers()->firstOrFail();
        $service = $this->app->make(CategoryRuleService::class);

        $ownCategory = $this->makeUserCategory($this->owner->id);
        $systemCategory = $this->makeSystemCategory();

        $service->create($tier, ['category_id' => $ownCategory->id, 'cashback_percent' => 2]);
        $service->create($tier, ['category_id' => $systemCategory->id, 'cashback_percent' => 3]);

        $this->assertSame(2, $tier->tierCategoryRules()->count());
    }

    #[Test]
    public function edit_page_no_longer_offers_rounding_configuration(): void
    {
        $template = $this->makeSystemPolicy();

        $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', $template))
            ->assertOk()
            // Cơ chế "Cách làm tròn" đã bị bỏ khỏi nghiệp vụ.
            ->assertDontSee('Cách làm tròn')
            ->assertDontSee('rounding_mode', false);
    }

    #[Test]
    public function version_save_from_editor_payload_preserves_unmodified_rules(): void
    {
        $catA = $this->makeSystemCategory();
        $catB = $this->makeSystemCategory();

        $create = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB JCB Ultimate',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [
                    [
                        'name' => 'Chi tiêu cơ bản',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            ['category_id' => $catA->id, 'cashback_percent' => 3],
                            ['category_id' => $catB->id, 'cashback_percent' => 5],
                        ],
                    ],
                ],
            ])
            ->assertCreated();

        $template = PolicyTemplate::query()->findOrFail($create->json('data.id'));

        // Presenter trả bậc kèm `tiers[].rules` một-một; editor giữ state `tier.rules`
        // và gửi lại đúng shape này + giữ id. Bản đồ phải GIỮ NGUYÊN rules
        // (chỉ đổi % của rule B sang 10%).
        $tiers = collect($create->json('data.tiers'))
            ->map(fn (array $tier) => [
                'name' => $tier['name'],
                'min_total_spend' => $tier['min_total_spend'],
                'max_total_spend' => $tier['max_total_spend'],
                'rules' => collect($tier['rules'])->map(fn (array $rule) => [
                    'id' => $rule['id'] ?? null,
                    'scope_type' => $rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY,
                    'category_id' => ($rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY) === PolicyTierCategory::SCOPE_OTHER
                        ? null
                        : (int) $rule['category_id'],
                    'cashback_percent' => (int) $rule['category_id'] === (int) $catB->id
                        ? 10.0
                        : (float) $rule['cashback_percent'],
                    'max_cashback_per_transaction' => $rule['max_cashback_per_transaction'],
                    'max_cashback_per_category_per_period' => $rule['max_cashback_per_category_per_period'],
                ])->values(),
            ])->values();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => $tiers->all(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 2);

        // v2 phải GIỮ ĐỦ 2 rules + % đã sửa — không được xoá sạch như hồi MB JCB.
        $v2 = $template->currentBlueprint();
        $this->assertNotNull($v2);
        $this->assertSame(2, (int) $v2->version_no);

        $v2Rules = $v2->tiers()->firstOrFail()->tierCategoryRules()->get();
        $this->assertCount(2, $v2Rules->whereNotNull('category_id'));
        $this->assertSame(1, $v2Rules->whereNull('category_id')->count(), 'Fallback round-trip giữ nguyên.');

        $ruleA = $v2Rules->firstWhere('category_id', $catA->id);
        $ruleB = $v2Rules->firstWhere('category_id', $catB->id);
        $this->assertNotNull($ruleA);
        $this->assertNotNull($ruleB);
        $this->assertEqualsWithDelta(3.0, (float) $ruleA->cashback_percent, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $ruleB->cashback_percent, 0.0001);

        // v1 bất biến.
        $v1 = $template->blueprints()->where('version_no', 1)->firstOrFail();
        $this->assertCount(2, $v1->tiers()->firstOrFail()->tierCategoryRules()->categorySpecific()->get());
    }

    // =====================================================================
    // Khái niệm DEFAULT VERSION: nguồn clone cho user mới
    // =====================================================================

    #[Test]
    public function creating_a_system_policy_sets_the_first_blueprint_as_default(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB JCB Ultimate',
                'effective_from' => '2026-09-01',
                'status' => 'published',
            ])
            ->assertCreated();

        $template = PolicyTemplate::query()->findOrFail($response->json('data.id'));
        $v1 = $template->defaultBlueprint();

        $this->assertNotNull($v1);
        $this->assertSame((int) $v1->id, (int) $template->default_version_id);
        $this->assertSame(1, (int) $v1->version_no);
    }

    #[Test]
    public function creating_new_versions_does_not_change_the_default_version(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 2);

        $template->refresh();
        $this->assertSame((int) $v1->id, (int) $template->default_version_id, 'Tạo version mới KHÔNG được tự đổi default.');
    }

    #[Test]
    public function default_marker_is_exposed_to_admin_pages_and_api(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();

        $showHtml = $this->actingAs($this->manager)->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('⭐ Mặc định: Version 1', $showHtml);

        $this->actingAs($this->manager)
            ->getJson(route('admin.credit-card-policies.api.versions', $template))
            ->assertOk()
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.0.can_delete', false);

        $this->actingAs($this->manager)
            ->getJson(route('admin.credit-card-policies.api.show', $template))
            ->assertOk()
            ->assertJsonPath('data.default_version_id', (int) $v1->id)
            ->assertJsonPath('data.default_version_no', 1);
    }

    #[Test]
    public function setting_a_default_version_updates_the_source_for_new_users_and_ui(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated();
        $v2 = $template->currentBlueprint();
        $this->assertSame(2, (int) $v2->version_no);

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $v2->id])
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('success');

        $template->refresh();
        $this->assertSame((int) $v2->id, (int) $template->default_version_id);

        $showHtml = $this->actingAs($this->manager)->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('⭐ Mặc định: Version 2', $showHtml);
    }

    #[Test]
    public function setting_a_nonexistent_or_foreign_version_as_default_is_rejected(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $foreign = $this->makeSystemPolicy(4.0)->defaultBlueprint();

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $foreign->id])
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('error');

        $template->refresh();
        $this->assertNotSame((int) $foreign->id, (int) $template->default_version_id);
    }

    #[Test]
    public function unused_version_can_be_deleted_and_default_stays(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated();
        $v2 = $template->currentBlueprint();

        $this->assertFalse($template->fresh()->default_version_id === $v2->id);

        // Trước khi xóa: UI báo can_delete = true cho version không mặc định, chưa dùng.
        $this->actingAs($this->manager)
            ->getJson(route('admin.credit-card-policies.api.versions', $template))
            ->assertOk()
            ->assertJsonPath('data.1.is_default', false)
            ->assertJsonPath('data.1.referenced', false)
            ->assertJsonPath('data.1.can_delete', true);

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]))
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('success');

        $this->assertNull(PolicyVersion::query()->find($v2->id));

        // Default v1 vẫn còn làm nguồn clone hợp lệ (kể cả khi v1 đã bị superseded).
        $template->refresh();
        $this->assertSame((int) $v1->id, (int) $template->default_version_id);
        $this->assertSame((int) $v1->id, (int) $template->defaultBlueprint()?->id);
    }

    #[Test]
    public function referenced_version_is_still_deletable_and_cloned_card_survives(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();

        // Thẻ đã deep clone từ v1 (3%): policy của thẻ là bản SAO, không FK về blueprint.
        $card = $this->makeUserCard($this->owner->id);
        $cloneResponse = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'clone_system',
                'template_id' => $template->id,
                'effective_from' => '2026-09-01',
            ])
            ->assertCreated();
        $clonedVersion = PolicyVersion::query()->findOrFail($cloneResponse->json('data.id'));

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated();
        $v2 = $template->currentBlueprint();

        // Dữ liệu lịch sử (kỳ sao kê) trỏ thẳng vào blueprint — KHÔNG được chặn xóa.
        $period = $this->makeStatementPeriod($card, [
            'policy_id' => $v2->id,
            'status' => StatementPeriod::STATUS_FINALIZED,
        ]);

        $this->actingAs($this->manager)
            ->getJson(route('admin.credit-card-policies.api.versions', $template))
            ->assertOk()
            ->assertJsonPath('data.1.is_default', false)
            ->assertJsonPath('data.1.referenced', true)
            ->assertJsonPath('data.1.can_delete', true);

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]))
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('success');

        $this->assertNull(PolicyVersion::query()->find($v2->id));
        $this->assertNotNull(StatementPeriod::query()->find($period->id), 'Xóa blueprint không được xóa kỳ sao kê.');

        // Thẻ đã clone giữ nguyên policy riêng + tỷ lệ 3%.
        $clonedVersion->refresh();
        $this->assertSame((int) $card->id, (int) $clonedVersion->user_card_id);
        $this->assertEquals(
            3.0,
            (float) $clonedVersion->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail()->cashback_percent
        );

        $template->refresh();
        $this->assertSame((int) $v1->id, (int) $template->default_version_id);
    }

    #[Test]
    public function default_version_cannot_be_deleted_but_chain_root_can_and_chain_is_repaired(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->currentBlueprint();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated();
        $v2 = $template->currentBlueprint();
        $this->assertTrue($v1->isRoot());
        $this->assertSame((int) $v1->id, (int) $v2->root_policy_id);

        // Xóa version đang là mặc định vẫn bị chặn.
        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v1->id]))
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'MẶC ĐỊNH'));

        $this->assertNotNull(PolicyVersion::query()->find($v1->id));

        // Đặt v2 làm mặc định rồi xóa gốc chuỗi (v1) — KHÔNG còn bị chặn.
        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $v2->id])
            ->assertRedirect(route('admin.credit-card-policies.show', $template));

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-12-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 7,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated();
        $v3 = $template->currentBlueprint();

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v1->id]))
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('success');

        $this->assertNull(PolicyVersion::query()->find($v1->id));

        // Không còn root_policy_id nào trỏ tới bản ghi đã xóa; version thấp nhất còn lại làm gốc mới.
        $this->assertSame(0, PolicyVersion::query()->where('root_policy_id', $v1->id)->count());
        $this->assertSame((int) $v2->id, (int) $v2->refresh()->root_policy_id);
        $this->assertTrue($v2->isRoot());
        $this->assertSame((int) $v2->id, (int) $v3->refresh()->root_policy_id);
        $this->assertFalse($v3->isRoot());

        $chain = PolicyVersion::query()
            ->where(function ($query) use ($v2): void {
                $query->where('id', $v2->id)->orWhere('root_policy_id', $v2->id);
            })
            ->orderBy('version_no')
            ->pluck('id')
            ->all();
        $this->assertSame([(int) $v2->id, (int) $v3->id], $chain);

        // Chuỗi còn nguyên: tạo version tiếp theo vẫn cấp version_no kế tiếp.
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2027-01-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 9,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 4);
    }

    #[Test]
    public function version_list_shows_set_default_and_delete_actions_and_default_is_the_only_blocked_one(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();
        $this->assertNotNull($v1);

        $store = function (string $effectiveFrom) use ($template): void {
            $this->actingAs($this->manager)
                ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                    'effective_from' => $effectiveFrom,
                    'tiers' => [
                        [
                            'name' => 'Bậc cơ bản',
                            'rules' => [
                                [
                                    'category_id' => $this->makeSystemCategory()->id,
                                    'cashback_percent' => 5,
                                    'spend_from' => 0,
                                    'spend_to' => null,
                                ],
                            ],
                        ],
                    ],
                ])
                ->assertCreated();
        };

        $store('2026-11-01');
        $v2 = $template->currentBlueprint();
        $store('2026-12-01');
        $v3 = $template->currentBlueprint();
        $this->assertSame(2, (int) $v2->version_no);
        $this->assertSame(3, (int) $v3->version_no, 'Phải có 3 version để test UI.');

        // 1+2. Version KHÔNG mặc định: [Đặt làm mặc định] + [Xóa] enabled trỏ đúng version.
        //       Version default (V1): indication rõ ràng + [Xóa] disabled (không có form DELETE).
        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('⭐ Mặc định: Version 1', $html);
        $this->assertStringContainsString(route('admin.credit-card-policies.api.default.store', $template->id), $html);
        $this->assertStringContainsString(sprintf('name="version_id" value="%d"', (int) $v2->id), $html);
        $this->assertStringContainsString(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]), $html);
        $this->assertStringContainsString('⭐ Đang mặc định', $html);
        $this->assertStringContainsString('Không thể xóa phiên bản đang mặc định. Hãy đặt phiên bản khác làm mặc định trước.', $html);
        $this->assertStringNotContainsString(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v1->id]), $html);

        // 3. Đặt V2 làm mặc định → default_version_id = V2, V1 không còn là default.
        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $v2->id])
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('success');

        $template->refresh();
        $this->assertSame((int) $v2->id, (int) $template->default_version_id, 'default_version_id phải nhảy sang V2.');
        $this->assertNotSame((int) $v1->id, (int) $template->default_version_id, 'V1 không còn là default.');

        // 4. UI sau khi đổi default: V1 có [Đặt làm mặc định], V2 hiển thị là default.
        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('⭐ Đang mặc định', $html);
        $this->assertStringContainsString(sprintf('name="version_id" value="%d"', (int) $v1->id), $html);
        $this->assertStringContainsString(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v3->id]), $html);

        // 5. Xóa V3 (không mặc định) → thành công thật.
        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v3->id]))
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('success');
        $this->assertNull(PolicyVersion::query()->find($v3->id));

        // V1 là gốc chuỗi nhưng KHÔNG phải mặc định → xóa được, chuỗi được sửa lại.
        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v1->id]))
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('success');
        $this->assertNull(PolicyVersion::query()->find($v1->id));
        $this->assertSame((int) $v2->id, (int) $v2->refresh()->root_policy_id);
        $this->assertTrue($v2->isRoot());

        // 6. Không cho xóa version đang là default.
        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.show', $template))
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]))
            ->assertRedirect(route('admin.credit-card-policies.show', $template))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'MẶC ĐỊNH'));
        $this->assertNotNull(PolicyVersion::query()->find($v2->id));

        // 7. Người xem (view, không manage): UI KHÔNG render action; API ghi trả 403.
        $html = $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('⭐ Đặt làm mặc định', $html);
        $this->assertStringNotContainsString(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]), $html);
        $this->actingAs($this->viewer)
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $v2->id])
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]))
            ->assertForbidden();
    }

    #[Test]
    public function new_boundaries_are_forbidden_for_viewer_and_manage_without_admin_role(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v2 = null;

        // Tạo v2 để có thứ "đặt mặc định"/"xóa".
        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated();
        $v2 = PolicyVersion::query()->findOrFail($response->json('data.id'));

        // member: không quyền → 403 mọi thứ.
        $this->actingAs($this->member)
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $v2->id])
            ->assertForbidden();
        $this->actingAs($this->member)
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]))
            ->assertForbidden();

        // viewer: đọc được nhưng 403 khi ghi.
        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk();
        $this->actingAs($this->viewer)
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $v2->id])
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]))
            ->assertForbidden();
        $this->actingAs($this->viewer)
            ->patchJson(route('admin.credit-card-policies.api.update', $template), ['name' => 'x'])
            ->assertForbidden();

        // editor: có manage nhưng không role Admin → PolicyTemplatePolicy chặn.
        $this->actingAs($this->editor)
            ->post(route('admin.credit-card-policies.api.default.store', $template), ['version_id' => $v2->id])
            ->assertForbidden();
        $this->actingAs($this->editor)
            ->delete(route('admin.credit-card-policies.api.versions.destroy', [$template->id, $v2->id]))
            ->assertForbidden();
        $this->actingAs($this->editor)
            ->patchJson(route('admin.credit-card-policies.api.update', $template), ['name' => 'x'])
            ->assertForbidden();
    }

    // =====================================================================
    // Metadata của template (chỉnh sửa thông tin)
    // =====================================================================

    #[Test]
    public function admin_can_edit_metadata_and_show_page_reflects_it(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.update', $template), [
                'name' => 'Tên mới từ màn hình thông tin',
                'description' => 'Mô tả mới từ màn hình thông tin',
                'status' => 'published',
            ])
            ->assertOk();

        $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->assertSee('Tên mới từ màn hình thông tin')
            ->assertSee('Mô tả mới từ màn hình thông tin');

        $this->assertSame('Tên mới từ màn hình thông tin', $template->fresh()->name);
        $this->assertSame(1, $template->blueprints()->count(), 'Sửa metadata không được tạo version mới.');
    }

    // =====================================================================
    // Chỉnh sửa CHÍNH version N (source_version_id) — deep copy + append
    // =====================================================================

    #[Test]
    public function editing_a_specific_version_copies_from_that_version_and_keeps_others_intact(): void
    {
        $catA = $this->makeSystemCategory();
        $catB = $this->makeSystemCategory();

        $template = $this->app->make(PolicyService::class)->createSystemTemplate(
            'Policy nhiều phiên bản',
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
                                'category_id' => $catA->id,
                                'cashback_percent' => 10,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ],
            true,
        );

        $v1 = $template->defaultBlueprint();
        $this->assertNotNull($v1);
        $this->assertSame(1, (int) $v1->version_no);
        $this->assertEquals(10.0, (float) $v1->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail()->cashback_percent);

        // v2 = 8% (supersede v1).
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            [
                                'category_id' => $catA->id,
                                'cashback_percent' => 8,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 2);
        $v2 = $template->currentBlueprint();
        $this->assertSame(2, (int) $v2->version_no);

        // Chỉnh sửa CHÍNH v1: source_version_id = v1 → v3 sinh ra TỪ v1 (10% + sửa),
        // vẫn nối tiếp chain, supersede v2.
        $v1RuleId = $v1->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail()->id;

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-12-01',
                'source_version_id' => $v1->id,
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            [
                                'category_id' => $catA->id,
                                'cashback_percent' => 8.5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                            [
                                'category_id' => $catB->id,
                                'cashback_percent' => 4,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 3);

        $v3 = $template->currentBlueprint();
        $this->assertNotNull($v3);
        $this->assertSame(3, (int) $v3->version_no);
        $this->assertSame(Policy::STATUS_SUPERSEDED, $v2->fresh()->status);

        $v3Rules = $v3->tiers()->firstOrFail()->tierCategoryRules()->get();
        $this->assertCount(2, $v3Rules->whereNotNull('category_id'));
        $this->assertSame(1, $v3Rules->whereNull('category_id')->count(), 'Fallback được deep-copy theo bậc.');
        $this->assertEqualsWithDelta(8.5, (float) $v3Rules->firstWhere('category_id', $catA->id)->cashback_percent, 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $v3Rules->firstWhere('category_id', $catB->id)->cashback_percent, 0.0001);

        // Deep copy: category_id giữ nguyên, id bản ghi là MỚI.
        $this->assertNotContains($v1RuleId, $v3Rules->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [(int) $catA->id, (int) $catB->id],
            $v3Rules->whereNotNull('category_id')->pluck('category_id')->map(fn ($id): int => (int) $id)->all(),
        );

        // v1 và v2 bất biến.
        $this->assertEquals(10.0, (float) $v1->fresh()->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail()->cashback_percent);
        $this->assertSame(Policy::STATUS_SUPERSEDED, $v1->fresh()->status);
        $v2Rules = $v2->fresh()->tiers()->firstOrFail()->tierCategoryRules()->get();
        $this->assertCount(1, $v2Rules->whereNotNull('category_id'));
        $this->assertEqualsWithDelta(8.0, (float) $v2Rules->first()->cashback_percent, 0.0001);

        // Default không đổi.
        $template->refresh();
        $this->assertSame((int) $v1->id, (int) $template->default_version_id);
    }

    #[Test]
    public function version_edit_page_targets_the_requested_version(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'rules' => [
                            [
                                'category_id' => $this->makeSystemCategory()->id,
                                'cashback_percent' => 5,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertCreated();
        $v2 = $template->currentBlueprint();

        // Sửa theo version: editor khởi tạo từ CHÍNH version 2 (5%), không phải default (3%).
        $response = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', [$template->id, 'version' => $v2->id]))
            ->assertOk()
            ->assertSee('Đang chỉnh sửa <strong>Version 2</strong>', false);

        $html = $response->getContent();

        // Editor nhận cấu hình version 2: effective_from 2026-11-01, không phải 2026-09-01 của default v1.
        $this->assertStringContainsString('\u0022effective_from\u0022:\u00222026-11-01\u0022', $html);
        $this->assertStringNotContainsString('\u0022effective_from\u0022:\u00222026-09-01\u0022', $html);
    }

    // =====================================================================
    // "Lưu lại" (PATCH versions.update) — sửa IN-PLACE chính version đang mở
    // =====================================================================

    #[Test]
    public function edit_page_hydrates_the_rules_and_category_options_of_the_target_version(): void
    {
        $catA = $this->makeSystemCategory();
        $catB = $this->makeSystemCategory();

        $template = $this->app->make(PolicyService::class)->createSystemTemplate(
            'Policy hồi quy category',
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            ['category_id' => $catA->id, 'cashback_percent' => 3],
                            ['category_id' => $catB->id, 'cashback_percent' => 5],
                        ],
                    ],
                ],
            ],
            true,
        );
        $v1 = $template->defaultBlueprint();
        $ruleIds = $v1->tiers()->firstOrFail()->tierCategoryRules()->pluck('id')->all();

        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', [$template->id, 'version' => $v1->id]))
            ->assertOk()
            ->getContent();

        // Server nhúng rule của CHÍNH version đang sửa vào x-data — đúng là bug
        // "Edit không load category" nằm ở CLIENT (Alpine x-model chạy trước x-for),
        // dữ liệu server đã đủ: category_id + id của rule và danh sách category.
        $this->assertStringContainsString('\u0022category_id\u0022:'.$catA->id, $html);
        $this->assertStringContainsString('\u0022category_id\u0022:'.$catB->id, $html);

        foreach ($ruleIds as $ruleId) {
            $this->assertStringContainsString('\u0022id\u0022:'.$ruleId, $html);
        }
    }

    #[Test]
    public function editor_payload_uses_the_canonical_rules_key_only(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', $template))
            ->assertOk()
            ->getContent();

        // Presenter + editor một-một `tiers[].rules`; `tier.categories` không tồn tại.
        $this->assertStringContainsString('\u0022rules\u0022:', $html);
        $this->assertStringNotContainsString('\u0022categories\u0022:', $html);
        $this->assertStringContainsString('x-for="(rule, ri) in tier.rules"', $html);
        $this->assertStringNotContainsString('tier.categories', $html);
    }

    #[Test]
    public function in_place_save_updates_the_same_version_keeping_ids(): void
    {
        $catA = $this->makeSystemCategory();
        $template = $this->makeSystemPolicy(3.0, $catA->id);
        $v1 = $template->currentBlueprint();
        $tier = $v1->tiers()->firstOrFail();
        $rule = $tier->tierCategoryRules()->firstOrFail();

        $versionId = (int) $v1->id;
        $tierId = (int) $tier->id;
        $ruleId = (int) $rule->id;
        $name = $template->name;

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'min_total_spend' => 500000,
                'tiers' => [[
                    'id' => $tierId,
                    'name' => 'Bậc cơ bản',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'max_cashback_per_period' => 2000000,
                    'rules' => [[
                        'id' => $ruleId,
                        'category_id' => $catA->id,
                        'cashback_percent' => 7,
                        'max_cashback_per_transaction' => 500000,
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.version_no', 1)
            ->assertJsonPath('data.id', $versionId);

        $template->refresh();
        $this->assertSame($name, $template->name, 'Lưu lại KHÔNG đổi metadata template.');
        $this->assertSame(1, $template->blueprints()->count(), 'Lưu lại KHÔNG tạo version mới.');
        $this->assertSame($versionId, (int) $template->default_version_id, 'Lưu lại KHÔNG đổi default.');

        $v1->refresh();
        $this->assertSame(1, (int) $v1->version_no, 'Lưu lại KHÔNG tăng version_no.');
        $this->assertSame('2026-09-15', $v1->effective_from->toDateString());
        $this->assertSame('500000.00', $v1->min_total_spend);
        $this->assertNull($v1->max_cashback_total_per_period, 'Trần hoàn giờ nằm ở bậc, policy không còn cap.');

        $tier->refresh();
        $this->assertSame($tierId, (int) $tier->id, 'Tier giữ nguyên id khi Lưu lại.');
        $this->assertSame('2000000.00', $tier->max_cashback_per_period);

        $rule->refresh();
        $this->assertSame($ruleId, (int) $rule->id, 'Rule giữ nguyên id khi Lưu lại.');
        $this->assertEqualsWithDelta(7.0, (float) $rule->cashback_percent, 0.0001);
        $this->assertSame('500000.00', $rule->max_cashback_per_transaction);
        $this->assertSame((int) $v1->id, (int) $v1->root_policy_id, 'Lưu lại KHÔNG đổi gốc chuỗi.');
    }

    #[Test]
    public function in_place_save_persists_the_template_description(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->currentBlueprint();

        $this->assertNull($template->description);

        // Editor "Lưu lại" gửi kèm `description` cùng cấu hình version.
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'description' => 'Updated description via in-place save',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', (int) $v1->id);

        $template->refresh();
        $this->assertSame('Updated description via in-place save', $template->description, 'Lưu lại phải lưu mô tả mới.');
        $this->assertSame(1, $template->blueprints()->count(), 'Lưu lại mô tả KHÔNG tạo version mới.');

        // Reload trang Chỉnh sửa: mô tả mới được hydrate lại vào editor.
        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', [$template->id, 'version' => $v1->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Updated description via in-place save', $html);
    }

    // =====================================================================
    // Giới hạn hoàn tiền theo giá trị giao dịch (§23)
    // =====================================================================

    #[Test]
    public function store_persists_transaction_caps_and_hydrates_them_back(): void
    {
        $catA = $this->makeSystemCategory();

        $create = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB Dynamic Cap',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc cơ bản',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'transaction_caps' => [
                        ['min_transaction_amount' => 0, 'max_transaction_amount' => 1000000, 'max_cashback_per_transaction' => 75000],
                        ['min_transaction_amount' => 1000000.01, 'max_transaction_amount' => null, 'max_cashback_per_transaction' => 150000],
                    ],
                    'rules' => [[
                        'category_id' => $catA->id,
                        'cashback_percent' => 10,
                    ]],
                ]],
            ])
            ->assertCreated();

        // API (và presenter) trả `transaction_caps` ở vị trí BẬC.
        $capsInResponse = $create->json('data.tiers.0.transaction_caps');
        $this->assertCount(2, $capsInResponse);
        $this->assertEqualsWithDelta(0.0, (float) $capsInResponse[0]['min_transaction_amount'], 0.0001);
        $this->assertSame(1000000.0, (float) $capsInResponse[0]['max_transaction_amount']);
        $this->assertNull($capsInResponse[1]['max_transaction_amount']);
        $this->assertNull($create->json('data.tiers.0.rules.0.transaction_caps'), 'Rule không còn mang cap động.');

        $template = PolicyTemplate::query()->findOrFail($create->json('data.id'));
        $tier = $template->currentBlueprint()
            ->tiers()->firstOrFail();

        $caps = $tier->transactionCaps()->orderBy('sort_order')->get();
        $this->assertCount(2, $caps);
        $this->assertSame('75000.00', $caps[0]->max_cashback_per_transaction);
        $this->assertSame('150000.00', $caps[1]->max_cashback_per_transaction);
        $this->assertNull($caps[1]->max_transaction_amount);
        $this->assertSame([1, 2], $caps->pluck('sort_order')->map(fn (int $v): int => (int) $v)->all());
        $this->assertSame((int) $tier->id, (int) $caps[0]->policy_tier_id, 'Cap gắn vào BẬC.');
    }

    #[Test]
    public function in_place_save_replaces_transaction_caps_without_touching_rule_ids(): void
    {
        [$template, $v1, $tier, $rule] = $this->systemPolicyWithCaps();

        $versionId = (int) $v1->id;
        $tierId = (int) $tier->id;
        $ruleId = (int) $rule->id;

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'tiers' => [[
                    'id' => $tierId,
                    'name' => 'Bậc cơ bản',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'transaction_caps' => [
                        ['min_transaction_amount' => 0, 'max_transaction_amount' => 200000, 'max_cashback_per_transaction' => 30000],
                    ],
                    'rules' => [[
                        'id' => $ruleId,
                        'category_id' => $rule->category_id,
                        'cashback_percent' => 10,
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.version_no', 1);

        $this->assertSame(1, (int) $v1->refresh()->version_no);

        $rule->refresh();
        $this->assertSame($ruleId, (int) $rule->id, 'Rule giữ nguyên id khi thay caps.');
        $this->assertSame(0, PolicyTierCategoryTransactionCap::where('policy_tier_id', $tierId)->where('max_cashback_per_transaction', '20000.00')->count(), 'Cap cũ đã bị thay.');

        $caps = $tier->transactionCaps()->orderBy('sort_order')->get();
        $this->assertCount(1, $caps);
        $this->assertSame('30000.00', $caps[0]->max_cashback_per_transaction);
        $this->assertSame('200000.00', $caps[0]->max_transaction_amount);
    }

    #[Test]
    public function turning_the_toggle_off_clears_transaction_caps(): void
    {
        [$template, $v1, $tier, $rule] = $this->systemPolicyWithCaps();
        $this->assertSame(2, PolicyTierCategoryTransactionCap::where('policy_tier_id', $tier->id)->count());

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'tiers' => [[
                    'id' => (int) $tier->id,
                    'name' => 'Bậc cơ bản',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'transaction_caps' => [],
                    'rules' => [[
                        'id' => (int) $rule->id,
                        'category_id' => $rule->category_id,
                        'cashback_percent' => 3,
                    ]],
                ]],
            ])
            ->assertOk();

        $rule->refresh();
        $this->assertSame(0, $tier->transactionCaps()->count(), 'Tắt toggle ⇒ xoá sạch cap.');
        $this->assertEqualsWithDelta(3.0, (float) $rule->cashback_percent, 0.0001, 'Rule vẫn tồn tại sau khi xoá cap.');
    }

    #[Test]
    public function overlapping_bands_are_rejected_with_422(): void
    {
        $catA = $this->makeSystemCategory();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB Overlap',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc cơ bản',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'transaction_caps' => [
                        ['min_transaction_amount' => 0, 'max_transaction_amount' => 200000, 'max_cashback_per_transaction' => 30000],
                        ['min_transaction_amount' => 150000, 'max_transaction_amount' => 500000, 'max_cashback_per_transaction' => 40000],
                    ],
                    'rules' => [[
                        'category_id' => $catA->id,
                        'cashback_percent' => 10,
                    ]],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Các khoảng giá trị giao dịch của giới hạn hoàn tiền không được chồng lấn hoặc trùng nhau.']);
    }

    #[Test]
    public function max_below_min_is_rejected_with_422(): void
    {
        $catA = $this->makeSystemCategory();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB Max<Min',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc cơ bản',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'transaction_caps' => [
                        ['min_transaction_amount' => 300000, 'max_transaction_amount' => 200000, 'max_cashback_per_transaction' => 30000],
                    ],
                    'rules' => [[
                        'category_id' => $catA->id,
                        'cashback_percent' => 10,
                    ]],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => '"Đến" (max_transaction_amount) phải lớn hơn hoặc bằng "Từ" (min_transaction_amount).']);
    }

    #[Test]
    public function bands_missing_required_fields_are_rejected_with_422(): void
    {
        $catA = $this->makeSystemCategory();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB Missing Fields',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc cơ bản',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'transaction_caps' => [
                        ['max_transaction_amount' => 500000],
                    ],
                    'rules' => [[
                        'category_id' => $catA->id,
                        'cashback_percent' => 10,
                    ]],
                ]],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function negative_band_amounts_are_rejected_with_422(): void
    {
        $catA = $this->makeSystemCategory();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB Negative',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc cơ bản',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'transaction_caps' => [
                        ['min_transaction_amount' => -1, 'max_transaction_amount' => 500000, 'max_cashback_per_transaction' => 30000],
                    ],
                    'rules' => [[
                        'category_id' => $catA->id,
                        'cashback_percent' => 10,
                    ]],
                ]],
            ])
            ->assertStatus(422);
    }

    /**
     * System policy có 1 bậc kèm 2 cap động — dùng chung cho §23.
     *
     * @return array{0: PolicyTemplate, 1: PolicyVersion, 2: PolicyTier, 3: PolicyTierCategory}
     */
    private function systemPolicyWithCaps(): array
    {
        $catA = $this->makeSystemCategory();

        $create = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'MB Dynamic Cap',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc cơ bản',
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'transaction_caps' => [
                        ['min_transaction_amount' => 0, 'max_transaction_amount' => 500000, 'max_cashback_per_transaction' => 20000],
                        ['min_transaction_amount' => 500000.01, 'max_transaction_amount' => null, 'max_cashback_per_transaction' => 90000],
                    ],
                    'rules' => [[
                        'category_id' => $catA->id,
                        'cashback_percent' => 10,
                    ]],
                ]],
            ])
            ->assertCreated();

        $template = PolicyTemplate::query()->findOrFail($create->json('data.id'));
        $version = $template->currentBlueprint();
        $tier = $version->tiers()->firstOrFail();
        $rule = $tier->tierCategoryRules()->firstOrFail();

        return [$template, $version, $tier, $rule];
    }

    #[Test]
    public function in_place_save_on_a_newer_version_keeps_the_chain_and_default(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();
        $v1Rule = $v1->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();

        // v2 = 5%, chưa đặt làm mặc định.
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'rules' => [
                        ['category_id' => $v1Rule->category_id, 'cashback_percent' => 5],
                    ],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 2);
        $v2 = $template->currentBlueprint();
        $v2Tier = $v2->tiers()->firstOrFail();
        $v2Rule = $v2Tier->tierCategoryRules()->firstOrFail();

        // Lưu lại ngay trên v2 (không phải default).
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v2->id]), [
                'effective_from' => '2026-11-10',
                'tiers' => [[
                    'id' => (int) $v2Tier->id,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [[
                        'id' => (int) $v2Rule->id,
                        'category_id' => (int) $v2Rule->category_id,
                        'cashback_percent' => 6,
                    ]],
                ]],
            ])
            ->assertOk();

        $template->refresh();
        $this->assertSame((int) $v1->id, (int) $template->default_version_id, 'Lưu lại KHÔNG đổi default.');
        $this->assertSame((int) $v2->id, (int) $template->currentBlueprint()->id, 'Lưu lại KHÔNG làm mất v2 là bản mới nhất.');
        $this->assertSame(2, (int) $v2->refresh()->version_no);
        $this->assertSame(Policy::STATUS_SUPERSEDED, $v1->refresh()->status);

        $v2Rule->refresh();
        $this->assertEqualsWithDelta(6.0, (float) $v2Rule->cashback_percent, 0.0001);
    }

    #[Test]
    public function in_place_save_does_not_rewrite_cloned_cards(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $card = $this->makeUserCard($this->owner->id);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card), [
                'mode' => 'clone_system',
                'template_id' => $template->id,
                'effective_from' => '2026-09-01',
            ])
            ->assertCreated();
        $cloned = PolicyVersion::query()->findOrFail($response->json('data.id'));
        $clonedRule = $cloned->tiers()->firstOrFail()->tierCategoryRules()->firstOrFail();

        $blueprint = $template->currentBlueprint();
        $bTier = $blueprint->tiers()->firstOrFail();
        $bRule = $bTier->tierCategoryRules()->firstOrFail();

        // Lưu lại trên blueprint 3% → 9%.
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $blueprint->id]), [
                'tiers' => [[
                    'id' => (int) $bTier->id,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [[
                        'id' => (int) $bRule->id,
                        'category_id' => (int) $bRule->category_id,
                        'cashback_percent' => 9,
                    ]],
                ]],
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(9.0, (float) $bRule->refresh()->cashback_percent, 0.0001);
        $clonedRule->refresh();
        $this->assertEqualsWithDelta(3.0, (float) $clonedRule->cashback_percent, 0.0001, 'Thẻ clone là bản SAO — KHÔNG bị Lưu lại viết lại.');
    }

    #[Test]
    public function in_place_save_removes_absent_rules_and_keeps_present_ids(): void
    {
        $catA = $this->makeSystemCategory();
        $catB = $this->makeSystemCategory();

        $template = $this->app->make(PolicyService::class)->createSystemTemplate(
            'Policy hai rules',
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            ['category_id' => $catA->id, 'cashback_percent' => 3],
                            ['category_id' => $catB->id, 'cashback_percent' => 5],
                        ],
                    ],
                ],
            ],
            true,
        );

        $v1 = $template->currentBlueprint();
        $tier = $v1->tiers()->firstOrFail();
        $rules = $tier->tierCategoryRules()->get()->keyBy('category_id');
        $ruleA = $rules->get($catA->id);
        $ruleB = $rules->get($catB->id);

        // Payload chỉ còn rule A → rule B vắng mặt phải bị XÓA, rule A giữ id + đổi %.
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'tiers' => [[
                    'id' => (int) $tier->id,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [[
                        'id' => (int) $ruleA->id,
                        'category_id' => $catA->id,
                        'cashback_percent' => 4,
                    ]],
                ]],
            ])
            ->assertOk();

        $tier->refresh();
        $remaining = $tier->tierCategoryRules()->get();
        $this->assertCount(2, $remaining);
        $this->assertSame(1, $remaining->whereNull('category_id')->count(), 'Fallback không thể bị gỡ bằng payload.');
        $this->assertSame((int) $ruleA->id, (int) $remaining->firstWhere('id', $ruleA->id)?->id, 'Rule có trong payload giữ id.');
        $this->assertEqualsWithDelta(4.0, (float) $remaining->firstWhere('id', $ruleA->id)->cashback_percent, 0.0001);
        $this->assertNull(PolicyTierCategory::query()->find($ruleB->id), 'Rule vắng trong payload bị xóa.');
    }

    #[Test]
    public function in_place_update_rejects_foreign_or_locked_versions(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->currentBlueprint();
        $foreign = $this->makeSystemPolicy(4.0)->currentBlueprint();

        // Phiên bản của blueprint KHÁC → 422.
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $foreign->id]), [
                'tiers' => [],
            ])
            ->assertUnprocessable();

        // Blueprint đã finalize (khoá) → 422.
        $v1->forceFill(['is_locked' => true])->save();
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'tiers' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'khoá'));
    }

    #[Test]
    public function versions_update_follows_the_auth_and_policy_matrix(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->currentBlueprint();
        $url = route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]);
        $body = ['effective_from' => '2026-09-15'];

        $this->patchJson($url, $body)->assertUnauthorized();

        $this->actingAs($this->member)->patchJson($url, $body)->assertForbidden();
        $this->actingAs($this->viewer)->patchJson($url, $body)->assertForbidden();
        $this->actingAs($this->editor)->patchJson($url, $body)->assertForbidden();

        $this->actingAs($this->manager)->patchJson($url, $body)->assertOk();
    }

    // =====================================================================
    // Trang [Xem] — chế độ chỉ đọc (reuse editor partial viewMode)
    // =====================================================================

    #[Test]
    public function manager_show_page_renders_readonly_editor_with_tier_cap(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $blueprint = $template->defaultBlueprint();
        $blueprint->tiers()->firstOrFail()
            ->forceFill(['max_cashback_per_period' => '5000000'])->save();

        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->getContent();

        // Editor chung nhưng chỉ đọc: không form submit, không endpoint API.
        $this->assertStringContainsString('x-data="systemPolicyEditor(', $html);
        $this->assertStringContainsString('Chế độ xem (chỉ đọc)', $html);
        $this->assertStringNotContainsString('api.versions.update', $html);
        $this->assertStringNotContainsString('api.versions.store', $html);
        $this->assertStringNotContainsString('api.clone', $html);

        // Sanitize về 0 DB writes: viewMode → khối hành động lưu bị @if(!$viewMode) loại bỏ.
        $this->assertStringNotContainsString('<template x-if="updateEndpoint">', $html);
        $this->assertStringNotContainsString('<template x-if="!updateEndpoint">', $html);
        $this->assertStringNotContainsString("@click=\"submit('current')\"", $html);
        $this->assertStringNotContainsString("@click=\"submit('new')\"", $html);

        // Trần hoàn của bậc xuất hiện trong editor hydrate; KHÔNG còn trần cấp policy.
        $this->assertStringContainsString('\u0022max_cashback_per_period\u0022:5000000', $html);
        $this->assertStringNotContainsString('max_cashback_total_per_period', $html);
        $this->assertStringNotContainsString('Hoàn tiền tối đa / kỳ', $html);

        // Version history read-only: heading hiển thị, không nút hành động.
        $this->assertStringContainsString('Lịch sử phiên bản chính sách', $html);
        $this->assertStringNotContainsString('Đặt mặc định', $html);

        // Manager thấy hành động Chỉnh sửa + Clone.
        $this->assertStringContainsString('Chỉnh sửa', $html);
        $this->assertStringContainsString('Clone', $html);
        $this->assertStringContainsString('Quay lại danh sách', $html);
    }

    #[Test]
    public function viewer_show_page_is_readonly_without_manage_actions(): void
    {
        $template = $this->makeSystemPolicy();

        $html = $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Quyền xem', $html);
        $this->assertStringContainsString('Chế độ xem (chỉ đọc)', $html);
        $this->assertStringNotContainsString('Chỉnh sửa', $html);
        $this->assertStringNotContainsString('Clone', $html);
        $this->assertStringNotContainsString('admin.credit-card-policies.edit', $html);
        $this->assertStringNotContainsString('system-policies.clone', $html);
        $this->assertStringContainsString('Lịch sử phiên bản chính sách', $html);
    }

    // =====================================================================
    // Trần hoàn chuyển lên BẬC — create / store version / in-place
    // =====================================================================

    #[Test]
    public function store_version_records_tier_cashback_cap_and_no_policy_cap(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.versions.store', $template), [
                'effective_from' => '2026-11-01',
                'tiers' => [[
                    'name' => 'Bậc nâng cao',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'max_cashback_per_period' => 2000000,
                    'rules' => [[
                        'category_id' => $this->makeSystemCategory()->id,
                        'cashback_percent' => 5,
                        'spend_from' => 0,
                        'spend_to' => null,
                    ]],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.version_no', 2)
            ->assertJsonPath('data.tiers.0.max_cashback_per_period', 2000000);

        $v2 = $template->currentBlueprint();
        $tier = $v2->tiers()->firstOrFail();
        $this->assertSame('2000000.00', $tier->max_cashback_per_period);
        $this->assertNull($v2->max_cashback_total_per_period, 'store version không nhận trần hoàn cấp policy.');
    }

    #[Test]
    public function create_and_store_ignore_legacy_policy_cap_record_tier_cap(): void
    {
        $category = $this->makeSystemCategory();

        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Chính sách cap bậc',
                'description' => 'Trần hoàn nằm ở bậc',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'min_total_spend' => 0,
                'max_cashback_total_per_period' => 999,
                'rounding_mode' => 'floor',
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'max_cashback_per_period' => 1000000,
                    'rules' => [[
                        'category_id' => $category->id,
                        'cashback_percent' => 3,
                        'spend_from' => 0,
                        'spend_to' => null,
                    ]],
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Chính sách cap bậc')
            ->assertJsonPath('data.tiers.0.max_cashback_per_period', 1000000);

        // Trần cấp policy bị BỎ IM LẶNG (whitelist overrides không còn chứa nó).
        $template = PolicyTemplate::query()->findOrFail($response->json('data.id'));
        $bp = $template->defaultBlueprint();
        $this->assertNull($bp->max_cashback_total_per_period, 'Create bỏ qua trần hoàn cấp policy.');
        $this->assertSame('1000000.00', $bp->tiers()->first()->max_cashback_per_period);
    }

    #[Test]
    public function tier_cashback_cap_rejects_negative_values(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Cap âm',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'rules' => [],
                    'max_cashback_per_period' => -1,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiers.0.max_cashback_per_period');
    }

    #[Test]
    public function backfill_copies_legacy_policy_cap_to_single_tier_blueprints_only(): void
    {
        $service = $this->app->make(PolicyService::class);

        // Blueprint hệ thống 1 bậc có trần cũ ở policy.
        $single = $service->createSystemTemplate(
            'Có cap cũ 1 bậc',
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'rounding_mode' => 'floor',
                'max_cashback_total_per_period' => '3000000',
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [[
                        'category_id' => $this->makeSystemCategory()->id,
                        'cashback_percent' => 3,
                        'spend_from' => 0,
                        'spend_to' => null,
                    ]],
                ]],
            ],
            true,
        );
        $singleBp = $single->defaultBlueprint();
        $this->assertSame('3000000.00', $singleBp->max_cashback_total_per_period);
        $this->assertNull($singleBp->tiers()->first()->max_cashback_per_period);

        // Blueprint hệ thống 2 bậc có trần cũ — không thể chia đều → giữ nguyên + báo cáo.
        $multi = $service->createSystemTemplate(
            'Có cap cũ 2 bậc',
            null,
            CarbonImmutable::parse('2026-09-02'),
            [
                'rounding_mode' => 'floor',
                'max_cashback_total_per_period' => '3000000',
                'tiers' => [
                    [
                        'name' => 'Bậc 1',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [[
                            'category_id' => $this->makeSystemCategory()->id,
                            'cashback_percent' => 3,
                            'spend_from' => 0,
                            'spend_to' => null,
                        ]],
                    ],
                    [
                        'name' => 'Bậc 2',
                        'sort_order' => 2,
                        'min_total_spend' => 100000,
                        'max_total_spend' => null,
                        'rules' => [[
                            'category_id' => $this->makeSystemCategory()->id,
                            'cashback_percent' => 5,
                            'spend_from' => 0,
                            'spend_to' => null,
                        ]],
                    ],
                ],
            ],
            true,
        );
        $multiBp = $multi->defaultBlueprint();
        $multiTierFirst = $multiBp->tiers()->orderBy('sort_order')->first();

        $result = $service->backfillSystemBlueprintCaps();

        $this->assertGreaterThanOrEqual(1, $result['backfilled']);
        $this->assertContains((int) $multiBp->id, $result['skipped_multi_tier']);
        $this->assertSame('3000000.00', $singleBp->refresh()->tiers()->first()->max_cashback_per_period);
        $this->assertNull($multiTierFirst->refresh()->max_cashback_per_period, '2 bậc không bị chia đôi cap.');

        // Idempotent: chạy lại không backfill thêm gì.
        $second = $service->backfillSystemBlueprintCaps();
        $this->assertSame(0, $second['backfilled']);
    }

    // =====================================================================
    // Rate limit riêng cho admin (tránh nghẽn user)
    // =====================================================================

    #[Test]
    public function admin_policy_api_is_rate_limited_per_user_with_json_429(): void
    {
        RateLimiter::for('credit-card-admin-api', fn (): Limit => Limit::perMinute(2)
            ->by(request()->user()?->getAuthIdentifier() ?? request()->ip())
            ->response(function ($request, array $headers) {
                return response()->json(['message' => 'Vượt giới hạn tốc độ cho quản trị.'], 429, $headers);
            }));

        $this->makeSystemPolicy();

        $base = route('admin.credit-card-policies.api.index');
        $this->actingAs($this->manager)->getJson($base)->assertOk();
        $this->actingAs($this->manager)->getJson($base)->assertOk();
        $this->actingAs($this->manager)->getJson($base)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Vượt giới hạn tốc độ cho quản trị.');

        // Admin khác vẫn còn quota — đếm theo USER, không theo IP.
        $this->actingAs($this->makeAdmin())->getJson($base)->assertOk();
    }

    // =====================================================================
    // §15 — FALLBACK "📦 CÁC DANH MỤC CÒN LẠI" TRONG EDITOR/VIEW/MIGRATION
    // =====================================================================

    #[Test]
    public function editor_payload_with_a_default_fallback_row_keeps_exactly_one_fallback(): void
    {
        $catA = $this->makeSystemCategory();
        $template = $this->makeSystemPolicy(3.0, $catA->id);
        $v1 = $template->defaultBlueprint();
        $tier = $v1->tiers()->firstOrFail();
        $fallback = $tier->tierCategoryRules()->fallback()->firstOrFail();
        $specific = $tier->tierCategoryRules()->whereNotNull('category_id')->firstOrFail();

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'tiers' => [[
                    'id' => (int) $tier->id,
                    'name' => $tier->name,
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [
                        [
                            'id' => (int) $specific->id,
                            'category_id' => $catA->id,
                            'scope_type' => 'category',
                            'counts_toward_tier_cap' => true,
                            'cashback_percent' => 3,
                        ],
                        [
                            'id' => (int) $fallback->id,
                            'category_id' => null,
                            'scope_type' => 'other',
                            'counts_toward_tier_cap' => false,
                            'cashback_percent' => 0,
                        ],
                    ],
                ]],
            ])
            ->assertOk();

        $fallbacks = PolicyTierCategory::query()->where('tier_id', $tier->id)->fallback()->get();

        $this->assertCount(1, $fallbacks, 'Mỗi bậc có đúng 1 fallback.');
        $this->assertSame((int) $fallback->id, (int) $fallbacks->first()->id, 'Fallback giữ nguyên dòng khi Lưu lại.');
        $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallbacks->first()->scope_type);
        $this->assertNull($fallbacks->first()->category_id);
        $this->assertSame('0.000', $fallbacks->first()->cashback_percent);
        $this->assertFalse((bool) $fallbacks->first()->counts_toward_tier_cap);
    }

    #[Test]
    public function toggling_counts_toward_tier_cap_round_trips_through_the_editor(): void
    {
        $catA = $this->makeSystemCategory();
        $template = $this->makeSystemPolicy(3.0, $catA->id);
        $v1 = $template->defaultBlueprint();
        $tier = $v1->tiers()->firstOrFail();
        $fallback = $tier->tierCategoryRules()->fallback()->firstOrFail();
        $specific = $tier->tierCategoryRules()->whereNotNull('category_id')->firstOrFail();

        $payload = [
            'effective_from' => '2026-09-15',
            'tiers' => [[
                'id' => (int) $tier->id,
                'name' => $tier->name,
                'sort_order' => 1,
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [
                    ['id' => (int) $specific->id, 'category_id' => $catA->id, 'scope_type' => 'category', 'counts_toward_tier_cap' => true, 'cashback_percent' => 3],
                    ['id' => (int) $fallback->id, 'category_id' => null, 'scope_type' => 'other', 'counts_toward_tier_cap' => true, 'cashback_percent' => 0],
                ],
            ]],
        ];

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), $payload)
            ->assertOk();

        $this->assertTrue((bool) $fallback->refresh()->counts_toward_tier_cap, 'Editor bật "tính vào cap" thì lưu đúng true.');

        $payload['tiers'][0]['rules'][1]['counts_toward_tier_cap'] = false;
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), $payload)
            ->assertOk();

        $this->assertFalse((bool) $fallback->refresh()->counts_toward_tier_cap, 'Tắt lại thì lưu về false.');
    }

    #[Test]
    public function omitting_the_fallback_from_the_payload_recreates_not_deletes_it(): void
    {
        $catA = $this->makeSystemCategory();
        $template = $this->makeSystemPolicy(3.0, $catA->id);
        $v1 = $template->defaultBlueprint();
        $tier = $v1->tiers()->firstOrFail();
        $fallback = $tier->tierCategoryRules()->fallback()->firstOrFail();
        $specific = $tier->tierCategoryRules()->whereNotNull('category_id')->firstOrFail();

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'tiers' => [[
                    'id' => (int) $tier->id,
                    'name' => $tier->name,
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [[
                        'id' => (int) $specific->id,
                        'category_id' => $catA->id,
                        'scope_type' => 'category',
                        'counts_toward_tier_cap' => true,
                        'cashback_percent' => 3,
                    ]],
                ]],
            ])
            ->assertOk();

        $fallbacks = PolicyTierCategory::query()->where('tier_id', $tier->id)->fallback()->get();

        $this->assertCount(1, $fallbacks, 'Fallback vắng trong payload vẫn tồn tại.');
        $this->assertSame((int) $fallback->id, (int) $fallbacks->first()->id, 'Fallback không bị xoá-tạo lại.');
        $this->assertSame('0.000', $fallbacks->first()->cashback_percent);
    }

    #[Test]
    public function two_fallback_rows_in_a_payload_merge_into_single_fallback(): void
    {
        $catA = $this->makeSystemCategory();
        $template = $this->makeSystemPolicy(3.0, $catA->id);
        $v1 = $template->defaultBlueprint();
        $tier = $v1->tiers()->firstOrFail();
        $fallback = $tier->tierCategoryRules()->fallback()->firstOrFail();

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'tiers' => [[
                    'id' => (int) $tier->id,
                    'name' => $tier->name,
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [
                        ['id' => (int) $fallback->id, 'category_id' => null, 'scope_type' => 'other', 'counts_toward_tier_cap' => false, 'cashback_percent' => 2],
                        ['category_id' => null, 'scope_type' => 'other', 'counts_toward_tier_cap' => false, 'cashback_percent' => 9],
                    ],
                ]],
            ])
            ->assertOk();

        $fallbacks = PolicyTierCategory::query()->where('tier_id', $tier->id)->fallback()->get();

        $this->assertCount(1, $fallbacks, 'Hai dòng other trong payload chỉ còn một fallback.');
        $this->assertEqualsWithDelta(2.0, (float) $fallbacks->first()->cashback_percent, 0.0001, 'Dòng đầu tiên thắng làm cấu hình.');
    }

    #[Test]
    public function a_fallback_payload_carrying_a_category_id_is_force_nulled(): void
    {
        $catA = $this->makeSystemCategory();
        $template = $this->makeSystemPolicy(3.0, $catA->id);
        $v1 = $template->defaultBlueprint();
        $tier = $v1->tiers()->firstOrFail();
        $fallback = $tier->tierCategoryRules()->fallback()->firstOrFail();

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'tiers' => [[
                    'id' => (int) $tier->id,
                    'name' => $tier->name,
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [[
                        'id' => (int) $fallback->id,
                        'category_id' => $catA->id,
                        'scope_type' => 'other',
                        'counts_toward_tier_cap' => false,
                        'cashback_percent' => 0,
                    ]],
                ]],
            ])
            ->assertOk();

        $this->assertNull($fallback->refresh()->category_id, 'Fallback luôn có category_id = NULL dù payload có truyền.');
        $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallback->scope_type);
    }

    #[Test]
    public function a_system_policy_can_target_a_system_combo(): void
    {
        $combo = $this->makeSystemCombo();

        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Chính sách theo combo',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'rules' => [[
                        'scope_type' => 'category',
                        'combo_id' => $combo->id,
                        'cashback_percent' => 5,
                    ]],
                ]],
            ])
            ->assertCreated();

        $rule = PolicyTierCategory::query()->where('combo_id', $combo->id)->firstOrFail();

        $this->assertNull($rule->category_id, 'Rule combo KHÔNG được có category_id.');
        $this->assertTrue($rule->isComboSpecific());
        $this->assertSame('combo', $response->json('data.tiers.0.rules.0.target_type'));
        $this->assertSame($combo->name, $response->json('data.tiers.0.rules.0.combo_name'));
    }

    #[Test]
    public function create_system_policy_rejects_a_user_combo(): void
    {
        $userCombo = $this->makeUserComboForOwner();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Dính combo riêng',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'rules' => [[
                        'scope_type' => 'category',
                        'combo_id' => $userCombo->id,
                        'cashback_percent' => 5,
                    ]],
                ]],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_rule_cannot_carry_both_a_category_and_a_combo_in_a_system_policy(): void
    {
        $category = $this->makeSystemCategory();
        $combo = $this->makeSystemCombo();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Mục tiêu mơ hồ',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'rules' => [[
                        'scope_type' => 'category',
                        'category_id' => $category->id,
                        'combo_id' => $combo->id,
                        'cashback_percent' => 5,
                    ]],
                ]],
            ])
            ->assertStatus(422);

        $this->assertSame(0, PolicyTierCategory::query()->where('combo_id', $combo->id)->count());
    }

    #[Test]
    public function combo_rule_is_hydrated_in_the_editor_page(): void
    {
        $combo = $this->makeSystemCombo();

        $template = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.store'), [
                'name' => 'Chính sách combo editor',
                'effective_from' => '2026-09-01',
                'status' => 'published',
                'tiers' => [[
                    'name' => 'Bậc 1',
                    'rules' => [[
                        'scope_type' => 'category',
                        'combo_id' => $combo->id,
                        'cashback_percent' => 2,
                    ]],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', $template))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('\u0022combo_id\u0022:'.$combo->id, $html);
        $this->assertStringContainsString('\u0022target_type\u0022:\u0022combo\u0022', $html);
    }

    #[Test]
    public function updating_a_version_can_switch_a_rule_to_a_combo(): void
    {
        $combo = $this->makeSystemCombo();
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();
        $tier = $v1->tiers()->firstOrFail();
        $specific = $tier->tierCategoryRules()->whereNotNull('category_id')->firstOrFail();

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'tiers' => [[
                    'id' => (int) $tier->id,
                    'name' => $tier->name,
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [[
                        'id' => (int) $specific->id,
                        'scope_type' => 'category',
                        'combo_id' => $combo->id,
                        'cashback_percent' => 4,
                    ]],
                ]],
            ])
            ->assertOk();

        $specific->refresh();
        $this->assertNull($specific->category_id);
        $this->assertSame($combo->id, (int) $specific->combo_id);
        $this->assertTrue($specific->isComboSpecific());
    }

    #[Test]
    public function fallback_is_hydrated_in_the_editor_page_for_the_target_version(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();

        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', [$template->id, 'version' => $v1->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('\u0022scope_type\u0022:\u0022other\u0022', $html, 'x-data phải chứa rule fallback.');
        $this->assertStringContainsString('\u0022category_id\u0022:null', $html);
        $this->assertStringContainsString('\u0022counts_toward_tier_cap\u0022:false', $html);
    }

    #[Test]
    public function a_new_tier_without_rules_in_a_payload_gets_the_default_fallback(): void
    {
        $template = $this->makeSystemPolicy(3.0);
        $v1 = $template->defaultBlueprint();
        $specific = $v1->tiers()->firstOrFail()->tierCategoryRules()->whereNotNull('category_id')->firstOrFail();

        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-15',
                'tiers' => [
                    [
                        'id' => (int) $v1->tiers()->firstOrFail()->id,
                        'name' => 'Bậc cơ bản',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => 5000000,
                        'rules' => [[
                            'id' => (int) $specific->id,
                            'category_id' => (int) $specific->category_id,
                            'scope_type' => 'category',
                            'counts_toward_tier_cap' => true,
                            'cashback_percent' => 3,
                        ]],
                    ],
                    [
                        'name' => 'Bậc mới',
                        'sort_order' => 2,
                        'min_total_spend' => 5000000,
                        'max_total_spend' => null,
                        'rules' => [],
                    ],
                ],
            ])
            ->assertOk();

        $newTier = $v1->tiers()->orderBy('sort_order')->orderBy('id')->get()->last();
        $fallbacks = PolicyTierCategory::query()->where('tier_id', $newTier->id)->fallback()->get();

        $this->assertCount(1, $fallbacks, 'Bậc mới không có rule payload vẫn được tạo fallback mặc định.');
        $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallbacks->first()->scope_type);
        $this->assertNull($fallbacks->first()->category_id);
        $this->assertSame('0.000', $fallbacks->first()->cashback_percent);
        $this->assertFalse((bool) $fallbacks->first()->counts_toward_tier_cap);
    }

    #[Test]
    public function show_page_renders_the_fallback_with_its_default_chip(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.show', $template))
            ->assertOk()
            ->assertSee('📦 Các danh mục còn lại', false)
            ->assertSee('Quy tắc mặc định', false)
            ->assertSee('Tính vào cap Bậc', false);
    }

    #[Test]
    public function editor_page_marks_the_fallback_as_non_removable(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', $template))
            ->assertOk()
            ->assertSee('📦 Các danh mục còn lại', false)
            ->assertSee('Tính vào giới hạn hoàn tiền của bậc', false)
            ->assertSee('Mỗi bậc chỉ có', false);
    }

    #[Test]
    public function editor_offers_only_the_three_target_scopes_and_no_source_selector(): void
    {
        $template = $this->makeSystemPolicy(3.0);

        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.edit', $template))
            ->assertOk()
            ->getContent();

        // "Phạm vi danh mục" gộp 3 trạng thái target: danh mục | combo | còn lại.
        $this->assertStringContainsString('>Phạm vi danh mục<', $html);
        $this->assertStringContainsString('<option value="category">Danh mục cụ thể</option>', $html);
        $this->assertStringContainsString('<option value="combo">🍱 Combo danh mục</option>', $html);
        $this->assertStringContainsString('<option value="other">📦 Danh mục còn lại</option>', $html);

        // Select dùng state `target_scope` (3 trạng thái gộp), không tách 2 dropdown cũ.
        $this->assertStringContainsString('x-model="rule.target_scope"', $html);
        $this->assertStringNotContainsString('onScopeChange', $html);
        $this->assertStringNotContainsString('onTargetTypeChange', $html);

        // "Loại danh mục" đã bị gỡ hoàn toàn (cả label lẫn state).
        $this->assertStringNotContainsString('Loại danh mục', $html);
        $this->assertStringNotContainsString('Loại mục tiêu', $html);
        $this->assertStringNotContainsString('category_scope', $html);
    }

    #[Test]
    public function in_place_save_writes_the_three_target_states_as_mutually_exclusive_targets(): void
    {
        $catA = $this->makeSystemCategory();
        $catB = $this->makeSystemCategory();
        $combo = $this->makeSystemCombo();
        $template = $this->makeSystemPolicy(3.0, $catA->id);
        $v1 = $template->currentBlueprint();
        $tier = $v1->tiers()->firstOrFail();

        // Editor gửi đúng 3 trạng thái: danh mục cụ thể | combo | còn lại.
        $this->actingAs($this->manager)
            ->patchJson(route('admin.credit-card-policies.api.versions.update', [$template->id, $v1->id]), [
                'effective_from' => '2026-09-01',
                'tiers' => [[
                    'id' => (int) $tier->id,
                    'name' => 'Bậc cơ bản',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [
                        ['scope_type' => 'category', 'category_id' => $catA->id, 'combo_id' => null, 'cashback_percent' => 3],
                        ['scope_type' => 'category', 'category_id' => null, 'combo_id' => $combo->id, 'cashback_percent' => 5],
                        ['scope_type' => 'other', 'category_id' => null, 'combo_id' => null, 'cashback_percent' => 0],
                    ],
                ]],
            ])
            ->assertOk();

        $rules = $tier->refresh()->tierCategoryRules()->orderBy('id')->get();

        $byCategory = $rules->firstWhere('category_id', $catA->id);
        $this->assertNotNull($byCategory, 'Trạng thái "Danh mục cụ thể" phải ghi category_id.');
        $this->assertSame(PolicyTierCategory::SCOPE_CATEGORY, $byCategory->scope_type);
        $this->assertNull($byCategory->combo_id, 'Danh mục cụ thể KHÔNG mang combo_id.');

        $byCombo = $rules->firstWhere('combo_id', $combo->id);
        $this->assertNotNull($byCombo, 'Trạng thái "Combo danh mục" phải ghi combo_id.');
        $this->assertSame(PolicyTierCategory::SCOPE_CATEGORY, $byCombo->scope_type);
        $this->assertNull($byCombo->category_id, 'Combo KHÔNG mang category_id.');

        $fallback = $rules->firstWhere('scope_type', PolicyTierCategory::SCOPE_OTHER);
        $this->assertNotNull($fallback, 'Trạng thái "Danh mục còn lại" phải tồn tại.');
        $this->assertNull($fallback->category_id);
        $this->assertNull($fallback->combo_id);

        $this->assertCount(3, $rules);
        $this->assertSame(1, $rules->where('scope_type', PolicyTierCategory::SCOPE_OTHER)->count(), 'Mỗi bậc chỉ một fallback.');
    }

    #[Test]
    public function backfill_creates_exactly_one_fallback_per_bare_tier(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $policy = $this->makePolicyForCard($card, [
            ['name' => 'Bậc 1', 'min' => 0, 'max' => 5000000],
            ['name' => 'Bậc 2', 'min' => 5000000, 'max' => null],
        ], []);

        $result = app(CategoryRuleService::class)->backfillFallbackRules();

        $this->assertSame(2, $result['backfilled']);
        $this->assertSame(0, $result['existing']);
        $this->assertSame([], $result['anomalies']);

        foreach ($policy->tiers as $tier) {
            $fallbacks = PolicyTierCategory::query()->where('tier_id', $tier->id)->fallback()->get();

            $this->assertCount(1, $fallbacks, 'Mỗi bậc có đúng 1 fallback sau backfill.');
            $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallbacks->first()->scope_type);
            $this->assertNull($fallbacks->first()->category_id);
            $this->assertSame('0.000', $fallbacks->first()->cashback_percent);
            $this->assertFalse((bool) $fallbacks->first()->counts_toward_tier_cap);
        }
    }

    #[Test]
    public function backfill_is_idempotent(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $policy = $this->makePolicyForCard($card, [['name' => 'Bậc 1', 'min' => 0, 'max' => null]], []);

        $service = app(CategoryRuleService::class);

        $first = $service->backfillFallbackRules();
        $second = $service->backfillFallbackRules();

        $this->assertSame(1, $first['backfilled']);
        $this->assertSame(1, $second['existing']);
        $this->assertSame(0, $second['backfilled']);
        $this->assertSame(
            1,
            PolicyTierCategory::query()->where('tier_id', $policy->tiers->first()->id)->fallback()->count()
        );
    }

    #[Test]
    public function backfill_flags_anomalies_without_touching_existing_rules(): void
    {
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $category = $this->makeSystemCategory();
        $policy = $this->makePolicyForCard($card, [['name' => 'Bậc 1', 'min' => 0, 'max' => null]], [
            ['category_id' => $category->id, 'percent' => '2.000'],
        ]);

        $tier = $policy->tiers->first();
        $specific = $tier->tierCategoryRules()->whereNotNull('category_id')->firstOrFail();

        PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $category->id,
            'scope_type' => PolicyTierCategory::SCOPE_OTHER,
            'counts_toward_tier_cap' => false,
            'sort_order' => 5,
            'spend_from' => 5000000,
            'cashback_percent' => '0.000',
        ]);
        PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => null,
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'counts_toward_tier_cap' => true,
            'sort_order' => 6,
            'cashback_percent' => '1.000',
        ]);

        $result = app(CategoryRuleService::class)->backfillFallbackRules();

        $this->assertSame(0, $result['backfilled'], 'Tier đã có fallback không backfill lại.');

        $issueNames = array_column($result['anomalies'], 'issue');
        sort($issueNames);
        $this->assertSame(['category_rule_without_category_id', 'fallback_has_category_id'], $issueNames);
        $this->assertSame([(int) $tier->id], $result['manual_review']);

        $this->assertSame((int) $specific->id, (int) $specific->refresh()->id, 'Rule cũ không bị chạm.');
        $this->assertSame('2.000', $specific->cashback_percent);
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * Chính sách hệ thống hoàn chỉnh (template + blueprint v1 + bậc + rule).
     */
    private function makeSystemPolicy(float $percent = 3.0, ?int $categoryId = null): PolicyTemplate
    {
        $categoryId ??= $this->makeSystemCategory()->id;

        return $this->app->make(PolicyService::class)->createSystemTemplate(
            'Chính sách hệ thống '.uniqid(),
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'tiers' => [
                    [
                        'name' => 'Bậc cơ bản',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [
                            [
                                'category_id' => $categoryId,
                                'cashback_percent' => $percent,
                                'spend_from' => 0,
                                'spend_to' => null,
                            ],
                        ],
                    ],
                ],
            ],
            true,
        );
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return $admin;
    }

    private function makeSystemCombo(): CategoryCombo
    {
        return app(CategoryComboService::class)->createSystemCombo([
            'name' => 'Combo hệ thống '.uniqid(),
            'category_ids' => [$this->makeSystemCategory()->id],
        ]);
    }

    private function makeUserComboForOwner(): CategoryCombo
    {
        return app(CategoryComboService::class)->createUserCombo($this->owner->id, [
            'name' => 'Combo riêng '.uniqid(),
            'category_ids' => [$this->makeSystemCategory()->id],
        ]);
    }
}
