<?php

namespace App\Console\Commands;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Services\ShopeeCsvParser;
use App\Services\WalletService;
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

    private int $totalRows = 0;
    private int $newCount = 0;
    private int $updatedCount = 0;
    private int $lockedCount = 0;
    private int $unmappedCount = 0;
    private int $errorCount = 0;
    private int $cashbackCreditedCount = 0;
    private int $cashbackSkippedCount = 0;

    private bool $isDryRun = false;
    private string $importBatch = '';

    private WalletService $walletService;
    private ShopeeCsvParser $parser;

    public function __construct(WalletService $walletService, ShopeeCsvParser $parser)
    {
        parent::__construct();
        $this->walletService = $walletService;
        $this->parser = $parser;
    }

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

        // Parse CSV
        $result = $this->parser->parse($filePath);
        if (!$result['is_valid']) {
            $this->error('Header không khớp với định dạng Export Shopee.');
            $this->line('');
            $this->line('Các cột bắt buộc:');
            foreach ($this->parser->getRequiredColumns() as $col) {
                $this->line('  - ' . $col);
            }
            if (!empty($result['missing'])) {
                $this->line('');
                $this->line('Thiếu:');
                foreach ($result['missing'] as $col) {
                    $this->line('  - ' . $col);
                }
            }
            return Command::FAILURE;
        }

        if (!empty($result['unused'])) {
            $this->line('');
            $this->line('Các cột không sử dụng:');
            foreach ($result['unused'] as $col) {
                $this->line('  - ' . $col);
            }
        }

        // Import (or dry run)
        $importOk = $this->importFromParsedRows($result['rows'], $originalName);
        if (!$importOk) {
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
        $this->line('  Cashback đã ghi:   ' . $this->cashbackCreditedCount);
        $this->line('  Cashback bỏ qua:   ' . $this->cashbackSkippedCount);
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

    private function importFromParsedRows(array $rows, string $originalName): bool
    {
        $this->importBatch = now()->format('Ymd_His');
        $now = now();
        $batch = [];

        foreach ($rows as $data) {
            $this->totalRows++;

            $data['platform'] = 'Shopee';
            $data['import_batch'] = $this->importBatch;
            $data['source_file'] = $originalName;

            // Parse dates
            $data['ordered_at'] = $this->parser->parseDate($data['ordered_at'] ?? null);
            $data['completed_at'] = $this->parser->parseDate($data['completed_at'] ?? null);
            $data['clicked_at'] = $this->parser->parseDate($data['clicked_at'] ?? null);

            // Parse numeric values
            $data['item_price'] = $this->parser->parseDecimal($data['item_price'] ?? 0);
            $data['quantity'] = (int) ($data['quantity'] ?? 0);
            $data['order_amount'] = $this->parser->parseDecimal($data['order_amount'] ?? 0);
            $data['refund_amount'] = $this->parser->parseDecimal($data['refund_amount'] ?? 0);
            $data['shopee_commission_rate'] = $this->parser->parseDecimal($data['shopee_commission_rate'] ?? 0);
            $data['shopee_commission'] = $this->parser->parseDecimal($data['shopee_commission'] ?? 0);
            $data['seller_commission_rate'] = $this->parser->parseDecimal($data['seller_commission_rate'] ?? 0);
            $data['xtra_commission'] = $this->parser->parseDecimal($data['xtra_commission'] ?? 0);
            $data['total_product_commission'] = $this->parser->parseDecimal($data['total_product_commission'] ?? 0);
            $data['order_commission_shopee'] = $this->parser->parseDecimal($data['order_commission_shopee'] ?? 0);
            $data['order_commission_seller'] = $this->parser->parseDecimal($data['order_commission_seller'] ?? 0);
            $data['total_order_commission'] = $this->parser->parseDecimal($data['total_order_commission'] ?? 0);
            $data['mcn_management_fee_rate'] = $this->parser->parseDecimal($data['mcn_management_fee_rate'] ?? 0);
            $data['mcn_management_fee'] = $this->parser->parseDecimal($data['mcn_management_fee'] ?? 0);
            $data['agreed_commission_rate'] = $this->parser->parseDecimal($data['agreed_commission_rate'] ?? 0);
            $data['net_commission'] = $this->parser->parseDecimal($data['net_commission'] ?? 0);

            // If sub_id1 is empty, generate default username based on channel
            $rawSubId1 = $data['sub_id1'] ?? null;
            if (!$rawSubId1 || trim($rawSubId1) === '') {
                $channel = isset($data['channel']) ? trim($data['channel']) : '';
                $data['sub_id1'] = match ($channel) {
                    'Shopee', 'shopee', 'SHOPEE'     => 'NonameShopee',
                    'Zalo', 'zalo', 'ZALO'           => 'NonameZalo',
                    'Facebook', 'facebook', 'FACEBOOK' => 'NonameFacebook',
                    'TikTok', 'tiktok', 'TIKTOK'     => 'NonameTikTok',
                    'Website', 'website', 'WEBSITE'   => 'NonameWebsite',
                    default                           => 'NonameUnknown',
                };
            }

            // User mapping: sub_id1 → users.username → users.id
            $username = $data['sub_id1'] ?? null;
            if ($username && trim($username) !== '') {
                $user = User::where('username', trim($username))->first();
                if ($user) {
                    $data['username'] = $user->username;
                    $data['user_id'] = $user->id;
                } else {
                    $this->unmappedCount++;
                    $data['username'] = $data['sub_id1'];
                    $data['user_id'] = null;
                }
            } else {
                $data['username'] = null;
                $data['user_id'] = null;
            }

            $batch[] = $data;

            if (count($batch) >= 50) {
                $this->isDryRun ? $this->processBatchDryRun($batch) : $this->processBatch($batch, $now);
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            $this->isDryRun ? $this->processBatchDryRun($batch) : $this->processBatch($batch, $now);
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

                    $oldStatus = $existing->affiliate_status;

                    $data['last_shopee_sync_at'] = $now;

                    // Recalculate cashback in case commission changed
                    $itemAmount = (float) $data['item_price'] * (int) $data['quantity'];
                    $productCommission = (float) $data['total_product_commission'];
                    $cashback = $this->parser->calculateCashback(
                        $productCommission,
                        $itemAmount
                    );
                    $data['cashback_rate'] = $cashback['rate'];
                    $data['cashback_amount'] = $cashback['amount'];

                    // Preserve first_imported_at from original record
                    unset($data['first_imported_at']);

                    $existing->update($data);
                    $this->updatedCount++;

                    $item = $existing->fresh();
                } else {
                    $oldStatus = null;

                    $data['first_imported_at'] = $now;
                    $data['last_shopee_sync_at'] = $now;

                    $itemAmount = (float) $data['item_price'] * (int) $data['quantity'];
                    $productCommission = (float) $data['total_product_commission'];
                    $cashback = $this->parser->calculateCashback(
                        $productCommission,
                        $itemAmount
                    );
                    $data['cashback_rate'] = $cashback['rate'];
                    $data['cashback_amount'] = $cashback['amount'];

                    $item = AffiliateOrderItem::create($data);
                    $this->newCount++;
                }

                $this->creditCashbackForItem($item, $oldStatus);
            }
        });
    }

    private function creditCashbackForItem(AffiliateOrderItem $item, ?string $oldStatus): void
    {
        if ($item->user_id === null) {
            return;
        }

        if ($item->affiliate_status !== 'Hoàn thành') {
            return;
        }

        if ($oldStatus === 'Hoàn thành') {
            return;
        }

        $transaction = $this->walletService->creditCashback($item, throwOnDuplicate: false);

        if ($transaction) {
            $this->cashbackCreditedCount++;
            $this->info(sprintf(
                'Cashback credited: #%d (%s) — %s VNĐ',
                $item->id,
                $item->order_id,
                number_format((float) $transaction->amount, 0, ',', '.'),
            ));
        } else {
            $this->cashbackSkippedCount++;
            $this->warn(sprintf(
                'Duplicate cashback skipped: #%d (%s)',
                $item->id,
                $item->order_id,
            ));
        }
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
}
