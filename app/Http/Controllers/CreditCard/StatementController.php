<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreStatementRequest;
use App\Http\Requests\CreditCard\UpdateStatementRequest;
use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\CreditCardCardSortService;
use App\Services\CreditCard\CreditCardStatementService;
use App\Services\CreditCard\StatementPeriodService;
use App\Services\CreditCard\UserCardService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sao kê THỰC TẾ — khác với `TransactionHistoryController`.
 *
 * ---------------------------------------------------------------------------
 * SAO KÊ THỰC TẾ ≠ LỊCH SỬ GIAO DỊCH
 * ---------------------------------------------------------------------------
 * Lịch sử giao dịch là thứ USER NHẬP và engine cashback dùng để tính. Sao kê thực tế
 * là con số user ĐỌC trên bảng sao kê của ngân hàng (`CreditCardStatement`). Hai
 * thứ có thể lệch nhau, và việc nhập sao kê KHÔNG được động vào cashback — nên
 * controller này không gọi engine, không sửa giao dịch, không sửa kỳ.
 *
 * ---------------------------------------------------------------------------
 * MỞ TRANG KHÔNG ĐƯỢC GHI
 * ---------------------------------------------------------------------------
 * Cả `index()` (HTML) lẫn `apiIndex()` (JSON) đều dùng `findForDate()` qua
 * `CreditCardStatementService::currentPeriodFor()` — chỉ đọc, KHÔNG tạo kỳ. Cùng
 * nguyên tắc đã áp cho trang Tổng quan.
 */
class StatementController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly StatementPeriodService $periods,
        private readonly CreditCardStatementService $statements,
        private readonly CreditCardCardSortService $sort,
        private readonly UserCardService $cards,
    ) {
    }

    /**
     * Trang Sao kê — /thetindung/sao-ke
     *
     * Mọi thẻ đều có dòng sao kê kỳ hiện tại (trống nếu user chưa nhập) để user
     * nhìn và so sánh được ngay, thay vì phải mở từng thẻ một lượt.
     */
    public function index(Request $request): View
    {
        $userId = (int) $request->user()->id;
        $today = CarbonImmutable::now();
        $sortMode = $this->sort->normalizeMode($request->query('sort'));

        $cards = $this->ownedCards($userId);

        $rows = $this->sort
            ->sort($sortMode, $cards, $today)
            ->map(fn (UserCard $card): array => $this->presentCard($card, $today))
            ->all();

        return view('credit-card.statements', [
            'rows' => $rows,
            'sortMode' => $sortMode,
            'sortModes' => CreditCardCardSortService::modes(),
            'sortAction' => route('credit-cards.statements'),
            'sortStorageKey' => 'credit-card-statements-sort',
            'today' => $today->toDateString(),
            'urls' => [
                'index' => route('credit-cards.api.statements.index'),
                // Với URL có tham số, truyền `0` để `route()` chỉ đúng hình dạng
                // rồi JS thay số 0 bằng id thật. `0` là chỗ giữ, không phải dữ liệu.
                'store' => route('credit-cards.api.statements.store', ['userCard' => 0]),
                'update' => route('credit-cards.api.statements.update', ['statement' => 0]),
                'destroy' => route('credit-cards.api.statements.destroy', ['statement' => 0]),
            ],
        ]);
    }

    /**
     * API đọc — /thetindung/api/sao-ke
     *
     * Trả về ĐÚNG những gì trang HTML đang hiển thị, cùng thứ tự, để không có hai
     * nơi hiển thị khác nhau.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $today = CarbonImmutable::now();
        $sortMode = $this->sort->normalizeMode($request->query('sort'));

        $data = $this->sort
            ->sort($sortMode, $this->ownedCards($userId), $today)
            ->map(fn (UserCard $card): array => $this->presentCard($card, $today))
            ->all();

        return response()->json([
            'data' => $data,
            'sort_mode' => $sortMode,
            'sort_modes' => CreditCardCardSortService::modes(),
        ]);
    }

    /**
     * Nhập / sửa sao kê của kỳ hiện tại.
     *
     * Ghi vào kỳ HIỆN TẠI nên dùng `currentPeriod()` (tạo kỳ nếu chưa có) — khác
     * các đường đọc ở trên. `user_id` không bao giờ lấy từ request: thẻ phải
     * thuộc đúng user đang đăng nhập, kiểm ở `findOwned()` rồi tới policy.
     */
    public function store(StoreStatementRequest $request, string $userCard): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $card = $this->cards->findOwned((int) $userCard, $userId);

        $this->authorize('create', [CreditCardStatement::class, $card]);

        $period = $this->periods->currentPeriod($card);
        $statement = $this->statements->upsert($card, $period, $request->payload());

        // `store` là cả nhập lẫn sửa: nhập mới ⇒ 201, sửa dòng đã có ⇒ 200. Phân
        // biệt bằng `wasRecentlyCreated` thay vì truy vấn lại lần nữa.
        return response()->json(
            ['data' => $this->presentStatement($statement)],
            $statement->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Sửa dòng sao kê đã có — cập nhật TỪNG PHẦN.
     *
     * Tra cứu không lọc owner để policy trả 403 cho dòng của user khác (như
     * `TransactionController::update`), đồng thời vẫn nói rõ lý do bị chặn.
     */
    public function update(UpdateStatementRequest $request, string $statement): JsonResponse
    {
        $model = CreditCardStatement::findOrFail((int) $statement);

        $this->authorize('update', $model);

        $card = $model->userCard;
        $period = $model->statementPeriod;

        // Quan hệ hỏng (không còn thẻ/kỳ) thì policy đã chặn; hai dòng này chỉ
        // để thỏa máy tĩnh, không mở thêm đường ghi.
        abort_unless($card !== null && $period !== null, 404);

        $updated = $this->statements->upsert($card, $period, $request->payload());

        return response()->json(['data' => $this->presentStatement($updated)]);
    }

    public function destroy(Request $request, string $statement): JsonResponse
    {
        $model = CreditCardStatement::findOrFail((int) $statement);

        $this->authorize('delete', $model);

        $card = $model->userCard;
        $period = $model->statementPeriod;

        abort_unless($card !== null && $period !== null, 404);

        $this->statements->delete($card, $period);

        return response()->json(['deleted' => true, 'id' => (int) $model->id]);
    }

    /**
     * Thẻ của user, cùng quan hệ mà cả ba chế độ sort đều cần.
     *
     * @return \Illuminate\Support\Collection<int, UserCard>
     */
    private function ownedCards(int $userId): \Illuminate\Support\Collection
    {
        return UserCard::query()
            ->ownedBy($userId)
            ->with(['bank', 'currentPolicy'])
            ->ordered()
            ->get();
    }

    /**
     * Một hàng của màn Sao kê: thẻ + kỳ hiện tại + dòng sao kê + ranh giới kỳ.
     *
     * `period_start`/`period_end` gửi cả khi CHƯA có bản ghi kỳ: người dùng cần
     * biết kỳ đang nhập là kỳ nào, kể cả lần đầu tiên (lúc đó hệ thống chưa tạo
     * kỳ nào — mở trang không được tạo).
     *
     * @return array<string, mixed>
     */
    private function presentCard(UserCard $card, CarbonImmutable $today): array
    {
        [$start, $end] = $this->periods->currentBoundaries($card, $today);
        [$period, $statement] = $this->statements->currentBundleFor($card, $today);

        $finalized = $period !== null && $period->isFinalized();

        return [
            'id' => (int) $card->id,
            'name' => $card->name,
            'card_number_last4' => $card->card_number_last4,
            'bank' => $card->bank?->name,
            'sort_order' => (int) $card->sort_order,
            // Thẻ chưa có sao kê vẫn sửa được (đó là lúc cần nhập); có sao kê
            // trong kỳ đã chốt thì không. View chỉ đọc cờ này.
            'editable' => ! $finalized,
            'period' => $period === null ? null : [
                'id' => (int) $period->id,
                'start' => $period->period_start->toDateString(),
                'end' => $period->period_end->toDateString(),
                'due_date' => $period->payment_due_date?->toDateString(),
                'status' => $period->status,
                'finalized' => $period->isFinalized(),
                // Tính ở server để view chỉ tô màu, không tự đếm ngày — và để
                // test kiểm được trạng thái quá hạn mà không phụ thuộc múi giờ JS.
                'due_state' => $this->dueState($period->payment_due_date, $today),
                'days_to_due' => $period->payment_due_date === null
                    ? null
                    : (int) $today->startOfDay()->diffInDays($period->payment_due_date->startOfDay(), false),
            ],
            'period_bounds' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                // Bản đã định dạng để view in ra thẳng, khỏi parse lại ở tầng
                // trình bày. Còn `start`/`end` giữ nguyên ISO cho API.
                'start_label' => $start->format('d/m/Y'),
                'end_label' => $end->format('d/m/Y'),
            ],
            'statement' => $statement === null ? null : $this->presentStatement($statement),
        ];
    }

    /**
     * Hạn thanh toán còn bao nhiêu ngày — quyết định hiển thị do SERVER chốt.
     *
     * Trả về `overdue` / `today` / `soon` (≤ 3 ngày) / `later` / `none`. View chỉ
     * chọn màu theo kết quả này, không tự so sánh ngày ở trình duyệt — nơi đếm
     * ngày khác nhau giữa máy chủ và máy người dùng là nơi hay sinh sai lệch.
     */
    private function dueState(?CarbonInterface $due, CarbonImmutable $today): string
    {
        if ($due === null) {
            return 'none';
        }

        $days = (int) $today->startOfDay()->diffInDays($due->startOfDay(), false);

        return match (true) {
            $days < 0 => 'overdue',
            $days === 0 => 'today',
            $days <= 3 => 'soon',
            default => 'later',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStatement(CreditCardStatement $statement): array
    {
        $period = $statement->statementPeriod;

        return [
            'id' => (int) $statement->id,
            'user_card_id' => (int) $statement->user_card_id,
            'actual_spend' => (string) $statement->actual_spend,
            'actual_reward' => (string) $statement->actual_reward,
            // Đọc bằng công thức, không đọc thẳng cột: đây là số server sẽ ghi,
            // nên client không thể hiển thị lệch với DB.
            'closing_balance' => $statement->closingBalance(),
            'editable' => $period === null || ! $period->isFinalized(),
        ];
    }
}
