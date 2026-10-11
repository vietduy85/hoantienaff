<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                    🧩 Tạo hồ sơ MCC chính sách
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">Nhập tay MCC được hoàn tiền và MCC loại trừ cho một chính sách.</p>
            </div>
            <a href="{{ route('admin.credit-card.mcc.index') }}"
               class="shrink-0 px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-xl transition-colors">← Danh sách</a>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-4xl mx-auto">
        <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <x-mcc-profile-form
                :action="route('admin.credit-card.mcc.store')"
                method="POST"
                :templates="$templates"
                :master-endpoint="$masterEndpoint"
                :initial-name="$initialName"
                :initial-template-id="$initialTemplateId"
                :initial-note="$initialNote"
                :initial-included="$initialIncluded"
                :initial-excluded="$initialExcluded"
            />
        </div>
    </div>
</x-app-layout>
