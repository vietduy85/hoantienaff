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
        // `cards` chỉ gồm thẻ CÒN NHẬN GIAO DỊCH (ô chọn của form). Danh sách thẻ
        // bên dưới dùng `cardRows` nên vẫn hiện thẻ đã đóng.
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
                // Ranh giới kỳ sao kê HIỆN TẠI. Gửi xuống để hiển thị, KHÔNG
                // dùng để giới hạn ô ngày giao dịch — ngày giao dịch tự do.
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
            // Sao kê của KỲ ĐÃ KẾT THÚC GẦN NHẤT — chỉ để hiển thị. Lấy từ
            // controller (`$latestStatements`), KHÔNG tự suy ra ở view: ranh giới
            // kỳ là việc của `StatementPeriodService`.
            'statement' => $latestStatements[$card->id] ?? null,
            'statements_url' => route('credit-cards.statements'),
        ]);

        // Có thẻ nào đang hiển thị khối "Điều kiện hoàn tiền đặc biệt" không.
        // Label ô toggle ở dải "Hiển thị" chỉ xuất hiện khi có khối THẬT — §2 cấm
        // để cụm từ này lọt vào trang khi không có thẻ nào dùng điều kiện.
        $hasAnySpendQualification = collect($cardMetrics)->contains(
            fn ($metrics): bool => ($metrics['spend_qualification'] ?? null) !== null
        );
    @endphp

    <div x-data="creditCardOverview(@js($overviewState))" class="space-y-4">

        {{-- ═══ 4 chỉ số ═══
             Số tiền dùng `cc-stat-amount` (xem `resources/css/app.css`): cỡ chữ co
             theo bề rộng thật của ô qua container query, `white-space: nowrap`
             ⇒ một số tiền LUÔN nằm trên một dòng. Trước đây là
             `text-2xl sm:text-3xl` + `break-words`, nên "800.000.000 đ" trên
             iPhone bị gãy thành "800.000.000" / "00 đ" — đọc sai hậu tố. KHÔNG
             dùng truncate/ellipsis/overflow-hidden để "giấu" phần thừa: số bị
             cắt dở thì tệ hơn số nhỏ đi một chút.

             Tiền format bằng `ccMoneyVnd()` — cùng hàm với Quản lý thẻ.

             Trạng thái rỗng dùng `text-gray-500` (tương phản 4.6:1 trên nền
             trắng) chứ không phải `text-gray-300` (~1.9:1) — số "0 đ" mờ như
             vô hình thì người dùng tưởng trang chưa tải xong. --}}
        <div class="grid grid-cols-2 gap-3 sm:gap-4" data-testid="overview-summary">
            <div class="cc-stat-tile min-w-0 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <p class="text-xs sm:text-sm text-gray-500">💳 Tổng số thẻ</p>
                <p class="cc-stat-amount font-bold"
                   :class="summary.total_cards > 0 ? 'text-emerald-600' : 'text-gray-500'"
                   data-testid="stat-total-cards"
                   x-text="summary.total_cards">{{ (int) $summary['total_cards'] }}</p>
                @if ((int) $summary['total_cards'] === 0)
                    <p class="text-xs text-gray-400">Chưa có dữ liệu</p>
                @endif
            </div>

            <div class="cc-stat-tile min-w-0 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <p class="text-xs sm:text-sm text-gray-500">🎯 Tổng hạn mức</p>
                <p class="cc-stat-amount font-bold"
                   :class="ccNumber(summary.total_credit_limit) > 0 ? 'text-emerald-600' : 'text-gray-500'"
                   data-testid="stat-total-limit"
                   x-text="ccMoneyVnd(summary.total_credit_limit)"><x-credit-card.money :value="$summary['total_credit_limit']" /></p>
                @if ((float) $summary['total_credit_limit'] === 0.0)
                    <p class="text-xs text-gray-400">Chưa khai hạn mức</p>
                @endif
            </div>

            {{-- TỔNG CHI TIÊU = SUM(actual spend của KỲ SAO KẾ HIỆN TẠI của TỪNG thẻ).
                 Mỗi thẻ tự có kỳ riêng (statement_day khác nhau), và service cộng
                 đúng các kỳ `open` đang chứa hôm nay — xem
                 `CreditCardOverviewService::scope()`. KHÔNG phải tháng dương lịch,
                 không phải một kỳ chung cho cả module.

                 Vì vậy dưới đây KHÔNG in khoảng ngày nào: in một khoảng duy nhất
                 sẽ là của thẻ đầu tiên và sai với các thẻ còn lại. --}}
            <div class="cc-stat-tile min-w-0 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <p class="text-xs sm:text-sm text-gray-500">💸 Tổng chi tiêu</p>
                <p class="cc-stat-amount font-bold"
                   :class="ccNumber(summary.total_spend) > 0 ? 'text-emerald-600' : 'text-gray-500'"
                   data-testid="stat-total-spend"
                   x-text="ccMoneyVnd(summary.total_spend)"><x-credit-card.money :value="$summary['total_spend']" /></p>
                <p class="text-xs text-gray-400">Theo kỳ sao kê hiện tại của từng thẻ</p>
            </div>

            {{-- CASHBACK DỰ KIẾN = SUM(expected cashback hiện tại của từng thẻ).
                 Mỗi thẻ giữ nguyên logic của nó (kỳ hiện tại × bậc đích theo
                 `desired_spend` × rate của bậc đó, kẹp theo cap) — không tính
                 `tổng chi tiêu × một rate chung`, vì rate thuộc về TỪNG thẻ. --}}
            <div class="cc-stat-tile min-w-0 bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <p class="text-xs sm:text-sm text-gray-500">🎁 Cashback dự kiến</p>
                <p class="cc-stat-amount font-bold"
                   :class="ccNumber(summary.expected_cashback) > 0 ? 'text-emerald-600' : 'text-gray-500'"
                   data-testid="stat-expected-cashback"
                   x-text="ccMoneyVnd(summary.expected_cashback)"><x-credit-card.money :value="$summary['expected_cashback']" /></p>
                <p class="text-xs text-gray-400">Theo kỳ sao kê hiện tại của từng thẻ</p>
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

            {{-- Thông báo LƯU THÀNH CÔNG nằm NGOÀI màn hình nổi.
                 `submit()` đóng overlay rồi mới gán `notice`; nếu để trong form thì
                 thông báo bị giấu theo form và không bao giờ thấy. --}}
            <div x-show="notice" x-cloak data-testid="transaction-notice"
                 class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
                <p class="text-sm text-emerald-700 break-words" x-text="notice"></p>
            </div>
        </section>

        {{-- ═══ MÀN HÌNH NỔI TOÀN MÀN HÌNH: Nhập giao dịch ═══
             Người dùng đang đứng ở Tổng quan bấm "+ Nhập giao dịch" ⇒ form bung ra
             phủ KÍN viewport, không phải modal hộp giữa màn hình và không nối dài
             bên dưới trang (cả hai cách đó đều làm mất ngữ cảnh hoặc nhét thêm
             khối giữa danh sách thẻ).

             Cấu trúc một hộp duy nhất: header dính đáy trên + vùng cuộn giữa +
             thanh nút dính đáy dưới. `h-[100dvh]` thay vì `h-screen` vì 100vh trên
             trình duyệt điện thoại CAO HƠN khung nhìn thật (thanh địa chỉ thu lại),
             cắt mất nút Lưu; `dvh` bám theo khung nhìn thật. `inset-0` đã giới
             hạn chiều cao rồi, `h-[100dvh]` chỉ để chắc.

             `z-[9999]` vượt `z-50` của header dính và bottom-sheet trong
             `layouts/navigation.blade.php` để trang sau không ló lên trên form.

             Escape đóng, và trong lúc mở thì khoá cuộn trang phía sau.

                 CHIẾM TOÀN BỘ VIEWPORT KHẢ DỤNG:
                 - `inset-0` + `h-[100dvh]` + `max-h-[100dvh]`. `dvh` là viewport
                   ĐỘNG nên bám theo thanh địa chỉ/thanh dưới của Safari đang co hay
                   mở; `vh` (cao hơn khung nhìn thật trên iOS) chỉ để LÀM DỰ PHÒNG cho
                   Safari cũ chưa biết `dvh` — khai báo sau nên `dvh` thắng.
                 - `overscroll-contain`: chặn "scroll chaining", tức không để cuộn
                   tới hết vùng form rồi tiếp tục cuộn trang phía sau.
                 - `bg-white` đục: không nhìn xuyên được xuống trang dưới. --}}
        <div x-show="formOpen" x-cloak
             class="fixed inset-0 z-[9999] h-[100dvh] max-h-[100dvh] w-full bg-white flex flex-col overscroll-contain"
             style="height: 100vh; height: 100dvh;"
             role="dialog" aria-modal="true" aria-labelledby="tx-overlay-title"
             @keydown.escape.window="if (formOpen) closeForm()">

            {{-- Header: nút quay lại + tên màn hình. Tiêu đề ĐỊNH VỊ GIỮA khung nên nó
                 luôn nằm giữa cả trên mobile lẫn desktop, bất kể nút "Quay lại" dài
                 bao nhiêu — không cần ô trắng bù bề rộng (ô bù phải ghim px và sẽ
                 tràn ở 390px).

                 `shrink-0` giữ header KHỎI CUỘN: nó là sibling của vùng cuộn chứ
                 không nằm trong đó. Cộng thêm `env(safe-area-inset-top)` để không
                 bị dính vào vùng notch / thanh trạng thái trên iPhone. --}}
            <div class="shrink-0 relative flex items-center px-3 sm:px-4 pb-2.5 bg-white border-b border-gray-100"
                 style="padding-top: calc(0.625rem + env(safe-area-inset-top, 0px));">
                <button type="button" @click="closeForm()"
                        data-testid="close-transaction-overlay"
                        class="relative z-10 shrink-0 inline-flex items-center justify-center gap-1 h-11 px-3 rounded-xl border border-gray-200 text-gray-700 text-sm font-semibold active:bg-gray-50 transition-colors">
                    <span aria-hidden="true">←</span> Quay lại
                </button>
                <h2 id="tx-overlay-title"
                    class="absolute left-1/2 -translate-x-1/2 max-w-[70%] text-center font-bold text-gray-800 text-base sm:text-lg leading-tight">
                    Nhập giao dịch
                </h2>
            </div>

            {{-- Form giữ NGUYÊN toàn bộ field + handler cũ; chỉ đổi vỏ bọc.
                 5 ô theo thứ tự đọc tự nhiên trên điện thoại: NGÀY → SỐ TIỀN →
                 THẺ → DANH MỤC → GHI CHÚ. KHÔNG có ô cashback, policy, tier, cap
                 hay chọn kỳ sao kê — những thứ đó hệ thống tự resolve.

                 Mobile: 1 cột, mọi input cao `h-12`, nút cao `h-14` để bấm được
                 bằng ngón cái; `<select>` native để hệ điều hành mở bảng chọn. --}}
            <form x-show="formOpen" x-cloak @submit.prevent="submit()"
                  class="flex-1 min-h-0 flex flex-col" novalidate
                  data-testid="transaction-form">
                {{-- VÙNG DUY NHẤT CHỊU TRÁCH NHIỆM CUỘN. `flex-1 min-h-0` để nó co
                     lại đúng phần còn lại giữa header và footer thay vì đẩy footer
                     ra ngoài khung. `overscroll-y-contain` chặn cuộn lan sang trang
                     phía sau khi đã cuộn tới đáy. --}}
                <div class="flex-1 min-h-0 overflow-y-auto overscroll-y-contain [-webkit-overflow-scrolling:touch] px-4 sm:px-6 pt-4 pb-8 space-y-4">
                    <p class="text-xs text-gray-500">
                        Kỳ sao kê, bậc và quy tắc hoàn tiền do hệ thống tự xử lý.
                    </p>

                    {{-- Lỗi chung (mạng/500). KHÔNG in exception/stack trace ra UI. --}}
                    <div x-show="error" x-cloak class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                        <p class="text-sm text-red-700 break-words" x-text="error"></p>
                    </div>

                    {{-- Ngày giao dịch: ĐẦU TIÊN vì mọi thứ còn lại (kỳ sao kế, bậc,
                         quota) đều bám theo ngày này.
                         KHÔNG giới hạn: không `min`/`max`, không khoá theo kỳ sao kê,
                         không chặn ngày tương lai. Người dùng chọn BẤT KỲ ngày nào;
                         `StatementPeriodService::resolvePeriodForDate()` tự suy ra
                         kỳ chứa ngày đó (luồng import Excel vốn đã vậy). Mặc định
                         hôm nay chỉ là TIỆN ÍCH, không phải ràng buộc. --}}
                    <div class="space-y-1.5">
                        <label for="tx-transaction-date" class="block text-sm font-semibold text-gray-700">Ngày giao dịch</label>
                        <input id="tx-transaction-date" type="date" required
                               x-model="form.transaction_date"
                               class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="text-xs text-gray-500">Kỳ sao kê được tự xác định theo ngày đã chọn.</p>
                        <p class="text-xs text-red-600" x-show="fieldErrors.transaction_date" x-cloak x-text="fieldErrors.transaction_date"></p>
                    </div>

                    {{-- Số tiền --}}
                    <div class="space-y-1.5">
                        <label for="tx-amount" class="block text-sm font-semibold text-gray-700">Số tiền</label>
                        <x-credit-card.money-input
                            id="tx-amount"
                            expr="form.amount"
                            example="1555000"
                            required
                            class="w-full h-12 rounded-xl border-gray-300 text-base px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                        <p class="text-xs text-red-600" x-show="fieldErrors.amount" x-cloak x-text="fieldErrors.amount"></p>
                    </div>

                    {{-- Thẻ: chỉ thẻ CÒN DÙNG ĐƯỢC của chính user. Ngày giao dịch độc
                         lập với thẻ nên đổi thẻ KHÔNG cần chỉnh lại ngày. --}}
                    <div class="space-y-1.5">
                        <label for="tx-card" class="block text-sm font-semibold text-gray-700">Thẻ tín dụng</label>
                        <select id="tx-card" x-model="form.user_card_id" required
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
                </div>{{-- /vùng cuộn --}}

                {{-- Thanh hành động dính đáy: nút to, chạm bằng ngón cái, luôn thấy
                     dù form dài đến mấy.

                     `shrink-0` giữ footer KHỎI CUỘN (nó là sibling của vùng cuộn).
                     Cộng `env(safe-area-inset-bottom)` để nút Lưu không bị thanh
                     điều hướng dưới của Safari che — trên iPhone phần safe-area là
                     khoảng trống hệ thống, đệm vào đó nút nằm cao hơn mép màn
                     hình. `env(..., 0px)` để máy không có safe-area thì về 0. --}}
                <div class="shrink-0 px-4 sm:px-6 pt-3 bg-white/95 backdrop-blur border-t border-gray-100"
                     style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px));">
                    <button type="submit"
                            data-testid="save-transaction-button"
                            :disabled="busy"
                            class="w-full h-14 rounded-2xl bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 disabled:bg-emerald-300 disabled:cursor-not-allowed text-white text-base font-bold shadow-sm transition-colors">
                        <span x-show="! busy">Lưu giao dịch</span>
                        <span x-show="busy" x-cloak>Đang lưu…</span>
                    </button>
                </div>
            </form>
        </div>{{-- /màn hình nổi --}}

        {{-- ═══ SẮP XẾP THẺ ═══
         Ô chọn 4 chế độ, dùng CHUNG partial với Quản lý thẻ và Sao kê để ba màn không
         lệch nhau. Lựa chọn được nhớ trong `localStorage`; chế độ tự động KHÔNG ghi
         `sort_order` nên xem thử không làm mất thứ tự user đã sắp ở Quản lý thẻ. --}}
        @include('credit-card.partials.sort-picker', [
            'sortModes' => $sortModes,
            'sortMode' => $sortMode,
            'sortAction' => $sortAction,
            'sortStorageKey' => $sortStorageKey,
        ])

        {{-- Danh sách thẻ — MỘT bề mặt liền mạch.
             KHÔNG bọc mỗi thẻ trong một ô bo góc: giữa các thẻ chỉ có đường kẻ mảnh
             (`divide-y`) để trang thoáng và dễ quét (§3, §20). Các dòng số liệu
             phân cấp bằng MÀU + độ đậm chữ, không bằng ô vuông — mỗi ô vuống thêm
             một là mất không gian trên màn hình 390px mà không thêm thông tin. --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

            <div class="flex items-center justify-between gap-3 min-w-0 px-4 sm:px-5 py-3">
                <h3 class="font-bold text-gray-800">Thẻ tín dụng đang có</h3>
                @if ($userCreditCards->isNotEmpty())
                    <a href="{{ route('credit-cards.manage') }}"
                       class="shrink-0 inline-flex items-center h-9 px-3 bg-gray-50 hover:bg-gray-100 text-gray-600 text-xs font-semibold rounded-xl transition-colors">
                        Quản lý
                    </a>
                @endif
            </div>

            {{-- ═══ "Hiển thị" — 5 tùy chọn ĐỘC LẬP ═══
                 Một DẢI mảnh có đường kẻ, KHÔNG phải card lồng trong card. Chỉ ẩn/hiện
                 phần hiển thị phía dưới trên MỌI thẻ; dữ liệu và nghiệp vụ không đổi,
                 không reload trang (Alpine), và lựa chọn được nhớ trong localStorage
                 nên tồn tại sau khi chuyển trang. --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 sm:px-5 py-2.5 border-y border-gray-100 bg-gray-50/60"
                 data-testid="overview-display-options">
                <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Hiển thị</span>

                <label class="inline-flex items-center gap-1.5 text-xs sm:text-sm text-gray-700 cursor-pointer select-none">
                    <input type="checkbox" x-model="display.spend" @change="persistDisplay()"
                           data-testid="toggle-spend" checked
                           class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span>Số tiền đã chi tiêu</span>
                </label>

                <label class="inline-flex items-center gap-1.5 text-xs sm:text-sm text-gray-700 cursor-pointer select-none">
                    <input type="checkbox" x-model="display.cashback" @change="persistDisplay()"
                           data-testid="toggle-cashback" checked
                           class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span>Số tiền cashback dự kiến</span>
                </label>

                <label class="inline-flex items-center gap-1.5 text-xs sm:text-sm text-gray-700 cursor-pointer select-none">
                    <input type="checkbox" x-model="display.quota" @change="persistDisplay()"
                           data-testid="toggle-quota" checked
                           class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span>Quota hoàn tiền còn lại</span>
                </label>

                @if ($hasAnySpendQualification)
                <label class="inline-flex items-center gap-1.5 text-xs sm:text-sm text-gray-700 cursor-pointer select-none">
                    <input type="checkbox" x-model="display.qualification" @change="persistDisplay()"
                           data-testid="toggle-qualification" checked
                           class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span>Điều kiện hoàn tiền đặc biệt</span>
                </label>
            @endif

                <label class="inline-flex items-center gap-1.5 text-xs sm:text-sm text-gray-700 cursor-pointer select-none">
                    <input type="checkbox" x-model="display.statement" @change="persistDisplay()"
                           data-testid="toggle-statement" checked
                           class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span>Sao kê kỳ vừa kết thúc</span>
                </label>
            </div>

            @if ($userCreditCards->isEmpty())
                <div class="px-4 py-8 text-center">
                    <p class="text-sm text-gray-500">Bạn chưa thêm thẻ tín dụng nào.</p>
                    <a href="{{ route('credit-cards.manage') }}"
                       class="mt-3 inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold">
                        Thêm thẻ ở Quản lý thẻ
                    </a>
                </div>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($cardRows as $row)
                        @php
                            $card = $row['card'];
                            $cardKey = (string) $card->id;
                            $metrics = $row['metrics'] ?? null;
                            $quota = $metrics['quota'] ?? null;

                            // Chỉ đọc số ĐÃ TÍNH SẴN ở server. Blade/JS không có công
                            // thức quota nào (§11, §15) — kể cả phần trăm vạch đỏ và
                            // phần trăm thanh, đều lấy thẳng từ `CreditCardOverviewService`.
                            $hasGoal = (bool) ($metrics['has_goal'] ?? false);
                            $hasMinimum = (bool) ($metrics['has_minimum'] ?? false);
                            $meetsMinimum = (bool) ($metrics['meets_minimum'] ?? true);

                            // `min()` chỉ dùng cho CHIỀU RỘNG thanh; vạch đỏ cũng kẹp
                            // 100% để ngưỡng lớn hơn mục tiêu không đẩy nó ra ngoài.
                            $progressWidth = $hasGoal
                                ? min(100.0, max(0.0, (float) ($metrics['progress_percent'] ?? 0)))
                                : 0.0;
                            $minimumLeft = $hasGoal && $hasMinimum
                                ? min(100.0, max(0.0, (float) ($metrics['minimum_percent'] ?? 0)))
                                : 0.0;

                            // Trần CHUNG của bậc đích (theo `desired_spend`) — cùng số
                            // mà `CashbackQuotaService` dùng cho quota, không phải số
                            // tính lại ở đây.
                            $tierCashbackMax = $quota['tier_cashback_max'] ?? null;
                            $hasTierCashbackMax = (bool) ($quota['has_tier_cashback_max'] ?? false);

                            // Chỉ rule ĐÃ TICK quota. Phase 2 đã lọc sẵn fallback và
                            // rule thường; không có rule nào ⇒ không hiện tiêu đề rỗng.
                            $quotaRules = $quota['rules'] ?? [];
                        @endphp

                        <li class="px-4 sm:px-5 py-4 min-w-0 space-y-2"
                            data-testid="card-row"
                            data-card-id="{{ $card->id }}">

                            {{-- ═══ HEADER: DẢI MÀU PASTEL ═══
                                 Tên thẻ là thứ user nhìn đầu tiên nên được tách riêng
                                 thành MỘT DẢI MÀU chạy ngang hết bề ngang thẻ — không bọc
                                 thêm khung, không bo góc, không đổ bóng, nên không sinh
                                 card lồng trong card và không làm UI nặng thêm (§1, §3).
                                 Chữ vẫn là xám-900 đậm trên nền rất nhạt ⇒ tương phản tốt
                                 ở mọi màu pastel, kể cả dưới ánh sáng ngoài trời.

                                 Màu xoay vòng theo THỨ TỰ THẺ đang hiển thị (`$loop->index`):
                                 thêm/bớt thẻ không làm đổi thứ tự, chỉ chuyển màu các thẻ
                                 phía sau — và hai thẻ liền nhau luôn khác màu vì bảng màu
                                 dài hơn 1. --}}
                            @php
                                $cardPalette = ['bg-blue-100', 'bg-emerald-100', 'bg-amber-100', 'bg-purple-100', 'bg-pink-100'];
                                $bandClass = $cardPalette[$loop->index % count($cardPalette)];
                            @endphp
                            <div class="-mx-4 sm:-mx-5 -mt-4 px-4 sm:px-5 py-2.5 {{ $bandClass }}
                                        flex items-center justify-between gap-3 min-w-0"
                                 data-testid="card-header">
                                {{-- Tên do USER tự đặt, KHÔNG in tên ngân hàng (lặp giữa các
                                     thẻ, không giúp gì trên điện thoại). Tên dài cắt bằng
                                     `truncate` + `min-w-0` để nút "Chi tiết" không bị đẩy
                                     xuống dòng và không sinh thanh cuộn ngang. --}}
                                <p class="font-bold text-gray-900 text-[15px] leading-tight truncate min-w-0"
                                   data-testid="card-title">
                                    {{ $card->name ?: 'Thẻ tín dụng' }}
                                    @if ($card->card_number_last4)
                                        <span class="font-medium text-gray-500 text-sm">· •••• {{ $card->card_number_last4 }}</span>
                                    @endif
                                </p>

                                {{-- Route này là trang chi tiết/lịch sử của chính thẻ đó.
                                     Nền trắng mờ để nổi trên dải pastel mà không thêm viền. --}}
                                <a href="{{ $row['history_url'] }}"
                                   data-testid="card-history-link"
                                   class="shrink-0 inline-flex items-center whitespace-nowrap h-8 px-3 bg-white/80 hover:bg-white text-gray-700 text-xs font-semibold rounded-lg transition-colors">
                                    Chi tiết
                                </a>
                            </div>

                            {{-- ═══ THANH TIẾN ĐỘ ═══
                                 `desired_spend` = 100% chiều dài. KHÔNG có mục tiêu thì
                                 không vẽ thanh (không suy diễn chiều dài) và hiện dòng
                                 gợi ý thay vì một thanh 0% giả. --}}
                            @if ($hasGoal)
                                <div class="relative h-3 rounded-full bg-gray-100 border border-gray-200/80 overflow-hidden"
                                     data-testid="card-progress-track">

                                    {{-- Màu theo THỨ TỰ ƯU TIÊN:
                                         1. VƯỢT mức chi tiêu mong muốn  → TÍM
                                         2. CHƯA đạt Vạch-Min-Spend      → CAM
                                         3. Đã đạt Min, chưa vượt      → XANH
                                         Cả hai ngưỡng — Vạch-Min-Spend lẫn
                                         mức chi tiêu — đều so trên đúng số
                                         `spent` mà thanh này đang đo và dòng
                                         "Chi tiêu" in ra, nên người dùng đối
                                         chiếu được bằng mắt: thanh chưa tới
                                         vạch đỏ thì đang CAM. --}}
                                    <div class="h-full rounded-full transition-[width] duration-300"
                                         data-testid="card-progress-bar"
                                         style="width: {{ $progressWidth }}%"
                                         :style="'width:' + progressWidth(@js($cardKey)) + '%'"
                                         :class="isOverGoal(@js($cardKey))
                                             ? 'bg-purple-500'
                                             : (meetsMinimumFor(@js($cardKey)) ? 'bg-emerald-500' : 'bg-amber-500')"></div>

                                    {{-- Vượt mục tiêu: thanh kẹp 100% và màu TÍM — KHÔNG cho
                                         thanh dài hơn 100% (sẽ tràn ngang trên điện thoại).
                                         Đoạn cuối này luôn TÍM vì `x-show` chỉ mở khi đã
                                         vượt; trạng thái "đã vượt" do dòng chữ bên dưới nói. --}}
                                    <span class="absolute inset-y-0 right-0 w-1.5 bg-purple-500"
                                          data-testid="card-progress-overflow"
                                          x-show="isOverGoal(@js($cardKey))"
                                          aria-hidden="true"></span>

                                    {{-- Vạch đỏ: vị trí Vạch-Min-Spend lưu trong policy của thẻ đó. Chỉ vẽ
                                         khi ngưỡng > 0; vị trí = ngưỡng / mục tiêu, kẹp
                                         [0, 100] nên không tràn ra ngoài ô chứa. --}}
                                    @if ($hasMinimum)
                                        <span class="absolute inset-y-0 w-0.5 bg-red-500"
                                              data-testid="card-minimum-marker"
                                              style="left: {{ $minimumLeft }}%"
                                              :style="'left:' + minimumLeft(@js($cardKey)) + '%'"
                                              title="Mức chi tiêu tối thiểu"></span>
                                    @endif
                                </div>

                                {{-- Nói rõ bằng chữ việc đã vượt mục tiêu. Đặt ở ĐÂY, cạnh
                                     thanh, chứ không nhét vào dòng cashback: dòng cashback
                                     nằm sau `x-show="showSection('cashback')"` nên bỏ tick
                                     ô "Cashback" sẽ nuốt mất thông báo này. `x-show` thay
                                     vì `@if` để nó còn đúng sau khi lưu giao dịch, khi
                                     server chưa render lại trang. --}}
                                <span class="mt-0.5 block text-[11px] font-medium text-purple-600"
                                      data-testid="card-progress-goal-state"
                                      x-show="isOverGoal(@js($cardKey))">Đã vượt mục tiêu</span>
                            @endif

                            {{-- ═══ DÒNG CHI TIÊU ═══
                                 "Chi tiêu : 8.000.000đ / 15.000.000đ" — số đã chi nổi bật,
                                 mục tiêu đi theo màu trung tính. Một dòng, không ô vuông. --}}
                            <div class="flex items-baseline justify-between gap-2 text-sm min-w-0"
                                 x-show="showSection('spend')"
                                 data-testid="card-spend-row">
                                <span class="min-w-0 truncate">
                                    <span class="text-gray-500">Chi tiêu :</span>
                                    <span class="font-bold text-gray-900 tabular-nums"
                                          x-text="ccMoneyVnd(metricFor(@js($cardKey), 'spent'))"><x-credit-card.money :value="$metrics['spent'] ?? '0.00'" /></span>
                                    @if ($hasGoal)
                                        <span class="text-gray-500 tabular-nums"
                                              x-text="' / ' + ccMoneyVnd(metricFor(@js($cardKey), 'desired_spend'))">/<x-credit-card.money :value="$metrics['desired_spend'] ?? '0.00'" /></span>
                                    @endif
                                </span>

                                {{-- Vạch đỏ không tự giải thích được, nên nói rõ bằng chữ
                                     — nhưng chỉ khi thẻ THẬT SỰ có ngưỡng tối thiểu. --}}
                                @if ($hasMinimum)
                                    <span class="shrink-0 text-[11px] font-medium text-red-600"
                                          data-testid="card-minimum-caption">
                                        Tối thiểu <span x-text="ccMoneyVnd(metricFor(@js($cardKey), 'minimum_spend'))"><x-credit-card.money :value="$metrics['minimum_spend'] ?? '0.00'" /></span>
                                    </span>
                                @endif
                            </div>

                            {{-- ═══ CASHBACK DỰ KIẾN ═══
                                 Cả tử số lẫn mẫu số đều theo BẬC ĐÍCH — bậc chọn
                                 theo `desired_spend`, không phải theo chi tiêu thực tế.
                                 Tử số là `expected_cashback` (server nhân CHI TIÊU
                                 THỰC TẾ với rate của bậc đích, đã kẹp theo trần
                                 bậc); mẫu số là trần chung của chính bậc đó. --}}
                            <div class="flex items-baseline justify-between gap-2 text-sm min-w-0"
                                 x-show="showSection('cashback')"
                                 data-testid="card-cashback-row">
                                <span class="min-w-0 truncate">
                                    <span class="text-gray-500">Cashback dự kiến :</span>
                                    <span class="font-bold text-gray-900 tabular-nums"
                                          x-text="ccMoneyVnd(metricFor(@js($cardKey), 'expected_cashback'))"><x-credit-card.money :value="$metrics['expected_cashback'] ?? '0.00'" /></span>
                                    @if ($hasTierCashbackMax)
                                        <span class="text-gray-500 tabular-nums"
                                              x-text="' / ' + ccMoneyVnd(tierCashbackMax(@js($cardKey)))">/<x-credit-card.money :value="$tierCashbackMax" /></span>
                                    @endif
                                </span>
                            </div>

                            {{-- ═══ QUOTA HOÀN TIỀN ═══
                                 Mỗi rule một dòng. KHÔNG có rule quota nào thì KHÔNG hiện
                                 cả tiêu đề — tránh một mục rỗng trên trang (§12). --}}
                            @if ($quotaRules !== [])
                                <div class="pt-0.5 min-w-0"
                                     x-show="showSection('quota')"
                                     data-testid="card-quota-section">
                                    <p class="text-xs font-semibold text-gray-700 mb-0.5">Quota hoàn tiền</p>

                                    <ul class="space-y-0.5">
                                        @foreach ($quotaRules as $ruleIndex => $quotaRule)
                                            @php
                                                // Tên hiển thị: combo thì tên combo, còn lại
                                                // tên danh mục. Chỉ CHỌN nhãn — không suy
                                                // luận danh mục hay quyết định có tính quota
                                                // hay không (đó là việc của Phase 2).
                                                $quotaLabel = $quotaRule['target_scope'] === 'combo'
                                                    ? ($quotaRule['combo_name'] ?: ($quotaRule['name'] ?: 'Combo'))
                                                    : ($quotaRule['category_name'] ?: ($quotaRule['name'] ?: 'Danh mục'));
                                            @endphp
                                            {{-- `flex-wrap` + `basis-full` cho phép dòng tự xuống
                                                 dòng trên màn 390px thay vì bị bóp nghẹt hoặc
                                                 tràn ngang (§3, §9). --}}
                                            <li class="flex flex-wrap items-baseline gap-x-1.5 text-xs min-w-0"
                                                data-testid="card-quota-row">
                                                <span class="shrink-0 max-w-[42%] truncate font-medium text-gray-700">{{ $quotaLabel }}</span>
                                                <span class="text-gray-300">:</span>

                                                <span class="min-w-0 text-gray-600 tabular-nums">
                                                    {{-- ═══ BA NHÁNH TRÌNH BÀY ĐÃ CHỐT ═══
                                                         A. có trần riêng, còn phòng:
                                                            [đã dùng] / [trần] → Có thể chi thêm ~[x]
                                                         B. có trần riêng, HẾT phòng:
                                                            [trần] / [trần] · HẾT QUOTA
                                                         C. KHÔNG trần riêng:
                                                            [đã dùng] → Có thể chi thêm ~[x]
                                                            (hết phòng ⇒ [đã dùng] · HẾT QUOTA)

                                                         KHÔNG còn nhánh "· còn X đ": số đó là ngân
                                                         sách chung, in chung với dòng riêng khiến user
                                                         tưởng là hạn mức của chính danh mục (§4, §11).

                                                         Tử số in `cashback_used_display`, KHÔNG phải
                                                         `cashback_used`: snapshot có thể ghi 0đ (engine
                                                         chạy bậc theo chi tiêu thực tế) trong khi số
                                                         tiền đã chi vẫn ăn vào trần bậc đích. Tử số đã
                                                         được service kẹp theo trần nên không bao giờ
                                                         vượt mẫu số. Nhánh B in thẳng trần làm tử số —
                                                         "hết quota" nói phòng đã cạn, nên con số quan
                                                         trọng là trần, không phải mức đã dùng. --}}
                                                    @if ($quotaRule['has_cashback_max'])
                                                        @if ($quotaRule['is_exhausted'])
                                                            {{-- B — hết quota: `[trần] / [trần] · HẾT QUOTA`. --}}
                                                            <span class="font-semibold text-gray-900"
                                                                  x-text="ccMoneyVnd(quotaRuleValue(@js($cardKey), {{ $ruleIndex }}, 'cashback_max'))"><x-credit-card.money :value="$quotaRule['cashback_max']" /></span>
                                                            <span class="text-gray-400"
                                                                  x-text="' / ' + ccMoneyVnd(quotaRuleValue(@js($cardKey), {{ $ruleIndex }}, 'cashback_max'))"> / <x-credit-card.money :value="$quotaRule['cashback_max']" /></span>
                                                            <span class="font-semibold text-rose-600"
                                                                  data-testid="card-quota-exhausted">· HẾT QUOTA</span>
                                                        @else
                                                            {{-- A — `[đã dùng] / [trần] → ước lượng`.

                                                                 Khoảng trắng quanh "/" phải có Ở PHÍA
                                                                 SERVER: `x-text` ghi `' / '` nên sau khi
                                                                 Alpine thay nội dung sẽ là " / 400.000 đ",
                                                                 còn nếu markup không có khoảng trắng thì lúc
                                                                 mới tải trang nó là "/400.000 đ" — dòng tiền
                                                                 nhảy khi Alpine chạy. --}}
                                                            <span class="font-semibold text-gray-900"
                                                                  x-text="ccMoneyVnd(quotaRuleValue(@js($cardKey), {{ $ruleIndex }}, 'cashback_used_display'))"><x-credit-card.money :value="$quotaRule['cashback_used_display']" /></span>
                                                            <span class="text-gray-400"
                                                                  x-text="' / ' + ccMoneyVnd(quotaRuleValue(@js($cardKey), {{ $ruleIndex }}, 'cashback_max'))"> / <x-credit-card.money :value="$quotaRule['cashback_max']" /></span>
                                                            @if ($quotaRule['spend_remaining_estimate'] !== null)
                                                                @if ($quotaRule['spend_estimate_is_reachable'])
                                                                    <span class="font-semibold text-emerald-600"
                                                                          data-testid="card-quota-estimate">→ Có thể chi thêm ~<x-credit-card.money :value="$quotaRule['spend_remaining_estimate']" /></span>
                                                                @else
                                                                    <span class="text-gray-400">· không đủ dải rate</span>
                                                                @endif
                                                            @endif
                                                        @endif
                                                    @else
                                                        {{-- C — không có trần riêng: KHÔNG bịa số làm
                                                             mẫu số (§11), KHÔNG in "· còn". Đã dùng +
                                                             ước lượng là đủ để user hành động. --}}
                                                        <span class="font-semibold text-gray-900"
                                                              x-text="ccMoneyVnd(quotaRuleValue(@js($cardKey), {{ $ruleIndex }}, 'cashback_used_display'))"><x-credit-card.money :value="$quotaRule['cashback_used_display']" /></span>
                                                        @if ($quotaRule['is_exhausted'])
                                                            <span class="font-semibold text-rose-600"
                                                                  data-testid="card-quota-exhausted">· HẾT QUOTA</span>
                                                        @elseif ($quotaRule['spend_remaining_estimate'] !== null)
                                                            @if ($quotaRule['spend_estimate_is_reachable'])
                                                                <span class="font-semibold text-emerald-600"
                                                                      data-testid="card-quota-estimate">→ Có thể chi thêm ~<x-credit-card.money :value="$quotaRule['spend_remaining_estimate']" /></span>
                                                            @else
                                                                <span class="text-gray-400">· không đủ dải rate</span>
                                                            @endif
                                                        @endif
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- ═══ ĐIỀU KIỆN HOÀN TIỀN ĐẶC BIỆT ═══
                                 Chỉ thêm một block HIỂN THỊ giống hệt Quota: đọc payload
                                 `spend_qualification` mà `CreditCardOverviewService` đã
                                 tính sẵn (actual = chi tiêu THỰC TẾ của kỳ hiện tại của
                                 chính thẻ này; other = tổng kỳ − danh mục loại trừ) —
                                 Blade/JS KHÔNG có công thức nào.

                                 KHÔNG hiện khi: card không có qualification, qualification
                                 tắt, hoặc không có điều kiện nào bật — server đã trả
                                 `null` và số `0 / min` của card chưa chi tiêu vẫn là
                                 trạng thái thật (edge case "không có transaction").
                                 KHÔNG đọc System Policy/template để quyết định hiển thị. --}}
                            @php
                                $spendQualification = $metrics['spend_qualification'] ?? null;
                                $sqConditions = $spendQualification !== null ? ($spendQualification['conditions'] ?? []) : [];
                            @endphp
                            @if ($spendQualification !== null && $sqConditions !== [])
                                <div class="pt-0.5 min-w-0"
                                     x-show="showSection('qualification')"
                                     data-testid="card-qualification-section">
                                    <p class="text-xs font-semibold text-gray-700 mb-0.5">Điều kiện hoàn tiền đặc biệt</p>

                                    <ul class="space-y-0.5">
                                        @foreach ($sqConditions as $sqIndex => $sqCondition)
                                            {{-- Giữ đúng sort_order của qualification; "Lĩnh vực
                                                 khác" luôn ở cuối (bất biến domain, server đã
                                                 chuẩn hoá). Mỗi dòng: nhãn → " : " → actual / min
                                                 → trạng thái, không có thanh tiến độ. --}}
                                            <li class="flex flex-wrap items-baseline gap-x-1.5 text-xs min-w-0"
                                                data-testid="card-qualification-row">
                                                <span class="shrink-0 max-w-[42%] truncate font-medium text-gray-700">{{ $sqCondition['label'] }}</span>
                                                <span class="text-gray-300">:</span>

                                                <span class="min-w-0 text-gray-600 tabular-nums">
                                                    {{-- actual / min. KHÔNG nối " đ" sau
                                                         `x-credit-card.money` — hậu tố nằm sẵn trong
                                                         component (§9). --}}
                                                    <span class="font-semibold text-gray-900"
                                                          x-text="ccMoneyVnd(qualificationConditionValue(@js($cardKey), {{ $sqIndex }}, 'actual_spend'))"><x-credit-card.money :value="$sqCondition['actual_spend']" /></span>
                                                    <span class="text-gray-400"
                                                          x-text="' / ' + ccMoneyVnd(qualificationConditionValue(@js($cardKey), {{ $sqIndex }}, 'min_spend'))"> / <x-credit-card.money :value="$sqCondition['min_spend']" /></span>

                                                    {{-- Trạng thái: ĐÃ ĐẠT khi actual >= min, còn lại
                                                         "Còn thiếu <số>". KHÔNG hiện "Còn thiếu 0 đ"
                                                         khi đã đạt (§7). --}}
                                                    @if ($sqCondition['met'])
                                                        <span class="font-semibold text-emerald-600"
                                                              data-testid="card-qualification-met">→ ĐÃ ĐẠT</span>
                                                    @else
                                                        <span class="font-semibold text-rose-600"
                                                              data-testid="card-qualification-remaining">→ Còn thiếu <span
                                                                  x-text="ccMoneyVnd(qualificationConditionValue(@js($cardKey), {{ $sqIndex }}, 'remaining'))"><x-credit-card.money :value="$sqCondition['remaining']" /></span></span>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{-- ═══ SAO KÊ KỲ VỪA KẾT THÚC ═══
                                 CHỈ ĐỌC. Sao kê là số tiền thực tế trên bảng
                                 kê của ngân hàng, nên nó KHÔNG được cộng vào
                                 `spent`, `expected_cashback`, quota hay thanh tiến
                                 độ ở trên — bỏ ô này đi, các số đó không đổi
                                 một đồng nào.

                                 Chỉ hiện khi kỳ đã kết thúc gần nhất của thẻ.
                                 Kỳ chưa có dòng sao kê VẪN hiện đủ: số tiền
                                 0/0/0, hạn trả và trạng thái thanh toán. Ẩn
                                 cả khối chỉ vì chưa nhập số là nói dối — "0 đ" ở
                                 đây là trạng thái mặc định đúng của kỳ, và
                                 chính trạng thái thanh toán mới là thứ người
                                 dùng cần thấy. --}}
                            @php
                                // MỘT nguồn cho cả dòng hạn lẫn cảnh báo:
                                // `paymentState()` đã quét `paid` TRƯỚC và trả
                                // `due_line` không kèm "quá hạn N ngày" khi kỳ đã
                                // trả. Tổng quan và màn Sao kê in ra cùng một
                                // payload nên không thể lệch nhau.
                                $payment = $row['statement']['payment'];
                                $alert = $payment['alert'];
                            @endphp

                            <div class="pt-0.5 min-w-0"
                                 x-show="showSection('statement')"
                                 data-testid="card-statement-section">
                                <p class="text-xs font-semibold text-gray-700 mb-0.5">Sao kê kỳ vừa kết thúc</p>

                                <p class="text-[11px] text-gray-500 tabular-nums"
                                   data-testid="card-statement-period">
                                    Kỳ {{ $row['statement']['start_label'] }} &ndash; {{ $row['statement']['end_label'] }}
                                </p>

                                {{-- CHỈ DƯ NỢ CUỐI KỲ.
                                     KHÔNG in "Chi tiêu thực tế" / "Hoàn thưởng thực
                                     tế" ở đây: đó là hai số của màn Sao kê, và
                                     Tổng quan đã có ô "Số tiền đã chi tiêu" riêng
                                     cho việc đó. In cả hai chỗ là lặp lại cùng một
                                     dữ liệu ở hai nơi, và làm card cao lên ở
                                     mobile chỉ vì hai dòng không ai đọc ở màn
                                     này. Dư nợ cuối kỳ thì phải giữ: đó là
                                     câu hỏi duy nhất Tổng quan cần trả lời —
                                     "thẻ này còn nợ bao nhiêu".

                                     Số đọc từ `payment` — kỳ ảo trả về
                                     `0.00`, cùng nguồn với màn Sao kê. KHÔNG dùng
                                     `$row['statement']['actual_spend']`: bản đó null
                                     khi chưa có dòng và sẽ khoét đúng số liệu mà
                                     người dùng cần thấy. --}}
                                <dl class="space-y-0.5" data-testid="card-statement-values">
                                    <div class="flex flex-wrap items-baseline gap-x-1.5 text-xs min-w-0">
                                        <dt class="shrink-0 text-gray-500">Dư nợ cuối kỳ</dt>
                                        <dd class="min-w-0 font-semibold text-gray-900 tabular-nums"
                                            data-testid="card-statement-closing-balance">
                                            <x-credit-card.money :value="$payment['closing_balance']" />
                                        </dd>
                                    </div>
                                </dl>

                                {{-- Nhắc nhẹ: phân biệt "0 vì chưa nhập" với "0 vì đã
                                     nhập". Cờ do server tính, không suy từ số 0 ở
                                     view. --}}
                                <p @class(['text-[11px] mt-0.5 min-w-0', 'hidden' => $payment['statement_data_entered']])
                                   data-testid="card-statement-empty">
                                    Chưa nhập sao kê cho kỳ này.
                                    <a href="{{ $row['statements_url'] }}" class="underline">Nhập tại trang Sao kê</a>
                                </p>

                                <p class="text-[11px] mt-0.5 min-w-0"
                                   data-testid="card-statement-due">
                                    @if ($payment['due_line'] === null)
                                        <span class="text-gray-400">Chưa có hạn thanh toán</span>
                                    @else
                                        <span @class([
                                                'font-semibold',
                                                'text-red-700' => $payment['due_tone'] === 'danger',
                                                'text-amber-700' => $payment['due_tone'] === 'warning',
                                                'text-emerald-700' => $payment['due_tone'] === 'settled',
                                                'text-gray-600' => $payment['due_tone'] === 'neutral',
                                            ])>{{ $payment['due_line'] }}</span>
                                    @endif
                                </p>

                                {{-- ═══ TRẠNG THÁI + CẢNH BÁO THANH TOÁN ═══
                                     Cả hai đều thuộc KỲ ĐÃ KẾT THÚC GẦN NHẤT
                                     của thẻ (đúng khối này), và do
                                     `CreditCardStatementService::paymentState()`
                                     đã resolve từ hạn trả của chính kỳ đó với
                                     số ngày nhắc CHUNG của user — Blade chỉ in ra.

                                     Cố ý KHÔNG in số ngày nhắc ở từng thẻ: đó là
                                     một con số chung cho cả tài khoản, in lặp ở
                                     mỗi thẻ chỉ làm rối mà không thêm thông tin.

                                     Luôn render + `hidden` để khối này không phụ
                                     thuộc JavaScript; test kiểm qua lớp `hidden`. --}}
                                <div @class([
                                        'rounded-lg border px-2 py-1.5 mt-1 min-w-0',
                                        'hidden' => $alert === null,
                                        'border-red-300 bg-red-50' => $alert !== null && $alert['tone'] === 'danger',
                                        'border-amber-300 bg-amber-50' => $alert !== null && $alert['tone'] !== 'danger',
                                    ])
                                     data-testid="card-statement-alert"
                                     data-statement-alert>
                                    <p class="text-xs font-bold leading-snug break-words"
                                       data-statement-alert-title>{{ $alert['title'] ?? '' }}</p>
                                    <p @class(['text-[11px] mt-0.5 opacity-90 break-words tabular-nums', 'hidden' => ($alert['detail'] ?? null) === null])
                                       data-statement-alert-detail>{{ $alert['detail'] ?? '' }}</p>
                                </div>

                                <p class="text-[11px] mt-1 min-w-0"
                                   data-testid="card-statement-payment-status">
                                    @if ($payment['is_paid'])
                                        <span class="font-semibold text-emerald-700">&#10003; ĐÃ THANH TOÁN</span>
                                    @else
                                        <span class="text-gray-600">{{ $payment['status_label'] }}</span>
                                    @endif
                                </p>
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
             * Khoá lưu lựa chọn hiển thị của Tổng quan.
             *
             * Chỉ là TÙY CHỌN HIỂN THỊ: bỏ tick chỉ ẩn phần tương ứng trên mọi thẻ,
             * không đổi dữ liệu, không gọi API, không reload trang. Vì vậy để trong
             * `localStorage` là đủ — không cần cột DB, migration hay endpoint.
             */
            const ccDisplayStorageKey = 'cc.overview.display';

            /**
             * 5 tùy chọn độc lập; MẶC ĐỊNH bật hết.
             *
             * Danh sách khoá đóng ở đây để khi đọc từ `localStorage` không tin
             * bừa thuộc tính lạ trong JSON — nếu không, một giá trị rác sẽ tạo ra
             * tên thuộc tính mà markup không dùng đến.
             *
             * Thêm khoá mới chỉ cần thêm ở đây và thêm một checkbox + một khối
             * `x-show="showSection(...)"`: markup cũ đã lưu không có khoá mới thì
             * `ccReadStoredDisplay()` bỏ qua nó, và khoá mới giữ nguyên mặc định
             * bật — không phá vỡ lựa chọn của người dùng đang dùng trang.
             */
            function ccDefaultDisplay() {
                return { spend: true, cashback: true, quota: true, qualification: true, statement: true };
            }

            /**
             * Đọc lựa chọn đã lưu, chỉ nhận đúng 5 khoá boolean ở trên.
             *
             * Trả `null` khi chưa lưu, JSON hỏng, hoặc không còn khoá nào hợp lệ —
             * khi đó dùng mặc định bật hết. Mọi lỗi bị nuốt: `localStorage` có thể
             * bị chặn (chế độ riêng tư, quota đầy) và đó không phải lý do để hỏng
             * cả trang.
             */
            function ccReadStoredDisplay() {
                try {
                    const raw = window.localStorage.getItem(ccDisplayStorageKey);

                    if (!raw) return null;

                    const parsed = JSON.parse(raw);

                    if (!parsed || typeof parsed !== 'object') return null;

                    const defaults = ccDefaultDisplay();
                    const restored = {};

                    for (const key of Object.keys(defaults)) {
                        if (typeof parsed[key] === 'boolean') {
                            restored[key] = parsed[key];
                        }
                    }

                    return Object.keys(restored).length > 0 ? restored : null;
                } catch (e) {
                    return null;
                }
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

                    display: ccDefaultDisplay(),

                    /**
                     * Khôi phục lựa chọn hiển thị đã lưu. `init()` là hook đặc biệt của
                     * Alpine, chạy một lần khi component khởi tạo.
                     */
                    init() {
                        const stored = ccReadStoredDisplay();

                        if (stored) {
                            this.display = { ...this.display, ...stored };
                        }
                    },

                    /** Section có đang được hiển thị không (mặc định: có). */
                    showSection(key) {
                        return this.display?.[key] !== false;
                    },

                    /** Ghi lựa chọn hiển thị để sống sót qua việc chuyển trang. */
                    persistDisplay() {
                        try {
                            window.localStorage.setItem(ccDisplayStorageKey, JSON.stringify(this.display));
                        } catch (e) {
                            // Không lưu được thì chỉ mất lựa chọn sau khi tải lại
                            // trang, vẫn tốt hơn là báo lỗi.
                        }
                    },

                    formOpen: false,
                    busy: false,
                    error: '',
                    notice: '',
                    fieldErrors: {},
                    form: ccBlankTransactionForm(state.today),

                    // Trạng thái khoá cuộn trang phía sau + vị trí cuộn đã lưu, để
                    // đóng overlay lại trả nguyên trạng vị trí.
                    scrollLocked: false,
                    savedScrollY: 0,

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

                    /**
                     * Ranh giới kỳ sao kê hiện tại (`period_start`/`period_end`) vẫn
                     * được server gửi xuống nhưng CỐT ý KHÔNG dùng để chặn ngày:
                     * ngày giao dịch là dữ liệu độc lập, mọi ngày đều hợp lệ.
                     */

                    /** Số liệu từng thẻ từ server. Khoá JSON là chuỗi. */
                    metricFor(id, key) {
                        return this.card_metrics?.[String(id)]?.[key] ?? null;
                    },

                    quotaFor(id, key) {
                        return this.card_metrics?.[String(id)]?.quota?.[key] ?? null;
                    },

                    /** Các rule ĐÃ TICK quota của thẻ (Phase 2 đã lọc sẵn). */
                    quotaRules(id) {
                        return this.card_metrics?.[String(id)]?.quota?.rules ?? [];
                    },

                    /**
                     * Một ô của rule quota theo THỨ TỰ.
                     *
                     * Tra theo chỉ số thay vì `x-for` để server vẫn render sẵn được
                     * danh sách (không JS vẫn đọc được, và test kiểm tra được HTML).
                     * Danh sách rule của một thẻ không đổi giữa hai lần làm mới cùng
                     * một phiên nên chỉ số vẫn trỏ đúng rule.
                     */
                    quotaRuleValue(id, index, key) {
                        return this.quotaRules(id)[index]?.[key] ?? null;
                    },

                    /**
                     * Một ô của khối điều kiện đặc biệt theo THỨ TỰ
                     * (tra theo chỉ số để server vẫn render sẵn, list không đổi giữa
                     * hai lần làm mới cùng một phiên — giống `quotaRuleValue`).
                     */
                    qualificationConditionValue(id, index, key) {
                        return this.card_metrics?.[String(id)]?.spend_qualification?.conditions?.[index]?.[key] ?? null;
                    },

                    /** Trần CHUNG của bậc đích — mẫu số của dòng "Cashback dự kiến". */
                    tierCashbackMax(id) {
                        return this.quotaFor(id, 'tier_cashback_max');
                    },

                    /**
                     * Bề rộng thanh tiến độ, kẹp [0, 100].
                     *
                     * Vượt mục tiêu vẫn vẽ 100% và đánh dấu bằng đoạn ở cuối thanh —
                     * thanh dài hơn 100% sẽ tràn khung và phá layout trên điện thoại.
                     */
                    progressWidth(id) {
                        return this.percentOf(id, 'progress_percent');
                    },

                    /** Vị trí vạch đỏ = ngưỡng tối thiểu / mục tiêu, cũng kẹp [0, 100]. */
                    minimumLeft(id) {
                        return this.percentOf(id, 'minimum_percent');
                    },

                    /** Kẹp một tỷ lệ server-sent về [0, 100] cho việc đặt CSS. */
                    percentOf(id, key) {
                        const value = Number(this.metricFor(id, key));

                        if (!Number.isFinite(value)) return 0;

                        return Math.max(0, Math.min(100, value));
                    },

                    /**
                     * Đã vượt mục tiêu chi tiêu chưa?
                     *
                     * Đọc giá trị CHƯA kẹp của server (`progress_percent`), vì thanh chỉ
                     * kẹp ở `percentOf()`. Vượt ngưỡng này thì ưu tiên màu TÍM, bất kể
                     * đã đạt vạch Min hay chưa.
                     */
                    isOverGoal(id) {
                        return Number(this.metricFor(id, 'progress_percent')) > 100;
                    },

                    /**
                     * Đã đạt Vạch-Min-Spend để sang màu xanh chưa?
                     *
                     * Server đã so sẵn (`spent >= min_total_spend`, với
                     * `min_total_spend` đọc từ policy riêng của thẻ); đây chỉ đọc
                     * kết quả. Thẻ không có Vạch-Min-Spend (`has_minimum` = false)
                     * thì `meets_minimum` luôn true → màu xanh bình thường.
                     */
                    meetsMinimumFor(id) {
                        return this.metricFor(id, 'meets_minimum') !== false;
                    },

                    /**
                     * Khoá cuộn trang PHÍA SAU — cách làm thật sự có tác dụng
                     * trên iPhone Safari.
                     *
                     * Riêng `overflow: hidden` trên `<body>` KHÔNG đủ: Safari vẫn
                     * cuộn trang từ vùng overlay, và khi thả tay còn "bật" (rubber
                     * band) được. Nên mình GHIM body tại chỗ bằng `position: fixed`
                     * và dịch nó lên đúng bằng `-scrollY`, nên:
                     *   - không còn khả năng cuộn trang dưới,
                     *   - và mở lại không làm trang nhảy vị trí cũ.
                     *
                     * `overflow: hidden` trên cả `<html>` và `<body>` để chặn nốt
                     * vùng cuộn ở cấp gốc.
                     */
                    lockPageScroll() {
                        if (this.scrollLocked) return;

                        this.savedScrollY = window.scrollY || window.pageYOffset || 0;

                        const html = document.documentElement;
                        const body = document.body;
                        this.savedHtmlOverflow = html.style.overflow;
                        this.savedBodyOverflow = body.style.overflow;
                        this.savedBodyPosition = body.style.position;
                        this.savedBodyTop = body.style.top;

                        html.style.overflow = 'hidden';
                        body.style.overflow = 'hidden';
                        body.style.position = 'fixed';
                        body.style.top = `-${this.savedScrollY}px`;
                        body.style.left = '0';
                        body.style.right = '0';
                        body.style.width = '100%';

                        this.scrollLocked = true;
                    },

                    /** Mở khoá và TRẢ LẠI ĐÚNG vị trí cuộn trước khi khoá. */
                    unlockPageScroll() {
                        if (!this.scrollLocked) return;

                        const html = document.documentElement;
                        const body = document.body;
                        html.style.overflow = this.savedHtmlOverflow || '';
                        body.style.overflow = this.savedBodyOverflow || '';
                        body.style.position = this.savedBodyPosition || '';
                        body.style.top = this.savedBodyTop || '';
                        body.style.left = '';
                        body.style.right = '';
                        body.style.width = '';

                        this.scrollLocked = false;
                        window.scrollTo(0, this.savedScrollY || 0);
                    },

                    /** Alpine gỡ component (điều hướng client-side) thì nhả khoá,
                     *  không để trang bị "đóng băng" vĩnh viễn. */
                    destroy() {
                        this.unlockPageScroll();
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

                        // Ngày mặc định là hôm nay — TIỆN ÍCH cho người dùng, không
                        // phải ràng buộc: sau đó họ chọn ngày nào cũng được.
                        this.form.transaction_date = this.today;
                        this.formOpen = true;

                        // Khoá cuộn trang phía sau (xem `lockPageScroll`).
                        this.lockPageScroll();
                    },

                    closeForm() {
                        this.formOpen = false;
                        this.form = ccBlankTransactionForm(this.today);
                        this.fieldErrors = {};
                        this.error = '';
                        this.notice = '';
                        this.unlockPageScroll();
                    },

                    /**
                     * Chặn submit khi ô nhập không hợp lệ TRƯỚC khi gọi mạng.
                     *
                     * Ô này ghi CHI TIÊU nên chỉ nhận số dương. Số âm là hoàn tiền
                     * và thuộc luồng khác (import Excel) — form nhập tay không cho
                     * gõ, server cũng chặn (`gt:0` trong `StoreTransactionRequest`).
                     *
                     * Ngày giao dịch CHỈ cần có mặt. KHÔNG so ngày với kỳ sao kê
                     * và không so với hôm nay: người dùng được chọn bất kỳ ngày nào,
                     * server tự suy ra kỳ chứa ngày đó.
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
