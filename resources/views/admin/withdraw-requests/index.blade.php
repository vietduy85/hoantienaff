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

        {{-- Desktop: table --}}
        <div class="hidden md:block bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
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

        {{-- Mobile: cards --}}
        <div class="md:hidden space-y-4">
            @forelse ($requests as $wr)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-mono text-gray-400">{{ $wr->running_no }}</span>
                        @if($wr->status === 'pending')
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Chờ xử lý</span>
                        @elseif($wr->status === 'paid')
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Đã thanh toán</span>
                        @elseif($wr->status === 'rejected')
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Đã từ chối</span>
                        @else
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">{{ $wr->status }}</span>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <div class="text-gray-400 text-xs">Người dùng</div>
                            <div class="text-gray-800 font-medium">{{ $wr->username }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Số tiền</div>
                            <div class="text-gray-800 font-semibold">{{ number_format($wr->amount, 0, ',', '.') }}đ</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Ngân hàng</div>
                            <div class="text-gray-800">{{ $wr->bank_name ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Số tài khoản</div>
                            <div class="text-gray-800 font-mono text-xs">{{ $wr->bank_account ? '******' . substr($wr->bank_account, -4) : '—' }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Chủ tài khoản</div>
                            <div class="text-gray-800 text-sm">{{ $wr->account_name ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Ngày tạo</div>
                            <div class="text-gray-800 text-xs whitespace-nowrap">{{ $wr->created_at->format('d/m/Y H:i') }}</div>
                        </div>
                    </div>

                    @if ($wr->status === 'rejected' && $wr->note)
                        <div class="bg-red-50 border border-red-100 rounded-xl px-3 py-2.5">
                            <div class="text-xs text-red-500 font-medium mb-0.5">Lý do từ chối</div>
                            <div class="text-sm text-red-700">{{ $wr->note }}</div>
                        </div>
                    @endif

                    @if ($wr->isPending())
                        <div class="pt-1 space-y-2.5">
                            <form method="POST" action="{{ route('admin.withdraw-requests.complete', $wr) }}" onsubmit="return confirm('Bạn xác nhận đã chuyển khoản?')">
                                @csrf
                                <button type="submit" class="w-full h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm">
                                    Đã chuyển tiền
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.withdraw-requests.reject', $wr) }}"
                                  x-data="{ rejectOpen: false, rejectNote: '' }"
                                  @submit.prevent="if (!rejectNote.trim()) return; $event.target.submit()">
                                @csrf
                                <template x-if="!rejectOpen">
                                    <button @click.prevent="rejectOpen = true" type="button" class="w-full h-12 bg-red-500 hover:bg-red-600 active:bg-red-700 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm">
                                        Từ chối
                                    </button>
                                </template>
                                <template x-if="rejectOpen">
                                    <div class="space-y-2">
                                        <textarea rows="3" x-model="rejectNote" name="note" maxlength="500" placeholder="Nhập lý do từ chối..." class="w-full px-3 py-2 text-sm border border-gray-300 rounded-xl resize-none" required></textarea>
                                        <div class="flex gap-2">
                                            <button type="submit" class="flex-1 h-11 bg-red-500 hover:bg-red-600 active:bg-red-700 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm">
                                                Xác nhận từ chối
                                            </button>
                                            <button @click.prevent="rejectOpen = false; rejectNote = ''" type="button" class="flex-1 h-11 bg-gray-200 hover:bg-gray-300 active:bg-gray-400 text-gray-600 font-semibold text-sm rounded-xl transition-colors">
                                                Hủy
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </form>
                        </div>
                    @endif
                </div>
            @empty
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
                    <p class="text-gray-300">Chưa có yêu cầu rút tiền nào</p>
                </div>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $requests->links() }}
        </div>
    </div>
</x-app-layout>
