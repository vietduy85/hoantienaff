<?php

namespace App\Services\CreditCard\Import;

use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileDocument;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccRowBenefit;
use App\Models\CreditCard\PolicyTemplate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * MccRowBenefitImporter — nạp DỮ LIỆU HOÀN TIỀN theo dòng MCC từ file dữ liệu.
 *
 * Nguồn: `database/data/credit_card_mcc_row_benefits.php` (mảng khoá theo slug
 * template → `category_name` dòng `included` → danh sách bậc hoàn tiền).
 *
 * ---------------------------------------------------------------------------
 * ĐỊNH VỊ (khớp theo cấu trúc, KHÔNG hard-code id)
 * ---------------------------------------------------------------------------
 *  - Template: tra theo `slug`.
 *  - Hồ sơ: hồ sơ đầu tiên của template đó.
 *  - Dòng: dòng `included` có `category_name` trùng khớp.
 *  - Tài liệu nguồn: tài liệu của hồ sơ có `original_name` chứa mảnh `source_doc`
 *    (fallback: tài liệu đầu tiên của hồ sơ).
 *
 * ---------------------------------------------------------------------------
 * IDEMPOTENT + AN TOÀN
 * ---------------------------------------------------------------------------
 *  - Upsert bậc theo UNIQUE (`policy_mcc_row_id`, `tier_order`).
 *  - Sau khi nạp, xoá các bậc thừa (`tier_order` > số bậc mới) để chạy lại sau
 *    khi rút gọn dữ liệu không để lại bậc mồ côi.
 *  - Toàn bộ ghi trong MỘT transaction trên connection `creditcard`.
 *  - KHÔNG đụng `credit_card_mcc_codes`, blueprint, hay dữ liệu engine.
 */
class MccRowBenefitImporter
{
    private const DEFAULT_DATA_FILE = 'data/credit_card_mcc_row_benefits.php';

    /**
     * @return array{
     *     profiles_imported:int,
     *     rows_imported:int,
     *     tiers_created:int,
     *     tiers_updated:int,
     *     tiers_removed:int,
     *     skipped_profiles:array<int,string>,
     *     skipped_rows:array<int,string>
     * }
     */
    public function import(?string $dataFile = null): array
    {
        $path = $dataFile !== null && $dataFile !== ''
            ? $dataFile
            : database_path(self::DEFAULT_DATA_FILE);

        if (! is_file($path)) {
            throw new RuntimeException("Không tìm thấy file dữ liệu: {$path}");
        }

        /** @var array<string, array<string, mixed>> $data */
        $data = require $path;

        $stats = [
            'profiles_imported' => 0,
            'rows_imported' => 0,
            'tiers_created' => 0,
            'tiers_updated' => 0,
            'tiers_removed' => 0,
            'skipped_profiles' => [],
            'skipped_rows' => [],
        ];

        DB::connection('creditcard')->transaction(function () use ($data, &$stats): void {
            foreach ($data as $slug => $profileData) {
                $this->importProfile((string) $slug, (array) $profileData, $stats);
            }
        });

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $profileData
     * @param  array<string, mixed>  $stats
     */
    private function importProfile(string $slug, array $profileData, array &$stats): void
    {
        $template = PolicyTemplate::query()->where('slug', $slug)->orderBy('id')->first();

        if ($template === null) {
            $stats['skipped_profiles'][] = "{$slug}: không tìm thấy template theo slug";

            return;
        }

        $profile = PolicyMccProfile::query()
            ->where('policy_template_id', $template->id)
            ->orderBy('id')
            ->first();

        if ($profile === null) {
            $stats['skipped_profiles'][] = "{$slug}: template chưa có hồ sơ MCC";

            return;
        }

        $document = $this->resolveDocument($profile, $profileData['source_doc'] ?? null);
        $defaultPage = $this->str($profileData['source_page'] ?? null);

        $rows = (array) ($profileData['rows'] ?? []);

        foreach ($rows as $categoryName => $tiers) {
            $row = PolicyMccProfileRow::query()
                ->where('policy_mcc_profile_id', $profile->id)
                ->where('row_type', PolicyMccProfileRow::TYPE_INCLUDED)
                ->where('category_name', $categoryName)
                ->orderBy('id')
                ->first();

            if ($row === null) {
                $stats['skipped_rows'][] = "{$slug} / {$categoryName}: không có dòng included khớp category_name";

                continue;
            }

            $this->importRow($row, (array) $tiers, $document, $defaultPage, $stats);

            $stats['rows_imported']++;
        }

        $stats['profiles_imported']++;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tiers
     * @param  array<string, mixed>  $stats
     */
    private function importRow(
        PolicyMccProfileRow $row,
        array $tiers,
        ?PolicyMccProfileDocument $document,
        ?string $defaultPage,
        array &$stats,
    ): void {
        $count = 0;

        foreach (array_values($tiers) as $order => $tier) {
            $attributes = [
                'cashback_percent' => $this->percent($tier['pct'] ?? null),
                'rate_label' => $this->str($tier['label'] ?? null),
                'min_spend' => $this->money($tier['min'] ?? null),
                'max_spend' => $this->money($tier['max'] ?? null),
                'cap_amount' => $this->money($tier['cap'] ?? null),
                'cap_period' => $this->str($tier['cap_period'] ?? null),
                'cap_unit' => $this->str($tier['cap_unit'] ?? null),
                'condition_note' => $this->str($tier['cond'] ?? null),
                'exception_note' => $this->str($tier['exc'] ?? null),
                'source_document_id' => $document?->id,
                'source_page' => $this->str($tier['page'] ?? null) ?? $defaultPage,
            ];

            $benefit = PolicyMccRowBenefit::query()->firstOrNew([
                'policy_mcc_row_id' => $row->id,
                'tier_order' => $order,
            ]);

            $isNew = ! $benefit->exists;

            $benefit->fill($attributes);

            $isDirty = $benefit->isDirty();

            $benefit->save();

            if ($isNew) {
                $stats['tiers_created']++;
            } elseif ($isDirty) {
                $stats['tiers_updated']++;
            }

            $count++;
        }

        // Xoá bậc thừa nếu dữ liệu mới ít bậc hơn (idempotent khi rút gọn).
        $removed = PolicyMccRowBenefit::query()
            ->where('policy_mcc_row_id', $row->id)
            ->where('tier_order', '>=', $count)
            ->delete();

        $stats['tiers_removed'] += (int) $removed;
    }

    private function resolveDocument(PolicyMccProfile $profile, mixed $fragment): ?PolicyMccProfileDocument
    {
        $fragment = $this->str($fragment);

        if ($fragment !== null) {
            $found = PolicyMccProfileDocument::query()
                ->where('policy_mcc_profile_id', $profile->id)
                ->where('original_name', 'like', '%'.$fragment.'%')
                ->orderBy('id')
                ->first();

            if ($found !== null) {
                return $found;
            }
        }

        return PolicyMccProfileDocument::query()
            ->where('policy_mcc_profile_id', $profile->id)
            ->orderBy('id')
            ->first();
    }

    private function percent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
