<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chuyển dữ liệu module MCC CŨ sang mô hình HỒ SƠ mới (an toàn, không mất dữ liệu).
 *
 * Nguồn (bảng cũ, GIỮ NGUYÊN):
 *   - credit_card_policy_mcc_groups         (nhóm MCC của một chính sách)
 *   - credit_card_policy_mcc_group_codes    (mã applied / excluded của nhóm)
 *   - credit_card_policy_mcc_common_exclusions (loại trừ chung của chính sách)
 *
 * Đích (bảng mới):
 *   - Mỗi chính sách có dữ liệu ⇒ MỘT hồ sơ `MCC <tên chính sách>`.
 *   - Loại trừ CHUNG ⇒ một dòng `excluded` tên "Loại trừ chung".
 *   - Mỗi nhóm cũ ⇒ dòng `included` (mã role=applied) và/hoặc dòng `excluded`
 *     (mã role=excluded), giữ nguyên tên danh mục, mô tả, ghi chú, thứ tự.
 *   - Nhóm cũ phạm vi "Tất cả MCC" (không có mã áp dụng) không biểu diễn được ở mô
 *     hình mới ⇒ ghi chú lại vào hồ sơ để admin xử lý tay (không âm thầm bỏ).
 *
 * AN TOÀN / IDEMPOTENT:
 *   - Chạy lại không tạo trùng: bỏ qua chính sách đã có hồ sơ.
 *   - KHÔNG xoá bảng cũ. `down()` cố ý không xoá để tránh mất dữ liệu.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        $connection = DB::connection($this->connection);

        if (! Schema::connection($this->connection)->hasTable('credit_card_policy_mcc_groups')
            || ! Schema::connection($this->connection)->hasTable('credit_card_policy_mcc_profiles')) {
            return;
        }

        $templateIds = $connection->table('credit_card_policy_mcc_groups')
            ->distinct()
            ->pluck('policy_template_id')
            ->merge(
                $connection->table('credit_card_policy_mcc_common_exclusions')
                    ->distinct()
                    ->pluck('policy_template_id')
            )
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($templateIds->isEmpty()) {
            return;
        }

        $mccIdByCode = $connection->table('credit_card_mcc_codes')
            ->pluck('id', 'mcc')
            ->mapWithKeys(fn ($id, $mcc): array => [(string) $mcc => (int) $id])
            ->all();

        foreach ($templateIds as $templateId) {
            // Idempotent: chính sách đã có hồ sơ thì bỏ qua.
            $alreadyMigrated = $connection->table('credit_card_policy_mcc_profiles')
                ->where('policy_template_id', $templateId)
                ->exists();

            if ($alreadyMigrated) {
                continue;
            }

            $templateName = $connection->table('credit_card_policy_templates')
                ->where('id', $templateId)
                ->value('name');

            $now = now();

            $profileId = (int) $connection->table('credit_card_policy_mcc_profiles')->insertGetId([
                'policy_template_id' => $templateId,
                'name' => 'MCC '.($templateName !== null && $templateName !== '' ? $templateName : '#'.$templateId),
                'note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $order = 1;
            $profileNotes = [];

            // 1) Loại trừ chung ⇒ một dòng `excluded`.
            $common = $connection->table('credit_card_policy_mcc_common_exclusions')
                ->where('policy_template_id', $templateId)
                ->orderBy('mcc')
                ->get();

            if ($common->isNotEmpty()) {
                $rowId = $this->insertRow($connection, $profileId, 'excluded', 'Loại trừ chung', null, $this->firstNote($common), $order++);
                $this->insertCodes($connection, $rowId, $common->pluck('mcc')->all(), $mccIdByCode, $now);
            }

            // 2) Nhóm cũ ⇒ dòng included / excluded.
            $groups = $connection->table('credit_card_policy_mcc_groups')
                ->where('policy_template_id', $templateId)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

            foreach ($groups as $group) {
                $codes = $connection->table('credit_card_policy_mcc_group_codes')
                    ->where('policy_mcc_group_id', $group->id)
                    ->orderBy('mcc')
                    ->get();

                $applied = $codes->where('role', 'applied')->pluck('mcc')->all();
                $excluded = $codes->where('role', 'excluded')->pluck('mcc')->all();

                $categoryName = $group->reference_name;
                $description = $group->description;
                $note = $group->note;

                if ($applied !== []) {
                    $rowId = $this->insertRow($connection, $profileId, 'included', $categoryName, $description, $note, $order++);
                    $this->insertCodes($connection, $rowId, $applied, $mccIdByCode, $now);
                }

                if ($excluded !== []) {
                    $excludedName = $categoryName !== null && $categoryName !== ''
                        ? $categoryName.' (loại trừ)'
                        : 'Loại trừ riêng của nhóm';
                    $rowId = $this->insertRow($connection, $profileId, 'excluded', $excludedName, $description, $note, $order++);
                    $this->insertCodes($connection, $rowId, $excluded, $mccIdByCode, $now);
                }

                // Nhóm "Tất cả MCC" không có mã áp dụng: mô hình mới không có khái
                // niệm này — ghi chú lại để admin nhập tay, KHÔNG âm thầm bỏ.
                if ($group->scope_mode === 'all' && $applied === []) {
                    $label = $categoryName !== null && $categoryName !== '' ? $categoryName : '#'.$group->id;
                    $profileNotes[] = 'Nhóm cũ "'.$label.'" có phạm vi "Tất cả MCC"; hãy nhập danh sách MCC áp dụng tương ứng.';
                }
            }

            if ($profileNotes !== []) {
                $connection->table('credit_card_policy_mcc_profiles')
                    ->where('id', $profileId)
                    ->update([
                        'note' => implode("\n", $profileNotes),
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Cố ý KHÔNG xoá: đây là migration chuyển dữ liệu một chiều. Bảng cũ vẫn
        // còn nguyên nên có thể tạo lại hồ sơ bằng cách chạy lại `up()` sau khi
        // xoá thủ công nếu thực sự cần.
    }

    /**
     * @param  \Illuminate\Database\Connection  $connection
     */
    private function insertRow($connection, int $profileId, string $type, ?string $categoryName, ?string $description, ?string $note, int $order): int
    {
        return (int) $connection->table('credit_card_policy_mcc_rows')->insertGetId([
            'policy_mcc_profile_id' => $profileId,
            'row_type' => $type,
            'category_name' => $categoryName,
            'description' => $description,
            'note' => $note,
            'display_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  \Illuminate\Database\Connection  $connection
     * @param  array<int, string>  $codes
     * @param  array<string, int>  $mccIdByCode
     */
    private function insertCodes($connection, int $rowId, array $codes, array $mccIdByCode, $now): void
    {
        $rows = [];

        foreach (array_values(array_unique($codes)) as $code) {
            $code = (string) $code;

            if ($code === '') {
                continue;
            }

            $rows[] = [
                'policy_mcc_row_id' => $rowId,
                'mcc_code_id' => $mccIdByCode[$code] ?? null,
                'mcc' => $code,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            $connection->table('credit_card_policy_mcc_row_codes')->insert($rows);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function firstNote($rows): ?string
    {
        foreach ($rows as $row) {
            if (isset($row->note) && $row->note !== null && $row->note !== '') {
                return (string) $row->note;
            }
        }

        return null;
    }
};
