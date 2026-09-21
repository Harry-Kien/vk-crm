# VK-CRM M7 — Kế hoạch bàn giao, lưu trữ và tìm kiếm

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hồ sơ đi hết vòng đời của nó. Một luật sư nghỉ việc thì vụ việc của họ chuyển người mà không rơi mất mốc hạn nào; một vụ việc kết thúc thì khách nhận được **một gói bàn giao có mục lục** — thứ khách hàng nhớ rất lâu và gần như không văn phòng nào làm; quyền tra cứu của khách hết hạn đúng ngày; hồ sơ tới hạn tiêu huỷ thì hệ thống **báo cho người quyết định chứ không bao giờ tự xoá**. Và một ô tìm kiếm tìm được vụ việc bằng bất cứ thứ gì người ta nhớ. Tiêu chí nghiệm thu SPEC §13 dòng M7: test phần "bàn giao và lưu trữ" xanh, **giải nén gói bàn giao kiểm tra được**.

**Architecture:** Không panel mới. M7 thêm Action vòng đời (`app/Actions/Matter/`), một job sinh gói chạy trong queue, hai tác vụ định kỳ nối vào lịch M6 đã dựng, và một trang tìm kiếm trên admin. Gói bàn giao ghi vào **đúng cái đĩa `private` của M4**, không bao giờ vào `local`, và chỉ tải được qua đường ký có sẵn.

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8, Pest 4, Pint. **Một gói mới duy nhất được phép**: một thư viện PDF thuần PHP (xem R3). Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §4.19 (`matter_archives`), §6.11 (`ReassignMatter`), §6.12 (`GenerateHandoverPackage`, `ExpireClientAccess`, `FlagRetentionExpiry`), §6.13 (tìm kiếm), §4.11 (nhóm tài liệu A/B/C/D), §7.4, §10, §11 phần "Bàn giao và lưu trữ" và "Tải tệp", §13 dòng M7.

---

## Ràng buộc toàn cục

- **Nhánh:** `m7-handover-and-archive`, cắt từ `main` **sau khi M6 đã merge**. M7 phụ thuộc cứng vào lịch và nhật ký thư của M6 (Task 4 và Task 6 ở đây gửi thư và chạy theo lịch), và vào đĩa `private` + đường tải ký của M4.
- PHP sàn **8.3**, cứng. Không Elasticsearch, Meilisearch, Scout — SPEC §6.13 cấm thẳng, lý do là ràng buộc shared hosting. `LIKE` với index phù hợp là đủ ở quy mô vài nghìn hồ sơ.
- Nghiệp vụ chỉ ở `app/Actions/`. Job và màn hình chỉ gọi Action.
- Mọi chuỗi hiển thị tiếng Việt qua `__()` và `lang/vi/`, **gồm cả chữ trong PDF mục lục**.
- TDD với Pest, mutation probe cho mọi điều kiện mới, đọc lại docblock đối chiếu mã trước khi commit — ba luật này đã bắt lỗi ở từng task một của M3, M4, M5.
- Task có migration chạy vòng MariaDB thật (`migrate:fresh --seed`, rồi `migrate:reset` → `migrate`), dán output.
- `git commit -- <path>`, không bao giờ `add -A`, không bao giờ commit trần. Trailer `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- Người rà soát cuối được brief **giả định có một Critical**.

---

## Phán quyết của chủ nhiệm

**R1 — Gói bàn giao là một tệp như mọi tệp khác của M4.** Nó nằm trên đĩa `private`, có `document_downloads` khi ai đó tải, và chỉ đi qua đường ký hiện có. **Không** thêm đường phục vụ tệp thứ hai; M4 đã dựng đúng một cửa và cửa đó có kiểm tra tài khoản còn hoạt động, có policy, có throttle.

**R2 — Nhóm D không bao giờ vào gói, và điều đó phải chứng minh bằng cách giải nén.** SPEC §11 yêu cầu đúng chữ "giải nén và khẳng định". Test phải mở tệp zip thật bằng `ZipArchive`, liệt kê toàn bộ entry, và khẳng định không entry nào thuộc nhóm D — chứ không phải khẳng định trên mảng mà code vừa dựng trước khi nén. Một khẳng định trên đầu vào của hàm nén không nhìn thấy lỗi của hàm nén.

**R3 — PDF sinh bằng thư viện thuần PHP, và tiếng Việt phải hiện đúng dấu.** Không Browsershot, không Chromium, không Node — vi phạm ràng buộc shared hosting. Đã kiểm chứng trong container ngày 2026-09-21: `composer require --dry-run barryvdh/laravel-dompdf` giải được trên sàn PHP 8.3, khoá v3.1.2 cùng bốn gói phụ thuộc (dompdf v3.1.6, php-font-lib, php-svg-lib, sabberworm/php-css-parser), không cảnh báo lỗ hổng nào, và composer.json/composer.lock không hề đổi. Dùng gói này, đừng khảo sát lại. Cái bẫy đã biết: font mặc định của dompdf rơi mất dấu tiếng Việt. Task nào dựng PDF phải **nhúng font** và test bằng cách trích lại chuỗi từ PDF đã sinh, so với chuỗi gốc có dấu — không phải bằng cách nhìn ảnh.

**R4 — `ExpireClientAccess` làm khách mất quyền xem, không làm mất dữ liệu.** Vụ việc biến mất khỏi portal, còn nguyên trong admin. Tài khoản không còn vụ việc nào thì `is_active = false`. Cài bằng **một điều kiện thêm vào ranh giới portal đã có** (`ClientPortalScope` và policy, cả hai tầng, độc lập như luật ba tầng của M5), không bằng cách sửa dữ liệu vụ việc.

**R5 — `FlagRetentionExpiry` không bao giờ xoá.** Nó cảnh báo. Việc tiêu huỷ hồ sơ pháp lý do người quyết định và có biên bản. Bất kỳ mã nào trong milestone này gọi `forceDelete()` trên dữ liệu hồ sơ là sai.

**R6 — Chặn vô hiệu hoá luật sư còn dẫn dắt vụ việc đang mở phải là một luật nghiệp vụ, không phải một điều kiện trên form.** Đặt trong Action, thông điệp nêu **đúng số vụ cần bàn giao** và đường đi tới màn hình bàn giao hàng loạt. Một người quản trị đọc "không thể vô hiệu hoá" mà không biết vì sao sẽ đi sửa thẳng vào cơ sở dữ liệu.

**R7 — Tìm kiếm đi qua policy, luôn luôn.** Kết quả tìm kiếm là nơi rò rỉ dễ nhất và khó thấy nhất, vì nó gộp sáu bảng. Mỗi nguồn trong sáu nguồn phải có test riêng cho cả hai chiều: người có quyền thấy, người không có quyền **không thấy cả sự tồn tại** (không đếm, không gợi ý, không thông báo "có kết quả bị ẩn").

---

## Tasks

### - [ ] Task 1 — `ReassignMatter` (SPEC §6.11)

Đổi `lead_lawyer_id` và `matter_user`; tự sinh một dòng `stage_logs` **nội bộ** ghi ai bàn giao cho ai và lý do, **không công bố cho khách theo mặc định**; chuyển mọi `deadlines` của người cũ sang người mới; gửi một thư tổng hợp danh sách mốc hạn cho người nhận (dùng hạ tầng thư M6); ghi activity log. Kèm chặn vô hiệu hoá theo R6.

### - [ ] Task 2 — Màn hình bàn giao hàng loạt

Chọn nhiều vụ việc của một luật sư và bàn giao cùng lúc, qua đúng Action ở Task 1 (một vòng lặp gọi Action, không phải một truy vấn `update()` hàng loạt — mỗi vụ việc phải có dòng `stage_logs` và deadline của nó). Báo cáo kết quả từng vụ, kể cả vụ thất bại, thay vì một thông điệp chung.

### - [ ] Task 3 — `matter_archives` và Action kết thúc vụ việc

Migration `matter_archives` (SPEC §4.19). Action đặt `archived_at`, `client_access_until` mặc định 90 ngày sau ngày kết thúc, `retention_until` theo `.env` (mặc định 10 năm). Task có migration → vòng MariaDB thật.

### - [ ] Task 4 — `GenerateHandoverPackage` (SPEC §6.12)

Job trong queue dựng zip chứa nhóm A, B, C sắp theo thư mục nhóm, **không bao giờ nhóm D** (R2), kèm `MUC-LUC.pdf` sinh tự động (R3) gồm thông tin vụ việc, danh sách tài liệu có đánh số, và **toàn bộ dòng thời gian tiến độ đã công bố**. Xong thì thông báo luật sư phụ trách; luật sư xem lại rồi bấm công bố thì khách mới tải được từ portal. Tệp nằm trên đĩa `private` và tải qua đường ký của M4 (R1).

Kiểm chứng bắt buộc: giải nén tệp thật; tên tệp tiếng Việt có dấu còn nguyên trong zip; một tài liệu nhóm D và một tài liệu đã rút (soft-deleted) đều vắng mặt; chữ có dấu trong PDF trích lại đúng.

### - [ ] Task 5 — `ExpireClientAccess` (R4)

Tác vụ hằng ngày. Quá `client_access_until` thì vụ việc rời portal ở **cả hai tầng** (scope và policy, mỗi tầng một điều kiện độc lập, mỗi tầng một mutation probe). Tài khoản không còn vụ việc nào → `is_active = false`. Test khẳng định dữ liệu còn nguyên trong admin panel.

### - [ ] Task 6 — `FlagRetentionExpiry` (R5)

Cảnh báo quản trị khi có hồ sơ quá `retention_until`. Không xoá. Một test khẳng định bản ghi vẫn còn sau khi job chạy, và một test cấu trúc quét cả milestone tìm `forceDelete` trên các model hồ sơ.

### - [ ] Task 7 — Rút lại tài liệu đã công bố (`RetractDocument`)

Món nợ mang từ M4 sang: hôm nay một tài liệu công bố nhầm cho khách **không có đường rút lại đúng nghiệp vụ**. Action đặt trạng thái rút, tắt hai cờ hiển thị, ghi lý do và người rút, giữ nguyên tệp và giữ nguyên `document_downloads` đã có — bằng chứng khách đã tải là thứ không được phép biến mất. **Phải sửa khoá ngoại `document_downloads.document_id` khỏi `cascadeOnDelete` trước**, nếu không việc xoá một tài liệu sẽ xoá luôn bằng chứng tải của nó; đây là một migration nên áp dụng luật MariaDB thật.

### - [ ] Task 8 — Tìm kiếm (SPEC §6.13, R7)

Một ô tìm kiếm trên admin tìm đồng thời trong mã hồ sơ, tiêu đề vụ việc, tên khách hàng, số thụ lý của toà, tên các bên, tiêu đề tài liệu. `LIKE` với index phù hợp; đo thời gian trên dữ liệu seed và ghi số đo. Sáu nguồn, mười hai test (thấy/không thấy cho từng nguồn), và một test rằng số lượng kết quả cũng không rò rỉ.

### - [ ] Task 9 — Nghiệm thu, tài liệu, cổng merge

Chạy toàn bộ phần "Bàn giao và lưu trữ" của SPEC §11; giải nén một gói thật sinh từ dữ liệu seed và dán danh sách entry; cập nhật `docs/PROGRESS.md`; rà soát toàn nhánh với brief "giả định có một Critical".

---

## Những chỗ đã biết trước là sẽ cắn

- **Tên tệp tiếng Việt trong zip.** `ZipArchive` ghi tên theo byte; một số trình giải nén trên Windows đọc theo codepage địa phương và làm hỏng dấu. Quyết định cách đặt tên **trước khi** viết, và test bằng cách đọc lại entry chứ không bằng cách mở thử bằng tay.
- **Gói bàn giao có thể rất lớn.** Nó chạy trong queue, và trên shared hosting queue chỉ chạy khi cron gọi. Thời gian từ lúc bấm tới lúc có gói phải được nói ra trên màn hình, nếu không luật sư sẽ bấm lại nhiều lần.
- **`client_access_until` chạm vào đúng ranh giới mà M5 vừa dựng.** Đây là chỗ M5 đã cố tình chừa: `visibleToPortal()` trên `StageLogView` và `ClientRequestReply` được giữ lại dù hôm nay không phải một lớp độc lập, vì điều kiện này sẽ đáp xuống đó. Đọc ghi chú M5 trước khi sửa.
- **Đừng tin `withTrashed()`.** Nó bỏ `SoftDeletingScope` nhưng **không** bỏ `ClientPortalScope`, và ngược lại việc làm rỗng một scope chỉ chứng minh độc lập với scope đó. M5 mất một vòng vì chuyện này.
