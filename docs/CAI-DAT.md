# Cài lại VK-CRM trên một máy trống

Đã kiểm chứng thật ngày **2026-09-23**: tải một bản sạch từ GitHub về ổ D, cài phụ thuộc,
dựng cơ sở dữ liệu từ số không, và cả hai màn hình đều lên với đủ dữ liệu mẫu (21 vụ việc,
12 khách hàng, 8 nhân sự). Các bước dưới đây là đúng những bước đã chạy, không phải mô tả.

## Cần có sẵn trên máy

Chỉ **Docker Desktop** và **Git**. Không cần cài PHP, Composer hay MariaDB lên máy — mọi
thứ chạy trong container. Đây là chủ đích: máy nào có Docker là dựng lại được.

## Bốn bước

```bash
git clone https://github.com/Harry-Kien/vk-crm.git
cd vk-crm
cp .env.example .env
```

Rồi mở `.env` và điền (xem phần dưới về `APP_KEY` — **đọc trước khi sinh khoá mới**).

```bash
bin/dev up -d
bin/dev composer install
bin/dev artisan migrate:fresh --seed
```

Xong. Mở `http://localhost/admin` và `http://localhost/portal`.

## Bốn thứ cố ý KHÔNG nằm trong kho, và vì sao

| Thứ gì | Vì sao không đẩy lên | Lấy lại bằng cách nào |
|---|---|---|
| `.env` | Chứa khoá bí mật và mật khẩu cơ sở dữ liệu. Không bao giờ đưa lên kho, kể cả kho riêng tư. | Chép từ `.env.example` rồi điền |
| `vendor/` | Vài nghìn tệp của thư viện ngoài, dựng lại được từ `composer.lock` | `bin/dev composer install` |
| Cơ sở dữ liệu | Dữ liệu, không phải mã | `migrate:fresh --seed` cho dữ liệu mẫu, hoặc phục hồi từ bản sao lưu cho dữ liệu thật |
| Tệp hồ sơ khách nộp | Dữ liệu của khách hàng | Phục hồi từ bản sao lưu |

## CẢNH BÁO về `APP_KEY` — đọc trước khi chạy `key:generate`

Cột số định danh cá nhân của khách hàng (`clients.id_number`) được **mã hoá khi lưu**
(SPEC §10.5). Khoá dùng để mã hoá chính là `APP_KEY`.

**M8 Task 2 (R2): `APP_KEY` giờ còn mã hoá cả secret 2FA và mã khôi phục của MỌI nhân sự nội
bộ** (`users.two_factor_secret`, `users.two_factor_recovery_codes` — panel `admin` bắt buộc 2FA,
không tắt được). Sinh khoá mới không chỉ mất số định danh khách hàng — nó khoá NGOÀI cả văn
phòng: với mỗi nhân sự đã cài 2FA, bước nhập mã sau mật khẩu ném `DecryptException` (trang lỗi
500, không phải trang cài đặt lại), nên không ai đăng nhập được `/admin` nữa. Có một lối thoát,
từng người một, và chỉ dùng được khi có quyền vào máy chủ: `php artisan vkcrm:reset-2fa <email>`
xoá secret (không cần giải mã nó), người đó đăng nhập bằng mật khẩu như cũ rồi cài lại 2FA. Lệnh
này KHÔNG cứu được số định danh khách hàng đã mã hoá — chúng vẫn mất vĩnh viễn (đoạn dưới). Cả
hai điều trên có test đo hành vi: `tests/Feature/Actions/User/ResetStaffTwoFactorTest.php`.

**M8 Task 4 (SPEC §10.5): `APP_KEY` còn là khoá của cột so trùng số CCCD** của kiểm tra xung đột
lợi ích (`matter_parties.id_number_hash` — HMAC-SHA256 với `APP_KEY`, không phải `sha256` trần,
để ai cầm một bản dump cơ sở dữ liệu cũng không dò ngược ra được số CCCD). Sinh khoá mới làm mọi
giá trị đã lưu thôi khớp: kiểm tra xung đột lợi ích **im lặng** không còn thấy trùng số CCCD với
bất kỳ bên nào nhập trước đó — không báo lỗi, chỉ còn so được bằng số điện thoại và tên. Với bên
đối lập không có cách tính lại: số CCCD thô của họ chưa bao giờ được lưu. Test đo hành vi:
`tests/Feature/Actions/RunConflictCheckTest.php` ("a new key silently blinds the id-number tier").

**Xoay khoá có kế hoạch (`APP_PREVIOUS_KEYS`) cũng KHÔNG cứu được cột so trùng đó.** Laravel cho
đặt khoá mới vào `APP_KEY` và khoá cũ vào `APP_PREVIOUS_KEYS` (`config/app.php`): dữ liệu MÃ HOÁ
(`clients.id_number`, secret 2FA) vẫn giải mã được bằng khoá cũ. Nhưng `matter_parties.id_number_hash`
là một HMAC tính bằng khoá HIỆN TẠI, không phải một bản mã — không có gì để "thử khoá cũ", nên sau
khi xoay, mọi giá trị đã lưu thôi khớp y như sinh khoá mới. Dự án chưa có lệnh tính lại. Với bên là
khách của văn phòng, số thô còn trong `clients.id_number` nên tính lại được bằng một lệnh viết riêng
theo đúng logic của migration `2026_10_01_000001_rehash_matter_party_id_number_hashes`; với bên đối
lập thì không. Chỉ xoay `APP_KEY` khi nghi khoá đã lộ, và biết trước cái giá này.

**Sinh khoá mới trên một cơ sở dữ liệu đã có dữ liệu thật nghĩa là mọi số định danh đã lưu
trở thành không đọc được, vĩnh viễn.** Không có cách khôi phục.

- Dựng máy mới với dữ liệu mẫu: sinh khoá mới thoải mái.
- Dựng lại để dùng tiếp dữ liệu thật: **giữ nguyên `APP_KEY` cũ**, chép từ bản `.env` đang chạy.

Vì vậy `.env` của máy đang chạy — nó chứa `APP_KEY` **và** `BACKUP_ARCHIVE_PASSWORD`, mật
khẩu mở mọi bản sao lưu — phải được cất ở **hai nơi ngoài máy chủ, KHÔNG cùng chỗ với bản sao
lưu**, và không trong thư mục dự án. Ai có cả bản sao lưu lẫn `.env` là mở được mọi bản sao lưu
và đọc được mọi số định danh đã mã hoá: cất `.env` cạnh bản sao lưu (ví dụ cùng thư mục Google
Drive) là tự đưa chìa khoá kèm két. Cách cất cụ thể: `docs/SAO-LUU-KHOI-PHUC.md`, **Bước 6**.

## Tài khoản dùng thử sau khi gieo dữ liệu mẫu

**Chỉ có trên máy dev.** `migrate:fresh --seed` tạo chúng khi `APP_ENV` là `local` (hoặc `testing`
của bộ test); trên máy chủ thật (`APP_ENV=production`) cùng lệnh seed chỉ tạo dữ liệu tham chiếu,
không tài khoản nào (M6.5 Task 19) — quản trị viên đầu tiên ở đó tạo bằng
`php artisan vkcrm:create-admin` (phần "Cài lên máy chủ thật", Bước 6).

Mật khẩu đều là `password`.

| Vai | Đăng nhập tại | Tài khoản |
|---|---|---|
| Quản trị | `/admin` | `admin@luatvukhang.com` |
| Quản lý | `/admin` | `quanly@luatvukhang.com` |
| Luật sư | `/admin` | `luatsu1@luatvukhang.com` |
| Trợ lý | `/admin` | `troly1@luatvukhang.com` |
| Kế toán | `/admin` | `ketoan@luatvukhang.com` |
| Khách hàng | `/portal` | `khach1@example.com` |

Cổng khách gửi một mã sáu số qua email sau bước mật khẩu. Trên máy dev, thư bị bắt lại và
đọc ở `http://localhost:8025`.

**Panel `/admin` bắt buộc 2FA ứng dụng (M8 Task 2, R2), không có cách nào tắt.** Năm tài khoản
nhân sự ở trên (`local`/`testing` thôi — không phải khi ép `db:seed --class=DemoDataSeeder` trên
một máy chủ thật) đều dùng CHUNG một secret TOTP cố định:

```
JBSWY3DPEHPK3PXP
```

Thêm secret này vào một app xác thực (Google Authenticator, Authy, 1Password…) MỘT LẦN — theo
kiểu "nhập mã thủ công" (manual entry key), tên tài khoản đặt tuỳ ý — và dùng lại được cho cả năm
tài khoản, qua mọi lần `migrate:fresh --seed`. Không cần quét mã QR.

Một máy chủ THẬT không bao giờ có secret này: seeder chỉ gán nó khi `APP_ENV` là `local` hoặc
`testing`. Nhân sự thật cài 2FA của riêng mình ở lần đăng nhập đầu (trang "Cài đặt 2FA bắt buộc"
hiện ra tự động).

## Chạy nhiều bản cùng lúc trên một máy

Đổi cổng trong `.env` của bản thứ hai, nếu không Docker sẽ báo cổng đã bị chiếm:

```
APP_PORT=8080
FORWARD_DB_PORT=33070
FORWARD_MAILPIT_PORT=11025
FORWARD_MAILPIT_DASHBOARD_PORT=18025
```

Docker đặt tên dự án theo tên thư mục, nên hai bản có cơ sở dữ liệu riêng và không đụng nhau.

## Lệnh hay dùng

```bash
bin/dev up -d                # dựng môi trường
bin/dev artisan <lệnh>       # chạy lệnh Laravel
bin/dev test                 # bộ kiểm thử, cơ sở dữ liệu nhẹ trong bộ nhớ
bin/dev test:mariadb         # bộ kiểm thử trên cơ sở dữ liệu thật — bắt buộc trước khi hợp nhất
bin/dev pint                 # định dạng mã
bin/dev mariadb              # dòng lệnh cơ sở dữ liệu
```

**`bin/dev test` và `bin/dev test:mariadb` không thay thế nhau.** Bản nhẹ dựng lại cả bảng
mỗi khi một chỉ mục đổi, nên nó **chưa từng** bắt được một ràng buộc chỉ mục hay khoá ngoại
nào của cơ sở dữ liệu thật; dự án đã vỡ vì chuyện này hai lần. Và các phép kiểm chỉ nói được
sự thật trên cơ sở dữ liệu thật thì trên bản nhẹ **tự bỏ qua trong im lặng** — đó chính là
cách một lỗ hổng đếm số lần đăng nhập sai sống sót qua hai vòng sửa.

## Cài lên máy chủ thật (production)

Mọi phần ở trên là cho máy DEV (Docker, dữ liệu mẫu). Phần này là máy chủ THẬT: một VPS Linux
(Ubuntu/Debian) hoặc một gói shared hosting có SSH. Làm **đúng thứ tự** — vài bước dựa vào bước
trước (ví dụ `vkcrm:preflight` phải chạy TRƯỚC `php artisan optimize`). Các bước dưới đây đã được
đi thử một lượt theo đúng chữ, trên một bản clone mới trong container sạch, ngày 2026-10-01 (M8
Task 7; đầu ra ở `docs/PROGRESS.md`, mục "Ghi chú M8").

Ví dụ đặt mã nguồn ở `/var/www/vk-crm` — đúng đường dẫn của hai mẫu máy chủ web trong
`tools/deploy/` — và PHP-FPM chạy bằng người dùng `www-data`. Đổi cả hai cho đúng máy chủ thật.
Mọi lệnh `php artisan …` chạy trong thư mục `/var/www/vk-crm`, bằng chính người dùng chạy PHP-FPM
(ví dụ `sudo -u www-data php artisan …`), để tệp nó tạo ra trong `storage/` không thuộc về `root`.

### Bước 0 — Hỏi chủ văn phòng trước khi bắt đầu

| Cần gì | Dùng ở đâu |
|---|---|
| Có giới hạn IP được vào `/admin` không; có thì những IP/dải nào | `ADMIN_IP_ALLOWLIST` (để trống = tắt, mặc định) |
| Bốn thông tin pháp lý: mã số thuế, Đoàn Luật sư, số Giấy đăng ký hoạt động, địa chỉ văn phòng | `BRAND_TAX_CODE`, `BRAND_BAR_ASSOCIATION`, `BRAND_LICENCE_NUMBER`, `BRAND_OFFICE_ADDRESS` — in ở chân mọi thư gửi khách |
| Hộp thư có người đọc, nhận thư khi khách bấm "Trả lời" | `BRAND_REPLY_TO_ADDRESS` |
| Họ tên và email của quản trị viên đầu tiên | Bước 6 |
| Một tài khoản Google RIÊNG cho sao lưu | `docs/SAO-LUU-KHOI-PHUC.md`, Bước 2 |
| Hai chỗ cất khoá NGOÀI máy chủ (trình quản lý mật khẩu + bản giấy/USB trong két) | Bước 3 và `docs/SAO-LUU-KHOI-PHUC.md`, Bước 6 |

### Bước 1 — Máy chủ cần có

- **PHP 8.3** chạy qua **PHP-FPM**, với ĐỦ các extension sau — thiếu một cái là `vkcrm:preflight`
  báo ĐỎ:

  `ctype` `dom` `exif` `fileinfo` `filter` `hash` `iconv` `intl` `json` `libxml` `mbstring`
  `openssl` `pcre` `session` `tokenizer` `xmlreader` `zip` `zlib` `pdo_mysql`

  Đây là kết quả `composer check-platform-reqs --no-dev` cộng `pdo_mysql`, và chính là danh sách
  `vkcrm:preflight` kiểm (`config/vkcrm.php`, khoá `deployment.required_extensions`). Hai cái hay
  thiếu nhất trên shared hosting: `intl` (Filament bắt buộc) và `dom` (gói làm sạch HTML, gói ghép
  CSS vào thư, gói đọc/ghi tệp xlsx đều cần). Nên có thêm, chưa bắt buộc: `gd` (preflight báo VÀNG
  nếu thiếu) và `curl`. Kiểm nhanh: `php -m`.
- **Cấu hình PHP-FPM** (php.ini của FPM, KHÁC tệp php.ini của dòng lệnh — `php -i` chỉ in tệp của
  dòng lệnh; trên Ubuntu xem bản của FPM bằng `php-fpm8.3 -i`):
  - `upload_max_filesize` ≥ `UPLOAD_MAX_MB` (mặc định 20 → `20M`) và `post_max_size` lớn hơn nó
    (ví dụ `25M`). Mặc định của PHP là `2M`/`8M`: để nguyên thì mọi lần tải tệp trên 2 MB hỏng.
  - `proc_open` KHÔNG nằm trong `disable_functions` (sao lưu cần nó để gọi `mariadb-dump` và
    `rclone`; preflight kiểm bản của dòng lệnh — cron chạy sao lưu bằng PHP dòng lệnh).
- **MariaDB 11** — bản dự án chạy kiểm thử (máy dev và CI đều là `mariadb:11`). MariaDB 10.11 (mặc
  định của Ubuntu 24.04) chưa được chạy thử. Một cơ sở dữ liệu `utf8mb4` riêng và một tài khoản chỉ
  có quyền trên đúng cơ sở dữ liệu đó:

  ```sql
  CREATE DATABASE vk_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'vk_crm'@'localhost' IDENTIFIED BY '<mật-khẩu-cơ-sở-dữ-liệu>';
  GRANT ALL PRIVILEGES ON vk_crm.* TO 'vk_crm'@'localhost';
  ```
- **`mariadb-dump`** (gói `mariadb-client`, ví dụ `apt install mariadb-client`) trong `PATH` — sao
  lưu dùng nó; thiếu thì preflight ĐỎ.
- **`rclone`** cho đích sao lưu Google Drive — cài theo `docs/SAO-LUU-KHOI-PHUC.md`, Bước 1.
- **Composer 2** và **Git**.
- **nginx hoặc Apache** với chứng chỉ HTTPS (ví dụ Let's Encrypt/Certbot) — Bước 4.
- **Không cần** Redis, Supervisor, Node.js hay `npm run build`: cache, hàng đợi và phiên đều nằm
  trong cơ sở dữ liệu, và giao diện dùng tài sản đã biên dịch sẵn của Filament.

### Bước 2 — Lấy mã nguồn, cài phụ thuộc

```bash
git clone https://github.com/Harry-Kien/vk-crm.git /var/www/vk-crm
cd /var/www/vk-crm
cp .env.example .env
composer install --no-dev --optimize-autoloader
```

- `cp .env.example .env` TRƯỚC `composer install`: sau khi cài, composer tự gọi `php artisan
  package:discover` và `php artisan filament:upgrade` (mục `post-autoload-dump` của
  `composer.json`), và hai lệnh đó cần có `.env`.
- `filament:upgrade` chép tài sản giao diện của Filament vào `public/css/filament/`,
  `public/js/filament/`, `public/fonts/filament/` (không nằm trong kho — `.gitignore`). **Không có
  bước dựng giao diện**: không `npm`, không `vite build`; Livewire tự phục vụ tệp script của nó qua
  đường `/livewire-…/livewire.min.js` (mẫu nginx có khối riêng cho đúng đường này — Bước 4).
- `--no-dev`: không cài công cụ kiểm thử lên máy chủ thật.

Thư mục `storage/` và `bootstrap/cache/` phải GHI ĐƯỢC bởi người dùng chạy PHP-FPM; phần còn lại
của mã nguồn thì không cần:

```bash
chown -R www-data:www-data storage bootstrap/cache
```

**Không chạy `php artisan storage:link`** — tệp hồ sơ không bao giờ có đường dẫn tĩnh (xem mục
"Tệp hồ sơ" trong `README.md`).

### Bước 3 — Tệp `.env`

```bash
php artisan key:generate
```

**`key:generate` CHỈ ở lần cài đầu tiên, trên một cơ sở dữ liệu RỖNG.** Dựng lại máy chủ cho dữ
liệu đã có (khôi phục từ bản sao lưu, chuyển máy) thì chép `APP_KEY` CŨ vào `.env`, không sinh
khoá mới — đọc lại mục "CẢNH BÁO về `APP_KEY`" ở trên. Ngay sau lần sinh khoá đầu tiên, cất
`APP_KEY` (cùng `BACKUP_ARCHIVE_PASSWORD` ở Bước 10) ở hai nơi ngoài máy chủ
(`docs/SAO-LUU-KHOI-PHUC.md`, Bước 6).

Rồi mở `.env` và điền. Mọi biến đều có sẵn một dòng trong `.env.example`, kèm một câu nói giá trị
thật lấy ở đâu (`tests/Feature/Deployment/EnvExampleTest.php` giữ cho điều đó luôn đúng: một biến
mới mà quên dòng mẫu là test đỏ). Những dòng PHẢI sửa so với bản mẫu — bản mẫu là cho máy dev:

| Biến | Giá trị trên máy chủ thật | Lấy ở đâu |
|---|---|---|
| `APP_ENV` | `production` | — (seed, sao lưu, HTTPS, CSP đều đổi hành vi theo biến này) |
| `APP_DEBUG` | `false` | — (`true` thì trang lỗi in ra cấu hình; preflight ĐỎ) |
| `APP_URL` | `https://khachhang.luatvukhang.com` | tên miền thật, có `https://` |
| `LOG_LEVEL` | `warning` | — (`debug` của bản mẫu ghi quá nhiều) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `127.0.0.1`, `3306`, `vk_crm`, `vk_crm`, mật khẩu ở Bước 1 | người quản trị cơ sở dữ liệu / bảng điều khiển hosting |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` | máy chủ SMTP của tên miền văn phòng | nhà cung cấp email của tên miền (cần SPF/DKIM cho `MAIL_FROM_ADDRESS`) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `no-reply@luatvukhang.com`, `"Luật Vũ Khang"` | `MAIL_FROM_NAME` là tên văn phòng khách nhìn thấy, không phải `${APP_NAME}` |
| `TRUSTED_PROXIES` | `127.0.0.1` (bỏ dấu `#` đầu dòng) | đọc mục 1 ngay dưới bảng — KHÔNG dùng `*` |
| `HEARTBEAT_URL` | URL ping của dịch vụ giám sát cron | Bước 8 |
| `BRAND_TAX_CODE`, `BRAND_BAR_ASSOCIATION`, `BRAND_LICENCE_NUMBER`, `BRAND_OFFICE_ADDRESS` | bốn thông tin pháp lý | chủ văn phòng (Bước 0) |
| `BRAND_REPLY_TO_ADDRESS` | hộp thư có người đọc | chủ văn phòng — đọc mục 3 ngay dưới bảng |
| `BACKUP_*` | | `docs/SAO-LUU-KHOI-PHUC.md`, Bước 4 |
| `ADMIN_IP_ALLOWLIST` | để trống, hoặc danh sách IP/CIDR | chủ văn phòng (Bước 0) |

Để TRỐNG (đúng giá trị của bản mẫu) là chặt nhất, không cần điền gì: `SESSION_SECURE_COOKIE`,
`FORCE_HTTPS`, `HSTS_MAX_AGE` (ba biến tự bật ở mọi môi trường trừ `local`/`testing`), `CSP_MODE`
(trống ở production là `enforce`). `HSTS_INCLUDE_SUBDOMAINS`, `HSTS_PRELOAD` để trống — bật chúng
khoá luôn website `luatvukhang.com` và mọi tên miền con khác vào https. Giữ nguyên
`SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`: dự án không dùng
Redis, và lịch chạy rút hàng đợi `database` mỗi phút (Bước 8). Các biến `BRAND_*` còn lại (tên,
khẩu hiệu, hotline, Zalo, website…) đang là dòng chú thích mang giá trị mặc định — chỉ bỏ dấu `#`
và sửa khi văn phòng đổi; **đừng để `BRAND_…=` trống**: một dòng trống là chuỗi rỗng, không phải
"dùng mặc định", và tên văn phòng biến mất khỏi trang đăng nhập lẫn thư.

Ba điều cần nói rõ hơn một dòng bảng:

1. **`TRUSTED_PROXIES` phải điền địa chỉ proxy thật.** Để trống nghĩa là mọi khách hàng dùng
   chung một bộ đếm đăng nhập: năm lần gõ sai của bất kỳ ai khoá cả cổng trong 15 phút.
   **Đứng theo đúng hai mẫu `tools/deploy/nginx.conf.example`/`apache-vhost.conf.example` (máy chủ
   web nói thẳng với php-fpm, KHÔNG có proxy/CDN tách rời) thì điền `TRUSTED_PROXIES=127.0.0.1`** —
   không có proxy nào để tin, `REMOTE_ADDR` mà php-fpm thấy đã là địa chỉ thật của khách (nginx tự
   đặt qua `fastcgi_param REMOTE_ADDR $remote_addr`). **KHÔNG dùng `*`** dù `config/
   trustedproxy.php` có nhắc tới nó cho trường hợp "không biết địa chỉ đó": `*` tin bất kỳ IP nào
   tự khai `X-Forwarded-For`, xuyên thủng `ADMIN_IP_ALLOWLIST` (R7) và bộ đếm đăng nhập theo IP
   (SPEC §10.3) — `vkcrm:preflight` (R1) nay chặn đỏ `*`/`**`/`0.0.0.0/0`/`::/0` đúng vì lý do này.
   Có CDN/reverse-proxy thật đứng trước (Cloudflare, một load balancer riêng…) thì điền địa chỉ/dải
   IP THẬT của nó, không phải `127.0.0.1`.
2. **`MAIL_FROM_NAME` phải là tên văn phòng** (ví dụ `"Luật Vũ Khang"`), không phải `${APP_NAME}`
   mặc định của bộ cài — nếu không, hộp thư của khách hiện tên kỹ thuật của dự án làm người gửi.
3. **Văn phòng xác nhận địa chỉ "Trả lời" của thư, `BRAND_REPLY_TO_ADDRESS`** (M6.5 Task 12).
   Mọi thư của hệ thống gắn `Reply-To` lấy từ `config('vkcrm.brand.reply_to')`, để khách bấm "Trả
   lời" thì thư tới một hộp có người đọc, không tới `MAIL_FROM_ADDRESS` (`no-reply@`). Ba trường hợp:
   - **không có dòng** `BRAND_REPLY_TO_ADDRESS` trong `.env` (hoặc dòng còn dấu `#`, như trong
     `.env.example`): dùng mặc định `lienhe@luatvukhang.com` (trong `config/vkcrm.php`);
   - **có dòng nhưng để trống** (`BRAND_REPLY_TO_ADDRESS=`): thư **không có** `Reply-To`, và khách
     trả lời sẽ rơi vào hộp `no-reply@`;
   - điền một địa chỉ: dùng địa chỉ đó.

   Chủ văn phòng cần xác nhận địa chỉ mặc định có đúng không (sổ tay M6.5 ghi việc này đang chờ trả
   lời).

**Bốn thông tin pháp lý nằm ở `.env` cho tới khi M7 Task 10 được gộp vào** — từ đó chủ văn phòng
sửa chúng ngay trong ứng dụng, không cần quyền vào máy chủ.

### Bước 4 — Máy chủ web

Dùng mẫu ĐÃ CHẠY THỬ, sửa tên miền, đường dẫn chứng chỉ và đường dẫn dự án:

- nginx: `tools/deploy/nginx.conf.example` (cùng socket PHP-FPM `/run/php/php8.3-fpm.sock` —
  sửa nếu máy chủ khác);
- Apache: `tools/deploy/apache-vhost.conf.example` (cần `a2enmod ssl rewrite headers alias`). Mẫu
  không tự nối PHP: dùng mod_php, hoặc PHP-FPM qua `proxy_fcgi` — trên Ubuntu
  `a2enmod proxy_fcgi setenvif` rồi `a2enconf php8.3-fpm`.

Hai mẫu cùng làm năm việc — đừng bỏ việc nào khi chép sang cấu hình khác:

1. **Document root là `public/`**, không phải gốc dự án.
2. **Chuyển http → https** trước khi PHP chạy, và gửi **HSTS** (`Strict-Transport-Security`) trên
   https. `App\Http\Middleware\EnforceHttps` chỉ là lớp DỰ PHÒNG khi hosting không cho sửa cấu hình
   máy chủ web, không thay thế tầng này.
3. **Ba header SPEC §10 mục 2 cho TỆP TĨNH dưới `public/`** (`X-Frame-Options`,
   `X-Content-Type-Options`, `Referrer-Policy`). Trang do PHP trả đã có chúng
   (`App\Http\Middleware\SendSecurityHeaders`, cùng `Content-Security-Policy`), nhưng ảnh/CSS/JS
   do chính máy chủ web trả thẳng không bao giờ qua PHP — không đặt ở máy chủ web là chúng thiếu
   header.
4. **Chặn `/storage/` và mọi dotfile** (`/.env`, `/.git/…`) — kể cả khi document root lỡ đặt nhầm
   vào gốc dự án. Kiểm THẬT bằng request thật, sau mọi lần đổi cấu hình máy chủ web:
   `bash tools/deploy/verify-storage-blocked.sh` (cần Docker — chạy trên máy dev với chính mẫu đã
   sửa; trên máy chủ thật thì `curl -I https://<tên miền>/storage/app/private/` và
   `curl -I https://<tên miền>/.env` phải là `404`).
5. **Giới hạn thân request ≥ `UPLOAD_MAX_MB` cộng phần dư** (`client_max_body_size 25m;` /
   `LimitRequestBody 26214400` cho 20 MB mặc định) — thấp hơn thì máy chủ web tự trả `413` trước
   khi PHP kịp thấy tệp.

Shared hosting không cho sửa cấu hình máy chủ web: trỏ document root (tên miền/tên miền con) vào
`public/` trong bảng điều khiển, bật "Force HTTPS" nếu có, và kiểm mục 4 bằng `curl` như trên —
`EnforceHttps` lo phần HTTPS/HSTS dự phòng.

### Bước 5 — Cơ sở dữ liệu: migrate và seed dữ liệu tham chiếu

**Seed đúng lệnh — KHÔNG chạy `migrate:fresh --seed` như bước "Bốn bước" của máy dev.** Lệnh đó
XOÁ mọi bảng rồi gọi `DatabaseSeeder`, và trên `APP_ENV=production` (`.env` của máy chủ thật phải
đặt vậy) seeder CHỈ tạo dữ liệu tham chiếu (vai trò, quyền, 6 loại vụ việc, giai đoạn, danh mục hồ
sơ mẫu) — không có admin, không có tài khoản demo mật khẩu `password` nào (M6.5 Task 19; trước bản
vá này, `migrate:fresh --seed` tạo thẳng `admin@luatvukhang.com`/`password` trên đúng tên miền
thật). Trên máy chủ thật:

```bash
php artisan migrate --force
php artisan db:seed --force
```

**Chạy lại `db:seed --force` sau mỗi lần cập nhật là an toàn** (rà soát cuối M6.5, X10). Ba
seeder nó gọi (`ReferenceDataSeeder`):

- `RolesAndPermissionsSeeder` — đồng bộ lại vai trò và quyền theo mã nguồn; chạy lại bao
  nhiêu lần cũng được, và NÊN chạy lại khi bản cập nhật có quyền mới.
- `MatterTypeSeeder`, `ChecklistTemplateSeeder` — **chỉ thêm**: một loại vụ việc (kèm giai
  đoạn) chỉ được tạo khi mã của nó chưa từng có, kể cả đã xoá; một danh mục hồ sơ mẫu (kèm đầu
  mục) chỉ được tạo khi loại đó chưa có danh mục mang đúng tên ấy. Tên, "Đang dùng", nhãn và mô
  tả giai đoạn, đầu mục đã sửa hay đã xoá — mọi thứ quản trị viên đã chỉnh — không bao giờ bị
  ghi đè hay khôi phục. Muốn lấy lại cấu hình mặc định của một loại thì phải sửa tay.

**Muốn dữ liệu mẫu để demo cho khách trước khi dùng thật** (không phải dữ liệu thật): gọi thẳng
seeder demo bằng `--class`, cờ này đi thẳng vào lớp được đặt tên, không qua kiểm tra môi trường
của `DatabaseSeeder`:

```bash
php artisan db:seed --class=DemoDataSeeder --force
```

Đừng chạy lệnh này trên dữ liệu thật: nó tạo tài khoản mật khẩu `password` trên tên miền thật
(không kèm secret 2FA demo — secret đó chỉ gán ở `local`/`testing`, nên mỗi tài khoản demo vẫn
phải tự cài 2FA ở lần đăng nhập đầu).

### Bước 6 — Tạo quản trị viên đầu tiên: `vkcrm:create-admin`

Ngay sau bước seed, khi chưa ai đăng nhập được `/admin`:

```bash
php artisan vkcrm:create-admin
```

Lệnh hỏi họ tên, email, rồi mật khẩu **nhập ẩn hai lần** (không hiện ký tự nào khi gõ — đó là
bình thường). Mật khẩu theo đúng luật của màn hình Nhân sự (hôm nay: ít nhất 8 ký tự). Tài khoản
ra đời với chức danh Quản trị viên, vai trò `admin`, đang hoạt động, **chưa có 2FA** — lần đăng
nhập đầu tiên hệ thống buộc cài (Bước 9). Mỗi lần tạo ghi một dòng "Tạo quản trị viên từ dòng
lệnh máy chủ" vào Nhật ký hệ thống.

- Lệnh **chỉ chạy tương tác**, trong một phiên SSH: không có tham số nào nhận mật khẩu (nó sẽ nằm
  lại trong lịch sử shell), và `--no-interaction` bị từ chối.
- **Đã có quản trị viên thì lệnh từ chối**, nêu số lượng (tính cả người đang bị vô hiệu hoá). Nhân
  sự tiếp theo — kể cả quản trị viên thứ hai — tạo trong `/admin`, màn hình Nhân sự. Chỉ khi không
  còn quản trị viên nào đăng nhập được mới chạy `php artisan vkcrm:create-admin --additional`
  (việc này cũng vào nhật ký, ghi rõ cờ).
- Email đã thuộc một nhân sự — kể cả nhân sự đã xoá — bị từ chối: dùng email khác.
- **Không dùng `php artisan make:filament-user`** (lệnh có sẵn của Filament): nó tạo người dùng
  không có chức danh, không vai trò, không ghi nhật ký.

### Bước 7 — `vkcrm:preflight`, rồi mới cache cấu hình

```bash
php artisan vkcrm:preflight
php artisan optimize
```

**`php artisan vkcrm:preflight` phải xanh hết (R1) — chạy TRƯỚC khi mở cổng, sau MỖI lần nâng
cấp, và TRƯỚC `php artisan optimize`/`config:cache`** (vài điều kiện đọc `.env` trực tiếp, không
còn thấy giá trị thật sau khi cấu hình đã cache). Lệnh tự kiểm
`TRUSTED_PROXIES`/`HEARTBEAT_URL`/`SESSION_SECURE_COOKIE`/`APP_DEBUG`, PHP extension bắt buộc,
`storage/app/private` có phục vụ công khai được không (nó tự gửi một request tới `APP_URL` — chạy
khi máy chủ web và HTTPS ở Bước 4 đã lên), và ba điều kiện máy chủ cho sao lưu:

- PHP extension `zip` dựng với libzip có mã hoá AES (`ZipArchive::EM_AES_256`) — thiếu nó, mọi
  lượt sao lưu ở production bị từ chối (không tạo bản sao lưu không mã hoá) và có email báo lỗi;
- hàm `proc_open` không bị tắt (nhiều shared hosting tắt nó trong `disable_functions`; thiếu nó
  thì không dump được CSDL và không gọi được `rclone`);
- lệnh `mariadb-dump` (gói `mariadb-client`, ví dụ `apt install mariadb-client`) có trong PATH;
- cộng tệp chạy `rclone` cho đích Google Drive (Bước 1 của `docs/SAO-LUU-KHOI-PHUC.md` —
  `vkcrm:preflight` không kiểm riêng `rclone`, dùng `vkcrm:backup-check` cho việc đó).

Dòng ĐỎ chặn mở cổng; dòng VÀNG (ví dụ bốn thông tin pháp lý `BRAND_*` chưa điền — xem M7
Task 10) không chặn nhưng nên xử lý sớm.

`php artisan optimize` cache cấu hình, route, view và sự kiện (cộng phần cache riêng của
Filament). **Từ lúc này, sửa `.env` không có tác dụng cho tới khi cache lại**: sau mỗi lần sửa
`.env`, chạy `php artisan optimize:clear`, `php artisan vkcrm:preflight`, rồi `php artisan
optimize`.

### Bước 8 — Một dòng lịch chạy tự động (cron), và giám sát nó

Đúng một dòng trong crontab của người dùng chạy PHP-FPM (`crontab -u www-data -e` trên VPS; mục
"Cron Jobs" trên shared hosting):

```
* * * * * cd /var/www/vk-crm && php artisan schedule:run >> /dev/null 2>&1
```

Dòng này chạy MỌI việc định kỳ khai báo trong `routes/console.php`, ví dụ: gửi thư trong hàng đợi
(mỗi phút), nhắc mốc thời hạn (07:00–19:30, mỗi 30 phút), sao lưu (02:00), giám sát sao lưu (08:00),
ping giám sát cron (mỗi 5 phút). Không cần tiến trình `queue:work` chạy thường trực.

**Giám sát cron** — cron trên shared hosting hay lặng lẽ ngừng chạy sau khi gia hạn gói hay đổi
cấu hình PHP:

- tạo một kiểm tra (check) trên một dịch vụ giám sát cron miễn phí (ví dụ healthchecks.io), chu kỳ
  5 phút, và điền URL ping của nó vào `HEARTBEAT_URL` — cron ngừng thì dịch vụ đó gửi email;
- trang chủ `/admin` hiện khối đỏ "Hệ thống nhắc việc đã ngừng chạy" khi lần chạy gần nhất cũ hơn
  30 phút (`App\Models\SystemHealth::STALE_AFTER_MINUTES`), và "Hệ thống nhắc việc chưa từng chạy"
  khi dòng cron chưa bao giờ chạy — thấy khối đó ngay sau khi cài là dòng cron chưa đúng.

### Bước 9 — Đăng nhập lần đầu, cài 2FA, nhập thông tin văn phòng

1. Mở `https://<tên miền>/admin`, đăng nhập bằng tài khoản của Bước 6.
2. Hệ thống dẫn thẳng tới trang **cài xác thực hai lớp bắt buộc** (panel `/admin` bắt buộc 2FA,
   không có cách nào tắt — R2). Quét mã QR bằng một app xác thực trên điện thoại (Google
   Authenticator, Authy, 1Password…), nhập mã sáu số.
3. Hệ thống hiện **mã khôi phục ĐÚNG MỘT LẦN**. Cất chúng ngay, ở nơi khác điện thoại (trình quản lý
   mật khẩu). Mất điện thoại mà còn mã khôi phục thì vẫn vào được.
4. Tạo các nhân sự khác ở màn hình **Nhân sự** (chức danh nào thì vai trò đó). Mỗi người tự cài 2FA
   của mình ở lần đăng nhập đầu.
5. Kiểm lại bốn thông tin pháp lý (Bước 3) — chúng in ở chân mọi thư gửi khách.

### Bước 10 — Sao lưu, và một lần khôi phục thử

Làm theo `docs/SAO-LUU-KHOI-PHUC.md` từ Bước 1 tới Bước 6: cài `rclone`, nối Google Drive, điền
`BACKUP_*`, chạy `php artisan vkcrm:backup-check`, và **cất `APP_KEY` + `BACKUP_ARCHIVE_PASSWORD`
ở hai nơi ngoài máy chủ, KHÔNG cùng chỗ với bản sao lưu**. `APP_KEY` giờ là chìa khoá của BA thứ
trong bản sao lưu: số định danh khách hàng (`clients.id_number`), secret 2FA của mọi nhân sự, và
cột so trùng CCCD của kiểm tra xung đột lợi ích (`matter_parties.id_number_hash`). Rồi chạy **một
lần khôi phục thử thật** (mục "Khôi phục thử" của tài liệu đó) trước khi coi là đã có sao lưu
(R3): sao lưu chưa khôi phục thử thì chưa phải sao lưu.

### Bước 11 — Mở cổng

`vkcrm:preflight` xanh, `vkcrm:backup-check` xanh, cron đã chạy (khối đỏ ở trang chủ `/admin` đã
biến mất), quản trị viên đã cài 2FA, một lần khôi phục thử đã chạy. Giờ mới gửi đường dẫn
`/portal` cho khách.

## Vận hành hằng ngày

- **Nhân sự bị khoá đăng nhập** (năm lần sai trong 15 phút, theo email và theo IP — SPEC §10.3):
  một quản trị viên KHÁC mở **Nhân sự → người đó → "Mở khoá đăng nhập"**. Nếu người đó vẫn ngồi
  chung mạng với các lần gõ sai, chiều IP có thể còn khoá tới hết 15 phút. Khách hàng bị khoá ở
  cổng `/portal`: màn hình **Tài khoản portal → tài khoản của khách → "Mở khoá đăng nhập"**.
- **Nhân sự mất điện thoại và mã khôi phục**: một quản trị viên KHÁC mở **Nhân sự → người đó →
  "Đặt lại 2FA"**. Việc này xoá xác thực cũ, đăng xuất mọi phiên đang mở của người đó, và buộc họ
  cài lại 2FA ở lần đăng nhập kế tiếp; không ai tự đặt lại 2FA cho chính mình. Quản trị viên DUY
  NHẤT mất cả điện thoại lẫn mã khôi phục: người có quyền vào máy chủ chạy
  `php artisan vkcrm:reset-2fa <email>`.
- **Email báo lỗi sao lưu** không phải chuyện để "xem sau" — `docs/SAO-LUU-KHOI-PHUC.md`.
- **Nhật ký hệ thống** (`/admin`, mục Nhật ký hệ thống): đăng nhập, tải tài liệu, công bố, đổi phân
  quyền, tạo/khoá tài khoản portal, đặt lại 2FA, mở khoá đăng nhập, tạo quản trị viên từ dòng lệnh.
  Không có lịch tự xoá nhật ký: giữ ít nhất `RETENTION_YEARS` năm.

## Nâng cấp lên bản mới

```bash
cd /var/www/vk-crm
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force
php artisan optimize:clear
php artisan vkcrm:preflight
php artisan optimize
php artisan up
```

- `php artisan down` trả trang bảo trì (503) cho mọi người trong lúc cập nhật, để không ai ghi dữ
  liệu giữa chừng một migration.
- `db:seed --force` an toàn để chạy lại (Bước 5) và NÊN chạy: bản mới có thể thêm quyền hay loại vụ
  việc.
- Preflight ĐỎ thì sửa trước khi `php artisan up` — chạy `up` rồi mới phát hiện là mở cổng trên
  một cấu hình hỏng.
- Đọc phần ghi chú nâng cấp của bản mới trong `docs/PROGRESS.md` TRƯỚC khi chạy: một bản có thể
  kèm việc phải làm tay (ví dụ một biến `.env` mới — so `.env.example` mới với `.env` đang chạy).
- **Đêm đầu sau nâng cấp, theo dõi hộp thư báo lỗi**: lượt sao lưu 02:00 và lượt giám sát 08:00 là
  lần đầu bản mới chạy những việc đó. Sáng hôm sau chạy `php artisan vkcrm:backup-check`.
- Khi nghi ngờ: bản sao lưu đêm trước là điểm quay lại, và `APP_KEY` không đổi qua các bản nâng cấp.

## Thao tác tiền và thời gian chờ khoá của MariaDB (`innodb_lock_wait_timeout`)

Các thao tác tiền (hợp đồng, đợt thu, khoản thu — M9) khoá dòng theo một thứ tự cố định (vụ việc,
rồi hợp đồng, đợt thu, khoản thu) và chạy lại TOÀN BỘ transaction tối đa 3 lần khi MariaDB báo xung
đột (mã 1020, 1205 — hết thời gian chờ khoá, 1213 — deadlock). Hết lượt thì người dùng thấy câu
tiếng Việt "thử lại" và không có gì được ghi — đó là đường đúng.

Đường SAI xảy ra khi máy chủ web bỏ cuộc trước MariaDB. Mỗi lượt chờ một dòng đang bị khoá tối đa
`innodb_lock_wait_timeout` giây (mặc định của MariaDB: **50**), nên ba lượt có thể chờ tới ~150 giây.
nginx mặc định chỉ chờ PHP-FPM 60 giây (`fastcgi_read_timeout`; Apache: `ProxyTimeout`/`Timeout`,
cũng 60): người dùng thấy trang lỗi 504 trong khi PHP **vẫn chạy tiếp** (thời gian chờ cơ sở dữ liệu
không tính vào `max_execution_time` trên Linux) và có thể vẫn ghi xong — bấm lại lúc đó có thể ghi
hai lần.

Giữ **3 × `innodb_lock_wait_timeout` < thời gian chờ của máy chủ web**:

- **VPS (khuyến nghị):** đặt `innodb_lock_wait_timeout = 15` trong mục `[mysqld]` của cấu hình
  MariaDB (Ubuntu/Debian: `/etc/mysql/mariadb.conf.d/50-server.cnf`), khởi động lại MariaDB, kiểm
  bằng `SELECT @@GLOBAL.innodb_lock_wait_timeout;` — 3 × 15 = 45 giây < 60. Biến này chỉ đo lần chờ
  khoá DÒNG của InnoDB, nên nó chỉ làm một lần chờ dài báo lỗi sớm hơn; khoá bảng (migration,
  `LOCK TABLES`) chờ theo một biến khác, `lock_wait_timeout`, không đổi ở đây.
- Hoặc nâng thời gian chờ của máy chủ web (`fastcgi_read_timeout 180s;` trong khối `location ~
  \.php$` của mẫu nginx) — đổi lại, người dùng nhìn trang quay tới ba phút.
- **Shared hosting** (không đổi được cả hai): giữ nguyên, và dặn kế toán: gặp trang lỗi 504 ở màn
  hình thu tiền thì mở lại danh sách khoản thu của hợp đồng và kiểm trước khi nhập lại.

Bình thường một lần ghi tiền khoá dòng vài phần nghìn giây; chờ lâu chỉ xảy ra khi một việc dài
khác đang giữ khoá dòng vụ việc cùng lúc.

## Giới hạn đã biết

- **Safari cũ hơn 15.5** có thể bỏ qua chỉ thị `worker-src` của Content-Security-Policy: ảnh xem
  trước trong ô tải tệp có thể không hiện. Việc tải tệp vẫn chạy. Không nới `child-src` khi chưa đo
  được trên một máy Safari thật.
- `'unsafe-eval'` còn trong `script-src` (Alpine.js của Filament cần nó) — đã rà các chỗ in HTML
  thô ở M8 Task 1; giữ hay bỏ là quyết định của chủ văn phòng (`docs/PROGRESS.md`, "Ghi chú M8").

## Kiểm tra tay trước mỗi bản phát hành

Hai phép kiểm không chạy trong bộ test thường:

- **Quét bản sao lưu THẬT tìm số CCCD thô** (SPEC §10.5, M8 Task 4) —
  `tests/Feature/Backup/BackupPersonalDataScanTest.php` tạo một bản sao lưu thật rồi giải nén và
  quét nó, nhưng tự bỏ qua (`skipped`) khi máy không có `mariadb-dump` — mà container dev và CI đều
  không có. Trên máy dev:

  ```bash
  docker compose exec app apk add --no-cache mariadb-client
  bin/dev test:mariadb tests/Feature/Backup/BackupPersonalDataScanTest.php
  ```

  Kết quả phải là `passed`, không phải `skipped`. (Gói vừa cài mất khi container được dựng lại —
  lần sau cài lại.)
- **Máy chủ web chặn `storage/` và dotfile**: `bash tools/deploy/verify-storage-blocked.sh` (cần
  Docker), sau mọi lần sửa hai mẫu trong `tools/deploy/`.
