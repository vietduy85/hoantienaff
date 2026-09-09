<?php

namespace Tests\Unit\Services\Lazada;

use App\Services\Lazada\LazadaOrderStatusMapper;
use PHPUnit\Framework\TestCase;

class LazadaOrderStatusMapperTest extends TestCase
{
    private LazadaOrderStatusMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new LazadaOrderStatusMapper();
    }

    public function test_fulfilled_maps_to_completed(): void
    {
        $mapped = $this->mapper->map('fulfilled');

        $this->assertSame('Hoàn thành', $mapped['status']);
        $this->assertFalse($mapped['unknown']);
    }

    public function test_delivered_maps_to_completed(): void
    {
        $mapped = $this->mapper->map('delivered');

        $this->assertSame('Hoàn thành', $mapped['status']);
        $this->assertFalse($mapped['unknown']);
    }

    public function test_returned_maps_to_cancelled(): void
    {
        $mapped = $this->mapper->map('returned');

        $this->assertSame('Đã hủy', $mapped['status']);
        $this->assertFalse($mapped['unknown']);
        $this->assertTrue(LazadaOrderStatusMapper::isTerminal('Đã hủy'));
    }

    public function test_unknown_status_stays_pending_and_is_tallied(): void
    {
        $mapped = $this->mapper->map('shipped');

        $this->assertSame('Đang xử lý', $mapped['status']);
        $this->assertTrue($mapped['unknown']);
    }

    public function test_empty_status_is_unknown_pending_not_terminal(): void
    {
        $mapped = $this->mapper->map('');

        $this->assertSame('Đang xử lý', $mapped['status']);
        $this->assertTrue($mapped['unknown']);
        $this->assertFalse(LazadaOrderStatusMapper::isTerminal('Đang xử lý'));
    }

    public function test_mapping_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame('Hoàn thành', $this->mapper->map(' Fulfilled ' )['status']);
        $this->assertSame('Đã hủy', $this->mapper->map('RETURNED')['status']);
    }
}