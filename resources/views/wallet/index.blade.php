<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('dashboard') }}" class="shrink-0">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </a>
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                {{ __('Ví tiền') }}
            </h2>
        </div>
    </x-slot>

    @php
        $maskedAccount = $accountNumber ? str_repeat('*', max(0, strlen($accountNumber) - 4)) . substr($accountNumber, -4) : '';
    @endphp

    @if (session('success'))
        <div class="py-6 px-4 max-w-lg mx-auto">
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        </div>
    @endif

    <div x-data="{
            showWithdraw: false,
            loading: false,
            rawAmount: '',
            minWithdraw: 10000,
            maxWithdraw: {{ (int) $available }},
            formatAmount(val) {
                return val ? parseInt(val).toLocaleString('de-DE') : '';
            },
            setAmount(val) {
                this.rawAmount = String(val);
            },
            get numAmount() {
                return parseInt(this.rawAmount) || 0;
            },
            get canSubmit() {
                return this.numAmount >= this.minWithdraw && this.numAmount <= this.maxWithdraw;
            },
            get displayAmount() {
                return this.formatAmount(this.rawAmount);
            }
         }">

        <div class="py-6 px-4 max-w-lg mx-auto space-y-4">

        {{-- Card 1: Số dư khả dụng --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-500">💰 Số dư khả dụng</span>
            </div>
            <div class="text-emerald-600 font-extrabold text-3xl tracking-tight">
                {{ number_format(floor($available), 0, ',', '.') }}<span class="text-lg">đ</span>
            </div>

            @if ($hasBankInfo)
                <div class="bg-gray-50 rounded-xl p-4 space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2 text-gray-600">
                            <span>🏦</span>
                            <span class="font-medium">{{ $bankName }}</span>
                        </div>
                        <a href="{{ route('profile.edit') }}" class="text-xs text-emerald-600 hover:underline shrink-0">
                            Đổi thông tin
                        </a>
                    </div>
                    <div class="flex items-center gap-2 text-gray-600">
                        <span>🔢</span>
                        <span class="font-mono tracking-tight">{{ $maskedAccount }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-gray-600">
                        <span>👤</span>
                        <span>{{ $accountName }}</span>
                    </div>
                </div>
                <button @click="showWithdraw = true"
                        class="w-full h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-semibold text-sm rounded-xl transition-colors shadow-sm">
                    Rút tiền
                </button>
            @else
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 space-y-4">
                    <div class="flex items-start gap-3">
                        <span class="text-lg shrink-0 mt-0.5">⚠️</span>
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-amber-800">Chưa có tài khoản nhận tiền</p>
                            <p class="text-sm text-amber-700 leading-relaxed">
                                Bạn cần cập nhật thông tin ngân hàng trong Hồ sơ để có thể gửi yêu cầu rút tiền.
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('profile.edit') }}"
                       class="block w-full h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-semibold text-base rounded-xl transition-colors shadow-sm text-center leading-[3rem]">
                        Cập nhật hồ sơ
                    </a>
                </div>
            @endif
        </div>

        {{-- Card 2: Tiền chờ xác nhận --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
            <div class="flex items-center gap-2">
                <span class="text-lg">🕒</span>
                <span class="text-sm font-medium text-gray-500">Tiền đang chờ về</span>
            </div>
            <div class="text-amber-500 font-bold text-xl tracking-tight">
                {{ number_format(floor($pending), 0, ',', '.') }}<span class="text-sm">đ</span>
            </div>
        </div>

        {{-- Card 3: Tổng tiền đã thanh toán --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
            <div class="flex items-center gap-2">
                <span class="text-lg">💸</span>
                <span class="text-sm font-medium text-gray-500">Tổng tiền đã thanh toán</span>
            </div>
            <div class="text-blue-500 font-bold text-xl tracking-tight">
                {{ number_format(floor($paid), 0, ',', '.') }}<span class="text-sm">đ</span>
            </div>
        </div>

        {{-- Card 4: Lịch sử giao dịch --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
            <div class="flex items-center gap-2">
                <span class="text-lg">📋</span>
                <span class="text-sm font-medium text-gray-500">Lịch sử giao dịch</span>
            </div>
            <div class="overflow-x-auto -mx-5">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100">
                            <th class="px-5 py-3 text-left text-gray-400 font-medium text-xs uppercase">Mã GD</th>
                            <th class="px-5 py-3 text-left text-gray-400 font-medium text-xs uppercase">Mô tả</th>
                            <th class="px-5 py-3 text-right text-gray-400 font-medium text-xs uppercase">Số tiền</th>
                            <th class="px-5 py-3 text-center text-gray-400 font-medium text-xs uppercase">Trạng thái</th>
                            <th class="px-5 py-3 text-right text-gray-400 font-medium text-xs uppercase">Ngày</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions as $tx)
                            <tr class="border-b border-gray-50">
                                <td class="px-5 py-3 text-left text-gray-700 font-mono text-xs">{{ $tx->running_no }}</td>
                                <td class="px-5 py-3 text-left text-gray-600 text-xs max-w-40 truncate">
                                    @if ($tx->type === 'cashback')
                                        <span class="text-emerald-600 font-medium">Cashback</span>
                                        @if (!empty($tx->metadata['order_id']))
                                            <span class="text-gray-400">({{ $tx->metadata['order_id'] }})</span>
                                        @endif
                                    @elseif ($tx->type === 'withdraw')
                                        <span class="text-blue-600 font-medium">Rút tiền</span>
                                        @if (!empty($tx->metadata['withdraw_running_no']))
                                            <span class="text-gray-400">({{ $tx->metadata['withdraw_running_no'] }})</span>
                                        @endif
                                        @if ($tx->status === 'cancelled' && !empty($tx->metadata['reject_reason']))
                                            <br><span class="text-red-400 text-xs">Lý do: {{ $tx->metadata['reject_reason'] }}</span>
                                        @endif
                                    @elseif ($tx->type === 'adjustment')
                                        <span class="text-gray-600 font-medium">Điều chỉnh</span>
                                    @elseif ($tx->type === 'refund')
                                        <span class="text-orange-600 font-medium">Hoàn tiền</span>
                                    @else
                                        <span class="text-gray-600">{{ $tx->type }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-800 whitespace-nowrap">
                                    <span class="{{ $tx->direction === 'credit' ? 'text-emerald-600' : 'text-red-500' }}">
                                        {{ $tx->direction === 'credit' ? '+' : '-' }}{{ number_format($tx->amount, 0, ',', '.') }}đ
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium
                                        @if($tx->type === 'withdraw' && $tx->status === 'pending') bg-red-100 text-red-800
                                        @elseif($tx->status === 'completed') bg-emerald-100 text-emerald-800
                                        @elseif($tx->status === 'pending') bg-amber-100 text-amber-800
                                        @elseif($tx->status === 'cancelled') bg-gray-100 text-gray-600
                                        @elseif($tx->status === 'failed') bg-red-100 text-red-800
                                        @else bg-gray-100 text-gray-800
                                        @endif
                                    ">
                                        @if($tx->type === 'withdraw' && $tx->status === 'completed') Đã thanh toán
                                        @elseif($tx->status === 'completed') Hoàn thành
                                        @elseif($tx->status === 'pending') Đang chờ xử lý
                                        @elseif($tx->status === 'cancelled') Đã từ chối
                                        @elseif($tx->status === 'failed') Thất bại
                                        @else {{ $tx->status }}
                                        @endif
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right text-gray-400 text-xs whitespace-nowrap">{{ $tx->completed_at?->format('d/m/Y H:i') ?? $tx->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-gray-300">
                                    Chưa có giao dịch nào
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    {{-- Bottom Sheet --}}
    <div x-cloak
         x-show="showWithdraw"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="translate-y-full"
         x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="translate-y-0"
         x-transition:leave-end="translate-y-full"
         class="fixed inset-0 z-50 flex items-end">
        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-black/40" @click="showWithdraw = false"></div>

        {{-- Sheet --}}
        <form method="POST" action="{{ route('wallet.withdraw') }}"
              @submit="if (!canSubmit) $event.preventDefault(); loading = true"
              class="relative w-full bg-white rounded-t-2xl shadow-2xl px-5 pt-6 pb-8 space-y-5 max-h-[75vh] overflow-y-auto">
            @csrf
            <input type="hidden" name="amount" :value="rawAmount">

            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800">Rút tiền</h3>
                <button @click="showWithdraw = false" type="button" class="text-gray-400 hover:text-gray-600 p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Error messages --}}
            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-xl p-3.5">
                    <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Số dư khả dụng --}}
            <div class="space-y-1.5">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">Số dư khả dụng</span>
                    <span class="font-semibold text-emerald-600">{{ number_format(floor($available), 0, ',', '.') }}đ</span>
                </div>
                <div class="flex items-center justify-between text-xs text-gray-400">
                    <span>Có thể rút: <span class="font-medium text-gray-600">{{ number_format(floor($available), 0, ',', '.') }}đ</span></span>
                    <span>Rút tối thiểu: <span class="font-medium text-gray-600">10.000đ</span></span>
                </div>
            </div>

            {{-- Thông tin ngân hàng --}}
            <div class="bg-gray-50 rounded-xl p-4 space-y-2 text-sm">
                <div class="flex items-center gap-2 text-gray-600">
                    <span>🏦</span>
                    <span class="font-medium">{{ $bankName }}</span>
                </div>
                <div class="flex items-center gap-2 text-gray-600">
                    <span>🔢</span>
                    <span class="font-mono tracking-tight">{{ $maskedAccount }}</span>
                </div>
                <div class="flex items-center gap-2 text-gray-600">
                    <span>👤</span>
                    <span>{{ $accountName }}</span>
                </div>
            </div>

            {{-- Ô nhập số tiền --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Số tiền rút</label>
                <div class="relative">
                    <input type="text"
                           :value="displayAmount"
                           @input="rawAmount = $event.target.value.replace(/[^0-9]/g, '')"
                           class="w-full h-12 pl-4 pr-12 rounded-xl border border-gray-200 bg-white text-lg font-semibold text-gray-800 focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 transition-shadow shadow-sm"
                           placeholder="0">
                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 font-medium">đ</span>
                </div>
            </div>

            {{-- Chips --}}
            <div class="flex gap-2">
                <button @click="setAmount(100000)" type="button"
                        class="h-10 px-4 rounded-xl bg-gray-100 hover:bg-gray-200 active:bg-gray-300 text-sm font-medium text-gray-700 transition-colors">
                    100.000
                </button>
                <button @click="setAmount(200000)" type="button"
                        class="h-10 px-4 rounded-xl bg-gray-100 hover:bg-gray-200 active:bg-gray-300 text-sm font-medium text-gray-700 transition-colors">
                    200.000
                </button>
                <button @click="setAmount(500000)" type="button"
                        class="h-10 px-4 rounded-xl bg-gray-100 hover:bg-gray-200 active:bg-gray-300 text-sm font-medium text-gray-700 transition-colors">
                    500.000
                </button>
                <button @click="setAmount(maxWithdraw)" type="button"
                        class="h-10 px-4 rounded-xl bg-gray-100 hover:bg-gray-200 active:bg-gray-300 text-sm font-medium text-gray-700 transition-colors">
                    Rút toàn bộ
                </button>
            </div>

            {{-- Card cảnh báo --}}
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-3.5">
                <p class="text-xs text-amber-700 leading-relaxed">
                    Yêu cầu rút tiền sẽ được Admin kiểm tra. Số dư chỉ giảm khi yêu cầu được thanh toán.
                </p>
            </div>

            {{-- Buttons --}}
            <div class="space-y-3">
                <button type="submit" :disabled="!canSubmit || loading"
                        :class="canSubmit && !loading
                            ? 'bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 shadow-sm'
                            : 'bg-gray-200 cursor-not-allowed'"
                        class="w-full h-12 text-white font-semibold text-sm rounded-xl transition-colors">
                    <span x-show="!loading">Xác nhận</span>
                    <span x-show="loading">Đang gửi...</span>
                </button>
                <button @click="showWithdraw = false" type="button"
                        class="w-full h-12 bg-gray-100 hover:bg-gray-200 active:bg-gray-300 text-gray-700 font-semibold text-sm rounded-xl transition-colors">
                    Hủy
                </button>
            </div>
        </form>
    </div>
    </div>
</x-app-layout>
