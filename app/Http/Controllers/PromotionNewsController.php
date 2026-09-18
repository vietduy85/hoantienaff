<?php

namespace App\Http\Controllers;

use App\Services\PromotionNews\DataTransfer\PromotionNewsData;
use App\Services\PromotionNews\PromotionNewsManager;
use App\Services\PromotionNews\PromotionNewsSources;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PromotionNewsController extends Controller
{
    private const PER_PAGE = 12;

    public function __construct(private readonly PromotionNewsManager $news) {}

    public function index(Request $request): View
    {
        $allNews = $this->news->all();

        return view('promotion-news.index', [
            'news' => $this->paginate($allNews, $request),
            'sourceCounts' => $this->sourceCounts($allNews),
            'total' => count($allNews),
            'activeSource' => null,
            'pageTitle' => 'Tin tức khuyến mãi',
            'pageDescription' => 'Tổng hợp tin tức khuyến mãi mới nhất từ các siêu thị, thương hiệu và dịch vụ tại Việt Nam.',
            'canonical' => route('promotion-news.index'),
        ]);
    }

    public function source(Request $request, string $source): View
    {
        $allNews = $this->news->all();

        $items = array_values(array_filter(
            $allNews,
            static fn (PromotionNewsData $item) => $item->source === $source
        ));

        if ($items === []) {
            throw new NotFoundHttpException;
        }

        $label = PromotionNewsSources::label($source);

        return view('promotion-news.source', [
            'news' => $this->paginate($items, $request),
            'sourceCounts' => $this->sourceCounts($allNews),
            'total' => count($allNews),
            'activeSource' => $source,
            'source' => $source,
            'sourceLabel' => $label,
            'sourceEmoji' => PromotionNewsSources::emoji($source),
            'pageTitle' => "Tin tức khuyến mãi {$label}",
            'pageDescription' => "Tổng hợp tin khuyến mãi mới nhất từ {$label}.",
            'canonical' => route('promotion-news.source', ['source' => $source]),
        ]);
    }

    /**
     * Total items per source, derived from the already memoized aggregate so no
     * provider is fetched again just to count.
     *
     * @param  array<int, PromotionNewsData>  $items
     *
     * @return array<string, int>
     */
    private function sourceCounts(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            $source = $item->source;
            $counts[$source] = ($counts[$source] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<int, PromotionNewsData>  $items
     */
    private function paginate(array $items, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($items),
            self::PER_PAGE,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }
}
