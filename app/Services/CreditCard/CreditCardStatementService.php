<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\CreditCardUserSetting;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Support\CreditCard\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * CreditCardStatementService — đọc/ghi SAO KÊ THỰC TẾ của một kỳ.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CẦN NỐI RIÊNG `StatementPeriodService`
 * ---------------------------------------------------------------------------
 * `StatementPeriodService` là nguồn duy nhất của ranh giới kỳ. Service này KHÔNG
 * tự tính kỳ, chỉ hỏi nó rồi ghép dòng sao kê vào đúng cặp (thẻ, kỳ).
 *
 * ---------------------------------------------------------------------------
 * ĐỌC ≠ GHI — KHÔNG BAO GIỜ TẠO KỲ KHI CHỈ ĐỌC
 * ---------------------------------------------------------------------------
 * `StatementPeriodService::currentPeriod()` TẠO bản ghi kỳ nếu chưa có; `findForDate()`
 * thì không. Trang Sao kê là trang NHẬP LIỆU, nên mọi đường đọc ở đây dùng
 * `findForDate()`: mở trang không được sinh bản ghi (cùng nguyên tắc đã có ở
 * trang Tổng quan — xem `CreditCardModuleTest::index_does_not_create_statement_periods`).
 */
class CreditCardStatementService
{
    /**
     * Còn bao nhiêu ngày thì dòng hạn chuyển sang màu hổ phách và ghi "còn N ngày".
     *
     * Thuần về TRÌNH BÀY, tách khỏi mốc nhắc của user (`reminder_days`): người dùng
     * đổi số ngày nhắc không được làm đổi câu chữ của dòng hạn.
     */
    private const DUE_SOON_DAYS = 3;

    public function __construct(private readonly StatementPeriodService $periods)
    {
    }

    /**
     * Kỳ hiện tại của thẻ NẾU kỳ đã tồn tại; không tạo mới.
     */
    public function currentPeriodFor(UserCard $card, ?CarbonInterface $today = null): ?StatementPeriod
    {
        return $this->periods->findForDate($card, $today ?? CarbonImmutable::now());
    }

    /**
     * Dòng sao kê của kỳ hiện tại, hoặc null. Chỉ đọc.
     */
    public function currentFor(UserCard $card, ?CarbonInterface $today = null): ?CreditCardStatement
    {
        $period = $this->currentPeriodFor($card, $today);

        return $period === null ? null : $this->findOrNull($card, $period);
    }

    /**
     * Dòng sao kê của một kỳ cụ thể, hoặc null. Chỉ đọc.
     */
    public function findOrNull(UserCard $card, StatementPeriod $period): ?CreditCardStatement
    {
        return CreditCardStatement::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->first();
    }

    /**
     * Cặp [kỳ hiện tại, dòng sao kê] để view dùng — gom vào đây để controller
     * không phải tự ghép, và để mọi màn hình đều đi qua CÙNG một đường đọc.
     *
     * @return array{0: ?StatementPeriod, 1: ?CreditCardStatement}
     */
    public function currentBundleFor(UserCard $card, ?CarbonInterface $today = null): array
    {
        $period = $this->currentPeriodFor($card, $today);

        return [$period, $period === null ? null : $this->findOrNull($card, $period)];
    }

    /**
     * `period_start` MẶC ĐỊNH khi mở form "Nhập sao kê": kỳ đã kết thúc gần nhất.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO MẶC ĐỊNH LÀ KỲ ĐÃ KẾT THÚC, KHÔNG PHẢI KỲ HIỆN TẠI
     * ---------------------------------------------------------------------------
     * Người dùng mở trang để nhập bảng kê NGÂN HÀNG ĐÃ PHÁT HÀNH — bảng kê đó ứng
     * với kỳ vừa chốt, không phải kỳ đang mở. Mặc định kỳ hiện tại buộc người
     * dùng nhập số của kỳ cũ vào kỳ mới: sai dữ liệu, sai ngày đến hạn, và
     * "còn phải trả" sai.
     *
     * Kỳ đã kết thúc gần nhất luôn tồn tại (lùi một kỳ từ kỳ hiện tại) nên hàm
     * này không bao giờ null — không cần fallback.
     */
    public function defaultPeriodStart(UserCard $card, ?CarbonInterface $today = null): string
    {
        [$start] = $this->periods->completedBoundaries($card, $today);

        return $start->toDateString();
    }

    /**
     * `period_start` người dùng gửi lên có hợp lệ với dropdown không.
     *
     * Chỉ SO SÁNH với ranh giới service suy ra, không tự tính lại chu kỳ — nếu
     * hai nơi cùng suy luận kỳ thì chỗ sai là chỗ không ai nhìn thấy.
     */
    public function isSelectablePeriodStart(UserCard $card, string $periodStart, ?CarbonInterface $today = null): bool
    {
        // So sánh CHUỖI ngày, không parse: `selectableBoundaries()` trả về ISO
        // `Y-m-d`, nên trùng chuỗi đã đủ hẹp — ngày sai định dạng không thể trùng
        // được chuỗi nào, và không cần thêm một nhánh parse để bắt lỗi đó.
        foreach ($this->periods->selectableBoundaries($card, $today) as [$start]) {
            if ($start->toDateString() === $periodStart) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bundle của một kỳ CHỈ ĐỌC, theo `period_start`.
     *
     * Kỳ chưa có bản ghi vẫn trả về ranh giới đúng (người dùng cần biết mình
     * đang nhìn kỳ nào) nhưng `period` là null và `statement` là null. Chỉ khi
     * LƯU thì `StatementPeriodService::resolvePeriodStart()` mới tạo bản ghi kỳ.
     *
     * `due_date` trả về cả khi kỳ chưa có bản ghi: hạn thanh toán suy ra được
     * từ `paymentDueDateFor()` (thuần toán), và người dùng cần biết kỳ mình đang
     * xem đến hạn khi nào — kể cả trước khi nhập. Lưu lại cùng khi lưu sao kê
     * cho ra CÙNG ngày, vì cùng một công thức.
     *
     * @param  int  $reminderDays  số ngày nhắc trước của USER (thiết lập chung)
     * @return array{period: ?StatementPeriod, statement: ?CreditCardStatement, start: CarbonImmutable, end: CarbonImmutable, due_date: ?CarbonImmutable, payment: array<string, mixed>}
     */
    public function bundleForPeriodStart(UserCard $card, string $periodStart, ?CarbonInterface $today = null, int $reminderDays = CreditCardUserSetting::DEFAULT_PAYMENT_REMINDER_DAYS): array
    {
        $date = CarbonImmutable::createFromFormat('Y-m-d', $periodStart)->startOfDay();

        [$start, $end] = $this->periods->boundariesForPeriodStart($card, $date);
        $period = $this->periods->findByBoundaries($card, $start, $end);
        $statement = $period === null ? null : $this->findOrNull($card, $period);
        $dueDate = $this->dueDateFor($card, $period, $end);

        return [
            'period' => $period,
            'statement' => $statement,
            'start' => $start,
            'end' => $end,
            'due_date' => $dueDate,
            'payment' => $this->paymentState($statement, $dueDate, $reminderDays, $today),
        ];
    }

    /**
     * Danh sách kỳ cho dropdown, mới nhất trước — CHỈ ĐỌC.
     *
     * Ranh giới lấy từ `StatementPeriodService::selectableBoundaries()`; bản ghi
     * kỳ và dòng sao kê được nạp SẴN cho cả danh sách để không tạy N+1 khi
     * người dùng mở trang có nhiều thẻ.
     *
     * `period_id` có thể null: kỳ hợp lệ mà chưa có bản ghi. Đó là bình thường —
     * bản ghi kỳ chỉ sinh khi có việc cần ghi.
     *
     * @return list<array<string, mixed>>
     */
    public function selectablePeriods(UserCard $card, ?CarbonInterface $today = null, int $limit = StatementPeriodService::SELECTABLE_LIMIT): array
    {
        $bounds = $this->periods->selectableBoundaries($card, $today, $limit);

        $periods = StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->get()
            ->keyBy(fn (StatementPeriod $period): string => $period->period_start->toDateString());

        $statements = CreditCardStatement::query()
            ->where('user_card_id', $card->id)
            ->get()
            ->keyBy('statement_period_id');

        [$currentStart] = $this->periods->currentBoundaries($card, $today);

        $options = [];

        foreach ($bounds as [$start, $end]) {
            $period = $periods->get($start->toDateString());
            $statement = $period === null ? null : $statements->get($period->id);

            $options[] = [
                'period_start' => $start->toDateString(),
                'start_label' => $start->format('d/m/Y'),
                'end_label' => $end->format('d/m/Y'),
                'period_id' => $period === null ? null : (int) $period->id,
                'has_statement' => $statement !== null,
                // Cờ "kỳ hiện tại" so với RANH GIỚI kỳ hiện tại, không so với bản
                // ghi kỳ: thẻ chưa có bản ghi nào thì so bản ghi sẽ cho cờ sai.
                'is_current' => $start->toDateString() === $currentStart->toDateString(),
            ];
        }

        return $options;
    }

    /**
     * Bundle kỳ đã kết thúc gần nhất của một thẻ — cho TỔNG QUAN hiển thị.
     *
     * Không trả về `period` khi kỳ đó chưa có bản ghi: Tổng quan cần câu trả lời
     * "kỳ nào đã có sao kê", mà kỳ chưa ghi thì chưa có gì để hiển thị.
     *
     * @param  int  $reminderDays  số ngày nhắc trước của USER (thiết lập chung)
     * @return array{period: ?StatementPeriod, statement: ?CreditCardStatement, start: CarbonImmutable, end: CarbonImmutable, due_date: ?CarbonImmutable, payment: array<string, mixed>}
     */
    public function latestCompletedBundleFor(UserCard $card, ?CarbonInterface $today = null, int $reminderDays = CreditCardUserSetting::DEFAULT_PAYMENT_REMINDER_DAYS): array
    {
        [$start, $end] = $this->periods->completedBoundaries($card, $today);
        $period = $this->periods->findByBoundaries($card, $start, $end);
        $statement = $period === null ? null : $this->findOrNull($card, $period);
        $dueDate = $this->dueDateFor($card, $period, $end);

        return [
            'period' => $period,
            'statement' => $statement,
            'start' => $start,
            'end' => $end,
            'due_date' => $dueDate,
            // Cùng bộ resolve với màn Sao kê ⇒ Tổng quan không thể cảnh báo lệch
            // với màn nhập liệu cho cùng một kỳ.
            'payment' => $this->paymentState($statement, $dueDate, $reminderDays, $today),
        ];
    }

/**
     * Hạn thanh toán của kỳ: ưu tiên giá trị ĐÃ LƯU trên kỳ, không có thì suy ra.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO CẦN MỘT HÀM CHO VIỆC NÀY
     * ---------------------------------------------------------------------------
     * Kỳ chưa có bản ghi thì cột `payment_due_date` còn null, nhưng người dùng vẫn cần
     * biết kỳ đó đến hạn khi nào — cùng công thức suy ra được. Viết biểu thức "lưu
     * hoặc suy ra" ở ba nơi thì sớm muộn một chỗ quên phần "hoặc suy ra", và cảnh báo
     * đến hạn sẽ im lặng đúng kỳ chưa có bản ghi — tức đúng kỳ người dùng cần nó nhất.
     */
    public function dueDateFor(UserCard $card, ?StatementPeriod $period, CarbonInterface $end): ?CarbonImmutable
    {
        // Ép về `CarbonImmutable`: cast `'date'` của Eloquent trả về
        // `Illuminate\Support\Carbon`, còn ranh giới kỳ là `CarbonImmutable`. Trả lẫn
        // lộn hai loại thì chỗ gọi phải tự đoán mình nhận loại nào, và chỗ đoán sai là
        // `TypeError` lúc chạy chứ không phải lúc test.
        $due = $period?->payment_due_date ?? $this->periods->paymentDueDateFor($card, $end);

        return $due === null ? null : CarbonImmutable::instance($due);
    }

/**
     * Trạng thái thanh toán + nhắc của MỘT dòng sao kê — nơi DUY NHẤT quyết định
     * "đến hạn chưa".
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO MỘT CHỖ, KHÔNG PHẢI MỖI BLADE TỰ TÍNH
     * ---------------------------------------------------------------------------
     * Màn Sao kê và Tổng quan đều phải hiện cảnh báo. Nếu hai view tự so sánh ngày
     * thì chúng sẽ trôi khỏi nhau, và người dùng thấy "đã trả" ở một màn,
     * "đến hạn" ở màn kia cho CÙNG một kỳ. Ở đây so sánh ngày đúng một lần rồi
     * trả về cả trạng thái lẫn câu chữ; Blade chỉ in ra.
     *
     * ---------------------------------------------------------------------------
     * `$dueDate` LÀ THAM SỐ, KHÔNG SUY RA Ở ĐÂY
     * ---------------------------------------------------------------------------
     * Người gọi truyền vào hạn của ĐÚNG kỳ đang xem (thường là kỳ đã kết thúc gần
     * nhất). Service không tự tìm kỳ hiện tại — nếu tự tìm thì khi người dùng đang
     * xem kỳ LỊCH SỬ, cảnh báo sẽ nhảy sang kỳ mới: đúng cái lỗi nguy hiểm nhất,
     * báo động nhầm kỳ.
     *
     * ---------------------------------------------------------------------------
     * `$reminderDays` LÀ THAM SỐ VÌ NÓ THUỘC USER, KHÔNG THUỘC KỲ
     * ---------------------------------------------------------------------------
     * Số ngày nhắc là thiết lập chung cho mọi thẻ và mọi kỳ
     * (`credit_card_user_settings`). Service này không tự truy vấn bảng thiết lập vì
     * nó gọi `paymentState()` MỘT LẦN cho mỗi thẻ — tự đọc ở đây sẽ thành N+1 truy
     * vấn trên một trang có nhiều thẻ. Người gọi đọc đúng một lần rồi truyền vào.
     *
     * ---------------------------------------------------------------------------
     * THỨ TỰ ƯU TIÊN
     * ---------------------------------------------------------------------------
     * `paid` → KHÔNG BAO GIỜ cảnh báo. Sau đó `no_due` → `overdue` → `due_today` →
     * `reminding`. Quá hạn đứng trên nhắc trước vì nó là sự thật đã xảy ra; nhắc
     * trước chỉ là dự báo. `upcoming` (chưa tới ngưỡng) KHÔNG cảnh báo — người dùng
     * mới đổi số ngày không nên bị dồn cảnh báo ngay lập tức khi hạn còn xa.
     *
     * ---------------------------------------------------------------------------
     * `paid` OVERRIDE MỌI CẢNH BÁO — KỂ CẢ DÒNG HẠN
     * ---------------------------------------------------------------------------
     * Đã trả nghĩa là nghĩa vụ đã xong, nên `due_line` cũng phải theo: nó chỉ còn
     * câu "Hạn thanh toán kỳ này: …", KHÔNG kèm "· quá hạn N ngày". Trước đây dòng
     * hạn do `StatementController::dueState()` tính riêng — một state machine THỨ
     * HAI không hề biết `payment_status` — nên kỳ đã trả mà quá hạn vẫn hiện
     * "Đến hạn … · quá hạn N ngày" cạnh dấu ✓ ĐÃ THANH TOÁN. Mọi quyết định hiển
     * thị giờ nằm ở đây, đúng một chỗ, và cả hai màn dùng chung.
     *
     * ---------------------------------------------------------------------------
     * `$statement = null` LÀ KỲ ẢO, KHÔNG PHẢI "KỲ KHÔNG CÓ TRẠNG THÁI"
     * ---------------------------------------------------------------------------
     * Trạng thái thanh toán KHÔNG phụ thuộc việc đã nhập số liệu hay chưa: mỗi kỳ
     * sao kê luôn có một trạng thái logic, và khi chưa có dòng trong DB thì đó là
     * `unpaid` với `0/0/0`. Trước đây hàm này trả `no_statement` và view ẩn hẳn ô
     * điều khiển — tức phải nhập sao kê, lưu, tải lại trang mới đánh dấu được "đã
     * trả", vô lý vì "hóa đơn này đã trả" là một sự thật về KỲ, không phụ thuộc đã
     * nhập số hay chưa.
     *
     * Vì vậy `null` được đọc như `unpaid` + 0/0/0 (xem {@see statementState()}) và
     * chạy tiếp qua đúng nhánh nhắc/quá hạn bình thường. Việc ĐỌC trang này vẫn
     * thuần read-only: hàm không gọi `save()`, dòng DB chỉ do `setPaymentStatusForPeriod()`
     * hay `upsert()` tạo ra — tức là chỉ khi người dùng thao tác.
     *
     * @param  CreditCardStatement|null  $statement  null = kỳ ảo (chưa nhập sao kê)
     * @param  int  $reminderDays  số ngày nhắc trước của user, luôn trong 1..10
     * @return array<string, mixed>
     */
    public function paymentState(
        ?CreditCardStatement $statement,
        ?CarbonInterface $dueDate,
        int $reminderDays = CreditCardUserSetting::DEFAULT_PAYMENT_REMINDER_DAYS,
        ?CarbonInterface $today = null,
    ): array {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::now())->startOfDay();
        $due = $dueDate === null
            ? null
            : CarbonImmutable::instance($dueDate)->startOfDay();

        // Nguồn duy nhất của trạng thái: `?? unpaid` cho kỳ ảo. KHÔNG suy từ việc
        // dòng sao kê có tồn tại hay không — đó chính là bug kiến trúc làm ô điều
        // khiển biến mất, và làm "chưa nhập" khác "chưa trả".
        $status = $statement?->payment_status ?? CreditCardStatement::PAYMENT_STATUS_UNPAID;
        $isPaid = $status === CreditCardStatement::PAYMENT_STATUS_PAID;

        // `paid` QUYẾT ĐỊNH TRƯỚC MỌI THỨ KHÁC. Câu hỏi "quá hạn mấy ngày" chỉ
        // có nghĩa khi khoản nợ CHƯA được trả; trả rồi thì hạn đó chỉ còn là
        // ngày đã lịch sử. Nên ở đây ta không tính `days_to_due` và không dựng
        // cửa sổ nhắc cho kỳ đã trả — cùng một lý do mà `dueDisplay()`/`paymentAlert()`
        // trả về `settled`/`null`. Tính trước rồi mới quên dùng là cách làm nửa
        // vời: `days_to_due` vẫn lọt ra payload và bất cứ tầng nào đọc nó cũng
        // thấy "quá hạn" cho một kỳ đã trả.
        if ($isPaid) {
            return [
                'status' => $status,
                'status_label' => CreditCardStatement::PAYMENT_STATUS_LABELS[$status]
                    ?? CreditCardStatement::PAYMENT_STATUS_LABELS[CreditCardStatement::PAYMENT_STATUS_PAID],
                'is_paid' => true,
                ...$this->statementState($statement),
                'reminder_days' => $reminderDays,
                // Không cửa sổ nhắc cho kỳ đã trả.
                'reminder_start_date' => null,
                'reminder_start_label' => null,
                // Ngày hạn VẪN phải có: người dùng cần biết hạn của kỳ này là khi
                // nào, chỉ là không còn ý nghĩa "còn bao lâu nữa" nữa.
                'due_date' => $due?->toDateString(),
                'payment_due_date' => $due?->toDateString(),
                'due_label' => $due?->format('d/m/Y'),
                // Không quá hạn ⇒ không có số đếm ngược.
                'days_to_due' => null,
                'state' => 'paid',
                ...$this->dueDisplay('paid', $due, null),
                'alert' => null,
            ];
        }

        $reminderStart = $this->paymentReminderStartDate($due, $reminderDays);
        $daysToDue = $due === null ? null : (int) $today->diffInDays($due, false);

        // Nhắc chỉ hiệu lực khi hôm nay đã tới ngưỡng = hạn trả − số ngày nhắc.
        // Trước ngưỡng thì khoản nợ còn xa và cảnh báo chỉ là nhiễu.
        $reminderReached = $reminderStart !== null && ! $today->lessThan($reminderStart);

        $state = match (true) {
            $due === null => 'no_due',
            $daysToDue < 0 => 'overdue',
            $daysToDue === 0 => 'due_today',
            $reminderReached => 'reminding',
            default => 'upcoming',
        };

        return [
            'status' => $status,
            'status_label' => CreditCardStatement::PAYMENT_STATUS_LABELS[$status]
                ?? CreditCardStatement::PAYMENT_STATUS_LABELS[CreditCardStatement::PAYMENT_STATUS_UNPAID],
            'is_paid' => $isPaid,
            ...$this->statementState($statement),
            // Số ngày nhắc của USER, không phải của kỳ này — đưa vào payload để view
            // in ra được ngữ cảnh mà không phải truy vấn thiết lập lần nữa.
            'reminder_days' => $reminderDays,
            'reminder_start_date' => $reminderStart?->toDateString(),
            'reminder_start_label' => $reminderStart?->format('d/m/Y'),
            'due_date' => $due?->toDateString(),
            // Cùng giá trị `due_date`, đúng tên hợp đồng của client. Hai khoá cùng
            // tính từ MỘT biến ở đây — client đọc khoá nào cũng được, và không thể
            // lệch với nhau vì không có chỗ nào tính lần thứ hai.
            'payment_due_date' => $due?->toDateString(),
            'due_label' => $due?->format('d/m/Y'),
            'days_to_due' => $daysToDue,
            'state' => $state,
            // DÒNG HẠN — cùng nguồn với `alert`, cùng một thứ tự ưu tiên: `paid`
            // không bao giờ mang theo "quá hạn N ngày". `line` và `tone` do một hàm
            // quyết nên không thể lệch nhau.
            ...$this->dueDisplay($state, $due, $daysToDue),
            // Đường duy nhất quyết "có cảnh báo to" hay không. `paid` không bao giờ
            // có alert; `upcoming` (chưa tới ngưỡng) cũng không.
            'alert' => $this->paymentAlert($state, $due, $daysToDue),
        ];
    }

    /**
     * Trạng thái SAO KÊ của kỳ đang xem, kể cả khi kỳ đó chưa có dòng trong DB.
     *
     * ---------------------------------------------------------------------------
     * KỲ ẢO: CÓ ĐỦ TRẠNG THÁI, KHÔNG CÓ DÒNG
     * ---------------------------------------------------------------------------
     * `null` ⇒ `payment_status = unpaid` và `0/0/0`. Hàm này CHỈ đọc, không
     * `save()`: mở trang không được tạo dòng sao kê (xem `setPaymentStatusForPeriod()`
     * cho đường ghi duy nhất khi người dùng chủ động đánh dấu).
     *
     * ---------------------------------------------------------------------------
     * `statement_data_entered` TÁCH "0 VÌ CHƯA NHẬP" KHỎI "0 VÌ ĐÃ NHẬP"
     * ---------------------------------------------------------------------------
     * Dòng `0/0/0` mà người dùng chỉ tạo ra bằng cách đánh dấu "đã thanh toán" là
     * dòng CÓ THẬT nhưng CHƯA CÓ SỐ LIỆU. Nếu coi `0` là bằng chứng đã nhập thì
     * giao diện sẽ tắt mất lời nhắc "chưa nhập sao kê thực tế" và đổi nút thành
     * "Sửa", khiến người dùng tin rằng số liệu đã được ghi.
     *
     * Cố ý SUY RA từ số tiền thay vì thêm cột `data_entered`: một cột nữa là một
     * nguồn sự thật phải đồng bộ thủ công, còn `0/0/0` vốn đã nói đủ ý. Đánh đổi:
     * người dùng nhập thật sự `0/0` vẫn thấy lời nhắc — chấp nhận được, vì nút
     * nhập/sửa luôn hiện và số tiền hiển thị vẫn đúng.
     *
     * @return array{
     *     statement_exists: bool, statement_data_entered: bool, statement_id: ?int,
     *     actual_spend: string, actual_reward: string, closing_balance: string,
     *     payment_status: string, payment_status_label: string
     * }
     */
    private function statementState(?CreditCardStatement $statement): array
    {
        $status = $statement?->payment_status ?? CreditCardStatement::PAYMENT_STATUS_UNPAID;

        // Số tiền đọc bằng công thức của model, không đọc thẳng cột: đây đúng là số
        // server sẽ ghi, nên client không thể hiển thị lệch với DB.
        $spend = $statement === null ? '0.00' : Decimal::money($statement->actual_spend);
        $reward = $statement === null ? '0.00' : Decimal::money($statement->actual_reward);

        return [
            'statement_exists' => $statement !== null,
            'statement_data_entered' => $statement !== null
                && (Decimal::compare($spend, '0') !== 0 || Decimal::compare($reward, '0') !== 0),
            // null ⇒ chưa có dòng: client dùng cờ này để biết gọi endpoint theo KỲ
            // (tự tạo dòng) hay theo dòng (chỉ đổi trạng thái).
            'statement_id' => $statement === null ? null : (int) $statement->id,
            'actual_spend' => $spend,
            'actual_reward' => $reward,
            'closing_balance' => $statement === null ? '0.00' : Decimal::subtract($spend, $reward),
            // Lặp lại `status`/`status_label` dưới tên đầy đủ cho hợp đồng client.
            // Cùng biến `$status`, cùng một lần tính ở trên.
            'payment_status' => $status,
            'payment_status_label' => CreditCardStatement::PAYMENT_STATUS_LABELS[$status]
                ?? CreditCardStatement::PAYMENT_STATUS_LABELS[CreditCardStatement::PAYMENT_STATUS_UNPAID],
        ];
    }

    /**
     * Câu hạn + màu của dòng hạn, quyết trong MỘT chỗ.
     *
     * Trả SẴN CÂU CHỮ để Blade không ghép chuỗi và không tự so sánh ngày — cùng
     * nguyên tắc với `paymentAlert()`.
     *
     * ---------------------------------------------------------------------------
     * `paid` OVERRIDE, VÀ NÓ PHẢI CHẶN LUÔN DÒNG HẠN
     * ---------------------------------------------------------------------------
     * Đã trả nghĩa là nghĩa vụ đã xong, nên dòng hạn chỉ còn câu "Hạn thanh toán kỳ
     * này: …" — KHÔNG kèm "· quá hạn N ngày" và KHÔNG tô đỏ. Trước đây dòng hạn do
     * `StatementController::dueState()` tính riêng: một state machine THỨ HAI
     * không hề biết `payment_status`, nên kỳ đã trả mà quá hạn vẫn hiện "Đến hạn
     * … · quá hạn N ngày" ngay cạnh dấu ✓ ĐÃ THANH TOÁN. Bỏ hẳn state machine thứ
     * hai, mọi quyết định hiển thị giờ nằm ở đây và cả hai màn dùng chung.
     *
     * `soon` là mốc TRÌNH BÀY (3 ngày), tách khỏi `reminding` (mốc nhắc của user,
     * quyết cảnh báo) — gộp làm một thì đổi số ngày nhắc sẽ vô tình đổi luôn câu
     * chữ của dòng hạn.
     *
     * @return array{due_line: ?string, due_tone: string}
     */
    private function dueDisplay(string $state, ?CarbonImmutable $due, ?int $daysToDue): array
    {
        if ($due === null) {
            return ['due_line' => null, 'due_tone' => 'none'];
        }

        $label = $due->format('d/m/Y');
        $days = $daysToDue ?? 0;

        if ($state === 'paid') {
            return [
                'due_line' => sprintf('Hạn thanh toán kỳ này: %s', $label),
                'due_tone' => 'settled',
            ];
        }

        return match (true) {
            $days < 0 => [
                'due_line' => sprintf('Đến hạn %s · quá hạn %d ngày', $label, abs($days)),
                'due_tone' => 'danger',
            ],
            $days === 0 => [
                'due_line' => sprintf('Đến hạn %s · hôm nay', $label),
                'due_tone' => 'danger',
            ],
            $days <= self::DUE_SOON_DAYS => [
                'due_line' => sprintf('Đến hạn %s · còn %d ngày', $label, $days),
                'due_tone' => 'warning',
            ],
            default => ['due_line' => sprintf('Đến hạn %s', $label), 'due_tone' => 'neutral'],
        };
    }
public function paymentReminderStartDate(?CarbonInterface $dueDate, int $days): ?CarbonImmutable
    {
        if ($dueDate === null || $days <= 0) {
            return null;
        }

        return CarbonImmutable::instance($dueDate)->startOfDay()->subDays($days);
    }

    /**
     * Cảnh báo to cho trạng thái hiện tại, hoặc null khi không cảnh báo.
     *
     * Trả SẴN CÂU CHỮ để Blade không phải ghép chuỗi: ghép ở view là chỗ dễ lệch
     * giữa hai màn, mà "ĐẾN HẠN" chính là thứ người dùng nhìn để quyết định có
     * trả hay không.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO `due_today` CŨNG ĐỎ, KHÔNG PHẢI VÀNG
     * ---------------------------------------------------------------------------
     * Hạn trả là NGÀY CUỐI CÙNG để trả. Đến đúng ngày đó mà chưa trả là hạn đã tới,
     * và cùng cảnh báo đỏ với quá hạn — chỉ khác câu chữ. Để vàng thì người dùng
     * đọc là "còn có thời gian" rồi trả sớm hơn một ngày, tức chính câu cảnh báo
     * đẩy họ sang trạng thái xấu hơn.
     *
     * @return array{tone: string, title: string, detail: ?string}|null
     */
    private function paymentAlert(string $state, ?CarbonImmutable $due, ?int $daysToDue): ?array
    {
        $dueLabel = $due?->format('d/m/Y');

        return match ($state) {
            'overdue' => [
                'tone' => 'danger',
                'title' => 'ĐÃ QUÁ HẠN THANH TOÁN',
                'detail' => sprintf(
                    'Hạn thanh toán: %s · Quá hạn %d ngày',
                    $dueLabel,
                    abs($daysToDue ?? 0),
                ),
            ],
            'due_today' => [
                'tone' => 'danger',
                'title' => 'HẠN THANH TOÁN NGÀY CUỐI CÙNG',
                'detail' => sprintf('Hạn thanh toán: %s', $dueLabel),
            ],
            'reminding' => [
                'tone' => 'warning',
                'title' => 'SẮP ĐẾN HẠN THANH TOÁN',
                'detail' => sprintf('Hạn thanh toán: %s · Còn %d ngày', $dueLabel, $daysToDue ?? 0),
            ],
            default => null,
        };
    }

    /**
     * Ghi trạng thái thanh toán lên một dòng sao kê ĐÃ CÓ — cập nhật thuần.
     *
     * Chỉ là nhánh "dòng đã có" của {@see setPaymentStatusForPeriod()}, tách riêng
     * vì nó chỉ `save()` một dòng đang tồn tại: đường ghi thấp nhất, không tra cứu,
     * không tạo. Việc tìm/tạo thuộc về hàm kia để quy tắc "tạo khi nào" chỉ có
     * một chỗ.
     *
     * ---------------------------------------------------------------------------
     * CHỈ GHI `payment_status` — KHÔNG CÒN CỘT NHẮC
     * ---------------------------------------------------------------------------
     * Trước đây hàm này ghi kèm `payment_reminder_enabled` +
     * `payment_reminder_days`. Số ngày nhắc đã chuyển lên thiết lập chung của user
     * (`credit_card_user_settings`), nên ở đây chỉ còn trạng thái của KỲ. Ghi thêm
     * hai cột đó ở đây sẽ cho phép giao diện ghi đè thiết lập chung khi lưu kỳ —
     * đúng cái lỗi mà việc tách request/service sinh ra để chặn.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePayment(CreditCardStatement $statement, array $data): CreditCardStatement
    {
        $statement->forceFill([
            'payment_status' => $data['payment_status'] ?? CreditCardStatement::PAYMENT_STATUS_UNPAID,
        ])->save();

        return $statement->refresh();
    }

    /**
     * Đặt trạng thái thanh toán cho một cặp (thẻ, kỳ) — tạo dòng nếu chưa có.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO ĐƯỢC TẠO DÒNG SAO KÊ KHI CHƯA CÓ
     * ---------------------------------------------------------------------------
     * Trước đây đổi trạng thái bắt buộc phải có dòng sẵn, nên kỳ chưa nhập sao kê thì
     * không đánh dấu "đã trả" được: phải nhập → lưu → tải lại trang → mới chọn
     * được. Điều đó sai về nghiệp vụ — "hóa đơn tháng này đã trả" là một sự thật về
     * KỲ, không phụ thuộc người dùng đã nhập số liệu chưa. Giờ chọn "Đã thanh toán"
     * tự sinh dòng `0/0/0` mang trạng thái đó.
     *
     * Dòng sinh ra là `0/0/0` và được đánh dấu bằng
     * `statement_data_entered = false` (xem {@see statementState()}), nên giao diện
     * vẫn nói rõ là CHƯA NHẬP số liệu — không lừa người dùng rằng đã có sao kê.
     *
     * ---------------------------------------------------------------------------
     * CỐ Ý KHÔNG CHẶN KỲ ĐÃ CHỐT
     * ---------------------------------------------------------------------------
     * `upsert()` chặn kỳ đã chốt vì SỐ TIỀN của kỳ đó là bản ghi lịch sử. Ở đây
     * chỉ ghi TRẠNG THÁI, và trả hóa đơn xảy ra *sau* khi kỳ đã đóng — đúng cái kỳ
     * `CreditCardStatementPolicy::updatePayment()` cố ý mở. Chặn ở đây sẽ khiến
     * kỳ đã chốt mãi không tắt được cảnh báo.
     *
     * ---------------------------------------------------------------------------
     * SỐ TIỀN KHÔNG ĐỔI Ở ĐÂY
     * ---------------------------------------------------------------------------
     * Đánh dấu "đã trả" chỉ ghi `payment_status`. Đã có dòng thì `actual_*` và
     * `closing_balance` giữ nguyên; chưa có thì tạo `0/0/0`. Nhờ vậy thao tác này
     * không tiện tay làm lệch tiền — và khi user nhập số liệu SAU đó,
     * {@see upsert()} cập nhật tiền mà KHÔNG đụng `payment_status`.
     *
     * ---------------------------------------------------------------------------
     * KHÔNG TẠO TRÙNG
     * ---------------------------------------------------------------------------
* UNIQUE `(user_card_id, statement_period_id)` là chốt chặn cuối; tra cứu và
     * tạo nằm trong một transaction để hai request đồng thời không cùng tạo.
     *
     * ---------------------------------------------------------------------------
     * KỲ ẢO + "CHƯA THANH TOÁN" ⇒ KHÔNG GHI
     * ---------------------------------------------------------------------------
     * Mặc định của một kỳ chưa có dòng ĐÃ LÀ "chưa thanh toán". Giữ nguyên mặc
     * định không phải hành động ghi: nếu tạo dòng `0/0/0` ở đây thì chỉ cần mở
     * trang là DB sinh dòng bằng 0 — rồi lần đọc sau phải phân biệt "0 vì chưa
     * nhập" với "0 vì đã nhập", đúng cái mớ lẫn bài toán này dẹp. Chỉ `paid` mới
     * là ý định của người dùng và mới đáng ghi.
     *
     * @return CreditCardStatement|null `null` khi kỳ ảo được giữ "chưa thanh toán".
     */
    public function setPaymentStatusForPeriod(
        UserCard $card,
        StatementPeriod $period,
        string $status,
    ): ?CreditCardStatement {
        // Chặn ghép kỳ của thẻ khác: `(thẻ, kỳ)` nhận id từ URL/body nên đây là
        // lớp phòng thủ CUỐI, sau policy. Cùng nguyên tắc với `upsert()`.
        if ((int) $period->user_card_id !== (int) $card->id) {
            throw new \InvalidArgumentException('Kỳ sao kê không thuộc thẻ này.');
        }

        // Kỳ chưa có dòng + "chưa thanh toán" ⇒ không có gì để ghi. Nếu tạo dòng
        // 0/0/0 ở đây thì chỉ cần MỞ trang là DB sinh dòng bằng 0, đúng cái bẫy
        // mà bài toán này chặn: "0" thành bản ghi thật rồi lại được đọc như đã
        // nhập. Chỉ `paid` mới là ý định của người dùng cần lưu.
        if ($this->findOrNull($card, $period) === null
            && $status !== CreditCardStatement::PAYMENT_STATUS_PAID) {
            return null;
        }

        return DB::connection('creditcard')->transaction(function () use ($card, $period, $status): CreditCardStatement {
            $statement = $this->findOrNull($card, $period);

            if ($statement !== null) {
                return $this->updatePayment($statement, ['payment_status' => $status]);
            }

            // Dòng mới: 0/0/0 + trạng thái. `closing_balance` NOT NULL nên phải tính
            // TRƯỚC `save()` — tạo xong tính sau sẽ phải UPDATE lần hai.
            $statement = (new CreditCardStatement([
                'user_card_id' => $card->id,
                'statement_period_id' => $period->id,
                'actual_spend' => '0.00',
                'actual_reward' => '0.00',
                'payment_status' => $status,
            ]))->recalculateClosingBalance();

            $statement->save();

            return $statement->refresh();
        });
    }

    /**
     * Ghi sao kê cho một cặp (thẻ, kỳ) — tạo nếu chưa có, sửa nếu đã có.
     *
     * `closing_balance` KHÔNG đọc từ `$data`: nó luôn tính lại trong model từ
     * `actual_spend - actual_reward`. Client gửi kèm cũng bị bỏ qua.
     *
     * @param  array<string, mixed>  $data  chỉ cần `actual_spend` + `actual_reward`
     */
    public function upsert(UserCard $card, StatementPeriod $period, array $data): CreditCardStatement
    {
        // Chặn ghép kỳ của thẻ khác: `update`/`destroy` nhận id từ URL nên đây là
        // lớp phòng thủ CUỐI, sau policy.
        if ((int) $period->user_card_id !== (int) $card->id) {
            throw new \InvalidArgumentException('Kỳ sao kê không thuộc thẻ này.');
        }

        // Kỳ đã chốt là bản ghi lịch sử ⇒ không sửa được. Cùng nguyên tắc với
        // `TransactionPolicy` và `StatementPeriodService::assignTransactionToPeriod()`.
        if ($period->isFinalized()) {
            throw new \LogicException('Không thể sửa sao kê của kỳ đã chốt.');
        }

        return DB::connection('creditcard')->transaction(function () use ($card, $period, $data): CreditCardStatement {
            $statement = $this->findOrNull($card, $period);

            // Gộp với giá trị ĐANG LƯU: cập nhật là từng phần, nên sửa riêng
            // `actual_reward` không được làm `actual_spend` rơi về 0 — mất tiền thật.
            $actualSpend = array_key_exists('actual_spend', $data)
                ? Decimal::money($data['actual_spend'])
                : ($statement === null ? '0.00' : Decimal::money($statement->actual_spend));

            $actualReward = array_key_exists('actual_reward', $data)
                ? Decimal::money($data['actual_reward'])
                : ($statement === null ? '0.00' : Decimal::money($statement->actual_reward));

            if (Decimal::compare($actualReward, $actualSpend) > 0) {
                throw new \InvalidArgumentException('Tiền hoàn/thưởng không được lớn hơn thực tế chi tiêu.');
            }

            $attributes = [
                'actual_spend' => $actualSpend,
                'actual_reward' => $actualReward,
            ];

            if ($statement === null) {
                $statement = new CreditCardStatement([
                    'user_card_id' => $card->id,
                    'statement_period_id' => $period->id,
                    ...$attributes,
                ]);
            } else {
                $statement->forceFill($attributes);
            }

            // Tính TRƯỚC rồi `save()` một lần: `closing_balance` là NOT NULL, nên
            // tạo dòng trước rồi tính sau sẽ phải UPDATE lần hai.
            $statement->recalculateClosingBalance();
            $statement->save();

            return $statement->refresh();
        });
    }

    /**
     * Ghi sao kê vào kỳ NGƯỜI DÙNG CHỌN, theo `period_start`.
     *
     * Đây là cổng GHI duy nhất cho form nhập: nó nối "người dùng chọn kỳ nào"
     * với "tạo bản ghi kỳ đó nếu chưa có" trong một chỗ, để controller không
     * phải tự ghép `resolvePeriodStart()` + `upsert()` và không thể quên kiểm tra
     * kỳ có hợp lệ với thẻ không.
     */
    public function upsertForPeriodStart(UserCard $card, string $periodStart, array $data): CreditCardStatement
    {
        $period = $this->resolvePeriodForStart($card, $periodStart);

        return $this->upsert($card, $period, $data);
    }

    /**
     * Bản ghi kỳ của `period_start`, tạo nếu chưa có.
     *
     * ---------------------------------------------------------------------------
     * CHỈ DÙNG Ở ĐƯỜNG GHI
     * ---------------------------------------------------------------------------
     * Hàm này CÓ THỂ tạo bản ghi `StatementPeriod`. Mọi đường đọc phải dùng
     * `bundleForPeriodStart()` (`findByBoundaries()`) để không sinh dữ liệu chỉ vì
     * người dùng mở trang. Tách riêng để ranh giới "đọc không tạo / ghi thì tạo" nằm
     * ngay ở tên hàm, không phải ở chỗ gọi.
     *
     * @throws \InvalidArgumentException khi ngày không thuộc chu kỳ thẻ
     */
    public function resolvePeriodForStart(UserCard $card, string $periodStart): StatementPeriod
    {
        return $this->periods->resolvePeriodStart(
            $card,
            CarbonImmutable::createFromFormat('Y-m-d', $periodStart)->startOfDay(),
        );
    }

    /**
     * Xoá dòng sao kê của một cặp (thẻ, kỳ).
     */
    public function delete(UserCard $card, StatementPeriod $period): void
    {
        if ((int) $period->user_card_id !== (int) $card->id) {
            throw new \InvalidArgumentException('Kỳ sao kê không thuộc thẻ này.');
        }

        if ($period->isFinalized()) {
            throw new \LogicException('Không thể xoá sao kê của kỳ đã chốt.');
        }

        CreditCardStatement::query()
            ->where('user_card_id', $card->id)
            ->where('statement_period_id', $period->id)
            ->delete();
    }
}
