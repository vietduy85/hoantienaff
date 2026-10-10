{{-- PWA meta - Ten hien thi khi Add to Home Screen: Hoan Tien --}}
@php
    // Icon thu hai: Module The tin dung (/thetindung/*) co manifest rieng, mo thang
    // start_url /thetindung. Moi trang khac giu nguyen manifest Dashboard hien tai.
    $isCreditCardApp = request()->routeIs('credit-cards.*');
    $pwaManifest = $isCreditCardApp ? '/thetindung.webmanifest' : '/manifest.webmanifest';
    $pwaAppName = $isCreditCardApp ? 'Thẻ tín dụng' : 'Hoan Tien';
    $pwaThemeColor = $isCreditCardApp ? '#0D9EF3' : '#FF6A00';
    // Tạm thời dùng chung bộ icon của Dashboard cho cả hai PWA.
    $pwaAppleIcon = '/apple-touch-icon-180.png';
@endphp
<script>
    window.__PWA_ENABLED = {{ config('pwa.enabled') ? 'true' : 'false' }};
</script>
@if(config('pwa.enabled'))
<link rel="manifest" href="{{ $pwaManifest }}">
<meta name="theme-color" content="{{ $pwaThemeColor }}">
<meta name="application-name" content="{{ $pwaAppName }}">
<meta name="apple-mobile-web-app-title" content="{{ $pwaAppName }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" sizes="180x180" href="{{ $pwaAppleIcon }}">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
@endif
