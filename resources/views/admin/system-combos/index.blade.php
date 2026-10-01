<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                🍱 Quản lý Combo danh mục hệ thống
            </h2>
            <p class="text-sm text-gray-500 mt-0.5">Tập hợp các danh mục hệ thống đang hoạt động thành Combo dùng cho chính sách hoàn tiền.</p>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-3xl mx-auto space-y-4">
        <div x-data="systemComboAdmin({ combos: @js($combos) })" x-init="init()" class="space-y-4">
            {{-- Toolbar --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 flex flex-col gap-3">
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400" aria-hidden="true">🔎</span>
                    <input type="search" x-model="search" placeholder="Tìm Combo theo tên..."
                           class="w-full rounded-xl border-gray-300 pl-10 pr-4 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                </div>
                <button type="button" @click="openCreate()"
                        class="inline-flex items-center justify-center h-11 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">
                    + Tạo Combo
                </button>
                <p class="text-xs text-gray-500">
                    Combo hệ thống chỉ chứa danh mục hệ thống đang hoạt động. Mỗi danh mục chỉ xuất hiện một lần trong cùng Combo.
                </p>
            </div>

            <template x-if="notice">
                <div class="rounded-2xl bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800" x-text="notice"></div>
            </template>
            <template x-if="pageError">
                <div class="rounded-2xl bg-rose-50 border border-rose-200 p-4 text-sm text-rose-700" x-text="pageError"></div>
            </template>

            {{-- List --}}
            <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <header class="px-4 py-3.5 border-b border-gray-100">
                    <h3 class="font-semibold text-gray-800 text-sm">
                        Combo danh mục hệ thống
                        <span x-text="`(${filtered.length})`" class="ml-1 font-medium text-gray-400"></span>
                    </h3>
                </header>

                <template x-if="filtered.length === 0">
                    <div class="px-4 py-10 text-center text-sm text-gray-500">Chưa có Combo nào.</div>
                </template>

                <ul class="divide-y divide-gray-100">
                    <template x-for="combo in filtered" :key="combo.id">
                        <li class="flex items-start gap-3 px-4 py-3">
                            <div class="min-w-0 flex-1 space-y-1.5">
                                <p class="text-sm font-medium text-gray-800" x-text="combo.name"></p>
                                <code class="text-[11px] text-gray-400" x-text="combo.slug"></code>
                                <p class="text-xs text-gray-500 line-clamp-2" x-show="combo.description" x-text="combo.description"></p>
                                <div class="text-xs text-gray-600 space-y-0.5">
                                    <p class="font-medium">Gồm <span x-text="combo.items.length"></span> danh mục:</p>
                                    <ul class="list-disc pl-5">
                                        <template x-for="it in combo.items" :key="it.id">
                                            <li x-text="it.category_name"></li>
                                        </template>
                                    </ul>
                                </div>
                            </div>
                            <button @click="openEdit(combo)"
                                    class="inline-flex items-center justify-center h-11 w-11 rounded-lg text-gray-500 hover:bg-gray-100">
                                ✏️
                            </button>
                        </li>
                    </template>
                </ul>
            </section>

            {{-- Modal --}}
            <x-modal name="system-combo-form" :show="false" maxWidth="md">
                <div class="p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-gray-800"
                            x-text="form.mode==='create'?'Tạo Combo hệ thống':'Sửa Combo hệ thống'"></h3>
                        <button @click="closeModal()" class="p-1.5 text-gray-400 hover:bg-gray-100">✕</button>
                    </div>

                    <template x-if="pageError">
                        <div class="rounded-xl bg-rose-50 border border-rose-200 p-3 text-sm text-rose-700" x-text="pageError"></div>
                    </template>

                    <form @submit.prevent="save()" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tên Combo <span class="text-rose-500">*</span></label>
                            <input x-model="form.name" maxlength="150" required
                                   class="mt-1 w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Slug (tự sinh từ tên)</label>
                            <input :value="form.mode==='edit' ? form.slug : slugify(form.name)" readonly
                                   class="mt-1 w-full rounded-xl border-gray-200 bg-gray-50 text-sm text-gray-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Mô tả</label>
                            <textarea x-model="form.description" rows="2" maxlength="1000"
                                      class="mt-1 w-full rounded-xl border-gray-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Danh mục thành phần <span class="text-rose-500">*</span></label>
                            <div class="mt-2 max-h-64 overflow-auto rounded-xl border border-gray-200 p-3 space-y-2">
                                <template x-for="cat in systemCategories" :key="cat.id">
                                    <label class="flex items-start gap-2 text-sm">
                                        <input type="checkbox" :value="cat.id" x-model="form.category_ids"
                                               class="mt-0.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span x-text="cat.name"></span>
                                    </label>
                                </template>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Chọn ít nhất 1 danh mục hệ thống đang hoạt động.</p>
                        </div>
                        <div class="flex justify-end gap-2 pt-1">
                            <button type="button" @click="closeModal()"
                                    class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm">Huỷ</button>
                            <button type="submit" :disabled="busy"
                                    class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Lưu</button>
                        </div>
                    </form>
                </div>
            </x-modal>
        </div>
    </div>

    @push('scripts')
        <script>
            window.systemComboAdmin = function (initial) {
                return {
                    combos: initial.combos || [],
                    systemCategories: initial.systemCategories || @js($systemCategories ?? []),
                    search: '',
                    busy: false,
                    pageError: '',
                    notice: '',
                    form: {mode:'create', id:null, name:'', slug:'', description:'', category_ids:[], errors:{}},

                    init() {
                        const f = window.localStorage.getItem('cc_syscombo_flash');
                        if (f){ this.notice=f; window.localStorage.removeItem('cc_syscombo_flash'); }
                    },
                    get filtered() {
                        const n = this.search.trim().toLowerCase();
                        if(!n) return this.combos;
                        return this.combos.filter(c=> (c.name||'').toLowerCase().includes(n)||(c.slug||'').toLowerCase().includes(n));
                    },
                    slugify(v){ return (v||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/g,'d').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); },
                    openCreate(){ this.form={mode:'create',id:null,name:'',slug:'',description:'',category_ids:[],errors:{}}; this.pageError=''; this.$dispatch('open-modal','system-combo-form'); },
                    openEdit(c){
                        this.form={mode:'edit',id:c.id,name:c.name,slug:c.slug,description:c.description||'',category_ids:[...(c.category_ids||[])],errors:{}};
                        this.pageError=''; this.$dispatch('open-modal','system-combo-form');
                    },
                    closeModal(){ this.$dispatch('close-modal','system-combo-form'); },
                    async request(url,method,body){
                        this.busy=true; this.pageError=''; this.form.errors={};
                        try{
                            const r=await fetch(url,{method,headers:{'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content??''},body:body?JSON.stringify(body):null});
                            const p=await r.json().catch(()=>({}));
                            if(!r.ok){ if(p.errors){ for(const[k,m] of Object.entries(p.errors)){ this.form.errors[k]=Array.isArray(m)?m[0]:m;} } this.pageError=p.message||(`Lỗi ${r.status}`); return null; }
                            return p;
                        }catch(e){ this.pageError='Không kết nối máy chủ.'; return null; }
                        finally{ this.busy=false; }
                    },
                    async save(){
                        const name=(this.form.name||'').trim();
                        if(!name){ this.form.errors.name='Bắt buộc'; return; }
                        if(!this.form.category_ids.length){ this.pageError='Chọn ít nhất 1 danh mục'; return; }
                        const url=this.form.mode==='create'?@js(route('admin.credit-card.system-combos.store')) : @js(route('admin.credit-card.system-combos.update', ['combo'=>0])),
                              real=this.form.mode==='edit'?url.replace('0', this.form.id):url,
                              method=this.form.mode==='create'?'POST':'PATCH';
                        const p=await this.request(real,method,{name,description:(this.form.description||'').trim()||null,category_ids:this.form.category_ids});
                        if(!p) return;
                        const nm=p.data?.name||name;
                        window.localStorage.setItem('cc_syscombo_flash', this.form.mode==='create'?`Đã tạo Combo "${nm}"`:`Đã cập nhật Combo "${nm}"`);
                        window.location.reload();
                    }
                };
            };
            document.addEventListener('alpine:init',()=>{ window.Alpine.data('systemComboAdmin',window.systemComboAdmin); });
        </script>
    @endpush
</x-app-layout>
