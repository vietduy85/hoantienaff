<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">🎯 {{ $template['name'] }}</h2>
                    @if ($template['is_active'])
                        <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 border border-emerald-100">🟢 Đang sử dụng</span>
                    @else
                        <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">⚪ Nháp</span>
                    @endif
                    @if ($template['default_version_no'] !== null)
                        <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 border border-amber-100">⭐ Mặc định: Version {{ $template['default_version_no'] }}</span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 mt-0.5">
                    <a href="{{ route('admin.credit-card-policies.index') }}" class="hover:underline">← Quay lại danh sách</a>
                </p>
                @if (isset($template['tiers_count']))
                    <p class="text-xs text-gray-400 mt-0.5">{{ $template['tiers_count'] }} bậc · {{ $template['categories_count'] }} danh mục</p>
                @endif
                @if (! empty($template['description']))
                    <p class="text-sm text-gray-500 mt-0.5">{{ $template['description'] }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                @if ($canManage)
                    <a href="{{ route('admin.credit-card-policies.edit', $template['id']) }}"
                       class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors shadow-sm text-center">
                        ✏️ Chỉnh sửa
                    </a>
                    <a href="{{ route('admin.credit-card.system-policies.clone', $template['id']) }}"
                       class="px-4 py-2 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium rounded-xl transition-colors shadow-sm text-center">
                        📋 Clone
                    </a>
                @else
                    <span class="px-3 py-1.5 rounded-xl bg-gray-100 text-gray-500 text-xs">Quyền xem</span>
                @endif
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

        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
            <p class="font-semibold">Chế độ xem (chỉ đọc)</p>
            <p class="mt-1">Bên dưới là cấu hình của ⭐ phiên bản mặc định ({{ $template['default_version_no'] ?? '—' }}).
                @if ($canManage)
                    Muốn thay đổi cấu hình, bấm <strong>✏️ Chỉnh sửa</strong> để mở trang sửa (bậc / tỷ lệ / trần hoàn của từng bậc),
                    hoặc <strong>📋 Clone</strong> để tạo một chính sách hệ thống mới từ cấu hình này.
                @else
                    Mọi thay đổi cấu hình cần người có quyền quản lý chính sách hệ thống.
                @endif</p>
        </div>

        {{-- Editor ở CHẾ ĐỘ XEM — dùng chung partial policy-editor.blade.php, chỉ đọc --}}
        @include('credit-card.partials.policy-editor', [
            'endpoint' => '',
            'updateEndpoint' => null,
            'submitLabel' => 'Lưu',
            'viewMode' => true,
            'sourceVersionId' => null,
            'initial' => $initial,
        ])

        {{-- Lịch sử phiên bản --}}
        <div id="versions" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-4 sm:px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2">
                <h3 class="font-semibold text-gray-800">Lịch sử phiên bản chính sách</h3>
                <span class="text-xs text-gray-500">{{ count($versions) }} phiên bản</span>
            </div>

            <div class="divide-y divide-gray-100">
                @forelse ($versions as $version)
                    <div class="p-4 sm:p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-gray-800 text-sm">Version {{ $version['version_no'] }}</span>
                                @if ($version['is_default'])
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 border border-amber-100">⭐ Mặc định</span>
                                @endif
                                @if ($version['active'])
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Hiện hành</span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-500">Đã thay thế</span>
                                @endif
                            </div>
                            <span class="text-xs text-gray-500">
                                {{ $version['effective_from'] }} → {{ $version['effective_to'] ?? 'hiện tại' }}
                            </span>
                        </div>

                        <div class="mt-3 grid gap-1.5 grid-cols-1 sm:grid-cols-3 text-xs text-gray-600">
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2.5 py-1">
                                Mức chi tiêu tối thiểu / kỳ: <strong>{{ number_format((float) $version['min_total_spend']) }} ₫</strong>
                            </span>
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2.5 py-1">
                                {{ $version['tiers_count'] }} bậc · {{ $version['categories_count'] }} danh mục
                            </span>
                            <span class="rounded-lg bg-gray-50 border border-gray-100 px-2.5 py-1">
                                Cách làm tròn: <strong>{{ $version['rounding_mode'] }}</strong>
                            </span>
                        </div>

                        @if ($canManage)
                            <div class="mt-3 flex flex-wrap items-center justify-end gap-2 pt-3 border-t border-gray-100">
                                <a href="{{ route('admin.credit-card-policies.edit', ['template' => $template['id'], 'version' => $version['id']]) }}"
                                   class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium rounded-lg transition-colors">
                                    ✏️ Chỉnh sửa
                                </a>
                                @if ($version['is_default'])
                                    <span class="px-3 py-1.5 bg-amber-100 text-amber-800 text-xs font-medium rounded-lg">
                                        ⭐ Đang mặc định
                                    </span>
                                @else
                                    <form method="POST" action="{{ route('admin.credit-card-policies.api.default.store', $template['id']) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="version_id" value="{{ $version['id'] }}">
                                        <button type="submit"
                                                class="px-3 py-1.5 bg-amber-100 hover:bg-amber-200 text-amber-800 text-xs font-medium rounded-lg transition-colors">
                                            ⭐ Đặt làm mặc định
                                        </button>
                                    </form>
                                @endif
                                @if ($version['is_default'])
                                    <button type="button"
                                            disabled
                                            title="Không thể xóa phiên bản đang mặc định. Hãy đặt phiên bản khác làm mặc định trước."
                                            class="px-3 py-1.5 bg-gray-100 text-gray-400 text-xs font-medium rounded-lg cursor-not-allowed">
                                        🗑 Xóa
                                    </button>
                                @else
                                    <form method="POST" action="{{ route('admin.credit-card-policies.api.versions.destroy', [$template['id'], $version['id']]) }}"
                                          class="inline"
                                          onsubmit="return confirm('Xóa Version {{ $version['version_no'] }} của chính sách này?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 text-xs font-medium rounded-lg transition-colors">
                                            🗑 Xóa
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif

                        <div class="mt-3 text-sm">
                            @foreach ($version['tiers'] ?? [] as $tier)
                                <div class="rounded-xl border border-gray-100 bg-gray-50/60 p-3 mb-2 last:mb-0">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="font-semibold text-gray-700 text-[13px]">
                                            @if ($tier['name'] && $tier['name'] !== ''){{ $tier['name'] }}@else Bậc {{ $loop->iteration }}@endif
                                            <span class="font-normal text-gray-400">
                                                ({{ number_format((float) ($tier['min_total_spend'] ?? 0)) }} ₫
                                                @if (!empty($tier['max_total_spend']))
                                                    – {{ number_format((float) $tier['max_total_spend']) }} ₫
                                                @endif)
                                            </span>
                                        </p>
                                        <span class="text-[11px] text-gray-500 rounded-lg bg-white border border-gray-100 px-2 py-0.5">
                                            Hoàn tiền tối đa của bậc / kỳ:
                                            <strong>{{ !empty($tier['max_cashback_per_period']) || $tier['max_cashback_per_period'] === 0 ? number_format((float) $tier['max_cashback_per_period']) . ' ₫' : 'Không giới hạn' }}</strong>
                                        </span>
                                    </div>
                                    <div class="mt-2 grid gap-1.5 sm:grid-cols-2">
                                        @foreach ($tier['rules'] ?? [] as $rule)
                                            @php($isFallback = ($rule['scope_type'] ?? 'category') === 'other')
                                            <div class="rounded-lg bg-white border border-gray-100 px-3 py-2 text-[13px] flex items-center justify-between gap-2">
                                                <span class="min-w-0">
                                                    <span class="text-gray-700">
                                                        {{ $isFallback ? '📦 Các danh mục còn lại' : ($rule['category_name'] ?? $rule['name']) }}
                                                        @if ($isFallback)
                                                            <span class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-700">Quy tắc mặc định</span>
                                                        @endif
                                                    </span>
                                                    <span class="block text-[11px] text-gray-400 mt-0.5">
                                                        Hoàn tối đa / giao dịch:
                                                        {{ !empty($rule['max_cashback_per_transaction']) || ($rule['max_cashback_per_transaction'] ?? null) === 0.0
                                                            ? number_format((float) $rule['max_cashback_per_transaction']) . ' ₫'
                                                            : 'Không giới hạn' }}
                                                    </span>
                                                </span>
                                                <span class="flex items-center gap-2">
                                                    <span class="font-semibold text-emerald-600">{{ (float) $rule['cashback_percent'] }}%</span>
                                                    <span class="text-[11px] text-gray-500 rounded-lg bg-gray-50 border border-gray-100 px-2 py-0.5">
                                                        Tính vào cap Bậc: <strong>{{ ($rule['counts_toward_tier_cap'] ?? true) ? 'Có' : 'Không' }}</strong>
                                                    </span>
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                    @php($tierCaps = $tier['transaction_caps'] ?? [])
                                    @if ($tierCaps !== [])
                                        <div class="mt-2 rounded-lg bg-white border border-gray-100 px-3 py-2 text-[13px] space-y-1">
                                            <p class="text-[11px] font-medium text-emerald-700">
                                                Giới hạn hoàn tiền theo giá trị giao dịch (theo bậc)
                                            </p>
                                            @foreach ($tierCaps as $cap)
                                                <span class="block text-[11px] text-gray-500">
                                                    {{ number_format((float) $cap['min_transaction_amount']) }} ₫
                                                    @if (!empty($cap['max_transaction_amount']) || ($cap['max_transaction_amount'] ?? null) === 0.0)
                                                        – {{ number_format((float) $cap['max_transaction_amount']) }} ₫
                                                    @else
                                                        +
                                                    @endif
                                                    → tối đa {{ number_format((float) $cap['max_cashback_per_transaction']) }} ₫ / giao dịch
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                            @if (empty($version['tiers']))
                                <p class="text-xs text-gray-400">Chưa có bậc chi tiêu.</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">Chưa có phiên bản nào.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>