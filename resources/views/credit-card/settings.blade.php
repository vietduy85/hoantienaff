{{-- /thetindung/cai-dat — tuỳ chọn hiển thị của module --}}
<x-credit-card.layout
    title="Cài đặt"
    subtitle="Tuỳ chọn hiển thị và thông báo của module"
    active="settings">

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-5">
        <div>
            <h4 class="font-semibold text-gray-800 text-sm">Tuỳ chọn module</h4>
            <p class="text-sm text-gray-500">Đổi đơn vị tiền để mọi màn hình của module hiển thị theo cách bạn muốn.</p>
        </div>

        {{-- ĐƠN VIỆN TIỀN + KÝ TỰ ĐẠI DIỆN
             - `savedMoneyUnit`/`savedMoneyUnitSymbol` = trạng thái ĐÃ LƯU trong DB
               (symbol đã resolve nên ô nhập luôn có chữ, kể cả DB NULL);
             - `moneyUnit`/`moneyUnitSymbol` = DRAFT đang chỉnh trên UI;
             - đổi radio CHỈ sửa draft (reset ký tự về default của đơn vị mới),
               KHÔNG gọi API, KHÔNG reload;
             - chỉ nút "Lưu" mới PATCH, thành công thì đồng bộ saved từ response
               và hiện "Đã lưu." — vẫn KHÔNG reload;
             - lỗi validation giữ nguyên draft để người dùng sửa.
             DB LUÔN LƯU VND — đây chỉ là cách đọc số trên màn hình. --}}
        <div x-data="{
            savedMoneyUnit: @js($moneyUnit),
            savedMoneyUnitSymbol: @js($moneyUnitSymbol),
            moneyUnit: @js($moneyUnit),
            moneyUnitSymbol: @js($moneyUnitSymbol),
            saving: false,
            saved: false,
            error: null,
            defaultSymbol(unit) {
                return unit === 'THOUSAND_VND' ? 'nghìn' : 'đ';
            },
            pickUnit(unit) {
                if (this.moneyUnit === unit) return;
                this.moneyUnit = unit;
                this.moneyUnitSymbol = this.defaultSymbol(unit);
                this.saved = false;
            },
            save() {
                if (this.saving) return;
                this.saving = true;
                this.saved = false;
                this.error = null;
                fetch('{{ route('credit-cards.api.settings.money-unit.update') }}', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') },
                    body: JSON.stringify({ money_unit: this.moneyUnit, money_unit_symbol: this.moneyUnitSymbol }),
                })
                    .then(async (response) => {
                        const payload = await response.json().catch(() => null);

                        if (!response.ok || !payload?.data) {
                            this.error = payload?.message
                                || Object.values(payload?.errors ?? {}).flat()[0]
                                || 'Không lưu được. Vui lòng thử lại.';
                            return;
                        }

                        this.savedMoneyUnit = payload.data.money_unit;
                        this.savedMoneyUnitSymbol = payload.data.money_unit_symbol;
                        this.moneyUnit = payload.data.money_unit;
                        this.moneyUnitSymbol = payload.data.money_unit_symbol;
                        this.saved = true;
                    })
                    .catch(() => { this.error = 'Không lưu được. Vui lòng thử lại.'; })
                    .finally(() => { this.saving = false; });
            },
        }">
            <p class="text-sm font-semibold text-gray-700">Đơn vị tiền</p>
            <p class="mt-1 text-xs text-gray-500">Số tiền được hiển thị theo đơn vị chọn — dữ liệu luôn được lưu theo Đồng. Ký tự đại diện có thể tuỳ chỉnh hoặc xoá hoàn toàn (để trống = không hiển thị hậu tố).</p>

            {{-- Ký tự đại diện CHỈ hiển thị cho đơn vị ĐANG CHỌN (x-if) — ô nhập
                 luôn bắt đầu từ giá trị resolved, không bao giờ trống trơn. --}}
            <div class="mt-3 space-y-2">
                <div class="rounded-xl border border-gray-200 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/50">
                    <label class="flex items-center gap-3 px-4 py-3 cursor-pointer">
                        <input type="radio" name="cc-money-unit" value="VND" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500"
                               :checked="moneyUnit === 'VND'" :disabled="saving"
                               @change="pickUnit('VND')">
                        <span class="text-sm font-medium text-gray-800">Đồng</span>
                    </label>
                    <template x-if="moneyUnit === 'VND'">
                        <div class="flex items-center gap-2 px-4 pb-3 -mt-1">
                            <label for="cc-money-symbol-vnd" class="text-xs text-gray-500">Ký tự đại diện:</label>
                            <input id="cc-money-symbol-vnd" type="text" maxlength="20" autocomplete="off"
                                   x-model="moneyUnitSymbol" :disabled="saving" @input="saved = false"
                                   class="h-9 w-36 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                    </template>
                </div>

                <div class="rounded-xl border border-gray-200 has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/50">
                    <label class="flex items-center gap-3 px-4 py-3 cursor-pointer">
                        <input type="radio" name="cc-money-unit" value="THOUSAND_VND" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500"
                               :checked="moneyUnit === 'THOUSAND_VND'" :disabled="saving"
                               @change="pickUnit('THOUSAND_VND')">
                        <span class="text-sm font-medium text-gray-800">Nghìn đồng</span>
                    </label>
                    <template x-if="moneyUnit === 'THOUSAND_VND'">
                        <div class="flex items-center gap-2 px-4 pb-3 -mt-1">
                            <label for="cc-money-symbol-nghin" class="text-xs text-gray-500">Ký tự đại diện:</label>
                            <input id="cc-money-symbol-nghin" type="text" maxlength="20" autocomplete="off"
                                   x-model="moneyUnitSymbol" :disabled="saving" @input="saved = false"
                                   class="h-9 w-36 rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        </div>
                    </template>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="button" @click="save" :disabled="saving"
                        class="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        x-text="saving ? 'Đang lưu…' : 'Lưu'">Lưu</button>
                <p class="text-xs text-emerald-600" x-show="saved && !saving" x-cloak>Đã lưu.</p>
                <p class="text-xs text-red-600" x-show="error" x-cloak x-text="error"></p>
            </div>
        </div>
    </div>

</x-credit-card.layout>
