# Khảo sát CSP có đo đạc — M8a Task 4 (phán quyết R4), 2026-09-27

SPEC §10 mục 2 đòi "Content-Security-Policy không cho `unsafe-inline` script". Kế hoạch M8, R4:
khảo sát trước, đo, thử ba cách, rồi mới phán quyết; dừng lại trình chủ văn phòng nếu phải giữ
riêng một phần lớn view của Filament. Tài liệu này là số đo và phán quyết đó.

## 1. Cách đo

- Bản chạy của làn: `/d/vkwt/m8-dev seed` rồi
  `/d/vkwt/m8-dev serve -e CSP_MODE=report -e PHP_INI_SCAN_DIR=:/var/www/html/tools/csp/php`
  (tệp ini chỉ bật opcache cho `php artisan serve`; không có nó một trang vụ việc mất ~21 giây).
- Trình duyệt thật: Chromium 153.0.8010.12 qua Playwright 1.63.0, chạy bằng Node trên máy, cài
  ngoài repo (`D:\vkwt\m8-tools`). Không dùng Playwright MCP.
- Script: `tools/csp/survey.cjs` (cách chạy ở đầu tệp). Nó bắt vi phạm qua sự kiện
  `securitypolicyviolation` (gắn trước mọi script của trang bằng `addInitScript`), bắt lỗi JS qua
  `pageerror` và `console.error`, và với mỗi trang liệt kê mọi `<script>` nội tuyến: có nonce hay
  không, sha256 của nội dung.
- Đăng nhập thật: nhân sự `admin@luatvukhang.com`; khách `khach1@example.com` — mật khẩu rồi mã
  6 số đọc từ `storage/logs/laravel.log` của làn (`MAIL_MAILER=log`).
- Chính sách nháp của brief, gửi ở chế độ `Content-Security-Policy-Report-Only`:

  ```
  default-src 'self'; script-src 'self' 'nonce-…'; style-src 'self' 'unsafe-inline' https://fonts.bunny.net;
  font-src 'self' https://fonts.bunny.net data:; img-src 'self' data: blob:; connect-src 'self';
  frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'
  ```

  Nonce sinh mỗi request ở `SendSecurityHeaders` bằng `Vite::useCspNonce()`. Livewire 4.4.4 tự
  đọc `Vite::cspNonce()` (`FrontendAssets::nonce()`) và gắn nonce vào thẻ `<script src=livewire.js>`
  của nó — không cần giữ riêng view nào của Livewire.

### Trang đã đi qua (liệt kê theo `route:list`, không theo trí nhớ)

Admin (mọi route GET của panel, trừ những route liệt kê ở dưới): đăng nhập; bảng điều khiển; vụ
việc — danh sách, tạo, một trang vụ việc và **từng tab trong 8 tab** (Tổng quan, Đội ngũ, Tiến
độ, Danh mục hồ sơ, Tài liệu, Các bên, Yêu cầu từ khách, Mốc thời hạn), mở form "Chuyển giai
đoạn" và "Thêm cập nhật" (cả hai là action đầu bảng của tab Tiến độ); khách hàng — danh sách, sửa,
tạo; tài khoản cổng — danh sách, sửa, tạo; nhân sự — danh sách, sửa, tạo; loại vụ việc — danh
sách, sửa, tạo; nhật ký hệ thống. Web: trang 404, `/up`. Portal: đăng nhập (mật khẩu + mã một
lần), danh sách hồ sơ, trang hồ sơ, nộp giấy tờ (chọn đầu mục, mở form, chọn một tệp PDF), trang
yêu cầu, đổi mật khẩu.

Bỏ qua, kèm lý do:
- `POST /admin/logout`, `POST /portal/logout`: không phải trang, không vẽ HTML.
- Mỗi route có `{record}` chỉ đi MỘT bản ghi: cùng một view, cùng một bộ script — số đo ở mục 2
  cho thấy mọi trang cùng panel có đúng cùng một bộ script nội tuyến.
- `GET /documents/{document}/download`: phản hồi là tệp đính kèm, trình duyệt không vẽ nên CSP
  không có gì để chặn; header của nó có test riêng (Task 5), và lượt kiểm `enforce` tải một tệp
  thật qua route này.
- `/portal/change-password` trả 404 cho `khach1` (tài khoản không bị buộc đổi mật khẩu,
  `RequirePortalPasswordChange`) — đó là hành vi đúng; trang được tính nhưng không có gì để đo.
- `/up` chỉ được thêm vào script SAU lượt A0–A3 (xem mục 4); số đo của nó nằm ở lượt enforce.

## 2. Vi phạm theo trang — bốn lượt đo

Mỗi ô: **script nội tuyến bị chặn (khác nhau) / sự kiện `eval` / sự kiện `img-src`**, kèm số lỗi
JS nếu có. "Sự kiện" đếm mọi lần trình duyệt báo; `eval` báo một lần cho mỗi biểu thức Alpine
được dựng nên con số theo độ phức tạp của trang, không theo số chỗ phải sửa.

- **A0** — chính sách nháp, chưa làm gì thêm (Livewire đã có nonce sẵn).
- **A1** — cách 1: thêm sha256 của 4 script tĩnh vào `script-src`.
- **A2** — cách 2: giữ riêng 3 view Filament, gắn nonce vào mọi `<script>` nội tuyến của chúng.
- **A3** — cách 3: A2 + `livewire.csp_safe = true` (bản Alpine dựng cho CSP).

| Trang | A0 | A1 | A2 | A3 |
|---|---|---|---|---|
| admin: đăng nhập (+ trang đích sau đăng nhập) | 7 / 78 / 4 | 0 / 98 / 4 | 0 / 99 / 4 | 0 / 0 / 4 · lỗi JS 18 |
| admin: bảng điều khiển | 4 / 98 / 4 | 0 / 77 / 4 | 0 / 76 / 4 | 0 / 0 / 4 · lỗi JS 16 |
| admin: danh sách vụ việc | 4 / 95 / 2 | 0 / 94 / 2 | 0 / 94 / 2 | 0 / 0 / 2 · lỗi JS 13 |
| admin: trang vụ việc + 8 tab | 4 / 172 / 2 | 0 / 178 / 2 | 0 / 178 / 2 | 0 / 0 / 2 · lỗi JS 121 |
| admin: form "Chuyển giai đoạn" | 4 / 162 / 2 | 0 / 155 / 2 | 0 / 155 / 2 | 0 / 0 / 2 · lỗi JS 49 · **modal không mở** |
| admin: form "Thêm cập nhật" | 4 / 152 / 2 | 0 / 152 / 2 | 0 / 152 / 2 | 0 / 0 / 2 · lỗi JS 39 · **modal không mở** |
| admin: tạo vụ việc | 4 / 123 / 2 | 0 / 123 / 2 | 0 / 123 / 2 | 0 / 0 / 2 · lỗi JS 34 |
| admin: danh sách khách hàng | 4 / 106 / 2 | 0 / 106 / 2 | 0 / 106 / 2 | 0 / 0 / 2 · lỗi JS 39 |
| admin: sửa khách hàng | 4 / 71 / 2 | 0 / 71 / 2 | 0 / 71 / 2 | 0 / 0 / 2 · lỗi JS 14 |
| admin: tạo khách hàng | 4 / 70 / 2 | 0 / 70 / 2 | 0 / 70 / 2 | 0 / 0 / 2 · lỗi JS 13 |
| admin: tài khoản cổng | 4 / 108 / 2 | 0 / 108 / 2 | 0 / 108 / 2 | 0 / 0 / 2 · lỗi JS 41 |
| admin: sửa tài khoản cổng | 4 / 85 / 2 | 0 / 85 / 2 | 0 / 85 / 2 | 0 / 0 / 2 · lỗi JS 16 |
| admin: tạo tài khoản cổng | 4 / 84 / 2 | 0 / 84 / 2 | 0 / 84 / 2 | 0 / 0 / 2 · lỗi JS 15 |
| admin: nhân sự | 4 / 115 / 2 | 0 / 115 / 2 | 0 / 115 / 2 | 0 / 0 / 2 · lỗi JS 38 |
| admin: sửa nhân sự | 4 / 82 / 2 | 0 / 82 / 2 | 0 / 82 / 2 | 0 / 0 / 2 · lỗi JS 17 |
| admin: tạo nhân sự | 4 / 81 / 2 | 0 / 81 / 2 | 0 / 81 / 2 | 0 / 0 / 2 · lỗi JS 15 |
| admin: loại vụ việc | 4 / 92 / 2 | 0 / 92 / 2 | 0 / 92 / 2 | 0 / 0 / 2 · lỗi JS 29 |
| admin: sửa loại vụ việc | 4 / 157 / 2 | 0 / 157 / 2 | 0 / 157 / 2 | 0 / 0 / 2 · lỗi JS 12 |
| admin: tạo loại vụ việc | 4 / 70 / 2 | 0 / 70 / 2 | 0 / 70 / 2 | 0 / 0 / 2 · lỗi JS 86 |
| admin: nhật ký hệ thống | 4 / 80 / 2 | 0 / 80 / 2 | 0 / 80 / 2 | 0 / 0 / 2 · lỗi JS 5 |
| web: trang 404 | 0 / 0 / 0 | 0 / 0 / 0 | 0 / 0 / 0 | 0 / 0 / 0 |
| portal: đăng nhập (mật khẩu + mã) | 7 / 66 / 2 | 0 / 66 / 2 | 0 / 66 / 2 | 0 / 0 / 2 · lỗi JS 16 |
| portal: danh sách hồ sơ | 4 / 33 / 2 | 0 / 33 / 2 | 0 / 33 / 2 | 0 / 0 / 2 · lỗi JS 2 |
| portal: trang hồ sơ (2 hồ sơ) | 8 / 66 / 4 | 0 / 66 / 4 | 0 / 66 / 4 | 0 / 0 / 4 · lỗi JS 4 |
| portal: nộp giấy tờ (mở form, chọn tệp) | 4 / 40 / 2 | 0 / 40 / 2 | 0 / 40 / 2 | 0 / 0 / 2 · lỗi JS 7 |
| portal: yêu cầu | 4 / 33 / 2 | 0 / 33 / 2 | 0 / 33 / 2 | 0 / 0 / 2 · lỗi JS 2 |
| portal: đổi mật khẩu (404, đúng) | 0 / 0 / 0 | 0 / 0 / 0 | 0 / 0 / 0 | 0 / 0 / 0 |
| **Tổng sự kiện vi phạm / lỗi JS** | **2485 / 0** | **2372 / 0** | **2372 / 0** | **56 / 661** |

(Ở lượt A0, trang 404 và "đổi mật khẩu" từng ghi 1 "lỗi JS" — chính là dòng console "Failed to
load resource … 404" của trang 404; script đã được sửa để không đếm nó từ A1 trở đi.)

Ghi chú chung cho mọi lượt: thỉnh thoảng một promise hành động Livewire bị từ chối với giá trị là
id component (20 ký tự, không stack) khi script rời trang giữa lúc một request đang chạy. Nó xuất
hiện ở cả những lượt không chặn gì (Report-Only không bao giờ chặn), nên không do CSP; script ghi
nó thành ghi chú "Livewire huỷ request", không tính là lỗi.

### Vi phạm khác nhau (A0)

1. **4 thẻ `<script>` nội tuyến của Filament không có nonce** — cùng một bộ, cùng một băm trên
   MỌI trang của cả hai panel (đo: mỗi loại đúng 1 băm khác nhau trên 25 trang):

   | Script | View gốc (Filament 5.8.1) | Trang |
   |---|---|---|
   | `const loadDarkMode = () => { … }` | `filament/resources/views/components/layout/base.blade.php` (trong `<head>`) | mọi trang |
   | `loadDarkMode()` | cùng view, cuối `<body>` | mọi trang |
   | `window.filamentData = []` | `support/resources/views/assets.blade.php` | mọi trang |
   | `var collapsedGroups = …` | `filament/resources/views/livewire/sidebar.blade.php` | mọi trang đã đăng nhập |

   `base.blade.php` còn ba thẻ nữa không được vẽ với cấu hình hiện tại (nhánh "không dark mode",
   nhánh "ép dark mode", khối Echo khi bật broadcasting) — cũng được gắn nonce khi giữ riêng, để
   đổi cấu hình panel về sau không lặng lẽ sinh vi phạm.

2. **`eval`** — `livewire.js:1531` (`new AsyncFunction`) và `livewire.js:1603` (`new Function`):
   bản Alpine thường dựng MỌI biểu thức `x-data`/`x-on`/`wire:*` và mọi khối `@script` của Filament
   (`page/index.blade.php`, `unsaved-action-changes-alert.blade.php`, hai view thông báo) bằng
   `Function`. Các khối `@script` đó KHÔNG phải thẻ `<script>` trong DOM — Livewire trích nội dung
   rồi chạy bằng `Function` — nên nonce không áp được cho chúng; chúng chỉ cần `'unsafe-eval'`.

3. **`img-src https://ui-avatars.com/…`** — ảnh đại diện mặc định của Filament
   (`Filament\AvatarProviders\UiAvatarsProvider`) ở menu người dùng và widget tài khoản, cả hai
   panel. URL mang chữ cái đầu của tên người dùng (`Q+t+h+t`, `N+V+A`) — tức mỗi lượt tải trang
   đã đăng nhập, kể cả của khách, gửi chữ cái đầu tên và địa chỉ IP tới một bên thứ ba. SPEC §3 chỉ
   quyết cho Bunny Fonts; ui-avatars.com chưa từng được quyết.

Không có vi phạm nào khác: không `style-src`, không `font-src` (Bunny đã có trong chính sách),
không `connect-src`, không thuộc tính sự kiện nội tuyến (`onclick=`), không `frame`/`object`.
FilePond (ô chọn tệp ở trang nộp giấy tờ) dùng `blob:` cho xem trước — đã có trong `img-src`.

## 3. Ba cách, và số đo của từng cách

### Cách 1 — băm các script tĩnh (A1)

Thêm 4 `'sha256-…'` vào `script-src`: **vi phạm nội tuyến 0**, không cần giữ riêng view nào.
Nhưng băm là băm của ĐÚNG từng byte, kể cả thụt đầu dòng của view gốc:
- một bản vá Filament đổi một khoảng trắng trong `base.blade.php` là script bị chặn ở `enforce`
  (chế độ tối và thu gọn thanh bên hỏng lặng lẽ);
- `window.filamentData` là `@js($data)` — hôm nay là `[]` vì không gói nào đăng ký dữ liệu, một
  plugin đăng ký dữ liệu là đổi băm, và dữ liệu phụ thuộc request thì không băm được (đúng như R4
  cảnh báo);
- `collapsedGroups` chứa nhãn các nhóm điều hướng bị thu gọn — đổi cấu hình điều hướng là đổi băm.
- **Và `eval` vẫn còn nguyên** (2372 sự kiện): băm không chạm được tới nó.

Kết luận: chạy được, nhưng giòn theo NỘI DUNG — là cách dự phòng, không phải cách chọn.

### Cách 2 — giữ riêng đúng các view vi phạm, gắn nonce (A2)

Ba view, sao chép nguyên văn từ `vendor/` rồi đổi DUY NHẤT thẻ mở `<script>` thành
`<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">`:

| View giữ riêng (đường dẫn trong `resources/views/vendor/`) | Gốc | Số thẻ gắn nonce |
|---|---|---|
| `filament-panels/components/layout/base.blade.php` | `vendor/filament/filament/resources/views/components/layout/base.blade.php` | 5 |
| `filament-panels/livewire/sidebar.blade.php` | `vendor/filament/filament/resources/views/livewire/sidebar.blade.php` | 1 |
| `filament/assets.blade.php` | `vendor/filament/support/resources/views/assets.blade.php` | 1 |

Kết quả: **vi phạm nội tuyến 0, script nội tuyến không nonce 0** trên cả 27 trang. Còn lại đúng
`eval` và `ui-avatars.com`. Nonce đổi mỗi request, nên nội dung script đổi thế nào cũng không sao —
thứ phải canh chỉ là view gốc có đổi hay không, và việc đó giao cho test ghim (Task 5).

### Cách 3 — `livewire.csp_safe = true` (A3)

Livewire 4.4.4 có sẵn cờ này (bản `livewire.csp.js`, Alpine với bộ phân tích biểu thức riêng,
không `Function`). Đo trên nền A2: **`eval` về 0** — nhưng Filament không chạy nổi: **661 lỗi JS**
trên 27 trang, 16 loại, ví dụ:

```
CSP Parser Error: Expected PUNCTUATION ":" but got PUNCTUATION ","
CSP Parser Error: Unexpected token: OPERATOR ">"          (hàm mũi tên trong biểu thức Alpine)
CSP Parser Error: Unexpected token: KEYWORD "new"
Undefined variable: JSON                                  (bản CSP cấm biến toàn cục)
Undefined variable: getSelectedRecordsCount / isRecordSelected / canSelectAllRecords (bảng)
```

Và hỏng đúng nút nghiệp vụ: **form "Chuyển giai đoạn" và "Thêm cập nhật" không mở được**
(chờ modal 15 giây, không có). Biểu thức Alpine của Filament viết cho bản Alpine thường; bản CSP
chỉ hiểu một tập con. Muốn dùng cách này phải viết lại biểu thức trong hàng chục view của
Filament — chính là "giữ riêng một phần lớn view" mà R4 bảo dừng lại. **Loại.**

### Đo thêm: A2 ở chế độ `enforce` KHÔNG có `'unsafe-eval'`

Để chắc `'unsafe-eval'` là thứ trang thật cần chứ không phải thêm theo đoán: chạy lại A2 với
`CSP_MODE=enforce`. Ngay trang đầu tiên:

```
| admin: đăng nhập | 21 | 1 | script-src → eval ×21 | 23 |
pageerror: Evaluating a string as JavaScript violates the following Content Security Policy
           directive because 'unsafe-eval' is not an allowed source of script …
bước hỏng: page.fill: Timeout 90000ms exceeded.
```

Ô nhập email của trang đăng nhập nhân sự không bao giờ dùng được — không ai đăng nhập nổi.

## 4. Phán quyết

**Đạt `script-src` không `unsafe-inline` bằng cách 2, với 3 view giữ riêng (≤ 8) → Task 5 bật
`enforce`.** Chính sách thi hành:

```
default-src 'self'; script-src 'self' 'nonce-{mỗi request}' 'unsafe-eval'; worker-src 'self' blob:;
style-src 'self' 'unsafe-inline' https://fonts.bunny.net; font-src 'self' https://fonts.bunny.net data:;
img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self';
form-action 'self'; object-src 'none'
```

Ba điểm khác chính sách nháp, cả ba do số đo (điểm 3 thêm ở vòng sửa 1, mục 7):

1. **`'unsafe-eval'` trong `script-src`.** Đo ở mục 3: thiếu nó thì không đăng nhập được; cách
   duy nhất bỏ được nó (cách 3) làm hỏng nút nghiệp vụ. SPEC §10.2 chỉ cấm `unsafe-inline`, nên
   đây KHÔNG phải một đính chính SPEC — nhưng là điều chủ văn phòng phải biết (mục 5).
2. **Không thêm `https://ui-avatars.com` vào `img-src`.** Thay vào đó, cả hai panel dùng ảnh đại
   diện chữ cái đầu dựng ngay trên máy chủ (một ảnh SVG `data:`, `img-src` đã cho phép `data:`):
   bỏ luôn một lượt gửi tên khách tới bên thứ ba chưa ai quyết, thay vì hợp thức hoá nó trong CSP.
3. **`worker-src 'self' blob:`.** FilePond dựng bản xem trước ảnh trong một Worker tạo từ `blob:`;
   thiếu chỉ thị này thì Worker rơi về `script-src` và bị chặn ở cả Chromium lẫn WebKit (mục 7).
   `blob:` chỉ mở cho Worker, không cho `script-src`.

Và một vi phạm được chấp nhận có chủ đích: **`/up`** (trang kiểm tra sống của Laravel) nạp Tailwind
từ `cdn.jsdelivr.net`. CSP chặn nó là đúng — không cho script bên thứ ba — và trang vẫn trả 200,
thứ duy nhất bộ giám sát đọc; chỉ mất phần tô màu chữ "Application up". Script khảo sát ghi vi phạm
này riêng, không tính vào tổng.

`CSP_MODE` để trống: `report` CHỈ khi `APP_ENV` là `local` hoặc `testing`, `enforce` ở mọi môi
trường khác (production, staging, APP_ENV gõ sai — vòng sửa 1); một giá trị `CSP_MODE` gõ sai cũng
rơi về `enforce`.

Cách 1 (băm) được giữ trong khảo sát làm phương án dự phòng: nếu một ngày giữ riêng view thành
gánh nặng, 4 băm đo ở trên thay được nonce mà không cần view nào — đổi lại là giòn theo nội dung.

## 5. Điều chủ văn phòng cần biết về `'unsafe-eval'`

- CSP này **chặn** kiểu tấn công XSS phổ biến nhất: một `<script>` bị chèn vào trang (không có
  nonce đúng thì không chạy), script từ tên miền lạ, trang bị nhúng vào khung của trang khác
  (`frame-ancestors 'none'`), `<base>` bị đổi, form bị trỏ ra ngoài.
- CSP này **không chặn** một kiểu hẹp hơn: nếu kẻ tấn công chèn được HTML có thuộc tính Alpine
  (`<div x-data x-init="…">`) vào trang, Alpine sẽ chạy biểu thức đó — vì Alpine cần
  `'unsafe-eval'`. Hàng rào cho kiểu này là Blade tự thoát HTML (`{{ }}`); mọi chỗ in HTML thô
  (`{!! !!}`, `HtmlString`) là chỗ phải soát khi review.
- Muốn bỏ `'unsafe-eval'` thì phải chờ Filament hỗ trợ bản Alpine CSP (hiện chưa), hoặc viết lại
  biểu thức Alpine trong hàng chục view của Filament. Không đáng ở v1.

## 6. Kiểm ở chế độ `enforce` (M8a Task 5) — đầu ra thật

> Lượt này dùng chính sách CHƯA có `worker-src` và chỉ tải lên một PDF — nên nó không đi tới
> Worker xem trước ảnh. Vòng sửa 1 (mục 7) đo lại với ảnh JPEG/PNG thật trên Chromium và WebKit.

`/d/vkwt/m8-dev serve -e CSP_MODE=enforce -e PHP_INI_SCAN_DIR=:/var/www/html/tools/csp/php`, rồi
`ACTIONS=1 NODE_PATH=/d/vkwt/m8-tools/node_modules node tools/csp/survey.cjs` (mã thoát 0). Header:

```
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-tYp0Bs64NUTlrEHpn3pMyRrYWcNLSdnaOvIUtS6z' 'unsafe-eval'; style-src 'self' 'unsafe-inline' https://fonts.bunny.net; font-src 'self' https://fonts.bunny.net data:; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'
```

Kết quả: 30 bước (27 trang + `/up` + 2 bước hành động), **0 vi phạm, 0 lỗi JS, 0 script nội tuyến
không nonce**, và:

```
Hành động chính:
  [OK ] admin chuyển giai đoạn một vụ việc — thấy thông báo "Đã chuyển giai đoạn." ở /admin/matters/1
  [OK ] portal đăng nhập đủ hai bước (mật khẩu + mã) — tới /portal
  [OK ] portal mở trang hồ sơ — /portal/ho-so/1
  [OK ] portal nộp một giấy tờ thật — thấy "Chúng tôi đã nhận được" ở /portal/nop-giay-to/1?item=3
  [OK ] portal tải một tệp — giay-to-khao-sat.pdf (193 byte)
```

Đối chiếu trong CSDL `vk_crm_lane_m8` sau lượt chạy: `documents.id=50` (matter 1) là giấy tờ vừa
nộp; `document_downloads.id=2` trỏ `document_id=50` — tức tệp tải về chính là tệp vừa nộp qua
trình duyệt; `stage_logs.id=115` chuyển matter 1 sang `on_hold`.

## 7. Vòng sửa 1 — `worker-src` cho bản xem trước ảnh (C1), đo trên Chromium và WebKit

**Nguyên nhân.** Ô tải lên của Filament (FilePond, `public/js/filament/forms/components/file-upload.js`)
dựng bản xem trước ẢNH trong một Web Worker: `new Worker(URL.createObjectURL(new Blob([...])))`.
Không có `worker-src` thì trình duyệt dùng `script-src 'self' …`, nơi `blob:` không khớp — Worker
bị chặn. Lượt mục 6 chỉ tải một PDF, nên không đi tới đường này. Đường chụp ảnh giấy tờ bằng điện
thoại của khách (SPEC §8.4) là đúng đường này.

**Cách đo.** `tools/csp/survey.cjs` giờ dựng một ảnh JPEG và một ảnh PNG thật (canvas 1200×900 do
chính trình duyệt mã hoá), bọc `window.Worker` để đếm Worker `blob:` đã dựng / đã trả thông điệp
đầu tiên / lỗi, và buộc mỗi bước chọn ảnh có ≥ 1 Worker `blob:` đã chạy, 0 Worker lỗi, ≥ 1 canvas
xem trước — thiếu là bước hỏng, mã thoát 1. Hai màn hình tải lên: modal "Đưa tài liệu vào hồ sơ"
của tab Tài liệu (nhân sự) và trang nộp giấy tờ của cổng khách (JPEG, PNG, rồi PDF). `SCOPE=uploads`
chỉ đi các bước này; `BROWSER=webkit` chạy WebKit 26.6 (động cơ của Safari trên iPhone).

**Đỏ — enforce, chính sách KHÔNG có `worker-src`** (`SCOPE=uploads`, mã thoát 1, cả hai trình duyệt
cùng một kết quả):

| Bước | Chromium 153 | WebKit 26.6 |
|---|---|---|
| admin: modal "Đưa tài liệu vào hồ sơ" — ảnh JPEG | 1 vi phạm `worker-src → blob`; Worker 1/0/1; 0 canvas | như Chromium |
| portal: nộp giấy tờ — ảnh JPEG | 1 vi phạm `worker-src → blob`; Worker 1/0/1; 0 canvas | như Chromium |
| portal: nộp giấy tờ — ảnh PNG | 1 vi phạm `worker-src → blob`; Worker 1/0/1; 0 canvas | như Chromium |
| portal: nộp giấy tờ — PDF | 0 (không dựng Worker) | 0 |

(Worker a/b/c = dựng từ `blob:` / đã trả thông điệp / lỗi.)

**Xanh — enforce, chính sách có `worker-src 'self' blob:`.** Chromium, lượt đầy đủ
`ACTIONS=1` (mã thoát 0, 6 phút 44 giây):

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-…' 'unsafe-eval'; worker-src 'self' blob:; style-src 'self' 'unsafe-inline' https://fonts.bunny.net; font-src 'self' https://fonts.bunny.net data:; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'
| admin: modal "Đưa tài liệu vào hồ sơ" — chọn ảnh JPEG rồi gửi (Worker blob: 1, đã trả thông điệp 1, lỗi 0; 1 canvas xem trước) | 0 | 0 | — | 0 | 1/1/0 |
| portal: nộp giấy tờ — chọn ảnh JPEG rồi gửi (…; Worker blob: 1, đã trả thông điệp 1, lỗi 0; 1 canvas xem trước) | 0 | 0 | — | 0 | 1/1/0 |
| portal: nộp giấy tờ — chọn ảnh PNG rồi gửi (…; Worker blob: 1, đã trả thông điệp 1, lỗi 0; 1 canvas xem trước) | 0 | 0 | — | 0 | 1/1/0 |
| portal: nộp giấy tờ — chọn PDF (đã chọn PDF, tải lên tạm thành công) | 0 | 0 | — | 0 | — |
Tổng vi phạm: 0. Lỗi JS: 0. Worker blob: 3, đã chạy 3, lỗi 0.
Script nội tuyến KHÔNG nonce: 0 loại.
  [OK ] admin chuyển giai đoạn một vụ việc — thấy thông báo "Đã chuyển giai đoạn." ở /admin/matters/1
  [OK ] admin đưa một ảnh JPEG vào hồ sơ qua modal tải lên — thấy "Đã lưu tài liệu vào hồ sơ." ở /admin/matters/1
  [OK ] portal đăng nhập đủ hai bước (mật khẩu + mã) — tới /portal
  [OK ] portal mở trang hồ sơ — /portal/ho-so/1
  [OK ] portal nộp một giấy tờ thật (ảnh JPEG) — thấy "Chúng tôi đã nhận được" ở /portal/nop-giay-to/1?item=2
  [OK ] portal nộp một giấy tờ thật (ảnh PNG) — thấy "Chúng tôi đã nhận được" ở /portal/nop-giay-to/1?item=3
  [OK ] portal tải một tệp — anh-chup-giay-to.png (1158082 byte)
```

Mọi trang còn lại của lượt đầy đủ (29 dòng khác, cùng danh sách mục 6) đều 0 vi phạm, 0 lỗi JS.

WebKit, `SCOPE=uploads ACTIONS=1`: 3 bước ảnh đều 0 vi phạm, Worker 1/1/0, 1 canvas; ba lần gửi
đều OK ("Đã lưu tài liệu vào hồ sơ.", hai lần "Chúng tôi đã nhận được"). Tổng vi phạm 0. Lượt này
ghi 1 lỗi JS ở "admin: danh sách vụ việc":
`pageerror: /localhost:8090/livewire-…/update due to access control checks.` — đó là cách WebKit
báo một request Livewire bị huỷ khi trang chuyển đi. **Không do CSP**: không có sự kiện vi phạm nào
đi kèm, nó có cả ở lượt đỏ, và lượt đối chứng `CSP_MODE=off` (không gửi CSP nào) cũng ghi đúng lỗi
đó ở đúng trang ấy (lượt đối chứng còn ghi thêm một `pageerror: [object Object]` ở trang đó, cũng
không kèm vi phạm nào). Lượt đối chứng không đi tới trang nộp giấy tờ vì các lượt trước đã nộp
hết đầu mục còn thiếu của `khach1`.

Đối chiếu CSDL `vk_crm_lane_m8` sau hai lượt xanh: `documents` 49–54 là sáu tài liệu ảnh vừa lưu
qua trình duyệt, cỡ tệp trong `media` khớp đúng từng byte với ảnh script dựng — Chromium JPEG
28427 / PNG 1158082, WebKit JPEG 28665 / PNG 197456.

**`blob:` chỉ mở cho Worker.** `script-src` không có `blob:` (test `§10.2 worker-src cho Worker
blob: …` ghim điều này), nên một `<script src="blob:…">` vẫn bị chặn; `default-src` không đổi.
