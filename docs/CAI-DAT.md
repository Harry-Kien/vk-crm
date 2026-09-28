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

## Khi đưa lên máy chủ thật

Chưa làm, thuộc phần bảo mật và vận hành. Những thứ bắt buộc phải xong trước:

1. **`TRUSTED_PROXIES` phải điền địa chỉ proxy thật.** Để trống nghĩa là mọi khách hàng dùng
   chung một bộ đếm đăng nhập: năm lần gõ sai của bất kỳ ai khoá cả cổng trong 15 phút.
2. Đúng một dòng lịch chạy tự động:
   `* * * * * cd /đường/dẫn && php artisan schedule:run >> /dev/null 2>&1`
3. Sao lưu hằng ngày ra Google Drive, và **đã thử khôi phục thật một lần** — làm theo từng bước ở
   `docs/SAO-LUU-KHOI-PHUC.md`. Các biến `.env` của phần sao lưu và bảo mật trình duyệt (giải thích
   từng biến ở `.env.example` và ở Bước 4 của tài liệu đó):
   - sao lưu: `BACKUP_DISKS`, `BACKUP_NAME`, `BACKUP_ARCHIVE_PASSWORD` (bắt buộc ở production),
     `BACKUP_NOTIFY_EMAIL`, `BACKUP_RCLONE_REMOTE`, `BACKUP_RCLONE_BINARY`, `BACKUP_RCLONE_CONFIG`,
     `BACKUP_LOCAL_KEEP`, `BACKUP_RCLONE_TIMEOUT`, `BACKUP_MAX_STORAGE_MB`;
   - Content-Security-Policy: `CSP_MODE` (để trống ở production là `enforce`).

   Biến cũ `BACKUP_DISK` (số ít) không còn được đọc — dùng `BACKUP_DISKS`.
4. Xác thực hai lớp cho toàn bộ tài khoản nội bộ.
5. **Máy chủ có đủ những thứ mà sao lưu cần** (M8; lệnh kiểm tự động `vkcrm:preflight` là M8 Task 8,
   chưa có — hiện kiểm tay):
   - PHP extension `zip` dựng với libzip có mã hoá AES — `php -r 'var_dump(defined("ZipArchive::EM_AES_256"));'`
     phải in `bool(true)`. Thiếu nó, mọi lượt sao lưu ở production bị từ chối (không tạo bản sao
     lưu không mã hoá) và có email báo lỗi;
   - hàm `proc_open` không bị tắt — `php -r 'var_dump(function_exists("proc_open"));'` phải in
     `bool(true)` (nhiều shared hosting tắt nó trong `disable_functions`; thiếu nó thì không dump
     được CSDL và không gọi được `rclone`);
   - lệnh `mariadb-dump` (gói `mariadb-client`, ví dụ `apt install mariadb-client`) —
     `mariadb-dump --version` phải in ra một số phiên bản;
   - cộng tệp chạy `rclone` cho đích Google Drive (Bước 1 của `docs/SAO-LUU-KHOI-PHUC.md`).
6. **`MAIL_FROM_NAME` phải là tên văn phòng** (ví dụ `"Luật Vũ Khang"`), không phải `${APP_NAME}`
   mặc định của bộ cài — nếu không, hộp thư của khách hiện tên kỹ thuật của dự án làm người gửi.
7. **Văn phòng xác nhận địa chỉ "Trả lời" của thư, `BRAND_REPLY_TO_ADDRESS`** (M6.5 Task 12).
   Mọi thư của hệ thống gắn `Reply-To` lấy từ `config('vkcrm.brand.reply_to')`, để khách bấm "Trả
   lời" thì thư tới một hộp có người đọc, không tới `MAIL_FROM_ADDRESS` (`no-reply@`). Ba trường hợp:
   - **không có dòng** `BRAND_REPLY_TO_ADDRESS` trong `.env`: dùng mặc định
     `lienhe@luatvukhang.com` (trong `config/vkcrm.php`);
   - **có dòng nhưng để trống** (`BRAND_REPLY_TO_ADDRESS=`): thư **không có** `Reply-To`, và khách
     trả lời sẽ rơi vào hộp `no-reply@`;
   - điền một địa chỉ: dùng địa chỉ đó.

   Biến này chưa có dòng mẫu trong `.env.example` lúc viết. Chủ văn phòng cần xác nhận địa chỉ mặc
   định có đúng không (sổ tay M6.5 ghi việc này đang chờ trả lời).
8. **Seed đúng lệnh — KHÔNG chạy `migrate:fresh --seed` như bước "Bốn bước" ở trên.** Lệnh đó
   gọi `DatabaseSeeder`, và trên `APP_ENV=production` (`.env` của máy chủ thật phải đặt vậy)
   nó CHỈ tạo dữ liệu tham chiếu (vai trò, quyền, 6 loại vụ việc, giai đoạn, danh mục hồ sơ mẫu)
   — không có admin, không có tài khoản demo mật khẩu `password` nào (M6.5 Task 19; trước bản vá
   này, `migrate:fresh --seed` tạo thẳng `admin@luatvukhang.com`/`password` trên đúng tên miền
   thật). Sau khi migrate xong:

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

   `DemoDataSeeder` thì KHÔNG an toàn trên dữ liệu thật (xem cuối mục này).

   Rồi tạo tài khoản quản trị ĐẦU TIÊN bằng tay, với một mật khẩu thật:

   ```bash
   php artisan tinker
   >>> \App\Models\User::create(['name' => 'Tên quản trị viên', 'email' => 'ten@luatvukhang.com', 'password' => 'một-mật-khẩu-thật', 'position' => \App\Enums\UserPosition::Admin, 'is_active' => true])->assignRoleFromPosition();
   ```

   **Muốn dữ liệu mẫu để demo cho khách trước khi dùng thật** (không phải dữ liệu thật): gọi
   thẳng seeder demo bằng `--class`, cờ này đi thẳng vào lớp được đặt tên, không qua kiểm tra môi
   trường của `DatabaseSeeder`:

   ```bash
   php artisan db:seed --class=DemoDataSeeder --force
   ```

   Đừng chạy lệnh này trên dữ liệu thật: nó tạo tài khoản mật khẩu `password` trên tên miền thật.
