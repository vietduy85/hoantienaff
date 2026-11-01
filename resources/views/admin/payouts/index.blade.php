<x-app-layout>
    <div class="py-4 px-3 max-w-lg mx-auto space-y-4" data-testid="payouts-page"
         x-data="{ app: localStorage.getItem('payoutApp') || 'mb', apps: @js($apps) }"
         x-init="$watch('app', v => localStorage.setItem('payoutApp', v))">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-900">💸 Chuyển tiền rút</h1>
            <span class="text-xs text-gray-500">Hôm nay đã chuyển: {{ $paidToday }}</span>
        </div>

        {{-- Thông báo đẩy lên màn hình khoá khi có yêu cầu rút mới --}}
        <div x-data="pushToggle(@js(config('services.webpush.public_key')))" x-init="init()" data-testid="push-toggle"
             class="bg-white rounded-xl border border-gray-100 p-3">
            <template x-if="state === 'on'">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm text-emerald-700">🔔 Đã bật thông báo trên máy này</span>
                    <div class="flex gap-2 shrink-0">
                        <button type="button" x-on:click="sendTest()" class="text-xs px-2 py-1 rounded-md border border-gray-300">Gửi thử</button>
                        <button type="button" x-on:click="disable()" class="text-xs px-2 py-1 rounded-md text-gray-500">Tắt</button>
                    </div>
                </div>
            </template>
            <template x-if="state === 'off'">
                <button type="button" x-on:click="enable()" x-bind:disabled="busy"
                        class="w-full py-2.5 rounded-lg bg-orange-500 text-white font-semibold disabled:opacity-60">🔔 Bật thông báo khi có yêu cầu rút</button>
            </template>
            <template x-if="state === 'need-install'">
                <p class="text-sm text-gray-700">📲 Trên iPhone, thông báo chỉ chạy khi mở từ màn hình chính: trong Safari bấm <b>Chia sẻ</b> → <b>Thêm vào MH chính</b>, rồi mở hoantien từ biểu tượng đó và quay lại trang này bấm "Bật thông báo".</p>
            </template>
            <template x-if="state === 'denied'">
                <p class="text-sm text-red-700">Bạn đã chặn thông báo. Vào Cài đặt → Thông báo → Hoàn Tiền để cho phép, rồi tải lại trang.</p>
            </template>
            <template x-if="state === 'unsupported'">
                <p class="text-sm text-gray-500">Trình duyệt này không hỗ trợ thông báo đẩy.</p>
            </template>
            <p x-show="message" x-text="message" x-cloak class="mt-2 text-xs text-gray-500"></p>
            <p x-show="diag" x-text="diag" x-cloak class="mt-1 text-[11px] text-gray-400" data-testid="push-diag"></p>
        </div>

        <div class="bg-white rounded-xl border border-gray-100 p-3 space-y-1">
            <label for="payout-app" class="text-xs text-gray-500">App ngân hàng bạn dùng để chuyển</label>
            <select id="payout-app" x-model="app" class="w-full rounded-lg border-gray-300 text-sm">
                @foreach ($apps as $id => $app)
                    <option value="{{ $id }}">{{ $app['name'] }}{{ $app['autofill'] ? ' — tự điền sẵn' : ' — chỉ mở app' }}</option>
                @endforeach
            </select>
            <p x-show="! apps[app]?.autofill" x-cloak class="text-[11px] text-amber-700">App này chỉ mở lên, chưa tự điền: dùng nút "Chép" bên dưới. MB, BIDV, VietinBank, ACB, OCB tự điền đầy đủ.</p>
        </div>

        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 text-sm text-emerald-700">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        @forelse ($requests as $item)
            @php($wr = $item['model'])
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-3"
                 data-testid="payout-card" x-data="{ rejecting: false }">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs text-gray-400">#{{ $wr->id }} · {{ $wr->created_at->format('H:i d/m') }} · {{ $wr->username }}</p>
                        <p class="text-2xl font-bold text-emerald-600">{{ number_format((float) $wr->amount, 0, ',', '.') }}đ</p>
                    </div>
                    <span class="shrink-0 text-xs px-2 py-1 rounded-full bg-amber-50 text-amber-700">Chờ chuyển</span>
                </div>

                @if ($item['deeplink'])
                    {{-- Mở ở tab mới để trang hoantien vẫn giữ nguyên, quay lại bấm "Đã chuyển xong" --}}
                    <a data-testid="payout-deeplink" target="_blank" rel="noopener"
                       x-bind:href="@js($item['deeplink']).replace('{{ \App\Services\Payout\VietQrPayload::APP_PLACEHOLDER }}', app)"
                       class="flex items-center justify-center gap-2 w-full py-3.5 rounded-xl bg-blue-600 text-white font-semibold text-base">
                        ⚡ Chuyển bằng <span x-text="apps[app]?.name"></span>
                    </a>
                @endif

                @if ($item['qr_svg'])
                    <details class="text-center">
                        <summary class="text-xs text-gray-500 cursor-pointer">Hiện mã QR (quét bằng máy khác)</summary>
                        <div class="mt-2 mx-auto w-56 h-56 bg-white p-2 rounded-xl border border-gray-200 [&>svg]:w-full [&>svg]:h-full">{!! $item['qr_svg'] !!}</div>
                    </details>
                @else
                    <p class="text-xs text-amber-700 bg-amber-50 rounded-lg p-2">Ngân hàng chưa có mã VietQR — nhập tay theo thông tin dưới.</p>
                @endif

                <dl class="text-sm divide-y divide-gray-100">
                    @foreach ([
                        'Ngân hàng' => [$wr->bank_name, false],
                        'Số tài khoản' => [$wr->bank_account, true],
                        'Chủ tài khoản' => [$wr->account_name, false],
                        'Số tiền' => [(string) (int) round((float) $wr->amount), true],
                        'Nội dung' => [$item['note'], true],
                    ] as $label => [$value, $copy])
                        <div class="flex items-center justify-between gap-2 py-2">
                            <dt class="text-gray-500 shrink-0">{{ $label }}</dt>
                            <dd class="flex items-center gap-2 min-w-0">
                                <span class="font-medium text-gray-900 text-right break-all">{{ $value }}</span>
                                @if ($copy)
                                    <button type="button" x-data="{ ok: false }"
                                            x-on:click="navigator.clipboard.writeText(@js($value)); ok = true; setTimeout(() => ok = false, 1500)"
                                            class="shrink-0 px-2 py-1 text-xs rounded-md border border-gray-300 text-gray-600"
                                            x-text="ok ? 'Đã chép' : 'Chép'">Chép</button>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <form method="post" action="{{ route('admin.payouts.complete', $wr) }}"
                      x-on:submit="if (! confirm(@js('Đã chuyển '.number_format((float) $wr->amount, 0, ',', '.').'đ cho '.$wr->account_name.'?'))) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="w-full py-3 rounded-xl bg-emerald-600 text-white font-semibold">✓ Đã chuyển xong</button>
                </form>

                <button type="button" x-show="!rejecting" x-on:click="rejecting = true" class="w-full text-sm text-red-600 py-1">Từ chối…</button>
                <form x-show="rejecting" x-cloak method="post" action="{{ route('admin.payouts.reject', $wr) }}" class="space-y-2">
                    @csrf
                    <input name="note" required maxlength="500" placeholder="Lý do (user sẽ thấy)" class="w-full rounded-lg border-gray-300 text-sm">
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 py-2 rounded-lg bg-red-600 text-white text-sm font-medium">Từ chối</button>
                        <button type="button" x-on:click="rejecting = false" class="px-4 py-2 text-sm text-gray-600">Hủy</button>
                    </div>
                </form>
            </div>
        @empty
            <div class="bg-white rounded-2xl border border-gray-100 p-8 text-center text-gray-500">🎉 Không có yêu cầu rút tiền nào đang chờ.</div>
        @endforelse

        <p class="text-center text-xs text-gray-400">
            <a href="{{ route('admin.withdraw-requests.index') }}" class="underline">Trang quản lý đầy đủ (PC)</a>
        </p>
    </div>

    <script>
        function pushToggle(publicKey) {
            const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
            const post = (url, body) => fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify(body || {}),
            });
            const keyBytes = (base64) => {
                const pad = '='.repeat((4 - base64.length % 4) % 4);
                const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
                return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
            };

            return {
                state: 'off', busy: false, message: '', diag: '',

                async init() {
                    const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
                    const standalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
                    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                        this.state = ios && !standalone ? 'need-install' : 'unsupported';
                        return;
                    }
                    if (!publicKey) { this.state = 'unsupported'; this.message = 'Máy chủ chưa cấu hình khoá thông báo.'; return; }
                    if (Notification.permission === 'denied') { this.state = 'denied'; return; }
                    // Đăng ký lại bằng URL mới để thay SW cũ (thiếu xử lý push), rồi chờ bản mới chạy.
                    try { await navigator.serviceWorker.register('/sw.js?v=2', { updateViaCache: 'none' }); } catch (e) {}
                    const reg = await Promise.race([navigator.serviceWorker.ready, new Promise((r) => setTimeout(r, 4000))])
                        || await navigator.serviceWorker.getRegistration('/');
                    const sub = reg ? await reg.pushManager.getSubscription() : null;
                    this.state = sub && Notification.permission === 'granted' ? 'on' : 'off';
                    this.diag = 'Máy này: ' + (standalone ? 'mở từ MH chính' : 'mở trong trình duyệt')
                        + ' · quyền ' + Notification.permission
                        + ' · đăng ký ' + (sub ? 'có' : 'không')
                        + ' · SW ' + await this.swVersion(reg);
                },

                swVersion(reg) {
                    const worker = reg && (reg.active || reg.waiting || reg.installing);
                    if (!worker) return Promise.resolve('chưa có');
                    return new Promise((resolve) => {
                        const channel = new MessageChannel();
                        channel.port1.onmessage = (e) => resolve(e.data);
                        worker.postMessage({ type: 'version' }, [channel.port2]);
                        setTimeout(() => resolve('cũ (chưa cập nhật)'), 1500);
                    });
                },

                async enable() {
                    this.busy = true; this.message = '';
                    try {
                        const permission = await Notification.requestPermission();
                        if (permission !== 'granted') { this.state = permission === 'denied' ? 'denied' : 'off'; return; }
                        await navigator.serviceWorker.register('/sw.js?v=2', { updateViaCache: 'none' });
                        const reg = await navigator.serviceWorker.ready;
                        const sub = await reg.pushManager.getSubscription()
                            || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });
                        const res = await post(@js(route('admin.push.subscribe')), sub.toJSON());
                        if (!res.ok) throw new Error('Lưu thiết bị thất bại (' + res.status + ')');
                        this.state = 'on';
                        await this.sendTest();
                    } catch (e) {
                        this.message = 'Không bật được: ' + e.message;
                    } finally {
                        this.busy = false;
                    }
                },

                async disable() {
                    const reg = await navigator.serviceWorker.getRegistration('/');
                    const sub = reg ? await reg.pushManager.getSubscription() : null;
                    if (sub) {
                        await post(@js(route('admin.push.unsubscribe')), { endpoint: sub.endpoint });
                        await sub.unsubscribe();
                    }
                    this.state = 'off'; this.message = 'Đã tắt thông báo trên máy này.';
                },

                async sendTest() {
                    const res = await post(@js(route('admin.push.test')));
                    const data = res.ok ? await res.json() : {};
                    this.message = data.ok ? 'Đã gửi thông báo thử — kiểm tra màn hình.' : 'Gửi thử chưa thành công, thử lại sau ít phút.';
                },
            };
        }
    </script>
</x-app-layout>
