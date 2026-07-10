<?php

namespace App\Services;

class ShopeeCsvParser
{
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

    private const HEADER_ALIASES = [
        'Tỷ lệ sản phẩm hoa hồng Shope'   => 'shopee_commission_rate',
        'Mức phí quản lý MNC'             => 'mcn_management_fee_rate',
        'Phí quản lý MNC(₫)'              => 'mcn_management_fee',
        'Đối tác chiến dịchr'             => 'campaign_partner',
    ];

    private const MIN_COLUMNS = 10;

    private const REQUIRED_COLUMNS = [
        'ID đơn hàng',
        'Item id',
        'Sub_id1',
        'Hoa hồng ròng tiếp thị liên kết(₫)',
    ];

    public function parse(string $filePath): array
    {
        $validation = $this->validateHeader($filePath);
        if (!$validation['is_valid']) {
            return [
                'is_valid' => false,
                'missing' => $validation['missing'],
                'unused' => $validation['unused'],
                'rows' => [],
            ];
        }

        $rows = $this->readRows($filePath, $validation['mapping']);

        return [
            'is_valid' => true,
            'missing' => [],
            'unused' => $validation['unused'],
            'rows' => $rows,
        ];
    }

    public function validateHeader(string $filePath): array
    {
        $raw = $this->readHeaderLine($filePath);
        if ($raw === null) {
            return ['is_valid' => false, 'mapping' => [], 'missing' => [], 'unused' => []];
        }

        $headers = str_getcsv($raw);

        if (count($headers) < self::MIN_COLUMNS) {
            return ['is_valid' => false, 'mapping' => [], 'missing' => [], 'unused' => []];
        }

        $allKnownHeaders = array_merge(
            array_keys(self::HEADER_MAP),
            array_keys(self::HEADER_ALIASES)
        );

        $mapping = [];
        $foundColumns = [];
        $foundMappedTo = [];

        foreach ($headers as $index => $header) {
            $trimmed = trim($header);

            if (isset(self::HEADER_MAP[$trimmed])) {
                $column = self::HEADER_MAP[$trimmed];
                $mapping[$index] = $column;
                $foundColumns[] = $trimmed;
                $foundMappedTo[] = $column;
                continue;
            }

            if (isset(self::HEADER_ALIASES[$trimmed])) {
                $mapping[$index] = self::HEADER_ALIASES[$trimmed];
                $foundColumns[] = $trimmed;
                $foundMappedTo[] = self::HEADER_ALIASES[$trimmed];
                continue;
            }
        }

        $missing = [];
        foreach (self::REQUIRED_COLUMNS as $req) {
            $mappedCol = self::HEADER_MAP[$req] ?? null;
            if ($mappedCol && !in_array($mappedCol, $foundMappedTo)) {
                $missing[] = $req;
            }
        }

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

    public function getRequiredColumns(): array
    {
        return self::REQUIRED_COLUMNS;
    }

    public function getHeaderMap(): array
    {
        return self::HEADER_MAP;
    }

    public function getHeaderAliases(): array
    {
        return self::HEADER_ALIASES;
    }

    public function cleanValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = preg_replace('/[\x{200B}\x{FEFF}\x{00A0}]/u', '', $value);
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $trimmed)) {
            return $trimmed;
        }

        return null;
    }

    public function parseDecimal(?string $value): float
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }

        $cleaned = trim($value);
        $cleaned = str_replace('%', '', $cleaned);
        $cleaned = str_replace(',', '', $cleaned);

        return (float) $cleaned;
    }

    public function calculateCashback(float $productCommission, float $itemAmount): array
    {
        if ($itemAmount <= 0 || $productCommission <= 0) {
            return ['rate' => 0, 'amount' => 0];
        }

        $commissionRate = ($productCommission * 0.9) / $itemAmount;

        if ($commissionRate < 0.12) {
            $cashbackRate = 50;
        } elseif ($commissionRate <= 0.52) {
            $cashbackRate = 60;
        } else {
            $cashbackRate = 70;
        }

        $cashbackAmount = $productCommission * $cashbackRate / 100;

        return [
            'rate' => $cashbackRate,
            'amount' => round($cashbackAmount, 2),
        ];
    }

    private function readHeaderLine(string $filePath): ?string
    {
        $fh = @fopen($filePath, 'r');
        if (!$fh) {
            return null;
        }

        $raw = fgets($fh);
        fclose($fh);

        if ($raw === false) {
            return null;
        }

        // Strip UTF-8 BOM if present
        $bom = "\xEF\xBB\xBF";
        if (str_starts_with($raw, $bom)) {
            $raw = substr($raw, strlen($bom));
        }

        return $raw;
    }

    private function readRows(string $filePath, array $mapping): array
    {
        $fh = fopen($filePath, 'r');
        if (!$fh) {
            return [];
        }

        // Skip header
        fgets($fh);

        $rows = [];

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line);
            if (count($values) < 5) {
                continue;
            }

            $data = [];
            foreach ($mapping as $index => $column) {
                if (isset($values[$index])) {
                    $data[$column] = $this->cleanValue($values[$index]);
                }
            }

            if (empty($data['order_id']) || empty($data['item_id'])) {
                continue;
            }

            $rows[] = $data;
        }

        fclose($fh);

        return $rows;
    }
}
