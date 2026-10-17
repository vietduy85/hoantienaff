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
use Illuminate\Support\Str;

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

        // Gắn tỷ lệ/bậc hoàn tiền THỰC TẾ của danh mục, lấy từ blueprint của
        // chính sách liên kết (KHÔNG suy diễn từ tên danh mục hay ghi chú tự do).
        // Thực hiện TRƯỚC khi sắp xếp để thứ tự dựa trên dữ liệu cashback thật.
        $matches = $this->attachCashback($matches);

        // Sắp theo dữ liệu cashback: tỷ lệ cao nhất trước, cùng tỷ lệ thì trần
        // cao hơn trước; thẻ thiếu dữ liệu xếp sau thẻ so sánh được; loại trừ
        // cuối cùng. Hoà bằng hoàn toàn theo tên + id cho kết quả ổn định.
        $matches = $this->sortMatchesByCashback($matches);

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
     * Danh sách lựa chọn danh mục cho tab "Tra cứu danh mục".
     *
     * Nguồn: TÊN DANH MỤC trong các dòng INCLUDED của mọi HỒ SƠ MCC. Tên được
     * chuẩn hoá rồi gom nhóm theo `config/credit_card_mcc_categories.php`; tên
     * chưa khai báo giữ RIÊNG một lựa chọn (key `raw:*`) — không tự gộp. Nhờ đó
     * hồ sơ mới tự xuất hiện mà không cần sửa code.
     *
     * @return array<int, array{id:string, label:string, sublabel:?string}>
     */
    public function categoryOptions(): array
    {
        $aliases = $this->categoryAliasMap();
        $groups = $this->categoryGroupLabels();
        $rank = array_flip(array_keys($groups));

        $options = [];

        PolicyMccProfile::query()
            ->with(['rows' => fn ($query) => $query->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)])
            ->get()
            ->each(function (PolicyMccProfile $profile) use (&$options, $aliases, $groups): void {
                foreach ($profile->rows as $row) {
                    foreach ($this->categoryKeysFor($row->category_name, $aliases) as $key) {
                        if (isset($options[$key])) {
                            continue;
                        }

                        $options[$key] = [
                            'id' => $key,
                            'label' => $groups[$key] ?? (string) $row->category_name,
                            'sublabel' => null,
                        ];
                    }
                }
            });

        uasort($options, function (array $a, array $b) use ($rank): int {
            $rankA = $rank[$a['id']] ?? PHP_INT_MAX;
            $rankB = $rank[$b['id']] ?? PHP_INT_MAX;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return strcmp($a['label'], $b['label']);
        });

        return array_values($options);
    }

    /**
     * Tra cứu các thẻ hoàn tiền theo MỘT danh mục (đã chuẩn hoá).
     *
     * CHỈ xét dòng INCLUDED — dòng loại trừ KHÔNG tạo kết quả. Một thẻ có nhiều
     * dòng cùng danh mục được gộp về MỘT kết quả; chương trình dùng chung nhiều
     * hạng thẻ (`product_name`) gộp một kết quả nhưng giữ mọi khác biệt bậc
     * (không dùng bậc của hạng khác, không nhân bản thẻ).
     *
     * @return array{category:array{key:string, label:string, matched_names:array<int, string>}, matches:array<int, array<string, mixed>>, found:bool}
     */
    public function lookupCategory(string $categoryKey): array
    {
        $aliases = $this->categoryAliasMap();
        $groups = $this->categoryGroupLabels();

        $matches = [];
        $matchedNames = [];

        PolicyMccProfile::query()
            ->with(['template:id,name', 'documents', 'rows.codes', 'rows.benefits.document'])
            ->orderBy('policy_template_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->each(function (PolicyMccProfile $profile) use ($categoryKey, $aliases, &$matches, &$matchedNames): void {
                $rows = $profile->rows
                    ->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)
                    ->filter(fn (PolicyMccProfileRow $row): bool => in_array($categoryKey, $this->categoryKeysFor($row->category_name, $aliases), true))
                    ->values();

                if ($rows->isEmpty()) {
                    return;
                }

                foreach ($rows as $row) {
                    if ($row->category_name !== null && $row->category_name !== '') {
                        $matchedNames[(string) $row->category_name] = true;
                    }
                }

                $matches[] = $this->categoryProfileMatch($profile, $rows);
            });

        // Gộp chương trình dùng chung nhiều hạng thẻ về một kết quả đại diện.
        $matches = $this->mergeProductMatches($matches);

        // Sắp theo dữ liệu cashback CHÍNH THỨC — cùng quy tắc với tab "Tra cứu
        // MCC": tỷ lệ cao nhất trước, cùng tỷ lệ thì trần cao hơn trước; ưu đãi
        // bổ sung KHÔNG tham gia xếp hạng; hoà thì theo tên + id (ổn định).
        $matches = $this->sortMatchesByCashback($matches);

        return [
            'category' => [
                'key' => $categoryKey,
                'label' => $groups[$categoryKey] ?? (array_key_first($matchedNames) ?? $categoryKey),
                'matched_names' => array_keys($matchedNames),
            ],
            'matches' => $matches,
            'found' => $matches !== [],
        ];
    }

    /**
     * Một kết quả "thẻ hoàn tiền" cho tab danh mục: gộp MỌI dòng included của
     * hồ sơ thuộc danh mục đang tra về một kết quả (không nhân bản thẻ), hợp
     * nhất bậc/tỷ lệ để giữ khác biệt giữa các hạng.
     *
     * @param  Collection<int, PolicyMccProfileRow>  $rows
     * @return array<string, mixed>
     */
    private function categoryProfileMatch(PolicyMccProfile $profile, $rows): array
    {
        $productName = $profile->product_name;

        $tiers = [];
        $seenTiers = [];
        $codes = [];
        $names = [];

        foreach ($rows as $row) {
            if ($row->category_name !== null && $row->category_name !== '') {
                $names[(string) $row->category_name] = (string) $row->category_name;
            }

            foreach ($row->codes as $codeRow) {
                $codes[(string) $codeRow->mcc] = (string) $codeRow->mcc;
            }

            foreach ($row->benefits as $benefit) {
                $tier = $this->benefitTierPayload($benefit);
                $tierKey = implode('|', [
                    $tier['cashback_percent'] ?? '',
                    $tier['rate_label'] ?? '',
                    $tier['min_total_spend'] ?? '',
                    $tier['max_total_spend'] ?? '',
                    $tier['cap_amount'] ?? '',
                    $tier['note'] ?? '',
                ]);

                if (isset($seenTiers[$tierKey])) {
                    continue;
                }

                $seenTiers[$tierKey] = true;
                $tiers[] = $tier;
            }
        }

        ksort($codes);
        $codeList = array_values($codes);
        $categoryLabel = implode(', ', array_values($names));

        $cashback = $tiers === []
            ? $this->missingCashback($categoryLabel)
            : $this->availableCashback($categoryLabel, $tiers);

        // Dòng theo MÔ TẢ (không có mã MCC): ghi rõ để không hiểu nhầm là đã
        // kiểm tra danh sách mã MCC đầy đủ.
        $descriptiveNote = null;

        if ($codeList === []) {
            $descriptiveNote = ($profile->note !== null && $profile->note !== '')
                ? $profile->note
                : 'Quyền lợi theo mô tả của ngân hàng — chưa có danh sách mã MCC cụ thể.';
        }

        return [
            'id' => 'category#'.$profile->id,
            'source' => 'policy_mcc_profile',
            'policy_template_id' => (int) $profile->policy_template_id,
            'policy_name' => ($productName !== null && $productName !== '') ? $productName : $profile->template?->name,
            'product_name' => $productName,
            'relation' => 'group',
            'relation_label' => $this->relationLabel('group'),
            'exclusion_scope' => null,
            'profile_name' => $profile->name,
            'group_reference_name' => $categoryLabel,
            'mcc' => null,
            'match_type' => 'category',
            'category_id' => null,
            'category_name' => $categoryLabel,
            'note' => null,
            'descriptive_note' => $descriptiveNote,
            'profile_note' => $profile->note,
            'verification_status' => null,
            'source_pdf' => $rows->first()?->benefits->first()?->document?->original_name,
            'source_page' => null,
            'documents' => $this->profileDocumentsPayload($profile),
            'cashback' => $cashback,
            'mcc_group' => null,
            'mcc_codes' => $codeList,
        ];
    }

    /**
     * Bản đồ tên gốc đã chuẩn hoá => khoá chuẩn (hoặc mảng khoá).
     *
     * @return array<string, string|array<int, string>>
     */
    private function categoryAliasMap(): array
    {
        $map = [];

        foreach ((array) config('credit_card_mcc_categories.aliases', []) as $raw => $value) {
            $normalized = $this->normalizeCategoryKey((string) $raw);

            if ($normalized !== '') {
                $map[$normalized] = $value;
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function categoryGroupLabels(): array
    {
        return (array) config('credit_card_mcc_categories.groups', []);
    }

    /**
     * Khoá danh mục chuẩn của một TÊN GỐC: theo `aliases`, ngược lại giữ riêng
     * dưới khoá `raw:<tên-chuẩn-hoá>`.
     *
     * @param  array<string, string|array<int, string>>  $aliases
     * @return array<int, string>
     */
    private function categoryKeysFor(?string $name, array $aliases): array
    {
        $normalized = $this->normalizeCategoryKey((string) $name);

        if ($normalized === '') {
            return [];
        }

        if (isset($aliases[$normalized])) {
            return array_values(array_unique((array) $aliases[$normalized]));
        }

        return ['raw:'.$normalized];
    }

    /**
     * Chuẩn hoá tên danh mục để gom nhóm: thường hoá, bỏ dấu, bỏ dấu câu, gộp
     * khoảng trắng. KHÔNG đổi tên gốc lưu trong hồ sơ.
     */
    private function normalizeCategoryKey(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = Str::ascii($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
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
                foreach ($this->groupRowsByProfile($excluded) as [$profile, $rows]) {
                    $matches[] = $this->mergedProfileMatch($profile, $rows, $policyName, $code, 'excluded');
                }

                continue; // Loại trừ thắng mọi áp dụng của cùng chính sách.
            }

            $included = $this->matchingRows($templateProfiles, $code, PolicyMccProfileRow::TYPE_INCLUDED);

            foreach ($this->groupRowsByProfile($included) as [$profile, $rows]) {
                $matches[] = $this->mergedProfileMatch($profile, $rows, $policyName, $code, 'group');
            }
        }

        return $matches;
    }

    /**
     * Gom các cặp [hồ sơ, dòng] theo hồ sơ để MỘT thẻ chỉ tạo MỘT kết quả
     * (một hồ sơ có nhiều dòng cùng chứa mã đang tra ⇒ gộp, không nhân bản).
     *
     * @param  array<int, array{0: PolicyMccProfile, 1: PolicyMccProfileRow}>  $pairs
     * @return array<int, array{0: PolicyMccProfile, 1: array<int, PolicyMccProfileRow>}>
     */
    private function groupRowsByProfile(array $pairs): array
    {
        $grouped = [];

        foreach ($pairs as [$profile, $row]) {
            $grouped[$profile->id][] = [$profile, $row];
        }

        return array_values(array_map(
            fn (array $items): array => [$items[0][0], array_column($items, 1)],
            $grouped,
        ));
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
     * Một kết quả tra cứu MCC cho MỘT hồ sơ, gộp MỌI dòng của hồ sơ chứa mã
     * đang tra về một kết quả (không nhân bản thẻ). Khi chỉ có một dòng, giữ
     * nguyên payload của {@see profileMatch()}; khi nhiều dòng, hợp nhất tên
     * danh mục + bậc hoàn tiền (khử trùng lặp) và giữ tỷ lệ cao nhất cùng điều
     * kiện của chính bậc đó. Không cộng trần của các dòng với nhau.
     *
     * @param  array<int, PolicyMccProfileRow>  $rows
     * @return array<string, mixed>
     */
    private function mergedProfileMatch(PolicyMccProfile $profile, array $rows, ?string $policyName, string $code, string $relation): array
    {
        $rows = array_values($rows);
        $match = $this->profileMatch($profile, $rows[0], $policyName, $code, $relation);

        if (count($rows) === 1) {
            return $match;
        }

        $names = [];
        $notes = [];
        $tiers = [];
        $seen = [];

        foreach ($rows as $row) {
            if ($row->category_name !== null && $row->category_name !== '') {
                $names[(string) $row->category_name] = (string) $row->category_name;
            }

            if ($row->note !== null && $row->note !== '') {
                $notes[(string) $row->note] = (string) $row->note;
            }

            if ($relation === 'excluded') {
                continue;
            }

            foreach ($row->benefits as $benefit) {
                $tier = $this->benefitTierPayload($benefit);
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

        $categoryLabel = $names === [] ? $match['category_name'] : implode(', ', array_values($names));

        $match['group_reference_name'] = $categoryLabel;
        $match['category_name'] = $categoryLabel;
        $match['note'] = $notes === [] ? null : implode('; ', array_values($notes));

        if ($relation !== 'excluded') {
            $match['cashback'] = $tiers === []
                ? $this->missingCashback($categoryLabel)
                : $this->availableCashback($categoryLabel, $tiers);
        }

        return $match;
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
            return $this->excludedCashback($row->category_name);
        }

        $benefits = $row->benefits;

        if ($benefits->isEmpty()) {
            return $this->missingCashback($row->category_name);
        }

        $tiers = $benefits
            ->map(fn (PolicyMccRowBenefit $benefit): array => $this->benefitTierPayload($benefit))
            ->values()
            ->all();

        return $this->availableCashback($row->category_name, $tiers);
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
     * Tách danh sách bậc thành [chính thức, bổ sung]. Bậc có `is_bonus = true`
     * là ƯU ĐÃI BỔ SUNG; còn lại (kể cả dữ liệu blueprint không có khoá này) là
     * CASHBACK CHÍNH THỨC.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function partitionTiersByKind(array $tiers): array
    {
        $official = [];
        $bonus = [];

        foreach ($tiers as $tier) {
            if (($tier['is_bonus'] ?? false) === true) {
                $bonus[] = $tier;
            } else {
                $official[] = $tier;
            }
        }

        return [array_values($official), array_values($bonus)];
    }

    /**
     * Payload `cashback` khi CÓ dữ liệu: tách bậc chính thức/bổ sung. `highest`
     * CHỈ tính trên bậc CHÍNH THỨC (dùng để xếp hạng + hiển thị "Hoàn tiền chính
     * thức"); `highest_bonus` để hiển thị riêng "Ưu đãi bổ sung", KHÔNG xếp hạng.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @return array<string, mixed>
     */
    private function availableCashback(?string $categoryName, array $tiers): array
    {
        [$official, $bonus] = $this->partitionTiersByKind($tiers);

        return [
            'status' => 'available',
            'category_name' => $categoryName,
            'tiers' => array_values($tiers),
            'official_tiers' => $official,
            'bonus_tiers' => $bonus,
            'highest' => $this->highestTier($official),
            'highest_bonus' => $this->highestTier($bonus),
            'reason' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingCashback(?string $categoryName, string $reason = 'no_data'): array
    {
        return [
            'status' => 'missing',
            'category_name' => $categoryName,
            'tiers' => [],
            'official_tiers' => [],
            'bonus_tiers' => [],
            'highest' => null,
            'highest_bonus' => null,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function excludedCashback(?string $categoryName): array
    {
        return [
            'status' => 'excluded',
            'category_name' => $categoryName,
            'tiers' => [],
            'official_tiers' => [],
            'bonus_tiers' => [],
            'highest' => null,
            'highest_bonus' => null,
            'reason' => 'excluded',
        ];
    }

    /**
     * Sắp xếp kết quả "Tra cứu MCC" theo dữ liệu cashback thực tế:
     *  1. Có tỷ lệ số so sánh được → thiếu dữ liệu tỷ lệ (gồm cả "chưa xác
     *     minh") → bị loại trừ;
     *  2. Tỷ lệ hoàn tiền giảm dần (so sánh BẰNG SỐ, không bằng chuỗi);
     *  3. Trần hoàn tiền giảm dần — NULL = nguồn không nêu trần, KHÔNG coi là
     *     0đ và KHÔNG tự gán "không giới hạn" ⇒ xếp sau bậc có trần số khi cùng
     *     tỷ lệ;
     *  4. Tên chính sách tăng dần, rồi id — thứ tự ổn định giữa các lần truy vấn.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function sortMatchesByCashback(array $matches): array
    {
        $decorated = array_map(fn (array $match): array => [
            'metrics' => $this->cashbackSortMetrics($match),
            'match' => $match,
        ], array_values($matches));

        usort($decorated, function (array $a, array $b): int {
            return $a['metrics']['rank'] <=> $b['metrics']['rank']
                ?: $b['metrics']['rate'] <=> $a['metrics']['rate']
                ?: $this->capCompareValue($b['metrics']['cap']) <=> $this->capCompareValue($a['metrics']['cap'])
                ?: strcmp((string) ($a['match']['policy_name'] ?? ''), (string) ($b['match']['policy_name'] ?? ''))
                ?: strcmp((string) ($a['match']['id'] ?? ''), (string) ($b['match']['id'] ?? ''));
        });

        return array_map(fn (array $item): array => $item['match'], $decorated);
    }

    /**
     * Khóa xếp hạng + tỷ lệ + trần của MỘT kết quả tra MCC để so sánh.
     *
     * @return array{rank:int, rate:?float, cap:?float}
     */
    private function cashbackSortMetrics(array $match): array
    {
        $relation = (string) ($match['relation'] ?? '');

        // Không được hoàn tiền — luôn sau mọi thẻ có dữ liệu so sánh được.
        if ($relation === 'excluded') {
            return ['rank' => 2, 'rate' => null, 'cap' => null];
        }

        // "Chưa đủ dữ liệu để xác nhận": không xếp theo tỷ lệ chưa xác minh.
        if ($relation === 'unverified') {
            return ['rank' => 1, 'rate' => null, 'cap' => null];
        }

        $bestRate = null;
        $bestCap = null;

        foreach (($match['cashback']['tiers'] ?? []) as $tier) {
            // Bậc không áp dụng cho danh mục này (blueprint không khớp rule).
            if (($tier['has_rule'] ?? true) === false) {
                continue;
            }

            // Ưu đãi BỔ SUNG không phải "cashback chính thức" ⇒ không dùng xếp hạng.
            if (($tier['is_bonus'] ?? false) === true) {
                continue;
            }

            $percent = $tier['cashback_percent'] ?? null;

            // Chỉ có nhãn nguyên văn ("tới 20%") ⇒ không bịa số để so sánh.
            if ($percent === null || $percent === '') {
                continue;
            }

            $percent = (float) $percent;
            $cap = $this->tierCapForSort($tier);

            if ($bestRate === null
                || $percent > $bestRate
                || ($percent === $bestRate && $this->capCompareValue($cap) > $this->capCompareValue($bestCap))) {
                $bestRate = $percent;
                $bestCap = $cap;
            }
        }

        if ($bestRate === null) {
            return ['rank' => 1, 'rate' => null, 'cap' => null];
        }

        return ['rank' => 0, 'rate' => $bestRate, 'cap' => $bestCap];
    }

    /**
     * Trần áp dụng cho một bậc khi so sánh thứ tự: trần quyền lợi/bậc
     * (`cap_amount`, dữ liệu trích tài liệu); kết quả blueprint dùng trần theo
     * danh mục/kỳ (`max_cashback_per_category_per_period`) — KHÔNG cộng dồn các
     * loại trần, KHÔNG lấy trần tổng của cả thẻ.
     *
     * @param  array<string, mixed>  $tier
     */
    private function tierCapForSort(array $tier): ?float
    {
        $cap = $tier['cap_amount'] ?? null;

        if ($cap !== null && $cap !== '') {
            return (float) $cap;
        }

        $cap = $tier['max_cashback_per_category_per_period'] ?? null;

        return ($cap !== null && $cap !== '') ? (float) $cap : null;
    }

    /**
     * Giá trị số để so sánh trần giảm dần: NULL (nguồn không nêu trần) KHÔNG
     * phải 0đ — đặt thấp hơn mọi trần số để xếp sau khi cùng tỷ lệ.
     */
    private function capCompareValue(?float $cap): float
    {
        return $cap ?? -1.0;
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
            $representative['cashback'] = $this->excludedCashback($representative['category_name'] ?? null);

            return $representative;
        }

        $representative['cashback'] = $tiers === []
            ? $this->missingCashback($representative['category_name'] ?? null)
            : $this->availableCashback($representative['category_name'] ?? null, $tiers);

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
            // Phân loại quyền lợi: `bonus` = ưu đãi bổ sung (KHÔNG dùng xếp hạng
            // "cashback chính thức"); `official` = mặc định.
            'benefit_type' => $benefit->benefitKind(),
            'is_bonus' => $benefit->isBonus(),
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
