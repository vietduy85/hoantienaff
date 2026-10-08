{{--
    Ô nhập số tiền theo đơn vị của user — xem `App\View\Components\CreditCard\MoneyInput`.

    Quy trình: `:value` hiện `ccMoneyToDisplay(expr)` (VND → chuỗi hiển thị) trừ
    khi user đang gõ (`ccRaw`); `@input` vừa giữ chuỗi đang gõ vừa ghi
    `expr = ccMoneyParseInput(...)` (chuỗi hiển thị → VND); `@blur` thả `ccRaw`
    để ô chuyển về hiển thị chuẩn. STATE VÀ PAYLOAD LUÔN LÀ VND.
--}}
@php
    $userId = $userId ?? null;
    $example = $example ?? null;
    $placeholder = $placeholder
        ?? $attributes->get('placeholder')
        ?? ($example === null
            ? null
            : 'Ví dụ: '.\App\Support\CreditCard\CreditCardMoneyFormatter::number($example, $userId));

    // Suffix rỗng (user bỏ ký tự) ⇒ KHÔNG render hậu tố và KHÔNG giữ padding
    // pr-10 cho khoảng trống không còn dùng — ô input chiếm hết chiều rộng.
    $hasSuffix = $suffix !== '';
    $inputClass = trim(($attributes->get('class') ?? '').($hasSuffix ? ' pr-10' : ''));
    $attributes = $attributes->except(['class', 'placeholder']);
@endphp
<div x-data="{ ccRaw: null }" class="relative">
    <input type="text" inputmode="decimal" autocomplete="off" min="0"
           :value="ccRaw !== null ? ccRaw : ccMoneyToDisplay({{ $expr }})"
           @input="ccRaw = $event.target.value; {{ $expr }} = ccMoneyParseInput(ccRaw)"
           @blur="ccRaw = null"
           @if ($idExpr !== null) x-bind:id="{{ $idExpr }}" @endif
           @if ($disabledExpr !== null) x-bind:disabled="{{ $disabledExpr }}" @endif
           @if ($placeholder !== null) placeholder="{{ $placeholder }}" @endif
           class="{{ $inputClass }}"
           {{ $attributes }}>
    @if ($hasSuffix)
    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-gray-500">{{ $suffix }}</span>
    @endif
</div>