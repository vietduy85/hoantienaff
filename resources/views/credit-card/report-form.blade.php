{{--
    Form tạo / sửa báo cáo — dùng chung một view cho cả hai chế độ.

    ---------------------------------------------------------------------------
    KHÔNG LIỆT KÊ KỲ Ở ĐÂY
    ---------------------------------------------------------------------------
    Báo cáo lưu "xem gì", không lưu "kỳ nào". Việc chọn kỳ nằm ở TRANG KẾT QUẢ
    (dropdown cạnh bảng số), vì cùng một báo cáo người dùng muốn xem nhiều kỳ khác
    nhau mà không phải sửa lại cấu hình.

    ---------------------------------------------------------------------------
    THẺ Ở ĐÂY LÀ "LỌC", KHÔNG PHẢI "CHỌN KỲ"
    ---------------------------------------------------------------------------
    Checkbox chọn thẻ nào nằm trong báo cáo. Danh sách thẻ do server gửi xuống và
    đã lọc theo chủ sở hữu; giá trị gửi lên còn bị `StoreReportRequest` kiểm lại
    (Rule::exists kèm user_id) nên không thể gắn thẻ của người khác.

    `$report` là null khi tạo ⇒ action/method chuỗi do controller truyền vào, không
    rẽ nhánh bằng `@if` trong view.
--}}
@php
    $isEdit = $report !== null;
    $title = $isEdit ? 'Sửa báo cáo' : 'Tạo báo cáo';
@endphp

<x-credit-card.layout :title="$title" active="reports">

    <div class="max-w-2xl mx-auto space-y-4 sm:space-y-5">

        <div class="flex items-center gap-2">
            <a href="{{ $isEdit ? route('credit-cards.reports.show', ['report' => $report->id]) : route('credit-cards.reports') }}"
               class="text-sm text-gray-500 hover:text-gray-700">← Quay lại</a>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-6">
            <h3 class="font-bold text-gray-800 text-base">{{ $title }}</h3>
            <p class="text-xs text-gray-500 mt-1">
                Đặt tên và chọn kiểu báo cáo. Kỳ sao kê sẽ chọn ở trang kết quả.
            </p>

            @if ($errors->any())
                <div class="mt-4 rounded-xl bg-rose-50 border border-rose-200 p-3 text-sm text-rose-700"
                     role="alert" data-testid="report-errors">
                    <p class="font-semibold mb-1">Vui lòng kiểm tra lại:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ $action }}" class="mt-4 space-y-5" data-testid="report-form">
                @csrf
                @if ($method !== 'POST')
                    @method($method)
                @endif

                {{-- Tên báo cáo --}}
                <div>
                    <label for="report-name" class="block text-sm font-medium text-gray-700">
                        Tên báo cáo <span class="text-rose-500">*</span>
                    </label>
                    <input id="report-name"
                           name="name"
                           type="text"
                           maxlength="150"
                           required
                           data-testid="report-name"
                           value="{{ old('name', $report->name ?? '') }}"
                           placeholder="Ví dụ: Chi tiêu quý này"
                           class="mt-1 block w-full rounded-xl border-gray-300 text-sm shadow-sm
                                  focus:border-emerald-500 focus:ring-emerald-500">
                </div>

                {{-- Kiểu báo cáo: quyết định cấu trúc bảng ở trang kết quả --}}
                <fieldset>
                    <legend class="block text-sm font-medium text-gray-700">
                        Kiểu báo cáo <span class="text-rose-500">*</span>
                    </legend>
                    <div class="mt-2 space-y-2">
                        @foreach ($types as $type)
                            <label class="flex items-start gap-2.5 min-h-11 rounded-xl border border-gray-200 px-3 py-2.5 cursor-pointer hover:bg-gray-50">
                                <input type="radio"
                                       name="type"
                                       value="{{ $type['value'] }}"
                                       @checked($selectedType === $type['value'])
                                       data-testid="report-type-{{ $type['value'] }}"
                                       class="mt-0.5 rounded-full border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-800">{{ $type['label'] }}</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">
                                        {{ $type['value'] === 'by_category'
                                            ? 'Một dòng một danh mục, mỗi thẻ hai cột chi tiêu và cashback.'
                                            : 'Một dòng một thẻ, kèm kỳ sao kê và tổng chi tiêu / cashback.' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- Thẻ nằm trong báo cáo --}}
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <label class="block text-sm font-medium text-gray-700">
                            Thẻ trong báo cáo <span class="text-rose-500">*</span>
                        </label>
                        <span class="text-[11px] text-gray-500">
                            Đã chọn <span class="font-semibold text-emerald-600" data-testid="report-card-count">{{ count($selected) }}</span>
                        </span>
                    </div>

                    @if ($cards->isEmpty())
                        <p class="mt-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-4 text-sm text-gray-500"
                           data-testid="report-no-cards">
                            Bạn chưa có thẻ tín dụng nào.
                            <a href="{{ route('credit-cards.manage') }}" class="text-emerald-600 hover:underline">Thêm thẻ</a>
                            trước khi tạo báo cáo.
                        </p>
                    @else
                        <div class="mt-2 max-h-72 overflow-auto rounded-xl border border-gray-200 p-2 divide-y divide-gray-50">
                            @foreach ($cards as $card)
                                <label class="flex items-start gap-2.5 px-1 py-2.5 min-h-[44px] cursor-pointer">
                                    <input type="checkbox"
                                           name="card_ids[]"
                                           value="{{ $card->id }}"
                                           @checked(in_array((int) $card->id, $selected, true))
                                           data-testid="report-card-{{ $card->id }}"
                                           class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm text-gray-800">{{ $card->name }}</span>
                                        <span class="block text-[11px] text-gray-400">
                                            {{ $card->bank?->name ?? 'Không rõ ngân hàng' }}
                                            @if ($card->card_number_last4)
                                                · •••• {{ $card->card_number_last4 }}
                                            @endif
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Loại trừ danh mục: chỉ áp dụng cho báo cáo theo danh mục --}}
                @if ($categories->isNotEmpty())
                    <div id="report-excluded-section" data-testid="report-excluded-categories">
                        <label class="block text-sm font-medium text-gray-700">
                            Loại trừ các danh mục
                            <span class="font-normal text-gray-400">(bỏ trống = tính trên tất cả danh mục)</span>
                        </label>
                        <p class="mt-1 text-xs text-gray-500">
                            Các danh mục được chọn sẽ không xuất hiện trong báo cáo và không được tính vào
                            tổng chi tiêu, cashback hoặc tỷ lệ.
                        </p>

                        {{-- Chọn/bỏ chọn TẤT CẢ danh mục để loại trừ. Đây là điều khiển UI
                             thuần tuý: KHÔNG có `name` nên không được gửi lên server; chỉ
                             tác động lên từng checkbox chip phía dưới. --}}
                        <label for="exclude-all-categories"
                               class="mt-2 inline-flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox"
                                   id="exclude-all-categories"
                                   data-testid="exclude-all-toggle"
                                   aria-label="Chọn tất cả danh mục để loại trừ"
                                   class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-medium text-gray-700"
                                  data-testid="exclude-all-label"
                                  data-role="exclude-all-label">
                                Chọn tất cả danh mục để loại trừ
                            </span>
                        </label>

                        <div class="mt-2 flex flex-wrap gap-1.5" data-role="excluded-chips">
                            @foreach ($categories as $category)
                                {{-- `<label>` bao quanh checkbox THẬT: nhấp vào bất kỳ đâu
                                     trên chip (nhãn) cũng chuyển trạng thái checkbox theo
                                     cơ chế gốc của trình duyệt. Checkbox là nguồn chân lý;
                                     JS chỉ đồng bộ màu chip + nút chọn tất cả từ đó. --}}
                                <label class="inline-flex cursor-pointer select-none" data-role="excluded-chip">
                                    <input type="checkbox"
                                           name="excluded_category_ids[]"
                                           value="{{ $category->id }}"
                                           @checked(in_array((int) $category->id, $selectedExclusions, true))
                                           data-testid="exclude-category-{{ $category->id }}"
                                           class="sr-only">
                                    <span data-role="chip-label"
                                          class="inline-flex items-center px-3 h-9 rounded-full border text-xs font-medium transition-colors
                                                 bg-white text-gray-600 border-gray-200 hover:border-emerald-300">
                                        {{ $category->name }}
                                        @unless ($category->is_active)
                                            <span class="ml-1 text-[10px] opacity-75">(đã ẩn)</span>
                                        @endunless
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-end gap-2 pt-1">
                    <a href="{{ $isEdit ? route('credit-cards.reports.show', ['report' => $report->id]) : route('credit-cards.reports') }}"
                       class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Huỷ
                    </a>
                    <button type="submit"
                            data-testid="report-submit"
                            @disabled($cards->isEmpty())
                            class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                        {{ $isEdit ? 'Lưu thay đổi' : 'Tạo báo cáo' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Ẩn/hiện mục loại trừ theo kiểu báo cáo + đồng bộ chip / nút chọn tất cả.

     1. Dùng `hidden` chứ KHÔNG `disabled`: khi chọn "theo thẻ" các checkbox vẫn
        được gửi lên và được lưu, nên đổi qua lại kiểu báo cáo không làm mất lựa
        chọn trước khi lưu.
     2. Trạng thái màu của chip do JS bật/tắt TRỰC TIẾP các class tiện ích
        (`bg-emerald-600 text-white border-emerald-600` / nền trắng) — không phụ
        thuộc variant `peer-checked` có được compile trong bản CSS hay không.
        Checkbox là nguồn chân lý DUY NHẤT: màu và "chọn tất cả" luôn bám theo
        `input.checked`.
     3. Nút "Chọn tất cả / Bỏ chọn tất cả" là control UI thuần tuý, không submit;
        hiển thị trạng thái ba trạng thái (rỗng / một phần / tất cả). --}}
<script>
    (function () {
        var section = document.getElementById('report-excluded-section');
        if (!section) {
            return;
        }

        // --- Hiện/ẩn theo kiểu báo cáo ---
        var radios = Array.prototype.slice.call(document.querySelectorAll('input[name="type"]'));

        var syncTypeVisibility = function () {
            var checked = document.querySelector('input[name="type"]:checked');
            section.hidden = !checked || checked.value !== 'by_category';
        };

        radios.forEach(function (radio) {
            radio.addEventListener('change', syncTypeVisibility);
        });
        syncTypeVisibility();

        // --- Đồng bộ chip + Chọn tất cả ---
        var inputs = Array.prototype.slice.call(
            section.querySelectorAll('input[name="excluded_category_ids[]"]')
        );
        var selectAll = document.getElementById('exclude-all-categories');
        var selectAllLabel = section.querySelector('[data-testid="exclude-all-label"]');

        var chipFor = function (input) {
            var label = input.closest('label');
            return label ? label.querySelector('[data-role="chip-label"]') : null;
        };

        // Hai trạng thái màu của chip. Các class ở đây là utility thông dụng
        // (đã có sẵn trong CSS build), nên tác dụng nhìn thấy ngay mà không cần
        // biên dịch lại variant `peer-checked`.
        var CHIP_ON = ['bg-emerald-600', 'text-white', 'border-emerald-600'];
        var CHIP_OFF = ['bg-white', 'text-gray-600', 'border-gray-200'];

        var refreshChip = function (input) {
            var chip = chipFor(input);
            if (!chip) {
                return;
            }
            chip.classList.remove.apply(chip.classList, CHIP_OFF);
            chip.classList.remove.apply(chip.classList, CHIP_ON);
            chip.classList.add.apply(chip.classList, input.checked ? CHIP_ON : CHIP_OFF);
            chip.setAttribute('data-chosen', input.checked ? '1' : '0');
        };

        var refreshSelectAll = function () {
            if (!selectAll) {
                return;
            }

            var total = inputs.length;
            var chosen = inputs.filter(function (input) {
                return input.checked;
            }).length;
            var all = total > 0 && chosen === total;

            selectAll.checked = all;
            selectAll.indeterminate = chosen > 0 && !all;

            if (selectAllLabel) {
                selectAllLabel.textContent = all
                    ? 'Bỏ chọn tất cả danh mục để loại trừ'
                    : 'Chọn tất cả danh mục để loại trừ';
            }
        };

        inputs.forEach(function (input) {
            refreshChip(input);
            input.addEventListener('change', function () {
                refreshChip(input);
                refreshSelectAll();
            });
        });

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var wanted = selectAll.checked;
                inputs.forEach(function (input) {
                    input.checked = wanted;
                    refreshChip(input);
                });
                refreshSelectAll();
            });
        }

        refreshSelectAll();
    })();
</script>

</x-credit-card.layout>
