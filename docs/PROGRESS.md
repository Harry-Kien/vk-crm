# Tiến độ VK-CRM

| Milestone | Trạng thái | Ngày | Ghi chú |
|---|---|---|---|
| M0 Khởi tạo | ✅ Xong | 2026-09-13 | Laravel 13.17, Filament 5, hai panel, hai guard, Pest 4, Pint, `.env.example`, seed demo. 23 test xanh. Đăng nhập thật cả hai panel đã kiểm tra trên trình duyệt |
| M1 Migration / model / enum / factory / seeder | ✅ Xong | 2026-09-14 | 19 bảng SPEC §4 + `code_sequences` + `client_password_reset_tokens`; 13 enum; seeder đủ SPEC §12; 74 test xanh |
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

## Ghi chú M1

- Mã tự sinh dùng bảng đếm `code_sequences` khoá dòng, không còn kẽ hở đồng thời. Có thể có khoảng trống số nếu insert lỗi sau khi lấy số; SPEC không yêu cầu liên tục.
- `stage_logs` không có `deleted_at`, model chặn sửa nội dung và xoá (`StageLogImmutable`). Chỉ `is_published`, `published_at`, `notified_at` được đổi.
- `matter_parties` có thêm `name_normalized` để so tên có index (SPEC §6.10 bước 2).
- Bảng nhật ký thuần và pivot không có `deleted_at` (xem "Ràng buộc toàn cục" trong kế hoạch M1).
- Chưa khai báo `Matter::timeEntries()` (SPEC §15) vì chưa có bảng; làm ở giai đoạn 2 cùng bảng `time_entries`.
- Tệp vật lý của `documents` gắn ở M4 qua medialibrary; M1 chỉ có metadata.
- Broker đặt lại mật khẩu của khách dùng bảng `client_password_reset_tokens` riêng.
- Bộ đếm mã dùng DB::transaction(..., 3): deadlock InnoDB khi hai request cùng tạo dòng đếm đầu tiên của một khoá được thử lại tối đa 3 lần.
- Normalizer::phone() bỏ số 0 đầu sau mã quốc gia: +84 0901234567 và 0901234567 đều thành 84901234567.
- MatterType::stage() và firstStage() đọc từ quan hệ stages đã nạp (tránh N+1 khi liệt kê); sau khi thêm giai đoạn trên cùng instance phải unsetRelation('stages').
- ApplyChecklistTemplate coi mục đã xoá mềm là đã tồn tại (không hồi sinh); không thiết kế cho hai lời gọi đồng thời trên cùng vụ việc.
- Deadline::upcoming() so sánh trực tiếp cột due_date (dùng được index). markReminderSent() không tự khoá dòng: job nhắc hạn ở M6 phải nạp Deadline bằng lockForUpdate() trong transaction.
- Guard bất biến của StageLog chạy qua model event; cập nhật bằng query builder (StageLog::where()->update()) sẽ bỏ qua guard. Mọi Action phải thao tác qua instance model.
