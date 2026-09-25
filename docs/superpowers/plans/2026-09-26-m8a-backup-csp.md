# VK-CRM M8a — Sao lưu, khôi phục, và CSP (làn song song)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Nguồn gốc.** Chủ văn phòng quyết ngày 2026-09-26 mở một làn thứ ba, chạy song song với M6.5 (phiên `crmkhachhang-31`) và M9 (phiên `crmkhachhang-c6`). Làn này làm trước hai phần độc lập của kế hoạch M8 `docs/superpowers/plans/2026-09-21-m8-security-and-launch.md`:
> - **Task 5 và phán quyết R3** (sao lưu, khôi phục);
> - **phần CSP của Task 1 và phán quyết R4**, kèm ba header còn lại của SPEC §10 mục 2.
>
> Các phần khác của M8 (preflight, HTTPS, 2FA, rate limit admin, README đầy đủ, nghiệm thu) vẫn chờ M6.5 và M7, đúng thứ tự cũ. R3 và R4 trong kế hoạch M8 là phán quyết gốc; kế hoạch này không lặp lại chúng, chỉ chia việc. Implementer đọc chúng trong brief.

**Goal:** Văn phòng có sao lưu hằng ngày, mã hoá, ra Google Drive (và thêm đích khác chỉ bằng cấu hình), đã **khôi phục thử thật** một lần có số đo. Cả hai panel gửi CSP không có `unsafe-inline` cho script mà không nút nào hỏng — hoặc có một phán quyết đo đạc giải thích vì sao chưa được, trình chủ văn phòng.

**Spec:** `docs/SPEC.md` §10 mục 2 và mục 8, §2 (một dòng cron, chạy được trên shared hosting), §3 (Bunny Fonts), §11. Kế hoạch M8: R3, R4 và mục "Những chỗ đã biết trước là sẽ cắn".

**Tech stack:** PHP 8.3 (sàn cứng), Laravel 13.x, Filament 5.8, Pest 4, Pint. Gói mới được phép: `spatie/laravel-backup`, một adapter Flysystem cho Google Drive (nếu không đạt thì `rclone`, theo R3).

---

## Ràng buộc toàn cục

**Làn song song, bắt buộc tuân thủ:**
- Worktree `D:\vkwt\lane-m8`, nhánh `m8-backup-csp`, cắt từ `b34e95c`. Chỉ làm việc ở đây.
- **Không bao giờ** sửa, commit, hay chạy lệnh trong `D:\crmkhachhang`, `D:\vkwt\lane-b` … `lane-e`, `D:\crmkhachhang-m9`. Không `bin/dev` (nó đụng container dev chính).
- Mọi lệnh PHP qua `/d/vkwt/m8-dev`: `composer`, `test`, `test:mariadb`, `pint`, `artisan`, `php`, `seed`, `serve`, `stop`. `vendor` của làn là **riêng**. Không bao giờ chạy composer vào `D:\crmkhachhang\vendor` — các làn khác mount nó.
- Không dùng cổng 80, 3306, 1025, 8025. Bản chạy của làn là `http://localhost:8090` (`m8-dev serve`), CSDL `vk_crm_lane_m8`, thư ghi vào `storage/logs` của làn.
- Tối đa 2 tiến trình test song song (`--parallel --processes=2`), vì ba làn chia một máy.
- `routes/console.php` và `.env.example`: **chỉ nối thêm dòng**, không sửa, không đổi thứ tự dòng có sẵn (M6.5 Task 1, 11, 14 cũng sửa hai tệp này).
- Không đổi cấu hình hàng đợi (`config/queue.php`, `QUEUE_CONNECTION`).
- Giữ thay đổi `composer.json`/`composer.lock` ở mức tối thiểu: chỉ các gói ở mục Tech stack.

**Luật chung của dự án (CLAUDE.md):**
- Nghiệp vụ trong `app/Actions/`; lệnh artisan, job, listener chỉ gọi Action.
- Chuỗi giao diện và thư tiếng Việt qua `__()` và `lang/vi/`; định danh code tiếng Anh.
- TDD với Pest: test đỏ trước. Mỗi điều kiện mới có **mutation probe**: xoá hoặc đảo đúng điều kiện đó, chạy test, xác nhận đúng test đỏ, rồi khôi phục. Ghi probe vào báo cáo.
- Trước mỗi commit: test liên quan xanh, `m8-dev pint` sạch. Trước khi báo DONE: toàn bộ suite xanh (`m8-dev test --parallel --processes=2`).
- `git commit -- <path> ...` (không commit cả index). Không push.
- Trailer, chép nguyên văn: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`
- Đọc lại docblock đối chiếu với mã trước khi commit; docblock nói sai là lỗi.
- Không có bước build CSS: style trong Blade là style nội tuyến.
- Mỗi mục §10 kết thúc bằng một test nêu đích danh số mục trong tên test (ví dụ `it('§10.8 …')`).
- Tên biến môi trường mới nào cũng phải có trong `.env.example`, kèm một dòng chú thích nói giá trị thật lấy ở đâu.

**Bí mật:** không bao giờ ghi mật khẩu, token, `APP_KEY` thật vào repo, vào log, hay vào báo cáo. Test dùng giá trị giả.

---

## Tasks

### - [ ] Task 1 — Gói sao lưu, nhiều đích, mã hoá, lịch, thư báo lỗi (§10 mục 8, R3)

**Khảo sát trước khi cài, ghi vào `docs/research/2026-09-26-sao-luu.md`:**
- `composer require spatie/laravel-backup --dry-run` qua `m8-dev composer`: phiên bản nào hỗ trợ Laravel 13 và PHP 8.3. Dán kết quả.
- Adapter Google Drive: ứng viên `masbug/flysystem-google-drive-ext` (và ứng viên khác nếu có). Với mỗi ứng viên: `--dry-run` trên PHP 8.3, flysystem 3, lần phát hành gần nhất, issue mở, kích thước cây phụ thuộc (ví dụ `google/apiclient`). Phán quyết adapter hay `rclone`, kèm lý do và cái giá nếu sai. **Task 1 chỉ khảo sát adapter, chưa cài** — Task 2 cài.
- Kiểm trong container: `mariadb-dump` hoặc `mysqldump` có trong image `webdevops/php:8.3-alpine` không, và `proc_open` không bị tắt. Nếu thiếu dump binary trong image dev, ghi cách test đã xử lý (ví dụ dump qua container mariadb) và điều README phải nói cho hosting đích.

**Cài và cấu hình `spatie/laravel-backup`:**
- `config/backup.php`:
  - nguồn: CSDL mặc định + `storage/app/private` (tệp hồ sơ). Không gồm `vendor`, `node_modules`, `storage/logs`, `storage/framework`.
  - đích: danh sách disk đọc từ `BACKUP_DISKS` (phân tách dấu phẩy, bỏ khoảng trắng, bỏ phần tử rỗng). Mặc định khi chưa khai báo: `local_backups` — một disk local ở `storage/app/backups`, **không** nằm dưới `storage/app/private`.
  - mã hoá archive bằng `BACKUP_ARCHIVE_PASSWORD`, thuật toán mặc định mạnh nhất gói hỗ trợ (AES-256). Khi môi trường là `production` mà mật khẩu rỗng: lệnh sao lưu **từ chối chạy** và báo lỗi rõ (không lặng lẽ tạo archive không mã hoá). Test cả hai chiều.
  - giữ 30 bản hằng ngày (SPEC §10 mục 8). Chiến lược dọn dẹp cấu hình sao cho sau 31 lần chạy liên tiếp mỗi ngày một lần, còn đúng 30 bản.
  - tên archive có tiền tố nhận ra được của văn phòng (lấy từ `APP_NAME` hoặc một biến `BACKUP_NAME`).
- Dòng `BACKUP_DISK=s3` và khối AWS hiện có trong `.env.example`: **không xoá hay sửa** (luật chỉ nối thêm). Nối thêm khối mới `BACKUP_DISKS`, `BACKUP_ARCHIVE_PASSWORD`, `BACKUP_NOTIFY_EMAIL`, kèm chú thích rằng `BACKUP_DISKS` thay thế `BACKUP_DISK` cũ (biến cũ không còn được đọc — kiểm bằng grep và nói trong báo cáo).
- **Lịch**, nối vào cuối `routes/console.php`, mỗi tác vụ gọi lệnh của gói hoặc một Action:
  - `backup:clean` rồi `backup:run` hằng ngày lúc 02:00 (giờ `APP_TIMEZONE`), `withoutOverlapping()`;
  - `backup:monitor` hằng ngày lúc 08:00.
  - Test ghim giờ và tên, theo khuôn test lịch sẵn có (tìm test ghim `07:00` của `CheckDeadlines`).
- **Thư báo** (thất bại sao lưu, thất bại dọn dẹp, bản sao không lành mạnh; không cần thư thành công):
  - tiếng Việt qua `lang/vi/backup.php`, nêu **đúng disk** hỏng và thông điệp lỗi, không nêu mật khẩu;
  - đi qua layout thương hiệu của dự án (xem `app/Mail/BrandedMailable.php` và `app/Mail/Staff/DeadlineReminder.php` để theo đúng khuôn);
  - được ghi vào `outbound_messages` (listener `RecordOutboundMail` tự làm việc này cho mọi thư đi qua mailer — kiểm bằng test, đừng giả định);
  - xếp hàng đợi (`ShouldQueue`), không gửi đồng bộ;
  - người nhận: `BACKUP_NOTIFY_EMAIL`; nếu trống thì mọi nhân sự đang hoạt động có vai admin.
  - Cách móc: thay các lớp notification mặc định của gói bằng lớp của dự án qua `config/backup.php` → `notifications`. Implementer đọc mã gói trong `vendor/spatie/laravel-backup` để chọn chỗ móc đúng, ghi vào docblock.

**Test bắt buộc:**
- `§10.8`: một lượt sao lưu ra **hai** disk giả lập (`Storage::fake` hoặc disk local tạm), cả hai đều có đúng một archive.
- Một disk hỏng (disk ném lỗi khi ghi): disk kia **vẫn** nhận archive; thư báo lỗi được xếp hàng, nêu đúng tên disk hỏng.
- Archive mã hoá: mở archive không có mật khẩu thì không đọc được nội dung tệp; có mật khẩu thì đọc được. Nếu dựng archive thật trong test quá nặng, test ở mức cấu hình **và** một test tích hợp chạy thật, đánh dấu nhóm riêng nếu cần, nhưng phải có ít nhất một test chạy thật `backup:run` trên SQLite hoặc MariaDB.
- `BACKUP_DISKS` với khoảng trắng và phần tử rỗng (`" google, ,office "`) cho ra `['google', 'office']`.
- Production thiếu mật khẩu: từ chối chạy.
- Lịch: giờ và tên.
- Nguồn không gồm `storage/logs`, và gồm `storage/app/private`.

### - [ ] Task 2 — Đích Google Drive và lệnh kiểm tra đích

Theo phán quyết của Task 1 (adapter hay `rclone`).

**Nếu adapter:**
- Cài gói, đăng ký driver Flysystem `google` trong một service provider (hoặc `AppServiceProvider`, theo khuôn dự án), khai disk `google` trong `config/filesystems.php`, đọc từ env: `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REFRESH_TOKEN`, `GOOGLE_DRIVE_FOLDER` (hoặc ID thư mục), và `GOOGLE_DRIVE_TEAM_DRIVE_ID` nếu dùng Shared Drive. Nối vào `.env.example` kèm chú thích.
- Thiếu thông tin xác thực thì disk `google` báo lỗi **rõ ràng bằng tiếng Việt khi được dùng**, không làm hỏng việc khởi động ứng dụng hay các disk khác.

**Nếu rclone:** một Action đẩy archive mới nhất bằng `rclone copy` sau mỗi lần sao lưu thành công, gọi qua `Process`, lỗi đi vào đúng đường thư báo của Task 1; test giả lập `Process`.

**Cả hai trường hợp:**
- Lệnh `vkcrm:backup-check {disk?}` (mặc định: mọi disk trong `BACKUP_DISKS`): ghi một tệp nhỏ, đọc lại so nội dung, xoá; in kết quả từng disk bằng tiếng Việt; mã thoát khác 0 nếu có disk hỏng. Nghiệp vụ trong Action, lệnh chỉ gọi Action. Test cả chiều xanh lẫn chiều đỏ.
- Hướng dẫn **cho chủ văn phòng**, tiếng Việt, từng bước có thể làm theo không cần lập trình viên: tạo tài khoản Google riêng cho sao lưu (khuyến nghị Google Workspace + Shared Drive), tạo OAuth client hoặc service account, lấy refresh token, điền `.env`, chạy `vkcrm:backup-check`. Đặt ở `docs/SAO-LUU-KHOI-PHUC.md` (tệp mới, Task 3 mở rộng tiếp). Không sửa `README.md` (M6.5 và M8 Task 7 sở hữu nó).

### - [ ] Task 3 — Khôi phục thật, có số đo (R3)

- Dựng dữ liệu: `m8-dev seed`, rồi tạo thêm trên CSDL `vk_crm_lane_m8` một khách hàng có `id_number` và một tài liệu có tệp thật trong `storage/app/private` (qua Action có sẵn hoặc tinker gọi Action — không insert tay bỏ qua cast).
- Chạy `backup:run` thật ra disk `local_backups` với mật khẩu giả lập, dùng cấu hình của bản chạy làn (`m8-dev` với biến DB của `vk_crm_lane_m8`).
- Khôi phục trong môi trường **sạch**: một container MariaDB mới tạm thời (tên có tiền tố `vkcrm-lane-m8-restore-`, không map cổng ra host, xoá sau khi xong) và một thư mục ứng dụng sạch (checkout nhánh vào thư mục tạm, hoặc bản sao worktree không có `storage/app/private`). Bước:
  1. giải nén archive bằng mật khẩu;
  2. nạp dump vào MariaDB sạch;
  3. chép tệp về `storage/app/private`;
  4. đặt `APP_KEY` **cũ** (từ chỗ cất riêng, mô phỏng bằng biến môi trường);
  5. chạy `migrate:status` (không có migration đang chờ);
  6. giải mã được `clients.id_number` của khách đã tạo (so với giá trị gốc);
  7. mở được tệp của tài liệu (so checksum với bản gốc);
  8. đếm dòng các bảng chính khớp bản gốc.
- Chạy thêm một lần với `APP_KEY` **mới** để chứng minh bước 6 thất bại — tức `APP_KEY` là một nửa của bản sao lưu. Ghi thông điệp lỗi thật.
- Đo thời gian từng bước và tổng.
- Viết thành script có thể chạy lại: `tools/backup/restore-drill.sh` (bash, chạy từ Git Bash trên máy dev), và ghi quy trình khôi phục cho **máy chủ thật** vào `docs/SAO-LUU-KHOI-PHUC.md`: gồm bước cất `APP_KEY` và `BACKUP_ARCHIVE_PASSWORD` ngoài máy chủ (hai nơi, không cùng chỗ với bản sao), bảng số đo thật, và câu cảnh báo "khôi phục mà không có `APP_KEY` cũ mất vĩnh viễn mọi số CCCD" ở đầu mục.
- Phán quyết có loại gói bàn giao M7 khỏi bản sao hay không: M7 chưa có, nên ghi phán quyết tạm vào tài liệu và PROGRESS-note trong báo cáo, không viết mã.
- Test Pest (nếu có phần mã PHP mới, ví dụ một Action kiểm tính toàn vẹn sau khôi phục). Script bash không cần test Pest, nhưng báo cáo phải dán đầu ra thật của một lần chạy đầy đủ.
- Dọn dẹp: xoá container và thư mục tạm; không để lại archive thật trong repo (thư mục `storage/app/backups` phải bị git bỏ qua — kiểm).

### - [ ] Task 4 — Khảo sát CSP có đo đạc (R4)

- Middleware `SecurityHeaders` (tên theo khuôn dự án) gắn cho cả hai panel và route web, đọc `config('vkcrm.security.csp_mode')` từ env `CSP_MODE` với ba giá trị `off` | `report` | `enforce`; mặc định `off` ở task này (Task 5 quyết mặc định cuối). Chế độ `report` gửi `Content-Security-Policy-Report-Only`. Chính sách nháp:
  - `default-src 'self'`; `script-src 'self'` (+ nonce/hash tuỳ cách thử); `style-src 'self' 'unsafe-inline' https://fonts.bunny.net`; `font-src 'self' https://fonts.bunny.net data:`; `img-src 'self' data: blob:`; `connect-src 'self'`; `frame-ancestors 'none'`; `base-uri 'self'`; `form-action 'self'`; `object-src 'none'`.
  - Nguồn nào khác mà trang thật cần (đo được) thì ghi vào khảo sát, không thêm theo đoán.
- Đo bằng trình duyệt thật: `m8-dev seed` + `m8-dev serve -e CSP_MODE=report`, rồi một script Playwright chạy bằng Node **trên máy** (`node` v24 có sẵn; cài `playwright` vào một thư mục tạm ngoài repo, ví dụ `D:\vkwt\m8-tools`, không thêm vào `package.json` của repo). **Không dùng Playwright MCP** — trình duyệt đó dùng chung với các phiên khác.
  - Bắt vi phạm qua sự kiện `securitypolicyviolation` và console.
  - Trang phải đi qua, với đăng nhập thật: admin — đăng nhập, bảng điều khiển, danh sách vụ việc, trang một vụ việc và từng tab của nó, mở form "Chuyển giai đoạn" và "Thêm cập nhật", danh sách khách hàng, trang sửa khách hàng, nhân sự, nhật ký hệ thống; portal — đăng nhập (mật khẩu + mã một lần đọc từ `storage/logs/laravel.log` của làn), trang hồ sơ, trang nộp giấy tờ (mở form, chọn tệp), trang yêu cầu. Liệt kê trang theo router của hai panel, không theo trí nhớ, và ghi trang nào bỏ qua kèm lý do.
- Thử **ba cách** ở R4, mỗi cách đo lại số vi phạm:
  1. băm các script tĩnh;
  2. publish và sửa đúng các view vi phạm để gắn nonce (dùng `Vite::useCspNonce()`/`csp_nonce` và cơ chế nonce của Livewire);
  3. bật `livewire.csp_safe` (nếu phiên bản Livewire có), đo xem Alpine/Filament còn chạy không.
- Kết quả vào `docs/research/2026-09-26-csp-khao-sat.md`: bảng vi phạm theo trang, kết quả từng cách, **số view Filament/Livewire phải giữ riêng** (publish), và **phán quyết** theo R4:
  - đạt được `script-src` không `unsafe-inline` với số view giữ riêng nhỏ (≤ 8 view, mỗi view có test so sánh khi nâng cấp) → Task 5 bật `enforce`;
  - không đạt → Task 5 chỉ gửi ba header còn lại và CSP ở chế độ `report`, và báo cáo nêu rõ đây là **đính chính SPEC chờ chủ văn phòng quyết**.
- Test Pest cho middleware: có header ở từng chế độ; chế độ `off` không gửi CSP; `report` dùng đúng tên header Report-Only.
- Commit phần middleware + tài liệu khảo sát + script đo (script để trong `tools/csp/`, không gồm `node_modules`).

### - [ ] Task 5 — Header bảo mật và CSP theo phán quyết (§10 mục 2)

- Ba header luôn bật, cả hai panel và route web (kể cả route tải tệp có chữ ký): `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`. Test mỗi header một test tên `§10.2 …`.
- CSP theo phán quyết Task 4:
  - **enforce:** nonce sinh mỗi request, gắn vào mọi script nội tuyến qua các view đã giữ riêng; `CSP_MODE` mặc định `enforce` ở production, `report` ở local. Test `§10.2`: header có mặt, `script-src` không chứa `unsafe-inline`, nonce khác nhau giữa hai request. Chạy lại script Playwright của Task 4 ở chế độ `enforce`: **0 vi phạm** và các hành động chính còn chạy — đăng nhập portal đủ hai bước, mở trang hồ sơ, nộp một giấy tờ thật, tải một tệp, chuyển giai đoạn một vụ việc. Dán đầu ra thật vào báo cáo.
  - **report:** giữ `report`, ghi đính chính SPEC nháp vào báo cáo cho controller trình chủ văn phòng; không sửa `docs/SPEC.md`.
- Mỗi view đã publish có một test ghim: so hash nội dung view gốc trong `vendor` với hash lúc publish, đỏ khi Filament nâng cấp đổi view gốc — để người nâng cấp biết phải so lại.
- Nối `CSP_MODE` vào `.env.example`.
