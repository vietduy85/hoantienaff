<div
    x-data="{
        url: '',
        loading: false,
        done: false,
        error: '',
        requestId: null,
        result: null,
        copied: false,
        pollTimer: null,

        submit() {
            if (!this.url.trim()) return;
            this.loading = true;
            this.error = '';
            this.done = false;
            this.result = null;

            fetch('{{ route('link-requests.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ original_url: this.url.trim() })
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    this.error = data.error || 'Lỗi không xác định';
                    this.loading = false;
                    return;
                }
                this.requestId = data.request_id;
                this.startPolling();
            })
            .catch(e => {
                this.error = 'Không thể kết nối máy chủ';
                this.loading = false;
            });
        },

        startPolling() {
            this.pollTimer = setInterval(() => {
                fetch('/api/link-request/' + this.requestId, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'completed') {
                        clearInterval(this.pollTimer);
                        this.result = data;
                        this.loading = false;
                        this.done = true;
                    } else if (data.status === 'failed' || data.status === 'rejected') {
                        clearInterval(this.pollTimer);
                        this.error = 'Không thể tạo affiliate link. Vui lòng thử lại sau.';
                        this.loading = false;
                    }
                })
                .catch(() => {});
            }, 2000);
        },

        copyLink() {
            if (!this.result?.affiliate_url) return;
            navigator.clipboard.writeText(this.result.affiliate_url);
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        }
    }"
    class="bg-white rounded-2xl shadow-md border-2 border-emerald-400 max-[390px]:p-3 p-4 -mx-1"
>
    <div class="text-center max-[390px]:mb-2 mb-2.5">
        <h2 class="font-bold text-gray-900 max-[390px]:text-base text-lg">Tạo Link Hoàn Tiền</h2>
        <p class="max-[390px]:text-[11px] text-xs text-gray-400 mt-0.5">
            Hỗ trợ Shopee • Lazada • TikTok Shop • Tiki
        </p>
    </div>

    <template x-if="error">
        <div class="bg-red-50 border border-red-200 rounded-xl max-[390px]:px-2.5 max-[390px]:py-1.5 px-3 py-2 max-[390px]:mb-2 mb-2.5 flex items-start gap-1.5">
            <span class="max-[390px]:text-sm text-base shrink-0">❌</span>
            <p class="max-[390px]:text-[11px] text-xs text-red-700 font-medium" x-text="error"></p>
        </div>
    </template>

    <template x-if="!loading && !done">
        <div class="space-y-2.5">
            <div>
                <label for="original_url" class="sr-only">Dán link sản phẩm</label>
                <input
                    id="original_url"
                    type="url"
                    required
                    placeholder="Dán link sản phẩm..."
                    x-model="url"
                    class="block w-full max-[390px]:h-11 h-12 max-[390px]:px-3 px-3.5 max-[390px]:text-sm text-base border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400"
                >
            </div>

            <button
                type="button"
                @click="submit"
                class="w-full max-[390px]:h-12 h-13 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white max-[390px]:text-sm text-base font-bold rounded-xl shadow-lg shadow-emerald-200 hover:shadow-emerald-300 transition-all duration-150 flex items-center justify-center gap-2"
            >
                <span class="max-[390px]:text-lg text-xl">🚀</span>
                <span>Tạo Link Ngay</span>
            </button>
        </div>
    </template>

    <template x-if="loading && !done">
        <div class="text-center max-[390px]:py-6 py-8">
            <div class="inline-block w-8 h-8 border-4 border-emerald-200 border-t-emerald-500 rounded-full animate-spin max-[390px]:mb-2 mb-3"></div>
            <p class="text-sm font-medium text-emerald-700">Đang tạo affiliate link...</p>
            <p class="text-xs text-gray-400 mt-1">Vui lòng đợi trong giây lát</p>
        </div>
    </template>

    <template x-if="done && result">
        <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-xl border border-emerald-200 max-[390px]:p-3 p-4">
            <div class="flex items-center gap-1.5 mb-2.5">
                <span class="max-[390px]:text-base text-lg">🎉</span>
                <h3 class="max-[390px]:text-sm text-base font-bold text-emerald-800">Link Hoàn Tiền</h3>
            </div>

            <div class="space-y-2.5">
                <div class="text-center bg-white rounded-xl border border-emerald-100 max-[390px]:px-3 max-[390px]:py-1.5 px-4 py-2">
                    <p class="max-[390px]:text-[11px] text-xs text-gray-500">Ước tính hoàn tiền</p>
                    <p class="max-[390px]:text-2xl text-3xl font-extrabold text-emerald-600 leading-tight">
                        ≈ <span x-text="Number(result.estimated_cashback || 0).toLocaleString('vi-VN')"></span>đ
                    </p>
                </div>

                <div class="text-center">
                    <p class="max-[390px]:text-[11px] text-xs text-gray-500">🔗 Link hoàn tiền</p>
                    <a
                        x-bind:href="result.affiliate_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block text-lg font-bold text-gray-800 text-center mt-2 mb-4 font-mono break-all cursor-pointer hover:underline hover:opacity-90 min-h-[44px] flex items-center justify-center"
                        x-text="result.affiliate_url"
                    ></a>
                </div>

                <div class="flex gap-2">
                    <button
                        type="button"
                        @click="copyLink"
                        class="flex-1 max-[390px]:h-11 h-12 bg-white hover:bg-emerald-50 active:bg-emerald-100 text-emerald-700 font-semibold max-[390px]:text-xs text-sm rounded-xl border-2 border-emerald-200 transition-all duration-150 flex items-center justify-center gap-1.5"
                    >
                        <span x-show="!copied" class="max-[390px]:text-base text-lg">📋</span>
                        <span x-show="!copied">Sao chép</span>
                        <span x-show="copied" x-cloak>✅</span>
                        <span x-show="copied" x-cloak class="font-bold">Đã sao chép!</span>
                    </button>

                    <a
                        x-bind:href="result.affiliate_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex-1 max-[390px]:h-11 h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-bold max-[390px]:text-xs text-sm rounded-xl transition-all duration-150 flex items-center justify-center gap-1.5"
                    >
                        <span class="max-[390px]:text-base text-lg">🛒</span>
                        <span>Mua ngay</span>
                    </a>
                </div>

                <button
                    type="button"
                    @click="url = ''; loading = false; done = false; result = null; error = ''"
                    class="w-full text-sm text-gray-500 hover:text-gray-700 py-1"
                >Tạo link khác</button>
            </div>
        </div>
    </template>
</div>
