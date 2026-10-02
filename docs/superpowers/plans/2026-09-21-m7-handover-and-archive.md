# VK-CRM M7 — Kế hoạch bàn giao, lưu trữ và tìm kiếm

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Sửa ngày 2026-09-24**, sau đợt kiểm tra quy trình (`docs/audits/2026-09-24-quy-trinh.md`) và đối chiếu kế hoạch này với SPEC và mã thật. Những thay đổi chính:
> - Task 1 và R6 đã chuyển sang M6.5 (một vụ việc).
> - `matter_archives` đã có từ M1.
> - Gói bàn giao là một `Document` (R1 mới), và nội dung gói có luật riêng (R8).
> - Bổ sung: ghi quyết định tiêu huỷ (Task 6), trạng thái rút tài liệu (Task 7), index cho tìm kiếm (Task 9), trang "Thông tin văn phòng" sửa được trong app (Task 10, theo quyết định của chủ văn phòng cùng ngày).
> - Bỏ cảnh báo `CommunicationLogPolicy::view`, vì đã sửa ở d069424.

**Goal:** Hồ sơ đi hết vòng đời của nó.
- Một luật sư nghỉ việc thì vụ việc của họ chuyển người mà không rơi mất mốc hạn nào.
- Một vụ việc kết thúc thì khách nhận được **một gói bàn giao có mục lục**. Đây là thứ khách nhớ rất lâu, và gần như không văn phòng nào làm.
- Quyền tra cứu của khách hết hạn đúng ngày.
- Hồ sơ tới hạn tiêu huỷ thì hệ thống **báo cho người quyết định, chứ không bao giờ tự xoá**. Quyết định tiêu huỷ, khi có, được ghi lại kèm biên bản.
- Một ô tìm kiếm tìm được vụ việc bằng bất cứ thứ gì người ta nhớ.

Tiêu chí nghiệm thu SPEC §13 dòng M7: test phần "bàn giao và lưu trữ" xanh, **giải nén gói bàn giao kiểm tra được**.

**Architecture:** Không panel mới. M7 thêm:
- Action vòng đời trong `app/Actions/Matter/`;
- một job sinh gói, chạy trong **hàng đợi riêng**;
- hai tác vụ định kỳ nối vào lịch M6 đã dựng;
- hai tab trên trang vụ việc;
- một trang tìm kiếm trên admin.

Gói bàn giao là một tài liệu như mọi tài liệu khác (R1). Nó nằm trên đĩa `private` của M4 và chỉ tải được qua đường ký có sẵn.

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8, Pest 4, Pint. **Một gói mới duy nhất được phép**: một thư viện PDF thuần PHP (xem R3). Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md`:
- §4.11 (nhóm tài liệu A/B/C/D), §4.17 (`communication_logs`), §4.19 (`matter_archives`);
- §6.11 (`ReassignMatter`), §6.12 (`GenerateHandoverPackage`, `ExpireClientAccess`, `FlagRetentionExpiry`), §6.13 (tìm kiếm);
- §7.2 (tab Liên lạc và Nhật ký), §7.4, §10.6 ("xuất dữ liệu");
- §11 phần "Bàn giao và lưu trữ"; §13 dòng M7.

---

## Ràng buộc toàn cục

- **Nhánh:** `m7-handover-and-archive`, cắt từ `main` **sau khi M6.5 và phần còn lại của M6 đã merge**.
  - Thứ tự hiện hành: M6.5 → M6 (Task 3, 4, 7, 8, 9, 10) → M7.
  - M7 phụ thuộc cứng vào: hạ tầng thư xếp hàng của M6.5 (R2, Task 11); luật người nhận `ResolveStaffRecipients` (M6.5 R3, Task 8); `closed_at` và `Matter::scopeOpen()` (M6.5 R8, Task 5); `ReassignMatter` một vụ (M6.5 R7, Task 4); thư `client.document_published` (M6 Task 3); đĩa `private` và đường tải ký của M4.
- PHP sàn **8.3**, cứng.
- Không Elasticsearch, Meilisearch, Scout. SPEC §6.13 cấm thẳng, vì ràng buộc shared hosting. `LIKE` là đủ ở quy mô vài nghìn hồ sơ (xem Task 9 về index).
- Nghiệp vụ chỉ ở `app/Actions/`. Job, listener và màn hình chỉ gọi Action.
- Mọi chuỗi hiển thị tiếng Việt qua `__()` và `lang/vi/`, **gồm cả chữ trong PDF mục lục**.
- **Mang từ M6.5, áp nguyên cho M7:**
  - Test cho màn hình đi qua Livewire hoặc HTTP, không gọi thẳng Action.
  - Trang Filament tự viết tự hỏi `Gate::forUser($account)` trong `canAccess()` và ở mọi chỗ resolve record, rồi `abort(404)`.
  - Giới hạn độ dài của form bằng độ dài cột DB.
  - Chỉ style nội tuyến trên biến CSS của Filament. Không có bước build CSS.
  - Mọi thư đi qua hàng đợi, sau khi commit (M6.5 R2).
  - Thư cho khách chỉ tới tài khoản `is_active` **và** `activated_at` không null (M6.5 R12).
- TDD với Pest, mutation probe cho mọi điều kiện mới, đọc lại docblock đối chiếu mã trước khi commit.
- Task có migration chạy vòng MariaDB thật (`migrate:fresh --seed`, rồi `migrate:reset` → `migrate`), dán output.
- Task đụng khoá, collation hay tìm kiếm tiếng Việt chạy thêm `bin/dev test:mariadb`, **tuần tự**.
- `git commit -- <path>`, không bao giờ `add -A`, không bao giờ commit trần.
- Trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, chép nguyên văn.
- Người rà soát mỗi task và rà soát cuối được brief **giả định có một Critical**.
- **Bốn thông tin pháp lý của văn phòng** (mã số thuế, Đoàn Luật sư, số Giấy đăng ký hoạt động, địa chỉ trụ sở) **không chặn milestone này.** Chủ văn phòng quyết ngày 2026-09-24: chưa cần, sẽ tự nhập sau trong app (Task 10). Chân `MUC-LUC.pdf` và chân thư của M6 phải hiển thị đúng khi chúng còn trống: bỏ hẳn dòng trống, không in nhãn thiếu giá trị.

---

## Phán quyết của chủ nhiệm

**R1 — Gói bàn giao là một bản ghi `Document`, không phải một đường dẫn tệp.** Có ba lý do, đều đã kiểm trên mã:
- Đường tải duy nhất (`routes/web.php`, `documents.download`) nhận id của một `Document`.
- `document_downloads.document_id` là khoá ngoại tới `documents`.
- `PublishDocument` là cửa công bố duy nhất, và M6 Task 3 đã gắn thư `client.document_published` vào đó.

Nếu gói là một chuỗi đường dẫn trong `matter_archives.handover_package_path`, sẽ phải dựng cửa tải thứ hai và bảng nhật ký tải thứ hai. SPEC §4.12 ("ghi log **mọi** lượt tải") và M4 không cho phép điều đó. Cụ thể:
- Gói là một `Document` **nhóm B** (văn bản do văn phòng phát hành), tệp gắn qua medialibrary, collection `file`, đĩa `private`.
- Job tạo nó ở trạng thái `signed_filed`. Gói chỉ gồm tài liệu đã phát hành hoặc đã công bố (R8), nên nó không phải một bản nháp. Luật sư xem lại rồi bấm công bố qua **đúng `PublishDocument`**.
- Migration thêm `matter_archives.handover_document_id` (FK `documents`, nullable). `handover_package_path` không dùng nữa. Ghi đính chính SPEC §4.19.
- Sinh lại gói là một version mới của cùng tài liệu (`parent_document_id`), không phải một tài liệu thứ hai.
- Tài liệu gói không bao giờ nằm trong một gói sau.

**R2 — Nhóm D không bao giờ vào gói, và điều đó phải chứng minh bằng cách giải nén.** SPEC §11 yêu cầu đúng chữ "giải nén và khẳng định".
- Test mở tệp zip thật bằng `ZipArchive`, liệt kê toàn bộ entry, và khẳng định không entry nào thuộc nhóm D.
- Không khẳng định trên mảng mà code vừa dựng trước khi nén. Khẳng định trên đầu vào của hàm nén không nhìn thấy lỗi của hàm nén.

**R3 — PDF sinh bằng thư viện thuần PHP, và tiếng Việt phải hiện đúng dấu.** Không Browsershot, không Chromium, không Node, vì vi phạm ràng buộc shared hosting.
- Đã kiểm chứng trong container ngày 2026-09-21: `composer require --dry-run barryvdh/laravel-dompdf` giải được trên sàn PHP 8.3, khoá v3.1.2 cùng bốn gói phụ thuộc (dompdf v3.1.6, php-font-lib, php-svg-lib, sabberworm/php-css-parser), không cảnh báo lỗ hổng. Dùng gói này, đừng khảo sát lại.
- Bẫy đã biết: font mặc định của dompdf rơi mất dấu tiếng Việt.
  - Nhúng DejaVu Sans (có sẵn trong dompdf, đủ dấu tiếng Việt), hoặc commit tệp TTF Be Vietnam Pro (giấy phép OFL). Repo hiện chỉ có Inter dạng woff2, dompdf không đọc được.
  - Thư mục cache font phải ghi được.
- Test bằng cách trích lại chuỗi từ PDF đã sinh và so với chuỗi gốc có dấu, không bằng cách nhìn ảnh.
- dompdf cần `ext-dom`. Ghi vào danh sách extension ở M8 Task 7.

**R4 — `ExpireClientAccess` làm khách mất quyền xem, không làm mất dữ liệu.** Vụ việc biến mất khỏi portal nhưng còn nguyên trong admin.
- Cài bằng **một điều kiện thêm vào ranh giới portal đã có**, ở cả hai tầng, độc lập như luật ba tầng của M5:
  - `Matter::applyClientPortalConstraints()`: đã có sẵn chỗ chờ "M7 bổ sung điều kiện client_access_until ở đây";
  - `MatterPolicy::releasedToPortal()`.
- Không sửa dữ liệu vụ việc.
- Chỉ vô hiệu hoá tài khoản của khách **có ít nhất một vụ đã hết hạn tra cứu và không còn vụ nào hiển thị trên portal**. Một khách mới có vụ đầu tiên chưa công bố cũng "không có vụ nào trên portal", nhưng tuyệt đối không bị vô hiệu hoá.
- Vô hiệu hoá bằng cách lưu từng model, để LogsActivity ghi lại (SPEC §10.6 "vô hiệu hoá tài khoản portal"). `update()` hàng loạt bỏ qua log.

**R5 — `FlagRetentionExpiry` không bao giờ xoá.** Nó cảnh báo.
- Việc tiêu huỷ hồ sơ pháp lý do người quyết định và có biên bản. Quyết định đó được **ghi lại** bằng một Action riêng (Task 6), không bằng việc xoá.
- Bất kỳ mã nào trong milestone này gọi `forceDelete()` trên dữ liệu hồ sơ là sai.

**R6 — Chặn vô hiệu hoá nhân sự còn giữ việc: đã làm ở M6.5** (R7, Task 4). M7 chỉ thêm vào thông điệp chặn một liên kết tới màn hình bàn giao hàng loạt, khi Task 2 có màn hình đó.

**R7 — Tìm kiếm đi qua policy, luôn luôn.** Kết quả tìm kiếm là nơi rò rỉ dễ nhất và khó thấy nhất, vì nó gộp sáu nguồn.
- Mỗi nguồn có test riêng cho cả hai chiều: người có quyền thấy; người không có quyền **không thấy cả sự tồn tại**. Không đếm, không gợi ý, không thông báo "có kết quả bị ẩn".
- Tiêu đề tài liệu nhóm D chỉ trả cho người có `document.viewInternal`.
- Kế toán không nhận kết quả nào từ tên các bên hay tiêu đề tài liệu, vì kế toán "không thấy nội dung hồ sơ" (SPEC §1).

**R8 — Nội dung gói.** SPEC §6.12 nói "toàn bộ tài liệu nhóm A, B, C". Nhưng §4.11 tồn tại để khách không bao giờ thấy "một bản đơn mà toà chưa hề nhận được". Phán quyết:
- **Nhóm A:** mọi tệp của version mới nhất đã được chấp nhận của mỗi đầu mục (một lần nộp có thể nhiều tệp, M6.5 R10). Bỏ version bị từ chối và version đã bị thay.
- **Nhóm B và C:** chỉ tài liệu ở `signed_filed` hoặc `published`.
- **Không bao giờ:** nhóm D; tài liệu đã xoá mềm; tài liệu đã rút (Task 7); chính tài liệu gói của lần trước.
- **Tên entry trong zip:** `<nhóm>/<NN>-<FileGuard::safeName(tiêu đề)>.<đuôi>`, với `NN` là số thứ tự trong mục lục. Tiêu đề không duy nhất, và có thể chứa `/` hoặc `..`. Tên theo số thứ tự loại cả hai rủi ro, và cho mục lục với zip cùng một cách đánh số.
- Ghi đính chính SPEC §6.12.

**R9 — Sinh gói không được chặn thư.** Hôm nay mọi job dùng chung một lượt `queue.drain`, có `withoutOverlapping()` (`routes/console.php`). Một job nén vài trăm MB sẽ giữ lượt đó, trong lúc thư nhắc mốc hạn (loại việc SPEC gọi là rủi ro nghề nghiệp cao nhất) phải đứng chờ. Vì vậy:
- Job gói chạy trên hàng đợi `handover`, bằng một mục lịch riêng, với `$timeout` và `$tries` tường minh.
- Cả hai mục lịch dùng `withoutOverlapping(<phút>)` có hạn. Mặc định Laravel giữ khoá tới 24 giờ khi tiến trình chết giữa chừng.
- Job thất bại hẳn thì báo luật sư phụ trách qua `ResolveStaffRecipients`, và màn hình hiện trạng thái "đang sinh / lỗi", để luật sư không bấm lại liên tục.

**R10 — Đọc SPEC §6.11 bước 3 theo M6.5.** SPEC ghi chuyển "toàn bộ" mốc hạn sang người mới. M6.5 Task 4 chỉ chuyển mốc **chưa hoàn thành**: mốc đã xong là lịch sử của người cũ, chuyển nó là viết lại ai đã làm việc gì. M7 giữ cách đọc này và ghi đính chính SPEC §6.11 và §11.

---

## Tasks

### - [x] Task 1 — Phần còn lại của `ReassignMatter` (SPEC §6.11)

**Đã làm ở M6.5 Task 4 (một vụ):** đổi `lead_lawyer_id` và `matter_user`, dòng `stage_logs` nội bộ, chuyển mốc hạn và yêu cầu khách chưa đóng, audit, và luật chặn vô hiệu hoá (R6). **Không viết lại.**

Còn lại:
- Nếu M6.5 Task 4 ghi rằng thư tổng hợp mốc hạn cho người nhận bị hoãn, vì hạ tầng thư của M6.5 Task 11 chưa có lúc đó, thì làm thư đó ở đây. Thư đi qua hàng đợi, người nhận theo R3.
- Ghi đính chính SPEC §6.11 bước 3 và §11 theo R10.

### - [x] Task 2 — Màn hình bàn giao hàng loạt

- Chọn nhiều vụ việc của một luật sư và bàn giao cùng lúc, qua **đúng `ReassignMatter`**. Một vòng lặp gọi Action, không phải một truy vấn `update()` hàng loạt: mỗi vụ phải có dòng `stage_logs` và mốc hạn của nó.
- Báo cáo kết quả từng vụ, kể cả vụ thất bại, thay vì một thông điệp chung.
- Người nhận nhận **một** thư tổng hợp cho cả lô, không phải một thư mỗi vụ.
- Với mỗi vụ đã công bố portal, hiện gợi ý soạn dòng cập nhật giới thiệu luật sư mới (SPEC §6.11 bước 4). Chỉ gợi ý, không tự gửi.
- Vụ `restricted` chỉ chọn được khi người bấm được bàn giao nó.
- Thêm liên kết tới màn hình này vào thông điệp chặn vô hiệu hoá của M6.5 (R6).
- Test Livewire cho các điểm trên.

### - [x] Task 3 — Lưu trữ khi vụ việc kết thúc

**Bảng `matter_archives` đã có từ M1** (migration `2026_09_14_000020`), cùng model, policy (portal `1 = 0`) và factory. Không tạo lại bảng.

- **Migration:**
  - thêm `handover_document_id` (R1);
  - thêm các cột ghi quyết định tiêu huỷ mà Task 6 cần (`destruction_reason`, `destruction_record_no`, `destroyed_by`);
  - vòng MariaDB thật.
- **Kích hoạt:** listener `afterCommit` khi `TransitionMatterStage` đưa vụ vào giai đoạn `is_terminal` (M6.5 R8). Listener gọi một Action tạo hoặc cập nhật bản ghi archive:
  - `archived_at = now()`;
  - `client_access_until = closed_at + config('vkcrm.client_access_days')`;
  - `retention_until = closed_at + config('vkcrm.retention_years')`.

  Cả hai giá trị cấu hình đã có trong `config/vkcrm.php` và `.env.example`. Không viết số cứng.
- **Mở lại rồi đóng lại:** đường bỏ qua của admin (M6.5 R8) xoá `closed_at`.
  - Khi đó bản ghi archive được **cập nhật**: `client_access_until = null`, để `ExpireClientAccess` không giấu một vụ đang sống.
  - **Không xoá mềm bản ghi:** `unique(matter_id)` trên MariaDB tính cả dòng đã xoá mềm, nên lần đóng sau sẽ lỗi.
  - Test chuỗi đóng → mở lại → đóng lại, trên cả `test:mariadb`.
- **Seeder:** thêm một vụ đã kết thúc, có tài liệu nhóm A, B, C, D, một tài liệu nhóm B còn `internal_draft`, và một tài liệu đã xoá mềm. `MatterSeeder` hiện loại giai đoạn kết thúc, nên nếu thiếu bước này Task 10 không có gói thật để giải nén.
- **Sửa 2026-09-27, sau M6.5 (Task 21) — hai việc M6.5 hoãn sang đây, vì chúng thuộc "vụ đã đóng":**
  - **Danh mục hồ sơ của vụ đã đóng.** Thêm đầu mục, duyệt, từ chối và "không áp dụng" trên một vụ có `closed_at` hiện không bị chặn ở đâu (có từ trước M6.5; SPEC im lặng; R8 chỉ định nghĩa `closed_at`). Phán quyết Task 15 của M6.5 hoãn sang M7. Task này quyết vụ đã đóng là chỉ đọc với danh mục (đề xuất), chặn ở Action, và thông điệp tiếng Việt chỉ đường mở lại vụ.
  - **Đổi `is_terminal` của một giai đoạn** trên màn hình cấu hình không cập nhật các vụ đang đứng ở giai đoạn đó (việc nhỏ hoãn lại của M6.5 Task 5): vụ đang ở giai đoạn vừa thành "kết thúc" không có `closed_at`, và ngược lại. Hoặc chặn đổi cờ khi giai đoạn đang có vụ, hoặc đồng bộ `closed_at` và bản ghi archive trong cùng Action; chọn một và ghi lý do.

### - [x] Task 4 — `GenerateHandoverPackage` (SPEC §6.12, R1, R8, R9)

Job trên hàng đợi `handover` (R9) dựng zip theo R8, kèm `MUC-LUC.pdf` sinh tự động (R3). `MUC-LUC.pdf` gồm:
- thông tin vụ việc;
- danh sách tài liệu có đánh số, khớp số trong tên entry;
- **toàn bộ dòng thời gian tiến độ đã công bố** (`public_content`), không có gì khác;
- chân trang có bốn thông tin pháp lý khi chúng đã có.

Kết quả là một `Document` nhóm B ở `signed_filed` (R1). Xong thì báo luật sư phụ trách: thư xếp hàng, người nhận theo `ResolveStaffRecipients`. Luật sư xem lại rồi bấm công bố qua `PublishDocument`; lúc đó khách mới tải được từ portal và nhận thư `client.document_published` của M6 Task 3.

**Audit:** ghi `data_exported` khi sinh gói và khi gói được tải (SPEC §10.6 "xuất dữ liệu"). Đây là tính năng xuất dữ liệu đầu tiên của hệ thống.

**Kiểm chứng bắt buộc:**
- Giải nén tệp thật. Tên tệp tiếng Việt có dấu còn nguyên trong zip.
- Một tài liệu nhóm D, một tài liệu đã xoá mềm và một tài liệu nhóm B còn `internal_draft` đều vắng mặt.
- Chữ có dấu trong PDF trích lại đúng.
- Một chuỗi đánh dấu đặt trong `internal_note` và trong dòng bàn giao nội bộ của `ReassignMatter` **không** xuất hiện trong văn bản trích từ PDF, cũng như trong zip (SPEC §11 "Ghi chú nội bộ").
- Hai tài liệu cùng tiêu đề, và một tiêu đề chứa `../`: entry đúng, không đè nhau, không thoát khỏi thư mục nhóm.
- Job lỗi giữa chừng: không để lại tệp dở dang, luật sư được báo, bấm sinh lại được.

### - [x] Task 5 — `ExpireClientAccess` (R4)

- Tác vụ hằng ngày. Đăng ký lịch, kèm test ghim giờ chạy như M6.5 Task 14 đã làm cho `CheckDeadlines`.
- Quá `client_access_until` thì vụ việc rời portal ở **cả hai tầng**. Mỗi tầng một điều kiện độc lập, mỗi tầng một mutation probe. Sửa docblock `MatterPolicy` từ "bốn điều kiện" thành năm (M6.5 Task 2 đã thêm điều kiện thứ tư: khách chưa bị xoá mềm).
- Tài khoản bị vô hiệu hoá theo đúng điều kiện ở R4.
- **Test:**
  - dữ liệu còn nguyên trong admin panel;
  - khách mới có vụ chưa công bố không bị vô hiệu hoá;
  - một URL tải có chữ ký phát ra trước ngày hết hạn trả 404 sau ngày hết hạn;
  - việc vô hiệu hoá có dòng trong activity log.

### - [x] Task 6 — `FlagRetentionExpiry` và ghi quyết định tiêu huỷ (R5)

- **`FlagRetentionExpiry`:** cảnh báo quản trị khi có hồ sơ quá `retention_until` mà `destroyed_at` còn null. Không xoá.
- **Action `RecordMatterDestruction`**, chỉ admin: ghi `destroyed_at`, người quyết định, lý do và số biên bản, cùng một dòng audit. Action này **không xoá gì**. Việc huỷ vật lý hồ sơ giấy và tệp là thao tác có biên bản ngoài hệ thống. Sau khi đã ghi quyết định, `FlagRetentionExpiry` bỏ qua vụ đó, để cảnh báo không lặp mãi.
- **Test:**
  - bản ghi vẫn còn sau khi job chạy;
  - vụ đã ghi `destroyed_at` không còn bị cảnh báo;
  - người không phải admin không gọi được Action;
  - một test cấu trúc quét **lời gọi** `->forceDelete(` trên các model hồ sơ. Không quét chuỗi `forceDelete`: `ForceDeleteBulkAction` bị policy chặn vẫn hợp lệ trong bảng.

### - [x] Task 7 — Rút lại tài liệu đã công bố (`RetractDocument`)

Món nợ mang từ M4 sang (`docs/docs-6`): hôm nay một tài liệu công bố nhầm cho khách **không có đường rút lại đúng nghiệp vụ**.

- Thêm case `retracted` vào `DocumentStatus`, có `label()`. Ghi đính chính SPEC §4.11.
- Action đặt trạng thái rút, tắt hai cờ hiển thị, và ghi:
  - lý do tối thiểu 20 ký tự (đếm bằng `mb_strlen`);
  - người rút;
  - thời điểm rút.
- Action giữ nguyên tệp và giữ nguyên `document_downloads` đã có. Bằng chứng khách đã tải là thứ không được phép biến mất.
- **Khách thấy một dòng trên portal** ở chỗ tài liệu từng hiện: "Văn phòng đã rút lại tài liệu này. Lý do: …". Khoảng trống không lời giải thích làm khách nghĩ tài liệu bị mất.
- **Một đường rút duy nhất.** Với tài liệu đã công bố, "chuyển sang nhóm D" và "xoá" hoặc đi qua `RetractDocument`, hoặc bị chặn kèm thông điệp chỉ tới nút Rút. Đây là hai "đường rút tạm thời" mà PROGRESS ghi ở M4. M6.5 Task 21 sửa câu tương ứng trong PROGRESS.
- **Trước hết, sửa khoá ngoại `document_downloads.document_id` khỏi `cascadeOnDelete`.** Nếu không, xoá một tài liệu sẽ xoá luôn bằng chứng tải của nó. Đây là migration, nên áp dụng luật MariaDB thật.

### - [ ] Task 8 — Nhật ký liên lạc và nhật ký riêng của vụ việc (SPEC §7.2, §13 dòng M7)

SPEC §13 liệt kê "nhật ký liên lạc" trên dòng M7, và §7.2 đặc tả hẳn một tab. Bảng `communication_logs` có từ M1, policy có từ M2, nhưng **không có màn hình nào để ghi một cuộc gọi**. M5 đã phán quyết bảng này không lên cổng khách.

Hai tab còn thiếu trên trang chi tiết vụ việc:

- **Liên lạc.** SPEC đặt một ràng buộc thời gian, không phải một ràng buộc tính năng: *"ghi nhanh một cuộc gọi trong dưới 15 giây, vì nếu mất lâu hơn thì không ai ghi"*. Thiết kế theo câu đó:
  - mặc định sẵn ngày giờ và người ghi;
  - chọn kênh bằng một lần chạm;
  - nội dung là một ô duy nhất.
- **Nhật ký.** Activity log của riêng vụ việc này, lọc từ trang nhật ký toàn hệ thống của M3. Trang đó đòi `auditLog.view`, mà chỉ admin và manager có. Tab riêng của vụ hiện cho admin, manager và luật sư phụ trách của vụ. Lọc theo `subject` là vụ việc hoặc model con của nó, và theo `properties.matter_id`.

Phán quyết kèm theo:
- **Công tắc `is_visible_to_client` không lên form.** Không màn hình nào ở portal đọc bảng này (SPEC §8.3, phán quyết 3 của M5). Một công tắc không làm gì sẽ khiến luật sư tin rằng khách đã thấy. Cột giữ nguyên, mặc định false. Ghi chú vào SPEC §4.17.
- **Nhật ký liên lạc là bằng chứng** (SPEC §4.17: nó trả lời câu "văn phòng có thông báo cho tôi không").
  - `CommunicationLogPolicy::create` hiện không nhận vụ việc: sửa để nhận `$matter`, và hỏi người đó có được xem vụ và có `matter.update` không.
  - `delete` hiện mở cho bất kỳ ai xem được vụ: đổi thành xoá mềm kèm lý do bắt buộc và một dòng audit, như `stage_logs`.
- `CommunicationLogPolicy::view` **đã đúng** từ d069424. Không sửa lại.

### - [ ] Task 9 — Tìm kiếm (SPEC §6.13, R7)

- Một ô tìm kiếm trên admin, là một trang tự viết (luật `canAccess()` của M6.5). Nó tìm đồng thời trong: mã hồ sơ, tiêu đề vụ việc, tên khách hàng, số thụ lý của toà, tên các bên, tiêu đề tài liệu.
- Dựng trên phần đã có: ô tìm của `MattersTable` đã tìm được mã, tên khách, tiêu đề và luật sư. `matter_parties.name_normalized` đã có index.
- **Migration index** cho `matters.case_number`, `matters.title`, `clients.name`, `documents.title` (hiện chưa có).
  - Ghi rõ trong docblock: `LIKE 'x%'` dùng được index; `LIKE '%x%'` thì không.
  - Đo thời gian trên dữ liệu seed nhân lên vài nghìn hồ sơ, và ghi số đo vào PROGRESS.
- **Test:** sáu nguồn, mười hai test (thấy và không thấy cho từng nguồn), cộng thêm:
  - số lượng kết quả không rò rỉ;
  - tiêu đề tài liệu nhóm D không trả cho trợ lý và kế toán;
  - kế toán không nhận kết quả từ tên các bên hay tiêu đề tài liệu;
  - vụ `restricted` không lộ với người ngoài đội ngũ.
- Chạy cả dưới `test:mariadb`: `utf8mb4_unicode_ci` bỏ qua dấu nhưng coi "đ" khác "d", còn SQLite so theo byte.

### - [ ] Task 10 — Thông tin văn phòng sửa được trong app

Chủ văn phòng quyết ngày 2026-09-24 sẽ nhập thông tin pháp lý sau, **trong app**. Hôm nay mọi thông tin thương hiệu nằm ở `config/vkcrm.php` và chỉ đổi được qua `.env`, tức là phải có người sửa máy chủ.

- **Trang "Thông tin văn phòng"**, chỉ admin: một trang tự viết, theo luật `canAccess()`. Trang sửa được:
  - bốn thông tin pháp lý: mã số thuế, Đoàn Luật sư, số Giấy đăng ký hoạt động, địa chỉ trụ sở;
  - tên pháp lý, hotline, Zalo, website, email liên hệ.
- **Lưu trong một bảng `settings`** kiểu khoá–giá trị, qua một Action. Không cài gói mới.
- **Đọc qua một nơi duy nhất.** Một service `OfficeProfile` trả giá trị theo thứ tự: bảng `settings` → `config('vkcrm.brand')` (giá trị `.env` hoặc mặc định).
  - Mọi chỗ đang đọc `config('vkcrm.brand.*')` cho các trường này đều chuyển sang service đó: layout thư M6, chân trang portal, `MUC-LUC.pdf`.
  - Test cấu trúc: không còn lời gọi `config('vkcrm.brand.<trường sửa được>')` nào ngoài service.
  - Màu, logo và font **không** sửa được trong app, vì `BrandingTest` ghim chúng. Sửa nhầm màu làm hỏng cả hai panel.
- **Kiểm tra đầu vào:** độ dài bằng độ dài cột. Mã số thuế là 10 hoặc 13 chữ số (dạng `0123456789-001`). Chuẩn hoá hotline qua `Normalizer::phone()`.
- **Mỗi lần lưu ghi audit**, nêu trường nào đổi. Thư đang nằm trong hàng đợi dùng giá trị ở lúc render, không phải lúc xếp hàng; ghi rõ trong docblock.
- **Test:**
  - không phải admin thì trang trả 404, gồm cả đường Livewire update;
  - lưu xong, thư và PDF sinh sau đó mang giá trị mới;
  - để trống thì chân thư và chân PDF bỏ hẳn dòng đó.
- Migration → vòng MariaDB thật.

### - [ ] Task 11 — Nghiệm thu, tài liệu, cổng merge

- Liệt kê theo tên bốn test SPEC §11 "Bàn giao và lưu trữ", trong đó hai test nằm ở M6.5 Task 4, và chạy chúng.
- Sinh gói thật từ vụ đã kết thúc trong seed (Task 3), giải nén, và dán danh sách entry.
- Ghi các đính chính SPEC mà các task trên đã nêu (§4.11, §4.17, §4.19, §6.11, §6.12).
- Cập nhật `docs/PROGRESS.md`.
- Rà soát toàn nhánh với brief "giả định có một Critical".

---

## Những chỗ đã biết trước là sẽ cắn

- **Tên tệp tiếng Việt trong zip.** `ZipArchive` ghi tên theo byte. Một số trình giải nén trên Windows đọc theo codepage địa phương và làm hỏng dấu. Quyết định cách đặt tên **trước khi** viết: bật cờ UTF-8 của entry, và test bằng cách đọc lại entry, không bằng cách mở thử bằng tay.
- **Gói bàn giao có thể rất lớn, và nó là bản sao thứ hai của mọi tệp A/B/C.** Hạn mức đĩa của shared hosting sẽ chạm trần ở vụ lớn nhất trước tiên, và M8 sao lưu thêm một bản nữa. Chỉ giữ version mới nhất của gói. Thời gian từ lúc bấm tới lúc có gói phải hiện trên màn hình.
- **`client_access_until` chạm vào đúng ranh giới M5 vừa dựng, và M6.5 Task 2 vừa sửa lại.** `visibleToPortal()` trên `StageLogView` và `ClientRequestReply` được giữ lại dù hôm nay không phải một lớp độc lập, vì điều kiện này sẽ đáp xuống đó. Đọc ghi chú M5 và commit của M6.5 Task 2 trước khi sửa.
- **Đừng tin `withTrashed()`.** Nó bỏ `SoftDeletingScope` nhưng **không** bỏ `ClientPortalScope`. Ngược lại, làm rỗng một scope chỉ chứng minh độc lập với scope đó. M5 mất một vòng vì chuyện này.
- **Vụ bị huỷ vì mở nhầm** (M6.5 Task 5, `CancelMatter`) không bao giờ có bản ghi archive, nên không có ngày lưu trữ. Dữ liệu cá nhân trong đó cần một hạn xoá: ghi vào PROGRESS như một việc cần chủ văn phòng quyết cùng chính sách lưu trữ của M10.
