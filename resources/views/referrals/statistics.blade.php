<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.users.index') }}" class="shrink-0">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </a>
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                {{ __('Thống kê giới thiệu') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-6xl mx-auto space-y-4">
        <p class="text-sm text-gray-500">
            {{ __('Thống kê số lượng người dùng được giới thiệu và tiến độ hoàn thành.') }}
        </p>

        {{-- Summary cards --}}
        <div class="grid grid-cols-3 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <span class="text-sm text-gray-500">Tổng người giới thiệu</span>
                <span class="block text-2xl font-bold text-emerald-600 tracking-tight">{{ number_format($summary['referrers']) }}</span>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <span class="text-sm text-gray-500">Tổng lượt giới thiệu</span>
                <span class="block text-2xl font-bold text-emerald-600 tracking-tight">{{ number_format($summary['total_referrals']) }}</span>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <span class="text-sm text-gray-500">Tổng đã hoàn thành</span>
                <span class="block text-2xl font-bold text-emerald-600 tracking-tight">{{ number_format($summary['completed_referrals']) }}</span>
            </div>
        </div>

        {{-- Search --}}
        <form method="GET" action="{{ route('admin.referrals.statistics') }}" class="flex items-center gap-2">
            <input type="text" name="user" value="{{ $search }}" placeholder="Tìm theo username hoặc tên..."
                   class="w-full sm:max-w-xs h-11 px-3 rounded-xl border border-gray-200 bg-white text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-emerald-100">
            <button type="submit" class="shrink-0 h-11 px-4 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm">
                Tìm
            </button>
            @if ($search !== '')
                <a href="{{ route('admin.referrals.statistics') }}" class="shrink-0 h-11 px-4 inline-flex items-center bg-gray-200 hover:bg-gray-300 text-gray-600 text-sm font-medium rounded-xl transition-colors">
                    Xóa lọc
                </a>
            @endif
        </form>

        {{-- Desktop: table --}}
        <div class="hidden md:block bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50">
                        <th class="px-4 py-3 text-left text-gray-500 font-medium text-xs uppercase">User</th>
                        <th class="px-4 py-3 text-right text-gray-500 font-medium text-xs uppercase">Đã giới thiệu</th>
                        <th class="px-4 py-3 text-right text-gray-500 font-medium text-xs uppercase">Hoàn thành</th>
                        <th class="px-4 py-3 text-right text-gray-500 font-medium text-xs uppercase">Đang chờ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800">{{ $row->username ?? '—' }}</div>
                                @if (!empty($row->name) && $row->name !== $row->username)
                                    <div class="text-gray-400 text-xs">{{ $row->name }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ $row->total_referrals }}</td>
                            <td class="px-4 py-3 text-right">
                                <span class="inline-flex items-center gap-1.5 font-medium text-emerald-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    {{ $row->completed_referrals }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span class="inline-flex items-center gap-1.5 font-medium text-amber-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                    {{ $row->pending_referrals }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-300">
                                Không có dữ liệu
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile: cards --}}
        <div class="md:hidden space-y-4">
            @forelse ($rows as $row)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium text-gray-800">{{ $row->username ?? '—' }}</span>
                            @if (!empty($row->name) && $row->name !== $row->username)
                                <div class="text-gray-400 text-xs">{{ $row->name }}</div>
                            @endif
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                            {{ $row->total_referrals }} đã giới thiệu
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div class="flex items-center gap-1.5 text-emerald-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            <span class="text-gray-400 text-xs">Hoàn thành</span>
                            <span class="font-semibold">{{ $row->completed_referrals }}</span>
                        </div>
                        <div class="flex items-center gap-1.5 text-amber-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                            <span class="text-gray-400 text-xs">Đang chờ</span>
                            <span class="font-semibold">{{ $row->pending_referrals }}</span>
                        </div>
                    </div>
                </div>
            @empty
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
                    <p class="text-gray-300">Không có dữ liệu</p>
                </div>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </div>
</x-app-layout>