<?php

namespace App\Http\Controllers\Admin\CreditCard;

use App\Http\Controllers\Controller;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyVersion;
use App\Support\CreditCard\SystemPolicyPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trang vận hành (Blade) quản trị "Chính sách hoàn tiền hệ thống".
 *
 * Chỉ chịu trách nhiệm đổ dữ liệu cho view. Mọi ghi (tạo mới / đổi version / sửa
 * metadata) do trang gửi fetch tới `SystemPolicyApiController` — cùng một payload
 * được dùng để test HTTP, giống kiến trúc "trang Blade + JSON API" của module user.
 */
class SystemPolicyAdminController extends Controller
{
    public function __construct(
        private readonly SystemPolicyPresenter $presenter,
    ) {}

    public function index(Request $request): View
    {
        $templates = PolicyTemplate::query()
            ->system()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (PolicyTemplate $template) => $this->presenter->template($template));

        return view('admin.credit-card-policies.index', [
            'templates' => $templates,
            'canManage' => $request->user()->hasPermissionTo('credit-cards.manage'),
        ]);
    }

    public function create(): View
    {
        return view('admin.credit-card-policies.create', [
            'categories' => $this->systemCategories(),
            'combos' => $this->systemCombos(),
        ]);
    }

    public function show(Request $request, PolicyTemplate $template): View
    {
        $presented = $this->presenter->template($template, withDetails: true);

        return view('admin.credit-card-policies.show', [
            'template' => $presented,
            'versions' => $template->blueprints()->get()
                ->map(fn ($blueprint) => $this->presenter->blueprint($blueprint))
                ->all(),
            'categories' => $this->systemCategories(),
            // Trang Xem: nạp cả combo đã ẩn để rule cũ vẫn hiện đúng tên.
            'combos' => $this->systemCombos(withInactive: true),
            'initial' => $this->editorInitial($presented),
            'canManage' => $request->user()->hasPermissionTo('credit-cards.manage'),
        ]);
    }

    public function edit(Request $request, PolicyTemplate $template): View
    {
        $templateView = $this->presenter->template($template);

        // Luồng "Chỉnh sửa version N": `?version=` trỏ đúng blueprint được sửa.
        // Editor khởi tạo bằng cấu hình CHÍNH version đó; khi lưu, `source_version_id`
        // = version đó để version mới được copy từ nó (không bị lệch so với màn hình).
        $blueprint = $this->resolveVersionForEdit($template, $request->integer('version') ?: null);

        $presented = $templateView;
        $presented['version_no'] = $blueprint?->version_no;
        $presented['effective_from'] = $blueprint?->effective_from?->toDateString();
        $presented['effective_to'] = $blueprint?->effective_to?->toDateString();
        $presented['min_total_spend'] = $blueprint === null ? null : (float) $blueprint->min_total_spend;
        $presented['tiers'] = $blueprint === null ? [] : $this->presenter->blueprint($blueprint)['tiers'];

        return view('admin.credit-card-policies.edit', [
            'template' => $presented,
            'templateId' => $template->id,
            'sourceVersionId' => $blueprint?->id,
            'editingVersionNo' => $blueprint?->version_no,
            'initial' => $this->editorInitial($presented),
            'categories' => $this->systemCategories(),
            // Sửa: nạp cả combo đã ẩn — rule đang trỏ vào combo bị ẩn không được
            // mất nhãn khi mở lại editor.
            'combos' => $this->systemCombos(withInactive: true),
        ]);
    }

    /**
     * Mở EDITOR clone cho chính sách hệ thống (GET — KHÔNG ghi DB).
     *
     * Đúng luồng admin bấm [📋 Clone]: mở trang "Chỉnh sửa" đã hydrate từ CHÍNH
     * blueprint mặc định của nguồn (name đổi thành "X - Copy"), mode=clone. Khi
     * admin bấm [Lưu thành chính sách mới], editor POST JSON tới API clone
     * (`SystemPolicyApiController::clone`) → tạo template hệ thống MỚI trong một
     * transaction; chính sách gốc không hề bị thay đổi.
     */
    public function cloneForm(Request $request, PolicyTemplate $template): View|RedirectResponse
    {
        if (! $template->blueprints()->exists()) {
            return redirect()->route('admin.credit-card-policies.index')
                ->with('error', 'Chính sách hệ thống chưa có version nào để clone.');
        }

        $blueprint = $template->defaultBlueprint() ?? $template->currentBlueprint();

        $presented = $this->presenter->template($template);
        $presented['name'] = $template->name.' - Copy';
        $presented['version_no'] = $blueprint?->version_no;
        $presented['effective_from'] = $blueprint?->effective_from?->toDateString();
        $presented['effective_to'] = $blueprint?->effective_to?->toDateString();
        $presented['min_total_spend'] = $blueprint === null ? null : (float) $blueprint->min_total_spend;
        $presented['tiers'] = $blueprint === null ? [] : $this->presenter->blueprint($blueprint)['tiers'];

        return view('admin.credit-card-policies.edit', [
            'template' => $presented,
            'templateId' => $template->id,
            'sourceVersionId' => $blueprint?->id,
            'editingVersionNo' => null,
            'initial' => $this->editorInitial($presented),
            'categories' => $this->systemCategories(),
            'combos' => $this->systemCombos(withInactive: true),
            'cloneMode' => true,
            'sourceName' => $template->name,
        ]);
    }

    /**
     * Chọn blueprint nguồn cho trang sửa: version cụ thể nếu có, ngược lại default.
     */
    private function resolveVersionForEdit(PolicyTemplate $template, ?int $versionId): ?PolicyVersion
    {
        if ($versionId !== null) {
            $version = PolicyVersion::query()
                ->where('id', $versionId)
                ->where('template_id', $template->id)
                ->whereNull('user_card_id')
                ->first();

            if ($version !== null) {
                return $version;
            }
        }

        return $template->defaultBlueprint();
    }

    /**
     * Dữ liệu khởi tạo cho editor Alpine trên trang "Chỉnh sửa".
     *
     * @param  array<string, mixed>  $presented
     * @return array<string, mixed>
     */
    private function editorInitial(array $presented): array
    {
        return [
            'name' => $presented['name'],
            'description' => $presented['description'],
            'effective_from' => $presented['effective_from'] ?? now()->toDateString(),
            'status' => $presented['is_active'] ? 'published' : 'draft',
            'min_total_spend' => $presented['min_total_spend'] ?? 0,
            'tiers' => $presented['tiers'] ?? [],
        ];
    }

    /**
     * @return array<int, array{id:int, name:string}>
     */
    private function systemCategories(): array
    {
        return Category::query()
            ->system()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => ['id' => $category->id, 'name' => $category->name])
            ->values()
            ->all();
    }

    /**
     * Combo HỆ THỐNG đang bật — mục tiêu thứ hai cho rule (danh mục | combo | fallback).
     *
     * Chỉ combo active mới chọn được: combo đã ẩn làm rule cũ mất hiệu lực, và rule
     * đang trỏ vào combo bị ẩn vẫn phải đọc lại được tên để hiển thị ở chế độ Xem.
     *
     * @return array<int, array{id:int, name:string, category_count:int}>
     */
    private function systemCombos(bool $withInactive = false): array
    {
        return CategoryCombo::query()
            ->system()
            ->when(! $withInactive, fn (Builder $query) => $query->active())
            ->with('items')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (CategoryCombo $combo) => [
                'id' => $combo->id,
                'name' => $combo->name,
                'category_count' => $combo->items->count(),
            ])
            ->values()
            ->all();
    }
}
