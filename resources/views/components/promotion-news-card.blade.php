@props([
    'item',
    'href' => null,
    'external' => false,
    'compact' => false,
])

@php
    $label = \App\Services\PromotionNews\PromotionNewsSources::label($item->source);
    $emoji = \App\Services\PromotionNews\PromotionNewsSources::emoji($item->source);
    $image = $item->imageUrl;
    $tag = $href !== null ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if ($href !== null)
        href="{{ $href }}"
        @if ($external) target="_blank" rel="noopener noreferrer nofollow" @endif
    @endif
    {{ $attributes->merge(['class' => 'group block rounded-xl border border-gray-200 bg-white overflow-hidden transition hover:shadow-md hover:border-gray-300']) }}
>
    <div class="flex items-center justify-center bg-gray-50 overflow-hidden {{ $compact ? 'h-28 sm:h-32' : 'h-32 sm:h-40' }}">
        @if ($image)
            <img
                src="{{ $image }}"
                alt="{{ $item->title ?? $label }}"
                loading="lazy"
                class="max-h-full max-w-full object-contain"
            >
        @else
            <span class="text-3xl">{{ $emoji }}</span>
        @endif
    </div>

    <div class="p-3">
        <div class="flex items-center gap-1.5 text-xs font-medium text-gray-500">
            <span aria-hidden="true">{{ $emoji }}</span>
            <span>{{ $label }}</span>
        </div>

        <p class="mt-1 text-sm font-semibold text-gray-900">
            {{ $item->title ?? 'Xem khuyến mãi' }}
        </p>

        <span class="mt-2 inline-block text-xs font-medium text-indigo-600 group-hover:text-indigo-700">
            Xem chi tiết &rarr;
        </span>
    </div>
</{{ $tag }}>
