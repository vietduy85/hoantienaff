{{--
    Trang KẾT QUẢ báo cáo — /thetindung/bao-cao/{id}

    ---------------------------------------------------------------------------
    SỐ LÀ SỐ SERVER TÍNH, BLADE CHỈ IN
    ---------------------------------------------------------------------------
    Mọi tổng chi tiêu / cashback do `CreditCardReportService` cộng bằng SQL +
    `Decimal` (chuỗi/bcmath) rồi truyền xuống. Blade không tự cộng, không gọi
    policy, không suy ra kỳ — nhờ vậy bảng, footer và trang Sao kê luôn cùng một
    con số.

    ---------------------------------------------------------------------------
    CHỌN KỲ Ở ĐÂY, KHÔNG LƯU KỲ VÀO BÁO CÁO
    ---------------------------------------------------------------------------
    Dropdown gửi lại `?period=` và tự submit. Kỳ đặc biệt "kỳ hiện tại" để mỗi thẻ
    tự lấy kỳ đang mở của nó; chọn một tháng thì lấy kỳ có `period_end` đúng tháng
    đó. Không có kỳ nào được tạo khi mở trang.

    ---------------------------------------------------------------------------
    BẢNG THEO DANH MỤC: THANH CUỘN RIÊNG
    ---------------------------------------------------------------------------
    Bảng có 1 + 2×N cột (N = số thẻ) nên rộng hơn màn hình điện thoại. Nó nằm
    trong khung `overflow-x-auto` riêng để cuộn ngang mà không kéo cả trang, và cột
    "Danh mục" dính bên trái để vẫn đọc được tên khi cuộn tới cột thẻ cuối.
--}}
@php
    $isByCategory = $data['mode'] === 'by_category';
    $title = $report->name;
@endphp

<x-credit-card.layout :title="$title" active="reports">

    <div class="space-y-4 sm:space-y-5">

        <div class="flex flex-wrap items-center justify-between gap-2">
            <a href="{{ route('credit-cards.reports') }}"
               class="text-sm text-gray-500 hover:text-gray-700">← Danh sách báo cáo</a>
            <a href="{{ route('credit-cards.reports.edit', ['report' => $report->id]) }}"
               data-testid="report-edit-link"
               class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                Sửa báo cáo
            </a>
        </div>

        @if ($errors->any())
            <div class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700" role="alert">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Đầu trang: tên + kiểu + chọn kỳ --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                <div class="min-w-0">
                    <h3 class="font-bold text-gray-800 text-base break-words" data-testid="report-title">{{ $report->name }}</h3>
                    <span class="inline-block mt-1.5 rounded-full bg-blue-50 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700"
                          data-testid="report-type-label">
                        {{ $report->typeLabel() }}
                    </span>
                </div>

                @if ($cards->isNotEmpty())
                    <form method="GET"
                          action="{{ route('credit-cards.reports.show', ['report' => $report->id]) }}"
                          data-testid="period-selector"
                          class="shrink-0">
                        <label for="report-period" class="block text-xs font-medium text-gray-500 mb-1">Kỳ sao kê</label>
                        <div class="flex items-center gap-2">
                            <select id="report-period"
                                    name="period"
                                    data-testid="period-select"
                                    onchange="this.form.submit()"
                                    class="rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                @foreach ($periodOptions as $option)
                                    <option value="{{ $option['key'] }}" @selected($periodKey === $option['key'])>
                                        {{ $option['label'] }}
                                    </option>
                                @endforeach
                            </select>
                            <noscript>
                                <button type="submit"
                                        class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">
                                    Xem
                                </button>
                            </noscript>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        @if ($cards->isEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8 text-center"
                 data-testid="report-no-cards">
                <p class="text-3xl sm:text-4xl leading-none" aria-hidden="true">💳</p>
                <h3 class="font-bold text-gray-800 text-lg mt-3">Báo cáo chưa có thẻ nào</h3>
                <p class="text-sm text-gray-500 mt-1.5">Báo cáo này không còn thẻ hợp lệ. Sửa báo cáo để chọn lại thẻ.</p>
                <a href="{{ route('credit-cards.reports.edit', ['report' => $report->id]) }}"
                   class="inline-block mt-4 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                    Sửa báo cáo
                </a>
            </div>

        @elseif (! $isByCategory)
            {{-- ═══ BÁO CÁO A — CHI TIÊU THEO THẺ ═══ --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden"
                 data-testid="report-by-card">
                <div class="px-4 sm:px-5 py-3.5 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 text-sm">Chi tiêu theo thẻ</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-100">
                                <th class="px-4 sm:px-5 py-2.5 font-semibold">Thẻ</th>
                                <th class="px-4 sm:px-5 py-2.5 font-semibold whitespace-nowrap">Kỳ sao kê</th>
                                <th class="px-4 sm:px-5 py-2.5 font-semibold text-right whitespace-nowrap">Chi tiêu</th>
                                <th class="px-4 sm:px-5 py-2.5 font-semibold text-right whitespace-nowrap">Cashback</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach ($data['rows'] as $row)
                                <tr data-testid="by-card-row-{{ $row['card_id'] }}">
                                    <td class="px-4 sm:px-5 py-3">
                                        <p class="font-medium text-gray-800">{{ $row['name'] }}</p>
                                        <p class="text-[11px] text-gray-400">
                                            {{ $row['bank'] ?? 'Không rõ ngân hàng' }}
                                            @if ($row['last4'])
                                                · •••• {{ $row['last4'] }}
                                            @endif
                                        </p>
                                    </td>
                                    <td class="px-4 sm:px-5 py-3 text-gray-600 whitespace-nowrap">
                                        @if ($row['has_period'])
                                            <span data-testid="by-card-period-{{ $row['card_id'] }}">{{ $row['period_label'] }}</span>
                                            @unless ($row['has_record'])
                                                <span class="block text-[11px] text-gray-400">Chưa có bản ghi kỳ · 0 đồng</span>
                                            @endunless
                                        @else
                                            <span class="text-gray-400">Kỳ này không nằm trong khoảng của thẻ</span>
                                        @endif
                                    </td>
                                    <td class="px-4 sm:px-5 py-3 text-right font-semibold text-gray-800 whitespace-nowrap"
                                        data-testid="by-card-spend-{{ $row['card_id'] }}">
                                        <x-credit-card.money :value="$row['spend']" />
                                    </td>
                                    <td class="px-4 sm:px-5 py-3 text-right font-semibold text-emerald-600 whitespace-nowrap"
                                        data-testid="by-card-cashback-{{ $row['card_id'] }}">
                                        <x-credit-card.money :value="$row['cashback']" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-gray-200 bg-gray-50 font-bold text-gray-800">
                                <td class="px-4 sm:px-5 py-3" colspan="2">Tổng tất cả thẻ đã chọn</td>
                                <td class="px-4 sm:px-5 py-3 text-right whitespace-nowrap" data-testid="by-card-total-spend">
                                    <x-credit-card.money :value="$data['total_spend']" />
                                </td>
                                <td class="px-4 sm:px-5 py-3 text-right text-emerald-700 whitespace-nowrap" data-testid="by-card-total-cashback">
                                    <x-credit-card.money :value="$data['total_cashback']" />
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        @else
            {{-- ═══ BÁO CÁO B — CHI TIÊU THEO DANH MỤC ═══ --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden"
                 data-testid="report-by-category">
                <div class="px-4 sm:px-5 py-3.5 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 text-sm">Chi tiêu theo danh mục</h3>
                    <p class="text-[11px] text-gray-500 mt-1">
                        Mỗi danh mục một dòng, mỗi thẻ hai cột chi tiêu / cashback. Dòng "Chưa phân loại" gom giao dịch không gắn danh mục.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            {{-- Hàng 1: tên thẻ, mỗi thẻ trải 2 cột --}}
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b border-gray-100">
                                <th rowspan="2"
                                    class="sticky left-0 z-10 bg-white px-4 sm:px-5 py-2.5 font-semibold text-left align-bottom">
                                    Danh mục
                                </th>
                                @foreach ($data['columns'] as $column)
                                    <th colspan="2" class="px-3 py-2 font-semibold text-center border-l border-gray-100 min-w-[180px]">
                                        <span class="block text-gray-700 normal-case">{{ $column['name'] }}</span>
                                        <span class="block text-[10px] font-normal normal-case text-gray-400">
                                            {{ $column['bank'] ?? 'Không rõ ngân hàng' }}@if ($column['last4']) · •••• {{ $column['last4'] }}@endif
                                        </span>
                                        @php $cardPeriod = $data['periods'][$column['card_id']] ?? null; @endphp
                                        @if ($cardPeriod && $cardPeriod['label'])
                                            <span class="block text-[10px] font-normal normal-case text-gray-400">{{ $cardPeriod['label'] }}</span>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                            {{-- Hàng 2: nhãn con của từng thẻ --}}
                            <tr class="text-[10px] uppercase tracking-wide text-gray-400 border-b border-gray-100">
                                @foreach ($data['columns'] as $column)
                                    <th class="px-3 py-1.5 font-medium text-right border-l border-gray-100">Chi tiêu</th>
                                    <th class="px-3 py-1.5 font-medium text-right">Cashback</th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-50">
                            @forelse ($data['rows'] as $row)
                                @php $rowKey = $row['category_id'] ?? 'uncategorized'; @endphp
                                <tr data-testid="category-row-{{ $rowKey }}"
                                    class="{{ $row['is_uncategorized'] ? 'bg-gray-50/60' : '' }}">
                                    <td class="sticky left-0 z-10 {{ $row['is_uncategorized'] ? 'bg-gray-50' : 'bg-white' }} px-4 sm:px-5 py-3">
                                        <span class="font-medium text-gray-800">{{ $row['name'] }}</span>
                                    </td>
                                    @foreach ($data['columns'] as $column)
                                        @php
                                            $cell = $row['cells'][$column['card_id']] ?? ['spend' => '0.00', 'cashback' => '0.00'];
                                        @endphp
                                        <td class="px-3 py-3 text-right text-gray-700 whitespace-nowrap border-l border-gray-100"
                                            data-testid="cell-{{ $column['card_id'] }}-{{ $rowKey }}-spend">
                                            <x-credit-card.money :value="$cell['spend']" />
                                        </td>
                                        <td class="px-3 py-3 text-right text-emerald-600 whitespace-nowrap"
                                            data-testid="cell-{{ $column['card_id'] }}-{{ $rowKey }}-cashback">
                                            <x-credit-card.money :value="$cell['cashback']" />
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td class="px-4 sm:px-5 py-6 text-sm text-gray-500"
                                        colspan="{{ 1 + count($data['columns']) * 2 }}">
                                        Kỳ này chưa có giao dịch nào.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>

                        <tfoot>
                            {{-- Tổng theo cột (mỗi thẻ) — cộng cả dòng "Chưa phân loại" --}}
                            <tr class="border-t border-gray-200 bg-gray-50 font-semibold text-gray-800">
                                <td class="sticky left-0 z-10 bg-gray-50 px-4 sm:px-5 py-3">Tổng theo thẻ</td>
                                @foreach ($data['columns'] as $column)
                                    @php $t = $data['card_totals'][$column['card_id']] ?? ['spend' => '0.00', 'cashback' => '0.00']; @endphp
                                    <td class="px-3 py-3 text-right whitespace-nowrap border-l border-gray-100"
                                        data-testid="card-total-{{ $column['card_id'] }}-spend">
                                        <x-credit-card.money :value="$t['spend']" />
                                    </td>
                                    <td class="px-3 py-3 text-right text-emerald-700 whitespace-nowrap"
                                        data-testid="card-total-{{ $column['card_id'] }}-cashback">
                                        <x-credit-card.money :value="$t['cashback']" />
                                    </td>
                                @endforeach
                            </tr>
                            {{-- Tổng tất cả: gộp mọi thẻ và mọi danh mục --}}
                            <tr class="bg-gray-100 font-bold text-gray-900">
                                <td class="sticky left-0 z-10 bg-gray-100 px-4 sm:px-5 py-3">
                                    Tổng tất cả: <span class="text-emerald-700"><x-credit-card.money :value="$data['grand']['spend']" /></span> chi tiêu
                                </td>
                                @foreach ($data['columns'] as $column)
                                    @php $t = $data['card_totals'][$column['card_id']] ?? ['spend' => '0.00', 'cashback' => '0.00']; @endphp
                                    <td class="px-3 py-3 text-right whitespace-nowrap border-l border-gray-100">
                                        <x-credit-card.money :value="$t['spend']" />
                                    </td>
                                    <td class="px-3 py-3 text-right text-emerald-700 whitespace-nowrap">
                                        <x-credit-card.money :value="$t['cashback']" />
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Tổng gộp in riêng, không phụ thuộc cuộn ngang để luôn nhìn thấy --}}
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 sm:px-5 py-3.5 border-t border-gray-100 bg-gray-50">
                    <p class="text-sm text-gray-600">
                        Tổng chi tiêu:
                        <span class="font-bold text-gray-900" data-testid="grand-spend"><x-credit-card.money :value="$data['grand']['spend']" /></span>
                    </p>
                    <p class="text-sm text-gray-600">
                        Tổng cashback:
                        <span class="font-bold text-emerald-700" data-testid="grand-cashback"><x-credit-card.money :value="$data['grand']['cashback']" /></span>
                    </p>
                </div>
            </div>
        @endif
    </div>

</x-credit-card.layout>
