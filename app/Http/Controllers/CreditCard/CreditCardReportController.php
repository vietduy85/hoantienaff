<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreReportRequest;
use App\Http\Requests\CreditCard\UpdateReportRequest;
use App\Models\CreditCard\Report;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\CreditCardReportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

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

        $data = $report->type === Report::TYPE_BY_CATEGORY
            ? $this->reports->byCategory($cards, $periodKey)
            : $this->reports->byCard($cards, $periodKey);

        return view('credit-card.report-show', [
            'report' => $report,
            'cards' => $cards,
            'periodKey' => $periodKey,
            'periodOptions' => $this->reports->periodOptions($cards),
            'data' => $data,
        ]);
    }

    /** Form sửa báo cáo. */
    public function edit(Request $request, Report $report): View
    {
        $this->authorize('update', $report);

        return view('credit-card.report-form', [
            'report' => $report,
            'cards' => $this->selectableCards((int) $request->user()->id),
            'types' => $this->types(),
            'action' => route('credit-cards.reports.update', ['report' => $report->id]),
            'method' => 'PATCH',
            'selected' => array_map('intval', (array) old('card_ids', $report->cards->pluck('id')->all())),
            'selectedType' => (string) old('type', $report->type),
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
}
