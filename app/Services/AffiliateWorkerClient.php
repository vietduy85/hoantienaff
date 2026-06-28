<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class AffiliateWorkerClient
{
    private PendingRequest $http;

    public function __construct()
    {
        $baseUrl = config('services.affiliate_worker.url', 'http://127.0.0.1:3001');

        $this->http = Http::baseUrl($baseUrl)
            ->timeout(15)
            ->acceptJson();
    }

    public function health(): array
    {
        $response = $this->http->get('/health');

        return $response->successful()
            ? $response->json()
            : [
                'success' => false,
                'service' => 'affiliate-worker',
                'version' => null,
            ];
    }

    public function createLink(string $url): array
    {
        $response = $this->http->post('/shopee/create-link', [
            'url' => $url,
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        $body = $response->json();

        return [
            'success' => false,
            'error' => $body['error'] ?? 'Worker returned status ' . $response->status(),
        ];
    }

    public function testPlaywright(): array
    {
        $response = $this->http->get('/playwright-test');

        if ($response->successful()) {
            return $response->json();
        }

        return [
            'success' => false,
            'error' => 'Worker returned status ' . $response->status(),
        ];
    }

    public function shopeeProfileTest(): array
    {
        $response = $this->http->timeout(180)->get('/shopee/profile-test');

        if ($response->successful()) {
            return $response->json();
        }

        return [
            'success' => false,
            'error' => 'Worker returned status ' . $response->status(),
        ];
    }

    public function shopeeDashboardTest(): array
    {
        $response = $this->http->timeout(60)->get('/shopee/dashboard-test');

        if ($response->successful()) {
            return $response->json();
        }

        return [
            'success' => false,
            'error' => 'Worker returned status ' . $response->status(),
        ];
    }

    public function shopeeSessionTest(): array
    {
        $response = $this->http->get('/shopee/session-test');

        if ($response->successful()) {
            return $response->json();
        }

        return [
            'success' => false,
            'error' => 'Worker returned status ' . $response->status(),
        ];
    }

    public function shopeeLogin(): array
    {
        $response = $this->http->post('/shopee-login');

        if ($response->successful()) {
            return $response->json();
        }

        return [
            'success' => false,
            'error' => 'Worker returned status ' . $response->status(),
        ];
    }

    public function shopeeLoginInteractive(): array
    {
        $response = $this->http->timeout(180)->post('/shopee-login-interactive');

        if ($response->successful()) {
            return $response->json();
        }

        return [
            'success' => false,
            'error' => 'Worker returned status ' . $response->status(),
        ];
    }

    public function ping(): bool
    {
        $response = $this->http->get('/health');

        return $response->successful();
    }
}
