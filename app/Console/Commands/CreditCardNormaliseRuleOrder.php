<?php

namespace App\Console\Commands;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Services\CreditCard\CategoryRuleService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Chuẩn hoá thứ tự hiển thị của CÁC QUY TẮC HOÀN TIỀN trong một bậc.
 *
 * Thứ tự chuẩn: danh mục cụ thể → combo danh mục → fallback "📦 Các danh mục còn
 * lại" (luôn CUỐI). Trong từng nhóm giữ thứ tự hiện có. Command CHỈ gán lại cột
 * `sort_order` (1..n liên tục) — không đổi tỷ lệ, hạn mức, target, cờ, lịch sử.
 *
 * Chạy trên connection `creditcard` (database `hoantien_creditcard`) qua model.
 *
 * Phạm vi MẶC ĐỊNH: các template hệ thống Shinhan Supreme + VIB Max Card Visa và
 * MỌI policy mang `template_id` của chúng (blueprint + bản đã gắn vào thẻ + mọi
 * phiên bản) — đúng phạm vi danh sách "Rà soát thứ tự quy tắc hoàn tiền". Dùng
 * `--all` nếu muốn chuẩn hoá toàn bộ module.
 *
 * Idempotent: chạy lại không đổi dòng nào nếu dữ liệu đã đúng trật tự.
 */
class CreditCardNormaliseRuleOrder extends Command
{
    protected $signature = 'credit-card:normalise-rule-order
                            {--all : Chuẩn hoá MỌI policy thay vì chỉ template Shinhan Supreme + VIB Max Card Visa}
                            {--templates= : Danh sách template slug cách nhau bằng dấu phẩy; mặc định là 5 template trong phạm vi}';

    protected $description = 'Chuẩn hoá thứ tự quy tắc hoàn tiền (fallback luôn cuối) cho policy thuộc phạm vi Shinhan Supreme + VIB Max Card Visa';

    /**
     * Phạm vi mặc định — khớp với danh sách rà soát.
     */
    private const DEFAULT_TEMPLATE_SLUGS = [
        'shinhan-supreme-visa',
        'vib-max-card-visa-starter',
        'vib-max-card-visa-plus',
        'vib-max-card-visa-pro',
        'vib-max-card-visa-elite',
    ];

    public function handle(CategoryRuleService $rules): int
    {
        $templates = $this->resolveTemplates();

        if ($templates->isEmpty()) {
            $this->warn('Không tìm thấy template nào trong phạm vi.');

            return self::SUCCESS;
        }

        $table = [];
        $changedRules = 0;
        $scannedRules = 0;

        foreach ($templates as $template) {
            $policyCount = 0;
            $tierCount = 0;
            $templateChanged = 0;

            foreach (Policy::query()->where('template_id', $template->id)->orderBy('id')->get() as $policy) {
                $policyCount++;

                foreach (PolicyTier::query()->where('policy_id', $policy->id)->orderBy('id')->get() as $tier) {
                    $tierCount++;
                    $templateChanged += $rules->normaliseRuleOrder($tier);
                }
            }

            $changedRules += $templateChanged;
            $scannedRules += $this->ruleCountFor($template->id);

            $table[] = [
                $template->name,
                $template->slug,
                $policyCount,
                $tierCount,
                $templateChanged,
            ];
        }

        $this->table(
            ['Template', 'Slug', 'Policy', 'Tier', 'Rule đã đổi sort_order'],
            $table
        );

        $this->components->twoColumnDetail('Quy tắc đã rà soát', (string) $scannedRules);
        $this->components->twoColumnDetail('Quy tắc đã đổi sort_order', (string) $changedRules);
        $this->components->success($changedRules === 0
            ? 'Dữ liệu đã đúng thứ tự chuẩn (fallback luôn cuối).'
            : 'Đã chuẩn hoá thứ tự quy tắc cho các policy trong phạm vi.');

        return self::SUCCESS;
    }

    private function resolveTemplates(): Collection
    {
        if ($this->option('all')) {
            return PolicyTemplate::query()->orderBy('id')->get();
        }

        $explicit = $this->option('templates');

        if ($explicit !== null && $explicit !== '') {
            $slugs = array_map('trim', explode(',', (string) $explicit));

            return PolicyTemplate::query()
                ->whereIn('slug', $slugs)
                ->orderBy('id')
                ->get();
        }

        return PolicyTemplate::query()
            ->where('scope', PolicyTemplate::SCOPE_SYSTEM)
            ->whereIn('slug', self::DEFAULT_TEMPLATE_SLUGS)
            ->orderBy('id')
            ->get();
    }

    private function ruleCountFor(int $templateId): int
    {
        $tierIds = PolicyTier::query()
            ->whereIn('policy_id', Policy::query()->where('template_id', $templateId)->select('id'))
            ->select('id');

        return (int) PolicyTierCategory::query()
            ->whereIn('tier_id', $tierIds)
            ->count();
    }
}