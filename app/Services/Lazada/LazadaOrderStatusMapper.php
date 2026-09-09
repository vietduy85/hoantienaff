<?php

namespace App\Services\Lazada;

/**
 * Maps the Lazada sub-order `status` values (documented for the offer/
 * conversion lifecycle) onto the single shared Vietnamese status vocabulary:
 *
 *   fulfilled  -> Hoàn thành  (est. payout is calculated from this point on)
 *   delivered  -> Hoàn thành  (product delivered; commission still counts)
 *   returned   -> Đã hủy      (the product was requested for return)
 *   anything else -> Đang xử lý (never credited; tallied as UNKNOWN so the
 *                                mapping can be finalised once real statuses
 *                                flow through the conversion report)
 *
 * Conservative-by-design: an unknown status NEVER credits or reverses money.
 * The terminal-status set is checked in applyWalletTransition under the
 * CONSUMING code's idempotent guards, so re-syncing can never double-credit or
 * double-reverse.
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
            'fulfilled', 'delivered' => self::STATUS_COMPLETED,
            'returned'               => self::STATUS_CANCELLED,
            default                  => self::STATUS_PENDING,
        };

        return [
            'status'  => $status,
            'unknown' => ! in_array($normalized, ['fulfilled', 'delivered', 'returned'], true),
        ];
    }

    public static function isTerminal(string $status): bool
    {
        return $status === self::STATUS_COMPLETED || $status === self::STATUS_CANCELLED;
    }
}