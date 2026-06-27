<?php

namespace App\Providers;

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
    }

    public function boot(): void
    {
        if (! $this->app->isLocal()) {
            URL::forceScheme('https');
        }
    }
}
