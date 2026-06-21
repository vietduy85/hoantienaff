@php
    $styles = [
        'pending' => 'bg-yellow-50 text-yellow-700',
        'processing' => 'bg-blue-50 text-blue-700',
        'completed' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-red-50 text-red-700',
    ];
    $labels = [
        'pending' => 'Đang chờ',
        'processing' => 'Đang xử lý',
        'completed' => 'Hoàn thành',
        'rejected' => 'Từ chối',
    ];
    $style = $styles[$status] ?? 'bg-gray-50 text-gray-600';
    $label = $labels[$status] ?? $status;
@endphp

<span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full {{ $style }}">
    {{ $label }}
</span>
