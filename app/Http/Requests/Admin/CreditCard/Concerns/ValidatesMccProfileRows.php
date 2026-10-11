<?php

namespace App\Http\Requests\Admin\CreditCard\Concerns;

use App\Models\CreditCard\MccCode;
use Illuminate\Validation\Validator;

/**
 * Chuẩn hoá + kiểm tra hai bảng dòng MCC (included / excluded) của một hồ sơ.
 *
 * Nhận cả mảng (`codes[]`) lẫn chuỗi phân tách bằng dấu phẩy/khoảng trắng (admin
 * dán nhiều mã), chuẩn hoá về mảng đã trim/bỏ rỗng, bỏ dòng hoàn toàn trống, và:
 *   - mỗi mã đúng 4 chữ số;
 *   - mã phải tồn tại trong MCC master — KHÔNG tự tạo mới (báo rõ mã thiếu);
 *   - không trùng mã trong CÙNG một danh sách;
 *   - một mã không được vừa `included` vừa `excluded` trong cùng hồ sơ.
 */
trait ValidatesMccProfileRows
{
    /**
     * @return array<int, string>
     */
    protected function normalizeMccList(mixed $value): array
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

        // GIỮ nguyên trùng lặp để bước kiểm tra phát hiện được (không unique ở đây).
        return $normalized;
    }

    /**
     * Chuẩn hoá một bảng dòng, bỏ dòng trống hoàn toàn.
     *
     * @return array<int, array{category_name:string|null, description:string|null, note:string|null, codes:array<int, string>}>
     */
    protected function normalizedRows(string $key): array
    {
        $rows = $this->input($key);

        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $codes = $this->normalizeMccList($row['codes'] ?? $row['mcc'] ?? []);
            $categoryName = $this->blankToNull($row['category_name'] ?? null);
            $description = $this->blankToNull($row['description'] ?? null);
            $note = $this->blankToNull($row['note'] ?? null);

            if ($codes === [] && $categoryName === null && $description === null && $note === null) {
                continue;
            }

            $normalized[] = [
                'category_name' => $categoryName,
                'description' => $description,
                'note' => $note,
                'codes' => $codes,
            ];
        }

        return $normalized;
    }

    /**
     * Gọi trong `withValidator()`. Thêm lỗi vào key `included` / `excluded`.
     */
    protected function validateMccRows(Validator $validator): void
    {
        $included = $this->normalizedRows('included');
        $excluded = $this->normalizedRows('excluded');

        $includedCodes = $this->assertRowCodes($validator, $included, 'MCC được hoàn tiền', 'included');
        $excludedCodes = $this->assertRowCodes($validator, $excluded, 'MCC loại trừ', 'excluded');

        $this->assertCodesExist($validator, array_merge($includedCodes, $excludedCodes));

        $overlap = array_values(array_intersect($includedCodes, $excludedCodes));

        if ($overlap !== []) {
            $validator->errors()->add(
                'included',
                'MCC không thể vừa được hoàn tiền vừa bị loại trừ trong cùng hồ sơ: '.implode(', ', $overlap).'.',
            );
        }
    }

    /**
     * @param  array<int, array{category_name:string|null, description:string|null, note:string|null, codes:array<int, string>}>  $rows
     * @return array<int, string>
     */
    private function assertRowCodes(Validator $validator, array $rows, string $label, string $errorKey): array
    {
        $all = [];

        foreach ($rows as $row) {
            $codes = $row['codes'];

            $duplicates = array_keys(array_filter(array_count_values($codes), fn (int $count): bool => $count > 1));

            if ($duplicates !== []) {
                $validator->errors()->add($errorKey, sprintf('%s bị lặp trong một dòng: %s.', $label, implode(', ', $duplicates)));
            }

            $invalid = array_values(array_filter($codes, fn (string $code): bool => preg_match('/^\d{4}$/', $code) !== 1));

            if ($invalid !== []) {
                $validator->errors()->add($errorKey, sprintf('%s phải gồm đúng 4 chữ số: %s.', $label, implode(', ', $invalid)));
            }

            $all = array_merge($all, $codes);
        }

        return array_values(array_unique($all));
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function assertCodesExist(Validator $validator, array $codes): void
    {
        $codes = array_values(array_filter($codes, fn (string $code): bool => preg_match('/^\d{4}$/', $code) === 1));

        if ($codes === []) {
            return;
        }

        $found = MccCode::query()->whereIn('mcc', $codes)->pluck('mcc')->all();
        $missing = array_values(array_diff($codes, array_map('strval', $found)));

        if ($missing !== []) {
            $validator->errors()->add('included', 'MCC không tồn tại trong danh mục chuẩn: '.implode(', ', $missing).'. Hãy đối chiếu lại tài liệu ngân hàng.');
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{included: array<int, array<string,mixed>>, excluded: array<int, array<string,mixed>>}
     */
    protected function profileRowPayload(): array
    {
        return [
            'included' => $this->normalizedRows('included'),
            'excluded' => $this->normalizedRows('excluded'),
        ];
    }
}
