<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreStatementRequest;
use App\Http\Requests\CreditCard\UpdateStatementRequest;
use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\CreditCardCardSortService;
use App\Services\CreditCard\CreditCardStatementService;
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
 * KỲ NÀO, AI QUYẾT
 * ---------------------------------------------------------------------------
 * Controller KHÔNG suy luận kỳ. Nó nhận `period[<card id>]` từ query string rồi hỏi
 * `CreditCardStatementService`; người dùng không chọn thì dùng kỳ đã kết thúc gần
 * nhất. Mọi công thức chu kỳ nằm trong `StatementPeriodService`.
 *
 * ---------------------------------------------------------------------------
 * MỞ TRANG KHÔNG ĐƯỢC GHI
 * ---------------------------------------------------------------------------
 * Cả `index()` (HTML) lẫn `apiIndex()` (JSON) đều đi qua các đường đọc
 * (`bundleForPeriodStart()`, `selectablePeriods()`) — chỉ đọc, KHÔNG tạo kỳ. Cùng
 * nguyên tắc đã áp cho trang Tổng quan.
 */
class StatementController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
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
            ->map(fn (UserCard $card): array => $this->presentCard($card, $today, $request))
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
            ->map(fn (UserCard $card): array => $this->presentCard($card, $today, $request))
            ->all();

        return response()->json([
            'data' => $data,
            'sort_mode' => $sortMode,
            'sort_modes' => CreditCardCardSortService::modes(),
        ]);
    }

    /**
     * Nhập / sửa sao kê của KỲ NGƯỜI DÙNG CHỌN.
     *
     * Ghi vào kỳ đã chọn nên dùng `upsertForPeriodStart()` (tạo bản ghi kỳ đó
     * nếu chưa có) — khác các đường đọc ở trên. `user_id` không bao giờ lấy từ
     * request: thẻ phải thuộc đúng user đang đăng nhập, kiểm ở `findOwned()` rồi
     * tới policy.
     */
    public function store(StoreStatementRequest $request, string $userCard): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $card = $this->cards->findOwned((int) $userCard, $userId);

        $this->authorize('create', [CreditCardStatement::class, $card]);

        // Không gửi `period_start` ⇒ kỳ mặc định (kỳ đã kết thúc gần nhất). Gửi
        // ngày không thuộc chu kỳ thẻ ⇒ service ném exception, bên dưới trả 422.
        $periodStart = $request->periodStart() ?? $this->statements->defaultPeriodStart($card);

        try {
            $statement = $this->statements->upsertForPeriodStart($card, $periodStart, $request->payload());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

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
     * Một hàng của màn Sao kê: thẻ + KỲ ĐANG CHỌN + dòng sao kê + ranh giới kỳ.
     *
     * `period_start`/`period_end` gửi cả khi CHƯA có bản ghi kỳ: người dùng cần
     * biết kỳ đang nhập là kỳ nào, kể cả lần đầu tiên (lúc đó hệ thống chưa tạo
     * kỳ nào — mở trang không được tạo).
     *
     * @return array<string, mixed>
     */
    private function presentCard(UserCard $card, CarbonImmutable $today, Request $request): array
    {
        $selection = $this->selectedPeriodStart($card, $request);

        $bundle = $this->statements->bundleForPeriodStart($card, $selection, $today);
        $period = $bundle['period'];
        $statement = $bundle['statement'];
        [$start, $end] = [$bundle['start'], $bundle['end']];
        $dueDate = $bundle['due_date'];

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
            // Kỳ đang xem — nguồn sự thật cho mọi con số dưới đây. `due_date`
            // lấy từ KỲ ĐANG CHỌN, không phải kỳ hiện tại: người dùng xem kỳ
            // nào thì thấy hạn của kỳ đó.
            'period' => [
                'period_start' => $selection,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'id' => $period === null ? null : (int) $period->id,
                'due_date' => $dueDate?->toDateString(),
                'status' => $period?->status,
                'finalized' => $finalized,
                // Tính ở server để view chỉ tô màu, không tự đếm ngày — và để
                // test kiểm được trạng thái quá hạn mà không phụ thuộc múi giờ JS.
                'due_state' => $this->dueState($dueDate, $today),
                'days_to_due' => $dueDate === null
                    ? null
                    : (int) $today->startOfDay()->diffInDays($dueDate->startOfDay(), false),
            ],
            'period_bounds' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                // Bản đã định dạng để view in ra thẳng, khỏi parse lại ở tầng
                // trình bày. Còn `start`/`end` giữ nguyên ISO cho API.
                'start_label' => $start->format('d/m/Y'),
                'end_label' => $end->format('d/m/Y'),
            ],
            // Danh sách kỳ cho dropdown — mới nhất trước.
            'periods' => $this->statements->selectablePeriods($card, $today),
            'statement' => $statement === null ? null : $this->presentStatement($statement),
        ];
    }

    /**
     * Kỳ đang chọn của MỘT thẻ.
     *
     * Mỗi thẻ một lựa chọn riêng (`period[<card id>]` trên query string): thẻ có
     * chu kỳ khác nhau nên một lựa chọn dùng chung sẽ không có nghĩa.
     *
     * Lựa chọn không hợp lệ (ngày bận, ngoài danh sách kỳ của thẻ) bị bỏ qua và
     * rơi về kỳ mặc định — không báo lỗi: đây là bộ lọc xem, chứ không phải thao tác
     * ghi tiền, và kỳ mặc định vẫn cho người dùng một màn hình hợp lệ.
     */
    private function selectedPeriodStart(UserCard $card, Request $request): string
    {
        $requested = $request->query('period');

        if (is_array($requested)) {
            $value = $requested[(string) $card->id] ?? $requested[$card->id] ?? null;

            if (is_string($value) && $value !== ''
                && $this->statements->isSelectablePeriodStart($card, $value)) {
                return $value;
            }
        }

        return $this->statements->defaultPeriodStart($card);
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
