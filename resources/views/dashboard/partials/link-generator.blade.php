<div
    x-data="{
        url: '',
        loading: false,
        error: '',
        requestId: null,
        result: null,
        copied: false,
        pollTimer: null,
        lastSubmittedUrl: '',
        autoGenerateTimer: null,

        submit() {
            if (!this.url.trim()) return;
            this.lastSubmittedUrl = this.url.trim();
            this.loading = true;
            this.error = '';
            this.result = null;
            this.stopPolling();

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
                if (data.affiliate_url) {
                    this.result = { ...data };
                    this.loading = false;
                }
                this.startPolling();
            })
            .catch(e => {
                this.error = 'Không thể kết nối máy chủ';
                this.loading = false;
            });
        },

        stopPolling() {
            if (this.pollTimer) {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
            this._pollStartTime = null;
        },

        startPolling() {
            this.stopPolling();
            this._pollStartTime = Date.now();

            const poll = () => {
                const elapsed = (Date.now() - this._pollStartTime) / 1000;
                let delay;
                if (elapsed < 3) delay = 300;
                else if (elapsed < 8) delay = 800;
                else delay = 2000;

                fetch('/api/link-request/' + this.requestId, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(r => r.json())
                .then(data => {
                    this._errorCount = 0;
                    if (data.status === 'completed') {
                        this.stopPolling();
                        if (this.result) {
                            Object.assign(this.result, data);
                        } else {
                            this.result = data;
                        }
                        this.loading = false;
                        this.$nextTick(() => {
                            this.$refs.urlInput.focus();
                            this.$refs.urlInput.select();
                        });
                        return;
                    }
                    if (data.status === 'failed' || data.status === 'rejected') {
                        this.stopPolling();
                        this.error = 'Không thể tạo affiliate link. Vui lòng thử lại sau.';
                        this.loading = false;
                        return;
                    }
                    if (this.result && data.user_estimated_cashback != null) {
                        Object.assign(this.result, data);
                    }
                    this.pollTimer = setTimeout(poll, delay);
                })
                .catch(() => {
                    this._errorCount = (this._errorCount || 0) + 1;
                    const backoff = Math.min(delay * Math.pow(2, this._errorCount), 5000);
                    this.pollTimer = setTimeout(poll, backoff);
                });
            };

            poll();
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
        <h2 class="font-semibold text-gray-900 text-lg" style="font-family: 'Inter', sans-serif">Tạo Link Hoàn Tiền</h2>
            <p class="text-xs text-gray-400 mt-0.5">
                Hỗ trợ Shopee • Lazada • TikTok Shop • Tiki
            </p>
    </div>

    <template x-if="error">
        <div class="bg-red-50 border border-red-200 rounded-xl max-[390px]:px-2.5 max-[390px]:py-1.5 px-3 py-2 max-[390px]:mb-2 mb-2.5 flex items-start gap-1.5">
            <span class="max-[390px]:text-sm text-base shrink-0">❌</span>
            <p class="text-xs text-red-700" x-text="error"></p>
        </div>
    </template>

    <div class="space-y-2.5">
        <div class="relative">
            <label for="original_url" class="sr-only">Dán link sản phẩm</label>
            <input
                id="original_url"
                x-ref="urlInput"
                type="url"
                required
                placeholder="Dán link sản phẩm..."
                x-model="url"
                x-bind:disabled="loading"
                @click="if (url.trim()) { url = ''; }"
                @input="
                    clearTimeout(autoGenerateTimer);
                    autoGenerateTimer = setTimeout(() => {
                        if (loading) return;
                        const val = url.trim();
                        if (!val) return;
                        if (val === lastSubmittedUrl) return;
                        if (!/^https?:\/\//i.test(val)) return;
                        submit();
                    }, 300);
                "
                class="block w-full max-[390px]:h-11 h-12 max-[390px]:px-3 px-3.5 pr-14 max-[390px]:text-sm text-base border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400"
            >
            <button
                type="button"
                x-show="url.trim() !== ''"
                @click="url = ''; $nextTick(() => $refs.urlInput.focus())"
                class="absolute right-3 top-1/2 -translate-y-1/2 w-7 h-7 flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full transition-colors"
                aria-label="Xóa link"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        <button
            type="button"
            @click="submit"
            x-bind:disabled="loading"
            class="w-full h-13 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-sm font-semibold rounded-xl shadow-lg shadow-emerald-200 hover:shadow-emerald-300 transition-all duration-150 flex items-center justify-center gap-2 disabled:opacity-60 disabled:cursor-not-allowed" style="font-family: 'Inter', sans-serif"
        >
            <template x-if="!loading">
                <span class="max-[390px]:text-lg text-xl">🚀</span>
            </template>
            <template x-if="loading">
                <span class="inline-block w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
            </template>
            <span x-text="loading ? 'Đang xử lý...' : 'Tạo Link Ngay'"></span>
        </button>
    </div>

    <template x-if="result">
        <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-xl border border-emerald-200 max-[390px]:p-3 p-4">
            <div class="flex items-center gap-1.5 mb-2.5">
                <span class="max-[390px]:text-base text-lg">🎉</span>
                <h3 class="text-sm font-semibold text-emerald-800">Link Hoàn Tiền</h3>
            </div>

            <div class="space-y-2.5">
                <div class="text-center bg-white rounded-xl border border-emerald-100 max-[390px]:px-3 max-[390px]:py-1.5 px-4 py-2">
                    <p class="text-xs text-gray-500">Bạn sẽ được hoàn</p>
                    <p class="text-3xl font-bold text-emerald-600 leading-tight">
                        <span x-text="result.user_estimated_cashback != null ? '≈ ' + Number(result.user_estimated_cashback).toLocaleString('vi-VN') + 'đ' : ''"></span>
                    </p>
                </div>

                <div x-show="result.product_price || result.estimated_cashback || result.cashback_rate"
                     class="bg-white rounded-xl border border-emerald-100 px-4 py-2.5 space-y-1.5">
                    <div x-show="result.product_price" class="flex items-center justify-between text-xs">
                        <span class="text-gray-500">Giá</span>
                        <span class="font-semibold text-gray-800">
                            <span x-text="result.product_price != null ? Number(result.product_price).toLocaleString('vi-VN') + 'đ' : ''"></span>
                        </span>
                    </div>
                    <div x-show="result.estimated_cashback" class="flex items-center justify-between text-xs">
                        <span class="text-gray-500">Hoa hồng ước tính</span>
                        <span class="font-semibold text-purple-600">
                            <span x-text="result.estimated_cashback != null ? Number(result.estimated_cashback).toLocaleString('vi-VN') + 'đ' : ''"></span>
                        </span>
                    </div>
                    <div x-show="result.cashback_rate" class="flex items-center justify-between text-xs">
                        <span class="text-gray-500">Tỷ lệ hoàn cho bạn</span>
                        <span class="font-semibold text-emerald-700">
                            <span x-text="result.cashback_rate != null ? Number(result.cashback_rate) + '%' : ''"></span>
                        </span>
                    </div>
                </div>

                <div>
                    <p class="text-xs text-gray-500 text-center mb-2">🔗 Link hoàn tiền</p>
                    <a
                        x-bind:href="result.affiliate_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block bg-white rounded-xl border border-emerald-100 overflow-hidden cursor-pointer hover:opacity-90 transition-opacity"
                    >
                        <div class="flex items-center gap-3 p-3">
                            <div class="w-16 h-16 shrink-0 bg-gray-100 rounded-lg overflow-hidden flex items-center justify-center">
                                <img
                                    x-show="result.product_image"
                                    x-bind:src="result.product_image"
                                    alt=""
                                    class="w-full h-full object-contain"
                                    x-on:error="$el.style.display='none'"
                                >
                                <div
                                    x-show="!result.product_image"
                                    class="w-full h-full flex items-center justify-center text-gray-300"
                                >
                                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.41a2.25 2.25 0 013.182 0l2.909 2.91m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
                                </div>
                            </div>
                            <p
                                class="text-sm text-gray-800 font-medium leading-snug line-clamp-3 min-w-0"
                                x-text="result.product_name || 'Sản phẩm'"
                            ></p>
                        </div>
                    </a>
                </div>

                <div class="flex gap-2">
                    <button
                        type="button"
                        @click="copyLink"
                        class="flex-1 h-12 bg-white hover:bg-emerald-50 active:bg-emerald-100 text-emerald-700 font-semibold text-sm rounded-xl border-2 border-emerald-200 transition-all duration-150 flex items-center justify-center gap-1.5"
                    >
                        <span x-show="!copied" class="max-[390px]:text-base text-lg">📋</span>
                        <span x-show="!copied">Sao chép</span>
                        <span x-show="copied" x-cloak>✅</span>
                        <span x-show="copied" x-cloak class="font-semibold">Đã sao chép!</span>
                    </button>

                    <a
                        x-bind:href="result.affiliate_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="flex-1 h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-semibold text-sm rounded-xl transition-all duration-150 flex items-center justify-center gap-1.5"
                    >
                        <span class="max-[390px]:text-base text-lg">🛒</span>
                        <span>Mua ngay</span>
                    </a>
                </div>
            </div>
        </div>
    </template>
</div>
