<?php

namespace App\Services\ShopeeFood;

/**
 * Parses the `utm_content` value into either:
 *
 *   FORMAT A  - sub_id1-sub_id2-sub_id3-sub_id4-sub_id5  (e.g. "225----", "----")
 *   FORMAT B  - <contentId>-AppS-<platform>-<build>      (e.g. "37712193991759004-AppS-android-11010")
 *             - <contentId>-AccS-<platform>              (e.g. "6938992619562034-AccS-webapp")
 *
 * IMPORTANT:
 *  - All sub_id1..5 are STRINGS (never cast "225" or "001" to int).
 *  - content_id is ALWAYS kept as STRING (16-17 digit ints or 32-char hex).
 *  - FORMAT B must NEVER be split into sub_id fields, otherwise User mapping
 *    and sub_id statistics would be wrong.
 *
 * Result: https://example.com (a plain value object).
 */
final class ShopeeFoodUtmContentParser
{
    private const FORMAT_B_MARKERS = ['-AppS-', '-AccS-'];
    private const SUB_ID_COUNT = 5;

    private function __construct()
    {
    }

    /**
     * Parse a raw utm_content string.
     *
     * @return array{
     *   format: 'A'|'B'|null,
     *   sub_id1: string,
     *   sub_id2: string,
     *   sub_id3: string,
     *   sub_id4: string,
     *   sub_id5: string,
     *   content_id: string|null,
     * }
     */
    public static function parse(?string $utmContent): array
    {
        $empty = [
            'format'     => null,
            'sub_id1'    => '',
            'sub_id2'    => '',
            'sub_id3'    => '',
            'sub_id4'    => '',
            'sub_id5'    => '',
            'content_id' => null,
        ];

        if ($utmContent === null || $utmContent === '') {
            return $empty;
        }

        if (self::isFormatB($utmContent)) {
            return [
                'format'     => 'B',
                'sub_id1'    => '',
                'sub_id2'    => '',
                'sub_id3'    => '',
                'sub_id4'    => '',
                'sub_id5'    => '',
                'content_id' => self::extractContentId($utmContent),
            ];
        }

        return [
            'format'     => 'A',
            'sub_id1'    => self::part($utmContent, 0),
            'sub_id2'    => self::part($utmContent, 1),
            'sub_id3'    => self::part($utmContent, 2),
            'sub_id4'    => self::part($utmContent, 3),
            'sub_id5'    => self::part($utmContent, 4),
            'content_id' => null,
        ];
    }

    /**
     * FORMAT B is recognised by containing "-AppS-" or "-AccS-".
     */
    private static function isFormatB(string $value): bool
    {
        foreach (self::FORMAT_B_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The leading token before the first FORMAT B marker is the content_id,
     * kept as a STRING verbatim.
     */
    private static function extractContentId(string $value): ?string
    {
        foreach (self::FORMAT_B_MARKERS as $marker) {
            $pos = strpos($value, $marker);
            if ($pos !== false) {
                $head = substr($value, 0, $pos);

                return $head === '' ? null : $head;
            }
        }

        return null;
    }

    private static function part(string $value, int $index): string
    {
        $parts = explode('-', $value);

        // Guard against inconsistent hyphen counts while keeping the index stable.
        while (count($parts) < self::SUB_ID_COUNT) {
            $parts[] = '';
        }

        return $parts[$index] ?? '';
    }
}
