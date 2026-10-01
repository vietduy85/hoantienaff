<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                    @if ($cloneMode ?? false)
                        📋 Clone chính sách hoàn tiền hệ thống
                    @else
                        ✏️ Chỉnh sửa chính sách hoàn tiền hệ thống
                    @endif
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">
                    <a href="{{ route('admin.credit-card-policies.index') }}" class="hover:underline">← Quay lại danh sách</a>
                </p>
            </div>
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

        @if ($cloneMode ?? false)
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                <p class="font-semibold">Bản sao từ chính sách "{{ $sourceName }}"</p>
                <p class="mt-1">
                    Bạn đang tạo một bản sao <strong>độc lập hoàn toàn</strong> của chính sách
                    <strong>{{ $sourceName }}</strong>. Toàn bộ phiên bản, bậc chi tiêu và quy tắc
                    danh mục được sao chép sang bản mới; chỉnh sửa bản mới sau này KHÔNG ảnh hưởng
                    tới chính sách gốc. Bấm <strong>Lưu thành chính sách mới</strong> để tạo bản sao
                    với các thay đổi bạn đã chỉnh ở dưới.
                </p>
            </div>
        @else
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                <p class="font-semibold">Lưu ý về phiên bản</p>
                <p class="mt-1">Trang này có hai cách lưu:</p>
                <ul class="mt-1 list-disc pl-5 space-y-1">
                    <li><strong>Lưu lại</strong> — cập nhật ngay <em>chính</em> phiên bản đang sửa (giữ nguyên số phiên bản và ngày hiệu lực, tên/mô tả vẫn đổi qua nút "Lưu thay đổi" ở trang danh sách). Dùng khi chỉnh lại cấu hình của kỳ chưa finalize.</li>
                    <li><strong>Lưu phiên bản mới</strong> — sao chép phiên bản đang sửa thành phiên bản kế tiếp (bắt đầu hiệu lực từ ngày bạn chọn), phiên bản cũ giữ nguyên để đối soát.</li>
                </ul>

                @if ($editingVersionNo !== null)
                    <p class="mt-2 text-xs">
                        Đang chỉnh sửa <strong>Version {{ $editingVersionNo }}</strong> — chỉ "Lưu phiên bản mới"
                        mới tạo phiên bản kế tiếp; "Lưu lại" giữ nguyên phiên bản này.
                    </p>
                @endif
            </div>
        @endif

        @include('admin.credit-card-policies.partials.editor', [
            'endpoint' => ($cloneMode ?? false)
                ? route('admin.credit-card-policies.api.clone', $templateId)
                : route('admin.credit-card-policies.api.versions.store', $templateId),
            'updateEndpoint' => ($cloneMode ?? false) ? null : (($sourceVersionId !== null && $editingVersionNo !== null)
                ? route('admin.credit-card-policies.api.versions.update', [$templateId, $sourceVersionId])
                : null),
            'submitLabel' => ($cloneMode ?? false) ? 'Lưu thành chính sách mới' : 'Lưu phiên bản mới',
            'sourceVersionId' => $sourceVersionId ?? null,
        ])
    </div>
</x-app-layout>