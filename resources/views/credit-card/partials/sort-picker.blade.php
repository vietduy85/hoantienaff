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
         * `init()` KHÔNG điều hướng nữa — xem giải thích bên dưới.
         */
        function ccSortPicker(storageKey, currentMode, knownModes) {
            return {
                mode: currentMode,

                init() {
                    // URL có `?sort=` ⇒ ý chí rõ ràng của lần truy cập này, kể cả khi
                    // nó khác lựa chọn đã lưu (người dùng muốn xem thử chế độ khác và
                    // không muốn nó bị ghi đè vĩnh viễn). Giữ nguyên hành vi cũ: không
                    // đụng vào lựa chọn đã nhớ.
                    const url = new URL(window.location.href);

                    if (url.searchParams.has('sort')) return;

                    let stored = null;

                    try {
                        stored = window.localStorage.getItem(storageKey);
                    } catch (e) {
                        // localStorage bị chặn ⇒ mất lựa chọn đã nhớ, không hỏng trang.
                        return;
                    }

                    if (!stored || !knownModes.includes(stored)) return;

                    if (stored === this.mode) return;

                    // KHÔNG `window.location.replace()` ở đây nữa.
                    //
                    // Server đọc chế độ ĐÃ NHỚ qua cookie CÙNG TÊN khoá
                    // `localStorage` (`credit-card-overview-sort`,
                    // `credit-card-management-sort`, `credit-card-statements-sort`)
                    // nên lần vào sau đã render đúng ngay ở request ĐẦU. Bản cũ chỉ
                    // lưu `localStorage` rồi tự `location.replace` để yêu cầu lần
                    // hai, nên mỗi lần vào trang đều tải 2 lần: 1 request dựng trang
                    // theo mặc định rồi 1 request nữa mới dựng lại theo lựa chọn đã
                    // nhớ. Đây là nguồn `GET /thetindung` thứ hai — KHÔNG phải tùy
                    // chọn hiển thị (`persistDisplay()` chỉ ghi `localStorage`, không
                    // điều hướng).
                    //
                    // Ghi cookie ở đây chỉ để chữa lành dữ liệu cũ: người dùng đã
                    // đổi chế độ từ trước khi có cookie thì `localStorage` có mà
                    // cookie thì chưa. Không ghi thì lượt này hiện mặc định (đúng
                    // với những gì server vừa render) và lượt sau mới đúng.
                    this.rememberMode(stored);
                },

                remember() {
                    this.rememberMode(this.mode);
                },

                /**
                 * Ghi lựa chọn vào CẢ HAI nơi: `localStorage` cho tương thích dữ
                 * liệu cũ, cookie để server đọc được ngay request đầu tiên.
                 *
                 * Tên cookie trùng tên khoá `localStorage` để mỗi màn tự lưu trữ
                 * độc lập — Tổng quan, Quản lý thẻ và Sao kê có ba chế độ riêng.
                 */
                rememberMode(mode) {
                    try {
                        window.localStorage.setItem(storageKey, mode);
                    } catch (e) {
                        // Không lưu được thì lần sau về mặc định, vẫn dùng được.
                    }

                    try {
                        const oneYear = 60 * 60 * 24 * 365;

                        document.cookie =
                            `${storageKey}=${encodeURIComponent(mode)}; path=/; max-age=${oneYear}; SameSite=Lax`;
                    } catch (e) {
                        // Cookie bị chặn ⇒ server về mặc định, không hỏng trang.
                    }
                },
            };
        }
    </script>
@endonce
