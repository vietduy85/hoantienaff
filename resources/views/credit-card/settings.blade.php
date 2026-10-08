{{-- /thetindung/cai-dat — tuỳ chọn hiển thị của module --}}
<x-credit-card.layout
    title="Cài đặt"
    subtitle="Tuỳ chọn hiển thị và thông báo của module"
    active="settings">

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-5">
        <div>
            <h4 class="font-semibold text-gray-800 text-sm">Tuỳ chọn module</h4>
            <p class="text-sm text-gray-500">Đổi đơn vị số tiền để mọi màn hình của module hiển thị theo cách bạn muốn.</p>
        </div>

        {{-- Đơn vị số tiền: chọn là lưu ngay (auto-save), sau đó tải lại để
             mọi số tiền render lại theo đơn vị mới. DB luôn lưu VND — đây chỉ
             là cách đọc số trên màn hình. --}}
        <div x-data="{
            moneyUnit: @js($moneyUnit),
            saving: false,
            save(unit) {
                if (this.saving || unit === this.moneyUnit) return;
                this.saving = true;
                fetch('{{ route('credit-cards.api.settings.money-unit.update') }}', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') },
                    body: JSON.stringify({ money_unit: unit }),
                })
                    .then(response => response.json())
                    .then(payload => {
                        this.saving = false;
                        if (payload?.data) this.moneyUnit = payload.data.money_unit;
                        window.location.reload();
                    })
                    .catch(() => { this.saving = false; });
            },
        }">
            <p class="text-sm font-semibold text-gray-700">Đơn vị số tiền</p>
            <p class="mt-1 text-xs text-gray-500">Số tiền được hiển thị theo đơn vị chọn — dữ liệu luôn được lưu theo Đồng.</p>

            <div class="mt-3 space-y-2">
                <label class="flex items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/50">
                    <input type="radio" name="cc-money-unit" value="VND" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500"
                           :checked="moneyUnit === 'VND'" :disabled="saving"
                           @change="save('VND')">
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Đồng (đ)</span>
                        <span class="block text-sm text-gray-500">Ví dụ: 5.000.000 đ · 250.000 đ</span>
                    </span>
                </label>
                <label class="flex items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/50">
                    <input type="radio" name="cc-money-unit" value="THOUSAND_VND" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500"
                           :checked="moneyUnit === 'THOUSAND_VND'" :disabled="saving"
                           @change="save('THOUSAND_VND')">
                    <span>
                        <span class="block text-sm font-medium text-gray-800">Nghìn đồng</span>
                        <span class="block text-sm text-gray-500">Ví dụ: 5.000 nghìn · 250 nghìn</span>
                    </span>
                </label>
            </div>

            <p class="mt-3 text-xs text-gray-400" x-show="saving" x-cloak>Đang lưu…</p>
        </div>
    </div>

</x-credit-card.layout>