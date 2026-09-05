<?php

namespace App\Services\ShopeeFood;

use DateTimeInterface;

/**
 * Result DTO produced by ShopeeFoodOrderSyncService::run().
 *
 * Phase 1 (dry-run) reports what WOULD happen: wouldInsert / wouldUpdate and a
 * cashback ESTIMATE. Phase 2 (persist=true) additionally fills the real
 * inserted / updated / wallet counters. Both modes share the same fields so the
 * admin UI and a future batch import can read one shape.
 */
class ShopeeFoodSyncResult
{
    public function __construct(
        public int $checkoutsFetched = 0,
        public int $ordersFetched = 0,
        public int $itemsFetched = 0,
        public int $wouldInsert = 0,
        public int $wouldUpdate = 0,
        public int $pending = 0,
        public int $completed = 0,
        public int $cancelled = 0,
        public int $unresolvedUsers = 0,
        public float $totalCommission = 0.0,
        public float $cashbackEstimate = 0.0,
        public int $cashbackEligible = 0,
        public int $commissionMismatches = 0,
        public int $inserted = 0,
        public int $updated = 0,
        public int $cashbackCredited = 0,
        public int $cashbackReversed = 0,
        public int $cashbackSkipped = 0,
        public int $invalidLines = 0,
        public int $errors = 0,
        public array $errorsDetail = [],
        public array $lines = [],
        public ?DateTimeInterface $startedAt = null,
        public ?DateTimeInterface $finishedAt = null,
    ) {}

    public function elapsedSeconds(): float
    {
        if ($this->startedAt === null || $this->finishedAt === null) {
            return 0.0;
        }

        return round($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp(), 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'started_at'            => $this->startedAt !== null ? $this->startedAt->format('Y-m-d H:i:s') : null,
            'finished_at'           => $this->finishedAt !== null ? $this->finishedAt->format('Y-m-d H:i:s') : null,
            'elapsed_seconds'       => $this->elapsedSeconds(),
            'checkouts_fetched'     => $this->checkoutsFetched,
            'orders_fetched'        => $this->ordersFetched,
            'items_fetched'         => $this->itemsFetched,
            'would_insert'          => $this->wouldInsert,
            'would_update'          => $this->wouldUpdate,
            'pending'               => $this->pending,
            'completed'             => $this->completed,
            'cancelled'             => $this->cancelled,
            'unresolved_users'      => $this->unresolvedUsers,
            'total_commission'      => round($this->totalCommission, 2),
            'cashback_estimate'     => round($this->cashbackEstimate, 2),
            'cashback_eligible'     => $this->cashbackEligible,
            'commission_mismatches' => $this->commissionMismatches,
            'inserted'              => $this->inserted,
            'updated'               => $this->updated,
            'cashback_credited'     => $this->cashbackCredited,
            'cashback_reversed'     => $this->cashbackReversed,
            'cashback_skipped'      => $this->cashbackSkipped,
            'invalid_lines'         => $this->invalidLines,
            'errors'                => $this->errors,
            'lines_count'           => count($this->lines),
        ];
    }
}