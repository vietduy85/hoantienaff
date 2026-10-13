<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                    🧩 Chỉnh sửa hồ sơ MCC
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">Cập nhật MCC được hoàn tiền / loại trừ và tài liệu đính kèm. Chính sách không đổi.</p>
            </div>
            <div class="shrink-0 flex items-center gap-2">
                <a href="{{ route('admin.credit-card.mcc.show', $profile->id) }}"
                   class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-xl transition-colors">Xem chi tiết</a>
                <a href="{{ route('admin.credit-card.mcc.index') }}"
                   class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-xl transition-colors">← Danh sách</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-4xl mx-auto">
        <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <x-mcc-profile-form
                :action="route('admin.credit-card.mcc.update', $profile->id)"
                method="PATCH"
                :templates="$templates"
                :master-endpoint="$masterEndpoint"
                :is-edit="true"
                :template-name="$profile->template?->name"
                :initial-name="$initialName"
                :initial-template-id="$initialTemplateId"
                :initial-note="$initialNote"
                :initial-annual-fee-primary="$initialAnnualFeePrimary"
                :initial-annual-fee-supplementary="$initialAnnualFeeSupplementary"
                :initial-annual-spend-for-waiver="$initialAnnualSpendForWaiver"
                :initial-included="$initialIncluded"
                :initial-excluded="$initialExcluded"
                :documents="$profile->documents"
            />
        </div>
    </div>
</x-app-layout>
