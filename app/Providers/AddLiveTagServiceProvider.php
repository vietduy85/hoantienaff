<?php

namespace App\Providers;

use App\Services\AddLiveTag\ConversionsClient;
use Illuminate\Support\ServiceProvider;

class AddLiveTagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConversionsClient::class, function ($app) {
            $config = $app['config']['services.addlivetag'] ?? [];
            return new ConversionsClient(
                baseUrl: $config['conversions_base_url'] ?? 'https://addlivetag.com/api/v1/conversions.php',
                apiKey: $config['api_key'] ?? '',
                maxPages: $config['max_sync_pages'] ?? 100,
                pageSize: 100,
                delayMs: (int) ($config['page_delay_ms'] ?? 2000)
            );
        });
    }
}
