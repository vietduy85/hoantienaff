<x-guest-layout>
    <div class="mb-6 text-center">
        <h1 class="text-2xl font-bold text-gray-900">Hoàn tất tài khoản</h1>
        <p class="mt-2 text-sm text-gray-500">Thiết lập thông tin tài khoản của bạn</p>
    </div>

    <form method="POST" action="{{ route('complete-profile.store') }}">
        @csrf

        <div>
            <x-input-label for="username" :value="__('Username')" />
            <div class="relative">
                <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username', $suggestion)" required autofocus data-check-username placeholder="Gợi ý: {{ $suggestion }}" />
                <span id="username-status" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"></span>
            </div>
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
            <p id="username-msg" class="mt-1.5 text-xs text-gray-400">
                Username chỉ được chứa chữ cái, số và dấu gạch dưới (_). 3-30 ký tự.
            </p>
        </div>

        <div class="mt-4">
            <x-input-label for="name" :value="__('Tên hiển thị')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name', $user->name)" required />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg">
            <p class="text-xs text-amber-700 leading-relaxed">
                <strong>Lưu ý:</strong> Username sẽ được sử dụng để theo dõi đơn hàng, ví tiền và Affiliate.
                Username chỉ được thiết lập <strong>MỘT LẦN</strong> và không thể thay đổi sau này.
            </p>
        </div>

        <div class="flex items-center justify-end mt-6">
            <x-primary-button>
                {{ __('Hoàn tất') }}
            </x-primary-button>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const input = document.querySelector('[data-check-username]');
            if (!input) return;
            const status = document.getElementById('username-status');
            const msg = document.getElementById('username-msg');
            let timer;

            input.addEventListener('input', function () {
                clearTimeout(timer);
                const val = input.value.trim();
                if (val.length < 3) {
                    status.className = 'absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden';
                    msg.textContent = 'Username chỉ được chứa chữ cái, số và dấu gạch dưới (_). 3-30 ký tự.';
                    msg.className = 'mt-1.5 text-xs text-gray-400';
                    return;
                }
                timer = setTimeout(function () {
                    fetch('/check-username?username=' + encodeURIComponent(val))
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            status.className = 'absolute right-3 top-1/2 -translate-y-1/2 text-sm';
                            if (data.available) {
                                status.textContent = '🟢';
                                msg.textContent = 'Username khả dụng.';
                                msg.className = 'mt-1.5 text-xs text-green-600';
                            } else {
                                status.textContent = '🔴';
                                msg.textContent = data.message;
                                msg.className = 'mt-1.5 text-xs text-red-500';
                            }
                        });
                }, 400);
            });
        });
    </script>
</x-guest-layout>
