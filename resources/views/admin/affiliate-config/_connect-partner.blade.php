{{--
    Tab "Connect Partner AFF" — READ-ONLY.
    Hiển thị trạng thái thật của nhà cung cấp, phương thức tạo link và
    CHUỖI NHẬN DIỆN URL (detect rules) đọc trực tiếp từ code đang chạy.
    KHÔNG có form lưu cấu hình, KHÔNG có input secret, KHÔNG gọi API.
    KHÔNG tạo rule giả: AccessTrade không có rule nào nên hiển thị empty state.
    Phần persistence sinh rule sẽ được đề xuất riêng (xem báo cáo).
--}}
@php
    $statusClasses = [
        'configured' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'incomplete' => 'bg-amber-50 text-amber-700 border-amber-200',
        'not_configured' => 'bg-gray-100 text-gray-500 border-gray-200',
    ];
    $methodStateClasses = [
        'active' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'available' => 'bg-gray-50 text-gray-600 border-gray-200',
        'not_implemented' => 'bg-gray-50 text-gray-400 border-gray-200',
    ];
    $methodStateLabel = [
        'active' => 'Đang dùng',
        'available' => 'Có thể dùng',
        'not_implemented' => 'Chưa triển khai',
    ];
    $matchLabel = [
        'substring' => 'Substring (toàn URL)',
        'hostname' => 'Hostname (exact/subdomain)',
    ];
    $sourceLabel = [
        'code' => 'Code',
        'config' => 'Config',
        'database' => 'Database',
    ];
    $detectStatusClasses = [
        'active' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'flow_only' => 'bg-blue-50 text-blue-700 border-blue-200',
        'diagnostic' => 'bg-gray-50 text-gray-500 border-gray-200',
        'legacy' => 'bg-amber-50 text-amber-700 border-amber-200',
    ];
    $detectStatusLabel = [
        'active' => 'Đang sử dụng',
        'flow_only' => 'Chỉ một luồng',
        'diagnostic' => 'Chẩn đoán — không phải luồng thật',
        'legacy' => 'Legacy — không dùng',
    ];
@endphp

<div class="space-y-4">
    {{-- Thông báo phạm vi --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-xs text-blue-700 leading-relaxed">
        Khu vực này hiển thị <span class="font-semibold">trạng thái thật</span> của từng nhà cung cấp, các phương thức tạo link
        và <span class="font-semibold">chuỗi nhận diện URL</span> đọc trực tiếp từ code đang chạy.
        Đây là bước chuẩn bị giao diện — <span class="font-semibold">chưa lưu cấu hình</span>, <span class="font-semibold">chưa chỉnh sửa rule</span> và <span class="font-semibold">chưa gọi API AccessTrade</span>.
    </div>

    {{-- Danh sách nhà cung cấp --}}
    @foreach ($providers as $provider)
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-bold text-gray-800">{{ $provider['name'] }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Phương thức hiện tại:
                        <span class="font-medium text-gray-700">{{ $provider['method'] }}</span>
                    </p>
                </div>
                <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $statusClasses[$provider['status']] ?? $statusClasses['not_configured'] }}">
                    {{ $provider['status_label'] }}
                </span>
            </div>

            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Phương thức tạo link</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($provider['methods'] as $method)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium border {{ $methodStateClasses[$method['state']] ?? $methodStateClasses['not_implemented'] }}">
                            {{ $method['label'] }}
                            <span class="opacity-70">· {{ $methodStateLabel[$method['state']] ?? 'Chưa triển khai' }}</span>
                        </span>
                    @endforeach
                </div>
            </div>

            {{-- Chuỗi nhận diện URL (detect rules) --}}
            <div>
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Chuỗi nhận diện URL</div>

                @php $detect = $provider['detect'] ?? ['rules' => [], 'note' => null]; @endphp

                @if (empty($detect['rules']))
                    <div class="border border-dashed border-gray-200 rounded-xl p-4 text-center">
                        <p class="text-xs text-gray-500">{{ $detect['note'] ?? 'Chưa cấu hình chuỗi nhận diện.' }}</p>
                    </div>
                @else
                    <div class="space-y-2">
                        @foreach ($detect['rules'] as $rule)
                            <div class="rounded-lg border border-gray-100 bg-gray-50 p-2.5">
                                <div class="flex items-start justify-between gap-2">
                                    <code class="text-xs text-gray-800 break-all">{{ $rule['pattern'] }}</code>
                                    <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border bg-white text-gray-600 border-gray-200">
                                        {{ $matchLabel[$rule['match']] ?? $rule['match'] }}
                                    </span>
                                </div>
                                <div class="mt-1.5 space-y-0.5 text-[11px] text-gray-500 leading-snug">
                                    <div><span class="text-gray-400">Phạm vi:</span> {{ $rule['scope'] }}</div>
                                    <div><span class="text-gray-400">Phương thức:</span> {{ $rule['method'] }}</div>
                                    <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium border bg-white text-gray-500 border-gray-200">
                                            Nguồn: {{ $sourceLabel[$rule['source']] ?? $rule['source'] }}
                                        </span>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium border {{ $detectStatusClasses[$rule['status']] ?? $detectStatusClasses['diagnostic'] }}">
                                            {{ $detectStatusLabel[$rule['status']] ?? $rule['status'] }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if (! empty($detect['note']))
                        <p class="text-[11px] text-amber-600 leading-relaxed mt-2">Lưu ý: {{ $detect['note'] }}</p>
                    @endif
                @endif
            </div>

            <div class="pt-1">
                <button type="button" disabled
                        title="Chưa triển khai cấu hình"
                        class="inline-flex items-center h-9 px-3 text-xs font-semibold rounded-lg border border-gray-200 text-gray-400 cursor-not-allowed">
                    Chỉnh sửa cấu hình
                    <span class="ml-1.5 text-[10px] font-normal">· Chưa triển khai</span>
                </button>
            </div>
        </div>
    @endforeach

    {{-- AccessTrade — chuẩn bị giao diện, chưa lưu --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">AccessTrade</h3>
                <p class="text-xs text-gray-500 mt-0.5">Cấu hình kết nối đối tác (chuẩn bị, chưa lưu)</p>
            </div>
            <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border bg-gray-100 text-gray-500 border-gray-200">
                Chưa kết nối
            </span>
        </div>

        <dl class="divide-y divide-gray-100 text-sm">
            <div class="flex items-center justify-between gap-4 py-2.5">
                <dt class="text-gray-500">API Endpoint (dự kiến)</dt>
                <dd class="text-gray-700 font-mono text-xs">https://api.accesstrade.vn</dd>
            </div>
            <div class="flex items-center justify-between gap-4 py-2.5">
                <dt class="text-gray-500">API Key</dt>
                <dd>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border bg-gray-100 text-gray-500 border-gray-200">
                        Chưa cấu hình
                    </span>
                </dd>
            </div>
            <div class="flex items-center justify-between gap-4 py-2.5">
                <dt class="text-gray-500">Phương thức được phép</dt>
                <dd>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border bg-gray-50 text-gray-400 border-gray-200">
                        AccessTrade API · Chưa triển khai
                    </span>
                </dd>
            </div>
        </dl>

        <div>
            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Danh sách campaign</div>
            <div class="border border-dashed border-gray-200 rounded-xl p-6 text-center">
                <p class="text-sm text-gray-500">Chưa kết nối AccessTrade</p>
                <p class="text-xs text-gray-400 mt-1">Campaign, merchant và domain sẽ hiển thị tại đây sau khi tích hợp API.</p>
            </div>
        </div>

        <p class="text-[11px] text-gray-400 leading-relaxed">
            Ghi chú: dữ liệu campaign chỉ là mô tả giao diện. Không có campaign/domain nào được tạo thật trong bước này.
        </p>
    </div>
</div>
