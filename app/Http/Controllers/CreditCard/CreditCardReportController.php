<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreReportRequest;
use App\Http\Requests\CreditCard\UpdateReportRequest;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\Report;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\CategoryService;
use App\Services\CreditCard\CreditCardReportExporter;
use App\Services\CreditCard\CreditCardReportService;
use App\Support\CreditCard\CreditCardSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Báo cáo chi tiêu — /thetindung/bao-cao
 *
 * ---------------------------------------------------------------------------
 * CẤU HÌNH LƯU LẠI, SỐ LIỆU TÍNH MỖI LẦN MỞ
 * ---------------------------------------------------------------------------
 * Controller chỉ lo điều phối: CRUD cấu hình báo cáo (tên / kiểu / thẻ) và hiển
 * thị kết quả. Mọi phép tính nằm ở `CreditCardReportService` (aggregate SQL, đọc
 * snapshot cashback thật). Không có phép tính tiền nào trong Blade.
 *
 * ---------------------------------------------------------------------------
 * QUYỀN: POLICY, KHÔNG TIN ID TRÊN URL
 * ---------------------------------------------------------------------------
 * `{report}` được route-model-binding rồi kiểm qua `ReportPolicy` — báo cáo của
 * người khác trả 403. Danh sách thẻ luôn đọc theo `auth()->id()`; id thẻ do client
 * gửi lên còn bị chặn thêm ở tầng validation (`StoreReportRequest`).
 */
class CreditCardReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreditCardReportService $reports,
        private readonly CreditCardReportExporter $exporter,
        private readonly CategoryService $categories,
    ) {}

    /** Danh sách báo cáo đã lưu của user. */
    public function index(Request $request): View
    {
        $userId = (int) $request->user()->id;

        return view('credit-card.reports', [
            'reports' => $this->reports->forUser($userId),
            'hasCards' => $this->selectableCards($userId)->isNotEmpty(),
        ]);
    }

    /** Form tạo báo cáo. */
    public function create(Request $request): View
    {
        $userId = (int) $request->user()->id;

        return view('credit-card.report-form', [
            'report' => null,
            'cards' => $this->selectableCards($userId),
            'types' => $this->types(),
            'action' => route('credit-cards.reports.store'),
            'method' => 'POST',
            'selected' => array_map('intval', (array) old('card_ids', [])),
            'selectedType' => (string) old('type', Report::TYPE_BY_CARD),
            'categories' => $this->categoryOptions(null, $userId),
            'selectedExclusions' => array_map('intval', (array) old('excluded_category_ids', [])),
        ]);
    }

    public function store(StoreReportRequest $request): RedirectResponse
    {
        $report = $this->reports->create((int) $request->user()->id, $request->payload());

        return redirect()
            ->route('credit-cards.reports.show', ['report' => $report->id])
            ->with('status', 'report-created');
    }

    /**
     * Trang kết quả. Kỳ đang xem nằm ở query `?period=`; lựa chọn không hợp lệ rơi
     * về "kỳ hiện tại của từng thẻ". Mở trang KHÔNG tạo kỳ/giao dịch.
     */
    public function show(Request $request, Report $report): View
    {
        $this->authorize('view', $report);

        $cards = $report->cards()->with('bank')->get();

        $periodKey = $this->reports->normalizePeriodKey(
            $request->query('period') === null ? null : (string) $request->query('period'),
            $cards,
        );

        $userId = (int) $request->user()->id;
        $excluded = $report->excludedCategoryIds();

        $data = $report->type === Report::TYPE_BY_CATEGORY
            ? $this->reports->byCategory($cards, $periodKey, $excluded)
            : $this->reports->byCard($cards, $periodKey);

        // Sắp xếp (chỉ đổi thứ tự dòng, không đổi số): key/chiều qua allowlist,
        // chiều mặc định asc khi chọn tiêu chí mới, tỷ lệ "—" luôn cuối.
        $sortKey = CreditCardSort::normalizeSort(
            $request->query('sort') === null ? null : (string) $request->query('sort'),
        );
        $sortDir = CreditCardSort::normalizeDirection(
            $request->query('dir') === null ? null : (string) $request->query('dir'),
        );

        $data['rows'] = CreditCardSort::applyRows($data['rows'], (string) $data['mode'], $sortKey, $sortDir);

        return view('credit-card.report-show', [
            'report' => $report,
            'cards' => $cards,
            'periodKey' => $periodKey,
            'periodOptions' => $this->reports->periodOptions($cards),
            'data' => $data,
            'sortKey' => $sortKey,
            'sortDir' => $sortDir,
            'excludedCategoryNames' => $this->excludedCategoryNames($excluded, $userId),
        ]);
    }

    /**
     * Xuất file `.xlsx` của báo cáo đang xem.
     *
     * Chỉ đọc: policy `view` (chủ sở hữu), kỳ qua `normalizePeriodKey()` và dữ
     * liệu do `CreditCardReportService` tính — y hệt trang kết quả, không tạo
     * kỳ/giao dịch. Nếu lỗi tạo file thì quay về trang báo cáo kèm thông báo.
     */
    public function export(Request $request, Report $report): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $report);

        $cards = $report->cards()->with('bank')->get();

        $periodKey = $this->reports->normalizePeriodKey(
            $request->query('period') === null ? null : (string) $request->query('period'),
            $cards,
        );

        $sortKey = CreditCardSort::normalizeSort(
            $request->query('sort') === null ? null : (string) $request->query('sort'),
        );
        $sortDir = CreditCardSort::normalizeDirection(
            $request->query('dir') === null ? null : (string) $request->query('dir'),
        );

        try {
            $tempPath = $this->exporter->export($report, $cards, $periodKey, $sortKey, $sortDir);
        } catch (\Throwable $e) {
            Log::error('Report export failed', [
                'report_id' => $report->id,
                'user_id' => $report->user_id,
                'period_key' => $periodKey,
                'exception' => get_class($e).': '.$e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return redirect()
                ->route('credit-cards.reports.show', ['report' => $report->id, 'period' => $periodKey])
                ->with('error', 'Không thể tạo file Excel. Vui lòng thử lại sau giây lát.');
        }

        return response()->download($tempPath, $this->exporter->downloadFilename($report, $periodKey), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ])->deleteFileAfterSend(true);
    }

    /** Form sửa báo cáo. */
    public function edit(Request $request, Report $report): View
    {
        $this->authorize('update', $report);

        $userId = (int) $request->user()->id;

        return view('credit-card.report-form', [
            'report' => $report,
            'cards' => $this->selectableCards($userId),
            'types' => $this->types(),
            'action' => route('credit-cards.reports.update', ['report' => $report->id]),
            'method' => 'PATCH',
            'selected' => array_map('intval', (array) old('card_ids', $report->cards->pluck('id')->all())),
            'selectedType' => (string) old('type', $report->type),
            // Ưu tiên dữ liệu vừa submit (validation lỗi); ngược lại dùng cấu hình
            // đang lưu — kể cả danh mục đã bị ẩn, để user nhìn thấy và gỡ bỏ.
            'categories' => $this->categoryOptions($report, $userId),
            'selectedExclusions' => array_map(
                'intval',
                (array) old('excluded_category_ids', $report->excludedCategoryIds()),
            ),
        ]);
    }

    public function update(UpdateReportRequest $request, Report $report): RedirectResponse
    {
        $this->authorize('update', $report);

        $this->reports->update($report, (int) $request->user()->id, $request->payload());

        return redirect()
            ->route('credit-cards.reports.show', ['report' => $report->id])
            ->with('status', 'report-updated');
    }

    public function destroy(Request $request, Report $report): RedirectResponse
    {
        $this->authorize('delete', $report);

        $this->reports->delete($report);

        return redirect()
            ->route('credit-cards.reports')
            ->with('status', 'report-deleted');
    }

    /**
     * Thẻ của user để chọn trong form — CÙNG thứ tự hiển thị với màn Quản lý thẻ.
     *
     * @return Collection<int, UserCard>
     */
    private function selectableCards(int $userId): Collection
    {
        return UserCard::query()
            ->ownedBy($userId)
            ->with('bank')
            ->ordered()
            ->get();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function types(): array
    {
        return [
            ['value' => Report::TYPE_BY_CARD, 'label' => 'Chi tiêu theo thẻ'],
            ['value' => Report::TYPE_BY_CATEGORY, 'label' => 'Chi tiêu theo danh mục'],
        ];
    }

    /**
     * Danh mục để render chip "Loại trừ": danh mục hệ thống đang hoạt động + danh
     * mục riêng của user đang hoạt động, VẪN kèm danh mục đang được báo cáo loại
     * trừ dù đã bị ẩn (để user nhìn thấy chip và gỡ bỏ khi sửa).
     *
     * @return Collection<int, Category>
     */
    private function categoryOptions(?Report $report, int $userId): Collection
    {
        $allowed = $this->categories->selectableFor($userId)
            ->keyBy('id');

        if ($report !== null) {
            foreach ($report->excludedCategoryIds() as $excludedId) {
                if ($allowed->has($excludedId)) {
                    continue;
                }

                $stored = Category::query()
                    ->whereKey($excludedId)
                    ->where(function (Builder $query) use ($userId): void {
                        $query->where('scope', Category::SCOPE_SYSTEM)
                            ->orWhere(function (Builder $user) use ($userId): void {
                                $user->where('scope', Category::SCOPE_USER)
                                    ->where('owner_user_id', $userId);
                            });
                    })
                    ->get(['id', 'name', 'scope', 'owner_user_id', 'is_active'])
                    ->first();

                if ($stored instanceof Category) {
                    $allowed->put($stored->id, $stored);
                }
            }
        }

        return $allowed
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Tên các danh mục đang bị loại trừ (để hiển thị dòng tóm tắt trên trang kết
     * quả). ID không còn tồn tại được lược bỏ — không hiển thị ID trần.
     *
     * @param  array<int, int>  $excludedIds
     * @return Collection<int, string>
     */
    private function excludedCategoryNames(array $excludedIds, int $userId): Collection
    {
        if ($excludedIds === []) {
            return collect();
        }

        return Category::query()
            ->whereIn('id', $excludedIds)
            ->where(function (Builder $query) use ($userId): void {
                $query->where('scope', Category::SCOPE_SYSTEM)
                    ->orWhere(function (Builder $user) use ($userId): void {
                        $user->where('scope', Category::SCOPE_USER)
                            ->where('owner_user_id', $userId);
                    });
            })
            ->orderBy('name')
            ->pluck('name');
    }
}
