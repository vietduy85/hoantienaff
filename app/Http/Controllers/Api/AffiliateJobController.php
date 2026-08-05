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
        if (app()->isLocal()) {
            \Illuminate\Support\Facades\Log::debug('[ENTER jobs] token=' . $request->query('token', '(none)') . ' ip=' . $request->ip() . ' ua=' . substr($request->userAgent() ?? 'unknown', 0, 80));
        }

        $token = $request->query('token');
        $expected = config('services.affiliate_extension.token');
        if ($token !== $expected) {
            if (app()->isLocal()) {
                \Illuminate\Support\Facades\Log::debug('[ENTER jobs] UNAUTHORIZED: token provided=' . substr($token ?? 'null', 0, 20) . ' expected=' . substr($expected ?? 'null', 0, 20));
            }
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $jobs = LinkRequest::select('link_requests.id', 'link_requests.original_url', 'users.username')
            ->where('link_requests.status', 'pending')
            ->join('users', 'link_requests.user_id', '=', 'users.id')
            ->orderBy('link_requests.id')
            ->limit(5)
            ->get();

        if ($jobs->isNotEmpty()) {
            LinkRequest::whereIn('id', $jobs->pluck('id'))
                ->update(['status' => 'processing']);
        }

        if (app()->isLocal()) {
            \Illuminate\Support\Facades\Log::debug('[RETURN jobs] count=' . $jobs->count() . ' ids=' . $jobs->pluck('id')->implode(','));
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
