<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">➕ Tạo chính sách hoàn tiền hệ thống</h2>
                <p class="text-sm text-gray-500 mt-0.5">
                    <a href="{{ route('admin.credit-card-policies.index') }}" class="hover:underline">← Quay lại danh sách</a>
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-6xl mx-auto">
        @include('admin.credit-card-policies.partials.editor', [
            'endpoint' => route('admin.credit-card-policies.api.store'),
            'submitLabel' => 'Tạo chính sách hệ thống',
        ])
    </div>
</x-app-layout>