{{-- Trang tổng quan Module Thẻ tín dụng — /thetindung --}}
<x-credit-card.layout
    title="Thẻ tín dụng"
    subtitle="Quản lý và theo dõi các thẻ tín dụng của bạn"
    active="index">

    {{-- Số liệu: đọc thật từ DB. Chưa có thẻ => 0 / "Chưa có dữ liệu" --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
            <p class="text-xs sm:text-sm text-gray-500">💳 Tổng số thẻ</p>
            @if ($totalCards > 0)
                <p class="text-2xl sm:text-3xl font-bold text-emerald-600 tracking-tight">{{ $totalCards }}</p>
            @else
                <p class="text-2xl sm:text-3xl font-bold text-gray-300 tracking-tight">0</p>
                <p class="text-xs text-gray-400">Chưa có dữ liệu</p>
            @endif
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
            <p class="text-xs sm:text-sm text-gray-500">🎯 Tổng hạn mức</p>
            @if ($totalLimit > 0)
                <p class="text-2xl sm:text-3xl font-bold text-emerald-600 tracking-tight">
                    {{ number_format($totalLimit, 0, ',', '.') }}<span class="text-base">đ</span>
                </p>
            @else
                <p class="text-2xl sm:text-3xl font-bold text-gray-300 tracking-tight">0</p>
                <p class="text-xs text-gray-400">Chưa có dữ liệu</p>
            @endif
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
            <p class="text-xs sm:text-sm text-gray-500">💰 Cashback kỳ hiện tại</p>
            @if ($currentPeriod !== null)
                <p class="text-2xl sm:text-3xl font-bold text-emerald-600 tracking-tight">
                    {{ number_format((float) $currentPeriod->total_cashback, 0, ',', '.') }}<span class="text-base">đ</span>
                </p>
                <p class="text-xs text-gray-400">
                    Kỳ {{ \Carbon\CarbonImmutable::parse($currentPeriod->period_start)->format('d/m') }}–{{ \Carbon\CarbonImmutable::parse($currentPeriod->period_end)->format('d/m/Y') }}
                </p>
            @else
                <p class="text-2xl sm:text-3xl font-bold text-gray-300 tracking-tight">0</p>
                <p class="text-xs text-gray-400">Chưa có dữ liệu</p>
            @endif
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
            <p class="text-xs sm:text-sm text-gray-500">📅 Ngày chốt kỳ</p>
            @if ($currentPeriod !== null)
                <p class="text-2xl sm:text-3xl font-bold text-emerald-600 tracking-tight">
                    {{ \Carbon\CarbonImmutable::parse($currentPeriod->period_end)->format('d/m') }}
                </p>
                @if ($currentPeriod->payment_due_date !== null)
                    <p class="text-xs text-gray-400">
                        Đến hạn {{ \Carbon\CarbonImmutable::parse($currentPeriod->payment_due_date)->format('d/m/Y') }}
                    </p>
                @endif
            @else
                <p class="text-2xl sm:text-3xl font-bold text-gray-300 tracking-tight">—</p>
                <p class="text-xs text-gray-400">Chưa có dữ liệu</p>
            @endif
        </div>
    </div>

    @if (count($deadlineWarnings) > 0)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 space-y-1">
            <p class="text-sm font-semibold text-amber-800">Nhắc chi tiêu</p>
            @foreach ($deadlineWarnings as $warning)
                <p class="text-xs text-amber-700">{{ $warning['name'] }}: {{ $warning['message'] }}</p>
            @endforeach
        </div>
    @endif

    {{-- Danh sách thẻ --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="font-bold text-gray-800">Thẻ tín dụng đang có</h3>
            <a href="{{ route('credit-cards.manage') }}"
               class="shrink-0 inline-flex items-center gap-1.5 h-10 px-4 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm">
                <span aria-hidden="true">+</span> Thêm thẻ
            </a>
        </div>

        @if ($userCreditCards->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-200 py-8 px-4 text-center">
                <p class="text-sm text-gray-500">Bạn chưa thêm thẻ tín dụng nào.</p>
            </div>
        @else
            <ul class="space-y-3">
                @foreach ($userCreditCards as $userCard)
                    <li class="rounded-xl border border-gray-100 p-4">
                        <p class="font-semibold text-gray-800">
                            {{ $userCard->product?->name ?? 'Thẻ tín dụng' }}
                        </p>
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ $userCard->bank?->name ?? '—' }}
                            @if ($userCard->name && $userCard->name !== $userCard->product?->name)
                                <span class="text-gray-400">· {{ $userCard->name }}</span>
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

</x-credit-card.layout>
