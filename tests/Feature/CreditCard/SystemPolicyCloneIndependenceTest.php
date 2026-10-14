<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\User;
use App\Services\CreditCard\CardPolicyLookupService;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\PolicyService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Bản clone chính sách hệ thống phải ĐỘC LẬP HOÀN TOÀN.
 *
 * Regression cho lỗi: sau khi clone + chỉnh sửa + lưu, một số thông tin (trần
 * hoàn tiền tổng cấp policy, ghi chú blueprint) vẫn mang giá trị của chính sách
 * NGUỒN. Nguyên nhân:
 *   1. `createSystemPolicyFromEditor()` chép nguyên ghi chú blueprint (`$blueprint
 *      ->note`) — ghi chú sinh tự động chứa TÊN template nguồn.
 *   2. Cùng hàm chép nguyên `max_cashback_total_per_period` (cột legacy cấp policy)
 *      và editor không bao giờ gửi khoá này ⇒ bản clone giữ trần cũ mãi mãi.
 *
 * Bảy bảo vệ (theo yêu cầu):
 *   1. Clone tạo policy MỚI với id riêng (mọi identity mới).
 *   2. Sửa trần tổng + bậc của bản clone, lưu, đọc lại = giá trị mới.
 *   3. Nguồn KHÔNG đổi sau khi clone + sửa bản clone.
 *   4. Ghi chú trỏ tên template ĐÍCH, không phải nguồn.
 *   5. Tra cứu hiển thị policy/blueprint của template ĐÍCH.
 *   6. Policy và thẻ KHÁC không bị ảnh hưởng.
 *   7. Clone không sinh bản ghi trùng khi lưu lại / chạy lại luồng.
 */
class SystemPolicyCloneIndependenceTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private PolicyService $service;

    private PolicyCloneService $cloner;

    private CardPolicyLookupService $lookup;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->service = $this->app->make(PolicyService::class);
        $this->cloner = $this->app->make(PolicyCloneService::class);
        $this->lookup = $this->app->make(CardPolicyLookupService::class);
    }

    // =====================================================================
    // 1. Clone tạo policy MỚI với id riêng
    // =====================================================================

    #[Test]
    public function clone_creates_a_new_policy_with_its_own_ids(): void
    {
        $source = $this->makeSource();

        $sourceBlueprintIds = $source->blueprints()->pluck('id')->all();
        $sourceTierIds = PolicyTier::whereIn('policy_id', $sourceBlueprintIds)->pluck('id')->all();
        $sourceRuleIds = PolicyTierCategory::whereIn('tier_id', $sourceTierIds)->pluck('id')->all();

        $clone = $this->cloneSource($source, 'Bản sao độc lập');

        $this->assertNotSame((int) $source->id, (int) $clone->id);
        $this->assertTrue($clone->isSystemScope());
        $this->assertNotNull($clone->defaultBlueprint());

        $cloneBlueprintIds = $clone->blueprints()->pluck('id')->all();
        $cloneTierIds = PolicyTier::whereIn('policy_id', $cloneBlueprintIds)->pluck('id')->all();
        $cloneRuleIds = PolicyTierCategory::whereIn('tier_id', $cloneTierIds)->pluck('id')->all();

        $this->assertEmpty(array_intersect($sourceBlueprintIds, $cloneBlueprintIds), 'Blueprint clone phải có id mới.');
        $this->assertEmpty(array_intersect($sourceTierIds, $cloneTierIds), 'Bậc clone phải có id mới.');
        $this->assertEmpty(array_intersect($sourceRuleIds, $cloneRuleIds), 'Rule clone phải có id mới.');
        $this->assertNotSame((int) $source->defaultBlueprint()->id, (int) $clone->defaultBlueprint()->id);
    }

    // =====================================================================
    // 2. Sửa trần tổng + bậc của bản clone, lưu, đọc lại = giá trị mới
    // =====================================================================

    #[Test]
    public function editing_clone_total_cap_and_tiers_is_persisted_exactly(): void
    {
        $source = $this->makeSource();
        $clone = $this->cloneSource($source, 'Bản sao để chỉnh sửa');
        $cloneDefault = $clone->defaultBlueprint();

        $categoryId = $this->firstCategoryId($cloneDefault);

        $updated = $this->service->updateSystemVersion($clone, $cloneDefault, [
            'max_cashback_total_per_period' => 1234000,
            'tiers' => [[
                'name' => 'Bậc ĐÍCH vừa sửa',
                'sort_order' => 1,
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'max_cashback_per_period' => 777000,
                'rules' => [[
                    'category_id' => $categoryId,
                    'cashback_percent' => 8,
                    'spend_from' => 0,
                    'spend_to' => null,
                ]],
            ]],
        ]);

        $this->assertSame('1234000.00', number_format((float) $updated->max_cashback_total_per_period, 2, '.', ''));
        $this->assertCount(1, $updated->tiers()->get());
        $this->assertSame('Bậc ĐÍCH vừa sửa', $updated->tiers()->first()->name);
        $this->assertSame('777000.00', number_format((float) $updated->tiers()->first()->max_cashback_per_period, 2, '.', ''));
        $this->assertSame(8.0, (float) $updated->tiers()->first()->tierCategoryRules()->whereNotNull('category_id')->first()->cashback_percent);
    }

    // =====================================================================
    // 3. Nguồn KHÔNG đổi sau khi clone + sửa bản clone
    // =====================================================================

    #[Test]
    public function source_is_untouched_after_cloning_and_editing_the_clone(): void
    {
        $source = $this->makeSource();
        $before = $this->snapshot($source);

        $clone = $this->cloneSource($source, 'Bản sao sửa thoải mái');
        $cloneDefault = $clone->defaultBlueprint();

        $this->service->updateSystemVersion($clone, $cloneDefault, [
            'max_cashback_total_per_period' => 999000,
            'tiers' => [[
                'name' => 'Bậc thay đổi hoàn toàn',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [[
                    'category_id' => $this->firstCategoryId($cloneDefault),
                    'cashback_percent' => 2,
                    'spend_from' => 0,
                    'spend_to' => null,
                ]],
            ]],
        ]);

        $this->assertSame($before, $this->snapshot($source), 'Chính sách nguồn phải nguyên vẹn.');
        $this->assertSame('5000000.00', number_format((float) $source->defaultBlueprint()->max_cashback_total_per_period, 2, '.', ''));
        $this->assertSame('Bậc nguồn v2 thấp', $source->defaultBlueprint()->tiers()->orderBy('sort_order')->first()->name);
    }

    // =====================================================================
    // 4. Ghi chú trỏ tên template ĐÍCH, không phải nguồn
    // =====================================================================

    #[Test]
    public function clone_notes_reference_the_target_template_not_the_source(): void
    {
        $source = $this->makeSource();
        $sourceName = (string) $source->name;
        $cloneName = 'Ghi chú theo tên đích';

        $clone = $this->cloneSource($source, $cloneName);

        foreach ($clone->blueprints()->get() as $blueprint) {
            $this->assertNotNull($blueprint->note);
            $this->assertStringContainsString($cloneName, (string) $blueprint->note, 'Ghi chú phải trỏ tên template đích.');
            $this->assertStringNotContainsString($sourceName, (string) $blueprint->note, 'Ghi chú KHÔNG được còn tên template nguồn.');
        }
    }

    // =====================================================================
    // 5. Tra cứu hiển thị policy/blueprint của template ĐÍCH
    // =====================================================================

    #[Test]
    public function lookup_shows_the_target_template_policy_and_blueprint(): void
    {
        $source = $this->makeSource();
        $clone = $this->cloneSource($source, 'Chính sách đích tra cứu');
        $cloneDefault = $clone->defaultBlueprint();

        // Đổi cấu hình bản clone để chứng minh tra cứu đọc ĐÚNG blueprint của clone.
        $this->service->updateSystemVersion($clone, $cloneDefault, [
            'tiers' => [[
                'name' => 'Bậc ĐÍCH độc quyền',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [[
                    'category_id' => $this->firstCategoryId($cloneDefault),
                    'cashback_percent' => 6,
                    'spend_from' => 0,
                    'spend_to' => null,
                ]],
            ]],
        ]);

        $summary = $this->lookup->summarize($clone->refresh());

        $this->assertSame((int) $clone->id, $summary['id']);
        $this->assertSame('Chính sách đích tra cứu', $summary['name']);
        $this->assertStringContainsString('Chính sách đích tra cứu', (string) $summary['note']);
        $this->assertStringNotContainsString((string) $source->name, (string) $summary['note']);

        // Bậc hiển thị là của clone (đã sửa), KHÔNG phải bậc nguồn.
        $this->assertSame(['Bậc ĐÍCH độc quyền'], array_column($summary['tiers'], 'name'));
        $this->assertNotContains('Bậc ĐÍCH độc quyền', $source->refresh()->defaultBlueprint()->tiers()->pluck('name')->all());

        // Trần hoàn tổng legacy KHÔNG kế thừa từ nguồn.
        $this->assertNull($summary['max_cashback_total_per_period']);
    }

    // =====================================================================
    // 6. Policy và thẻ KHÁC không bị ảnh hưởng
    // =====================================================================

    #[Test]
    public function other_policies_and_cards_are_unaffected(): void
    {
        $source = $this->makeSource();
        $other = $this->makeSource();

        // Thẻ đang dùng một template KHÁC (bản sao độc lập của thẻ).
        $user = User::factory()->create();
        $card = $this->makeUserCard($user->id);
        $cardPolicy = $this->cloner->attachTemplateToCard($card, $other, CarbonImmutable::parse('2026-01-01'));

        $otherBefore = $this->snapshot($other);
        $cardCapBefore = $cardPolicy->max_cashback_total_per_period;

        // Clone + sửa chính sách `$source` (không liên quan tới `$other`/thẻ).
        $clone = $this->cloneSource($source, 'Bản sao không đụng ai');
        $cloneDefault = $clone->defaultBlueprint();
        $this->service->updateSystemVersion($clone, $cloneDefault, [
            'max_cashback_total_per_period' => 321000,
            'tiers' => [[
                'name' => 'Bậc riêng của bản sao',
                'min_total_spend' => 0,
                'max_total_spend' => null,
                'rules' => [[
                    'category_id' => $this->firstCategoryId($cloneDefault),
                    'cashback_percent' => 3,
                    'spend_from' => 0,
                    'spend_to' => null,
                ]],
            ]],
        ]);

        $this->assertSame($otherBefore, $this->snapshot($other), 'Template khác phải nguyên vẹn.');
        $this->assertSame(
            (string) $cardCapBefore,
            (string) $cardPolicy->refresh()->max_cashback_total_per_period,
            'Policy của thẻ đang dùng template khác không được đổi.',
        );
        $this->assertSame('Bậc riêng của bản sao', $cloneDefault->refresh()->tiers()->first()->name);
    }

    // =====================================================================
    // 7. Clone không sinh bản ghi trùng khi lưu lại / chạy lại luồng
    // =====================================================================

    #[Test]
    public function clone_does_not_duplicate_records_on_repeated_save(): void
    {
        $source = $this->makeSource();
        $clone = $this->cloneSource($source, 'Không trùng lặp');
        $cloneDefault = $clone->defaultBlueprint();

        $categoryA = $this->firstCategoryId($cloneDefault);
        $categoryB = $this->secondCategoryId($cloneDefault);

        $payload = [
            'tiers' => [
                [
                    'name' => 'Bậc 1',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => 5000000,
                    'rules' => [[
                        'category_id' => $categoryA,
                        'cashback_percent' => 3,
                        'spend_from' => 0,
                        'spend_to' => null,
                    ]],
                ],
                [
                    'name' => 'Bậc 2',
                    'sort_order' => 2,
                    'min_total_spend' => 5000000,
                    'max_total_spend' => null,
                    'rules' => [[
                        'category_id' => $categoryB,
                        'cashback_percent' => 7,
                        'spend_from' => 0,
                        'spend_to' => null,
                    ]],
                ],
            ],
        ];

        $this->service->updateSystemVersion($clone, $cloneDefault, $payload);

        $versionId = (int) $cloneDefault->id;
        $tierIdsAfterFirst = PolicyTier::where('policy_id', $versionId)->pluck('id')->all();
        $ruleCountAfterFirst = PolicyTierCategory::whereIn('tier_id', $tierIdsAfterFirst)->count();

        // Lưu LẠI cùng payload (mô phỏng mở editor rồi bấm "Lưu lại" lần nữa).
        $this->service->updateSystemVersion($clone, $cloneDefault->refresh(), $payload);

        $fresh = PolicyVersion::findOrFail($versionId);
        $freshTierIds = PolicyTier::where('policy_id', $versionId)->pluck('id')->all();

        $this->assertCount(2, $freshTierIds, 'Số bậc không tăng sau khi lưu lại.');
        $this->assertSame(
            $ruleCountAfterFirst,
            PolicyTierCategory::whereIn('tier_id', $freshTierIds)->count(),
            'Số rule không tăng sau khi lưu lại.',
        );

        foreach ($fresh->tiers()->get() as $tier) {
            $this->assertCount(1, $tier->tierCategoryRules()->fallback()->get(), 'Mỗi bậc có đúng 1 fallback, không nhân bản.');
        }

        // Bản clone không tạo thêm template khi lưu lại.
        $this->assertSame(1, PolicyTemplate::query()->where('name', 'Không trùng lặp')->count());
    }

    // =====================================================================
    // Fixtures + helpers
    // =====================================================================

    /**
     * Nguồn: template hệ thống 2 version. v1 (default) một bậc; v2 hai bậc.
     * Trần hoàn tổng legacy = 5.000.000, ghi chú do hệ thống sinh tự động.
     */
    private function makeSource(): PolicyTemplate
    {
        $catA = $this->makeSystemCategory(['name' => 'Danh mục A']);
        $catB = $this->makeSystemCategory(['name' => 'Danh mục B']);

        $template = $this->service->createSystemTemplate(
            'Chính sách nguồn '.uniqid(),
            'Mô tả nguồn',
            CarbonImmutable::parse('2026-09-01'),
            [
                'rounding_mode' => 'floor',
                'max_cashback_total_per_period' => '5000000',
                'tiers' => [[
                    'name' => 'Bậc nguồn v1',
                    'sort_order' => 1,
                    'min_total_spend' => 0,
                    'max_total_spend' => null,
                    'max_cashback_per_period' => '5000000',
                    'rules' => [[
                        'category_id' => $catA->id,
                        'cashback_percent' => 5,
                        'spend_from' => 0,
                        'spend_to' => null,
                    ]],
                ]],
            ],
            true,
        );

        $this->service->createSystemVersion($template, CarbonImmutable::parse('2026-10-01'), [
            'tiers' => [
                [
                    'name' => 'Bậc nguồn v2 thấp',
                    'min_total_spend' => 0,
                    'max_total_spend' => 5000000,
                    'rules' => [[
                        'category_id' => $catB->id,
                        'cashback_percent' => 7,
                        'spend_from' => 0,
                        'spend_to' => null,
                    ]],
                ],
                [
                    'name' => 'Bậc nguồn v2 cao',
                    'min_total_spend' => 5000000,
                    'max_total_spend' => null,
                    'rules' => [[
                        'category_id' => $catA->id,
                        'cashback_percent' => 10,
                        'spend_from' => 0,
                        'spend_to' => null,
                    ]],
                ],
            ],
        ]);

        // Default = version hiện hành (đang ACTIVE) — đúng kịch bản admin clone
        // một chính sách rồi chỉnh sửa version mặc định.
        $this->service->setDefaultVersion($template, (int) $template->currentBlueprint()->id);

        return $template->refresh();
    }

    private function cloneSource(PolicyTemplate $source, string $name): PolicyTemplate
    {
        return $this->cloner->createSystemPolicyFromEditor(
            $source,
            $name,
            $source->description,
            true,
            CarbonImmutable::parse('2026-11-01'),
            [],
            null,
        );
    }

    private function firstCategoryId(PolicyVersion $blueprint): int
    {
        $rule = $blueprint->tiers()->get()
            ->flatMap(fn ($tier) => $tier->tierCategoryRules()->whereNotNull('category_id')->get())
            ->first();

        $this->assertNotNull($rule, 'Blueprint phải có ít nhất một rule danh mục.');

        return (int) $rule->category_id;
    }

    private function secondCategoryId(PolicyVersion $blueprint): int
    {
        $ids = $blueprint->tiers()->get()
            ->flatMap(fn ($tier) => $tier->tierCategoryRules()->whereNotNull('category_id')->get())
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // Nguồn chỉ có thể trùng danh mục giữa các bậc; nếu vậy tạo thêm một danh mục.
        if ($ids->count() < 2) {
            return (int) $this->makeSystemCategory(['name' => 'Danh mục phụ '.uniqid()])->id;
        }

        return (int) $ids[1];
    }

    /**
     * Ảnh chụp bất biến của một template: metadata + mọi blueprint/bậc/rule.
     *
     * @return array<string, mixed>
     */
    private function snapshot(PolicyTemplate $template): array
    {
        $blueprintIds = $template->blueprints()->pluck('id');
        $tierIds = PolicyTier::whereIn('policy_id', $blueprintIds)->pluck('id');

        return [
            'name' => $template->name,
            'description' => $template->description,
            'is_active' => (bool) $template->is_active,
            'blueprints' => $template->blueprints()->orderBy('version_no')->get()->map(fn ($blueprint): array => [
                'id' => (int) $blueprint->id,
                'version_no' => (int) $blueprint->version_no,
                'status' => (string) $blueprint->status,
                'note' => (string) $blueprint->note,
                'max_cashback_total_per_period' => $blueprint->max_cashback_total_per_period === null
                    ? null
                    : (string) $blueprint->max_cashback_total_per_period,
            ])->all(),
            'tier_ids' => $tierIds->sort()->values()->all(),
            'rules' => PolicyTierCategory::whereIn('tier_id', $tierIds)->orderBy('id')->get()->map(fn ($rule): array => [
                'id' => (int) $rule->id,
                'tier_id' => (int) $rule->tier_id,
                'category_id' => $rule->category_id === null ? null : (int) $rule->category_id,
                'cashback_percent' => (string) $rule->cashback_percent,
            ])->all(),
        ];
    }
}
