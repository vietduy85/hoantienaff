{{-- Thanh tab chung cho nhóm "Đơn & Ví": Ví tiền · Đơn hàng · Giới thiệu · Tài khoản (STK). --}}
@php
    $walletTabs = [
        ['label' => 'Ví tiền', 'href' => route('wallet.index'), 'active' => request()->routeIs('wallet.*')],
        ['label' => 'Đơn hàng', 'href' => route('orders.index'), 'active' => request()->routeIs('orders.*')],
        ['label' => 'Giới thiệu', 'href' => route('referrals.index'), 'active' => request()->routeIs('referrals.*')],
        ['label' => 'Tài khoản & STK', 'href' => route('profile.edit'), 'active' => request()->routeIs('profile.*')],
    ];
@endphp
<div class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-testid="wallet-tabs">
    @foreach ($walletTabs as $tab)
        <a href="{{ $tab['href'] }}"
           @if ($tab['active']) aria-current="page" @endif
           class="shrink-0 h-9 px-4 rounded-full text-sm font-medium flex items-center whitespace-nowrap transition-colors {{ $tab['active'] ? 'bg-emerald-500 text-white shadow-sm' : 'bg-gray-100 text-gray-600' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
