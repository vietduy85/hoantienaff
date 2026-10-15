{{--
    Tab "Connect Partner AFF".
    - Hiển thị trạng thái nhà cung cấp + phương thức tạo link (read-only).
    - "Chuỗi nhận diện URL": rule hard-code (read-only, code baseline) VÀ rule
      do quản trị viên quản lý (thêm/sửa/xóa). Rule quản trị là lớp phủ ADDITIVE
      chạy SAU toàn bộ rule trong code, nên không đổi hành vi nhận diện hiện có.
    - Cấu hình kết nối AccessTrade (token mã hóa, che khi hiển thị) + kiểm tra kết nối.
--}}
@php
    use App\Models\AffiliateDetectRule;

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
    $strategyLabel = [
        'accesstrade_api' => 'AccessTrade API',
        'unsupported' => 'Không hỗ trợ',
    ];
    $ruleStatusLabel = [
        'active' => 'Đang bật',
        'disabled' => 'Đã tắt',
    ];
    $inputClass = 'block w-full h-10 px-3 text-sm border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition';
    $labelClass = 'block text-xs font-semibold text-gray-600 mb-1';
@endphp

<div class="space-y-4">
    {{-- Thông báo --}}
    @if (session('error'))
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
            {{ session('error') }}
        </div>
    @endif

    @if (session('rule_warnings'))
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-xs text-amber-700 space-y-1">
            <div class="font-semibold">Cảnh báo xung đột / trùng lặp</div>
            @foreach (session('rule_warnings') as $warning)
                <div>• {{ $warning }}</div>
            @endforeach
        </div>
    @endif

    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-xs text-blue-700 leading-relaxed">
        Các chuỗi nhận diện <span class="font-semibold">trong code</span> là bản gốc (read-only) và luôn được kiểm tra
        <span class="font-semibold">trước tiên</span>. Chuỗi do quản trị viên thêm là <span class="font-semibold">lớp phủ bổ sung</span>
        chỉ chạy khi code chưa nhận diện được URL — do đó <span class="font-semibold">không làm thay đổi</span> hành vi nhận diện hiện tại.
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

            {{-- Chuỗi nhận diện URL (code baseline, read-only) --}}
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
        </div>
    @endforeach

    {{-- Quản lý chuỗi nhận diện (do quản trị viên thêm) --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
        <div>
            <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">Chuỗi nhận diện do quản trị viên quản lý</h3>
            <p class="text-xs text-gray-500 mt-1">
                Bổ sung domain/chuỗi đặc thù (ví dụ merchant AccessTrade). Rule chỉ áp dụng cho URL mà code chưa nhận diện.
            </p>
        </div>

        {{-- Danh sách rule hiện có --}}
        @if ($dbRules->isEmpty())
            <div class="border border-dashed border-gray-200 rounded-xl p-4 text-center">
                <p class="text-xs text-gray-500">Chưa có chuỗi nào do quản trị viên thêm.</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($dbRules as $rule)
                    <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 space-y-2" x-data="{ edit: false }">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <code class="text-xs text-gray-800 break-all">{{ $rule->pattern }}</code>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border bg-white text-gray-600 border-gray-200">
                                        {{ $matchLabel[$rule->match_type] ?? $rule->match_type }}
                                    </span>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium border {{ $rule->isActive() ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-gray-100 text-gray-500 border-gray-200' }}">
                                        {{ $ruleStatusLabel[$rule->status] ?? $rule->status }}
                                    </span>
                                </div>
                                <div class="text-[11px] text-gray-500 mt-1">
                                    {{ $rule->name }}
                                    · {{ $rule->platform }}
                                    · {{ $strategyLabel[$rule->strategy] ?? $rule->strategy }}
                                    · ưu tiên {{ $rule->priority }}
                                    @if ($rule->campaign_id)
                                        · campaign {{ $rule->campaign_id }}
                                    @endif
                                </div>
                                @if ($rule->description)
                                    <div class="text-[11px] text-gray-400 mt-0.5">{{ $rule->description }}</div>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" @click="edit = ! edit"
                                        class="text-[11px] font-semibold text-emerald-600 hover:text-emerald-700">
                                    Sửa
                                </button>
                                <form method="POST" action="{{ route('admin.affiliate-config.rules.destroy', $rule) }}"
                                      onsubmit="return confirm('Xóa chuỗi nhận diện này?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-[11px] font-semibold text-red-600 hover:text-red-700">Xóa</button>
                                </form>
                            </div>
                        </div>

                        <form x-show="edit" x-cloak method="POST"
                              action="{{ route('admin.affiliate-config.rules.update', $rule) }}"
                              class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-gray-200 pt-3">
                            @csrf
                            @method('PUT')
                            <div>
                                <label class="{{ $labelClass }}">Tên</label>
                                <input type="text" name="name" value="{{ $rule->name }}" class="{{ $inputClass }}" required>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Chuỗi / hostname</label>
                                <input type="text" name="pattern" value="{{ $rule->pattern }}" class="{{ $inputClass }}" required>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Kiểu khớp</label>
                                <select name="match_type" class="{{ $inputClass }}">
                                    @foreach (['hostname', 'substring'] as $type)
                                        <option value="{{ $type }}" @selected($rule->match_type === $type)>{{ $matchLabel[$type] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Nền tảng</label>
                                <select name="platform" class="{{ $inputClass }}">
                                    @foreach (AffiliateDetectRule::PLATFORMS as $platform)
                                        <option value="{{ $platform }}" @selected($rule->platform === $platform)>{{ $platform }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Phương thức</label>
                                <select name="strategy" class="{{ $inputClass }}">
                                    @foreach (AffiliateDetectRule::STRATEGIES as $strategy)
                                        <option value="{{ $strategy }}" @selected($rule->strategy === $strategy)>{{ $strategyLabel[$strategy] ?? $strategy }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Campaign ID (tùy chọn)</label>
                                <input type="text" name="campaign_id" value="{{ $rule->campaign_id }}" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Ưu tiên</label>
                                <input type="number" name="priority" value="{{ $rule->priority }}" class="{{ $inputClass }}" min="0">
                            </div>
                            <div>
                                <label class="{{ $labelClass }}">Trạng thái</label>
                                <select name="status" class="{{ $inputClass }}">
                                    @foreach (['active', 'disabled'] as $status)
                                        <option value="{{ $status }}" @selected($rule->status === $status)>{{ $ruleStatusLabel[$status] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="{{ $labelClass }}">Mô tả</label>
                                <input type="text" name="description" value="{{ $rule->description }}" class="{{ $inputClass }}">
                            </div>
                            <div class="sm:col-span-2">
                                <button type="submit" class="h-10 px-4 text-xs font-semibold rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white">
                                    Lưu thay đổi
                                </button>
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Form thêm rule --}}
        <form method="POST" action="{{ route('admin.affiliate-config.rules.store') }}"
              class="grid grid-cols-1 sm:grid-cols-2 gap-3 border-t border-gray-100 pt-4">
            @csrf
            <div>
                <label class="{{ $labelClass }}">Tên</label>
                <input type="text" name="name" value="{{ old('name') }}" class="{{ $inputClass }}" placeholder="VD: TheFaceShop Haravan" required>
                @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Chuỗi / hostname</label>
                <input type="text" name="pattern" value="{{ old('pattern') }}" class="{{ $inputClass }}" placeholder="VD: thefaceshopvn.myharavan.com" required>
                @error('pattern')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Kiểu khớp</label>
                <select name="match_type" class="{{ $inputClass }}">
                    <option value="hostname" @selected(old('match_type') === 'hostname')>Hostname (exact/subdomain)</option>
                    <option value="substring" @selected(old('match_type') === 'substring')>Substring (toàn URL)</option>
                </select>
            </div>
            <div>
                <label class="{{ $labelClass }}">Nền tảng</label>
                <select name="platform" class="{{ $inputClass }}">
                    @foreach (AffiliateDetectRule::PLATFORMS as $platform)
                        <option value="{{ $platform }}" @selected(old('platform', 'AccessTrade') === $platform)>{{ $platform }}</option>
                    @endforeach
                </select>
                @error('platform')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Phương thức</label>
                <select name="strategy" class="{{ $inputClass }}">
                    @foreach (AffiliateDetectRule::STRATEGIES as $strategy)
                        <option value="{{ $strategy }}" @selected(old('strategy', 'accesstrade_api') === $strategy)>{{ $strategyLabel[$strategy] ?? $strategy }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $labelClass }}">Campaign ID (tùy chọn)</label>
                <input type="text" name="campaign_id" value="{{ old('campaign_id') }}" class="{{ $inputClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Ưu tiên</label>
                <input type="number" name="priority" value="{{ old('priority', 100) }}" class="{{ $inputClass }}" min="0">
            </div>
            <div>
                <label class="{{ $labelClass }}">Trạng thái</label>
                <select name="status" class="{{ $inputClass }}">
                    <option value="active" @selected(old('status', 'active') === 'active')>Đang bật</option>
                    <option value="disabled" @selected(old('status') === 'disabled')>Đã tắt</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $labelClass }}">Mô tả</label>
                <input type="text" name="description" value="{{ old('description') }}" class="{{ $inputClass }}">
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="h-10 px-4 text-xs font-semibold rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white">
                    Thêm chuỗi nhận diện
                </button>
            </div>
        </form>
    </div>

    {{-- AccessTrade — cấu hình kết nối --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 space-y-4">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wide">AccessTrade</h3>
                <p class="text-xs text-gray-500 mt-0.5">Cấu hình kết nối đối tác</p>
            </div>
            @php $atConnected = $accessTrade['enabled'] && $accessTrade['has_token']; @endphp
            <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold border {{ $atConnected ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-gray-100 text-gray-500 border-gray-200' }}">
                {{ $atConnected ? 'Đã kết nối' : 'Chưa kết nối' }}
            </span>
        </div>

        @if ($accessTrade['last_check_at'])
            @php $ok = $accessTrade['last_check_ok']; @endphp
            <div class="rounded-xl border p-3 text-xs {{ $ok ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700' }}">
                Kiểm tra gần nhất ({{ $accessTrade['last_check_at'] }}):
                {{ $accessTrade['last_check_message'] }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.affiliate-config.accesstrade.update') }}" class="space-y-3">
            @csrf
            <div class="flex items-center justify-between gap-4">
                <div>
                    <div class="text-sm font-medium text-gray-800">Bật kết nối AccessTrade</div>
                    <div class="text-xs text-gray-500 mt-0.5">Khi tắt, không có URL nào được gửi sang AccessTrade.</div>
                </div>
                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                    <input type="hidden" name="enabled" value="false">
                    <input type="checkbox" name="enabled" value="true" {{ $accessTrade['enabled'] ? 'checked' : '' }} class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 rounded-full peer peer-checked:bg-emerald-500 transition-colors
                                after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white
                                after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5
                                after:transition-all peer-checked:after:translate-x-full"></div>
                </label>
            </div>

            <div>
                <label class="{{ $labelClass }}">API Endpoint</label>
                <input type="text" name="base_url" value="{{ old('base_url', $accessTrade['base_url']) }}" class="{{ $inputClass }}">
                @error('base_url')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="{{ $labelClass }}">API Key</label>
                <input type="password" name="token" value="" autocomplete="new-password" class="{{ $inputClass }}"
                       placeholder="{{ $accessTrade['has_token'] ? 'Đã lưu ' . $accessTrade['masked_token'] . ' — nhập để thay' : 'Chưa cấu hình' }}">
                <p class="text-[11px] text-gray-400 mt-1">
                    @if ($accessTrade['has_token'])
                        Trạng thái: <span class="font-semibold text-gray-600">{{ $accessTrade['masked_token'] }}</span> · Để trống nếu không đổi.
                    @else
                        Trạng thái: <span class="font-semibold text-gray-600">Chưa cấu hình</span>
                    @endif
                </p>
            </div>

            @if ($accessTrade['has_token'])
                <label class="flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox" name="clear_token" value="1" class="rounded border-gray-300 text-emerald-500 focus:ring-emerald-400">
                    Xóa API key đã lưu
                </label>
            @endif

            <button type="submit" class="h-10 px-4 text-xs font-semibold rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white">
                Lưu cấu hình AccessTrade
            </button>
        </form>

        <form method="POST" action="{{ route('admin.affiliate-config.accesstrade.test') }}">
            @csrf
            <button type="submit"
                    @disabled(! $accessTrade['enabled'] || ! $accessTrade['has_token'])
                    class="h-9 px-3 text-xs font-semibold rounded-lg border border-gray-200 {{ ($accessTrade['enabled'] && $accessTrade['has_token']) ? 'text-emerald-600 hover:bg-emerald-50' : 'text-gray-400 cursor-not-allowed' }}">
                Kiểm tra kết nối
            </button>
        </form>

        <div>
            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Danh sách campaign</div>
            @if (empty($accessTrade['campaigns']))
                <div class="border border-dashed border-gray-200 rounded-xl p-6 text-center">
                    <p class="text-sm text-gray-500">Chưa kết nối AccessTrade</p>
                    <p class="text-xs text-gray-400 mt-1">Nhấn "Kiểm tra kết nối" để tải danh sách campaign.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead>
                            <tr class="text-left text-gray-500 border-b border-gray-100">
                                <th class="py-2 pr-3 font-semibold">Campaign ID</th>
                                <th class="py-2 pr-3 font-semibold">Tên</th>
                                <th class="py-2 pr-3 font-semibold">Merchant / Domain</th>
                                <th class="py-2 pr-3 font-semibold">Hoa hồng</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @foreach ($accessTrade['campaigns'] as $campaign)
                                <tr>
                                    <td class="py-2 pr-3 text-gray-700 font-mono">{{ $campaign['campaign_id'] ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-gray-700">{{ $campaign['name'] ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-gray-500">{{ $campaign['domain'] ?? ($campaign['merchant'] ?? '—') }}</td>
                                    <td class="py-2 pr-3 text-gray-500">
                                        @if (isset($campaign['min_commission']) && $campaign['min_commission'] !== null)
                                            {{ $campaign['min_commission'] }}% – {{ $campaign['max_commission'] ?? '?' }}%
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
