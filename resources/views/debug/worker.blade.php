<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Debug Worker</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans antialiased">
    <div class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-xl font-bold text-gray-900 mb-1">🔧 Debug Worker</h1>
        <p class="text-sm text-gray-400 mb-6">Kiểm tra kết nối Laravel ↔ NodeJS worker</p>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
            <div class="flex items-center justify-between mb-4">
                <span class="text-sm font-semibold text-gray-700">Worker Status</span>
                <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-1 rounded-full {{ $online ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600' }}">
                    <span class="w-2 h-2 rounded-full {{ $online ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                    {{ $online ? 'Online' : 'Offline' }}
                </span>
            </div>

            @if ($health)
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-400">Service</span>
                        <span class="font-mono text-gray-700">{{ $health['service'] ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between border-t border-gray-50 pt-2">
                        <span class="text-gray-400">Version</span>
                        <span class="font-mono text-gray-700">{{ $health['version'] ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between border-t border-gray-50 pt-2">
                        <span class="text-gray-400">Worker URL</span>
                        <span class="font-mono text-gray-700 text-xs">{{ config('services.affiliate_worker.url', 'http://127.0.0.1:3001') }}</span>
                    </div>
                </div>
            @else
                <p class="text-sm text-red-500">Không thể kết nối tới worker.</p>
            @endif
        </div>

        @if (!$online)
            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                <p class="text-sm font-medium text-amber-800 mb-1">Worker chưa chạy</p>
                <p class="text-xs text-amber-700">Mở terminal riêng và chạy:</p>
                <pre class="text-xs font-mono bg-amber-100 rounded-lg px-3 py-2 mt-1.5 text-amber-900">cd affiliate-worker
npm install
npm start</pre>
            </div>
        @endif
    </div>
</body>
</html>
