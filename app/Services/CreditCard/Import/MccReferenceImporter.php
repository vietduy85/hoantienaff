<?php

namespace App\Services\CreditCard\Import;

use App\Models\CreditCard\CardPolicyMccMapping;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyTemplate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * MccReferenceImporter — nạp dữ liệu MCC + mapping từ file tham chiếu.
 *
 * CHỈ GHI vào hai bảng mới `credit_card_mcc_codes` và
 * `credit_card_policy_mcc_mappings`; không đụng dữ liệu chính sách đang chạy.
 *
 * ---------------------------------------------------------------------------
 * IDEMPOTENT
 * ---------------------------------------------------------------------------
 *  - MCC: upsert theo cột `mcc` (khoá tự nhiên).
 *  - Mapping: `updateOrCreate` theo `fingerprint` do model sinh — chạy lại cùng
 *    file KHÔNG tạo bản ghi trùng.
 *
 * ---------------------------------------------------------------------------
 * NGUYÊN TẮC DỮ LIỆU
 * ---------------------------------------------------------------------------
 *  - KHÔNG bịa mô tả/mức hoàn tiền. Ô trống để nguyên NULL.
 *  - Quy tắc "mọi MCC trừ danh sách" phải vào bằng `match_type=all_except` +
 *    `mcc_codes` — KHÔNG tự mở rộng thành danh sách đầy đủ.
 *  - Dòng không xác định được `match_type`/`mcc` coi là `unverified`.
 */
class MccReferenceImporter
{
    /**
     * Alias header (đã bỏ dấu, lowercase) → cột chuẩn.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'mcc' => 'mcc',
        'ma mcc' => 'mcc',
        'mcc code' => 'mcc',
        'code' => 'mcc',

        'description vi' => 'description_vi',
        'mo ta' => 'description_vi',
        'mo ta vi' => 'description_vi',
        'mo ta tieng viet' => 'description_vi',
        'giai thich' => 'description_vi',
        'giai thich tieng viet' => 'description_vi',
        'ten' => 'description_vi',
        'ten mcc' => 'description_vi',
        'ten tieng viet chuan hoa' => 'description_vi',

        'description en' => 'description_en',
        'mo ta en' => 'description_en',
        'mo ta tieng anh' => 'description_en',
        'ten mcc tieng anh theo visa' => 'description_en',

        'keywords' => 'keywords',
        'tu khoa' => 'keywords',
        'keyword' => 'keywords',

        'match type' => 'match_type',
        'loai quy tac' => 'match_type',
        'quy tac' => 'match_type',

        'mcc codes' => 'mcc_codes',
        'ma thanh vien' => 'mcc_codes',
        'danh sach ma' => 'mcc_codes',
        'cac ma' => 'mcc_codes',
        'ma con' => 'mcc_codes',

        'mcc group' => 'mcc_group',
        'nhom' => 'mcc_group',
        'nhom mcc' => 'mcc_group',

        'policy' => 'policy',
        'chinh sach' => 'policy',
        'ten chinh sach' => 'policy',
        'policy template' => 'policy',
        'template' => 'policy',

        'category' => 'category',
        'danh muc' => 'category',
        'ten danh muc' => 'category',

        'application status' => 'application_status',
        'trang thai ap dung' => 'application_status',
        'ap dung' => 'application_status',

        'cashback percent' => 'cashback_percent',
        'cashback' => 'cashback_percent',
        'hoan tien' => 'cashback_percent',
        'ty le hoan tien' => 'cashback_percent',
        'phan tram' => 'cashback_percent',

        'condition note' => 'condition_note',
        'dieu kien' => 'condition_note',
        'ghi chu' => 'condition_note',

        'exclusion reason' => 'exclusion_reason',
        'ly do loai tru' => 'exclusion_reason',
        'loai tru' => 'exclusion_reason',

        'source pdf' => 'source_pdf',
        'nguon' => 'source_pdf',
        'file nguon' => 'source_pdf',

        'source page' => 'source_page',
        'trang' => 'source_page',

        'verification status' => 'verification_status',
        'trang thai xac minh' => 'verification_status',
        'trang thai' => 'verification_status',
        'xac minh' => 'verification_status',

        'verified at' => 'verified_at',
        'ngay xac minh' => 'verified_at',
        'ngay xac minh thong tin' => 'verified_at',
    ];

    private const MATCH_TYPES = ['direct', 'group', 'all', 'all_except'];

    private const APPLICATION_STATUSES = ['applied', 'excluded', 'unverified'];

    private const VERIFICATION_STATUSES = ['verified', 'unverified', 'needs_review'];

    /**
     * Nạp file tham chiếu (`.csv`, `.xlsx`, `.xls`).
     *
     * @param  string|null  $sheet  Tên sheet chứa danh sách MCC; null = tự dò.
     * @return array{sheet:string,rows_read:int,mcc_created:int,mcc_updated:int,mcc_unchanged:int,mappings_created:int,mappings_updated:int,skipped:int}
     */
    public function import(string $path, ?string $sheet = null): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Không tìm thấy file: {$path}");
        }

        [$rows, $sheetName] = $this->readRows($path, $sheet);

        $stats = [
            'sheet' => $sheetName,
            'rows_read' => count($rows),
            'mcc_created' => 0,
            'mcc_updated' => 0,
            'mcc_unchanged' => 0,
            'mappings_created' => 0,
            'mappings_updated' => 0,
            'skipped' => 0,
        ];

        foreach ($rows as $row) {
            $mcc = $this->normalizeMcc($row['mcc'] ?? null);

            if ($mcc === null) {
                $stats['skipped']++;

                continue;
            }

            $this->upsertMcc($mcc, $row, $stats);

            // Một dòng chỉ tạo mapping khi xác định được chính sách đích.
            if ($this->blank($row['policy'] ?? null)) {
                continue;
            }

            $mapping = $this->buildMapping($mcc, $row);

            if ($mapping === null) {
                $stats['skipped']++;

                continue;
            }

            $fingerprint = $mapping->buildFingerprint();
            $exists = CardPolicyMccMapping::query()->where('fingerprint', $fingerprint)->exists();

            CardPolicyMccMapping::query()->updateOrCreate(
                ['fingerprint' => $fingerprint],
                collect($mapping->getAttributes())
                    ->except(['id', 'created_at', 'updated_at'])
                    ->all(),
            );

            $stats[$exists ? 'mappings_updated' : 'mappings_created']++;
        }

        return $stats;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function upsertMcc(string $mcc, array $row, array &$stats): void
    {
        // CHỈ ghi các cột có dữ liệu thật: chạy lại file mà cột nào trống thì
        // giữ nguyên giá trị tốt đã có (không ghi đè bằng rỗng).
        $attributes = [
            'description_vi' => $this->str($row['description_vi'] ?? null),
            'description_en' => $this->str($row['description_en'] ?? null),
            'keywords' => $this->str($row['keywords'] ?? null),
            'source' => $this->str($row['source_pdf'] ?? null),
        ];

        if (! $this->blank($row['verification_status'] ?? null)) {
            $attributes['verification_status'] = $this->verificationStatus($row['verification_status']);
        }

        if (! $this->blank($row['verified_at'] ?? null)) {
            $attributes['verified_at'] = $this->parseDate($row['verified_at']);
        }

        $existing = MccCode::query()->where('mcc', $mcc)->first();

        if ($existing === null) {
            MccCode::query()->create(array_filter(
                ['mcc' => $mcc] + $attributes,
                fn ($value): bool => $value !== null,
            ));
            $stats['mcc_created']++;

            return;
        }

        // HỢP NHẤT: chỉ ghi khi giá trị nguồn non-null VÀ khác giá trị hiện có,
        // tránh cập nhật vô nghĩa và không bao giờ xoá dữ liệu tốt bằng rỗng.
        $changes = [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($this->sameValue($existing->getAttribute($key), $value)) {
                continue;
            }

            $changes[$key] = $value;
        }

        if ($changes === []) {
            $stats['mcc_unchanged']++;

            return;
        }

        $existing->fill($changes)->save();
        $stats['mcc_updated']++;
    }

    private function sameValue(mixed $current, mixed $incoming): bool
    {
        if ($current instanceof DateTimeInterface) {
            $current = $current->format('Y-m-d');
        }

        return (string) ($current ?? '') === (string) $incoming;
    }

    private function buildMapping(string $mcc, array $row): ?CardPolicyMccMapping
    {
        $template = $this->findTemplate($row['policy']);
        $category = $this->findCategory($row['category'] ?? null);

        $matchType = $this->matchType($row['match_type'] ?? null, $row['mcc_codes'] ?? null);
        $codes = $this->parseCodes($row['mcc_codes'] ?? null);

        $mapping = new CardPolicyMccMapping([
            'policy_template_id' => $template?->id,
            'category_id' => $category?->id,
            'match_type' => $matchType,
            'mcc' => $matchType === 'direct' ? $mcc : null,
            'mcc_group' => $this->str($row['mcc_group'] ?? null),
            'mcc_codes' => $codes === [] ? null : $codes,
            'application_status' => $this->applicationStatus($row['application_status'] ?? null),
            'cashback_percent' => $this->percent($row['cashback_percent'] ?? null),
            'condition_note' => $this->str($row['condition_note'] ?? null),
            'exclusion_reason' => $this->str($row['exclusion_reason'] ?? null),
            'source_pdf' => $this->str($row['source_pdf'] ?? null),
            'source_page' => $this->str($row['source_page'] ?? null),
            'verification_status' => $this->verificationStatus($row['verification_status'] ?? null),
        ]);

        // Không có chính sách ⇒ không thể mapping (cột FK NULL vô nghĩa).
        if ($mapping->policy_template_id === null) {
            return null;
        }

        return $mapping;
    }

    private function findTemplate(string $name): ?PolicyTemplate
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return PolicyTemplate::query()
            ->where('name', $name)
            ->orWhere('slug', Str::slug($name))
            ->orderBy('id')
            ->first();
    }

    private function findCategory(?string $name): ?Category
    {
        $name = $this->str($name);

        if ($name === null) {
            return null;
        }

        return Category::query()
            ->where('name', $name)
            ->orWhere('slug', Str::slug($name))
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    private function readRows(string $path, ?string $sheet = null): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($path);
        $worksheet = $this->resolveSheet($spreadsheet, $sheet);

        $rows = $worksheet->toArray(null, true, true, false);
        $sheetName = $worksheet->getTitle();

        if ($rows === []) {
            return [[], $sheetName];
        }

        $header = array_map(fn ($cell): string => $this->headerKey($cell), array_shift($rows));

        $out = [];

        foreach ($rows as $raw) {
            $row = [];

            foreach ($header as $index => $key) {
                if ($key === '') {
                    continue;
                }

                $row[$key] = $raw[$index] ?? null;
            }

            if (array_filter($row, fn ($value): bool => ! $this->blank($value)) === []) {
                continue;
            }

            $out[] = $row;
        }

        return [$out, $sheetName];
    }

    /**
     * Chọn sheet chứa danh sách MCC: ưu tiên tên chỉ định, nếu không thì tự dò
     * sheet có header nhận ra được cột `mcc` kèm ít nhất một cột mô tả.
     */
    private function resolveSheet(Spreadsheet $spreadsheet, ?string $sheet): Worksheet
    {
        if ($sheet !== null && $sheet !== '') {
            $found = $spreadsheet->getSheetByName($sheet);

            if ($found === null) {
                throw new RuntimeException("Không tìm thấy sheet: {$sheet}");
            }

            return $found;
        }

        $sheets = $spreadsheet->getAllSheets();

        if (count($sheets) === 1) {
            return $sheets[0];
        }

        foreach ($sheets as $candidate) {
            $header = $this->headerKeysOf($candidate);

            if (in_array('mcc', $header, true)
                && (in_array('description_en', $header, true) || in_array('description_vi', $header, true))) {
                return $candidate;
            }
        }

        return $spreadsheet->getActiveSheet();
    }

    /**
     * @return array<int, string>
     */
    private function headerKeysOf(Worksheet $worksheet): array
    {
        $firstRow = $worksheet->toArray(null, true, true, false)[0] ?? [];

        return array_values(array_filter(array_map(
            fn ($cell): string => $this->headerKey($cell),
            $firstRow,
        )));
    }

    private function headerKey(mixed $cell): string
    {
        $key = Str::of((string) $cell)->lower()->ascii()->replace(['_', '-', '.'], ' ')->squish()->value();

        return self::HEADERS[$key] ?? '';
    }

    private function normalizeMcc(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // MCC là mã 4 chữ số — giữ số 0 đứng đầu.
        return str_pad(substr($digits, 0, 4), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<int, string>
     */
    private function parseCodes(mixed $value): array
    {
        if ($this->blank($value)) {
            return [];
        }

        $codes = preg_split('/[,;\s|]+/', (string) $value) ?: [];

        $out = [];

        foreach ($codes as $code) {
            $normalized = $this->normalizeMcc($code);

            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }

    private function matchType(mixed $value, mixed $codes): string
    {
        $type = Str::of((string) $value)->lower()->ascii()->replace([' ', '-'], '_')->squish()->value();

        if (in_array($type, self::MATCH_TYPES, true)) {
            return $type;
        }

        // Không rõ mà có nhiều mã ⇒ nhóm; ngược lại là trực tiếp.
        return $this->parseCodes($codes) !== [] ? 'group' : 'direct';
    }

    private function applicationStatus(mixed $value): string
    {
        $status = Str::of((string) $value)->lower()->ascii()->replace([' ', '-'], '_')->squish()->value();

        return in_array($status, self::APPLICATION_STATUSES, true) ? $status : 'unverified';
    }

    private function verificationStatus(mixed $value): string
    {
        $status = Str::of((string) $value)->lower()->ascii()->replace([' ', '-'], '_')->squish()->value();

        return in_array($status, self::VERIFICATION_STATUSES, true) ? $status : 'unverified';
    }

    private function percent(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        $number = (float) str_replace(['%', ',', ' '], ['', '.', ''], (string) $value);

        return number_format($number, 3, '.', '');
    }

    private function str(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        return trim((string) $value);
    }

    private function parseDate(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
