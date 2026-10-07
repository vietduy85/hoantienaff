<?php

namespace App\Http\Controllers\Admin\CreditCard;

use App\Http\Controllers\Controller;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\SpendQualification;
use App\Models\CreditCard\SpendQualificationTemplate;
use App\Services\CreditCard\SpendQualificationService;
use Illuminate\View\View as ViewResponse;

/**
 * Admin: trang Blade quản lý MẪU "Điều kiện hoàn tiền đặc biệt".
 *
 * Trang đổ dữ liệu template + danh mục hệ thống cho editor; mọi GHI đi qua
 * `SpendQualificationTemplateApiController` (JSON) — đúng kiến trúc "trang +
 * JSON API" của các phân hệ khác.
 */
class SpendQualificationAdminController extends Controller
{
    public function __construct(
        private readonly SpendQualificationService $qualifications,
    ) {}

    public function index(): ViewResponse
    {
        $templates = SpendQualificationTemplate::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('admin.spend-qualification-templates.index', [
            'templates' => $templates->map(
                fn (SpendQualificationTemplate $template) => self::presentWithQualification($template)
            )->values(),
        ]);
    }

    public function create(): ViewResponse
    {
        return $this->editorView(null);
    }

    public function show(SpendQualificationTemplate $template): ViewResponse
    {
        return view('admin.spend-qualification-templates.show', [
            'template' => self::presentWithQualification($template),
        ]);
    }

    public function edit(SpendQualificationTemplate $template): ViewResponse
    {
        return $this->editorView($template);
    }

    private function editorView(?SpendQualificationTemplate $template): ViewResponse
    {
        $qualificationPayload = $template === null
            ? null
            : $this->qualifications->payloadForTemplate((int) $template->id);

        return view('admin.spend-qualification-templates.editor', [
            'template' => $template === null ? null : self::present($template),
            'qualification' => $qualificationPayload,
            'systemCategories' => Category::query()
                ->system()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                ])
                ->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWithQualification(SpendQualificationTemplate $template): array
    {
        return self::present($template) + [
            'spend_qualification' => $this->qualifications->payloadForTemplate((int) $template->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(SpendQualificationTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'note' => $template->note,
            'is_active' => $template->isActive(),
            'sort_order' => (int) $template->sort_order,
            'in_use' => $template->isUsedByAnyQualification(),
            'usage_count' => SpendQualification::query()
                ->where('source_template_id', $template->getKey())
                ->count(),
        ];
    }
}