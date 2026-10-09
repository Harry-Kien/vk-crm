# Cài lại VK-CRM trên một máy trống

Đã kiểm chứng thật ngày **2026-09-23**, và đi lại theo đúng chữ ngày **2026-10-08** (Git Bash trên
Windows, bản clone mới, cổng riêng theo mục "Chạy nhiều bản cùng lúc"): cài phụ thuộc, dựng cơ sở dữ
liệu từ số không, và cả hai màn hình đều lên với đủ dữ liệu mẫu — 27 vụ việc, 12 khách hàng, 9 nhân
sự (8 đăng nhập được, cộng một luật sư "đã nghỉ việc" của dữ liệu mẫu M13), 16 tài khoản cổng khách.
Các bước dưới đây là đúng những bước đã chạy, không phải mô tả.

## Cần có sẵn trên máy

Chỉ **Docker Desktop** và **Git**. Không cần cài PHP, Composer hay MariaDB lên máy — mọi
thứ chạy trong container. Đây là chủ đích: máy nào có Docker là dựng lại được.

## Bốn bước

```bash
git clone https://github.com/Harry-Kien/vk-crm.git
cd vk-crm
cp .env.example .env
```

Máy đang chạy một bản khác (cổng 80, 3306, 1025, 8025 đã bị chiếm) thì đổi cổng trong `.env` trước —
mục "Chạy nhiều bản cùng lúc" bên dưới. Rồi cài phụ thuộc, dựng môi trường, sinh khoá, gieo dữ liệu
mẫu (máy mới với dữ liệu mẫu thì sinh khoá mới thoải mái; dựng lại cho dữ liệu THẬT thì đọc mục
`APP_KEY` bên dưới trước):

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$PWD:/var/www/html" -w /var/www/html webdevops/php:8.3-alpine composer install
bin/dev up -d
bin/dev artisan key:generate
bin/dev artisan migrate:fresh --seed
```

`MSYS_NO_PATHCONV=1` là cho Git Bash trên Windows: thiếu nó, Git Bash đổi `/var/www/html` thành
`C:/Program Files/Git/var/www/html` và Docker từ chối ("the working directory … is invalid"). Trên
Linux/macOS biến đó vô hại. Thiếu `key:generate` thì bước gieo dữ liệu dừng giữa chừng với
`No application encryption key has been specified.`: dữ liệu mẫu có số định danh khách hàng, cột này
được mã hoá bằng `APP_KEY`.

Xong. Mở `http://localhost/admin` và `http://localhost/portal` (đổi `APP_PORT` thì thêm `:<cổng>`).

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

**M11 (kết nối AI cho nhân sự): `APP_KEY` còn mã hoá refresh token và mã uỷ quyền OAuth.** Passport
dùng khoá mã hoá của ứng dụng (tức `APP_KEY`) cho hai thứ đó; hai tệp khoá RSA
(`storage/oauth-*.key`) chỉ ký và kiểm access token. Sinh hay xoay `APP_KEY` vì vậy làm MỌI kết nối
AI của MỌI nhân sự không làm mới được nữa: mỗi người phải vào Claude/ChatGPT kết nối lại từ đầu
(`docs/KET-NOI-AI.md`). Mất hay đổi riêng hai tệp khoá RSA thì nhẹ hơn: access token đang dùng (sống
tối đa 1 giờ) hết hiệu lực ngay, và client tự làm mới bằng refresh token. Hai tệp khoá vẫn cất cùng
chỗ, cùng quy trình với `APP_KEY` (mục "Máy chủ MCP" bên dưới).

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

**Trên Windows, đừng gọi thẳng `php artisan test` trong container.** Docker Desktop gắn mã
nguồn qua một ổ chia sẻ mà ở đó thư mục có khoảng 40 mục trở lên bị PHP đọc thiếu, nên PHPUnit
bỏ qua cả loạt tệp test mà vẫn báo xanh (2026-09-28: 750 test của `tests/Feature/Filament` biến
mất). `bin/dev test` đi qua `bin/container-test`, liệt kê tệp bằng `find` rồi truyền tường minh.
Nghi ngờ số test thì so các lớp trong `bin/dev test --list-tests` với
`find tests -name '*Test.php'`. CI chạy trên Linux nên không bị.

## Cài lên máy chủ thật (production)

Mọi phần ở trên là cho máy DEV (Docker, dữ liệu mẫu). Phần này là máy chủ THẬT: một VPS Linux
(Ubuntu/Debian) hoặc một gói shared hosting có SSH. Làm **đúng thứ tự** — vài bước dựa vào bước
trước (ví dụ `vkcrm:preflight` phải chạy TRƯỚC `php artisan optimize`). Các bước dưới đây đã được
đi thử theo đúng chữ hai lần: ngày 2026-10-01 trên một bản clone mới trong container sạch (M8 Task
7), và ngày 2026-10-08 trên một máy Ubuntu 24.04 trống trong container (nginx 1.24, PHP-FPM 8.3 của
Ubuntu, hai người dùng như dưới đây) từ Bước 1 tới "Nâng cấp lên bản mới" — đầu ra ở
`docs/PROGRESS.md`, mục "Nghiệm thu bản 1.0".

Ví dụ đặt mã nguồn ở `/var/www/vk-crm` — đúng đường dẫn của hai mẫu máy chủ web trong
`tools/deploy/` — và PHP-FPM chạy bằng người dùng `www-data`. Đổi cả hai cho đúng máy chủ thật.
Hai người dùng, mỗi người một việc:

- **Người quản trị** (tài khoản SSH của bạn, có `sudo`; KHÔNG phải `www-data`) là chủ của mã nguồn:
  chạy `git clone`, `git pull`, `composer install`, giữ deploy key. PHP-FPM không ghi được mã nguồn.
- **`www-data`** (người dùng chạy PHP-FPM) chạy MỌI lệnh `php artisan …`, trong thư mục
  `/var/www/vk-crm`: `sudo -u www-data php artisan …`. Nó là chủ của `storage/`, `bootstrap/cache/`
  và `.env` (Bước 2), nên tệp lệnh tạo ra không thuộc về `root` hay người quản trị, và hai lệnh ghi
  vào `.env` (`key:generate`, `webpush:vapid`) ghi được. Những khối lệnh bên dưới viết gọn
  `php artisan …`; chuỗi "Nâng cấp lên bản mới" viết đủ người chạy từng dòng.

**Shared hosting (không có `sudo`).** Gói shared hosting chỉ có MỘT người dùng: tài khoản SSH của bạn
vừa giữ mã nguồn vừa là người chạy PHP, và không có `sudo` (gõ thì nhận `sudo: command not found`).
Một người làm cả hai việc trên, nên ở mọi khối lệnh của tài liệu này: bỏ tiền tố `sudo -u www-data`
(chạy thẳng `php artisan …`, `rm …`, `nano .env`), bỏ hẳn hai dòng `sudo mkdir`/`sudo chown` của Bước 2
(đặt mã nguồn ở thư mục nhà cung cấp chỉ định, trong thư mục nhà của bạn) và mọi dòng `chown` (tệp đã
thuộc về bạn), bỏ dòng `crontab` (Bước 8 dùng mục "Cron Jobs" của bảng điều khiển); những dòng quản
trị máy (`sudo apt …`, `sudo nginx -t`, `sudo systemctl …` ở Bước 1 và 4) là việc của nhà cung cấp —
nhờ họ, hoặc làm qua bảng điều khiển. GIỮ `chmod 600 .env` — viết không `sudo`: `.env` chứa `APP_KEY`
và mọi mật khẩu, người dùng khác trên cùng máy không được đọc. Gói nào chạy PHP bằng một người dùng KHÁC tài khoản SSH mà không có `sudo` thì không cài theo
tài liệu này được: hỏi nhà cung cấp, đừng `chmod 777`.

### Bước 0 — Hỏi chủ văn phòng trước khi bắt đầu

| Cần gì | Dùng ở đâu |
|---|---|
| Có giới hạn IP được vào `/admin` không; có thì những IP/dải nào | `ADMIN_IP_ALLOWLIST` (để trống = tắt, mặc định) |
| Bốn thông tin pháp lý: mã số thuế, Đoàn Luật sư, số Giấy đăng ký hoạt động, địa chỉ văn phòng | `BRAND_TAX_CODE`, `BRAND_BAR_ASSOCIATION`, `BRAND_LICENCE_NUMBER`, `BRAND_OFFICE_ADDRESS` — in ở chân mọi thư gửi khách. Địa chỉ trụ sở đã có sẵn (1808 đường Nguyễn Ái Quốc, phường Trấn Biên, thành phố Đồng Nai — chủ văn phòng cung cấp ngày 2026-10-02); ba thông tin còn lại chờ chủ văn phòng |
| Hộp thư có người đọc, nhận thư khi khách bấm "Trả lời" | `BRAND_REPLY_TO_ADDRESS` |
| Họ tên và email của quản trị viên đầu tiên | Bước 6 |
| Người giữ kho mã nguồn trên GitHub thêm khoá đọc (deploy key) của máy chủ | Bước 2 — kho là kho riêng tư |
| Một tài khoản Google RIÊNG cho sao lưu | `docs/SAO-LUU-KHOI-PHUC.md`, Bước 2 |
| Hai chỗ cất khoá NGOÀI máy chủ (trình quản lý mật khẩu + bản giấy/USB trong két) | Bước 3 và `docs/SAO-LUU-KHOI-PHUC.md`, Bước 6 |
| Vụ việc mới có được lên AI ngay không (mặc định: KHÔNG, bật từng vụ khi khách đã đồng ý bằng văn bản), và tên các bên không phải khách có giả danh không (mặc định: CÓ) | `MCP_MATTER_DEFAULT`, `MCP_PARTY_NAMES` — mục "Máy chủ MCP" bên dưới. Máy chủ MCP luôn TẮT khi cài; chủ văn phòng chỉ bật sau danh sách việc ở `docs/CHINH-SACH-AI.md` |

### Bước 1 — Máy chủ cần có

- **PHP 8.3** chạy qua **PHP-FPM**, với ĐỦ các extension sau — thiếu một cái là `vkcrm:preflight`
  báo ĐỎ:

  `ctype` `curl` `dom` `exif` `fileinfo` `filter` `hash` `iconv` `intl` `json` `libxml`
  `mbstring` `openssl` `pcre` `session` `sodium` `tokenizer` `xmlreader` `zip` `zlib` `pdo_mysql`

  Đây là kết quả `composer check-platform-reqs --no-dev` cộng `pdo_mysql`, và chính là danh sách
  `vkcrm:preflight` kiểm (`config/vkcrm.php`, khoá `deployment.required_extensions`). Hai cái hay
  thiếu nhất trên shared hosting: `intl` (Filament bắt buộc) và `dom` (gói làm sạch HTML, gói ghép
  CSS vào thư, gói đọc/ghi tệp xlsx đều cần). `curl` bắt buộc từ M12 (gói thông báo đẩy
  `minishlink/web-push` cần nó; phần tải tài liệu CIMD của máy chủ MCP cũng dùng hằng số của curl).
  `sodium` là của máy chủ MCP (M11, kết nối AI cho nhân sự): gói ký token mà Passport kéo vào đòi
  nó, thiếu thì `/oauth/token` hỏng, và chỉ hỏng trên máy chủ thật (SPEC §2, đính chính
  2026-10-07). Nên có thêm, chưa bắt buộc: `gd` (preflight báo VÀNG nếu thiếu). Kiểm nhanh:
  `php -m`. Trên **Ubuntu 24.04** một dòng cài đủ PHP, các extension trên (tên gói
  khác tên extension: `dom`, `xmlreader` nằm trong `php8.3-xml`, `pdo_mysql` trong `php8.3-mysql`;
  phần còn lại có sẵn trong `php8.3-common`), `mariadb-client`, Composer 2, Git và nginx — đã chạy
  thử ngày 2026-10-08 trên một máy Ubuntu 24.04 trống, trước khi bản M11 thêm `sodium`; sau khi cài,
  `php -m | grep -i sodium` phải in `sodium` (thiếu thì `composer install` từ chối chạy):
  ```bash
  sudo apt install php8.3-fpm php8.3-cli php8.3-intl php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl php8.3-mysql php8.3-gd mariadb-client composer git nginx
  ```

  `mariadb-client` của Ubuntu 24.04 là bản 10.11; `mariadb-dump` của nó sao lưu được cơ sở dữ liệu
  MariaDB 11 (đo cùng ngày). Bản máy chủ MariaDB 11 không có trong kho gói của Ubuntu 24.04 — xem
  gạch "MariaDB 11" bên dưới.
- **Cấu hình PHP-FPM** (php.ini của FPM, KHÁC tệp php.ini của dòng lệnh — `php -i` chỉ in tệp của
  dòng lệnh; trên Ubuntu xem bản của FPM bằng `php-fpm8.3 -i`):
  - `upload_max_filesize` ≥ `UPLOAD_MAX_MB` (mặc định 20 → `20M`) và `post_max_size` lớn hơn nó
    (ví dụ `25M`). Mặc định của PHP là `2M`/`8M`: để nguyên thì mọi lần tải tệp trên 2 MB hỏng.
  - `proc_open` KHÔNG nằm trong `disable_functions` (sao lưu cần nó để gọi `mariadb-dump` và
    `rclone`; preflight kiểm bản của dòng lệnh — cron chạy sao lưu bằng PHP dòng lệnh).
- **PHP dòng lệnh có `pcntl`, và ba hàm `pcntl_async_signals`, `pcntl_signal`, `pcntl_alarm`
  không bị chặn** (PHP-FPM không cần). Cron rút hàng đợi bằng PHP dòng lệnh, và giờ chết 600 giây
  của job dựng gói bàn giao (mục lịch `queue.handover`) chỉ có tác dụng khi có `pcntl`. Thiếu
  `pcntl` thì `vkcrm:preflight` báo VÀNG: một gói lớn chạy quá giờ không bị dừng, luật sư không được
  báo lỗi, và sau 15 phút lượt chạy kế tiếp có thể dựng lại cùng gói vào cùng thư mục. Có `pcntl`
  mà một trong ba hàm trên nằm trong `disable_functions` của php.ini dòng lệnh (hay gặp trên
  cPanel/CloudLinux) thì nặng hơn nhiều: Laravel chỉ hỏi `pcntl` đã nạp chưa, nên worker vẫn gọi
  các hàm đó ngay khi khởi động và chết với lỗi "Call to undefined function". Mọi lượt rút hàng đợi
  hỏng, không thư nào được gửi, kể cả thư nhắc mốc thời hạn. `vkcrm:preflight` báo ĐỎ trường hợp
  này; bỏ các hàm đó khỏi `disable_functions` của PHP dòng lệnh. `pcntl` không nằm trong danh sách
  bắt buộc ở trên vì `composer check-platform-reqs` không đòi nó. Kiểm bằng đúng PHP mà cron gọi:

  ```bash
  php -r 'echo json_encode([extension_loaded("pcntl"), function_exists("pcntl_async_signals"), function_exists("pcntl_signal"), function_exists("pcntl_alarm")]), PHP_EOL;'
  ```

  Phải in `[true,true,true,true]`. Giá trị đầu là `false`: thiếu `pcntl` (VÀNG). Giá trị đầu là
  `true` mà một giá trị sau là `false`: hàm ở vị trí đó bị chặn (ĐỎ).
- **Chỗ trống trên đĩa cho gói bàn giao.** Khi vụ việc kết thúc, hệ thống tự dựng một tệp zip chứa
  các tài liệu nhóm A, B, C của vụ cùng mục lục, rồi cất nó vào kho hồ sơ (`storage/app/private`).
  Mỗi vụ đã kết thúc vì vậy chiếm thêm chừng bằng dung lượng tài liệu của chính nó, và bản sao lưu
  hằng đêm lớn lên tương ứng. Lúc dựng, gói nằm tạm ở `HANDOVER_WORK_DIR` (mặc định
  `storage/app/handover-tmp`; trỏ sang ổ rộng hơn nếu phần đĩa của `storage/` nhỏ, và không bao giờ
  đặt bên trong `storage/app/private`). `MEDIA_MAX_FILE_SIZE_MB` (mặc định 2048) là trần của một
  tệp trong kho hồ sơ: gói lớn hơn trần thì không sinh được, và trang vụ việc báo lỗi cho luật sư.
- **MariaDB 11** — bản dự án chạy kiểm thử (máy dev và CI đều là `mariadb:11`). MariaDB 10.11 (mặc
  định của Ubuntu 24.04) chưa được chạy thử. Bản 11 lấy từ kho gói của chính MariaDB: trang
  `https://mariadb.org/download/?t=repo-config` chọn hệ điều hành và bản 11.x, rồi làm theo các dòng
  lệnh trang đó in ra (phần cài máy chủ cơ sở dữ liệu này chưa đi thử trong lượt 2026-10-08: lượt đó
  dùng máy chủ `mariadb:11` của dự án). Cơ sở dữ liệu nằm trên máy khác thì đổi `'localhost'` ở
  dưới thành địa chỉ của máy chủ web. Một cơ sở dữ liệu `utf8mb4` riêng và một tài khoản chỉ có quyền
  trên đúng cơ sở dữ liệu đó:

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

#### Máy chủ có gọi ra được máy chủ push không

Thông báo đẩy trên điện thoại (M12) đi từ CHÍNH máy chủ này tới máy chủ push của Google (Android,
Chrome), Apple (iPhone) và Mozilla (Firefox), qua HTTPS cổng 443. Một số shared hosting chặn kết nối
ra ngoài, hoặc chỉ mở tới vài đích — và việc ping giám sát cron (`HEARTBEAT_URL`, Bước 8) chạy được
KHÔNG chứng minh push đi được, vì đích khác nhau. Kiểm trên đúng máy chủ thật, bằng đúng người dùng
chạy PHP (ví dụ thêm `sudo -u www-data` trước mỗi dòng):

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://fcm.googleapis.com/
curl -sS -o /dev/null -w '%{http_code}\n' https://jmt17.google.com/
curl -sS -o /dev/null -w '%{http_code}\n' https://web.push.apple.com/
curl -sS -o /dev/null -w '%{http_code}\n' https://updates.push.services.mozilla.com/
```

- In ra **một mã HTTP bất kỳ** (`404`, `405`, `400`…) là ĐẠT: máy chủ tới được nơi đó. Bốn trang gốc
  này không dành cho trình duyệt, nên một mã "lỗi" là bình thường. Google có hai tên máy cho
  thông báo đẩy: `fcm.googleapis.com` và `jmt17.google.com` (trình duyệt Chromium đo ngày 2026-10-04
  đăng ký trên tên thứ hai) — kiểm cả hai.
- In ra `000`, hoặc `curl: (6) Could not resolve host`, `(7) Failed to connect`, `(28) … timed out`
  là KHÔNG ĐẠT: nhờ nhà cung cấp hosting mở kết nối ra ngoài cổng 443 tới bốn tên máy trên (Apple
  còn dùng các tên máy con dạng `*.push.apple.com`). Chưa mở được thì app trên điện thoại vẫn cài và
  chạy, email vẫn đi, chỉ thông báo đẩy không tới: màn hình **Thư đã gửi** của `/admin` (lọc kênh
  "Thông báo đẩy") ghi một dòng `failed` cho từng máy.

### Bước 2 — Lấy mã nguồn, cài phụ thuộc

**Kho `Harry-Kien/vk-crm` là kho riêng tư.** Người không có quyền trên kho chạy lệnh `git clone`
dưới đây sẽ nhận `Repository not found` (hoặc bị hỏi mật khẩu GitHub, mà GitHub không nhận mật khẩu
cho git nữa). Cách làm: máy chủ có một **deploy key** chỉ đọc, do người giữ kho thêm vào kho (Bước 0).

Mọi lệnh của bước này chạy bằng **người quản trị** (không phải `www-data`, không phải `root`). Thư
mục `/var/www` thuộc về `root`, nên tạo trước thư mục mã nguồn và giao nó cho người quản trị — thiếu
dòng này, `git clone` dừng ở `could not create work tree dir '/var/www/vk-crm': Permission denied`:

```bash
sudo mkdir -p /var/www/vk-crm
sudo chown "$USER": /var/www/vk-crm
```

Deploy key sinh bằng chính người quản trị, nên khoá riêng nằm trong `~/.ssh` của người đó, và mọi
`git pull` khi nâng cấp cũng chạy bằng người đó (`~` trong `core.sshCommand` là thư mục nhà của
người đang gõ lệnh: `git pull` bằng người khác thì nhận `Permission denied (publickey)`):

```bash
ssh-keygen -t ed25519 -C "vk-crm deploy $(hostname)" -f ~/.ssh/vk-crm-deploy -N ""
cat ~/.ssh/vk-crm-deploy.pub
```

Gửi dòng `ssh-ed25519 …` vừa in ra (khoá CÔNG KHAI — tệp `.pub`; tệp không đuôi là khoá riêng, không
gửi cho ai) cho người giữ kho. Người đó vào GitHub → kho `vk-crm` → Settings → Deploy keys → Add
deploy key, dán khoá, **không** tích "Allow write access". Rồi clone qua SSH thay cho dòng `https://`
ở khối lệnh dưới:

```bash
git clone -c core.sshCommand="ssh -i ~/.ssh/vk-crm-deploy -o IdentitiesOnly=yes" git@github.com:Harry-Kien/vk-crm.git /var/www/vk-crm
```

`-c core.sshCommand=…` lưu vào `.git/config` của bản clone, nên mọi `git pull` khi nâng cấp dùng lại
đúng khoá đó. Người đã có quyền trên kho (tài khoản GitHub được mời) dùng thẳng dòng `https://` dưới đây.

```bash
git clone https://github.com/Harry-Kien/vk-crm.git /var/www/vk-crm
cd /var/www/vk-crm
cp .env.example .env
composer install --no-dev --optimize-autoloader
```

- `cp .env.example .env` TRƯỚC `composer install`: sau khi cài, composer tự gọi `php artisan
  package:discover` và `php artisan filament:upgrade` (mục `post-autoload-dump` của
  `composer.json`), và hai lệnh đó cần có `.env`. Lần cài đầu chúng chạy được bằng người quản trị vì
  `storage/` và `bootstrap/cache/` còn thuộc về người đó; khi nâng cấp thì không — chuỗi "Nâng cấp
  lên bản mới" tách chúng ra, chạy bằng đúng người.
- `filament:upgrade` chép tài sản giao diện của Filament vào `public/css/filament/`,
  `public/js/filament/`, `public/fonts/filament/` (không nằm trong kho — `.gitignore`). **Không có
  bước dựng giao diện**: không `npm`, không `vite build`; Livewire tự phục vụ tệp script của nó qua
  đường `/livewire-…/livewire.min.js` (mẫu nginx có khối riêng cho đúng đường này — Bước 4).
- `--no-dev`: không cài công cụ kiểm thử lên máy chủ thật.

Thư mục `storage/` và `bootstrap/cache/` phải GHI ĐƯỢC bởi người dùng chạy PHP-FPM; phần còn lại
của mã nguồn thì không cần. `.env` thuộc về `www-data` và chỉ `www-data` đọc được (nó chứa `APP_KEY`
và mọi mật khẩu):

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chown www-data:www-data .env
sudo chmod 600 .env
```

Chạy ba dòng này SAU `composer install`. Dòng đầu chạy lại sau MỖI lần `composer install` (kể cả khi
nâng cấp): lệnh `php artisan` nào chạy bằng người khác tạo tệp trong `bootstrap/cache/` và `storage/`
mang chủ là người đó, và PHP-FPM không ghi đè được tệp của người khác.

Từ đây, **sửa `.env` bằng `sudo -u www-data nano .env`** (hay `sudoedit .env`), để chủ và quyền của
tệp giữ nguyên. Lượt đi thử ngày 2026-10-08 vấp hai lần ở đây: `.env` còn thuộc người quản trị thì
`sudo -u www-data php artisan key:generate` dừng ở `file_put_contents(/var/www/vk-crm/.env): Failed
to open stream: Permission denied`; và khi `www-data` không đọc được `.env` (tệp bị người quản trị ghi
lại và mất quyền đọc của `www-data`), mọi lệnh chạy như chưa có `.env` — ví dụ `Database file at path
[/var/www/vk-crm/database/database.sqlite] does not exist`. Gặp một trong hai thì chạy lại hai dòng
`chown`/`chmod` của `.env` ở trên.

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

Ngay sau đó, sinh **khoá Passport** — cặp khoá RSA ký access token cho kết nối AI của nhân sự (máy
chủ MCP, M11; đọc mục "Máy chủ MCP (kết nối AI cho nhân sự)" ở cuối phần này):

```bash
php artisan passport:keys
```

Lệnh tạo `storage/oauth-private.key` (quyền 600) và `storage/oauth-public.key`, mang chủ là người
chạy lệnh — vì vậy chạy bằng người dùng của PHP-FPM, như mọi lệnh `php artisan` ở đây. **Cùng luật với
`APP_KEY`**: chỉ sinh ở lần cài đầu; dựng lại máy cho dữ liệu đã có thì chép hai tệp CŨ về
`storage/`; cất chúng cùng chỗ và cùng quy trình với `APP_KEY` (hai nơi ngoài máy chủ, không cùng chỗ
bản sao lưu — bản sao lưu hằng đêm không chứa `storage/oauth-*.key`). Đừng nới quyền tệp khoá riêng:
ai đọc được nó là tự ký được access token mang tên bất kỳ nhân sự nào, và `vkcrm:preflight` báo ĐỎ.
Máy chủ không giữ được tệp (một số shared hosting) thì dán NỘI DUNG hai khoá vào
`PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` của `.env`, xuống dòng viết là `\n`.

Rồi mở `.env` và điền. Mọi biến đều có sẵn một dòng trong `.env.example`, kèm một câu nói giá trị
thật lấy ở đâu (`tests/Feature/Deployment/EnvExampleTest.php` giữ cho điều đó luôn đúng: một biến
mới mà quên dòng mẫu là test đỏ).

**Giá trị có dấu cách phải nằm trong ngoặc kép.** Bốn thông tin pháp lý, tên văn phòng, địa chỉ là
chuỗi tiếng Việt có dấu cách; viết thiếu ngoặc kép thì cả tệp `.env` bị từ chối — mọi lệnh
`php artisan` và mọi trang chết với `The environment file is invalid!`. Viết đúng, dạng:

```
BRAND_BAR_ASSOCIATION="Đoàn Luật sư tỉnh Đồng Nai"
```

(tên đoàn luật sư thật do chủ văn phòng cung cấp — Bước 0; dòng trên chỉ là dạng viết).

Những dòng PHẢI sửa so với bản mẫu — bản mẫu là cho máy dev:

| Biến | Giá trị trên máy chủ thật | Lấy ở đâu |
|---|---|---|
| `APP_ENV` | `production` | — (seed, sao lưu, HTTPS, CSP đều đổi hành vi theo biến này) |
| `APP_DEBUG` | `false` | — (`true` thì trang lỗi in ra cấu hình; preflight ĐỎ) |
| `APP_URL` | `https://khachhang.luatvukhang.com` | tên miền thật, có `https://` |
| `LOG_LEVEL` | `warning` | — (`debug` của bản mẫu ghi quá nhiều) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `127.0.0.1`, `3306`, `vk_crm`, `vk_crm`, mật khẩu ở Bước 1 | người quản trị cơ sở dữ liệu / bảng điều khiển hosting |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` | máy chủ SMTP của tên miền văn phòng; `MAIL_SCHEME` chỉ nhận `smtps` (cổng 465) hoặc `smtp`/`null` (cổng 587 hay 25 — STARTTLS tự bật khi máy chủ có) — đọc mục 4 ngay dưới bảng | nhà cung cấp email của tên miền (cần SPF/DKIM cho `MAIL_FROM_ADDRESS`) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `no-reply@luatvukhang.com`, `"Luật Vũ Khang"` | `MAIL_FROM_NAME` là tên văn phòng khách nhìn thấy, không phải `${APP_NAME}` |
| `TRUSTED_PROXIES` | `127.0.0.1` (bỏ dấu `#` đầu dòng) | đọc mục 1 ngay dưới bảng — KHÔNG dùng `*` |
| `HEARTBEAT_URL` | URL ping của dịch vụ giám sát cron | Bước 8 |
| `BRAND_TAX_CODE`, `BRAND_BAR_ASSOCIATION`, `BRAND_LICENCE_NUMBER`, `BRAND_OFFICE_ADDRESS` | bốn thông tin pháp lý (bỏ dấu `#` đầu dòng rồi điền); `BRAND_OFFICE_ADDRESS` đã có mặc định đúng địa chỉ trụ sở, chỉ điền khi đổi | chủ văn phòng (Bước 0) |
| `BRAND_REPLY_TO_ADDRESS` | hộp thư có người đọc | chủ văn phòng — đọc mục 3 ngay dưới bảng |
| `BACKUP_*` | | `docs/SAO-LUU-KHOI-PHUC.md`, Bước 4 |
| `ADMIN_IP_ALLOWLIST` | để trống, hoặc danh sách IP/CIDR | chủ văn phòng (Bước 0) |
| `MCP_MATTER_DEFAULT`, `MCP_PARTY_NAMES` | giữ `denied`, `pseudonym` của bản mẫu, trừ khi chủ văn phòng quyết khác | chủ văn phòng (Bước 0) — mục "Máy chủ MCP" bên dưới |
| `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | `mailto:` + hộp thư có người đọc; cặp khoá do lệnh sinh | mục "Khoá thông báo đẩy" ngay dưới — để trống thì thông báo đẩy trên điện thoại TẮT êm |

Để TRỐNG (đúng giá trị của bản mẫu) là chặt nhất, không cần điền gì: `SESSION_SECURE_COOKIE`,
`FORCE_HTTPS`, `HSTS_MAX_AGE` (ba biến tự bật ở mọi môi trường trừ `local`/`testing`), `CSP_MODE`
(trống ở production là `enforce`). `HSTS_INCLUDE_SUBDOMAINS`, `HSTS_PRELOAD` để trống — bật chúng
khoá luôn website `luatvukhang.com` và mọi tên miền con khác vào https. Giữ nguyên
`SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`: dự án không dùng
Redis, và lịch chạy rút hàng đợi `database` mỗi phút (Bước 8). Các biến `BRAND_*` còn lại (tên,
khẩu hiệu, hotline, Zalo, website…) đang là dòng chú thích mang giá trị mặc định — chỉ bỏ dấu `#`
và sửa khi văn phòng đổi; **đừng để `BRAND_…=` trống**: một dòng trống là chuỗi rỗng, không phải
"dùng mặc định", và tên văn phòng biến mất khỏi trang đăng nhập lẫn thư.

Bốn điều cần nói rõ hơn một dòng bảng:

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
4. **`MAIL_SCHEME` không nhận `tls` hay `ssl`** — dù bảng điều khiển email của nhà cung cấp ghi
   "Mã hoá: TLS/SSL" và nhiều hướng dẫn cũ viết `MAIL_ENCRYPTION=tls`. Biến này chỉ có ba giá trị:
   `smtps` cho cổng 465 (SSL ngay từ đầu), `smtp` hoặc để `null` cho cổng 587/25 (máy chủ có STARTTLS
   thì kết nối tự mã hoá). Ghi `tls` hay `ssl` thì MỌI thư hỏng với `The "tls" scheme is not supported;
   supported schemes for mailer "smtp" are: "smtp", "smtps".` (đo ngày 2026-10-08: lỗi xảy ra ngay
   lúc dựng kết nối thư, trước khi gửi được thư nào). `vkcrm:preflight` báo ĐỎ trường hợp này.

**Từ M7 Task 10, chủ văn phòng sửa bốn thông tin pháp lý (cùng tên pháp lý, hotline, Zalo,
website, email liên hệ) ngay trong ứng dụng** — trang "Thông tin văn phòng" trong `/admin`, chỉ
quản trị viên — không cần quyền vào máy chủ. Giá trị nhập trong ứng dụng thắng giá trị trong
`.env`; ô để trống thì dùng `.env` (rồi mặc định của `config/vkcrm.php`). Chạy `php artisan
migrate --force` (Bước 5) TRƯỚC khi mở lại web và hàng đợi: chân trang cổng, trang 404 và mọi thư
đều đọc bảng `settings`.

#### Khoá thông báo đẩy (VAPID) — app trên điện thoại

Thông báo đẩy trên điện thoại (M12) ký mỗi lần gửi bằng một cặp khoá VAPID. Sinh **MỘT lần cho mỗi
môi trường** (máy chủ thật một cặp, máy chủ thử một cặp khác), trên chính máy chủ đó:

```bash
php artisan config:clear
php artisan webpush:vapid
```

- `config:clear` phải đứng TRƯỚC: lệnh sinh khoá dò dòng cũ trong `.env` theo khoá đang có trong
  CẤU HÌNH. Cấu hình đã cache (sau lệnh `optimize` của Bước 7) mà không khớp `.env` — ví dụ cache
  lúc khoá còn trống, rồi `.env` có khoá — thì lệnh ghi đè hỏng dòng: `VAPID_PUBLIC_KEY=cu` thành
  `VAPID_PUBLIC_KEY=moicu`.
- Chỉ chạy khi hai dòng `VAPID_PUBLIC_KEY=` và `VAPID_PRIVATE_KEY=` trong `.env` **còn trống** (đúng
  như `.env.example`). Lệnh tự điền hai dòng đó. Ngoài `production` lệnh GHI ĐÈ khoá đang có mà
  không hỏi; ở `production` nó hỏi lại — trả lời **không** nếu đã có người bật thông báo.
- Rồi mở `.env`, điền dòng thứ ba bằng hộp thư có người đọc của văn phòng (máy chủ push của Apple
  từ chối khi thiếu): `VAPID_SUBJECT=mailto:lienhe@luatvukhang.com`.
- Lần cài đầu: đi tiếp Bước 4; `vkcrm:preflight` ở Bước 7 kiểm cả ba biến. Sinh khoá trên một máy
  chủ ĐANG CHẠY (nâng cấp lên bản có M12): chạy tiếp `php artisan vkcrm:preflight` rồi
  `php artisan optimize`.
- **`VAPID_PRIVATE_KEY` là bí mật cùng hạng với `APP_KEY`**: cất cả cặp (`VAPID_PUBLIC_KEY` và
  `VAPID_PRIVATE_KEY`) cùng chỗ với `APP_KEY` — `docs/SAO-LUU-KHOI-PHUC.md`, Bước 6. Không commit,
  không gửi qua thư. Khoá không nằm trong bản sao lưu (`.env` không được sao lưu).
- **Mất hay đổi khoá riêng thì mọi đăng ký trên mọi điện thoại chết im lặng** (máy chủ push trả
  401/403, không tự dọn). Sau MỖI lần đổi khoá chạy `php artisan vkcrm:push-reset` (xoá mọi đăng
  ký, ghi nhật ký; mọi người bật lại thông báo trên từng máy), rồi báo nhân sự và khách.
- Để trống cả ba biến cũng được: app trên điện thoại vẫn cài và chạy, email vẫn đi, chỉ thông báo
  đẩy tắt (không nút bật, không gửi gì) — `vkcrm:preflight` báo VÀNG.

### Bước 4 — Máy chủ web

Dùng mẫu ĐÃ CHẠY THỬ, sửa tên miền, đường dẫn chứng chỉ và đường dẫn dự án:

- nginx: `tools/deploy/nginx.conf.example` (cùng socket PHP-FPM `/run/php/php8.3-fpm.sock` —
  sửa nếu máy chủ khác). Trên Ubuntu: chép mẫu vào `/etc/nginx/sites-available/vk-crm`, nối nó vào
  `/etc/nginx/sites-enabled/`, bỏ `/etc/nginx/sites-enabled/default`, rồi `sudo nginx -t` và
  `sudo systemctl reload nginx`. Mẫu bật HTTP/2 bằng dòng `http2 on;` — cú pháp của nginx từ bản
  1.25.1. **nginx của Ubuntu 24.04 là bản 1.24**: `nginx -t` dừng ở `unknown directive "http2"`. Với
  nginx cũ hơn 1.25.1, xoá dòng `http2 on;` và viết HTTP/2 vào hai dòng `listen` của khối 443:
  `listen 443 ssl http2;` và `listen [::]:443 ssl http2;` (đã chạy thử trên nginx 1.24 ngày 2026-10-08);
- Apache: `tools/deploy/apache-vhost.conf.example` (cần `a2enmod ssl rewrite headers alias`). Mẫu
  không tự nối PHP: dùng mod_php, hoặc PHP-FPM qua `proxy_fcgi` — trên Ubuntu
  `a2enmod proxy_fcgi setenvif` rồi `a2enconf php8.3-fpm`.

Hai mẫu cùng làm sáu việc — đừng bỏ việc nào khi chép sang cấu hình khác:

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
6. **`/.well-known/` đi vào Laravel** (M11): luật chặn dotfile của mục 4 chừa ĐÚNG đoạn đầu
   `/.well-known/` (`location ~ /\.(?!well-known/)` / `RedirectMatch 404 "/\.(?!well-known/)"`), vì
   bốn route metadata OAuth của máy chủ MCP nằm ở đó, và Claude hay ChatGPT đọc chúng trước khi cho
   nhân sự đăng nhập. Luật cũ `location ~ /\.` / `RedirectMatch 404 "/\."` chặn luôn chúng: `/mcp`
   vẫn trả 401 đúng, nhưng không ai kết nối được. Kiểm trên máy chủ thật:
   `curl -s https://<tên miền>/.well-known/oauth-protected-resource/mcp` phải in JSON có `"resource"`,
   còn `curl -I https://<tên miền>/.well-known/.env` vẫn phải là `404`.
7. **Đưa service worker của hai app trên điện thoại tới PHP** (M12). `/admin/sw.js` và
   `/portal/sw.js` là ROUTE PHP mang đuôi `.js`: thiếu hai khối `location = /admin/sw.js` và
   `location = /portal/sw.js` của mẫu nginx, khối tệp tĩnh trả 404 thẳng từ nginx — app không cài
   được và không bật được thông báo, dù mọi trang vẫn "lên". Mẫu Apache không cần khối riêng (đi qua
   `.htaccess`). Kiểm bằng request thật: `bash tools/deploy/verify-pwa-routes.sh` (cần Docker, chạy
   trên máy dev với chính mẫu đã sửa); trên máy chủ thật `curl -I https://<tên miền>/portal/sw.js`
   phải là `200` kèm `Service-Worker-Allowed: /portal`.

**HTTPS là bắt buộc cho app trên điện thoại**, không chỉ cho đăng nhập: trình duyệt chỉ chạy
service worker, chỉ cho cài app và chỉ cho bật thông báo đẩy trên `https://` (ngoại lệ duy nhất là
`localhost` của máy dev). Trên `http://` mọi trang vẫn mở, nhưng nút cài và nút bật thông báo không
bao giờ hiện.

Shared hosting không cho sửa cấu hình máy chủ web: trỏ document root (tên miền/tên miền con) vào
`public/` trong bảng điều khiển, bật "Force HTTPS" nếu có, và kiểm mục 4 và mục 6 bằng `curl` như
trên — `EnforceHttps` lo phần HTTPS/HSTS dự phòng. Một số bảng điều khiển (cPanel với AutoSSL…) tự
giữ `/.well-known/` cho việc xin chứng chỉ: nếu lệnh `curl` của mục 6 trả `404` hay một trang của
hosting thay vì JSON, nhờ nhà cung cấp chuyển `/.well-known/oauth-*` vào `public/index.php`; không
làm được thì không bật kết nối AI trên gói hosting đó.

**Không đệm `/mcp`.** Máy chủ MCP trả JSON thường, không luồng, nên hai mẫu không cần khối riêng cho
`/mcp`. Nếu sau này một tool trả luồng (SSE), laravel/mcp tự gửi `X-Accel-Buffering: no` cho phản hồi
đó và nginx tôn trọng nó theo mặc định — đừng thêm `fastcgi_ignore_headers X-Accel-Buffering`, và
đừng bật đệm phản hồi cho `/mcp` ở một proxy/CDN đứng trước.

### Bước 5 — Cơ sở dữ liệu: migrate và seed dữ liệu tham chiếu

**Seed đúng lệnh — KHÔNG chạy `migrate:fresh --seed` như bước "Bốn bước" của máy dev.** Lệnh đó
XOÁ mọi bảng rồi gọi `DatabaseSeeder`, và trên `APP_ENV=production` (`.env` của máy chủ thật phải
đặt vậy) seeder CHỈ tạo dữ liệu tham chiếu (vai trò, quyền, 12 loại vụ việc, giai đoạn, danh mục
hồ sơ mẫu) — không có admin, không có tài khoản demo mật khẩu `password` nào (M6.5 Task 19; trước bản
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
  mục) chỉ được tạo khi loại đó CHƯA có danh mục mẫu nào, bất kể tên, kể cả đã xoá (rà soát cuối
  làn M9, C1: vụ mới nhận mẫu đang dùng mới nhất của loại, nên một mẫu seed chèn cạnh mẫu văn
  phòng tự soạn sẽ thay chỗ nó). Tên, "Đang dùng", nhãn và mô tả giai đoạn, danh mục và đầu mục
  đã soạn, sửa hay xoá — mọi thứ quản trị viên đã chỉnh — không bao giờ bị ghi đè, khôi phục
  hay thay bằng mẫu seed. Muốn lấy lại cấu hình mặc định của một loại thì phải sửa tay.

**Bản cập nhật M9 (mười hai lĩnh vực) làm gì trên máy chủ đã có dữ liệu** — để quản trị viên
biết trước ô chọn loại vụ việc sẽ hiện gì:

- `migrate --force` chạy một lần migration dữ liệu
  `2026_09_30_000001_rename_matter_types_to_office_names`: đổi tên bốn loại `DD`, `DN`, `DS`,
  `LD` sang tên lĩnh vực của văn phòng, **chỉ khi** tên hiện tại còn đúng từng ký tự bằng tên seed
  cũ; loại đã được đổi tên tay thì giữ nguyên. Mã loại không đổi.
- `db:seed --force` thêm sáu loại mới `HC`, `TM`, `NH`, `SH`, `TC`, `XD`, đang dùng, với bộ năm
  giai đoạn **TẠM** (ghi "TẠM" ở mô tả loại) chờ chủ văn phòng mô tả quy trình thật; mã nào văn
  phòng đã tự tạo thì bỏ qua. Loại nào chưa có danh mục hồ sơ mẫu nào thì nhận một danh mục tối
  thiểu.
- Văn phòng đã tự tạo một loại cùng lĩnh vực nhưng **mã khác** (ví dụ "Thuế" mã `TH`) thì sau
  bản cập nhật ô chọn có hai mục gần giống nhau. Quản trị viên tắt "Đang dùng" ở một trong hai
  trong màn hình loại vụ việc; seeder không tự gộp hay xoá loại nào.

**Bản cập nhật M9 (hợp đồng dịch vụ và thu phí theo đợt) làm gì trên máy chủ đã có dữ liệu:**

- `migrate --force` chỉ THÊM năm bảng (`contracts`, `instalments`, `payments`,
  `contract_amendments`, `time_entries` — bảng cuối chỉ là khung, chưa màn hình nào đọc), không
  sửa cấu trúc bảng nào đã có.
- `db:seed --force` (`ReferenceDataSeeder`) tạo bốn quyền mới — `billing.view`, `contract.manage`,
  `payment.record`, `revenue.viewAny` — và gắn chúng vào vai trò theo bảng SPEC §5. **Bắt buộc**:
  chưa chạy thì không ai, kể cả quản trị viên, mở được trang Công nợ, trang Doanh thu hay tab
  "Hợp đồng và thanh toán". Không tạo hợp đồng hay khoản thu nào: tiền mẫu (`BillingSeeder`) chỉ
  nằm trong `DemoDataSeeder`.
- Hai tác vụ hằng ngày mới chạy dưới dòng cron sẵn có (không thêm dòng cron nào):
  `instalments.reconcile-stage` 07:00 (đối chiếu đợt thu theo giai đoạn — lưới an toàn cho lần
  kích hoạt lỡ) và `instalments.remind` 08:00 (thư nội bộ khi một đợt quá hạn).
- `php artisan billing:check-invariants` (và dòng "bất biến tiền" của `vkcrm:preflight`): tổng
  các đợt khớp đúng giá trị hợp đồng trên mọi hợp đồng đang hiệu lực. Trên một máy vừa nâng cấp
  chưa có hợp đồng nào thì nó báo sạch trên 0 hợp đồng.
- Văn phòng nhập những hợp đồng đang chạy từ trước theo `docs/QUY-TRINH.md`, Giai đoạn 5, "Nhập
  hợp đồng đang chạy khi bắt đầu dùng hệ thống" — đặc biệt luật "đợt của giai đoạn đã qua nhập là
  đến hạn theo ngày".

**Bản cập nhật M10 (tiếp nhận) làm gì trên máy chủ đã có dữ liệu:**

- `migrate --force` chỉ THÊM hai bảng mới và hai cột của một trong hai bảng đó, không sửa bảng nào
  đã có — bốn migration: `2026_09_30_000001_create_intake_requests_table` (bảng `intake_requests`,
  mỗi lần có người liên hệ văn phòng là một dòng), `2026_09_30_000002_create_intake_parties_table`
  (bảng `intake_parties`, các bên đối lập người liên hệ kể),
  `2026_10_01_000001_add_conflict_red_pending_since_to_intake_requests_table` và
  `2026_10_04_000001_add_merge_chain_matter_id_to_intake_requests_table` (hai cột của
  `intake_requests`; migration sau điền ngược cho những bản ghi đã gộp mà chuỗi của chúng đã thành vụ
  việc — máy chủ chưa có bản ghi tiếp nhận nào thì không có gì để điền).
- `db:seed --force` (`ReferenceDataSeeder`) tạo ba quyền mới — `intake.create`, `intake.viewAny`,
  `intake.convert` — và gắn chúng vào vai trò theo bảng SPEC §5: quản trị viên và quản lý cả ba, luật
  sư `intake.create` và `intake.convert`, trợ lý `intake.create`, kế toán không quyền nào.
  **Bắt buộc**: chưa chạy thì menu **Tiếp nhận** không hiện với ai, kể cả quản trị viên (mở thẳng
  đường dẫn cũng ra 404), và trang "Bức tranh đầu vào" cùng widget "Liên hệ chưa ai gọi lại" cũng
  vắng. Không tạo bản ghi tiếp nhận nào: dữ liệu mẫu tiếp nhận (`IntakeSeeder`) chỉ nằm trong
  `DemoDataSeeder`.
- Hai tác vụ mới chạy dưới dòng cron sẵn có (không thêm dòng cron nào):
  - `intakes.remind-unanswered`, mỗi 15 phút: một lần liên hệ còn ở "Mới" quá ngưỡng phản hồi
    (`INTAKE_RESPONSE_HOURS` giờ làm việc, dưới) thì người được giao — không có ai thì trưởng phòng
    hay quản trị viên — nhận thư nội bộ và chuông, không mang dữ liệu của người liên hệ. Giờ làm việc
    là Thứ Hai–Thứ Sáu 08:00–17:30 theo `APP_TIMEZONE` (`config/vkcrm.php`, khoá `business_hours`;
    ngày lễ chưa được trừ ra); ngoài giờ đó mỗi lượt không làm gì.
  - `prospects.anonymise`, 03:30 hằng ngày: **ẩn danh — không hoàn tác được —** người liên hệ KHÔNG
    thành khách đã quá hạn lưu, tức bản ghi "Văn phòng từ chối", "Khách không theo tiếp" hay "Đã gộp
    vào bản ghi khác", chưa thành vụ việc, đã qua ngày hạn của nó. Tên, số điện thoại, email, số căn
    cước, câu chuyện, các bên đối lập bị xoá khỏi hệ thống; mã, nguồn, trạng thái và các mốc thời gian
    ở lại để thống kê. Chỉ các bản sao lưu cũ còn giữ dữ liệu đó, cho tới khi chúng bị dọn.
- Hai biến `.env` tuỳ chọn — không đặt thì dùng mặc định, nên bản nâng cấp không bắt buộc sửa `.env`
  (sửa thì chạy lại `optimize:clear`, `vkcrm:preflight`, `optimize` như Bước 7):
  - `PROSPECT_RETENTION_MONTHS` — số tháng giữ dữ liệu người liên hệ không thành khách, mặc định 24
    (`.env.example` ghi sẵn 24; trống, 0, số âm, chữ hay quá 1200 cũng về 24). Hạn của mỗi bản ghi tính lúc
    bản ghi vào một trong ba trạng thái trên (hôm đó cộng số tháng này; gộp đi một bản đã từ chối hay
    đã mất liên lạc thì tính lại từ ngày gộp, theo con số đang đặt hôm đó); đổi biến sau đó không dời
    hạn của bản ghi đã có. Bản ghi còn mở hoặc đã thành vụ việc không có hạn. Con số 24 là mặc định
    của kế hoạch M10, **chưa được luật sư xác nhận**: xác nhận với luật sư TRƯỚC khi nhân sự bắt đầu
    dùng màn hình Tiếp nhận, không đợi tới lúc bản ghi đầu tiên tới hạn — bản ghi đã đóng trước khi
    đổi biến giữ hạn cũ, và lượt 03:30 ẩn danh nó đúng hạn đó mà không hỏi ai. Câu thông báo đọc cho
    người gọi (bản nháp `2026-09-nhap`) đọc chính con số này — đổi biến thì câu tự đổi theo, và phiên
    bản ghi kèm mỗi lần ghi nhận mang thêm số tháng (ví dụ `2026-09-nhap-36t`), nên nút "Ghi nhận
    thông báo" hiện lại trên bản ghi chưa đóng. KHÔNG sửa tệp ngôn ngữ trên máy chủ: lần `git pull`
    sau xung đột hoặc ghi đè chỗ sửa; câu chữ đổi qua kho mã.
  - `INTAKE_RESPONSE_HOURS` — ngưỡng phản hồi lần đầu, tính bằng giờ làm việc, mặc định 4
    (`.env.example` để trống; trống, 0, số âm hay chữ cũng về 4).

**Bản cập nhật M13 (theo dõi đội ngũ) làm gì trên máy chủ đã có dữ liệu:**

- `migrate --force` thêm một bảng và một index, không sửa cột hay dữ liệu nào đã có — hai migration:
  `2026_10_04_130000_create_performance_snapshots_table` (bảng `performance_snapshots`, ảnh chụp số
  "bây giờ" của từng người mỗi tối, để vẽ xu hướng) và
  `2026_10_07_090000_add_event_created_at_index_to_activity_log_table` (index `(event, created_at)` trên
  bảng nhật ký `activity_log`, cho cột "Giấy tờ đã duyệt" của trang "Hiệu suất theo kỳ"; trên một nhật ký
  vài trăm nghìn dòng, dựng index mất vài giây).
- `db:seed --force` (`ReferenceDataSeeder`) tạo quyền mới `performance.viewAny` và gắn cho quản trị viên
  và quản lý (SPEC §5). **Bắt buộc**: chưa chạy thì trang **Theo dõi đội ngũ** không hiện với ai, kể cả
  quản trị viên (mở thẳng đường dẫn cũng ra 404), và trên trang **Hiệu suất theo kỳ** mỗi người chỉ thấy
  dòng của chính mình. Luật sư và trợ lý không cần quyền mới: mục **Việc của tôi** và dòng của chính họ
  đi theo quyền xem vụ việc sẵn có. Kế toán không mở được trang nào trong ba trang.
- Một tác vụ mới chạy dưới dòng cron sẵn có (không thêm dòng cron nào): `performance.snapshot`, 23:50
  hằng ngày, ghi số "bây giờ" của từng người được theo dõi (vụ quá hạn cập nhật, mốc quá hạn, mức hoàn
  thiện danh mục) và xoá ảnh chụp cũ hơn 25 tháng. Không gửi thư, không thông báo.
- **Xu hướng bắt đầu từ ngày nâng cấp:** biểu đồ xu hướng trên trang của từng người trống cho tới đêm
  đầu tiên, và cột "Xu hướng" của trang "Hiệu suất theo kỳ" in "—" cho mọi ngày trước đó. Hệ thống không
  dựng ảnh chụp ngược cho quá khứ.
- **Lịch sử "ai giữ việc lúc nào" chỉ đầy đủ từ ngày nâng cấp** cho hai thứ: mốc thời hạn bị chuyển khi
  bàn giao vụ (trước đó lần bàn giao chỉ ghi SỐ mốc đã chuyển), và yêu cầu của khách được giao ĐÍCH DANH
  cho luật sư cũ. Với các tháng trước ngày nâng cấp, những mốc, luồng đó tính cho người đang giữ chúng.
  Luồng yêu cầu chưa giao ai (gần như mọi luồng) thì đủ, nhờ dòng "bàn giao vụ việc" có từ trước. Vài
  tháng đầu, tỉ lệ đúng hạn của người từng nhận bàn giao hàng loạt có thể thấp hơn thật.
- Trang Nhật ký hệ thống có thêm dòng "Xem số liệu hiệu suất của nhân sự" mỗi lần một người mở số của
  người khác. Số liệu hiệu suất theo người là dữ liệu cá nhân của nhân sự: thông báo cho nhân sự (nội
  quy, hợp đồng lao động) là câu hỏi cho luật sư của văn phòng, TRƯỚC khi dùng trang này để đánh giá.
- Bản này không có biến `.env` mới.

**Bản cập nhật M12 (app trên điện thoại và thông báo đẩy) làm gì trên máy chủ đã có dữ liệu** — các
bước tay theo thứ tự nằm ở mục "Bản cập nhật M12" của "Nâng cấp lên bản mới"; đoạn này nói mỗi bước
làm gì với máy chủ:

- `migrate --force` chỉ THÊM một bảng, `push_subscriptions` (mỗi điện thoại hay máy tính đã bật
  thông báo là một dòng), không sửa bảng nào đã có — hai migration:
  `2026_10_03_000001_create_push_subscriptions_table` và
  `2026_10_03_000002_add_device_label_and_last_seen_at_to_push_subscriptions_table`. Bảng rỗng sau
  nâng cấp: chưa ai bật thông báo.
- `db:seed --force` không có gì mới: M12 không thêm quyền nào.
- **Khoá thông báo đẩy (VAPID)** sinh MỘT lần cho máy chủ này theo mục "Khoá thông báo đẩy (VAPID)"
  ở Bước 3 (`config:clear`, `webpush:vapid`, điền `VAPID_SUBJECT`, rồi cất `VAPID_PRIVATE_KEY` cùng
  chỗ với `APP_KEY`). Chưa sinh thì app vẫn cài và chạy, email vẫn đi, chỉ thông báo đẩy tắt.
- **Dòng cron giữ nguyên** (Bước 8). Hai mục lịch mới chạy dưới chính dòng `schedule:run` đó:
  `queue.push` mỗi phút, chạy nền (rút hàng đợi thông báo đẩy, tách khỏi hàng thư) và
  `push-subscriptions.prune` lúc 03:30 (dọn đăng ký của tài khoản đã vô hiệu hay đã xoá và của máy
  không mở ứng dụng quá 180 ngày).
- **nginx:** hai khối `location = /admin/sw.js` và `location = /portal/sw.js` của
  `tools/deploy/nginx.conf.example` mới phải có trong cấu hình đang chạy — thiếu thì mẫu cũ trả 404
  cho hai tệp đó và app không cài được (Bước 4, việc 6). Apache không phải sửa gì.
- **`vkcrm:preflight` có hai dòng của M12** (Bước 7): thiếu extension `curl` là ĐỎ (dòng extension
  bắt buộc — kiểm TRƯỚC `git pull`, vì `composer install` của bản mới từ chối cài khi thiếu nó); thiếu
  hay sai khoá thông báo đẩy là VÀNG, không chặn `php artisan up`.

**Muốn dữ liệu mẫu để demo cho khách trước khi dùng thật** (không phải dữ liệu thật) — đọc hết
đoạn này TRƯỚC khi chạy lệnh. Dữ liệu mẫu có tám tài khoản nhân sự, cùng mật khẩu `password`,
và CHƯA tài khoản nào có 2FA: `admin@luatvukhang.com` (Quản trị viên), `quanly@luatvukhang.com`,
`luatsu1@luatvukhang.com`, `luatsu2@luatvukhang.com`, `luatsu3@luatvukhang.com`,
`troly1@luatvukhang.com`, `troly2@luatvukhang.com`, `ketoan@luatvukhang.com`. Secret 2FA demo
chỉ được gán ở `local`/`testing`, nên ở đây `/admin` bắt mỗi tài khoản tự cài 2FA ở lần đăng
nhập đầu — và **ai đăng nhập TRƯỚC thì app xác thực của CHÍNH NGƯỜI ĐÓ được gắn vào tài khoản**
(trust-on-first-use: hệ thống tin người đầu tiên tới, không hỏi người đó là ai). Email và mật
khẩu này nằm công khai trong mã nguồn và trong chính tài liệu này. Trên một tên miền mở ra
Internet, một người lạ đăng nhập `admin@luatvukhang.com` trước văn phòng là **chiếm trọn quyền
quản trị** — tạo nhân sự, đọc mọi vụ việc, gửi thư cho khách bằng hộp thư và chân thư của văn
phòng — còn văn phòng thì bị khoá ngoài chính tài khoản đó, vì mã 2FA nằm trong điện thoại của
người kia. Bảy tài khoản còn lại cũng vậy, mỗi cái một vai. (Từ M13 dữ liệu mẫu có thêm một luật sư
"đã nghỉ việc", `luatsu4@luatvukhang.com`, để trang "Hiệu suất theo kỳ" có một lần bàn giao khi nghỉ
việc: tài khoản đó đã bị vô hiệu hoá và mang mật khẩu ngẫu nhiên, không đăng nhập được.)

Vì vậy dữ liệu mẫu chỉ được nạp khi người ngoài văn phòng không mở được `/admin`. Lệnh
`php artisan vkcrm:preflight` (Bước 7) giữ đúng luật này trong mã: còn tài khoản nào ở trên dùng
mật khẩu `password` mà `ADMIN_IP_ALLOWLIST` trống thì dòng "tài khoản nhân sự demo" ĐỎ (nêu đích
danh từng email), có allowlist thì VÀNG cho tới khi chạy chuỗi "Hết demo, chuyển sang dùng thật"
bên dưới.

1. **Bắt buộc: `ADMIN_IP_ALLOWLIST` đã đặt IP văn phòng** (bảng biến ở Bước 3; đã chạy Bước 7
   thì sửa `.env` xong chạy lại `php artisan optimize`). Kiểm từ một mạng NGOÀI văn phòng (4G
   của điện thoại): `https://<tên miền>/admin/login` phải là `404`. Chưa thấy `404` thì chưa
   chạy lệnh dưới.
2. **Nên: demo trên một bản cài RIÊNG** — tên miền con (ví dụ `demo.<tên miền>`) hoặc máy chủ
   khác, CSDL riêng, `.env` riêng, vẫn đặt `ADMIN_IP_ALLOWLIST` như mục 1 — để CSDL của bản thật
   không bao giờ chứa tài khoản demo.

Đủ điều kiện thì gọi thẳng seeder demo bằng `--class` (cờ này đi thẳng vào lớp được đặt tên,
không qua kiểm tra môi trường của `DatabaseSeeder`). Dữ liệu mẫu cần thư viện sinh dữ liệu giả
(Faker), một gói chỉ dành cho máy dev mà `composer install --no-dev` của Bước 2 không cài — thiếu nó,
lệnh dừng ở `Call to undefined function Database\Seeders\fake()` (đo ngày 2026-10-08). Nên cài thêm
các gói dev trước, rồi mới gieo:

```bash
composer install --optimize-autoloader --no-scripts
sudo -u www-data rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
sudo -u www-data php artisan package:discover
sudo -u www-data php artisan db:seed --class=DemoDataSeeder --force
```

Tài khoản cổng khách demo (`khach…@example.com`) cũng mật khẩu `password`, nhưng cổng khách luôn
đòi thêm mã sáu số gửi qua email, và hộp thư `example.com` không có ai nhận.

**Hết demo, chuyển sang dùng thật — chạy chuỗi này TRƯỚC Bước 6** (dòng cuối của nó CHÍNH LÀ
Bước 6). Trước hết gỡ lại các gói dev đã cài cho dữ liệu mẫu:

```bash
composer install --no-dev --optimize-autoloader --no-scripts
sudo -u www-data rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
sudo -u www-data php artisan package:discover
```

Rồi chuỗi dưới — nó xoá SẠCH mọi thứ demo đã tạo:

```bash
sudo -u www-data php artisan migrate:fresh --force && \
  sudo -u www-data php artisan db:seed --force && \
  sudo -u www-data rm -rf storage/app/private/[0-9]* && \
  sudo -u www-data php artisan vkcrm:create-admin
```

(Mọi dòng bằng `www-data`, kể cả `rm`: tệp hồ sơ mẫu thuộc về `www-data`, người quản trị không xoá
được — `Permission denied`.)

- `migrate:fresh --force` xoá MỌI bảng trong CSDL `DB_DATABASE` rồi tạo lại — tám tài khoản demo,
  khách, vụ việc, nhật ký, phiên đăng nhập (ai đang đăng nhập đều bị đẩy ra), thư còn chờ gửi.
  `db:seed --force` nạp lại dữ liệu tham chiếu, như lần cài đầu ở trên.
- `rm -rf storage/app/private/[0-9]*` xoá tệp của tài liệu mẫu: mỗi tài liệu nằm trong một thư
  mục mang số của nó (`storage/app/private/1/`, `2/`…). Bỏ qua dòng này thì tệp demo nằm lại
  không thuộc bản ghi nào, và tài liệu thật đầu tiên rơi vào đúng thư mục `1/` cũ của một tệp
  demo. Hai tệp `.gitignore` và `.htaccess` của thư mục đó được giữ nguyên.
- `vkcrm:create-admin` là Bước 6 — đọc Bước 6 trước khi trả lời câu hỏi của nó. Sau đó đặt lại
  `ADMIN_IP_ALLOWLIST` theo quyết định ở Bước 0.
- Đây là chỗ DUY NHẤT `migrate:fresh` được chạy trên máy chủ thật, và chỉ khi CSDL CHƯA có gì
  thật: dữ liệu nào lỡ nhập thật trong lúc demo cũng mất theo, không lấy lại được. Lỡ nhập thật
  rồi thì dừng lại, đừng chạy.
- Không có đường tắt nào khác. Trên CSDL demo, Bước 6 từ chối ("Hệ thống đã có 1 quản trị viên"
  — người đó chính là `admin@luatvukhang.com`); `--additional` chỉ thêm một quản trị viên mới bên
  cạnh tám tài khoản demo vẫn mật khẩu `password`, vẫn chưa ai cài 2FA. Xoá tay từng tài khoản
  cũng không đủ: khách, vụ việc, tài liệu và nhật ký mẫu vẫn còn.

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
  (việc này cũng vào nhật ký, ghi rõ cờ). Đã nạp dữ liệu mẫu ở Bước 5 thì con số đó tính cả
  `admin@luatvukhang.com`: chạy chuỗi "Hết demo, chuyển sang dùng thật" ở cuối Bước 5, ĐỪNG dùng
  `--additional`.
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
còn thấy giá trị thật sau khi cấu hình đã cache) — ngoại lệ duy nhất là dòng "bất biến tiền", xem
đoạn ngay sau danh sách dưới. Lệnh tự kiểm
`TRUSTED_PROXIES`/`HEARTBEAT_URL`/`MAIL_SCHEME`/`SESSION_SECURE_COOKIE`/`APP_DEBUG`, tài khoản nhân sự demo
còn mật khẩu `password` (ĐỎ khi `ADMIN_IP_ALLOWLIST` trống, VÀNG khi có — Bước 5), PHP extension
bắt buộc, `storage/app/private` có phục vụ công khai được không (nó tự gửi một request tới `APP_URL` — chạy
khi máy chủ web và HTTPS ở Bước 4 đã lên), và ba điều kiện máy chủ cho sao lưu:

- PHP extension `zip` dựng với libzip có mã hoá AES (`ZipArchive::EM_AES_256`) — thiếu nó, mọi
  lượt sao lưu ở production bị từ chối (không tạo bản sao lưu không mã hoá) và có email báo lỗi;
- hàm `proc_open` không bị tắt (nhiều shared hosting tắt nó trong `disable_functions`; thiếu nó
  thì không dump được CSDL và không gọi được `rclone`);
- lệnh `mariadb-dump` (gói `mariadb-client`, ví dụ `apt install mariadb-client`) có trong PATH;
- cộng tệp chạy `rclone` cho đích Google Drive (Bước 1 của `docs/SAO-LUU-KHOI-PHUC.md` —
  `vkcrm:preflight` không kiểm riêng `rclone`, dùng `vkcrm:backup-check` cho việc đó).

Và bốn điều kiện của máy chủ MCP (kết nối AI cho nhân sự, M11); ba điều kiện đầu kiểm cả khi máy chủ
MCP đang tắt:

- `mcp.redirect_domains` (`config/mcp.php` của gói laravel/mcp) không có `*` — ĐỎ nếu có. App dùng
  danh sách redirect so khớp chính xác của riêng nó; `*` ở khoá này chỉ chờ ai đó bật lại route
  đăng ký client của gói là mở cho mọi tên miền;
- access token của Passport sống không quá 1 giờ — ĐỎ nếu dài hơn (mặc định của Passport là một năm;
  `AppServiceProvider` đặt 1 giờ);
- hai khoá Passport (Bước 3) có mặt, đọc được thành khoá RSA, là CÙNG MỘT CẶP (khoá công khai dán
  từ cặp khác làm mọi kết nối AI hỏng chữ ký), và tệp khoá riêng không mở quyền gì cho người dùng
  khác (`chmod 600`; 640 hay 660 được) — ĐỎ nếu không, nêu đúng tệp hoặc biến thiếu. "Đọc được" là
  đọc được bằng người dùng ĐANG CHẠY lệnh preflight (thường là `root` hay tài khoản SSH), không nhất
  thiết là người dùng của PHP-FPM: chạy `ls -l storage/oauth-*.key` và xác nhận chủ tệp là người dùng
  của PHP-FPM (`www-data`);
- máy chủ MCP đang bật (công tắc trên trang "Kết nối AI") mà chưa ghi ngày đã nộp hồ sơ đánh giá tác
  động chuyển dữ liệu cá nhân ra nước ngoài (`docs/CHINH-SACH-AI.md`, việc 1) — VÀNG, chỉ nhắc; ghi
  ngày nộp trên chính trang đó thì dòng này XANH.

Dòng ĐỎ chặn mở cổng — trừ dòng "bất biến tiền" (một hợp đồng đang hiệu lực mà tổng các đợt lệch
giá trị hợp đồng; máy chưa có hợp đồng nào luôn XANH ở dòng này): nó vẫn ĐỎ và mã thoát vẫn 1, nhưng
là dữ liệu, chỉ sửa được trong app bằng một phụ lục, nên không chặn `php artisan up` (mục "Nâng cấp
lên bản mới"); câu tổng kết của lệnh nói đúng điều đó khi nó là dòng ĐỎ duy nhất. Dòng VÀNG (ví dụ
bốn thông tin pháp lý chưa điền ở cả trang "Thông tin văn phòng" lẫn `.env` — Bước 3) không chặn
nhưng nên xử lý sớm.

Hai dòng của app trên điện thoại (M12):

- thiếu extension `curl` (gói thông báo đẩy cần nó) là ĐỎ, như mọi extension bắt buộc ở Bước 1;
- thiếu hay sai khoá thông báo đẩy là VÀNG — "Chưa có khoá thông báo đẩy (…) — thông báo đẩy trên
  điện thoại đang TẮT …": app vẫn cài và chạy, email vẫn đi, chỉ thông báo đẩy tắt. Sửa theo mục
  "Khoá thông báo đẩy (VAPID)" ở Bước 3 (nhớ `php artisan config:clear` trước khi sinh khoá).

`php artisan optimize` cache cấu hình, route, view và sự kiện (cộng phần cache riêng của
Filament). **Từ lúc này, sửa `.env` không có tác dụng cho tới khi cache lại**: sau mỗi lần sửa
`.env`, chạy `php artisan optimize:clear`, `php artisan vkcrm:preflight`, rồi `php artisan
optimize`.

### Bước 8 — Một dòng lịch chạy tự động (cron), và giám sát nó

Đúng một dòng trong crontab của người dùng chạy PHP-FPM (`sudo crontab -u www-data -e` trên VPS; mục
"Cron Jobs" trên shared hosting):

```
* * * * * cd /var/www/vk-crm && php artisan schedule:run >> /dev/null 2>&1
```

Dòng này chạy MỌI việc định kỳ khai báo trong `routes/console.php`, ví dụ: gửi thư trong hàng đợi
(mỗi phút), nhắc mốc thời hạn (07:00–19:30, mỗi 30 phút), sao lưu (02:00), giám sát sao lưu (08:00),
ping giám sát cron (mỗi 5 phút). Không cần tiến trình `queue:work` chạy thường trực.

Thông báo đẩy trên điện thoại (M12) có hàng đợi RIÊNG, `push`, cũng rút bằng CHÍNH dòng này —
không thêm dòng cron nào: mục lịch `queue.push` chạy
`queue:work --queue=push --stop-when-empty --max-time=50` mỗi phút (mỗi máy nhận là một request ra
máy chủ push, hạn 10 giây, nên tách khỏi hàng thư để máy chủ push chậm không giữ chân thư nhắc hạn),
chạy NỀN để một phút bận không bắt các mục lịch sau nó chờ, và `push-subscriptions.prune` dọn đăng
ký cũ lúc 03:30.

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

### Máy chủ MCP (kết nối AI cho nhân sự)

Từ M11, nhân sự nối tài khoản AI của chính mình (Claude, ChatGPT, Claude Code, VS Code…) vào hệ
thống qua một cổng MCP duy nhất, để hỏi hồ sơ và soạn nháp. Phần này là việc của người cài máy
chủ. Việc của chủ văn phòng (pháp lý, ai được dùng, bật lúc nào) ở `docs/CHINH-SACH-AI.md`; việc
của từng nhân sự (kết nối từng ứng dụng) ở `docs/KET-NOI-AI.md`.

**Cài xong, máy chủ MCP vẫn TẮT.** Hai công tắc toàn hệ thống (bật máy chủ MCP; cho phép ghi) mặc
định tắt và nằm trong trang "Kết nối AI" của quản trị viên, cùng công tắc theo từng nhân sự (mặc
định tắt). Không có biến `.env` nào bật được chúng. Người cài máy chủ chỉ cần làm đúng năm việc dưới
đây để khi chủ văn phòng bật thì nó chạy.

1. **Khoá Passport** — Bước 3 (`php artisan passport:keys`, quyền 600, cất cùng `APP_KEY`).
   `vkcrm:preflight` (Bước 7) báo ĐỎ khi thiếu khoá hay khoá riêng mở cho người dùng khác.
2. **`/.well-known/` tới được Laravel** — Bước 4, mục 6. Đây là chỗ hỏng hay gặp nhất, vì nó chỉ
   hỏng trên máy chủ thật: `/mcp` vẫn trả 401 đúng, nhưng Claude và ChatGPT không tìm được trang đăng
   nhập.
3. **Tường lửa, WAF, Cloudflare, chặn địa lý không được chặn hai nền tảng AI.** Claude và ChatGPT
   gọi máy chủ từ hạ tầng của họ ở nước ngoài, kể cả bước đọc `/.well-known/*` và `/oauth/token`.
   Phải cho qua:
   - dải IP đi ra của Anthropic (Claude): `160.79.104.0/21`;
   - danh sách IP của OpenAI (ChatGPT), công bố dạng JSON ở `https://openai.com/chatgpt-connectors.json`
     và **thay đổi thường xuyên** — quy tắc tường lửa phải cập nhật theo nó, không chép một lần rồi
     để đó. Danh sách IP không thay được xác thực: mọi request vẫn phải mang token.

   Claude Code, Cursor và VS Code thì gọi từ chính máy của nhân sự. `ADMIN_IP_ALLOWLIST` (nếu có
   đặt) chặn màn hình đồng ý `/oauth/authorize`, vì màn hình đó mở trong trình duyệt của nhân sự
   như `/admin`: nhân sự ở ngoài các IP đó không kết nối mới được, nhưng kết nối đã có vẫn chạy
   (`/mcp`, `/oauth/token` không nằm sau allowlist).
4. **Không đệm `/mcp`** — Bước 4, đoạn "Không đệm `/mcp`".
5. **URL của máy chủ MCP là URL trên tên miền QUẢN TRỊ**: `https://<ADMIN_DOMAIN>/mcp` khi `.env` đặt
   `ADMIN_DOMAIN`, còn không thì `https://<tên miền của APP_URL>/mcp` (ví dụ
   `https://khachhang.luatvukhang.com/mcp`). Khi tách hai tên miền, nhân sự PHẢI dán URL trên tên
   miền quản trị: dán URL trên tên miền cổng khách thì máy chủ trả siêu dữ liệu mang URL khác URL
   client đã gọi, và theo chuẩn (RFC 9728) client phải từ chối — kết nối không bao giờ thành. Báo URL
   đúng cho chủ văn phòng để ghi vào `docs/KET-NOI-AI.md` của văn phòng.

**Biến `.env` của máy chủ MCP** (mỗi biến có một dòng giải thích trong `.env.example`):

| Biến | Mặc định | Khi nào đổi |
|---|---|---|
| `MCP_MATTER_DEFAULT` | `denied` | Vụ việc MỚI có lên AI ngay không. Để `denied`: luật sư bật từng vụ ở tab Tổng quan, kèm ô tích "Khách đã đồng ý bằng văn bản" và một dòng nhật ký. Đặt `allowed` thì mọi vụ mới lên AI mà KHÔNG có ô tích đồng ý nào, KHÔNG có dòng nhật ký nào cho riêng việc đó — hệ thống không còn giữ bằng chứng khách đã đồng ý. Chỉ chủ văn phòng quyết |
| `MCP_PARTY_NAMES` | `pseudonym` | `full` trả tên thật của các bên không phải khách (bị đơn, người liên quan) cho AI. Chỉ chủ văn phòng quyết. Giả danh không phải khử nhận dạng: tiêu đề vụ việc thường chứa tên đương sự |
| `MCP_EXTRA_REDIRECT_URIS` | trống | Một ứng dụng AI khác ngoài danh sách có sẵn (`docs/KET-NOI-AI.md`) cần redirect URI riêng: thêm ĐÚNG URI đầy đủ, phân tách dấu phẩy, không ký tự đại diện |
| `MCP_EXTRA_ALLOWED_ORIGINS` | trống | Một ứng dụng chạy trong trình duyệt (gửi header `Origin`) ngoài `https://claude.ai`, `https://chatgpt.com` bị trả 403 |
| `MCP_CLIENT_ID_METADATA_DOCUMENTS` | `false` | Giữ `false`. Chỉ bật sau khi đã thử thật với Claude và ChatGPT trên máy chạy thử; tắt lại sau khi đã bật thì nhân sự kết nối theo cách đó phải kết nối lại |
| `PASSPORT_PRIVATE_KEY`, `PASSPORT_PUBLIC_KEY` | trống (đọc tệp `storage/oauth-*.key`) | Chỉ khi máy chủ không giữ được tệp khoá — Bước 3 |

**Giới hạn đã biết, nói trước để không ai chẩn đoán nhầm:**

- **Đăng ký client động bị giới hạn 10 lần một giờ cho mỗi IP**, đếm cả lần hỏng. Claude và ChatGPT
  đăng ký từ IP của nền tảng, và mỗi lần một nhân sự kết nối (hay kết nối lại) là một lần đăng ký —
  nên MỌI nhân sự dùng cùng một nền tảng chung một bộ đếm. Một buổi hướng dẫn cả văn phòng kết nối
  Claude trong cùng một giờ sẽ gặp lỗi từ người thứ mười một (HTTP 429); người đó chờ sang giờ sau.
  Ai biết URL máy chủ cũng tiêu được bộ đếm đó bằng tài khoản Claude của chính họ. Rải việc kết nối
  ra nhiều giờ; nếu thành vấn đề thật thì báo lại để nới giới hạn.
- **Loopback IPv6 (`http://[::1]:…`) không dùng được.** Chính sách bảo mật nội dung (CSP) của màn
  hình đồng ý không mở được cho địa chỉ IPv6, nên trình duyệt chặn bước quay về ứng dụng sau khi bấm
  "Đồng ý": hệ thống đã ghi một lần đồng ý mà ứng dụng không nhận được mã. Dùng `http://localhost`
  hay `http://127.0.0.1` cho ứng dụng dòng lệnh.
- **IP trong nhật ký là IP của nền tảng AI, không phải của nhân sự.** Claude và ChatGPT gọi từ máy
  chủ của họ (thường ở Mỹ); chỉ Claude Code, Cursor, VS Code gọi từ máy của nhân sự. Một dòng nhật ký
  mang IP nước ngoài không có nghĩa nhân sự đang ở nước ngoài.
- **Hai việc dọn dẹp hằng đêm chạy dưới dòng cron sẵn có** (không thêm dòng cron): `mcp.tokens.purge`
  03:00 (xoá token OAuth đã thu hồi, và token đã hết hạn quá 37 ngày) và `mcp.clients.prune` 03:15
  (xoá client đăng ký động đã quá 30 ngày tuổi mà không còn token nào sống — mỗi lần kết nối tạo một
  client mới).

## Vận hành hằng ngày

- **Nhân sự bị khoá đăng nhập** (năm lần sai trong 15 phút, theo email và theo IP — SPEC §10.3):
  một quản trị viên KHÁC mở **Nhân sự → người đó → "Mở khoá đăng nhập"**. Nếu người đó vẫn ngồi
  chung mạng với các lần gõ sai, chiều IP có thể còn khoá tới hết 15 phút. Khách hàng bị khoá ở
  cổng `/portal`: màn hình **Tài khoản portal → tài khoản của khách → "Mở khoá đăng nhập"**.
- **Nhân sự mất điện thoại và mã khôi phục**: một quản trị viên KHÁC mở **Nhân sự → người đó →
  "Đặt lại 2FA"**. Việc này xoá xác thực cũ, đăng xuất mọi phiên đang mở của người đó (ở request kế
  tiếp của từng phiên), gỡ mọi điện thoại và máy tính đang nhận thông báo đẩy của người đó (mỗi máy
  một dòng trong Nhật ký hệ thống), và buộc họ cài lại 2FA ở lần đăng nhập kế tiếp; không ai tự đặt
  lại 2FA cho chính mình. Cho tới khi cài lại 2FA, người đó không nhận thông báo đẩy nào (email vẫn
  đi). Quản trị viên DUY NHẤT mất cả điện thoại lẫn mã khôi phục: người có quyền vào máy chủ
  chạy `php artisan vkcrm:reset-2fa <email>` (cũng gỡ máy như nút).
- **Khách báo mất hay đổi điện thoại**: **Tài khoản portal → tài khoản của khách → "Gỡ mọi máy
  nhận thông báo"** — các máy đó thôi nhận thông báo đẩy về hồ sơ (email vẫn đi); khách bật lại trên
  máy mới. Máy mất còn đang đăng nhập cổng thì bấm thêm "Cấp lại mật khẩu". Đổi email của một tài
  khoản portal tự gỡ mọi máy của tài khoản đó.
- **Email báo lỗi sao lưu** không phải chuyện để "xem sau" — `docs/SAO-LUU-KHOI-PHUC.md`.
- **Nhật ký hệ thống** (`/admin`, mục Nhật ký hệ thống): đăng nhập, tải tài liệu, công bố, đổi phân
  quyền, tạo/khoá tài khoản portal, đặt lại 2FA, mở khoá đăng nhập, tạo quản trị viên từ dòng lệnh.
  Không có lịch tự xoá nhật ký: giữ ít nhất `RETENTION_YEARS` năm.

## Nâng cấp lên bản mới

Chạy bằng người quản trị (chủ của mã nguồn và của deploy key — Bước 2); mỗi dòng `php artisan` chạy
bằng `www-data`, viết đủ ở đây để chép nguyên khối.

**Shared hosting:** một tài khoản làm cả hai việc (đoạn "Shared hosting" ở đầu phần "Cài lên máy chủ
thật") — chép khối dưới, bỏ tiền tố `sudo -u www-data` ở mọi dòng có nó và bỏ hẳn dòng `chown`;
thứ tự các dòng còn lại giữ nguyên.

```bash
cd /var/www/vk-crm
sudo -u www-data php artisan down
git pull
composer install --no-dev --optimize-autoloader --no-scripts
sudo -u www-data rm -f bootstrap/cache/config.php bootstrap/cache/packages.php bootstrap/cache/services.php
sudo -u www-data php artisan package:discover
php artisan filament:assets
sudo chown -R www-data:www-data storage bootstrap/cache
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force
sudo -u www-data php artisan billing:check-invariants
sudo -u www-data php artisan vkcrm:preflight
sudo -u www-data php artisan optimize
sudo -u www-data php artisan up
```

- `php artisan down` trả trang bảo trì (503) cho mọi người trong lúc cập nhật, để không ai ghi dữ
  liệu giữa chừng một migration.
- `composer install … --no-scripts` rồi ba dòng sau nó: sau lần cài đầu, `bootstrap/cache/` và
  `storage/` thuộc về `www-data`, nên hai lệnh composer tự gọi sau khi cài (`package:discover`,
  `filament:upgrade`) không chạy được bằng người quản trị — lượt đi thử ngày 2026-10-08 dừng ở `Script
  @php artisan package:discover --ansi handling the post-autoload-dump event returned with error code
  1`. Vì vậy composer không tự gọi chúng, và ba dòng sau làm đúng việc của chúng bằng đúng người:
  xoá ba tệp cache khởi động của bản cũ trong `bootstrap/cache/` — cấu hình `config.php` và hai danh
  sách gói, đúng ba tệp composer vẫn tự xoá trước `package:discover` (còn hai tệp danh sách gói cũ
  thì một gói vừa bị gỡ làm `package:discover` chết ngay lúc khởi động, ví dụ
  `Class "Laravel\Pail\PailServiceProvider" not found`, đo cùng ngày; `config.php`: gạch dưới),
  `package:discover` bằng `www-data` (nó ghi `bootstrap/cache/`), `filament:assets` bằng người quản trị
  (nó chép tài sản giao diện vào `public/`, thư mục của người quản trị).
- `optimize:clear` đứng TRƯỚC `migrate`: nó làm phần dọn cache của `filament:upgrade` (`config:clear`,
  `route:clear`, `view:clear`) và dọn thêm cache sự kiện, để `migrate`, `db:seed`,
  `billing:check-invariants` chạy bằng cấu hình và mã của bản MỚI. Cache cấu hình của lần `optimize`
  trước mà còn hiệu lực thì một migration đọc cấu hình mới đọc ra null — ví dụ máy chủ trước M12 nâng
  lên M12: migration `create_push_subscriptions_table` đọc `config('webpush.table_name')`, tệp
  `config/webpush.php` chưa có trong cache cũ, `Schema::create(null)` ném lỗi và site kẹt ở trang bảo
  trì (tìm ra khi rà soát, không quan sát được: lượt đi thử nâng cấp một bản lên chính nó). Nó đứng
  SAU `filament:assets` vì nó xoá luôn hai tệp danh sách gói, mà lệnh `php artisan` kế tiếp tự ghi
  lại vào `bootstrap/cache/` — lệnh đó phải chạy bằng `www-data`, không phải người quản trị.
- `db:seed --force` an toàn để chạy lại (Bước 5) và NÊN chạy: bản mới có thể thêm quyền hay loại vụ
  việc. Bản M9 thêm bốn quyền tiền (`billing.view`, `contract.manage`, `payment.record`,
  `revenue.viewAny`) — không chạy thì không ai mở được màn hình tiền; bản M10 thêm ba quyền tiếp nhận
  (`intake.create`, `intake.viewAny`, `intake.convert`) — không chạy thì menu Tiếp nhận không hiện
  với ai, kể cả quản trị viên; bản M12 không thêm quyền nào; bản M13 thêm quyền `performance.viewAny` —
  không chạy thì trang Theo dõi đội ngũ không hiện với ai. Từng bản làm gì trên máy chủ đã có dữ liệu:
  Bước 5, các đoạn "Bản cập nhật … làm gì trên máy chủ đã có dữ liệu" (M9, M10, M12, M13). Bản nào
  phải làm thêm việc tay TRƯỚC hay SAU chuỗi lệnh này có hướng dẫn riêng — hôm nay là M11
  (gạch đầu dòng "Bản cập nhật M11" ngay dưới: cài `sodium`/`curl` TRƯỚC chuỗi lệnh, `passport:keys`
  bằng `www-data` ngay SAU dòng `chown`) và M12 ("Bản cập nhật M12", có một bước TRƯỚC `git pull`); làm theo đúng thứ tự
  của từng mục, bản cũ hơn trước.
- `billing:check-invariants` in bảng những hợp đồng đang hiệu lực mà tổng các đợt lệch giá trị hợp
  đồng (mã thoát 1). `vkcrm:preflight` ngay sau cũng ĐỎ vì cùng lý do. Dòng ĐỎ này là DỮ LIỆU,
  không phải cấu hình máy, và chỉ sửa được trong app: vẫn `up`, rồi luật sư phụ trách ký ngay một
  phụ lục cho từng hợp đồng trong bảng (màn hình tiền, công nợ, doanh thu tính sai hợp đồng đó cho
  tới khi sửa); chạy lại lệnh cho tới khi sạch. Mọi dòng ĐỎ khác của preflight vẫn chặn `up`.
- Preflight ĐỎ thì sửa trước khi `php artisan up` — chạy `up` rồi mới phát hiện là mở cổng trên
  một cấu hình hỏng. Ngoại lệ duy nhất là dòng "bất biến tiền" ở gạch đầu dòng trên: khi nó là dòng
  ĐỎ duy nhất, câu tổng kết của lệnh nói vẫn `up` (mã thoát vẫn 1), và đúng là vẫn `up`.
- **Bản cập nhật M11 (kết nối AI cho nhân sự)** trên một máy chủ đã có dữ liệu, theo ĐÚNG thứ tự:
  (1) **trước** chuỗi lệnh ở trên, cài extension `sodium` và `curl` nếu máy chưa có (Bước 1) —
  `composer install --no-dev` từ chối chạy khi thiếu `sodium` (gói ký token mà Passport kéo vào khai
  nó là bắt buộc); (2) ngay SAU dòng `sudo chown -R www-data:www-data storage bootstrap/cache` của
  chuỗi lệnh (trước `vkcrm:preflight`), chạy MỘT lần `sudo -u www-data php artisan passport:keys` —
  bằng `www-data` như mọi dòng `php artisan` của chuỗi: chạy bằng người quản trị hay `root` thì khoá
  riêng (quyền 600) mang chủ là người đó, PHP-FPM không đọc được, trong khi `vkcrm:preflight` chạy
  bằng chính người đó vẫn XANH;
  (3) cất hai tệp khoá cùng `APP_KEY` (Bước 3); (4) kiểm `/.well-known/` (Bước 4, mục 6) và tường
  lửa (mục "Máy chủ MCP"). `migrate --force` chỉ thêm bảng và cột; mọi vụ việc đã có nhận cờ AI "không cho phép",
  và mọi công tắc AI mặc định tắt, nên sau bản cập nhật chưa ai kết nối được AI, chưa vụ nào lên AI,
  cho tới khi chủ văn phòng bật.
- Đọc phần ghi chú nâng cấp của bản mới trong `docs/PROGRESS.md` TRƯỚC khi chạy: một bản có thể
  kèm việc phải làm tay (ví dụ một biến `.env` mới — so `.env.example` mới với `.env` đang chạy).
- **Đêm đầu sau nâng cấp, theo dõi hộp thư báo lỗi**: lượt sao lưu 02:00 và lượt giám sát 08:00 là
  lần đầu bản mới chạy những việc đó. Sáng hôm sau chạy `php artisan vkcrm:backup-check`.
- **Hàng đợi qua lần nâng cấp:** thư và việc nền đã xếp hàng TRƯỚC `php artisan down` nằm nguyên
  trong bảng `jobs` và được mã MỚI chạy sau `php artisan up` (lịch không chạy trong lúc bảo trì).
  Không cần rút hàng đợi bằng tay: mã của các việc nền được viết để đọc được việc do bản cũ xếp (ví
  dụ thư kích hoạt cổng xếp trước khi có cờ "cấp lại" chạy như lần cấp đầu). Muốn chắc, trước
  `php artisan down` chạy `php artisan queue:work --stop-when-empty` một lần cho hàng đợi trống.
- Khi nghi ngờ: bản sao lưu đêm trước là điểm quay lại, và `APP_KEY` không đổi qua các bản nâng cấp.

### Bản cập nhật M12 (app trên điện thoại và thông báo đẩy)

Máy chủ đã chạy bản trước M12 thì làm thêm, theo thứ tự:

1. **TRƯỚC `git pull`:** `php -m | grep -i curl` phải in `curl` (bản dòng lệnh) và `php-fpm8.3 -m`
   cũng vậy — từ M12 `curl` là extension bắt buộc (Bước 1); thiếu thì `composer install` của bản mới
   từ chối cài. Kiểm luôn máy chủ có gọi ra được máy chủ push không (Bước 1, mục "Máy chủ có gọi ra
   được máy chủ push không").
2. Chuỗi lệnh nâng cấp ở trên, nguyên vẹn. `migrate --force` chạy hai migration mới, chỉ THÊM một
   bảng đăng ký thiết bị, không đụng dữ liệu cũ:
   `2026_10_03_000001_create_push_subscriptions_table`,
   `2026_10_03_000002_add_device_label_and_last_seen_at_to_push_subscriptions_table`.
   M12 không thêm quyền mới (`db:seed --force` vẫn chạy như mọi lần).
3. **nginx (đứng theo mẫu cũ):** chép hai khối `location = /admin/sw.js { … }` và
   `location = /portal/sw.js { … }` của `tools/deploy/nginx.conf.example` mới vào cấu hình đang chạy
   (đặt đâu cũng được trong khối `server` — `location =` thắng mọi khối regex), `nginx -t`, rồi
   `systemctl reload nginx`. Thiếu hai khối này thì app không cài được (Bước 4, việc 6). Apache:
   không phải sửa gì.
4. **Khoá thông báo đẩy:** làm đúng mục "Khoá thông báo đẩy (VAPID)" ở Bước 3 — `config:clear`,
   `webpush:vapid`, điền `VAPID_SUBJECT`, `vkcrm:preflight`, `optimize` — rồi cất cặp khoá cùng
   `APP_KEY`. Chưa sinh khoá thì mọi thứ khác của M12 vẫn chạy, chỉ thông báo đẩy tắt (preflight
   VÀNG).
5. **Dòng cron giữ nguyên.** Mục lịch mới `queue.push` (rút hàng đợi thông báo đẩy mỗi phút) và
   `push-subscriptions.prune` (03:30) chạy từ chính dòng `schedule:run` đã có (Bước 8).
6. **`php artisan vkcrm:push-reset` chỉ khi đổi khoá** (lộ khoá, mất khoá, khôi phục mà không còn khoá
   cũ) — KHÔNG chạy trong một lần nâng cấp bình thường: nó xoá đăng ký của mọi điện thoại.
7. Gửi cho khách và nhân sự hướng dẫn cài app (`docs/QUY-TRINH.md`, mục "Hướng dẫn cài ứng dụng Luật
   Vũ Khang trên điện thoại"). Chủ văn phòng chạy danh sách kiểm tra trên máy thật
   (`docs/research/2026-10-01-pwa-kiem-tra-may-that.md`) trên chính máy chủ này.

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

**Phiên bản MariaDB cho bộ test (M9).** Test hai kết nối của `DraftContract`
(`MoneyTransactionConcurrencyTest`) đo hành vi khoá của MariaDB ≥ 11.6 (`innodb_snapshot_isolation`
bật mặc định từ bản đó). Trên MariaDB 10.11 hay MySQL 8 test đó đỏ mà mã không sai. CI
(`.github/workflows/ci.yml`) và `compose.yaml` dùng ảnh `mariadb:11` (bản 11.x mới nhất) — đừng hạ
xuống 10.x. `AmendContract` chưa có test hai kết nối; thứ tự khoá của nó có ở
`BillingLockOrderTest`.

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
