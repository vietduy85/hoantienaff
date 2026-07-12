<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LinkRequest;
use App\Services\AffiliateLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AffiliateShortLinkController extends Controller
{
    public function __construct(
        private readonly AffiliateLinkService $affiliateLinkService,
    ) {}

    public function index(Request $request): View
    {
        $user = auth()->user();
        $pinnedLinks = LinkRequest::forUser($user)->pinned()->latest('pinned_at')->limit(5)->get();
        $recentLinks = LinkRequest::forUser($user)->latest()->limit(5)->get();

        return view('admin.affiliate-short-link.index', compact('pinnedLinks', 'recentLinks'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_url' => ['required', 'url', 'max:2048'],
        ]);

        $user = auth()->user();
        $platform = $this->detectPlatform($validated['original_url']);

        $link = LinkRequest::create([
            'user_id'      => $user->id,
            'original_url' => $validated['original_url'],
            'platform'     => $platform,
            'status'       => 'pending',
        ]);

        $this->affiliateLinkService->handle($link, 'admin');

        return response()->json([
            'success'    => true,
            'request_id' => $link->id,
            'platform'   => $platform,
        ]);
    }

    private function detectPlatform(string $url): string
    {
        $url = strtolower($url);

        $platforms = [
            'shopee'  => 'Shopee',
            'shp.ee'  => 'Shopee',
            'lazada'  => 'Lazada',
            'tiktok'  => 'TikTok Shop',
            'tiki'    => 'Tiki',
        ];

        foreach ($platforms as $domain => $name) {
            if (str_contains($url, $domain)) {
                return $name;
            }
        }

        return 'Khác';
    }
}
