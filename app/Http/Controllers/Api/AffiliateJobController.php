<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LinkRequest;
use App\Services\AffiliateCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliateJobController extends Controller
{
    public function __construct(
        private readonly AffiliateCacheService $cacheService,
    ) {}

    public function jobs(Request $request): JsonResponse
    {
        $token = $request->query('token');
        if ($token !== config('services.affiliate_extension.token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $jobs = LinkRequest::where('status', 'pending')
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'original_url']);

        if ($jobs->isNotEmpty()) {
            LinkRequest::whereIn('id', $jobs->pluck('id'))
                ->update(['status' => 'processing']);
        }

        return response()->json(['jobs' => $jobs]);
    }

    public function result(Request $request): JsonResponse
    {
        $token = $request->query('token');
        if ($token !== config('services.affiliate_extension.token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $results = $request->input('results', []);

        if (empty($results)) {
            return response()->json(['ok' => false, 'error' => 'Empty results'], 400);
        }

        foreach ($results as $item) {
            if (!isset($item['id'])) continue;
            $lr = LinkRequest::find($item['id']);
            if (!$lr) continue;

            $lr->update([
                'affiliate_url' => $item['affiliate_url'] ?? '',
                'status' => $item['status'] ?? 'completed',
            ]);

            if ($lr->item_id && !empty($item['affiliate_url'])) {
                $this->cacheService->updateAffiliateUrl($lr->item_id, $item['affiliate_url']);
            }
        }

        return response()->json(['ok' => true, 'updated' => count($results)]);
    }
}
