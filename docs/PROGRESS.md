# Tiến độ VK-CRM

| Milestone | Trạng thái | Ngày | Ghi chú |
|---|---|---|---|
| M0 Khởi tạo | ✅ Xong | 2026-09-13 | Laravel 13.17, Filament 5, hai panel, hai guard, Pest 4, Pint, `.env.example`, seed demo. 23 test xanh. Đăng nhập thật cả hai panel đã kiểm tra trên trình duyệt |
| M1 Migration / model / enum / factory / seeder | ⬜ | | |
| M2 Phân quyền (spatie, Policy, global scope client) | ⬜ | | |
| M3 Panel admin + `TransitionMatterStage` + `RunConflictCheck` | ⬜ | | |
| M4 Danh mục hồ sơ + tài liệu + `PublishDocument` | ⬜ | | |
| M5 Portal khách (OTP, hồ sơ, nộp tài liệu, yêu cầu) | ⬜ | | |
| M6 Thông báo + tác vụ định kỳ + heartbeat | ⬜ | | |
| M7 Bàn giao + lưu trữ + liên lạc + tìm kiếm | ⬜ | | |
| M8 Bảo mật + backup + README triển khai | ⬜ | | |

## Ghi chú M0

- Môi trường local dùng `compose.yaml` tự viết (image `webdevops/php:8.3-alpine`,
  `mariadb:11`, `mailpit`) thay cho Sail vì Sail không chạy trên Git Bash Windows và
  build image quá chậm. Lệnh đi qua `bin/dev`.
- Bảng `users`, `clients`, `client_users` đã có đủ cột theo SPEC §4.1–4.3 để M1
  không phải sửa lại.
- 2FA nội bộ và OTP portal chưa làm (thuộc M8 và M5). Dự kiến dùng MFA tích hợp
  sẵn của Filament 5 thay cho Fortify.
