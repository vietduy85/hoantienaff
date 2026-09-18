<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromotionNews;
use App\Rules\HttpUrl;
use App\Services\PromotionNews\PromotionNewsManager;
use App\Services\PromotionNews\PromotionNewsSources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PromotionNewsController extends Controller
{
    public function __construct(private readonly PromotionNewsManager $news) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'source' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'category' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'source_type' => ['nullable', Rule::in([PromotionNews::SOURCE_TYPE_AUTO, PromotionNews::SOURCE_TYPE_MANUAL])],
            'is_active' => ['nullable', Rule::in(['0', '1'])],
        ]);

        $query = PromotionNews::query()
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['source_type'] ?? null, fn ($q, $type) => $q->where('source_type', $type))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active'] === '1'));

        $news = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.promotion-news.index', [
            'news' => $news,
            'filters' => $filters,
            'knownSources' => PromotionNewsSources::order(),
            'categories' => PromotionNews::categories(),
        ]);
    }

    public function create(): View
    {
        return view('admin.promotion-news.create', [
            'news' => new PromotionNews([
                'source_type' => PromotionNews::SOURCE_TYPE_MANUAL,
                'is_active' => true,
                'category' => PromotionNews::CATEGORY_SUPERMARKET,
            ]),
            'knownSources' => PromotionNewsSources::order(),
            'categories' => PromotionNews::categories(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $validated['source_type'] = PromotionNews::SOURCE_TYPE_MANUAL;
        $validated['source_id'] = null;

        PromotionNews::create($validated);

        $this->news->flush();

        return redirect()->route('admin.promotion-news.index')
            ->with('success', __('Đã thêm tin khuyến mãi.'));
    }

    public function edit(PromotionNews $promotionNews): View
    {
        $this->guardManual($promotionNews);

        return view('admin.promotion-news.edit', [
            'news' => $promotionNews,
            'knownSources' => PromotionNewsSources::order(),
            'categories' => PromotionNews::categories(),
        ]);
    }

    public function update(Request $request, PromotionNews $promotionNews): RedirectResponse
    {
        $this->guardManual($promotionNews);

        $validated = $this->validatePayload($request);

        $validated['source_type'] = PromotionNews::SOURCE_TYPE_MANUAL;
        $validated['source_id'] = null;

        $promotionNews->update($validated);

        $this->news->flush();

        return redirect()->route('admin.promotion-news.index')
            ->with('success', __('Đã cập nhật tin khuyến mãi.'));
    }

    public function toggle(PromotionNews $promotionNews): RedirectResponse
    {
        $this->guardManual($promotionNews);

        $promotionNews->update(['is_active' => ! $promotionNews->is_active]);

        $this->news->flush();

        return redirect()->back()->with('success', __('Đã cập nhật trạng thái tin khuyến mãi.'));
    }

    public function destroy(PromotionNews $promotionNews): RedirectResponse
    {
        $this->guardManual($promotionNews);

        $promotionNews->delete();

        $this->news->flush();

        return redirect()->route('admin.promotion-news.index')
            ->with('success', __('Đã xoá tin khuyến mãi.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'category' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image_url' => ['required', 'string', 'max:2048', new HttpUrl],
            'mobile_image_url' => ['nullable', 'string', 'max:2048', new HttpUrl],
            'landing_url' => ['nullable', 'string', 'max:2048', new HttpUrl],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'sort_order' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        return $validated;
    }

    private function guardManual(PromotionNews $promotionNews): void
    {
        if ($promotionNews->source_type !== PromotionNews::SOURCE_TYPE_MANUAL) {
            abort(403, 'Tin tự động từ nhà cung cấp không thể chỉnh sửa thủ công.');
        }
    }
}
