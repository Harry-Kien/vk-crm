# VK-CRM M8 — Kế hoạch bảo mật và đưa vào vận hành

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tick hết mười mục SPEC §10, rồi bàn giao một hệ thống mà **một người không tham gia phát triển cài lại được từ đầu trên một máy chủ trống** (SPEC §14 mục 8). Đây là milestone duy nhất trong dự án mà một việc bỏ sót không làm hỏng test nào cả — nó chỉ hỏng vào ngày hệ thống đã có dữ liệu thật của khách hàng thật.

**Architecture:** Không tính năng nghiệp vụ mới. M8 là ba loại việc: (1) lấp những mục §10 chưa có chủ, (2) sao lưu và khôi phục, (3) tài liệu triển khai. Mọi thứ phải chạy trên PHP 8.3 với đúng một dòng cron, không Redis, không supervisor (SPEC §14 mục 2).

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8, Pest 4, Pint. Gói mới được phép: `spatie/laravel-backup` (SPEC §10 mục 8 gọi đích danh) và một gói TOTP nếu Filament 5 không có sẵn — **kiểm chứng vendor trước, đừng cài theo trí nhớ**; M5 đã chứng minh Filament 5 có sẵn cả luồng OTP email mà kế hoạch tưởng là phải cài gói ngoài.

**Spec:** `docs/SPEC.md` **§10 toàn bộ** (mười mục, đây là checklist nghiệm thu của milestone), §2 (ràng buộc hạ tầng, giám sát cron), §14 (tám tiêu chí nghiệm thu của cả hệ thống), §11.

---

## Ràng buộc toàn cục

- **Nhánh:** `m8-security-and-launch`, cắt từ `main` sau khi M7 đã merge. Đây là milestone cuối của bản 1.0; sau nó chỉ còn M9 nếu M9 chưa merge.
- Luật chung của dự án giữ nguyên: nghiệp vụ trong Action, tiếng Việt qua `__()`, TDD với Pest, mutation probe cho mọi điều kiện mới, đọc lại docblock đối chiếu mã trước khi commit, `git commit -- <path>`, trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- **Mỗi mục §10 phải kết thúc bằng một test nêu đích danh số mục đó trong tên test.** Lý do: §14 mục 1 đòi "checklist mục 10 tick hết", và một checklist tick bằng trí nhớ thì không phải checklist.
- Task có migration chạy vòng MariaDB thật.
- Người rà soát cuối được brief **giả định có một Critical**, và lần này thêm một câu: giả định có một mục §10 được đánh dấu xong mà thực ra chỉ đúng ở môi trường test.

---

## Phán quyết của chủ nhiệm

**R1 — `TRUSTED_PROXIES` là điều kiện chặn phát hành, không phải một ghi chú.** Phát hiện ở vòng rà soát M5: hôm nay `request()->ip()` trả về `REMOTE_ADDR` và bỏ qua `X-Forwarded-For`, nên sau một proxy hay CDN **cả cổng khách hàng dùng chung một ô đếm đăng nhập** — năm lần nhập sai của bất kỳ ai khoá mọi khách trong mười lăm phút — và `last_login_ip` lẫn `stage_log_views.ip` ghi địa chỉ của proxy chứ không phải của người dùng. Cột `stage_log_views.ip` là chứng cứ. M8 điền giá trị thật và **có một test khẳng định giá trị không còn rỗng ở cấu hình production**.

**R2 — 2FA cho tài khoản nội bộ (§10 mục 7) chưa có chủ ở bất kỳ kế hoạch nào. M8 nhận.** "Không có tuỳ chọn tắt" nghĩa là: không màn hình nào, không hành động nào, không cột nào tắt được — kiểm chứng bằng một test quét đường đi, đúng cách M5 đã làm cho `ClientUser::toggleEmailAuthentication()`. Kiểm chứng vendor Filament 5 trước khi cài gói: nếu bộ có sẵn app-authentication thì dùng, và mã khôi phục phải in ra được cho người dùng đúng một lần.

**R3 — Sao lưu chưa khôi phục thử thì chưa phải sao lưu.** `spatie/laravel-backup` là điều kiện cần. Điều kiện đủ của milestone này là một lần **khôi phục thật**: lấy bản sao lưu mới nhất, dựng lại cơ sở dữ liệu và thư mục tệp trong một container sạch, rồi mở một tài liệu và một hồ sơ. Quy trình đó viết vào `README.md` với số đo thời gian thật.

**R4 — CSP không cho `unsafe-inline` script (§10 mục 2) sẽ va vào Livewire và Filament.** Đây là mục tốn công nhất trong §10 và là chỗ dễ "tick cho xong" nhất. Cách làm: đo thật xem trang nào vi phạm (trang đăng nhập portal, trang hồ sơ, trang tải tệp), dùng nonce, và test bằng cách **khẳng định header có mặt và không chứa `unsafe-inline`, đồng thời khẳng định các trang chính vẫn chạy được** — một CSP đúng mà làm hỏng nút "Nộp giấy tờ" thì tệ hơn không có.
Lưu ý riêng của dự án này: **không có bước build CSS**, nên thay đổi ở đây không được đòi một pipeline mới.

**R5 — Vô hiệu hoá tài khoản khách phải cắt phiên ở request kế tiếp (§10 mục 9).** M5 đã dựng `EnsurePortalAccountIsActive` đứng trước `Authenticate`. M8 chỉ **kiểm chứng lại có đo đạc**, gồm cả đường Livewire update, và ghi kết quả — không viết lại.

**R6 — `README.md` là sản phẩm bàn giao, không phải phụ lục.** Tiêu chí là một người ngoài dự án cài lại được trên máy chủ trống. Cách nghiệm thu duy nhất được chấp nhận: một agent **chưa từng đọc repo này** làm theo `README.md` trong một container sạch, và mọi chỗ nó phải đoán là một lỗi của tài liệu.

---

## Tasks

### - [ ] Task 1 — Proxy, HTTPS, HSTS, header (§10 mục 1, 2)

`TRUSTED_PROXIES` theo R1; ép HTTPS; `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`; CSP theo R4. Mỗi header một test nêu tên mục §10.

### - [ ] Task 2 — 2FA cho toàn bộ tài khoản nội bộ (§10 mục 7, R2)

Bắt buộc, không tắt được, có mã khôi phục, chuỗi tiếng Việt đã dịch sẵn từ M5. Test quét mọi đường tắt.

### - [ ] Task 3 — Rà soát rate limit và nhật ký hoạt động (§10 mục 3, 6)

Đăng nhập 5/15 phút theo email **và** IP (M5 đã làm, kiểm chứng lại sau khi R1 đổi nguồn IP — đây là chỗ hai thay đổi đúng có thể cộng lại thành một hành vi sai). Nộp tài liệu 20 tệp/giờ/tài khoản. API 60 request/phút. Activity log đủ tám loại sự kiện §10 mục 6, mỗi loại một test.

### - [ ] Task 4 — Dữ liệu cá nhân và tệp (§10 mục 4, 5)

Kiểm chứng `clients.id_number` cast `encrypted` và **không xuất hiện trong bất kỳ log nào** — quét log thật sau khi chạy luồng tạo khách hàng, không đọc mã bằng mắt. Kiểm chứng lại đĩa `private`, `.htaccess`, cấu hình nginx tương ứng, và chữ ký URL 5 phút vẫn kèm kiểm tra policy trong controller.

### - [ ] Task 5 — Sao lưu và khôi phục (§10 mục 8, R3)

`spatie/laravel-backup` hằng ngày cho cả CSDL lẫn thư mục tệp, đẩy ra disk ngoài máy chủ, giữ 30 bản, nối vào lịch M6. Rồi một lần khôi phục thật, có số đo.

### - [ ] Task 6 — Quét lại §10 mục 9 và 10 trên toàn hệ thống (R5)

Tài khoản bị vô hiệu mất phiên ở request kế tiếp, gồm đường Livewire. Và mục 10: không tồn tại với không có quyền phải **không phân biệt được**, ở mọi màn hình đã có sau bảy milestone — không chỉ ở portal. Danh sách màn hình dựng từ router, không từ trí nhớ.

### - [ ] Task 7 — `README.md` và hướng dẫn triển khai (§14 mục 8, R6)

Cài từ máy chủ trống, biến `.env` (mọi biến, kèm giá trị thật phải điền), một dòng cron, giám sát cron, sao lưu, khôi phục, nâng cấp. Nghiệm thu bằng một agent chưa đọc repo.

### - [ ] Task 8 — Nghiệm thu toàn hệ thống (SPEC §14)

Tám tiêu chí §14, từng cái một, có bằng chứng: độ phủ `app/Actions/` và `app/Policies/` ≥ 80% (đo, dán số); một dòng cron; luồng luật sư chuyển giai đoạn → khách nhận thư và thấy trên portal; luồng khách trên điện thoại; không đường nào thấy dữ liệu khách khác hay nhóm D; xung đột lợi ích chặn và để lại dấu vết; gói bàn giao đầy đủ; `README.md` đã nghiệm thu. Cập nhật `docs/PROGRESS.md` và rà soát toàn nhánh.

---

## Những chỗ đã biết trước là sẽ cắn

- **Hai thay đổi đúng cộng lại thành một hành vi sai.** R1 đổi nguồn của `request()->ip()`; ô đếm đăng nhập theo IP của M5 đọc chính giá trị đó. Sau Task 1, chạy lại toàn bộ test rate limit và đọc lại kết luận của chúng, đừng chỉ xem màu xanh.
- **CSP là mục dễ tick sai nhất §10.** Một header có mặt không có nghĩa là nó chặn được gì, và một CSP chặt quá làm hỏng nút bấm của khách hàng mà không ai thấy cho tới khi khách gọi điện.
- **`.env` thật không nằm trong repo.** Mọi biến M6–M8 thêm vào phải có mặt trong `.env.example` kèm một câu nói rõ giá trị thật lấy ở đâu, nếu không người triển khai sẽ bỏ trống đúng những biến quan trọng nhất.
- **Bốn thông tin pháp lý của văn phòng vẫn chưa có**: mã số thuế, Đoàn Luật sư, số Giấy đăng ký hoạt động, địa chỉ trụ sở. Chúng xuất hiện ở chân thư, ở PDF mục lục và ở `README.md`. Hỏi chủ văn phòng trước khi Task 7 bắt đầu.
