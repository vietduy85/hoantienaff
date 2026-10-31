<section class="space-y-6">
    {{-- Form 1: STK ngân hàng — việc user hay làm nhất, đặt lên đầu và lưu riêng --}}
    <form method="post" action="{{ route('profile.update') }}" data-testid="bank-form"
          class="p-4 sm:p-8 bg-white shadow sm:rounded-lg border-t-4 border-emerald-500">
        @csrf
        @method('patch')
        <input type="hidden" name="section" value="bank">

        <div class="max-w-xl">
            <header class="mb-5">
                <h2 class="text-lg font-semibold text-gray-900">🏦 Tài khoản nhận tiền hoàn</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Dùng khi bạn rút tiền hoàn. Vui lòng nhập chính xác tên chủ tài khoản và số tài khoản.
                </p>
            </header>

            <div class="space-y-4">
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

                <div>
                    <x-input-label for="bank_account_number" :value="__('Số tài khoản')" />
                    <x-text-input id="bank_account_number" name="bank_account_number" type="text" inputmode="numeric" class="mt-1 block w-full" :value="old('bank_account_number', $user->bank_account_number)" />
                    <x-input-error class="mt-2" :messages="$errors->get('bank_account_number')" />
                </div>

                <div>
                    <x-input-label for="bank_account_name" :value="__('Tên chủ tài khoản (KHÔNG DẤU)')" />
                    <x-text-input id="bank_account_name" name="bank_account_name" type="text" class="mt-1 block w-full" :value="old('bank_account_name', $user->bank_account_name)" placeholder="NGUYEN VAN A" x-data="{ normalizeBankAccountName(el) { el.value = el.value.trim().replace(/\s+/g, ' ').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[đĐ]/g, 'D').toUpperCase() } }" x-on:blur="normalizeBankAccountName($el)" />
                    <x-input-error class="mt-2" :messages="$errors->get('bank_account_name')" />
                </div>
            </div>

            <div class="mt-6 flex items-center gap-4">
                <button type="submit" class="inline-flex items-center justify-center w-full sm:w-auto px-6 py-3 rounded-lg bg-emerald-600 text-white font-semibold hover:bg-emerald-700 transition">
                    Lưu tài khoản ngân hàng
                </button>
                @if (session('status') === 'bank-updated')
                    <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2500)"
                       class="text-sm font-medium text-emerald-600 whitespace-nowrap">✓ Đã lưu STK</p>
                @endif
            </div>
        </div>
    </form>

    {{-- Form 2: Thông tin cá nhân — khóa sẵn, phải bấm "Sửa" mới chỉnh được --}}
    @php($infoHasErrors = $errors->hasAny(['name', 'email', 'phone', 'zalo']))
    <div x-data="{ editing: {{ $infoHasErrors ? 'true' : 'false' }} }" data-testid="info-card"
         class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
        <div class="max-w-xl">
            <header class="mb-5 flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">👤 Thông tin cá nhân</h2>
                    <p class="mt-1 text-sm text-gray-600">Không cần sửa khi cập nhật STK ngân hàng.</p>
                </div>
                <button type="button" x-show="!editing" x-on:click="editing = true"
                        class="shrink-0 px-3 py-1.5 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    ✏️ Sửa
                </button>
            </header>

            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 2500)"
                   class="mb-4 text-sm font-medium text-emerald-600">✓ Đã lưu thông tin cá nhân</p>
            @endif

            {{-- Chế độ xem --}}
            <dl x-show="!editing" class="divide-y divide-gray-100 text-sm">
                @foreach ([
                    'Username' => $user->username,
                    'Tên hiển thị' => $user->name,
                    'Email' => $user->email,
                    'Số điện thoại' => $user->phone,
                    'Zalo' => $user->zalo,
                ] as $label => $value)
                    <div class="flex justify-between gap-4 py-2.5">
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="text-right font-medium text-gray-900 break-all">{{ $value ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            {{-- Chế độ sửa --}}
            <form x-show="editing" x-cloak method="post" action="{{ route('profile.update') }}" class="space-y-4">
                @csrf
                @method('patch')
                <input type="hidden" name="section" value="info">

                <div>
                    <x-input-label for="username" :value="__('Username')" />
                    <x-text-input id="username" type="text" class="mt-1 block w-full bg-gray-50 text-gray-500" :value="$user->username" readonly disabled />
                </div>

                <div>
                    <x-input-label for="name" :value="__('Tên hiển thị')" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autocomplete="name" />
                    <x-input-error class="mt-2" :messages="$errors->get('name')" />
                </div>

                <div>
                    <x-input-label for="email" :value="__('Email')" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" autocomplete="username" />
                    <x-input-error class="mt-2" :messages="$errors->get('email')" />
                    <p class="mt-1 text-xs text-gray-400">Email không bắt buộc; dùng để khôi phục mật khẩu và nhận thông báo nếu được hỗ trợ.</p>

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

                <div class="flex items-center gap-3 pt-2">
                    <x-primary-button>{{ __('Lưu thông tin cá nhân') }}</x-primary-button>
                    <button type="button" x-on:click="editing = false" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Hủy</button>
                </div>
            </form>
        </div>
    </div>

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
