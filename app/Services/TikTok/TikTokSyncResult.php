<?php

namespace App\Services\TikTok;

/**
 * Result DTO produced by TikTokOrderSyncService::run().
 *
 * Collects the outcome of a sync cycle without leaking API credentials or
 * exposing raw response bodies.
 */
class TikTokSyncResult
{
    public function __construct(
        public int $ordersFetched = 0,
        public int $itemsFetched = 0,
        public int $inserted = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $unmatched = 0,
        public int $errors = 0,
        public int $cashbackCredited = 0,
        public int $cashbackSkipped = 0,
        public int $cashbackReversed = 0,
        public array $errorsDetail = [],
        public ?\DateTimeInterface $startedAt = null,
        public ?\DateTimeInterface $finishedAt = null,
    ) {}

    public function elapsedSeconds(): float
    {
        if ($this->startedAt === null || $this->finishedAt === null) {
            return 0.0;
        }

        return round($this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp(), 3);
    }

    public function toArray(): array
    {
        return [
            'started_at'        => $this->startedAt?->format('Y-m-d H:i:s'),
            'finished_at'       => $this->finishedAt?->format('Y-m-d H:i:s'),
            'elapsed_seconds'   => $this->elapsedSeconds(),
            'orders_fetched'    => $this->ordersFetched,
            'items_fetched'     => $this->itemsFetched,
            'inserted'          => $this->inserted,
            'updated'           => $this->updated,
            'skipped'           => $this->skipped,
            'unmatched'         => $this->unmatched,
            'errors'            => $this->errors,
            'cashback_credited' => $this->cashbackCredited,
            'cashback_skipped'  => $this->cashbackSkipped,
            'cashback_reversed' => $this->cashbackReversed,
        ];
    }
}
