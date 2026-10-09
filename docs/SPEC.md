# VK-CRM — Đặc tả hệ thống

Phiên bản 1.0 · 13/09/2026
Phạm vi bản này: **Hồ sơ vụ việc + Tiến độ + Tài liệu + Portal khách hàng**

---

## 1. Bối cảnh và mục tiêu

Công ty Luật Vũ Khang hiện quản lý hồ sơ vụ việc bằng file rời và trao đổi với
khách qua điện thoại. Hệ quả: khách không biết hồ sơ mình đang ở bước nào, trợ
lý mất nhiều thời gian gọi nhắc giấy tờ, và ban lãnh đạo không nhìn ra hồ sơ nào
đang đình trệ.

Hệ thống này giải quyết đúng ba việc:

1. **Nội bộ** quản lý vụ việc theo giai đoạn tố tụng, có nhật ký, có mốc thời
   hạn, có danh mục hồ sơ chuẩn.
2. **Khách hàng** tự đăng nhập tra cứu tiến độ hồ sơ của mình 24/7, biết còn
   thiếu giấy tờ gì, tự nộp lên, tải về văn bản đã phát hành.
3. **Hệ thống tự thông báo** mỗi khi có tiến triển, tự nhắc hạn tố tụng, tự
   nhắc khách bổ sung giấy tờ.

### Ngoài phạm vi bản này

Ghi rõ để không làm thừa. Các phần sau **không** làm ở bản 1.0 nhưng mô hình dữ
liệu phải để chỗ mở rộng: QR VietQR, tích hợp Zalo ZNS, tích hợp form website,
dashboard phân tích nguồn khách, ký số.

**Đính chính 2026-09-24 (M9 — hợp đồng dịch vụ và thu phí theo đợt).** Danh sách
trên từng mở đầu bằng "hợp đồng dịch vụ và đợt thanh toán, công nợ". M9 đưa chúng
vào hệ thống — một hợp đồng dịch vụ pháp lý cho mỗi vụ việc với một giá trị thoả
thuận, thu theo đợt, ghi nhận khoản thu, nhắc nội bộ đợt quá hạn, trang "Công nợ"
và trang doanh thu — nên chúng không còn ngoài phạm vi. Quyền mới: §5, "Bổ sung
2026-09-19, sửa 2026-09-24". Phần còn lại của danh sách giữ nguyên. Dòng "Kế toán"
ở bảng dưới vẫn đúng về **nội dung hồ sơ**; từ M9 kế toán còn xem và ghi **tiền**
của các vụ thường, trong ranh giới viết ở §5.

**Đính chính 2026-09-24 (M10 — tiếp nhận và thẩm định đầu vào).** Cụm "dashboard phân
tích nguồn khách" **không còn ngoài phạm vi**: M10 dựng nó (trang báo cáo đầu vào —
nguồn khách, tỷ lệ chuyển đổi, thời gian phản hồi lần đầu; kế hoạch M10 Task 6).
"Tích hợp form website" **vẫn ngoài phạm vi**: M10 chỉ làm màn hình nhập tay trong
`/admin`, không có đường công khai nào (kế hoạch M10, R6); cột `source` có giá trị
`website_form` để nhân sự nhập tay một lead gửi từ website. Form trên luatvukhang.com
gửi thẳng vào hệ thống là một milestone riêng sau M10, chưa đánh số. Quyền mới: §5,
"Bổ sung 2026-09-24 (M10)".

### Người dùng

| Vai trò | Guard | Mô tả |
|---|---|---|
| Quản trị hệ thống | `web` | Cấu hình loại vụ việc, giai đoạn, danh mục hồ sơ, người dùng, phân quyền |
| Trưởng phòng / Ban lãnh đạo | `web` | Xem toàn bộ vụ việc, nhận cảnh báo leo thang, theo dõi tiến độ và hiệu suất của đội ngũ (M13 — bổ sung 2026-10-04, §5, §6.14, §7.5) |
| Luật sư | `web` | Chỉ thấy vụ việc mình phụ trách hoặc được thêm vào đội ngũ |
| Trợ lý | `web` | Hỗ trợ vụ việc được phân công, duyệt tài liệu khách nộp |
| Kế toán | `web` | Bản 1.0 chỉ xem danh sách vụ việc, không thấy nội dung hồ sơ |
| Khách hàng | `client` | Chỉ thấy vụ việc của chính mình, chỉ thấy nội dung đã công bố |

---

## 2. Hạ tầng — khuyến nghị và ràng buộc

**Khuyến nghị triển khai trên VPS riêng** (2 vCPU / 4 GB RAM / 60 GB SSD, đặt
tại Việt Nam). Lý do cụ thể, không phải sở thích kỹ thuật:

- Chủ động phiên bản PHP, không phụ thuộc lịch nâng cấp của nhà cung cấp.
- Chạy được queue worker thường trực nên email gửi tức thì thay vì chờ cron.
- Tự cấu hình được sao lưu ra ngoài máy chủ và kiểm soát nơi lưu bản sao — với
  dữ liệu hồ sơ pháp lý đây là yêu cầu, không phải tuỳ chọn.
- Đặt được quyền thư mục chặt và tách biệt tiến trình.

**Nhưng kiến trúc bắt buộc phải chạy được trên shared hosting.** Đây là ràng
buộc thiết kế cứng, không phải mong muốn. Cụ thể:

- Queue dùng driver `database`. Trên VPS chạy `queue:work` bằng systemd; trên
  shared hosting để `schedule:run` gọi `queue:work --stop-when-empty`.
- Toàn bộ tác vụ định kỳ khai báo trong `routes/console.php`, chạy bằng **đúng
  một dòng cron**:
  `* * * * * cd /đường/dẫn && php artisan schedule:run >> /dev/null 2>&1`
- Không phụ thuộc Redis, Memcached, supervisor, websocket, hay bất kỳ extension
  PHP nào ngoài danh sách phổ biến (`bcmath`, `ctype`, `fileinfo`, `json`,
  `mbstring`, `openssl`, `pdo`, `tokenizer`, `xml`, `gd`, `zip`).
- Không dùng symlink `storage:link` cho tệp hồ sơ. Tệp phục vụ qua controller.

**Đính chính 2026-10-01 (M8 Task 7).** Danh sách extension ở trên thiếu, và thiếu đúng những cái
hay vắng trên shared hosting. Danh sách đầy đủ (`composer check-platform-reqs --no-dev` cộng driver
cơ sở dữ liệu), cũng là danh sách `vkcrm:preflight` kiểm ĐỎ (`config/vkcrm.php`, khoá
`deployment.required_extensions`): `ctype`, `dom`, `exif`, `fileinfo`, `filter`, `hash`, `iconv`,
`intl`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`, `xmlreader`,
`zip`, `zlib`, `pdo_mysql`. `intl` do Filament bắt buộc; `dom` do gói làm sạch HTML, gói ghép CSS
vào thư và gói đọc/ghi xlsx cần. `gd` không còn là bắt buộc (dự án chưa đăng ký chuyển đổi ảnh nào
— preflight báo VÀNG khi thiếu); `bcmath` không gói nào bắt buộc. Ngoài extension, máy chủ còn cần:
`zip` dựng với libzip có AES (`ZipArchive::EM_AES_256` — sao lưu mã hoá), hàm `proc_open` không bị
tắt, lệnh `mariadb-dump` (gói `mariadb-client`) và `rclone` cho sao lưu (§10 mục 8). Hướng dẫn cài:
`docs/CAI-DAT.md`, phần "Cài lên máy chủ thật".

**Đính chính 2026-10-03 (M12 Task 4).** Thêm `curl` vào danh sách bắt buộc ở trên: gói thông báo đẩy
`minishlink/web-push` (dùng qua `laravel-notification-channels/webpush`) đòi `ext-curl`, và
`vkcrm:preflight` báo ĐỎ khi thiếu. Từ đây danh sách được canh bằng `composer.lock`: một gói
production mới đòi extension chưa có trong `deployment.required_extensions` làm đỏ test
`tests/Feature/Deployment/PreflightCommandTest.php` ("mọi ext-* mà một gói production … đòi").

**Đính chính 2026-10-03 (việc sau gộp M7, làn fu2).** PHP dòng lệnh (PHP chạy cron và worker hàng
đợi) nên có `pcntl`: thiếu nó, giờ chết của job dựng gói bàn giao không có tác dụng, và preflight báo
VÀNG. Nếu có `pcntl` thì ba hàm `pcntl_async_signals`, `pcntl_signal`, `pcntl_alarm` BẮT BUỘC không
bị tắt (`disable_functions`): Laravel thấy `pcntl` đã nạp là gọi chúng, nên một hàm bị tắt làm mọi
lượt `queue:work` chết ngay khi khởi động, và không thư nào được gửi. Preflight báo ĐỎ trường hợp này.

**Đính chính 2026-10-04 (M14 — Google Drive làm kho tài liệu).** Từ M14 tệp hồ sơ có thể nằm trên một
Shared Drive của văn phòng ("kho"), chỉ CRM đọc và ghi qua một tài khoản dịch vụ; công tắc
`DOCUMENT_STORAGE=local` (mặc định) giữ nguyên hành vi trước M14. Hạ tầng thêm:
- máy chủ phải gọi ra được `oauth2.googleapis.com` và `www.googleapis.com` qua HTTPS, bằng `curl` (đã
  bắt buộc từ M12) hoặc `allow_url_fopen`; thiếu cả hai thì dòng `drive_http_client` ĐỎ. Gói mới duy
  nhất là `google/auth` (kéo theo `firebase/php-jwt`, `psr/cache`), chỉ để đổi khoá lấy token; adapter
  Drive REST v3 là mã của dự án (không `masbug/flysystem-google-drive-ext`: nó đòi hạ Guzzle);
- hàng đợi `storage` riêng (kết nối `storage`, driver `database`, `retry_after` 2400) rút bằng chính
  dòng cron (`queue.storage` mỗi phút): không worker thường trực, không dòng cron thứ hai;
- `disk_free_space` nên bật: tắt thì gói bàn giao không kiểm được chỗ trống trước khi dựng
  (`disk_free_space_available` VÀNG);
- extension `posix` nên có, để chứng minh nhóm của tệp khoá là nhóm riêng; thiếu thì khoá `0440` chỉ
  VÀNG (dùng `0400`);
- tệp khoá JSON của tài khoản dịch vụ nằm ngoài mã nguồn và ngoài gốc web (VPS:
  `/etc/vkcrm/google-drive-key.json` `0440`; shared hosting: `~/.config/vkcrm/google-drive-key.json`
  `0400`), `.env` chỉ mang đường dẫn; access token cache ở store `file`, không trong CSDL.

Với `DOCUMENT_STORAGE=local` trên một máy chưa từng chuyển tệp nào, `vkcrm:preflight` không có dòng kho
nào ĐỎ. Hướng dẫn: `docs/CAI-DAT.md` (mục "Bản cập nhật M14") và `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`.

**Đính chính 2026-10-07 (M11 Task 16).** Máy chủ MCP cho nhân sự (M11) thêm `sodium` vào danh sách
bắt buộc `required_extensions` mà `vkcrm:preflight` kiểm ĐỎ: `lcobucci/jwt` (gói ký token mà Passport
kéo vào qua `league/oauth2-server`) khai `ext-sodium`, thiếu nó thì `/oauth/token` hỏng. M11 cũng cần
`curl` (đã bắt buộc từ M12, đính chính 2026-10-03 ở trên): việc tải tài liệu CIMD của app ghim địa
chỉ IP bằng một hằng số của curl. Danh sách đầy đủ vẫn là `composer check-platform-reqs --no-dev`
cộng `pdo_mysql`. Hướng dẫn cài: `docs/CAI-DAT.md`, Bước 1.

### Giám sát cron

Trên shared hosting cron rất hay lặng lẽ ngừng chạy sau khi gia hạn gói hoặc đổi
cấu hình PHP, và không ai biết cho tới lúc lỡ một mốc thời hạn. Bắt buộc:

- Task `heartbeat` chạy mỗi 5 phút, gọi HTTP GET tới một URL cấu hình trong
  `.env` (`HEARTBEAT_URL`), dùng dịch vụ giám sát cron miễn phí bên ngoài.
- Bảng `system_health` ghi `last_schedule_run_at`. Trang chủ admin panel hiển
  thị cảnh báo đỏ nếu giá trị này cũ hơn 30 phút.

---

## 3. Kiến trúc ứng dụng

Một ứng dụng Laravel duy nhất, hai Filament panel, hai guard xác thực.

```
app/
├── Enums/                    MatterStage, DocumentGroup, DocumentStatus,
│                             ChecklistItemStatus, DeadlineSeverity, ...
├── Models/
├── Actions/                  Toàn bộ logic nghiệp vụ
│   ├── Matter/TransitionMatterStage.php
│   ├── Matter/PublishStageLog.php
│   ├── Document/PublishDocument.php
│   ├── Document/ReviewChecklistItem.php
│   └── Client/CreateClientPortalAccount.php
├── Filament/
│   ├── Admin/                Panel nội bộ, path /admin, guard web
│   │   ├── Resources/
│   │   ├── Widgets/
│   │   └── Pages/
│   └── Portal/               Panel khách hàng, path /portal, guard client
│       ├── Resources/
│       └── Pages/
├── Jobs/
├── Events/  Listeners/
├── Notifications/
├── Policies/
└── Support/Scopes/           Global scope giới hạn dữ liệu theo client
```

### Hai panel

| | Panel `admin` | Panel `portal` |
|---|---|---|
| Đường dẫn | `/admin` | `/portal` |
| Tên miền gợi ý | `crm.{tên-miền-công-ty}` | `portal.{tên-miền-công-ty}` |
| Guard | `web` (bảng `users`) | `client` (bảng `client_users`) |
| Xác thực | Email + mật khẩu + **2FA bắt buộc** (2FA ứng dụng của Filament) | Email + mật khẩu + **OTP qua email** |
| Màu chủ đạo | Xám trung tính | Màu thương hiệu công ty |
| Giới hạn IP | Có thể bật qua middleware, cấu hình `.env` | Không |

Cấu hình tên miền qua `.env` (`ADMIN_DOMAIN`, `PORTAL_DOMAIN`). Nếu để trống
thì cả hai panel chạy chung một tên miền theo đường dẫn — phải hoạt động được
cả hai cách.

**Đính chính 2026-09-28 (M8 Task 2, R2).** "Fortify TOTP" ở hàng Xác thực trên là sai — dự án
chưa từng cài `laravel/fortify`. Filament 5.8.1 có sẵn một bộ 2FA ứng dụng (TOTP, kèm mã khôi
phục) trong `filament/filament`, và đó là thứ panel `admin` dùng
(`App\Filament\Admin\Auth\StaffAppAuthentication`, đăng ký ở `AdminPanelProvider`). "Bắt buộc"
nghĩa đúng như đã ghi ở §10 mục 7: không màn hình, không hành động, không cột nào tắt được — kể
cả admin không tự tắt được của chính mình. Mất điện thoại đi qua "Đặt lại 2FA"
(`App\Actions\User\ResetStaffTwoFactor`), không phải một nút tắt.

### Bộ chữ web — quyết định ghi ngày 2026-09-16 (M3)

Cả hai panel gọi `->font(config('vkcrm.brand.font'))` (Be Vietnam Pro) mà không
chỉ định provider, nên Filament dùng mặc định `BunnyFontProvider`. **Hệ quả: mỗi
lượt tải trang của cả hai panel — KỂ CẢ trang đăng nhập cổng khách hàng, tức
trước khi ai đăng nhập — phát một request tới `fonts.bunny.net`, một bên thứ ba.**

Quyết định: **giữ Bunny ở giai đoạn này.** Bunny Fonts không đặt cookie, không
ghi log địa chỉ IP và tự tuyên bố tuân thủ GDPR — khác hẳn Google Fonts, vốn là
lý do quy tắc này đáng được ghi lại thay vì mặc nhiên. Cái giá vẫn có thật và
phải nói rõ: (a) địa chỉ IP của khách hàng chạm tới một hạ tầng ngoài tầm kiểm
soát của văn phòng, ngay ở trang đăng nhập; (b) CDN chết hoặc bị chặn thì cả hai
panel âm thầm rơi về phông hệ thống.

Phương án thay thế khi cần: **tự host** — tải các tệp `woff2` vào `public/fonts`
và dùng `FontProviders::local()`. Nếu quan điểm bảo vệ dữ liệu của văn phòng đòi
"không có request ra ngoài nào từ cổng khách hàng", đây là cách đóng lại, và nó
đồng thời xoá luôn rủi ro (b).

`tests/Feature/BrandingTest.php` khẳng định thẻ `<link>` tới stylesheet phông có
mặt ở cả hai panel, để một lần gỡ hay một CDN bị chặn làm ĐỎ một test thay vì âm
thầm hạ cấp chữ nghĩa của cả sản phẩm.

---

## 4. Mô hình dữ liệu

Toàn bộ bảng dùng `id` bigint tự tăng, `created_at`, `updated_at`, `deleted_at`.
Các cột `created_by` / `updated_by` là FK tới `users`.

### 4.1 `users` — nhân sự nội bộ

| Cột | Kiểu | Ghi chú |
|---|---|---|
| name | string(100) | |
| email | string(150) unique | |
| password | string | |
| phone | string(20) nullable | |
| position | enum | `lawyer`, `assistant`, `accountant`, `manager`, `admin` |
| bar_number | string(50) nullable | Số thẻ luật sư |
| is_active | boolean default true | |
| two_factor_secret, two_factor_recovery_codes | text nullable, cast `encrypted`/`encrypted:array` | 2FA ứng dụng của Filament |
| last_login_at | timestamp nullable | |

Vai trò và quyền quản lý bằng `spatie/laravel-permission`, không tự viết.

**Đính chính 2026-09-28 (M8 Task 2, R2).** "Fortify" ở hàng `two_factor_secret`/
`two_factor_recovery_codes` là sai — cùng đính chính đã ghi ở §3. Hai cột này chưa từng đổi tên
hay đổi kiểu (`text nullable` có từ M0); Task 2 chỉ thêm cast `encrypted`/`encrypted:array` (không
migration — chưa đường nào từng GHI hai cột này trước Task 2) và implement hai interface
`Filament\Auth\MultiFactor\App\Contracts\{HasAppAuthentication,HasAppAuthenticationRecovery}` trên
`App\Models\User`, ánh xạ thẳng vào tên cột hiện có.

**Đính chính 2026-10-08 (M11 Task 17; §16 R1, R2, R12).** Thêm cột `ai_access` string(20) default
`off`, enum `App\Enums\AiAccessMode` (`off` "Tắt", `read` "Chỉ đọc", `read_write` "Đọc và ghi"): công
tắc vận hành theo người cho máy chủ MCP, **không** phải một quyền spatie (bảng quyền §5 không đổi).
Chỉ người có `settings.manage` đổi được, trên trang "Kết nối AI" (`App\Actions\Mcp\SetUserAiAccess`,
nhật ký `ai_access_changed`); không bật được cho người thiếu `matter.view` (kế toán); hạ về `off` thu
hồi mọi kết nối AI của người đó trong cùng transaction. Bảng mới `ai_acknowledgements` — cam kết của
nhân sự trước lần kết nối đầu (`user_id`, `policy_version` string(20), `accepted_at`, `ip_address`
string(45), `user_agent` string(500); unique `(user_id, policy_version)`). Passport thêm bốn bảng
`oauth_auth_codes`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_clients`; token chỉ gắn
`users`, không bao giờ `client_users`. `oauth_clients` có thêm `is_mcp` boolean default false (token của
client không mang cờ bị `/mcp` từ chối) và `metadata_url` string(255) nullable unique (client đăng ký
bằng tài liệu CIMD).

### 4.2 `clients` — khách hàng

| Cột | Kiểu | Ghi chú |
|---|---|---|
| code | string(20) unique | Tự sinh, dạng `KH-2026-0001` |
| type | enum | `individual`, `organization` |
| name | string(200) | |
| id_number | text nullable | **Mã hoá bằng cast `encrypted`** — số căn cước / mã số thuế |
| phone | string(20) nullable | |
| email | string(150) nullable | |
| address | string(300) nullable | |
| representative_name | string(120) nullable | Người đại diện, nếu là tổ chức |
| note | text nullable | Nội bộ, không bao giờ ra portal |

### 4.3 `client_users` — tài khoản đăng nhập portal

| Cột | Kiểu | Ghi chú |
|---|---|---|
| client_id | FK clients | |
| name | string(100) | |
| email | string(150) unique | Dùng để đăng nhập |
| phone | string(20) nullable | |
| password | string | |
| is_active | boolean default true | |
| must_change_password | boolean default true | Bật khi mới tạo |
| activated_at | timestamp nullable | |
| last_login_at | timestamp nullable | |
| last_login_ip | string(45) nullable | |

Một `client` có thể có nhiều `client_users` (ví dụ hai vợ chồng cùng theo dõi
một vụ). Mọi truy vấn portal giới hạn theo `client_id` của tài khoản đang đăng
nhập, **không phải** theo `client_user_id`.

### 4.4 `matter_types` — loại vụ việc

| Cột | Kiểu | Ghi chú |
|---|---|---|
| code | string(10) unique | Ví dụ `DD` cho đất đai, `DS` dân sự, `HS` hình sự, `DN` doanh nghiệp, `LD` lao động, `HN` hôn nhân gia đình |
| name | string(150) | |
| description | text nullable | |
| is_active | boolean default true | |
| sort_order | integer | |

**Đính chính 2026-09-30 (M9 Task 1).** Ví dụ mã ở dòng `code` còn thiếu sáu lĩnh vực
thêm ở M9: `HC` hành chính và giấy phép, `TM` hợp đồng và thương mại, `NH` ngân hàng và
tín dụng, `SH` sở hữu trí tuệ và công nghệ, `TC` thuế và tài chính, `XD` xây dựng và hạ
tầng — tổng cộng 12 loại, xem §12.

### 4.5 `matter_type_stages` — giai đoạn theo từng loại vụ việc

Giai đoạn **không** hardcode trong code. Mỗi loại vụ việc có bộ giai đoạn riêng,
quản trị viên tự cấu hình được.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_type_id | FK | |
| key | string(40) | Định danh, ví dụ `court_accepted` |
| label | string(120) | Nhãn hiển thị nội bộ, ví dụ "Toà thụ lý" |
| client_label | string(120) | Nhãn hiển thị cho khách, có thể viết dễ hiểu hơn |
| client_description | text nullable | Một đoạn giải thích giai đoạn này nghĩa là gì, hiện trên portal |
| sort_order | integer | |
| is_terminal | boolean default false | Giai đoạn kết thúc |
| allowed_next | json | Mảng các `key` được phép chuyển tới |
| default_next_update_days | integer default 14 | Dùng để gợi ý ngày dự kiến có tin tiếp theo |

Bộ giai đoạn mẫu cho tranh chấp dân sự (seeder phải tạo sẵn):
`intake` → `collecting_documents` → `drafting` → `filed` → `court_accepted` →
`mediation` → `first_instance` → `appeal` → `enforcement` → `closed`,
cộng trạng thái `on_hold` có thể vào ra từ `intake` và `collecting_documents`.

### 4.6 `matters` — vụ việc

| Cột | Kiểu | Ghi chú |
|---|---|---|
| code | string(30) unique | Tự sinh `{tiền tố}-{năm}-{mã loại}-{số thứ tự 4 chữ số}`, ví dụ `VK-2026-DD-0147`. Tiền tố lấy từ `.env` (`MATTER_CODE_PREFIX=VK`) |
| client_id | FK clients | |
| matter_type_id | FK matter_types | |
| title | string(250) | |
| description_internal | text nullable | **Không bao giờ ra portal** |
| summary_for_client | text nullable | Mô tả ngắn hiện trên portal |
| stage | string(40) | Trỏ tới `matter_type_stages.key` |
| stage_entered_at | timestamp | |
| lead_lawyer_id | FK users | |
| opened_at | date | |
| closed_at | date nullable | |
| is_published_to_portal | boolean default false | **Công tắc tổng.** Tắt thì vụ việc vô hình trên portal dù khách đúng quyền |
| court_name | string(200) nullable | |
| case_number | string(80) nullable | Số thụ lý của toà |
| last_client_update_at | timestamp nullable | Lần cuối công bố tiến độ cho khách — dùng cho SLA 14 ngày |
| confidentiality | enum default `normal` | `normal`, `restricted` — `restricted` chỉ lead lawyer và quản trị thấy |

Index: `(client_id)`, `(lead_lawyer_id)`, `(stage)`, `(last_client_update_at)`,
`(is_published_to_portal, client_id)`.

**Đính chính 2026-09-27 (M6.5 Task 5, ghi ở Task 21; phán quyết R8).** Trước M6.5 không chỗ nào
ghi `closed_at` (`stage/stage-03`, `spec-gap/spec-gap-03`). "Kết thúc vụ việc" nghĩa là
`TransitionMatterStage` đưa vụ việc vào một giai đoạn `is_terminal = true`: Action ghi
`closed_at = now()`, và xoá `closed_at` khi đường bỏ qua của admin đưa vụ việc rời giai đoạn kết
thúc đó. **"Vụ đang mở" có đúng một định nghĩa**, `Matter::scopeOpen()`: `closed_at` null **và**
`deleted_at` null (điều kiện thứ hai viết tường minh, để một lời gọi `withTrashed()` phía trên
không kéo vụ đã huỷ vào). Widget, cột tô màu, `CheckDeadlines` và mọi tác vụ sau này (`CheckStaleMatters`
ở M6, lưu trữ ở M7) dùng scope này hoặc bản trong bộ nhớ `Matter::isOpen()`, không tự viết lại
điều kiện.

**Đính chính 2026-09-24 (M9).** "`restricted` chỉ lead lawyer và quản trị thấy" áp
cho cả **tiền** của vụ — hợp đồng, đợt thanh toán, khoản thu, phụ lục — theo đúng
quy tắc của nội dung, qua cùng một định nghĩa `Matter::listableBy` (§5, "Bổ sung
2026-09-19, sửa 2026-09-24"). Kế toán và trưởng phòng không thấy tiền của vụ
`restricted`, kể cả trong số liệu tổng hợp; luật sư phụ trách ghi được khoản thu
trên vụ đó dù không có `payment.record`.

**Đính chính 2026-10-08 (M11 Task 17; §16 R9).** Thêm cột `ai_access` string(20) default `denied`,
enum `App\Enums\MatterAiAccess` (`allowed` "Cho phép AI truy cập", `denied` "Không cho AI truy cập").
Vụ mới nhận giá trị của `.env` `MCP_MATTER_DEFAULT` (`config('vkcrm.mcp.matter_default')`, mặc định
`denied`): dự án không tự quyết thay luật sư rằng khách đã đồng ý (Luật Luật sư Điều 25; Luật 91 Điều
9: im lặng không phải đồng ý). Đổi trên tab Tổng quan cần `matter.update`; bật (`allowed`) cần thêm ô
tích "Khách đã đồng ý bằng văn bản cho việc này" — không bao giờ đánh dấu sẵn; tắt thì không cần
(`App\Actions\Matter\SetMatterAiAccess`,
nhật ký `matter_ai_access_changed` mang người và thời điểm). Vụ `denied` vắng mặt khỏi mọi tool MCP và
mọi số đếm, như vụ không tồn tại.

### 4.7 `matter_user` — đội ngũ tham gia vụ việc

| Cột | Kiểu |
|---|---|
| matter_id, user_id | FK, unique cặp |
| role_in_matter | enum: `lead`, `associate`, `assistant`, `observer` |

Đây là bảng quyết định luật sư nào thấy vụ việc nào. **Luật sư không có tên
trong bảng này thì không thấy vụ việc**, kể cả trong kết quả tìm kiếm.

### 4.8 `stage_logs` — nhật ký tiến độ

Bảng quan trọng nhất hệ thống. Chỉ thêm, không sửa, không xoá.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK | |
| from_stage, to_stage | string(40) nullable | `from_stage` null khi là dòng cập nhật không đổi giai đoạn |
| occurred_at | datetime | Thời điểm sự việc xảy ra trên thực tế, do người nhập chọn |
| internal_note | text nullable | **Chỉ nội bộ. Không bao giờ serialize ra portal** |
| public_content | text nullable | Nội dung khách đọc |
| next_step | text nullable | "Tiếp theo sẽ là gì" |
| client_action | text nullable | "Anh/chị cần làm gì" — để trống nghĩa là không cần làm gì |
| expected_next_update_at | date nullable | "Dự kiến có tin tiếp theo trước ngày" |
| is_published | boolean default false | |
| published_at | timestamp nullable | |
| notified_at | timestamp nullable | Chống gửi trùng thông báo |
| created_by | FK users | |

Ràng buộc ở tầng Action, có test: **`is_published = true` thì `public_content`
bắt buộc không rỗng và tối thiểu 30 ký tự.**

### 4.9 `checklist_templates` và `checklist_template_items`

Danh mục hồ sơ chuẩn theo loại vụ việc.

`checklist_templates`: `matter_type_id`, `name`, `is_active`.

`checklist_template_items`: `template_id`, `name` string(200),
`description` text (hướng dẫn khách cách chuẩn bị giấy tờ này),
`is_required` boolean, `sort_order`.

Seeder phải tạo sẵn danh mục cho tranh chấp đất đai gồm 12 đầu mục sau, trong đó
các mục đánh dấu ✱ là bắt buộc:

1. ✱ Giấy tờ tuỳ thân của người khởi kiện, bản sao chứng thực
2. ✱ Giấy chứng nhận quyền sử dụng đất hoặc giấy tờ về quyền sử dụng đất
3. ✱ Biên bản hoà giải tại Uỷ ban nhân dân cấp xã
4. Hợp đồng chuyển nhượng, tặng cho hoặc văn bản về thừa kế liên quan
5. Trích lục bản đồ địa chính, trích đo thửa đất
6. Văn bản, quyết định của cơ quan nhà nước liên quan đến thửa đất
7. Chứng cứ về quá trình sử dụng đất: biên lai thuế, hoá đơn điện nước
8. Ảnh hiện trạng thửa đất và công trình trên đất
9. Danh sách, địa chỉ người có quyền lợi và nghĩa vụ liên quan
10. ✱ Hợp đồng dịch vụ pháp lý và giấy uỷ quyền
11. Giấy chứng tử và văn bản kê khai di sản, nếu có yếu tố thừa kế
12. Tài liệu khác theo yêu cầu của toà án

### 4.10 `matter_checklist_items` — danh mục của từng vụ việc cụ thể

Khi tạo vụ việc, hệ thống **sao chép** các item từ template sang bảng này. Sao
chép chứ không tham chiếu, để sửa template về sau không làm thay đổi hồ sơ đã
mở.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK | |
| name | string(200) | |
| description | text nullable | |
| is_required | boolean | |
| sort_order | integer | |
| status | enum | `missing`, `pending_review`, `accepted`, `rejected`, `not_applicable` |
| rejection_reason | text nullable | |
| reviewed_by | FK users nullable | |
| reviewed_at | timestamp nullable | |

Ràng buộc có test: **`status = rejected` thì `rejection_reason` bắt buộc, tối
thiểu 20 ký tự.** Lý do này hiện thẳng cho khách nên phải viết cho người thường
đọc được.

Trường tính toán hiển thị trên portal: `Đã nộp X / Y` trong đó Y là số item
`is_required = true` cộng số item không bắt buộc nhưng đã có tài liệu.

**Đính chính 2026-09-16 (M4 Task 6).** Câu trên định nghĩa đủ **Y** nhưng không
định nghĩa **X**, và cách đọc tự nhiên nhất — "X là số item `accepted` hoặc
`not_applicable`" — cho ra một tử số **lớn hơn mẫu số** trên chính dữ liệu mẫu
của SPEC §12: thanh tiến độ hiện `Đã nộp 5/3`. Luật đầy đủ:

- **Y là một TẬP HỢP**, không phải một con số đếm riêng: các item
  `is_required = true`, **hợp** với các item không bắt buộc đang có ít nhất một
  tài liệu **không thuộc nhóm D** gắn vào (nhóm D là hồ sơ công việc nội bộ —
  §4.11 — nên nó không bao giờ là bằng chứng rằng khách đã nộp gì).
- **X đếm BÊN TRONG tập đó**: số phần tử của Y có `status` là `accepted` hoặc
  `not_applicable`. Ràng buộc `X ⊆ Y` là điều kiện thiếu ở bản đầu.

Hệ quả cần nói thẳng vì nó nhìn như một lỗi: một item **không bắt buộc, không có
tài liệu nào**, được văn phòng đánh dấu `not_applicable`, **không xuất hiện ở cả
hai vế**. Nó chưa bao giờ nằm trong danh sách giấy tờ khách phải nộp, nên việc
tuyên bố nó không cần nộp không làm thanh tiến độ nhúc nhích. Dữ liệu mẫu ở §12
sinh ra đúng những item như vậy và chúng không phải dữ liệu sai.

**Đính chính 2026-09-27 (M6.5 Task 17, ghi ở Task 21; `checklist/checklist-05`).** Câu "đang có
ít nhất một tài liệu **không thuộc nhóm D**" ở trên quá rộng. Khi văn phòng gắn một văn bản nhóm
B hoặc C vào một item không bắt buộc, item đó vào Y trong khi vẫn ở `missing`, và cổng báo khách
"chúng tôi còn chờ ở anh/chị" một giấy tờ mà chính văn phòng phát hành. Luật đúng, cài ở
`App\Actions\Document\ChecklistProgress`:

- **Y** = các item `is_required = true`, **hợp** với các item không bắt buộc đang có ít nhất một
  tài liệu **nhóm A** (`ClientProvided`, do khách cung cấp, kể cả khi nhân sự nộp thay) gắn vào.
  Tài liệu nhóm B, C, D không bao giờ đưa một item vào Y, dù đã công bố hay chưa.
- **X** không đổi: số item trong Y có `status` là `accepted` hoặc `not_applicable`.

Hệ quả: một item không bắt buộc chỉ được thoả bằng văn bản văn phòng phát hành thì không hiện
trên thanh tiến độ. Chủ nhiệm chấp nhận điều này (sổ tay M6.5, phán quyết Task 17).

### 4.11 `documents`

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK | |
| matter_checklist_item_id | FK nullable | Gắn vào đầu mục danh mục, nếu có |
| group | enum | `A` khách cung cấp, `B` văn bản đã phát hành, `C` văn bản từ cơ quan nhà nước, `D` hồ sơ công việc nội bộ |
| title | string(250) | |
| status | enum | `internal_draft`, `pending_approval`, `signed_filed`, `published` |
| version | unsignedInteger default 1 | |
| parent_document_id | FK nullable self | Bản cũ khi nộp lại |
| uploader_type | string | Morph: `App\Models\User` hoặc `App\Models\ClientUser` |
| uploader_id | unsignedBigInteger | |
| client_can_view | boolean default false | |
| client_can_download | boolean default false | |
| published_at | timestamp nullable | |
| published_by | FK users nullable | |
| issued_at | date nullable | Ngày ban hành / ngày nộp thực tế |

Tệp gắn qua medialibrary, collection `file`, disk `private`, chỉ một tệp mỗi
document.

Quy tắc mặc định khi tạo:

| Nhóm | Người tạo | status mặc định | client_can_view | client_can_download |
|---|---|---|---|---|
| A | Khách nộp qua portal | `published` | true | true |
| A | Nhân viên nộp thay | `published` | true | true |
| B | Luật sư | `internal_draft` | false | false |
| C | Nhân viên | `internal_draft` | false | false |
| D | Bất kỳ ai | `internal_draft` | false | **vĩnh viễn false** |

Quy tắc chuyển trạng thái nhóm B: `internal_draft` → `pending_approval` →
`signed_filed` → `published`. **Không được nhảy thẳng từ `internal_draft` hoặc
`pending_approval` sang `published`.** Đây là ràng buộc ngăn khách nhìn thấy một
bản đơn mà toà chưa hề nhận được.

**Đính chính 2026-09-27 (M6.5 Task 16, ghi ở Task 21; phán quyết R9).** SPEC gốc im lặng về
việc ai được đi từng bước, và trước M6.5 giao diện không có đường nào tới `signed_filed`
(`docs/docs-1`), nên văn bản nhóm B không bao giờ công bố được. Luật đã cài:

- **"Trình duyệt"** (`internal_draft` → `pending_approval`): ai có quyền `update` tài liệu.
- **"Đã ký, đã nộp"** (`pending_approval` → `signed_filed`): đòi `document.publish`. Chỉ sau bước
  này mới công bố được (`PublishDocument`, cũng đòi `document.publish`).
- **"Trả về bản nháp"** (`pending_approval` → `internal_draft`): đòi `document.publish`, có audit.
  Thêm vì một bản bị từ chối duyệt nội bộ trước đó nằm ở `pending_approval` mãi, không rời B được.
- **Đổi nhóm** (`RegroupDocument`, `docs/docs-2`):
  - chuyển **vào** nhóm D luôn được với `document.update`: nó chỉ làm giảm cái khách thấy, và là
    đường rút tạm duy nhất cho tới `RetractDocument` (M7 Task 7);
  - rời nhóm D đòi `document.publish`;
  - rời nhóm B sang A hoặc C đòi `document.publish` **và** một trong hai: tài liệu đã
    `signed_filed`, hoặc đang `published` với `client_can_view = true`; hoặc một lý do sửa xếp nhầm nhóm (tối thiểu 10
    ký tự, không tính khoảng trắng hai đầu) được ghi vào `document_regrouped.misfiling_reason`.
    Không được lặng lẽ "giặt" một bản nháp B thành nhóm C để công bố thẳng.

Audit mới cho ba bước đầu: `document_submitted_for_approval`, `document_signed_filed`,
`document_returned_to_draft` (xem §10.6).

**Đính chính 2026-09-28 (M7 Task 7 — `RetractDocument`).** `status` có trạng thái thứ năm,
`retracted` ("Đã rút lại"), cùng ba cột `retracted_at` (timestamp nullable), `retracted_by` (FK users
nullable, `nullOnDelete`) và `retraction_reason` (text nullable).

- **Rút lại là gì.** Văn phòng đưa một tài liệu ĐANG ra tới khách (`published`, `client_can_view`,
  không nhóm D, chưa xoá mềm) ra khỏi tầm mắt khách: `status = retracted`, hai cờ khách tắt, ghi
  người rút, lúc rút và lý do. Tệp và mọi dòng `document_downloads` giữ nguyên; `published_at`/
  `published_by` giữ nguyên. Audit `document_retracted` mang số lượt tải của khách trước lúc rút.
- **Ai rút.** Người có `document.publish` trên tài liệu (cùng cổng với công bố): luật sư trong đội
  ngũ của vụ việc (không riêng luật sư phụ trách), trưởng phòng, quản trị — tức người sửa được vụ
  việc VÀ có quyền công bố (sửa câu ngày 2026-10-03, M7 Task 11, theo rà soát Task 7). Trợ lý không
  rút được; họ nhờ người có quyền bấm "Rút lại".
- **Lý do** bắt buộc, tối thiểu 20 ký tự (`mb_strlen`, sau khi bỏ khoảng trắng hai đầu), tối đa 5000.
  Lý do **khách đọc được**, và là chữ duy nhất về tài liệu mà khách còn đọc được (dòng dưới).
- **Khách thấy gì.** Ở khối Tài liệu của trang hồ sơ, chỗ tài liệu từng hiện: nhãn trung tính "Tài
  liệu đã được văn phòng rút lại", câu "Văn phòng đã rút lại tài liệu này. Lý do: …" và ngày rút.
  **Không có tiêu đề tài liệu** (sửa ngày 2026-10-03, rà soát cuối M7, C1): ca rút điển hình là tài
  liệu của khách khác công bố nhầm, và dòng rút không bao giờ gỡ được (tài liệu đã rút không xoá,
  không vào nhóm D, không công bố lại được), nên một dòng mang tiêu đề sẽ để tên của khách kia trên
  cổng của khách này chừng nào vụ còn trên cổng. Ô lý do nói rõ cho người rút: tiêu đề không hiện,
  muốn khách biết là tài liệu nào thì nêu trong lý do. Không có đường tải; một đường dẫn tải ký
  trước lúc rút trả 404. Dòng này chỉ hiện trên vụ khách đang xem được (cùng khách, đã lên portal,
  chưa hết hạn tra cứu), không bao giờ cho tài liệu nhóm D.
- **Trạng thái cuối.** Tài liệu đã rút không công bố lại được; muốn đưa lại cho khách thì tải lên
  một bản mới. Tài liệu đã rút không vào gói bàn giao (§6.12).
- **Một đường rút duy nhất.** Với tài liệu đang ra tới khách, chuyển vào nhóm D bị từ chối (câu chỉ
  tới nút "Rút lại") và xoá không được phép; tài liệu đã rút cũng không xoá được và không vào nhóm D
  (dòng giải thích của khách đọc từ chính bản ghi đó). Thay cho gạch đầu dòng "chuyển vào nhóm D
  luôn được" của đính chính M6.5 ở trên: câu đó nay chỉ đúng cho tài liệu KHÔNG đang ra tới khách
  và chưa bị rút.

**Đính chính 2026-10-04 (M14, R2, R4, R8).** "disk `private`" ở câu trên đọc như sau. Tệp luôn được
GHI vào đĩa `private` (`storage/app/private/<media_id>/…`) trong transaction như trước: ba Action ghi
tệp (`UploadStaffDocument`, `SubmitClientDocument`, `BuildHandoverPackage`) không đổi. Khi kho đã bật
(`DOCUMENT_STORAGE=google_drive` VÀ đã chạy `vkcrm:storage:enable`, tức có mốc
`settings.storage.remote_enabled_at`), job `PushDocumentFile` trên hàng `storage`, xếp sau commit, đẩy
tệp lên đĩa `documents_remote` (Shared Drive), kiểm md5 do Google tính cùng kích thước, rồi đổi
`media.disk` bằng một câu UPDATE có điều kiện. Tệp tạo trước mốc bật chỉ lên kho qua
`vkcrm:storage:migrate`. Bản trong `private` thành **vùng đệm**: chỉ được dọn theo biên nhận của máy chủ
văn phòng (§10 mục 8). Mỗi media nằm trọn ở MỘT đĩa tại mỗi thời điểm; quay lui
(`vkcrm:storage:rollback`) chỉ chạy khi công tắc đã về `local`.
- Bảng `media` thêm bốn cột: `remote_pushed_at` (lúc đổi đĩa sang kho), `local_purge_after` (bản cục bộ
  giữ ít nhất tới lúc này; NULL = không còn bản cục bộ, chưa đẩy, hoặc đã quay lui), `checksum_md5`
  (char 32, tính từ vùng đệm lúc đẩy), `checksum_sha256` (char 64, bản ghi toàn vẹn của văn phòng).
- Trên Drive, tên tệp là khoá mờ `<media_id>~<ulid>.<đuôi>` (lần tải lại thứ N ≥ 2 thêm `~gN`) trong
  thư mục tháng `YYYY-MM`: không tên khách, không mã hồ sơ, không tiêu đề. Chỉ mục khoá → mã tệp Drive
  nằm trong CSDL (bảng hạ tầng `drive_objects`, `drive_folders`, `office_receipt_imports`; không màn
  hình, policy từ chối mọi người); `system_health` thêm năm cột trạng thái kho và biên nhận văn phòng.
- "Xoá" trên kho là cho vào thùng rác của Shared Drive; CRM không bao giờ xoá vĩnh viễn, không bao giờ
  ghi đè một tệp đã có.

### 4.12 `document_downloads` — nhật ký tải về

`document_id`, `downloader_type`, `downloader_id`, `ip` string(45),
`user_agent` string(500), `downloaded_at`.

Ghi log **mọi** lượt tải, cả nội bộ lẫn khách hàng.

**Đính chính 2026-09-28 (M7 Task 7).** Khoá ngoại `document_id` là `restrictOnDelete` (trước đó
`cascadeOnDelete`): xoá cứng một tài liệu đã có lượt tải bị CSDL từ chối, để bằng chứng khách đã
nhận tài liệu không bao giờ biến mất cùng tài liệu.

**Đính chính 2026-10-04 (M14, R3).** "Mọi lượt tải" đọc là mọi lượt tải mà máy chủ ĐÃ MỞ ĐƯỢC tệp.
Thứ tự của route tải: chữ ký, người nhận, policy → mở luồng đọc (`OpenStoredFile`; với kho là đúng một
`GET …alt=media`) → ghi dòng `document_downloads` và audit `document_downloaded` → stream. Kho sập lúc
mở luồng thì người dùng nhận trang 503 tiếng Việt kèm `Retry-After: 120` và KHÔNG có dòng nhật ký; chỉ
mục còn mà Drive trả 404 thì 404, log `critical`, không dòng. Luồng đứt giữa chừng sau khi đã ghi dòng
là khe chấp nhận có chủ đích: "lượt tải" nghĩa là văn phòng đã bắt đầu giao tệp cho người đó. `HEAD`
không mở luồng và không ghi dòng (như trước M14).

### 4.13 `deadlines` — mốc thời hạn

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK | |
| name | string(200) | |
| due_date | date | |
| severity | enum | `normal`, `critical` — `critical` là các hạn tố tụng không thể gia hạn |
| responsible_user_id | FK users | |
| is_completed | boolean default false | |
| completed_at | timestamp nullable | |
| is_published | boolean default false | Có hiện cho khách không |
| reminders_sent | json | Ghi các mốc nhắc đã gửi: `["d7","d3","d1","overdue"]` |

Index `(due_date, is_completed)`.

**Đính chính 2026-10-08 (M11 Task 17; §16 R5, R6).** Thêm ba cột: `created_via` string(20) default
`web`, enum `App\Enums\CreatedVia` (`web` "Nhập trên web", `mcp` "Tạo qua AI"); `confirmed_at`
timestamp nullable; `confirmed_by` FK users nullable (null khi người bị xoá). Mốc tạo qua tool
`create_deadline` luôn `created_via = mcp`, `is_published = false`, `created_by` là người
sở hữu token (server ép, không nhận từ tham số), và mang nhãn "Tạo qua AI, chưa xác nhận" trên tab
Mốc thời hạn cho tới khi một người bấm "Xác nhận" (`App\Actions\Deadline\ConfirmAiDeadline`: ghi
`confirmed_at`/`confirmed_by` — hai cột không `fillable` — và nhật ký `deadline_ai_confirmed`). Mốc
tạo qua AI vẫn được `CheckDeadlines` nhắc hạn như mọi mốc, xác nhận hay chưa: một mốc tố tụng thật bị
im lặng là thiệt hại thật (§6.8).

### 4.14 `client_requests` — yêu cầu từ khách

`matter_id`, `client_user_id`, `subject` string(200), `content` text,
`status` enum (`new`, `in_progress`, `answered`, `closed`),
`assigned_to` FK users nullable, `answered_at`.

Bảng con `client_request_replies`: `request_id`, `author_type`, `author_id`,
`content`, `created_at`.

### 4.15 `outbound_messages` — nhật ký thông báo gửi đi

`channel` enum (`email`, `zns`, `sms`), `recipient` string(200),
`template` string(80), `payload` json, `related_type`, `related_id`,
`status` enum (`queued`, `sent`, `failed`), `sent_at`, `error` text nullable.

Mục đích: khi khách nói "tôi không nhận được thông báo nào", phải tra được ngay.

**Đính chính 2026-10-04 (M12 Task 7, phán quyết R13):** `channel` thêm giá trị `push` (thông báo đẩy
trên điện thoại, nhãn "Thông báo đẩy"); cột `string(10)` đủ, không migration. Mỗi lần đẩy để lại MỘT dòng
cho MỖI máy (`App\Actions\Notification\RecordOutboundPush`, nghe `NotificationSent`/`NotificationFailed`
của gói `laravel-notification-channels/webpush`):
- `recipient` = `{bí danh morph}:{id}` của chủ máy (`client_user:12`, `user:7`) — **không bao giờ endpoint**
  (một URL mang quyền gửi);
- `template` = giá trị `App\Enums\PushTopic`, trùng tên mẫu thư mà nó đi cùng (`client.stage_update`…;
  riêng nút "Gửi thử" là `push.test`);
- `related_type`/`related_id` = ĐÚNG bản ghi mà dòng thư tương ứng mang, nên luật "ai xem dòng nào"
  không đổi;
- `payload` chỉ `title` (tên văn phòng) và `body` (một câu chung, không dữ liệu hồ sơ);
- `status` `sent`/`failed`; `error` = mã HTTP và lý do của máy chủ push (đã gỡ endpoint).

Dòng `push` không gửi lại được từ nhật ký (nút "Gửi lại" chỉ cho dòng `email`); màn hình nhật ký lọc được
theo kênh.

### 4.16 `matter_parties` — các bên trong vụ việc

Bảng này tồn tại vì một lý do duy nhất nhưng rất quan trọng: **kiểm tra xung đột
lợi ích**. Không có bảng này thì không có cách nào biết văn phòng đã từng nhận
bảo vệ cho bên đối lập trong một vụ khác.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK | |
| role | enum | `plaintiff` nguyên đơn, `defendant` bị đơn, `related` người có quyền lợi nghĩa vụ liên quan, `third_party` bên thứ ba, `opposing_counsel` luật sư đối phương |
| is_our_client | boolean | Bên này có phải khách hàng của văn phòng không |
| client_id | FK clients nullable | Điền khi `is_our_client = true` |
| name | string(200) | |
| id_number_hash | string(64) nullable | **SHA-256 của số căn cước/mã số thuế đã chuẩn hoá.** Lưu hash chứ không lưu số, để đối chiếu trùng mà không nhân bản dữ liệu định danh |
| phone_normalized | string(20) nullable | Chuẩn hoá về dạng `84xxxxxxxxx` để so khớp |
| address | string(300) nullable | |
| note | text nullable | |

Index `(id_number_hash)`, `(phone_normalized)`, `(matter_id)`.

### 4.17 `communication_logs` — nhật ký liên lạc

Cuộc gọi, buổi làm việc, email quan trọng trao đổi với khách hoặc với cơ quan
tiến hành tố tụng. Đây là thứ mà khi có tranh chấp về việc "văn phòng có thông
báo cho tôi không", sẽ trả lời được bằng bằng chứng.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK | |
| type | enum | `call_in`, `call_out`, `meeting`, `email`, `letter`, `court_visit` |
| occurred_at | datetime | |
| duration_minutes | unsignedSmallInteger nullable | |
| counterpart | string(200) | Nói chuyện với ai |
| summary | text | |
| is_visible_to_client | boolean default false | Mặc định nội bộ |
| created_by | FK users | |

**Đính chính 2026-09-28 (M7 Task 8).** Cột `is_visible_to_client` giữ nguyên, mặc định `false`,
nhưng **không có công tắc nào trên form** ghi nhật ký liên lạc và Action ghi
(`App\Actions\Communication\LogCommunication`) luôn ép `false`: không màn hình portal nào đọc bảng
này (§8.3, phán quyết 3 của M5), nên một công tắc chỉ khiến luật sư tin rằng khách đã thấy. Nhật ký
liên lạc là bằng chứng: không sửa được trên màn hình; xoá là xoá mềm kèm lý do bắt buộc và một dòng
audit (`communication_log_deleted`), không bao giờ xoá cứng. Ghi vào một vụ việc đòi đúng
`MatterPolicy::update` trên vụ đó (`CommunicationLogPolicy::create($user, $matter)`).

**Đính chính 2026-10-08 (M11 Task 17; §16 R5, R6).** Thêm cột `created_via` string(20) default `web`,
enum `App\Enums\CreatedVia` (`web` "Nhập trên web", `mcp` "Tạo qua AI"). Tool `log_communication` ghi
qua đúng `App\Actions\Communication\LogCommunication` (tham số mới `createdVia`, server ép `mcp`), cùng
cổng `CommunicationLogPolicy::create($user, $matter)`, `is_visible_to_client = false` và `created_by` là
người sở hữu token; tab "Liên lạc" hiện nhãn "Tạo qua AI" ở dòng đó. Không có bước "Xác nhận" như mốc
thời hạn: nhật ký liên lạc không nhắc ai và không tới khách.

### 4.18 `stage_log_views` — xác nhận khách đã đọc

| Cột | Kiểu |
|---|---|
| stage_log_id | FK |
| client_user_id | FK |
| viewed_at | datetime |
| ip | string(45) |

Ghi một lần cho mỗi cặp `(stage_log_id, client_user_id)` — lần xem đầu tiên.

Giá trị của bảng này không phải là thống kê. Nó là **bằng chứng đã thông báo cho
khách hàng**, có dấu thời gian. Trên giao diện nội bộ, mỗi dòng tiến độ đã công
bố hiển thị "Khách đã xem lúc 21:14 ngày 14/09" hoặc "Khách chưa xem" — và dòng
chưa xem quá 5 ngày thì nhắc luật sư gọi điện.

### 4.19 `matter_archives` — bàn giao và lưu trữ

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK unique | |
| archived_at | timestamp | |
| archived_by | FK users | |
| handover_package_path | string nullable | **Ngừng dùng** — xem đính chính M7 Task 3 dưới đây |
| handover_document_id | FK documents nullable | Gói bàn giao — xem đính chính M7 Task 3 |
| handover_generated_at | timestamp nullable | Thời điểm sinh gói XONG |
| handover_status | string(20) nullable | `generating` / `ready` / `failed`; NULL = chưa ai yêu cầu — xem đính chính M7 Task 4 |
| handover_requested_at | timestamp nullable | Lúc bấm (hoặc lúc vụ đóng, với lần tự sinh); cũng là dấu của lần yêu cầu |
| handover_requested_by | FK users nullable | Người bấm; NULL với lần tự sinh khi vụ đóng |
| handover_error | string(500) nullable | Câu tiếng Việt cho người vận hành khi `failed` |
| client_access_until | date nullable | Ngày vô hiệu quyền tra cứu của khách |
| retention_until | date | Ngày được phép tiêu huỷ dữ liệu theo chính sách lưu trữ |
| destroyed_at | timestamp nullable | |
| destruction_reason | text nullable | Lý do tiêu huỷ — xem đính chính M7 Task 3 |
| destruction_record_no | string(50) nullable | Số biên bản tiêu huỷ — xem đính chính M7 Task 3 |
| destroyed_by | FK users nullable | Người quyết định tiêu huỷ — xem đính chính M7 Task 3 |

**Đính chính 2026-09-28 (M7 Task 3, R1 — phán quyết của chủ nhiệm kế hoạch M7).** Gói bàn giao là
một bản ghi `Document` (nhóm B, đĩa `private`, qua đúng `PublishDocument`), không phải một chuỗi
đường dẫn: đường tải duy nhất của hệ thống (`documents.download`) nhận id của một `Document`, và
`document_downloads.document_id` là khoá ngoại tới `documents` — một đường dẫn trần sẽ cần dựng
thêm một cửa tải và một bảng nhật ký tải thứ hai, điều SPEC §4.12 ("ghi log **mọi** lượt tải") và
kiến trúc M4 không cho phép. `handover_document_id` (FK `documents`, nullable, `nullOnDelete`) thay
thế `handover_package_path`; cột cũ được GIỮ LẠI trên bảng (không `dropColumn`, tránh một thao tác
phá huỷ không cần thiết trên dữ liệu đã seed) nhưng không còn nằm trong `MatterArchive::$fillable`
— không còn đường ghi nào chạm tới nó. Sinh lại gói là một version mới của CÙNG tài liệu
(`parent_document_id`), không phải một tài liệu thứ hai. Ba cột `destruction_reason`/
`destruction_record_no`/`destroyed_by` chuẩn bị cho Task 6 (ghi quyết định tiêu huỷ — R5: không
bao giờ `forceDelete()` dữ liệu hồ sơ).

**Đính chính 2026-09-28 (M7 Task 4, R9).** Bốn cột `handover_status`, `handover_requested_at`,
`handover_requested_by`, `handover_error` ghi trạng thái của MỘT lần yêu cầu sinh gói, để màn hình
hiện "đang sinh / sẵn sàng / lỗi" kèm thời điểm bấm và thời điểm xong (`handover_generated_at`) và
khoá nút khi gói đang được dựng. `handover_requested_at` còn là dấu của lần yêu cầu: job mang theo
giá trị đó và chỉ được ghi kết quả khi nó còn khớp, nên một job cũ không ghi đè lần yêu cầu mới hơn.
Một lần `generating` cũ hơn 60 phút được coi là kẹt và cho yêu cầu lại.

**Đính chính 2026-10-03 (M7 Task 3, 5, 6 — ghi ở Task 11).** Vòng đời của dòng lưu trữ:
- *Ai ghi.* Dòng được tạo khi vụ việc vào giai đoạn kết thúc (`closed_at` có giá trị), bởi
  `SyncMatterArchive` qua sự kiện `MatterStageChanged` — không có form nào sửa các cột ngày của
  nó. Mỗi lần đóng (lại), `archived_at`, `client_access_until` và `retention_until` được tính lại
  từ `closed_at`. Vụ được mở lại thì chỉ `client_access_until` về NULL; phần còn lại của dòng giữ
  nguyên. Dòng không bao giờ bị xoá. Vụ bị huỷ vì mở nhầm (`CancelMatter`) không bao giờ có dòng
  này.
- *`client_access_until`* là ngày CUỐI khách còn tra cứu được (hết ngày đó, theo giờ ứng dụng);
  xem đính chính M7 Task 5 ở §6.12.
- *Bốn cột tiêu huỷ* (`destroyed_at`, `destroyed_by`, `destruction_reason`,
  `destruction_record_no`) chỉ do `RecordMatterDestruction` ghi, MỘT lần, không sửa được;
  `SyncMatterArchive` không bao giờ đụng tới chúng. Ghi quyết định không xoá gì — xem đính chính M7
  Task 6 ở §6.12.

**Đính chính 2026-10-04 (M14, R12, R15).**
- *Gói bàn giao.* Job dựng gói nay tải tệp từ kho về thư mục làm việc trước khi nén, nên ngưỡng
  "`generating` kẹt" ở đoạn trên là **90 phút** (`RequestHandoverPackage::STALE_AFTER_MINUTES`; 60 trước
  M14), giờ chết của job 1200 giây, `retry_after` của kết nối `handover` 1500 giây.
- *Huỷ tệp của hồ sơ đã quá hạn lưu.* Ghi quyết định tiêu huỷ (`RecordMatterDestruction`) vẫn không xoá
  gì. Tệp trên kho, ở máy văn phòng và trong vùng đệm trên máy chủ được huỷ bằng tay, có biên bản, theo
  sổ tay "Huỷ tệp của hồ sơ đã quá hạn lưu" (`docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`). Bước liệt kê:
  `php artisan vkcrm:storage:destruction-list <mã hồ sơ> --by=<email quản trị viên>` — từ chối khi vụ
  chưa có `destroyed_at` hoặc người `--by` không có quyền `recordDestruction`; in tên trên Drive (mọi
  thế hệ, kể cả bản đã vào thùng rác), đường vùng đệm và đường trong remote `crypt` của văn phòng, chỉ
  mã và tên mờ; ghi audit `matter_storage_destruction_listed`. Sau đó Manager dự phòng xoá vĩnh viễn
  trên Shared Drive (cả thùng rác), người giữ máy văn phòng `rclone deletefile` trong `crypt`, người vận
  hành xoá thư mục vùng đệm; archive CSDL cũ hết dần theo vòng giữ 30 bản. `vkcrm:storage:verify` và
  `vkcrm:storage:orphans` báo tệp của vụ đã ghi huỷ thành nhóm riêng, không tính là lỗi.

---

## 5. Phân quyền

Dùng `spatie/laravel-permission`. Quyền đặt tên dạng `<resource>.<action>`.

### Vai trò nội bộ và quyền

| Quyền | admin | manager | lawyer | assistant | accountant |
|---|---|---|---|---|---|
| `matter.viewAny` (mọi vụ việc) | ✓ | ✓ | — | — | danh sách rút gọn |
| `matter.view` (vụ có tên trong đội ngũ) | ✓ | ✓ | ✓ | ✓ | — |
| `matter.create` | ✓ | ✓ | ✓ | — | — |
| `matter.update` | ✓ | ✓ | ✓ (vụ của mình) | hạn chế | — |
| `matter.transitionStage` | ✓ | ✓ | ✓ (vụ của mình) | — | — |
| `stageLog.publish` | ✓ | ✓ | ✓ (vụ của mình) | — | — |
| `document.viewInternal` (nhóm D) | ✓ | ✓ | ✓ (vụ của mình) | — | — |
| `document.publish` | ✓ | ✓ | ✓ (vụ của mình) | — | — |
| `checklist.review` | ✓ | ✓ | ✓ | ✓ | — |
| `client.manage` | ✓ | ✓ | — | ✓ | — |
| `clientUser.manage` | ✓ | ✓ | ✓ | ✓ | — |
| `settings.manage` | ✓ | — | — | — | — |
| `auditLog.view` | ✓ | ✓ | — | — | — |

> **`document.publish` cũng là quyền xoá một tài liệu.** Quyết định ở M4 Task 2, ghi lại ở
> đây vì đọc riêng chữ "đưa tài liệu ra tới khách" thì không đoán ra: bảng trên không có
> quyền nào khác tách được "quyết định số phận một tài liệu" khỏi "làm hồ sơ thường ngày",
> vì mọi vai trò có `matter.view` đều có luôn `matter.update`. Cấp `document.publish` cho một
> vai trò mới là cấp luôn quyền xoá tài liệu của vai trò đó.

> **Bổ sung 2026-09-19, sửa 2026-09-24 (M9 — hợp đồng dịch vụ và thu phí theo đợt).** Danh sách 13 quyền ở trên được viết cho phạm vi bản 1.0, vốn **không có tiền** — §1 xếp "hợp đồng dịch vụ và đợt thanh toán, công nợ" vào phần ngoài phạm vi. M9 đưa chúng vào hệ thống, và không quyền nào trong 13 quyền trên diễn tả được chúng: `matter.view` là quyền đọc **nội dung hồ sơ**, còn tiền là một trục riêng — kế toán phải thấy tiền trong khi vẫn **không** được thấy nội dung, còn luật sư phải thấy tiền của vụ mình mà **không** thấy doanh thu toàn văn phòng. Thêm **bốn** quyền:
>
> | Quyền | admin | manager | lawyer | assistant | accountant |
> |---|---|---|---|---|---|
> | `billing.view` (hợp đồng, đợt thanh toán và khoản thu của một vụ việc) | ✓ | ✓ | ✓ (vụ của mình) | — | ✓ (**không kèm nội dung hồ sơ**) |
> | `contract.manage` (soạn, kích hoạt, ký phụ lục, huỷ hợp đồng) | ✓ | ✓ | ✓ (vụ của mình) | — | — |
> | `payment.record` (ghi nhận và huỷ một khoản thu) | ✓ | — | — (trừ vụ `restricted`, xem dưới) | — | ✓ |
> | `revenue.viewAny` (số liệu doanh thu toàn văn phòng, trang "Công nợ") | ✓ | ✓ | — | — | ✓ |
>
> **Ai thấy tiền của vụ nào: một định nghĩa.** Có `billing.view` **và** vụ nằm trong danh sách người đó được liệt kê (`Matter::listableBy`, cùng định nghĩa với danh sách vụ việc). Vì vậy tiền của vụ `restricted` (§4.6: "chỉ lead lawyer và quản trị thấy") chỉ luật sư phụ trách và admin thấy; kế toán và quản lý không thấy, kể cả trong số liệu tổng hợp. Trên vụ `restricted`, luật sư phụ trách ghi được khoản thu dù không có `payment.record`, vì ngoài admin không ai khác thấy vụ đó.
>
> Cặp `billing.view` / `revenue.viewAny` lặp lại đúng cặp `matter.view` / `matter.viewAny`.
>
> **Ranh giới của kế toán, viết ra vì đây là một sự nới rộng.** `billing.view` **không** làm câu "kế toán chỉ xem danh sách vụ việc, không thấy nội dung hồ sơ" sai đi: màn hình tiền của kế toán mang mã hồ sơ, loại vụ việc, tên khách hàng, tên đợt, các con số và các ngày — **không** mang tiêu đề vụ việc, tóm tắt, mô tả nội bộ, tài liệu, tiến độ hay các bên. Ranh giới này cài bằng một DTO readonly như `ConflictMatch` ở §6.10, có test. Điểm **mới thật sự** là **tên khách hàng**: không có tên thì không lập được phiếu thu — một sự nới rộng có chủ đích, cũng là một mục đích xử lý dữ liệu mới cần ghi vào PROGRESS.
>
> **`contract.manage` cũng là quyền đổi số tiền của từng đợt** qua phụ lục, kèm lý do, có dấu vết.
>
> **Đính chính 2026-10-03 (M9 Task 13, phán quyết rà soát Task 3) — miễn một đợt cũng thuộc `contract.manage`.** Miễn một đợt thanh toán (`WaiveInstalment`, lý do ≥ 20 ký tự) là xoá một khoản nợ, tức một quyết định thương mại về hợp đồng, không phải việc ghi tiền: `InstalmentPolicy::waive` đòi `contract.manage` cộng "thấy tiền của vụ" (định nghĩa trên) — tức mọi luật sư trong đội của vụ (kể cả luật sư phối hợp, không riêng luật sư phụ trách), quản lý và admin, đúng dấu "✓ (vụ của mình)" của bảng trên; trên vụ `restricted` chỉ luật sư phụ trách và admin (hai người duy nhất thấy tiền của vụ đó); **kế toán không miễn được** (họ có `payment.record`, không có `contract.manage`). *(Sửa 2026-10-04, rà soát cuối làn m9f: bản 2026-10-03 ghi "luật sư phụ trách, quản lý, admin" — hẹp hơn quyền mã thật cấp; mã đúng theo bảng, câu này sửa theo mã.)* Huỷ một đợt của hợp đồng đang hiệu lực thì không có quyền riêng: nó chỉ đi qua phụ lục (`AmendContract`), cũng dưới `contract.manage`.
>
> **Đính chính 2026-10-03 (M9 Task 10, rà soát vòng 1) — tải gói bàn giao là đọc tiền.** Từ M9, `MUC-LUC.pdf` trong gói bàn giao in "Bảng kê thanh toán" (§6.12). Gói là một tài liệu nhóm B của vụ, nên trước bản sửa mọi nhân sự có `matter.view` trên vụ — kể cả **trợ lý** trong đội, vai trò không có `billing.view` — tải được gói và đọc được toàn bộ tiền của vụ. Nay `DocumentPolicy::download` của nhân sự đòi thêm, **chỉ cho các version của gói bàn giao** và **chỉ khi vụ có hợp đồng đã từng ký** (khác `draft` — kể cả `cancelled`, vì gói dựng trước lần huỷ vẫn in bảng kê): người tải phải thấy được tiền của vụ theo đúng định nghĩa trên (`ContractPolicy::view`). Không có định nghĩa thứ hai. Người không tải được gói vẫn thấy dòng gói (tên, version) trên tab Tài liệu, chỉ mất nút "Tải"; mọi tài liệu khác của vụ, và gói của vụ chưa từng có hợp đồng đã ký, không đổi luật. Khách không chịu điều kiện này (bảng kê là thứ §5 phần Portal cho khách xem về vụ của chính họ).

> **Bổ sung 2026-10-04 (M13 — theo dõi đội ngũ và hiệu suất).** Số liệu theo dõi và hiệu suất **theo người** (§6.14, ba trang ở §7.5) là dữ liệu truy cập về nhân sự, không phải một màn hình thao tác, nên nó nằm trong bảng vai trò này như M9 đã làm với tiền. Thêm **một** quyền (13 quyền gốc + 4 quyền tiền của M9 + 3 quyền tiếp nhận của M10 + 1 quyền của M13 = **21**, đúng số trường hợp của enum `Permission`; *sửa 2026-10-07, rà soát cuối làn M13: bản trước ghi "13 + 4 + 1", bỏ sót ba quyền `intake.*` của bổ sung M10 ngay dưới*):
>
> | Quyền | admin | manager | lawyer | assistant | accountant |
> |---|---|---|---|---|---|
> | `performance.viewAny` (số liệu theo dõi và hiệu suất của **mọi** nhân sự được theo dõi; trang "Theo dõi đội ngũ") | ✓ | ✓ | — | — | — |
>
> **Số của chính mình không cần quyền mới.** Người thuộc danh sách theo dõi có `matter.view` (luật sư, trợ lý; quản lý thì đã có `performance.viewAny`) xem được trang của chính mình và dòng của chính mình trên "Hiệu suất theo kỳ". Một luật: `UserPolicy::viewPerformance(người xem, người được xem)` = (có `performance.viewAny` **hoặc** người xem chính là người được xem và có `matter.view`) **và** người được xem thuộc danh sách theo dõi (§6.14: luật sư, trợ lý, quản lý, chưa xoá mềm; người đã nghỉ việc vẫn thuộc danh sách). Quản lý xem được trang của một quản lý khác. Admin và kế toán không thuộc danh sách, nên trang của họ là cùng một 404 với id không tồn tại (§10 mục 10).
>
> **Kế toán: không gì cả** — 404 ở cả ba trang, kể cả trang "của mình". Phần đi sâu mang tên mốc thời hạn, chủ đề yêu cầu của khách, tên đầu mục giấy tờ — nội dung hồ sơ mà §1 và "Ranh giới của kế toán" ở trên giữ ngoài tầm của kế toán. Câu hỏi "doanh thu theo luật sư" của kế toán đã có trên trang Doanh thu (bộ lọc luật sư).
>
> **Cột doanh thu** trên dòng của một người chỉ hiện khi người xem được xem người đó (luật trên), **và** có `billing.view`, **và** (có `revenue.viewAny` **hoặc** là chính người đó) — `UserPolicy::viewPerformanceRevenue()`. Luật sư thấy doanh thu của chính mình, như trang Doanh thu cho họ thấy tiền của vụ mình; trợ lý không có `billing.view` nên không thấy cột này.
>
> Mọi vai trò có `performance.viewAny` cũng có `matter.viewAny` (test cấu trúc trên `Role::permissions()`): nhờ vậy số xu hướng (ảnh chụp số liệu hằng ngày) mà người có `performance.viewAny` đọc bằng đúng số trực tiếp cùng ngày. Luật không lộ vụ `restricted` của ảnh chụp KHÔNG dựa vào điều này: nó nằm ở `PerformanceSnapshot::visibleLevels()`, hỏi `matter.viewAny` (và `Matter::isListableBy()` cho dòng `restricted`), không hỏi `performance.viewAny` (§6.14, "Phạm vi xem"; đính chính 2026-10-07, M13 Task 8). Số liệu này không bao giờ rời hệ thống qua MCP (M11): không tool, không presenter nào tham chiếu các lớp `Performance` hay bảng `performance_snapshots` — test cấu trúc `PerformanceMcpBoundaryTest`, xanh vì rỗng cho tới khi M11 gộp, rồi canh từ đó. Máy chủ đã có dữ liệu nhận quyền mới qua `php artisan db:seed --force` (`RolesAndPermissionsSeeder`).

**Mang sang M11, ghi 2026-09-24 (M9 Task 3), viết lại 2026-10-03 (M9 Task 13).** Dữ liệu
tiền là dữ liệu nhạy cảm ("tài chính", Nghị định 356/2025): **tiền của vụ việc không bao giờ
rời hệ thống qua MCP** (`contracts`, `instalments`, `payments`, `contract_amendments`, và
khung `time_entries`), kể cả với người được xem tiền đó trên web. Đây là một YÊU CẦU cho M11,
chưa phải một sự thật của mã: M9 gộp vào `main` TRƯỚC M11 (thứ tự thực tế khác thứ tự đã chốt),
và ngày 2026-10-03 nhánh `m11-mcp-server` chưa có dòng này. M11 phải (1) thêm dòng "tiền của vụ
việc — không bao giờ" vào bảng R4 của kế hoạch M11 (`docs/superpowers/plans/2026-09-24-m11-mcp.md`,
"Các loại dữ liệu không bao giờ rời hệ thống qua MCP"); (2) không liệt kê năm model đó trong
presenter theo danh sách cho phép (R4 cài bằng danh sách cho phép, nên "không liệt kê" là đủ để
chúng không ra); (3) thêm một test cấu trúc khẳng định không tool hay presenter nào dưới
`app/Mcp` / `app/Support/Mcp` tham chiếu `Contract`, `Instalment`, `Payment`,
`ContractAmendment`, `TimeEntry`. M9 không viết được test đó vì thư mục MCP chưa có trên `main`;
việc mang sang ghi ở PROGRESS ("Ghi chú M9", làn m9f, Task 13).
**Đã làm 2026-10-08 (M11 Task 17):** (1) dòng "Tiền của vụ việc" trong bảng R4 của kế hoạch M11 và
ở §16.4; (2) không presenter MCP nào liệt kê năm model đó; (3)
`tests/Feature/Mcp/MoneyMcpBoundaryTest.php` quét MỌI tệp MCP (cùng danh sách với phép quét tiếp nhận,
`Tests\Support\McpSourceScan`) tìm mọi tên — lớp, không gian tên, quan hệ, hàm, cột, bảng, bí danh
morph, quyền, biến, từ trong chuỗi và Blade — CHỨA ở bất kỳ vị trí nào một từ tiền (`contract`,
`instalment`/`installment`, `payment`, `amendment`, `time_entr`, `billing`, `revenue`,
`receivable`; nên bắt cả `App\Support\Billing\BillingSummary`, `triggeredInstalments`,
`paymentReceipts`), chỉ miễn đoạn không gian tên `Contracts` (`Illuminate\Contracts\…`); cộng lượt
quét hành vi: bộ dữ liệu của `SensitiveDataSweepTest` mang hợp đồng, đợt (đến hạn vì một dòng tiến
độ đã công bố), khoản thu, phụ lục và giờ làm có kim trên vụ mở của admin — kể cả dư nợ tính ra
665.334.000 — và không kim nào ra khỏi bất kỳ tool nào.

> **Bổ sung 2026-09-24 (M10 — tiếp nhận và thẩm định đầu vào).** Bảng 13 quyền gốc và bốn quyền tiền của M9 không có dòng nào cho một người **chưa phải khách hàng**: một lần có người gọi điện, nhắn Zalo hay bước vào văn phòng. M10 thêm bản ghi tiếp nhận (`intake_requests`) và ba quyền, nâng bảng từ 17 lên **20**:
>
> | Quyền | admin | manager | lawyer | assistant | accountant |
> |---|---|---|---|---|---|
> | `intake.create` (ghi một lần liên hệ; đổi trạng thái bản ghi mình ghi hoặc được giao) | ✓ | ✓ | ✓ | ✓ | — |
> | `intake.viewAny` (mọi bản ghi, kể cả câu chuyện và lý do từ chối vì xung đột; xử lý Đỏ; báo cáo đầu vào) | ✓ | ✓ | — | — | — |
> | `intake.convert` (chuyển thành vụ việc; **cần thêm** `matter.create`) | ✓ | ✓ | ✓ | — | — |
>
> **Ai thấy bản ghi nào: một định nghĩa** (`IntakeRequest::scopeVisibleTo`). Có `intake.viewAny` thì thấy mọi bản ghi; chỉ có `intake.create` thì thấy bản ghi **mình ghi hoặc được giao**, kể cả câu chuyện của chúng — trợ lý A không thấy bản ghi của trợ lý B. Cặp `intake.create` / `intake.viewAny` lặp lại đúng cặp `matter.view` / `matter.viewAny`. Luật sư chỉ chuyển đổi được bản ghi mình thấy; trợ lý không có `matter.create` nên không chuyển đổi.
>
> **Bản ghi đã chuyển thành vụ `restricted` (bổ sung 2026-09-30, vòng sửa 1 của Task 1)** chỉ thấy được với người xem được vụ đó (admin, luật sư phụ trách còn `matter.view`): bản ghi mang tên khách, câu chuyện và liên kết `client_id`/`matter_id`, nên `intake.viewAny` — hay việc đã ghi/được giao bản ghi — không được là cửa vào vụ hạn chế. Cùng định nghĩa `scopeVisibleTo`/`isVisibleTo`; vụ thường không đòi thêm gì. Mọi màn hình đọc bản ghi (danh sách, widget, báo cáo) phải đi qua định nghĩa này, không tự lọc lại.
>
> **Đính chính 2026-10-04 (rà soát cuối M10, vòng sửa 1).** Ba chỗ định nghĩa trên chưa phủ: (1) **chuỗi gộp** — gộp để tên, SĐT và câu chuyện ở lại bản nguồn, nên khi bản cuối của chuỗi thành vụ `restricted`, mọi bản đã gộp vào nó cũng chỉ thấy được với người xem được vụ đó (cột `intake_requests.merge_chain_matter_id`, `ConvertIntakeToMatter` đóng dấu lúc chuyển đổi; migration điền ngược cho chuỗi đã chuyển đổi trước đó); (2) **trang Nhật ký hệ thống** — dòng có chủ thể là một bản ghi tiếp nhận (dòng kiểm tra xung đột mang tên người liên hệ và tên các bên) chỉ hiện cho người xem được chính bản ghi đó (`ActivityOwningMatter`); (3) **lý do từ chối** — lý do của MỌI lần từ chối, không chỉ lần vì xung đột, chỉ người có `intake.viewAny` (và chính người đã từ chối) đọc; nếu lý do thường hiện cho mọi người thì "không có dòng lý do" tự nói "vì xung đột". Thêm (R1 của kế hoạch M10, nguyên văn): với một khớp **Đỏ**, người không xử lý được Đỏ chỉ thấy mã hồ sơ và vai của bên trùng trên trang tiếp nhận — không tên, không loại vụ việc, không tiêu chí khớp; khớp Vàng giữ đủ ranh giới §6.10 vì người nhập phải tự xem trước khi xác nhận.
>
> **Kế toán không thấy gì của tiếp nhận**, vì SPEC §1 tách kế toán khỏi nội dung hồ sơ và người liên hệ không có khoản tiền nào để thu.
>
> **Xử lý xung đột Đỏ lúc tiếp nhận** (mở ô câu chuyện, từ chối vì xung đột) là **một** định nghĩa cho cả hệ thống: `ConflictOverride::allowedFor()` — vai `manager` hoặc `admin`, cùng cổng ghi đè Đỏ của `OpenMatter` (§6.10) — cộng với việc xem được bản ghi. Hôm nay nó trùng người với `intake.viewAny`, nhưng đọc theo **vai**, không theo quyền, để hai nơi không thể lệch nhau. **Lý do từ chối vì xung đột** chỉ người có `intake.viewAny` thấy; người khác thấy "Đã từ chối". **Xoá dữ liệu theo yêu cầu** của chủ thể chỉ admin.
>
> **Không ai xoá một bản ghi tiếp nhận.** Xoá là **ẩn danh** (các trường cá nhân về null, dòng ở lại để thống kê). Cổng khách đóng kín: người liên hệ chưa có tài khoản.

**Mang sang M11, ghi 2026-09-24 (M10 Task 1).** Câu chuyện và danh tính của người
**chưa thành khách** là dữ liệu nhạy cảm và bên thứ ba không thể đồng ý; Luật Luật
sư giữ bí mật cả với người chưa thành khách. Bảng R4 của kế hoạch M11 thêm một
dòng: **tiếp nhận, kể cả câu chuyện — không bao giờ** (`intake_requests`,
`intake_parties`). Test cấu trúc `tests/Feature/Intake/IntakeMcpBoundaryTest.php`
(M10 Task 1) quét `app/Mcp`, `app/Support/Mcp`, `app/Actions/Mcp` và đỏ nếu một
tệp nào tham chiếu `IntakeRequest` hay `IntakeParty`; nó xanh từ hôm nay dù các thư
mục chưa tồn tại, và canh từ lúc M11 thêm tệp đầu tiên.

Cài bằng Policy cho từng model. `MatterPolicy::view()` kiểm tra: người dùng có
`matter.viewAny`, **hoặc** có bản ghi trong `matter_user`. Vụ việc
`confidentiality = restricted` thì chỉ `lead_lawyer_id` và vai trò `admin`.

### Portal

Guard `client` không dùng spatie. Quyền của khách là cố định và cài bằng global
scope + policy:

- Thấy `Matter` khi `matter.client_id = auth('client')->user()->client_id`
  **và** `matter.is_published_to_portal = true`.
- Thấy `StageLog` khi thuộc matter hợp lệ **và** `is_published = true`.
- Thấy `Document` khi thuộc matter hợp lệ **và** `client_can_view = true`
  **và** `group != 'D'`.
- Tải `Document` khi thêm điều kiện `client_can_download = true`.
- Thấy `Deadline` khi thuộc matter hợp lệ và `is_published = true`.
- Tạo và xem `ClientRequest` của chính mình.
- Nộp tài liệu vào `matter_checklist_items` thuộc matter hợp lệ.

Cài đặt bắt buộc: các điều kiện trên phải nằm trong **global scope** áp lên model
khi guard đang hoạt động là `client` (`app/Support/Scopes/`), không phải viết
`where()` ở từng resource. Một chỗ quên là một vụ rò rỉ dữ liệu.

**Đính chính 2026-09-27 (M6.5 Task 21, `requests/REQ-8`).** "Của chính mình" ở dòng
`ClientRequest` đọc là **của khách hàng (`Client`)**, không phải của riêng tài khoản đăng nhập
(`ClientUser`) đã mở luồng: mọi tài khoản cổng của cùng một khách hàng (ví dụ người nhà) đọc và
viết được vào cùng một luồng yêu cầu. Mã làm đúng như vậy từ M5:
`ClientRequest::applyClientPortalConstraints()` chỉ đòi luồng thuộc một vụ việc khách xem được
(`whereHas('matter')`, và vụ việc tự lọc theo `client_id` cộng `is_published_to_portal`), không
lọc theo `client_user_id`. Trước bản đính chính này, cách đọc đó chỉ nằm trong docblock. M6.5 Task
18 thêm trên trang "Yêu cầu của tôi" một dòng nói rõ điều này, và chỉ gắn nhãn "Anh/chị viết"
cho câu của chính tài khoản đang xem; câu của tài khoản khác cùng khách hàng mang tên người viết.

**Đính chính 2026-10-03 (M9 Task 10, phán quyết P1 của chủ văn phòng).** Danh sách trên có thêm
**loại dữ liệu thứ tám**: hợp đồng và lịch thu của chính khách, CHỈ ĐỌC.
- Thấy `Contract` khi thuộc matter hợp lệ **và** `status` là `active` hoặc `completed` (không bao
  giờ bản nháp hay bản đã huỷ).
- Thấy `Instalment` khi thuộc hợp đồng thấy được **và** `status != cancelled` (đợt đã miễn vẫn hiện,
  chỉ một câu "Văn phòng đã miễn", không lý do).
- Thấy `Payment` khi thuộc đợt thấy được **và** `voided_at` rỗng.
- Thấy `ContractAmendment` khi thuộc hợp đồng thấy được (mở theo chữ kế hoạch M9; trang cổng không
  vẽ phụ lục, lý do và bản scan không bao giờ ra cổng).

"Matter hợp lệ" ở đây là ĐÚNG ranh giới cổng của vụ việc — năm điều kiện của
`Matter::applyClientPortalConstraints()`: đúng khách, `is_published_to_portal`, vụ chưa xoá mềm,
khách hàng chưa xoá mềm, chưa quá `client_access_until` (§6.12). Tiền không có ranh giới thứ hai.
Khách không liệt kê, soạn, sửa, xoá, miễn, ghi hay huỷ gì. Cột nội bộ không bao giờ ra cổng: ghi
chú, lý do huỷ hợp đồng/miễn đợt/huỷ khoản thu/phụ lục, người kích hoạt/miễn/huỷ/ghi/sửa, luật sư
được tính doanh thu, biên lai và bản scan phụ lục (nhóm D), phần trăm người soạn đã gõ. `TimeEntry`
vẫn đóng kín. Vụ `restricted` không đổi điều gì ở đây: "chỉ luật sư phụ trách và admin thấy tiền"
(P3) là luật của NHÂN SỰ; khách là bên đã ký hợp đồng đó. Ba tầng (scope, policy, serialize) đo
độc lập ở `tests/Feature/Portal/BillingOnPortalTest.php`.

---

## 6. Logic nghiệp vụ

### 6.1 Sinh mã hồ sơ

`{MATTER_CODE_PREFIX}-{YYYY}-{mã loại}-{số thứ tự 4 chữ số}`, ví dụ
`VK-2026-DD-0147`. Số thứ tự chạy theo từng năm và
từng loại. Sinh trong transaction có khoá để tránh trùng khi hai người tạo cùng
lúc. Mã không bao giờ đổi sau khi tạo.

### 6.2 Chuyển giai đoạn — `TransitionMatterStage`

Đầu vào: `Matter`, `to_stage`, `occurred_at`, `internal_note`, `public_content`,
`next_step`, `client_action`, `expected_next_update_at`, `publish` (bool).

Các bước, trong một transaction:

1. Kiểm tra `to_stage` nằm trong `allowed_next` của giai đoạn hiện tại. Nếu
   không, ném `InvalidStageTransition`. Vai trò `admin` được phép bỏ qua kiểm
   tra này nhưng phải ghi vào activity log là đã bỏ qua.
2. Kiểm tra quyền qua `MatterPolicy::transitionStage`.
3. Nếu `publish = true`: bắt buộc `public_content` ≥ 30 ký tự.
4. Tạo `StageLog`. Nếu không nhập `expected_next_update_at`, tự tính bằng
   `now() + default_next_update_days` của giai đoạn mới.
5. Cập nhật `matters.stage`, `stage_entered_at`.
6. Nếu `publish = true` **và** `matter.is_published_to_portal = true`: cập nhật
   `matters.last_client_update_at`, và dispatch `StageLogPublished` event.
7. Ghi activity log.

Listener của `StageLogPublished` dispatch job `SendStageUpdateNotification`
(queue). Job kiểm tra `stage_logs.notified_at` — nếu đã có thì thoát ngay, không
gửi lại. Gửi xong ghi `notified_at` và tạo bản ghi `outbound_messages`.

### 6.3 Dòng cập nhật không đổi giai đoạn

Rất quan trọng và hay bị bỏ sót: **phải cho phép thêm một dòng cập nhật mà không
đổi giai đoạn.** Đây là cách ghi những tin kiểu "tuần này chưa có văn bản mới từ
toà, đây là điều bình thường ở giai đoạn này, dự kiến có tin trước ngày 25/09".
Lúc đó `from_stage` và `to_stage` đều bằng giai đoạn hiện tại.

Trên giao diện, nút này phải nổi bật ngang với nút chuyển giai đoạn, và mẫu nội
dung mặc định điền sẵn cho luật sư sửa.

### 6.4 SLA cập nhật 14 ngày

Job `CheckStaleMatters` chạy hằng ngày 07:30:

- Vụ việc chưa đóng, `is_published_to_portal = true`, và
  `last_client_update_at` cũ hơn 14 ngày → thông báo trong hệ thống cho
  `lead_lawyer_id`.
- Cũ hơn 21 ngày → gửi email cho lead lawyer, đồng gửi mọi user có vai trò
  `manager`.
- Hiển thị widget "Hồ sơ quá hạn cập nhật" trên trang chủ admin panel.

### 6.5 Công bố tài liệu — `PublishDocument`

1. Chặn tuyệt đối `group = 'D'`. Ném exception. Có test.
2. Với `group = 'B'`: yêu cầu `status = signed_filed`. Nếu chưa, ném exception
   kèm thông điệp rõ ràng.
3. Đặt `client_can_view`, `client_can_download` theo lựa chọn của người công bố
   (hai cờ độc lập — có tài liệu cho khách biết là đã có nhưng chưa cho tải).
4. Đặt `status = published`, `published_at`, `published_by`.
5. Ghi activity log. Dispatch thông báo cho khách nếu là tài liệu quan trọng
   (nhóm C hoặc B).

### 6.6 Khách nộp tài liệu

Luồng ở `app/Actions/Document/SubmitClientDocument`:

1. Kiểm tra quyền: checklist item thuộc matter mà client này được xem.
2. Kiểm tra phần mở rộng theo danh sách trắng: `pdf`, `jpg`, `jpeg`, `png`,
   `doc`, `docx`, `xls`, `xlsx`. **Từ chối** `zip`, `rar`, `html`, `svg`, `exe`
   và mọi thứ khác. SVG bị cấm vì có thể chứa JavaScript.
3. Kiểm tra MIME thực tế bằng `finfo`, không tin `Content-Type` từ client.
4. Giới hạn 20 MB mỗi tệp, cấu hình qua `.env`.
5. Nếu `CLAMAV_ENABLED=true` thì quét bằng ClamAV. Mặc định tắt, nhưng phải có
   interface `VirusScanner` và một implementation `NullScanner` để bật lên sau
   không phải sửa logic.
6. Lưu qua medialibrary, disk private, tên tệp sinh ngẫu nhiên.
7. Tạo `Document` nhóm A. Nếu checklist item đã có tài liệu trước đó: tạo bản
   mới với `version + 1` và `parent_document_id` trỏ bản cũ. **Không ghi đè.**
8. Đặt `matter_checklist_items.status = pending_review`.
9. Thông báo trong hệ thống cho lead lawyer và trợ lý.

### 6.7 Duyệt tài liệu khách nộp — `ReviewChecklistItem`

Nhân viên chọn `accepted` hoặc `rejected`. Nếu `rejected` thì bắt buộc nhập lý do
≥ 20 ký tự, và lý do đó hiện thẳng cho khách trên portal.

Giao diện phải có sẵn 3 mẫu lý do bấm một cái là điền, để trợ lý không viết cụt
lủn kiểu "không hợp lệ":

- "Ảnh bị mờ ở góc trên nên không đọc được số thửa. Nhờ anh/chị chụp lại dưới
  ánh sáng tự nhiên, lấy trọn cả bốn góc trang."
- "Bản này là bản photo chưa chứng thực. Toà yêu cầu bản sao có chứng thực,
  anh/chị mang bản gốc ra Uỷ ban phường hoặc phòng công chứng để chứng thực giúp
  em."
- "File này là [tên tài liệu đã nộp], còn mục đang cần là [tên đầu mục].
  Anh/chị kiểm tra lại giúp em nhé."

### 6.8 Nhắc hạn tố tụng — `CheckDeadlines`

Chạy hằng ngày 07:00. Với mỗi deadline chưa hoàn thành:

| Số ngày còn lại | Hành động | Người nhận |
|---|---|---|
| 7 | Email nhắc lần một | responsible_user |
| 3 | Email nhắc lần hai | responsible_user + trợ lý trong đội ngũ |
| 1 | Email khẩn | responsible_user + toàn bộ vai trò `manager` |
| < 0 | Đánh dấu quá hạn, tạo thông báo cảnh báo | responsible_user + `manager` |

Cột `reminders_sent` chống gửi trùng. Deadline `severity = critical` thì thêm
mốc nhắc ở 14 ngày.

**Đính chính 2026-09-27 (M6.5 Task 8 và 12, ghi ở Task 21; phán quyết R3).** Trước M6.5, thư
nhắc của một vụ `restricted` đi tới manager và trợ lý không được xem vụ đó (`deadlines/F2`,
`notify/notify-3`), và người phụ trách bị khoá thì mốc im lặng (`deadlines/F4`,
`notify/notify-4`). Luật đã cài:

- **Người nhận là người được xem vụ**: đang `is_active`, chưa xoá, và qua
  `Gate::forUser($u)->allows('view', $matter)`. Mọi thư và thông báo trong hệ thống gửi cho nhân
  sự về một vụ việc đi qua `App\Actions\Notification\ResolveStaffRecipients`, không riêng
  `CheckDeadlines`.
- **"Toàn bộ vai trò `manager`"** trong bảng trên đọc là **mọi manager xem được vụ đó**
  (`ResolveStaffRecipients::supervisorsFor()`); với vụ `restricted` thì là mọi admin đang hoạt
  động thay cho manager.
- **Người phụ trách không hợp lệ** (bị khoá, bị xoá, không còn xem được vụ) thì luật sư phụ trách
  vụ thế chỗ ở mọi bậc. Cả hai đều không hợp lệ thì `supervisorsFor()` được thêm vào ở mọi bậc,
  không chỉ bậc 1 ngày và quá hạn.
- **Không bao giờ im lặng:** nếu không ai trong danh sách ưu tiên hợp lệ, chuỗi dự phòng là luật
  sư phụ trách → một manager xem được vụ → một admin xem được vụ.
- Job gửi thư suy lại người nhận lúc thật sự gửi, và không gửi lại cho người đã nhận thư của đúng
  mốc và bậc đó khi hàng đợi thử lại.

**Đính chính 2026-09-24 (M9).** Thư nội bộ về **tiền** (`staff.instalment_overdue`,
§9 — nhắc đợt thanh toán quá hạn) đi tới người **được xem tiền của vụ** (có
`billing.view` và vụ nằm trong `Matter::listableBy` của họ, §5 bổ sung M9), không
phải người được xem vụ: vụ thường gửi kế toán và luật sư phụ trách; vụ `restricted`
gửi luật sư phụ trách và admin. Người nhận lấy qua cùng lớp tìm người nhận của thư
nội bộ, với cổng "được xem tiền của vụ" — không một định nghĩa thứ hai. Chống gửi
trùng qua `outbound_messages`; bảng `instalments` không có cột `reminders_sent`.

**Đính chính 2026-09-30 (M9 Task 11, đối chiếu với mã đã cài).** `RemindOverdueInstalments`
chạy 08:00 hằng ngày (một lần mỗi ngày, không lặp trong ngày như nhắc hạn). Hành vi thật:

- **Chọn đợt:** đúng định nghĩa "quá hạn" của `Instalment::overdue()` (đợt `pending`, hạn
  trước hôm nay, hợp đồng `active`, còn phải thu > 0 — đợt thu một phần đã quá ngày vẫn
  quá hạn) cộng vụ chưa xoá mềm. **Vụ đã kết thúc (`closed_at`) vẫn được nhắc**: nợ không
  biến mất khi đóng hồ sơ (khác nhắc hạn, vốn bỏ qua vụ đã đóng).
- **Người nhận:** `ResolveStaffRecipients::forBilling()` với
  `billingAudienceFor()` (quyết định vai trò nằm trong lớp): vụ thường = luật sư phụ trách
  + mọi kế toán đang hoạt động; vụ `restricted` = luật sư phụ trách + mọi admin đang hoạt
  động. Quản lý không nhận ở vụ thường (họ xem trang "Công nợ" khi cần). **Chuỗi dự phòng
  R3 chỉ chạy khi cả danh sách trên không còn ai hợp lệ**: luật sư phụ trách → một quản lý
  → một admin, mỗi tầng qua cùng cổng tiền (nên vụ `restricted` tự rơi xuống admin).
- **Nhịp:** ngày đầu tiên quá hạn, rồi bảy ngày lịch một lần (mốc 1, 8, 15, …), cho tới khi
  thu đủ, miễn hoặc huỷ. Chống trùng theo **từng người nhận**: một dòng `sent` của
  (`staff.instalment_overdue`, đợt, người nhận, ngày đến hạn) trong bảy ngày lịch gần nhất
  chặn thư mới; dòng `failed`/`queued` không chặn, nên thư hỏng được gửi lại ở lượt sau.
  Khoá mang **ngày đến hạn**: phụ lục dời hạn sang ngày khác là một "đợt quá hạn mới" và
  được nhắc ngay ngày đầu, không bị nuốt bởi lời nhắc của ngày cũ. Kiểm cả lúc xếp job lẫn
  lúc job gửi; job đọc lại đợt, hợp đồng, vụ việc và người nhận lúc chạy.
- **Liên kết trong thư:** người mở được trang "Công nợ" (`billing.view` + `revenue.viewAny`
  — admin, quản lý, kế toán) nhận liên kết tới trang đó; người còn lại (luật sư phụ trách)
  nhận liên kết tới tab "Hợp đồng và thanh toán" của vụ. Đây là thư nội bộ đầu tiên có
  liên kết.
- Thư hỏng để lại dòng `failed` trong `outbound_messages`, không chặn người nhận khác, không
  gây lỗi 500; không có thông báo trong ứng dụng khi hỏng hẳn (khác nhắc hạn) vì tác vụ chạy
  lại mỗi ngày.

**Đính chính 2026-10-03 (M9 Task 6, ghi ở Task 13) — tác vụ hằng ngày thứ hai của tiền.**
`ReconcileStageTriggeredInstalments` chạy 07:00 hằng ngày (`instalments.reconcile-stage`,
`withoutOverlapping(60)`), TRƯỚC lượt nhắc quá hạn 08:00: một đợt vừa được đối chiếu kích hoạt
với hạn ghi lùi được nhắc ngay sáng đó. Nó là lưới an toàn cho đợt thanh toán theo giai đoạn —
bình thường đợt đến hạn ngay khi luật sư chuyển giai đoạn (sự kiện `MatterStageChanged` của M7 →
listener → `TriggerInstalmentsForStage`), lúc kích hoạt hợp đồng, hoặc lúc ký phụ lục thêm đợt
cho giai đoạn vụ đã qua; tác vụ này bắt những lần lỡ (một lần kích hoạt hỏng, dòng `stage_logs`
ghi thẳng không qua `TransitionMatterStage`). Nó chỉ đọc dòng `stage_logs` có thật: một giai
đoạn vụ chưa từng có dòng nào VÀO (vụ nhập thẳng vào giữa chừng lúc bắt đầu dùng hệ thống) không
bao giờ kích hoạt đợt của nó — `docs/QUY-TRINH.md`, "Nhập hợp đồng đang chạy". Không gửi thư,
không xếp hàng đợi; lỗi của một cặp (vụ, giai đoạn) được báo và không chặn các cặp khác.

### 6.9 Nhắc khách bổ sung giấy tờ — `RemindMissingDocuments`

Chạy 08:00 các ngày thứ Hai, Tư, Sáu. Với mỗi matter đang mở, đã công bố
portal, còn item bắt buộc ở trạng thái `missing` hoặc `rejected`:

- Gửi email liệt kê **đúng những gì còn thiếu**, kèm liên kết portal.
- Không gửi quá 1 email nhắc mỗi 3 ngày cho cùng một matter.
- Nếu tình trạng thiếu kéo dài quá 14 ngày: thông báo cho lead lawyer là hồ sơ
  đang đình trệ vì thiếu giấy tờ, để gọi điện hỗ trợ trực tiếp.

### 6.10 Kiểm tra xung đột lợi ích — `RunConflictCheck`

Đây là chức năng phân biệt một phần mềm quản lý vụ việc chuyên nghiệp với một
CRM bán hàng được sơn lại. Không phần mềm CRM phổ thông nào có nó, và với nghề
luật thì đây là yêu cầu đạo đức nghề nghiệp chứ không phải tiện ích.

**Chạy bắt buộc ở hai thời điểm:** trước khi lưu vụ việc mới, và mỗi lần thêm
một bên mới vào vụ việc đang chạy.

Thuật toán:

1. Chuẩn hoá dữ liệu đầu vào của từng bên: bỏ dấu, viết thường, bỏ khoảng trắng
   thừa với tên; chuẩn hoá số điện thoại về `84xxxxxxxxx`; băm SHA-256 số căn
   cước sau khi bỏ ký tự không phải chữ số.
2. Tìm trong toàn bộ `matter_parties` các bản ghi trùng theo thứ tự ưu tiên:
   trùng `id_number_hash` (chắc chắn), trùng `phone_normalized` (rất khả nghi),
   trùng tên đã chuẩn hoá (cần người xem xét).
3. Với mỗi bản ghi trùng, xác định mức độ:

| Mức | Điều kiện | Xử lý |
|---|---|---|
| **Đỏ — chặn** | Bên này đang là khách hàng của văn phòng trong một vụ khác, và trong vụ mới họ ở vai đối lập với khách hàng mới | Không cho lưu. Chỉ vai trò `manager` hoặc `admin` mới được ghi đè, và bắt buộc nhập lý do, ghi vào activity log |
| **Vàng — cảnh báo** | Từng xuất hiện trong hồ sơ khác ở bất kỳ vai nào | Cho lưu nhưng hiện cảnh báo, bắt người tạo tích xác nhận đã xem xét |
| **Xanh** | Không tìm thấy | Lưu bình thường |

4. Kết quả mỗi lần chạy ghi vào activity log kèm danh sách bản ghi trùng, kể cả
   khi kết quả xanh. Phải chứng minh được là đã kiểm tra.

Giao diện: kết quả hiện ngay trong form tạo vụ việc, dạng bảng liệt kê vụ việc
liên quan kèm mã hồ sơ và vai của bên đó — **chỉ mã hồ sơ, loại vụ việc và vai**,
không xem được nội dung, kể cả khi người dùng không có quyền trên vụ đó. Đây là
ngoại lệ có chủ đích của quy tắc phân quyền: đủ thông tin để nhận ra xung đột,
không đủ để lộ bí mật hồ sơ khác.

**Đính chính 2026-09-27 (M6.5 Task 9, ghi ở Task 21; `conflict/conflict-08`).** Câu "Người dùng
bấm được sang xem" đã bị bỏ khỏi đoạn trên. Đợt kiểm tra 2026-09-24 xác nhận bảng kết quả không có
liên kết nào sang hồ sơ trùng, và kế hoạch M6.5 Task 9 quyết định giữ nguyên như vậy: những gì
người kiểm tra được phép biết đã nằm sẵn trong các cột của bảng (xem đính chính 2026-09-16 ngay
dưới), còn một liên kết sang vụ việc mà họ không có quyền xem chỉ dẫn tới trang 404 hoặc, tệ hơn,
thành một đường lộ. Đây là chỗ SPEC gốc mô tả sai, không phải cài đặt làm thiếu.

**Đính chính 2026-09-16 (sau review M3).** Bảng kết quả trên thực tế hiện **năm**
cột chứ không phải ba: mã hồ sơ, loại vụ việc, vai của bên, **tên của bên trùng**,
và **tầng khớp + mức độ**. Hai cột thêm là cố ý và giới hạn này vẫn đóng:

- **Tên của bên trùng** là thứ không thể bỏ mà vẫn nhận ra được xung đột. Khi
  khớp ở tầng tên thì chính người dùng vừa gõ tên đó; khi khớp ở tầng số căn cước
  hoặc số điện thoại thì đây đúng là thông tin người dùng cần — "người anh vừa
  nhập, văn phòng đang biết dưới một cái tên khác" — và không có nó thì người
  dùng không có cách nào kiểm chứng hay phản bác kết quả.
- **Tầng khớp và mức độ** nói cho người dùng biết kết quả này đáng tin đến đâu.
  Một dòng vàng vì trùng tên và một dòng đỏ vì trùng số căn cước đòi hai phản ứng
  khác hẳn nhau; gộp lại thành một bảng không nhãn là mời người dùng bấm cho qua.

Vẫn tuyệt đối không hiện: tiêu đề vụ việc, tóm tắt, nội dung, khách hàng của vụ
đó, người phụ trách, giai đoạn, tài liệu — nghĩa là mọi thứ thuộc về **nội dung**
hồ sơ. Ranh giới này được cài bằng DTO `ConflictMatch` (readonly, đúng sáu trường)
chứ không bằng quy ước, và có test khẳng định tiêu đề không lọt ra.

**Đính chính 2026-09-24 (M10 — tiếp nhận và thẩm định đầu vào).** Câu "chạy bắt buộc ở
hai thời điểm" ở đầu mục này đổi thành **ba**, và thuật toán bước 2 có **hai nguồn** thay vì một:

- **Thời điểm thứ ba: lúc tiếp nhận.** Kiểm tra chạy ngay khi nhập xong phần **danh
  tính** của một lần có người liên hệ (người gọi, SĐT, CCCD nếu có, vai dự kiến, các
  bên đối lập nếu biết) — **trước** lúc nghe câu chuyện, vì thông tin đã nghe rồi thì
  không rút lại được. Ô câu chuyện (`summary`) mở theo kết quả: Xanh đủ định danh thì
  mở; Vàng, hoặc Xanh nhưng thiếu định danh, thì đòi đúng cổng xác nhận của `OpenMatter`;
  Đỏ thì khoá, chỉ `manager` hoặc `admin` mở được (từ chối, hoặc ghi đè kèm lý do). Mỗi
  lần chạy vẫn ghi `conflict_check_run`, chủ thể là bản ghi tiếp nhận.
- **Nguồn dò thứ hai: bản ghi tiếp nhận.** Ngoài `matter_parties`, thuật toán dò cả
  người liên hệ và bên đối lập của các bản ghi tiếp nhận **chưa chuyển đổi, chưa gộp,
  chưa ẩn danh** (trừ chính bản ghi đang kiểm tra) — vì một người văn phòng đã nghe
  chuyện nhưng không nhận việc không được vô hình với lần kiểm tra sau. Khớp từ nguồn
  này **tối đa là Vàng**: người đó chưa là khách hàng, nên không đạt định nghĩa Đỏ ở
  bảng trên. Nhãn ghi "đã liên hệ văn phòng ngày …" kèm mã bản ghi, **không kèm câu
  chuyện**. Nguồn này áp cho cả `OpenMatter` lẫn `AddMatterParty`; hệ quả: một vụ
  mới có thể ra Vàng vì một cuộc gọi cũ.

Cài đặt: kế hoạch M10, Task 2. Task 1 chỉ dựng bảng, model, quyền và policy.

**Ghi chú cài đặt 2026-09-30 (M10 Task 2).** Những điều mà đính chính trên để ngỏ, nay đã chọn:
- **Người gọi lại.** Khi kiểm tra một lần tiếp nhận, người liên hệ khớp **SĐT hoặc CCCD**
  (không phải chỉ tên) với người liên hệ của một lần tiếp nhận khác còn mở, mà lần đó đã khai
  **đúng vai** lần kiểm tra này dùng cho người liên hệ (vai đã khai, hoặc vai suy ra ở mục dưới),
  là cùng một người gọi lại về cùng một việc. Hai vai khác nhau, hoặc lần gọi trước chưa khai
  vai, thì không — vợ và chồng chung một số máy bàn không phải cùng một người; khớp chỉ theo tên
  không bao giờ. Với người gọi lại (đính chính 2026-10-01, fix vòng 1 của Task 2):
  - các bên đối lập khai ở lần gọi trước được **mang vào** lần kiểm tra của lần gọi lại, nên
    khớp với khách hàng hiện hữu bật lại (Đỏ đến từ khách hàng, không từ nguồn thứ hai — nguồn
    đó vẫn tối đa Vàng);
  - khớp với lần gọi trước không hiện, **trừ khi** lần đó còn Đỏ chưa xử lý hoặc đã bị từ chối
    vì xung đột: khi đó mã của nó hiện ra **và** lần gọi lại bị khoá như Đỏ (chỉ `manager`/`admin`
    mở, bằng ghi đè kèm lý do) — nếu không, một người nhận khác sẽ nghe hết câu chuyện mà không
    quản lý nào biết;
  - bên đối lập được gõ lại ở lần gọi lại không thành khớp với chính nó ở lần gọi trước.
  Quy tắc này chỉ áp cho kiểm tra của chính một lần tiếp nhận; khi mở vụ hay thêm bên, một cuộc
  gọi cũ chưa chuyển đổi luôn hiện.
- **Đỏ dính** (đính chính 2026-10-01). Một lần tiếp nhận từng ra Đỏ thì ô câu chuyện khoá cho tới
  khi `manager`/`admin` xử lý — **không** theo mức của lần chạy gần nhất: sửa hay gỡ bên đối lập
  rồi chạy lại ra Xanh, kể cả quản lý tự chạy lại, không mở ô. Lý do ghi đè được ghi vào activity
  log ở mỗi lần ghi đè (như `OpenMatter`), nên một ghi đè đã hết hiệu lực vì có khớp mới vẫn còn
  lý do của nó.
- **Vai người liên hệ chưa khai.** Để Đỏ không tắt lặng lẽ, vai dùng cho lần kiểm tra được suy
  ra từ bên đối lập (đối của nguyên đơn là bị đơn và ngược lại), còn không thì `related`. Vai
  suy ra chỉ dùng cho lần kiểm tra, không ghi vào bản ghi.
- **Kết quả kiểm tra gắn với danh tính đã chạy.** Bản ghi lưu dấu vân tay danh tính cùng kết
  quả; ai sửa danh tính mà chưa chạy lại kiểm tra thì ô câu chuyện đóng lại (kết quả cũ, kể cả
  Xanh, không còn là bằng chứng), và xác nhận/ghi đè cũ bị xoá khi có khớp mới hoặc danh tính
  đã đổi.

### 6.11 Bàn giao vụ việc — `ReassignMatter`

Khi luật sư nghỉ việc, nghỉ dài ngày, hoặc vụ việc đổi người phụ trách:

1. Đổi `lead_lawyer_id`, cập nhật `matter_user`.
2. **Tự động tạo một dòng `stage_logs` nội bộ** ghi lại việc bàn giao, ai bàn
   giao cho ai, lý do. Không công bố cho khách theo mặc định.
3. Chuyển toàn bộ `deadlines` có `responsible_user_id` là người cũ sang người
   mới, và gửi email tổng hợp danh sách mốc hạn cho người nhận.
4. Nếu vụ việc đã công bố portal: gợi ý soạn một dòng cập nhật công bố giới
   thiệu luật sư mới — gợi ý, không tự động gửi, vì đây là việc tế nhị cần người
   quyết định.
5. Ghi activity log.

**Đính chính 2026-09-28 (M7 Task 1, R10).** Bước 3 ở trên chỉ chuyển những
`deadlines` **CHƯA HOÀN THÀNH** của người cũ, không phải "toàn bộ" như câu trên
viết — một mốc đã xong là lịch sử của người đã hoàn thành nó, chuyển nó đi chỉ
viết lại ai đã thật sự làm việc gì. Thư tổng hợp cũng chỉ liệt kê đúng những mốc
CHƯA hoàn thành vừa chuyển (không phải mọi mốc lead mới đang giữ), cộng số yêu
cầu khách hàng chưa đóng đã chuyển; một vụ không có mốc nào vẫn có mặt trong thư
để lead mới biết mình vừa nhận vụ. Thư đi qua hàng đợi, sau khi commit
(`App\Jobs\SendReassignmentDigest`), dựng để dùng lại được cho một lô nhiều vụ
việc (màn hình hàng loạt bên dưới).

Màn hình hàng loạt: chọn nhiều vụ việc của một luật sư và bàn giao cùng lúc.
Khi vô hiệu hoá một tài khoản `users` mà người đó còn là lead lawyer của vụ việc
đang mở, hệ thống **chặn** và yêu cầu bàn giao trước.

### 6.12 Kết thúc và bàn giao hồ sơ cho khách — `GenerateHandoverPackage`

Khi vụ việc chuyển sang giai đoạn kết thúc, hệ thống sinh một gói bàn giao —
đây là thứ khách hàng nhớ rất lâu và gần như không văn phòng nào làm:

1. Tạo tệp zip chứa toàn bộ tài liệu nhóm A, B, C của vụ việc (không bao giờ
   nhóm D), sắp xếp theo thư mục tương ứng nhóm.
2. Kèm một file `MUC-LUC.pdf` sinh tự động: thông tin vụ việc, danh sách tài
   liệu có đánh số, và **toàn bộ dòng thời gian tiến độ đã công bố** — tức là
   một bản tường trình đầy đủ quá trình xử lý hồ sơ.
3. Chạy trong queue vì có thể lâu. Xong thì thông báo cho luật sư phụ trách.
4. Luật sư xem lại, rồi bấm công bố để khách tải về từ portal.
5. Ghi `matter_archives`, đặt `client_access_until` mặc định 90 ngày sau ngày
   kết thúc, và `retention_until` theo chính sách lưu trữ cấu hình trong `.env`
   (mặc định 10 năm).

**Đính chính 2026-09-28 (M7 Task 4, R1, R3, R8, R9).** Bước 1–4 đọc theo các phán quyết sau:

- *Nội dung gói (R8).* "Toàn bộ tài liệu nhóm A, B, C" là quá rộng so với §4.11 (khách không bao
  giờ thấy "một bản đơn mà toà chưa hề nhận được"). Gói chứa: **nhóm A** — mọi tệp của version mới
  nhất đã được chấp nhận của mỗi đầu mục danh mục (một lần nộp có thể nhiều tệp), bỏ version bị từ
  chối và version đã bị thay; tài liệu nhóm A nhân sự nộp thay không gắn đầu mục nào cũng vào gói;
  **nhóm B và C** — chỉ tài liệu ở `signed_filed` hoặc `published`. Luật trạng thái đó áp cho cả
  nhóm A (một tài liệu đổi nhóm sang A giữ nguyên trạng thái cũ, và một bản còn `internal_draft`
  thì khách chưa từng được thấy). **Không bao giờ**: nhóm D, tài
  liệu đã xoá mềm, tài liệu đã rút, và chính tài liệu gói của lần trước (mọi version).
- *Tên entry.* `<nhóm>/<NN>-<tên an toàn của tiêu đề>.<đuôi>`, `NN` là số thứ tự trong mục lục.
  Tiêu đề không duy nhất và có thể chứa `/` hay `..`; số thứ tự loại cả hai rủi ro, và cho mục lục
  với zip cùng một cách đánh số. Tên entry được đánh dấu UTF-8 (bit 11) để dấu tiếng Việt không hỏng.
- *Gói là một `Document` (R1).* Nhóm B, `signed_filed`, tệp trên đĩa `private`; sinh lại là version
  mới của cùng tài liệu. Bước 4 đi qua đúng `PublishDocument`.
- *Sinh lại và rút lại (sửa ngày 2026-10-03, rà soát cuối M7, I2).* Hai luật từng cãi nhau: "chỉ giữ
  version mới nhất của gói" (hạn mức đĩa) và "một đường rút duy nhất" cùng "bằng chứng khách đã nhận
  không biến mất" (§4.11, đính chính M7 Task 7). Đọc như sau:
  - Gói hiện tại đang ra tới khách thì **không sinh lại được**: nút báo câu chỉ tới "Rút lại", và job
    hỏi lại dưới khoá (gói có thể được công bố trong lúc job chờ hàng) rồi hỏng với lỗi có tên, không
    tạo version mới. Sinh lại không bao giờ tự gỡ gói khỏi cổng khách. Muốn thay gói đã giao: rút nó
    (lý do khách đọc được) rồi sinh lại.
  - Tệp của version cũ chỉ bị xoá khi version đó chưa từng tới tay khách: không ở trạng thái
    `retracted` và không có lượt tải nào của khách. Version đã rút hay khách đã tải giữ tệp. Dòng
    `documents` và `document_downloads` của mọi version luôn giữ nguyên.
- *Chạy nền (R9).* Job chạy trên kết nối/hàng `handover` riêng với mục lịch `queue.handover` riêng
  (không dùng chung lượt của `queue.drain`, để một gói lớn không giữ thư nhắc mốc thời hạn), có
  `$timeout` và `$tries` tường minh; thất bại hẳn thì báo luật sư phụ trách và màn hình hiện trạng
  thái lỗi. Tự sinh MỘT lần khi vụ vào giai đoạn kết thúc; sinh lại là nút bấm.
- *Xuất dữ liệu (SPEC §10.6).* Ghi `data_exported` khi gói sinh xong và mỗi lần gói được tải.
- *Nhãn giai đoạn trong `MUC-LUC.pdf` (sửa ở M7 Task 11).* Mục lục giao cho khách, nên nhãn giai
  đoạn (cả "giai đoạn cuối" lẫn từng dòng tiến độ) là `client_label` (§4.5), cùng nhãn cổng khách
  hàng hiện. Dòng tiến độ đã công bố mà không ghi giai đoạn đích (`to_stage` NULL, §4.8) vẫn vào
  mục lục, chỉ in ngày và nội dung. Trước bản sửa, một dòng như vậy làm hỏng cả mục lục, và gói của
  vụ mẫu đã kết thúc không sinh được.

**Đính chính 2026-10-03 (M9 Task 10, P1).** `MUC-LUC.pdf` có thêm mục **"Bảng kê thanh toán"**, sau
khối tiến độ: ĐÚNG những trường khối "Hợp đồng và thanh toán" của cổng khách hiện (§8.3, đính chính
cùng ngày) — cùng một hình chiếu (`App\Support\Billing\ClientBillingStatement`), nên cùng dữ liệu thì
cổng và mục lục cho đúng cùng các dòng và cùng câu chữ. Bản ghi được lọc bằng cùng điều kiện mà tầng
truy vấn của cổng dùng (hợp đồng `active`/`completed`, đợt khác `cancelled`, khoản thu chưa huỷ —
các scope `shownToClient()`), vì gói dựng trong job, không có phiên cổng. Ranh giới vụ việc của cổng
(đã công bố, chưa hết hạn tra cứu) KHÔNG áp ở đây, như mọi khối khác của mục lục. Vụ không có hợp
đồng như vậy thì không có mục này. Cột nội bộ của bốn bảng tiền không được nạp (chọn cột tường
minh). Biên lai (`payments.receipt_document_id`) và bản scan phụ lục (`contract_amendments.document_id`)
là nhóm D, nên không vào zip.

**Đính chính 2026-10-03 (M9 Task 10, rà soát vòng 1) — ai trong văn phòng tải được gói mang bảng
kê.** Vì mục lục mang tiền, tải một version của gói bàn giao của vụ có hợp đồng đã từng ký (khác
`draft`) là đọc tiền: nhân sự phải thấy được tiền của vụ theo định nghĩa duy nhất của §5 (`billing.view`
cộng `Matter::listableBy()`, hỏi qua `ContractPolicy::view`), không chỉ `matter.view`. Trợ lý trong đội
(không `billing.view`), và một luật sư phụ trách vụ `restricted` đã bị đổi sang vai trò trợ lý, thấy
dòng gói nhưng route tải trả 404 và tab Tài liệu không có nút "Tải" ở dòng đó. Hợp đồng `cancelled`
vẫn tính, vì gói dựng trước lần huỷ vẫn in bảng kê; cái giá phía đóng: gói dựng khi hợp đồng còn là
bản nháp rồi hợp đồng được ký sau đó cũng bị giữ lại với người không thấy tiền. Gói của vụ chưa từng
có hợp đồng đã ký, và mọi tài liệu khác của vụ, không đổi luật. Khách tải gói đã công bố như trước.
Xem §5, đính chính cùng ngày.

**Bổ sung 2026-10-04 (việc sau gộp M9 + M10, làn fu3) — bảng kê là ảnh chụp lúc lập gói.** "Bảng kê
thanh toán" trong `MUC-LUC.pdf` được dựng một lần, lúc gói được lập, và không đổi sau đó: khoản thu ghi
sau ngày đó, một đợt miễn hay huỷ sau, một phụ lục ký sau không vào gói đã lập — chỉ vào gói sinh lại.
Từ khi thư công bố gói mời khách tải gói về và cất giữ, khách giữ đúng ảnh chụp đó, nên ngay dưới tiêu
đề mục là một dòng "Tính đến ngày lập gói (dd/mm/yyyy)" — cùng ngày với dòng "Lập ngày" đầu mục lục,
cùng một biến lúc dựng — kèm câu chỉ khách sang cổng cho tình hình mới nhất. Khối "Hợp đồng và thanh
toán" của cổng khách đọc dữ liệu lúc mở trang, nên luôn là tình hình hiện tại — trong thời gian khách
còn xem được vụ trên cổng (vụ đang công bố, chưa quá `client_access_until`); sau đó khách chỉ còn gói đã
tải về. Thư công bố gói không thêm câu nào về tiền: thư đi cho mọi gói, kể cả gói của vụ không có hợp
đồng nào.

**Sửa câu 2026-10-08 (lượt quét toàn hệ thống trước bản 1.0).** Dòng dưới tiêu đề nay là "Tính đến LÚC
lập gói (ngày dd/mm/yyyy)" và nói rõ khoản thanh toán cùng mọi thay đổi khác về thanh toán (miễn đợt,
huỷ khoản thu, phụ lục) ghi nhận sau LÚC lập gói không có trong bảng kê — bảng kê đóng băng lúc lập,
không phải cuối ngày đó, nên một khoản ghi chiều cùng ngày cũng không có (`handover.pdf.billing.as_of`).

Job `ExpireClientAccess` chạy hằng ngày: khi quá `client_access_until`, vụ việc
biến mất khỏi portal của khách. Tài khoản `client_users` không còn vụ việc nào
thì tự đặt `is_active = false`. Dữ liệu vẫn nguyên trong hệ thống nội bộ.

**Đính chính 2026-09-28 (M7 Task 5, R4).** Đoạn trên đọc như sau:
- *Hết hạn là gì.* Vụ việc hết hạn tra cứu khi có dòng `matter_archives` (chưa xoá mềm) với
  `client_access_until` khác null và `client_access_until` < hôm nay theo giờ ứng dụng. Khách còn
  xem được HẾT ngày `client_access_until`, và mất quyền từ 00:00 ngày hôm sau. Vụ chưa đóng hoặc đã
  mở lại (`client_access_until` null) không bao giờ hết hạn.
- *Ai làm vụ việc biến mất.* Không phải job: hai tầng ranh giới của cổng
  (`Matter::applyClientPortalConstraints()` và `MatterPolicy::releasedToPortal()`, điều kiện thứ
  năm) tự loại vụ đã hết hạn, đúng từ 00:00, dù job đã chạy hay chưa. Không cột nào của vụ việc
  bị sửa (`is_published_to_portal` giữ nguyên). Mở lại vụ việc đưa vụ về lại cổng.
- *Ai bị vô hiệu hoá.* "Không còn vụ việc nào" đọc là: khách có ÍT NHẤT MỘT vụ đã hết hạn tra cứu
  VÀ không còn vụ nào hiển thị trên cổng. Một khách mới có vụ đầu tiên chưa công bố không bao giờ bị
  vô hiệu hoá. Mỗi tài khoản được lưu riêng để nhật ký ghi lại, kèm một dòng nhật ký
  `portal_account_deactivated`. Tài khoản bị vô hiệu không tự bật lại khi vụ được mở lại; nhân sự
  bật tay.
- *Giờ chạy.* 00:30 hằng ngày (mục lịch `client-access.expire`).

Job `FlagRetentionExpiry` cảnh báo quản trị khi có hồ sơ quá `retention_until`,
nhưng **không bao giờ tự xoá**. Việc tiêu huỷ hồ sơ pháp lý phải do người quyết
định và ghi biên bản.

**Đính chính 2026-09-28 (M7 Task 6, R5).** Đoạn trên đọc như sau:
- *Hồ sơ nào bị cảnh báo.* Có dòng `matter_archives` chưa xoá mềm, `retention_until` < hôm nay theo
  giờ ứng dụng (hồ sơ còn trong hạn HẾT ngày `retention_until`), `destroyed_at` rỗng, và vụ việc
  chưa xoá mềm, đang đóng (`closed_at` có giá trị). Vụ đã được mở lại không bị cảnh báo, dù bản ghi
  lưu trữ còn giữ `retention_until` của lần đóng trước.
- *Cảnh báo là gì, tới ai.* Một thông báo trong hệ thống (chuông của panel admin), không thư, tới
  mọi admin đang hoạt động — chọn qua `ResolveStaffRecipients::activeAdminsFor()`. Mỗi người nhận
  nhận MỘT lần cho mỗi hạn lưu trữ của một hồ sơ, không lặp mỗi ngày. Admin được thêm sau vẫn nhận
  một lần. Hồ sơ được đóng lại với hạn mới rồi quá hạn lần nữa thì được cảnh báo lần nữa.
- *Giờ chạy.* 01:00 hằng ngày (mục lịch `retention.flag`).
- *Ghi quyết định tiêu huỷ.* Action `RecordMatterDestruction`, nút "Ghi quyết định tiêu huỷ" trên
  trang vụ việc. Chỉ admin. Chỉ khi vụ đang đóng, đã quá `retention_until` và chưa có quyết định.
  Bắt buộc số biên bản (tối đa 50 ký tự, bằng độ dài cột) và lý do (20–5000 ký tự). Action ghi
  `destroyed_at`, `destroyed_by`, `destruction_reason`, `destruction_record_no` cộng một dòng nhật
  ký `matter_destruction_recorded`. Một quyết định chỉ ghi một lần và không sửa được.
- *Ghi quyết định không xoá gì.* Vụ việc, tài liệu, tệp trên đĩa và bản ghi lưu trữ còn nguyên.
  Việc huỷ hồ sơ giấy và tệp là thao tác có biên bản, làm ngoài hệ thống. Sau khi ghi, job không
  cảnh báo hồ sơ đó nữa. Một test cấu trúc cấm mọi lời gọi `forceDelete()` (cùng `forceDeleteQuietly()`,
  `forceDestroy()`) trong `app/`, `routes/` và `database/seeders/`.
- *`destroyed_by` (§4.19)* là admin đã GHI quyết định vào hệ thống, tức người chịu trách nhiệm về
  bản ghi đó. Người phê duyệt có tên trên biên bản được nêu trong lý do.

### 6.13 Tìm kiếm

Một ô tìm kiếm trên admin panel, tìm đồng thời trong: mã hồ sơ, tiêu đề vụ việc,
tên khách hàng, số thụ lý của toà, tên các bên trong `matter_parties`, và tiêu
đề tài liệu.

Kết quả **luôn đi qua policy** — luật sư chỉ thấy vụ việc mình có quyền, trừ kết
quả từ kiểm tra xung đột lợi ích ở mục 6.10 vốn có quy tắc riêng.

Dùng `LIKE` với index phù hợp là đủ ở quy mô vài nghìn hồ sơ. **Không cài
Elasticsearch hay Meilisearch** — vi phạm ràng buộc chạy được trên shared
hosting.

**Đính chính 2026-09-28 (M7 Task 9, R7).** "Luôn đi qua policy" đọc theo từng nguồn, cài ở
`App\Actions\Search\SearchMatters` (trang `App\Filament\Admin\Pages\Search` chỉ gọi nó; M11
`search_matters` dùng lại `matching()` với bốn nguồn của vụ):
- Tập vụ là `Matter::scopeListableBy()` của người tìm, áp **trong cùng câu SQL, trước giới hạn số
  dòng**. Không tổng số, không "có kết quả bị ẩn"; "không có gì khớp" và "có khớp nhưng không được
  xem" là cùng một câu. Vụ `restricted` chỉ ra cho luật sư phụ trách và admin.
- Mã hồ sơ và tên khách hàng: mọi người liệt kê được vụ. Tiêu đề vụ việc, số thụ lý, tên các bên,
  tiêu đề tài liệu: chỉ người có `matter.view`. **Kế toán vì vậy chỉ tìm theo mã và tên khách** —
  đúng hai cột họ thấy trên danh sách vụ việc và đúng "Ranh giới của kế toán" ở §5 (không tiêu đề,
  không tài liệu, không các bên); dòng kết quả của kế toán không có tiêu đề và không liên kết vào
  trang vụ việc. Tài liệu nhóm D chỉ với `document.viewInternal`; tài liệu, các bên và vụ đã xoá
  mềm không bao giờ ra.
- Số thụ lý tìm theo tiền tố (`LIKE 'x%'`, vì độ chính xác); mã, tiêu đề, tên khách, tên các bên,
  tiêu đề tài liệu theo kiểu chứa (`LIKE '%x%'`). Sáu nguồn nằm trong một `OR`, nên câu tìm duyệt
  bảng, không dùng index nào — đo trên 6.000 hồ sơ: 3,5–23,5 ms (PROGRESS, "Ghi chú M7"). Index của bốn
  cột (`matters.case_number`, `matters.title`, `clients.name`, `documents.title`) vẫn được thêm,
  cho câu tiền tố đứng riêng và cho lúc quy mô vượt "vài nghìn hồ sơ". Tên các bên so trên
  `name_normalized` (không dấu, `đ` → `d`); các cột còn lại theo collation (MariaDB
  `utf8mb4_unicode_ci` bỏ qua dấu và hoa/thường, nhưng `đ` khác `d`).

### 6.14 Số liệu đội ngũ và hiệu suất

**Bổ sung 2026-10-04 (M13 — theo dõi đội ngũ và hiệu suất; kế hoạch
`docs/superpowers/plans/2026-10-04-m13-team-performance.md`, phán quyết R1–R20).** Chủ văn phòng
yêu cầu ngày 2026-10-04: cấp trên theo dõi tiến độ vụ việc của luật sư/chuyên viên, và đánh giá tỉ
lệ hoàn thành công việc. Mọi màn hình trước M13 xếp **theo vụ việc**; mục này định nghĩa các con số
xếp **theo người**. Ba trang ở §7.5, quyền ở §5 (bổ sung 2026-10-04).

**Một định nghĩa cho mỗi con số.** Mỗi số gọi lại đúng nguồn sự thật mà trang chủ, thư nhắc và trang
doanh thu đang dùng (cột "Nguồn" dưới). Định nghĩa còn thiếu được thêm vào **đúng lớp đang giữ luật
đó** (`Matter`, `Deadline`, `ClientRequest`, `MatterChecklistItem`, `ChecklistProgress`,
`MatterStaleness`, `ActivityOwningMatter`, `App\Support\Billing`); mã của M13 (`App\Actions\Performance`,
`App\Support\Performance`, ba trang, widget xu hướng, tác vụ chụp) không tự viết điều kiện nghiệp vụ
nào, và một test cấu trúc quét token canh luật này. Mỗi con số có một câu giải thích tiếng Việt hiện
trên trang (khối "Cách tính các con số").

**Phạm vi xem — một phép đếm trên phần giao, không bao giờ một phép trừ (R4).** Mọi con số về người X
mà người xem V đọc được tính trên **giao** của `Matter::listableBy(V)` và tập việc của X. Không số
nào tính trên tập rộng hơn rồi trừ đi; không dòng "đã ẩn N vụ"; không tổng toàn văn phòng ngoài
`listableBy(V)`. Hệ quả có chủ đích: cùng một người có thể có hai con số khác nhau với hai người xem
(trưởng phòng thấy 12 vụ của luật sư A, A thấy 13 vì vụ thứ 13 là vụ `restricted` A phụ trách) —
đúng như trang Doanh thu của M9. Mọi trang in cùng một câu cho mọi người xem, kể cả admin: "Mọi con
số tính trên các vụ việc anh/chị được xem." Ảnh chụp hằng ngày (dùng cho xu hướng) được tính ngoài
`listableBy` nên **đóng khi không chắc**: dòng `normal` chỉ hiện cho người có `matter.viewAny` hoặc
cho chính người đó; dòng `restricted` chỉ khi một vụ `restricted` giả do người đó phụ trách qua được
`Matter::isListableBy(V)`.

**Ai được theo dõi (R3).** `App\Support\Performance\TeamRoster` là định nghĩa duy nhất: vai trò luật
sư, trợ lý hoặc quản lý, và chưa xoá mềm. Admin và kế toán không vào danh sách. Người đã nghỉ việc
(`is_active = false`) **vẫn** được theo dõi — số của kỳ họ đã làm là của họ; trang "bây giờ" mặc
định chỉ hiện người đang hoạt động, công tắc "Gồm người đã nghỉ việc" thêm họ; trang theo kỳ mặc
định gồm người đang hoạt động vào một lúc nào đó trong kỳ. Người đã xoá mềm không hiện ở đâu. Danh
sách chỉ phụ thuộc vai trò, **không** phụ thuộc "có vụ mà người xem thấy được": một luật sư chỉ phụ
trách vụ `restricted` mà biến mất khỏi danh sách của trưởng phòng thì chính sự biến mất đó lộ vụ ấy.

**Quy về người (R5).** Mỗi con số quy về đúng một người, theo đúng một cột đã có:

| Loại việc | Quy về | Cột / nguồn |
|---|---|---|
| Vụ việc, bây giờ (đang mở, đã kết thúc, quá hạn cập nhật, chờ giấy tờ khách, giấy tờ chờ duyệt, `X/Y`) | luật sư phụ trách hiện tại | `matters.lead_lawyer_id` (người §6.4 và §6.9 gửi cảnh báo) |
| Vụ kết thúc **trong kỳ** | luật sư phụ trách **lúc vụ kết thúc** | lịch sử `matter_reassigned` (R18) |
| Vụ tham gia | thành viên đội ngũ vai `associate` hoặc `assistant` | `matter_user.role_in_matter` (`observer` không giữ việc) |
| Mốc thời hạn, bây giờ | người giữ mốc hiện tại | `deadlines.responsible_user_id` |
| Mốc thời hạn, trong kỳ | người giữ mốc **vào ngày đến hạn** | lịch sử `deadline_responsible_changed` (R9) |
| Yêu cầu của khách, bây giờ | người đang giữ luồng: người được giao còn tài khoản, không có thì luật sư phụ trách hiện tại | cùng người mà `ReplyToClientRequest` báo khi khách viết thêm |
| Yêu cầu của khách, trong kỳ | người giữ luồng **lúc văn phòng trả lời lần đầu**; chưa trả lời tới hết kỳ thì **lúc hết kỳ** | lịch sử `client_request_assigned` và `matter_reassigned` (R18) |
| Chuyển giai đoạn | người ghi dòng tiến độ | `stage_logs.created_by` |
| Giấy tờ đã duyệt | người bấm duyệt hoặc từ chối | `causer_id` của dòng nhật ký `checklist_item_reviewed` |
| Doanh thu đã thu | luật sư phụ trách tại lúc thu | `payments.attributed_lawyer_id` (M9, không bao giờ suy lại) |
| Thao tác hồ sơ gần nhất | người gây ra dòng nhật ký | `activity_log.causer_id` |

Việc không quy được về ai (ví dụ dòng lịch sử có `from` rỗng) **không** bị đoán cho ai: nó chỉ vào
dòng tham chiếu "Chung" (mọi việc trên các vụ người xem được xem, không lọc người giữ; chỉ người có
`performance.viewAny` thấy dòng này) của trang theo kỳ — vì vậy dòng "Chung" không bằng tổng các dòng
bên dưới.

**Các con số "bây giờ"** (trang "Theo dõi đội ngũ" và đầu trang của một người; tính tại lúc xem,
trên vụ `listableBy(V)`; **(L)** = chỉ dành cho người phụ trách vụ, xem R6 dưới):

| Mã | Con số | Cách tính | Nguồn sự thật |
|---|---|---|---|
| N1 (L) | Vụ đang phụ trách | đếm vụ `open()` theo `lead_lawyer_id` | `Matter::scopeOpen()`; cùng số với `LoadPerLawyerWidget` của trang Doanh thu |
| N2 | Vụ đang tham gia | đếm vụ `open()` có người này trong đội với vai `associate`/`assistant` | `MatterRole` |
| N3 (L) | Vụ đã kết thúc | đếm vụ `closed()` đang đứng tên người này, từ trước tới nay | `Matter::scopeClosed()` |
| N4 (L) | Quá hạn cập nhật cho khách | vụ đang mở, đã bật cổng, cập nhật cho khách gần nhất quá 14 ngày; kèm "chưa bật cổng: N" | `MatterStaleness` (§6.4, widget §7.1 mục 1) |
| N5 | Mốc quá hạn | mốc người này đang giữ, chưa xong, ngày đến hạn trước hôm nay, trên vụ còn mở | `Deadline` (bậc `overdue` của `CheckDeadlines`, §6.8) |
| N6 | Mốc 7 ngày tới | mốc chưa xong, đến hạn từ hôm nay tới hết ngày thứ 7 kể từ hôm nay | `Deadline` (bậc `d7` của `CheckDeadlines`); N5 ∪ N6 = widget §7.1 mục 2 |
| N7 (L) | Chờ giấy tờ của khách | vụ đã bật cổng còn đầu mục bắt buộc khách chưa nộp hoặc bị từ chối; trong ngoặc: số vụ đã chờ quá 14 ngày | `ChecklistProgress::mattersAwaitingClient()`; số trong ngoặc = widget §7.1 mục 4 |
| N8 (L) | Giấy tờ chờ duyệt | đầu mục khách đã nộp mà văn phòng chưa duyệt, trên vụ người này phụ trách | `MatterChecklistItem` (widget §7.1 mục 3) |
| N9 | Yêu cầu chờ trả lời | yêu cầu Mới hoặc Đang xử lý trên vụ còn mở mà người này đang giữ | `ClientRequest` |
| N10 (L) | Hoàn thiện danh mục | `Σ X / Σ Y` trên các vụ đang phụ trách | `ChecklistProgress::handle()`, §4.10 |
| N11 | Thao tác hồ sơ gần nhất — **chỉ ở trang của một người** (phán quyết N11 của M13 Task 4: tính cho một người, không phải một cột của "Theo dõi đội ngũ") | lần gần nhất người này ghi một thay đổi vào một vụ người xem được xem, theo nhật ký; đăng nhập không tính | `ActivityOwningMatter` (§10 mục 6) |

N3 và P5 quy người khác nhau có chủ đích: bàn giao một vụ đã kết thúc chuyển N3 sang người nhận,
nhưng P5 của kỳ kết thúc vẫn là của người phụ trách lúc đó.

**Các con số "trong kỳ"** (trang "Hiệu suất theo kỳ"; vụ trong `listableBy(V)`; tập việc của kỳ
nạp **không lọc người**, rồi mới quy về người):

| Mã | Con số | Cách tính | Nguồn sự thật |
|---|---|---|---|
| P1 | Mốc đúng hạn | mốc đến hạn trong kỳ, phân loại đúng hạn / trễ / lỡ theo bảng ca biên dưới; tỉ lệ = đúng hạn / (đúng hạn + trễ + lỡ); quy về người giữ vào ngày đến hạn (R9) | `Deadline`, `SetDeadlineCompletion` |
| P2 | Mốc đã gỡ | mốc người này giữ bị gỡ (xoá kèm lý do) trong kỳ; không vào tỉ lệ, hiện ra để tỉ lệ không đẹp lên nhờ gỡ mốc | `DeleteDeadline` |
| P3 | Trả lời yêu cầu của khách | yêu cầu khách gửi trong kỳ (trừ yêu cầu đóng không trả lời): số đã trả lời tới hết kỳ trên tổng; thời gian từ lúc gửi tới lần trả lời đầu tiên (trung vị và trung bình); quy về người giữ luồng lúc trả lời, hoặc lúc hết kỳ nếu chưa trả lời (R18) | `ReplyToClientRequest`, `TriageClientRequest` |
| P4 (L) | Chuyển giai đoạn | số lần người này đưa một vụ **vào** một giai đoạn mới trong kỳ (theo ngày ghi trên dòng tiến độ) và số vụ khác nhau; không chia tiến/lùi | `StageLog::scopeEntries()` |
| P5 (L) | Vụ kết thúc trong kỳ | vụ người này phụ trách **lúc vụ kết thúc** đã vào giai đoạn kết thúc trong kỳ | `Matter` (`closed_at`), `LeadAt` (R18) |
| P6 | Giấy tờ đã duyệt | số lần người này bấm duyệt hoặc từ chối một đầu mục trong kỳ, theo nhật ký (khách nộp lại rồi duyệt lại = hai lần; văn phòng tải lên thay khách không phải một lần duyệt) | `ReviewChecklistItem` |
| P7 (L) | Doanh thu đã thu | tiền khách đã trả trong kỳ theo `attributed_lawyer_id`; cùng con số trên trang Doanh thu | M9 (P2 của kế hoạch M9) |
| P8 | Xu hướng | số vụ quá hạn cập nhật, số mốc quá hạn và `X/Y` vào cuối mỗi ngày, từ ảnh chụp hằng ngày; ngày không có ảnh chụp để trống, không vẽ thành 0 | ảnh chụp `performance_snapshots` |
| P9 | Hoàn thành việc đến hạn | R7 dưới | P1 + P3 |
| P10 | Yêu cầu đóng không trả lời | yêu cầu khách gửi trong kỳ mà văn phòng đóng lại không trả lời; không vào tỉ lệ, hiện ra để tỉ lệ không đẹp lên nhờ đóng luồng chưa trả lời | `TriageClientRequest` |
| — | Lĩnh vực chính | hai loại vụ có nhiều vụ nhất trong số vụ người này có việc trong kỳ (vụ của P1, P3/P10, P4, P5 đã quy về người đó), kèm số vụ — để đọc tỉ lệ trong đúng bối cảnh, thay cho chuẩn hoá | `MatterType` |

Kỳ: tháng trước (**mặc định** — đánh giá trên kỳ đã đóng mới công bằng), tháng này, quý trước, quý
này, hoặc tự chọn tối đa 366 ngày không quá hôm nay. Mọi cận ngày, kể cả của các con số "bây giờ",
là cận **đủ giờ** (`00:00:00` ngày đầu … `23:59:59` ngày cuối), như `RevenueFilters::bounds()` của
M9. Thời gian phản hồi (P3) đo bằng **giờ làm việc** qua đúng định nghĩa của M10
(`App\Support\BusinessHours`, cấu hình `vkcrm.business_hours`: Thứ Hai–Thứ Sáu 08:00–17:30 theo giờ ứng
dụng, ngày lễ chưa trừ), không viết định nghĩa thứ hai; trang in "x,y giờ" (giờ làm việc), không gộp
thành "ngày". SPEC không có mục tiêu thời gian trả lời, nên P3 báo trung vị và trung bình, không báo
"trong hạn".

**Đính chính 2026-10-07 (M13 Task 8, R17).** Bản 2026-10-04 của đoạn trên ghi P3 đo bằng **giờ lịch**
cho tới khi `BusinessHours` của M10 có trên `main`. M10 đã gộp vào `main` (và vào làn M13 ngày
2026-10-05), nên từ M13 Task 7 P3 đo bằng giờ làm việc như trên: một yêu cầu gửi 16:00 Thứ Sáu, trả
lời 09:00 Thứ Hai là 2,5 giờ, không phải 65 giờ. Đổi `vkcrm.business_hours` thì con số đổi theo.

**Ca biên của P1** (`$dueEnd` = 23:59:59 của ngày đến hạn theo giờ ứng dụng; `$cutoff` = mốc cắt của
kỳ, R19):

| # | Ca | Kết quả |
|---|---|---|
| 1 | mốc ghi vào hệ thống sau `$dueEnd` (nhập dữ liệu cũ, ghi lại phiên toà đã qua, mốc AI tạo với ngày đã qua) | không vào tập |
| 2 | đã xong nhưng không có `completed_at` (dữ liệu cũ) | không vào tập (không đoán) |
| 3 | xong, `completed_at ≤ $dueEnd` | đúng hạn |
| 4 | xong, `$dueEnd < completed_at ≤ $cutoff` | trễ |
| 5 | chưa xong (hoặc xong sau `$cutoff`) và `$dueEnd > $cutoff` (đến hạn hôm nay, kỳ đang chạy) | không vào tập |
| 6 | chưa xong (hoặc xong sau `$cutoff`) và vụ đã kết thúc không muộn hơn `$dueEnd` (kể cả kết thúc đúng ngày đến hạn) | không vào tập (mốc hết hiệu lực) |
| 7 | chưa xong (hoặc xong sau `$cutoff`), các ca trên không áp | lỡ |
| 8 | xong đúng hạn rồi bị **mở lại** sau ngày đến hạn | theo trạng thái hiện tại (ca 7, hoặc ca 4 nếu xong lại trước `$cutoff`), tính cho người giữ vào ngày đến hạn |
| 9 | dòng lịch sử người giữ có `from` rỗng hoặc không phải số | phân loại giữ nguyên; không quy về ai, chỉ vào dòng "Chung" |

Mốc đến hạn đúng ngày cuối kỳ vì vậy chỉ có thể đúng hạn hoặc lỡ.

**"Không áp dụng" (R6).** Không hiện số 0 cho việc người đó không được làm — số 0 đọc thành "không
làm gì". Một điều kiện duy nhất: người đó có `matter.transitionStage` (cùng quyền mà màn hình tạo vụ
dùng để liệt kê người được chọn làm luật sư phụ trách) thì là người phụ trách vụ; không có (trợ lý)
thì các cột **(L)** — N1, N3, N4, N7, N8, N10, P4, P5, P7 — hiện "Không áp dụng". Điều kiện này
**chỉ theo quyền, không bao giờ theo vụ**: một luật sư chỉ phụ trách vụ `restricted` phải hiện **0**
với trưởng phòng, không phải "Không áp dụng" (R4). Doanh thu có thêm một lý do vắng mặt khác, không
trộn: người xem không được thấy tiền thì cả cột không có trên trang (§5).

**Tỉ lệ hoàn thành việc đến hạn (R7).**

```
(mốc đến hạn đã xong tới hết kỳ, đúng hạn hoặc trễ) + (yêu cầu của khách đã trả lời tới hết kỳ)
─────────────────────────────────────────────────────────────────────────────────────────────────
     (mốc đến hạn trong kỳ) + (yêu cầu khách gửi trong kỳ, trừ yêu cầu đóng không trả lời)
```

- Là một phép đếm, không phải một điểm số: mỗi việc một đơn vị, không trọng số; phân rã luôn in cạnh
  tỉ lệ ("12/15 mốc · 8/9 yêu cầu"). Chỉ hai loại việc vào: mốc (toà án hoặc cơ quan chờ) và yêu cầu
  (khách chờ). Danh mục hồ sơ `X/Y` không vào — nó đo việc khách nộp đủ giấy tờ chưa.
- Yêu cầu đóng không trả lời (trùng, khách rút, giải quyết ngoài hệ thống) không vào mẫu số và
  **không** tính là "đã giải quyết"; chúng hiện ở P10. "Đã trả lời qua điện thoại" có đường riêng
  (chuyển trạng thái sang Đã trả lời ghi `answered_at`).
- Dưới 5 việc thì không tính tỉ lệ: trang hiện "Chưa đủ dữ liệu (n = 3)". Áp cho mọi tỉ lệ của M13.
- Không bảng xếp hạng (R8): không cột hạng, không điểm tổng hợp; trên trang theo kỳ không cột nào
  sắp xếp được ngoài tên; tỉ lệ không bao giờ tô xanh hay đỏ. Cơ cấu vụ khác nhau, mẫu nhỏ, và xếp
  hạng dạy người ta bấm "xong" sớm, gỡ mốc khó, tránh vụ khó.

**Lịch sử người giữ mốc (R9).** Lịch sử "ai từng giữ mốc này" đọc ở **một** khoá sự kiện,
`deadline_responsible_changed` (`from`, `to`, `reason`). Từ M13 mọi đường đổi người giữ mốc đều ghi
dòng đó: đổi người phụ trách mốc, lần mở lại có chuyển người (`reopened_holder_no_longer_qualifies`),
sửa mốc khi người phụ trách thật sự đổi (`deadline_updated`, dòng `deadline_updated` vẫn giữ), và
bàn giao vụ — **một dòng cho mỗi mốc** được chuyển (`matter_reassigned`), trong cùng transaction.
Người giữ mốc vào ngày đến hạn = `from` của dòng sớm nhất sau 23:59:59 ngày đến hạn, không có dòng
nào thì người giữ hiện tại. Nhờ vậy mốc lỡ rồi mới được bàn giao (ví dụ ngày một luật sư nghỉ việc)
tính cho người trước, không kéo tỉ lệ của người nhận xuống. **Giới hạn đã biết:** lần bàn giao trước
ngày triển khai M13 không có dòng cho từng mốc; những mốc đó rơi về người giữ hiện tại (câu giải
thích của P1 nói điều này).

**Người giữ yêu cầu và người phụ trách vụ tại một thời điểm (R18).** Gần như mọi luồng yêu cầu có
`assigned_to` rỗng (chỉ "Giao việc" tay mới ghi), nên quy theo người giữ **hiện tại** sẽ chuyển mọi
luồng của một vụ — kể cả luồng đã trả lời và đã đóng — sang người nhận mỗi lần bàn giao, và viết lại
các tháng đã qua của người trước. Vì vậy:
- luật sư phụ trách của một vụ tại thời điểm t = `from_user_id` của dòng `matter_reassigned` sớm
  nhất sau t, không có thì `lead_lawyer_id` hiện tại (`ReassignMatter` là đường duy nhất đổi người
  phụ trách, và dòng đó có từ M6.5, nên lịch sử này đầy đủ cả trước M13);
- người giữ một luồng tại thời điểm t = người được giao tại t (`from` của dòng `client_request_assigned`
  sớm nhất sau t, không có thì `assigned_to` hiện tại); rỗng thì là luật sư phụ trách của vụ tại t;
- bàn giao vụ ghi thêm **một dòng `client_request_assigned` cho mỗi luồng** được chuyển (`reason =
  matter_reassigned`), trong cùng transaction;
- lịch sử **không** bỏ qua người đã xoá mềm (khác số "bây giờ" N9, nơi người được giao đã xoá mềm
  nhường cho luật sư phụ trách như đường thông báo); việc của người đã xoá mềm chỉ hiện ở dòng "Chung".
- **Giới hạn đã biết:** luồng giao đích danh cho luật sư cũ rồi bị bàn giao vụ chuyển **trước** ngày
  triển khai M13 không có dòng riêng; luồng chưa giao ai không bị giới hạn này.

**Kỳ đã đóng không trôi (R19).** Mốc cắt của một kỳ = `min(23:59:59 ngày cuối kỳ, bây giờ)`. Mốc xong
sau mốc cắt vẫn là "lỡ" của kỳ đó; yêu cầu "đã trả lời" là trả lời không muộn hơn mốc cắt. Vì vậy
"tháng trước" không tăng dần trong tháng này khi người ta làm nốt việc tồn, và bàn giao sau kỳ không
đổi số của kỳ (R9, R18). Những gì vẫn đổi được sau kỳ, có chủ đích, mỗi việc có người bấm và có dòng
nhật ký: ghi lùi ngày một dòng tiến độ (P4), mở lại một vụ đã kết thúc (admin; P5), gỡ một mốc (P1 →
P2), dời ngày đến hạn của một mốc (P1 dùng ngày đến hạn hiện tại), mở lại một mốc đã xong (ca 8), đóng
một yêu cầu chưa trả lời (mẫu số P3 → P10), huỷ một vụ việc (việc của vụ đã huỷ rời mọi con số, vì
`listableBy` không chứa vụ đã xoá mềm). Ngoài ra, mọi con số chỉ tính trên các vụ người xem đang được
xem: khi người xem không còn được xem một vụ (ví dụ một vụ `restricted` đã bàn giao cho người khác),
việc trên vụ đó không còn trong số người xem đọc, kể cả ở kỳ đã qua. Riêng P8 và hai biểu đồ xu hướng đọc ảnh chụp
hằng ngày (`performance_snapshots`, chỉ tách theo mức bảo mật lúc chụp): số của một ngày đã qua giữ
nguyên như lúc chụp, nên vẫn gồm vụ đã bàn giao, đã huỷ hay người xem nay không còn được xem (sửa câu
2026-10-08, lượt quét trước bản 1.0 — câu "Kỳ đã đóng" và câu xu hướng của trang một người nói theo).
Câu "Kỳ đã đóng" trên màn hình (`performance.explain.closed_period`) kể đúng bảy việc này; test
`SpecM13ParityTest` giữ hai nơi khớp nhau.

**Mốc tạo qua AI (R20).** Khi M11 có mốc "Tạo qua AI, chưa xác nhận", mốc đó tính như mốc thường ở
mọi con số (N5, N6, P1, P9), đúng như `CheckDeadlines` nhắc nó như mốc thường — "một mốc hạn thật
không được im lặng chỉ vì AI tạo". Mốc AI tạo sai thì gỡ kèm lý do và nó hiện ở P2. Đảo phán quyết này
thì sửa cùng lúc `Deadline`, `CheckDeadlines` và widget trang chủ, không loại ở một chỗ.

**Định dạng.** Tỉ lệ in `87,5% (35/40)` (dấu phẩy thập phân, luôn kèm tử và mẫu); thời lượng tính bằng
giờ làm việc, in "3,5 giờ" hoặc "52 giờ" — không bao giờ gộp thành "ngày", vì một ngày làm việc không phải
24 giờ (`ResponseTime::label()`); tiền qua `Money::format()`.

**Ngoài phạm vi, có chủ đích (R15):** không đo giờ làm (`time_entries` vẫn là khung, M13 không đọc),
không tỉ lệ thắng kiện, không "thời gian xử lý theo loại vụ". Không thư tổng hợp, không thông báo mới
(R12); số liệu này không bao giờ rời hệ thống qua MCP (R13, §5).

---

## 7. Giao diện panel `admin`

### 7.1 Trang chủ — các widget, theo thứ tự

1. **Hồ sơ quá hạn cập nhật** — danh sách vụ việc `last_client_update_at` cũ hơn
   14 ngày. Đây là widget quan trọng nhất, đặt trên cùng.
2. **Mốc thời hạn 7 ngày tới** — sắp xếp theo `due_date`, tô đỏ mục `critical`.
3. **Tài liệu chờ duyệt** — khách đã nộp, chưa ai xem.
4. **Hồ sơ thiếu giấy tờ quá 14 ngày** — hồ sơ đang tắc vì khách chưa nộp.
5. **Khách chưa xem cập nhật** — dòng tiến độ đã công bố quá 5 ngày mà chưa có
   bản ghi trong `stage_log_views`. Nghĩa là khách không nhận được email, hoặc
   không biết dùng portal — cần gọi điện.
6. **Thống kê nhanh** — số vụ đang mở theo giai đoạn, dạng biểu đồ cột ngang.
7. **Cảnh báo hệ thống** — hiện dải đỏ nếu `last_schedule_run_at` cũ hơn 30 phút.

**Đính chính ghi ngày 2026-09-22 (chủ văn phòng yêu cầu).** Thêm một hàng **ô số tóm tắt**
đứng trên cả bảy widget: tổng số hồ sơ, đang xử lý, đã kết thúc, mở trong tháng này — mỗi ô
đếm trong phạm vi `Matter::listableBy` của chính người đang xem. Việc nó đứng trên mục 1
không phá luật "widget quan trọng nhất đặt trên cùng": luật đó nói về việc DANH SÁCH nào dẫn
đầu, vì danh sách là thứ người ta phải hành động theo. Một hàng cao một dòng là phần tóm tắt,
không đẩy danh sách quá hạn xuống khỏi màn hình đầu. Nếu hàng này dài thành nhiều dòng thì
đính chính này hết đúng.

**Đính chính 2026-09-24 (M10 — tiếp nhận).** Thêm một widget **"Liên hệ chưa ai gọi lại"**:
danh sách các bản ghi tiếp nhận còn ở trạng thái `new` quá ngưỡng phản hồi (mặc định 4 giờ
làm việc, `INTAKE_RESPONSE_HOURS`), mỗi dòng hiện mã bản ghi, nguồn và thời gian đã chờ —
**không** tên hay số điện thoại của người liên hệ. Mỗi người chỉ thấy các bản ghi trong phạm
vi `IntakeRequest::scopeVisibleTo` của mình (§5, bổ sung M10); người không có quyền `intake.*`
nào không thấy widget. Widget này là một danh sách phải hành động theo như mục 1 và 3, đặt
ngay dưới mục 3 ("Tài liệu chờ duyệt"), không đẩy "Hồ sơ quá hạn cập nhật" xuống. Cài đặt: kế
hoạch M10, Task 5.

**Đính chính 2026-10-04 (M13).** Widget mục 2 "Mốc thời hạn 7 ngày tới" gồm cả mốc đến hạn **đúng
ngày thứ 7** kể từ hôm nay, trên mọi CSDL. Trước M13, cận trên của `Deadline::scopeUpcoming()` là một
ngày trần, nên trên SQLite (cột `date` lưu `Y-m-d 00:00:00`) mốc ngày +7 rơi ra, còn trên MariaDB thì
giữ — đúng lỗi "ngày cuối kỳ" mà M9 đã sửa cho trang Doanh thu (`RevenueFilters::bounds()`). M13 sửa
cận đó thành `23:59:59`, khớp bậc `d7` của `CheckDeadlines` (§6.8): mốc nào được nhắc "còn 7 ngày"
thì cũng có trên widget. Đây là bản sửa lỗi, không phải đổi luật; cùng định nghĩa là nguồn của cột
"Mốc 7 ngày tới" ở §6.14.

### 7.2 Resource `Matter`

Bảng danh sách: mã hồ sơ, khách hàng, loại, tiêu đề, giai đoạn (badge màu),
luật sư phụ trách, cập nhật gần nhất cho khách (hiện "12 ngày trước", tô vàng
khi > 10 ngày, đỏ khi > 14). Lọc theo giai đoạn, loại, luật sư, trạng thái công
bố portal.

Trang chi tiết dùng tabs:

- **Tổng quan** — thông tin vụ việc, đội ngũ, công tắc công bố portal.
- **Tiến độ** — dòng thời gian `stage_logs`, hai nút lớn: *Chuyển giai đoạn* và
  *Thêm cập nhật*. Mỗi dòng hiện rõ đâu là ghi chú nội bộ (nền xám, có nhãn
  "Nội bộ") và đâu là nội dung đã công bố (nền trắng, có nhãn "Khách đã xem").
- **Danh mục hồ sơ** — bảng checklist với thanh tiến độ `X/Y`, thao tác duyệt
  hoặc từ chối ngay trên dòng.
- **Tài liệu** — nhóm theo A/B/C/D. **Nhóm D hiển thị trên nền khác màu rõ rệt
  và có nhãn "Chỉ nội bộ — không bao giờ hiện cho khách".**
- **Các bên** — `matter_parties`. Thêm một bên thì chạy lại kiểm tra xung đột
  lợi ích ngay, hiện kết quả tại chỗ.
- **Mốc thời hạn** — danh sách, thêm nhanh.
- **Liên lạc** — `communication_logs`. Ghi nhanh một cuộc gọi trong dưới 15 giây,
  vì nếu mất lâu hơn thì không ai ghi.
- **Yêu cầu từ khách** — hộp thư của vụ việc.
- **Nhật ký** — activity log của riêng vụ việc này.

**Đính chính 2026-09-24 (M9).** Thêm tab **Hợp đồng và thanh toán**: giá trị hợp
đồng và thuế suất, lịch thu theo đợt (đến hạn khi nào, đã thu, trạng thái), các
khoản thu, phụ lục, và dòng tổng đã thu / còn phải thu / quá hạn. Đây là một tab
**thêm** vào chín tab trên (cùng tab "Đội ngũ" của M6.5), và chỉ hiện với người
được xem tiền của vụ (§5, bổ sung M9). Kế toán không mở được trang vụ việc — màn
hình tiền của kế toán là trang "Công nợ", không phải tab này.

Mỗi dòng tiến độ đã công bố hiển thị nhãn trạng thái đọc: *"Khách đã xem lúc
21:14 ngày 14/09"* hoặc *"Khách chưa xem"* — nhãn chưa xem quá 5 ngày tô vàng.

### 7.3 Form chuyển giai đoạn

Đây là màn hình luật sư dùng nhiều nhất trong ngày, thiết kế phải gọn:

- Chọn giai đoạn mới (chỉ hiện các giai đoạn hợp lệ theo `allowed_next`).
- Ngày xảy ra thực tế (mặc định hôm nay).
- Ghi chú nội bộ — nhãn ghi rõ "Chỉ nội bộ, khách không đọc được".
- Nội dung công bố cho khách — có gợi ý mẫu theo giai đoạn, lấy từ
  `matter_type_stages.client_description`.
- Tiếp theo sẽ là gì.
- Anh/chị cần làm gì (để trống = không cần làm gì).
- Dự kiến có tin tiếp theo trước ngày (tự điền, sửa được).
- Công tắc "Công bố cho khách ngay" — **mặc định bật** khi vụ việc đã bật portal.

Bên dưới form hiển thị **bản xem trước đúng như khách sẽ thấy**, cập nhật trực
tiếp khi gõ. Đây là cách rẻ nhất để ngăn việc vô tình dán ghi chú nội bộ vào ô
công bố.

### 7.4 Các resource khác

`Client`, `ClientUser`, `MatterType` (kèm relation manager cho stages và
checklist template), `User`, `Role`, và trang xem `ActivityLog`,
`OutboundMessage`.

**Đính chính 2026-09-28 (M7 Task 10).** Thêm trang **"Thông tin văn phòng"**, chỉ người có
`settings.manage` (admin) mở được; người khác nhận 404, kể cả ở request cập nhật Livewire. Chủ văn
phòng quyết ngày 2026-09-24 sẽ tự nhập bốn thông tin pháp lý trong app thay vì sửa `.env` trên máy
chủ. Trang sửa chín trường: mã số thuế (10 chữ số, hoặc 13 chữ số dạng `0123456789-001`), Đoàn Luật
sư, số Giấy đăng ký hoạt động, địa chỉ trụ sở, tên pháp lý, hotline (chuẩn hoá qua
`Normalizer::phone()`, lưu theo cách viết trong nước), Zalo và website (URL `http`/`https`), email
liên hệ (Reply-To của mọi thư). Màu, logo, font **không** sửa được trong app.
- Lưu trong bảng mới `settings` (`key` `string(100)` unique, `value` `text` NULL, `updated_by` FK
  `users` NULL, timestamps), một bảng khoá–giá trị **chung**: khoá văn phòng có tiền tố `office.`;
  M11 lưu công tắc MCP vào cùng bảng. Mọi lần ghi qua `App\Actions\Settings\WriteSettings`; lần lưu
  của trang qua `UpdateOfficeProfile`, ghi audit `office_profile_updated` nêu tên các trường đã đổi.
- Đọc qua MỘT nơi, `App\Support\OfficeProfile`: bảng `settings` (giá trị không rỗng) →
  `config('vkcrm.brand.*')`. Ô để trống nghĩa là dùng giá trị `.env`/mặc định. Chân thư (§9), chân
  `MUC-LUC.pdf` (§6.12) và cổng khách hàng đọc qua service này, **lúc render**: thư đang nằm trong
  hàng đợi mang giá trị mới. Thông tin còn trống thì dòng của nó biến mất, không để lại nhãn treo.

### 7.5 Theo dõi đội ngũ và hiệu suất

**Bổ sung 2026-10-04 (M13).** Ba trang tự viết trên panel `admin`, trả lời hai câu hỏi khác nhau —
trộn số "bây giờ" với số "trong kỳ" trên một bảng làm người đọc so hai thứ không cùng thời điểm (R1).
Định nghĩa từng con số ở §6.14, quyền ở §5 (bổ sung 2026-10-04).

| Trang | Đường dẫn | Ai mở được | Trả lời câu |
|---|---|---|---|
| **Theo dõi đội ngũ** | `/admin/team` | `performance.viewAny` (admin, quản lý) | *Bây giờ* ai đang giữ gì, cái gì đang nguy hiểm — hàng đợi hành động: một dòng mỗi người được theo dõi, các cột N1–N10 (N11 chỉ ở trang của một người, §6.14); công tắc "Gồm người đã nghỉ việc"; chỉ các cột đếm việc đang tồn sắp xếp được |
| **Trang của một người** | `/admin/team/{người}` | người có `performance.viewAny`, hoặc chính người đó (có `matter.view`) | Đi sâu từ hai trang kia: danh sách vụ, mốc, yêu cầu, giấy tờ chờ, cơ cấu lĩnh vực, xu hướng 90 ngày |
| **Hiệu suất theo kỳ** | `/admin/performance` | `matter.view` hoặc `performance.viewAny` | *Trong một kỳ*, mỗi người đã làm đúng hạn tới đâu (P1–P10); người có `performance.viewAny` thấy mọi người được theo dõi cùng dòng tham chiếu "Chung", người khác chỉ thấy dòng của chính mình; đoạn "Vì sao không có bảng xếp hạng" |

- **Điều hướng.** Người có `performance.viewAny` thấy "Theo dõi đội ngũ" và "Hiệu suất". Người khác
  có `matter.view` (luật sư, trợ lý) thấy "Việc của tôi" — trỏ tới trang của chính họ — và "Hiệu
  suất". Kế toán không thấy mục nào.
- **Từ chối luôn là 404, và là MỘT 404** (§10 mục 10): trang của admin, của kế toán, của người đã xoá
  mềm, của người ngoài danh sách theo dõi, một id không tồn tại hay không phải số, và trang của một
  đồng nghiệp với luật sư — cùng một response. Cổng được hỏi lại ở **mọi** request Livewire, không
  chỉ lúc tải trang: người mất quyền khi trang còn mở nhận 404 ở thao tác kế tiếp. Id người được xem
  là thuộc tính Livewire bị khoá, trình duyệt không đặt lại được.
- Mọi trang in cùng câu phạm vi cho mọi người xem, kể cả admin: "Mọi con số tính trên các vụ việc
  anh/chị được xem." (§6.14, R4).
- **Chỉ xem, không quyết định tự động (R14).** Hệ thống không tự khoá, nhắc hay đổi quyền dựa trên
  các con số này. Xem số của **người khác** để lại dòng nhật ký `performance_viewed` (§10 mục 6);
  xem số của chính mình thì không. Trang của chính nhân sự hiện cùng bảng "Cách tính các con số"
  như trang của trưởng phòng. Số liệu hiệu suất gắn với một người là dữ liệu cá nhân (Luật
  91/2025/QH15); việc thông báo chính thức cho nhân sự (nội quy, hợp đồng lao động) là việc của
  luật sư văn phòng.
- **Số giữ tạm tối đa 5 phút (R11, đính chính 2026-10-07, rà soát cuối làn M13).** Ngân sách thời gian
  của "Hiệu suất theo kỳ" và trang của một người vẫn vỡ sau khi thêm index, nên hai trang này giữ tạm
  CON SỐ (báo cáo của kỳ; dòng N1–N11 ở đầu trang của một người) cho riêng từng người xem, tối đa 5 phút
  (`PerformanceCache`; khoá gồm id người xem, kỳ, công tắc, tập người trên trang và việc cột doanh thu có
  hiện không). Danh sách việc, bảng "Vụ việc" và mọi lần hỏi quyền luôn đọc trực tiếp. "Theo dõi đội
  ngũ" không giữ tạm. Khối "Cách tính các con số" của hai trang nói số có thể chậm tới 5 phút.

---

## 8. Giao diện panel `portal`

Yêu cầu chung: đơn giản, chữ to, dùng tốt trên điện thoại. Người dùng là khách
hàng ở mọi lứa tuổi, không phải dân công nghệ. Không dùng thuật ngữ kỹ thuật,
không dùng từ viết tắt.

### 8.1 Đăng nhập

Email + mật khẩu → gửi mã OTP 6 số qua email, hiệu lực 5 phút → nhập mã. Sai
quá 5 lần khoá tạm 15 phút theo cả tài khoản lẫn địa chỉ IP. Lần đầu đăng nhập
bắt buộc đổi mật khẩu.

### 8.2 Danh sách hồ sơ

Mỗi hồ sơ là một thẻ: mã hồ sơ, tiêu đề, **nhãn giai đoạn dễ hiểu**
(`client_label`), ngày cập nhật gần nhất, thanh tiến độ danh mục hồ sơ `X/Y`, và
huy hiệu đỏ nếu còn giấy tờ cần nộp.

### 8.3 Chi tiết hồ sơ

Bố cục dọc, theo thứ tự:

1. **Tình trạng hiện tại** — nhãn giai đoạn to, kèm đoạn giải thích giai đoạn
   này nghĩa là gì.
2. **Việc anh/chị cần làm** — chỉ hiện khi có. Ô nổi bật, liệt kê giấy tờ còn
   thiếu và `client_action` của dòng cập nhật mới nhất.
3. **Dòng thời gian** — các `stage_logs` đã công bố, mới nhất trên cùng. Mỗi
   mục hiển thị đúng bốn phần: chuyện gì đã xảy ra, tiếp theo là gì, anh/chị cần
   làm gì, dự kiến có tin tiếp theo khi nào.
4. **Hồ sơ giấy tờ** — danh mục với trạng thái từng mục. Mục `rejected` hiện lý
   do đầy đủ và nút nộp lại. Mục `missing` có nút nộp.
5. **Tài liệu** — chỉ những gì `client_can_view`. Nút tải chỉ hiện khi
   `client_can_download`.
6. **Mốc thời hạn sắp tới** — chỉ những mốc `is_published`.
7. **Gửi yêu cầu** — form đơn giản, xem lại lịch sử trao đổi.

**Đính chính 2026-10-03 (M9 Task 10, P1).** Thêm khối **Hợp đồng và thanh toán**, đứng SAU khối 6
"Mốc thời hạn sắp tới" và TRƯỚC khối 7 "Gửi yêu cầu", và — như khối 2 — **chỉ hiện khi có**: vụ có
hợp đồng `active` hoặc `completed` (§5 Portal, đính chính cùng ngày). Khối gồm: số hợp đồng, ngày
ký, tổng giá trị, thuế suất khi hợp đồng có thuế suất (kể cả 0%), "Hợp đồng đã hoàn tất ngày …" khi
đã hoàn tất; mỗi đợt (trừ đợt đã huỷ) — tên, số tiền, "Đến hạn ngày …" hoặc "Đến hạn khi vụ việc
tới bước: <nhãn cho khách của giai đoạn>", đã thanh toán, còn lại, tình trạng ("Quá hạn thanh
toán" luôn bằng chữ kèm màu; đợt miễn chỉ "Văn phòng đã miễn"; hợp đồng đã hoàn tất thì tình trạng
của hợp đồng thay cho tình trạng từng đợt); các khoản văn phòng đã nhận (trừ khoản đã huỷ) — ngày,
số tiền, cách trả. Không ghi chú, lý do, người ghi, mã giao dịch, biên lai, phụ lục. Tiền định dạng
một chỗ (`Money::format()`), trạng thái đợt suy ra một chỗ (`Instalment::state()`). Một cột, không
bảng, như phần còn lại của trang.

### 8.4 Nộp tài liệu

Chọn đầu mục → tải tệp lên (hỗ trợ chụp ảnh trực tiếp trên điện thoại) → xem
trước → gửi. Sau khi gửi hiện trạng thái "Đang chờ văn phòng kiểm tra".

Thông báo lỗi phải nói rõ phải làm gì, không được nói "Upload failed":
"Tệp vượt quá 20 MB. Anh/chị thử chụp lại ở chế độ ảnh thường thay vì HDR, hoặc
gửi từng trang một."

---

## 9. Email

Toàn bộ mẫu email đặt trong `resources/views/emails/`, dùng chung một layout có
logo và chân trang công ty. Gửi qua SMTP tên miền riêng, cấu hình trong `.env`.

| Mẫu | Kích hoạt khi |
|---|---|
| `client.activation` | Tạo tài khoản portal |
| `client.otp` | Đăng nhập portal |
| `client.stage_update` | Công bố một dòng tiến độ |
| `client.missing_documents` | Job nhắc bổ sung giấy tờ |
| `client.document_rejected` | Từ chối một giấy tờ |
| `client.document_published` | Công bố văn bản nhóm B hoặc C |
| `staff.deadline_reminder` | Job nhắc hạn |
| `staff.stale_matter` | Job SLA 14/21 ngày |
| `staff.new_client_document` | Khách nộp tài liệu |
| `staff.new_client_request` | Khách gửi yêu cầu |
| `client.request_answered` | Văn phòng trả lời một yêu cầu của khách |
| `staff.instalment_overdue` | Job nhắc đợt thanh toán quá hạn — thêm 2026-09-24 (M9), người nhận theo §6.8 đính chính M9 |
| `staff.intake_unanswered` | Job nhắc một lần liên hệ chưa ai gọi lại quá ngưỡng phản hồi — thêm 2026-09-24 (M10), người nhận và nội dung theo đính chính M10 dưới đây |

**Đính chính 2026-09-24 (M9).** Mẫu `staff.instalment_overdue` là thư **nội bộ**;
nội dung đi qua cùng ranh giới với màn hình tiền của kế toán (§5 bổ sung M9): mã
hồ sơ, loại vụ việc, tên khách, tên đợt, các con số và ngày — không tiêu đề vụ
việc. M9 **không** gửi thư nhắc nợ nào cho khách.

**Đính chính 2026-09-30 (M9 Task 11).** Nội dung thật của mẫu: tiêu đề "Đợt thanh toán
quá hạn N ngày: <tên đợt> (<mã hồ sơ>)"; thân thư gồm mã hồ sơ kèm loại vụ việc, tên khách
hàng, tên đợt, số tiền **còn phải thu** (không phải giá trị mặt của đợt), ngày đến hạn, số
ngày quá hạn, và một nút liên kết (§6.8 đính chính 2026-09-30 nói liên kết đi đâu). Dòng
`outbound_messages` của thư gắn vào đợt (`related` = `instalment`); vì bảng `instalments`
không có `matter_id`, dòng đó chỉ admin thấy ở màn hình nhật ký thư (không nới điều này).

Email gửi cho khách **chỉ chứa nội dung đã công bố**, tuyệt đối không nhúng
`internal_note`. Nội dung tóm tắt ngắn, chi tiết mời bấm vào portal.

**Đính chính 2026-09-27 (M6.5 Task 21, `requests/REQ-4`).** Thêm mẫu `client.request_answered`
vào bảng trên. Đợt kiểm tra 2026-09-24 tìm ra: khách gửi câu hỏi qua cổng, văn phòng trả lời, mà
khách không được báo bằng cách nào (không thư, không dấu hiệu trên thẻ hồ sơ). Đây là khoảng trống
của chính SPEC. Chủ văn phòng giao "làm cho tốt nhất". Mẫu gửi khi văn phòng trả lời một luồng
(luồng chuyển sang `answered`), tới các tài khoản cổng của khách sở hữu luồng, cùng điều kiện với
mọi thư cho khách: `is_active` **và** `activated_at` không null (M6.5 R12). Cài đặt thuộc M6 Task 4
(`docs/superpowers/plans/2026-09-21-m6-notifications.md`), cùng với huy hiệu "có trả lời mới" trên
thẻ hồ sơ ở cổng; M6.5 không viết mẫu thư này (R1).

**Đính chính 2026-09-24 (M10 — tiếp nhận).** Thêm mẫu `staff.intake_unanswered` vào bảng
trên: thư **nội bộ** nhắc rằng một lần có người liên hệ văn phòng quá ngưỡng phản hồi (mặc
định 4 giờ làm việc) mà chưa ai gọi lại. Người nhận: người được giao nếu còn hoạt động và còn
xem được bản ghi; nếu không thì những người có `intake.viewAny` đang hoạt động; cuối cùng là
admin — không bao giờ im lặng, và qua đúng một chỗ chọn người nhận nhân sự
(`ResolveStaffRecipients`). Thư chỉ mang mã bản ghi, nguồn, thời gian đã chờ và liên kết;
**không** tên, số điện thoại hay câu chuyện của người liên hệ, vì hộp thư là nơi dữ liệu nằm
lâu nhất và ít ai kiểm soát nhất. Cài đặt: kế hoạch M10, Task 5.

**Đính chính 2026-10-03 (M7, gộp vào `main`; việc sau gộp, làn fu2).** M7 thêm hai mẫu thư NỘI BỘ
vào bảng trên, và đổi một hành vi của `client.document_published`:

| Mẫu | Kích hoạt khi |
|---|---|
| `staff.matter_reassigned` | Bàn giao một hay nhiều vụ việc sang luật sư phụ trách mới (§6.11 bước 3, R10) |
| `staff.handover_ready` | Gói bàn giao hồ sơ sinh xong (§6.12 bước 3) |

- `staff.matter_reassigned`: một thư tổng hợp cho cả lô, chỉ tới luật sư phụ trách MỚI, liệt kê các
  mốc thời hạn chưa xong vừa chuyển sang họ. Tiêu đề chỉ nêu số vụ, không nêu mã. Dòng
  `outbound_messages` gắn vào chính người nhận (`related` = người dùng), nên ở màn hình nhật ký thư
  chỉ admin thấy dòng đó.
- `staff.handover_ready`: tới luật sư phụ trách và người bấm "Sinh gói bàn giao", qua
  `ResolveStaffRecipients` (R3), kèm một chuông trong hệ thống. Thư nội bộ nên tiêu đề mang mã hồ sơ.
  Dòng nhật ký thư gắn vào tài liệu gói (`related` = tài liệu). Job gửi thẳng thư từ trong nó, không
  xếp thêm một job thư mang model người nhận (làn fu2).
- Cả hai mẫu **không gửi lại được** từ nhật ký thư (`ResendTargets::NOT_RESENDABLE`, mỗi mẫu một câu
  từ chối): thư tổng hợp liệt kê các mốc ở đúng lúc bàn giao, gửi lại là gửi một danh sách cũ, và các
  mốc vẫn hiện ở trang chủ, ở tab "Mốc thời hạn" và trong thư nhắc mốc; thư gói chỉ báo một sự kiện
  đã qua, trạng thái gói luôn hiện trên trang vụ việc và chuông đã báo cùng lúc.
- `client.document_published` khi tài liệu là **gói bàn giao hiện tại** của vụ
  (`matter_archives.handover_document_id`): thư đi cả khi vụ đã kết thúc (ngoại lệ duy nhất của điều
  kiện "vụ còn mở" mà M6 đặt cho mẫu này), tới các tài khoản R12 mà vụ còn trên cổng của chính họ
  (chưa quá `client_access_until`). Tiêu đề và thân thư là của gói: nói đây là gói hồ sơ bàn giao
  (các tài liệu của hồ sơ cùng `MUC-LUC.pdf`) và hạn tải theo `client_access_until`, không nêu tên
  tài liệu nào; gói công bố "chỉ xem" thì thư không hứa tải được. Tài liệu thường trên vụ đã kết thúc
  vẫn không gửi thư này.

**Đính chính 2026-10-04 (M12 Task 10, phán quyết R10–R11 của kế hoạch
`docs/superpowers/plans/2026-09-24-m12-pwa.md`).** Thông báo đẩy trên điện thoại (kênh `push`, §4.15
đính chính M12 Task 7) đi CÙNG một thư của bảng trên, không bao giờ thay thư. Không có luật người
nhận thứ hai: người nhận push là ĐÚNG những người lượt gửi đó vừa gửi thư thành công (khách: M6.5
R12; nhân sự: `ResolveStaffRecipients`, gồm luật vụ `restricted`), nên chống trùng cũng là sổ thư.
Chủ đề (`App\Enums\PushTopic`) mang đúng tên mẫu thư nó đi cùng:

| Chủ đề | Đi cùng thư | Người nhận | Chạm vào thì mở |
|---|---|---|---|
| `client.stage_update` | `client.stage_update` | khách | trang tiến độ hồ sơ trên cổng |
| `client.document_published` | `client.document_published` | khách | trang tiến độ, khối Tài liệu |
| `client.document_rejected` | `client.document_rejected` | khách | trang tiến độ, khối Hồ sơ giấy tờ |
| `client.request_answered` | `client.request_answered` | khách | trang yêu cầu của hồ sơ |
| `staff.deadline_reminder` | `staff.deadline_reminder`, mọi bậc và quá hạn (mỗi bậc một lần) | như thư | trang vụ việc, tab Mốc thời hạn |
| `staff.new_client_request` | `staff.new_client_request`, kể cả khách viết tiếp vào yêu cầu cũ (`REQ-2`, không có thư — cùng người nhận với thông báo trong hệ thống; gom theo luồng: câu viết tiếp chưa tới 10 phút sau lời trước của chính khách không đẩy lại, thông báo trong hệ thống vẫn có — vòng sửa cuối M12) | như thư | trang vụ việc, tab Yêu cầu từ khách |
| `staff.new_client_document` | `staff.new_client_document` | như thư | trang vụ việc, tab Danh mục hồ sơ |
| `staff.instalment_overdue` | `staff.instalment_overdue` (M9) | như thư | trang Công nợ cho người mở được nó, không thì tab Hợp đồng và thanh toán |
| `staff.handover_ready` | `staff.handover_ready`, báo gói bàn giao đã sinh (M7 Task 4; nối lúc gộp `main` vào nhánh M12) | như thư | trang vụ việc, tab Tài liệu (nơi xem lại và công bố gói) |

- Nội dung: tiêu đề là tên văn phòng, thân là chỉ một câu chung (`lang/vi/push.php`) — không mã hồ
  sơ, tiêu đề vụ, tên khách, tên các bên, tiêu đề tài liệu, tên giấy tờ, lý do từ chối, nội dung
  câu hỏi/trả lời hay `internal_note`; điện thoại nằm trên bàn và người nhà đọc được màn hình khoá
  (R11). Mức khẩn của mốc hạn ("hôm nay hoặc ngày mai", "đã quá hạn") được phép.
- TTL 24 giờ cho mốc hạn, 72 giờ cho các chủ đề khác; độ khẩn `high` cho mốc hạn bậc 1 ngày và quá
  hạn, `normal` cho còn lại. Thêm nút "Gửi thử" (`push.test`) trên trang "Thông báo trên điện thoại".
- Cố ý KHÔNG đẩy: `client.otp` (một mã trên màn hình khoá là một mã lộ), `client.activation` (chưa
  kích hoạt thì chưa có máy), `client.missing_documents`, `staff.stale_matter` (không gấp; thư đã
  đủ), cảnh báo xung đột lợi ích và mọi thư lỗi sao lưu (đọc trên máy tính). Hai thư nhân sự có trên
  `main` khi M12 gộp cũng không đẩy: `staff.matter_reassigned` (M7 — thư tổng hợp mốc hạn khi bàn
  giao vụ, một danh sách để đọc trên máy tính; mỗi mốc vẫn có thư nhắc và thông báo đẩy riêng theo bậc)
  và `staff.intake_unanswered` (M10 — nhánh M12 cắt trước M10; đẩy thư này, nếu muốn, là việc của M10
  qua `PushTopic`). Mọi mẫu thư về sau phải được xếp vào một trong hai bên (`PushTopicTest`).
- Email không tắt được với mọi chủ đề và mọi người (R14): email là chứng cứ "văn phòng có báo cho tôi
  không"; push là tiện ích, và "nhận push hay không" chính là "máy này đã bật chưa".

---

## 10. Bảo mật — yêu cầu cụ thể

1. HTTPS bắt buộc, middleware ép chuyển hướng, bật HSTS ở tầng web server.
2. Header bảo mật: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
   `Referrer-Policy: strict-origin-when-cross-origin`, Content-Security-Policy
   không cho `unsafe-inline` script.

   **Đính chính 2026-10-04 (§10.2, M12 Task 10, phán quyết R5).** App trên điện thoại cần bốn chỉ thị
   trong CSP của trang (M8 R4, `App\Support\Security\ContentSecurityPolicy::policy()`), đều đã có:
   `manifest-src 'self'` (manifest của hai app), `worker-src 'self' blob:` (`'self'` cho service
   worker `/{admin,portal}/sw.js`; `blob:` có từ M8 cho bản xem trước ảnh của ô tải tệp),
   `connect-src 'self'` (script đăng ký thiết bị gửi về máy chủ), `img-src 'self' data: blob:` (biểu
   tượng). Không thêm script nội tuyến nào: việc đăng ký nằm trong tệp tĩnh `public/pwa/register.js`,
   tham số và chuỗi tiếng Việt đi qua thuộc tính `data-*`. Máy chủ push của Apple, Google và Mozilla
   do TRÌNH DUYỆT gọi, không phải script của trang, nên KHÔNG thêm vào `connect-src` — đừng "sửa" bằng
   cách mở rộng nó. Bản thân `sw.js` mang CSP riêng `default-src 'self'`
   (`ContentSecurityPolicy::WORKER_POLICY`), cùng `Service-Worker-Allowed` và `Cache-Control: no-cache`.
3. Rate limit: đăng nhập 5 lần / 15 phút theo email và theo IP; nộp tài liệu 20
   tệp / giờ / tài khoản; API 60 request / phút.

   **Đính chính 2026-09-30 (§10.3, M8 Task 3).** (a) "Đăng nhập" gồm cả hai cổng và cả hai bước
   của mỗi cổng (mật khẩu, rồi mã — TOTP/mã khôi phục của nhân sự, mã email của khách): mỗi bước
   một bộ đếm riêng, mỗi bộ đếm hai chiều (tài khoản + IP), `App\Support\LoginThrottle`. (b) "20
   tệp / giờ" đếm TỆP (một request mang nhiều tệp tốn nhiều suất; cả request bị từ chối nếu vượt),
   không đếm request, và là luật nộp tài liệu của KHÁCH; nhân sự có trần riêng 200 tệp / giờ /
   tài khoản trên cùng endpoint (`App\Support\UploadThrottle`). (c) "API 60 request / phút": hôm
   nay chưa có route `api/*` (có test khẳng định); giới hạn thuộc M11.

   **Đính chính 2026-10-08 (§10.3, M11 Task 17).** "API 60 request / phút" áp ở máy chủ MCP
   (`POST /mcp`, §16) — vẫn không có route `api/*`. Đếm LẦN GỌI TOOL (`tools/call`), mỗi giới hạn hai
   khoá độc lập, theo người và theo access token (`App\Support\Mcp\McpRateLimits`): mọi tool 60 / phút;
   `search` và `fetch` mỗi tool 30 / phút; bốn tool ghi chung 10 / phút và 100 / ngày. Vượt thì HTTP
   429 kèm `Retry-After` và `X-RateLimit-*`, một dòng nhật ký `outcome = rate_limited`; lần bị chặn
   không tính vào lượt. Bộ đếm trên cache `database` (không bao giờ `array` ở máy thật: dưới PHP-FPM nó
   về 0 ở mỗi request). Thêm: `/oauth/register` 10 lần / giờ / IP, đếm cả lần hỏng; `/oauth/token` giữ
   `throttle` mặc định của Passport (60 / phút); request `/mcp` mang bearer sai bị đếm theo IP và dấu
   băm của bearer (30 lần / phút, `ThrottleMcpAuthenticationFailures`). Cảnh báo trong hệ thống cho
   admin khi một người đọc quá 200 bản ghi trong một giờ, tối đa một cảnh báo mỗi người mỗi giờ.
4. Tệp lưu ở `storage/app/private/`, có `.htaccess` chặn và cấu hình nginx tương
   ứng. Phục vụ qua route có `signed` URL hết hạn sau 5 phút, và vẫn kiểm tra
   policy trong controller — chữ ký URL không thay thế kiểm tra quyền.
5. Cột `clients.id_number` dùng cast `encrypted`. Không log giá trị này ở bất
   kỳ đâu.
6. Activity log bắt buộc ghi: đăng nhập thành công và thất bại (cả hai guard),
   tải tài liệu, công bố tài liệu, công bố tiến độ, đổi phân quyền, tạo và vô
   hiệu hoá tài khoản portal, xuất dữ liệu.

   **Đính chính 2026-09-27 (§10.6, M6.5 Task 21).** M6.5 thêm 19 sự kiện `Audit::record()`
   vào danh sách bắt buộc ghi. Đếm bằng cách so `Audit::record('…'` giữa `main` và nhánh gộp
   `m6-5-lane-d` ngày 2026-09-27:
   - vụ việc: `matter_details_updated`, `matter_cancelled`, `matter_reassigned` (R7, R14);
   - đội ngũ và nghỉ việc: `team_member_added`, `team_member_removed`,
     `deadline_responsible_changed`, `user_password_reset` (R6, R7);
   - các bên và xung đột: `matter_party_updated`, `matter_party_removed`,
     `client_identity_conflict_detected`, `client_identity_recheck_failed` (R13, R14);
   - tra khách khi mở vụ: `client_lookup`, `client_lookup_throttled` (R4; không ghi số thô);
   - tài liệu nhóm B: `document_submitted_for_approval`, `document_signed_filed`,
     `document_returned_to_draft` (R9);
   - danh mục: `checklist_item_added`;
   - cổng và thư: `portal_login_unlocked`, `deadline_reminder_failed`.

   Task 14 (sửa và xoá mốc hạn) còn đang làm lúc ghi đính chính này và có thể thêm sự kiện. Trước
   khi merge, chạy lại phép so trên và `ActivityLogEventTranslationsTest` (mọi sự kiện phải có
   nhãn trong `lang/vi/activity.php`).

   **Đính chính 2026-09-30 (§10.6, M8 Task 3).** Ba loại trước đây chỉ có GIÁN TIẾP (một diff
   `updated` của `LogsActivity`, hoặc một cờ trong properties của sự kiện khác) nay là sự kiện
   tường minh: `stage_log_published` (`TransitionMatterStage`, cả chuyển giai đoạn lẫn "Thêm cập
   nhật"), `permission_changed` (`RecordStaffPermissionChange`, kèm chức danh/vai trò cũ → mới),
   `portal_account_created` / `portal_account_deactivated` (`CreatePortalAccount`,
   `UpdatePortalAccount`). Thêm `staff_login_unlocked` (`UnlockStaffLogin`). "Xuất dữ liệu" hôm
   nay chỉ có MỘT đường — tải một tài liệu (`documents.download`, đã ghi `document_downloaded`);
   test `ActivityLogSpec106Test` đóng băng tập đường xuất đó, và gói bàn giao hồ sơ (M7 Task 4)
   phải ghi `data_exported` khi ra đời. Nhật ký không bị xoá theo lịch (không có tác vụ
   `activitylog:clean`; con số của gói ≥ `RETENTION_YEARS`).

   **Đính chính 2026-10-03 (§10.6, M7 Task 11).** M7 thêm 10 sự kiện `Audit::record()`, đếm bằng
   cách so mọi `Audit::record('…'` trong `app/` (kể cả lời gọi xuống dòng) giữa gốc làn `d2de674`
   và nhánh `m7-handover` sau khi gộp làn m7b; cả 10 đều có nhãn trong `lang/vi/activity.php`:
   - bàn giao: `matter_reassignment_digest_failed` (Task 1, thư tổng hợp hỏng hẳn);
   - gói bàn giao và **xuất dữ liệu**: `handover_package_requested`, `handover_package_failed`,
     `data_exported` (Task 4 — ghi khi gói sinh xong VÀ mỗi lần gói được tải; đây là mục "xuất dữ
     liệu" của danh sách gốc ở trên);
   - **vô hiệu hoá tài khoản portal**: `portal_account_deactivated` (Task 5, `ExpireClientAccess`;
     mỗi tài khoản cũng được lưu từng model nên `LogsActivity` ghi thêm dòng "cập nhật");
   - lưu trữ: `matter_destruction_recorded` (Task 6);
   - tài liệu: `document_retracted` (Task 7);
   - nhật ký liên lạc: `communication_logged`, `communication_log_deleted` (Task 8);
   - thông tin văn phòng: `office_profile_updated` (Task 10).
   Lúc gộp M7 vào `main` (sau M8): `portal_account_deactivated` đã có trên `main` từ M8 Task 3
   (`UpdatePortalAccount`), nên với `main` M7 chỉ thêm một ĐƯỜNG ghi thứ hai cho nó
   (`ExpireClientAccess`), không thêm khoá mới — chín sự kiện mới so với `main`. Gói bàn giao
   (Task 4) là đường "xuất dữ liệu" thứ hai mà đính chính M8 Task 3 ở trên báo trước.

   **Đính chính 2026-10-03 (§10.6, M9 — ghi ở Task 13).** M9 thêm 11 sự kiện `Audit::record()`
   cho tiền, đếm bằng mọi literal `Audit::record('…'` trong `app/Actions/Billing/`; cả 11 có nhãn
   trong `lang/vi/activity.php`: `contract_drafted`, `contract_draft_updated`,
   `contract_draft_deleted`, `contract_activated`, `contract_amended`, `contract_completed`,
   `contract_cancelled`, `payment_recorded`, `payment_voided`, `instalment_waived` — người làm là
   causer — và `instalment_triggered` (Task 6): đợt theo giai đoạn đến hạn là hệ quả của một lần
   chuyển giai đoạn, không phải quyết định của ai, nên dòng này **không causer** (trang nhật ký
   hiện "Hệ thống"); nguồn gốc ở `properties.stage_log_id` → `stage_logs.created_by`. Dòng tiền
   trên trang nhật ký chỉ hiện cho người có `billing.view` (`ActivityOwningMatter`, P3).

   **Đính chính 2026-10-04 (§10.6, M13).** Số liệu hiệu suất gắn với một người là dữ liệu cá nhân của
   nhân sự (§7.5), nên xem số của người khác là một sự kiện bắt buộc ghi, và lịch sử người giữ việc
   phải đủ để quy việc về đúng người (§6.14, R9, R18):
   - **`performance_viewed`** (mới, causer là người xem, nhãn trong `lang/vi/activity.php`): một dòng
     mỗi lần người có `performance.viewAny` mở "Theo dõi đội ngũ" (chủ thể rỗng, `page =
     team_overview` — trang đó hiện số của mọi người được theo dõi); một dòng mỗi lần mở trang của
     **một người khác** (chủ thể là người đó); một dòng mỗi lần người có `performance.viewAny` mở
     "Hiệu suất theo kỳ" hoặc đổi kỳ (chủ thể rỗng, `properties` mang kỳ). Không ghi khi xem số của
     chính mình, không ghi lại ở mỗi request Livewire (bật công tắc, sắp xếp).
   - **`deadline_responsible_changed`** có thêm hai đường ghi, mỗi dòng mang `reason`:
     `matter_reassigned` (bàn giao vụ — một dòng cho **mỗi** mốc chưa xong được chuyển, trước đây dòng
     `matter_reassigned` chỉ mang số lượng) và `deadline_updated` (sửa mốc khi người phụ trách thật sự
     đổi; dòng `deadline_updated` vẫn giữ). Lý do có sẵn `reopened_holder_no_longer_qualifies` (lần mở
     lại có chuyển người) nay có nhãn tiếng Việt; trang nhật ký in nhãn lý do thay mã khi có nhãn.
   - **`client_request_assigned`** có thêm đường ghi từ bàn giao vụ: một dòng cho **mỗi** luồng yêu
     cầu giao đích danh cho luật sư cũ được chuyển sang luật sư mới (`from`, `to`, `reason =
     matter_reassigned`), trong cùng transaction với lần chuyển.
7. 2FA bắt buộc cho toàn bộ tài khoản nội bộ. Không có tuỳ chọn tắt.
8. `spatie/laravel-backup` cấu hình sao lưu hằng ngày cả CSDL lẫn thư mục tệp,
   đẩy ra một disk ngoài máy chủ (S3 hoặc tương đương), giữ 30 bản.
9. Khi `client_users.is_active = false`, mọi phiên đang mở phải bị vô hiệu ngay
   ở request kế tiếp, không đợi hết hạn session.
10. Không bao giờ trả về thông điệp lỗi tiết lộ sự tồn tại của bản ghi. Không có
    quyền và không tồn tại đều trả 404.

   **Đính chính 2026-10-04 (§10 mục 4, M14, R3, R5).** Khi kho tài liệu đã bật, tệp nằm trên một
   Shared Drive của văn phòng mà chỉ tài khoản dịch vụ truy cập, ở vai Người quản lý nội dung (không
   xoá vĩnh viễn, không chuyển tệp ra ngoài, không quản lý thành viên); Shared Drive "chỉ thành viên",
   thành viên và vai được kiểm mỗi giờ (`storage.health`), và mã không có lệnh tạo, sửa hay xoá quyền
   chia sẻ nào. `storage/app/private/` là vùng đệm (tệp mới, tệp chưa có bản ngoài Google), giữ nguyên
   `.htaccess` và cấu hình nginx. Route ký 5 phút cộng policy giữ nguyên: tải về luôn đi qua CRM, không
   redirect, không link Drive, không mã tệp Drive nào rời máy chủ, và không lệnh gọi Drive nào trước khi
   chữ ký, người nhận và policy đã đạt.

   **Đính chính 2026-10-04 (§10 mục 8, M14, R10).** CSDL sao lưu như cũ (`backup:run` 02:00, archive
   AES-256, 30 bản ngoài máy chủ). Archive vẫn gói `storage/app/private`, nay là vùng đệm cộng tệp chưa
   có biên nhận văn phòng — đúng những tệp chưa có bản ngoài Google. Tệp trên kho có **bản thứ hai ở
   máy chủ văn phòng**: máy đó KÉO về (`tools/backup/office-pull.sh`, `rclone copy --immutable` bằng
   token chỉ đọc) vào một remote `crypt` (tên và nội dung tệp mã hoá); mật khẩu `crypt` và mật khẩu cấu
   hình rclone không bao giờ nằm trên máy chủ web. Vùng đệm chỉ được dọn khi tệp đã ở kho, quá thời gian
   ân hạn, và có biên nhận văn phòng khớp tên, thế hệ và md5 từ 24 giờ trở lên (`ImportOfficeReceipts`
   là đường duy nhất ghi `office_copied_at`); chưa có máy văn phòng thì không tệp nào được dọn.
   **Tệp trên Kho không mã hoá phía văn phòng**: Google mã hoá khi lưu bằng khoá của Google, nên về kỹ
   thuật Google và quản trị viên Workspace đọc được tệp — khác bảo đảm trước M14 "mọi bản ngoài máy chủ
   đều là archive AES-256 của văn phòng". Bản ở văn phòng (`crypt`) và archive CSDL vẫn mã hoá bằng khoá
   của văn phòng. Chủ văn phòng ký nhận điều này (câu hỏi 8 của kế hoạch M14); ngày ký: **PENDING
   OWNER — chưa ký**. Thùng rác 30 ngày và lịch sử phiên bản của Drive không phải sao lưu.

---

## 11. Kiểm thử bắt buộc

Dùng Pest. Các test sau là điều kiện nghiệm thu, không phải tuỳ chọn:

### Cách ly dữ liệu giữa khách hàng
- Khách A đăng nhập, gọi trực tiếp URL chi tiết vụ việc của khách B → 404.
- Khách A tải trực tiếp URL tài liệu của khách B → 404.
- Danh sách hồ sơ của khách A không bao giờ chứa vụ việc của khách B, kể cả khi
  truyền tham số lọc thủ công.

### Tài liệu nội bộ
- Tài liệu nhóm D không xuất hiện trong bất kỳ truy vấn nào dưới guard `client`.
- Gọi `PublishDocument` với tài liệu nhóm D → ném exception, không đổi dữ liệu.
- Tài liệu nhóm B ở trạng thái `internal_draft` hoặc `pending_approval` không
  công bố được.

### Ghi chú nội bộ
- Response JSON và HTML của portal không chứa chuỗi trong
  `stage_logs.internal_note` — test bằng cách đặt một chuỗi đánh dấu duy nhất
  vào `internal_note` rồi khẳng định nó không xuất hiện ở bất kỳ đâu trong
  response.

### Quyền nội bộ
- Luật sư không có tên trong `matter_user` không xem được vụ việc đó.
- Vụ việc `restricted` chỉ lead lawyer và admin xem được.
- Kế toán không xem được nội dung hồ sơ.

### Nghiệp vụ
- Chuyển giai đoạn sai `allowed_next` → ném exception.
- Công bố tiến độ với `public_content` dưới 30 ký tự → lỗi xác thực.
- Từ chối checklist item không kèm lý do → lỗi xác thực.
- Job thông báo chạy hai lần chỉ gửi một email (`notified_at` chống trùng).
- Khách nộp lại tài liệu tạo bản version 2, bản version 1 vẫn còn.

### Xung đột lợi ích
- Tạo vụ việc mới trong đó bị đơn trùng số căn cước với một khách hàng hiện hữu
  → bị chặn ở mức đỏ, không lưu được.
- Cùng tình huống nhưng vai trò `manager` ghi đè có nhập lý do → lưu được, và
  activity log chứa lý do đó.
- Trùng tên nhưng khác số căn cước và khác số điện thoại → chỉ cảnh báo vàng,
  vẫn lưu được sau khi tích xác nhận.
- Kết quả kiểm tra xung đột hiển thị mã hồ sơ liên quan **nhưng không hiển thị
  tiêu đề, nội dung hay tài liệu** của hồ sơ mà người dùng không có quyền.
- Mọi lần chạy kiểm tra đều sinh bản ghi activity log, kể cả khi kết quả xanh.

### Bàn giao và lưu trữ
- Vô hiệu hoá tài khoản luật sư còn là lead lawyer của vụ việc đang mở → bị
  chặn, thông điệp nêu rõ số vụ cần bàn giao.
- Bàn giao vụ việc tự sinh dòng `stage_logs` nội bộ và chuyển deadline **CHƯA
  HOÀN THÀNH** sang người mới (Đính chính 2026-09-28, M7 Task 1, R10: không phải
  "toàn bộ" — mốc đã xong ở lại với người đã hoàn thành nó), kèm một thư tổng
  hợp qua hàng đợi liệt kê đúng những mốc đã chuyển.
- Gói bàn giao không bao giờ chứa tài liệu nhóm D — test bằng cách giải nén và
  khẳng định.
- Quá `client_access_until` thì vụ việc biến mất khỏi portal của khách nhưng vẫn
  còn nguyên trong admin panel.

**Đính chính 2026-10-03 (M7 Task 11).** Bốn test trên đọc như sau; tên test cụ thể ở PROGRESS,
"Ghi chú M7", mục Task 11:
- *Test 1* đi qua đúng form sửa nhân sự (tắt `is_active`), và thông điệp đếm vụ ĐANG MỞ — vụ đã
  kết thúc không cần bàn giao nên không tính.
- *Test 3* giải nén tệp zip thật và khẳng định trên danh sách entry đọc lại, không trên mảng dựng
  trước khi nén. Ngoài nhóm D, gói cũng không bao giờ chứa tài liệu đã xoá mềm, bản nháp hay bản
  chờ duyệt của nhóm B/C, tài liệu đã rút, và gói của lần trước (đính chính M7 Task 4 ở §6.12).
- *Test 4*: "quá" nghĩa là từ 00:00 ngày SAU `client_access_until` theo giờ ứng dụng — khách còn
  xem được hết ngày đó (đính chính M7 Task 5 ở §6.12).

### Tải tệp
- Tải tệp `.svg` bị từ chối.
- Tệp vượt 20 MB bị từ chối.
- Tệp có phần mở rộng `.pdf` nhưng MIME thực tế là `application/x-dosexec` bị
  từ chối.

### Theo dõi đội ngũ

**Bổ sung 2026-10-04 (M13, §5, §6.14, §7.5).** Test ở `tests/Feature/Performance/`:
- Vụ `restricted` không lộ qua con số: một vụ `restricted` của luật sư L chứa một bản ghi cho mọi chỉ
  số; số trưởng phòng đọc về L — trên **mọi** trường của các dòng "bây giờ" và "trong kỳ" (lặp theo
  tên thuộc tính, kể cả dòng "Chung" và "Lĩnh vực chính") và trên dữ liệu xu hướng — bằng đúng số khi
  vụ đó không tồn tại; không cột nào của L đổi giữa "0" và "Không áp dụng"; L và admin thấy vụ đó
  được tính.
- Kế toán nhận 404 ở cả ba trang và ở widget xu hướng, kể cả trang "của mình".
- Luật sư và trợ lý chỉ thấy số của chính mình: 404 ở "Theo dõi đội ngũ" và ở trang của người khác;
  trên "Hiệu suất theo kỳ" chỉ có dòng của chính mình. Trang của admin, của kế toán, của người đã xoá
  mềm và của một id không tồn tại trả cùng một 404.

Mục tiêu độ phủ: tối thiểu 80% cho `app/Actions/` và `app/Policies/`.

**Đính chính 2026-10-04 (M14 Task 4 và Task 8, R3, R12).** Thêm vào "Tải tệp", cho tài liệu nằm trên
kho (`tests/Feature/Http/DocumentDownloadFromRemoteTest.php`):
- mọi nhánh từ chối của route tải (chữ ký hỏng, hết hạn, sai người nhận, nhóm D, vụ `restricted`
  ngoài đội ngũ, tài liệu của khách khác, đã rút, khách bị vô hiệu hoá) không gọi kho lần nào — đo bằng
  đĩa gián điệp VÀ bằng adapter Drive thật trên HTTP giả (`Http::assertNothingSent()`), mỗi cách có cặp
  dương (người có quyền: đúng một `GET …alt=media`);
- `HEAD` không mở luồng, không gửi request nào tới Google, không ghi nhật ký;
- kho sập lúc mở luồng → trang 503 tiếng Việt kèm `Retry-After`, không một dòng `document_downloads`;
  trang 503 của khách mang hotline văn phòng;
- chỉ mục còn mà Drive trả 404 → 404, không dòng nhật ký, log `critical`;
- tải thành công: đúng byte, header lấy từ dòng `media`, tên tiếng Việt có dấu → `filename=` ASCII
  cộng `filename*=UTF-8`;
- rút một tài liệu nằm trên kho: tệp trên Drive còn nguyên, đường ký cũ trả 404 mà không gọi Drive.

Các test tải hiện có (`DocumentDownloadTest`, `HandoverPackageDownloadTest`,
`RetractedDocumentNoticeTest`, hai test tải của `PortalIsolationSweepTest` ở "Cách ly dữ liệu giữa
khách hàng") chạy ở HAI chế độ: tệp ở `private`, và tệp CHỈ còn trên kho giả sau lượt đẩy thật của
`PushDocumentFileToRemote` (dataset `RemoteDocuments::MODES`). "Giải nén gói bàn giao" được chứng minh
lại với media trên kho (`BuildHandoverPackageFromRemoteTest`). Danh sách theo tên của bốn phần "Tải
tệp", "Tài liệu nội bộ", "Cách ly dữ liệu giữa khách hàng", "Bàn giao và lưu trữ": `docs/PROGRESS.md`,
"Ghi chú M14". Mục tiêu độ phủ áp thêm cho `app/Actions/Storage/`, `app/Support/Storage/` và hai policy
mới (`DriveObjectPolicy`, `DriveFolderPolicy`); số đo cùng chỗ.

---

## 12. Dữ liệu mẫu

Seeder phải tạo được một môi trường demo dùng thật được ngay:

- 1 admin, 1 manager, 3 luật sư, 2 trợ lý, 1 kế toán.
- 12 `matter_types` với bộ giai đoạn đầy đủ cho ít nhất 3 loại.
- Checklist template 12 đầu mục cho tranh chấp đất đai, và 11 template khác (mỗi loại còn lại một).
- 12 khách hàng, mỗi khách 1–2 tài khoản portal.
- 20 vụ việc rải đều các giai đoạn, trong đó cố ý tạo: 3 vụ quá hạn cập nhật
  trên 14 ngày, 2 vụ có mốc thời hạn trong 3 ngày tới, 4 vụ đang thiếu giấy tờ,
  5 vụ có tài liệu chờ duyệt.
- Mỗi vụ có 3–8 dòng `stage_logs`, có cả dòng nội bộ lẫn dòng đã công bố.
- Mỗi vụ có 2–4 bản ghi `matter_parties`, và **cố ý cài sẵn một tình huống xung
  đột lợi ích mức đỏ** giữa hai vụ việc, để demo được chức năng ở mục 6.10 mà
  không phải tự nhập tay.
- Vài vụ đã có `stage_log_views`, vài vụ cố ý để trống để widget "Khách chưa
  xem" có dữ liệu.
- Tệp mẫu dùng PDF giả sinh bằng code, không commit tệp thật vào repo.

Tài khoản demo ghi rõ trong `README.md`.

**Đính chính 2026-09-30 (M9 Task 1).** Bản đầu ghi "6 `matter_types`" và "2 template
khác". Văn phòng hành nghề **mười hai** lĩnh vực (theo luatvukhang.com), và biểu đồ cơ
cấu vụ việc theo lĩnh vực của trang doanh thu chạy trên mọi loại đang hoạt động: một bộ
seed chỉ có sáu loại kể cho chủ văn phòng một câu chuyện sai. Nay có 12 loại — bốn loại
cũ đổi **tên** (`DD` "Đất đai và bất động sản", `DN` "Đầu tư và doanh nghiệp", `DS`
"Giải quyết tranh chấp", `LD` "Lao động và nhân sự"; `code` không đổi vì `matters.code`
nhúng mã loại), sáu loại mới `HC` hành chính và giấy phép, `TM` hợp đồng và thương mại,
`NH` ngân hàng và tín dụng, `SH` sở hữu trí tuệ và công nghệ, `TC` thuế và tài chính,
`XD` xây dựng và hạ tầng. Sáu loại mới dùng **bộ năm giai đoạn TẠM chung** (tiếp nhận,
thu thập hồ sơ, soạn hồ sơ, đang thực hiện, kết thúc), ghi rõ "TẠM" ở `description`,
và trong dữ liệu mẫu không vụ nào thuộc loại mới được công bố ra cổng (`matters.is_published_to_portal`),
cho tới khi chủ văn phòng mô tả quy trình thật. Mỗi loại có ít nhất một danh mục hồ sơ mẫu tối thiểu (giấy tờ
tuỳ thân, tài liệu của vụ, hợp đồng dịch vụ), nên "2 template khác" thành 11.
Seeder tham chiếu vẫn **chỉ thêm**; danh mục mẫu chỉ được seed cho loại CHƯA có danh mục
mẫu nào (kể cả đã xoá), nên máy chủ mà văn phòng đã tự soạn danh mục cho một loại thì giữ
nguyên danh mục đó. Máy chủ đã có dữ liệu đổi tên bốn loại cũ bằng một migration dữ liệu
chỉ đổi khi tên hiện tại đúng bằng tên seed cũ.

**Bổ sung 2026-10-03 (M9 Task 13) — tiền mẫu.** `BillingSeeder`, gọi sau `MatterSeeder` trong
`DemoDataSeeder` (chỉ dữ liệu mẫu, không bao giờ `ReferenceDataSeeder`), dựng hợp đồng, lịch thu và
khoản thu qua đúng các Action tiền, đủ để mọi màn hình tiền có dữ liệu thật: mọi vụ đã rời giai đoạn
đầu có hợp đồng đang hiệu lực, một vụ ở "Tiếp nhận" có bản nháp, một vụ của danh sách cố ý không có
hợp đồng (vụ mở từ tiếp nhận của M10 cũng chưa có — xem đính chính M10 dưới); giá trị 15–450
triệu đồng, thuế 8%, 10% hoặc không có; lịch 30% khi ký / 40% khi nộp đơn / 30% khi xét xử sơ
thẩm trên ít nhất bốn hợp đồng; đợt quá hạn theo ngày và theo giai đoạn; thu một phần; một lần
miễn có lý do; một khoản thu đã huỷ kèm lý do; một phụ lục; vụ đã kết thúc còn nợ; vụ `restricted`
có hợp đồng; một vụ bàn giao có khoản thu trước và sau; khoản thu rải trên ít nhất tám tháng; tổng
các đợt khớp giá trị hợp đồng tới từng đồng (`billing:check-invariants` sạch). Để có tám tháng,
vụ mẫu thứ i mở `30 + 12·i` ngày trước (vụ cũ nhất khoảng chín tháng). *(Sửa 2026-10-04, việc sau
gộp M9 + M10: bản đầu ghi `BillingSeeder` "gọi cuối `DemoDataSeeder`" và "một vụ cố ý không có hợp
đồng" — từ khi gộp M10, `IntakeSeeder` chạy sau nó và thêm một vụ chưa có hợp đồng, nay là vụ thứ 27:
đính chính 2026-10-09 cuối mục này.)*

**Đính chính 2026-10-03 (M10 Task 8 — tiếp nhận).** Thêm dữ liệu mẫu tiếp nhận (`IntakeSeeder`, gọi
cuối `DemoDataSeeder`, nên không bao giờ chạy production qua `DatabaseSeeder`): 12 lần có người liên
hệ, mỗi lần đi qua đúng các Action của mã sản phẩm, ở thời điểm "thật" của từng bước — bản ghi ở
**mọi** trạng thái của `IntakeStatus`, **một cặp tiếp nhận đối nhau** (lần gọi sau ra Vàng vì lần gọi
trước, nguồn dò thứ hai của §6.10), **một bản Đỏ** chờ trưởng phòng (bên đối lập là khách hiện hữu)
và một bản đã bị từ chối vì xung đột, **một bản quá hạn phản hồi** lần đầu, **một bản đã ẩn danh** vì
quá hạn lưu, và một bản đã chuyển thành vụ việc. Bản chuyển đổi gắn người liên hệ (một khách hiện hữu
gọi về việc mới) vào hồ sơ khách ĐÃ CÓ, nên không thêm khách hàng nào, nhưng thêm **một vụ việc**
(vụ cuối cùng của dữ liệu mẫu — nay là vụ thứ 27, đính chính 2026-10-09 cuối mục này): một vụ vừa mở
qua `OpenMatter`, có lead
trong đội ngũ và 2 bên, **chưa có dòng `stage_logs` nào** — luật "3–8 dòng" ở trên là của các vụ
`MatterSeeder` dựng, không phải của vụ này. *(Gộp M10 vào `main`, 2026-10-04: `IntakeSeeder` chạy SAU
`BillingSeeder` của M9 Task 13 ở trên, nên vụ đó còn ở giai đoạn đầu và chưa có hợp đồng — ngoài
vụ "cố ý không có hợp đồng" của danh sách tiền — để form "Soạn hợp đồng" của nó hiện phí đã báo lúc
tiếp nhận làm gợi ý.)*

**Đính chính 2026-10-09 (nghiệm thu bản 1.0, làn v1, rà soát cuối vòng sửa 1, I3) — số thứ tự của vụ
chuyển từ tiếp nhận.** Hai chỗ ở trên từng gọi vụ đó bằng số thứ tự của lúc gộp M10 (20 vụ của danh
sách, vụ `restricted` của M2, vụ đã kết thúc của M7 Task 3, rồi nó). Từ khi gộp M13,
`TeamPerformanceSeeder` (gọi trước `IntakeSeeder` trong `DemoDataSeeder`) thêm bốn vụ, nên
`migrate:fresh --seed` dựng **27 vụ** và vụ chuyển từ tiếp nhận là **vụ thứ 27** — vẫn là vụ có id lớn
nhất. Con số đo bằng cách gieo đúng `DatabaseSeeder` trong
`tests/Feature/Acceptance/InstallGuideColdReadTest.php` (test "promises the demo data the seeder really
builds"), cùng con số `README.md` ghi; test đó cũng giữ cho mục này không còn số thứ tự cũ.

---

## 13. Milestone

Làm đúng thứ tự. Kết thúc mỗi milestone: test xanh, chạy Pint, cập nhật
`docs/PROGRESS.md`, **dừng lại báo cáo**.

| | Nội dung | Xong khi |
|---|---|---|
| **M0** | Khởi tạo Laravel 13 + Filament v5, hai panel, hai guard, cấu hình `.env.example`, Pint, Pest | `php artisan test` chạy được, hai panel mở được với tài khoản seed |
| **M1** | Toàn bộ migration, model, enum, quan hệ, factory, seeder | Seeder chạy sạch, dữ liệu mẫu đầy đủ theo mục 12 |
| **M2** | Phân quyền: spatie roles, toàn bộ Policy, global scope cho guard `client` | Toàn bộ test ở mục 11 phần "cách ly dữ liệu" và "quyền nội bộ" xanh |
| **M3** | Panel admin: resource Client, MatterType, Matter (tab Tổng quan + Tiến độ + Các bên), Action `TransitionMatterStage`, và **kiểm tra xung đột lợi ích** `RunConflictCheck` | Tạo được vụ việc và chuyển giai đoạn end-to-end; test phần "xung đột lợi ích" xanh |
| **M4** | Danh mục hồ sơ + tài liệu: checklist, upload, review, `PublishDocument`, lưu trữ private, route tải có ký | Test phần "tài liệu nội bộ" và "tải tệp" xanh |
| **M5** | Panel portal: đăng nhập OTP, danh sách hồ sơ, chi tiết hồ sơ, nộp tài liệu, gửi yêu cầu, ghi nhận `stage_log_views` | Khách demo đăng nhập và đi hết luồng được trên điện thoại |
| **M6** | Thông báo và tác vụ định kỳ: email templates, các job, SLA 14 ngày, nhắc hạn, nhắc khách chưa xem, heartbeat | Chạy `schedule:test` sinh đúng email vào log |
| **M7** | Bàn giao và lưu trữ: `ReassignMatter`, `GenerateHandoverPackage`, `ExpireClientAccess`, nhật ký liên lạc, tìm kiếm | Test phần "bàn giao và lưu trữ" xanh, giải nén gói bàn giao kiểm tra được |
| **M8** | Bảo mật và hoàn thiện: header, rate limit, activity log, backup, kiểm tra toàn bộ mục 10, viết `README.md` và hướng dẫn triển khai | Toàn bộ test xanh, checklist mục 10 tick hết |
| **M9** | Hợp đồng dịch vụ pháp lý và thu phí theo đợt: bốn quyền tiền (§5 bổ sung M9), hợp đồng một giá trị chia đợt (theo ngày, khi ký, theo giai đoạn), khoản thu, miễn, phụ lục, nhắc nội bộ đợt quá hạn, tab tiền trên trang vụ việc, trang "Công nợ", trang doanh thu, khối hợp đồng và lịch thu trên cổng khách, khung `time_entries` | Bất biến "tổng các đợt = giá trị hợp đồng" giữ ở mọi đường ghi; test phân quyền tiền và cách ly cổng khách xanh; `billing:check-invariants` sạch trên dữ liệu mẫu |
| **M12** | App trên điện thoại (PWA) và thông báo đẩy: manifest, biểu tượng và service worker cho hai app (`/admin`, `/portal`) không lưu gì riêng tư, trang ngoại tuyến, tải tài liệu trong cửa sổ app, đăng ký thiết bị theo từng máy (trang "Thông báo trên điện thoại"), gỡ máy khi đăng xuất hay cắt phiên, `PushTopic` đi cùng thư với cùng người nhận, hàng đợi `push` rút bằng cron, nhật ký `outbound_messages` kênh `push`, `vkcrm:push-reset` | Toàn bộ test xanh trên SQLite và MariaDB; lượt Playwright cho thấy CacheStorage không giữ trang, JSON hay tệp hồ sơ nào; danh sách kiểm tra trên iPhone và Android thật (`docs/research/2026-10-01-pwa-kiem-tra-may-that.md`) do chủ văn phòng chạy |
| **M14** | Google Drive làm kho tài liệu phía sau CRM: adapter Drive REST v3 cộng `google/auth`, chỉ mục `drive_objects`, ghi qua vùng đệm `private` rồi đẩy trên hàng đợi `storage`, bật kho có mốc (`vkcrm:storage:enable`) và cổng pháp lý chuyển dữ liệu ra nước ngoài, chuyển tệp cũ, kiểm, quay lui, dựng lại chỉ mục, tải về luôn qua CRM, gói bàn giao dựng từ bản tải về, kiểm sẵn sàng và sức khoẻ kho mỗi giờ, trang "Kho tài liệu", bản thứ hai mã hoá ở máy chủ văn phòng và dọn vùng đệm theo biên nhận, danh sách huỷ tệp | Toàn bộ test xanh trên SQLite và MariaDB với Drive giả (`Http::fake()`); test SPEC §11 chạy lại với media trên kho; nghiệm thu trên Shared Drive thử do chủ văn phòng mở (Phụ lục A); production giữ `DOCUMENT_STORAGE=local` tới khi chủ văn phòng bật |

**Đính chính 2026-09-24 (M9).** Thêm dòng **M9** ở bảng trên. Thứ tự dựng hiện hành
không phải thứ tự dòng trong bảng: xem `docs/PROGRESS.md` (M9 chạy sau M11, trên
cơ sở dữ liệu production đã có dữ liệu thật).

**Đính chính 2026-09-24 (M10).** Thêm dòng **M10** cho bảng trên (viết ở đây, không sửa
dòng cũ của bảng). Thứ tự dựng hiện hành: M6.5 → phần còn lại của M6 → M7 → M8 → M11 → M9 →
**M10** → M12 (xem `docs/PROGRESS.md`).

| | Nội dung | Xong khi |
|---|---|---|
| **M10** | Tiếp nhận và thẩm định đầu vào: bản ghi tiếp nhận `intake_requests` và bên đối lập `intake_parties`; ba quyền `intake.*` (§5 bổ sung M10); kiểm tra xung đột lợi ích ở lần chạm đầu tiên, trước khi nghe câu chuyện (§6.10 đính chính M10); dò trùng lúc nhập; đo thời gian phản hồi lần đầu và nhắc quá ngưỡng (§7.1, §9 đính chính M10); chuyển thành vụ việc không gõ lại; từ chối kèm lý do; hạn lưu, ẩn danh và xoá theo yêu cầu cho người chưa thành khách; báo cáo đầu vào | Mọi lần liên hệ để lại một bản ghi; Đỏ khoá ô câu chuyện; chuyển đổi đi qua `FindClientByIdentifier`/`CreateClient`/`OpenMatter`, không tạo `Client` trùng; câu chuyện của người chưa thành khách không bao giờ qua MCP; test phân quyền và cách ly cổng khách xanh |

**Đính chính 2026-10-04 (M12 Task 10).** Thêm dòng **M12** ở bảng trên. Kế hoạch:
`docs/superpowers/plans/2026-09-24-m12-pwa.md`; phán quyết R1–R14 và kết quả nghiệm thu ở
`docs/PROGRESS.md`, "Ghi chú M12". Nhánh M12 cắt từ `main` trước khi M10 và M11 gộp (phán quyết 1 của
làn); trước khi giao, nhánh gộp lại `main` (M7, M9, M10) và nối thêm thông báo đẩy cho thư
`staff.handover_ready` của M7. Hai thư nhân sự khác của `main` không có thông báo đẩy:
`staff.matter_reassigned` (M7) và `staff.intake_unanswered` (M10) — §9, đính chính M12. M11 chưa gộp
lúc giao M12.

**Đính chính 2026-10-04 (M13).** Thêm dòng **M13** (chủ văn phòng yêu cầu ngày 2026-10-04, làm
song song với M11, M12):

| | Nội dung | Xong khi |
|---|---|---|
| **M13** | Theo dõi đội ngũ và hiệu suất: quyền `performance.viewAny` (§5 bổ sung M13), danh sách người được theo dõi, trang "Theo dõi đội ngũ", trang của một người, trang "Hiệu suất theo kỳ" (§7.5), các con số §6.14 với một định nghĩa cho mỗi số, lịch sử người giữ mốc và người giữ yêu cầu đầy đủ, ảnh chụp số liệu hằng ngày cho xu hướng | Test phần "Theo dõi đội ngũ" ở §11 xanh; mỗi cột "bây giờ" bằng đúng widget trang chủ tương ứng cho cùng người xem; kỳ đã đóng không đổi sau khi làm nốt việc tồn hoặc bàn giao; ngân sách thời gian trang đo trên MariaDB thật |

**Đính chính 2026-10-04 (M14 Task 8).** Thêm dòng **M14** ở bảng milestone đầu mục (sau dòng M12). Kế hoạch:
`docs/superpowers/plans/2026-10-04-m14-google-drive-storage.md`; phán quyết R1–R15, phán quyết
controller C1–C7 và kết quả nghiệm thu ở `docs/PROGRESS.md`, "Ghi chú M14"; hướng dẫn cho chủ văn phòng
và người cài đặt ở `docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`. Nghiệm thu trên Shared Drive thật (Task 8 Phần
2) chưa chạy vì văn phòng chưa có Workspace, tài khoản dịch vụ hay Shared Drive thử: "M14 — xong phần
mã, chờ chủ văn phòng".

---

## 14. Tiêu chí nghiệm thu

Hệ thống được coi là hoàn thành khi:

1. Toàn bộ test ở mục 11 xanh, độ phủ `app/Actions/` và `app/Policies/` ≥ 80%.
2. Chạy được trên PHP 8.3 với đúng một dòng cron, không cần Redis hay
   supervisor.
3. Luật sư chuyển giai đoạn một lần thì khách nhận được email và thấy cập nhật
   trên portal, không cần thao tác nào thêm.
4. Khách hàng trên điện thoại đăng nhập, xem được tiến độ, thấy còn thiếu giấy
   tờ gì, nộp được ảnh chụp, và nhận được phản hồi khi bị từ chối.
5. Không có cách nào, kể cả sửa tham số URL, để một khách hàng nhìn thấy dữ liệu
   của khách hàng khác hoặc nhìn thấy tài liệu nhóm D.
6. Mở một vụ việc có bên đối lập trùng với khách hàng hiện hữu thì bị chặn, và
   mọi lần kiểm tra xung đột đều để lại dấu vết trong nhật ký hệ thống.
7. Kết thúc một vụ việc sinh được gói bàn giao đầy đủ kèm mục lục và bản tường
   trình tiến độ, không lẫn tài liệu nội bộ.
8. `README.md` hướng dẫn được người không tham gia phát triển tự cài lại hệ
   thống từ đầu trên một máy chủ trống.

---

## 15. Ghi chú cho giai đoạn sau

Thiết kế bản 1.0 phải để chỗ cho các phần sau mà không phải sửa lại mô hình dữ
liệu: hợp đồng dịch vụ và đợt thanh toán (bảng `contracts`, `instalments` gắn
vào `matters`), ghi nhận thời gian làm việc tính phí theo giờ (bảng
`time_entries` gắn vào `matters` và `users`), tích hợp Zalo ZNS (thêm channel
vào `outbound_messages`), nhận lead từ form website qua API (bảng `leads`), ký
số tài liệu, và ứng dụng di động (API đã sẵn sàng vì logic nằm trong Action chứ
không nằm trong controller).

Riêng `time_entries` tuy chưa làm ở bản 1.0 nhưng nên tạo sẵn quan hệ trong
model `Matter`, vì khi văn phòng chuyển sang tính phí theo giờ thì đây là thứ
khó gắn thêm sau nhất.

**Đính chính 2026-09-24 (M9).** Hợp đồng dịch vụ và đợt thanh toán **đã làm** ở
M9: bảng `contracts` và `instalments` gắn vào `matters` như trên, cùng `payments`
(khoản thu) và `contract_amendments` (phụ lục). `time_entries` M9 chỉ dựng ở
**dạng khung** — bảng, model, quan hệ với `matters` và `users`, policy đóng kín —
không Action, không màn hình, không con số nào đọc bảng này; tính phí theo giờ
vẫn là việc của giai đoạn sau.

**Đính chính 2026-09-24 (M10).** Dòng "nhận lead từ form website qua API (bảng
`leads`)" ở trên: bảng dành cho lời hứa đó đã được dựng ở M10 với tên
**`intake_requests`** (kèm bảng con `intake_parties`), không phải `leads` — vì một lần có
người liên hệ qua điện thoại, Zalo hay gặp trực tiếp cũng cần một bản ghi, không riêng
lead từ website (cột `source`, giá trị `website_form`). **API nhận lead vẫn để sau**: M10
chỉ có màn hình nhập tay trong `/admin`, không có đường công khai nào. Form trên
luatvukhang.com gửi thẳng vào hệ thống là một milestone riêng sau M10, chưa đánh số, và
khi làm phải có route `POST` công khai riêng ngoài cả hai panel, honeypot, rate limit
60 request/phút (§10.3), ô đồng ý xử lý dữ liệu **không đánh dấu sẵn**, và nội dung người
gửi tự gõ được lưu vào vùng khoá, chỉ mở theo đúng §6.10 đính chính M10.

**Đính chính 2026-10-04 (M12 Task 10).** "Ứng dụng di động" ở đoạn đầu mục này: bản 1.0 đã có app
trên điện thoại dạng PWA (M12) — cài từ trình duyệt vào màn hình chính, mở trong cửa sổ riêng, nhận
thông báo đẩy trên Android và iPhone (iOS 16.4 trở lên), chung một nguồn dữ liệu với website;
ứng dụng không lưu sẵn hồ sơ trên điện thoại (chỉ tài liệu người dùng chủ động tải về nằm lại trong
thư mục tải xuống của máy, đăng xuất không xoá). App gốc trên App Store/Google Play vẫn là việc của giai đoạn sau. Khi nào mới
đáng làm (đo được sau vài tháng vận hành) và làm thế nào để nó không thành hệ thống thứ hai (OAuth
2.1 trên máy chủ Passport của M11, một lớp API mỏng trên đúng các Action, cùng `PushTopic` và cùng
luật người nhận) ghi ở mục cuối của kế hoạch M12: `docs/superpowers/plans/2026-09-24-m12-pwa.md`,
"Về sau: khi nào mới đáng làm app gốc".

**Đính chính 2026-10-04 (M13).** Phần **"năng suất"** của báo cáo quản trị (mục 7, "Báo cáo quản trị
nâng cao", của danh sách nâng cấp giai đoạn 2 trong `docs/PROGRESS.md`) đã làm ở M13: theo dõi đội
ngũ và hiệu suất theo kỳ (§6.14, §7.5). Hai phần còn lại vẫn để sau, có chủ đích (R15): **đo giờ làm**
(M13 không đọc `time_entries`, vẫn là khung của M9) và **tỉ lệ thắng kiện** (không có cột kết quả vụ
việc); "thời gian xử lý theo loại vụ" cũng chưa làm.

---

## 16. Máy chủ MCP cho nhân sự (M11)

*Thêm 2026-10-08 (M11 Task 17).* Nhân sự văn phòng dùng tài khoản AI của chính mình (Claude, ChatGPT,
Claude Code và các client MCP khác) để **hỏi** hồ sơ và **soạn nháp** việc hằng ngày, qua một cổng MCP
duy nhất theo chuẩn. Quyết định của chủ văn phòng ngày 2026-09-24; mười bốn phán quyết R1–R14 dưới đây
là luật cài được của quyết định đó. Nguồn đầy đủ (kèm trích dẫn tra cứu `[DC:n]`, `[PL:n]`): kế hoạch
`docs/superpowers/plans/2026-09-24-m11-mcp.md`; cách cài, khoảng lệch và kiểm chứng của từng task:
`docs/PROGRESS.md`, "Ghi chú M11"; chính sách cho nhân sự: `docs/CHINH-SACH-AI.md`; hướng dẫn kết
nối: `docs/KET-NOI-AI.md`. Phần pháp lý là thông tin tham khảo, không phải tư vấn pháp lý; chủ văn
phòng là luật sư và ký duyệt.

Bốn bất biến: AI không bao giờ thấy nhiều hơn người đó thấy trên web; có những loại dữ liệu không
bao giờ rời hệ thống, kể cả với người được xem trên web; không gì tới tay khách nếu không có một
người bấm nút trong `/admin`; mọi lần dùng một tool để lại dấu vết, và admin cắt được kết nối của một người
ngay lập tức. **Công tắc `mcp.enabled` để TẮT trên production** cho tới khi chủ văn phòng xử lý danh
sách ở §16.8.

### 16.1 Cổng và xác thực (R1, R7)

- **Một endpoint** Streamable HTTP: `POST <tên miền quản trị>/mcp` (`GET`/`DELETE` trả 405). Không
  SSE, không phiên, chạy trên PHP-FPM. URL chuẩn có một nguồn (`App\Support\Mcp\McpEndpoint`): gốc là
  `ADMIN_DOMAIN` khi có, không thì host của `APP_URL`. Server phục vụ cả client kiểu `initialize`
  (2025-11-25, 2025-06-18) lẫn client không trạng thái 2026-07-28.
- **Mười bước trước mỗi `POST /mcp`**, thứ tự ghim bằng test (`routes/ai.php`): Origin ngoài allowlist
  → 403 (không có Origin thì cho qua); chặn bearer đã sai quá 30 lần/phút theo IP và dấu băm bearer;
  chỉ header `Authorization: Bearer` (không query string, không cookie); token Passport hợp lệ của một
  `users.id` (guard `mcp`); `aud` chứa URL MCP; client mang cờ `oauth_clients.is_mcp`; scope
  `mcp:use`; nhật ký; giới hạn; quyền của người (§16.2). Chưa có token hợp lệ thì 401 cho MỌI method,
  kể cả `initialize` và `tools/list`, kèm `WWW-Authenticate: Bearer resource_metadata="…"`.
- **Máy chủ uỷ quyền OAuth 2.1 trong app** (Passport 13.8), chỉ cho bảng `users`, không bao giờ
  `client_users`. Chỉ hai grant: `authorization_code` (PKCE S256 bắt buộc với mọi client) và
  `refresh_token`. Không password grant, không personal access token, không `client_credentials`,
  không API key dùng chung, không `static_headers`.
- **Metadata:** PRM (RFC 9728) ở `/.well-known/oauth-protected-resource` và `…/mcp`; AS metadata tự
  khai (RFC 8414) với `code_challenge_methods_supported: ["S256"]`,
  `token_endpoint_auth_methods_supported: ["none"]`, `scopes_supported: ["mcp:use"]`,
  `registration_endpoint`, `authorization_response_iss_parameter_supported: true` (mọi phản hồi uỷ
  quyền mang `iss`, RFC 9207). `resource` (RFC 8707) khác URL MCP → `invalid_target`. Access token mang
  `aud = [id client, URL MCP]`. Máy chủ web không được chặn `/.well-known/` (`docs/CAI-DAT.md`).
- **Đăng ký client:** DCR (`POST /oauth/register`) là đường chạy hôm nay. Redirect URI so khớp
  **chính xác** với allowlist theo nền tảng (`config('vkcrm.mcp.redirect_uris')`; thêm bằng `.env`
  `MCP_EXTRA_REDIRECT_URIS`, không ký tự đại diện cho host); loopback `localhost`/`127.0.0.1` bỏ qua
  cổng (RFC 8252). Client DCR quá 30 ngày tuổi không còn access token nào được dọn theo lịch. CIMD có mã
  nhưng cờ `MCP_CLIENT_ID_METADATA_DOCUMENTS` để tắt cho tới khi thử thật trên staging (§16.9).
- **Token:** access token 1 giờ; refresh token 30 ngày, xoay vòng, mã cũ dùng lại → `invalid_grant`.
  `passport:purge` chạy theo lịch. Khoá Passport cất cùng `APP_KEY` (`docs/CAI-DAT.md`,
  `docs/SAO-LUU-KHOI-PHUC.md`); `vkcrm:preflight` đỏ khi thiếu khoá, khoá riêng mở cho người khác, hai
  khoá không cùng cặp, access token sống quá 1 giờ hay `redirect_domains` còn `*`.
- **Đăng nhập và đồng ý:** `/oauth/authorize` đi qua đăng nhập `/admin` và 2FA bắt buộc của M8 rồi một
  màn hình đồng ý hiện **host của redirect URI**, không hiện tên client tự khai. Màn hình chịu cùng
  giới hạn IP với `/admin`, và từ chối khi người đó chưa đủ điều kiện ở §16.2. Client đăng ký bằng
  CIMD không bao giờ được Passport tự duyệt (luôn hiện màn hình đồng ý).

### 16.2 Ai dùng được (R2, R12 mục 1)

- Chỉ nhân sự, mỗi token gắn đúng một `users.id`. Công tắc theo người `users.ai_access` (§4.1, đính
  chính 2026-10-08): `off` (mặc định), `read`, `read_write`; chỉ người có `settings.manage` đổi, trên
  trang "Kết nối AI"; kế toán (thiếu `matter.view`) không bật được. Không thêm quyền spatie nào.
- Hai công tắc toàn hệ thống trong bảng `settings`: `mcp.enabled` và `mcp.write_enabled`, mặc định tắt.
- Kiểm ở **mỗi request**, không ở lúc cấp token: tài khoản đang hoạt động, `ai_access` khác `off` và
  còn `matter.view`, `mcp.enabled` bật, cam kết chính sách đúng phiên bản hiện hành
  (`config('vkcrm.mcp.policy_version')`), client mang cờ `mcp`, scope `mcp:use`. Thiếu một điều là bị
  từ chối ngay ở request kế tiếp.
- **Cam kết của nhân sự** trước lần kết nối đầu: trang "Kết nối AI của tôi" hiện chính sách và một ô
  tích không đánh dấu sẵn; lưu `ai_acknowledgements` (người, phiên bản, thời điểm, IP, user agent).
  Đổi phiên bản chính sách thì mọi kết nối ngừng ở request kế tiếp cho tới khi người đó cam kết lại.
  Đây là cam kết của nhân sự, **không** thay đồng ý của khách (R9).

### 16.3 MCP thấy gì (R3, R9, R10, R11)

- **Kế thừa policy của web rồi chỉ thu hẹp.** Mọi tool hỏi `Gate::forUser($user)` với đúng ability mà
  màn hình web tương ứng hỏi. Tập vụ việc MCP thấy là giao của: `Matter::listableBy($user)` và `view`;
  `confidentiality = normal` — **vụ `restricted` không bao giờ lên MCP, kể cả với luật sư phụ trách và
  admin**; `matters.ai_access = allowed`; chưa xoá mềm. Một định nghĩa duy nhất:
  `App\Actions\Mcp\McpMatterScope`.
- Không tìm thấy, không có quyền, vụ hạn chế, vụ `denied`, dữ liệu R4: cùng **một** thông điệp "Không
  tìm thấy", cùng hình dạng; không đếm, không gợi ý "có kết quả bị ẩn". Số đếm trong `whoami` và trong
  phân trang tính trên tập đã thu hẹp (§10.10).
- **Cờ cấp vụ việc** `matters.ai_access` (§4.6, đính chính 2026-10-08), mặc định `denied`.
- **Tên các bên không phải khách của văn phòng:** mặc định (`MCP_PARTY_NAMES=pseudonym`) trả vai và số
  thứ tự ("Bị đơn 1"); `full` trả tên thật — chủ văn phòng quyết. Không bao giờ trả số điện thoại, địa
  chỉ hay ghi chú của các bên. Giả danh **không phải** khử nhận dạng: tiêu đề vụ việc thường chứa tên
  đương sự và mã hồ sơ dẫn ngược về người thật.
- **Nội dung do khách viết là dữ liệu, không phải chỉ dẫn:** tiêu đề và nội dung yêu cầu, trả lời của
  khách, tiêu đề tài liệu nhóm A trả trong trường `untrusted_client_content` qua
  `App\Support\Mcp\UntrustedText` (bỏ ký tự vô hình và điều khiển hướng chữ, HTML, ảnh, link và mọi
  URL; cắt độ dài kèm `truncated`). Lọc chỉ là best-effort; ranh giới thật là §16.6: không có đường ra
  ngoài.

### 16.4 Không bao giờ qua MCP (R4)

Cài bằng presenter theo danh sách trường được phép (`App\Support\Mcp\Presenters`), không bằng danh
sách chặn; không bao giờ serialize một model ra MCP (hai lớp bảo vệ của cổng khách không có mặt ở
ngữ cảnh MCP).

| Loại | Cột, bảng | Qua MCP |
|---|---|---|
| Số CCCD, MST | `clients.id_number`, `matter_parties.id_number_hash` | Không bao giờ, kể cả hash |
| Số điện thoại | `clients.phone`, `matter_parties.phone_normalized` | Của khách chỉ dạng che `***456`; của các bên thì không |
| Ghi chú nội bộ | `stage_logs.internal_note`, `matters.description_internal`, `clients.note`, `matter_parties.note` | Không đọc; chỉ cờ `has_internal_note`. Vẫn ghi được vào nháp |
| Tài liệu nhóm D | `documents.group = D` | Không liệt kê, không đếm, không lấy |
| Kết quả kiểm tra xung đột | nhật ký `conflict_*`, `RunConflictCheck` | Không có tool |
| Nội dung tệp, đường tải | medialibrary, `documents.download` | Chỉ metadata, không URL ký |
| Nhật ký thư | `outbound_messages` | Không có tool |
| Nhật ký hệ thống | `activity_log` | Không có tool |
| Tiền của vụ việc (M9) | `contracts`, `instalments`, `payments`, `contract_amendments`, `time_entries` | Không bao giờ, kể cả với người thấy tiền trên web |
| Tiếp nhận (M10) | `intake_requests`, `intake_parties` | Không bao giờ |
| Đăng ký thông báo đẩy (M12) | `push_subscriptions` (endpoint, khoá) | Không bao giờ |
| Số liệu đội ngũ, hiệu suất (M13) | `performance_snapshots`, `App\Actions\Performance`, `App\Support\Performance` | Không bao giờ; không tool (M13 R13) |
| Kho tài liệu (M14) | đĩa `documents_remote`, `drive_objects`, `drive_folders`, bốn cột kho của `media`, `office_receipt_imports` | Không bao giờ: mã MCP không chạm đĩa, kho, luồng tệp hay medialibrary — không mã tệp Drive, không URL Drive, không nội dung tệp |

Năm phép quét canh bảng này: `tests/Feature/Mcp/SensitiveDataSweepTest.php` (gọi mọi tool với mọi
tham số trên bộ dữ liệu có kim ở mọi ô trên, tìm trong thân HTTP thô),
`tests/Feature/Mcp/MoneyMcpBoundaryTest.php`, `tests/Feature/Intake/IntakeMcpBoundaryTest.php`,
`tests/Feature/Performance/PerformanceMcpBoundaryTest.php` và
`tests/Feature/Storage/StorageMcpBoundaryTest.php` (không tệp MCP nào tham chiếu model tiền, tiếp
nhận, số liệu đội ngũ, hay đĩa, kho Drive và medialibrary; phép quét tiền bắt mọi tên chứa một từ
tiền ở bất kỳ vị trí nào — lớp, không gian tên `Billing`, quan hệ như `triggeredInstalments` — xem
§5).

### 16.5 Bộ tool (R13, R14)

Mười lăm tool, thứ tự cố định: đọc — `whoami`, `search`, `fetch` (hợp đồng tìm/lấy của ChatGPT),
`search_matters`, `get_matter`, `list_matter_updates`, `list_deadlines`, `get_checklist`,
`list_documents`, `list_client_requests`, `get_client_request`; ghi — `draft_progress_update`,
`draft_request_reply`, `create_deadline`, `log_communication`.

- Id có tiền tố (`matter_123`, `request_7`…); mỗi kết quả kèm `url` tuyệt đối về trang `/admin` (trang
  tự kiểm quyền). `inputSchema` đóng (`additionalProperties: false`), `maxLength` bằng độ dài cột; phân
  trang `limit` mặc định 10, tối đa 25, `cursor`; không tool "xuất tất cả".
- Mô tả tool tiếng Việt theo mẫu "Dùng khi… / Không dùng để…"; `instructions` của server tiếng Việt.
- `tools/list` đổi theo quyền: người `read` (hay khi `mcp.write_enabled` tắt) không thấy bốn tool ghi;
  handler của tool ghi vẫn kiểm lại ở mỗi lần gọi (client cache danh sách tool).
- Annotation khai đủ và trung thực: tool đọc `readOnlyHint: true, destructiveHint: false,
  idempotentHint: true, openWorldHint: false`; tool ghi `readOnlyHint: false, destructiveHint: false,
  idempotentHint: true, openWorldHint: false`; `title` ở cả `Tool.title` lẫn `annotations.title`.
- Không làm ở M11: tìm/lấy khách hàng riêng, đọc nội dung tệp, kiểm tra xung đột, chuyển giai đoạn,
  hoàn thành mốc, thao tác hàng loạt, MCP Apps, elicitation, scope `crm.read`/`crm.write`, allowlist IP,
  nộp lên thư mục plugin của Claude hay ChatGPT.

### 16.6 Ghi (R5, R6)

- **Không tool xoá, sửa, gửi, công bố**; không chuyển giai đoạn, không hoàn thành mốc, không duyệt
  giấy tờ.
- **Nháp** (`draft_progress_update`, `draft_request_reply`): ghi vào bảng riêng `stage_log_drafts` và
  `client_request_reply_drafts` — về cấu trúc không thể tới cổng khách. Bắt buộc `idempotency_key`
  (8–64 ký tự, không phân biệt hoa thường, duy nhất theo người); gọi lại trả nháp đã có. Người mở nháp
  ở khối "Nháp từ AI" trên tab Tiến độ hay tab Yêu cầu từ khách, sửa, rồi bấm nút của luồng web đã có
  (hay "Bỏ nháp"); nhật ký `mcp_draft_used` / `mcp_draft_discarded`. Cổng ghi nháp đúng cổng của nút
  web tương ứng (`transitionStage` trên vụ; `ClientRequestReplyPolicy::create` với luồng).
- **Ghi nội bộ hai bước ở server** (`create_deadline`, `log_communication`): lần gọi đầu validate đủ,
  kiểm policy, không ghi gì, trả bản xem trước kèm `confirmation_token` — ký HMAC bằng `APP_KEY`, gắn
  người, tên tool, băm tham số chuẩn hoá, `jti`, hạn 10 phút. Lần hai cùng tham số kèm mã thì khoá vụ
  trước rồi ghi một lần qua đúng Action của web (`AddMatterDeadline`, `LogCommunication`); `jti` vào
  bảng `mcp_confirmations` (unique), gọi lại cùng mã trả đúng bản ghi đã tạo.
- Server **ép**: `is_published = false`, `is_visible_to_client = false`, `created_by` là người sở hữu
  token, `created_via = mcp` (§4.13, §4.17, đính chính 2026-10-08). Gọi bất kỳ tool ghi nào, ở mọi
  nhánh kể cả nhánh lỗi, không sinh dòng `outbound_messages`, không đổi `is_published`, không phát
  `StageLogPublished`, không gửi thư hay thông báo cho khách.
- Nói thẳng (cũng trong `docs/CHINH-SACH-AI.md`): người dùng để "Always allow" thì AI tự gọi được cả
  hai bước. Lớp an toàn thật là ba điều: ghi chỉ nội bộ, nhãn "Tạo qua AI", người sửa được trên web.

### 16.7 Nhật ký, giới hạn, thu hồi (R8)

- **Mỗi `tools/call`**, kể cả lần bị từ chối hay bị chặn, sinh đúng một dòng `mcp_tool_called`:
  `channel = mcp`, causer là người sở hữu token (truyền tường minh), client OAuth và nền tảng suy từ host
  redirect, tên tool, tham số theo allowlist (id, enum, ngày; văn bản tự do chỉ còn độ dài), id và
  **tên** các trường đã trả (không giá trị), số bản ghi, `outcome` (`ok`, `not_found`, `denied`,
  `invalid`, `rate_limited`), thời gian xử lý, correlation id, IP (với Claude và ChatGPT là IP của nền
  tảng, không của nhân sự). Không ghi prompt, nội dung, CCCD, số điện thoại.
- Cũng ghi: đồng ý / từ chối kết nối (`mcp_connection_authorized`, `mcp_connection_denied`), **làm mới
  kết nối** (`mcp_token_refreshed`, thêm ở Task 17), thu hồi (`ai_connections_revoked`), đổi chế độ
  (`ai_access_changed`), cam kết (`ai_policy_acknowledged`), đổi công tắc (`ai_settings_updated`), cờ
  của vụ (`matter_ai_access_changed`), dùng/bỏ nháp, xác nhận mốc AI. Trang "Kết nối AI" hiện 100 dòng
  gần nhất của kênh AI, lọc theo người và tool.
- Nhật ký là bằng chứng cho hồ sơ đánh giá tác động nên sống ít nhất 12 tháng: không tác vụ lịch nào
  chạy `activitylog:clean`, và con số của lệnh đó ≥ `retention_years × 365` (§10.6).
- Giới hạn: §10.3, đính chính 2026-10-08.
- **Thu hồi:** admin có "Thu hồi" từng kết nối và "Thu hồi tất cả" của một người; nhân sự tự thu hồi
  kết nối của mình. Thu hồi đánh `revoked` cả access lẫn refresh token; request kế tiếp nhận 401.
  Vô hiệu hoá tài khoản, đổi mật khẩu, "Đặt lại 2FA", hạ `ai_access` về `off` thu hồi mọi token của
  người đó trong cùng transaction.

### 16.8 Pháp lý (R12) — danh sách trước khi bật `mcp.enabled` trên dữ liệu thật

1. **Cam kết trong app** của từng nhân sự (§16.2) — đã cài.
2. **Chính sách dùng AI** `docs/CHINH-SACH-AI.md` — bản nháp, chờ chủ văn phòng duyệt.
3. **Hồ sơ đánh giá tác động chuyển dữ liệu cá nhân ra nước ngoài** (Điều 20 Luật 91; nộp A05 trong 60
   ngày kể từ lần chuyển đầu, cập nhật 6 tháng một lần). Văn phòng gọi là "Mẫu 10"; tra cứu chưa xác
   nhận số mẫu là 09 hay 10. Hệ thống không quyết việc nộp: trang "Kết nối AI" hiện dải cảnh báo cho
   tới khi admin ghi ngày đã nộp (`mcp.transfer_assessment_filed_on`), `vkcrm:preflight` báo VÀNG.
4. **Tài khoản AI cá nhân phải tắt huấn luyện** (ChatGPT "Improve the model for everyone"; Claude
   Free/Pro/Max: tuỳ chọn cho dùng hội thoại để huấn luyện). Server không kiểm được cấu hình phía
   client: đây là cam kết ở mục 1 cộng một lần kiểm định kỳ.

Cùng danh sách, ngoài phần mềm: đồng ý **bằng văn bản** của khách cho từng vụ trước khi bật cờ vụ (R9),
người phụ trách bảo vệ dữ liệu, quy trình báo sự cố 72 giờ, cân nhắc gói doanh nghiệp của nền tảng AI.

### 16.9 Khoảng lệch có chủ đích so với tra cứu

- **Một scope `mcp:use`** thay cho `crm.read`/`crm.write`: tài liệu Laravel 13 ghi scope tuỳ biến chưa
  được hỗ trợ; tách đọc/ghi bằng công tắc theo người (R2) và `mcp.write_enabled`.
- **`tools/list` đổi theo quyền** (R13), khác khuyến nghị giữ bộ tool cố định cho mọi vai trò: AI không
  nên được mời gọi tool mà người đó không được dùng.
- **`aud`: đạt theo phương án ưu tiên** (entity access token riêng thêm URL MCP vào `aud`, middleware
  kiểm), không cần phương án dự phòng.
- **CIMD: hoãn.** Mã có, cờ tắt; DCR là đường duy nhất cho tới khi thử thật với Claude và ChatGPT trên
  staging HTTPS (các bước ở `docs/PROGRESS.md`, "Ghi chú M11", Task 5 và Task 17).
- **Nghiệm thu bằng client thật** (MCP Inspector, Claude, ChatGPT developer mode, Claude Code, bộ prompt
  vàng `docs/audits/2026-10-08-m11-golden-prompts.md`) chờ staging HTTPS công khai và tài khoản AI của
  văn phòng; mọi phần còn lại đã nghiệm thu tự động qua HTTP thật trong bộ test.
