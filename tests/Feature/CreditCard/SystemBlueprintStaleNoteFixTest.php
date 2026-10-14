<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Sửa `note` sai của blueprint chính sách hệ thống bị clone nhầm tên template
 * nguồn (migration 2026_10_14_000011).
 *
 * Bối cảnh: nhiều template hệ thống (VPBank Lady, VPBank Z Card JCB, MSB mDigi,
 * LPBank Ultimate JCB, ...) có `note` dạng 'Blueprint version 1 của template
 * "TÊN KHÁC".' do luồng clone chép nguyên văn note nguồn. Migration 000011 chỉ
 * sửa note của 8 template đã xác minh, idempotent, không đụng trạng thái/trần/
 * bậc/rule.
 */
class SystemBlueprintStaleNoteFixTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();
    }

    /**
     * Nạp file migration đúng MỘT lần cho cả process rồi gọi `up()` (để kiểm
     * chứng tính idempotent khi chạy lại).
     */
    private function runFix(): void
    {
        static $migration = null;

        if ($migration === null) {
            $migration = require database_path('migrations/creditcard/2026_10_14_000011_fix_stale_system_blueprint_notes.php');
        }

        $migration->up();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeBlueprint(PolicyTemplate $template, array $attributes = []): Policy
    {
        $blueprint = Policy::create(array_merge([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $template->name,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'min_total_spend' => '0.00',
            'max_cashback_total_per_period' => null,
            'rounding_mode' => 'round',
            'note' => 'Blueprint version 1 của template "Nguồn Khác".',
        ], $attributes));

        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        return $blueprint->refresh();
    }

    #[Test]
    public function it_corrects_stale_notes_for_all_eight_target_templates(): void
    {
        $cases = [
            'vpbank-lady-mastercard' => 'VPBank Lady Mastercard',
            'vpbank-z-card-jcb' => 'VPBank Z Card JCB',
            'vpbank-super-shopee-platinum-visa' => 'VPBank  Super Shopee Platinum Visa',
            'msb-mdigi-mastercard' => 'MSB  mDigi Mastercard',
            'vpbank-cake-freedom-visa' => 'VPBank  Cake Freedom Visa',
            'lpbank-ultimate-jcb' => 'LPBank Ultimate JCB',
            'bidv-cashback-online-visa' => 'BIDV Cashback Online Visa',
            'seabank-sea-easy-visa' => 'SeABank SeA-Easy Visa',
        ];

        $blueprints = [];
        foreach ($cases as $slug => $name) {
            $template = $this->makeSystemTemplate(['slug' => $slug, 'name' => $name]);
            $blueprints[$slug] = $this->makeBlueprint($template);
        }

        $this->runFix();

        foreach ($cases as $slug => $name) {
            $this->assertSame(
                'Chính sách hệ thống '.$name.'.',
                $blueprints[$slug]->refresh()->note,
                "Note của {$slug} phải được thay bằng ghi chú đúng template.",
            );
        }
    }

    #[Test]
    public function it_collects_all_blueprints_of_a_template_not_only_the_default(): void
    {
        $template = $this->makeSystemTemplate(['slug' => 'vpbank-z-card-jcb', 'name' => 'VPBank Z Card JCB']);
        $v1 = $this->makeBlueprint($template, ['version_no' => 1, 'note' => 'Blueprint version 1 của template "MB Ultimate JCB".']);
        $v2 = $this->makeBlueprint($template, [
            'version_no' => 2,
            'root_policy_id' => $v1->id,
            'status' => Policy::STATUS_ACTIVE,
            'note' => 'Blueprint version 2 của template "MB Ultimate JCB".',
        ]);

        $this->runFix();

        $this->assertSame('Chính sách hệ thống VPBank Z Card JCB.', $v1->refresh()->note);
        $this->assertSame('Chính sách hệ thống VPBank Z Card JCB.', $v2->refresh()->note);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $template = $this->makeSystemTemplate(['slug' => 'lpbank-ultimate-jcb', 'name' => 'LPBank Ultimate JCB']);
        $blueprint = $this->makeBlueprint($template);

        $this->runFix();
        $this->runFix();

        $this->assertSame('Chính sách hệ thống LPBank Ultimate JCB.', $blueprint->refresh()->note);
    }

    #[Test]
    public function it_does_not_touch_templates_outside_the_list_nor_correct_notes(): void
    {
        // Template KHÔNG nằm trong danh sách: note sai kiểu này vẫn phải giữ nguyên.
        $unlisted = $this->makeSystemTemplate(['slug' => 'some-other-system-card', 'name' => 'Some Other System Card']);
        $unlistedBlueprint = $this->makeBlueprint($unlisted, ['note' => 'Blueprint version 1 của template "MB Ultimate JCB".']);

        // Template TRONG danh sách nhưng note đã ĐÚNG (trùng tên) ⇒ giữ nguyên.
        $alreadyCorrect = $this->makeSystemTemplate(['slug' => 'vpbank-z-card-jcb', 'name' => 'VPBank Z Card JCB']);
        $correctBlueprint = $this->makeBlueprint($alreadyCorrect, ['note' => 'Blueprint version 1 của template "VPBank Z Card JCB".']);

        // Note tuỳ biến do admin sửa tay (không đúng định dạng hệ thống) ⇒ giữ nguyên.
        $customNote = $this->makeSystemTemplate(['slug' => 'msb-mdigi-mastercard', 'name' => 'MSB  mDigi Mastercard']);
        $customBlueprint = $this->makeBlueprint($customNote, ['note' => 'Ghi chú nội bộ do admin nhập.']);

        $this->runFix();

        $this->assertSame('Blueprint version 1 của template "MB Ultimate JCB".', $unlistedBlueprint->refresh()->note);
        $this->assertSame('Blueprint version 1 của template "VPBank Z Card JCB".', $correctBlueprint->refresh()->note);
        $this->assertSame('Ghi chú nội bộ do admin nhập.', $customBlueprint->refresh()->note);
    }

    #[Test]
    public function it_does_not_change_status_cap_tier_or_rule_data(): void
    {
        $template = $this->makeSystemTemplate(['slug' => 'seabank-sea-easy-visa', 'name' => 'SeABank SeA-Easy Visa']);
        $blueprint = $this->makeBlueprint($template, [
            'status' => Policy::STATUS_SUPERSEDED,
            'max_cashback_total_per_period' => '800000.00',
            'min_total_spend' => '0.00',
            'note' => 'Blueprint version 1 của template "MB Ultimate JCB".',
        ]);

        $tier = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Bậc 1',
            'sort_order' => 1,
            'min_total_spend' => '0.00',
            'max_total_spend' => null,
            'max_cashback_per_period' => '600000.00',
        ]);

        $rule = PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $this->makeSystemCategory()->id,
            'name' => 'Online',
            'sort_order' => 0,
            'spend_from' => 0,
            'cashback_percent' => '10.000',
            'max_cashback_per_category_per_period' => '600000.00',
            'is_enabled' => true,
            'counts_toward_tier_cap' => true,
        ]);

        $this->runFix();

        $blueprint->refresh();
        $this->assertSame(Policy::STATUS_SUPERSEDED, $blueprint->status, 'Migration KHÔNG được đổi trạng thái.');
        $this->assertSame('800000.00', $blueprint->max_cashback_total_per_period, 'Trần cấp-policy không đổi.');
        $this->assertSame('0.00', $blueprint->min_total_spend, 'Chi tiêu tối thiểu không đổi.');
        $this->assertSame('Chính sách hệ thống SeABank SeA-Easy Visa.', $blueprint->note);
        $this->assertSame('600000.00', $tier->refresh()->max_cashback_per_period, 'Trần bậc không đổi.');
        $this->assertSame('10.000', $rule->refresh()->cashback_percent, 'Tỷ lệ rule không đổi.');
    }

    #[Test]
    public function it_skips_safely_when_no_target_template_exists(): void
    {
        // Không tạo template nào trong danh sách ⇒ migration bỏ qua, không lỗi.
        $this->runFix();

        $this->assertSame(0, PolicyTemplate::query()->whereIn('slug', [
            'vpbank-lady-mastercard', 'vpbank-z-card-jcb', 'msb-mdigi-mastercard',
        ])->count());
    }
}
