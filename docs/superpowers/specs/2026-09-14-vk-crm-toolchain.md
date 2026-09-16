# VK-CRM — Bộ công cụ hoàn thiện hệ thống (skill, gói, hạ tầng, hệ tham chiếu)

Ngày: 14/09/2026. Bổ sung cho `2026-09-13-vk-crm-design.md` mục 2 và 8. Tài liệu này
gom **toàn bộ** công cụ sẽ dùng từ M1 tới giai đoạn 2, xếp theo mục đích, để mỗi
milestone chỉ cần tra một chỗ. Khi mâu thuẫn với SPEC.md thì SPEC.md thắng.

Quy tắc chung khi cài bất kỳ gói nào:

1. Chạy `bin/dev composer require <gói>` và đọc kỹ ràng buộc PHP / Filament / Laravel.
   Gói đòi PHP 8.4+ hoặc chưa có bản Filament 5 ổn định thì **không dùng**, kể cả beta.
2. Cài xong chạy `bin/dev test` và `bin/dev pint` trước khi viết mã.
3. Ghi lại quyết định vào tài liệu này (mục 9) nếu khác với kế hoạch.

---

## 1. Skill Claude Code theo từng khâu

| Khâu | Skill | Khi nào dùng |
|---|---|---|
| Trước mọi tính năng mới | `superpowers:brainstorming` | Làm rõ yêu cầu và thiết kế trước khi viết kế hoạch |
| Lập kế hoạch milestone | `superpowers:writing-plans` | Sinh `docs/superpowers/plans/YYYY-MM-DD-mN-*.md` |
| Thực thi kế hoạch | `superpowers:executing-plans` hoặc `superpowers:subagent-driven-development` | Đi từng bước có checkpoint |
| Viết mã | `superpowers:test-driven-development` | Test đỏ trước, cài đặt sau (bắt buộc theo CLAUDE.md) |
| Gặp lỗi | `superpowers:systematic-debugging` | Trước khi đề xuất sửa |
| Trước khi báo xong | `superpowers:verification-before-completion` | Chạy lại test, Pint, kiểm tra tay |
| Kết thúc nhánh | `superpowers:finishing-a-development-branch` | Gộp, dọn, cập nhật PROGRESS |
| Rà mã | `code-review`, `simplify`, `security-review` | Sau mỗi milestone; `security-review` bắt buộc ở M2, M4, M5, M8 |
| Giao diện portal có cảm xúc riêng | `frontend-design` | Dòng thời gian tiến độ, trang đăng nhập OTP, email HTML |
| Mockup cho luật sư duyệt | `design` | Trước M3 và M5, vẽ màn hình chính trên khổ 375px |
| Biểu đồ dashboard | `dataviz` | Widget thống kê ở M3 (§7.1) |
| Chạy và chụp màn hình app | `run` | Kiểm tra tay hai panel sau mỗi milestone |
| Tra tài liệu gói | context7 (`query-docs`) | Trước khi dùng API Filament / Spatie, vì phiên bản đổi nhanh |
| Tài liệu bàn giao vận hành | `docx`, `pdf` | M8: hướng dẫn triển khai và vận hành cho văn phòng |

---

## 2. Gói Composer theo milestone

### M1 — Migration, model, enum, factory, seeder
Không cần gói mới. Chỉ Laravel 13 + Filament 5 đã có.

### M2 — Phân quyền
| Gói | Phiên bản | Ghi chú |
|---|---|---|
| `spatie/laravel-permission` | ^8 | Vai trò và quyền, bảng `roles`, `permissions` |
| `bezhansalleh/filament-shield` | ^4 | Sinh permission theo Resource, quy ước `<resource>.<action>` |
| Tự viết | — | Policy theo `matter_user`, global scope `app/Support/Scopes/ClientScope.php` |

### M3 — Panel admin, chuyển giai đoạn, xung đột lợi ích
| Gói | Phiên bản | Ghi chú |
|---|---|---|
| `spatie/laravel-activitylog` | **^4** | Bản 5 đòi PHP 8.4, vi phạm sàn 8.3 |
| Viewer activity log | `rmsramos/activitylog` hoặc `alizharb/filament-activity-log` | Chọn gói tương thích Filament 5 + activitylog 4 |
| `saade/filament-fullcalendar` | bản Filament 5 | Lịch hạn tố tụng; nếu chưa có thì dùng bảng + widget |
| `awcodes/filament-table-repeater` | bản Filament 5 | Nhập các bên trong vụ việc (`matter_parties`) |
| `spatie/laravel-model-states` | ^2 | **Chỉ cân nhắc.** Giai đoạn là dữ liệu cấu hình nên nhiều khả năng tự viết `TransitionMatterStage` |
| Tự viết | — | `RunConflictCheck` (SPEC §6.10), không có gói thay thế |

### M4 — Tài liệu
| Gói | Phiên bản | Ghi chú |
|---|---|---|
| `spatie/laravel-medialibrary` | ^11 | Phiên bản tệp, conversion, disk private |
| `filament/spatie-laravel-media-library-plugin` | ^5 | Field upload trong Filament |
| Tự viết | — | Kiểm tra MIME thật bằng `finfo`, chặn `.svg`, giới hạn 20 MB, quét ClamAV nếu bật |

### M5 — Portal khách
| Gói | Phiên bản | Ghi chú |
|---|---|---|
| `afsakar/filament-otp-login` | bản Filament 5 | Nếu không khớp luồng "email + mật khẩu rồi OTP" thì tự viết Login page |
| Tự viết | — | Trang chi tiết hồ sơ dạng dòng thời gian, nộp tài liệu bằng camera điện thoại |

### M6 — Thông báo, tác vụ định kỳ
| Gói | Phiên bản | Ghi chú |
|---|---|---|
| Laravel Notifications + Mail | có sẵn | Kênh `mail`; enum `outbound_messages.channel` chừa `zns`, `sms` |
| `spatie/laravel-health` | ^1 | Kiểm tra cron, queue, DB, dung lượng đĩa; thay `system_health` tự viết |
| `shuvroroy/filament-spatie-laravel-health` | bản Filament 5 | Hiện trạng thái trên dashboard admin |
| Tự viết | — | Heartbeat ping `HEARTBEAT_URL`, chống gửi trùng bằng `notified_at` |

### M7 — Bàn giao, lưu trữ, tìm kiếm
| Gói | Phiên bản | Ghi chú |
|---|---|---|
| `barryvdh/laravel-dompdf` | ^3 | Biên bản bàn giao PDF, không cần Node/Chromium |
| `ZipArchive` (ext-zip có sẵn) | — | Gói bàn giao `.zip`, test giải nén khẳng định không có tài liệu nhóm D |
| Tìm kiếm | MySQL `FULLTEXT` + Filament global search | Không Scout/Meilisearch |

### M8 — Bảo mật, backup, hoàn thiện
| Gói | Phiên bản | Ghi chú |
|---|---|---|
| Filament 5 MFA tích hợp | có sẵn | TOTP bắt buộc cho guard `web`, không tắt được |
| `spatie/laravel-backup` | bản tương thích PHP 8.3 | Hằng ngày, DB + `storage/app/private`, giữ 30 bản |
| `shuvroroy/filament-spatie-laravel-backup` | bản Filament 5 | Nút chạy và xem backup trong admin |
| `league/flysystem-aws-s3-v3` | ^3 | Disk `s3` cho Cloudflare R2 / Backblaze B2 |
| `pxlrbt/filament-excel` | bản Filament 5 | Xuất danh sách, **phải ghi activity log** (SPEC §10.6) |
| Tự viết | — | Middleware header bảo mật, `RateLimiter` đăng nhập 5 lần/15 phút, signed URL 5 phút |

### Công cụ chất lượng (cài ngay ở M1, chạy mỗi commit)
| Gói | Mục đích |
|---|---|
| `laravel/pint` | Đã có. Định dạng mã |
| `larastan/larastan` ^3 | Phân tích tĩnh, mức 5 trở lên, bắt lỗi Policy và scope |
| `rector/rector` + `driftingly/rector-laravel` | Nâng phiên bản tự động, dọn mã |
| `pestphp/pest-plugin-browser` | Test trình duyệt thật cho portal ở khổ điện thoại (M5) |
| `pestphp/pest-plugin-livewire` | Test component Filament |

### Giai đoạn 2 (sau M8)
| Nhu cầu | Cách làm |
|---|---|
| Hợp đồng, đợt thanh toán, công nợ | Tự viết theo mô hình `contracts`, `installments`, `payments` |
| VietQR | Không có gói ổn định. Sinh QR theo chuẩn NAPAS bằng `simplesoftwareio/simple-qrcode` hoặc `endroid/qr-code` |
| Zalo ZNS | Tự viết Notification channel gọi API Zalo OA; đã chừa enum `zns` |
| Lịch Google | `spatie/laravel-google-calendar` |
| Chấm giờ, tính phí | Bảng `time_entries`, đã chừa relation trên `Matter` |
| Nhận lead từ website | API token bằng Sanctum có sẵn, rate limit 60/phút |
| Theo dõi lỗi production | `sentry/sentry-laravel` gói miễn phí, chỉ nếu VPS |

---

## 3. Không cài

Redis, Horizon, Octane, Reverb, Pulse, Scout / Meilisearch, Telescope trên production,
Fortify (Filament MFA thay thế), Flux UI, mọi starter kit Filament, gói đòi PHP 8.4+.

Lý do: SPEC §2 ràng buộc shared hosting, một dòng cron, PHP 8.3 sàn; và giữ hệ thống
dễ vận hành lâu dài.

---

## 4. Frontend

| Thành phần | Quyết định |
|---|---|
| Khung giao diện | Filament 5 (Livewire 4, Alpine, Tailwind 4). Bật `->spa()` cả hai panel |
| Tuỳ biến | Theme Filament riêng cho portal: màu `BRAND_COLOR`, font hệ thống, chữ 16px, nút 44px |
| Trang riêng | Blade + Tailwind 4 qua Vite 8 cho dòng thời gian tiến độ và email HTML |
| Email | Mailable dùng Markdown mail của Laravel, tuỳ biến theme, test bằng Mailpit |
| Icon | Heroicons có sẵn trong Filament |
| Không dùng | React, Vue, Inertia, Flux UI. Không thêm framework JS |

Nguyên tắc trải nghiệm (đề nghị ghi vào design spec):

- Portal thiết kế cho điện thoại trước, 375px, test trên máy thật.
- Một màn hình một việc. Khối "Anh/chị cần làm gì" luôn nổi bật nhất.
- Luật sư xong việc thường ngày trong 3 cú bấm từ dashboard.
- Ba màu có nghĩa xuyên suốt: xanh xong, vàng chờ khách, đỏ quá hạn.
- Không hiện bảng rỗng; trạng thái trống phải kèm hướng dẫn bước tiếp theo.

---

## 5. Hạ tầng

### Local (đã có)
Docker: `webdevops/php:8.3-alpine`, `mariadb:11`, `mailpit`. Lệnh qua `bin/dev`.

### Production khuyến nghị (VPS)
| Lớp | Lựa chọn |
|---|---|
| Máy | VPS 2 vCPU / 4 GB đặt tại Việt Nam, Ubuntu 24.04 LTS |
| Web | Nginx + PHP 8.3-FPM, `client_max_body_size 25m`, chặn truy cập `storage/` |
| DB | MariaDB 11 (hoặc MySQL 8), `utf8mb4_unicode_ci`, backup logical hằng ngày |
| Queue | `php artisan queue:work --tries=3` chạy bằng systemd, tự khởi động lại |
| Cron | Một dòng `* * * * * php artisan schedule:run` |
| HTTPS | Cloudflare proxy + chứng chỉ origin, hoặc Let's Encrypt qua certbot. HSTS bật ở Nginx |
| Tường lửa | UFW chỉ mở 22, 80, 443; Fail2ban cho SSH; SSH bằng khoá |
| DNS / WAF | Cloudflare gói miễn phí: DNS, chống DDoS, chặn quốc gia nếu cần |
| Backup ngoài | Cloudflare R2 (10 GB miễn phí, không phí egress) hoặc Backblaze B2 |
| Giám sát cron | healthchecks.io gói miễn phí, điền `HEARTBEAT_URL` |
| Email | SMTP tên miền riêng `luatvukhang.com` (Google Workspace, Zoho Mail hoặc dịch vụ hosting), bật SPF, DKIM, DMARC |
| Quét virus | ClamAV daemon, bật `CLAMAV_ENABLED=true` |
| Nâng cấp | `composer update` theo bản Laravel LTS mỗi năm, chạy toàn bộ test trước khi deploy |

### Dự phòng shared hosting
Theo SPEC §2: queue `database`, `schedule:run` gọi `queue:work --stop-when-empty`,
`.htaccess` chặn `storage/`, không `storage:link`.

---

## 6. Quy trình chất lượng mỗi milestone

1. Brainstorm → kế hoạch có test bắt buộc từ SPEC §11.
2. TDD: test đỏ, cài đặt, xanh.
3. `bin/dev test`, `bin/dev pint`, `bin/dev composer larastan` (sau khi cài).
4. `code-review` và `security-review` (ở milestone có bảo mật).
5. Kiểm tra tay hai panel trên trình duyệt, portal trên điện thoại thật.
6. Cập nhật `docs/PROGRESS.md`, dừng báo cáo.

Tiêu chí đo được đề nghị bổ sung SPEC §14:
- M3: luật sư cập nhật tiến độ trong dưới 60 giây, 3 cú bấm từ dashboard.
- M5: khách demo tự nộp giấy tờ trên điện thoại không cần hướng dẫn.
- M8: `composer audit` sạch, header bảo mật đủ theo §10.2, khôi phục backup thành công một lần.

---

## 7. Hệ tham chiếu

### CRM pháp lý thương mại (học cơ chế, không sao chép)
| Hệ | Học gì |
|---|---|
| Clio | Matter làm trung tâm; portal Clio for Clients; kiểm tra xung đột |
| MyCase | Portal khách đơn giản, nhắn tin hai chiều gắn vụ việc |
| Smokeball | Tự động hoá văn bản mẫu theo loại vụ việc |
| Filevine | Giai đoạn cấu hình theo loại vụ việc, dashboard theo hạn |
| PracticePanther | Luồng tiếp nhận khách và nhắc hạn tự động |
| Actionstep | Workflow có bước bắt buộc, phân quyền theo matter |

### Mã nguồn mở tham khảo cách tổ chức (không fork)
| Repo | Tham khảo gì |
|---|---|
| Aureus ERP (MIT, Laravel 13 + Filament 5) | Tổ chức module, Chatter activity feed |
| helpdeskkitv4 (MIT, Filament 4) | Nối nhiều guard / nhiều panel |
| filamentphp/demo | Cách viết Resource, Relation Manager, Widget chuẩn |
| spatie/laravel-permission docs | Mẫu Policy kết hợp permission |

Kết luận khảo sát ở design spec mục 1 và 8 không đổi: không có CRM pháp lý mã nguồn
mở nào đủ giấy phép, đủ mới và có portal để fork.

---

## 8. Bản đồ tính năng so với hãng luật hiện đại

| Nhóm | SPEC hiện tại | Giai đoạn 2 |
|---|---|---|
| Quản lý vụ việc, giai đoạn, nhật ký | ✅ M1–M3 | |
| Kiểm tra xung đột lợi ích | ✅ M3 | |
| Tài liệu, phiên bản, công bố | ✅ M4 | |
| Portal khách, OTP, nộp giấy tờ | ✅ M5 | |
| Nhắc hạn, SLA, heartbeat | ✅ M6 | |
| Bàn giao, lưu trữ, tìm kiếm | ✅ M7 | |
| 2FA, header, rate limit, backup, activity log | ✅ M8 | |
| Hợp đồng, thanh toán, công nợ, VietQR | | ⬜ |
| Zalo ZNS | | ⬜ |
| Chấm giờ, tính phí | | ⬜ |
| Lịch Google | | ⬜ |
| Lead từ website | | ⬜ |
| Soạn văn bản mẫu tự động | | ⬜ |
| Ký số | Ngoài phạm vi | Ngoài phạm vi |

---

## 9. Nhật ký quyết định khi cài đặt thực tế

| Ngày | Gói | Kết quả | Ghi chú |
|---|---|---|---|
| 2026-09-14 | (không cài gói mới ở M1) | — | Đúng kế hoạch |
| 2026-09-14 | spatie/laravel-permission ^8 | Cài (8.3.0) | Tương thích PHP 8.3 + Laravel 13 |
| 2026-09-14 | bezhansalleh/filament-shield | Hoãn sang M3 | M2 chưa có resource để Shield sinh quyền; tên quyền theo SPEC §5 là nguồn sự thật |
| 2026-09-15 | spatie/laravel-activitylog | Cài (^4.0) | Đúng kế hoạch; morph map bổ sung `client`, `matter_party` khi hai model này bắt đầu ghi log |
| 2026-09-15 | pestphp/pest-plugin-livewire | Cài (^4.1) | Cần để test hành động/form Filament (mounted action, form fill) trong panel admin |
| 2026-09-16 | bezhansalleh/filament-shield | Không cài | Xét lại như kế hoạch: 13 quyền SPEC §5 không theo quy ước `<resource>.<action>` của Shield (vd. `matter.transitionStage`, `stageLog.publish`); vai trò/quyền tiếp tục gán qua `RolesAndPermissionsSeeder` + `User::assignRoleFromPosition()` |
| 2026-09-16 | saade/filament-fullcalendar | Không cần | Không có yêu cầu lịch trong phạm vi M3 (hạn tố tụng thuộc M4/M6); bảng `deadlines` đã đủ cho widget "Hồ sơ quá hạn cập nhật" |
| 2026-09-16 | awcodes/filament-table-repeater | Không cần | `MatterParty` dùng RelationManager (`AddMatterParty` Action + form Filament chuẩn) thay vì repeater; đơn giản hơn vì mỗi bên cần chạy `RunConflictCheck` riêng lẻ |
