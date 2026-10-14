<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileDocument;
use App\Models\CreditCard\PolicyTemplate;
use App\Services\CreditCard\CardPolicyLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /thetindung/tra-cuu — module "Tra cứu" cho Thẻ tín dụng.
 *
 * Ba chức năng, đều CHỈ ĐỌC:
 *   1. Tra cứu thẻ: chọn một chính sách hoàn tiền (template hệ thống hoặc riêng
 *      của user) → xem tóm tắt + danh sách MCC đã mapping.
 *   2. Tra cứu MCC: nhập mã 4 ký tự → xem mô tả + các chính sách liên quan.
 *   3. Tra cứu danh mục: chọn một danh mục (gom từ HỒ SƠ MCC) → xem các thẻ hoàn
 *      tiền cho danh mục đó.
 *
 * Mọi truy vấn scope theo `auth()->id()`: chỉ chính sách user được phép thấy
 * (hệ thống + riêng của họ) mới tra cứu được. Không đụng engine cashback.
 */
class CreditCardLookupController extends Controller
{
    public function __construct(
        private readonly CardPolicyLookupService $lookup,
    ) {}

    /** Trang /thetindung/tra-cuu — ba tab: "Tra cứu thẻ", "Tra cứu MCC", "Tra cứu danh mục". */
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
            'categoryOptions' => $this->lookup->categoryOptions(),
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

    /**
     * GET /thetindung/tra-cuu/danh-muc — các thẻ hoàn tiền cho MỘT danh mục.
     *
     * `category` là khoá danh mục (nhóm chuẩn hoá hoặc `raw:*`) do dropdown gửi
     * lên; service chỉ tra trong dòng INCLUDED của hồ sơ MCC.
     */
    public function category(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:191'],
        ]);

        return response()->json([
            'data' => $this->lookup->lookupCategory((string) $validated['category']),
        ]);
    }

    /**
     * GET /thetindung/tra-cuu/tai-lieu/{profile}/{document} — tải tài liệu ngân
     * hàng của HỒ SƠ MCC (module "Quản lý MCC").
     *
     * File nằm trên disk PRIVATE nên không có URL tĩnh; endpoint này kiểm tra
     * theo thứ tự: tài liệu thuộc ĐÚNG hồ sơ → hồ sơ thuộc chính sách mà user
     * được phép tra cứu → file còn tồn tại. Mọi sai lệch trả 404, không tiết
     * lộ đường dẫn hay sự tồn tại của tài liệu. Quyền đọc của Admin KHÔNG đổi.
     */
    public function document(PolicyMccProfile $profile, PolicyMccProfileDocument $document): StreamedResponse
    {
        abort_unless((int) $document->policy_mcc_profile_id === (int) $profile->id, 404);

        $selectable = PolicyTemplate::query()
            ->selectableBy((int) auth()->id())
            ->whereKey((int) $profile->policy_template_id)
            ->exists();

        abort_unless($selectable, 404);

        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }
}
