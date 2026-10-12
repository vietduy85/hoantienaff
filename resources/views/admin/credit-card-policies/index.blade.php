<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                    🎯 Chính sách hoàn tiền hệ thống
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">Quản lý các chính sách hoàn tiền dùng làm mẫu cho người dùng.</p>
            </div>
            <a href="{{ route('admin.credit-card-policies.create') }}"
               class="shrink-0 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
                + Tạo chính sách hệ thống
            </a>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-6xl mx-auto space-y-4">
        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 text-sm text-rose-700">
                {{ session('error') }}
            </div>
        @endif

        {{-- ═══ TÌM KIẾM CHÍNH SÁCH ═══
             Lọc BACKEND trên toàn bộ danh sách template hệ thống (giữ từ khoá trong
             URL `?q=`). Giống ô tìm kiếm ở Quản lý thẻ về mặt hình thức (kính lúp,
             kích thước, bo góc, focus); khác ở chỗ gửi GET lên server thay vì lọc
             client-side vì danh sách này đổ từ database. Rỗng ⇒ hiện đủ như trước. --}}
        <form method="GET" action="{{ route('admin.credit-card-policies.index') }}" role="search">
            <label class="relative block">
                <span class="sr-only">Tìm chính sách hệ thống</span>
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                    </svg>
                </span>
                <input type="search"
                       inputmode="search"
                       name="q"
                       value="{{ $search }}"
                       data-testid="policy-search-input"
                       placeholder="Tìm theo tên chính sách, ngân hàng hoặc sản phẩm..."
                       class="w-full h-11 pl-10 {{ $search !== '' ? 'pr-9' : 'pr-3' }} rounded-xl border border-gray-200 bg-white text-base sm:text-sm text-gray-800 placeholder:text-gray-400 focus:border-emerald-500 focus:ring-emerald-500">
                @if ($search !== '')
                    <a href="{{ route('admin.credit-card-policies.index') }}"
                       aria-label="Xóa từ khóa tìm kiếm"
                       title="Xóa từ khóa tìm kiếm"
                       data-testid="policy-search-clear"
                       class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M6.28 4.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 4.22z" clip-rule="evenodd" />
                        </svg>
                    </a>
                @endif
            </label>
        </form>

        <div class="grid gap-4 sm:grid-cols-2">
            @forelse ($templates as $template)
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-gray-800">{{ $template['name'] }}</h3>
                            @if ($template['description'])
                                <p class="text-sm text-gray-500 mt-0.5">{{ $template['description'] }}</p>
                            @endif
                        </div>
                        @if ($template['is_active'])
                            <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">🟢 Đang sử dụng</span>
                        @else
                            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600">⚪ Nháp</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-1.5 text-xs text-gray-600">
                        @if ($template['tiers_count'] !== null)
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">{{ $template['tiers_count'] }} bậc</span>
                        @endif
                        @if ($template['categories_count'] !== null)
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">{{ $template['categories_count'] }} danh mục</span>
                        @endif
                        @if ($template['version_no'] !== null)
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">Version {{ $template['version_no'] }}</span>
                        @endif
                        @if ($template['default_version_no'] !== null)
                            <span class="rounded-lg bg-amber-50 border border-amber-100 px-2 py-0.5 text-amber-700">⭐ Mặc định: Version {{ $template['default_version_no'] }}</span>
                        @endif
                        @if ($template['effective_from'])
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">Từ {{ $template['effective_from'] }}</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('admin.credit-card-policies.show', $template['id']) }}"
                           class="min-h-[44px] inline-flex items-center px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors">Xem</a>
                        <a href="{{ route('admin.credit-card-policies.edit', $template['id']) }}"
                           class="min-h-[44px] inline-flex items-center px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-medium rounded-lg transition-colors">Chỉnh sửa</a>
                        <a href="{{ route('admin.credit-card-policies.show', $template['id']) }}#versions"
                           class="min-h-[44px] inline-flex items-center px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors">Các phiên bản</a>
                        @if ($canManage)
                            <a href="{{ route('admin.credit-card.system-policies.clone', $template['id']) }}"
                               class="min-h-[44px] inline-flex items-center px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-xs font-medium rounded-lg transition-colors">📋 Clone</a>
                        @endif
                    </div>
                </div>
            @empty
                @if ($search !== '')
                    <div data-testid="policy-search-empty" class="sm:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
                        <p class="text-gray-500">Không tìm thấy chính sách hoàn tiền phù hợp.</p>
                    </div>
                @else
                    <div class="sm:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 p-10 text-center">
                        <p class="text-gray-500">Chưa có chính sách hệ thống nào.</p>
                        <a href="{{ route('admin.credit-card-policies.create') }}"
                           class="inline-block mt-3 px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors">
                            + Tạo chính sách hệ thống
                        </a>
                    </div>
                @endif
            @endforelse
        </div>
    </div>
</x-app-layout>