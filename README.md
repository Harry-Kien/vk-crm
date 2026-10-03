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

## Tiếp nhận khách tiềm năng (M10)

Mỗi lần có người liên hệ (điện thoại, Zalo, đến trực tiếp, người giới thiệu, lead gửi từ website
do nhân sự nhập tay) là một bản ghi ở **Tiếp nhận** (`/admin/intake-requests`), mã `TN-2026-0001`.
Kiểm tra xung đột lợi ích chạy ngay khi lưu phần danh tính, TRƯỚC khi ô câu chuyện mở; lần chuyển
thành vụ việc không gõ lại dữ liệu. Báo cáo "Bức tranh đầu vào" ở `/admin/intake-report` (trưởng
phòng, quản trị). Quy trình cho người trực điện thoại: [`docs/QUY-TRINH.md`](docs/QUY-TRINH.md),
Giai đoạn 1. Chưa có đường công khai nào (form trên luatvukhang.com gửi thẳng vào là một milestone
riêng sau M10).

| Biến `.env` | Mặc định | Ý nghĩa |
|---|---|---|
| `PROSPECT_RETENTION_MONTHS` | `24` | Số tháng giữ dữ liệu của người KHÔNG thành khách (tính từ lúc bị từ chối, không theo tiếp hay bị gộp), rồi tác vụ `prospects.anonymise` (03:30 hằng ngày) tự ẩn danh. Số nguyên dương; trống, 0, âm hay chữ thì dùng 24. Con số chờ luật sư của văn phòng xác nhận. |
| `INTAKE_RESPONSE_HOURS` | `4` | Ngưỡng phản hồi lần đầu, tính bằng GIỜ LÀM VIỆC. Bản ghi còn "Mới" quá ngưỡng thì tác vụ `intakes.remind-unanswered` (mỗi 15 phút) báo người được giao — không có thì trưởng phòng/quản trị, cuối cùng là admin — bằng chuông và thư (thư chỉ mang mã, nguồn, thời gian chờ và liên kết; không tên, SĐT hay câu chuyện), và bản ghi hiện ở widget "Liên hệ chưa ai gọi lại". Trống, 0, âm hay chữ thì dùng 4. |

**Giờ làm việc** viết thẳng trong `config/vkcrm.php` (khoá `business_hours`), không qua `.env`:
Thứ Hai–Thứ Sáu, 08:00–17:30, theo `APP_TIMEZONE`, tính cả hai đầu, không nghỉ trưa. **Ngày lễ không
được trừ ra** (một ngày lễ giữa tuần vẫn có nhắc); văn phòng làm Thứ Bảy thì thêm `6` vào `days`. Cả
hai tác vụ chạy từ dòng cron `schedule:run` đã có, không cần thêm cron nào.

Ba quyền mới (`intake.create`, `intake.viewAny`, `intake.convert`) chỉ có trên máy thật sau khi chạy
lại `php artisan db:seed --force` (dữ liệu tham chiếu, an toàn chạy lại — xem `docs/CAI-DAT.md`).

**Dữ liệu mẫu tiếp nhận** (`IntakeSeeder`, chỉ dev/test): 12 bản ghi đủ mọi trạng thái, mỗi bản đi qua
đúng các Action thật. Đáng mở thử: *Tôn Nữ Thanh Tranh* — Đỏ chờ trưởng phòng (bên kia là khách hiện
hữu Vũ Thị Em); *Trịnh Văn Hùng* và *Lưu Thị Nga* — một cặp đối nhau, lần gọi sau ra Vàng vì lần gọi
trước; *Kiều Văn Chờ* — quá hạn phản hồi, hiện trên trang chủ của `troly1@`; *Mạc Văn Kiện* — đã bị
từ chối vì xung đột (người ghi là `troly1@`, chỉ thấy "Văn phòng từ chối"; `quanly@` thấy lý do);
*Phạm Thị Dung* — khách cũ gọi về việc mới, đã chuyển thành vụ (vụ thứ 22, luật sư `luatsu2@`, phí
đã báo hiện sẵn ở form soạn hợp đồng); *Đặng Thị Thu Hương* — đã báo giá, chờ `luatsu1@` chuyển
thành vụ; và một bản đã ẩn danh vì quá hạn lưu.

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
config/vkcrm.php            Cấu hình riêng của hệ thống (tên miền, tiền tố mã hồ sơ, ...)
lang/vi/                    Toàn bộ chuỗi giao diện tiếng Việt
docs/                       Đặc tả, kiến trúc, kế hoạch, tiến độ
```

Hướng dẫn triển khai production (VPS và shared hosting) sẽ được bổ sung ở M8.
