{{--
    Bộ chọn thứ tự thẻ — dùng chung cho Tổng quan, Quản lý thẻ và Sao kê.

    ---------------------------------------------------------------------------
    VÌ SAO LÀ PARTIAL RIÊNG
    ---------------------------------------------------------------------------
    Ba màn hình phải cho cùng một danh sách 4 chế độ, cùng cách ghi nhớ lựa chọn
    và cùng một khoá `localStorage`. Nếu mỗi màn tự viết, chúng sẽ lệch nhau ngay
    lần sửa đầu tiên — mà lệch ở đây là thứ chỉ lộ ra khi người dùng chuyển qua
    lại ba trang, tức loại lỗi khó tái hiện nhất.

    ---------------------------------------------------------------------------
    SERVER SẮP XẾP, KHÔNG PHẢI JAVASCRIPT
    ---------------------------------------------------------------------------
    Thứ tự do `CreditCardCardSortService` quyết định rồi render sẵn. Form này là
    GET nên người dùng tắt JS vẫn đổi được chế độ. `localStorage` chỉ nhớ LỰA CHỌN
    để lần sau vào trang không có `?sort=` thì lấy lại đúng chế độ đó — không lưu
    thứ tự thẻ, vì thứ tự là dữ liệu (kể cả chế độ `manual`).

    Props (truyền qua `@include`):
      $sortModes   array<string,string>  value => nhãn, lấy từ `CreditCardCardSortService::modes()`
      $sortMode    string                 chế độ đang áp dụng (đã normalize ở controller)
      $sortAction  string                 URL trang (GET, không kèm query cũ)
      $sortStorageKey string              khoá localStorage riêng của màn này
--}}
<div class="flex flex-wrap items-center justify-between gap-3 px-4 sm:px-5 py-3 bg-white rounded-2xl shadow-sm border border-gray-100"
     data-testid="card-sort-picker"
     x-data="ccSortPicker(@js($sortStorageKey), @js($sortMode), @js(array_keys($sortModes)))">

    <form method="GET" action="{{ $sortAction }}" class="flex flex-wrap items-center gap-2" x-ref="form">
        <label for="cc-card-sort" class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
            Sắp xếp thẻ
        </label>

        <select id="cc-card-sort" name="sort" x-model="mode"
                @change="remember(); $el.form.submit()"
                data-testid="card-sort-mode"
                class="h-9 pl-3 pr-8 text-sm rounded-xl border-gray-200 bg-white focus:border-emerald-500 focus:ring-emerald-500">
            @foreach ($sortModes as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        {{-- Nút vẫn cần cho trường hợp không JS; có JS thì select đã tự gửi. --}}
        <button type="submit"
                class="h-9 px-3 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-semibold rounded-xl transition-colors">
            Áp dụng
        </button>
    </form>

    <p class="text-xs text-gray-500">
        @if ($sortMode === 'manual')
            Đang dùng thứ tự bạn tự sắp ở Quản lý thẻ.
        @else
            Thứ tự chỉ phục vụ xem trước — không thay đổi thứ tự bạn đã sắp.
        @endif
    </p>
</div>

@once
    <script>
        /**
         * Nhớ chế độ sắp xếp của màn hiện tại.
         *
         * `init()` chỉ hành động khi URL KHÔNG mang `?sort=` — tức người dùng vào
         * trang bằng menu/sidebar chứ không phải do vừa chọn. Có `?sort=` rồi thì
         * URL là ý chí rõ ràng nhất, kể cả khi nó khác lựa chọn đã lưu (người dùng
         * muốn xem thử chế độ khác và không muốn nó bị ghi đè vĩnh viễn).
         */
        function ccSortPicker(storageKey, currentMode, knownModes) {
            return {
                mode: currentMode,

                init() {
                    let stored = null;

                    try {
                        stored = window.localStorage.getItem(storageKey);
                    } catch (e) {
                        // localStorage bị chặn ⇒ mất lựa chọn đã nhớ, không hỏng trang.
                        return;
                    }

                    if (!stored || stored === this.mode) return;
                    if (!knownModes.includes(stored)) return;

                    const url = new URL(window.location.href);

                    if (url.searchParams.has('sort')) return;

                    url.searchParams.set('sort', stored);
                    window.location.replace(url.toString());
                },

                remember() {
                    try {
                        window.localStorage.setItem(storageKey, this.mode);
                    } catch (e) {
                        // Không lưu được thì lần sau về mặc định, vẫn dùng được.
                    }
                },
            };
        }
    </script>
@endonce
