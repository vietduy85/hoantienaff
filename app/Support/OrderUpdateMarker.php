<?php

namespace App\Support;

use App\Models\AffiliateOrderItem;
use Illuminate\Support\Carbon;

/**
 * Keeps the row-write timestamps (`last_<platform>_sync_at`) truthful for the
 * admin "Đơn cập nhật gần nhất" label.
 *
 * TikTok/Lazada upserts re-write their whole payload on every run (import
 * batch, volatile bookkeeping, ...), which used to bump the timestamp even
 * when NOTHING about the order changed — making the label useless and the 24h
 * warning impossible to trigger. Shopee already gets this for free through
 * ConversionsImporter::plan() (ACTION_UNCHANGED rows are never written).
 *
 * Rules (comparison mirrors ConversionsImporter::diff()):
 *   - bookkeeping keys never count as change (timestamp column itself,
 *     import_batch, source_file, first_imported_at, created_at, updated_at,
 *     plus platform-specific volatile fields such as TikTok locked_at)
 *   - keys that are not fillable / not loaded columns cannot change the row
 *   - datetime columns compare on 'Y-m-d H:i:s'
 *   - numeric values compare with the same 0.009 tolerance as the importer
 *     (decimal round-trips must not look like a change)
 *   - null and '' are equivalent; everything else compares trimmed strings
 *
 * When no content key changed, the timestamp is REMOVED from the payload so
 * the UPDATE statement leaves the stored marker untouched (writes themselves
 * stay exactly as before — counters, lifecycle, wallet logic untouched).
 */
final class OrderUpdateMarker
{
    /**
     * @param  array<string, mixed>  $payload  row payload about to be persisted (by reference)
     * @param  list<string>  $volatileFields  platform-specific non-content keys
     */
    public static function freezeTimestampUnlessChanged(
        AffiliateOrderItem $row,
        array &$payload,
        string $timestampColumn,
        array $volatileFields = [],
    ): void {
        $excluded = array_flip(array_merge(
            [$timestampColumn, 'import_batch', 'source_file', 'first_imported_at', 'created_at', 'updated_at'],
            $volatileFields,
        ));

        $attributes = $row->getAttributes();

        foreach ($payload as $key => $value) {
            if (isset($excluded[$key]) || ! array_key_exists($key, $attributes) || ! $row->isFillable($key)) {
                continue;
            }

            if (self::differs($row->getAttribute($key), $value)) {
                return;
            }
        }

        unset($payload[$timestampColumn]);
    }

    private static function differs(mixed $old, mixed $new): bool
    {
        if ($old instanceof \DateTimeInterface) {
            return $old->format('Y-m-d H:i:s') !== self::asDateTimeString($new);
        }

        if ($new instanceof \DateTimeInterface) {
            return $new->format('Y-m-d H:i:s') !== self::asDateTimeString($old);
        }

        if ($old === null || $old === '') {
            return ! ($new === null || $new === '');
        }

        if ($new === null || $new === '') {
            return true;
        }

        if (is_array($old) || is_array($new) || is_object($old) || is_object($new)) {
            return serialize($old) !== serialize($new);
        }

        if (is_bool($old) || is_bool($new)) {
            return (bool) $old !== (bool) $new;
        }

        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) > 0.009;
        }

        return trim((string) $old) !== trim((string) $new);
    }

    private static function asDateTimeString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
