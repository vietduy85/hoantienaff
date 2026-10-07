<?php

namespace App\Http\Controllers\Admin\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreditCard\StoreSpendQualificationTemplateRequest;
use App\Http\Requests\Admin\CreditCard\UpdateSpendQualificationTemplateRequest;
use App\Models\CreditCard\SpendQualificationTemplate;
use App\Services\CreditCard\SpendQualificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * JSON API quản trị MẪU "Điều kiện hoàn tiền đặc biệt" (admin).
 *
 * Mỗi route gắn `permission:credit-cards.view` / `permission:credit-cards.manage`
 * ở route layer. Ghi nghiệp vụ (chuẩn hoá điều kiện, validate danh mục system,
 * xoá-template-đang-dùng) nằm ở `SpendQualificationService`; ngoại lệ trả 422 JSON
 * thay vì 500 — cùng quy ước với `SystemPolicyApiController`.
 */
class SpendQualificationTemplateApiController extends Controller
{
    public function __construct(private readonly SpendQualificationService $qualifications) {}

    public function index(Request $request): JsonResponse
    {
        $templates = SpendQualificationTemplate::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (SpendQualificationTemplate $template) => $this->present($template)),
        ]);
    }

    public function show(Request $request, SpendQualificationTemplate $template): JsonResponse
    {
        return response()->json(['data' => $this->present($template)]);
    }

    public function store(StoreSpendQualificationTemplateRequest $request): JsonResponse
    {
        try {
            $template = SpendQualificationTemplate::query()->create($request->metadata());

            $this->qualifications->persistTemplate((int) $template->id, $request->spendQualification());
        } catch (InvalidArgumentException $e) {
            $template?->forceDelete();

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->flash('success', sprintf('Đã tạo mẫu điều kiện "%s".', $template->name));

        return response()->json(['data' => $this->present($template)], 201);
    }

    public function update(UpdateSpendQualificationTemplateRequest $request, SpendQualificationTemplate $template): JsonResponse
    {
        try {
            if ($request->metadata() !== []) {
                $template->fill($request->metadata())->save();
            }

            if ($request->hasSpendQualification()) {
                $this->qualifications->persistTemplate((int) $template->id, $request->spendQualification());
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $template->refresh();

        $request->session()->flash('success', sprintf('Đã lưu mẫu điều kiện "%s".', $template->name));

        return response()->json(['data' => $this->present($template)]);
    }

    /**
     * Bật / tắt mẫu điều kiện (user chỉ thấy mẫu ĐANG BẬT khi tạo/chỉnh chính sách).
     */
    public function setActive(Request $request, SpendQualificationTemplate $template): JsonResponse
    {
        $active = (bool) $request->input('is_active', $template->isActive());

        $template->is_active = $active;
        $template->save();

        $request->session()->flash('success', $active
            ? sprintf('Đã bật mẫu "%s".', $template->name)
            : sprintf('Đã tắt mẫu "%s".', $template->name));

        return response()->json(['data' => $this->present($template)]);
    }

    /**
     * Xoá mẫu: chỉ mẫu CHƯA được thẻ nào dùng mới xoá được; mẫu đang dùng phải
     * tắt thay vì xoá (`SpendQualificationService::deleteTemplate` lo kiểm này).
     */
    public function destroy(Request $request, SpendQualificationTemplate $template): JsonResponse
    {
        try {
            $this->qualifications->deleteTemplate((int) $template->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->flash('success', sprintf('Đã xoá mẫu điều kiện "%s".', $template->name));

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SpendQualificationTemplate $template): array
    {
        return SpendQualificationAdminController::present($template) + [
            'spend_qualification' => $this->qualifications->payloadForTemplate((int) $template->id),
        ];
    }
}