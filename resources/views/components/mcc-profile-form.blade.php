@props([
    'action',
    'method' => 'POST',
    'templates' => [],
    'masterEndpoint',
    'isEdit' => false,
    'templateName' => null,
    'initialName' => '',
    'initialTemplateId' => null,
    'initialNote' => '',
    'initialIncluded' => [],
    'initialExcluded' => [],
    'documents' => [],
])

@php
    $templateMap = collect($templates)->keyBy('id')->map(fn (array $template): string => $template['name'])->all();
    $fieldState = [
        'masterEndpoint' => $masterEndpoint,
        'templates' => $templateMap,
        'initialName' => (string) $initialName,
        'initialTemplateId' => $initialTemplateId !== null ? (int) $initialTemplateId : '',
        'initialNote' => (string) $initialNote,
        'initialIncluded' => array_values((array) $initialIncluded),
        'initialExcluded' => array_values((array) $initialExcluded),
    ];
@endphp

<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="space-y-5"
      x-data="mccProfileForm(@js($fieldState))">
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
            <p class="font-semibold">Không thể lưu. Vui lòng kiểm tra:</p>
            <ul class="mt-1 list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="block text-sm font-medium text-gray-700">Chính sách hoàn tiền</label>
            @if ($isEdit)
                <p class="mt-1 rounded-xl border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                    {{ $templateName ?? ('#'.$initialTemplateId) }}
                </p>
                <p class="mt-1 text-xs text-gray-400">Chính sách không đổi sau khi tạo hồ sơ.</p>
            @else
                <select name="policy_template_id" x-model="templateId" @change="onTemplateChange()" required
                        class="mt-1 w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
                    <option value="">— Chọn chính sách —</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template['id'] }}">{{ $template['name'] }}</option>
                    @endforeach
                </select>
            @endif
            @error('policy_template_id')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Tên MCC chính sách</label>
            <input type="text" name="name" maxlength="191" x-model="name"
                   placeholder="vd: MCC VIB Super Card"
                   class="mt-1 w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
            <p class="mt-1 text-xs text-gray-400">Mặc định "MCC + tên chính sách"; bạn có thể sửa lại.</p>
            @error('name')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700">Ghi chú chung</label>
        <textarea name="note" rows="2" maxlength="5000" x-model="note"
                  class="mt-1 w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm"></textarea>
        @error('note')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    {{-- ===== Bảng MCC được hoàn tiền ===== --}}
    <div class="rounded-2xl border border-emerald-100 bg-emerald-50/40 p-4 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-emerald-800">MCC được hoàn tiền</h3>
                <p class="text-xs text-emerald-700/70">Mỗi dòng một danh mục; nhập/dán nhiều mã MCC (4 chữ số).</p>
            </div>
            <button type="button" @click="addRow('included')"
                    class="shrink-0 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-medium rounded-lg transition-colors">+ Thêm dòng</button>
        </div>
        @error('included')
            <p class="text-xs text-rose-600">{{ $message }}</p>
        @enderror

        <template x-for="(row, index) in included" :key="row.uid">
            <div class="rounded-xl border border-emerald-100 bg-white p-3 space-y-2">
                <div class="flex items-start gap-2">
                    <div class="flex-1 grid gap-2 sm:grid-cols-2">
                        <input type="text" :name="'included[' + index + '][category_name]'" x-model="row.category_name"
                               placeholder="Tên danh mục (theo tài liệu ngân hàng)"
                               class="w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
                        <div class="relative">
                            <input type="text" x-model="row.query"
                                   @input.debounce.250ms="search(row)"
                                   @focus="row.open = true"
                                   @keydown.enter.prevent="commitQuery(row)"
                                   @blur="commitQuery(row)"
                                   placeholder="Nhập/dán mã MCC (vd 5411, 5412)..."
                                   autocomplete="off"
                                   class="w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
                            <div x-show="row.open" x-cloak @click.outside="row.open = false"
                                 class="absolute z-30 mt-1 w-full max-h-56 overflow-y-auto rounded-xl border border-gray-100 bg-white shadow-lg">
                                <template x-if="row.loading">
                                    <p class="px-3 py-2 text-xs text-gray-400">Đang tìm...</p>
                                </template>
                                <template x-for="item in row.results" :key="item.mcc">
                                    <button type="button" @mousedown.prevent="pick(row, item.mcc)"
                                            class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-gray-50">
                                        <span class="font-mono text-sm font-semibold text-gray-800" x-text="item.mcc"></span>
                                        <span class="text-xs text-gray-500" x-text="item.description_vi || item.description_en || ''"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                    <button type="button" @click="removeRow('included', index)"
                            class="shrink-0 mt-1 w-8 h-8 inline-flex items-center justify-center rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600"
                            aria-label="Xoá dòng">×</button>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    <template x-for="code in row.codes" :key="code">
                        <span class="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-mono text-xs text-emerald-700">
                            <span x-text="code"></span>
                            <button type="button" @click="removeCode(row, code)" class="opacity-60 hover:opacity-100" aria-label="Bỏ mã">×</button>
                            <input type="hidden" :name="'included[' + index + '][codes][]'" :value="code">
                        </span>
                    </template>
                    <span x-show="row.codes.length === 0" class="text-xs text-gray-400">Chưa nhập mã nào.</span>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <input type="text" :name="'included[' + index + '][description]'" x-model="row.description"
                           placeholder="Mô tả (tuỳ chọn)"
                           class="w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
                    <input type="text" :name="'included[' + index + '][note]'" x-model="row.note"
                           placeholder="Ghi chú dòng (tuỳ chọn)"
                           class="w-full rounded-xl border-gray-200 focus:border-emerald-400 focus:ring-emerald-400 text-sm">
                </div>
            </div>
        </template>

        <p x-show="included.length === 0" class="text-xs text-gray-400">Chưa có dòng nào. Bấm "+ Thêm dòng".</p>
    </div>

    {{-- ===== Bảng MCC loại trừ ===== --}}
    <div class="rounded-2xl border border-rose-100 bg-rose-50/40 p-4 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-rose-800">MCC loại trừ</h3>
                <p class="text-xs text-rose-700/70">MCC loại trừ được ưu tiên hơn MCC hoàn tiền của cùng hồ sơ.</p>
            </div>
            <button type="button" @click="addRow('excluded')"
                    class="shrink-0 px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-medium rounded-lg transition-colors">+ Thêm dòng</button>
        </div>
        @error('excluded')
            <p class="text-xs text-rose-600">{{ $message }}</p>
        @enderror

        <template x-for="(row, index) in excluded" :key="row.uid">
            <div class="rounded-xl border border-rose-100 bg-white p-3 space-y-2">
                <div class="flex items-start gap-2">
                    <div class="flex-1 grid gap-2 sm:grid-cols-2">
                        <input type="text" :name="'excluded[' + index + '][category_name]'" x-model="row.category_name"
                               placeholder="Tên danh mục (theo tài liệu ngân hàng)"
                               class="w-full rounded-xl border-gray-200 focus:border-rose-400 focus:ring-rose-400 text-sm">
                        <div class="relative">
                            <input type="text" x-model="row.query"
                                   @input.debounce.250ms="search(row)"
                                   @focus="row.open = true"
                                   @keydown.enter.prevent="commitQuery(row)"
                                   @blur="commitQuery(row)"
                                   placeholder="Nhập/dán mã MCC (vd 7995, 6011)..."
                                   autocomplete="off"
                                   class="w-full rounded-xl border-gray-200 focus:border-rose-400 focus:ring-rose-400 text-sm">
                            <div x-show="row.open" x-cloak @click.outside="row.open = false"
                                 class="absolute z-30 mt-1 w-full max-h-56 overflow-y-auto rounded-xl border border-gray-100 bg-white shadow-lg">
                                <template x-if="row.loading">
                                    <p class="px-3 py-2 text-xs text-gray-400">Đang tìm...</p>
                                </template>
                                <template x-for="item in row.results" :key="item.mcc">
                                    <button type="button" @mousedown.prevent="pick(row, item.mcc)"
                                            class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-gray-50">
                                        <span class="font-mono text-sm font-semibold text-gray-800" x-text="item.mcc"></span>
                                        <span class="text-xs text-gray-500" x-text="item.description_vi || item.description_en || ''"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                    <button type="button" @click="removeRow('excluded', index)"
                            class="shrink-0 mt-1 w-8 h-8 inline-flex items-center justify-center rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600"
                            aria-label="Xoá dòng">×</button>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    <template x-for="code in row.codes" :key="code">
                        <span class="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 font-mono text-xs text-rose-700">
                            <span x-text="code"></span>
                            <button type="button" @click="removeCode(row, code)" class="opacity-60 hover:opacity-100" aria-label="Bỏ mã">×</button>
                            <input type="hidden" :name="'excluded[' + index + '][codes][]'" :value="code">
                        </span>
                    </template>
                    <span x-show="row.codes.length === 0" class="text-xs text-gray-400">Chưa nhập mã nào.</span>
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <input type="text" :name="'excluded[' + index + '][description]'" x-model="row.description"
                           placeholder="Mô tả (tuỳ chọn)"
                           class="w-full rounded-xl border-gray-200 focus:border-rose-400 focus:ring-rose-400 text-sm">
                    <input type="text" :name="'excluded[' + index + '][note]'" x-model="row.note"
                           placeholder="Ghi chú dòng (tuỳ chọn)"
                           class="w-full rounded-xl border-gray-200 focus:border-rose-400 focus:ring-rose-400 text-sm">
                </div>
            </div>
        </template>

        <p x-show="excluded.length === 0" class="text-xs text-gray-400">Chưa có dòng nào. Bấm "+ Thêm dòng".</p>
    </div>

    {{-- ===== Tài liệu ngân hàng ===== --}}
    <div class="rounded-2xl border border-gray-100 bg-white p-4 space-y-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-800">Tài liệu ngân hàng</h3>
            <p class="text-xs text-gray-500">PDF, XLSX hoặc XLS — tối đa 10 MB mỗi tệp. Tệp lưu riêng tư, tải qua liên kết có kiểm tra quyền.</p>
        </div>

        @if (count($documents) > 0)
            <ul class="space-y-1.5">
                @foreach ($documents as $document)
                    <li class="flex items-center justify-between gap-2 rounded-xl border border-gray-100 bg-gray-50 px-3 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-gray-700">{{ $document->original_name }}</p>
                            <p class="text-[11px] text-gray-400">{{ $document->humanSize() }}</p>
                        </div>
                        <a href="{{ route('admin.credit-card.mcc.documents.download', [$document->policy_mcc_profile_id, $document->id]) }}"
                           class="shrink-0 text-xs font-medium text-emerald-700 hover:text-emerald-800">Tải xuống</a>
                    </li>
                @endforeach
            </ul>
        @endif

        <input type="file" name="documents[]" multiple accept=".pdf,.xlsx,.xls"
               class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-emerald-700 hover:file:bg-emerald-100">
        @error('documents')
            <p class="text-xs text-rose-600">{{ $message }}</p>
        @enderror
        @foreach ($errors->get('documents.*') as $messages)
            @foreach ($messages as $message)
                <p class="text-xs text-rose-600">{{ $message }}</p>
            @endforeach
        @endforeach
    </div>

    <div class="flex items-center gap-3 pt-1">
        <button type="submit"
                class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors shadow-sm">
            {{ $isEdit ? 'Lưu hồ sơ MCC' : 'Tạo hồ sơ MCC' }}
        </button>
        <a href="{{ route('admin.credit-card.mcc.index') }}"
           class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-xl transition-colors">Huỷ</a>
    </div>
</form>

@once
    <script>
        function mccProfileForm(config) {
            return {
                masterEndpoint: config.masterEndpoint,
                templates: config.templates || {},
                name: config.initialName || '',
                templateId: config.initialTemplateId ?? '',
                note: config.initialNote || '',
                lastAutoName: '',
                included: [],
                excluded: [],
                uid: 0,

                init() {
                    this.included = this.normalizeRows(config.initialIncluded);
                    this.excluded = this.normalizeRows(config.initialExcluded);
                    this.lastAutoName = this.autoName(this.templateId);

                    if (this.name === '' && this.templateId !== '' && this.templateId !== null) {
                        this.name = this.lastAutoName;
                    }
                },

                autoName(id) {
                    const templateName = this.templates[id];

                    return templateName ? ('MCC ' + templateName) : '';
                },

                onTemplateChange() {
                    const generated = this.autoName(this.templateId);

                    if (this.name === '' || this.name === this.lastAutoName) {
                        this.name = generated;
                    }

                    this.lastAutoName = generated;
                },

                normalizeRows(rows) {
                    if (! Array.isArray(rows)) {
                        return [];
                    }

                    return rows.map((row) => ({
                        uid: ++this.uid,
                        category_name: row.category_name || '',
                        description: row.description || '',
                        note: row.note || '',
                        codes: this.parseCodes(row.codes),
                        query: '',
                        results: [],
                        loading: false,
                        open: false,
                    }));
                },

                parseCodes(value) {
                    if (Array.isArray(value)) {
                        return value.map(String);
                    }

                    if (typeof value !== 'string') {
                        return [];
                    }

                    return value.split(/\D+/).filter((code) => code.length === 4);
                },

                addRow(list) {
                    this[list].push({
                        uid: ++this.uid,
                        category_name: '',
                        description: '',
                        note: '',
                        codes: [],
                        query: '',
                        results: [],
                        loading: false,
                        open: false,
                    });
                },

                removeRow(list, index) {
                    this[list].splice(index, 1);
                },

                addCode(row, code) {
                    const normalized = String(code).replace(/\D/g, '').slice(0, 4);

                    if (normalized.length !== 4 || row.codes.includes(normalized)) {
                        return;
                    }

                    row.codes.push(normalized);
                },

                removeCode(row, code) {
                    row.codes = row.codes.filter((item) => item !== code);
                },

                async search(row) {
                    const term = String(row.query || '').trim();

                    if (term.length < 1) {
                        row.results = [];
                        row.open = false;

                        return;
                    }

                    row.loading = true;

                    try {
                        const response = await fetch(this.masterEndpoint + '?q=' + encodeURIComponent(term), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });
                        const payload = response.ok ? await response.json() : { data: [] };
                        row.results = (payload.data || []).filter((item) => ! row.codes.includes(item.mcc));
                        row.open = true;
                    } catch (error) {
                        row.results = [];
                    } finally {
                        row.loading = false;
                    }
                },

                pick(row, mcc) {
                    this.addCode(row, mcc);
                    row.query = '';
                    row.results = [];
                    row.open = false;
                },

                commitQuery(row) {
                    String(row.query || '')
                        .split(/\D+/)
                        .filter((code) => code.length === 4)
                        .forEach((code) => this.addCode(row, code));

                    row.query = '';
                    row.results = [];
                    row.open = false;
                },
            };
        }
    </script>
@endonce
