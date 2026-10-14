<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Services\CreditCard\CardPolicyLookupService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Sửa lỗi hiển thị trần hoàn tiền + dòng blueprint sai của "MSB Online Visa"
 * trên màn hình Tra cứu thẻ (migration 2026_10_14_000010).
 *
 * Bối cảnh: template MSB Online Visa được sao chép từ "MB Ultimate JCB" nên
 * blueprint thừa hưởng trần cấp-policy 800.000đ và note nhắc "MB Ultimate JCB".
 * Migration chỉ sửa ĐÚNG template này, idempotent, không đụng template khác và
 * không đổi bậc/rule.
 */
class MsbOnlineVisaBlueprintCapFixTest extends TestCase
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
            $migration = require database_path('migrations/creditcard/2026_10_14_000010_fix_msb_online_visa_blueprint_note_and_total_cap.php');
        }

        $migration->up();
    }

    private function makeMsbBlueprint(PolicyTemplate $template, array $attributes = []): Policy
    {
        $blueprint = Policy::create(array_merge([
            'user_card_id' => null,
            'template_id' => $template->id,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => 'MSB Online Visa',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'min_total_spend' => '3000000.00',
            'max_cashback_total_per_period' => '800000.00',
            'rounding_mode' => 'round',
            'note' => 'Blueprint version 1 của template "MB Ultimate JCB".',
        ], $attributes));

        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        return $blueprint->refresh();
    }

    #[Test]
    public function it_corrects_stale_note_and_wrong_total_cap_and_lookup_reflects_it(): void
    {
        $template = $this->makeSystemTemplate(['slug' => 'msb-online-visa', 'name' => 'MSB Online Visa']);
        $blueprint = $this->makeMsbBlueprint($template);
        $template->forceFill(['default_version_id' => $blueprint->id])->save();

        $this->runFix();

        $blueprint->refresh();
        $this->assertSame('300000.00', $blueprint->max_cashback_total_per_period);
        $this->assertSame('Chính sách hệ thống MSB Online Visa.', $blueprint->note);
        $this->assertSame('3000000.00', $blueprint->min_total_spend, 'Chi tiêu tối thiểu giữ nguyên 3 triệu.');

        // Màn hình Tra cứu thẻ lấy đúng trần + note đã sửa.
        $summary = app(CardPolicyLookupService::class)->summarize($template->fresh());
        $this->assertSame('300000.00', $summary['max_cashback_total_per_period']);
        $this->assertSame('Chính sách hệ thống MSB Online Visa.', $summary['note']);
    }

    #[Test]
    public function it_is_idempotent_and_does_not_touch_other_templates(): void
    {
        $msb = $this->makeSystemTemplate(['slug' => 'msb-online-visa', 'name' => 'MSB Online Visa']);
        $msbBlueprint = $this->makeMsbBlueprint($msb);

        // Template khác vô tình trùng slug? Không — kiểm tra template KHÁC không bị đụng.
        $other = $this->makeSystemTemplate(['slug' => 'other-bank-card', 'name' => 'Other Bank Card']);
        $otherBlueprint = $this->makeMsbBlueprint($other);

        $this->runFix();
        $this->runFix();

        $msbBlueprint->refresh();
        $otherBlueprint->refresh();

        $this->assertSame('300000.00', $msbBlueprint->max_cashback_total_per_period);
        $this->assertSame('Chính sách hệ thống MSB Online Visa.', $msbBlueprint->note);

        $this->assertSame('800000.00', $otherBlueprint->max_cashback_total_per_period, 'Template khác KHÔNG bị đụng.');
        $this->assertSame('Blueprint version 1 của template "MB Ultimate JCB".', $otherBlueprint->note);
    }

    #[Test]
    public function it_does_not_change_tier_or_rule_data(): void
    {
        $template = $this->makeSystemTemplate(['slug' => 'msb-online-visa', 'name' => 'MSB Online Visa']);
        $blueprint = $this->makeMsbBlueprint($template);

        $tier = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Bậc 2 - Từ 3tr',
            'sort_order' => 1,
            'min_total_spend' => '3000000.00',
            'max_total_spend' => null,
            'max_cashback_per_period' => '300000.00',
        ]);

        $rule = PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $this->makeSystemCategory()->id,
            'name' => 'Shopee',
            'sort_order' => 0,
            'spend_from' => 0,
            'cashback_percent' => '10.000',
            'max_cashback_per_category_per_period' => '300000.00',
            'is_enabled' => true,
            'counts_toward_tier_cap' => true,
        ]);

        $this->runFix();

        $this->assertSame('300000.00', $tier->refresh()->max_cashback_per_period, 'Trần bậc không đổi.');
        $this->assertSame('10.000', $rule->refresh()->cashback_percent, 'Tỷ lệ rule không đổi.');
        $this->assertSame('300000.00', $rule->max_cashback_per_category_per_period);
    }

    #[Test]
    public function it_skips_safely_when_template_absent(): void
    {
        // Không tạo template 'msb-online-visa' ⇒ migration bỏ qua, không lỗi.
        $this->runFix();

        $this->assertSame(0, PolicyTemplate::query()->where('slug', 'msb-online-visa')->count());
    }
}
