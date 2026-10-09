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

</x-credit-card.layout>
