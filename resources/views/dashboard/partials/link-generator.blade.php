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
        _csrfToken: '',
        pageInstanceId: '{{ $page_instance_id ?? '' }}',
        t2FlowId: '',

        init() {
            try {
                this.pageInstanceId = '{{ $page_instance_id ?? '' }}';
                window.__t2Forensic = window.__t2Forensic || { page_instance_id: this.pageInstanceId, events: [] };
                this.fs('page_init', { meta_present: !!document.querySelector('meta[name=&quot;csrf-token&quot;]') });
                this.bindResumeProbe();
            } catch (e) {}
        },

        fs(event, extra) {
            try {
                const rec = { t: Date.now(), ts: new Date().toISOString(), ev: event, page_instance_id: this.pageInstanceId, t2_flow_id: this.t2FlowId || null, ...(extra || {}) };
                window.__t2Forensic = window.__t2Forensic || { events: [] };
                window.__t2Forensic.events.push(rec);
                if (window.__t2Forensic.events.length > 500) window.__t2Forensic.events.shift();
                if (window.console && window.console.debug) window.console.debug('[F]', event, rec);
            } catch (e) {}
        },

        fsFp(value) {
            try {
                if (window.crypto && window.crypto.subtle && value) {
                    window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(String(value))).then((buf) => {
                        const hex = Array.from(new Uint8Array(buf)).map((b) => b.toString(16).padStart(2, '0')).join('').slice(0, 10);
                        window.__t2Forensic = window.__t2Forensic || { events: [] };
                        window.__t2Forensic.events.push({ t: Date.now(), ev: 'token_fp', page_instance_id: this.pageInstanceId, t2_flow_id: this.t2FlowId || null, fp: hex });
                    }).catch(() => {});
                }
            } catch (e) {}
        },

        submit() {
            if (this.loading) return;
            if (!this.url.trim()) return;
            this.t2FlowId = 'T2-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
            this.fs('submit_start', { url_char_len: this.url.trim().length });
            this.lastSubmittedUrl = this.url.trim();
            this.loading = true;
            this.error = '';
            this.result = null;
            this.stopPolling();
            this.post();
        },

        csrfToken() {
            return this._csrfToken || '{{ csrf_token() }}';
        },

        // Single-flight CSRF refresh: nếu nhiều request đồng thời cùng gặp 419
        // thì CHỈ 1 GET /csrf-token được chạy, các request khác chờ cùng promise.
        async ensureFreshCsrf(force = false) {
            if (window.__csrfPromise) {
                return window.__csrfPromise;
            }

            window.__csrfPromise = (async () => {
                this.fs('refresh_csrf_start', { force });
                try {
                    const res = await fetch('{{ route('csrf-token') }}', {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        }
                    });
                    this.fs('refresh_csrf_response', { status: res.status, ok: res.ok, force });
                    if (!res.ok) {
                        throw new Error('CSRF refresh HTTP ' + res.status);
                    }
                    const data = await res.json();
                    const token = data && data.token;
                    if (!token) {
                        throw new Error('CSRF refresh invalid payload');
                    }
                    // Cập nhật token vào ĐÚNG state mà submit flow dùng (X-CSRF-TOKEN).
                    this._csrfToken = token;
                    this.fs('refresh_csrf_token_found', { new_token_len: token.length, force });
                    this.fsFp(token);
                    return token;
                } catch (e) {
                    this.fs('refresh_csrf_exception', { name: (e && e.name) || 'Error', msg: (e && e.message) ? String(e.message).slice(0, 200) : '' });
                    throw e;
                } finally {
                    window.__csrfPromise = null;
                }
            })();

            return window.__csrfPromise;
        },

        // Revalidate CSRF khi tab resume (visibility/focus), throttle hợp lý.
        // KHÔNG phải cơ chế recovery duy nhất: POST → 419 recovery vẫn tự hoạt động.
        bindResumeProbe() {
            try {
                let last = 0;
                const throttleMs = 10000;
                const probe = () => {
                    const now = Date.now();
                    if (now - last < throttleMs) return;
                    if (document.visibilityState !== 'visible') return;
                    last = now;
                    this.ensureFreshCsrf(true).catch(() => {});
                };
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') probe();
                });
                window.addEventListener('focus', probe);
                window.addEventListener('pageshow', probe);
            } catch (e) {}
        },

        getErrorMessage(data) {
            if (!data || typeof data !== 'object') return '';

            if (data.errors) {
                const vals = Array.isArray(data.errors) ? data.errors : Object.values(data.errors);
                const first = Array.isArray(vals[0]) ? vals[0][0] : vals[0];
                if (typeof first === 'string' && first.trim()) return first.trim();
            }

            if (typeof data.message === 'string' && data.message.trim()) return data.message.trim();
            if (typeof data.error === 'string' && data.error.trim()) return data.error.trim();
            return '';
        },

        async post(retry = true) {
            let response;
            this.fs('post_start', { retry });
            try {
                response = await fetch('{{ route('link-requests.store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken(),
                        'X-Forensic-Page-Id': this.pageInstanceId,
                        'X-Forensic-T2-Flow-Id': this.t2FlowId
                    },
                    body: JSON.stringify({ original_url: this.url.trim() })
                });
            } catch (e) {
                this.fs('post_network_error', { name: (e && e.name) || 'Error', msg: (e && e.message) ? String(e.message).slice(0, 200) : '' });
                this.error = 'Không thể kết nối máy chủ';
                this.loading = false;
                return;
            }

            this.fs('post_response', { status: response.status, ok: response.ok, retry });

            // 419 lần đầu: refresh CSRF bằng /csrf-token rồi retry đúng 1 lần (không redirect/reload).
            if (response.status === 419 && retry) {
                this.fs('post_419', { retry });
                try {
                    // ensureFreshCsrf(true) luôn thực hiện GET /csrf-token (chia sẻ single-flight nếu đang chạy).
                    await this.ensureFreshCsrf(true);
                } catch (e) {
                    // Refresh thất bại (HTTP !ok / payload rỗng / network) => không retry mù.
                    this.fs('refresh_fail_after_419', { name: (e && e.name) || 'Error' });
                    this.showSessionExpired();
                    return;
                }
                this.fs('retry_post_start', {});
                await this.post(false);
                return;
            }

            // Còn 401/419 sau khi đã retry => session thật sự không còn hợp lệ.
            if (response.status === 401 || response.status === 419) {
                this.fs('post_401_or_419', { status: response.status, retry });
                this.showSessionExpired();
                return;
            }

            try {
                const data = await response.json();
                if (!data.success) {
                    this.fs('post_business_error', { status: response.status });
                    this.error = this.getErrorMessage(data) || 'Lỗi không xác định';
                    this.loading = false;
                    return;
                }
                this.fs('post_success', { status: response.status });
                this.requestId = data.request_id;
                if (data.affiliate_url) {
                    this.result = { ...data };
                    this.loading = false;
                }
                this.startPolling();
            } catch (e) {
                this.fs('post_json_error', { name: (e && e.name) || 'Error', msg: (e && e.message) ? String(e.message).slice(0, 200) : '' });
                this.error = 'Không thể kết nối máy chủ';
                this.loading = false;
            }
        },

        showSessionExpired() {
            this.fs('show_session_expired_start', {});
            this.error = 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.';
            this.loading = false;
            setTimeout(() => {
                window.location.href = '{{ route('login') }}';
            }, 1200);
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
                Hỗ trợ Shopee • ShopeeFood • Lazada • TikTok Shop • Tiki
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
                class="block w-full max-[390px]:h-11 h-12 max-[390px]:px-3 px-3.5 pr-14 text-base border-2 border-gray-200 rounded-xl focus:border-emerald-400 focus:ring-emerald-400 transition placeholder:text-gray-400"
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
                    <p class="text-xs text-gray-500" x-show="result.platform !== 'ShopeeFood'">Bạn sẽ được hoàn</p>
                    <p class="text-3xl font-bold text-emerald-600 leading-tight">
                        <span x-show="result.platform === 'ShopeeFood'" x-cloak class="text-lg font-medium text-emerald-600">Lên đến 4% tổng bill</span>
                        <span x-show="result.platform !== 'ShopeeFood' && result.user_estimated_cashback != null && result.user_estimated_cashback > 0" x-text="'≈ ' + Number(result.user_estimated_cashback).toLocaleString('vi-VN') + 'đ'"></span>
                        <span x-show="result.platform !== 'ShopeeFood' && !(result.user_estimated_cashback != null && result.user_estimated_cashback > 0)" class="text-lg font-medium text-gray-400">Chưa có dữ liệu</span>
                    </p>
                    <p x-show="result.platform === 'Lazada' && result.estimated_cashback != null && result.estimated_cashback > 0" class="text-[11px] text-gray-400 mt-1">
                        Hoa hồng đối tác ≈ <span x-text="Number(result.estimated_cashback).toLocaleString('vi-VN')"></span>đ
                    </p>
                </div>

                <div>
                    <p class="text-xs text-gray-500 text-center mb-2" x-text="result.platform === 'Lazada' ? '🔗 Link Lazada' : '🔗 Link hoàn tiền'"></p>
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
                        class="flex-[0.35] h-12 bg-white hover:bg-emerald-50 active:bg-emerald-100 text-emerald-700 font-semibold text-sm rounded-xl border-2 border-emerald-200 transition-all duration-150 flex items-center justify-center gap-1.5"
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
                        class="flex-[0.65] h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-semibold text-sm rounded-xl transition-all duration-150 flex items-center justify-center gap-1.5"
                    >
                        <span class="max-[390px]:text-base text-lg">🛒</span>
                        <span>Add giỏ / Mua ngay</span>
                    </a>
                </div>
            </div>
        </div>
    </template>
</div>
