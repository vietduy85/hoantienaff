<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Quản lý yêu cầu rút tiền') }}
        </h2>
    </x-slot>

    <div class="py-6 px-4 max-w-5xl mx-auto space-y-4">
        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50">
                        <th class="px-4 py-3 text-left text-gray-500 font-medium text-xs uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-gray-500 font-medium text-xs uppercase">Mã</th>
                        <th class="px-4 py-3 text-left text-gray-500 font-medium text-xs uppercase">User</th>
                        <th class="px-4 py-3 text-right text-gray-500 font-medium text-xs uppercase">Số tiền</th>
                        <th class="px-4 py-3 text-center text-gray-500 font-medium text-xs uppercase">Trạng thái</th>
                        <th class="px-4 py-3 text-right text-gray-500 font-medium text-xs uppercase">Ngày</th>
                        <th class="px-4 py-3 text-center text-gray-500 font-medium text-xs uppercase">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $wr)
                        <tr class="border-b border-gray-50">
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ $wr->id }}</td>
                            <td class="px-4 py-3 text-gray-700 font-mono text-xs">{{ $wr->running_no }}</td>
                            <td class="px-4 py-3">
                                <div class="text-gray-800 font-medium">{{ $wr->username }}</div>
                                <div class="text-gray-400 text-xs">{{ $wr->user?->email }}</div>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ number_format($wr->amount, 0, ',', '.') }}đ</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium
                                    @if($wr->status === 'pending') bg-amber-100 text-amber-800
                                    @elseif($wr->status === 'paid') bg-emerald-100 text-emerald-800
                                    @elseif($wr->status === 'rejected') bg-red-100 text-red-800
                                    @else bg-gray-100 text-gray-800
                                    @endif
                                ">
                                    @if($wr->status === 'pending') Chờ xử lý
                                    @elseif($wr->status === 'paid') Đã thanh toán
                                    @elseif($wr->status === 'rejected') Đã từ chối
                                    @else {{ $wr->status }}
                                    @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-400 text-xs whitespace-nowrap">{{ $wr->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-center">
                                @if ($wr->isPending())
                                    <form method="POST" action="{{ route('admin.withdraw-requests.complete', $wr) }}" class="inline" onsubmit="return confirm('Bạn xác nhận đã chuyển khoản?')">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-medium rounded-lg transition-colors">
                                            Đã chuyển tiền
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.withdraw-requests.reject', $wr) }}" class="inline ml-1"
                                          x-data="{ rejectOpen: false, rejectNote: '' }"
                                          @submit.prevent="if (!rejectNote.trim()) return; $event.target.submit()">
                                        @csrf
                                        <template x-if="!rejectOpen">
                                            <button @click.prevent="rejectOpen = true" type="button" class="px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white text-xs font-medium rounded-lg transition-colors">
                                                Từ chối
                                            </button>
                                        </template>
                                        <template x-if="rejectOpen">
                                            <div class="flex flex-col gap-1">
                                                <textarea rows="3" x-model="rejectNote" name="note" maxlength="500" placeholder="Nhập lý do từ chối..." class="px-2 py-1 text-xs border border-gray-300 rounded w-56" required></textarea>
                                                <div class="flex gap-1">
                                                    <button type="submit" class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white text-xs font-medium rounded transition-colors">
                                                        Xác nhận
                                                    </button>
                                                    <button @click.prevent="rejectOpen = false; rejectNote = ''" type="button" class="px-2 py-1 bg-gray-200 hover:bg-gray-300 text-gray-600 text-xs font-medium rounded transition-colors">
                                                        Hủy
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </form>
                                @else
                                    <span class="text-gray-300 text-xs">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-300">
                                Chưa có yêu cầu rút tiền nào
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $requests->links() }}
        </div>
    </div>
</x-app-layout>
