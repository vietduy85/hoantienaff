<section>
    <form method="post" action="{{ route('profile.update') }}" class="space-y-6">
        @csrf
        @method('patch')

        <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
            <div class="max-w-xl">
                <header class="mb-6">
                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Thông tin cá nhân') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Cập nhật thông tin cơ bản của bạn.') }}
                    </p>
                </header>

                <div class="space-y-4">
                    <div>
                        <x-input-label for="username" :value="__('Username')" />
                        <x-text-input id="username" type="text" class="mt-1 block w-full bg-gray-50 text-gray-500" :value="$user->username" readonly disabled />
                    </div>

                    <div>
                        <x-input-label for="name" :value="__('Tên hiển thị')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
                        <x-input-error class="mt-2" :messages="$errors->get('name')" />
                    </div>

                    <div>
                        <x-input-label for="email" :value="__('Email')" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
                        <x-input-error class="mt-2" :messages="$errors->get('email')" />

                        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                            <div>
                                <p class="text-sm mt-2 text-gray-800">
                                    {{ __('Your email address is unverified.') }}
                                    <button form="send-verification" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                        {{ __('Click here to re-send the verification email.') }}
                                    </button>
                                </p>

                                @if (session('status') === 'verification-link-sent')
                                    <p class="mt-2 font-medium text-sm text-green-600">
                                        {{ __('A new verification link has been sent to your email address.') }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div>
                        <x-input-label for="phone" :value="__('Số điện thoại')" />
                        <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" :value="old('phone', $user->phone)" autocomplete="tel" />
                        <x-input-error class="mt-2" :messages="$errors->get('phone')" />
                    </div>

                    <div>
                        <x-input-label for="zalo" :value="__('Zalo')" />
                        <x-text-input id="zalo" name="zalo" type="text" class="mt-1 block w-full" :value="old('zalo', $user->zalo)" />
                        <x-input-error class="mt-2" :messages="$errors->get('zalo')" />
                    </div>
                </div>
            </div>
        </div>

        <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
            <div class="max-w-xl">
                <header class="mb-6">
                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Thông tin tài khoản ngân hàng') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Thông tin này sẽ được sử dụng khi bạn yêu cầu rút tiền hoàn. Vui lòng nhập chính xác tên chủ tài khoản và số tài khoản ngân hàng.') }}
                    </p>
                </header>

                <div class="space-y-4">
                    <div>
                        <x-input-label for="bank_account_name" :value="__('Tên chủ tài khoản (KHÔNG DẤU)')" />
                        <x-text-input id="bank_account_name" name="bank_account_name" type="text" class="mt-1 block w-full" :value="old('bank_account_name', $user->bank_account_name)" placeholder="NGUYEN VAN A" x-data="{ normalizeBankAccountName(el) { el.value = el.value.trim().replace(/\s+/g, ' ').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[đĐ]/g, 'D').toUpperCase() } }" x-on:blur="normalizeBankAccountName($el)" />
                        <x-input-error class="mt-2" :messages="$errors->get('bank_account_name')" />
                    </div>

                    <div>
                        <x-input-label for="bank_account_number" :value="__('Số tài khoản')" />
                        <x-text-input id="bank_account_number" name="bank_account_number" type="text" class="mt-1 block w-full" :value="old('bank_account_number', $user->bank_account_number)" />
                        <x-input-error class="mt-2" :messages="$errors->get('bank_account_number')" />
                    </div>

                    @if($user->bank_name && !in_array($user->bank_name, config('banks.list')))
                        <div class="p-4 bg-yellow-50 border border-yellow-300 rounded-md text-sm text-yellow-800">
                            {{ __('Ngân hàng bạn đã lưu trước đây không còn nằm trong danh sách chuẩn. Vui lòng chọn lại ngân hàng để tiếp tục sử dụng chức năng rút tiền.') }}
                        </div>
                    @endif

                    <div>
                        <x-input-label for="bank_name" :value="__('Ngân hàng')" />
                        <select id="bank_name" name="bank_name" class="mt-1 block w-full">
                            <option value="">-- Chọn hoặc nhập tên ngân hàng --</option>
                            @foreach($banks as $bank)
                                <option value="{{ $bank }}" {{ old('bank_name', $user->bank_name) === $bank ? 'selected' : '' }}>{{ $bank }}</option>
                            @endforeach
                        </select>
                        <x-input-error class="mt-2" :messages="$errors->get('bank_name')" />
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Lưu') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Đã lưu.') }}</p>
            @endif
        </div>
    </form>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            new TomSelect('#bank_name', {
                placeholder: 'Chọn hoặc nhập tên ngân hàng...',
                allowEmptyOption: true,
                maxOptions: null,
                closeAfterSelect: true,
                persist: false,
                create: false,
                searchField: ['text'],
                selectOnTab: true,
            });
        });
    </script>
    @endpush
</section>
