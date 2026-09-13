# VK-CRM — Quyết định thiết kế và kỹ thuật (bổ sung cho SPEC.md)

Ngày: 13/09/2026. Tài liệu nguồn: `docs/SPEC.md` (đặc tả đầy đủ, là nguồn sự thật
về mô hình dữ liệu, nghiệp vụ, phân quyền, kiểm thử, milestone) và
`docs/Kien-truc-CRM-Luat-Vu-Khang.pdf` (kiến trúc tổng thể). Tài liệu này **không
lặp lại** SPEC.md; nó chỉ ghi các quyết định mà SPEC.md để mở hoặc chưa nói tới.
Khi hai tài liệu mâu thuẫn, SPEC.md thắng.

## 1. Kết luận khảo sát mã nguồn mở (13/09/2026)

Không fork repo nào. Lý do: mọi CRM pháp lý mã nguồn mở đang có đều không giấy
phép, hoặc Laravel ≤ 10, hoặc không dùng Filament, hoặc đã ngừng bảo trì
(lawprosystem, lawyer-portal, CaseBox, Rukovoditel, OpenLawOffice, Iuris-Soft).
CRM bán hàng lớn (Krayin, Twenty, EspoCRM, Odoo, ERPNext) sai hạ tầng, sai giấy
phép hoặc không có portal. Starter kit Filament (filafluxkitv5, liberusoftware,
Ercogx) chỉ dùng **tham khảo cách cấu hình**, không fork, vì kéo theo Flux UI,
Horizon/Octane/Reverb hoặc PHP 8.5.

## 2. Phiên bản khoá

| Thành phần | Phiên bản | Ghi chú |
|---|---|---|
| PHP | **8.3** là sàn | Tiêu chí nghiệm thu 14.2 của SPEC. Không dùng cú pháp/gói yêu cầu 8.4+ |
| Laravel | 13.x | |
| Filament | 5.x (Livewire 4) | |
| Pest | 4.x | |
| spatie/laravel-permission | 8.x | |
| spatie/laravel-medialibrary | 11.x | qua `filament/spatie-laravel-media-library-plugin` 5.x |
| spatie/laravel-activitylog | **4.x** | Bản 5.x yêu cầu PHP 8.4, vi phạm sàn PHP 8.3 |
| spatie/laravel-backup | 9.x hoặc 10.x | tuỳ bản tương thích PHP 8.3 tại thời điểm cài |
| laravel/fortify | mới nhất | TOTP cho guard `web` |
| bezhansalleh/filament-shield | 4.x | Sinh permission theo resource, khớp quy ước `<resource>.<action>` |
| Database | MariaDB 10.11 (dev) / MySQL 8 hoặc MariaDB ≥ 10.3 (prod) | |

Không cài: Redis, Horizon, Octane, Reverb, Pulse, Scout/Meilisearch, Telescope
(trừ khi bật riêng ở local).

## 3. Tên miền và bố cục panel

Một tên miền duy nhất: `khachhang.luatvukhang.com`.

| Đường dẫn | Panel | Guard |
|---|---|---|
| `/` | chuyển hướng 302 tới `/portal` | — |
| `/portal` | Portal khách hàng | `client` |
| `/admin` | Panel nội bộ | `web` |

`ADMIN_DOMAIN` và `PORTAL_DOMAIN` để trống trong `.env` production. Code vẫn
phải hỗ trợ tách subdomain khi hai biến này được điền (SPEC §3), có test cho cả
hai chế độ.

## 4. Hạ tầng triển khai

Người dùng chấp nhận mọi nền tảng. Quyết định:

- **Kiến trúc tuân thủ ràng buộc shared hosting** của SPEC §2 (queue `database`,
  một dòng cron, không extension lạ, không `storage:link` cho tệp hồ sơ).
- **Khuyến nghị triển khai thực tế trên VPS** 2 vCPU / 4 GB tại Việt Nam, chạy
  `queue:work` bằng systemd. `README.md` ghi cả hai cách triển khai.
- Backup đẩy lên S3-compatible (Cloudflare R2 hoặc Backblaze B2, cấu hình
  `.env`), giữ 30 bản.

## 5. Môi trường phát triển local

Máy phát triển (Windows 11) không có PHP, Composer, MySQL; có Docker Desktop và
Git. Quyết định: **Laravel Sail** (Docker) với dịch vụ `laravel.test` (PHP 8.3),
`mariadb` (10.11), `mailpit` (bắt email test). Không cài PHP native trên Windows
để tránh lệch môi trường. Mọi lệnh artisan/composer/pest chạy qua
`./vendor/bin/sail`.

Tệp `compose.yaml` của Sail được commit. `docker-compose` production **không**
dùng Sail; hướng dẫn triển khai production viết riêng trong README.

## 6. Quy ước mã nguồn

- Ngôn ngữ giao diện: tiếng Việt toàn bộ (nhãn, thông báo lỗi, email). Khoá
  dịch trong `lang/vi/`. Không hardcode chuỗi tiếng Việt trong class Filament;
  dùng `__()`.
- Ngôn ngữ mã: tiếng Anh cho tên class, cột, enum (theo SPEC §4).
- Logic nghiệp vụ chỉ nằm trong `app/Actions/` (SPEC §3). Filament resource,
  controller, job chỉ gọi Action. Action ném exception có tên rõ
  (`InvalidStageTransition`, `DocumentNotPublishable`, `ConflictBlocked`...).
- Enum PHP 8.1 backed (`string`) cho mọi cột enum, có phương thức `label()`.
- Global scope cho guard `client` đặt tại `app/Support/Scopes/`, đăng ký trong
  `booted()` của model, kích hoạt khi `auth()->guard('client')->check()`.
- Pint mặc định preset `laravel`. Chạy trước mỗi commit.
- Mỗi milestone kết thúc bằng: test xanh, Pint sạch, cập nhật `docs/PROGRESS.md`.

## 7. Ngoài phạm vi (nhắc lại để không làm thừa)

Hợp đồng, đợt thanh toán, công nợ, VietQR, Zalo ZNS, API lead từ website, ký số,
dashboard nguồn khách. Chỉ để chỗ trong model theo SPEC §15 (`timeEntries()`
relation trên `Matter`, enum channel `zns`/`sms` trong `outbound_messages`).

## 8. Rà soát mã nguồn mở lần hai (13/09/2026, tối)

Rà thêm theo từ khoá tiếng Việt, ứng dụng Laravel có portal (Invoice Ninja, Crater,
Akaunting, FreeScout), ứng dụng Filament hoàn chỉnh (Aureus ERP, Relaticle,
helpdeskkitv4), và công cụ pháp lý ngoài Laravel. Kết luận **không đổi**: không fork.

- Invoice Ninja (Elastic License), Akaunting (BUSL), Crater (AAL, ngừng), FreeScout
  (AGPL, portal trả phí), Relaticle (AGPL, Postgres, Node): loại vì giấy phép hoặc hạ tầng.
- trungtm78/PC02 (NestJS, private): mô hình dữ liệu vụ án Việt Nam sát nhất, chỉ tham khảo.
- **Aureus ERP** (MIT, Laravel 13 + Filament 5, PHP 8.3): tham khảo cách tổ chức module,
  resource và Chatter (activity feed). Không fork vì là ERP.
- **helpdeskkitv4** (MIT, Filament 4): tham khảo cách nối 3 guard / 4 panel.

Gói Filament/Laravel sẽ cân nhắc dùng ở các milestone sau thay vì tự viết:

| Nhu cầu | Gói | Quyết định |
|---|---|---|
| 2FA nội bộ | **Filament 5 MFA tích hợp sẵn** (TOTP + mã email) | Dùng thay cho Fortify ở M8; SPEC §3 ghi Fortify, đổi vì Filament đã có sẵn và tích hợp thẳng vào panel |
| OTP portal | afsakar/filament-otp-login | Cân nhắc ở M5; nếu không khớp luồng "email + mật khẩu rồi OTP" thì tự viết trang login OTP |
| Máy trạng thái giai đoạn | spatie/laravel-model-states + A909M/Filament-StateFusion | Cân nhắc ở M3. Lưu ý giai đoạn là dữ liệu cấu hình (`matter_type_stages`), không phải class cố định, nên có thể chỉ dùng phần UI |
| Activity log viewer | AlizHarb/filament-activity-log hoặc rmsramos/activitylog | M3/M8, phải tương thích activitylog 4.x (PHP 8.3) |
| Lịch mốc thời hạn | saade/filament-fullcalendar | M3, chỉ nếu có bản Filament 5 |
| Backup UI | shuvroroy/filament-spatie-laravel-backup | M8 |
| Quyền | bezhansalleh/filament-shield + spatie/laravel-permission | M2 |

Không có gói nào cho kiểm tra xung đột lợi ích; tự viết theo SPEC §6.10.

## 9. Điều chỉnh môi trường local (thực tế khi dựng M0)

`vendor/bin/sail` từ chối chạy trên Git Bash Windows, và build image Sail (apt trong
Docker) quá chậm trên mạng hiện tại. Thay bằng `compose.yaml` tự viết: service `app` dùng
image dựng sẵn `webdevops/php:8.3-alpine` (đủ pdo_mysql, gd, intl, bcmath, zip, imagick)
chạy `php artisan serve`, cùng `mariadb:11` và `mailpit`. Mọi lệnh đi qua `bin/dev`
(`bin/dev artisan ...`, `bin/dev test`, `bin/dev pint`). Gói `laravel/sail` đã gỡ.
