<?php

namespace Tests\Unit\Services\Lazada;

use App\Services\Lazada\DTOs\LazadaConversionRecord;
use PHPUnit\Framework\TestCase;

class LazadaConversionRecordTest extends TestCase
{
    public function test_camel_case_documented_fields_are_read(): void
    {
        $record = LazadaConversionRecord::fromArray([
            'orderId'    => 'O1',
            'subOrderId' => 'S1',
            'sku'        => 'SKU1',
            'estPayout'  => '12000.00',
            'subId1'     => '5',
            'subId2'     => 'alice',
        ]);

        $this->assertSame('O1', $record->getOrderId());
        $this->assertSame('S1', $record->getSubOrderId());
        $this->assertSame('SKU1', $record->getSku());
        $this->assertSame(12000.0, $record->getEstPayout());
        $this->assertSame('5', $record->getSubId1());
        $this->assertSame('alice', $record->getSubId2());
    }

    public function test_underscored_variants_are_read_as_fallback(): void
    {
        $record = LazadaConversionRecord::fromArray([
            'order_id'    => 'O2',
            'sub_order_id' => 'S2',
            'sub_id1'     => '7',
        ]);

        $this->assertSame('O2', $record->getOrderId());
        $this->assertSame('S2', $record->getSubOrderId());
        $this->assertSame('7', $record->getSubId1());
    }

    public function test_summary_amounts_default_to_zero_when_missing(): void
    {
        $record = LazadaConversionRecord::fromArray([]);

        $this->assertSame(0.0, $record->getEstPayout());
        $this->assertSame(0.0, $record->getBasePayout());
        $this->assertSame(0.0, $record->getBonusPayout());
        $this->assertSame(0.0, $record->getOrderAmt());
    }

    public function test_dates_are_normalized_to_mysql_format(): void
    {
        $record = LazadaConversionRecord::fromArray([
            'conversionTime' => '2026-08-01 10:00:00',
            'fulfilledTime'  => '2026-08-02 09:30:00',
        ]);

        $this->assertSame('2026-08-01 10:00:00', $record->getConversionTime());
        $this->assertSame('2026-08-02 09:30:00', $record->getFulfilledTime());
        $this->assertNull($record->getReturnedTime());
    }

    public function test_tracking_tokens_default_to_empty_string(): void
    {
        $record = LazadaConversionRecord::fromArray([]);

        $this->assertSame('', $record->getSubId1());
        $this->assertSame('', $record->getSubId2());
        $this->assertSame('', $record->getOrderId());
    }
}