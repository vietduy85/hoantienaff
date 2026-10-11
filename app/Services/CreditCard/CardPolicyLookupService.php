<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CardPolicyMccMapping;
use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * CardPolicyLookupService — "Tra cứu" cho module Thẻ tín dụng.
 *
 * CHỈ ĐỌC. Không tạo kỳ sao kê, không ghi snapshot, không đụng engine cashback.
 *
 * Hai chức năng:
 *   1. Tra cứu thẻ: tóm tắt một chính sách hoàn tiền (lấy từ `policy_templates`
 *      + blueprint ĐÃ CÓ) và liệt kê các MCC đã mapping với chính sách đó.
 *   2. Tra cứu MCC: cho một mã, trả về mô tả + mọi mapping liên quan, PHÂN BIỆT
 *      RÕ: áp dụng trực tiếp / chỉ nằm trong nhóm / bị loại trừ / chưa đủ dữ liệu.
 *
 * Nguyên tắc dữ liệu: KHÔNG tự suy diễn nghĩa là "được hoàn tiền" chỉ vì tên
 * danh mục nghe hợp lý. Kết quả chỉ dựa trên mapping ĐÃ NHẬP; thiếu thì ghi rõ.
 */
class CardPolicyLookupService
{
    /**
     * Danh sách chính sách user được phép tra cứu (hệ thống + riêng của họ).
     *
     * @return Collection<int, PolicyTemplate>
     */
    public function selectableTemplates(int $userId): Collection
    {
        return PolicyTemplate::query()
            ->selectableBy($userId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Tóm tắt một chính sách từ dữ liệu blueprint hiện có.
     *
     * @return array<string, mixed>
     */
    public function summarize(PolicyTemplate $template): array
    {
        $blueprint = $template->defaultBlueprint();

        $summary = [
            'id' => (int) $template->id,
            'name' => $template->name,
            'scope' => $template->isSystemScope() ? 'system' : 'user',
            'description' => $template->description,
            'is_builtin' => (bool) $template->is_builtin,
            'has_blueprint' => $blueprint !== null,
            'min_total_spend' => null,
            'max_cashback_total_per_period' => null,
            'rounding_mode' => null,
            'note' => null,
            'tiers' => [],
        ];

        if ($blueprint === null) {
            return $summary;
        }

        $blueprint->load([
            'tiers.tierCategoryRules.category:id,name',
            'tiers.tierCategoryRules.combo:id,name',
        ]);

        $summary['min_total_spend'] = $blueprint->min_total_spend;
        $summary['max_cashback_total_per_period'] = $blueprint->max_cashback_total_per_period;
        $summary['rounding_mode'] = $blueprint->rounding_mode;
        $summary['note'] = $blueprint->note;

        foreach ($blueprint->tiers as $tier) {
            $summary['tiers'][] = [
                'name' => $tier->name,
                'min_total_spend' => $tier->min_total_spend,
                'max_total_spend' => $tier->max_total_spend,
                'max_cashback_per_period' => $tier->max_cashback_per_period,
                'rules' => $tier->tierCategoryRules
                    ->map(fn (PolicyTierCategory $rule): array => $this->rulePayload($rule))
                    ->values()
                    ->all(),
            ];
        }

        return $summary;
    }

    /**
     * Mapping MCC của một chính sách, đã chuẩn hoá cho view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mappingsForTemplate(int $templateId): array
    {
        return CardPolicyMccMapping::query()
            ->where('policy_template_id', $templateId)
            ->with(['category:id,name'])
            ->orderBy('match_type')
            ->orderBy('mcc')
            ->orderBy('mcc_group')
            ->orderBy('id')
            ->get()
            ->map(fn (CardPolicyMccMapping $mapping): array => $this->mappingPayload($mapping, $this->templateRelationLabel($mapping)))
            ->values()
            ->all();
    }

    /**
     * Hồ sơ MCC admin nhập tay cho một chính sách (module "Quản lý MCC").
     *
     * @return array<int, array<string, mixed>>
     */
    public function mccProfilesForTemplate(int $templateId): array
    {
        return PolicyMccProfile::query()
            ->where('policy_template_id', $templateId)
            ->with('rows.codes')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (PolicyMccProfile $profile): array => [
                'id' => (int) $profile->id,
                'name' => $profile->name,
                'note' => $profile->note,
                'included' => $profile->rows
                    ->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)
                    ->map(fn (PolicyMccProfileRow $row): array => $this->profileRowPayload($row))
                    ->values()
                    ->all(),
                'excluded' => $profile->rows
                    ->where('row_type', PolicyMccProfileRow::TYPE_EXCLUDED)
                    ->map(fn (PolicyMccProfileRow $row): array => $this->profileRowPayload($row))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Tra cứu một mã MCC: mô tả + mọi mapping liên quan.
     *
     * @return array<string, mixed>
     */
    public function lookupMcc(string $code): array
    {
        $master = MccCode::query()->where('mcc', $code)->first();

        $matches = [];

        // MCC nằm trong dòng loại trừ của hồ sơ ⇒ loại trừ thắng mọi áp dụng (kể
        // cả mapping import) của cùng chính sách.
        $excludedTemplateIds = $this->profileExcludedTemplateIds($code);

        CardPolicyMccMapping::query()
            ->whereNotNull('policy_template_id')
            ->whereNotIn('policy_template_id', $excludedTemplateIds === [] ? [0] : $excludedTemplateIds)
            ->with(['template:id,name', 'category:id,name'])
            ->orderBy('policy_template_id')
            ->orderBy('id')
            ->get()
            ->each(function (CardPolicyMccMapping $mapping) use ($code, &$matches): void {
                $relation = $this->classify($mapping, $code);

                if ($relation === null) {
                    return;
                }

                $matches[] = $this->mappingPayload($mapping, $relation);
            });

        foreach ($this->adminProfileMatches($code) as $match) {
            $matches[] = $match;
        }

        // Sắp theo độ ưu tiên: trực tiếp → nhóm → loại trừ → áp dụng rộng → chưa xác minh.
        $priority = [
            'direct' => 0,
            'group' => 1,
            'excluded' => 2,
            'broad' => 3,
            'unverified' => 4,
        ];

        usort($matches, function (array $a, array $b) use ($priority): int {
            $rank = ($priority[$a['relation']] ?? 9) <=> ($priority[$b['relation']] ?? 9);

            return $rank !== 0 ? $rank : strcmp((string) $a['policy_name'], (string) $b['policy_name']);
        });

        $known = $master !== null || $matches !== [];

        return [
            'code' => $code,
            'master' => $master === null ? null : [
                'mcc' => $master->mcc,
                'description_en' => $master->description_en,
                'description_vi' => $master->description_vi,
                'keywords' => $master->keywords,
                'source' => $master->source,
                'verification_status' => $master->verification_status,
                'verified_at' => $master->verified_at?->toDateString(),
            ],
            'known' => $known,
            'matches' => $matches,
            'note' => $this->lookupNote($known, $matches),
        ];
    }

    /**
     * Nhãn quan hệ MCC ↔ chính sách khi liệt kê mapping của MỘT chính sách.
     */
    private function templateRelationLabel(CardPolicyMccMapping $mapping): string
    {
        if ($mapping->isExcluded()) {
            return 'excluded';
        }

        return match ($mapping->match_type) {
            CardPolicyMccMapping::MATCH_DIRECT => 'direct',
            CardPolicyMccMapping::MATCH_GROUP => 'group',
            CardPolicyMccMapping::MATCH_ALL => 'broad',
            CardPolicyMccMapping::MATCH_ALL_EXCEPT => 'broad_except',
            default => 'unverified',
        };
    }

    /**
     * Phân loại một mapping với một mã cụ thể. `null` = mapping KHÔNG liên quan
     * tới mã này nên không đưa vào kết quả.
     */
    private function classify(CardPolicyMccMapping $mapping, string $code): ?string
    {
        // Mã có THUỘC quy tắc không? `all_except` phủ MỌI mã (mã trong danh sách
        // ngoại lệ vẫn thuộc quy tắc — chỉ là bị loại trừ), nên luôn inScope.
        $inScope = match ($mapping->match_type) {
            CardPolicyMccMapping::MATCH_DIRECT => $mapping->mcc === $code,
            CardPolicyMccMapping::MATCH_GROUP => in_array($code, $mapping->mcc_codes ?? [], true),
            CardPolicyMccMapping::MATCH_ALL,
            CardPolicyMccMapping::MATCH_ALL_EXCEPT => true,
            default => false,
        };

        if (! $inScope) {
            return null;
        }

        // Chưa xác minh hoặc chưa kết luận được ⇒ nói thẳng là "chưa đủ dữ liệu",
        // KHÔNG coi là áp dụng chỉ vì tên danh mục nghe hợp lý.
        if ($mapping->verification_status !== 'verified'
            || $mapping->application_status === CardPolicyMccMapping::STATUS_UNVERIFIED) {
            return 'unverified';
        }

        return match ($mapping->match_type) {
            CardPolicyMccMapping::MATCH_DIRECT => $mapping->isExcluded() ? 'excluded' : 'direct',
            CardPolicyMccMapping::MATCH_GROUP => $mapping->isExcluded() ? 'excluded' : 'group',
            CardPolicyMccMapping::MATCH_ALL => $mapping->isExcluded() ? 'excluded' : 'broad',
            CardPolicyMccMapping::MATCH_ALL_EXCEPT => in_array($code, $mapping->mcc_codes ?? [], true)
                ? 'excluded'
                : 'broad',
            default => null,
        };
    }

    /**
     * Kết quả tra cứu MCC đến từ HỒ SƠ MCC admin nhập (module "Quản lý MCC").
     *
     * Quy tắc (theo §6): trong CÙNG một chính sách, mã nằm ở bất kỳ dòng `excluded`
     * của bất kỳ hồ sơ nào ⇒ LOẠI TRỪ thắng, bỏ mọi dòng `included` của chính sách
     * đó. Ngược lại, mỗi dòng `included` chứa mã ⇒ một kết quả quan hệ `group`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function adminProfileMatches(string $code): array
    {
        $profiles = PolicyMccProfile::query()
            ->with(['template:id,name', 'rows.codes'])
            ->orderBy('policy_template_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $matches = [];

        foreach ($profiles->groupBy('policy_template_id') as $templateId => $templateProfiles) {
            $templateId = (int) $templateId;
            $policyName = $templateProfiles->first()->template?->name;

            $excluded = $this->matchingRows($templateProfiles, $code, PolicyMccProfileRow::TYPE_EXCLUDED);

            if ($excluded !== []) {
                foreach ($excluded as [$profile, $row]) {
                    $matches[] = $this->profileMatch($profile, $row, $policyName, $code, 'excluded');
                }

                continue; // Loại trừ thắng mọi áp dụng của cùng chính sách.
            }

            foreach ($this->matchingRows($templateProfiles, $code, PolicyMccProfileRow::TYPE_INCLUDED) as [$profile, $row]) {
                $matches[] = $this->profileMatch($profile, $row, $policyName, $code, 'group');
            }
        }

        return $matches;
    }

    /**
     * Các cặp [hồ sơ, dòng] của một loại dòng chứa mã MCC đang tra.
     *
     * @param  Collection<int, PolicyMccProfile>  $profiles
     * @return array<int, array{0: PolicyMccProfile, 1: PolicyMccProfileRow}>
     */
    private function matchingRows($profiles, string $code, string $rowType): array
    {
        $matches = [];

        foreach ($profiles as $profile) {
            foreach ($profile->rows->where('row_type', $rowType) as $row) {
                if ($row->codes->contains(fn ($codeRow): bool => (string) $codeRow->mcc === $code)) {
                    $matches[] = [$profile, $row];
                }
            }
        }

        return $matches;
    }

    /**
     * Các chính sách có hồ sơ chứa mã này trong dòng loại trừ.
     *
     * @return array<int, int>
     */
    private function profileExcludedTemplateIds(string $code): array
    {
        return PolicyMccProfile::query()
            ->whereHas('rows', fn (Builder $rows) => $rows
                ->where('row_type', PolicyMccProfileRow::TYPE_EXCLUDED)
                ->whereHas('codes', fn (Builder $codes) => $codes->where('mcc', $code)))
            ->pluck('policy_template_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function profileMatch(PolicyMccProfile $profile, PolicyMccProfileRow $row, ?string $policyName, string $code, string $relation): array
    {
        return [
            'id' => 'profile#'.$profile->id.'#'.$row->id,
            'source' => 'policy_mcc_profile',
            'policy_template_id' => (int) $profile->policy_template_id,
            'policy_name' => $policyName,
            'relation' => $relation,
            'relation_label' => $this->relationLabel($relation),
            'exclusion_scope' => $relation === 'excluded' ? 'policy' : null,
            'profile_name' => $profile->name,
            'group_reference_name' => $row->category_name,
            'mcc' => $code,
            'match_type' => 'profile',
            'category_name' => $row->category_name,
            'note' => $row->note !== null && $row->note !== '' ? $row->note : $profile->note,
            'verification_status' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileRowPayload(PolicyMccProfileRow $row): array
    {
        return [
            'category_name' => $row->category_name,
            'description' => $row->description,
            'note' => $row->note,
            'codes' => $row->codes
                ->pluck('mcc')
                ->map(fn ($code): string => (string) $code)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mappingPayload(CardPolicyMccMapping $mapping, string $relation): array
    {
        return [
            'id' => (int) $mapping->id,
            'policy_template_id' => $mapping->policy_template_id,
            'policy_name' => $mapping->template?->name,
            'match_type' => $mapping->match_type,
            'relation' => $relation,
            'relation_label' => $this->relationLabel($relation),
            'application_status' => $mapping->application_status,
            'mcc' => $mapping->mcc,
            'mcc_group' => $mapping->mcc_group,
            'mcc_codes' => array_values($mapping->mcc_codes ?? []),
            'category_name' => $mapping->category?->name,
            'cashback_percent' => $mapping->cashback_percent,
            'condition_note' => $mapping->condition_note,
            'exclusion_reason' => $mapping->exclusion_reason,
            'source_pdf' => $mapping->source_pdf,
            'source_page' => $mapping->source_page,
            'verification_status' => $mapping->verification_status,
            'verified_at' => $mapping->verified_at?->toDateString(),
        ];
    }

    private function relationLabel(string $relation): string
    {
        return match ($relation) {
            'direct' => 'Áp dụng trực tiếp',
            'group' => 'Thuộc nhóm MCC',
            'excluded' => 'Bị loại trừ',
            'broad' => 'Áp dụng rộng (mọi MCC / trừ danh sách ngoại lệ)',
            'broad_except' => 'Áp dụng trừ danh sách MCC ngoại lệ',
            default => 'Chưa đủ dữ liệu để xác nhận',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     */
    private function lookupNote(bool $known, array $matches): ?string
    {
        if (! $known) {
            return 'Không tìm thấy MCC này trong dữ liệu hệ thống.';
        }

        if ($matches === []) {
            return 'Chưa có dữ liệu mapping nào cho MCC này. Kết quả có thể chưa đầy đủ.';
        }

        foreach ($matches as $match) {
            // Kết quả nhóm admin nhập có trường `source`; không xét trạng thái xác
            // minh của dữ liệu import cho các kết quả này.
            if (isset($match['source'])) {
                continue;
            }

            if (($match['verification_status'] ?? null) !== 'verified') {
                return 'Một số kết quả chưa được xác minh — hãy kiểm tra lại với tài liệu gốc của chính sách.';
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rulePayload(PolicyTierCategory $rule): array
    {
        return [
            'name' => $this->targetName($rule),
            'is_fallback' => $rule->isFallback(),
            'is_combo' => $rule->isComboSpecific(),
            'scope_type' => $rule->scope_type,
            'cashback_percent' => $rule->cashback_percent,
            'spend_from' => $rule->spend_from,
            'spend_to' => $rule->spend_to,
            'max_cashback_per_transaction' => $rule->max_cashback_per_transaction,
            'max_cashback_per_category_per_period' => $rule->max_cashback_per_category_per_period,
            'min_transaction_amount' => $rule->min_transaction_amount,
            'is_enabled' => (bool) $rule->is_enabled,
            'note' => $rule->note,
        ];
    }

    private function targetName(PolicyTierCategory $rule): string
    {
        if ($rule->isFallback()) {
            return PolicyTierCategory::FALLBACK_NAME;
        }

        if ($rule->isComboSpecific()) {
            return $rule->combo?->name ?? 'Combo #'.$rule->combo_id;
        }

        return $rule->category?->name ?? 'Danh mục #'.$rule->category_id;
    }
}
