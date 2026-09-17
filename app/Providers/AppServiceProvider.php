<?php

namespace App\Providers;

use App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager;
use App\Services\AffiliateSearchLinks\Providers\LazadaAffiliateSearchLinkProvider;
use App\Services\AffiliateSearchLinks\Providers\ShopeeAffiliateSearchLinkProvider;
use App\Services\AffiliateSearchLinks\Providers\TikTokAffiliateSearchLinkProvider;
use App\Services\PriceComparison\PriceComparisonManager;
use App\Services\PriceComparison\Providers\BachHoaXanhProvider;
use App\Services\PriceComparison\Providers\CoopOnlineProvider;
use App\Services\PriceComparison\Providers\KingfoodmartProvider;
use App\Services\PriceComparison\Providers\WinMartProvider;
use App\Services\ProviderFactory;
use App\Services\Providers\AgodaProvider;
use App\Services\Providers\BookingProvider;
use App\Services\Providers\LazadaProvider;
use App\Services\Providers\LongChauProvider;
use App\Services\Providers\PharmacityProvider;
use App\Services\Providers\ShopeeProvider;
use App\Services\Providers\TikTokProvider;
use App\Services\Providers\TravelokaProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            ShopeeProvider::class,
            LazadaProvider::class,
            TikTokProvider::class,
            LongChauProvider::class,
            PharmacityProvider::class,
            TravelokaProvider::class,
            AgodaProvider::class,
            BookingProvider::class,
        ], 'affiliate-providers');

        $this->app->when(ProviderFactory::class)
            ->needs('$providers')
            ->giveTagged('affiliate-providers');

        $this->app->tag([
            CoopOnlineProvider::class,
            BachHoaXanhProvider::class,
            KingfoodmartProvider::class,
            WinMartProvider::class,
        ], 'catalog-providers');

        $this->app->when(PriceComparisonManager::class)
            ->needs('$providers')
            ->giveTagged('catalog-providers');

        $this->app->tag([
            ShopeeAffiliateSearchLinkProvider::class,
            LazadaAffiliateSearchLinkProvider::class,
            TikTokAffiliateSearchLinkProvider::class,
        ], 'affiliate-search-link-providers');

        $this->app->when(AffiliateSearchLinkManager::class)
            ->needs('$providers')
            ->giveTagged('affiliate-search-link-providers');
    }

    public function boot(): void
    {
        if (! $this->app->isLocal()) {
            URL::forceScheme('https');
        }
    }
}
