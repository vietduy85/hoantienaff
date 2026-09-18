<?php

namespace App\Services\PromotionNews\Concerns;

/**
 * Helpers for extracting embedded JSON from retailer homepages.
 *
 * Retailers are NEVER fetched through buildId-specific /_next/data routes;
 * only the stable public homepage HTML is requested.
 */
trait ParsesNextData
{
    /**
     * Extract and decode the `__NEXT_DATA__` script payload (Pages Router).
     *
     * @return array<string, mixed>|null
     */
    protected function extractNextData(string $html): ?array
    {
        if (! preg_match('#<script id="__NEXT_DATA__" type="application/json">(.*?)</script>#s', $html, $matches)) {
            return null;
        }

        $decoded = json_decode(trim($matches[1]), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Concatenate and decode the React Server Component flight chunks
     * (App Router `self.__next_f.push([1, "..."])`).
     */
    protected function decodeFlightData(string $html): ?string
    {
        if (! preg_match_all('#self\.__next_f\.push\(\[1,"(.*?)"\]\)#s', $html, $matches)) {
            return null;
        }

        $decoded = '';

        foreach ($matches[1] as $chunk) {
            $json = json_decode('"'.$chunk.'"');

            if (is_string($json)) {
                $decoded .= $json;
            }
        }

        return $decoded === '' ? null : $decoded;
    }

    /**
     * Find the first JSON object following the given key marker and decode it
     * using balanced-brace matching (handles nested objects/arrays/strings).
     *
     * @return array<string, mixed>|null
     */
    protected function extractBalancedJsonAfter(string $haystack, string $marker): ?array
    {
        $start = strpos($haystack, $marker);

        if ($start === false) {
            return null;
        }

        $open = strpos($haystack, '{', $start);

        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($haystack);
        $inString = false;
        $escaped = false;

        for ($i = $open; $i < $length; $i++) {
            $char = $haystack[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $decoded = json_decode(substr($haystack, $open, $i - $open + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }
}
