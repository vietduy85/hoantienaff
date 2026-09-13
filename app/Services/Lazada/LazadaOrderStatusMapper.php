<?php

namespace App\Services\Lazada;

/**
 * Maps the Lazada sub-order `status` values (documented for the offer/
 * conversion lifecycle) onto the single shared Vietnamese status vocabulary:
 *
 *   fulfilled, delivered -> Đang xử lý  (carrier confirmed; NOT yet a final
 *                          cashback — payout stays an ESTIMATE until the
 *                          10-day window after `deliveredTime` closes)
 *   returned, rejected, cancelled -> Đã hủy  (product returned / order
 *                          rejected or cancelled by the platform)
 *   anything else -> Đang xử lý (never credited; tallied as UNKNOWN so the
 *                          mapping can be finalised once real statuses flow
 *                          through the conversion report)
 *
 * Conservative-by-design: an unknown status NEVER credits or reverses money,
 * and since Phase 3 the sync layer never credits at all — final cashback is
 * minted exclusively by the affiliate:lazada-finalize command once
 * deliveredTime is at least 10 days old (FINALIZE_GATE_LAZADA_DELIVERED_10D).
 * The consuming code still runs idempotent guards, so re-syncing can never
 * double-credit or double-reverse.
 */
class LazadaOrderStatusMapper
{
    public const STATUS_PENDING = 'Đang xử lý';

    public const STATUS_COMPLETED = 'Hoàn thành';

    public const STATUS_CANCELLED = 'Đã hủy';

    /**
     * @return array{status: string, unknown: bool}
     */
    public function map(string $rawStatus): array
    {
        $normalized = strtolower(trim($rawStatus));

        $status = match ($normalized) {
            'fulfilled', 'delivered'              => self::STATUS_PENDING,
            'returned', 'rejected', 'cancelled'    => self::STATUS_CANCELLED,
            default                                => self::STATUS_PENDING,
        };

        return [
            'status'  => $status,
            'unknown' => ! in_array($normalized, ['fulfilled', 'delivered', 'returned', 'rejected', 'cancelled'], true),
        ];
    }

    public static function isTerminal(string $status): bool
    {
        return $status === self::STATUS_CANCELLED;
    }
}