<?php

namespace Tests\Unit;

use App\Services\ShopeeFood\ShopeeFoodUtmContentParser;
use PHPUnit\Framework\TestCase;

class ShopeeFoodUtmContentParserTest extends TestCase
{
    /**
     * FORMAT A: "225----" -> sub_id1 = "225", rest empty.
     */
    public function test_format_a_with_subid1(): void
    {
        $result = ShopeeFoodUtmContentParser::parse('225----');

        $this->assertSame('A', $result['format']);
        $this->assertSame('225', $result['sub_id1']);
        $this->assertSame('', $result['sub_id2']);
        $this->assertSame('', $result['sub_id3']);
        $this->assertSame('', $result['sub_id4']);
        $this->assertSame('', $result['sub_id5']);
        $this->assertNull($result['content_id']);
    }

    /**
     * FORMAT A: "----" -> all sub ids empty.
     */
    public function test_format_a_all_empty(): void
    {
        $result = ShopeeFoodUtmContentParser::parse('----');

        $this->assertSame('A', $result['format']);
        $this->assertSame('', $result['sub_id1']);
        $this->assertSame('', $result['sub_id2']);
        $this->assertSame('', $result['sub_id3']);
        $this->assertSame('', $result['sub_id4']);
        $this->assertSame('', $result['sub_id5']);
        $this->assertNull($result['content_id']);
    }

    /**
     * FORMAT A must not leak content when there are < 5 parts.
     */
    public function test_format_a_with_regular_sub_ids(): void
    {
        $result = ShopeeFoodUtmContentParser::parse('aaa-bbb-ccc');

        $this->assertSame('A', $result['format']);
        $this->assertSame('aaa', $result['sub_id1']);
        $this->assertSame('bbb', $result['sub_id2']);
        $this->assertSame('ccc', $result['sub_id3']);
        $this->assertSame('', $result['sub_id4']);
        $this->assertSame('', $result['sub_id5']);
    }

    /**
     * FORMAT B (AppS): content_id kept as STRING, sub ids must NOT contain
     * AppS / android / 11010.
     */
    public function test_format_b_apps_keeps_content_id_and_no_sub_ids(): void
    {
        $result = ShopeeFoodUtmContentParser::parse('37712193991759004-AppS-android-11010');

        $this->assertSame('B', $result['format']);
        $this->assertSame('37712193991759004', $result['content_id']);
        $this->assertSame('37712193991759004', (string) $result['content_id']);

        foreach (['sub_id1', 'sub_id2', 'sub_id3', 'sub_id4', 'sub_id5'] as $key) {
            $this->assertSame('', $result[$key], "{$key} must be empty for FORMAT B");
            $this->assertStringNotContainsString('AppS', $result[$key]);
        }

        $joined = implode('|', [
            $result['sub_id1'], $result['sub_id2'], $result['sub_id3'],
            $result['sub_id4'], $result['sub_id5'],
        ]);
        $this->assertStringNotContainsString('android', $joined);
        $this->assertStringNotContainsString('11010', $joined);
    }

    /**
     * FORMAT B (AccS): content_id kept as STRING, sub ids must NOT contain
     * AccS / webapp.
     */
    public function test_format_b_accs_keeps_content_id_and_no_sub_ids(): void
    {
        $result = ShopeeFoodUtmContentParser::parse('6938992619562034-AccS-webapp');

        $this->assertSame('B', $result['format']);
        $this->assertSame('6938992619562034', $result['content_id']);

        foreach (['sub_id1', 'sub_id2', 'sub_id3', 'sub_id4', 'sub_id5'] as $key) {
            $this->assertSame('', $result[$key]);
            $this->assertStringNotContainsString('AccS', $result[$key]);
            $this->assertStringNotContainsString('webapp', $result[$key]);
        }
    }

    /**
     * FORMAT B with 32-char hex content id: must stay a string, never cast to int.
     */
    public function test_format_b_hex_content_id_stays_string(): void
    {
        $hex = str_repeat('a', 32);
        $result = ShopeeFoodUtmContentParser::parse($hex . '-AppS-ios-20481');

        $this->assertSame('B', $result['format']);
        $this->assertSame($hex, $result['content_id']);
        $this->assertIsString($result['content_id']);
    }

    /**
     * A 16-17 digit integer content id must remain a STRING, not become a float/int.
     */
    public function test_content_id_not_cast_to_integer(): void
    {
        $result = ShopeeFoodUtmContentParser::parse('37712193991759004-AppS-android-11010');

        $this->assertSame('37712193991759004', $result['content_id']);
        $this->assertIsString($result['content_id']);
    }

    public function test_null_and_empty(): void
    {
        foreach ([null, ''] as $input) {
            $result = ShopeeFoodUtmContentParser::parse($input);
            $this->assertNull($result['format']);
            $this->assertSame('', $result['sub_id1']);
            $this->assertNull($result['content_id']);
        }
    }
}
