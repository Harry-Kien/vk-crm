# VK-CRM M6 — Kế hoạch thông báo và tác vụ định kỳ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hệ thống tự nhắc đúng người vào đúng lúc, bằng email tiếng Việt mang thương hiệu văn phòng, và **để lại bằng chứng đã gửi** cho từng thư. Mười mẫu email ở SPEC §9 có thật; bốn tác vụ định kỳ (§6.4, §6.8, §6.9, §4.18) chạy bằng đúng một dòng cron; cron chết thì trang chủ admin nói ra. Tiêu chí nghiệm thu SPEC §13 dòng M6: `schedule:test` sinh đúng email vào log.

Đây là milestone biến hệ thống từ "nơi tra cứu" thành "nơi nhắc". Trước M6, một mốc tố tụng còn 1 ngày và một hồ sơ đứng im 30 ngày trông **giống hệt nhau** với mọi người trong văn phòng.

**Architecture:** Không panel mới, không màn hình lớn mới. M6 thêm bốn thứ:

1. **Một cánh cửa duy nhất để thư đi ra** — `outbound_messages` (SPEC §4.15) vừa là nhật ký vừa là **trí nhớ chống gửi trùng**. Xem Phán quyết R1 và R3.
2. **Mẫu email** — `resources/views/emails/` trên một layout dùng chung có logo và chân trang (SPEC §9), chuỗi qua `lang/vi/`.
3. **Tác vụ định kỳ** — khai báo trong `routes/console.php`, mỗi tác vụ là một Action ở `app/Actions/Schedule/` để test gọi thẳng được mà không cần đi qua scheduler.
4. **Giám sát cron** — bảng `system_health`, heartbeat 5 phút, cảnh báo đỏ trên trang chủ admin (SPEC §2 "Giám sát cron").

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8, Pest 4, Pint. **Không gói mới.** Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §2 ("Giám sát cron", ràng buộc shared hosting và driver queue `database`), §4.13 (`deadlines`, cột `reminders_sent`), §4.15 (`outbound_messages`), §4.18 (dòng chưa xem quá 5 ngày thì nhắc luật sư gọi điện), §6.4 (SLA 14/21 ngày), §6.8 (`CheckDeadlines`), §6.9 (`RemindMissingDocuments`), §7.1 (widget trang chủ, mục 5 "Khách chưa xem cập nhật"), **§9 toàn bộ** (mười mẫu email, và câu "Email gửi cho khách chỉ chứa nội dung đã công bố, tuyệt đối không nhúng `internal_note`"), §10, §11 phần "Ghi chú nội bộ", §13 dòng M6.

---

## Ràng buộc toàn cục

- **Nhánh:** `m6-notifications`, cắt từ `main` **sau khi M5 đã merge**. M6 phụ thuộc cứng vào `stage_log_views` (M5 Task 2) cho Task 9 và vào `client_requests` (M5 Task 6) cho Task 4.
- PHP sàn **8.3**, cứng. Không Redis, Horizon, Octane, Reverb, Pulse, Scout, Telescope. Không supervisor, không worker thường trực **bắt buộc** — kiến trúc phải chạy được với đúng một dòng cron (SPEC §2).
- Nghiệp vụ chỉ ở `app/Actions/`. Command, job, listener, widget **chỉ gọi Action**. Một tác vụ định kỳ là một Action có `__invoke()`, không phải một closure trong `routes/console.php` — test phải gọi được nó mà không đi qua scheduler.
- Định danh mã tiếng Anh. **Mọi chuỗi hiển thị tiếng Việt qua `__()` và `lang/vi/`**, kể cả **tiêu đề thư**. Một tiêu đề thư tiếng Anh trong hộp thư của khách là thứ đầu tiên họ nhìn thấy.
- TDD với Pest: test đỏ trước. `Mail::fake()` / `Notification::fake()` và `travelTo()` cho mọi mốc thời gian. **Không test nào được phụ thuộc vào giờ chạy thật.**
- **Mutation probe cho mọi điều kiện được thêm** (luật đã có từ M3, nó bắt lỗi nhiều hơn mọi cách khác): xoá đúng điều kiện test nêu tên, chạy lại, dán bằng chứng ĐỎ vào báo cáo, khôi phục. Một test âm không có cặp dương đi kèm thì không tính.
- **Đọc lại từng docblock vừa viết, đối chiếu với mã, trước khi commit.** Lượt đọc đó tìm ra một lỗi thật ở **từng task một** của M3, M4 và M5, không sót task nào.
- Task nào đụng migration phải chạy trên **container MariaDB thật**: `migrate:fresh --seed`, rồi một vòng `migrate:reset` → `migrate`, dán nguyên văn output. SQLite không bao giờ bắt được ràng buộc index/khoá ngoại của MariaDB (dự án đã vỡ vì chuyện này hai lần).
- Kết thúc mỗi task: `bin/dev test` xanh, `bin/dev pint` sạch, commit **chỉ tệp của mình theo đường dẫn tường minh** (`git commit -- <path>`), không bao giờ `git add -A` và không bao giờ commit trần — index dùng chung đã ba lần cuốn hunk của người khác vào commit của người này.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` (chép nguyên văn; đây là dòng cho subagent Opus).
- Người rà soát cuối cùng được brief là **giả định có một lỗi Critical**.

---

## Phán quyết của chủ nhiệm (đã quyết, đừng dừng lại hỏi)

**R1 — Nhật ký thư ghi bằng sự kiện của framework, không bằng thiện chí của người gọi.**
`outbound_messages` được ghi bởi một listener nghe `Illuminate\Mail\Events\MessageSending` và `MessageSent`, **không** bởi từng nơi gửi thư. Lý do: một quy ước "nhớ ghi nhật ký" là thứ sẽ bị quên ở mẫu thứ mười một, và cột nhật ký này tồn tại để trả lời câu "tôi không nhận được thông báo" — nó phải đúng kể cả với thư mà không ai nhớ là mình gửi. `template` và `related_type`/`related_id` đi kèm thư qua **header** do một Mailable cơ sở dùng chung đặt; thư nào không mang header vẫn được ghi, với `template` là một giá trị nói rõ là không khai báo. **Test bắt buộc:** một `Mail::raw()` trần cũng phải sinh ra một dòng — nếu không, cánh cửa này có lỗ.

**R2 — Xếp hàng hay không.** `QUEUE_CONNECTION=database`. Thư **không** xếp hàng: OTP đăng nhập (đã phán quyết ở M5 — một mã 5 phút nằm trong bảng `jobs` chờ cron là một mã chết). Mọi thư còn lại xếp hàng. `routes/console.php` phải có một dòng gọi `queue:work --stop-when-empty` để shared hosting không cần worker thường trực (SPEC §2). Dòng đó có `withoutOverlapping()`.

**R3 — Nhật ký chính là trí nhớ chống gửi trùng.** Trừ `deadlines.reminders_sent` mà SPEC §4.13 đã chỉ định sẵn, **không thêm cột "đã nhắc lúc nào" ở đâu nữa**. "Đã nhắc matter này trong 3 ngày qua chưa" là một câu truy vấn trên `outbound_messages` theo `template` + `related` + `sent_at`. Lý do: hai trí nhớ về cùng một sự việc sẽ lệch nhau, và cái lệch đó im lặng. Hệ quả phải ghi trong docblock: nếu một thư **thất bại**, nó vẫn là một dòng — nên truy vấn chống trùng chỉ tính `status = sent`.

**R4 — Mọi tác vụ định kỳ phải chạy hai lần liên tiếp mà không sinh thư thứ hai.** Cron gọi trùng là chuyện thường (gia hạn gói, đổi múi giờ, người quản trị chạy tay). Mỗi task ở đây có một test chạy Action **hai lần** và khẳng định số thư không đổi.

**R5 — Tần suất nhắc lại, chỗ SPEC im lặng.** SLA §6.4: mốc 14 ngày sinh thông báo trong hệ thống **một lần cho mỗi đợt đình trệ** (đợt mới bắt đầu khi có một cập nhật cho khách); mốc 21 ngày gửi email **7 ngày một lần** trong lúc còn đình trệ. Lý do: một email mỗi ngày về cùng một hồ sơ dạy người ta bỏ qua email, và thứ bị bỏ qua thì không phải là nhắc.

**R6 — Thư cho khách chỉ chứa nội dung đã công bố.** Mỗi mẫu thư gửi khách phải có một test đặt chuỗi đánh dấu duy nhất vào `internal_note` (và các cột ở ranh giới phơi bày §6.10) rồi khẳng định nó không xuất hiện trong thân thư, tiêu đề, lẫn văn bản thuần. Và một ranh giới thứ hai, về **người nhận**: thư về hồ sơ X chỉ đi tới `client_users` của khách hàng sở hữu X, đang `is_active`. Test này soi bằng mutation, không bằng mắt.

**R7 — Nội dung thư nói phải làm gì, không nói chuyện đã xảy ra.** SPEC §8 cấm thuật ngữ; §9 nói "chi tiết mời bấm vào portal". Một thư nhắc thiếu giấy tờ liệt kê **đúng những gì còn thiếu** bằng tên người thường đọc được, kèm một liên kết. Không mã hồ sơ trần trong tiêu đề mà không có ngữ cảnh.

---

## Tasks

### - [ ] Task 1 — Cánh cửa thư và layout thương hiệu

Cài `outbound_messages` thành hạ tầng thật: enum `OutboundChannel`/`OutboundStatus` (backed string, có `label()`), model `OutboundMessage`, listener ghi nhật ký theo R1, Mailable cơ sở đặt header `template`/`related`, layout `resources/views/emails/layout.blade.php` dùng `config('vkcrm.brand')` (logo thật ở `public/brand/`, chân trang có tên pháp lý đầy đủ, hotline, website). Chuyển `client.otp` của M5 sang layout này **mà không** làm nó xếp hàng. Không migration (bảng đã có từ M1).

Kiểm chứng: `Mail::raw()` trần sinh một dòng; thư thất bại sinh `status = failed` kèm `error`; OTP vẫn gửi đồng bộ (test của M5 còn xanh); layout render được khi các trường thương hiệu tuỳ chọn còn trống (mã số thuế, số giấy ĐKHĐ — chủ văn phòng chưa cung cấp).

### - [ ] Task 2 — Bảng sức khoẻ, heartbeat, và dây cót của scheduler

Migration `system_health` (một dòng, `last_schedule_run_at`) và `notifications` (thông báo trong hệ thống của Filament — `make:notifications-table`). Bật `databaseNotifications()` **chỉ trên panel admin**, không bao giờ trên portal. Khai báo trong `routes/console.php`: chạm `last_schedule_run_at` mỗi phút, heartbeat GET `HEARTBEAT_URL` mỗi 5 phút (timeout ngắn, **không bao giờ ném** — một dịch vụ giám sát chết không được làm chết lịch), `queue:work --stop-when-empty` theo R2. Widget trang chủ admin cảnh báo đỏ khi `last_schedule_run_at` cũ hơn 30 phút, và khi **chưa bao giờ** chạy.

Task có migration → bắt buộc vòng MariaDB thật, dán output.

### - [ ] Task 3 — Bốn mẫu thư cho khách, kích hoạt bởi hành động

`client.activation`, `client.stage_update`, `client.document_published`, `client.document_rejected`. Mỗi mẫu nối vào Action đã có (`TransitionMatterStage`, `PublishDocument`, `ReviewChecklistItem`). **Lỗ hổng phải lấp trong task này:** hôm nay tài khoản portal được tạo bằng cách một luật sư **gõ tay mật khẩu vào form** rồi đọc cho khách qua điện thoại — không có thư kích hoạt nào cả. Thêm Action `App\Actions\Client\IssuePortalAccess` sinh mật khẩu tạm, đặt `must_change_password`, gửi `client.activation`, và **bỏ ô mật khẩu khỏi form tạo** (giữ đường đặt lại cho luật sư, nhưng cũng đi qua Action và cũng gửi thư). Áp dụng R6 cho cả bốn mẫu.

### - [ ] Task 4 — Hai mẫu thư cho nhân sự, kích hoạt bởi khách

`staff.new_client_document` (khách nộp tài liệu — `SubmitClientDocument`), `staff.new_client_request` (khách gửi yêu cầu — `OpenClientRequest` của M5 Task 6), kèm thông báo trong hệ thống cho lead lawyer và người phụ trách. Người nhận suy từ đội ngũ vụ việc, không hardcode vai trò.

### - [ ] Task 5 — Màn hình mốc thời hạn (SPEC §7.2 tab "Mốc thời hạn")

**Chỗ trống phát hiện khi rà soát toàn hệ thống ngày 2026-09-22, và nó chặn cả Task 6.** Bảng `deadlines` có từ M1, policy có từ M2, cổng khách đọc được từ M5 — nhưng **không có một màn hình nào để tạo một mốc hạn**. Không có màn hình thì không có dữ liệu, và `CheckDeadlines` sẽ chạy hằng ngày trên một bảng rỗng mà vẫn xanh. Đây là hình dạng lỗi tệ nhất: một tính năng đúng, chạy đều, và vô nghĩa.

Relation manager "Mốc thời hạn" trên trang chi tiết vụ việc: danh sách theo ngày, **thêm nhanh** (SPEC nói "thêm nhanh" — ít trường bắt buộc), đánh dấu hoàn thành, mức độ `severity`, người phụ trách mặc định là luật sư phụ trách vụ việc, và công tắc công bố cho khách. Nghiệp vụ trong Action, màn hình chỉ gọi. Quá hạn tô đỏ, sắp đến hạn tô vàng — bằng inline style, vì không có bước build CSS.

### - [ ] Task 6 — `CheckDeadlines` (SPEC §6.8)

Bậc 7/3/1/quá hạn, thêm bậc 14 khi `severity = critical`; `reminders_sent` chống trùng; quá hạn thì đánh dấu và sinh cảnh báo. Mẫu `staff.deadline_reminder`. Test theo R4 và bằng `travelTo()` qua từng mốc, gồm **mốc bị nhảy qua** (cron chết 3 ngày rồi chạy lại: hệ thống phải nhắc mốc gần nhất còn ý nghĩa, không im lặng bỏ qua). Lịch 07:00 hằng ngày.

### - [ ] Task 7 — `CheckStaleMatters` (SPEC §6.4)

14 ngày → thông báo trong hệ thống cho lead lawyer; 21 ngày → email cho lead lawyer, đồng gửi mọi `manager`; theo R5 về tần suất. `StaleMattersWidget` đã có từ M3 — **kiểm chứng nó dùng chung đúng một định nghĩa "quá hạn cập nhật"** với job này, không hai định nghĩa (M4 đã tìm ra đúng hình dạng lỗi đó ở thanh X/Y). Lịch 07:30 hằng ngày.

### - [ ] Task 8 — `RemindMissingDocuments` (SPEC §6.9)

Thứ Hai/Tư/Sáu 08:00. Chỉ matter đang mở, đã công bố portal, còn item **bắt buộc** ở `missing`/`rejected`. Liệt kê đúng những gì thiếu (R7). Không quá một thư mỗi 3 ngày cho cùng một matter (R3). Thiếu kéo dài quá 14 ngày → báo lead lawyer để gọi điện. Dùng đúng một nguồn sự thật về "còn thiếu": `App\Actions\Document\ChecklistProgress`.

### - [ ] Task 9 — Nhắc dòng tiến độ khách chưa xem (SPEC §4.18, §7.1 mục 5)

Dòng đã công bố quá 5 ngày mà `stage_log_views` chưa có dòng nào → nhắc luật sư phụ trách **gọi điện**, không gửi thêm thư cho khách. Lý do đã nằm trong SPEC: khách không xem thường là khách không dùng được portal, và thứ cần là một cuộc gọi. Widget "Khách chưa xem cập nhật" của M5 dùng chung định nghĩa này.

### - [ ] Task 10 — Nghiệm thu, tài liệu, cổng merge

Chạy `schedule:list` và `schedule:test` cho **từng tác vụ** trên dữ liệu seed thật trong container, dán nguyên văn output và các thư sinh ra trong log. Cập nhật `docs/PROGRESS.md` theo đúng lối M3/M4 (đường đi thật, số đo thật). Rà soát toàn nhánh, brief "giả định có một Critical". Cập nhật `.env.example` với mọi biến mới; điền giá trị thật lúc triển khai là việc của M8.

---

## Những chỗ đã biết trước là sẽ cắn

- **Múi giờ.** Lịch chạy theo `APP_TIMEZONE`; 07:00 phải là 07:00 giờ Việt Nam trên máy chủ đặt ở đâu cũng vậy. Kiểm chứng bằng test, không bằng niềm tin.
- **`schedule:run` trùng nhau.** Một tác vụ chạy 70 giây trong khi cron gọi mỗi phút sẽ chồng lên chính nó. `withoutOverlapping()` cho mọi tác vụ có thể chạy lâu, và mutex nằm ở cache driver `database` — kiểm chứng nó tồn tại qua nhiều tiến trình PHP.
- **Một người có thể là đại diện của hai khách hàng.** `client_users.email` là `unique`, nên khi đó họ có hai tài khoản với hai địa chỉ. Thư phải nói rõ **hồ sơ nào** (R7), vì người đọc không suy ra được từ tiêu đề.
- **Portal không có đường đặt lại mật khẩu** (ghi nhận từ M5). Thư `client.activation` là con đường duy nhất một khách có mật khẩu. Nếu M6 làm hỏng nó, khách mới không vào được hệ thống.
