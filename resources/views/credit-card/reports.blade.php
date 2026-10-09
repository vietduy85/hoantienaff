{{--
    /thetindung/bao-cao — Danh sách báo cáo chi tiêu đã lưu.

    ---------------------------------------------------------------------------
    ĐÂY LÀ "CẤU HÌNH ĐÃ LƯU", KHÔNG PHẢI "KẾT QUẢ ĐÃ LƯU"
    ---------------------------------------------------------------------------
    Mỗi dòng là một bộ lọc user đặt tên (tên + kiểu + thẻ). Bấm "Xem" mới tính ra
    số, và số được tính lại từ dữ liệu hiện tại mỗi lần mở — xem
    `CreditCardReportService`. Vì thế danh sách KHÔNG in tổng tiền: in một con số
    ở đây sẽ là con số cũ, lệch với trang kết quả ngay sau khi user nhập thêm giao
    dịch.

    ---------------------------------------------------------------------------
    RENDER Ở SERVER
    ---------------------------------------------------------------------------
    `@forelse` in sẵn mọi dòng; JavaScript không dựng danh sách. Không bật JS vẫn
    dùng được toàn bộ chức năng (xoá vẫn chạy nhờ form + confirm).
--}}
<x-credit-card.layout
    title="Báo cáo"
    subtitle="Lưu các góc nhìn chi tiêu và mở lại bất cứ lúc nào."
    active="reports">

    <div class="space-y-4 sm:space-y-5">

        {{-- Thông báo sau khi tạo/sửa/xoá --}}
        @if (session('status') === 'report-created')
            <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800"
                 role="status" data-testid="report-status">
                Đã tạo báo cáo.
            </div>
        @elseif (session('status') === 'report-updated')
            <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800"
                 role="status" data-testid="report-status">
                Đã cập nhật báo cáo.
            </div>
        @elseif (session('status') === 'report-deleted')
            <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800"
                 role="status" data-testid="report-status">
                Đã xoá báo cáo.
            </div>
        @endif

        {{-- Thanh công cụ --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h3 class="font-semibold text-gray-800 text-sm">📊 BÁO CÁO CỦA TÔI</h3>
                <p class="text-xs text-gray-500 mt-1">
                    Báo cáo tính lại từ giao dịch hiện có, nên luôn khớp dữ liệu mới nhất.
                </p>
            </div>

            @if ($hasCards)
                <a href="{{ route('credit-cards.reports.create') }}"
                   data-testid="create-report-link"
                   class="shrink-0 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                    + Tạo báo cáo
                </a>
            @endif
        </div>

        {{-- Chưa có thẻ thì báo cáo không có gì để xem — chặn trước, đỡ vào form trống --}}
        @if (! $hasCards)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8 text-center"
                 data-testid="reports-no-cards">
                <p class="text-3xl sm:text-4xl leading-none" aria-hidden="true">💳</p>
                <h3 class="font-bold text-gray-800 text-lg mt-3">Chưa có thẻ để lập báo cáo</h3>
                <p class="text-sm text-gray-500 mt-1.5">Thêm thẻ tín dụng trước, rồi quay lại tạo báo cáo chi tiêu.</p>
                <a href="{{ route('credit-cards.manage') }}"
                   class="inline-block mt-4 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                    Quản lý thẻ
                </a>
            </div>
        @elseif ($reports->isEmpty())
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8 text-center"
                 data-testid="reports-empty">
                <p class="text-3xl sm:text-4xl leading-none" aria-hidden="true">📊</p>
                <h3 class="font-bold text-gray-800 text-lg mt-3">Bạn chưa tạo báo cáo nào</h3>
                <p class="text-sm text-gray-500 mt-1.5">Tạo báo cáo để theo dõi chi tiêu theo thẻ hoặc theo danh mục.</p>
                <a href="{{ route('credit-cards.reports.create') }}"
                   class="inline-block mt-4 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                    + Tạo báo cáo
                </a>
            </div>
        @else
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden"
                 data-testid="reports-list">
                <ul class="divide-y divide-gray-100">
                    @foreach ($reports as $report)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 sm:px-5 py-3.5"
                            data-testid="report-row-{{ $report->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-800 break-words">{{ $report->name }}</p>
                                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                    <span class="rounded-full bg-blue-50 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700"
                                          data-testid="report-type-label-{{ $report->id }}">
                                        {{ $report->typeLabel() }}
                                    </span>
                                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-[11px] font-semibold text-gray-600">
                                        {{ $report->cards_count }} thẻ
                                    </span>
                                </div>
                            </div>

                            <div class="shrink-0 flex items-center gap-1.5">
                                <a href="{{ route('credit-cards.reports.show', ['report' => $report->id]) }}"
                                   data-testid="report-open-{{ $report->id }}"
                                   class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                    Xem
                                </a>
                                <a href="{{ route('credit-cards.reports.edit', ['report' => $report->id]) }}"
                                   data-testid="report-edit-{{ $report->id }}"
                                   class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                    Sửa
                                </a>
                                <form method="POST"
                                      action="{{ route('credit-cards.reports.destroy', ['report' => $report->id]) }}"
                                      onsubmit="return confirm('Xoá báo cáo &quot;{{ $report->name }}&quot;?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            data-testid="report-delete-{{ $report->id }}"
                                            class="rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50">
                                        Xoá
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

</x-credit-card.layout>
