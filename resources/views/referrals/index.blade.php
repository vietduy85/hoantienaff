<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('dashboard') }}" class="shrink-0">
                <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
                </svg>
            </a>
            <h2 class="font-bold text-2xl text-gray-800 leading-tight tracking-tight">
                {{ __('Quản lý giới thiệu') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-6 px-4 max-w-lg mx-auto space-y-4" x-data="{ show: false, message: '' }">
        {{-- Intro --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-2">
            <p class="font-bold text-gray-800">Chia sẻ link ngay – Nhận tiền liền tay 💸</p>
            <p class="text-sm text-gray-500 leading-relaxed">
                Khi bạn bè đăng ký qua link của bạn và hoàn thành đủ
                <span class="font-semibold text-emerald-600">{{ $requiredOrders }} đơn hàng hợp lệ</span>,
                bạn sẽ được thưởng
                <span class="font-semibold text-emerald-600">+{{ number_format($rewardAmount, 0, ',', '.') }}đ</span>.
            </p>
        </div>

        {{-- Referral link --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-3">
            <p class="text-sm font-medium text-gray-600">🔗 Link giới thiệu của bạn</p>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ $referralLink }}"
                       class="w-full h-11 px-3 rounded-xl border border-gray-200 bg-gray-50 text-sm text-gray-700 font-mono truncate focus:outline-none focus:ring-2 focus:ring-emerald-100"
                       aria-label="Link giới thiệu">
                <button type="button"
                        @click="
                            navigator.clipboard.writeText('{{ $referralLink }}').then(() => {
                                show = true; message = '✓ Đã sao chép link giới thiệu';
                                setTimeout(() => show = false, 2000);
                            }).catch(() => {
                                show = true; message = '✓ Đã sao chép link giới thiệu';
                                setTimeout(() => show = false, 2000);
                            });
                        "
                        class="shrink-0 h-11 px-4 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700 text-white text-sm font-semibold rounded-xl transition-colors shadow-sm"
                        title="Sao chép link giới thiệu">
                    Sao chép
                </button>
            </div>
            <p class="text-xs text-gray-400">
                Link đang dùng mã <span class="font-mono text-gray-500">{{ $user->username }}</span>
            </p>
        </div>

        {{-- Stats --}}
        <div class="grid grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <span class="text-sm text-gray-500">👥 Đã mời</span>
                <span class="block text-2xl font-bold text-emerald-600 tracking-tight">{{ $invitedCount }}</span>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 space-y-1">
                <span class="text-sm text-gray-500">💰 Đã nhận</span>
                <span class="block text-2xl font-bold text-emerald-600 tracking-tight">
                    {{ number_format(floor($receivedAmount), 0, ',', '.') }}<span class="text-sm">đ</span>
                </span>
            </div>
        </div>

        {{-- Referred users --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
            <p class="font-bold text-gray-800">Danh sách người được giới thiệu</p>

            @forelse ($referrals as $referral)
                @php
                    $username = $referral->referredUser?->username ?? '#'.$referral->referred_user_id;
                    $joinedAt = $referral->referredUser?->created_at;
                    $count = (int) $referral->completed_orders;
                    if ($count <= 0) {
                        $progressLabel = 'Chưa có đơn';
                        $progressColor = 'text-gray-400';
                    } elseif ($count < $requiredOrders) {
                        $progressLabel = 'Đang hoàn thành';
                        $progressColor = 'text-amber-500';
                    } else {
                        $progressLabel = 'Hoàn thành';
                        $progressColor = 'text-emerald-600';
                    }
                    $badge = $referral->isCompleted()
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                        : 'bg-amber-50 text-amber-700 border-amber-200';
                    $badgeText = $referral->isCompleted() ? 'Hoàn thành' : 'Đang chờ';
                @endphp

                <div class="flex items-center gap-3 py-1">
                    <div class="shrink-0 w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 font-bold flex items-center justify-center uppercase">
                        {{ mb_substr((string) $username, 0, 1) }}
                    </div>

                    <div class="flex-1 min-w-0 space-y-0.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-sm text-gray-800 truncate">{{ $username }}</span>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium border {{ $badge }}">
                                {{ $badgeText }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs text-gray-400">
                                Tham gia {{ $joinedAt ? $joinedAt->format('d/m/Y') : '—' }}
                            </span>
                            <span class="text-xs {{ $progressColor }}">
                                {{ $count }}/{{ $requiredOrders }} đơn
                                <span class="text-gray-400">· {{ $progressLabel }}</span>
                            </span>
                        </div>

                        @if ($referral->isRewarded())
                            <div class="text-sm font-semibold text-emerald-600">
                                +{{ number_format($referral->reward_amount, 0, ',', '.') }}đ đã nhận
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="border border-dashed border-gray-200 rounded-xl p-6 text-center space-y-1">
                    <span class="text-3xl block">🎉</span>
                    <p class="text-sm text-gray-400">
                        Bạn chưa mời được ai. Hãy chia sẻ link giới thiệu của bạn để nhận thưởng!
                    </p>
                </div>
            @endforelse
        </div>

        {{-- Toast --}}
        <div x-cloak
             x-show="show"
             x-transition.duration.200ms
             @click="show = false"
             class="fixed top-4 left-1/2 -translate-x-1/2 z-50 bg-gray-800 text-white text-sm font-medium px-4 py-2.5 rounded-xl shadow-lg cursor-pointer">
            <span x-text="message"></span>
        </div>
    </div>
</x-app-layout>