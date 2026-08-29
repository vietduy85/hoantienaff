<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Username -->
        <div>
            <x-input-label for="username" :value="__('Username')" />
            <div class="relative">
                <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" required autofocus autocomplete="off" data-check-username />
                <span id="username-status" class="absolute right-3 top-1/2 -translate-y-1/2 text-sm hidden"></span>
            </div>
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
            <p id="username-msg" class="mt-1 text-xs text-gray-400">Username chỉ được chứa chữ cái, số và dấu gạch dưới (_). 3-30 ký tự.</p>
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input id="password_confirmation" class="block mt-1 w-full"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('login') }}">
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Register') }}
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
                    msg.className = 'mt-1 text-xs text-gray-400';
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
                                msg.className = 'mt-1 text-xs text-green-600';
                            } else {
                                status.textContent = '🔴';
                                msg.textContent = data.message;
                                msg.className = 'mt-1 text-xs text-red-500';
                            }
                        });
                }, 400);
            });
        });
    </script>
</x-guest-layout>
