@php
    $shopeeFashionCount = (int) ($shopeeFashionAlert['count'] ?? 0);
    $shopeeFashionHref = (string) ($shopeeFashionAlert['destinationUrl']
        ?? \App\Models\Setting::SHOPEE_FASHION_ALERT_DESTINATION_URL_DEFAULT);
    $shopeeFashionDisplayUrl = (string) ($shopeeFashionAlert['displayUrl']
        ?? \App\Models\Setting::SHOPEE_FASHION_ALERT_DISPLAY_URL_DEFAULT);
@endphp
@if ($shopeeFashionCount > 0)
    <div
        x-data="{
            visible: true,
            busy: false,
            error: '',
            acknowledgeUrl: @js(route('shopee-fashion-alert.acknowledge')),
            disableUrl: @js(route('shopee-fashion-alert.disable')),
            csrfToken() {
                return document.querySelector('meta[name=&quot;csrf-token&quot;]')?.content || '{{ csrf_token() }}';
            },
            clearNavBadges() {
                document.querySelectorAll('[data-shopee-fashion-badge]').forEach((el) => el.remove());
            },
            async send(url) {
                if (this.busy) return;
                this.busy = true;
                this.error = '';
                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': this.csrfToken(),
                        },
                    });
                    if (! res.ok) {
                        throw new Error('HTTP ' + res.status);
                    }
                    this.visible = false;
                    this.clearNavBadges();
                } catch (e) {
                    this.error = 'Không thực hiện được, vui lòng thử lại.';
                } finally {
                    this.busy = false;
                }
            },
        }"
        x-show="visible"
        x-cloak
        class="bg-amber-50 border border-amber-300 rounded-xl max-[390px]:px-3 max-[390px]:py-2 px-4 py-2.5"
        role="status"
        aria-live="polite"
    >
        <div class="flex items-start gap-2">
            <span class="text-lg shrink-0 leading-6" aria-hidden="true">🪙</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-amber-900">
                    Có {{ $shopeeFashionCount }} đơn Shopee thời trang cần cào xu
                </p>
                <p class="text-xs sm:text-sm text-amber-800 leading-relaxed mt-0.5">
                    Bạn có {{ $shopeeFashionCount }} đơn hàng Shopee thuộc danh mục thời trang (hoa hồng 7%).
                    Hãy cào xu tại
                    <a
                        href="{{ $shopeeFashionHref }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-semibold text-amber-700 underline hover:text-amber-900 break-all"
                    >{{ $shopeeFashionDisplayUrl }}</a>
                    để không bỏ lỡ hoàn tiền.
                </p>
            </div>
            <button
                type="button"
                @click="visible = false"
                class="shrink-0 -mr-1 -mt-1 w-7 h-7 flex items-center justify-center text-amber-500 hover:text-amber-700 hover:bg-amber-100 rounded-full transition-colors"
                aria-label="Đóng thông báo cào xu"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        <p
            x-show="error"
            x-cloak
            class="text-[11px] text-red-600 mt-1.5"
            role="alert"
            x-text="error"
        ></p>

        <div class="flex gap-2 mt-2.5">
            <button
                type="button"
                @click="send(acknowledgeUrl)"
                x-bind:disabled="busy"
                class="flex-1 h-9 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center"
            >
                <span x-show="! busy">Tôi đã biết</span>
                <span x-show="busy" x-cloak>Đang xử lý...</span>
            </button>
            <button
                type="button"
                @click="send(disableUrl)"
                x-bind:disabled="busy"
                class="flex-1 h-9 bg-white hover:bg-amber-50 active:bg-amber-100 text-amber-700 text-xs font-semibold rounded-lg border border-amber-300 transition-colors disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center"
            >
                <span x-show="! busy">Tắt chức năng thông báo</span>
                <span x-show="busy" x-cloak>Đang xử lý...</span>
            </button>
        </div>
    </div>
@endif
