# VK-CRM — hướng dẫn cho agent

Đọc trước: `docs/SPEC.md` (nguồn sự thật về nghiệp vụ, mô hình dữ liệu, test bắt buộc,
milestone) và `docs/superpowers/specs/2026-09-13-vk-crm-design.md` (quyết định kỹ thuật).
Tiến độ ở `docs/PROGRESS.md`. Kế hoạch từng milestone ở `docs/superpowers/plans/`.

## Môi trường

- Máy dev không có PHP/Composer. Mọi lệnh chạy trong Docker qua `bin/dev`:
  `bin/dev artisan ...`, `bin/dev composer ...`, `bin/dev test`, `bin/dev pint`.
- Không dùng `php`/`composer` trực tiếp, không dùng `vendor/bin/sail`.
- Sàn PHP 8.3: không thêm gói yêu cầu PHP 8.4+. Không cài Redis, Horizon, Octane,
  Reverb, Pulse, Scout.

## Quy ước

- Nghiệp vụ nằm trong `app/Actions/`; Filament resource/controller/job chỉ gọi Action.
- Chuỗi giao diện tiếng Việt qua `__()` và `lang/vi/`; định danh code tiếng Anh.
- Enum backed string cho mọi cột trạng thái, có `label()`.
- TDD với Pest: viết test đỏ trước, rồi cài đặt. Test bắt buộc ở SPEC §11.
- Trước mỗi commit: `bin/dev test` xanh và `bin/dev pint` sạch.
- Kết thúc mỗi milestone: cập nhật `docs/PROGRESS.md`, dừng lại báo cáo.
