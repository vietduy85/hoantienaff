<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager;
use App\Services\PriceComparison\PriceComparisonManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TEST/DEVELOPMENT endpoint for catalog provider data.
 * Route is restricted to production auth; reachable freely in local/testing.
 */
class PriceComparisonController extends Controller
{
    public function __construct(
        private readonly PriceComparisonManager $catalog,
        private readonly AffiliateSearchLinkManager $affiliateLinks,
    ) {}

    public function search(Request $request): JsonResponse
    {
        return $this->searchSource($request, 'coop_online');
    }

    public function searchBhx(Request $request): JsonResponse
    {
        return $this->searchSource($request, 'bach_hoa_xanh');
    }

    public function searchKingfoodmart(Request $request): JsonResponse
    {
        return $this->searchSource($request, 'kingfoodmart');
    }

    public function searchAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword'  => ['required', 'string', 'max:120'],
            'page'     => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort'     => ['nullable', 'in:relevance,price_asc,price_desc'],
        ]);

        $keyword = $validated['keyword'];
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = $validated['sort'] ?? 'relevance';

        $result = $this->catalog->searchAll($keyword, $page, $perPage, $sort);

        return response()->json($result->toArray() + ['keyword' => $keyword]);
    }

    private function searchSource(Request $request, string $source): JsonResponse
    {
        $validated = $request->validate([
            'keyword'  => ['required', 'string', 'max:120'],
            'page'     => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $keyword = $validated['keyword'];
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $provider = $this->catalog->forSource($source);

        $result = $provider->search($keyword, $page, $perPage);

        return response()->json([
            'source'     => $provider->source(),
            'keyword'    => $keyword,
            'pagination' => [
                'page'        => $result->page,
                'per_page'    => $result->perPage,
                'total'       => $result->total,
                'total_pages' => $result->totalPages,
            ],
            'products' => $result->items
                ->map(fn ($product) => $product->toArray())
                ->values()
                ->all(),
        ]);
    }

    public function affiliateSearchLinks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword'  => ['required', 'string', 'max:120'],
        ]);

        $keyword = $validated['keyword'];
        $username = $request->user()?->username;

        $links = $this->affiliateLinks->getLinks($keyword, $username);

        return response()->json([
            'keyword' => $keyword,
            'links'   => $links,
        ]);
    }
}