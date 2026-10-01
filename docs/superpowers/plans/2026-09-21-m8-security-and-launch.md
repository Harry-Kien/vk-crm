# VK-CRM M8 — Kế hoạch bảo mật và đưa vào vận hành

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Sửa ngày 2026-09-24**, sau khi đối chiếu kế hoạch này với SPEC và mã thật. Những thay đổi chính:
> - Filament 5.8.1 có sẵn 2FA ứng dụng kèm mã khôi phục, nên **không cài gói TOTP**.
> - Đăng nhập **admin** chưa có luật 5 lần/15 phút; chỉ portal có.
> - Chưa có API, nên mục "API 60/phút" chuyển sang M11.
> - Script nội tuyến của Filament không mang nonce, nên CSP cần một bước khảo sát trước.
> - Sao lưu: chủ văn phòng chọn Google Drive, và thêm máy chủ tại văn phòng khi cần. Hệ thống ghi được nhiều đích, và mã hoá trước khi gửi đi.
> - Bổ sung: lệnh tạo admin đầu tiên, lệnh kiểm tra trước khi mở cổng, danh sách giới hạn IP cho admin.

**Goal:** Tick hết mười mục SPEC §10, rồi bàn giao một hệ thống mà **một người không tham gia phát triển cài lại được từ đầu trên một máy chủ trống** (SPEC §14 mục 8). Đây là milestone duy nhất mà một việc bỏ sót không làm hỏng test nào cả. Nó chỉ hỏng vào ngày hệ thống đã có dữ liệu thật của khách hàng thật.

**Architecture:** Không tính năng nghiệp vụ mới. M8 là ba loại việc:
1. lấp những mục §10 chưa có chủ;
2. sao lưu và khôi phục;
3. tài liệu triển khai.

Mọi thứ phải chạy trên PHP 8.3 với đúng một dòng cron, không Redis, không supervisor (SPEC §14 mục 2).

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8, Pest 4, Pint.

Gói mới được phép:
- `spatie/laravel-backup` (SPEC §10 mục 8 gọi đích danh);
- adapter Flysystem cho đích sao lưu theo R3: một adapter Google Drive ngay, và `league/flysystem-sftp-v3` chỉ khi văn phòng có máy chủ.

Mỗi gói kiểm `composer require --dry-run` trên sàn PHP 8.3 trước, như M7 R3 đã làm cho dompdf.

**Không** cài gói TOTP. Filament 5.8.1 đã có `AppAuthentication` (`vendor/filament/filament/src/Auth/MultiFactor/App/AppAuthentication.php`), gồm 8 mã khôi phục; `pragmarx/google2fa` đã được Filament kéo theo.

**Spec:** `docs/SPEC.md`:
- **§10 toàn bộ**: mười mục, đây là checklist nghiệm thu của milestone;
- §2 (ràng buộc hạ tầng, giám sát cron, danh sách extension), §3 (panel admin: 2FA, giới hạn IP);
- §14 (tám tiêu chí nghiệm thu của cả hệ thống), §11.

---

## Ràng buộc toàn cục

- **Nhánh:** `m8-security-and-launch`, cắt từ `main` sau khi M7 đã merge.
  - Sau M8 còn M9 (hợp đồng, thanh toán), M10 (tiếp nhận) và M11 (máy chủ MCP). Chủ văn phòng đã chốt ngày 2026-09-24 làm M11 ngay sau M8.
- Luật chung của dự án giữ nguyên: nghiệp vụ trong Action; tiếng Việt qua `__()`; TDD với Pest; mutation probe cho mọi điều kiện mới; đọc lại docblock đối chiếu mã trước khi commit; `git commit -- <path>`.
- Trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, chép nguyên văn.
- **Mang từ M6.5:** test màn hình đi qua Livewire hoặc HTTP; trang tự viết dùng `canAccess()` và `abort(404)`; chỉ style nội tuyến, không có bước build CSS.
- **Mỗi mục §10 phải kết thúc bằng một test nêu đích danh số mục đó trong tên test.** Lý do: §14 mục 1 đòi "checklist mục 10 tick hết", và một checklist tick bằng trí nhớ thì không phải checklist.
- Task có migration chạy vòng MariaDB thật.
- Người rà soát cuối được brief **giả định có một Critical**, cộng thêm một câu: giả định có một mục §10 được đánh dấu xong mà thực ra chỉ đúng ở môi trường test.
- **Hỏi chủ văn phòng trước khi bắt đầu:** danh sách IP được vào admin, nếu muốn bật (Task 1).
  - Đích sao lưu **đã quyết** ngày 2026-09-24 (R3).
  - Bốn thông tin pháp lý **không chặn** M8: chủ văn phòng tự nhập trong app (M7 Task 10).

---

## Phán quyết của chủ nhiệm

**R1 — `TRUSTED_PROXIES` là điều kiện chặn phát hành, không phải một ghi chú.**
- Phát hiện ở vòng rà soát M5: sau một proxy hay CDN, `request()->ip()` trả về địa chỉ của proxy. Khi đó:
  - **cả cổng khách dùng chung một ô đếm đăng nhập**, nên năm lần nhập sai của bất kỳ ai khoá mọi khách trong mười lăm phút;
  - `last_login_ip` và `stage_log_views.ip` ghi địa chỉ proxy. Cột `stage_log_views.ip` là chứng cứ.
- Cơ chế đọc biến đã sửa ở M6.5 Task 1: `config/trustedproxy.php` dùng `env('TRUSTED_PROXIES') ?: null`, và `.env.example` để dòng đó ở dạng chú thích.
- Giá trị thật chỉ nằm trong `.env` production, không nằm trong repo, nên không test nào của repo khẳng định được nó. M8 thêm lệnh **`vkcrm:preflight`**. Lệnh đỏ khi, ở môi trường `production`:
  - `TRUSTED_PROXIES`, `HEARTBEAT_URL`, `SESSION_SECURE_COOKIE` còn rỗng;
  - `APP_DEBUG=true`;
  - thiếu một extension bắt buộc;
  - thư mục `storage/app/private` phục vụ công khai được.
- Test gọi lệnh với cấu hình production giả lập, cả chiều đỏ lẫn chiều xanh.
- `README.md` bắt chạy lệnh này trước khi mở cổng, và sau mỗi lần nâng cấp.

**R2 — 2FA cho tài khoản nội bộ (§10 mục 7). M8 nhận.** "Không có tuỳ chọn tắt" nghĩa là: không màn hình nào, không hành động nào, không cột nào tắt được.
- Dùng `multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)` trên panel admin.
- Mã khôi phục in ra cho người dùng đúng một lần.
- `users.two_factor_secret` và `two_factor_recovery_codes` mang cast `encrypted` (hôm nay chỉ `$hidden`).
- **Đường tắt duy nhất có thật:** Filament hiện `DisableAppAuthenticationAction` trên trang hồ sơ, mà M6.5 Task 20 thêm `->profile()` vào panel admin. Ẩn hành động đó, và test bằng cách quét đường đi, đúng cách M5 đã làm cho `ClientUser::toggleEmailAuthentication()`.
- **Mất điện thoại không phải là tắt 2FA.** Admin có hành động "Đặt lại 2FA". Hành động xoá secret, buộc người đó cài lại ở lần đăng nhập sau, ghi audit, và không bao giờ để tài khoản ở trạng thái đăng nhập được mà không có 2FA.
- `UserFactory` mặc định có secret. Nếu không, hơn 1.300 test của panel admin sẽ bị chuyển hướng sang trang cài đặt.
- Ghi đính chính SPEC §3 và §4.1: "Fortify TOTP" thành "2FA ứng dụng của Filament". Dự án đã chọn vậy từ M0.

**R3 — Sao lưu chưa khôi phục thử thì chưa phải sao lưu.**
- `spatie/laravel-backup` là điều kiện cần. Điều kiện đủ là một lần **khôi phục thật**: lấy bản sao lưu mới nhất, dựng lại cơ sở dữ liệu và thư mục tệp trong một container sạch, rồi mở một tài liệu, một hồ sơ, và **giải mã được một `clients.id_number`**.
- **`APP_KEY` là một nửa của bản sao lưu.** Nó mã hoá số CCCD và, sau R2, secret 2FA của mọi nhân sự. `laravel-backup` không sao lưu `.env`. Một lần khôi phục sinh khoá mới vẫn "thành công", nhưng mất mọi số CCCD và khoá ngoài mọi nhân sự. Quy trình phải có bước cất `APP_KEY` riêng, ngoài máy chủ, và bước khôi phục phải dùng đúng khoá đó.
- Bản sao chứa tệp hồ sơ thô, nên archive được **mã hoá bằng mật khẩu**. Mật khẩu cất cùng chỗ với `APP_KEY`, không cùng chỗ với bản sao.
- **Đích sao lưu: chủ văn phòng quyết ngày 2026-09-24 — Google Drive, và máy chủ đặt tại văn phòng nếu cần.** Cách cài:
  - **Hệ thống sao lưu ra được nhiều đích cùng lúc.** `spatie/laravel-backup` nhận một danh sách disk; danh sách đọc từ `.env` (`BACKUP_DISKS`). Hôm nay là `google`. Khi có máy chủ văn phòng, thêm `office` mà không sửa mã.
  - **Google Drive:**
    - Dùng một tài khoản Google **riêng cho sao lưu** (tốt nhất là Google Workspace, thư mục trong Shared Drive), không dùng Gmail cá nhân của ai.
    - Xác thực bằng OAuth refresh token, hoặc service account trên Shared Drive. Service account không có dung lượng riêng, nên không ghi được vào "My Drive".
    - Adapter Flysystem cho Google Drive là gói cộng đồng (ví dụ `masbug/flysystem-google-drive-ext`). Kiểm `composer require --dry-run` trên PHP 8.3 và đọc lịch sử bảo trì của nó trước khi cài. Nếu không đạt, dùng `rclone` từ lệnh cron làm phương án dự phòng, và ghi phán quyết.
  - **Máy chủ văn phòng**, khi có: một NAS hoặc máy chủ nhận bản sao qua SFTP (`league/flysystem-sftp-v3`), khoá SSH chỉ ghi vào một thư mục. Máy chủ ở văn phòng thì không cần mở cổng vào văn phòng từ internet: nó có thể tự **kéo** bản sao về theo lịch, và hướng kéo an toàn hơn hướng đẩy.
  - **Mã hoá trước khi rời máy chủ là bắt buộc**, với mọi đích.
    - Google Drive đặt dữ liệu ở nước ngoài. Với luật 91/2025/QH15, Nghị định 356/2025 và Nghị định 333/2026 (tóm tắt ở `docs/research/2026-09-24-mcp-phap-ly-goi.md`), archive mã hoá bằng mật khẩu cất tại Việt Nam nghĩa là Google chỉ giữ bản mã. Việc này giảm rủi ro nhiều, nhưng có thể vẫn được coi là chuyển dữ liệu ra nước ngoài.
    - Chủ văn phòng là luật sư và tự đánh giá có cần lập hồ sơ đánh giá tác động hay không. Ghi đánh giá đó vào PROGRESS.
    - Máy chủ văn phòng xoá hẳn câu hỏi này.
  - **Nên có cả hai đích khi có máy chủ văn phòng.** Một bản ở văn phòng (khôi phục nhanh, dữ liệu trong nước) và một bản ở Google Drive (không mất khi văn phòng cháy hoặc bị trộm). Đó là quy tắc 3-2-1.
- Quy trình khôi phục viết vào `README.md`, với số đo thời gian thật.

**R4 — CSP không cho `unsafe-inline` script (§10 mục 2) là mục tốn công nhất §10, và kế hoạch cũ "dùng nonce" không đứng được.**
- Livewire hỗ trợ nonce. Nhưng Filament 5.8.1 in `<script>` nội tuyến **không có nonce** ở nhiều chỗ: `layout/base.blade.php`, `support/.../assets.blade.php` (`window.filamentData`, đổi theo từng request nên không băm được), `sidebar.blade.php`, `notifications.blade.php`.
- Task 1 **bắt đầu bằng một bước khảo sát có đo đạc**:
  - bật CSP ở chế độ `Content-Security-Policy-Report-Only`;
  - đi qua các trang chính của cả hai panel;
  - liệt kê từng vi phạm;
  - thử ba cách: băm các script tĩnh; publish và sửa đúng các view vi phạm để gắn nonce; bật `livewire.csp_safe`.
- Ghi phán quyết vào PROGRESS kèm số view phải giữ riêng.
- **Nếu không cách nào đạt `script-src` không `unsafe-inline` mà không phải giữ riêng một phần lớn view của Filament** (mỗi lần nâng cấp phải so lại), dừng lại và trình chủ văn phòng. Đó là một đính chính SPEC, không phải một chỗ để "tick cho xong".
- Hai điều đã rõ, ghi sẵn để không ai "sửa" nhầm:
  - `style-src` cần `'unsafe-inline'`, vì luật style nội tuyến của dự án. SPEC chỉ cấm với script.
  - `style-src` và `font-src` phải cho `fonts.bunny.net` (SPEC §3).
- Test khẳng định header có mặt và `script-src` không chứa `unsafe-inline`, **đồng thời** các trang chính vẫn chạy: đăng nhập portal, trang hồ sơ, nộp giấy tờ, tải tệp, chuyển giai đoạn. Một CSP đúng mà làm hỏng nút "Nộp giấy tờ" thì tệ hơn không có.

**R5 — Vô hiệu hoá tài khoản khách phải cắt phiên ở request kế tiếp (§10 mục 9).**
- M5 đã dựng `EnsurePortalAccountIsActive` đứng trước `Authenticate`. M6.5 Task 2 thêm trường hợp khách hàng bị xoá mềm. M7 Task 5 thêm vô hiệu hoá tự động.
- M8 chỉ **kiểm chứng lại có đo đạc**, gồm cả đường Livewire update, và ghi kết quả. Không viết lại.

**R6 — `README.md` là sản phẩm bàn giao, không phải phụ lục.**
- Tiêu chí: một người ngoài dự án cài lại được trên máy chủ trống.
- Cách nghiệm thu duy nhất được chấp nhận: một agent **chưa từng đọc repo này** làm theo `README.md` trong một container sạch. Mọi chỗ nó phải đoán là một lỗi của tài liệu.
- `README.md` (hôm nay chỉ phần chạy local) và `docs/CAI-DAT.md` (cài lại trên máy trống bằng Docker) đã có. **Mở rộng, không viết lại.**

**R7 — Giới hạn IP của admin (SPEC §3: "có thể bật qua middleware, cấu hình `.env`") chưa có chủ. M8 nhận.**
- Một middleware đọc `ADMIN_IP_ALLOWLIST`. Rỗng thì tắt, đây là mặc định.
- Có giá trị thì mọi request vào `/admin` từ IP ngoài danh sách nhận 404 (SPEC §10 mục 10).
- IP đọc qua `request()->ip()`, nên phụ thuộc R1. Test cả hai.

---

## Tasks

### - [x] Task 1 — Proxy, HTTPS, header, CSP, giới hạn IP admin (§10 mục 1, 2; R1, R4, R7)

- `vkcrm:preflight` theo R1.
- Ép HTTPS bằng middleware. Đặt `SESSION_SECURE_COOKIE=true`, và thêm biến này vào `.env.example`.
- HSTS ở tầng web server. Khi hosting không cho cấu hình web server, gửi HSTS từ middleware.
- `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.
- CSP theo R4: khảo sát trước, phán quyết, rồi mới cài.
- Giới hạn IP admin theo R7.
- Mỗi header và mỗi điều kiện có một test nêu tên mục §10.

### - [x] Task 2 — 2FA cho toàn bộ tài khoản nội bộ (§10 mục 7, R2)

- Bắt buộc, không tắt được, có mã khôi phục, dùng chuỗi tiếng Việt đã dịch sẵn từ M5.
- Migration cast `encrypted` cho hai cột secret, kèm vòng MariaDB thật.
- Có hành động "Đặt lại 2FA" của admin, ghi audit.
- `UserFactory` có secret mặc định.
- Test quét mọi đường tắt, gồm trang hồ sơ của M6.5 Task 20.
- Ghi đính chính SPEC §3 và §4.1.

### - [x] Task 3 — Rate limit và nhật ký hoạt động (§10 mục 3, 6)

- **Đăng nhập admin:** chưa có luật 5 lần/15 phút theo email **và** IP. `AdminPanelProvider` dùng `->login()` mặc định của Filament, chỉ giới hạn theo IP từng phút. Dựng theo khuôn `PortalLoginThrottle` của M5, kèm đường mở khoá.
- **Đăng nhập portal:** M5 đã làm. Đọc lại sau hai thay đổi: M6.5 Task 7 (mở khoá) và Task 20 (lỗi gửi OTP không tính là một lần sai). Chạy lại toàn bộ test rate limit **sau** khi R1 đổi nguồn IP, và đọc lại kết luận của chúng, đừng chỉ xem màu xanh.
- **Nộp tài liệu 20 tệp/giờ/tài khoản:**
  - Bộ đếm hiện đếm **request tải lên**, không đếm tệp. Sau M6.5 R10 (một lần nộp nhiều tệp), sửa để đếm tệp, và test với một request chứa nhiều tệp.
  - Phán quyết luật này có áp cho nhân sự không. Hôm nay khoá theo cả hai guard, nên một luật sư tải bộ hồ sơ toà 30 trang sẽ chạm giới hạn của khách.
- **API 60 request/phút:** chưa có route API nào. Test khẳng định không tồn tại route `api/*`. Ghi vào kế hoạch M11 rằng máy chủ MCP phải áp giới hạn này.
- **Activity log đủ tám loại sự kiện §10 mục 6**, mỗi loại một test. "Xuất dữ liệu" là sinh và tải gói bàn giao (M7 Task 4) và tải bản sao lưu (Task 5). Đăng nhập guard `web` đã ghi từ M6.5 Task 20.

### - [x] Task 4 — Dữ liệu cá nhân và tệp (§10 mục 4, 5)

- Kiểm chứng `clients.id_number` mang cast `encrypted` và **không xuất hiện trong bất kỳ log nào**. Quét dữ liệu thật sau khi chạy luồng tạo khách hàng và luồng gửi thư, không đọc mã bằng mắt. Chỗ quét:
  - `storage/logs`;
  - `failed_jobs.payload`, `outbound_messages`, `activity_log.properties`;
  - một bản sao lưu đã giải nén.
- Kiểm chứng lại:
  - đĩa `private` và `.htaccess`, cùng đoạn cấu hình nginx tương ứng (hôm nay chỉ là chú thích, cần đưa vào `README.md`);
  - chữ ký URL 5 phút vẫn kèm kiểm tra policy trong controller.

### - [ ] Task 5 — Sao lưu và khôi phục (§10 mục 8, R3)

- Đích theo R3: Google Drive ngay; máy chủ văn phòng thêm sau bằng cấu hình. Cài adapter Google Drive (hoặc phương án `rclone`) sau khi `--dry-run` đạt. Adapter SFTP chỉ cài khi văn phòng có máy chủ.
- Test: một lượt sao lưu ra hai disk giả lập đều có bản; một disk hỏng thì disk kia vẫn nhận bản, và thư báo lỗi nêu đúng disk hỏng.
- Kiểm trên hosting đích: `mysqldump` hoặc `mariadb-dump` có mặt, và `proc_open` không bị tắt.
- `spatie/laravel-backup` hằng ngày cho cả cơ sở dữ liệu lẫn thư mục tệp, archive mã hoá, giữ 30 bản.
- Nối `backup:run`, `backup:clean`, `backup:monitor` vào lịch M6, kèm test ghim giờ.
- Thư báo lỗi sao lưu bằng tiếng Việt, đi qua sổ thư (`outbound_messages`) và hàng đợi.
- Phán quyết có loại tài liệu gói bàn giao của M7 khỏi bản sao hay không (nó là bản sao thứ hai của tệp đã có).
- Một lần khôi phục thật theo R3, có số đo và có bước giải mã `id_number`.

### - [ ] Task 6 — Quét lại §10 mục 9 và 10 trên toàn hệ thống (R5)

- Tài khoản bị vô hiệu mất phiên ở request kế tiếp, gồm đường Livewire và đường vô hiệu hoá tự động của `ExpireClientAccess` (M7 Task 5).
- Mục 10: không tồn tại và không có quyền phải **không phân biệt được**, ở mọi màn hình đã có sau M0–M7 và M6.5, không chỉ ở portal.
  - Danh sách màn hình dựng từ router, không từ trí nhớ.
  - Ghi rõ ngoại lệ có chủ đích: chữ ký URL sai trả 403 (`routes/web.php`).

### - [ ] Task 7 — `README.md` và hướng dẫn triển khai (§14 mục 8, R6)

Mở rộng `README.md` và `docs/CAI-DAT.md`. Nội dung:
- **Cài từ máy chủ trống.**
- **Danh sách extension PHP đầy đủ**, gồm `intl` (Filament bắt buộc) và `dom` (dompdf). Kèm đính chính SPEC §2, vì danh sách ở đó thiếu hai extension này.
- **Mọi biến `.env`**, kèm giá trị thật phải điền và nơi lấy. Gồm các biến `BRAND_*`, trong đó có bốn thông tin pháp lý. Hôm nay chúng có trong `config/vkcrm.php` nhưng vắng mặt trong `.env.example`.
- **Lệnh tạo admin đầu tiên trong production:** gán chức danh và vai, rồi bắt cài 2FA ở lần đăng nhập đầu. M6.5 Task 19 bỏ tài khoản demo khỏi seed production, nên nếu thiếu lệnh này không ai đăng nhập được.
- **Một dòng cron, giám sát cron, `vkcrm:preflight`.**
- **Sao lưu, khôi phục (kèm bước `APP_KEY`), nâng cấp.**
- Mục tài khoản demo: sửa theo 2FA và theo việc seed tách đôi.

Nghiệm thu bằng một agent chưa đọc repo.

### - [ ] Task 8 — Nghiệm thu toàn hệ thống (SPEC §14)

- Cài `pcov` trong container và CI. CI hôm nay đặt `coverage: none` (`spec-gap/spec-gap-10`).
- Đo độ phủ `app/Actions/` và `app/Policies/` ≥ 80%, dán số.
- Bảng truy vết: mỗi gạch đầu dòng SPEC §11 ứng với tên một test cụ thể.
- Tám tiêu chí §14, từng cái một, có bằng chứng:
  1. toàn bộ test §11 xanh và độ phủ đạt;
  2. một dòng cron;
  3. luồng luật sư chuyển giai đoạn → khách nhận thư và thấy trên portal;
  4. luồng khách trên điện thoại: khách nhận phản hồi khi bị từ chối, nhờ thư `client.document_rejected` của M6 Task 3;
  5. không đường nào thấy dữ liệu khách khác hay nhóm D;
  6. xung đột lợi ích chặn và để lại dấu vết;
  7. gói bàn giao đầy đủ;
  8. `README.md` đã nghiệm thu.
- Cập nhật `docs/PROGRESS.md` và rà soát toàn nhánh.

---

## Những chỗ đã biết trước là sẽ cắn

- **Hai thay đổi đúng cộng lại thành một hành vi sai.** R1 đổi nguồn của `request()->ip()`. Ô đếm đăng nhập theo IP của M5, luật đăng nhập admin mới và giới hạn IP admin (R7) đều đọc chính giá trị đó.
- **CSP là mục dễ tick sai nhất §10.** Một header có mặt không có nghĩa là nó chặn được gì. Một CSP chặt quá làm hỏng nút bấm của khách mà không ai thấy, cho tới khi khách gọi điện.
- **Bật 2FA bắt buộc làm đỏ cả bộ test admin** nếu `UserFactory` không có secret. Làm việc đó ở commit đầu tiên của Task 2.
- **`.env` thật không nằm trong repo.** Mọi biến M6–M8 thêm vào phải có mặt trong `.env.example`, kèm một câu nói rõ giá trị thật lấy ở đâu. Nếu không, người triển khai sẽ bỏ trống đúng những biến quan trọng nhất. `vkcrm:preflight` là lưới cuối.
- **Khôi phục mà không có `APP_KEY` cũ** mất vĩnh viễn mọi dữ liệu mã hoá. Đây là lỗi không sửa được sau khi đã xảy ra.
- **Bốn thông tin pháp lý của văn phòng** được nhập trong app (M7 Task 10). `README.md` hướng dẫn admin nhập chúng ngay sau lần đăng nhập đầu tiên, và `vkcrm:preflight` cảnh báo (vàng, không đỏ) khi chúng còn trống.
