<?php

namespace App\Services\CreditCard\Import;

use App\Models\CreditCard\Transaction;

/**
 * Kết quả một lần import Excel.
 *
 * `imported` / `skipped` / `failed` là số DÒNG, không phải số giao dịch.
 */
final class TransactionImportResult
{
    /**
     * @param  array<int, TransactionRowError>  $errors
     * @param  array<int, int>  $importedIds  id các giao dịch đã tạo
     */
    public function __construct(
        public readonly int $imported,
        public readonly int $skipped,
        public readonly int $failed,
        public readonly array $errors = [],
        public readonly array $importedIds = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->failed === 0 && $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'errors' => array_map(fn (TransactionRowError $e): array => $e->toArray(), $this->errors),
        ];
    }
}
