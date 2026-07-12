<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Cấu hình tạo Link') }}
        </h2>
    </x-slot>

    <div class="py-6 px-4 max-w-xl mx-auto"
         x-data="{
             dashboardStrategy: '{{ $settings['dashboard_strategy'] }}',
             adminStrategy: '{{ $settings['admin_strategy'] }}'
         }">

        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm text-emerald-700 mb-4">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.affiliate-config.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            {{-- Phần 1: Dashboard Strategy --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Dashboard</h3>

                <div class="space-y-2">
                    <label class="flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-colors"
                           :class="dashboardStrategy === 'direct' ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="dashboard_strategy" value="direct" x-model="dashboardStrategy"
                               class="text-emerald-500 focus:ring-emerald-400">
                        <div>
                            <div class="text-sm font-medium text-gray-800">Direct Link</div>
                            <div class="text-xs text-gray-500">Tạo link trực tiếp bằng s.shopee.vn/an_redir</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-colors"
                           :class="dashboardStrategy === 'extension' ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="dashboard_strategy" value="extension" x-model="dashboardStrategy"
                               class="text-emerald-500 focus:ring-emerald-400">
                        <div>
                            <div class="text-sm font-medium text-gray-800">Extension Worker</div>
                            <div class="text-xs text-gray-500">Dùng Browser Extension tạo link</div>
                        </div>
                    </label>
                </div>
                @error('dashboard_strategy')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Phần 2: Admin Short Link Strategy --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Admin Short Link</h3>

                <div class="space-y-2">
                    <label class="flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-colors"
                           :class="adminStrategy === 'extension' ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="admin_strategy" value="extension" x-model="adminStrategy"
                               class="text-emerald-500 focus:ring-emerald-400">
                        <div>
                            <div class="text-sm font-medium text-gray-800">Extension Worker</div>
                            <div class="text-xs text-gray-500">Dùng Browser Extension tạo link</div>
                        </div>
                    </label>

                    <label class="flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-colors"
                           :class="adminStrategy === 'direct' ? 'border-emerald-400 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="admin_strategy" value="direct" x-model="adminStrategy"
                               class="text-emerald-500 focus:ring-emerald-400">
                        <div>
                            <div class="text-sm font-medium text-gray-800">Direct Link</div>
                            <div class="text-xs text-gray-500">Tạo link trực tiếp bằng s.shopee.vn/an_redir</div>
                        </div>
                    </label>
                </div>
                @error('admin_strategy')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Phần 3: Cài đặt chung (cho Direct) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4"
                 x-show="dashboardStrategy === 'direct' || adminStrategy === 'direct'" x-transition>
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Cài đặt Direct Link</h3>

                {{-- Affiliate ID --}}
                <div>
                    <label for="affiliate_id" class="block text-sm font-semibold text-gray-700 mb-1">Affiliate ID</label>
                    <input type="text" name="affiliate_id" id="affiliate_id"
                           value="{{ old('affiliate_id', $settings['affiliate_id']) }}"
                           placeholder="VD: 17342330566"
                           class="block w-full h-11 px-3.5 text-sm border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400">
                    <p class="text-xs text-gray-400 mt-1">Affiliate ID từ Shopee Affiliate</p>
                    @error('affiliate_id')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Resolve Shortlink --}}
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-3">Resolve Shortlink</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="resolve" value="true"
                                   {{ old('resolve', $settings['resolve']) === 'true' ? 'checked' : '' }}
                                   class="text-emerald-500 focus:ring-emerald-400">
                            <span class="text-sm text-gray-700">Bật</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="resolve" value="false"
                                   {{ old('resolve', $settings['resolve']) === 'false' ? 'checked' : '' }}
                                   class="text-emerald-500 focus:ring-emerald-400">
                            <span class="text-sm text-gray-700">Tắt</span>
                        </label>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Tự resolve s.shopee.vn trước khi tạo link</p>
                    @error('resolve')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Submit --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <button type="submit"
                        class="w-full h-11 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-emerald-200 hover:shadow-emerald-300 transition-all duration-150">
                    Lưu cấu hình
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
