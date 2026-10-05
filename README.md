# VK-CRM — Quản lý hồ sơ vụ việc Công ty Luật Vũ Khang

Hệ thống quản lý hồ sơ vụ việc nội bộ kèm portal tra cứu cho khách hàng, chạy tại
`khachhang.luatvukhang.com`.

- Đặc tả đầy đủ: [`docs/SPEC.md`](docs/SPEC.md)
- Kiến trúc tổng thể: [`docs/Kien-truc-CRM-Luat-Vu-Khang.pdf`](docs/Kien-truc-CRM-Luat-Vu-Khang.pdf)
- Quyết định kỹ thuật: [`docs/superpowers/specs/2026-09-13-vk-crm-design.md`](docs/superpowers/specs/2026-09-13-vk-crm-design.md)
- Tiến độ theo milestone: [`docs/PROGRESS.md`](docs/PROGRESS.md)

## Công nghệ

Laravel 13 · Filament 5 (Livewire 4) · PHP 8.3 · MariaDB/MySQL · Pest 4 · Pint.
Không phụ thuộc Redis, Supervisor hay Node runtime khi chạy production (chạy được trên
shared hosting với đúng một dòng cron).

## Chạy local (Docker)

Yêu cầu: Docker Desktop và Git. Không cần cài PHP hay Composer trên máy.

```bash
cp .env.example .env
docker run --rm -v "$PWD:/var/www/html" -w /var/www/html webdevops/php:8.3-alpine composer install
bin/dev up -d
bin/dev artisan key:generate
bin/dev artisan migrate:fresh --seed
```

Trên Windows dùng Git Bash (lệnh `bin/dev` là script bash).

| Panel | URL | Tài khoản demo | Vai trò | Mật khẩu |
|---|---|---|---|---|
| Nội bộ, quản trị | http://localhost/admin | `admin@luatvukhang.com` | admin | `password` |
| Nội bộ, trưởng phòng | http://localhost/admin | `quanly@luatvukhang.com` | manager | `password` |
| Nội bộ, luật sư | http://localhost/admin | `luatsu1@luatvukhang.com`, `luatsu2@…`, `luatsu3@…` | lawyer | `password` |
| Nội bộ, trợ lý | http://localhost/admin | `troly1@luatvukhang.com`, `troly2@…` | assistant | `password` |
| Nội bộ, kế toán | http://localhost/admin | `ketoan@luatvukhang.com` | accountant | `password` |
| Khách hàng | http://localhost/portal | `khach1@example.com` … `khach12@example.com` (thêm `khach2b@`, `khach5b@`, `khach8b@`, `khach11b@`) | — | `password` |
| Mailpit | http://localhost:8025 | — | — | — |

**Panel `/admin` bắt buộc xác thực hai lớp (2FA), không tắt được.** Mọi tài khoản nhân sự demo
dùng CHUNG một secret TOTP cố định, `JBSWY3DPEHPK3PXP`: thêm nó MỘT LẦN vào một app xác thực
(Google Authenticator, Authy, 1Password… — kiểu "nhập mã thủ công") là đăng nhập được mọi tài khoản
nhân sự ở trên, qua mọi lần `migrate:fresh --seed`. Cổng khách `/portal` gửi mã sáu số qua email
sau bước mật khẩu — đọc ở Mailpit. Tài khoản demo và secret này **chỉ có trên máy dev**
(`APP_ENV=local`): seed của máy chủ thật không tạo tài khoản nào. Chi tiết: `docs/CAI-DAT.md`,
"Tài khoản dùng thử".

Dữ liệu mẫu có 20 vụ việc với các tình huống cố ý: vụ 1–3 quá hạn cập nhật, vụ 4–5 có hạn trong 3 ngày,
vụ 6–9 thiếu giấy tờ, vụ 10–14 có tài liệu chờ duyệt, vụ 20 xung đột lợi ích với khách hàng số 2.
Từ M4, seeder tạo TỆP THẬT trên đĩa `private` (qua đúng hai Action nộp tệp, không ghi thẳng), nên
nút tải về trong bản demo tải ra tệp thật chứ không 404.

Phân quyền theo SPEC §5: kế toán chỉ thấy danh sách vụ việc rút gọn và không mở được nội dung;
luật sư chỉ thấy vụ việc có tên mình trong đội ngũ; vụ việc đánh dấu hạn chế chỉ luật sư phụ trách
và quản trị thấy. Khách hàng chỉ thấy hồ sơ của chính mình, chỉ những gì đã được công bố.

## Kiểm thử và định dạng mã

```bash
bin/dev test
bin/dev pint
```

Test chạy trên SQLite in-memory nên không đụng dữ liệu dev.

## Tệp hồ sơ

Tệp nằm trên đĩa `private` (`storage/app/private`), **ngoài web root**, và **không dùng
`storage:link`**. Không có URL tĩnh nào tới một tệp hồ sơ: đường duy nhất là route
`documents/{document}/download` — chữ ký hết hạn sau 5 phút, chữ ký mang theo người nhận, và
controller **vẫn kiểm policy sau khi chữ ký hợp lệ** (SPEC §10.4: chữ ký không thay thế quyền).
Mỗi lượt tải thành công ghi một dòng `document_downloads` kèm IP và user agent.

Mọi tệp tải lên đi qua `App\Support\Files\FileGuard`: danh sách trắng đuôi tệp, MIME thật đọc
bằng `finfo` (không tin `Content-Type` do client gửi), giới hạn `UPLOAD_MAX_MB` (mặc định
20), và với `.docx`/`.xlsx` thì mở gói ra kiểm cấu trúc OOXML — vì dưới mắt libmagic một tệp
OOXML chính là `application/zip`, nên nếu chỉ tin MIME thì một zip bất kỳ lọt qua dưới cái tên
`.docx`. `.svg` bị cấm tường minh (SVG mang được JavaScript).

Quét virus là một seam: `NullScanner` là mặc định, đặt `CLAMAV_ENABLED=true` (kèm
`CLAMAV_SOCKET`, `CLAMAV_TIMEOUT`) để dùng `ClamAvScanner` với một daemon clamd thật. Lần bật
đầu tiên trên một máy chủ thật nên tự kiểm bằng một tệp EICAR.

## Giao diện: không có bước dựng CSS

Panel dùng `theme.css` đã biên dịch sẵn của Filament và dự án **không chạy Tailwind**, nên một
lớp tiện ích viết tay (`bg-gray-100`, `text-amber-600`, kể cả `p-2`) **không tô ra gì cả**. Kiểu
dáng viết tay phải là `style=` nội tuyến trên biến CSS của Filament (`--gray-500`,
`--danger-500`, `--warning-600`, `--primary-500`) hoặc một khối `<style>` in kèm bảng.

## Tên miền

Mặc định cả hai panel chạy chung một tên miền theo đường dẫn: `/admin` cho nội bộ,
`/portal` cho khách, `/` chuyển hướng về `/portal`. Điền `ADMIN_DOMAIN` và
`PORTAL_DOMAIN` trong `.env` để tách thành hai subdomain riêng.

## Cấu trúc

```
app/
├── Enums/                  Enum backed string cho mọi cột trạng thái
├── Models/                 User (nhân sự), Client, ClientUser (tài khoản portal), ...
├── Actions/                Toàn bộ logic nghiệp vụ (Actions/Document/ là nộp, duyệt, công bố — M4)
├── Filament/Admin/         Panel nội bộ
├── Filament/Portal/        Panel khách hàng
├── Http/Controllers/       DocumentDownloadController — đường DUY NHẤT tới một tệp hồ sơ (từ M4)
├── Providers/Filament/     AdminPanelProvider, PortalPanelProvider
├── Support/Files/          FileGuard và seam VirusScanner (từ M4)
└── Support/Scopes/         Global scope giới hạn dữ liệu theo khách (từ M2)
app/Console/Commands/       vkcrm:preflight, vkcrm:create-admin, vkcrm:reset-2fa, vkcrm:backup-check,
                            vkcrm:push-reset
config/vkcrm.php            Cấu hình riêng của hệ thống (tên miền, tiền tố mã hồ sơ, ...)
tools/deploy/               Mẫu nginx/Apache đã chạy thử + kịch bản kiểm máy chủ web (chặn storage/,
                            đưa /admin/sw.js và /portal/sw.js tới PHP)
tools/pwa/                  Kịch bản Playwright đo app trên điện thoại (service worker, thông báo đẩy)
lang/vi/                    Toàn bộ chuỗi giao diện tiếng Việt
docs/                       Đặc tả, kiến trúc, kế hoạch, tiến độ
```

## Triển khai lên máy chủ thật

Hướng dẫn đầy đủ, từng bước, cho VPS và shared hosting: **`docs/CAI-DAT.md`, phần "Cài lên máy
chủ thật (production)"**. Sao lưu và khôi phục: **`docs/SAO-LUU-KHOI-PHUC.md`** (dành cho chủ văn
phòng, kèm bảng số đo thật của một lần khôi phục thử — khoảng 2 phút 14 giây trên dữ liệu mẫu).
Tóm tắt những điều không được bỏ qua:

- **PHP 8.3 với đủ extension**: `ctype` `curl` `dom` `exif` `fileinfo` `filter` `hash` `iconv`
  `intl` `json` `libxml` `mbstring` `openssl` `pcre` `session` `tokenizer` `xmlreader` `zip`
  `zlib` `pdo_mysql` (nên có thêm `gd`). `curl` bắt buộc từ M12 (gói thông báo đẩy). MariaDB 11,
  gói `mariadb-client` (`mariadb-dump`), và `rclone` cho sao lưu Google Drive. Không cần Redis,
  Supervisor hay Node.js.
- **Thứ tự cài:** `cp .env.example .env` → `composer install --no-dev --optimize-autoloader` →
  `php artisan key:generate` (chỉ lần cài đầu, trên cơ sở dữ liệu rỗng) và điền `.env`
  (`APP_ENV=production`, `APP_DEBUG=false`, `TRUSTED_PROXIES`, `BRAND_*`…) → cấu hình máy chủ web
  từ mẫu đã chạy thử
  `tools/deploy/nginx.conf.example` hoặc `tools/deploy/apache-vhost.conf.example` (HTTPS, HSTS,
  header cho tệp tĩnh, chặn `storage/`) → `php artisan migrate --force` →
  `php artisan db:seed --force` (chỉ dữ liệu tham chiếu) → **`php artisan vkcrm:create-admin`**
  (quản trị viên đầu tiên, hỏi tương tác, mật khẩu nhập ẩn; 2FA bắt buộc ở lần đăng nhập đầu) →
  **`php artisan vkcrm:preflight`** → `php artisan optimize`.
- **`php artisan vkcrm:preflight` phải xanh TRƯỚC khi mở cổng và sau MỖI lần nâng cấp**, và chạy
  TRƯỚC `php artisan optimize` (một vài điều kiện đọc `.env` trực tiếp). Nó kiểm
  `TRUSTED_PROXIES`, `HEARTBEAT_URL`, cookie phiên chỉ qua https, `APP_DEBUG`, extension PHP,
  `storage/app/private` có lộ ra web không, và điều kiện máy chủ cho sao lưu.
- **Đúng một dòng cron**, cộng giám sát cron qua `HEARTBEAT_URL`:
  `* * * * * cd /var/www/vk-crm && php artisan schedule:run >> /dev/null 2>&1`
- **`APP_KEY` là một nửa của bản sao lưu**: nó mã hoá số định danh khách hàng và secret 2FA của
  nhân sự, và là khoá của cột so trùng CCCD. Cất nó (cùng `BACKUP_ARCHIVE_PASSWORD`) ở hai nơi
  ngoài máy chủ, không cùng chỗ bản sao lưu; không bao giờ `key:generate` trên dữ liệu thật.
- **App trên điện thoại và thông báo đẩy (M12)** chỉ chạy trên HTTPS. Khoá thông báo đẩy sinh MỘT
  lần cho mỗi môi trường: `php artisan config:clear` rồi `php artisan webpush:vapid` (khi hai dòng
  `VAPID_*_KEY` còn trống), điền `VAPID_SUBJECT=mailto:…`. `VAPID_PRIVATE_KEY` cất cùng chỗ với
  `APP_KEY`; mất hay đổi khoá thì chạy `php artisan vkcrm:push-reset` (mọi người bật lại thông báo
  trên từng máy). Mẫu nginx có hai khối `location = /admin/sw.js`, `location = /portal/sw.js` — máy
  chủ cũ phải chép thêm. Hàng đợi `push` chạy trong chính dòng cron ở trên. Chi tiết:
  `docs/CAI-DAT.md`, Bước 3 và "Bản cập nhật M12"; hướng dẫn cài app cho khách:
  `docs/QUY-TRINH.md`.
- **Nâng cấp:** `php artisan down` → `git pull` → `composer install --no-dev --optimize-autoloader`
  → `chown -R www-data:www-data storage bootstrap/cache` → `php artisan migrate --force` →
  `php artisan db:seed --force` → `php artisan optimize:clear` → `php artisan vkcrm:preflight` →
  `php artisan optimize` → `php artisan up`, rồi theo dõi thư báo lỗi của lượt sao lưu đêm đầu.
  Chi tiết: `docs/CAI-DAT.md`, "Nâng cấp lên bản mới".
