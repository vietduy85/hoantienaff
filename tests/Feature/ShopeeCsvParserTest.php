<?php

namespace Tests\Feature;

use App\Services\ShopeeCsvParser;
use Tests\TestCase;

class ShopeeCsvParserTest extends TestCase
{
    private ShopeeCsvParser $parser;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ShopeeCsvParser;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_parse_valid_csv_returns_rows(): void
    {
        $path = $this->createCsv(
            "ID đơn hàng,Item id,Sub_id1,Giá(₫),Số lượng,Tổng hoa hồng sản phẩm(₫),Hoa hồng ròng tiếp thị liên kết(₫),Trạng thái sản phẩm liên kết,Tên Shop,Shop id,Tên Item,ID Model,Loại Hoa hồng,Trạng thái đặt hàng,Checkout id\n" .
            "ORD001,ITEM001,testuser,100000,1,10000,10000,Hoàn thành,ShopTest,1,Sản phẩm A,100,Shopee Comm,Đã giao,CHK001\n"
        );

        $result = $this->parser->parse($path);

        $this->assertTrue($result['is_valid']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('ORD001', $result['rows'][0]['order_id']);
        $this->assertSame('ITEM001', $result['rows'][0]['item_id']);
        $this->assertSame('testuser', $result['rows'][0]['sub_id1']);
        $this->assertSame('Hoàn thành', $result['rows'][0]['affiliate_status']);
    }

    public function test_validate_rejects_csv_with_too_few_columns(): void
    {
        $path = $this->createCsv("ID đơn hàng,Item id\nORD001,ITEM001\n");

        $result = $this->parser->validateHeader($path);

        $this->assertFalse($result['is_valid']);
    }

    public function test_validate_rejects_csv_missing_three_or_more_required_columns(): void
    {
        // Missing: Item id, Sub_id1, Hoa hồng ròng tiếp thị liên kết(₫) → 3 missing → invalid
        $path = $this->createCsv(
            "ID đơn hàng,Giá(₫),Số lượng,Tổng hoa hồng sản phẩm(₫),Trạng thái sản phẩm liên kết,Tên Shop,Shop id,Tên Item,Trạng thái đặt hàng,Checkout id\n" .
            "ORD001,100000,1,10000,Hoàn thành,ShopTest,1,Sản phẩm A,Đã giao,CHK001\n"
        );

        $result = $this->parser->validateHeader($path);

        $this->assertFalse($result['is_valid']);
    }

    public function test_parse_strips_bom(): void
    {
        $bom = "\xEF\xBB\xBF";
        $path = $this->createCsv(
            $bom . "ID đơn hàng,Item id,Sub_id1,Giá(₫),Số lượng,Tổng hoa hồng sản phẩm(₫),Hoa hồng ròng tiếp thị liên kết(₫),Trạng thái sản phẩm liên kết,Tên Shop,Shop id,Tên Item,ID Model,Loại Hoa hồng,Trạng thái đặt hàng,Checkout id\n" .
            "ORD001,ITEM001,testuser,100000,1,10000,10000,Hoàn thành,ShopTest,1,Sản phẩm A,100,Shopee Comm,Đã giao,CHK001\n"
        );

        $result = $this->parser->parse($path);

        $this->assertTrue($result['is_valid']);
        $this->assertCount(1, $result['rows']);
    }

    public function test_parse_handles_aliases(): void
    {
        $path = $this->createCsv(
            "ID đơn hàng,Item id,Sub_id1,Giá(₫),Số lượng,Tổng hoa hồng sản phẩm(₫),Hoa hồng ròng tiếp thị liên kết(₫),Trạng thái sản phẩm liên kết,Tên Shop,Shop id,Tên Item,ID Model,Tỷ lệ sản phẩm hoa hồng Shope,Trạng thái đặt hàng,Checkout id\n" .
            "ORD001,ITEM001,testuser,100000,1,10000,10000,Hoàn thành,ShopTest,1,Sản phẩm A,100,3.50,Đã giao,CHK001\n"
        );

        $result = $this->parser->parse($path);

        $this->assertTrue($result['is_valid']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('3.50', $result['rows'][0]['shopee_commission_rate']);
    }

    public function test_cleanValue_removes_invisible_chars(): void
    {
        $nbsp = "\xC2\xA0";
        $dirty = " \xEF\xBB\xBF test {$nbsp} ";
        $cleaned = $this->parser->cleanValue($dirty);

        $this->assertSame('test', $cleaned);
    }

    public function test_parseDate_returns_null_for_invalid(): void
    {
        $this->assertNull($this->parser->parseDate('not-a-date'));
        $this->assertNull($this->parser->parseDate(''));
        $this->assertNull($this->parser->parseDate(null));
    }

    public function test_parseDate_returns_valid_date(): void
    {
        $this->assertSame('2026-07-10 14:30:00', $this->parser->parseDate('2026-07-10 14:30:00'));
    }

    public function test_parseDecimal_strips_percent_and_commas(): void
    {
        $this->assertSame(3.5, $this->parser->parseDecimal('3.50%'));
        $this->assertSame(2221.94, $this->parser->parseDecimal('2,221.94'));
        $this->assertSame(151480.0, $this->parser->parseDecimal('151480'));
        $this->assertSame(0.0, $this->parser->parseDecimal(''));
        $this->assertSame(0.0, $this->parser->parseDecimal(null));
    }

    public function test_calculateCashback_returns_zero_when_item_amount_zero(): void
    {
        $result = $this->parser->calculateCashback(10000, 0);
        $this->assertSame(['rate' => 0, 'amount' => 0], $result);
    }

    public function test_calculateCashback_returns_zero_when_commission_zero(): void
    {
        $result = $this->parser->calculateCashback(0, 100000);
        $this->assertSame(['rate' => 0, 'amount' => 0], $result);
    }

    public function test_calculateCashback_rate_below_12_percent(): void
    {
        // commission_rate = (10000 * 0.9) / 100000 = 0.09 < 0.12 → rate = 50
        // net = floor(floor(10000 * 0.90) * 50 / 100) = floor(9000 * 0.50) = 4500
        $result = $this->parser->calculateCashback(10000, 100000);
        $this->assertSame(50, $result['rate']);
        $this->assertSame(4500.0, $result['amount']);
    }

    public function test_calculateCashback_rate_between_12_and_52_percent(): void
    {
        // commission_rate = (20000 * 0.9) / 50000 = 0.36 → 0.12 <= 0.36 <= 0.52 → rate = 60
        $result = $this->parser->calculateCashback(20000, 50000);
        $this->assertSame(60, $result['rate']);
    }

    public function test_calculateCashback_rate_above_52_percent(): void
    {
        // commission_rate = (60000 * 0.9) / 100000 = 0.54 > 0.52 → rate = 70
        $result = $this->parser->calculateCashback(60000, 100000);
        $this->assertSame(70, $result['rate']);
    }

    private function createCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_test_') . '.csv';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
