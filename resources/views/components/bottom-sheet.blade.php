@props(['show' => '', 'title' => ''])

<div x-cloak
     x-show="{{ $show }}"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-y-full"
     x-transition:enter-end="translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-y-0"
     x-transition:leave-end="translate-y-full"
     class="fixed inset-0 z-50 flex items-end">
    <div class="absolute inset-0 bg-black/40" @click="{{ $show }} = false"></div>
    <div class="relative w-full bg-white rounded-t-2xl shadow-2xl px-5 pt-6 pb-8 space-y-5 max-h-[75vh] overflow-y-auto">
        @if($title)
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-800">{{ $title }}</h3>
            <button @click="{{ $show }} = false" class="text-gray-400 hover:text-gray-600 p-1" aria-label="Đóng">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        @endif

        {{ $slot }}
    </div>
</div>
