{{--
    Thẻ kết quả "Các thẻ hoàn tiền" — dùng chung cho tab "Tra cứu MCC" và tab
    "Tra cứu danh mục".

    Tham số:
      - $matches    (bắt buộc) biểu thức Alpine trỏ tới mảng kết quả,
                     ví dụ "mccResult.matches" hoặc "categoryResult.matches".
      - $codesLabel (tuỳ chọn) nhãn dòng mã MCC liên quan (mặc định "Mã trong nhóm").

    Mỗi phần tử dùng biến vòng lặp `row` (và `index`) — hai tab render ở hai
    `<template x-if>` riêng nên không xung đột tên biến.

    CHỈ ĐỌC: mọi giá trị đến từ `CardPolicyLookupService`; không tự suy diễn.
--}}
@php($codesLabel = $codesLabel ?? 'Mã trong nhóm')

<template x-for="(row, index) in {{ $matches }}" :key="row.id">
    <li class="rounded-xl border border-gray-100 p-3 sm:p-4 space-y-2">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="font-medium text-gray-800 truncate" x-text="row.policy_name"></p>
                <p class="mt-1" x-show="row.relation !== 'group'">
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                          :class="ccRelationClass(row.relation)"
                          x-text="row.relation_label"></span>
                </p>
            </div>
        </div>

        {{-- Tóm tắt hoàn tiền lấy từ blueprint: bậc + tỷ lệ + điều kiện + hạn mức. --}}
        <div class="rounded-xl border p-3" :class="ccCashbackBoxClass(row.cashback)">
            <template x-if="row.cashback && row.cashback.status === 'excluded'">
                <div>
                    <p class="text-sm font-semibold text-rose-700">Không được hoàn tiền — MCC bị loại trừ</p>
                    <p class="text-xs text-rose-600 mt-0.5" x-show="row.note" x-text="row.note"></p>
                </div>
            </template>

            <template x-if="row.cashback && row.cashback.status === 'available'">
                <div class="space-y-2">
                    <p class="text-xs text-gray-500">
                        Danh mục:
                        <span class="font-semibold text-gray-800" x-text="row.cashback.category_name || row.category_name || '—'"></span>
                    </p>

                    {{-- Hoàn tiền CHÍNH THỨC: tỷ lệ cao nhất của các bậc chính thức +
                         điều kiện/trần của chính bậc đó (trích tài liệu gốc). --}}
                    <template x-if="row.cashback.highest">
                        <div class="rounded-lg border border-emerald-100 bg-emerald-50/70 px-3 py-2">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-bold text-emerald-700">
                                    Hoàn tiền chính thức
                                    <span x-text="ccBenefitRate(row.cashback.highest)"></span>
                                </p>
                                <p class="shrink-0 text-[11px] text-gray-500"
                                   x-show="ccBenefitCap(row.cashback.highest)"
                                   x-text="ccBenefitCap(row.cashback.highest)"></p>
                            </div>
                            <p class="mt-0.5 text-[11px] text-gray-600"
                               x-show="row.cashback.highest.note"
                               x-text="row.cashback.highest.note"></p>
                        </div>
                    </template>

                    {{-- ƯU ĐÃI BỔ SUNG (khuyến mại/kích hoạt/giao dịch đầu tiên): hiển
                         thị RIÊNG, nêu rõ KHÔNG tính vào tỷ lệ hoàn tiền chính thức. --}}
                    <template x-if="ccBonusTiers(row.cashback).length">
                        <div class="rounded-lg border border-violet-100 bg-violet-50/60 px-3 py-2 space-y-1.5">
                            <p class="text-xs font-semibold text-violet-700">Ưu đãi bổ sung</p>
                            <template x-for="(tier, bi) in ccBonusTiers(row.cashback)" :key="'bonus-' + row.id + '-' + bi">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs text-violet-800 font-semibold" x-text="ccBenefitRate(tier)"></p>
                                        <p class="text-[11px] text-gray-500 mt-0.5" x-show="tier.note" x-text="tier.note"></p>
                                        <p class="text-[11px] text-rose-600 mt-0.5" x-show="tier.exception" x-text="tier.exception"></p>
                                    </div>
                                    <p class="shrink-0 text-[11px] text-gray-500 text-right"
                                       x-show="ccBenefitCap(tier)"
                                       x-text="ccBenefitCap(tier)"></p>
                                </div>
                            </template>
                            <p class="text-[11px] text-violet-500 italic">Không tính vào tỷ lệ hoàn tiền chính thức.</p>
                        </div>
                    </template>

                    {{-- Bậc CHÍNH THỨC chi tiết: hiện khi KHÔNG có tỷ lệ cao nhất, hoặc khi
                         kết quả là SẢN PHẨM dùng chung nhiều hạng thẻ — để bảo toàn khác
                         biệt tỷ lệ theo hạng. --}}
                    <template x-if="! row.cashback.highest || row.product_name">
                        <div class="space-y-2">
                            <template x-for="(tier, ti) in ccOfficialTiers(row.cashback)" :key="'tier-' + row.id + '-' + ti">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs text-gray-700" x-text="tier.name"></p>
                                        <p class="text-[11px] text-gray-400" x-show="tier.has_rule"
                                           x-text="ccTierRange(tier.min_total_spend, tier.max_total_spend)"></p>
                                        <p class="text-[11px] text-gray-400" x-show="! tier.has_rule">Không áp dụng cho danh mục này</p>
                                        <p class="text-[11px] text-gray-500 mt-0.5" x-show="tier.note" x-text="tier.note"></p>
                                        <p class="text-[11px] text-rose-600 mt-0.5" x-show="tier.exception" x-text="tier.exception"></p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-sm font-semibold text-emerald-700" x-show="tier.has_rule"
                                           x-text="ccBenefitRate(tier)"></p>
                                        <p class="text-[11px] text-gray-400"
                                           x-show="tier.has_rule && ccBenefitCap(tier)"
                                           x-text="ccBenefitCap(tier)"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="! row.cashback || row.cashback.status === 'missing'">
                <p class="text-xs text-amber-700">Chưa có dữ liệu tỷ lệ hoàn tiền</p>
            </template>
        </div>

        <p class="text-xs text-gray-600" x-show="row.condition_note" x-text="row.condition_note"></p>
        <p class="text-xs text-rose-700" x-show="row.exclusion_reason" x-text="'Loại trừ: ' + row.exclusion_reason"></p>

        {{-- Chi tiết quy tắc nhóm MCC của dữ liệu import. --}}
        <p class="text-[11px] text-gray-500"
           x-show="row.match_type === 'group' && row.mcc_group"
           x-text="'Nhóm: ' + row.mcc_group"></p>
        <p class="text-[11px] text-gray-500"
           x-show="(row.mcc_codes || []).length"
           x-text="@js($codesLabel.': ') + (row.mcc_codes || []).join(', ')"></p>

        {{-- Phạm vi loại trừ theo hồ sơ MCC (không hiển thị tên hồ sơ/nhóm nội bộ). --}}
        <p class="text-[11px] text-rose-600"
           x-show="row.exclusion_scope === 'policy'"
           x-text="'Loại trừ theo hồ sơ MCC của chính sách'"></p>
        <p class="text-[11px] text-gray-500"
           x-show="row.note && (! row.cashback || row.cashback.status !== 'excluded')"
           x-text="row.note"></p>

        {{-- Quyền lợi theo mô tả (không có danh sách mã MCC): nêu rõ để không hiểu
             nhầm là đã kiểm tra đầy đủ. --}}
        <p class="text-[11px] text-amber-700" x-show="row.descriptive_note" x-text="row.descriptive_note"></p>

        {{-- File tài liệu của hồ sơ: mỗi tài liệu MỘT liên kết riêng, tải
             qua endpoint kiểm tra quyền (không lộ đường dẫn disk private).
             Không có file tải được ⇒ báo rõ, không tạo liên kết giả. --}}
        <div class="text-[11px] leading-relaxed" x-show="(row.documents || []).length">
            <template x-for="doc in row.documents" :key="'rowdoc-' + row.id + '-' + doc.id">
                <a :href="doc.url"
                   class="mr-2 font-medium underline text-blue-600 hover:text-blue-800">
                    <span>File liên quan</span><template x-if="(row.documents || []).length > 1"><span class="font-normal"> — <span x-text="doc.name"></span></span></template>
                </a>
            </template>
        </div>
        <p class="text-[11px] text-gray-400 italic"
           x-show="!(row.documents || []).length"
           x-cloak>Chưa có thông tin</p>
    </li>
</template>
