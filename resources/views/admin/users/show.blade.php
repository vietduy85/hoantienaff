<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.users.index') }}" class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                Chi tiết người dùng
            </h2>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-5xl mx-auto space-y-4">
        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700">
                {{ session('success') }}
                @if (session('adjustment_before') !== null && session('adjustment_after') !== null)
                    <div class="mt-1 text-emerald-600">
                        Số dư trước: {{ number_format(session('adjustment_before'), 0, ',', '.') }}đ —
                        Số dư sau: {{ number_format(session('adjustment_after'), 0, ',', '.') }}đ
                    </div>
                @endif
            </div>
        @endif

        @if (session('error'))
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
                <ul class="list-disc pl-4 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- User Info Card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <div class="flex flex-col sm:flex-row items-start gap-5">
                @if ($user->avatar)
                    <img src="{{ $user->avatar }}" alt="" class="w-20 h-20 rounded-full object-cover border-2 border-gray-200">
                @else
                    <div class="w-20 h-20 rounded-full bg-gray-200 flex items-center justify-center text-gray-400 text-2xl font-bold">
                        {{ strtoupper(substr($user->name ?? $user->username ?? '?', 0, 1)) }}
                    </div>
                @endif

                <div class="flex-1 space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-xl font-bold text-gray-800">{{ $user->username ?? '—' }}</h3>
                        @php $role = $user->roles->first(); @endphp
                        @if ($role)
                            @if ($role->name === 'Admin')
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">Admin</span>
                            @elseif ($role->name === 'Merchant')
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Merchant</span>
                            @elseif ($role->name === 'Affiliate')
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Affiliate</span>
                            @elseif ($role->name === 'Member')
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Member</span>
                            @else
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">{{ $role->name }}</span>
                            @endif
                        @else
                            <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Chưa gán</span>
                        @endif

                        @if ($user->status)
                            @if ($user->status === 'active')
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Active</span>
                            @elseif ($user->status === 'inactive' || $user->status === 'banned')
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{{ ucfirst($user->status) }}</span>
                            @else
                                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ $user->status }}</span>
                            @endif
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-gray-400">Tên:</span>
                            <span class="text-gray-800 ml-1">{{ $user->name ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Email:</span>
                            <span class="text-gray-800 ml-1">{{ $user->email }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Google ID:</span>
                            <span class="text-gray-500 ml-1 font-mono text-xs">{{ $user->google_id ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Số điện thoại:</span>
                            <span class="text-gray-800 ml-1">{{ $user->phone ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Zalo:</span>
                            <span class="text-gray-800 ml-1">{{ $user->zalo ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Referral Code:</span>
                            <span class="text-gray-800 ml-1 font-mono text-xs">{{ $user->referral_code ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Đăng nhập cuối:</span>
                            <span class="text-gray-800 ml-1">{{ $user->last_login_at ? \Carbon\Carbon::parse($user->last_login_at)->format('d/m/Y H:i') : '—' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Ngày tạo:</span>
                            <span class="text-gray-800 ml-1">{{ $user->created_at->format('d/m/Y H:i') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Wallet & Stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Ví hiện tại</div>
                <div class="text-xl font-bold text-gray-800">{{ number_format($user->wallet_balance, 0, ',', '.') }}đ</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng đã kiếm</div>
                <div class="text-xl font-bold text-emerald-600">{{ number_format($user->total_earned, 0, ',', '.') }}đ</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng đã rút</div>
                <div class="text-xl font-bold text-gray-800">{{ number_format($stats['total_withdrawn'], 0, ',', '.') }}đ</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Đang chờ rút</div>
                <div class="text-xl font-bold text-amber-600">{{ number_format($stats['pending_withdrawal'], 0, ',', '.') }}đ</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tiền chờ về</div>
                <div class="text-xl font-bold text-amber-600">{{ number_format($stats['pending_cashback'], 0, ',', '.') }}đ</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng đã hoàn</div>
                <div class="text-xl font-bold text-emerald-700">{{ number_format($stats['total_cashback_earned'], 0, ',', '.') }}đ</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng số đơn</div>
                <div class="text-xl font-bold text-gray-800">{{ number_format($stats['total_orders']) }}</div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Tổng giá trị đơn</div>
                <div class="text-xl font-bold text-gray-800">{{ number_format($stats['total_order_amount'], 0, ',', '.') }}đ</div>
            </div>
        </div>

        {{-- Điều chỉnh ví --}}
        @can('users.manage')
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6" x-data="walletAdjust()">
            <h4 class="text-sm font-semibold text-gray-700 mb-4">Điều chỉnh ví</h4>

            <div class="mb-4 text-sm">
                <span class="text-gray-400">Số dư hiện tại:</span>
                <span class="text-gray-800 font-bold ml-1">{{ number_format($user->wallet_balance, 0, ',', '.') }}đ</span>
            </div>

            <form method="POST" action="{{ route('admin.users.wallet-adjust', $user) }}" @submit="confirmAdjust()">
                @csrf

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Loại</label>
                        <div class="flex gap-4 text-sm">
                            <label class="inline-flex items-center">
                                <input type="radio" name="direction" value="credit" x-model="direction" class="mr-1" checked>
                                Cộng tiền
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="direction" value="debit" x-model="direction" class="mr-1">
                                Trừ tiền
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Số tiền</label>
                        <input type="number" name="amount" min="0.01" step="0.01" required
                               x-model="amount"
                               placeholder="0"
                               class="w-full rounded-xl border-gray-200 text-sm">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Lý do (bắt buộc)</label>
                        <textarea name="reason" rows="2" required maxlength="255"
                                  x-model="reason"
                                  placeholder="VD: Thưởng hỗ trợ tháng 8"
                                  class="w-full rounded-xl border-gray-200 text-sm"></textarea>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit"
                            x-ref="submitBtn"
                            class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 disabled:bg-gray-300 disabled:cursor-not-allowed text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                        Điều chỉnh ví
                    </button>
                </div>
            </form>
        </div>
        @endcan

        {{-- Bank Info --}}
        @if ($user->bank_name || $user->bank_account_number || $user->bank_account_name)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <h4 class="text-sm font-semibold text-gray-700 mb-3">Thông tin ngân hàng</h4>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                    <div>
                        <div class="text-gray-400 text-xs">Ngân hàng</div>
                        <div class="text-gray-800 font-medium">{{ $user->bank_name ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-xs">Số tài khoản</div>
                        <div class="text-gray-800 font-mono">{{ $user->bank_account_number ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-400 text-xs">Chủ tài khoản</div>
                        <div class="text-gray-800">{{ $user->bank_account_name ?? '—' }}</div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Pending Cashback Items --}}
        @if ($pendingCashbackItems->count() > 0)
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h4 class="text-sm font-semibold text-gray-700">Đơn chờ cashback ({{ $pendingCashbackItems->count() }})</h4>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50">
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Mã đơn</th>
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Shop</th>
                                <th class="px-4 py-2.5 text-right text-gray-500 font-medium text-xs uppercase">Giá trị</th>
                                <th class="px-4 py-2.5 text-right text-gray-500 font-medium text-xs uppercase">Cashback</th>
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Ngày đặt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pendingCashbackItems as $item)
                                <tr class="border-b border-gray-50">
                                    <td class="px-4 py-2.5 text-gray-700 font-mono text-xs">{{ $item->order_id }}</td>
                                    <td class="px-4 py-2.5 text-gray-600 text-xs">{{ $item->shop_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right text-gray-800 text-xs">{{ number_format($item->order_amount, 0, ',', '.') }}đ</td>
                                    <td class="px-4 py-2.5 text-right text-amber-600 font-medium text-xs">{{ number_format($item->cashback_amount, 0, ',', '.') }}đ</td>
                                    <td class="px-4 py-2.5 text-gray-400 text-xs whitespace-nowrap">{{ $item->ordered_at?->format('d/m/Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Recent Orders --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h4 class="text-sm font-semibold text-gray-700">Đơn hàng gần đây</h4>
            </div>
            @if ($recentOrders->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50">
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Mã đơn</th>
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Shop</th>
                                <th class="px-4 py-2.5 text-right text-gray-500 font-medium text-xs uppercase">Giá trị</th>
                                <th class="px-4 py-2.5 text-right text-gray-500 font-medium text-xs uppercase">Cashback</th>
                                <th class="px-4 py-2.5 text-center text-gray-500 font-medium text-xs uppercase">Trạng thái</th>
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Ngày đặt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentOrders as $item)
                                <tr class="border-b border-gray-50">
                                    <td class="px-4 py-2.5 text-gray-700 font-mono text-xs">{{ $item->order_id }}</td>
                                    <td class="px-4 py-2.5 text-gray-600 text-xs">{{ $item->shop_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right text-gray-800 text-xs">{{ number_format($item->order_amount, 0, ',', '.') }}đ</td>
                                    <td class="px-4 py-2.5 text-right text-emerald-700 font-medium text-xs">{{ number_format($item->cashback_amount, 0, ',', '.') }}đ</td>
                                    <td class="px-4 py-2.5 text-center">
                                        @if ($item->order_status === 'completed' || $item->order_status === 'Hoàn thành')
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Hoàn thành</span>
                                        @elseif ($item->order_status === 'cancelled' || $item->order_status === 'refunded')
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Đã hủy</span>
                                        @else
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ $item->order_status ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-gray-400 text-xs whitespace-nowrap">{{ $item->ordered_at?->format('d/m/Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-4 py-6 text-center text-gray-300 text-sm">
                    Chưa có đơn hàng nào
                </div>
            @endif
        </div>

        {{-- Recent Withdrawals --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h4 class="text-sm font-semibold text-gray-700">Yêu cầu rút tiền gần đây</h4>
            </div>
            @if ($recentWithdrawals->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50">
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Mã</th>
                                <th class="px-4 py-2.5 text-right text-gray-500 font-medium text-xs uppercase">Số tiền</th>
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Ngân hàng</th>
                                <th class="px-4 py-2.5 text-center text-gray-500 font-medium text-xs uppercase">Trạng thái</th>
                                <th class="px-4 py-2.5 text-right text-gray-500 font-medium text-xs uppercase">Ngày tạo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentWithdrawals as $wr)
                                <tr class="border-b border-gray-50">
                                    <td class="px-4 py-2.5 text-gray-700 font-mono text-xs">{{ $wr->running_no }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-gray-800 text-xs">{{ number_format($wr->amount, 0, ',', '.') }}đ</td>
                                    <td class="px-4 py-2.5 text-gray-600 text-xs">{{ $wr->bank_name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-center">
                                        @if($wr->status === 'pending')
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Chờ xử lý</span>
                                        @elseif($wr->status === 'paid')
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Đã thanh toán</span>
                                        @elseif($wr->status === 'rejected')
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Đã từ chối</span>
                                        @else
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ $wr->status }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-gray-400 text-xs whitespace-nowrap">{{ $wr->created_at->format('d/m/Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-4 py-6 text-center text-gray-300 text-sm">
                    Chưa có yêu cầu rút tiền nào
                </div>
            @endif
        </div>

        {{-- Recent Wallet Transactions --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100">
                <h4 class="text-sm font-semibold text-gray-700">Giao dịch ví gần đây</h4>
            </div>
            @if ($recentTransactions->count() > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50">
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Mã</th>
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Loại</th>
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Hướng</th>
                                <th class="px-4 py-2.5 text-right text-gray-500 font-medium text-xs uppercase">Số tiền</th>
                                <th class="px-4 py-2.5 text-center text-gray-500 font-medium text-xs uppercase">Trạng thái</th>
                                <th class="px-4 py-2.5 text-left text-gray-500 font-medium text-xs uppercase">Mô tả</th>
                                <th class="px-4 py-2.5 text-right text-gray-500 font-medium text-xs uppercase">Ngày</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentTransactions as $tx)
                                <tr class="border-b border-gray-50">
                                    <td class="px-4 py-2.5 text-gray-500 font-mono text-xs">{{ $tx->running_no }}</td>
                                    <td class="px-4 py-2.5 text-gray-700 text-xs">{{ ucfirst($tx->type) }}</td>
                                    <td class="px-4 py-2.5 text-xs">
                                        @if ($tx->direction === 'credit')
                                            <span class="text-emerald-600 font-medium">+Credit</span>
                                        @else
                                            <span class="text-red-500 font-medium">-Debit</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-xs {{ $tx->direction === 'credit' ? 'text-emerald-700 font-semibold' : 'text-red-600 font-semibold' }}">
                                        {{ $tx->direction === 'credit' ? '+' : '-' }}{{ number_format($tx->amount, 0, ',', '.') }}đ
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        @if($tx->status === 'completed')
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Hoàn thành</span>
                                        @elseif($tx->status === 'pending')
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Chờ xử lý</span>
                                        @elseif($tx->status === 'cancelled')
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Đã hủy</span>
                                        @else
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ $tx->status }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-gray-500 text-xs max-w-[200px] truncate" title="{{ $tx->description }}">{{ $tx->description ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-right text-gray-400 text-xs whitespace-nowrap">{{ $tx->created_at->format('d/m/Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-4 py-6 text-center text-gray-300 text-sm">
                    Chưa có giao dịch nào
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

@push('scripts')
<script>
    function walletAdjust() {
        return {
            amount: '',
            reason: '',
            direction: 'credit',
            confirmAdjust(event) {
                const dir = this.direction;
                const amount = this.amount;
                const amt = Number(amount).toLocaleString('vi-VN');
                const action = dir === 'credit' ? 'cong' : 'tru';
                const label = action === 'cong'
                    ? 'Bạn có chắc muốn cộng ' + amt + ' VNĐ vào ví User này?'
                    : 'Bạn có chắc muốn trừ ' + amt + ' VNĐ khỏi ví User này?';
                if (!confirm(label)) {
                    event.preventDefault();
                    return;
                }
                const btn = this.$refs.submitBtn;
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Đang xử lý...';
                }
            }
        }
    }
</script>
@endpush