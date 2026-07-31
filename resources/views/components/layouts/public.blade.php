<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? 'Hoàn Tiền Aff' }} - HoanTien.xyz</title>
    <meta name="description" content="{{ $pageDescription ?? 'Hoàn Tiền Aff - Nền tảng hoàn tiền affiliate hàng đầu Việt Nam.' }}">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

    {{-- PWA --}}
    @include('components.pwa-meta')

    {{-- Open Graph --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $pageTitle ?? 'Hoàn Tiền Aff' }}">
    <meta property="og:description" content="{{ $ogDescription ?? $pageDescription ?? 'Hoàn tiền đến 15% khi mua sắm online.' }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    <meta property="og:site_name" content="Hoàn Tiền Aff">
    <meta property="og:locale" content="vi_VN">
    @if(!empty($ogImage))
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
    @endif

    {{-- Twitter Card --}}
    <meta name="twitter:card" content="{{ !empty($ogImage) ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $pageTitle ?? 'Hoàn Tiền Aff' }}">
    <meta name="twitter:description" content="{{ $ogDescription ?? $pageDescription ?? 'Hoàn tiền đến 15% khi mua sắm online.' }}">
    @if(!empty($ogImage))
        <meta name="twitter:image" content="{{ $ogImage }}">
    @endif

    {{-- Favicon --}}
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=be-vietnam-pro:400,500,600,700,800&display=swap" rel="stylesheet">

    {{-- Tailwind via Vite --}}
    @vite(['resources/css/app.css'])

    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Be Vietnam Pro', sans-serif; }
    </style>
</head>
<body class="bg-white text-gray-800 antialiased">
    @include('components.site-header')
    <main>
        {{ $slot }}
    </main>
    @include('components.site-footer')
</body>
</html>
