<?php

namespace App\Console\Commands;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AffiliateImportShopee extends Command
{
    protected $signature = 'affiliate:import-shopee
                        {--dry-run : Parse and analyze without importing}
                        {--file= : Import a specific CSV file}';
    protected $description = 'Import newest Shopee Affiliate Commission Report CSV from Downloads';

    private const DOWNLOADS_DIR = 'C:\Users\Administrator\Downloads';
    private const IMPORTED_DIR = 'C:\Users\Administrator\Downloads\Imported';

    // Map Vietnamese header names from Shopee export to DB columns
    private const HEADER_MAP = [
        'ID đơn hàng'                         => 'order_id',
        'Trạng thái đặt hàng'                 => 'order_status',
        'Checkout id'                         => 'checkout_id',
        'Thời Gian Đặt Hàng'                  => 'ordered_at',
        'Thời gian hoàn thành'                => 'completed_at',
        'Thời gian Click'                     => 'clicked_at',
        'Tên Shop'                            => 'shop_name',
        'Shop id'                             => 'shop_id',
        'Loại Shop'                           => 'shop_type',
        'Item id'                             => 'item_id',
        'Tên Item'                            => 'item_name',
        'ID Model'                            => 'model_id',
        'Loại sản phẩm'                       => 'product_type',
        'Promotion id'                        => 'promotion_id',
        'L1 Danh mục toàn cầu'                => 'category_l1',
        'L2 Danh mục toàn cầu'                => 'category_l2',
        'L3 Danh mục toàn cầu'                => 'category_l3',
        'Giá(₫)'                              => 'item_price',
        'Số lượng'                            => 'quantity',
        'Giá trị đơn hàng (₫)'                => 'order_amount',
        'Số tiền hoàn trả (₫)'                => 'refund_amount',
        'Loại Hoa hồng'                       => 'commission_type',
        'Đối tác chiến dịch'                  => 'campaign_partner',
        'Tỷ lệ sản phẩm hoa hồng Shopee'      => 'shopee_commission_rate',
        'Hoa hồng Shopee trên sản phẩm(₫)'    => 'shopee_commission',
        'Tỷ lệ sản phẩm hoa hồng người bán'   => 'seller_commission_rate',
        'Hoa hồng Xtra trên sản phẩm(₫)'      => 'xtra_commission',
        'Tổng hoa hồng sản phẩm(₫)'           => 'total_product_commission',
        'Hoa hồng đơn hàng từ Shopee(₫)'      => 'order_commission_shopee',
        'Hoa hồng đơn hàng từ Người bán(₫)'   => 'order_commission_seller',
        'Tổng hoa hồng đơn hàng(₫)'           => 'total_order_commission',
        'Tên MNC đã liên kết'                 => 'mcn_name',
        'Mã hợp đồng MCN'                     => 'mcn_contract_code',
        'Mức phí quản lý MCN'                 => 'mcn_management_fee_rate',
        'Phí quản lý MCN(₫)'                  => 'mcn_management_fee',
        'Mức hoa hồng tiếp thị liên kết theo thỏa thuận' => 'agreed_commission_rate',
        'Hoa hồng ròng tiếp thị liên kết(₫)'  => 'net_commission',
        'Trạng thái sản phẩm liên kết'        => 'affiliate_status',
        'Ghi chú sản phẩm'                    => 'product_note',
        'Loại thuộc tính'                     => 'attribute_type',
        'Trạng thái người mua'                => 'buyer_status',
        'Sub_id1'                             => 'sub_id1',
        'Sub_id2'                             => 'sub_id2',
        'Sub_id3'                             => 'sub_id3',
        'Sub_id4'                             => 'sub_id4',
        'Sub_id5'                             => 'sub_id5',
        'Kênh'                                => 'channel',
    ];

    // Columns that are known to have inconsistent spelling in Shopee export
    private const HEADER_ALIASES = [
        'Tỷ lệ sản phẩm hoa hồng Shope'   => 'shopee_commission_rate',
        'Mức phí quản lý MNC'             => 'mcn_management_fee_rate',
        'Phí quản lý MNC(₫)'              => 'mcn_management_fee',
        'Đối tác chiến dịchr'             => 'campaign_partner',
    ];

    private int $totalRows = 0;
    private int $newCount = 0;
    private int $updatedCount = 0;
    private int $lockedCount = 0;
    private int $unmappedCount = 0;
    private int $errorCount = 0;

    private bool $isDryRun = false;
    private string $importBatch = '';

    public function handle(): int
    {
        $start = microtime(true);
        $this->isDryRun = (bool) $this->option('dry-run');
        $specifiedFile = $this->option('file');

        $this->info('================================');
        $this->info('  Shopee Import');
        $this->info('================================');

        // Resolve file path
        if ($specifiedFile) {
            $filePath = $specifiedFile;
            if (!file_exists($filePath)) {
                $this->error('File không tồn tại: ' . $filePath);
                return Command::FAILURE;
            }
            $originalName = basename($filePath);
            $this->line('');
            $this->line('File: ' . $originalName);
        } else {
            // Scan Downloads for CSV files
            $files = $this->listCsvFiles();
            if (empty($files)) {
                $this->error('Không tìm thấy file CSV nào trong ' . self::DOWNLOADS_DIR);
                return Command::FAILURE;
            }

            $filePath = $files[0]['path'];
            $originalName = basename($filePath);
            $this->line('');
            $this->line('Đã chọn: ' . $originalName);
            $this->line('Lý do: Modified Time mới nhất.');
        }

        // Check file accessibility
        if (!$this->isFileAccessible($filePath)) {
            $this->error('');
            $this->error('Không thể đọc file: ' . $originalName);
            $this->error('Có thể file đang được mở bởi Excel.');
            $this->error('Vui lòng đóng file rồi chạy lại.');
            return Command::FAILURE;
        }

        // Validate header
        $headerResult = $this->enhancedValidateHeader($filePath);
        if (!$headerResult['is_valid']) {
            $this->error('Header không khớp với định dạng Export Shopee.');
            $this->line('');
            $this->line('Các cột bắt buộc:');
            foreach (['ID đơn hàng', 'Item id', 'Sub_id1', 'Hoa hồng ròng tiếp thị liên kết(₫)'] as $col) {
                $this->line('  - ' . $col);
            }
            if (!empty($headerResult['missing'])) {
                $this->line('');
                $this->line('Thiếu:');
                foreach ($headerResult['missing'] as $col) {
                    $this->line('  - ' . $col);
                }
            }
            return Command::FAILURE;
        }

        // Warn about unused columns
        if (!empty($headerResult['unused'])) {
            $this->line('');
            $this->line('Các cột không sử dụng:');
            foreach ($headerResult['unused'] as $col) {
                $this->line('  - ' . $col);
            }
        }

        // Import (or dry run)
        $importResult = $this->importFile($filePath, $headerResult['mapping'], $originalName);
        if (!$importResult) {
            $this->error('Import thất bại.');
            return Command::FAILURE;
        }

        // Move file (only if not dry run)
        if (!$this->isDryRun) {
            try {
                $this->moveToImported($filePath, $originalName);
            } catch (\Exception $e) {
                $this->error('');
                $this->error('Không thể di chuyển file: ' . $originalName);
                $this->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        // Report
        $elapsed = round(microtime(true) - $start, 1);
        $this->line('');
        $this->info('================================');
        $this->info('  Shopee Import');
        $this->info('================================');
        $this->line('  File:              ' . $originalName);
        $this->line('  Batch:             ' . $this->importBatch);
        $this->line('  Platform:          Shopee');
        $this->line('  Tổng dòng:         ' . $this->totalRows);
        $this->line('  Đơn mới:           ' . $this->newCount);
        $this->line('  Đơn cập nhật:      ' . $this->updatedCount);
        $this->line('  Đơn locked (skip): ' . $this->lockedCount);
        $this->line('  Không map user:    ' . $this->unmappedCount);
        $this->line('  Lỗi:               ' . $this->errorCount);
        $this->line('  Thời gian:         ' . $elapsed . ' giây');
        $this->info('================================');

        if ($this->isDryRun) {
            $this->info('  DRY RUN');
            $this->info('================================');
            $this->line('');
            $this->line('  Không có thay đổi nào được ghi vào Database.');
        } else {
            $this->info('  SUCCESS');
            $this->info('================================');
        }

        return Command::SUCCESS;
    }

    private function findNewestCsv(): ?string
    {
        $files = glob(self::DOWNLOADS_DIR . '\*.csv');
        if (!$files) {
            return null;
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        return $files[0];
    }

    private function importFile(string $filePath, array $headerMapping, string $originalName): bool
    {
        $this->importBatch = now()->format('Ymd_His');
        $now = now();
        $rows = [];

        $fh = fopen($filePath, 'r');
        if (!$fh) {
            return false;
        }

        // Skip header line
        fgets($fh);

        // Read all rows
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Some CSV lines may contain quoted fields with newlines,
            // but Shopee export typically doesn't. Use str_getcsv for simplicity.
            $values = str_getcsv($line);
            if (count($values) < 5) {
                continue;
            }

            $this->totalRows++;

            // Build row data from mapping
            $data = [];
            foreach ($headerMapping as $index => $column) {
                if (isset($values[$index])) {
                    $data[$column] = $this->cleanValue($values[$index]);
                }
            }

            if (empty($data['order_id']) || empty($data['item_id'])) {
                $this->errorCount++;
                continue;
            }

            $data['platform'] = 'Shopee';
            $data['import_batch'] = $this->importBatch;
            $data['source_file'] = $originalName;

            // Parse dates
            $data['ordered_at'] = $this->parseDate($data['ordered_at'] ?? null);
            $data['completed_at'] = $this->parseDate($data['completed_at'] ?? null);
            $data['clicked_at'] = $this->parseDate($data['clicked_at'] ?? null);

            // Parse numeric values
            $data['item_price'] = $this->parseDecimal($data['item_price'] ?? 0);
            $data['quantity'] = (int) ($data['quantity'] ?? 0);
            $data['order_amount'] = $this->parseDecimal($data['order_amount'] ?? 0);
            $data['refund_amount'] = $this->parseDecimal($data['refund_amount'] ?? 0);
            $data['shopee_commission_rate'] = $this->parseDecimal($data['shopee_commission_rate'] ?? 0);
            $data['shopee_commission'] = $this->parseDecimal($data['shopee_commission'] ?? 0);
            $data['seller_commission_rate'] = $this->parseDecimal($data['seller_commission_rate'] ?? 0);
            $data['xtra_commission'] = $this->parseDecimal($data['xtra_commission'] ?? 0);
            $data['total_product_commission'] = $this->parseDecimal($data['total_product_commission'] ?? 0);
            $data['order_commission_shopee'] = $this->parseDecimal($data['order_commission_shopee'] ?? 0);
            $data['order_commission_seller'] = $this->parseDecimal($data['order_commission_seller'] ?? 0);
            $data['total_order_commission'] = $this->parseDecimal($data['total_order_commission'] ?? 0);
            $data['mcn_management_fee_rate'] = $this->parseDecimal($data['mcn_management_fee_rate'] ?? 0);
            $data['mcn_management_fee'] = $this->parseDecimal($data['mcn_management_fee'] ?? 0);
            $data['agreed_commission_rate'] = $this->parseDecimal($data['agreed_commission_rate'] ?? 0);
            $data['net_commission'] = $this->parseDecimal($data['net_commission'] ?? 0);

            // User mapping: sub_id1 → users.username → users.id
            $username = $data['sub_id1'] ?? null;
            if ($username && trim($username) !== '') {
                $user = User::where('username', trim($username))->first();
                if ($user) {
                    $data['username'] = $user->username;
                    $data['user_id'] = $user->id;
                } else {
                    $this->unmappedCount++;
                    $data['username'] = null;
                    $data['user_id'] = null;
                }
            } else {
                $data['username'] = null;
                $data['user_id'] = null;
            }

            $rows[] = $data;

            // Process in batches of 50 for memory efficiency
            if (count($rows) >= 50) {
                $this->isDryRun ? $this->processBatchDryRun($rows) : $this->processBatch($rows, $now);
                $rows = [];
            }
        }

        fclose($fh);

        // Process remaining rows
        if (count($rows) > 0) {
            $this->isDryRun ? $this->processBatchDryRun($rows) : $this->processBatch($rows, $now);
        }

        return true;
    }

    private function processBatch(array &$rows, \Illuminate\Support\Carbon $now): void
    {
        DB::transaction(function () use ($rows, $now) {
            foreach ($rows as $data) {
                $existing = AffiliateOrderItem::where('order_id', $data['order_id'])
                    ->where('item_id', $data['item_id'])
                    ->first();

                if ($existing) {
                    if ($existing->locked_at !== null) {
                        $this->lockedCount++;
                        continue;
                    }

                    $data['last_shopee_sync_at'] = $now;

                    // Recalculate cashback in case commission changed
                    $cashback = $this->calculateCashback(
                        (float) $data['net_commission'],
                        (float) $data['order_amount']
                    );
                    $data['cashback_rate'] = $cashback['rate'];
                    $data['cashback_amount'] = $cashback['amount'];

                    // Preserve first_imported_at from original record
                    unset($data['first_imported_at']);

                    $existing->update($data);
                    $this->updatedCount++;
                } else {
                    $data['first_imported_at'] = $now;
                    $data['last_shopee_sync_at'] = $now;

                    $cashback = $this->calculateCashback(
                        (float) $data['net_commission'],
                        (float) $data['order_amount']
                    );
                    $data['cashback_rate'] = $cashback['rate'];
                    $data['cashback_amount'] = $cashback['amount'];

                    AffiliateOrderItem::create($data);
                    $this->newCount++;
                }
            }
        });
    }

    private function processBatchDryRun(array &$rows): void
    {
        foreach ($rows as $data) {
            $existing = AffiliateOrderItem::where('order_id', $data['order_id'])
                ->where('item_id', $data['item_id'])
                ->first();

            if ($existing) {
                if ($existing->locked_at !== null) {
                    $this->lockedCount++;
                    continue;
                }
                $this->updatedCount++;
            } else {
                $this->newCount++;
            }
        }
    }

    private function listCsvFiles(): array
    {
        $files = glob(self::DOWNLOADS_DIR . '\*.csv');
        if (!$files) {
            return [];
        }

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $count = count($files);
        $this->line('');
        $this->line('Đã tìm thấy ' . $count . ' file CSV.');

        foreach ($files as $index => $file) {
            $name = basename($file);
            $mtime = date('Y-m-d H:i:s', filemtime($file));
            $this->line('');
            $this->line('[' . ($index + 1) . '] ' . $name);
            $this->line('  Modified: ' . $mtime);
        }

        return array_map(fn($path) => ['path' => $path, 'mtime' => filemtime($path)], $files);
    }

    private function isFileAccessible(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $fh = @fopen($filePath, 'r');
        if (!$fh) {
            return false;
        }
        fclose($fh);

        return true;
    }

    private function enhancedValidateHeader(string $filePath): array
    {
        $fh = @fopen($filePath, 'r');
        if (!$fh) {
            return ['is_valid' => false, 'mapping' => [], 'missing' => [], 'unused' => []];
        }

        $raw = fgets($fh);
        fclose($fh);

        if ($raw === false) {
            return ['is_valid' => false, 'mapping' => [], 'missing' => [], 'unused' => []];
        }

        // Strip UTF-8 BOM if present
        $bom = "\xEF\xBB\xBF";
        if (str_starts_with($raw, $bom)) {
            $raw = substr($raw, strlen($bom));
        }

        $headers = str_getcsv($raw);

        if (count($headers) < 10) {
            return ['is_valid' => false, 'mapping' => [], 'missing' => [], 'unused' => []];
        }

        $requiredColumns = ['ID đơn hàng', 'Item id', 'Sub_id1', 'Hoa hồng ròng tiếp thị liên kết(₫)'];
        $allKnownHeaders = array_merge(
            array_keys(self::HEADER_MAP),
            array_keys(self::HEADER_ALIASES)
        );

        $mapping = [];
        $foundColumns = [];
        $foundMappedTo = [];

        foreach ($headers as $index => $header) {
            $trimmed = trim($header);

            // Try exact match first
            if (isset(self::HEADER_MAP[$trimmed])) {
                $column = self::HEADER_MAP[$trimmed];
                $mapping[$index] = $column;
                $foundColumns[] = $trimmed;
                $foundMappedTo[] = $column;
                continue;
            }

            // Try alias match
            if (isset(self::HEADER_ALIASES[$trimmed])) {
                $mapping[$index] = self::HEADER_ALIASES[$trimmed];
                $foundColumns[] = $trimmed;
                $foundMappedTo[] = self::HEADER_ALIASES[$trimmed];
                continue;
            }
        }

        // Check missing required columns
        $missing = [];
        foreach ($requiredColumns as $req) {
            $mappedCol = self::HEADER_MAP[$req] ?? null;
            if ($mappedCol && !in_array($mappedCol, $foundMappedTo)) {
                $missing[] = $req;
            }
        }

        // Find unused columns
        $unused = [];
        foreach ($headers as $header) {
            $trimmed = trim($header);
            if (!in_array($trimmed, $foundColumns) && !in_array($trimmed, $allKnownHeaders)) {
                $unused[] = $trimmed;
            }
        }

        $isValid = count($missing) < 3;

        return [
            'is_valid' => $isValid,
            'mapping' => $mapping,
            'missing' => $missing,
            'unused' => $unused,
        ];
    }

    /**
     * Cashback business rule:
     *
     * commission_rate = (net_commission × 0.9) / order_amount
     *
     * If order_amount = 0 → cashback_rate = 0, cashback_amount = 0
     *
     * If commission_rate < 0.12   → cashback_rate = 50
     * If 0.12 <= commission_rate <= 0.52 → cashback_rate = 60
     * If commission_rate > 0.52   → cashback_rate = 70
     *
     * cashback_amount = net_commission × cashback_rate / 100
     */
    private function calculateCashback(float $netCommission, float $orderAmount): array
    {
        if ($orderAmount <= 0 || $netCommission <= 0) {
            return ['rate' => 0, 'amount' => 0];
        }

        $commissionRate = ($netCommission * 0.9) / $orderAmount;

        if ($commissionRate < 0.12) {
            $cashbackRate = 50;
        } elseif ($commissionRate <= 0.52) {
            $cashbackRate = 60;
        } else {
            $cashbackRate = 70;
        }

        $cashbackAmount = $netCommission * $cashbackRate / 100;

        return [
            'rate' => $cashbackRate,
            'amount' => round($cashbackAmount, 2),
        ];
    }

    private function moveToImported(string $filePath, string $originalName): void
    {
        if (!is_dir(self::IMPORTED_DIR)) {
            if (!@mkdir(self::IMPORTED_DIR, 0777, true) && !is_dir(self::IMPORTED_DIR)) {
                throw new \RuntimeException('Không thể tạo thư mục ' . self::IMPORTED_DIR);
            }
        }

        $newName = $this->importBatch . '_' . $originalName;
        $destPath = self::IMPORTED_DIR . '\\' . $newName;

        if (!@rename($filePath, $destPath)) {
            throw new \RuntimeException(
                'Không thể di chuyển file. Có thể file đang được mở bởi Excel hoặc chương trình khác.'
            );
        }
    }

    private function cleanValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        // Remove non-breaking spaces and other invisible chars
        $value = preg_replace('/[\x{200B}\x{FEFF}\x{00A0}]/u', '', $value);

        return $value === '' ? null : $value;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // Shopee format: YYYY-MM-DD HH:MM:SS
        $trimmed = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $trimmed)) {
            return $trimmed;
        }

        return null;
    }

    private function parseDecimal(?string $value): float
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }

        // Remove % sign, dots (thousand separators), and commas (decimal)
        // Shopee format: "3.50%" or "2,221.94" or "151480"
        $cleaned = trim($value);
        $cleaned = str_replace('%', '', $cleaned);
        $cleaned = str_replace(',', '', $cleaned);

        return (float) $cleaned;
    }
}
