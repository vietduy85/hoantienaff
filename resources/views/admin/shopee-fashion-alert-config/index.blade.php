<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Cập nhật link cào xu Shopee') }}
        </h2>
    </x-slot>

    <div class="py-6 px-4 max-w-xl mx-auto">

        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700 mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700 mb-4">
                <ul class="list-disc ml-4 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.shopee-fashion-alert-config.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            {{-- URL ĐÍCH (href) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">URL đích (href)</h3>

                <div>
                    <label for="destination_url" class="block text-sm font-semibold text-gray-700 mb-1">URL đích</label>
                    <input type="url" name="destination_url" id="destination_url" required
                           value="{{ old('destination_url', $destinationUrl) }}"
                           placeholder="https://shp.ee/..."
                           class="block w-full h-11 px-3.5 text-sm border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400">
                    <p class="text-xs text-gray-500 mt-1">
                        Đây là URL <strong>thực sự được mở</strong> khi người dùng nhấn vào liên kết cào xu.
                        Chỉ chấp nhận HTTPS hợp lệ (không nhận javascript:, data:, http://).
                    </p>
                    @error('destination_url')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 border border-amber-200 p-4">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold text-amber-700 uppercase tracking-wide">Đang có hiệu lực</div>
                        <div class="text-sm text-amber-900 font-medium break-all" data-active-destination-url>{{ $destinationUrl }}</div>
                    </div>
                    <button type="button"
                            class="shrink-0 px-3 py-2 text-xs font-semibold text-amber-700 bg-white border border-amber-300 hover:bg-amber-100 rounded-lg transition-colors"
                            onclick="(() => {
                                const el = document.querySelector('[data-active-destination-url]');
                                const text = el ? el.textContent.trim() : '';
                                if (navigator.clipboard && text) { navigator.clipboard.writeText(text); }
                            })()">Sao chép</button>
                </div>
            </div>

            {{-- URL HIỂN THỊ --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">URL hiển thị</h3>

                <div>
                    <label for="display_url" class="block text-sm font-semibold text-gray-700 mb-1">URL hiển thị trên giao diện</label>
                    <input type="url" name="display_url" id="display_url" required
                           value="{{ old('display_url', $displayUrl) }}"
                           placeholder="https://s.shopee.vn/..."
                           class="block w-full h-11 px-3.5 text-sm border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400">
                    <p class="text-xs text-gray-500 mt-1">
                        Chuỗi người dùng thấy trong thông báo trên trang chủ. Giá trị này KHÁC với URL đích (href).
                    </p>
                    @error('display_url')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm">
                    Lưu cấu hình
                </button>
                <a href="{{ route('dashboard') }}"
                   class="px-5 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-xl transition-colors">
                    Về trang chủ
                </a>
            </div>
        </form>
    </div>
</x-app-layout>
