<?php

namespace App\Console\Commands;

use App\Models\AffiliateCache;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AffiliateCacheCleanup extends Command
{
    protected $signature = 'affiliate:cache-cleanup {--days=3 : Number of days to keep}';

    protected $description = 'Remove affiliate_cache records older than N days (Asia/Ho_Chi_Minh)';

    public function handle(): int
    {
        $tz = config('app.timezone', 'Asia/Ho_Chi_Minh');
        $days = (int) $this->option('days');
        if ($days < 1) {
            $days = 3;
        }
        $cutoff = Carbon::now($tz)->startOfDay()->subDays($days)->toDateString();

        $query = AffiliateCache::whereDate('cache_date', '<', $cutoff);
        $count = $query->count();
        if ($count > 0) {
            $query->delete();
        }

        $this->info("Deleted {$count} affiliate_cache records with cache_date < {$cutoff}");
        Log::info('[affiliate:cache-cleanup]', ['deleted' => $count, 'cutoff' => $cutoff]);

        return self::SUCCESS;
    }
}
