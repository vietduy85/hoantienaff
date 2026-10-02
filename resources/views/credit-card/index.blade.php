{{--
    Trang tổng quan Module Thẻ tín dụng — /thetindung

    ---------------------------------------------------------------------------
    BỐN CHỈ SỐ
    ---------------------------------------------------------------------------
    Tổng số thẻ · Tổng hạn mức · Tổng chi tiêu · Cashback dự kiến — đều từ
    `CreditCardOverviewService` (aggregate SQL). Trang này KHÔNG tính cashback:
    "Cashback dự kiến" đọc `credit_card_statement_periods.total_cashback` mà
    engine đã ghi, nên không thể lệch với lịch sử giao dịch.

    ---------------------------------------------------------------------------
    TỪNG THẺ: TIẾN ĐỘ + QUOTA
    ---------------------------------------------------------------------------
    Mỗi thẻ có thanh tiến độ (chi tiêu kỳ hiện tại / `desired_spend`) và quota
    hoàn tiền còn lại. Cả hai đều đọc số đã tính sẵn ở server
    (`CreditCardOverviewService::perCardFrom()` + `CashbackQuotaService`) — Blade
    và Alpine KHÔNG có công thức nào, chỉ định dạng.

    ---------------------------------------------------------------------------
    KHÔNG CÓ "+ Thêm thẻ" Ở ĐÂY (nút đó chỉ nằm ở Quản lý thẻ) và KHÔNG có khối
    nhắc mốc chi tiêu — ngày nhắc là metadata phụ, còn kỳ sao kê do `statement_day`
    quyết định. Ô ngày + ranh giới kỳ đã thay thế vai trò nhắc ngày.
--}}
<x-credit-card.layout
    title="Thẻ tín dụng"
    subtitle="Quản lý và theo dõi các thẻ tín dụng của bạn"
    active="index">

    @php
        // Dữ liệu cho Alpine. Tiền gửi dạng CHUỖI (xem CreditCardOverviewService)
        // để không mất chính xác khi đi qua JSON; chỉ định dạng khi hiển thị.
        //
        // `cards` chỉ gồm thẻ CÒN NHẬN GIAO DỊCH (ô chọn của form) và mang kèm
        // ranh giới kỳ hiện tại để ô ngày giới hạn min/max theo đúng thẻ đang
        // chọn. Danh sách thẻ bên dưới dùng `cardRows` nên vẫn hiện thẻ đã đóng.
        $overviewState = [
            'summary' => [
                'total_cards' => (int) $summary['total_cards'],
                'total_credit_limit' => $summary['total_credit_limit'],
                'total_spend' => $summary['total_spend'],
                'expected_cashback' => $summary['expected_cashback'],
            ],
            'cards' => $transactionCards->map(fn ($card) => [
                'id' => (int) $card->id,
                'name' => $card->name ?: 'Thẻ tín dụng',
                'bank' => $card->bank?->name,
                // CHỈ 4 số cuối. Module không lưu số thẻ đầy đủ ở đâu cả, và §15
                // cấm hiển thị full card number.
                'last4' => $card->card_number_last4,
                // Ranh giới kỳ sao kê hiện tại — tính thuần từ `statement_day`,
                // nên thẻ chưa có kỳ nào trong DB vẫn có min/max đúng.
                'period_start' => $periodBounds[$card->id]['start'] ?? null,
                'period_end' => $periodBounds[$card->id]['end'] ?? null,
            ])->values()->all(),
            // Số liệu từng thẻ, khoá theo id. Khoá của JSON là CHUỖI nên JS tra
            // bằng `String(id)` (xem `cardMetrics()` bên dưới).
            'card_metrics' => $cardMetrics,
            'categories' => $transactionCategories,
            // Hôm nay theo timezone ứng dụng — mặc định của ô ngày và giá trị
            // server dùng để validate.
            'today' => $today,
            // URL lấy từ `route()` ở controller chứ KHÔNG gõ tay trong JS: đổi
            // prefix `/thetindung` sau này không làm form gọi nhầm đường dẫn.
            'store_url' => route('credit-cards.api.transactions.store'),
            'summary_url' => route('credit-cards.api.cards.index'),
        ];

        // Dòng hiển thị cho danh sách thẻ: gộp số liệu server đã tính với URL
        // lịch sử để Blade không phải tự chế ra gì.
        $cardRows = $userCreditCards->map(fn ($card) => [
            'card' => $card,
            'metrics' => $cardMetrics[$card->id] ?? null,
            'history_url' => route('credit-cards.transactions', ['userCard' => $card->id]),
        ]);
    @endphp

    <div x-data="creditCardOverview(@js($overviewState))" class="space-y-4">

        {{-- ═══ 4 chỉ số ═══
             `min-w-0` + `break-words` ở mọi ô để một con số dài không đẩy trang
             sang cuộn ngang trên màn hình điện thoại (§31). Tiền format bằng
             `ccMoney()` — cùng hàm với Quản lý thẻ.

             Trạng thái rỗng dùng `text-gray-500` (tương phản 4.6:1 trên nền
             trắng) chứ không phải `text-gray-300` (~1.9:1) — số "0 đ" mờ như
             vô hình thì người dùng tưởng trang chưa tải xong. --}}
        <div class="grid grid-cols-2 gap-3 sm:gap-4" data-testid="overview-summary">
            <div class="min-w-0 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <p class="text-xs sm:text-sm text-gray-500">💳 Tổng số thẻ</p>
                <p class="text-2xl sm:text-3xl font-bold tracking-tight break-words"
                   :class="summary.total_cards > 0 ? 'text-emerald-600' : 'text-gray-500'"
                   data-testid="stat-total-cards"
                   x-text="summary.total_cards">{{ (int) $summary['total_cards'] }}</p>
                @if ((int) $summary['total_cards'] === 0)
                    <p class="text-xs text-gray-400">Chưa có dữ liệu</p>
                @endif
            </div>

            <div class="min-w-0 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <p class="text-xs sm:text-sm text-gray-500">🎯 Tổng hạn mức</p>
                <p class="text-2xl sm:text-3xl font-bold tracking-tight break-words"
                   :class="ccNumber(summary.total_credit_limit) > 0 ? 'text-emerald-600' : 'text-gray-500'"
                   data-testid="stat-total-limit"
                   x-text="ccMoney(summary.total_credit_limit) + ' đ'"><x-credit-card.money :value="$summary['total_credit_limit']" /></p>
                @if ((float) $summary['total_credit_limit'] === 0.0)
                    <p class="text-xs text-gray-400">Chưa khai hạn mức</p>
                @endif
            </div>

            <div class="min-w-0 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <p class="text-xs sm:text-sm text-gray-500">💸 Tổng chi tiêu</p>
                <p class="text-2xl sm:text-3xl font-bold tracking-tight break-words"
                   :class="ccNumber(summary.total_spend) > 0 ? 'text-emerald-600' : 'text-gray-500'"
                   data-testid="stat-total-spend"
                   x-text="ccMoney(summary.total_spend) + ' đ'"><x-credit-card.money :value="$summary['total_spend']" /></p>
                <p class="text-xs text-gray-400">
                    @if ($currentPeriod !== null)
                        Kỳ {{ $currentPeriod->period_start->format('d/m') }}–{{ $currentPeriod->period_end->format('d/m/Y') }}
                    @else
                        Kỳ sao kê hiện tại
                    @endif
                </p>
            </div>

            <div class="min-w-0 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <p class="text-xs sm:text-sm text-gray-500">🎁 Cashback dự kiến</p>
                <p class="text-2xl sm:text-3xl font-bold tracking-tight break-words"
                   :class="ccNumber(summary.expected_cashback) > 0 ? 'text-emerald-600' : 'text-gray-500'"
                   data-testid="stat-expected-cashback"
                   x-text="ccMoney(summary.expected_cashback) + ' đ'"><x-credit-card.money :value="$summary['expected_cashback']" /></p>
                {{-- Ngày chốt/đến hạn đọc thẳng từ kỳ mà engine đang ghi, không
                     tự suy ra từ `statement_day` (ngày chốt có thể đã lùi vì
                     kỳ trước đóng muộn). --}}
                <p class="text-xs text-gray-400">
                    @if ($currentPeriod !== null)
                        Chốt {{ $currentPeriod->statement_date?->format('d/m') ?? '—' }}
                        · Đến hạn {{ $currentPeriod->payment_due_date?->format('d/m/Y') ?? '—' }}
                    @else
                        Hệ thống tự tính từ chính sách
                    @endif
                </p>
            </div>
        </div>

        {{-- ═══ Nhập giao dịch ═══
             Nút này nằm NGAY DƯỚI 4 chỉ số theo yêu cầu. Nhãn "+ Nhập giao dịch"
             cố ý khác "+ Thêm thẻ" (nút đó ở Quản lý thẻ) và "Lưu thẻ"
             (thanh dính đáy form thẻ) để không bấm nhầm. --}}
        <section class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 space-y-4">
            <div class="flex items-center justify-between gap-3 min-w-0">
                <div class="min-w-0">
                    <h3 class="font-bold text-gray-800 text-base">Giao dịch</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Chọn ngày, số tiền, thẻ và danh mục — cashback hệ thống tự tính.
                    </p>
                </div>

                {{-- Chưa có thẻ thì không có giao dịch để nhập: dẫn sang Quản lý
                     thẻ thay vì hiện nút chết. --}}
                @if ($transactionCards->isNotEmpty())
                    <button type="button"
                            data-testid="add-transaction-button"
                            x-show="! formOpen"
                            @click="openForm()"
                            class="shrink-0 inline-flex items-center justify-center gap-2 h-14 sm:h-12 px-4 rounded-2xl bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-base font-bold shadow-sm transition-colors">
                        <span aria-hidden="true" class="text-xl leading-none">+</span> Nhập giao dịch
                    </button>
                @endif
            </div>

            @if ($transactionCards->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-200 py-8 px-4 text-center">
                    <p class="text-sm text-gray-500">Bạn chưa có thẻ nào để ghi giao dịch.</p>
                    <a href="{{ route('credit-cards.manage') }}"
                       class="mt-3 inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold">
                        Thêm thẻ ở Quản lý thẻ
                    </a>
                </div>
            @endif

            {{-- ═══ Form nhập giao dịch ═══
                 Mở ngay tại chỗ (không rời trang): người đang ở Tổng quan thì
                 không phải sang trang khác để ghi một khoản chi.

                 5 ô theo thứ tự đọc tự nhiên trên điện thoại: NGÀY → SỐ TIỀN →
                 THẺ → DANH MỤC → GHI CHÚ. KHÔNG có ô cashback, policy, tier, cap
                 hay chọn kỳ sao kê — những thứ đó hệ thống tự resolve.

                 Mobile: 1 cột, mọi input cao `h-12`, nút cao `h-14` để bấm được
                 bằng ngón cái; `<select>` native để hệ điều hành mở bảng chọn. --}}
            <form x-show="formOpen" x-cloak @submit.prevent="submit()" class="space-y-4" novalidate
                  data-testid="transaction-form">
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <h4 class="font-bold text-gray-800">Nhập giao dịch</h4>
                        <button type="button" @click="closeForm()" aria-label="Đóng"
                                class="shrink-0 inline-flex items-center justify-center h-10 w-10 rounded-xl border border-gray-200 text-gray-500 text-lg leading-none">
                            &times;
                        </button>
                    </div>
                    <p class="text-xs text-gray-500">
                        Kỳ sao kê, bậc và quy tắc hoàn tiền do hệ thống tự xử lý.
                    </p>
                </div>

                {{-- Lỗi chung (mạng/500). KHÔNG in exception/stack trace ra UI. --}}
                <div x-show="error" x-cloak class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                    <p class="text-sm text-red-700 break-words" x-text="error"></p>
                </div>

                <div x-show="notice" x-cloak class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                    <p class="text-sm text-emerald-700 break-words" x-text="notice"></p>
                </div>

                {{-- Ngày giao dịch: ĐẦU TIÊN vì mọi thứ còn lại (kỳ sao kế, bậc,
                     quota) đều bám theo ngày này. Mặc định hôm nay, min/max theo
                     kỳ hiện tại của thẻ đang chọn — server còn chặn lại một lần nữa
                     ở `StoreTransactionRequest`. --}}
                <div class="space-y-1.5">
                    <label for="tx-transaction-date" class="block text-sm font-semibold text-gray-700">Ngày giao dịch</label>
                    <input id="tx-transaction-date" type="date" required
                           :min="periodStart()" :max="periodEnd()"
                           x-model="form.transaction_date"
                           class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <p class="text-xs text-gray-500" x-text="periodHint()"></p>
                    <p class="text-xs text-red-600" x-show="fieldErrors.transaction_date" x-cloak x-text="fieldErrors.transaction_date"></p>
                </div>

                {{-- Số tiền --}}
                <div class="space-y-1.5">
                    <label for="tx-amount" class="block text-sm font-semibold text-gray-700">Số tiền</label>
                    <input id="tx-amount" type="number" inputmode="decimal" min="0" step="1000"
                           x-model="form.amount" required placeholder="Ví dụ: 250000"
                           class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <p class="text-xs text-red-600" x-show="fieldErrors.amount" x-cloak x-text="fieldErrors.amount"></p>
                </div>

                {{-- Thẻ: chỉ thẻ CÒN DÙNG ĐƯỢC của chính user. Đổi thẻ ⇒ đổi kỳ
                     sao kê ⇒ ngày phải kiểm tra lại (`syncDateBounds`). --}}
                <div class="space-y-1.5">
                    <label for="tx-card" class="block text-sm font-semibold text-gray-700">Thẻ tín dụng</label>
                    <select id="tx-card" x-model="form.user_card_id" required @change="syncDateBounds()"
                            class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">— Chọn thẻ —</option>
                        <template x-for="card in cards" :key="card.id">
                            <option :value="card.id"
                                    x-text="cardLabel(card)"></option>
                        </template>
                    </select>
                    <p class="text-xs text-red-600" x-show="fieldErrors.user_card_id" x-cloak x-text="fieldErrors.user_card_id"></p>
                </div>

                {{-- Danh mục: chỉ danh mục hệ thống + danh mục riêng của chính user
                     (server đã lọc `selectableBy()`; form này chỉ hiển thị lại). --}}
                <div class="space-y-1.5">
                    <label for="tx-category" class="block text-sm font-semibold text-gray-700">Danh mục</label>
                    <select id="tx-category" x-model="form.category_id" required
                            class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="">— Chọn danh mục —</option>
                        <template x-for="category in categories" :key="category.id">
                            <option :value="category.id"
                                    x-text="category.name + (category.scope === 'user' ? ' (của tôi)' : '')"></option>
                        </template>
                    </select>
                    <p class="text-xs text-red-600" x-show="fieldErrors.category_id" x-cloak x-text="fieldErrors.category_id"></p>
                </div>

                {{-- Ghi chú: tuỳ chọn, CHỈ để ghi chú. Không dùng để xác định
                     danh mục hay tính cashback. --}}
                <div class="space-y-1.5">
                    <label for="tx-note" class="block text-sm font-semibold text-gray-700">
                        Ghi chú <span class="font-normal text-gray-400">(không bắt buộc)</span>
                    </label>
                    <textarea id="tx-note" rows="2" maxlength="1000" x-model="form.note"
                              placeholder="Ví dụ: cà phê, xăng…"
                              class="w-full rounded-xl border-gray-300 text-base px-4 py-3 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                </div>

                <button type="submit"
                        data-testid="save-transaction-button"
                        :disabled="busy"
                        class="w-full h-14 rounded-2xl bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 disabled:bg-emerald-300 disabled:cursor-not-allowed text-white text-base font-bold shadow-sm transition-colors">
                    <span x-show="! busy">Lưu giao dịch</span>
                    <span x-show="busy" x-cloak>Đang lưu…</span>
                </button>
            </form>
        </section>

        {{-- Danh sách thẻ (chỉ xem tại đây; thêm/sửa nằm ở "Quản lý thẻ") --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
            <div class="flex items-center justify-between gap-3 min-w-0">
                <h3 class="font-bold text-gray-800">Thẻ tín dụng đang có</h3>
                @if ($userCreditCards->isNotEmpty())
                    <a href="{{ route('credit-cards.manage') }}"
                       class="shrink-0 inline-flex items-center h-10 px-3 bg-gray-50 hover:bg-gray-100 text-gray-600 text-sm font-semibold rounded-xl transition-colors">
                        Quản lý
                    </a>
                @endif
            </div>

            @if ($userCreditCards->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-200 py-8 px-4 text-center">
                    <p class="text-sm text-gray-500">Bạn chưa thêm thẻ tín dụng nào.</p>
                    <a href="{{ route('credit-cards.manage') }}"
                       class="mt-3 inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold">
                        Thêm thẻ ở Quản lý thẻ
                    </a>
                </div>
            @else
                <ul class="space-y-3">
                    @foreach ($cardRows as $row)
                        @php
                            $card = $row['card'];
                            $metrics = $row['metrics'];
                            $quota = $metrics['quota'] ?? null;
                            $hasGoal = $metrics['has_goal'] ?? false;
                            $progress = min(100.0, (float) ($metrics['progress_percent'] ?? 0));
                            $overGoal = $hasGoal && (float) $metrics['progress_percent'] > 100.0;
                        @endphp
                        <li class="rounded-xl border border-gray-100 p-4 min-w-0 space-y-3"
                            data-testid="card-row"
                            data-card-id="{{ $card->id }}">
                            {{-- Tên thẻ do USER tự đặt. KHÔNG in tên ngân hàng ở đây:
                                 danh sách này đã dài trên điện thoại, và tên ngân hàng
                                 trùng lặp giữa các thẻ không giúp gì; muốn xem thì vào
                                 Quản lý thẻ. Chỉ giữ 4 số cuối để phân biệt thẻ. --}}
                            <div class="flex items-start justify-between gap-2 min-w-0">
                                <p class="font-semibold text-gray-800 break-words min-w-0">
                                    {{ $card->name ?: 'Thẻ tín dụng' }}
                                    @if ($card->card_number_last4)
                                        <span class="font-normal text-gray-400">· •••• {{ $card->card_number_last4 }}</span>
                                    @endif
                                </p>
                                <a href="{{ $row['history_url'] }}"
                                   data-testid="card-history-link"
                                   class="shrink-0 inline-flex items-center h-9 px-3 bg-gray-50 hover:bg-gray-100 text-gray-600 text-xs font-semibold rounded-xl transition-colors">
                                    Lịch sử
                                </a>
                            </div>

                            {{-- Tiến độ theo mục tiêu chi tiêu. Số đã tính ở server;
                                 JS chỉ cập nhật bề rộng khi làm mới sau khi lưu. --}}
                            <div class="space-y-1.5">
                                <div class="flex items-center justify-between gap-2 text-xs text-gray-500 min-w-0">
                                    <span class="min-w-0 truncate">
                                        <span x-text="ccMoney(metricFor(@js((string) $card->id), 'spent')) + ' đ'">{{ $metrics['spent'] ?? '0.00' }}</span>
                                        @if ($hasGoal)
                                            / mục tiêu <span x-text="ccMoney(metricFor(@js((string) $card->id), 'desired_spend')) + ' đ'">{{ $metrics['desired_spend'] }}</span>
                                        @else
                                            <span class="text-gray-400">· chưa đặt mục tiêu</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 font-semibold text-gray-700"
                                          data-testid="card-progress-percent"
                                          x-text="metricFor(@js((string) $card->id), 'progress_percent') + '%'">{{ $metrics['progress_percent'] ?? '0.00' }}%</span>
                                </div>

                                @if ($hasGoal)
                                    {{-- Vượt mục tiêu: thanh đầy 100% + nhãn riêng. Không vẽ
                                         thanh tràn (>100%) vì sẽ phá layout trên
                                         điện thoại; dấu hiệu vượt đến từ nhãn và màu. --}}
                                    <div class="relative h-2.5 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full transition-[width] duration-300"
                                             data-testid="card-progress-bar"
                                             :class="metricFor(@js((string) $card->id), 'progress_percent') > 100 ? 'bg-amber-500' : 'bg-emerald-500'"
                                             style="width: {{ $progress }}%"
                                             :style="'width:' + progressWidth(@js((string) $card->id)) + '%'"></div>
                                        {{-- Vạch mục tiêu ở cuối thanh — giữ đúng nghĩa
                                             "đạt ở 100%" khi vượt. --}}
                                        <span class="absolute inset-y-0 right-0 w-0.5 bg-gray-400"
                                              aria-hidden="true"></span>
                                    </div>
                                    <p class="text-xs font-medium"
                                       data-testid="card-progress-goal-state"
                                       @if ($overGoal)
                                           x-text="metricFor(@js((string) $card->id), 'progress_percent') > 100 ? 'Đã vượt mục tiêu kỳ này' : ''"
                                       >Đã vượt mục tiêu kỳ này</p>
                                    @else
                                       <p class="text-xs text-gray-400" data-testid="card-progress-goal-state"></p>
                                    @endif
                                @else
                                    {{-- Không có mục tiêu ⇒ không vẽ thanh 0% giả: nói rõ
                                         cần đặt mục tiêu thay vì hiện 0% như đã đo. --}}
                                    <div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500"
                                         data-testid="card-progress-goal-state">
                                        Đặt mục tiêu chi tiêu ở Quản lý thẻ để xem tiến độ.
                                    </div>
                                @endif
                            </div>

                            {{-- Quota hoàn tiền của kỳ: trần toàn kỳ theo bậc ứng với
                                 `desired_spend`. "Còn lại" = trần − phần đã dùng, KHÔNG
                                 âm. Không có trần ⇒ bậc không đặt trần, hiện "—". --}}
                            <div class="flex items-center justify-between gap-2 text-xs min-w-0">
                                <span class="text-gray-500 min-w-0 truncate" data-testid="card-quota-label">
                                    Quota hoàn tiền
                                    @if ($quota === null || ! $quota['has_limit'])
                                        <span class="text-gray-400">· không giới hạn</span>
                                    @else
                                        <span x-text="' · còn ' + ccMoney(quotaFor(@js((string) $card->id), 'remaining')) + ' đ'">· còn {{ $quota['remaining'] }}</span>
                                    @endif
                                </span>
                                @if ($quota !== null && $quota['has_limit'])
                                    <span class="shrink-0 font-semibold text-gray-700"
                                          data-testid="card-quota-remaining"
                                          x-text="ccMoney(quotaFor(@js((string) $card->id), 'remaining')) + ' đ'">{{ $quota['remaining'] }}</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

    </div>

    @once
        @include('credit-card.partials.money-js')

        <script>
            /**
             * Trạng thái form trống cho ô nhập giao dịch.
             *
             * PHẢI là hàm đứng riêng: `x-data="creditCardOverview({...})"` gọi nó
             * như hàm trần, lúc đó `this` chưa phải component nên không thể dùng
             * `this.blankForm()`.
             *
             * `transaction_date` mặc định HÔM NAY (server đưa xuống qua
             * `state.today`) — trong kỳ sao kê hiện tại theo `statement_day`.
             */
            function ccBlankTransactionForm(today) {
                return {
                    transaction_date: today ?? '',
                    amount: '',
                    user_card_id: '',
                    category_id: '',
                    note: '',
                };
            }

            /**
             * Tổng quan + nhập giao dịch.
             *
             * Controller KHÔNG orchestration gì ở đây: mọi quyết định nghiệp vụ
             * (chọn thẻ, gắn kỳ sao kê, tìm policy → tier → rule, tính cashback,
             * quota) nằm ở server. Form chỉ gửi field và đọc lại số liệu đã được
             * engine tính sẵn — ở đây KHÔNG có công thức cashback nào.
             */
            function creditCardOverview(state) {
                return {
                    summary: { ...(state.summary ?? {}) },
                    cards: state.cards ?? [],
                    card_metrics: state.card_metrics ?? {},
                    categories: state.categories ?? [],
                    today: state.today ?? '',
                    store_url: state.store_url ?? '',
                    summary_url: state.summary_url ?? '',

                    formOpen: false,
                    busy: false,
                    error: '',
                    notice: '',
                    fieldErrors: {},
                    form: ccBlankTransactionForm(state.today),

                    /** Nhãn thẻ trong ô chọn: tên + ngân hàng + 4 số cuối. KHÔNG
                     *  bao giờ hiện số thẻ đầy đủ. Ngân hàng giữ ở ô CHỌN (cần để
                     *  phân biệt khi có nhiều thẻ), không lặp lại ở danh sách thẻ. */
                    cardLabel(card) {
                        return [card.name, card.bank, card.last4 ? '•••• ' + card.last4 : null]
                            .filter(Boolean)
                            .join(' · ');
                    },

                    /** Thẻ đang chọn trong form. */
                    selectedCard() {
                        return this.cards.find((card) => String(card.id) === String(this.form.user_card_id)) ?? null;
                    },

                    /** Ranh giới kỳ sao kê hiện tại của thẻ đang chọn. Server tính
                     *  sẵn nên hàm này không có công thức ngày nào. */
                    periodStart() {
                        return this.selectedCard()?.period_start ?? this.today;
                    },

                    periodEnd() {
                        return this.selectedCard()?.period_end ?? this.today;
                    },

                    /** Dòng nhắc ngày dưới ô ngày — thay cho khối nhắc mốc chi tiêu
                     *  đã bỏ: nói rõ kỳ đang ghi thay vì nhắc một mốc phụ. */
                    periodHint() {
                        const card = this.selectedCard();

                        if (!card?.period_start || !card?.period_end) {
                            return 'Chọn thẻ để xem kỳ sao kê hiện tại.';
                        }

                        return 'Kỳ ' + this.ccDay(card.period_start) + ' – ' + this.ccDay(card.period_end);
                    },

                    /** Định dạng ngày ngắn gọn cho nhãn; không cần thư viện ngoài. */
                    ccDay(value) {
                        if (!value) return '—';

                        const parts = String(value).split('-');

                        if (parts.length !== 3) return value;

                        return parts[2] + '/' + parts[1];
                    },

                    /**
                     * Đổi thẻ ⇒ đổi kỳ sao kê ⇒ ngày đã chọn có thể nằm ngoài kỳ mới.
                     * Đưa về hôm nay (luôn thuộc kỳ hiện tại) và báo lý do, thay vì
                     * để người dùng bấm Lưu rồi mới nhận lỗi 422.
                     */
                    syncDateBounds() {
                        const card = this.selectedCard();

                        if (!card?.period_start || !card?.period_end) return;

                        const date = this.form.transaction_date;

                        if (date >= card.period_start && date <= card.period_end) return;

                        this.form.transaction_date = this.today;
                        this.notice = 'Ngày đã được đưa về hôm nay vì nằm ngoài kỳ sao kê của thẻ vừa chọn.';
                    },

                    /** Số liệu từng thẻ từ server. Khoá JSON là chuỗi. */
                    metricFor(id, key) {
                        return this.card_metrics?.[String(id)]?.[key] ?? null;
                    },

                    quotaFor(id, key) {
                        return this.card_metrics?.[String(id)]?.quota?.[key] ?? null;
                    },

                    /**
                     * Bề rộng thanh tiến độ, kẹp [0, 100].
                     *
                     * Vượt mục tiêu vẫn vẽ 100% và đổi màu ở Blade — thanh dài hơn
                     * 100% sẽ tràn khung và phá layout trên điện thoại.
                     */
                    progressWidth(id) {
                        const value = Number(this.metricFor(id, 'progress_percent'));

                        if (!Number.isFinite(value)) return 0;

                        return Math.max(0, Math.min(100, value));
                    },

                    openForm() {
                        this.error = '';
                        this.notice = '';
                        this.fieldErrors = {};

                        // Mặc định chọn thẻ đầu tiên để người dùng chỉ còn nhập
                        // số tiền + danh mục. Ưu tiên thẻ đang sửa gần nhất.
                        if (!this.form.user_card_id && this.cards.length > 0) {
                            this.form.user_card_id = this.cards[0].id;
                        }

                        // Ngày luôn bắt đầu từ hôm nay rồi mới kiểm tra theo kỳ
                        // của thẻ đang chọn.
                        this.form.transaction_date = this.today;
                        this.syncDateBounds();
                        this.formOpen = true;
                    },

                    closeForm() {
                        this.formOpen = false;
                        this.form = ccBlankTransactionForm(this.today);
                        this.fieldErrors = {};
                        this.error = '';
                        this.notice = '';
                    },

                    /**
                     * Chặn submit khi ô nhập không hợp lệ TRƯỚC khi gọi mạng.
                     *
                     * Ô này ghi CHI TIÊU nên chỉ nhận số dương. Số âm là hoàn tiền
                     * và thuộc luồng khác (import Excel) — form nhập tay không cho
                     * gõ, server cũng chặn (`gt:0` trong `StoreTransactionRequest`).
                     *
                     * Server vẫn validate lại — kiểm tra ở đây chỉ để báo lỗi nhanh,
                     * không phải để tin cậy.
                     */
                    validate() {
                        const errors = {};
                        const amount = Number(this.form.amount);

                        if (this.form.amount === '' || !Number.isFinite(amount) || amount <= 0) {
                            errors.amount = 'Số tiền phải lớn hơn 0.';
                        }

                        if (!this.form.transaction_date) {
                            errors.transaction_date = 'Vui lòng chọn ngày giao dịch.';
                        } else if (!this.isDateInSelectedPeriod(this.form.transaction_date)) {
                            errors.transaction_date = 'Ngày phải nằm trong kỳ sao kê hiện tại của thẻ đã chọn.';
                        }

                        if (!this.form.category_id) {
                            errors.category_id = 'Vui lòng chọn danh mục.';
                        }

                        if (!this.form.user_card_id) {
                            errors.user_card_id = 'Vui lòng chọn thẻ tín dụng.';
                        }

                        this.fieldErrors = errors;

                        return Object.keys(errors).length === 0;
                    },

                    isDateInSelectedPeriod(date) {
                        const card = this.selectedCard();

                        // Chưa chọn thẻ thì để lỗi "chọn thẻ" lo, không bắt lỗi ngày.
                        if (!card?.period_start || !card?.period_end) return true;

                        return date >= card.period_start && date <= card.period_end;
                    },

                    async submit() {
                        this.error = '';
                        this.notice = '';

                        if (!this.validate()) return;

                        // Khoá nút ngay trong lúc chờ: chặn double click tạo trùng
                        // giao dịch (tiền thật, không phải cái gì bỏ được).
                        this.busy = true;

                        try {
                            const payload = await this.request(this.store_url, {
                                method: 'POST',
                                body: JSON.stringify({
                                    transaction_date: this.form.transaction_date,
                                    amount: this.form.amount,
                                    category_id: this.form.category_id,
                                    user_card_id: this.form.user_card_id,
                                    note: this.form.note || null,
                                }),
                            });

                            if (!payload) return;

                            this.closeForm();
                            this.notice = 'Đã lưu giao dịch.';
                            await this.refreshOverview();
                        } finally {
                            this.busy = false;
                        }
                    },

                    /**
                     * Làm mới số liệu sau khi lưu giao dịch.
                     *
                     * Gọi lại endpoint thẻ ĐANG CÓ (không tạo endpoint thứ hai) và
                     * đọc `meta.summary` + `meta.card_metrics`. Nhờ vậy "Tổng chi
                     * tiêu", "Cashback dự kiến", thanh tiến độ và quota đều là số
                     * do server/engine tính, không phải tổng cộng tay trong trình
                     * duyệt — không thể lệch với lịch sử giao dịch.
                     */
                    async refreshOverview() {
                        const payload = await this.request(this.summary_url);

                        if (payload?.meta?.summary) {
                            this.summary = { ...payload.meta.summary };
                        }

                        if (payload?.meta?.card_metrics) {
                            this.card_metrics = { ...payload.meta.card_metrics };
                        }
                    },

                    async request(url, options = {}) {
                        try {
                            const response = await fetch(url, {
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                },
                                ...options,
                            });

                            const payload = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                // 422 ⇒ gắn lỗi đúng ô để người dùng biết sửa chỗ nào, và
                                // KHÔNG in `message` mặc định của Laravel (tiếng Anh).
                                if (payload.errors) {
                                    this.fieldErrors = {
                                        transaction_date: this.firstOf(payload.errors.transaction_date),
                                        amount: this.firstOf(payload.errors.amount),
                                        category_id: this.firstOf(payload.errors.category_id),
                                        user_card_id: this.firstOf(payload.errors.user_card_id),
                                        note: this.firstOf(payload.errors.note),
                                    };

                                    this.error = 'Vui lòng kiểm tra lại các ô được đánh dấu.';
                                } else {
                                    this.error = this.firstError(payload)
                                        || `Yêu cầu thất bại (${response.status}).`;
                                }

                                return null;
                            }

                            return payload;
                        } catch (e) {
                            // KHÔNG lộ exception/stack trace ra UI.
                            this.error = 'Không kết nối được máy chủ. Vui lòng thử lại.';
                            return null;
                        }
                    },

                    firstOf(list) {
                        return Array.isArray(list) && list.length > 0 ? list[0] : '';
                    },

                    firstError(payload) {
                        if (payload.message) return payload.message;
                        if (payload.errors) {
                            const first = Object.values(payload.errors)[0];
                            if (Array.isArray(first)) return first[0];
                        }
                        return null;
                    },
                };
            }
        </script>
    @endonce

</x-credit-card.layout>
