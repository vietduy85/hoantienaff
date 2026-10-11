<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileDocument;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccProfileRowCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * PolicyMccProfileService — nghiệp vụ cho module "Hồ sơ MCC chính sách".
 *
 * Mọi thao tác GHI chạy trong MỘT transaction connection `creditcard`: hồ sơ +
 * dòng MCC + mã + tài liệu lưu nguyên tử. Lỗi giữa chừng ⇒ rollback sạch.
 *
 * TUYỆT ĐỐI KHÔNG:
 *   - tự tạo MCC master từ mã admin nhập (báo lỗi nếu mã không tồn tại);
 *   - liên kết "tên danh mục" với `credit_card_categories`;
 *   - lưu tỷ lệ hoàn tiền vào hồ sơ (luôn đọc từ blueprint chính sách);
 *   - đụng engine cashback / `credit_card_policy_mcc_mappings`.
 */
class PolicyMccProfileService
{
    /** Thư mục gốc (trên disk private) chứa tài liệu hồ sơ. */
    public const DOCUMENT_DIRECTORY = 'credit-card-mcc';

    /** Disk private dùng để lưu tài liệu (không public). */
    public const DOCUMENT_DISK = 'local';

    /**
     * Tìm MCC master theo mã (tiền tố) hoặc mô tả (VI/EN).
     *
     * @return Collection<int, MccCode>
     */
    public function masterSearch(string $term, int $limit = 20): Collection
    {
        $term = trim($term);

        $query = MccCode::query();

        if ($term !== '') {
            $query->where(function ($inner) use ($term): void {
                $inner->where('mcc', 'like', $term.'%')
                    ->orWhere('mcc', 'like', '%'.$term.'%')
                    ->orWhere('description_vi', 'like', '%'.$term.'%')
                    ->orWhere('description_en', 'like', '%'.$term.'%');
            });
        }

        return $query->orderBy('mcc')->limit(max(1, min($limit, 50)))->get();
    }

    /**
     * Tra id master cho một danh sách mã; ném lỗi nếu có mã không tồn tại.
     *
     * @param  array<int, string>  $codes
     * @return array<string, int>
     */
    public function resolveMasterIds(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($code): string => trim((string) $code),
            $codes,
        ), fn (string $code): bool => $code !== '')));

        if ($codes === []) {
            return [];
        }

        $found = MccCode::query()
            ->whereIn('mcc', $codes)
            ->pluck('id', 'mcc')
            ->all();

        $missing = array_values(array_diff($codes, array_keys($found)));

        if ($missing !== []) {
            throw new InvalidArgumentException('MCC không tồn tại trong danh mục chuẩn: '.implode(', ', $missing));
        }

        return $found;
    }

    /**
     * @param  array{policy_template_id:int, name:string, note?:string|null, included?:array<int, array<string,mixed>>, excluded?:array<int, array<string,mixed>>}  $data
     * @param  array<int, UploadedFile>  $documents
     */
    public function create(array $data, array $documents = [], ?int $userId = null): PolicyMccProfile
    {
        return $this->transaction(function () use ($data, $documents, $userId): PolicyMccProfile {
            $profile = PolicyMccProfile::create([
                'policy_template_id' => (int) $data['policy_template_id'],
                'name' => trim((string) $data['name']),
                'note' => $this->nullIfBlank($data['note'] ?? null),
            ]);

            $this->syncRows($profile, $data['included'] ?? [], $data['excluded'] ?? []);
            $this->storeDocuments($profile, $documents, $userId);

            return $profile->refresh();
        });
    }

    /**
     * @param  array{name?:string, note?:string|null, included?:array<int, array<string,mixed>>, excluded?:array<int, array<string,mixed>>}  $data
     * @param  array<int, UploadedFile>  $documents
     */
    public function update(PolicyMccProfile $profile, array $data, array $documents = [], ?int $userId = null): PolicyMccProfile
    {
        return $this->transaction(function () use ($profile, $data, $documents, $userId): PolicyMccProfile {
            if (array_key_exists('name', $data)) {
                $profile->name = trim((string) $data['name']);
            }

            if (array_key_exists('note', $data)) {
                $profile->note = $this->nullIfBlank($data['note']);
            }

            $profile->save();

            $this->syncRows($profile, $data['included'] ?? [], $data['excluded'] ?? []);
            $this->storeDocuments($profile, $documents, $userId);

            return $profile->refresh();
        });
    }

    /**
     * Lưu các tài liệu tải lên (KHÔNG xoá tài liệu cũ — giữ lịch sử đối chiếu).
     *
     * @param  array<int, UploadedFile>  $documents
     */
    public function storeDocuments(PolicyMccProfile $profile, array $documents, ?int $userId = null): void
    {
        foreach ($documents as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?? ''));
            $directory = self::DOCUMENT_DIRECTORY.'/'.$profile->id;
            $storedName = (string) Str::uuid().($extension !== '' ? '.'.$extension : '');

            $path = $file->storeAs($directory, $storedName, self::DOCUMENT_DISK);

            if ($path === false) {
                throw new RuntimeException('Không lưu được tài liệu đính kèm.');
            }

            PolicyMccProfileDocument::create([
                'policy_mcc_profile_id' => $profile->id,
                'disk' => self::DOCUMENT_DISK,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'extension' => $extension !== '' ? $extension : null,
                'size' => (int) $file->getSize(),
                'uploaded_by' => $userId,
            ]);
        }
    }

    /**
     * Xoá một tài liệu (kèm file vật lý trên disk private).
     */
    public function deleteDocument(PolicyMccProfileDocument $document): void
    {
        $this->transaction(function () use ($document): void {
            $disk = $document->disk;
            $path = $document->path;

            $document->delete();

            if ($path !== '') {
                Storage::disk($disk)->delete($path);
            }
        });
    }

    /**
     * Đồng bộ dòng MCC: xoá hết dòng cũ rồi tạo lại từ payload (nguyên tử trong
     * transaction của caller). Vì đây là bảng con của hồ sơ, xoá dòng tự xoá mã.
     *
     * @param  array<int, array<string,mixed>>  $included
     * @param  array<int, array<string,mixed>>  $excluded
     */
    public function syncRows(PolicyMccProfile $profile, array $included, array $excluded): void
    {
        $includedCodes = $this->collectCodes($included);
        $excludedCodes = $this->collectCodes($excluded);

        $overlap = array_values(array_intersect($includedCodes, $excludedCodes));

        if ($overlap !== []) {
            throw new InvalidArgumentException(sprintf(
                'MCC vừa nằm trong danh sách được hoàn tiền vừa nằm trong danh sách loại trừ của cùng hồ sơ: %s.',
                implode(', ', $overlap),
            ));
        }

        // Xác thực mọi mã tồn tại trong master TRƯỚC khi ghi bất cứ gì.
        $mccIdByCode = $this->resolveMasterIds(array_merge($includedCodes, $excludedCodes));

        // Xoá dòng cũ (cascade xoá mã con).
        $profile->rows()->delete();

        $this->persistRows($profile, PolicyMccProfileRow::TYPE_INCLUDED, $included, $mccIdByCode);
        $this->persistRows($profile, PolicyMccProfileRow::TYPE_EXCLUDED, $excluded, $mccIdByCode);
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, string>
     */
    private function collectCodes(array $rows): array
    {
        $codes = [];

        foreach ($rows as $row) {
            foreach ($this->normalizeMccList($row['codes'] ?? $row['mcc'] ?? []) as $code) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @param  array<string, int>  $mccIdByCode
     */
    private function persistRows(PolicyMccProfile $profile, string $type, array $rows, array $mccIdByCode): void
    {
        $order = 0;

        foreach (array_values($rows) as $row) {
            $codes = $this->normalizeMccList($row['codes'] ?? $row['mcc'] ?? []);
            $categoryName = $this->nullIfBlank($row['category_name'] ?? null);
            $description = $this->nullIfBlank($row['description'] ?? null);
            $note = $this->nullIfBlank($row['note'] ?? null);

            // Bỏ dòng hoàn toàn trống.
            if ($codes === [] && $categoryName === null && $description === null && $note === null) {
                continue;
            }

            $model = PolicyMccProfileRow::create([
                'policy_mcc_profile_id' => $profile->id,
                'row_type' => $type,
                'category_name' => $categoryName,
                'description' => $description,
                'note' => $note,
                'display_order' => $order++,
            ]);

            $toInsert = [];

            foreach (array_values(array_unique($codes)) as $code) {
                $toInsert[] = [
                    'policy_mcc_row_id' => $model->id,
                    'mcc_code_id' => $mccIdByCode[$code] ?? null,
                    'mcc' => $code,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($toInsert !== []) {
                PolicyMccProfileRowCode::query()->insert($toInsert);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeMccList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if ($item === null) {
                continue;
            }

            $code = trim((string) $item);

            if ($code !== '') {
                $normalized[] = $code;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @template TReturn
     *
     * @param  \Closure():TReturn  $callback
     * @return TReturn
     */
    private function transaction(\Closure $callback): mixed
    {
        return (new PolicyMccProfile)->getConnection()->transaction($callback);
    }
}
