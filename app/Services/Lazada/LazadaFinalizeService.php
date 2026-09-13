<?php

namespace App\Services\Lazada;

use App\Models\AffiliateOrderItem;
use App\Services\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lazada finalization business logic — extracted from the
 * `affiliate:lazada-finalize` Artisan command so it can be reused by both the
 * standalone command and the `affiliate:sync-all` orchestrator.
 *
 * Lifecycle rule: a Lazada order is finalized (cashback credited + row LOCKED
 * with finalize_gate = lazada_delivered_10d) once `delivered_at` is at least
 * 10 days old. fulfilled/delivered rows are stored by the normal sync as
 * pending (Đang xử lý) with an ESTIMATE; this service turns the estimate into
 * the FINAL cashback and moves the money into the wallet.
 *
 * Idempotent by construction:
 *   - already-finalized rows are excluded by the query (whereNull finalized_at)
 *   - creditCashback(throwOnDuplicate: false) never double-credits
 *   - rows inside a race are re-checked under a row lock before finalizing
 *   - historical rows (credited before the lifecycle) are skipped untouched.
 */
class LazadaFinalizeService
{
    private const FINALIZE_DAYS = 10;

    /**
     * @return array{checked: int, eligible: int, finalized: int, credited: int, skipped: int, historical_protected: int, reversed: int, errors: int}
     */
    public function run(WalletService $wallet): array
    {
        $cashback = new LazadaCashbackCalculator();
        $now = Carbon::now();

        $counts = [
            'checked'              => 0,
            'eligible'             => 0,
            'finalized'            => 0,
            'credited'             => 0,
            'skipped'              => 0,
            'historical_protected' => 0,
            'reversed'             => 0,
            'errors'               => 0,
        ];

        // Informational: finalized rows in scope that were later reversed.
        $counts['reversed'] = AffiliateOrderItem::query()
            ->where('platform', LazadaOrderNormalizer::PLATFORM)
            ->whereNotNull('delivered_at')
            ->whereNotNull('reversed_at')
            ->count();

        $rows = AffiliateOrderItem::query()
            ->where('platform', LazadaOrderNormalizer::PLATFORM)
            ->whereNotNull('delivered_at')
            ->whereNull('finalized_at')
            ->whereIn('lazada_raw_status', ['fulfilled', 'delivered'])
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get();

        foreach ($rows as $item) {
            $counts['checked']++;

            if ($item->hasCompletedCashbackCredit()) {
                $counts['historical_protected']++;
                $counts['skipped']++;
                Log::warning('[LazadaFinalize] historical credited row skipped (money already moved)', [
                    'affiliate_order_item_id' => $item->id,
                    'lazada_line_key'         => $item->lazada_line_key,
                    'order_id'                => $item->order_id,
                ]);
                continue;
            }

            $threshold = $item->delivered_at->copy()->addDays(self::FINALIZE_DAYS);

            if ($now->lt($threshold)) {
                $counts['skipped']++;
                continue;
            }

            $counts['eligible']++;

            try {
                $this->finalizeItem($wallet, $cashback, $item, $now);
                $counts['finalized']++;
                $counts['credited']++;
            } catch (\Throwable $e) {
                $counts['errors']++;
                Log::error('[LazadaFinalize] finalize failed', [
                    'affiliate_order_item_id' => $item->id,
                    'lazada_line_key'         => $item->lazada_line_key,
                    'order_id'                => $item->order_id,
                    'error'                   => $e->getMessage(),
                ]);
            }
        }

        Log::info('[LazadaFinalize] run completed', [
            'counts' => $counts,
            'ran_at' => $now->toDateTimeString(),
        ]);

        return $counts;
    }

    /**
     * Atomically finalize one eligible Lazada order: compute the FINAL cashback
     * with the existing 50/60/70 tier calculator, credit the wallet, and ONLY
     * afterwards snapshot the lock (finalized_at + finalize_gate +
     * final_cashback_amount). A failed credit aborts the whole thing — no
     * finalized row can ever exist without a matching completed credit.
     */
    private function finalizeItem(WalletService $wallet, LazadaCashbackCalculator $cashback, AffiliateOrderItem $item, Carbon $now): void
    {
        DB::transaction(function () use ($wallet, $cashback, $item, $now): void {
            $item = AffiliateOrderItem::query()
                ->where('id', $item->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($item->isFinalized()) {
                throw new \RuntimeException('row finalized concurrently — abort');
            }

            $calculate = $cashback->calculate(
                (float) $item->net_commission,
                (float) $item->order_amount,
                false,
            );

            $final = (float) $calculate['cashback_amount'];

            if ($final <= 0) {
                throw new \RuntimeException('final cashback = 0 (commission thiếu?) — không finalize');
            }

            $item->cashback_rate = $calculate['cashback_rate'];
            $item->cashback_amount = $final;
            $item->affiliate_status = LazadaOrderStatusMapper::STATUS_COMPLETED;
            $item->order_status = LazadaOrderStatusMapper::STATUS_COMPLETED;
            $item->completed_at = $now;
            $item->locked_at = $now;

            $transaction = $wallet->creditCashback($item, throwOnDuplicate: false);

            if ($transaction === null) {
                throw new \RuntimeException('creditCashback trả null — không finalize (đã credit?)');
            }

            $item->markFinalized(AffiliateOrderItem::FINALIZE_GATE_LAZADA_DELIVERED_10D, $now);

            Log::info('[LazadaFinalize] cashback credited + order finalized', [
                'action'                  => 'FINALIZE',
                'platform'                => $item->platform,
                'affiliate_order_item_id' => $item->id,
                'lazada_line_key'         => $item->lazada_line_key,
                'order_id'                => $item->order_id,
                'user_id'                 => $item->user_id,
                'delivered_at'            => $item->delivered_at?->toDateTimeString(),
                'credited_amount'         => $final,
                'finalized_at'            => $item->finalized_at?->toDateTimeString(),
                'finalize_gate'           => $item->finalize_gate,
            ]);
        });
    }
}