<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\PolicyService;
use App\Support\CreditCard\SystemPolicyPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Tính năng "📋 Clone chính sách hoàn tiền hệ thống" — luồng EDITOR.
 *
 * Admin bấm [📋 Clone] → MỞ trang "Chỉnh sửa" hydrate từ nguồn (GET, KHÔNG ghi
 * DB) → chỉnh toàn bộ cấu hình → bấm [Lưu thành chính sách mới] → trình duyệt
 * POST JSON payload editor tới API clone → backend tạo chính sách hệ thống MỚI
 * (template + mọi blueprint + tier + rule) trong MỘT transaction.
 *
 * Bảo vệ cốt lõi:
 *   1. Phân quyền: đọc `credit-cards.view`, ghi `credit-cards.manage` — user
 *      thường (kể cả viewer) 403 ngay ở middleware cho cả GET lẫn POST.
 *   2. GET clone editor KHÔNG tạo bất kỳ bản ghi DB nào.
 *   3. Deep clone nguyên tử: trùng `version_no`/`sort_order`, mọi identity MỚI,
 *      `default_version_id` REMAP, `is_locked=false`, fail giữa chừng → rollback.
 *   4. Nguồn KHÔNG đổi: sửa/xoá tier-rule ở bản clone không chạm chính sách gốc.
 *   5. KHÔNG chạm dữ liệu nghiệp vụ khác (giao dịch, kỳ sao kê, thẻ, Category
 *      Master); danh mục payload phải thuộc scope hệ thống.
 */
class SystemPolicyCloneTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private PolicyService $service;

    private User $member;

    private User $viewer;

    private User $manager;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->service = $this->app->make(PolicyService::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        $admin = Role::create(['name' => 'Admin']);
        $admin->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);

        $this->member = User::factory()->create();
        $this->viewer = User::factory()->create()->givePermissionTo('credit-cards.view');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('Admin');
    }

    // =====================================================================
    // GET editor clone — mở trang, không ghi DB
    // =====================================================================

    #[Test]
    public function manager_can_open_the_clone_editor_page(): void
    {
        $source = $this->makeSourcePolicy();
        // Tên ASCII để khẳng định name-prefill an toàn (JSON `@js` escape unicode).
        $source->forceFill(['name' => 'MB Ultimate JCB'])->save();

        $html = $this->actingAs($this->manager)
            ->get(route('admin.credit-card.system-policies.clone', $source))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('📋 Clone chính sách hoàn tiền hệ thống', $html);
        $this->assertStringContainsString('Bản sao từ', $html);
        $this->assertStringContainsString('MB Ultimate JCB - Copy', $html);
        $this->assertStringContainsString('Lưu thành chính sách mới', $html);
    }

    #[Test]
    public function opening_the_clone_editor_writes_no_database_rows(): void
    {
        $source = $this->makeSourcePolicy();

        $templateBefore = PolicyTemplate::count();
        $policyBefore = Policy::count();
        $tierBefore = PolicyTier::count();
        $ruleBefore = PolicyTierCategory::count();
        $categoryBefore = Category::count();

        $this->actingAs($this->manager)
            ->get(route('admin.credit-card.system-policies.clone', $source))
            ->assertOk();

        $this->assertSame($templateBefore, PolicyTemplate::count());
        $this->assertSame($policyBefore, Policy::count());
        $this->assertSame($tierBefore, PolicyTier::count());
        $this->assertSame($ruleBefore, PolicyTierCategory::count());
        $this->assertSame($categoryBefore, Category::count());
    }

    #[Test]
    public function clone_editor_is_403_for_users_without_manage_permission(): void
    {
        $source = $this->makeSourcePolicy();

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card.system-policies.clone', $source))
            ->assertForbidden();

        $this->actingAs($this->member)
            ->get(route('admin.credit-card.system-policies.clone', $source))
            ->assertForbidden();
    }

    #[Test]
    public function clone_editor_for_empty_policy_redirects_back_without_creating_anything(): void
    {
        $empty = $this->makeSystemTemplate([
            'name' => 'Chính sách trống',
            'is_builtin' => false,
        ]);
        $before = PolicyTemplate::count();

        $this->actingAs($this->manager)
            ->from(route('admin.credit-card-policies.index'))
            ->get(route('admin.credit-card.system-policies.clone', $empty))
            ->assertRedirect(route('admin.credit-card-policies.index'))
            ->assertSessionHas('error');

        $this->assertSame($before, PolicyTemplate::count());
    }

    #[Test]
    public function clone_button_is_hidden_for_users_without_manage_permission(): void
    {
        $this->makeSourcePolicy();

        $viewerHtml = $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('📋 Clone', $viewerHtml);

        $managerHtml = $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('📋 Clone', $managerHtml);
    }

    // =====================================================================
    // POST JSON API clone — endpoint + phân quyền
    // =====================================================================

    #[Test]
    public function admin_posts_clone_payload_and_gets_a_new_policy_with_redirect(): void
    {
        $source = $this->makeSourcePolicy();

        [$response, $last] = $this->postCloneEditor($source, [], 'Chính sách 2026 mới');
        $cloned = $this->newestSystemTemplate($last);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Chính sách 2026 mới')
            ->assertJsonPath('data.is_system', true)
            ->assertJsonPath('redirect', route('admin.credit-card-policies.edit', $cloned->id))
            ->assertSessionHas('success', sprintf('Đã tạo chính sách "%s" từ bản sao "%s".', 'Chính sách 2026 mới', $source->name));
    }

    #[Test]
    public function non_admin_cannot_call_the_clone_api(): void
    {
        $source = $this->makeSourcePolicy();
        $payload = $this->editorPayload($source);

        $before = PolicyTemplate::count();

        $this->actingAs($this->viewer)
            ->postJson(route('admin.credit-card-policies.api.clone', $source), $payload)
            ->assertForbidden();

        $this->actingAs($this->member)
            ->postJson(route('admin.credit-card-policies.api.clone', $source), $payload)
            ->assertForbidden();

        $this->assertSame($before, PolicyTemplate::count());
    }

    #[Test]
    public function clone_api_requires_a_name(): void
    {
        $source = $this->makeSourcePolicy();
        $before = PolicyTemplate::count();

        $payload = $this->editorPayload($source);
        unset($payload['name']);

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.clone', $source), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame($before, PolicyTemplate::count());
    }

    #[Test]
    public function clone_api_rejects_whitespace_only_name(): void
    {
        $source = $this->makeSourcePolicy();
        $before = PolicyTemplate::count();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.clone', $source), $this->editorPayload($source, '   '))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame($before, PolicyTemplate::count());
    }

    #[Test]
    public function clone_api_rejects_an_invalid_category_id(): void
    {
        $source = $this->makeSourcePolicy();
        $before = PolicyTemplate::count();

        $payload = $this->editorPayload($source);
        $payload['tiers'][0]['rules'][0]['category_id'] = 999999;

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.clone', $source), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiers.0.rules.0.category_id');

        $this->assertSame($before, PolicyTemplate::count());
    }

    #[Test]
    public function clone_api_rejects_cashback_percent_above_100(): void
    {
        $source = $this->makeSourcePolicy();
        $before = PolicyTemplate::count();

        $payload = $this->editorPayload($source);
        $payload['tiers'][0]['rules'][0]['cashback_percent'] = 150;

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.clone', $source), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiers.0.rules.0.cashback_percent');

        $this->assertSame($before, PolicyTemplate::count());
    }

    #[Test]
    public function clone_api_rejects_a_user_scoped_category_in_the_payload(): void
    {
        $source = $this->makeSourcePolicy();
        $userCategory = $this->makeUserCategory($this->member->id);
        $before = PolicyTemplate::count();

        $payload = $this->editorPayload($source);
        $payload['tiers'][0]['rules'][0]['category_id'] = $userCategory->id;

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.clone', $source), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'chỉ được dùng danh mục hệ thống'));

        $this->assertSame($before, PolicyTemplate::count());
    }

    // =====================================================================
    // Template clone
    // =====================================================================

    #[Test]
    public function clone_creates_a_new_independent_template_with_payload_meta(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [
            'description' => 'Mô tả đổi từ editor',
            'status' => 'draft',
        ], 'Bản sao độc lập');

        $this->assertNotSame($source->id, $cloned->id);
        $this->assertTrue($cloned->isSystemScope());
        $this->assertSame(PolicyTemplate::SYSTEM_OWNER_ID, (int) $cloned->owner_user_id);
        $this->assertFalse((bool) $cloned->is_builtin);
        $this->assertSame('Mô tả đổi từ editor', $cloned->description);
        $this->assertFalse((bool) $cloned->is_active, 'status draft trong payload => not active');
        $this->assertGreaterThan((int) $source->sort_order, (int) $cloned->sort_order);
    }

    #[Test]
    public function clone_uses_the_admin_edited_name(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Tên mới do admin nhập');

        $this->assertSame('Tên mới do admin nhập', $cloned->name);
        $this->assertNotSame($source->name, $cloned->name);
    }

    #[Test]
    public function clone_creates_an_unique_slug(): void
    {
        $this->makeSourcePolicy();

        $first = $this->cloneFromEditor($this->makeSourcePolicy(), [], 'Chính sách trùng tên');
        $second = $this->cloneFromEditor($this->makeSourcePolicy(), [], 'Chính sách trùng tên');

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertStringStartsWith('chinh-sach-trung-ten', $first->slug);
        $this->assertStringStartsWith('chinh-sach-trung-ten-', $second->slug);
    }

    // =====================================================================
    // Deep clone — mọi thành phần
    // =====================================================================

    #[Test]
    public function clone_copies_all_versions_with_the_same_order(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Đủ phiên bản');

        $this->assertSame(
            $source->blueprints()->pluck('version_no')->all(),
            $cloned->blueprints()->pluck('version_no')->all(),
        );
        $this->assertSame([1, 2, 3], $cloned->blueprints()->pluck('version_no')->all());
    }

    #[Test]
    public function cloned_versions_get_fresh_ids(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Version id mới');

        $this->assertEmpty(array_intersect(
            $source->blueprints()->pluck('id')->all(),
            $cloned->blueprints()->pluck('id')->all(),
        ));
    }

    #[Test]
    public function clone_preserves_version_numbers_and_chain(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Giữ chain version');

        $blueprints = $cloned->blueprints()->orderBy('version_no')->get();
        $root = $blueprints->firstOrFail();

        $this->assertSame(1, (int) $root->version_no);
        $this->assertSame((int) $root->id, (int) $root->root_policy_id);
        $this->assertNull($root->user_card_id);

        foreach ($blueprints->slice(1) as $blueprint) {
            $this->assertSame((int) $root->id, (int) $blueprint->root_policy_id);
        }
    }

    #[Test]
    public function non_edited_blueprints_preserve_business_metadata(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Giữ metadata version');

        foreach ([1, 3] as $versionNo) {
            $sourceBlueprint = $this->blueprintVersion($source, $versionNo);
            $copy = $this->blueprintVersion($cloned, $versionNo);

            $this->assertSame((string) $sourceBlueprint->status, (string) $copy->status);
            $this->assertSame($sourceBlueprint->effective_from->toDateString(), $copy->effective_from->toDateString());
            $this->assertSame($sourceBlueprint->effective_to?->toDateString(), $copy->effective_to?->toDateString());
            $this->assertSame((string) $sourceBlueprint->min_total_spend, (string) $copy->min_total_spend);
            $this->assertSame((string) $sourceBlueprint->rounding_mode, (string) $copy->rounding_mode);
            $this->assertSame((string) $sourceBlueprint->max_cashback_total_per_period, (string) $copy->max_cashback_total_per_period);
            $this->assertSame((string) $sourceBlueprint->note, (string) $copy->note);

            $sourceTiers = $sourceBlueprint->tiers()->orderBy('sort_order')->get();
            $copyTiers = $copy->tiers()->orderBy('sort_order')->get();
            $this->assertCount($sourceTiers->count(), $copyTiers);
            $this->assertSame(
                $sourceTiers->map(fn ($t) => (string) $t->max_cashback_per_period)->all(),
                $copyTiers->map(fn ($t) => (string) $t->max_cashback_per_period)->all(),
            );
        }
    }

    #[Test]
    public function edited_blueprint_gets_the_payload_configuration(): void
    {
        $source = $this->makeSourcePolicy();
        $sourceV2 = $this->blueprintVersion($source, 2);

        // Payload thay toàn bộ cấu hình VERSION 2 (default — version đang edit).
        $overrides = [
            'effective_from' => '2027-01-01',
            'min_total_spend' => 1000,
            'rounding_mode' => 'ceil',
            'tiers' => [
                [
                    'name' => 'Bậc chi tiêu 5% của bản sao',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'max_cashback_per_period' => 9000000,
                    'rules' => [
                        [
                            'category_id' => $sourceV2->tiers()->orderBy('sort_order')->first()->tierCategoryRules()->first()->category_id,
                            'cashback_percent' => 5,
                            'spend_from' => 0,
                            'spend_to' => null,
                        ],
                    ],
                ],
            ],
        ];

        [$response, $last] = $this->postCloneEditor($source, $overrides, 'Sửa v2 từ editor');
        $response->assertCreated();
        $cloned = $this->newestSystemTemplate($last);

        $cloneV2 = $this->blueprintVersion($cloned, 2);

        $this->assertSame('2027-01-01', $cloneV2->effective_from->toDateString());
        $this->assertSame('1000.00', number_format((float) $cloneV2->min_total_spend, 2, '.', ''));
        $this->assertSame('9000000.00', $cloneV2->tiers()->first()->max_cashback_per_period);
        $this->assertSame((string) $sourceV2->max_cashback_total_per_period, (string) $cloneV2->max_cashback_total_per_period, 'Cột legacy vẫn được copy (backward compat), không gửi trong payload editor.');
        $this->assertSame('ceil', $cloneV2->rounding_mode);
        $this->assertCount(1, $cloneV2->tiers()->get());
        $this->assertSame('Bậc chi tiêu 5% của bản sao', $cloneV2->tiers()->first()->name);
        $this->assertSame(5.0, (float) $cloneV2->tiers()->first()->tierCategoryRules()->first()->cashback_percent);
        $this->assertSame($sourceV2->tiers()->count(), $this->blueprintVersion($source, 2)->refresh()->tiers()->count(), 'Nguồn v2 không bị payload chạm tới.');
    }

    #[Test]
    public function cloned_versions_are_unlocked_even_when_source_versions_are(): void
    {
        $source = $this->makeSourcePolicy();

        $source->blueprints()->where('version_no', 2)->first()
            ->forceFill(['is_locked' => true])->save();

        $cloned = $this->cloneFromEditor($source, [], 'Clone không khoá');

        foreach ($cloned->blueprints()->get() as $blueprint) {
            $this->assertFalse((bool) $blueprint->is_locked);
        }

        $this->assertTrue((bool) $source->blueprints()->where('version_no', 2)->first()->refresh()->is_locked);
    }

    #[Test]
    public function clone_copies_all_tiers_per_version(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Đủ all bậc');

        foreach ([1, 2, 3] as $versionNo) {
            $sourceTiers = $this->blueprintVersion($source, $versionNo)->tiers()->orderBy('sort_order')->get();
            $cloneTiers = $this->blueprintVersion($cloned, $versionNo)->tiers()->orderBy('sort_order')->get();

            $this->assertCount($sourceTiers->count(), $cloneTiers);
            $this->assertSame(
                $sourceTiers->map(fn ($t) => [(string) $t->name, (int) $t->sort_order, (string) $t->min_total_spend, (string) $t->max_total_spend, (string) $t->max_cashback_per_period])->all(),
                $cloneTiers->map(fn ($t) => [(string) $t->name, (int) $t->sort_order, (string) $t->min_total_spend, (string) $t->max_total_spend, (string) $t->max_cashback_per_period])->all(),
            );
        }
    }

    #[Test]
    public function cloned_tiers_get_fresh_ids(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Tier id mới');

        $sourceIds = PolicyTier::whereIn('policy_id', $source->blueprints()->pluck('id'))->pluck('id')->all();
        $cloneIds = PolicyTier::whereIn('policy_id', $cloned->blueprints()->pluck('id'))->pluck('id')->all();

        $this->assertCount(5, $cloneIds);
        $this->assertEmpty(array_intersect($sourceIds, $cloneIds));
    }

    #[Test]
    public function clone_copies_all_category_rules_per_tier(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Đủ all quy tắc');

        foreach ([1, 2, 3] as $versionNo) {
            $sourceTiers = $this->blueprintVersion($source, $versionNo)->tiers()->orderBy('sort_order')->get();
            $cloneTiers = $this->blueprintVersion($cloned, $versionNo)->tiers()->orderBy('sort_order')->get();

            foreach ($sourceTiers as $index => $sourceTier) {
                $sourceRules = $sourceTier->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->get();
                $cloneRules = $cloneTiers[$index]->tierCategoryRules()->orderBy('sort_order')->orderBy('id')->get();

                $this->assertCount($sourceRules->count(), $cloneRules);
                $this->assertSame(
                    $sourceRules->map(fn ($r) => $this->ruleSignature($r))->all(),
                    $cloneRules->map(fn ($r) => $this->ruleSignature($r))->all(),
                );
            }
        }
    }

    #[Test]
    public function cloned_category_rules_get_fresh_ids(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Rule id mới');

        $sourceIds = PolicyTierCategory::whereIn('tier_id', PolicyTier::whereIn('policy_id', $source->blueprints()->pluck('id'))->pluck('id'))->pluck('id')->all();
        $cloneIds = PolicyTierCategory::whereIn('tier_id', PolicyTier::whereIn('policy_id', $cloned->blueprints()->pluck('id'))->pluck('id'))->pluck('id')->all();

        $this->assertCount(count($sourceIds), $cloneIds, 'Clone giữ nguyên số rule (kể cả fallback) với id hoàn toàn mới.');
        $this->assertEmpty(array_intersect($sourceIds, $cloneIds));
    }

    #[Test]
    public function category_ids_are_preserved_and_category_master_is_not_duplicated(): void
    {
        $source = $this->makeSourcePolicy();
        $categoryCount = Category::count();

        $cloned = $this->cloneFromEditor($source, [], 'Giữ category_id');

        foreach ([1, 2, 3] as $versionNo) {
            $sourceCategories = $this->blueprintVersion($source, $versionNo)->tiers()->get()
                ->flatMap(fn ($tier) => $tier->tierCategoryRules()->pluck('category_id'))
                ->sort()
                ->values()
                ->all();
            $cloneCategories = $this->blueprintVersion($cloned, $versionNo)->tiers()->get()
                ->flatMap(fn ($tier) => $tier->tierCategoryRules()->pluck('category_id'))
                ->sort()
                ->values()
                ->all();

            $this->assertSame($sourceCategories, $cloneCategories);
        }

        $this->assertSame($categoryCount, Category::count());
    }

    #[Test]
    public function default_version_id_is_remapped_to_the_edited_version(): void
    {
        $source = $this->makeSourcePolicy();
        $sourceDefault = $source->defaultBlueprint();
        $this->assertSame(2, (int) $sourceDefault?->version_no);

        $cloned = $this->cloneFromEditor($source, [], 'Remap default');

        $cloneDefault = $cloned->defaultBlueprint();

        $this->assertNotNull($cloneDefault);
        $this->assertSame(2, (int) $cloneDefault->version_no);
        $this->assertNotSame((int) $sourceDefault->id, (int) $cloneDefault->id);
        $this->assertContains((int) $cloneDefault->id, $cloned->blueprints()->pluck('id')->all());
        $this->assertSame((int) $cloned->default_version_id, (int) $cloneDefault->id);
        $this->assertSame([1, 2, 3], $cloned->blueprints()->pluck('version_no')->all(), 'Bản clone giữ nguyên đầy đủ chuỗi version.');
    }

    #[Test]
    public function source_policy_is_untouched_after_clone(): void
    {
        $source = $this->makeSourcePolicy();

        $before = $this->snapshot($source);

        $this->cloneFromEditor($source, [], 'Không chạm nguồn');

        $this->assertSame($before, $this->snapshot($source));
    }

    // =====================================================================
    // Độc lập hoàn toàn (bảo vệ cốt lõi §13)
    // =====================================================================

    #[Test]
    public function editing_tier_in_clone_does_not_affect_source(): void
    {
        $source = $this->makeSourcePolicy();
        $cloned = $this->cloneFromEditor($source, [], 'Sửa tier bản clone');

        $before = $this->snapshot($source);

        $cloneV3 = $this->blueprintVersion($cloned, 3);
        $categoryId = $cloneV3->tiers()->orderBy('sort_order')->first()->tierCategoryRules()->first()->category_id;

        $this->service->updateSystemVersion($cloned, $cloneV3, [
            'tiers' => [[
                'name' => 'Bậc VIP của clone',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [
                    ['category_id' => $categoryId, 'cashback_percent' => 9, 'spend_from' => 0, 'spend_to' => null],
                ],
            ]],
        ]);

        $this->assertSame($before, $this->snapshot($source));
        $this->assertNotSame('Bậc VIP của clone', $this->blueprintVersion($source, 3)->tiers()->orderBy('sort_order')->first()->name);
    }

    #[Test]
    public function restructuring_clone_does_not_affect_source(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Cắt giảm bậc bản clone');

        $before = $this->snapshot($source);

        $cloneV3 = $this->blueprintVersion($cloned, 3);
        $this->assertCount(2, $cloneV3->tiers()->get());

        // Payload chỉ còn 1 bậc ⇒ tier thứ 2 của clone bị XOÁ, nguồn vẫn nguyên vẹn.
        $this->service->updateSystemVersion($cloned, $cloneV3, [
            'tiers' => [[
                'name' => 'Bậc duy nhất còn lại',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [],
            ]],
        ]);

        $this->assertSame($before, $this->snapshot($source));
        $this->assertCount(1, $cloneV3->refresh()->tiers()->get());
        $this->assertCount(2, $this->blueprintVersion($source, 3)->tiers()->get());
    }

    #[Test]
    public function cloning_an_existing_template_twice_keeps_both_fully_independent(): void
    {
        $source = $this->makeSourcePolicy();

        $first = $this->cloneFromEditor($source, [], 'Bản sao số 1');
        $second = $this->cloneFromEditor($source, [], 'Bản sao số 2');

        $sourceV3 = $this->blueprintVersion($source, 3);
        $firstV3 = $this->blueprintVersion($first, 3);
        $secondV3 = $this->blueprintVersion($second, 3);

        $firstRule = $firstV3->tiers()->orderBy('sort_order')->first()->tierCategoryRules()->first();

        $this->assertSame('4.000', $this->percent($secondV3->tiers()->orderBy('sort_order')->first()->tierCategoryRules()->first()->cashback_percent));

        $this->service->updateSystemVersion($first, $firstV3, [
            'tiers' => [[
                'name' => 'Bậc cơ bản',
                'min_total_spend' => 0,
                'max_total_spend' => 5000000,
                'rules' => [
                    ['category_id' => $firstRule->category_id, 'cashback_percent' => 2, 'spend_from' => 0, 'spend_to' => null],
                ],
            ]],
        ]);

        $this->assertSame('2.000', $this->percent($firstV3->refresh()->tiers()->orderBy('sort_order')->first()->tierCategoryRules()->whereNotNull('category_id')->first()->cashback_percent));
        $this->assertSame('4.000', $this->percent($secondV3->refresh()->tiers()->orderBy('sort_order')->first()->tierCategoryRules()->first()->cashback_percent));
        $this->assertSame('4.000', $this->percent($sourceV3->refresh()->tiers()->orderBy('sort_order')->first()->tierCategoryRules()->first()->cashback_percent));
    }

    // =====================================================================
    // Không đụng dữ liệu nghiệp vụ khác
    // =====================================================================

    #[Test]
    public function clone_does_not_touch_business_data(): void
    {
        $source = $this->makeSourcePolicy();

        $transactions = Transaction::count();
        $periods = StatementPeriod::count();
        $cards = UserCard::count();
        $userPolicies = Policy::query()->whereNotNull('user_card_id')->count();

        $cloned = $this->cloneFromEditor($source, [], 'Không đụng nghiệp vụ');

        $this->assertSame($transactions, Transaction::count());
        $this->assertSame($periods, StatementPeriod::count());
        $this->assertSame($cards, UserCard::count());
        $this->assertSame($userPolicies, Policy::query()->whereNotNull('user_card_id')->count());

        // Không giao dịch nào trỏ vào chuỗi clone.
        $cloneIds = $cloned->blueprints()->pluck('id');
        $this->assertSame(0, Transaction::query()
            ->whereIn('policy_version_id', function ($query) use ($cloneIds): void {
                $query->select('id')->from('credit_card_policies')->whereIn('id', $cloneIds);
            })->count());
    }

    #[Test]
    public function clone_commits_as_one_consistent_database_unit(): void
    {
        $source = $this->makeSourcePolicy();

        $cloned = $this->cloneFromEditor($source, [], 'Nhất quán sau commit');

        $blueprints = $cloned->blueprints()->orderBy('version_no')->get();
        $this->assertCount(3, $blueprints);

        $tierIds = PolicyTier::whereIn('policy_id', $blueprints->pluck('id'))->pluck('id');
        $ruleCount = PolicyTierCategory::whereIn('tier_id', $tierIds)->count();

        // Mọi tier thuộc blueprint clone, mọi rule thuộc tier clone — không orphan.
        $this->assertCount(5, $tierIds);
        $this->assertSame(11, $ruleCount, '6 rule nguồn + 1 fallback cho mỗi bậc clone (5 bậc).');
    }

    #[Test]
    public function clone_failure_midway_rolls_back_everything(): void
    {
        $source = $this->makeSourcePolicy();

        $templateBefore = PolicyTemplate::count();
        $policyBefore = Policy::count();
        $tierBefore = PolicyTier::count();
        $ruleBefore = PolicyTierCategory::count();

        // Chèn trigger ABORT ngay TRƯỚC insert tier ⇒ service fail sau khi đã tạo
        // template + blueprint v1, đúng lúc copyChildren chạm bảng `credit_card_policy_tiers`.
        DB::connection('creditcard')->statement(
            'CREATE TRIGGER clone_fail_before_tier BEFORE INSERT ON credit_card_policy_tiers '
            ."BEGIN SELECT RAISE(ABORT, 'boom'); END"
        );

        try {
            $this->app->make(PolicyCloneService::class)->createSystemPolicyFromEditor(
                $source,
                'Sẽ thất bại',
                null,
                true,
                CarbonImmutable::parse('2026-10-01'),
                ['tiers' => $this->editorPayload($source)['tiers']],
                $source->defaultBlueprint()?->id,
            );

            $this->fail('Trigger ABORT phải làm createSystemPolicyFromEditor ném QueryException.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        } finally {
            DB::connection('creditcard')->statement('DROP TRIGGER IF EXISTS clone_fail_before_tier');
        }

        // Toàn bộ transaction bị rollback: không để lại template / blueprint nào.
        $this->assertSame($templateBefore, PolicyTemplate::count());
        $this->assertSame($policyBefore, Policy::count());
        $this->assertSame($tierBefore, PolicyTier::count());
        $this->assertSame($ruleBefore, PolicyTierCategory::count());
    }

    #[Test]
    public function cloning_a_policy_without_versions_returns_error_and_creates_nothing(): void
    {
        $empty = $this->makeSystemTemplate([
            'name' => 'Chính sách trống',
            'is_builtin' => false,
        ]);
        $before = PolicyTemplate::count();

        $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.clone', $empty), [
                'name' => 'X',
                'effective_from' => '2026-10-01',
                'tiers' => [[
                    'name' => 'Bậc cơ bản',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'rules' => [],
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'chưa có version nào'));

        $this->assertSame($before, PolicyTemplate::count());
    }

    // =====================================================================
    // §15 — FALLBACK "📦 CÁC DANH MỤC CÒN LẠI" KHI CLONE
    // =====================================================================

    #[Test]
    public function clone_copies_the_fallback_of_each_tier(): void
    {
        $source = $this->makeSourcePolicy();
        $cloned = $this->cloneFromEditor($source);

        $sourceFallbacks = PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::whereIn('policy_id', $source->blueprints()->pluck('id'))->pluck('id'))
            ->fallback()
            ->count();

        $clonedFallbacks = PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::whereIn('policy_id', $cloned->blueprints()->pluck('id'))->pluck('id'))
            ->fallback()
            ->count();

        $this->assertSame(5, $sourceFallbacks, 'Nguồn có 5 fallback (1/bậc × 5 bậc trên 3 version).');
        $this->assertSame($sourceFallbacks, $clonedFallbacks, 'Clone giữ nguyên số fallback của nguồn.');

        foreach ($cloned->blueprints()->get() as $blueprint) {
            foreach ($blueprint->tiers()->get() as $tier) {
                $this->assertCount(1, $tier->tierCategoryRules()->fallback()->get(), 'Mỗi bậc clone có đúng 1 fallback.');
            }
        }
    }

    #[Test]
    public function cloned_fallbacks_get_fresh_ids_and_keep_null_category(): void
    {
        $source = $this->makeSourcePolicy();
        $cloned = $this->cloneFromEditor($source);

        $sourceIds = PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::whereIn('policy_id', $source->blueprints()->pluck('id'))->pluck('id'))
            ->fallback()
            ->pluck('id')
            ->all();

        $clonedFallbacks = PolicyTierCategory::query()
            ->whereIn('tier_id', PolicyTier::whereIn('policy_id', $cloned->blueprints()->pluck('id'))->pluck('id'))
            ->fallback()
            ->get();

        $this->assertEmpty(
            array_intersect($sourceIds, $clonedFallbacks->pluck('id')->all()),
            'Mọi fallback clone là bản ghi MỚI, không reuse id nguồn.',
        );

        foreach ($clonedFallbacks as $fallback) {
            $this->assertNull($fallback->category_id);
            $this->assertSame(PolicyTierCategory::SCOPE_OTHER, $fallback->scope_type);
            $this->assertFalse((bool) $fallback->counts_toward_tier_cap);
        }
    }

    #[Test]
    public function clone_preserves_fallback_configuration_per_tier(): void
    {
        $source = $this->makeSourcePolicy();
        $cloned = $this->cloneFromEditor($source);

        $sourceDefault = $source->defaultBlueprint();
        $clonedDefault = $cloned->defaultBlueprint();

        foreach ($sourceDefault->tiers()->get() as $sourceTier) {
            $clonedTier = $clonedDefault->tiers()->where('name', $sourceTier->name)->firstOrFail();
            $sourceFallback = $sourceTier->tierCategoryRules()->fallback()->first();
            $clonedFallback = $clonedTier->tierCategoryRules()->fallback()->first();

            $this->assertNotNull($sourceFallback);
            $this->assertNotNull($clonedFallback);
            $this->assertSame(
                $this->fallbackSignature($sourceFallback),
                $this->fallbackSignature($clonedFallback),
                'Fallback của bậc "'.$sourceTier->name.'" phải giữ nguyên cấu hình khi clone.',
            );
        }
    }

    #[Test]
    public function clone_never_creates_a_second_fallback_in_any_tier(): void
    {
        $source = $this->makeSourcePolicy();
        $cloned = $this->cloneFromEditor($source);

        foreach ($cloned->blueprints()->get() as $blueprint) {
            foreach ($blueprint->tiers()->get() as $tier) {
                $this->assertCount(
                    1,
                    $tier->tierCategoryRules()->fallback()->get(),
                    'Bậc "'.$tier->name.'" của version '.$blueprint->version_no.' phải có đúng 1 fallback.',
                );
            }
        }
    }

    #[Test]
    public function recloning_a_copy_twice_keeps_exactly_one_fallback_per_tier(): void
    {
        $source = $this->makeSourcePolicy();
        $first = $this->cloneFromEditor($source);
        $second = $this->cloneFromEditor($first);

        $this->assertNotSame((int) $source->id, (int) $second->id, 'Bản sao thứ hai độc lập với nguồn.');

        foreach ($second->blueprints()->get() as $blueprint) {
            foreach ($blueprint->tiers()->get() as $tier) {
                $this->assertCount(1, $tier->tierCategoryRules()->fallback()->get());
            }
        }
    }

    #[Test]
    public function editor_payload_can_flag_the_fallback_to_count_toward_the_cap(): void
    {
        $source = $this->makeSourcePolicy();
        $payload = $this->editorPayload($source);
        $payload['tiers'][0]['rules'] = array_map(
            fn (array $rule): array => ($rule['scope_type'] ?? 'category') === 'other'
                ? array_replace($rule, ['counts_toward_tier_cap' => true])
                : $rule,
            $payload['tiers'][0]['rules'],
        );

        $cloned = $this->cloneFromEditor($source, ['tiers' => $payload['tiers']]);

        $firstTier = $cloned->defaultBlueprint()->tiers()->orderBy('sort_order')->orderBy('id')->first();
        $this->assertNotNull($firstTier);

        $clonedFallback = $firstTier->tierCategoryRules()->fallback()->first();
        $this->assertNotNull($clonedFallback);
        $this->assertTrue((bool) $clonedFallback->counts_toward_tier_cap, 'Fallback clone nhận cờ counting từ payload editor.');
    }

    // =====================================================================
    // §23 — clone chính sách có giới hạn hoàn tiền theo giá trị giao dịch
    // =====================================================================

    #[Test]
    public function clone_copies_transaction_caps_with_fresh_ids_without_touching_the_source(): void
    {
        $catA = $this->makeSystemCategory(['name' => 'Danh mục A']);

        $source = $this->service->createSystemTemplate(
            'Nguồn cap động '.uniqid(),
            null,
            CarbonImmutable::parse('2026-09-01'),
            [
                'tiers' => [[
                    'name' => 'Bậc cơ bản',
                    'sort_order' => 1,
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
            ],
            true,
        );

        $sourceTier = $source->defaultBlueprint()->tiers()->firstOrFail();
        $sourceCapIds = $sourceTier->transactionCaps()->orderBy('sort_order')->pluck('id')->all();
        $this->assertCount(2, $sourceCapIds);

        $cloned = $this->cloneFromEditor($source);

        $clonedTier = $cloned->defaultBlueprint()->tiers()->firstOrFail();
        $caps = $clonedTier->transactionCaps()->orderBy('sort_order')->get();

        $this->assertNotSame((int) $sourceTier->id, (int) $clonedTier->id, 'Tier clone phải có id mới.');
        $this->assertCount(2, $caps);

        // Identity MỚI: không được dùng lại id cap của nguồn.
        $this->assertSame(
            [],
            array_values(array_intersect($clonedTier->transactionCaps()->pluck('id')->all(), $sourceCapIds)),
            'Cap clone phải có id mới, không dùng lại id nguồn.'
        );

        // Số liệu GIỮ NGUYÊN theo sort_order.
        $this->assertSame('20000.00', $caps[0]->max_cashback_per_transaction);
        $this->assertSame('90000.00', $caps[1]->max_cashback_per_transaction);
        $this->assertNull($caps[1]->max_transaction_amount);
        $this->assertSame('500000.00', $caps[0]->max_transaction_amount);
        $this->assertSame((int) $clonedTier->id, (int) $caps[0]->policy_tier_id, 'Cap clone gắn vào bậc clone.');

        // Nguồn KHÔNG đổi: vẫn giữ đủ 2 cap với id cũ.
        $sourceTier->refresh();
        $this->assertSame($sourceCapIds, $sourceTier->transactionCaps()->orderBy('sort_order')->pluck('id')->all());
        $this->assertSame('20000.00', $sourceTier->transactionCaps()->orderBy('sort_order')->first()->max_cashback_per_transaction);
    }

    // =====================================================================
    // Fixtures + helpers
    // =====================================================================

    /**
     * Source: template hệ thống 3 phiên bản (v1, v2, v3) với default = v2,
     * nhiều tier + rule, business metadata đa dạng.
     */
    private function makeSourcePolicy(): PolicyTemplate
    {
        $catA = $this->makeSystemCategory(['name' => 'Danh mục A']);
        $catB = $this->makeSystemCategory(['name' => 'Danh mục B']);
        $catC = $this->makeSystemCategory(['name' => 'Danh mục C']);

        $template = $this->service->createSystemTemplate(
            'Policy nguồn '.uniqid(),
            'Mô tả gốc của chính sách',
            CarbonImmutable::parse('2026-09-01'),
            [
                'rounding_mode' => 'floor',
                'max_cashback_total_per_period' => '5000000',
                'tiers' => [
                    [
                        'name' => 'Bậc cơ bản',
                        'sort_order' => 1,
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'max_cashback_per_period' => '5000000',
                        'rules' => [
                            ['category_id' => $catA->id, 'cashback_percent' => 5, 'spend_from' => 0, 'spend_to' => null, 'max_cashback_per_transaction' => 100000],
                            ['category_id' => $catB->id, 'cashback_percent' => 3, 'spend_from' => 0, 'spend_to' => null],
                        ],
                    ],
                ],
            ],
            true,
        );

        $this->service->createSystemVersion($template, CarbonImmutable::parse('2026-10-01'), [
            'tiers' => [
                [
                    'name' => 'Bậc cơ bản',
                    'min_total_spend' => 0,
                    'max_total_spend' => 5000000,
                    'rules' => [
                        ['category_id' => $catC->id, 'cashback_percent' => 7, 'spend_from' => 0, 'spend_to' => null],
                    ],
                ],
                [
                    'name' => 'Bậc cao',
                    'min_total_spend' => 5000000,
                    'max_total_spend' => null,
                    'rules' => [
                        ['category_id' => $catA->id, 'cashback_percent' => 10, 'spend_from' => 0, 'spend_to' => null],
                    ],
                ],
            ],
        ]);
        $v2 = $template->currentBlueprint();

        $this->service->createSystemVersion($template, CarbonImmutable::parse('2026-11-01'), [
            'tiers' => [
                [
                    'name' => 'Bậc cơ bản',
                    'min_total_spend' => 0,
                    'max_total_spend' => 5000000,
                    'rules' => [
                        ['category_id' => $catB->id, 'cashback_percent' => 4, 'spend_from' => 0, 'spend_to' => null],
                    ],
                ],
                [
                    'name' => 'Bậc cao',
                    'min_total_spend' => 5000000,
                    'max_total_spend' => null,
                    'rules' => [
                        ['category_id' => $catC->id, 'cashback_percent' => 6, 'spend_from' => 0, 'spend_to' => null],
                    ],
                ],
            ],
        ]);

        // Default = version 2 (giữa chuỗi) — đúng kịch bản đa phiên bản.
        $this->service->setDefaultVersion($template, (int) $v2->id);

        return $template;
    }

    /**
     * Payload editor đúng như `credit-card/partials/policy-editor.blade.php` gửi đi: metadata
     * template + cấu hình blueprint DEFAULT (version nguồn editor hydrate) +
     * `source_version_id` trỏ chính blueprint đó — chính là payload clone API nhận.
     *
     * @return array<string, mixed>
     */
    private function editorPayload(PolicyTemplate $source, string $name = 'Bản sao từ editor', array $overrides = []): array
    {
        $default = $source->defaultBlueprint();
        $this->assertNotNull($default, 'Source phải có default blueprint.');

        $payload = [
            'name' => $name,
            'description' => 'Mô tả bản sao từ editor',
            'status' => $source->is_active ? 'published' : 'draft',
            'effective_from' => $default->effective_from?->toDateString() ?? '2026-10-01',
            'min_total_spend' => (float) $default->min_total_spend,
            'rounding_mode' => $default->rounding_mode,
            'source_version_id' => $default->id,
            'tiers' => app(SystemPolicyPresenter::class)->blueprint($default)['tiers'],
        ];

        // `array_replace_recursive` TRỘN mảng numeric (tiers) theo index — phải thay
        // THẾ trọn `tiers` khi override để test "bản sao giảm bậc" hoạt động đúng.
        foreach ($overrides as $key => $value) {
            $payload[$key] = ($key !== 'tiers' && is_array($value) && is_array($payload[$key] ?? null))
                ? array_replace_recursive($payload[$key], $value)
                : $value;
        }

        return $payload;
    }

    /**
     * POST thành công payload editor tới API clone.
     *
     * @return array{0: TestResponse, 1: int} [response, max template id TRƯỚC khi gọi]
     */
    private function postCloneEditor(PolicyTemplate $source, array $overrides = [], string $name = 'Bản sao từ editor'): array
    {
        $last = (int) PolicyTemplate::query()->system()->max('id');

        $response = $this->actingAs($this->manager)
            ->postJson(route('admin.credit-card-policies.api.clone', $source), $this->editorPayload($source, $name, $overrides));

        return [$response, $last];
    }

    private function cloneFromEditor(PolicyTemplate $source, array $overrides = [], string $name = 'Bản sao từ editor'): PolicyTemplate
    {
        [$response, $last] = $this->postCloneEditor($source, $overrides, $name);

        $response->assertCreated();

        $cloned = $this->newestSystemTemplate($last);
        $response->assertJsonPath('redirect', route('admin.credit-card-policies.edit', $cloned->id));

        return $cloned;
    }

    private function newestSystemTemplate(int $afterId): PolicyTemplate
    {
        $cloned = PolicyTemplate::query()->system()
            ->where('id', '>', $afterId)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($cloned);

        return $cloned;
    }

    private function blueprintVersion(PolicyTemplate $template, int $versionNo): PolicyVersion
    {
        $blueprint = PolicyVersion::query()
            ->where('template_id', $template->id)
            ->whereNull('user_card_id')
            ->where('version_no', $versionNo)
            ->first();

        $this->assertNotNull($blueprint);

        return $blueprint;
    }

    private function ruleSignature(PolicyTierCategory $rule): array
    {
        return [
            'category_id' => (int) $rule->category_id,
            'name' => $rule->name,
            'sort_order' => (int) $rule->sort_order,
            'spend_from' => (string) $rule->spend_from,
            'spend_to' => (string) $rule->spend_to,
            'cashback_percent' => $this->percent($rule->cashback_percent),
            'max_cashback_per_transaction' => $rule->max_cashback_per_transaction === null ? null : (string) $rule->max_cashback_per_transaction,
            'max_cashback_per_category_per_period' => $rule->max_cashback_per_category_per_period === null ? null : (string) $rule->max_cashback_per_category_per_period,
            'min_transaction_amount' => $rule->min_transaction_amount === null ? null : (string) $rule->min_transaction_amount,
        ];
    }

    private function fallbackSignature(PolicyTierCategory $rule): array
    {
        return array_merge($this->ruleSignature($rule), [
            'scope_type' => $rule->scope_type,
            'counts_toward_tier_cap' => (bool) $rule->counts_toward_tier_cap,
        ]);
    }

    private function percent(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function snapshot(PolicyTemplate $template): array
    {
        $blueprintIds = $template->blueprints()->pluck('id');
        $tierIds = PolicyTier::whereIn('policy_id', $blueprintIds)->pluck('id');
        $rules = PolicyTierCategory::whereIn('tier_id', $tierIds)->orderBy('id')->get()
            ->map(fn ($rule) => $this->ruleSignature($rule))
            ->values()
            ->all();

        return [
            'id' => $template->id,
            'description' => $template->description,
            'is_active' => (bool) $template->is_active,
            'sort_order' => (int) $template->sort_order,
            'blueprint_ids' => $blueprintIds->sort()->values()->all(),
            'tier_ids' => $tierIds->sort()->values()->all(),
            'rules' => $rules,
        ];
    }
}
