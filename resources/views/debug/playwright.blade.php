<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Debug Playwright</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 font-sans antialiased">
    <div class="max-w-lg mx-auto px-4 py-8">
        <h1 class="text-xl font-bold text-gray-900 mb-1">🔧 Debug Playwright</h1>
        <p class="text-sm text-gray-400 mb-6">Kiểm tra NodeJS ↔ Playwright ↔ Browser</p>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4 space-y-4">
            <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-700">Worker</span>
                <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-1 rounded-full {{ $online ? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600' }}">
                    <span class="w-2 h-2 rounded-full {{ $online ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                    {{ $online ? 'Online' : 'Offline' }}
                </span>
            </div>

            @if ($online)
                <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                    <span class="text-sm font-semibold text-gray-700">Playwright</span>
                    @if ($playwrightResult && $playwrightResult['success'])
                        <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-1 rounded-full bg-emerald-50 text-emerald-600">
                            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                            Working
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-1 rounded-full bg-red-50 text-red-600">
                            <span class="w-2 h-2 rounded-full bg-red-500"></span>
                            Error
                        </span>
                    @endif
                </div>

                @if ($playwrightResult)
                    <div class="pt-3 border-t border-gray-100">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-400">Title</span>
                            <span class="font-mono text-gray-700 font-medium">{{ $playwrightResult['title'] ?? '—' }}</span>
                        </div>
                    </div>
                    @isset ($playwrightResult['error'])
                        <div class="text-sm text-red-600 bg-red-50 rounded-lg p-3 font-medium">{{ $playwrightResult['error'] }}</div>
                    @endisset
                @endif
            @else
                <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                    <p class="text-sm font-medium text-amber-800">Worker chưa chạy</p>
                    <pre class="text-xs font-mono bg-amber-100 rounded-lg px-3 py-2 mt-1.5 text-amber-900">cd affiliate-worker && npm start</pre>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
