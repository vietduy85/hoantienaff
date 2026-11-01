<x-app-layout>
    <x-slot name="header">
        <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
            {{ __('Sửa thông báo trang chủ') }}
        </h2>
    </x-slot>

    <div class="py-6 px-4 max-w-3xl mx-auto">
        <form method="POST" action="{{ route('admin.announcements.update', $announcement) }}" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-6">
            @csrf
            @method('PUT')

            @include('admin.announcements._form', ['announcement' => $announcement])

            <p class="mt-4 text-xs text-gray-400">
                Lưu thay đổi thì thông báo sẽ hiện lại cho cả những người đã bấm ✕ ẩn nó.
                Đã có {{ number_format($announcement->click_count, 0, ',', '.') }} lượt bấm.
            </p>

            <div class="mt-6 flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm">
                    Lưu
                </button>
                <a href="{{ route('admin.announcements.index') }}" class="px-5 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-xl transition-colors">
                    Huỷ
                </a>
            </div>
        </form>
    </div>
</x-app-layout>
