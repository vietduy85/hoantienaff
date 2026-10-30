<?php

namespace App\Console\Commands;

use App\Services\ShopeeOrderCommissionRateFiller;
use Illuminate\Console\Command;

class AffiliateFillShopeeOrderRates extends Command
{
    protected $signature = 'affiliate:fill-shopee-order-rates
                            {--from= : ordered_at lower bound (default 2026-10-25 00:00:00)}
                            {--limit=0 : Max rows}
                            {--apply : Write changes; default is dry-run}';

    protected $description = 'Auto-fill Shopee order commission rates (snapshot > cache > product-data) with provenance';

    public function handle(ShopeeOrderCommissionRateFiller $filler): int
    {
        $apply = (bool) $this->option('apply');
        $from = $this->option('from') ?: null;
        $limit = (int) $this->option('limit');

        $result = $filler->fill($apply, $from, $limit > 0 ? $limit : null);

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' - scope '.$result['scope'].', applied '.$result['applied']);
        $this->table(
            ['id', 'order_id', 'action', 'source', 'conf', 'shopee%', 'seller%', 'implied%', 'reason'],
            array_map(static fn (array $r): array => [
                $r['id'], $r['order_id'] ?? '', $r['action'], $r['source'] ?? '', $r['confidence'] ?? '',
                $r['shopee'] ?? '', $r['seller'] ?? '', $r['implied'] ?? '', $r['reason'] ?? '',
            ], $result['rows'])
        );
        $this->line('skipped: '.json_encode($result['skipped']));

        return self::SUCCESS;
    }
}
