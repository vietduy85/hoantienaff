<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CardPolicyMccMapping;
use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccRowBenefit;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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
            ->forTemplate($templateId)
            ->with(['rows.codes', 'documents'])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (PolicyMccProfile $profile): array => [
                'id' => (int) $profile->id,
                'name' => $profile->name,
                'note' => $profile->note,
                // Phí thường niên (VNĐ/năm) — CHỈ tham khảo, NULL = chưa có dữ liệu.
                'annual_fee_primary' => $profile->annual_fee_primary,
                'annual_fee_supplementary' => $profile->annual_fee_supplementary,
                'annual_spend_for_annual_fee_waiver' => $profile->annual_spend_for_annual_fee_waiver,
                'documents' => $this->profileDocumentsPayload($profile),
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

        // Gộp các kết quả của CÙNG một sản phẩm dùng chung tài liệu cho nhiều hạng
        // thẻ (VIB Max Card Visa / VIB One Card Visa) về MỘT kết quả đại diện.
        $matches = $this->mergeProductMatches($matches);

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

        // Gắn tỷ lệ/bậc hoàn tiền THỰC TẾ của danh mục, lấy từ blueprint của
        // chính sách liên kết (KHÔNG suy diễn từ tên danh mục hay ghi chú tự do).
        $matches = $this->attachCashback($matches);

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
            ->with(['template:id,name', 'documents', 'rows.codes', 'rows.benefits.document'])
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
     * Chương trình dùng chung nhiều hạng thẻ: một hồ sơ áp dụng cho NHIỀU template
     * (owner + liên kết) nên phạm vi loại trừ được mở rộng cho MỌI template đó.
     *
     * @return array<int, int>
     */
    private function profileExcludedTemplateIds(string $code): array
    {
        return PolicyMccProfile::query()
            ->whereHas('rows', fn (Builder $rows) => $rows
                ->where('row_type', PolicyMccProfileRow::TYPE_EXCLUDED)
                ->whereHas('codes', fn (Builder $codes) => $codes->where('mcc', $code)))
            ->with('templateLinks:id,policy_mcc_profile_id,policy_template_id')
            ->get()
            ->flatMap(fn (PolicyMccProfile $profile): array => $profile->allTemplateIds())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function profileMatch(PolicyMccProfile $profile, PolicyMccProfileRow $row, ?string $policyName, string $code, string $relation): array
    {
        $source = $row->benefits->first()?->document;

        // Chương trình dùng chung nhiều hạng thẻ: hiển thị TÊN SẢN PHẨM đại diện,
        // không hiển thị tên hạng thẻ vận chuyển dữ liệu (Starter / Gold...).
        $productName = $profile->product_name;

        return [
            'id' => 'profile#'.$profile->id.'#'.$row->id,
            'source' => 'policy_mcc_profile',
            'policy_template_id' => (int) $profile->policy_template_id,
            'policy_name' => $productName !== null && $productName !== '' ? $productName : $policyName,
            'product_name' => $productName,
            'relation' => $relation,
            'relation_label' => $this->relationLabel($relation),
            'exclusion_scope' => $relation === 'excluded' ? 'policy' : null,
            'profile_name' => $profile->name,
            'group_reference_name' => $row->category_name,
            'mcc' => $code,
            'match_type' => 'profile',
            'category_id' => null,
            'category_name' => $row->category_name,
            'note' => $row->note,
            'profile_note' => $profile->note,
            'verification_status' => null,
            'source_pdf' => $source?->original_name,
            'source_page' => $row->benefits->first()?->source_page,
            // Tài liệu ngân hàng TẢI ĐƯỢC của CHÍNH hồ sơ này (disk private, tải
            // qua endpoint kiểm tra quyền). Chỉ gồm file còn tồn tại — không tạo
            // liên kết giả; hồ sơ không có tài liệu ⇒ [].
            'documents' => $this->profileDocumentsPayload($profile),
            // Tỷ lệ/bậc hoàn tiền trích từ tài liệu gốc (chỉ để tra cứu) — KHÔNG
            // suy diễn, KHÔNG lấy từ blueprint.
            'cashback' => $this->benefitCashback($row, $relation),
        ];
    }

    /**
     * Bậc + tỷ lệ hoàn tiền của MỘT dòng MCC, đọc từ bảng tra cứu
     * `credit_card_policy_mcc_row_benefits` (dữ liệu trích tài liệu gốc).
     *
     * @return array{status:string, category_name:?string, tiers:array<int, array<string, mixed>>, highest:?array<string, mixed>, reason:?string}
     */
    private function benefitCashback(PolicyMccProfileRow $row, string $relation): array
    {
        if ($relation === 'excluded') {
            return ['status' => 'excluded', 'category_name' => $row->category_name, 'tiers' => [], 'highest' => null, 'reason' => 'excluded'];
        }

        $benefits = $row->benefits;

        if ($benefits->isEmpty()) {
            return ['status' => 'missing', 'category_name' => $row->category_name, 'tiers' => [], 'highest' => null, 'reason' => 'no_data'];
        }

        $tiers = $benefits
            ->map(fn (PolicyMccRowBenefit $benefit): array => $this->benefitTierPayload($benefit))
            ->values()
            ->all();

        return [
            'status' => 'available',
            'category_name' => $row->category_name,
            'tiers' => $tiers,
            'highest' => $this->highestTier($tiers),
            'reason' => null,
        ];
    }

    /**
     * Bậc hoàn tiền CAO NHẤT từ danh sách tier payload: ưu tiên tỷ lệ số, phá hoà
     * bằng trần lớn hơn. Trả null khi mọi bậc đều không có tỷ lệ (chỉ có nhãn) —
     * không suy diễn.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<string, mixed>|null
     */
    private function highestTier(array $tiers): ?array
    {
        $best = null;
        $bestPercent = null;

        foreach ($tiers as $tier) {
            $percent = $tier['cashback_percent'] ?? null;

            if ($percent === null || $percent === '') {
                continue;
            }

            $percent = (float) $percent;
            $cap = (float) ($tier['cap_amount'] ?? 0);

            if ($best === null || $percent > $bestPercent || ($percent === $bestPercent && $cap > (float) ($best['cap_amount'] ?? 0))) {
                $best = $tier;
                $bestPercent = $percent;
            }
        }

        return $best;
    }

    /**
     * Gộp các kết quả của CÙNG một sản phẩm (`product_name`) về MỘT kết quả đại
     * diện, hợp nhất các bậc hoàn tiền của mọi hạng thẻ để KHÔNG làm mất khác
     * biệt giữa các hạng. Kết quả không khai báo `product_name` giữ nguyên.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function mergeProductMatches(array $matches): array
    {
        $merged = [];
        $groups = [];

        foreach ($matches as $match) {
            $product = $match['product_name'] ?? null;

            if ($product === null || $product === '') {
                $merged[] = $match;

                continue;
            }

            $groups[$product][] = $match;
        }

        foreach ($groups as $product => $group) {
            $merged[] = $this->collapseProductGroup($group, (string) $product);
        }

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $group
     * @return array<string, mixed>
     */
    private function collapseProductGroup(array $group, string $product): array
    {
        $excluded = false;
        $tiers = [];
        $seen = [];

        foreach ($group as $match) {
            if (($match['relation'] ?? null) === 'excluded') {
                $excluded = true;
            }

            foreach (($match['cashback']['tiers'] ?? []) as $tier) {
                $key = implode('|', [
                    $tier['cashback_percent'] ?? '',
                    $tier['rate_label'] ?? '',
                    $tier['min_total_spend'] ?? '',
                    $tier['max_total_spend'] ?? '',
                    $tier['cap_amount'] ?? '',
                    $tier['note'] ?? '',
                ]);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $tiers[] = $tier;
            }
        }

        $representative = $group[0];
        $representative['policy_name'] = $product;
        $representative['product_group_size'] = count($group);

        if ($excluded) {
            $representative['relation'] = 'excluded';
            $representative['relation_label'] = $this->relationLabel('excluded');
            $representative['exclusion_scope'] = 'policy';
            $representative['cashback'] = [
                'status' => 'excluded',
                'category_name' => $representative['category_name'] ?? null,
                'tiers' => [],
                'highest' => null,
                'reason' => 'excluded',
            ];

            return $representative;
        }

        $representative['cashback'] = [
            'status' => $tiers === [] ? 'missing' : 'available',
            'category_name' => $representative['category_name'] ?? null,
            'tiers' => $tiers,
            'highest' => $this->highestTier($tiers),
            'reason' => $tiers === [] ? 'no_data' : null,
        ];

        return $representative;
    }

    /**
     * @return array<string, mixed>
     */
    private function benefitTierPayload(PolicyMccRowBenefit $benefit): array
    {
        return [
            'name' => $benefit->rate_label
                ?? ($benefit->cashback_percent !== null ? $this->number($benefit->cashback_percent).'%' : '—'),
            'cashback_percent' => $benefit->cashback_percent,
            'rate_label' => $benefit->rate_label,
            'min_total_spend' => $benefit->min_spend,
            'max_total_spend' => $benefit->max_spend,
            'cap_amount' => $benefit->cap_amount,
            'cap_period' => $benefit->cap_period,
            'cap_unit' => $benefit->cap_unit,
            'has_rule' => true,
            'note' => $benefit->condition_note,
            'exception' => $benefit->exception_note,
            'source_page' => $benefit->source_page,
        ];
    }

    private function number(mixed $value): string
    {
        $number = (float) $value;

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }

    /**
     * Tài liệu ngân hàng của một hồ sơ — chỉ dành cho phần "Tra cứu thẻ".
     *
     * Chỉ đưa tài liệu mà file còn TỒN TẠI trên disk (không tạo link chết).
     * KHÔNG lộ `path`/`disk` ra view: đường tải là endpoint có kiểm tra quyền,
     * file nằm trên disk private.
     *
     * @return array<int, array{id:int, name:string, size:int, human_size:string, url:string}>
     */
    private function profileDocumentsPayload(PolicyMccProfile $profile): array
    {
        $payload = [];

        foreach ($profile->documents as $document) {
            if (! Storage::disk($document->disk)->exists($document->path)) {
                continue;
            }

            $payload[] = [
                'id' => (int) $document->id,
                'name' => $document->original_name,
                'size' => (int) $document->size,
                'human_size' => $document->humanSize(),
                'url' => route('credit-cards.lookup.document', [(int) $profile->id, (int) $document->id]),
            ];
        }

        return $payload;
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
            'category_id' => $mapping->category_id,
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

    /**
     * Gắn tỷ lệ hoàn tiền lấy từ blueprint vào từng kết quả tra cứu MCC.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function attachCashback(array $matches): array
    {
        if ($matches === []) {
            return $matches;
        }

        $templateIds = [];

        foreach ($matches as $match) {
            $id = (int) ($match['policy_template_id'] ?? 0);

            if ($id > 0) {
                $templateIds[$id] = true;
            }
        }

        $templates = $templateIds === []
            ? collect()
            : PolicyTemplate::query()->whereIn('id', array_keys($templateIds))->get()->keyBy('id');

        foreach ($matches as $index => $match) {
            // Hồ sơ MCC admin nhập đã có sẵn tỷ lệ trích từ tài liệu gốc
            // (`benefitCashback`); không ghi đè bằng dữ liệu blueprint.
            if (($match['source'] ?? null) === 'policy_mcc_profile') {
                continue;
            }

            $template = $templates->get((int) ($match['policy_template_id'] ?? 0));

            $matches[$index]['cashback'] = $this->cashbackSummary(
                $template,
                $match['category_id'] ?? null,
                $match['category_name'] ?? null,
                ($match['relation'] ?? null) === 'excluded',
            );
        }

        return $matches;
    }

    /**
     * Tóm tắt bậc + tỷ lệ hoàn tiền của MỘT danh mục, đọc từ blueprint đang áp
     * dụng của chính sách (`credit_card_policy_tier_categories`).
     *
     * KHÔNG suy diễn tỷ lệ từ tên danh mục: nếu blueprint không có quy tắc danh
     * mục tương ứng thì trả `status = missing` để view ghi rõ "chưa có dữ liệu".
     *
     * @return array{status:string, category_name:?string, tiers:array<int, array<string, mixed>>, reason:?string}
     */
    private function cashbackSummary(?PolicyTemplate $template, ?int $categoryId, ?string $categoryName, bool $excluded): array
    {
        if ($excluded) {
            return ['status' => 'excluded', 'category_name' => $categoryName, 'tiers' => [], 'reason' => 'excluded'];
        }

        $missing = ['status' => 'missing', 'category_name' => $categoryName, 'tiers' => [], 'reason' => null];

        if ($template === null) {
            $missing['reason'] = 'no_template';

            return $missing;
        }

        $blueprint = $template->defaultBlueprint();

        if ($blueprint === null) {
            $missing['reason'] = 'no_blueprint';

            return $missing;
        }

        $blueprint->load(['tiers.tierCategoryRules.category:id,name']);

        $targetCategoryId = $categoryId;

        if ($targetCategoryId === null && $categoryName !== null) {
            $targetCategoryId = $this->resolveCategoryIdByName($blueprint, $categoryName);
        }

        $tiers = [];
        $matched = false;
        $resolvedName = $categoryName;

        foreach ($blueprint->tiers as $tier) {
            $rule = null;

            foreach ($tier->tierCategoryRules as $candidate) {
                if (! $candidate->is_enabled || $candidate->isFallback()) {
                    continue;
                }

                if ($targetCategoryId !== null && (int) $candidate->category_id === (int) $targetCategoryId) {
                    $rule = $candidate;
                    break;
                }
            }

            if ($rule !== null) {
                $matched = true;
                $resolvedName = $rule->category?->name ?? $resolvedName;
            }

            $tiers[] = [
                'name' => $tier->name,
                'min_total_spend' => $tier->min_total_spend,
                'max_total_spend' => $tier->max_total_spend,
                'has_rule' => $rule !== null,
                'cashback_percent' => $rule?->cashback_percent,
                'spend_from' => $rule?->spend_from,
                'spend_to' => $rule?->spend_to,
                'max_cashback_per_transaction' => $rule?->max_cashback_per_transaction,
                'max_cashback_per_category_per_period' => $rule?->max_cashback_per_category_per_period,
                'min_transaction_amount' => $rule?->min_transaction_amount,
                'note' => $rule?->note,
            ];
        }

        if (! $matched) {
            $missing['reason'] = 'not_found';

            return $missing;
        }

        return ['status' => 'available', 'category_name' => $resolvedName, 'tiers' => $tiers, 'reason' => null];
    }

    /**
     * Tìm `category_id` trong blueprint khớp nhất với tên danh mục tự do của hồ
     * sơ MCC. Ưu tiên khớp CHÍNH XÁC, rồi tới tiền tố (ví dụ "Y tế" khớp
     * "Y tế & Bệnh viện"). Không khớp được thì trả null (không suy diễn).
     */
    private function resolveCategoryIdByName(PolicyVersion $blueprint, string $categoryName): ?int
    {
        $needle = $this->normalizeName($categoryName);

        if ($needle === '') {
            return null;
        }

        $bestId = null;
        $bestScore = 0;

        foreach ($blueprint->tiers as $tier) {
            foreach ($tier->tierCategoryRules as $rule) {
                if (! $rule->is_enabled || $rule->isFallback() || $rule->category === null) {
                    continue;
                }

                $score = $this->nameMatchScore($needle, $this->normalizeName((string) $rule->category->name));

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestId = (int) $rule->category_id;
                }
            }
        }

        return $bestScore > 0 ? $bestId : null;
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function nameMatchScore(string $needle, string $haystack): int
    {
        if ($needle === '' || $haystack === '') {
            return 0;
        }

        if ($needle === $haystack) {
            return 3;
        }

        if (str_starts_with($haystack, $needle)) {
            return 2;
        }

        if (str_contains($haystack, $needle) || str_starts_with($needle, $haystack)) {
            return 1;
        }

        return 0;
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
