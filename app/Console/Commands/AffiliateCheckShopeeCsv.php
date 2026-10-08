<?php

namespace App\Console\Commands;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ShopeeCsvParser;
use Illuminate\Console\Command;

/**
 * Shopee CSV ↔ DB reconciliation — STRICTLY READ-ONLY audit.
 *
 * Runs OUTSIDE affiliate:sync-all (never scheduled, never locked): it only
 * reads the newest Shopee Affiliate Commission Report CSV from Downloads and
 * compares it against affiliate_order_items (platform = Shopee).
 *
 * No AddLiveTag API call, no DB write, no wallet write — ever.
 */
class AffiliateCheckShopeeCsv extends Command
{
    protected $signature = 'affiliate:check-shopee-csv
                            {--file= : Path to a specific CSV file (default: newest *.csv in Downloads)}
                            {--dry-run : Compatibility flag — this command is ALWAYS read-only}';

    protected $description = 'Audit READ-ONLY CSV Shopee vs DB (không API, không ghi DB/ví). Tự chọn CSV mới nhất trong Downloads. Exit 0 = PASS/WARNING, exit 1 = FAIL.';

    protected $help = <<<'HELP'
So sánh CSV Shopee Affiliate Commission Report với affiliate_order_items (platform = Shopee).

Usage:
  php artisan affiliate:check-shopee-csv
      → tự tìm file *.csv MỚI NHẤT trong C:\Users\Administrator\Downloads
  php artisan affiliate:check-shopee-csv --file="C:\path\report.csv"
      → dùng file chỉ định

READ-ONLY guarantees: không gọi AddLiveTag API, không ghi DB, không ghi ví,
không nằm trong affiliate:sync-all (chỉ audit, không bao giờ được schedule).

Exit codes:
  0 = PASS (không sai lệch) hoặc WARNING (chỉ informational: snapshot khác
      thời điểm, rounding <= 1, DB có thêm rows ngoài CSV)
  1 = FAIL (critical: sai số lượng, lệch tiền > 1, user mapping conflict)
      hoặc không đọc được CSV
HELP;

    private const DOWNLOADS_DIR = 'C:\Users\Administrator\Downloads';

    /**
     * Counters for the §11 report. Numeric comparisons use a rounding
     * tolerance of 1 VND (delta <= 1 → ROUNDING/warning, delta > 1 → FAIL).
     *
     * @var array<string, int>
     */
    private array $report = [
        'csv_rows' => 0,
        'csv_orders' => 0,
        'csv_items' => 0,
        'match' => 0,
        'csv_only' => 0,
        'db_only' => 0,
        'status_mismatch' => 0,
        'quantity_mismatch' => 0,
        'price_mismatch' => 0,
        'order_amount_mismatch' => 0,
        'commission_mismatch' => 0,
        'mcn_mismatch' => 0,
        'cashback_mismatch' => 0,
        'rounding_field_mismatch' => 0,
        'critical_field_mismatch' => 0,
        'user_mapping_conflict' => 0,
        'sub_id_mismatch' => 0,
        'credit_mismatch' => 0,
        'protected' => 0,
    ];

    public function __construct(private readonly ShopeeCsvParser $parser)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('========================================');
        $this->line('SHOPEE CSV RECONCILIATION');
        $this->line('========================================');

        $filePath = $this->resolveFile();
        if (! $filePath) {
            return Command::FAILURE;
        }

        if (! $this->isFileAccessible($filePath)) {
            $this->error('Cannot read CSV file: '.$filePath);

            return Command::FAILURE;
        }

        $parsed = $this->parser->parse($filePath);
        if (! $parsed['is_valid']) {
            $this->error('Invalid CSV header — not a Shopee Affiliate Commission Report export.');

            return Command::FAILURE;
        }

        $rows = $this->normalizeParsed($parsed['rows'], basename($filePath));
        $this->report['csv_rows'] = count($parsed['rows']);
        $this->report['csv_items'] = count($rows);
        $this->report['csv_orders'] = count(array_unique(array_map(
            static fn (string $key): string => explode('|', $key, 2)[0],
            array_keys($rows),
        )));

        $this->compare($rows);

        [$verdict, $reasons, $exitCode] = $this->verdict();
        $this->printReport($filePath, $verdict, $reasons);

        return $exitCode;
    }

    // ------------------------------------------------------------------
    //  CSV discovery (same rule as affiliate:import-shopee: newest wins)
    // ------------------------------------------------------------------

    private function resolveFile(): ?string
    {
        $specified = $this->option('file');
        if ($specified) {
            if (! file_exists($specified)) {
                $this->error('File not found: '.$specified);

                return null;
            }

            return $specified;
        }

        $files = $this->listCsvFiles();
        if (empty($files)) {
            $this->error('No CSV found in '.self::DOWNLOADS_DIR);

            return null;
        }

        return $files[0]['path'];
    }

    /**
     * @return list<array{path: string, mtime: int}>
     */
    private function listCsvFiles(): array
    {
        $files = glob(self::DOWNLOADS_DIR.'\*.csv');
        if (! $files) {
            return [];
        }

        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return array_map(fn (string $path): array => ['path' => $path, 'mtime' => filemtime($path)], $files);
    }

    private function isFileAccessible(string $path): bool
    {
        $fh = @fopen($path, 'r');
        if (! $fh) {
            return false;
        }
        fclose($fh);

        return true;
    }

    // ------------------------------------------------------------------
    //  CSV normalization (mirrors affiliate:import-shopee, no writes)
    // ------------------------------------------------------------------

    private function normalizeParsed(array $rows, string $filename): array
    {
        $out = [];
        foreach ($rows as $data) {
            $data['platform'] = 'Shopee';
            $data['source_file'] = $filename;

            $data['ordered_at'] = $this->parser->parseDate($data['ordered_at'] ?? null);
            $data['completed_at'] = $this->parser->parseDate($data['completed_at'] ?? null);
            $data['clicked_at'] = $this->parser->parseDate($data['clicked_at'] ?? null);

            $data['item_price'] = $this->parser->parseDecimal($data['item_price'] ?? 0);
            $data['quantity'] = (int) ($data['quantity'] ?? 0);
            $data['order_amount'] = $this->parser->parseDecimal($data['order_amount'] ?? 0);
            $data['total_product_commission'] = $this->parser->parseDecimal($data['total_product_commission'] ?? 0);
            $data['mcn_management_fee'] = $this->parser->parseDecimal($data['mcn_management_fee'] ?? 0);

            $rawSubId1 = $data['sub_id1'] ?? null;
            if (! $rawSubId1 || trim($rawSubId1) === '') {
                $channel = trim((string) ($data['channel'] ?? ''));
                $data['sub_id1'] = match ($channel) {
                    'Shopee', 'shopee', 'SHOPEE' => 'NonameShopee',
                    'Zalo', 'zalo', 'ZALO' => 'NonameZalo',
                    'Facebook', 'facebook', 'FACEBOOK' => 'NonameFacebook',
                    'TikTok', 'tiktok', 'TIKTOK' => 'NonameTikTok',
                    'Website', 'website', 'WEBSITE' => 'NonameWebsite',
                    default => 'NonameUnknown',
                };
            }

            $itemAmount = (float) $data['item_price'] * (int) $data['quantity'];
            $cashback = $this->parser->calculateCashback((float) $data['total_product_commission'], $itemAmount);
            $data['cashback_rate'] = $cashback['rate'];
            $data['cashback_amount'] = $cashback['amount'];

            $key = $data['order_id'].'|'.$data['item_id'];
            $out[$key] = $data;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    //  Comparison (item-level, CSV is the snapshot)
    // ------------------------------------------------------------------

    private function compare(array $csvRows): void
    {
        $keys = array_keys($csvRows);

        $dbMap = [];
        AffiliateOrderItem::query()
            ->where('platform', 'Shopee')
            ->get()
            ->each(function (AffiliateOrderItem $row) use (&$dbMap): void {
                $dbMap[$row->order_id.'|'.$row->item_id] = $row;
            });

        $allKeys = array_unique(array_merge($keys, array_keys($dbMap)));
        $credited = $this->loadCreditedIds($dbMap);

        foreach ($allKeys as $key) {
            $csv = $csvRows[$key] ?? null;
            $db = $dbMap[$key] ?? null;

            if ($csv && ! $db) {
                $this->report['csv_only']++;

                continue;
            }
            if ($db && ! $csv) {
                $this->report['db_only']++;

                continue;
            }

            // Locked / finalized / reversed rows are never touched by any
            // sync — report them as protected, do not compare.
            if ($db->locked_at !== null || $db->finalized_at !== null || $db->reversed_at !== null) {
                $this->report['protected']++;

                continue;
            }

            // User mapping checks keep the Phase 1/2 semantics: a mapping
            // conflict is reported (and now FAILS the audit) — never fixed here.
            $csvUser = User::where('username', trim((string) ($csv['sub_id1'] ?? '')))->first();
            $dbUserId = $db->user_id;
            $csvUserId = $csvUser?->id;

            if ($dbUserId && $csvUserId && $dbUserId !== $csvUserId) {
                $this->report['user_mapping_conflict']++;

                continue;
            }
            if ($dbUserId && ! $csvUserId && trim((string) ($csv['sub_id1'] ?? '')) !== $db->username) {
                $this->report['sub_id_mismatch']++;

                continue;
            }

            // Already credited → money is final; never re-audit it.
            if (isset($credited[$db->id])) {
                $this->report['protected']++;

                continue;
            }

            if ($this->compareFields($csv, $db)) {
                continue;
            }

            $this->report['match']++;
        }
    }

    /**
     * Field-level comparison. Returns TRUE when the row has ANY mismatch.
     *
     * - status: string compare (snapshot/timing → WARNING only)
     * - quantity: exact integer, any difference is CRITICAL
     * - money fields: numeric compare, |delta| <= 1 = rounding (WARNING),
     *   |delta| > 1 = CRITICAL
     */
    private function compareFields(array $csv, AffiliateOrderItem $db): bool
    {
        $mismatched = false;

        $csvAffiliate = trim((string) ($csv['affiliate_status'] ?? ''));
        $csvOrder = trim((string) ($csv['order_status'] ?? ''));
        if ($csvAffiliate !== trim((string) ($db->affiliate_status ?? ''))
            || $csvOrder !== trim((string) ($db->order_status ?? ''))) {
            $this->report['status_mismatch']++;
            $mismatched = true;
        }

        if ((int) ($csv['quantity'] ?? 0) !== (int) $db->quantity) {
            $this->report['quantity_mismatch']++;
            $this->report['critical_field_mismatch']++;
            $mismatched = true;
        }

        $moneyFields = [
            'item_price' => 'price_mismatch',
            'order_amount' => 'order_amount_mismatch',
            'total_product_commission' => 'commission_mismatch',
            'mcn_management_fee' => 'mcn_mismatch',
            'cashback_amount' => 'cashback_mismatch',
        ];

        foreach ($moneyFields as $field => $counter) {
            $delta = abs((float) ($csv[$field] ?? 0) - (float) ($db->$field ?? 0));

            if ($delta <= 0.004) {
                continue;
            }

            $this->report[$counter]++;
            $mismatched = true;

            if ($delta > 1.0) {
                $this->report['critical_field_mismatch']++;
            } else {
                $this->report['rounding_field_mismatch']++;
            }
        }

        return $mismatched;
    }

    /**
     * @param  array<string, AffiliateOrderItem>  $dbMap
     * @return array<int, bool>
     */
    private function loadCreditedIds(array $dbMap): array
    {
        if (empty($dbMap)) {
            return [];
        }

        $ids = array_values(array_map(fn (AffiliateOrderItem $row): int => $row->id, $dbMap));
        $credited = [];

        WalletTransaction::query()
            ->where('reference_type', 'affiliate_order_item')
            ->whereIn('reference_id', $ids)
            ->where('type', WalletTransaction::TYPE_CASHBACK)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->pluck('reference_id')
            ->each(function ($id) use (&$credited): void {
                $credited[(int) $id] = true;
            });

        return $credited;
    }

    // ------------------------------------------------------------------
    //  Verdict (§12 exit codes)
    // ------------------------------------------------------------------

    /**
     * FAIL (exit 1)  = critical money/mapping differences.
     * WARNING (0)    = informational only (snapshot timing, rounding <= 1).
     * PASS (0)       = every compared value matches.
     *
     * @return array{0: string, 1: list<string>, 2: int}
     */
    private function verdict(): array
    {
        $r = $this->report;
        $critical = [];
        $warnings = [];

        if ($r['credit_mismatch'] > 0) {
            $critical[] = 'CREDIT_MISMATCH: '.$r['credit_mismatch'].' (số tiền đã credit khác cashback trong DB)';
        }
        if ($r['critical_field_mismatch'] > 0) {
            $critical[] = 'FIELD_MISMATCH (>1): '.$r['critical_field_mismatch'].' giá trị lệch vượt dung sai làm tròn';
        }
        if ($r['quantity_mismatch'] > 0) {
            $critical[] = 'QUANTITY_MISMATCH: '.$r['quantity_mismatch'];
        }
        if ($r['user_mapping_conflict'] > 0) {
            $critical[] = 'USER_MAPPING_CONFLICT: '.$r['user_mapping_conflict'].' (CSV và DB gán 2 user khác nhau)';
        }

        if ($r['csv_only'] > 0) {
            $warnings[] = 'CSV_ONLY: '.$r['csv_only'].' (DB chưa đồng bộ bằng CSV snapshot)';
        }
        if ($r['db_only'] > 0) {
            $warnings[] = 'DB_ONLY: '.$r['db_only'].' (DB có rows ngoài CSV snapshot)';
        }
        if ($r['status_mismatch'] > 0) {
            $warnings[] = 'STATUS_MISMATCH: '.$r['status_mismatch'].' (có thể do snapshot khác thời điểm)';
        }
        if ($r['rounding_field_mismatch'] > 0) {
            $warnings[] = 'ROUNDING (<=1): '.$r['rounding_field_mismatch'].' giá trị lệch trong dung sai làm tròn';
        }
        if ($r['sub_id_mismatch'] > 0) {
            $warnings[] = 'SUB_ID_MISMATCH: '.$r['sub_id_mismatch'];
        }

        if ($critical) {
            return ['FAIL', array_merge($critical, $warnings), Command::FAILURE];
        }
        if ($warnings) {
            return ['WARNING', $warnings, Command::SUCCESS];
        }

        return ['PASS', ['Không phát hiện sai lệch giữa CSV và DB.'], Command::SUCCESS];
    }

    // ------------------------------------------------------------------
    //  §11 report output
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $reasons
     */
    private function printReport(string $filePath, string $verdict, array $reasons): void
    {
        $fi = new \SplFileInfo($filePath);

        $this->newLine();
        $this->line('CSV:');
        $this->line('  '.$fi->getFilename());
        $this->newLine();
        $this->line('Modified:');
        $this->line('  '.date('Y-m-d H:i:s', $fi->isFile() ? $fi->getMTime() : time()));
        $this->newLine();
        $this->line('Rows:');
        $this->line('  '.number_format($this->report['csv_rows']));
        $this->newLine();
        $this->line('Orders:');
        $this->line('  '.number_format($this->report['csv_orders']));
        $this->newLine();
        $this->line('Items:');
        $this->line('  '.number_format($this->report['csv_items']));

        $this->newLine();
        $this->line('----------------------------------------');
        $this->line('COMPARISON');
        $this->line('----------------------------------------');
        $this->newLine();
        $this->line('  CSV <-> DB (platform = Shopee, item-level)');

        foreach ($this->comparisonLines() as [$label, $value]) {
            $this->line(sprintf('  %-26s %s', $label.':', $value));
        }

        $this->newLine();
        $this->line('----------------------------------------');
        $this->line('VERDICT');
        $this->line('----------------------------------------');
        $this->newLine();
        $this->line('  '.$verdict);

        foreach ($reasons as $reason) {
            $this->line('  - '.$reason);
        }

        $this->newLine();
        $this->line('READ-ONLY: no DB writes, no wallet writes, no API calls.');
        $this->line('========================================');
    }

    /**
     * Comparison lines in the fixed §11 order.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function comparisonLines(): array
    {
        $labels = [
            'match' => 'Match',
            'csv_only' => 'CSV_ONLY',
            'db_only' => 'DB_ONLY',
            'status_mismatch' => 'Status mismatch',
            'quantity_mismatch' => 'Quantity mismatch',
            'price_mismatch' => 'Price mismatch',
            'order_amount_mismatch' => 'Order amount mismatch',
            'commission_mismatch' => 'Commission mismatch',
            'mcn_mismatch' => 'MCN fee mismatch',
            'cashback_mismatch' => 'Cashback mismatch',
            'user_mapping_conflict' => 'User mapping mismatch',
            'sub_id_mismatch' => 'Sub ID mismatch',
            'credit_mismatch' => 'Credit mismatch',
            'protected' => 'Protected',
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            $lines[] = [$label, number_format((int) $this->report[$key])];
        }

        return $lines;
    }
}
