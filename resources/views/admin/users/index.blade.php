<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Quản lý người dùng') }}
        </h2>
    </x-slot>

    <div class="py-6 px-4 max-w-7xl mx-auto space-y-4">
        <form method="GET" class="flex flex-col sm:flex-row gap-3">
            <input
                type="text"
                name="search"
                value="{{ request('search') }}"
                placeholder="Tìm username, email, tên, Google ID..."
                class="flex-1 px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:border-emerald-400 transition-colors"
            >
            <select
                name="role"
                class="px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:border-emerald-400 transition-colors"
            >
                <option value="">Tất cả Role</option>
                <option value="Admin" {{ request('role') === 'Admin' ? 'selected' : '' }}>Admin</option>
                <option value="Merchant" {{ request('role') === 'Merchant' ? 'selected' : '' }}>Merchant</option>
                <option value="Affiliate" {{ request('role') === 'Affiliate' ? 'selected' : '' }}>Affiliate</option>
                <option value="Member" {{ request('role') === 'Member' ? 'selected' : '' }}>Member</option>
            </select>
            <select
                name="sort"
                class="px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:border-emerald-400 transition-colors"
            >
                <option value="">Mới nhất</option>
                <option value="orders_desc" {{ request('sort') === 'orders_desc' ? 'selected' : '' }}>Số đơn: Cao → thấp</option>
                <option value="orders_asc" {{ request('sort') === 'orders_asc' ? 'selected' : '' }}>Số đơn: Thấp → cao</option>
                <option value="order_value_desc" {{ request('sort') === 'order_value_desc' ? 'selected' : '' }}>Giá trị đơn: Cao → thấp</option>
                <option value="order_value_asc" {{ request('sort') === 'order_value_asc' ? 'selected' : '' }}>Giá trị đơn: Thấp → cao</option>
                <option value="cashback_desc" {{ request('sort') === 'cashback_desc' ? 'selected' : '' }}>Tiền hoàn: Cao → thấp</option>
                <option value="cashback_asc" {{ request('sort') === 'cashback_asc' ? 'selected' : '' }}>Tiền hoàn: Thấp → cao</option>
            </select>
            <button type="submit" class="px-5 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                Tìm kiếm
            </button>
            @if (request('search') || request('role') || request('sort'))
                <a href="{{ route('admin.users.index') }}" class="px-4 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-600 text-sm font-medium rounded-xl transition-colors text-center">
                    Xóa bộ lọc
                </a>
            @endif
        </form>

        <div class="text-sm text-gray-500">
            {{ $users->total() }} người dùng
            @if (request('search'))
                — tìm "{{ request('search') }}"
            @endif
            @if (request('role'))
                — Role: {{ request('role') }}
            @endif
        </div>

        {{-- Desktop: table --}}
        <div class="hidden lg:block bg-white rounded-2xl shadow-sm border border-gray-100 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50">
                        <th class="px-3 py-3 text-left text-gray-500 font-medium text-xs uppercase">Avatar</th>
                        <th class="px-3 py-3 text-left text-gray-500 font-medium text-xs uppercase">Username</th>
                        <th class="px-3 py-3 text-left text-gray-500 font-medium text-xs uppercase">Tên</th>
                        <th class="px-3 py-3 text-left text-gray-500 font-medium text-xs uppercase">Email</th>
                        <th class="px-3 py-3 text-left text-gray-500 font-medium text-xs uppercase">Google</th>
                        <th class="px-3 py-3 text-center text-gray-500 font-medium text-xs uppercase">Role</th>
                        <th class="px-3 py-3 text-right text-gray-500 font-medium text-xs uppercase">Ví hiện tại</th>
                        <th class="px-3 py-3 text-right text-gray-500 font-medium text-xs uppercase">Tiền chờ về</th>
                        <th class="px-3 py-3 text-right text-gray-500 font-medium text-xs uppercase">Số đơn</th>
                        <th class="px-3 py-3 text-right text-gray-500 font-medium text-xs uppercase">Tổng giá trị đơn</th>
                        <th class="px-3 py-3 text-right text-gray-500 font-medium text-xs uppercase">Tổng tiền hoàn</th>
                        <th class="px-3 py-3 text-right text-gray-500 font-medium text-xs uppercase">Đăng nhập cuối</th>
                        <th class="px-3 py-3 text-right text-gray-500 font-medium text-xs uppercase">Ngày tạo</th>
                        <th class="px-3 py-3 text-center text-gray-500 font-medium text-xs uppercase">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors">
                            <td class="px-3 py-3">
                                @if ($user->avatar)
                                    <img src="{{ $user->avatar }}" alt="" class="w-8 h-8 rounded-full object-cover border border-gray-200">
                                @else
                                    <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-400 text-xs font-medium">
                                        {{ strtoupper(substr($user->name ?? $user->username ?? '?', 0, 1)) }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <a href="{{ route('admin.users.show', $user) }}" class="text-emerald-600 hover:text-emerald-700 font-medium text-xs">
                                    {{ $user->username ?? '—' }}
                                </a>
                            </td>
                            <td class="px-3 py-3 text-gray-700 text-xs">{{ $user->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-gray-500 text-xs max-w-[180px] truncate" title="{{ $user->email }}">{{ $user->email }}</td>
                            <td class="px-3 py-3 text-gray-400 text-xs font-mono max-w-[120px] truncate" title="{{ $user->google_id }}">
                                {{ $user->google_id ? Str::limit($user->google_id, 12, '...') : '—' }}
                            </td>
                            <td class="px-3 py-3 text-center">
                                @php $role = $user->roles->first(); @endphp
                                @if ($role)
                                    @if ($role->name === 'Admin')
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">Admin</span>
                                    @elseif ($role->name === 'Merchant')
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Merchant</span>
                                    @elseif ($role->name === 'Affiliate')
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Affiliate</span>
                                    @elseif ($role->name === 'Member')
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Member</span>
                                    @else
                                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">{{ $role->name }}</span>
                                    @endif
                                @else
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Chưa gán</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right font-semibold text-gray-800 text-xs whitespace-nowrap">
                                {{ number_format($user->wallet_balance, 0, ',', '.') }}đ
                            </td>
                            <td class="px-3 py-3 text-right text-amber-600 text-xs whitespace-nowrap">
                                {{ number_format($user->pending_cashback_amount, 0, ',', '.') }}đ
                            </td>
                            <td class="px-3 py-3 text-right text-gray-700 text-xs">
                                {{ number_format($user->orders_count, 0, ',', '.') }}
                            </td>
                            <td class="px-3 py-3 text-right text-gray-700 text-xs whitespace-nowrap">
                                {{ number_format($user->total_order_value, 0, ',', '.') }}đ
                            </td>
                            <td class="px-3 py-3 text-right font-semibold text-emerald-700 text-xs whitespace-nowrap">
                                {{ number_format($user->total_cashback_only, 0, ',', '.') }}đ
                            </td>
                            <td class="px-3 py-3 text-right text-gray-400 text-xs whitespace-nowrap">
                                {{ $user->last_login_at ? \Carbon\Carbon::parse($user->last_login_at)->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td class="px-3 py-3 text-right text-gray-400 text-xs whitespace-nowrap">
                                {{ $user->created_at->format('d/m/Y') }}
                            </td>
                            <td class="px-3 py-3 text-center">
                                @if ($user->status === 'active')
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Active</span>
                                @elseif ($user->status === 'inactive' || $user->status === 'banned')
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{{ ucfirst($user->status) }}</span>
                                @else
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ $user->status ?? '—' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="14" class="px-4 py-8 text-center text-gray-300">
                                Không tìm thấy người dùng nào
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile: cards --}}
        <div class="lg:hidden space-y-4">
            @forelse ($users as $user)
                <a href="{{ route('admin.users.show', $user) }}" class="block bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3 hover:shadow-md transition-shadow">
                    <div class="flex items-center gap-3">
                        @if ($user->avatar)
                            <img src="{{ $user->avatar }}" alt="" class="w-10 h-10 rounded-full object-cover border border-gray-200">
                        @else
                            <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-400 text-sm font-medium">
                                {{ strtoupper(substr($user->name ?? $user->username ?? '?', 0, 1)) }}
                            </div>
                        @endif
                        <div class="flex-1 min-w-0">
                            <div class="text-emerald-600 font-semibold text-sm">{{ $user->username ?? '—' }}</div>
                            <div class="text-gray-500 text-xs truncate">{{ $user->email }}</div>
                        </div>
                        @php $role = $user->roles->first(); @endphp
                        @if ($role)
                            @if ($role->name === 'Admin')
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">Admin</span>
                            @elseif ($role->name === 'Merchant')
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">Merchant</span>
                            @elseif ($role->name === 'Affiliate')
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Affiliate</span>
                            @elseif ($role->name === 'Member')
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Member</span>
                            @else
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">{{ $role->name }}</span>
                            @endif
                        @else
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Chưa gán</span>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <div class="text-gray-400 text-xs">Ví hiện tại</div>
                            <div class="text-gray-800 font-semibold">{{ number_format($user->wallet_balance, 0, ',', '.') }}đ</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Tiền chờ về</div>
                            <div class="text-amber-600 font-medium">{{ number_format($user->pending_cashback_amount, 0, ',', '.') }}đ</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Số đơn</div>
                            <div class="text-gray-800">{{ number_format($user->orders_count) }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Giá trị đơn</div>
                            <div class="text-gray-800">{{ number_format($user->total_order_value, 0, ',', '.') }}đ</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Tổng đã hoàn</div>
                            <div class="text-emerald-700 font-medium">{{ number_format($user->total_cashback_only, 0, ',', '.') }}đ</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Ngày tạo</div>
                            <div class="text-gray-700 text-xs">{{ $user->created_at->format('d/m/Y') }}</div>
                        </div>
                        <div>
                            <div class="text-gray-400 text-xs">Trạng thái</div>
                            @if ($user->status === 'active')
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Active</span>
                            @elseif ($user->status === 'inactive' || $user->status === 'banned')
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{{ ucfirst($user->status) }}</span>
                            @else
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">{{ $user->status ?? '—' }}</span>
                            @endif
                        </div>
                    </div>
                </a>
            @empty
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 text-center">
                    <p class="text-gray-300">Không tìm thấy người dùng nào</p>
                </div>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $users->links() }}
        </div>
    </div>
</x-app-layout>
