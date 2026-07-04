# HoanTien.xyz Design System

> **Phiên bản:** 1.0  
> **Cập nhật:** 2026-07-04  
> **Áp dụng cho:** Toàn bộ giao diện HoanTien.xyz

---

## 1. Mục tiêu

### Định hướng sản phẩm

| Tiêu chí | Giá trị |
|---|---|
| Platform | Web App (PWA-ready) |
| Ngôn ngữ | Tiếng Việt |
| Loại hình | Affiliate Cashback / Tài chính |
| Phong cách | Đơn giản, hiện đại, dễ dùng |
| UX priority | Thao tác một tay |

### Thiết bị ưu tiên (theo thứ tự)

1. **iPhone 12** (390px width) — chuẩn thiết kế chính
2. **Android Chrome** — kiểm tra rendering tiếng Việt, font-weight, dấu
3. **Safari iPhone** — kiểm tra font-smoothing, safe-area

Desktop chỉ là thiết bị phụ. Layout desktop = mở rộng từ mobile, không tạo giao diện riêng.

### Nguyên tắc Mobile First

- Media query mặc định = mobile. `sm:` = desktop.
- Không scroll ngang.
- Button đủ lớn để bấm (tối thiểu 44px height).
- Card vừa màn hình (max-width: 480px trên mobile, centered trên desktop).
- Không yêu cầu zoom.

---

## 2. Font

### Font chính

```css
font-family: 'Be Vietnam Pro', system-ui, -apple-system, BlinkMacSystemFont,
             'Segoe UI', Roboto, Arial, sans-serif;
```

| Thuộc tính | Giá trị |
|---|---|
| Font chính | **Be Vietnam Pro** |
| Nguồn | Bunny Fonts (`fonts.bunny.net`) |
| Weights | 400, 500, 600, 700, 800 |
| display | `swap` |
| Subset | Vietnamese (có sẵn) |

**Lý do chọn Be Vietnam Pro:**
- Tối ưu cho tiếng Việt (glyph đầy đủ, hinting tốt).
- Render đẹp trên Android Chrome (không vỡ dấu).
- Render đẹp trên Safari iPhone.
- Hỗ trợ đầy đủ font-weight 400–800.
- Thiết kế hiện đại, phù hợp Web App tài chính.

### Font loading

Chỉ load MỘT font duy nhất trong toàn bộ website:

```html
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=be-vietnam-pro:400,500,600,700,800&display=swap" rel="stylesheet" />
```

Cả landing page (`welcome.blade.php`) và app (`layouts/app.blade.php`, `layouts/guest.blade.php`) đều dùng chung link này.

### font-mono

Chỉ dùng cho dữ liệu kỹ thuật:

```css
font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas,
             'Liberation Mono', 'Courier New', monospace;
```

**Được phép dùng font-mono cho:**
- Mã đơn hàng (order ID)
- Affiliate URL
- Số tài khoản ngân hàng
- Mã giao dịch (running_no)
- API Token
- Debug / Code

**Không dùng font-mono cho:**
- Button
- Body
- Heading
- Badge
- Status
- Navigation

### Fallback

```
font-sans → 'Be Vietnam Pro', system-ui, -apple-system, BlinkMacSystemFont,
            'Segoe UI', Roboto, Arial, sans-serif

font-mono → ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas,
            'Liberation Mono', 'Courier New', monospace
```

### Triển khai trong Tailwind

```js
// tailwind.config.js
fontFamily: {
    sans: ['"Be Vietnam Pro"', 'system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'Arial', 'sans-serif'],
    mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', '"Liberation Mono"', '"Courier New"', 'monospace'],
},
```

---

## 3. Typography

### Quy tắc chung

- Mobile-first: mặc định = mobile size, `sm:` = desktop.
- Không dùng `tracking-wide`, `tracking-wider`, `tracking-widest` cho tiếng Việt (gây giãn chữ xấu).
- Không dùng `text-[...]` arbitrary values cho font-size.
- Không dùng `leading-snug`, `leading-loose`.
- `leading-tight` chỉ dùng cho heading, không dùng cho nội dung dài.

### Bảng Typography chuẩn

| Element | Tailwind classes | Size | Weight | Line-height | Letter-spacing |
|---|---|---|---|---|---|
| **Hero Title** | `font-extrabold text-3xl sm:text-4xl leading-tight tracking-tight` | 30px / 36px | 800 | 1.25 | -0.01em |
| **Page Title** | `font-bold text-2xl leading-tight tracking-tight` | 24px | 700 | 1.25 | -0.01em |
| **Section Title** | `font-bold text-xl leading-tight tracking-tight` | 20px | 700 | 1.25 | -0.01em |
| **Card Title** | `font-semibold text-lg` | 18px | 600 | 1.4 | normal |
| **Body** | `text-sm` | 14px | 400 | 1.5 | normal |
| **Body small** | `text-xs` | 12px | 400 | 1.5 | normal |
| **Caption** | `text-xs` | 12px | 400 | 1.4 | normal |
| **Label** | `text-sm font-medium` | 14px | 500 | normal | normal |
| **Navigation** | `text-sm font-medium leading-5` | 14px | 500 | 1.25 | normal |
| **Dropdown** | `text-sm leading-5 font-medium` | 14px | 500 | 1.25 | normal |
| **Button** | `text-sm font-semibold` | 14px | 600 | normal | normal |
| **Badge** | `text-xs font-medium` | 12px | 500 | normal | normal |
| **Price / Cashback** | `font-bold text-xl tracking-tight` | 20px | 700 | 1.2 | -0.01em |
| **Large price** | `font-bold text-3xl tracking-tight` | 30px | 700 | 1.1 | -0.01em |
| **Wallet Balance** | `font-extrabold text-3xl tracking-tight` | 30px | 800 | 1.1 | -0.02em |
| **Input text** | `text-sm` | 14px | 400 | normal | normal |
| **Placeholder** | `text-sm` | 14px | 400 | normal | normal |
| **Toast / Status message** | `text-sm` | 14px | 400 | normal | normal |

### Giải thích

- **`tracking-tight` trên heading/price:** Be Vietnam Pro có letter-spacing mặc định hơi rộng, `tracking-tight` giúp chữ dày dặn hơn, đặc biệt trên Android.
- **`font-medium` cho nav/dropdown/label:** Weight 500 giúp chữ rõ hơn trên màn hình điện thoại so với 400.
- **Body = 400:** Không cần class font-weight riêng (Tailwind mặc định là 400).
- **Không `tracking-wide` cho tiếng Việt:** Dấu thanh và dấu phụ sẽ bị giãn quá xa, render xấu trên Android.

### Android Rendering

- `antialiased` class trên `<body>` (Tailwind mặc định).
- Không set `text-rendering` thủ công (mặc định của trình duyệt là tối ưu cho Be Vietnam Pro).
- Be Vietnam Pro được thiết kế để render đẹp với `-webkit-font-smoothing: antialiased`.

---

## 4. Màu sắc

### Bảng màu chuẩn

```css
/* Primary - Emerald */
--emerald-50:  #ecfdf5;
--emerald-100: #d1fae5;
--emerald-200: #a7f3d0;
--emerald-400: #34d399;
--emerald-500: #10b981;
--emerald-600: #059669;
--emerald-700: #047857;
--emerald-800: #065f46;

/* Warning - Amber */
--amber-50:   #fffbeb;
--amber-100:  #fef3c7;
--amber-200:  #fde68a;
--amber-500:  #f59e0b;
--amber-700:  #b45309;
--amber-800:  #92400e;

/* Info - Blue */
--blue-50:    #eff6ff;
--blue-100:   #dbeafe;
--blue-200:   #bfdbfe;
--blue-500:   #3b82f6;
--blue-600:   #2563eb;
--blue-700:   #1d4ed8;

/* Danger - Red */
--red-50:     #fef2f2;
--red-100:    #fee2e2;
--red-200:    #fecaca;
--red-500:    #ef4444;
--red-600:    #dc2626;
--red-700:    #b91c1c;

/* Neutral - Gray */
--gray-50:    #f9fafb;
--gray-100:   #f3f4f6;
--gray-200:   #e5e7eb;
--gray-300:   #d1d5db;
--gray-400:   #9ca3af;
--gray-500:   #6b7280;
--gray-600:   #4b5563;
--gray-700:   #374151;
--gray-800:   #1f2937;
--gray-900:   #111827;
--white:      #ffffff;
```

### Ngữ nghĩa màu sắc

| Màu | Ý nghĩa | Dùng cho |
|---|---|---|
| **Emerald** | Primary / Thành công / Cashback | Button chính, số tiền dương, link thành công, badge "Hoàn thành" |
| **Amber** | Chờ xử lý / Pending | Badge "Đang chờ", số tiền pending, cảnh báo |
| **Blue** | Thông tin / Đã thanh toán | Badge "Đã thanh toán", giá trị thông tin |
| **Red** | Lỗi / Hủy | Badge "Đã hủy", error message, danger button |
| **Gray** | Nội dung phụ | Caption, placeholder, mô tả, secondary text, border |

### Quy tắc

- Không tự ý thêm màu mới ngoài bảng trên.
- Không dùng màu tùy chỉnh (ví dụ `text-[#abc123]`).
- Background cards luôn dùng `bg-white`.
- Background page luôn dùng `bg-gray-100`.
- Badges dùng nền tint 50, text tint 700 (ví dụ `bg-emerald-50 text-emerald-700`).

---

## 5. Button

### Chuẩn chung

| Thuộc tính | Giá trị |
|---|---|
| Chiều cao | `h-12` (48px) — tối thiểu 44px để bấm bằng ngón tay |
| Border radius | `rounded-xl` (12px) |
| Font | `font-semibold text-sm` (600, 14px) |
| Uppercase | Giữ `uppercase` (tuỳ chọn, không bắt buộc) |
| Letter-spacing | `tracking-normal` |
| Disabled | `opacity-60 cursor-not-allowed` |
| Loading | Spinner + text "Đang xử lý..." |

### Primary Button

```blade
<button class="w-full h-12 bg-emerald-500 hover:bg-emerald-600 active:bg-emerald-700
               text-white font-semibold text-sm rounded-xl transition-colors shadow-sm
               disabled:opacity-60 disabled:cursor-not-allowed">
    {{ $slot }}
</button>
```

| State | Background | Text |
|---|---|---|
| Default | `bg-emerald-500` | `text-white` |
| Hover | `bg-emerald-600` | `text-white` |
| Active | `bg-emerald-700` | `text-white` |
| Disabled | `opacity-60` của default | `text-white` |

### Secondary Button

```blade
<button class="w-full h-12 bg-white hover:bg-gray-50 active:bg-gray-100
               text-gray-700 font-semibold text-sm rounded-xl
               border-2 border-gray-200 transition-colors
               disabled:opacity-60 disabled:cursor-not-allowed">
    {{ $slot }}
</button>
```

| State | Background | Border | Text |
|---|---|---|---|
| Default | `bg-white` | `border-gray-200` | `text-gray-700` |
| Hover | `bg-gray-50` | `border-gray-200` | `text-gray-700` |
| Active | `bg-gray-100` | `border-gray-200` | `text-gray-700` |

### Danger Button

```blade
<button class="w-full h-12 bg-red-600 hover:bg-red-500 active:bg-red-700
               text-white font-semibold text-sm rounded-xl transition-colors
               disabled:opacity-60 disabled:cursor-not-allowed">
    {{ $slot }}
</button>
```

### Chip Button (Filter / Amount)

```blade
<button class="h-10 px-4 rounded-xl bg-gray-100 hover:bg-gray-200
               active:bg-gray-300 text-sm font-medium text-gray-700 transition-colors">
    100.000
</button>
```

### Quy tắc icon trong button

- Icon + text: `gap-2`, icon size `w-5 h-5`.
- Icon only: đảm bảo `min-h-[44px] min-w-[44px]` để bấm được.

---

## 6. Card

### Chuẩn Card

| Thuộc tính | Giá trị |
|---|---|
| Background | `bg-white` |
| Border radius | `rounded-2xl` (16px) |
| Shadow | `shadow-sm` |
| Border | `border border-gray-100` |
| Padding | `p-5` (20px) |
| Khoảng cách giữa các card | `space-y-4` |

```blade
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
    <!-- Card content -->
</div>
```

### Card nhỏ / Dạng list

| Thuộc tính | Giá trị |
|---|---|
| Padding | `px-4 py-2.5` |
| Border radius | `rounded-xl` (12px) |
| Shadow | `shadow-sm` |

### Empty state

```blade
<div class="bg-white rounded-2xl border border-dashed border-gray-200 p-8 text-center space-y-2">
    <span class="text-3xl block">📋</span>
    <p class="text-sm text-gray-400">Chưa có dữ liệu</p>
</div>
```

---

## 7. Form

### Input

```blade
<input type="text"
       class="w-full h-12 px-4 rounded-xl border border-gray-200 bg-white text-sm
              focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100
              transition-shadow shadow-sm placeholder:text-gray-400">
```

| Thuộc tính | Giá trị |
|---|---|
| Chiều cao | `h-12` (48px) |
| Border | `border border-gray-200` |
| Border radius | `rounded-xl` (12px) |
| Font | `text-sm` (400, 14px) |
| Placeholder | `placeholder:text-gray-400` |
| Focus | `focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100` |

### Label

```blade
<label class="block text-sm font-medium text-gray-700 mb-1.5">
    {{ $slot }}
</label>
```

### Error

```blade
<ul class="text-sm text-red-600 space-y-1">
    <li>{{ $message }}</li>
</ul>
```

### Select / Textarea

Dùng chung style với Input (border, radius, font, focus).

### Checkbox / Radio

Dùng mặc định của `@tailwindcss/forms` plugin.

---

## 8. Badge

### Bảng Badge chuẩn

| Loại | Background | Text | Border | Ví dụ |
|---|---|---|---|---|
| Pending | `bg-yellow-50` | `text-yellow-700` | `border-yellow-200` | Đang chờ |
| Processing | `bg-blue-50` | `text-blue-700` | `border-blue-200` | Đang xử lý |
| Completed | `bg-emerald-50` | `text-emerald-700` | `border-emerald-200` | Hoàn thành |
| Paid | `bg-blue-50` | `text-blue-700` | `border-blue-200` | Đã thanh toán |
| Rejected | `bg-red-50` | `text-red-700` | `border-red-200` | Từ chối |
| Failed | `bg-red-50` | `text-red-700` | `border-red-200` | Thất bại |
| Locked | `bg-gray-50` | `text-gray-600` | `border-gray-200` | Đã khóa |

### Cấu trúc badge

```blade
<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border">
    {{ $label }}
</span>
```

| Thuộc tính | Giá trị |
|---|---|
| Padding | `px-2.5 py-1` |
| Border radius | `rounded-full` |
| Font | `text-xs font-medium` |
| Border | `border` |

---

## 9. Icon

### Quy tắc

- **Ưu tiên Heroicons** (dạng SVG inline).
- Emoji chỉ dùng cho mục đích đơn giản hoá (icon cảm xúc, trạng thái).
- Kích thước icon: `w-5 h-5` (20px) cho inline với text, `w-6 h-6` (24px) cho standalone.
- Khoảng cách icon với text: `gap-1.5` hoặc `gap-2`.
- Không lạm dụng emoji cho chức năng chính.

### Ví dụ

```blade
{{-- Heroicon inline với text --}}
<button class="flex items-center gap-2">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
    </svg>
    <span>Thêm mới</span>
</button>

{{-- Emoji cho trạng thái --}}
<span class="text-lg">📋</span>
```

---

## 10. Khoảng cách

### Chuẩn spacing

| Ngữ cảnh | Tailwind | Giá trị |
|---|---|---|
| Page padding mobile | `px-4` | 16px |
| Page padding desktop | `sm:px-6 lg:px-8` | 24px / 32px |
| Card padding | `p-5` | 20px |
| Card spacing | `space-y-4` | 16px |
| Section spacing | `space-y-6` | 24px |
| Element spacing trong card | `space-y-3` | 12px |
| Input spacing | `space-y-1.5` | 6px |
| Gap giữa icon và text | `gap-2` | 8px |
| Gap filter chips | `gap-2` | 8px |
| Max width mobile | `max-w-lg` | 512px |

### Section spacing

```blade
{{-- Page wrapper --}}
<div class="py-6 px-4 max-w-lg mx-auto space-y-4">
    {{-- Cards ở đây --}}
</div>
```

---

## 11. Mobile Layout

### Breakpoints

| Breakpoint | Width | Target |
|---|---|---|
| Mặc định | < 640px | iPhone 12 (390px), Android |
| `sm:` | ≥ 640px | Desktop, tablet ngang |

### Container mobile

```blade
<div class="py-6 px-4 max-w-lg mx-auto space-y-4">
```

- `max-w-lg` (512px) = vừa màn hình iPhone 12 (390px) + padding 2 bên.
- `mx-auto` = căn giữa trên desktop.
- `space-y-4` = khoảng cách giữa các card.

### Nguyên tắc

- Không scroll ngang — mọi nội dung phải fit trong viewport.
- Touch target tối thiểu 44×44px (Apple HIG).
- Bottom sheet: `max-h-[75vh] overflow-y-auto` để vừa kéo lên mà không che hết màn hình.

### Bottom Sheet

```blade
<div x-cloak
     x-show="open"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-y-full"
     x-transition:enter-end="translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-y-0"
     x-transition:leave-end="translate-y-full"
     class="fixed inset-0 z-50 flex items-end">
    <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
    <div class="relative w-full bg-white rounded-t-2xl shadow-2xl px-5 pt-6 pb-8 space-y-5 max-h-[75vh] overflow-y-auto">
        <!-- Sheet content -->
    </div>
</div>
```

---

## 12. Desktop

- Giữ nguyên layout mobile khi ≥ 640px.
- Mở rộng max-width từ `max-w-lg` lên `max-w-7xl` (1280px) cho các trang có nhiều nội dung (guide index, dashboard grid).
- Bottom sheet vẫn giữ nguyên trên desktop (không chuyển thành modal).

---

## 13. Quy tắc UX

### Luồng chính — tối đa 2 bước

| Tính năng | Bước 1 | Bước 2 |
|---|---|---|
| Tạo link | Dán URL → | Nhấn "Tạo Link Ngay" |
| Xem đơn | Mở trang đơn hàng → | Lọc / tìm kiếm |
| Xem ví | Mở trang ví → | Xem số dư + lịch sử |
| Rút tiền | Mở bottom sheet → | Nhập số + Xác nhận |

### Touch

- Tất cả button/card clickable tối thiểu 44px.
- Link trong card có `min-h-[44px]` nếu là touch target riêng.
- Filter chips: `h-9` (36px) là ngoại lệ cho filter ngang, nhưng vẫn đủ lớn để bấm.

---

## 14. Quy tắc giao diện

**Không tự ý thay đổi:**

- Font (`Be Vietnam Pro`, `font-mono`)
- Màu sắc (emerald / amber / blue / red / gray)
- Spacing (`p-5`, `space-y-4`, `px-4`, `gap-2`, v.v.)
- Typography hierarchy (weight / size / line-height / letter-spacing)
- Border radius (`rounded-2xl` cho card, `rounded-xl` cho button/input, `rounded-full` cho badge)
- Shadow (`shadow-sm` cho card)

**Nếu cần thay đổi:** phải có yêu cầu rõ ràng, không tự ý deviate.

---

## 15. Quy tắc cho OpenCode

Mọi thay đổi UI về sau phải:

1. Tuân thủ file `docs/ui/design-system.md`.
2. Không phá vỡ Design System.
3. Không tạo component có style khác hệ thống.
4. Nếu cần tạo component mới: dùng lại màu sắc, typography, spacing, button từ tài liệu này.
5. Nếu cần màu / kích thước mới: thêm vào tài liệu trước, hỏi ý kiến, sau đó mới code.

---

## Phụ lục: Tóm tắt Tailwind classes thường dùng

```blade
{{-- Page --}}
<div class="py-6 px-4 max-w-lg mx-auto space-y-4">

{{-- Card --}}
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">

{{-- Card title --}}
<h3 class="font-semibold text-lg">

{{-- Section title --}}
<h2 class="font-bold text-xl tracking-tight">

{{-- Body text --}}
<p class="text-sm text-gray-500">

{{-- Caption --}}
<p class="text-xs text-gray-400">

{{-- Label --}}
<label class="text-sm font-medium text-gray-700">

{{-- Input --}}
<input class="w-full h-12 px-4 rounded-xl border border-gray-200 bg-white text-sm">

{{-- Primary button --}}
<button class="w-full h-12 bg-emerald-500 text-white font-semibold text-sm rounded-xl">

{{-- Secondary button --}}
<button class="w-full h-12 bg-white text-gray-700 font-semibold text-sm rounded-xl border-2 border-gray-200">

{{-- Badge --}}
<span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border">

{{-- Empty state --}}
<div class="bg-white rounded-2xl border border-dashed border-gray-200 p-8 text-center">
```

---

*Tài liệu này là chuẩn cho toàn bộ dự án HoanTien.xyz. Mọi thay đổi UI phải tuân thủ.*
