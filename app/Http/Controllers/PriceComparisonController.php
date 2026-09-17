<?php

namespace App\Http\Controllers;

use App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager;
use App\Services\AffiliateSearchLinks\ShopeeSearchJobService;
use App\Services\PriceComparison\PriceComparisonManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class PriceComparisonController extends Controller
{
    private const RETAILERS = [
        'coop'         => 'Co.op Online',
        'bhx'          => 'BHX',
        'kingfoodmart' => 'Kingfoodmart',
        'winmart'      => 'WinMart',
        'dmx'     => 'Điện Máy Xanh',
        'cps'     => 'CellphoneS',
        'nk'      => 'Nguyễn Kim',
        'lotte'   => 'LOTTE Mart',
    ];

    /**
     * Retailer tab key => catalog source identifier.
     */
    private const RETAILER_SOURCES = [
        'coop'         => 'coop_online',
        'bhx'          => 'bach_hoa_xanh',
        'kingfoodmart' => 'kingfoodmart',
    ];

    /**
     * Catalog source identifier => retailer tab key.
     */
    private const SOURCE_RETAILERS = [
        'coop_online'    => 'coop',
        'bach_hoa_xanh'  => 'bhx',
        'kingfoodmart'   => 'kingfoodmart',
    ];

    /**
     * Retailer tab representing the merged "all providers" view.
     */
    private const ALL_RETAILER = 'all';

    public function __construct(
        private readonly PriceComparisonManager $catalog,
        private readonly AffiliateSearchLinkManager $affiliateLinks,
        private readonly ShopeeSearchJobService $shopeeJobs,
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
        $activeRetailer = self::ALL_RETAILER;
        $retailerNotice = null;
        $retailerCounts = array_fill_keys(array_keys(self::RETAILERS), null);
        $retailerCounts[self::ALL_RETAILER] = null;
        $sourceCount = 0;
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

            $activeRetailer = ($retailer === null || $retailer === '') ? self::ALL_RETAILER : $retailer;

            if ($activeRetailer === self::ALL_RETAILER) {
                try {
                    $result = $this->catalog->searchAll($keyword, $page, 20, $sort);

                    $pagination = [
                        'total'       => $result->total,
                        'total_pages' => $result->totalPages,
                    ];

                    $products = $result->items
                        ->map(fn ($p) => $p->toArray())
                        ->values()
                        ->all();

                    $retailerCounts[self::ALL_RETAILER] = $result->total > 0 ? $result->total : null;

                    foreach (self::SOURCE_RETAILERS as $source => $tab) {
                        $count = (int) ($result->sourceTotals[$source] ?? 0);

                        $retailerCounts[$tab] = $count > 0 ? $count : null;

                        if ($count > 0) {
                            $sourceCount++;
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('PriceComparison page search failed', [
                        'keyword' => $keyword,
                        'page'    => $page,
                        'message' => $e->getMessage(),
                    ]);

                    $error = 'Không thể lấy dữ liệu lúc này. Vui lòng thử lại.';
                }
            } elseif (array_key_exists($activeRetailer, self::RETAILER_SOURCES)) {
                $source = self::RETAILER_SOURCES[$activeRetailer];

                if ($this->providerNeedsConfiguration($source)) {
                    $providerAvailable = false;
                    $retailerNotice = 'Nguồn BHX chưa được cấu hình cửa hàng (BHX_STORE_ID).';
                } else {
                    try {
                        $result = $this->catalog->searchSource($source, $keyword, $page, 20);

                        $pagination = [
                            'total'       => $result->total,
                            'total_pages' => $result->totalPages,
                        ];

                        $products = $result->items
                            ->map(fn ($p) => $p->toArray())
                            ->values()
                            ->all();

                        $retailerCounts[$activeRetailer] = $result->total;
                    } catch (Throwable $e) {
                        Log::warning('PriceComparison page search failed', [
                            'keyword'  => $keyword,
                            'page'     => $page,
                            'message'  => $e->getMessage(),
                        ]);

                        $error = 'Không thể lấy dữ liệu lúc này. Vui lòng thử lại.';
                    }
                }

                // Bring back the aggregated totals from the last "all retailers"
                // search so the other tab counts survive switching retailer tabs,
                // without triggering extra provider queries just for counts.
                $aggCounts = $this->catalog->aggregatedCounts($keyword);

                if ($aggCounts !== null) {
                    $allTotal = (int) ($aggCounts['total'] ?? 0);

                    if ($allTotal > 0) {
                        $retailerCounts[self::ALL_RETAILER] = $allTotal;
                    }

                    foreach (self::SOURCE_RETAILERS as $source => $tab) {
                        if ($tab === $activeRetailer || ($retailerCounts[$tab] ?? null) !== null) {
                            continue;
                        }

                        $count = (int) ($aggCounts['sources'][$source] ?? 0);

                        $retailerCounts[$tab] = $count > 0 ? $count : null;
                    }
                }
            } else {
                $providerAvailable = false;
                $retailerNotice = 'Nguồn giá này đang được cập nhật.';
            }

            if ($activeRetailer !== self::ALL_RETAILER && $sort === 'price_asc' && $error === null) {
                usort($products, fn (array $a, array $b) => ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX));
            } elseif ($activeRetailer !== self::ALL_RETAILER && $sort === 'price_desc' && $error === null) {
                usort($products, fn (array $a, array $b) => ($b['price'] ?? 0) <=> ($a['price'] ?? 0));
            }

            $shopeePending = isset($marketplaceLinks['shopee'])
                && $error === null
                && $username !== null
                && empty($marketplaceLinks['shopee']['affiliate_url']);

            if ($shopeePending && $user = Auth::user()) {
                $job = $this->shopeeJobs->ensureForKeyword($keyword, $user);

                $marketplaceLinks['shopee']['request_id'] = $job['request_id'];
                $marketplaceLinks['shopee']['affiliate_url'] = $job['affiliate_url'];
                $marketplaceLinks['shopee']['status'] = $job['affiliate_url'] !== null ? 'ready' : $job['status'];
            }
        }

        return view('price-comparison.index', [
            'keyword'           => $keyword ?: null,
            'products'          => $products,
            'pagination'        => $pagination,
            'page'              => $page,
            'sort'              => $sort,
            'retailer'          => $retailer,
            'activeRetailer'    => $activeRetailer,
            'retailers'         => self::RETAILERS,
            'retailerCounts'    => $retailerCounts,
            'sourceCount'       => $sourceCount,
            'providerAvailable' => $providerAvailable,
            'retailerNotice'    => $retailerNotice,
            'error'             => $error,
            'marketplaceLinks'  => $marketplaceLinks,
        ]);
    }

    private function providerNeedsConfiguration(string $source): bool
    {
        if ($source !== 'bach_hoa_xanh') {
            return false;
        }

        return blank(config('services.bachhoaxanh.store_id'));
    }
}