@php
    $colors = [
        'Shopee' => 'bg-orange-50 text-orange-700',
        'Lazada' => 'bg-blue-50 text-blue-700',
        'TikTok Shop' => 'bg-pink-50 text-pink-700',
        'Tiki' => 'bg-cyan-50 text-cyan-700',
    ];
    $color = $colors[$platform] ?? 'bg-gray-50 text-gray-600';
@endphp

<span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full shrink-0 {{ $color }}">
    {{ $platform }}
</span>
