<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreStatementRequest;
use App\Http\Requests\CreditCard\UpdateStatementPaymentRequest;
use App\Http\Requests\CreditCard\UpdateStatementRequest;
use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\CreditCardCardSortService;
use App\Services\CreditCard\CreditCardStatementService;
use App\Services\CreditCard\CreditCardUserSettingService;
use App\Services\CreditCard\UserCardService;
use Carbon\CarbonImmutable;
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
        private readonly CreditCardUserSettingService $settings,
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

        // Đọc số ngày nhắc MỘT LẦN cho cả trang, rồi truyền vào từng dòng: đọc
        // trong `presentCard()` sẽ thành N+1 truy vấn trên trang có nhiều thẻ.
        $reminderDays = $this->settings->reminderDaysFor($userId);

        $rows = $this->sort
            ->sort($sortMode, $cards, $today)
            ->map(fn (UserCard $card): array => $this->presentCard($card, $today, $request, $reminderDays))
            ->all();

        return view('credit-card.statements', [
            'rows' => $rows,
            'sortMode' => $sortMode,
            'sortModes' => CreditCardCardSortService::modes(),
            'sortAction' => route('credit-cards.statements'),
            'sortStorageKey' => 'credit-card-statements-sort',
            'today' => $today->toDateString(),
            // Số ngày nhắc là thiết lập CHUNG của user: hiển thị MỘT ô duy nhất ở
            // đầu trang, không lặp lại theo từng thẻ.
            'reminderDays' => $reminderDays,
            'reminderMin' => CreditCardUserSetting::MIN_PAYMENT_REMINDER_DAYS,
            'reminderMax' => CreditCardUserSetting::MAX_PAYMENT_REMINDER_DAYS,
            'urls' => [
                'index' => route('credit-cards.api.statements.index'),
                // Với URL có tham số, truyền `0` để `route()` chỉ đúng hình dạng
                // rồi JS thay số 0 bằng id thật. `0` là chỗ giữ, không phải dữ liệu.
                'store' => route('credit-cards.api.statements.store', ['userCard' => 0]),
                'update' => route('credit-cards.api.statements.update', ['statement' => 0]),
                // URL mẫu có `0` giữ chỗ ở CUỐI đường dẫn, nên `bind()` vẫn tra
                // `/0(?=/|$)` được. Xem `creditCardStatements.bind()`.
                'payment' => route('credit-cards.api.statements.payment.update', ['statement' => 0]),
                // Cùng việc nhưng theo KỲ — dùng khi kỳ chưa có dòng sao kê, lúc đó
                // chưa có `{statement}` để đưa vào URL. `paymentUrl()` chọn giữa hai
                // URL này theo cờ `statement_id` server gửi lên.
                'paymentPeriod' => route('credit-cards.api.statements.payment.update-period', ['userCard' => 0]),
                'destroy' => route('credit-cards.api.statements.destroy', ['statement' => 0]),
                'reminder' => route('credit-cards.api.settings.payment-reminder.update'),
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
        $reminderDays = $this->settings->reminderDaysFor($userId);

        $data = $this->sort
            ->sort($sortMode, $this->ownedCards($userId), $today)
            ->map(fn (UserCard $card): array => $this->presentCard($card, $today, $request, $reminderDays))
            ->all();

        return response()->json([
            'data' => $data,
            'sort_mode' => $sortMode,
            'sort_modes' => CreditCardCardSortService::modes(),
            'payment_reminder_days' => $reminderDays,
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
            $period = $this->statements->resolvePeriodForStart($card, $periodStart);
            $statement = $this->statements->upsert($card, $period, $request->payload());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // `store` là cả nhập lẫn sửa: nhập mới ⇒ 201, sửa dòng đã có ⇒ 200. Phân
        // biệt bằng `wasRecentlyCreated` thay vì truy vấn lại lần nữa.
        return response()->json(
            [
                'data' => $this->presentStatement($statement),
                // Gửi kèm trạng thái để client tắt lời nhắc "chưa nhập sao kê" và
                // giữ nguyên cảnh báo hạn mà không phải tải lại trang. Đặc biệt quan
                // trọng khi người dùng đã đánh dấu "đã trả" TRƯỚC rồi mới nhập số:
                // số tiền đổi, trạng thái phải giữ nguyên là `paid`.
                'payment' => $this->paymentPayload($statement, $card, $period, $request),
            ],
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

    /**
     * Đổi trạng thái thanh toán của một dòng sao kê — lưu ngay, không có nút "Lưu".
     *
     * ---------------------------------------------------------------------------
     * CHỈ NHẬN `payment_status`
     * ---------------------------------------------------------------------------
     * Số ngày nhắc không nằm ở đây nữa: đó là thiết lập chung của user, đổi qua
     * `CreditCardSettingController::update()`. Nếu endpoint này vẫn nhận số ngày
     * thì lưu một kỳ sẽ âm thầm ghi đè thiết lập chung — cùng một kiểu bug ở tầng
     * UI, chỉ khác chỗ xảy ra.
     *
     * ---------------------------------------------------------------------------
     * TRA CỨU KHÔNG LỌC OWNER, ĐỂ POLICY TRẢ 403
     * ---------------------------------------------------------------------------
     * Cùng cách làm với `update()`/`destroy()`: nếu lọc owner ngay từ truy vấn thì
     * dòng của người khác trả 404 và trông giống hàng không tồn tại — người dùng
     * không hiểu vì sao. 403 nói thẳng "không phải của bạn".
     *
     * ---------------------------------------------------------------------------
     * `findOrFail` ⇒ 404 KHI DÒNG KHÔNG TỒN TẠI
     * ---------------------------------------------------------------------------
     * Endpoint này nhắm vào dòng đã có. Kỳ CHƯA có dòng thì dùng
     * {@see updatePaymentForPeriod()} — xem docblock hàm đó.
     */
    public function updatePayment(UpdateStatementPaymentRequest $request, string $statement): JsonResponse
    {
        $model = CreditCardStatement::findOrFail((int) $statement);

        $this->authorize('updatePayment', $model);

        $card = $model->userCard;
        $period = $model->statementPeriod;

        abort_unless($card !== null && $period !== null, 404);

        $updated = $this->statements->updatePayment($model, $request->payload());

        return response()->json([
            'data' => $this->presentStatement($updated),
            'payment' => $this->paymentPayload($updated, $card, $period, $request),
        ]);
    }

    /**
     * Đổi trạng thái thanh toán theo KỲ — chạy được cả khi kỳ CHƯA có dòng sao kê.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO CẦN ĐƯỜNG NÀY
     * ---------------------------------------------------------------------------
     * Trước đây đánh dấu "đã trả" bắt buộc phải có sẵn dòng sao kê, nên kỳ chưa
     * nhập thì phải nhập → lưu → tải lại trang mới chọn được: một vòng lặp vô lý
     * với một sự thật đơn giản là "hóa đơn này đã trả hay chưa".
     *
     * Ở đây `{userCard}` + `period_start` định danh mục tiêu; service tra cặp
     * `(thẻ, kỳ)` và TẠO dòng `0/0/0` nếu chưa có. Dòng đó được đánh dấu
     * `statement_data_entered = false` nên giao diện vẫn nói rõ chưa có số liệu —
     * không có chuyện âm thầm bịa ra một bảng kê.
     *
     * ---------------------------------------------------------------------------
     * `period_start` KHÔNG THUỘC CHU KỲ THẺ ⇒ 422
     * ---------------------------------------------------------------------------
     * Cùng cách `store()` xử lý ngày rác: người dùng chỉ chọn được kỳ trong
     * dropdown, nên ngày lạ là sai sót nhập liệu chứ không phải hành động hợp lệ.
     */
    public function updatePaymentForPeriod(UpdateStatementPaymentRequest $request, string $userCard): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $card = $this->cards->findOwned((int) $userCard, $userId);

        $this->authorize('updatePaymentForPeriod', [CreditCardStatement::class, $card]);

        $periodStart = $request->periodStart() ?? $this->statements->defaultPeriodStart($card);

        try {
            // `resolvePeriodForStart()` tạo bản ghi kỳ nếu chưa có — chấp nhận được ở
            // đây vì đây là HÀNH ĐỘNG GHI của người dùng (đánh dấu đã trả), khác
            // hẳn với GET vốn phải thuần read-only.
            $period = $this->statements->resolvePeriodForStart($card, $periodStart);

            $updated = $this->statements->setPaymentStatusForPeriod(
                $card,
                $period,
                (string) ($request->payload()['payment_status'] ?? CreditCardStatement::PAYMENT_STATUS_UNPAID),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // `null` = kỳ ảo được giữ "chưa thanh toán": không có gì để ghi nên
        // không có dòng để trả về, nhưng client VẪN cần payload trạng thái để vẽ
        // lại (nếu không nó sẽ tưởng request hỏng rồi báo lỗi lên UI).
        return response()->json([
            'data' => $updated === null ? null : $this->presentStatement($updated),
            'payment' => $this->paymentPayload($updated, $card, $period, $request),
        ]);
    }

    public function destroy(Request $request, string $statement): JsonResponse
    {
        $model = CreditCardStatement::findOrFail((int) $statement);

        $this->authorize('delete', $model);

        $card = $model->userCard;
        $period = $model->statementPeriod;

        abort_unless($card !== null && $period !== null, 404);

        $this->statements->delete($card, $period);

        return response()->json([
            'deleted' => true,
            'id' => (int) $model->id,
            // Dòng vừa xoá nên kỳ quay về trạng thái ảo: 0/0/0 + chưa thanh toán.
            // Gửi kèm để client vẽ lại đúng như lúc render trang — nó không có
            // quyền tự kết luận "xoá rồi thì chưa trả" bằng logic riêng.
            'payment' => $this->paymentPayload(null, $card, $period, $request),
        ]);
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
    private function presentCard(UserCard $card, CarbonImmutable $today, Request $request, int $reminderDays): array
    {
        $selection = $this->selectedPeriodStart($card, $request);

        $bundle = $this->statements->bundleForPeriodStart($card, $selection, $today, $reminderDays);
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
                // KHÔNG có `due_state`/`days_to_due` ở đây nữa. Chúng từng do
                // `dueState()` của chính controller này tính — một state machine
                // THỨ HAI song song `paymentState()` và không hề biết
                // `payment_status`, nên kỳ ĐÃ TRẢ mà quá hạn vẫn hiện "Đến hạn …
                // · quá hạn N ngày" cạnh dấu ✓ ĐÃ THANH TOÁN. Dòng hạn nay lấy
                // `due_line`/`due_tone` trong `payment` bên dưới: một nguồn, một
                // thứ tự ưu tiên, và hai màn dùng chung.
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
            // Trạng thái thanh toán ĐÃ resolve ở server (kỳ đang chọn, hạn của chính
            // kỳ đó, số ngày nhắc chung của user). `state = no_statement` ⇒ view hiện
            // "Chưa nhập sao kê" và không vẽ ô điều khiển nào.
            'payment' => $bundle['payment'],
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
            // Trạng thái của KỲ. KHÔNG có trường nhắc ở đây: số ngày nhắc là thiết
            // lập chung của user, nằm trong payload `payment` của màn chứ không
            // lặp lại trên từng dòng.
            'payment_status' => $statement->payment_status,
        ];
    }

    /**
     * Payload trạng thái đầy đủ của một dòng sao kê, resolve lại từ server.
     *
     * Mọi response GHI (nhập/sửa sao kê, đổi trạng thái thanh toán) đều đi qua đây
     * để client vẽ lại bằng đúng số máy chủ đang giữ. Gom một chỗ vì đây là cùng
     * một câu hỏi — "kỳ này đang ở trạng thái nào" — và trả lệch nhau là lỗi hiển
     * thị, không phải lỗi mỹ thuật.
     *
     * Hạn trả lấy qua `dueDateFor()`: cùng đường dẫn với lúc render trang, nên
     * đánh dấu "đã trả" không làm đổi hạn mà cảnh báo dùng. Số ngày nhắc lấy từ
     * thiết lập chung của user, y hệt lúc render trang.
     *
     * @return array<string, mixed>
     */
    private function paymentPayload(
        ?CreditCardStatement $statement,
        UserCard $card,
        StatementPeriod $period,
        Request $request,
    ): array {
        return $this->statements->paymentState(
            $statement,
            $this->statements->dueDateFor($card, $period, $period->period_end),
            $this->settings->reminderDaysFor((int) $request->user()->id),
        );
    }
}
