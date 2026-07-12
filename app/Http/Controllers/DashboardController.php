<?php

namespace App\Http\Controllers;

use App\Models\LinkRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $pinnedLinks = LinkRequest::forUser($user)
            ->pinned()
            ->latest('pinned_at')
            ->limit(5)
            ->get();

        $recentLinks = LinkRequest::forUser($user)
            ->latest()
            ->limit(5)
            ->get();

        return view('dashboard', compact('pinnedLinks', 'recentLinks'));
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'original_url' => ['required', 'url', 'max:2048'],
        ]);

        $strategy = Setting::get('affiliate.dashboard.strategy', 'direct');

        if ($strategy === 'direct') {
            $controller = app(DashboardCreateDirectLinkController::class);
        } else {
            $controller = app(DashboardCreateExtensionLinkController::class);
        }

        return $controller->store($request);
    }

    public function togglePin(LinkRequest $linkRequest): RedirectResponse
    {
        $user = auth()->user();

        if ($linkRequest->user_id !== $user->id) {
            abort(403);
        }

        if ($linkRequest->is_pinned) {
            $linkRequest->update([
                'is_pinned' => false,
                'pinned_at' => null,
            ]);
        } else {
            $pinnedCount = LinkRequest::forUser($user)->pinned()->count();

            if ($pinnedCount >= 5) {
                return redirect()->route('dashboard')
                    ->with('error', 'Bạn chỉ có thể ghim tối đa 5 link.');
            }

            $linkRequest->update([
                'is_pinned' => true,
                'pinned_at' => now(),
            ]);
        }

        return redirect()->route('dashboard');
    }

    private function detectPlatform(string $url): string
    {
        $url = strtolower($url);

        $platforms = [
            'shopee'  => 'Shopee',
            'shp.ee' => 'Shopee',
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
