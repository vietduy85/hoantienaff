<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Models\CreditCard\PolicyTemplate;
use App\Services\CreditCard\CardPolicyLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * /thetindung/tra-cuu — module "Tra cứu" cho Thẻ tín dụng.
 *
 * Hai chức năng, đều CHỈ ĐỌC:
 *   1. Tra cứu thẻ: chọn một chính sách hoàn tiền (template hệ thống hoặc riêng
 *      của user) → xem tóm tắt + danh sách MCC đã mapping.
 *   2. Tra cứu MCC: nhập mã 4 ký tự → xem mô tả + các chính sách liên quan.
 *
 * Mọi truy vấn scope theo `auth()->id()`: chỉ chính sách user được phép thấy
 * (hệ thống + riêng của họ) mới tra cứu được. Không đụng engine cashback.
 */
class CreditCardLookupController extends Controller
{
    public function __construct(
        private readonly CardPolicyLookupService $lookup,
    ) {}

    /** Trang /thetindung/tra-cuu — hai tab "Tra cứu thẻ" và "Tra cứu MCC". */
    public function index(): View
    {
        $templates = $this->lookup
            ->selectableTemplates((int) auth()->id())
            ->map(fn (PolicyTemplate $template): array => [
                'id' => (int) $template->id,
                'label' => $template->name,
                'sublabel' => $template->isSystemScope() ? 'Hệ thống' : 'Của tôi',
            ])
            ->values()
            ->all();

        return view('credit-card.lookup', [
            'policyTemplates' => $templates,
        ]);
    }

    /**
     * GET /thetindung/tra-cuu/chinh-sach — tóm tắt + MCC của một chính sách.
     */
    public function policy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer'],
        ]);

        $template = PolicyTemplate::query()
            ->selectableBy((int) auth()->id())
            ->whereKey((int) $validated['template_id'])
            ->first();

        if ($template === null) {
            throw ValidationException::withMessages([
                'template_id' => 'Chính sách không tồn tại hoặc không thuộc quyền tra cứu của bạn.',
            ]);
        }

        return response()->json([
            'data' => [
                'policy' => $this->lookup->summarize($template),
                'mcc_mappings' => $this->lookup->mappingsForTemplate((int) $template->id),
                'mcc_profiles' => $this->lookup->mccProfilesForTemplate((int) $template->id),
            ],
        ]);
    }

    /**
     * GET /thetindung/tra-cuu/mcc — tra một mã MCC (giữ đúng 4 ký tự).
     */
    public function mcc(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mcc' => ['required', 'string', 'regex:/^\d{4}$/'],
        ]);

        return response()->json([
            'data' => $this->lookup->lookupMcc((string) $validated['mcc']),
        ]);
    }
}
