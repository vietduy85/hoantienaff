{{--
    Danh sách bậc hoàn tiền cho danh mục đang chọn (dùng chung cho "Thẻ của tôi" và
    "Thẻ trên thị trường"). Chỉ gồm bậc đạt ngưỡng hiển thị (>= 3%). "row" là biến
    Alpine của thẻ trong vòng x-for; các bậc có `status` (đang áp dụng / chưa đạt
    điều kiện / không xác định) và `shortfall` (số tiền còn thiếu, nếu có).
--}}
<div class="mt-3 space-y-1.5" x-show="row.tiers && row.tiers.length" x-cloak>
    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
        Các bậc hoàn tiền cho danh mục này
    </p>
    <template x-for="tier in row.tiers" :key="tier.id">
        <div class="rounded-lg border px-3 py-2 text-xs"
             :class="tier.status === 'applied'
                 ? 'border-emerald-300 bg-emerald-50'
                 : 'border-gray-100 bg-gray-50/70'">
            <div class="flex items-center justify-between gap-2">
                <span class="font-semibold text-gray-700">
                    <span x-text="tier.label"></span>
                    <span x-show="tier.status === 'applied'" class="text-emerald-700"> · đang áp dụng</span>
                </span>
                <span class="font-bold shrink-0"
                      :class="tier.status === 'applied' ? 'text-emerald-700' : 'text-gray-500'"
                      x-text="ccMoneyVnd(tier.cashback) + ' (' + ccNumber(tier.rate) + '%)'"></span>
            </div>
            <p class="text-gray-500 mt-0.5" x-text="tier.condition"></p>
            <p class="mt-0.5"
               :class="tier.status === 'applied' ? 'text-emerald-700' : 'text-amber-700'"
               x-text="ccTierStatusLabel(tier.status, tier.shortfall)"></p>
            <p class="text-gray-500 mt-0.5"
               x-show="tier.caps && tier.caps.length"
               x-text="'Hạn mức chính sách: ' + ccCapDetailsLabel(tier.caps)"></p>
            <p class="text-gray-500 mt-0.5"
               x-show="tier.status !== 'applied'"
               x-text="'Tiền hoàn là mức tiềm năng cho khoản chi này, không phải khoản chắc chắn nhận được.'"></p>
        </div>
    </template>
</div>
