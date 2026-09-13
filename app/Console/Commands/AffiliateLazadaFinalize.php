<?php

namespace App\Console\Commands;

use App\Models\AffiliateOrderItem;
use App\Services\Lazada\LazadaCashbackCalculator;
use App\Services\Lazada\LazadaOrderNormalizer;
use App\Services\Lazada\LazadaOrderStatusMapper;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3 Lazada finalizer — the ONLY component that credits Lazada cashback.
 *
 * Lifecycle rule: a Lazada order is finalized (cashback credited + row LOCKED
 * with finalize_gate = lazada_delivered_10d) once `delivered_at` is at least
 * 10 days old. fulfilled/delivered rows are stored by the normal sync as
 * pending (Đang xử lý) with an ESTIMATE; this command turns the estimate into
 * the FINAL cashback and moves the money into the wallet.
 *
 * Idempotent by construction:
 *   - already-finalized rows are excluded by the query (whereNull finalized_at)
 *   - creditCashback(throwOnDuplicate: false) never double-credits
 *   - rows inside a race are re-checked under a row lock before finalizing
 *   - historical rows (credited before the lifecycle) are skipped untouched.
 */
class AffiliateLazadaFinalize extends Command
{
    protected $signature = 'affiliate:lazada-finalize';

    protected $description = 'Chốt (FINALIZE) cashback đơn Lazada sau 10 ngày kể từ delivered_at — idempotent, chỉ đụng Lazada';

    private const FINALIZE_DAYS = 10;

    public function handle(WalletService $wallet): int
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
            // Raw status must be documented-confirmed — an UNKNOWN status is
            // pending (never paid) until the mapping is finalised.
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

        $this->info('--- LAZADA FINALIZE REPORT (affiliate:lazada-finalize) ---');
        $this->table(['Tiêu chí', 'Giá trị'], [
            ['Rows checked', (string) $counts['checked']],
            ['Eligible (delivered_at >= 10 ngày)', (string) $counts['eligible']],
            ['Finalized (wallets credited)', (string) $counts['finalized']],
            ['Credited', (string) $counts['credited']],
            ['Skipped (chưa đủ 10 ngày / không đủ điều kiện)', (string) $counts['skipped']],
            ['Historical protected (không đụng)', (string) $counts['historical_protected']],
            ['Reversed (đã hoàn tiền, informational)', (string) $counts['reversed']],
            ['Errors', (string) $counts['errors']],
        ]);

        Log::info('[LazadaFinalize] run completed', [
            'counts' => $counts,
            'ran_at' => $now->toDateTimeString(),
        ]);

        return self::SUCCESS;
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

            // Race guard: another run finalised this row while we queued.
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