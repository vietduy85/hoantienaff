<?php

namespace App\Http\Controllers\Admin\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreditCard\CloneSystemPolicyRequest;
use App\Http\Requests\Admin\CreditCard\CreateSystemPolicyRequest;
use App\Http\Requests\Admin\CreditCard\StoreSystemPolicyVersionRequest;
use App\Http\Requests\Admin\CreditCard\UpdateSystemPolicyRequest;
use App\Http\Requests\Admin\CreditCard\UpdateSystemPolicyVersionRequest;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyVersion;
use App\Services\CreditCard\PolicyCloneService;
use App\Services\CreditCard\PolicyService;
use App\Support\CreditCard\SystemPolicyPresenter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

/**
 * JSON API quản trị "Chính sách hoàn tiền hệ thống" (admin).
 *
 * Nằm trong group admin, mỗi route gắn `permission:credit-cards.view` /
 * `permission:credit-cards.manage` (route layer) → user thường 403 NGAY từ middleware,
 * không cần chạy tới controller. `PolicyTemplatePolicy` là lớp chống chồng thứ hai.
 *
 * KHÔNG có tier/rule CRUD granular cho blueprint: mọi thay đổi cấu hình đi qua tạo
 * VERSION mới (`storeVersion`) — blueprint cũ bất biến (§9.2). Rich, thin controller:
 * nghiệp vụ nằm ở `PolicyService` + `PolicyCloneService`.
 *
 * Ngoại lệ từ service (danh mục không hợp lệ, template vô hiệu...) trả 422 JSON,
 * KHÔNG để rơi thành 500: đây là lỗi dữ liệu đầu vào, không phải lỗi chương trình.
 */
class SystemPolicyApiController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PolicyService $policies,
        private readonly PolicyCloneService $cloneService,
        private readonly SystemPolicyPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PolicyTemplate::class);

        $templates = PolicyTemplate::query()
            ->system()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (PolicyTemplate $template) => $this->presenter->template($template)),
        ]);
    }

    public function store(CreateSystemPolicyRequest $request): JsonResponse
    {
        try {
            $template = $this->policies->createSystemTemplate(
                $request->input('name'),
                $request->description(),
                $request->date('effective_from'),
                $request->overrides(),
                $request->isPublished(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->presenter->template($template, withDetails: true)], 201);
    }

    public function show(Request $request, PolicyTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        return response()->json(['data' => $this->presenter->template($template, withDetails: true)]);
    }

    public function versions(Request $request, PolicyTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        $blueprints = $this->policies->systemVersions($template);

        return response()->json([
            'data' => $blueprints->map(fn (Policy $blueprint) => $this->presenter->blueprint($blueprint)),
        ]);
    }

    /**
     * Sửa cấu hình ⇒ tạo blueprint version N+1 (kèm metadata template nếu có).
     *
     * Truyền `source_version_id` trong luồng "Chỉnh sửa version N" để version mới
     * được copy từ CHÍNH version đó cộng các thay đổi, thay vì từ current/latest.
     */
    public function storeVersion(StoreSystemPolicyVersionRequest $request, PolicyTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        try {
            $this->policies->updateSystemMeta($template, $request->meta());

            $blueprint = $this->policies->createSystemVersion(
                $template,
                $request->date('effective_from'),
                $request->overrides(),
                $request->sourceVersionId(),
            );
        } catch (InvalidArgumentException|LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->flash('success', "Đã tạo Version {$blueprint->version_no}.");

        return response()->json(['data' => $this->presenter->blueprint($blueprint)], 201);
    }

    /**
     * Tạo chính sách hệ thống MỚI từ editor clone của `$template` (nguồn không đổi).
     *
     * Editor clone hydrate từ CHÍNH version nguồn (`source_version_id`) rồi gửi toàn
     * bộ payload; backend deep-clone mọi blueprint, overlay cấu hình version đang
     * sửa, remap default, trong MỘT transaction. Thành công → JSON kèm `redirect`
     * tới trang "Chỉnh sửa" của chính sách mới để admin chỉnh tiếp.
     */
    public function clone(CloneSystemPolicyRequest $request, PolicyTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $meta = $request->meta();

        try {
            $cloned = $this->cloneService->createSystemPolicyFromEditor(
                $template,
                $meta['name'],
                $meta['description'] ?? null,
                (bool) ($meta['is_active'] ?? false),
                $request->date('effective_from'),
                $request->overrides(),
                $request->sourceVersionId(),
            );
        } catch (InvalidArgumentException|LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->flash('success', sprintf(
            'Đã tạo chính sách "%s" từ bản sao "%s".',
            $cloned->name,
            $template->name,
        ));

        return response()->json([
            'data' => $this->presenter->template($cloned, withDetails: true),
            'redirect' => route('admin.credit-card-policies.edit', $cloned),
        ], 201);
    }

    /**
     * "Lưu lại" — cập nhật in-place cấu hình của CHÍNH phiên bản blueprint đang sửa.
     *
     * KHÔNG tạo version mới (ngược hẳn `storeVersion`); giữ nguyên `version_no`,
     * giữ id của tier/rule khi có thể, xóa rule vắng trong payload, KHÔNG đổi
     * default và KHÔNG cascade sang user policy (thẻ clone là bản sao độc lập).
     *
     * Nếu editor gửi kèm metadata template (tên / mô tả / trạng thái) thì ghi luôn
     * để ô "Mô tả" không bị mất khi bấm "Lưu lại". Metadata chỉ được ghi SAU khi
     * cấu hình version hợp lệ, tránh lưu mô tả khi version bị từ chối (422).
     */
    public function updateVersion(UpdateSystemPolicyVersionRequest $request, PolicyTemplate $template, PolicyVersion $version): JsonResponse
    {
        $this->authorize('update', $template);

        try {
            $updated = $this->policies->updateSystemVersion($template, $version, $request->overrides());

            $meta = $request->meta();

            if ($meta !== []) {
                $this->policies->updateSystemMeta($template, $meta);
            }
        } catch (InvalidArgumentException|LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->flash('success', "Đã cập nhật Version {$updated->version_no}.");

        return response()->json(['data' => $this->presenter->blueprint($updated)]);
    }

    /**
     * Sửa metadata template (tên / mô tả / trạng thái xuất bản).
     *
     * Trang show gọi PATCH này rồi reload — flash để banner success hiện sau reload.
     */
    public function update(UpdateSystemPolicyRequest $request, PolicyTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $updated = $this->policies->updateSystemMeta($template, $request->meta());

        $request->session()->flash('success', 'Đã lưu thông tin chính sách.');

        return response()->json(['data' => $this->presenter->template($updated)]);
    }

    /**
     * Xóa một blueprint (version) của chính sách hệ thống.
     *
     * Chỉ version đang là MẶC ĐỊNH mới không xóa được — `PolicyService::deleteSystemVersion()`
     * giải thích lý do, ta hiển thị lại lên màn hình qua flash error.
     */
    public function destroyVersion(Request $request, PolicyTemplate $template, PolicyVersion $version): RedirectResponse
    {
        $this->authorize('update', $template);

        try {
            $this->policies->deleteSystemVersion($template, $version);
        } catch (InvalidArgumentException|LogicException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Đã xóa Version {$version->version_no}.");
    }

    /**
     * Đặt một blueprint của chính sách làm version MẶC ĐỊNH (nguồn clone user mới).
     */
    public function setDefaultVersion(Request $request, PolicyTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template);

        $request->validate([
            'version_id' => ['required', 'integer'],
        ]);

        try {
            $version = $this->policies->setDefaultVersion($template, (int) $request->input('version_id'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Version {$version->version_no} đã được đặt làm mặc định.");
    }
}
