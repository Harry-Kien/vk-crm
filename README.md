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

| Panel | URL | Tài khoản demo | Mật khẩu |
|---|---|---|---|
| Nội bộ, quản trị | http://localhost/admin | `admin@luatvukhang.com` | `password` |
| Nội bộ, trưởng phòng | http://localhost/admin | `quanly@luatvukhang.com` | `password` |
| Nội bộ, luật sư | http://localhost/admin | `luatsu1@luatvukhang.com`, `luatsu2@…`, `luatsu3@…` | `password` |
| Nội bộ, trợ lý | http://localhost/admin | `troly1@luatvukhang.com`, `troly2@…` | `password` |
| Nội bộ, kế toán | http://localhost/admin | `ketoan@luatvukhang.com` | `password` |
| Khách hàng | http://localhost/portal | `khach1@example.com` … `khach12@example.com` (thêm `khach2b@`, `khach5b@`, `khach8b@`, `khach11b@`) | `password` |
| Mailpit | http://localhost:8025 | — | — |

Dữ liệu mẫu có 20 vụ việc với các tình huống cố ý: vụ 1–3 quá hạn cập nhật, vụ 4–5 có hạn trong 3 ngày,
vụ 6–9 thiếu giấy tờ, vụ 10–14 có tài liệu chờ duyệt, vụ 20 xung đột lợi ích với khách hàng số 2.

## Kiểm thử và định dạng mã

```bash
bin/dev test
bin/dev pint
```

Test chạy trên SQLite in-memory nên không đụng dữ liệu dev.

## Tên miền

Mặc định cả hai panel chạy chung một tên miền theo đường dẫn: `/admin` cho nội bộ,
`/portal` cho khách, `/` chuyển hướng về `/portal`. Điền `ADMIN_DOMAIN` và
`PORTAL_DOMAIN` trong `.env` để tách thành hai subdomain riêng.

## Cấu trúc

```
app/
├── Enums/                  Enum backed string cho mọi cột trạng thái
├── Models/                 User (nhân sự), Client, ClientUser (tài khoản portal), ...
├── Actions/                Toàn bộ logic nghiệp vụ (ApplyChecklistTemplate từ M1)
├── Filament/Admin/         Panel nội bộ
├── Filament/Portal/        Panel khách hàng
├── Providers/Filament/     AdminPanelProvider, PortalPanelProvider
└── Support/Scopes/         Global scope giới hạn dữ liệu theo khách (từ M2)
config/vkcrm.php            Cấu hình riêng của hệ thống (tên miền, tiền tố mã hồ sơ, ...)
lang/vi/                    Toàn bộ chuỗi giao diện tiếng Việt
docs/                       Đặc tả, kiến trúc, kế hoạch, tiến độ
```

Hướng dẫn triển khai production (VPS và shared hosting) sẽ được bổ sung ở M8.
