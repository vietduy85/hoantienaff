<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                    🧩 {{ $profile->name }}
                </h2>
                <p class="text-sm text-gray-500 mt-0.5">
                    Chính sách: {{ $profile->template?->name ?? '—' }}
                </p>
            </div>
            <div class="shrink-0 flex items-center gap-2">
                @can('credit-cards.manage')
                    <a href="{{ route('admin.credit-card.mcc.edit', $profile->id) }}"
                       class="px-3 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors">Chỉnh sửa</a>
                @endcan
                <a href="{{ route('admin.credit-card.mcc.index') }}"
                   class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-xl transition-colors">← Danh sách</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-4xl mx-auto space-y-4">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">{{ session('status') }}</div>
        @endif

        @if ($profile->note)
            <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Ghi chú chung</p>
                <p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $profile->note }}</p>
            </div>
        @endif

        @php
            $annualFees = [
                ['label' => 'Phí thường niên thẻ chính', 'value' => $profile->annual_fee_primary, 'suffix' => ' đ/năm'],
                ['label' => 'Phí thường niên thẻ phụ', 'value' => $profile->annual_fee_supplementary, 'suffix' => ' đ/năm'],
                ['label' => 'Chi tiêu để miễn phí thường niên', 'value' => $profile->annual_spend_for_annual_fee_waiver, 'suffix' => ' đ/năm'],
            ];
            $hasAnnualFee = collect($annualFees)->contains(fn (array $fee): bool => $fee['value'] !== null && $fee['value'] !== '');
        @endphp

        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Phí thường niên (tham khảo)</p>

            @if ($hasAnnualFee)
                <dl class="grid gap-2 sm:grid-cols-3">
                    @foreach ($annualFees as $fee)
                        @if ($fee['value'] !== null && $fee['value'] !== '')
                            <div class="rounded-xl bg-gray-50 p-3">
                                <dt class="text-[11px] font-medium text-gray-500">{{ $fee['label'] }}</dt>
                                <dd class="mt-0.5 text-sm font-semibold text-gray-800">
                                    {{ number_format((float) $fee['value'], 0, ',', '.') }}{{ $fee['suffix'] }}
                                </dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-gray-400">Chưa có thông tin phí thường niên.</p>
            @endif
        </div>

        @php
            $sections = [
                ['title' => 'MCC được hoàn tiền', 'rows' => $profile->includedRows, 'tone' => 'emerald'],
                ['title' => 'MCC loại trừ', 'rows' => $profile->excludedRows, 'tone' => 'rose'],
            ];
        @endphp

        @foreach ($sections as $section)
            <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800">{{ $section['title'] }}</h3>
                    <span class="text-xs text-gray-400">{{ $section['rows']->count() }} dòng</span>
                </div>

                @forelse ($section['rows'] as $row)
                    <div class="rounded-xl border border-gray-100 bg-gray-50/50 p-3 space-y-1.5">
                        @if ($row->category_name)
                            <p class="text-sm font-medium text-gray-800">{{ $row->category_name }}</p>
                        @endif
                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($row->codes as $code)
                                <span class="rounded-md border px-2 py-0.5 font-mono text-xs {{ $section['tone'] === 'rose' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' }}">
                                    {{ $code->mcc }}@if ($code->mccCode?->description_vi)<span class="ml-1 font-sans text-[11px] text-gray-500">· {{ $code->mccCode->description_vi }}</span>@endif
                                </span>
                            @empty
                                <span class="text-xs text-gray-400">Chưa có mã nào.</span>
                            @endforelse
                        </div>
                        @if ($row->description)
                            <p class="text-xs text-gray-500">{{ $row->description }}</p>
                        @endif
                        @if ($row->note)
                            <p class="text-xs text-gray-500">{{ $row->note }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-400">Không có dòng nào.</p>
                @endforelse
            </div>
        @endforeach

        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-gray-800">Tài liệu ngân hàng</h3>
                <span class="text-xs text-gray-400">{{ $profile->documents->count() }} tệp</span>
            </div>

            <ul class="space-y-1.5">
                @forelse ($profile->documents as $document)
                    <li class="flex items-center justify-between gap-2 rounded-xl border border-gray-100 bg-gray-50 px-3 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-gray-700">{{ $document->original_name }}</p>
                            <p class="text-[11px] text-gray-400">{{ $document->humanSize() }}</p>
                        </div>
                        <a href="{{ route('admin.credit-card.mcc.documents.download', [$profile->id, $document->id]) }}"
                           class="shrink-0 text-xs font-medium text-emerald-700 hover:text-emerald-800">Tải xuống</a>
                    </li>
                @empty
                    <li class="text-sm text-gray-400">Chưa có tài liệu nào.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-app-layout>
