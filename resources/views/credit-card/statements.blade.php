{{--
    Sao kê THỰC TẾ — /thetindung/sao-ke

    ---------------------------------------------------------------------------
    KHÁC "LỊCH SỬ GIAO DỊCH"
    ---------------------------------------------------------------------------
    Giao dịch là thứ user nhập và engine cashback dùng để tính. Sao kê ở đây là con
    số user ĐỌC trên bảng kê của ngân hàng: nó có thể lệch với giao dịch (thiếu phiếu,
    sai số tiền), và việc nhập nó KHÔNG được động vào cashback. Vì thế trang này không
    có ô cashback dự kiến, không có %/rule/tier, và không import engine.

    ---------------------------------------------------------------------------
    DANH SÁCH RENDER Ở SERVER, JAVASCRIPT CHỈ LÀM LỊCH
    ---------------------------------------------------------------------------
    Cùng nguyên tắc trang Lịch sử giao dịch: `@forelse` in sẵn mọi dòng, Alpine chỉ
    mở form và làm mới đúng dòng vừa sửa. Không dựng danh sách bằng `<template x-for>`
    — làm vậy thì người không bật JavaScript ra trang trắng, và bộ máy tìm kiếm/
    trình đọc màn hình không đọc được nội dung mà trang này vốn dành cho chính họ.

    ---------------------------------------------------------------------------
    "SỐ DƯ CÒN PHẢI TRẢ" LUÔN LÀ SỐ SERVER TÍNH
    ---------------------------------------------------------------------------
    Cột này in `closing_balance` mà server gửi, KHÔNG phải `spend - reward` tính trong
    trình duyệt: hai nơi cùng một công thức là chỗ chắc chắn phát sinh lệch. Vì thế form
    nhập không có dòng xem trước — người dùng lưu xong mới thấy, và thấy đúng số sẽ ghi.

    ---------------------------------------------------------------------------
    MỞ TRANG KHÔNG ĐƯỢC GHI
    ---------------------------------------------------------------------------
    Controller dựng danh sách bằng `findForDate()` (chỉ đọc). Thẻ chưa có sao kê vẫn
    hiện một dòng kèm kỳ và hạn thanh toán, để thấy "đang nợ kỳ nào" trước khi nhập.

    ---------------------------------------------------------------------------
    NHẮC THANH TOÁN: MỘT Ô Ở ĐẦU TRANG, KHÔNG LẶP THEO TỪNG THẺ
    ---------------------------------------------------------------------------
    Số ngày nhắc là thiết lập CHUNG của người dùng cho mọi thẻ và mọi kỳ
    (`credit_card_user_settings`), nên chỉ có một ô duy nhất. Trước đây mỗi dòng thẻ
    có checkbox + ô số ngày riêng — người dùng phải khai báo lại cùng một con số cho
    từng thẻ, và các kỳ tạo sau sẽ mặc định khác nhau. Đó không phải khác biệt về
    nghiệp vụ, chỉ là chi phí vô ích và một cách gõ nhầm.
--}}
<x-credit-card.layout title="Sao kê" subtitle="Số tiền thực tế trên bảng kê của ngân hàng, theo từng kỳ."
                    active="statements">

    @include('credit-card.partials.money-js')
    {{-- `ccNormalizeSearch()` / `ccCardSearchMatchCount()` dùng chung với Tổng quan
         và Quản lý thẻ — xem partial. --}}
    @include('credit-card.partials.search-js')

    @include('credit-card.partials.sort-picker', [
        'sortModes' => $sortModes,
        'sortMode' => $sortMode,
        'sortAction' => $sortAction,
        'sortStorageKey' => $sortStorageKey,
    ])

    <div x-data="creditCardStatements(@js(['urls' => $urls, 'reminderDays' => $reminderDays]))"
         class="space-y-3 sm:space-y-4"
         {{-- `x-ref="cardList"` là container `ccCardSearchMatchCount` đọc để đếm dòng
              khớp — phải bọc đúng các dòng `data-testid="statement-row"`. --}}
         x-ref="cardList">

        {{-- ═══ NHẮC THANH TOÁN TRƯỚC — THIẾT LẬP CHUNG ═══
             MỘT ô cho cả trang, đặt TRƯỚC danh sách thẻ vì nó quyết định cảnh báo
             của mọi dòng bên dưới — đặt sau sẽ giống như thiết lập của thẻ cuối.

             KHÔNG có checkbox bật/tắt: nhắc trước luôn bật, và chỉ có một câu hỏi là
             "trước mấy ngày". Cờ bật/tắt sẽ tạo ra trạng thái "tắt" không có ngày bắt
             đầu cảnh báo, tức không tính được ngày phải cảnh báo.

             KHÔNG có nút "Lưu": đổi số ⇒ lưu ngay sau khoảng lặng
             (`@input.debounce.400ms` + `sendReminder()`).

             Mobile-first: nhãn và ô xuống hàng khi hẹp (`flex-wrap`), ô số `w-20`
             cố định nên không giật layout khi gõ từ 1 chữ sang 2 chữ. --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 min-w-0"
             data-testid="payment-reminder-setting">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 min-w-0">
                <label for="payment-reminder-days"
                       class="min-h-11 flex items-center text-sm font-medium text-gray-700 min-w-0">
                    Nhắc tôi trước khi hạn thanh toán
                </label>

                <div class="flex items-center gap-1.5">
                    <input type="number"
                           inputmode="numeric"
                           id="payment-reminder-days"
                           min="{{ $reminderMin }}"
                           max="{{ $reminderMax }}"
                           step="1"
                           data-testid="payment-reminder-input"
                           data-payment-reminder-days
                           value="{{ $reminderDays }}"
                           @input.debounce.400ms="saveReminder()"
                           @change="saveReminder()"
                           @keydown.enter.prevent="saveReminder()"
                           class="w-20 h-11 px-2 rounded-xl border-gray-200 text-sm tabular-nums
                                  focus:border-emerald-500 focus:ring-emerald-500"
                           aria-describedby="payment-reminder-range">
                    <span class="text-sm text-gray-500">ngày</span>
                </div>

                <p id="payment-reminder-range"
                   class="text-xs text-gray-500 min-w-0 basis-full sm:basis-auto sm:flex-1">
                    Áp dụng cho tất cả thẻ và mọi kỳ sao kê.
                </p>
            </div>

            {{-- Trạng thái lưu: nhỏ, chỉ hiện khi có việc. Màu do Alpine gắn theo
                 `tone` trả về từ `reminderLabel()` — KHÔNG dùng `@class(...)` gọi
                 hàm JS ở tầng Blade. --}}
            <p class="text-[11px] text-gray-400 mt-1.5"
               data-testid="payment-reminder-save-status"
               data-reminder-status-line
               x-text="reminderLabel().label"
               :class="{
                   'text-gray-400': reminderLabel().tone === 'idle',
                   'text-gray-500': reminderLabel().tone === 'busy',
                   'text-emerald-600': reminderLabel().tone === 'ok',
                   'text-red-600': reminderLabel().tone === 'error',
               }"></p>
        </div>

        <div x-show="error" x-cloak class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm text-red-700 break-words" x-text="error"></p>
        </div>

        @if (count($rows) > 0)
            {{-- ═══ TÌM KIẾM THẺ ═══
                 Lọc CLIENT-SIDE trên danh sách thẻ server đã sắp (theo thứ tự đang
                 chọn) — KHÔNG gọi mạng, KHÔNG đổi thứ tự. Rỗng ⇒ hiện đủ như trước.
                 Khớp tên HOẶC ngân hàng, bỏ dấu — cùng `ccNormalizeSearch` với Tổng
                 quan và Quản lý thẻ. --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 min-w-0">
                <label class="relative block">
                    <span class="sr-only">Tìm thẻ tín dụng</span>
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                        </svg>
                    </span>
                    <input type="search"
                           inputmode="search"
                           x-model="cardQuery"
                           data-testid="card-search-input"
                           placeholder="Tìm tên thẻ hoặc ngân hàng..."
                           class="w-full h-11 pl-10 pr-3 rounded-xl border border-gray-200 bg-white text-base sm:text-sm text-gray-800 placeholder:text-gray-400 focus:border-emerald-500 focus:ring-emerald-500">
                </label>
            </div>
        @endif

        @forelse ($rows as $row)
            @php
                $statement = $row['statement'];
                // Trạng thái thanh toán + nhắc: server đã resolve SẴN theo kỳ đang
                // chọn. Blade chỉ in ra — không tự so sánh ngày ở đây.
                $payment = $row['payment'];
                $paymentAlert = $payment['alert'];
            @endphp

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3 min-w-0"
                 data-testid="statement-row"
                 data-card-id="{{ $row['id'] }}"
                 {{-- `data-card-search` server in sẵn = `ccNormalizeSearch(name + ' ' + bank)`,
                      `x-show` đọc chính attribute đó — không tự nối lại trong JS. --}}
                 data-card-search="{{ mb_strtolower(\Illuminate\Support\Str::ascii(($row['name'] ?: 'Thẻ tín dụng').' '.($row['bank'] ?? ''))) }}"
                 x-show="cardMatches($el)">

                {{-- ═══ THẺ ═══
                     Chỉ 4 số cuối: module không lưu số thẻ đầy đủ ở đâu cả. --}}
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 min-w-0">
                    <span class="font-semibold text-gray-800">{{ $row['name'] }}</span>
                    @if ($row['bank'] !== null && $row['bank'] !== '')
                        <span class="text-xs text-gray-500">{{ $row['bank'] }}</span>
                    @endif
                    @if ($row['card_number_last4'] !== null && $row['card_number_last4'] !== '')
                        <span class="text-xs text-gray-400 tabular-nums">•••• {{ $row['card_number_last4'] }}</span>
                    @endif
                </div>

                {{-- ═══ CHỌN KỲ ═══
                     Mỗi thẻ MỘT dropdown riêng: hai thẻ có thể lệch chu kỳ, nên
                     một lựa chọn dùng chung sẽ không có nghĩa.

                     Đổi kỳ = ĐỔI TRANG, không gọi API: cả danh sách kỳ lẫn dòng
                     sao kê đều do server dựng, và server chỉ tin `period[<id>]`
                     trên query string. Nhờ vậy chọn kỳ vẫn dùng được khi không
                     bật JavaScript, và URL có thể chia sẻ/bookmark.

                     Hidden field giữ lại lựa chọn của CÁC THẺ KHÁC và sort mode:
                     một form GET nằm trong mỗi dòng, nên phải mang theo toàn bộ
                     trạng thái trang, không chỉ thẻ đang đổi. --}}
                <form method="GET" action="{{ route('credit-cards.statements') }}"
                      data-testid="statement-period-form-{{ $row['id'] }}">
                    {{-- `sort` luôn được mang theo, kể cả `manual`: `normalizeMode()`
                         tự đưa nó về `manual`, nên không có nhánh nào phải biết
                         giá trị hợp lệ của sort mode là gì. --}}
                    <input type="hidden" name="sort" value="{{ $sortMode }}">

                    @foreach ($rows as $other)
                        @if ((int) $other['id'] !== (int) $row['id'])
                            <input type="hidden" name="period[{{ $other['id'] }}]"
                                   value="{{ $other['period']['period_start'] }}">
                        @endif
                    @endforeach

                    <div class="flex flex-wrap items-end gap-2 min-w-0">
                        <div class="min-w-0 flex-1">
                            <label for="statement-period-{{ $row['id'] }}"
                                   class="block text-xs font-medium text-gray-700">
                                Kỳ sao kê
                            </label>
                            <select id="statement-period-{{ $row['id'] }}"
                                    name="period[{{ $row['id'] }}]"
                                    data-testid="statement-period-select-{{ $row['id'] }}"
                                    onchange="this.form.submit()"
                                    class="mt-1 w-full h-11 px-3 rounded-xl border-gray-200 text-sm
                                           focus:border-emerald-500 focus:ring-emerald-500">
                                @foreach ($row['periods'] as $option)
                                    <option value="{{ $option['period_start'] }}"
                                            @selected($option['period_start'] === $row['period']['period_start'])>
                                        {{ $option['start_label'] }} &ndash; {{ $option['end_label'] }}@if ($option['is_current']) (kỳ hiện tại)@endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Nút tải lại: `onchange` chỉ chạy khi có JS, nút này
                             giúp trang dùng được không JS. --}}
                        <button type="submit"
                                data-testid="statement-period-apply-{{ $row['id'] }}"
                                class="h-11 px-4 rounded-xl bg-gray-50 hover:bg-gray-100 text-gray-700
                                       text-sm font-semibold transition-colors">
                            Xem
                        </button>
                    </div>
                </form>

                {{-- ═══ KỲ ĐANG XEM ═══
                     In từ `period_bounds` (kỳ server suy ra ra), không phải
                     `period`: kỳ chưa có bản ghi nào vẫn cần biết đang xem kỳ
                     nào. --}}
                <p class="text-xs text-gray-500 tabular-nums"
                   data-testid="statement-period-label-{{ $row['id'] }}">
                    Kỳ {{ $row['period_bounds']['start_label'] }}
                    &ndash; {{ $row['period_bounds']['end_label'] }}
                </p>

                {{-- ═══ HẠN THANH TOÁN ═══
                     Cả câu chữ lẫn màu đều do SERVER chốt sẵn trong `payment`
                     (`due_line` + `due_tone`), cùng một nguồn với `alert`. Blade
                     KHÔNG tự so sánh ngày ở đây — chính chỗ tự tính lần trước đã
                     sinh ra lỗi "đã trả nhưng vẫn hiện quá hạn N ngày", vì nó
                     không biết `payment_status`.

                     Đã trả ⇒ `due_line` chỉ còn "Hạn thanh toán kỳ này: …" và
                     `due_tone = settled`: ngày vẫn hiện (người dùng cần biết hạn
                     của kỳ) nhưng không còn "quá hạn" và không còn màu đỏ. --}}
                <p class="text-xs">
                    @if ($payment['due_line'] === null)
                        <span class="text-gray-400">Chưa có hạn thanh toán</span>
                    @else
                        <span @class([
                                'font-semibold',
                                'text-red-700' => $payment['due_tone'] === 'danger',
                                'text-amber-700' => $payment['due_tone'] === 'warning',
                                'text-emerald-700' => $payment['due_tone'] === 'settled',
                                'text-gray-600' => $payment['due_tone'] === 'neutral',
                            ])
                              data-payment-due-line>{{ $payment['due_line'] }}</span>
                    @endif
                </p>

                {{-- ═══ THANH TOÁN ═══
                     Trạng thái trả thuộc TỪNG KỲ đang xem, lấy theo
                     `$row['payment']` mà server đã resolve từ hạn của CHÍNH kỳ này.
                     Chuyển sang kỳ khác là thấy trạng thái của kỳ đó, không phải kỳ
                     hiện tại.

                     Số ngày nhắc KHÔNG có ở đây: đó là thiết lập chung đã đặt ở đầu
                     trang. Mỗi dòng chỉ còn ô chọn trạng thái của chính kỳ này.

                     Kỳ chưa nhập sao kê VẪN có ô điều khiển: "hóa đơn này đã trả
                     chưa" là câu hỏi của KỲ, không phụ thuộc đã nhập số hay chưa.
                     Trước đây kỳ chưa có dòng thì ẩn hẳn cục này, bắt người dùng
                     phải nhập → lưu → tải lại trang mới đánh dấu được "đã trả". --}}
                {{-- ═══ CẢNH BÁO ═══
                     LUÔN render (kể cả khi không có cảnh báo) và chỉ ẩn bằng
                     `hidden`. Lý do: đổi trạng thái thanh toán phải bật/tắt cảnh
                     báo tại chỗ mà không tải lại trang; nếu render có điều kiện
                     thì lúc cần bật mà node chưa tồn tại, buộc phải dựng DOM
                     bằng tay — nơi dễ sinh markup lệch với server.

                     Nội dung vẫn do SERVER chốt sẵn (`title`/`detail`), không
                     có chỗ nào để view tự ghép câu chữ hay tự so sánh ngày.

                     `data-payment-alert` còn là mỏ neo cho `applyPayment()`
                     đổi màu theo `tone` server gửi. --}}
                <div @class([
                        'rounded-xl border px-3 py-2.5 min-w-0',
                        'hidden' => $paymentAlert === null,
                        'border-red-300 bg-red-50 text-red-800' => $paymentAlert !== null && $paymentAlert['tone'] === 'danger',
                        'border-amber-300 bg-amber-50 text-amber-900' => $paymentAlert !== null && $paymentAlert['tone'] !== 'danger',
                    ])
                     data-testid="payment-alert-{{ $row['id'] }}"
                     data-payment-alert>
                    <p class="text-sm font-bold leading-snug break-words"
                       data-payment-alert-title>{{ $paymentAlert['title'] ?? '' }}</p>
                    <p @class(['text-xs mt-0.5 opacity-90 break-words tabular-nums', 'hidden' => ($paymentAlert['detail'] ?? null) === null])
                       data-payment-alert-detail>{{ $paymentAlert['detail'] ?? '' }}</p>
                </div>

                {{-- ═══ ĐÃ THANH TOÁN ═══
                     Cũng luôn render + `hidden`, cùng lý do như cảnh báo: đổi
                     từ "chưa trả" sang "đã trả" phải hiện được ngay. `paid`
                     không bao giờ có `alert`, nên hai node này không bao giờ
                     cùng bật. --}}
                <p @class([
                        'text-xs font-semibold text-emerald-700 flex items-center gap-1',
                        'hidden' => ! $payment['is_paid'],
                    ])
                   data-testid="payment-paid-{{ $row['id'] }}"
                   data-payment-paid>
                    <span aria-hidden="true">&#10003;</span> ĐÃ THANH TOÁN
                </p>

                <div class="space-y-2"
                     data-testid="payment-controls-{{ $row['id'] }}"
                     data-payment-controls
                     data-payment-state="{{ json_encode($payment) }}">

                    {{-- ═══ COMBO TRẠNG THÁI ═══
                         MỘT select, không phải hai checkbox: hai trạng thái loại
                         trừ nhau, và hai ô riêng sẽ cho phép cả hai cùng bật —
                         tức dữ liệu tự mâu thuẫn mà không ai báo lỗi.

                         Đổi ⇒ `savePayment()` gửi ngay, không có nút "Lưu":
                         thao tác này là một từ khóa, thêm nút bấm chỉ tăng
                         việc phải làm.

                         Kỳ chưa có dòng sao kê thì select vẫn hiện, mặc định
                         "Chưa thanh toán" — nhưng CHƯA gửi gì: giữ nguyên
                         `unpaid` ảo không cần ghi, chỉ chọn "Đã thanh toán" mới
                         là hành động cần lưu. --}}
                    <div class="min-w-0">
                        <label for="payment-status-{{ $row['id'] }}"
                               class="block text-xs font-medium text-gray-700">
                            Trạng thái thanh toán
                        </label>
                        <select id="payment-status-{{ $row['id'] }}"
                                data-payment-status
                                @change="savePayment({{ $row['id'] }})"
                                class="mt-1 w-full h-11 px-3 rounded-xl border-gray-200 text-sm
                                       focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach (\App\Models\CreditCard\CreditCardStatement::PAYMENT_STATUS_LABELS as $value => $label)
                                <option value="{{ $value }}" @selected($payment['status'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Trạng thái lưu: nhỏ, chỉ hiện khi có việc. Màu do Alpine
                         gắn theo `tone` trả về từ `paymentLabel()` — KHÔNG
                         dùng `@class(...)` gọi hàm JS ở tầng Blade. --}}
                    <p class="text-[11px] text-gray-400"
                       data-testid="payment-save-status-{{ $row['id'] }}"
                       data-payment-status-line
                       x-text="paymentLabel({{ $row['id'] }}).label"
                       :class="{
                           'text-gray-400': paymentLabel({{ $row['id'] }}).tone === 'idle',
                           'text-gray-500': paymentLabel({{ $row['id'] }}).tone === 'busy',
                           'text-emerald-600': paymentLabel({{ $row['id'] }}).tone === 'ok',
                           'text-red-600': paymentLabel({{ $row['id'] }}).tone === 'error',
                       }"></p>

                    {{-- KHÔNG in lại dòng hạn ở đây. Trước đây khối ô chọn trạng
                         thái có in thêm một `<p data-payment-due-line>` nữa, và đó
                         chính là nơi sinh ra bug "đã trả nhưng vẫn đỏ quá hạn":
                         `applyPayment()` dùng `querySelector` nên chỉ dựng lại
                         node ĐẦU TIÊN, node thứ hai giữ nguyên chuỗi cũ. Dòng hạn
                         là thông tin của KỲ nên chỉ có một chỗ hiện — ngay dưới
                         tiêu đề thẻ, phía trên ô điều khiển. --}}
                </div>

                {{-- ═══ SỐ LIỆU ═══
                     Kỳ chưa nhập hiện 0 — ĐÚNG GIÁ TRỊ MẶC ĐỊNH của kỳ ảo — chứ
                     không phải "Chưa nhập"/"—". Lý do: ô trạng thái thanh toán luôn
                     hiện, nên một dòng "chưa nhập" ở giá tiền sẽ đọc như lỗi dữ liệu
                     khi thực ra chỉ là kỳ mới. Phân biệt "0 vì chưa nhập" với "0 vì
                     đã nhập" là việc của dòng nhắc nhẹ ngay dưới, theo cờ
                     `statement_data_entered` do server tính — thay vì để con số 0
                     tự mang ý nghĩa.

                     Số đọc từ `payment` (chính là payload `paymentState()` trả về)
                     để màn này và màn Tổng quan lấy CÙNG một nguồn. --}}
                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-2 pt-1">
                    <div>
                        <dt class="text-xs text-gray-500">Chi tiêu thực tế</dt>
                        {{-- Hoàn tiền: KHÔNG nối " đ" sau `x-credit-card.money` —
                             hậu tố nằm sẵn trong component. --}}
                        <dd class="font-semibold text-gray-800 tabular-nums" data-testid="statement-spend">
                            <x-credit-card.money :value="$payment['actual_spend']" />
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs text-gray-500">Hoàn/thưởng thực tế</dt>
                        <dd class="font-semibold text-gray-800 tabular-nums" data-testid="statement-reward">
                            <x-credit-card.money :value="$payment['actual_reward']" />
                        </dd>
                    </div>

                    <div class="col-span-2 sm:col-span-1 sm:text-right">
                        <dt class="text-xs text-gray-500">Còn phải trả</dt>
                        {{-- `data-statement-id` để JS biết dòng này ĐÃ có sao kê hay
                             chưa (POST khi nhập, PATCH khi sửa) mà không phải giữ
                             thêm một bản sao danh sách trong state. --}}
                        <dd class="font-bold text-gray-900 tabular-nums"
                            data-testid="statement-closing-balance"
                            data-statement-id="{{ $payment['statement_id'] ?? '' }}">
                            <x-credit-card.money :value="$payment['closing_balance']" />
                        </dd>
                    </div>
                </dl>

                {{-- ═══ CHƯA NHẬP SỐ LIỆU ═══
                     Dòng nhẹ phân biệt "0 vì chưa nhập" với "0 vì đã nhập". Luôn
                     render + `hidden` vì lý do như cảnh báo: nhập số xong nó phải
                     tắt ngay tại chỗ, không tải lại trang. --}}
                <p @class([
                        'text-[11px] text-gray-500',
                        'hidden' => $payment['statement_data_entered'],
                    ])
                   data-testid="statement-no-data-{{ $row['id'] }}"
                   data-payment-no-data>
                    Chưa nhập sao kê thực tế cho kỳ này — số tiền ở trên là mặc định 0.
                </p>

                @if (! $row['editable'])
                    <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5">
                        Kỳ đã chốt, không sửa được
                    </p>
                @else
                    {{-- Thanh thao tác. Nút sửa mở form ngay trong dòng; nút xoá hỏi
                         lại trước khi gọi vì đây là tiền thật. --}}
                    <div class="flex items-center gap-2 border-t border-gray-100 pt-3">
                        <button type="button"
                                data-testid="statement-edit-{{ $row['id'] }}"
                                @click="openEdit({{ $row['id'] }})"
                                :disabled="busyId === {{ $row['id'] }}"
                                class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-50
                                       hover:bg-gray-100 text-gray-700 text-sm font-semibold transition-colors
                                       disabled:opacity-50">
                            {{-- Nhãn theo `statement_exists`, KHÔNG theo việc đã có số liệu hay chưa:
                             dòng `0/0/0` sinh ra từ thao tác đánh dấu "đã trả" vẫn là
                             một dòng thật cần sửa được. Việc "chưa nhập số" đã do dòng
                             nhắc nhẹ phía trên nói rõ. --}}
                            {{ $payment['statement_exists'] ? 'Sửa' : 'Nhập sao kê' }}
                        </button>

                        {{-- Nút "Xoá" LUÔN render, chỉ ẩn khi chưa có dòng: xoá cái gì không tồn tại
                         là nút bấm chết người dùng. Ẩn bằng `hidden` (không render
                         có điều kiện) vì đánh dấu "đã trả" sẽ tạo dòng ngay trong
                         phiên hiện tại — xem `syncDeleteButton()`. --}}
                        <button type="button"
                                @class([
                                    'inline-flex items-center justify-center h-11 px-4 rounded-xl bg-red-50',
                                    'hover:bg-red-100 text-red-600 text-sm font-semibold transition-colors',
                                    'disabled:opacity-50',
                                    'hidden' => ! $payment['statement_exists'],
                                ])
                                data-testid="statement-delete-{{ $row['id'] }}"
                                @click="destroy({{ $row['id'] }})"
                                :disabled="busyId === {{ $row['id'] }}">
                            Xoá
                        </button>
                    </div>

                    {{-- Form 2 ô, mở tại chỗ trong dòng. Không dùng <form> + POST thật
                         vì Alpine đã giữ state; gửi JSON để khớp API và gắn lỗi 422
                         đúng ô. --}}
                    <div x-show="openId === {{ $row['id'] }}" x-cloak class="border-t border-gray-100 pt-3 space-y-3">
                        <form @submit.prevent="save({{ $row['id'] }})" novalidate class="space-y-3">
                            {{-- KỲ NÀO — theo NGÀY MỞ KỲ, không theo tháng. Hidden
                                 vì người dùng chọn kỳ ở dropdown phía trên, form
                                 chỉ việc mang theo: đổi kỳ mà quên đổi form thì
                                 tiền rơi nhầm kỳ. --}}
                            <input type="hidden" name="period_start"
                                   value="{{ $row['period']['period_start'] }}">

                            {{-- Ký tự hậu tố resolve MỘT lần cho cả hai ô — suffix
                                 rỗng (user bỏ ký tự) thì không render hậu tố và
                                 không giữ padding pr-10 thừa. --}}
                            @php $ccSuffix = \App\Support\CreditCard\CreditCardMoneyFormatter::suffix(auth()->id() ?? null); @endphp

                            <div>
                                <label for="statement-spend-{{ $row['id'] }}"
                                       class="block text-sm font-medium text-gray-700">
                                    Chi tiêu thực tế <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input id="statement-spend-{{ $row['id'] }}" name="actual_spend"
                                           type="text" inputmode="decimal" autocomplete="off"
                                           value="{{ \App\Support\CreditCard\CreditCardMoneyFormatter::input($statement['actual_spend'] ?? null, auth()->id() ?? null) }}"
                                           :disabled="busyId === {{ $row['id'] }}"
                                           class="mt-1 w-full h-11 px-3 {{ $ccSuffix !== '' ? 'pr-10 ' : '' }}rounded-xl border-gray-200 text-sm tabular-nums
                                                  focus:border-emerald-500 focus:ring-emerald-500"
                                           :class="errors.actual_spend ? 'border-red-400' : ''"
                                           placeholder="Nhập 0 nếu kỳ không chi">
                                    @if($ccSuffix !== '')
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">{{ $ccSuffix }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-red-600" x-show="errors.actual_spend" x-cloak
                                   x-text="errors.actual_spend"></p>
                            </div>

                            <div>
                                <label for="statement-reward-{{ $row['id'] }}"
                                       class="block text-sm font-medium text-gray-700">
                                    Hoàn/thưởng thực tế <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <input id="statement-reward-{{ $row['id'] }}" name="actual_reward"
                                           type="text" inputmode="decimal" autocomplete="off"
                                           value="{{ \App\Support\CreditCard\CreditCardMoneyFormatter::input($statement['actual_reward'] ?? null, auth()->id() ?? null) }}"
                                           :disabled="busyId === {{ $row['id'] }}"
                                           class="mt-1 w-full h-11 px-3 {{ $ccSuffix !== '' ? 'pr-10 ' : '' }}rounded-xl border-gray-200 text-sm tabular-nums
                                                  focus:border-emerald-500 focus:ring-emerald-500"
                                           :class="errors.actual_reward ? 'border-red-400' : ''"
                                           placeholder="Nhập 0 nếu không có hoàn">
                                    @if($ccSuffix !== '')
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-400">{{ $ccSuffix }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-red-600" x-show="errors.actual_reward" x-cloak
                                   x-text="errors.actual_reward"></p>
                            </div>

                            {{-- Cố tình KHÔNG có dòng "xem trước số còn phải trả": nó sẽ là
                                 công thức thứ hai của cùng một phép trừ, và chỗ hai nơi
                                 tính tiền là chỗ sinh lệch. Số này hiện ở trên, lấy từ
                                 server. --}}
                            <p class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
                                Số tiền còn phải trả sẽ được hệ thống tính và hiển thị ngay dòng trên sau khi lưu.
                            </p>

                            <div class="flex items-center justify-end gap-2">
                                <button type="button" @click="closeEdit({{ $row['id'] }})"
                                        class="h-10 px-4 rounded-xl text-sm font-semibold text-gray-600 hover:bg-gray-100">
                                    Huỷ
                                </button>
                                <button type="submit"
                                        :disabled="busyId === {{ $row['id'] }}"
                                        class="h-10 px-5 rounded-xl text-sm font-semibold text-white bg-emerald-600
                                               hover:bg-emerald-700 disabled:opacity-60">
                                    <span x-text="busyId === {{ $row['id'] }} ? 'Đang lưu…' : 'Lưu'"></span>
                                </button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 px-4 py-10 text-center">
                <p class="text-sm text-gray-500">Bạn chưa có thẻ tín dụng nào để lấy sao kê.</p>
                <a href="{{ route('credit-cards.manage') }}"
                   class="mt-3 inline-flex items-center justify-center h-11 px-4 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold">
                    Thêm thẻ ở Quản lý thẻ
                </a>
            </div>
        @endforelse

        {{-- Không có thẻ nào khớp từ khoá (chỉ hiện khi đang gõ từ khoá, không hiện
             lúc danh sách đầy đủ). Cùng `x-show`/`cardMatchCount` như Tổng quan. --}}
        <div x-show="cardQuery.trim() !== '' && cardMatchCount === 0"
             x-cloak
             data-testid="card-search-empty"
             class="bg-white rounded-2xl shadow-sm border border-gray-100 px-4 py-10 text-center">
            <p class="text-sm text-gray-500">Không tìm thấy thẻ phù hợp</p>
        </div>
    </div>
</x-credit-card.layout>

@once
    <script>
        /**
         * Sao kê thực tế — nhập / sửa / xoá trên API.
         *
         * Controller KHÔNG orchestration gì ở đây: thứ tự thẻ, kỳ sao kê, quyền sở
         * hữu và số "còn phải trả" đều do server trả về. Trình duyệt chỉ gửi hai ô
         * người dùng thực sự nhập, rồi đọc lại đúng số server đã ghi.
         */
        function creditCardStatements(state) {
            return {
                urls: state.urls ?? {},
                /** Số ngày nhắc server render sẵn; `init()` đọc lại từ ô nhập. */
                reminderDays: state.reminderDays ?? null,

                /** id thẻ đang mở form; `null` = không dòng nào mở. */
                openId: null,
                /** id thẻ đang gọi API, để khoá nút cho đúng dòng đang bận. */
                busyId: null,
                error: '',
                errors: {},

                // Ô tìm kiếm thẻ ở Sao kê. Lọc CLIENT-SIDE trên danh sách thẻ server
                // đã sắp (xem `CreditCardCardSortService`): rỗng ⇒ hiện tất cả, gõ ⇒
                // khớp tên HOẶC ngân hàng, bỏ dấu, không đổi thứ tự, không gọi mạng.
                // Cùng convention với Tổng quan (`index.blade.php`) và Quản lý thẻ.
                cardQuery: '',

                /** Dòng thẻ có khớp từ khoá không — dùng chung `ccNormalizeSearch`. */
                cardMatches(el) {
                    const keyword = ccNormalizeSearch(this.cardQuery);

                    if (keyword === '') return true;

                    return ccNormalizeSearch(el?.dataset?.cardSearch).includes(keyword);
                },

                /** Số dòng thẻ đang khớp từ khoá — để hiện empty state. */
                get cardMatchCount() {
                    return ccCardSearchMatchCount(this, 'statement-row');
                },

                /**
                 * Trạng thái lưu thanh toán, khoá theo id thẻ.
                 *
                 * Tách khỏi `busyId` của form tiền: đánh dấu "đã trả" không được
                 * khoá nút nhập tiền và ngược lại — hai việc độc lập.
                 */
                paymentSave: {},
                /** Debounce chờ tự tắt dòng "Đã lưu", khoá theo id thẻ. */
                paymentTimers: {},
                /**
                 * Trạng thái thanh toán SERVER đã render, khoá theo id thẻ.
                 *
                 * Cần để khi lưu hỏng thì kéo UI về đúng thứ đang được lưu, thay
                 * vì để nguyên giá trị người dùng vừa bấm. Nạp một lần ở `init()`
                 * từ `data-payment-state` của mỗi dòng.
                 */
                lastPayment: {},
                /**
                 * Payload đang chờ gửi lại, khoá theo id thẻ.
                 *
                 * Khi người dùng đổi control lúc một request khác còn bay, ta không
                 * gửi được ngay. Thay vì bỏ qua — khiến thao tác biến mất — giữ
                 * lại đúng giá trị họ vừa chọn và gửi tiếp ngay khi request kia xong.
                 */
                paymentQueued: {},

                /**
                 * Số ngày nhắc trước — thiết lập CHUNG của user cho mọi thẻ.
                 *
                 * Khởi tạo từ giá trị server render sẵn trong ô nhập. Giữ trong state
                 * để khi lưu hỏng thì kéo ô về đúng thứ server đang giữ, thay vì
                 * giữ nguyên số người vừa gõ.
                 */
                reminderDays: null,
                /** Trạng thái lưu của ô nhắc: `idle` | `busy` | `ok` | `error`. */
                reminderSave: { state: 'idle', message: '' },
                /** Timer tự tắt dòng "Đã lưu" của ô nhắc. */
                reminderTimer: null,

                init() {
                    // Form nằm trong DOM sẵ (render ở server), nên giá trị khởi tạo
                    // lấy thẳng từ input thay vì giữ một bản sao trong state.
                    this.$watch('openId', (id) => {
                        if (id === null) this.errors = {};
                    });

                    // Ô nhắc là MỘT ô duy nhất cho cả trang, nên đọc một lần ở đây
                    // thay vì lặp theo từng dòng thẻ.
                    const reminder = document.querySelector('[data-payment-reminder-days]');

                    if (reminder) {
                        this.reminderDays = Number(reminder.value);
                    }

                    // Nạp trạng thái thanh toán server đã render làm mốc để kéo về
                    // khi lưu hỏng. Blade escape (`e()`) lo phần `"` thành
                    // `&quot;` nên `dataset` trả về JSON thuần.
                    for (const node of document.querySelectorAll('[data-payment-state]')) {
                        const cardId = node.closest('[data-card-id]')?.dataset.cardId;

                        if (! cardId) continue;

                        try {
                            this.lastPayment[cardId] = JSON.parse(node.dataset.paymentState);
                        } catch (e) {
                            // JSON hỏng không được làm hỏng cả trang: bỏ qua dòng
                            // này, nó chỉ mất khả năng kéo về giá trị cũ.
                        }
                    }
                },

                openEdit(cardId) {
                    this.error = '';
                    this.errors = {};
                    this.openId = this.openId === cardId ? null : cardId;
                },

                closeEdit(cardId) {
                    if (this.busyId === cardId) return;
                    if (this.openId === cardId) this.openId = null;
                },

                formOf(cardId) {
                    const root = document.getElementById(`statement-spend-${cardId}`)?.closest('form');

                    if (!root) return null;

                    return {
                        actual_spend: ccMoneyParseInput(root.querySelector('[name="actual_spend"]')?.value ?? ''),
                        actual_reward: ccMoneyParseInput(root.querySelector('[name="actual_reward"]')?.value ?? ''),
                        // Kỳ do dropdown quyết định, form chỉ mang theo.
                        period_start: root.querySelector('[name="period_start"]')?.value ?? '',
                    };
                },

                /** Có dòng sao kê chưa — quyết định POST (nhập) hay PATCH (sửa). */
                statementUrl(cardId) {
                    const cell = document.querySelector(
                        `[data-card-id="${cardId}"] [data-testid="statement-closing-balance"]`,
                    );

                    const id = cell?.dataset.statementId;

                    if (! id) {
                        return { url: this.bind(this.urls.store, cardId), method: 'POST' };
                    }

                    return {
                        url: this.bind(this.urls.update, Number(id)),
                        method: 'PATCH',
                    };
                },

                async save(cardId) {
                    if (this.busyId !== null) return;

                    const payload = this.formOf(cardId);

                    if (payload === null) return;

                    this.busyId = cardId;
                    this.error = '';
                    this.errors = {};

                    const { url, method } = this.statementUrl(cardId);

                    const result = await this.request(url, {
                        method,
                        body: JSON.stringify(payload),
                    });

                    this.busyId = null;

                    if (! result) return;

                    // Ghi đè đúng dòng vừa sửa bằng số server trả về, thay vì tự
                    // cộng/trừ trong trình duyệt — nơi duy nhất có số chuẩn.
                    this.applyStatement(cardId, result.data, result.payment);
                    this.openId = null;
                },

                async destroy(cardId) {
                    if (this.busyId !== null) return;

                    const cell = document.querySelector(
                        `[data-card-id="${cardId}"] [data-testid="statement-closing-balance"]`,
                    );
                    const statementId = cell?.dataset.statementId;

                    // Chưa có dòng sao kê thì không có gì để xoá.
                    if (! statementId) return;

                    if (! window.confirm('Xoá sao kê của kỳ này?')) return;

                    this.busyId = cardId;
                    this.error = '';

                    const result = await this.request(this.bind(this.urls.destroy, Number(statementId)), {
                        method: 'DELETE',
                    });

                    this.busyId = null;

                    if (! result) return;

                    this.applyStatement(cardId, null, result.payment);
                },

                /**
                 * Cập nhật số hiển thị của đúng một dòng.
                 *
                 * `statement === null` = dòng CHƯA có sao kê. Khi đó phải dọn luôn
                 * `data-statement-id`, ô nhập, nhãn nút và nút xoá — không chỉ thay
                 * chữ. Nếu để sót id thì lần mở kế tiếp `statementUrl()` vẫn trả
                 * PATCH tới một dòng đã xoá (404), và nhãn "Sửa"/nút "Xoá" mô tả sai
                 * trạng thái thật của dòng.
                 *
                 * Số tiền rỗng hiện `0 đ` chứ không phải "Chưa nhập": khác biệt
                 * "chưa nhập" đã được dòng nhắc nhẹ đảm nhiệm, và ô trạng thái
                 * thanh toán luôn hiện nên "Chưa nhập" ở giá tiền đọc như lỗi.
                 */
                applyStatement(cardId, statement, payment) {
                    const row = document.querySelector(`[data-card-id="${cardId}"]`);

                    if (! row) return;

                    const set = (testId, text, muted) => {
                        const node = row.querySelector(`[data-testid="${testId}"]`);

                        if (! node) return;

                        node.textContent = text;
                        node.classList.toggle('text-gray-400', muted);
                    };

                    const closing = row.querySelector('[data-testid="statement-closing-balance"]');

                    if (statement === null) {
                        set('statement-spend', '0 đ', false);
                        set('statement-reward', '0 đ', false);

                        if (closing) {
                            closing.dataset.statementId = '';
                            closing.textContent = '0 đ';
                            closing.classList.remove('text-gray-400');
                        }

                        for (const name of ['actual_spend', 'actual_reward']) {
                            const input = row.querySelector(`[name="${name}"]`);

                            if (input) input.value = '';
                        }

                        const edit = row.querySelector('[data-testid^="statement-edit-"]');

                        if (edit) edit.textContent = 'Nhập sao kê';

                        this.syncDeleteButton(row, false);

                        this.errors = {};

                        return;
                    }

                    // Ngược lại: ghi id mà server vừa trả. Bỏ trống ô này thì lần sửa
                    // kế tiếp vẫn đi theo POST và đụng UNIQUE (mỗi cặp thẻ/kỳ chỉ
                    // một dòng) — tức là vừa nhập xong là sửa tiếp sẽ lỗi.
                    if (closing) {
                        closing.dataset.statementId = String(statement.id ?? '');
                        closing.classList.remove('text-gray-400');
                    }

                    set('statement-spend', ccMoneyVnd(statement.actual_spend), false);
                    set('statement-reward', ccMoneyVnd(statement.actual_reward), false);
                    set('statement-closing-balance', ccMoneyVnd(statement.closing_balance), false);

                    // Server gửi kèm trạng thái sau khi ghi: nhập số xong thì dòng
                    // "chưa nhập sao kê" phải tắt, còn trạng thái thanh toán phải giữ
                    // nguyên — đặc biệt khi người dùng đã đánh dấu "đã trả" TRƯỚC rồi
                    // mới nhập số. `applyPayment()` không tự so sánh ngày nên nhận
                    // luôn payload này thay vì giữ bản sao cũ trong state.
                    if (payment) this.applyPayment(cardId, payment);
                },

                /** Nhãn + màu của dòng "đang lưu / đã lưu / lỗi" cho một thẻ. */
                paymentLabel(cardId) {
                    const entry = this.paymentSave[cardId];

                    if (! entry) return { label: '', tone: 'idle' };
                    if (entry.state === 'saving') return { label: 'Đang lưu…', tone: 'busy' };
                    if (entry.state === 'saved') return { label: 'Đã lưu', tone: 'ok' };
                    if (entry.state === 'error') {
                        return { label: entry.message || 'Không thể lưu, thử lại.', tone: 'error' };
                    }

                    return { label: '', tone: 'idle' };
                },

                /**
                 * Trạng thái thanh toán của một dòng, đọc từ DOM.
                 *
                 * CHỈ có `payment_status`: số ngày nhắc là thiết lập chung, gửi qua
                 * endpoint riêng (`saveReminder()`). Gửi kèm ở đây sẽ cho phép lưu
                 * một kỳ âm thầm ghi đè thiết lập của cả những kỳ khác.
                 *
                 * Đọc ô đang hiện thay vì giữ bản sao trong state: nếu state và ô
                 * lệch nhau thì không biết cái nào là sự thật, và state là cái
                 * phải đoán.
                 */
                paymentPayload(cardId) {
                    const row = document.querySelector(`[data-card-id="${cardId}"]`);

                    if (! row) return null;

                    return {
                        payment_status: row.querySelector('[data-payment-status]')?.value ?? 'unpaid',
                        // Kỳ đang chọn. Endpoint theo DÒNG bỏ qua, endpoint theo Kỳ
                        // dùng để biết đánh dấu kỳ nào — mà không có nó thì kỳ đã đóng
                        // gần nhất sẽ bị ghi nhầm vào kỳ đang xem.
                        period_start: row.querySelector('[name="period_start"]')?.value ?? '',
                    };
                },

                /**
                 * Đích để đánh dấu trạng thái thanh toán của dòng.
                 *
                 * Có dòng sao kê ⇒ PATCH theo dòng. CHƯA có ⇒ PATCH theo KỲ, và
                 * server tự tạo dòng `0/0/0`. Đây là lý do ô trạng thái vẫn hiện khi
                 * kỳ chưa nhập sao kê: không có đường nào để đánh dấu thì "chưa trả"
                 * và "chưa nhập" bị nhập làm một, và người dùng không trả được
                 * hóa đơn của kỳ mới.
                 *
                 * Quyết định dựa trên `data-statement-id` mà SERVER gửi, không
                 * suy từ trạng thái select — nếu không thì lần đầu tiên bấm chưa có
                 * request nào để lấy id.
                 */
                paymentUrl(cardId) {
                    const row = document.querySelector(`[data-card-id="${cardId}"]`);

                    if (! row) return null;

                    const payload = this.paymentPayload(cardId);
                    const id = row.querySelector('[data-testid="statement-closing-balance"]')?.dataset.statementId;

                    if (! payload) return null;

                    if (id) {
                        return {
                            url: this.bind(this.urls.payment, Number(id)),
                            body: { payment_status: payload.payment_status },
                        };
                    }

                    // Kỳ lấy từ hidden của form nhập sao kê ngay trong dòng: đó là
                    // kỳ đang chọn ở dropdown phía trên, nên không thể lệch.
                    if (! payload.period_start) return null;

                    return {
                        url: this.bind(this.urls.paymentPeriod, Number(cardId)),
                        body: {
                            payment_status: payload.payment_status,
                            period_start: payload.period_start,
                        },
                    };
                },

                /**
                 * Lưu trạng thái thanh toán — KHÔNG có nút "Lưu".
                 *
                 * Gửi ngay khi đổi select. Ô số ngày gửi sau khoảng
                 * lặng 400ms (`@input.debounce.400ms`) để mỗi phím gõ không sinh
                 * một request, và gửi ngay khi blur / Enter / đổi giá trị.
                 *
                 * Kỳ CHƯA có dòng sao kê mà người dùng để nguyên "Chưa thanh toán"
                 * thì KHÔNG gửi: `unpaid` ảo đã là giá trị đúng, mà tạo dòng chỉ vì
                 * người dùng đã mở dropdown sẽ làm DB đầy dòng rác. Chỉ chọn "Đã
                 * thanh toán" mới là hành động cần lưu — và đó là lý do đường theo
                 * Kỳ tồn tại.
                 */
                async savePayment(cardId) {
                    if (this.paymentTimers[cardId]) {
                        clearTimeout(this.paymentTimers[cardId]);
                        delete this.paymentTimers[cardId];
                    }

                    const payload = this.paymentPayload(cardId);
                    const target = this.paymentUrl(cardId);

                    if (! payload || ! target) return;

                    // Không có dòng sao kê + vẫn `unpaid` ⇒ không việc gì phải lưu.
                    if (! this.lastPayment[cardId]?.statement_exists
                        && payload.payment_status === 'unpaid') {
                        return;
                    }

                    // Đang có request bay: chụp lại ý định mới nhất rồi đợi, chứ
                    // đừng `return` luôn — bỏ qua nó nghĩa là nuốt mất thao tác
                    // của người dùng mà không có bất kỳ dấu hiệu nào. Phải chụp
                    // NGAY, vì khi request đang bay trả về, `applyPayment()` sẽ
                    // ghi đè các control bằng số cũ và giá trị mới sẽ mất.
                    if (this.paymentSave[cardId]?.state === 'saving') {
                        this.paymentQueued[cardId] = target;

                        return;
                    }

                    await this.sendPayment(cardId, target);

                    const queued = this.paymentQueued[cardId];

                    if (! queued) return;

                    delete this.paymentQueued[cardId];

                    await this.sendPayment(cardId, queued);
                },

                /** Gửi một payload đã chụp rồi cập nhật UI theo số server trả về. */
                async sendPayment(cardId, target) {
                    this.paymentSave[cardId] = { state: 'saving', message: '' };

                    const result = await this.request(target.url, {
                        method: 'PATCH',
                        body: JSON.stringify(target.body),
                    });

                    if (! result) {
                        // `this.error` đã có sẵn câu chữ tiếng Việt do `request()`
                        // đặt (422 ⇒ lấy message của đúng ô; lỗi khác ⇒ `message`
                        // của server). Dùng lại kênh này thay vì tạo thêm một.
                        const message = this.error || 'Không thể lưu, thử lại.';

                        this.paymentSave[cardId] = { state: 'error', message };

                        // Kéo cả ba control về đúng thứ server đang giữ: để chúng
                        // ở giá trị chưa lưu được là nói dối người dùng về dữ liệu
                        // đang có. Việc họ vừa gõ không lưu được vẫn nằm lại trong
                        // thông báo lỗi để họ gõ lại.
                        this.applyPayment(cardId, this.lastPayment[cardId]);

                        return;
                    }

                    this.applyPayment(cardId, result.payment);
                    this.paymentSave[cardId] = { state: 'saved', message: '' };

                    // Tự tắt dòng "Đã lưu" sau vài giây: giữ nguyên thì dòng đó
                    // đọc như trạng thái vĩnh viễn của thẻ.
                    clearTimeout(this.paymentTimers[cardId]);
                    this.paymentTimers[cardId] = setTimeout(() => {
                        if (this.paymentSave[cardId]?.state === 'saved') {
                            this.paymentSave[cardId] = { state: 'idle', message: '' };
                        }
                    }, 2000);
                },

                /**
                 * Nút "Xoá" chỉ có nghĩa khi đã có dòng sao kê.
                 *
                 * Node này LUÔN được render (chỉ ẩn bằng `hidden`) vì lý do giống
                 * cảnh báo: đánh dấu "đã trả" lúc kỳ chưa có dòng sẽ TẠO dòng ngay
                 * trong phiên hiện tại, mà nếu node không có sẵn thì phải dựng DOM
                 * bằng tay — và node dựng tay không qua `Alpine.initTree()` nên mất
                 * luôn `@click`.
                 */
                syncDeleteButton(row, exists) {
                    row.querySelector('[data-testid^="statement-delete-"]')
                        ?.classList.toggle('hidden', ! exists);
                },

                /**
                 * Ghi trạng thái thanh toán của đúng một dòng theo giá trị SERVER
                 * vừa trả.
                 *
                 * Không tự tính lại cảnh báo ở trình duyệt: nơi so sánh ngày khác
                 * nhau giữa máy chủ và máy người dùng là nơi sinh cảnh báo sai.
                 * Server quyết `alert` nào hiện, view chỉ bật/tắt theo đó.
                 */
                applyPayment(cardId, payment) {
                    const row = document.querySelector(`[data-card-id="${cardId}"]`);

                    if (! row || ! payment) return;

                    this.lastPayment[cardId] = payment;

                    const status = row.querySelector('[data-payment-status]');

                    if (status) status.value = payment.status ?? 'unpaid';

                    // Đánh dấu "đã trả" lúc kỳ chưa có dòng sẽ TẠO dòng ở server.
                    // Ghi id mới vào `data-statement-id` ngay để lần bấm kế tiếp đi
                    // theo endpoint theo dòng, và để nút nhập/sửa + nút xoá mô tả
                    // đúng trạng thái sau khi đánh dấu. Bỏ trống thì lần sau lại tạo
                    // dòng thứ hai cho cùng một kỳ và đụng UNIQUE.
                    const closing = row.querySelector('[data-testid="statement-closing-balance"]');

                    if (closing) {
                        closing.dataset.statementId = payment.statement_id == null
                            ? ''
                            : String(payment.statement_id);
                    }

                    // Dòng "chưa nhập sao kê thực tế": vẫn hiện sau khi đánh dấu
                    // "đã trả", vì dòng mới sinh ra là 0/0/0 — chưa có số liệu nào.
                    const noData = row.querySelector('[data-payment-no-data]');

                    if (noData) noData.classList.toggle('hidden', !! payment.statement_data_entered);

                    // Nhãn nút theo `statement_exists`: dòng vừa được tạo thì nút là
                    // "Sửa", không phải "Nhập sao kê".
                    const edit = row.querySelector('[data-testid^="statement-edit-"]');

                    if (edit) edit.textContent = payment.statement_exists ? 'Sửa' : 'Nhập sao kê';

                    // Dòng 0/0/0 vừa tạo có nút xoá, và server đã sinh node này
                    // chưa tồn tại — thêm vào thay vì để người dùng không xoá được.
                    this.syncDeleteButton(row, payment.statement_exists);

                    // DÒNG HẠN — chữ và màu đều lấy thẳng từ server. Đây là dòng
                    // từng hiện sai kiểu "đã trả nhưng vẫn quá hạn N ngày" vì nó
                    // tự so sánh ngày mà không biết `payment_status`; giờ nó cũng do
                    // server quyết, nên đổi trạng thái là dòng này tự đúng lại.
                    //
                    // `querySelectorAll` chứ không `querySelector`: nếu sau này có
                    // thêm một chỗ hiện dòng hạn nữa thì `querySelector` chỉ dựng
                    // lại node ĐẦU TIÊN và node còn lại giữ nguyên chuỗi "quá hạn
                    // N ngày" của kỳ đã trả — đúng loại bug đã gặp. Dựng lại MỌI
                    // node thì không còn chỗ nào để sót.
                    row.querySelectorAll('[data-payment-due-line]').forEach((dueLine) => {
                        dueLine.textContent = payment.due_line ?? '';

                        dueLine.classList.toggle('text-red-700', payment.due_tone === 'danger');
                        dueLine.classList.toggle('text-amber-700', payment.due_tone === 'warning');
                        dueLine.classList.toggle('text-emerald-700', payment.due_tone === 'settled');
                        dueLine.classList.toggle('text-gray-600', payment.due_tone === 'neutral');
                    });

                    const alert = row.querySelector('[data-payment-alert]');

                    if (alert) {
                        const tone = payment.alert?.tone;

                        alert.classList.toggle('hidden', ! payment.alert);
                        alert.classList.toggle('border-red-300', tone === 'danger');
                        alert.classList.toggle('bg-red-50', tone === 'danger');
                        alert.classList.toggle('text-red-800', tone === 'danger');
                        alert.classList.toggle('border-amber-300', tone !== 'danger');
                        alert.classList.toggle('bg-amber-50', tone !== 'danger');
                        alert.classList.toggle('text-amber-900', tone !== 'danger');

                        const title = alert.querySelector('[data-payment-alert-title]');
                        const detail = alert.querySelector('[data-payment-alert-detail]');

                        if (title) title.textContent = payment.alert?.title ?? '';

                        if (detail) {
                            detail.textContent = payment.alert?.detail ?? '';
                            detail.classList.toggle('hidden', ! payment.alert?.detail);
                        }
                    }

                    // `paid` không bao giờ có `alert` nên hai node này không bao giờ
                    // cùng bật — vẫn bật/tắt độc lập để không phụ thuộc điều đó.
                    const paid = row.querySelector('[data-payment-paid]');

                    if (paid) paid.classList.toggle('hidden', ! payment.is_paid);
                },

                /** Nhãn + màu của dòng "đang lưu / đã lưu / lỗi" cho ô nhắc chung. */
                reminderLabel() {
                    if (this.reminderSave.state === 'busy') return { label: 'Đang lưu…', tone: 'busy' };
                    if (this.reminderSave.state === 'saved') return { label: 'Đã lưu', tone: 'ok' };
                    if (this.reminderSave.state === 'error') {
                        return {
                            label: this.reminderSave.message || 'Không thể lưu, thử lại.',
                            tone: 'error',
                        };
                    }

                    return { label: '', tone: 'idle' };
                },

                /** Số ngày đang gõ trong ô nhắc. */
                reminderInput() {
                    const input = document.querySelector('[data-payment-reminder-days]');

                    return input ? input.value : '';
                },

                /**
                 * Kéo ô nhắc về đúng số ngày server đang giữ.
                 *
                 * Dùng khi lưu hỏng: để nguyên số người vừa gõ là nói dối người
                 * dùng về thiết lập đang có. Việc họ gõ không lưu được vẫn nằm lại
                 * trong thông báo lỗi để họ gõ lại.
                 */
                applyReminder(days) {
                    this.reminderDays = days;

                    const input = document.querySelector('[data-payment-reminder-days]');

                    if (input) input.value = String(days);
                },

                /**
                 * Lưu số ngày nhắc — KHÔNG có nút "Lưu".
                 *
                 * Ô số gửi sau khoảng lặng 400ms (`@input.debounce.400ms`) để mỗi
                 * phím gõ không sinh một request, và gửi ngay khi blur / Enter.
                 *
                 * Endpoint này KHÔNG trả lại trạng thái cảnh báo từng dòng: số ngày
                 * nhắc áp dụng cho mọi kỳ nên cảnh báo của từng dòng đã đổi theo,
                 * và trang phải tải lại mới thấy. Vẽ lại cảnh báo ở trình duyệt là
                 * lặp lại đúng lỗi so ngày lệch chỗ mà server đã gài.
                 */
                async saveReminder() {
                    const raw = this.reminderInput();

                    // Ô rỗng giữa lúc người dùng xoá để gõ lại: chưa phải giá trị
                    // họ muốn, nên đừng gửi — gửi sẽ báo lỗi 422 giữa lúc gõ.
                    if (raw === '') return;

                    const days = Number(raw);

                    if (! Number.isInteger(days)) return;

                    this.reminderSave = { state: 'busy', message: '' };

                    const result = await this.request(this.urls.reminder, {
                        method: 'PATCH',
                        body: JSON.stringify({ payment_reminder_days: days }),
                    });

                    clearTimeout(this.reminderTimer);

                    if (! result) {
                        this.reminderSave = {
                            state: 'error',
                            message: this.error || 'Không thể lưu, thử lại.',
                        };

                        // Kéo ô về số server đang giữ (mặc định 1 nếu chưa có).
                        this.applyReminder(this.reminderDays ?? 1);

                        return;
                    }

                    // Chốt lại đúng số SERVER giữ, không phải số vừa gửi: server có
                    // thể đã chuẩn hoá, và ô phải phản ánh DB chứ không phải ý định.
                    this.applyReminder(result.data.payment_reminder_days);
                    this.reminderSave = { state: 'saved', message: '' };

                    // Tự tắt dòng "Đã lưu": giữ nguyên thì dòng đó đọc như trạng
                    // thái vĩnh viễn.
                    this.reminderTimer = setTimeout(() => {
                        if (this.reminderSave.state === 'saved') {
                            this.reminderSave = { state: 'idle', message: '' };
                        }
                    }, 2000);
                },

                /** URL mẫu có `0` giữ chỗ; chỉ thay đúng số 0 đó. */
                bind(template, id) {
                    return String(template ?? '').replace(/\/0(?=\/|$)/, '/' + id);
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

                        if (! response.ok) {
                            // 422 ⇒ gắn lỗi đúng ô để biết sửa chỗ nào, KHÔNG in
                            // `message` mặc định của Laravel (tiếng Anh).
                            if (payload.errors) {
                                this.errors = {
                                    actual_spend: this.firstOf(payload.errors.actual_spend),
                                    actual_reward: this.firstOf(payload.errors.actual_reward),
                                };

                                this.error = 'Vui lòng kiểm tra lại các ô được đánh dấu.';
                            } else {
                                this.error = this.firstError(payload)
                                    || `Yêo cầu thất bại (${response.status}).`;
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
