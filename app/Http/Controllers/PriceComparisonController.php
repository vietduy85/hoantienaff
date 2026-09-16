<?php

namespace App\Http\Controllers;

use App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager;
use App\Services\PriceComparison\PriceComparisonManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class PriceComparisonController extends Controller
{
    private const RETAILERS = [
        'coop'    => 'Co.op Online',
        'bhx'     => 'BHX',
        'winmart' => 'WinMart',
        'dmx'     => 'Điện Máy Xanh',
        'cps'     => 'CellphoneS',
        'nk'      => 'Nguyễn Kim',
        'lotte'   => 'LOTTE Mart',
    ];

    private const SUPPORTED_RETAILERS = ['coop'];

    public function __construct(
        private readonly PriceComparisonManager $catalog,
        private readonly AffiliateSearchLinkManager $affiliateLinks,
    ) {}

    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $page = max(1, (int) $request->query('page', 1));
        $sort = in_array($request->query('sort'), ['relevance', 'price_asc', 'price_desc'], true)
            ? $request->query('sort')
            : 'relevance';
        $retailer = $request->query('retailer');

        $products = [];
        $pagination = ['total' => 0, 'total_pages' => 0];
        $error = null;
        $providerAvailable = true;
        $retailerCounts = array_fill_keys(array_keys(self::RETAILERS), 0);
        $marketplaceLinks = [];

        if ($keyword !== '') {
            $username = Auth::user()?->username;

            try {
                $marketplaceLinks = $this->affiliateLinks->getLinks($keyword, $username);
            } catch (Throwable $e) {
                Log::warning('AffiliateSearchLinkManager failed', [
                    'keyword' => $keyword,
                    'error'   => $e->getMessage(),
                ]);
            }

            $useProvider = $retailer === null || $retailer === '' || $retailer === 'coop';

            if ($useProvider && in_array($retailer ?? 'coop', self::SUPPORTED_RETAILERS, true)) {
                try {
                    $provider = $this->catalog->forSource('coop_online');
                    $result = $provider->search($keyword, $page, 20);

                    $pagination = [
                        'total'       => $result->total,
                        'total_pages' => $result->totalPages,
                    ];

                    $products = $result->items
                        ->map(fn ($p) => $p->toArray())
                        ->values()
                        ->all();

                    $retailerCounts['coop'] = $result->total;
                } catch (Throwable $e) {
                    Log::warning('PriceComparison page search failed', [
                        'keyword'  => $keyword,
                        'page'     => $page,
                        'message'  => $e->getMessage(),
                    ]);

                    $error = 'Không thể lấy dữ liệu lúc này. Vui lòng thử lại.';
                }
            } else {
                $providerAvailable = false;
            }

            if ($sort === 'price_asc' && $error === null) {
                usort($products, fn (array $a, array $b) => ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX));
            } elseif ($sort === 'price_desc' && $error === null) {
                usort($products, fn (array $a, array $b) => ($b['price'] ?? 0) <=> ($a['price'] ?? 0));
            }
        }

        return view('price-comparison.index', [
            'keyword'           => $keyword ?: null,
            'products'          => $products,
            'pagination'        => $pagination,
            'page'              => $page,
            'sort'              => $sort,
            'retailer'          => $retailer,
            'retailers'         => self::RETAILERS,
            'retailerCounts'    => $retailerCounts,
            'providerAvailable' => $providerAvailable,
            'error'             => $error,
            'marketplaceLinks'  => $marketplaceLinks,
        ]);
    }
}