# BUG_AUDIT — TRUY XUẤT DÒNG DOCUMENT (LINEAGE) — 2026-09-26

- **Mục tiêu duy nhất:** giải thích vì sao lúc **16:09:18** có document **T2 V2** (token `0d1d2ef645`) nhưng lúc **16:36:53** lại có request **legacy** với token `7035c67915`.
- **Ràng buộc:** KHÔNG dùng giả định "Chrome restore document cũ" (D/E/F bên dưới). Mọi kết luận dựa trên **fingerprint ghi được trong server logs**. Không thể phân biệt bằng server logs → ghi `UNKNOWN`.
- **Phạm vi:** access log Apache (`access_backup_20260805.log`, ngày 10/Sep–26/Sep), `storage/logs/csrf-forensic-2026-09-26.log`, `storage/logs/laravel.log`. **Không thay đổi code.**

Phân loại độ chắc: **CONFIRMED** (có chứng cứ trực tiếp) / **LIKELY** (mạnh, suy diễn hợp lý) / **POSSIBLE** (có thể xảy ra, chưa loại trừ) / **NOT SUPPORTED** (không có chứng cứ ủng hộ) / **UNKNOWN** (không thể xác định từ dữ liệu).

---

## 0. Tóm tắt kết luận (TL;DR)

1. **CONFIRMED:** POST `16:36:53` KHÔNG thuộc document render lúc `16:09:18` (DOC-B).
   - Token request tại 16:36:53 = `7035c67915` ≠ token nhúng trong HTML của DOC-B = `0d1d2ef645`.
   - POST 16:36:53 có `page_instance_id=""` và `t2_flow_id=""` (forensic `csrf_failure` 16:36:54); DOC-B là T2 V2 nên mọi POST của nó đều mang `page_instance_id` + `t2_flow_id` (bằng chứng chuẩn: POST 16:09:47 `T2-mui653lw-n4pnz8`).
2. **CONFIRMED:** tồn tại **ít nhất 2 document Chrome sống song song** trong cửa sổ 16:09→16:36: **DOC-B** (T2) và **ít nhất 1 document legacy** (giữ token `7035c67915`, chưa được instrumentation).
3. **CONFIRMED (gián tiếp):** tài liệu legacy không thể được tạo bởi bất kỳ `GET /dashboard` nào sau khi server token đổi sang `0d1d2ef645` — nó phải được render từ **thời kỳ trước rotation** và **tồn tại từ trước 16:08** ngày 26/09.
4. **LIKELY:** `16:09:18 GET /dashboard` **TẠO document thứ hai** (thêm tab/context) chứ **không thay thế** document đã POST 16:08; document legacy vẫn còn sống đến 16:36:53 (manifest 16:36:49 + POST ngay sau, cùng dấu vân tay hành vi 16:08:06→16:08:09). *UNKNOWN tuyệt đối* vì server log không thấy bên trong trình duyệt.
5. Document legacy là **chứng bệnh mãn tính**: token cũ (era trước rotation) bị ném 419 liên tục từ **24/09 21:34:50** đến **26/09 16:36:53** (7 lần 419, đặc biệt CẢ NGÀY 25/09 không POST nào thành công) mà không hề được reload lại.

→ Mâu thuẫn "16:09 T2 nhưng 16:36 legacy" được giải quyết bằng **nhiều document/context song song**, không cần và không dựa vào cơ chế "restore".

---

## 1. Bổ đề (nền tảng suy luận)

- **L1 — Một document chỉ mang ĐÚNG MỘT token:** token nằm trong HTML do server render (chính bằng `$request->session()->token()`), cố định trong suốt vòng đời document.
- **L2 — Mọi document render SAU khi server token = `0d1d2ef645`:** (a) nhúng token `0d1d2ef645`; (b) chạy code T2 V2 → POST có `page_instance_id` + `t2_flow_id`. Do đó bất kỳ request POST nào mang `7035c67915` + `page/t2 rỗng` BẮT BUỘC đến từ document render **trước rotation**; và không thể có document legacy "mới" ra đời sau rotation.
- **L3 — Đếm document qua forensic:** mỗi `GET /dashboard` thành công khiến server ghi một sự kiện `dashboard_render` kèm `page_instance_id` duy nhất (phiên bản T2). Document legacy (pre-T2) KHÔNG có `page_instance_id` → "vô hình" trong forensic nhưng hiển thị qua `csrf_failure` (token + empty ids) và qua manifest/asset pattern.
- **L4 — `GET /manifest.webmanifest` với referer `/dashboard`, không kèm navigation mới = tín hiệu "activation" của một dashboard document ĐÃ NẠP sẵn** (theo mô hình khớp với mọi ngày: fresh navigation 16:09:18 KHÔNG fetch manifest; activation 16:08:06 / 16:36:49 CÓ fetch).

---

## 2. Đếm số tab/document/browser context (câu hỏi 1 của directive)

> Chứng cứ concreter: forensic log uid17 + access log thiết bị (iOS 26_6_2).

| # | Context | Dấu hiệu server | Trạng thái |
|---|---|---|---|
| DOC-B | Chrome **document T2 duy nhất** render `16:09:18.428839` (`PAGE-e4a5db9c474e115d`, tok `0d1d2ef645`) | `dashboard_render` + csrf-token 16:09:19/43/16:22:40 + POST 16:09:47 | **CONFIRMED** |
| L-A | Chrome **document legacy** chủ POST 16:08:09 (token `7035c67915`, ids rỗng) | manifest 16:08:06 + `csrf_failure` 16:08:10 | **CONFIRMED tồn tại** (dạng legacy) |
| L-P | Chrome **document legacy** chủ POST 16:36:53 (cùng token `7035c67915`, ids rỗng) | manifest 16:36:49 + `csrf_failure` 16:36:54 | **CONFIRMED tồn tại**; L-P === L-A hay khác: **UNKNOWN** |
| Safari ×5 | Browser **context riêng** (UA `iPhone OS 18_7`, cùng session `03a5d9026e`) | 5× `dashboard_render` + POST T2 thành công, không 419 | **CONFIRMED** context độc lập |

- Cửa sổ 16:09→16:36: **≥2 document Chrome** (DOC-B + ≥1 legacy). Cận trên số tab thực tế: **UNKNOWN** (server log không đếm tab).
- Ngoài ra còn 5 document Safari (05:44:46, 10:40:32, 16:10:44, 16:37:49, 16:38:12) — tất cả T2, token `0d1d2ef645`, luôn 200. Luôn **không bao giờ 419**.

---

## 3. Xác định document gốc cho từng request (câu hỏi 2)

| Request (Apache) | Fingerprint server (forensic) | Document gốc | Độ chắc |
|---|---|---|---|
| 16:08:06 `GET /manifest.webmanifest` 200 2418 (ref `/dashboard`) | activation của dashboard doc đã nạp | **L-A** (legacy) | LIKELY |
| **16:08:09 POST /link-requests → 419** | `csrf_failure` 16:08:10: `token_request=7035c67915`, `page_instance_id=""`, `t2_flow_id=""` | **L-A** (document legacy, render trước rotation) | CONFIRMED (dạng legacy; L-A chính là tài liệu nào → UNKNOWN) |
| 16:09:18 `GET /dashboard` 200 64095 (ref `-`) | `dashboard_render` 16:09:18.428839: `page=PAGE-e4a5db9c474e115d`, server tok `0d1d2ef645`, UA CriOS/154 | **DOC-B** (T2 V2) | CONFIRMED |
| 16:09:19 `GET /csrf-token` (52B) | — | DOC-B (T2 init) | LIKELY (chỉ doc T2 gọi /csrf-token) |
| 16:09:43 `GET /csrf-token` (52B) | — | DOC-B (T2 probe resume) | LIKELY |
| 16:09:47 POST → 200 | `link_request_post` 16:09:49: `page=PAGE-e4a5db9c474e115d`, `t2=T2-mui653lw-n4pnz8` | **DOC-B** | CONFIRMED |
| **16:22:40 `GET /csrf-token`** (52B) | probe `bindResumeProbe` khi tab được focus/visible/pageshow | **DOC-B** (vẫn sống ≥16:22:40) | LIKELY |
| 16:36:49 `GET /manifest.webmanifest` 200 2418 (ref `/dashboard`) | activation không kèm navigation mới | **L-A/L-P** (legacy) chứ không DOC-B (DOC-B đang ở tab khác; tương tự DOC-A activation) | LIKELY |
| **16:36:53 POST /link-requests → 419** | `csrf_failure` 16:36:54: `token_request=7035c67915`, `page_instance_id=""`, `t2_flow_id=""`; **không probe /csrf-token** (đặc trưng legacy) | **L-P ≠ DOC-B** | CONFIRMED (khác DOC-B); L-P===L-A: UNKNOWN |

Chú thích: ngoài 3 lần `GET /csrf-token` nói trên, **không có** lần gọi `/csrf-token` nào vào lúc 16:36 — đúng kỳ vọng của document legacy (không có logic T2), và cũng cho thấy DOC-B không được kích hoạt lại lúc 16:36.

---

## 4. Ma trận bằng chứng phân biệt (câu hỏi 3)

| Tín hiệu | DOC-B (16:09:18) | Legacy L (16:08 / 16:36) |
|---|---|---|
| CSRF token request | `0d1d2ef645` | `7035c67915` |
| `page_instance_id` | `PAGE-e4a5db9c474e115d` | rỗng (uninstrumented) |
| `t2_flow_id` | `T2-mui653lw-n4pnz8` (khi POST) | rỗng |
| Code era / template | T2 V2 (HEAD `d5e6518`) | pre-T2 (trước `5ab9ce3`) |
| Byte size HTML render | 64,095 B | ~58,700 B (mẫu cũ: 24/09 19:34 = 58,700 B) |
| `GET /csrf-token` | Có (16:09:19/16:09:43/16:22:40) | Không bao giờ |
| Manifest-with-ref-dashboard | Không (navigation mới không fetch manifest) | Có (16:08:06, 16:36:49 — activation) |
| Referer của POST | `/dashboard` | `/dashboard` (giống nhau) |
| UA | CriOS/154.0.8037.55 (iPhone OS 26_6_2) | giống hệt (cùng thiết bị Chrome) |
| Sequence | 1 navigation → init probe → POST 200 | activation (manifest) → POST 419, không probe |
| Vòng đời | tạo lúc 16:09:18, còn sống 16:22:40 | render trước rotation, còn sống 16:36:53 |

Không có browser event log (pageshow/pagehide/focus/blur, SW điều hướng) → các lifecycle event không ghi được; `public/sw.js` network-only với HTML/navigation/csrf-token nên không thể là nguồn document legacy.

---

## 5. Phân tích chuyên sâu — 16:09 có thay thế document 16:08 không? (câu hỏi 5, 8)

**Kết luận:** CHỨNG MINH được 16:09:18 KHÔNG tạo ra doc legacy của 16:36 (token khác biệt); và vì không có cách nào tạo document legacy sau rotation, nguồn legacy của 16:36 là tài liệu **tồn tại từ trước 16:09:18**. Điều đó có nghĩa: sau 16:09:18 vẫn còn ≥1 document legacy sống.

- **M1 (LIKELY — tối giản):** 16:09:18 mở THÊM document thứ hai (DOC-B, tab mới / cửa sổ / home-screen), **không thay thế** L-A. Bằng chứng:
  - (a) manifest 16:36:49 + POST 16:36:53 có dấu vân tay hành vi giống hệt 16:08:06→16:08:09 (activation rồi submit của một dashboard doc đã nạp sẵn).
  - (b) không có bất kỳ `GET /dashboard` nào từ thiết bị trong khoảng `24/09 19:34:12` → `26/09 16:09:18` để tạo văn bản legacy mới; doc legacy KHÔNG THỂ ra đời sau rotation.
  - (c) thói quen lịch sử của thiết bị: mở dashboard từ `wallet`/`orders`/`so-sanh-gia` nhiều lần/ngày, 419 lặp nhiều lần một ngày (20/09: 4 lần) → **tích lũy nhiều dashboard doc**; các tab cũ giữ token cũ vẫn mở.
  - (d) `16:09:18` có referer `-` (không referrer) — khác với các lần recovery quen thuộc (referer `wallet/orders/…`) → phù hợp gõ địa chỉ / mở tab mới (POSSIBLE, yếu: các ngày khác cũng có vài lần ref `-`).
- **M2 (POSSIBLE):** 16:09:18 thay thế L-A trong cùng tab (reload/gõ lại), nhưng vẫn còn **một document legacy thứ 3** (copy cũ hơn, từ những lần mở trước) tồn tại độc lập → nó phát manifest 16:36:49 và POST 16:36:53. Yêu cầu thêm một tài liệu phụ mà không có tín hiệu trực tiếp.
- **M3 (POSSIBLE):** nguồn legacy 16:36 nằm trong một context Chrome khác (window đa nhiệm / PWA standalone) — không phân biệt được bằng log; chỉ có dấu hiệu gián tiếp là manifest-activation.

Cả M1, M2, M3 đều nhất quán với mọi CONFIRMED fact; M1 tối giản nhất → **LIKELY**. Đặc biệt, không phương án nào cần đến "Chrome restore/BFCache/session restore" để giải thích.

---

## 6. Bối cảnh lịch sử — cuộc chiến mãn tính của document legacy

Chuỗi 419 của thiết bị Chrome (iOS 26_6_2 / session `03a5d9026e`):

| Ngày | Sự kiện 419 (lần) | Phục hồi |
|---|---|---|
| …(các ngày 14–23/09) | nhiều chu kỳ 419→GET /dashboard→200 | mỗi lần tải dashboard mới |
| **24/09 21:34:50** | 419 (bắt đầu chuỗi "chết") | ❌ không phục hồi |
| **25/09 04:15:56, 04:17:25, 11:25:04** | 3× 419 | ❌ **CẢ NGÀY 25/09 không POST nào thành công** |
| **26/09 16:08:09** | 419 | người dùng load /dashboard 16:09:18 → DOC-B → 200 16:09:47 |
| **26/09 16:36:53** | 419 (lại! L-P legacy) | Firefox: tải lại 17:04–17:08 các doc T2 mới → 200 |

- Giữa 24/09 22:50 và 26/09 16:08 không có sự kiện dashboard nào của thiết bị → DOC-A hiển thị manifest lúc 16:08:06 **mà không cần tải mới** → tài liệu đã nạp sẵn trong tab này (server chỉ thấy tín hiệu activation).
- **Chrome nâng cấp 153→154** giữa `25/09 12:15:20` và `26/09 16:08:06` (CriOS/153.0.8010.24 → CriOS/154.0.8037.55). Đây là sự kiện khách quan (log), NHƯNG **không được dùng làm giả định cơ chế**: DOC-A có thể sống qua cả quá trình mà không cần restore, hoặc được khôi phục — mechanism client-side **UNKNOWN, không phán xét**.
- **Chỉnh lại audit #1:** audit #1 ước lượng rotation vào `25/09 ~11:25` (dựa lần quan sát đầu 11:25:04). Chuỗi log mở rộng cho thấy rotation sang `0d1d2ef645` thực tế xảy ra trong khoảng **(24/09 19:34:15, 24/09 21:34:50]**; `11:25` chỉ là một lần quan sát tiếp theo (sự kiện phiên tạm `eb11…` là sự kiện khác). Không ảnh hưởng kết luận cơ chế.

---

## 7. Ma trận A–I (nguồn có thể của request 16:36) — câu hỏi 9

| Nguồn | Đánh giá | Lý do |
|---|---|---|
| **A. Tab khác trong Chrome** (cùng profile) | **LIKELY** | Mô hình M1/M2: nhiều tab dashboard tích lũy; doc legacy vẫn ở tab cũ; POST có dạng activation→submit đúng signature. |
| **B. Window khác** (đa cửa sổ iOS 26 Safari/Chrome) | **POSSIBLE** | Không phân biệt được bằng log; không có tín hiệu bác bỏ. |
| **C. PWA instance** (standalone) | **POSSIBLE** | Manifest được fetch ở 16:08:06/16:36:49 (pattern của PWA/chrome activation), nhưng không có by bằng chứng tích cực (không có install/launch event riêng). UA không phân biệt. |
| **D. Chrome restored tab** | **NOT SUPPORTED** (không giả định) | Theo chỉ đạo, không dùng làm giả định; mô hình ≥2 context không cần đến. Server log không có tín hiệu ủng hộ/bác bỏ riêng. |
| **E. BFCache** | **NOT SUPPORTED** | Không có server signal; không cần để giải thích. |
| **F. Session restore** | **NOT SUPPORTED** | Như D. |
| **G. Legacy document khác vẫn mở** | **POSSIBLE** | L-P có thể là bản copy pre-rotation khác (M2); cùng fingerprint với L-A; không phân biệt được → UNKNOWN. |
| **H. iframe/WebView** | **POSSIBLE** (không evidence) | Trang dashboard render nguyên vẹn (không thấy sub-request/iframe referer) — không tín hiệu ủng hộ. |
| **I. Browser context khác chia sẻ cookie** | **CONFIRMED** (Safari) | Safari (UA `iPhone OS 18_7`) dùng cùng session `03a5d9026e`, ip_fp chủ yếu `02d444c699` (cùng IP). Có 5 document Safari/POST T2 200, **không bao giờ 419** — riêng biệt với Chrome legacy. |

Lưu ý về ip_fp: `ip_fp = fp($request->ip())` (app/Support/CsrfForensic.php:122) — thuần IP, không gồm UA. Chrome (iOS 26_6_2) và Safari (iOS 18_7) trùng `ip_fp=02d444c699` ở 4/5 render Safari → cùng đường mạng (cùng Wi-Fi gia đình); 1 render (10:40:32) có ip_fp `a9f92fdb95` → mạng khác (mobile). UA OS khác nhau (`26_6_2` vs `18_7`) gợi ý **khả năng 2 thiết bị vật lý** (POSSIBLE); cơ chế hai thiết bị dùng chung giá trị session cookie `03a5d9026e`: **UNKNOWN** (không thể suy luận từ server log; ngoài phạm vi lineage).

---

## 8. Trả lời 10 câu hỏi của directive

1. **Bao nhiêu tab/document Chrome?** Ít nhất 2 (DOC-B + ≥1 legacy). Con số chính xác: UNKNOWN. Có thêm 5 document Safari (browser context riêng).
2. **Document của 16:08 POST:** document legacy (token `7035c67915`, ids rỗng); CONFIRMED dạng legacy/pre-T2. Tài liệu cụ thể (render khi nào): LIKELY = render `24/09 19:34:12` (lần GET /dashboard cuối trước rotation trên thiết bị) hoặc bản copy cũ hơn.
3. **Document của 16:09 GET /dashboard:** DOC-B = `PAGE-e4a5db9c474e115d`, T2 V2, token `0d1d2ef645`. CONFIRMED (render event forensic).
4. **Document của 16:22 GET /csrf-token:** DOC-B (probe T2 `bindResumeProbe` khi tab resume). LIKELY (chỉ T2 gọi /csrf-token; không có context nào khác).
5. **Document của 16:36 POST:** document legacy ≠ DOC-B (token + ids rỗng + không probe). CONFIRMED khác DOC-B; L-P===L-A là UNKNOWN.
6. **Bằng chứng phân biệt đầy đủ:** xem ma trận mục 4.
7. **A–I:** xem mục 7. Đặc biệt D/E/F **không được viện dẫn** (theo chỉ đạo) và **không cần thiết** để giải thích.
8. **16:09 có thay thế document 16:08?** Chứng minh được 16:09 KHÔNG loại bỏ nguồn legacy (vì 16:36 vẫn có POST legacy + không có đường tạo legacy doc mới). LIKELY 16:09 **thêm** document thứ hai (M1), không thay thế; M2 (thay thế + copy thứ 3) là POSSIBLE. Cả hai đều cho ≥2 context → mâu thuẫn tiêu tan.
9. **Tồn tại mối quan hệ giữa 2 document?** L-A và L-P có dấu vân tay giống hệt (token, empty ids, sequence manifest→POST, UA, referer) nhưng server log **không thể chứng minh** cùng một JS instance hay hai bản copy → **UNKNOWN**; không kết luận "tab cũ" nào.
10. **Tại sao 16:09 T2 / 16:36 legacy?** Vì hai request đến từ **hai document khác nhau song song**: DOC-B (mới, T2) và L (cũ, pre-rotation, giữ token `7035c67915`). Không có document nào vừa T2 lúc 16:09 vừa legacy lúc 16:36.

---

## 9. Danh sách UNKNOWN

- Số lượng tab/window/PWA chính xác của Chrome.
- L-A (chủ 16:08) và L-P (chủ 16:36) là **cùng một instance** hay **hai bản copy** (cùng fingerprint, không phân biệt được).
- 16:09:18 thực tế mở tab mới hay thay DOC-A (M1 vs M2). Chỉ chứng minh được **≥2 context**.
- Cơ chế client giữ document legacy sống xuyên 24→26/09 (không có client log; không đưa ra giả định restore).
- Số thiết bị vật lý (Chrome iOS 26_6_2 vs Safari iOS 18_7) và cách chia sẻ giá trị session cookie — UNKNOWN, ngoài phạm vi (đã ghi nhận).
- Mốc chính xác giây rotation token (đã thu hẹp: 24/09 19:34:15 → 21:34:50).

---

## 10. Kết luận

- Request `16:36:53` là **sản phẩm của document legacy (pre-rotation, token `7035c67915`) tồn tại song song** với DOC-B — hoàn toàn giải thích được bằng **≥2 Chrome document/context**; không cần và không sử dụng giả định "restore document cũ".
- T2 V2 (via DOC-B) đã hoạt động đúng: POST 16:09:47 thành công với token hiện tại; lỗi 419 tại 16:36 đến từ tài liệu không thuộc T2 V2.
- Để chứng minh triệt để các mục UNKNOWN (tab/window/PWA, M1/M2, instance L-A/L-P) **cần instrumentation client-side** (log page_instance_id, visibilitychange, pageshow/pagehide, tab/window id tại client, gửi về server) — **đề xuất, không thực hiện** (zero code change theo phạm vi).