<?php

namespace App\Console\Commands;

use App\Services\AddLiveTag\ConversionsClient;
use App\Services\AddLiveTag\ConversionsImporter;
use App\Services\AddLiveTag\ConversionsNormalizer;
use App\Support\AffiliateSyncLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * PHASE 1 — AddLiveTag conversions sync (SAFE).
 *
 * Hard guarantees:
 *  - NEVER credits wallet (no WalletService call exists in this path)
 *  - NEVER finalizes / reverses
 *  - --dry-run / --reconcile => ZERO DB writes
 *  - default mode may create/update ordinary rows only, behind the shared
 *    AffiliateSyncLock, with locked/finalized/credited rows protected
 *  - API key is never printed
 */
class AffiliateAddLiveTagSync extends Command
{
    protected $signature = 'affiliate:addlivetag-sync
                            {--dry-run : Read + compare + report, ZERO DB writes}
                            {--reconcile : Read + compare + report, ZERO DB writes}
                            {--from= : purchase_time lower bound (YYYY-MM-DD)}
                            {--to= : purchase_time upper bound (YYYY-MM-DD) — behavior unverified, see report}
                            {--order-id= : Fetch a single order only}
                            {--apply : Apply safe row updates (rows only, no wallet)}
                            {--force : Alias of --apply}';

    protected $description = 'AddLiveTag conversions (Shopee) sync — PHASE 1: no wallet credit, no finalize, no reverse';

    public function __construct(
        private readonly ConversionsClient $client,
        private readonly ConversionsNormalizer $normalizer,
        private readonly ConversionsImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $start = microtime(true);

        $dryRun = (bool) $this->option('dry-run');
        $reconcile = (bool) $this->option('reconcile');
        $apply = (bool) $this->option('apply') || (bool) $this->option('force');
        $from = $this->option('from');
        $to = $this->option('to');
        $orderId = $this->option('order-id');

        $readOnly = $dryRun || $reconcile || ! $apply;

        $this->info('================================');
        $this->info('  AddLiveTag Conversions Sync (PHASE 1)');
        $this->info('================================');
        $this->line('  mode:              '.($readOnly ? 'READ-ONLY (no DB writes)' : 'APPLY (rows only)'));
        $this->line('  dry-run:           '.($dryRun ? 'yes' : 'no'));
        $this->line('  reconcile:         '.($reconcile ? 'yes' : 'no'));
        $this->line('  apply:             '.($apply ? 'yes' : 'no'));
        $this->line('  from:              '.($from ?: '-'));
        $this->line('  to:                '.($to ?: '-'));
        $this->line('  order_id:          '.($orderId ?: '-'));
        $this->line('  wallet credit:     BLOCKED (Phase 1)');
        $this->line('  finalize/reverse:  BLOCKED (Phase 1)');
        $this->line('');

        $lock = null;
        if ($apply) {
            $lock = Cache::lock(AffiliateSyncLock::KEY, AffiliateSyncLock::SECONDS);
            if (! $lock->get()) {
                $this->error('[BLOCK] Another affiliate sync holds affiliate:sync:lock — aborting safely.');

                return Command::FAILURE;
            }
        }

        try {
            $this->info('Fetching AddLiveTag conversions...');
            try {
                $result = $this->client->fetch(
                    from: $from,
                    to: $to,
                    orderId: $orderId,
                );
            } catch (\Throwable $e) {
                $this->error('AddLiveTag fetch failed: '.$this->safeMessage($e));

                return Command::FAILURE;
            }

            $rawItems = $result['items'];
            $truncated = $result['truncated'];

            $this->line('  raw item rows:     '.count($rawItems));
            $this->line('  pages fetched:     '.($result['pages_fetched'] ?? 0));
            $this->line('  pagination:        '.($truncated ? 'TRUNCATED (INCOMPLETE — treated as failure)' : 'complete (data==[] reached)'));
            $this->line('');

            if ($truncated) {
                $this->error('[FAIL] Pagination hit max pages before data==[] — result is INCOMPLETE. Not a successful synchronization.');

                return Command::FAILURE;
            }

            $norm = $this->normalizer->normalize($rawItems);
            $rows = $norm['all'];

            $importBatch = now()->format('Ymd_His');
            $planResult = $this->importer->plan($rows, $importBatch);

            $this->report($planResult, count($rows), $importBatch);

            if ($readOnly) {
                $this->line('');
                $this->info('  READ-ONLY — no DB writes, no wallet writes.');
                $this->info('================================');
            } else {
                $applied = $this->importer->apply($planResult);
                $this->line('');
                $this->line('  created:           '.$applied['created']);
                $this->line('  updated:           '.$applied['updated']);
                $this->line('  wallet credit:     NONE (Phase 1 blocked)');
                $this->info('  APPLIED (rows only, wallet untouched).');
                $this->info('================================');
            }

            $elapsed = round(microtime(true) - $start, 1);
            $this->line('  elapsed:           '.$elapsed.'s');

            return Command::SUCCESS;
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    private function report(array $planResult, int $totalRows, string $importBatch): void
    {
        $counts = $planResult['counts'];

        $this->line('  normalized rows:   '.$totalRows);
        $this->line('  import_batch:      '.$importBatch);
        $this->line('  source_file:       '.ConversionsImporter::SOURCE_FILE);
        $this->line('');
        $this->line('  --- Plan ---');
        $this->line('  create (new rows): '.$counts[ConversionsImporter::ACTION_CREATE]);
        $this->line('  update:            '.$counts[ConversionsImporter::ACTION_UPDATE]);
        $this->line('  unchanged:         '.$counts[ConversionsImporter::ACTION_UNCHANGED]);
        $this->line('  protected:         '.$counts[ConversionsImporter::ACTION_PROTECTED].'  (locked/finalized/reversed/credited — PROTECTED_CONFLICT)');
        $this->line('  no_downgrade:      '.$counts[ConversionsImporter::ACTION_NO_DOWNGRADE].'  (CSV completed + API paid — kept completed)');
        $this->line('  user_conflict:     '.$counts[ConversionsImporter::ACTION_USER_CONFLICT].'  (USER_MAPPING_CONFLICT — manual review)');
        $this->line('  sub_id_mismatch:   '.count($planResult['sub_id_mismatches']).'  (info-only — API sub_id1 differs from DB username, mapping kept)');
        $this->line('');

        $this->line('  --- Overlap (duplicate order_sn+item_id) ---');
        $this->line('  overlap groups:    '.$planResult['overlap_groups']);
        $this->line('  affected orders:   '.count($planResult['overlap_orders']));
        $this->line('  merge rule:        deterministic LAST-WINS (final line), no quantity summation');
        $this->line('');

        if ($planResult['mcn_fee_rows'] > 0) {
            $this->warn('  [TODO mcn_fee] '.$planResult['mcn_fee_rows'].' row(s) with mcn_fee > 0 — cashback formula NOT adjusted (mcn_fee>0 semantics UNVERIFIED, dataset historically all-zero).');
        }

        foreach ($planResult['user_conflicts'] as $c) {
            $this->warn(sprintf(
                '  [USER_MAPPING_CONFLICT] order=%s item=%s db_user=%s(%s) api_sub_id1=%s(%s)',
                $c['order_id'],
                $c['item_id'],
                $c['db_username'],
                $c['db_user_id'] ?? 'null',
                $c['api_sub_id1'],
                $c['api_user_id'] ?? 'null',
            ));
        }

        foreach ($planResult['sub_id_mismatches'] as $c) {
            $this->warn(sprintf(
                '  [SUB_ID_MISMATCH] order=%s item=%s db_user=%s(%s) api_sub_id1=%s(%s) — mapping kept',
                $c['order_id'],
                $c['item_id'],
                $c['db_username'],
                $c['db_user_id'] ?? 'null',
                $c['api_sub_id1'],
                $c['api_user_id'] ?? 'null',
            ));
        }

        $protectedEntries = array_values(array_filter(
            $planResult['plan'],
            static fn (array $e): bool => $e['action'] === ConversionsImporter::ACTION_PROTECTED
        ));
        $shown = array_slice($protectedEntries, 0, 10);
        foreach ($shown as $entry) {
            $this->warn(sprintf(
                '  [PROTECTED_CONFLICT] order=%s item=%s db_status=%s api_status=%s',
                $entry['order_id'],
                $entry['item_id'],
                $entry['db_row']->affiliate_status,
                $entry['row']['affiliate_status'],
            ));
        }
        if (count($protectedEntries) > count($shown)) {
            $this->warn(sprintf(
                '  [PROTECTED_CONFLICT] ... and %d more row(s) — full list via --dry-run on a narrower window',
                count($protectedEntries) - count($shown),
            ));
        }
    }

    /**
     * Never expose credentials: exception messages can contain full URLs.
     */
    private function safeMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $key = trim((string) config('services.addlivetag.api_key', ''));
        if ($key !== '' && str_contains($msg, $key)) {
            $msg = str_replace($key, '****', $msg);
        }

        return preg_replace('/([?&]key=)[^&\s]+/i', '$1****', $msg) ?? $msg;
    }
}
