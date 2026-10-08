<?php

namespace App\Services\AddLiveTag;

use App\Services\ShopeeCsvParser;
use Illuminate\Support\Carbon;

class ConversionsNormalizer
{
    public function __construct(
        private readonly ShopeeCsvParser $parser
    ) {}

    public function normalize(array $apiRows): array
    {
        $groups = [];
        $normalized = [];

        foreach ($apiRows as $row) {
            if (!is_array($row)) continue;
            $n = $this->mapRow($row);
            if (!$n) continue;
            $key = $n['order_id'] . '|' . $n['item_id'];
            $groups[$key][] = $n;
            $normalized[] = $n;
        }

        return [
            'all' => $normalized,
            'groups' => $groups,
        ];
    }

    private function mapRow(array $row): ?array
    {
        $orderSn = $this->clean($row['order_sn'] ?? null);
        if (!$orderSn) return null;
        $itemUrl = $row['item_url'] ?? null;
        $itemId = $this->extractItemId($itemUrl);
        if (!$itemId) return null;

        $statusCode = $row['status_code'] ?? null;
        if (is_array($statusCode)) {
            $statusCodeStr = (string) (array_key_first($statusCode) ?? '');
        } else {
            $statusCodeStr = (string) $statusCode;
        }

        $affiliateStatus = $this->mapStatus($statusCodeStr);
        $orderStatus = $affiliateStatus;

        $totalProductCommission = $this->parser->parseDecimal($row['commission'] ?? 0);
        $itemPrice = $this->parser->parseDecimal($row['price'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);
        $itemAmount = $itemPrice * $qty;
        $cb = $this->parser->calculateCashback($totalProductCommission, $itemAmount);

        $mcnFee = $this->parser->parseDecimal($row['mcn_fee'] ?? 0);
        $subId1 = $this->clean($row['sub_id1'] ?? null);

        $purchaseTime = $this->parseDt($row['purchase_time'] ?? null);
        $completeTime = $this->parseDt($row['complete_time'] ?? null);
        $clickTime = $this->parseDt($row['click_time'] ?? null);

        $orderValue = $this->parser->parseDecimal($row['order_value'] ?? ($itemPrice * $qty));

        return [
            'order_id' => $orderSn,
            'checkout_id' => $this->clean($row['checkout_id'] ?? null),
            'ordered_at' => $purchaseTime,
            'completed_at' => $completeTime,
            'clicked_at' => $clickTime,
            'item_id' => $itemId,
            'item_name' => $this->clean($row['item_name'] ?? null),
            'item_price' => $itemPrice,
            'quantity' => $qty,
            'order_amount' => $orderValue,
            'total_product_commission' => $totalProductCommission,
            'mcn_management_fee' => $mcnFee,
            'mcn_fee' => $mcnFee,
            'affiliate_status' => $affiliateStatus,
            'order_status' => $orderStatus,
            'status_code' => $statusCodeStr,
            'commission_status' => $this->clean($row['commission_status'] ?? null),
            'sub_id1' => $subId1,
            'sub_id2' => $this->clean($row['sub_id2'] ?? null),
            'sub_id3' => $this->clean($row['sub_id3'] ?? null),
            'sub_id4' => $this->clean($row['sub_id4'] ?? null),
            'sub_id5' => $this->clean($row['sub_id5'] ?? null),
            'utm' => $this->clean($row['utm'] ?? null),
            'cashback_rate' => $cb['rate'],
            'cashback_amount' => $cb['amount'],
            'source_file' => 'addlivetag-api',
            'platform' => 'Shopee',
        ];
    }

    private function extractItemId($itemUrl): ?string
    {
        if (!$itemUrl) return null;
        if (is_numeric($itemUrl)) return (string) $itemUrl;
        $s = (string) $itemUrl;
        if (preg_match('/[?&]item_id=(\d+)/i', $s, $m)) {
            return $m[1];
        }
        if (preg_match('/i\.(\d+)\.(\d+)/i', $s, $m)) {
            return $m[2];
        }
        if (preg_match('/(\d+)/', $s, $m)) {
            $id = $m[1];
            if (strlen($id) >= 8) return $id;
        }
        return null;
    }

    private function mapStatus(string $code): string
    {
        $c = strtolower(trim($code));
        return match ($c) {
            'completed' => 'Hoàn thành',
            'paid' => 'Đang chờ xử lý',
            'unpaid' => 'Đang chờ xử lý',
            'cancelled' => 'Đã hủy',
            default => 'Đang chờ xử lý',
        };
    }

    private function clean($v): ?string
    {
        if ($v === null || $v === '') return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function parseDt($v): ?string
    {
        if ($v === null || $v === '') return null;
        $s = trim((string) $v);
        if ($s === '0') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return $s;

        // AddLiveTag returns unix timestamps (seconds) for purchase_time,
        // complete_time and click_time.
        if (preg_match('/^\d{10}$/', $s)) {
            return date('Y-m-d H:i:s', (int) $s);
        }

        try {
            $dt = Carbon::parse($s);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
