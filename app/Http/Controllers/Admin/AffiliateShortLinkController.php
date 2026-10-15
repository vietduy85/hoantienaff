<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LinkRequest;
use App\Services\AffiliateLinkService;
use App\Services\Url\PlatformResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AffiliateShortLinkController extends Controller
{
    public function __construct(
        private readonly AffiliateLinkService $affiliateLinkService,
        private readonly PlatformResolver $platformResolver,
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
        $target = $this->platformResolver->resolve($validated['original_url']);
        $platform = $target->platform;
        $isShopee = $target->isShopee();

        $link = LinkRequest::create([
            'user_id'      => $user->id,
            'original_url' => $validated['original_url'],
            'platform'     => $platform,
            'status'       => $isShopee ? 'processing' : 'completed',
        ]);

        if (! $isShopee) {
            // Admin short-link flow only handles Shopee. Any other platform
            // must not be left stuck in `processing` forever (or reported as
            // `completed` without a link).
            $error = 'Nền tảng chưa được hỗ trợ tạo short link tại Admin. Vui lòng dán link Shopee.';

            $link->update([
                'status' => 'failed',
                'notes' => $error,
            ]);

            Log::info('[AdminShortLink] Unsupported platform', [
                'url' => $validated['original_url'],
                'host' => $target->host,
                'strategy' => $target->strategy,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success'    => false,
                'error'      => $error,
                'request_id' => $link->id,
                'platform'   => $platform,
            ], 422);
        }

        $this->affiliateLinkService->handle($link, 'admin');

        return response()->json([
            'success'    => true,
            'request_id' => $link->id,
            'platform'   => $platform,
        ]);
    }
}
