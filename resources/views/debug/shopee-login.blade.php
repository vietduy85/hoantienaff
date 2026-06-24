<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Debug Shopee Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans antialiased">
    <div class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-xl font-bold text-gray-900 mb-1">🔧 TEST 8A + 8B — Shopee Affiliate</h1>
        <p class="text-sm text-gray-400 mb-6">Đăng nhập và kiểm tra session Shopee Affiliate</p>

        @if (session('status'))
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
                <div class="flex items-center gap-2">
                    @php
                        $statusColors = [
                            'success' => ['bg-emerald-50 text-emerald-600', 'bg-emerald-500', 'Thành công'],
                            'success-session' => ['bg-emerald-50 text-emerald-600', 'bg-emerald-500', 'Session OK'],
                            'session-valid' => ['bg-emerald-50 text-emerald-600', 'bg-emerald-500', 'Session valid'],
                            'session-expired' => ['bg-red-50 text-red-600', 'bg-red-500', 'Session expired'],
                            'manual_login_required' => ['bg-amber-50 text-amber-600', 'bg-amber-500', 'Cần đăng nhập'],
                        ];
                        $colors = $statusColors[session('status')] ?? ['bg-red-50 text-red-600', 'bg-red-500', 'Lỗi'];
                    @endphp
                    <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-1 rounded-full {{ $colors[0] }}">
                        <span class="w-2 h-2 rounded-full {{ $colors[1] }}"></span>
                        {{ $colors[2] }}
                    </span>
                    <span class="text-sm text-gray-700">{{ session('message') }}</span>
                </div>
            </div>
        @endif

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4 space-y-4">
            <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-700">Worker</span>
                <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-1 rounded-full {{ $online ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600' }}">
                    <span class="w-2 h-2 rounded-full {{ $online ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                    {{ $online ? 'Online' : 'Offline' }}
                </span>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                <span class="text-sm font-semibold text-gray-700">Session file</span>
                <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-1 rounded-full {{ $hasSession ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500' }}">
                    <span class="w-2 h-2 rounded-full {{ $hasSession ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                    {{ $hasSession ? 'Tồn tại' : 'Chưa có' }}
                </span>
            </div>
        </div>

        @if ($online)
            <div class="space-y-3">
                <form method="POST" action="{{ url('debug/shopee-login/session-test') }}">
                    @csrf
                    <button
                        type="submit"
                        class="w-full h-11 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-bold text-sm rounded-xl transition-all"
                    >
                        Kiểm tra Session
                    </button>
                </form>

                <form method="POST" action="{{ url('debug/shopee-login/check') }}">
                    @csrf
                    <button
                        type="submit"
                        class="w-full h-11 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white font-bold text-sm rounded-xl transition-all"
                    >
                        Kiểm tra login
                    </button>
                </form>

                <form method="POST" action="{{ url('debug/shopee-login/interactive') }}">
                    @csrf
                    <button
                        type="submit"
                        class="w-full h-11 bg-amber-500 hover:bg-amber-600 active:bg-amber-700 text-white font-bold text-sm rounded-xl transition-all"
                    >
                        Đăng nhập thủ công (mở trình duyệt)
                    </button>
                </form>

                <p class="text-xs text-gray-400 text-center pt-2">
                    Sau khi bấm "Đăng nhập thủ công", trình duyệt sẽ mở ra.
                    Hãy đăng nhập và chờ tự động đóng.
                </p>
            </div>
        @else
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                <p class="text-sm font-medium text-amber-800">Worker chưa chạy</p>
                <pre class="text-xs font-mono bg-amber-100 rounded-lg px-3 py-2 mt-1.5 text-amber-900">cd affiliate-worker && npm start</pre>
            </div>
        @endif
    </div>
</body>
</html>
