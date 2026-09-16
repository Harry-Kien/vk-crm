# VK-CRM M4 — Kế hoạch danh mục hồ sơ và tài liệu

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Văn phòng quản lý được danh mục giấy tờ của từng vụ việc và toàn bộ tài liệu, công bố có kiểm soát cho khách, và tệp chỉ tải được qua đường dẫn có chữ ký kèm kiểm tra quyền. Toàn bộ test SPEC §11 mục "Tài liệu nội bộ" và "Tải tệp" xanh.

**Architecture:** Tệp vật lý do `spatie/laravel-medialibrary` quản lý trên disk `private` (`storage/app/private`, đã cấu hình từ M0, ngoài web root). Không dùng `storage:link` — SPEC §2 cấm. Mọi lượt tải đi qua một controller: kiểm tra chữ ký URL, **rồi vẫn kiểm tra policy** (SPEC §10.4 nói rõ chữ ký không thay thế quyền), rồi stream tệp và ghi `document_downloads`.

Bốn Action mới trong `app/Actions/Document/`: `SubmitClientDocument` (§6.6), `ReviewChecklistItem` (§6.7), `PublishDocument` (§6.5), `UploadStaffDocument`. Kiểm tra tệp tách thành `app/Support/Files/` với `FileGuard` (đuôi, MIME thật qua `finfo`, kích thước) và giao diện `VirusScanner` + `NullScanner` + `ClamAvScanner` — SPEC §6.6 bước 5 yêu cầu có sẵn giao diện để bật ClamAV sau mà không sửa logic.

**Tech Stack:** PHP 8.3, Laravel 13.31, Filament 5.8, `spatie/laravel-medialibrary` ^11, `filament/spatie-laravel-media-library-plugin` ^5, Pest 4, Pint. Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §4.10 (`matter_checklist_items`), §4.11 (`documents`, bảng quy tắc mặc định theo nhóm, quy tắc chuyển trạng thái nhóm B), §4.12 (`document_downloads`), §6.5, §6.6, §6.7, §7.2 (tab Danh mục hồ sơ và tab Tài liệu), §8.4 (khách nộp tệp — giao diện portal là M5, nhưng Action và luật ở đây), §10.4, §10.6, §11 mục "Tài liệu nội bộ" và "Tải tệp", §13 dòng M4. `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md` §2 (M4).

## Ràng buộc toàn cục

- PHP sàn **8.3**. Gói mới: `spatie/laravel-medialibrary` ^11 và `filament/spatie-laravel-media-library-plugin` ^5. Kiểm tra tương thích PHP 8.3 + Filament 5 bằng `composer require --dry-run` trước khi cài thật; nếu bản Filament plugin chưa ổn định thì báo lại, đừng dùng beta.
- Không Redis, Horizon, Octane, Reverb, Pulse, Scout, Telescope. Không `storage:link` cho tệp hồ sơ.
- Định danh mã tiếng Anh. **Mọi chuỗi hiển thị tiếng Việt qua `__()` và `lang/vi/`** — không hardcode tiếng Việt trong class PHP.
- Nghiệp vụ chỉ ở `app/Actions/`. Resource, page, controller chỉ gọi Action.
- **Filament 5 khác các bản trước.** Không viết mã Filament từ trí nhớ: đọc các resource đã có dưới `app/Filament/Admin/Resources/`, và các báo cáo trong `.superpowers/sdd/2026-09-15-m3-admin-panel/task-*-report.md` ghi lại các API đã khám phá được, gồm cả bẫy `RelationManager::isReadOnly()` mặc định `true` trên trang `ViewRecord`.
- **Bộ test chạy SQLite; SQLite dựng lại bảng khi đổi chỉ số nên không bao giờ bắt được ràng buộc chỉ số/khoá ngoại mà MariaDB áp.** Task nào đụng migration phải chạy `bin/dev artisan migrate:fresh --seed` và một vòng `migrate:rollback` trên container thật, và dán kết quả vào báo cáo.
- TDD với Pest. Kết thúc mỗi task: `bin/dev test` xanh, `bin/dev pint` sạch, commit.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` (chép nguyên văn).

## Việc bắt buộc mang sang từ rà soát M2/M3

| Việc | Task |
|---|---|
| ~~`Document::applyClientPortalConstraints` chưa xét `status`~~ — **đã sửa ở Task 2**: điều kiện `status = published` đặt ở CẢ global scope lẫn `DocumentPolicy::view` (qua `Document::isReleasedToPortal()`), có test gây lỗi chủ ý ở tầng truy vấn để chứng minh tầng policy tự đứng được | 2 |
| ~~`DocumentPolicy::create()` và `ClientRequestPolicy::create()` trả `true` vô điều kiện~~ — **đã sửa ở Task 2** theo quy ước tham số ngữ cảnh tuỳ chọn của `ClientUserPolicy::create` | 2 |
| ~~`DocumentPolicy::update`/`delete` và `DeadlinePolicy::update`/`delete` chỉ xét khả năng thấy vụ việc~~ — **đã sửa ở Task 2, có đi chệch kế hoạch**: `matter.update` một mình KHÔNG loại được trợ lý (bảng SPEC §5 cho cả bốn vai có `matter.view` luôn có `matter.update`), nên `DocumentPolicy::delete` đòi thêm `document.publish`; `update`/`delete` giờ cũng đi qua `view()` nên không ai xoá được tài liệu nhóm D mình không đọc được | 2 |
| Chưa có quyền nào diễn tả "khách nộp tệp vào một đầu mục danh mục" — **nửa policy xong ở Task 2**: `DocumentPolicy::create($clientUser, $checklistItem)`, không thêm tên quyền vào SPEC §5. Task 4 phải gọi ability này KÈM đầu mục, vì nhánh không có ngữ cảnh cố ý chỉ trả lời câu hỏi giao diện | 2, 4 |
| `ForceDeleteAction`/`RestoreAction` thừa trên các resource M3 (không policy nào định nghĩa hai quyền đó) | 7 |
| `MatterType.code` có cùng lỗ hổng xoá-mềm-rồi-tạo-lại như `matter_type_stages.key` từng có trước khi M3 thêm guard ở model — task nào đụng `MatterTypeForm` nên vá luôn | 7 |
| `MattersByStageWidget` gộp theo nhãn giai đoạn (`label`), nên hai loại vụ việc có giai đoạn trùng nhãn sẽ bị cộng chung một cột — số liệu sai | 7 |
| `PartiesRelationManager::visibleClientOptions()` là bản sao trùng logic của `App\Filament\Admin\Support\VisibleClientOptions::forCurrentUser()` — **đã sửa ở M3 round 2 review** (cả hai nơi giờ dùng chung một lớp), chỉ còn ghi lại ở đây để tránh ai đó vô tình chép lại lần nữa | — |
| 19 khoá Filament vẫn hiện tiếng Anh trong các tệp chưa ai publish, `LocalizationTest` **không nhìn thấy** vì nó chỉ duyệt tệp đã có dưới `lang/vendor/`. Đáng kể nhất: các câu giới hạn tần suất của `filament/auth/multi-factor/**` (liên quan trực tiếp 2FA bắt buộc ở SPEC §10.7) và `support/components/input/one-time-code.php` `aria_label` — **chính là ô nhập OTP của cổng khách hàng ở M5** | 7 |
| `lang/en/` hiện **che** bản `en` của framework: một lần nâng Laravel thêm thông báo xác thực mới sẽ thiếu luôn ở bản `en` của ứng dụng, nên `LocalizationTest` vẫn xanh trong khi giao diện hiện ra khoá thô. Phải đối chiếu với `vendor/laravel/framework/.../lang/en/validation.php` thay vì với `lang/en/` | 7 |
| ~~Trang panel trả **403** trong khi mọi chỗ khác đã là **404**~~ — **đã chốt ở Task 2: 404, một kiểu duy nhất trong cả hai panel** (`AnswerDeniedPanelRequestsWithNotFound`). Lý do quyết định: Filament GIẢI BẢN GHI TRƯỚC rồi mới hỏi `canAccess()`, nên cặp (403, 404) là một máy dò sự tồn tại của bản ghi cho đúng người không được biết — kế toán phân biệt được một `client_id` có thật với một id bịa. Cái giá đã nhận: một tài khoản bị vô hiệu cũng nhận 404 thay vì 403 | 2 |
| Luật sư gán luật sư chính là người khác thì bị đẩy về danh sách, không xem được vụ vừa mở; và vì `OpenMatter` tự ghi dòng nhật ký công bố portal thay vì đi qua `SetMatterPortalPublication`, họ có thể bật công bố lúc tạo rồi không tắt lại được (403). Liên quan tới câu hỏi tiếp nhận khách mới đang chờ chủ văn phòng quyết | 6 |
| `SyncClientPartyIdentities` có thể **tạo ra** một xung đột mức đỏ khi nó ghi lại `id_number_hash` của các bên, mà không có lần kiểm tra nào chạy sau đó. SPEC §6.10 chỉ bắt buộc hai thời điểm nên đây không phải vi phạm, nhưng nó là thời điểm thứ ba và cần một quyết định — chạy lại kiểm tra theo lô, hay chỉ cảnh báo | 7 |
| `OurClientPartyNeedsClient` / `ClientRoleRequired` chưa được bắt ở màn hình nào; nếu một `required()` trên form bị gỡ thì chúng thành lỗi 500. Mọi màn hình M4 gọi Action phải bắt `DomainException` và đổi thành lỗi trên form | 3, 4, 6 |
| **Cần quyết định, không phải sửa lỗi:** `RunConflictCheck` đối chiếu lại MỌI bên đã có ở mỗi lần chạy (cố ý, từ rà soát vòng 3). Hệ quả: một khi mức đỏ đã bị ghi đè, mọi lần thêm bên sau đó trên cùng vụ việc lại trả về đỏ — chính bên vừa ghi đè giờ là một bên đã có. Mỗi lần thêm sau thành một lần ghi đè nữa, và một cái cổng phải bấm qua mỗi lần là cái cổng người ta học cách bấm cho xong. Hai hướng: ghi lại cặp đã được phân xử để một cặp đã ghi đè hạ xuống mức thông báo, hoặc thu hẹp phạm vi đối chiếu lại. Chạm mô hình dữ liệu nên phải chốt trước khi viết task | 2 |

---

## Cấu trúc tệp (trạng thái cuối M4)

| Đường dẫn | Trách nhiệm |
|---|---|
| `app/Support/Files/FileGuard.php` | Đuôi tệp, MIME thật, kích thước — một chỗ duy nhất |
| `app/Support/Files/{VirusScanner,NullScanner,ClamAvScanner}.php` | SPEC §6.6 bước 5 |
| `app/Actions/Document/{UploadStaffDocument,SubmitClientDocument,ReviewChecklistItem,PublishDocument}.php` | |
| `app/Exceptions/{DocumentNotPublishable,FileRejected}.php` | |
| `app/Http/Controllers/DocumentDownloadController.php` | Chữ ký + policy + stream + ghi nhật ký |
| `app/Filament/Admin/Resources/Matters/RelationManagers/{ChecklistRelationManager,DocumentsRelationManager}.php` | Hai tab mới ở SPEC §7.2 |
| `lang/vi/documents.php`, `lang/vi/checklist.php` | Gồm ba mẫu lý do từ chối ở SPEC §6.7 |
| `tests/Feature/Actions/Document/*`, `tests/Feature/Http/DocumentDownloadTest.php` | |

---

### Task 1: Cài medialibrary, `FileGuard`, `VirusScanner`

**Files:** `composer.json`, migration của medialibrary, `config/media-library.php`, `app/Support/Files/*`, `app/Models/Document.php` (implements `HasMedia`), `lang/vi/documents.php`, `tests/Feature/Support/FileGuardTest.php`

**Interfaces:** Produces `FileGuard::check(UploadedFile|string $file): void` ném `FileRejected` với thông điệp tiếng Việt nói rõ phải làm gì (SPEC §8.4 cấm thông điệp kiểu "Upload failed"); `VirusScanner` interface với `scan(string $path): void`, `NullScanner` mặc định, `ClamAvScanner` dùng khi `CLAMAV_ENABLED=true`, bind trong `AppServiceProvider` theo config.

Danh sách trắng đuôi tệp theo SPEC §6.6 bước 2: `pdf`, `jpg`, `jpeg`, `png`, `doc`, `docx`, `xls`, `xlsx`. Từ chối mọi thứ khác, `svg` bị cấm tường minh vì chứa được JavaScript. MIME thật kiểm bằng `finfo`, **không tin `Content-Type` do client gửi**. Giới hạn lấy từ `config('vkcrm.upload_max_mb')` (đã có, mặc định 20).

Test bắt buộc (SPEC §11 "Tải tệp"): `.svg` bị từ chối; tệp > 20 MB bị từ chối; tệp đuôi `.pdf` nhưng MIME thật là `application/x-dosexec` bị từ chối. Thêm: thông điệp lỗi là tiếng Việt và nói được việc cần làm.

`Document` implements `HasMedia`, collection `file`, disk `private`, một tệp mỗi document. Chạy migration trên MariaDB thật và dán kết quả.

- [ ] Cài, test đỏ, cài đặt, test xanh, pint, commit `feat: file guard, virus scanner seam and medialibrary on the private disk`.

---

### Task 2: Siết policy và scope tài liệu

**Files:** `app/Models/Document.php`, `app/Policies/{Document,ClientRequest,Deadline}Policy.php`, `app/Enums/Permission.php` (**không** thêm quyền mới — xem dưới), `tests/Feature/Authorization/*`

Bốn việc mang sang, tất cả là lỗ hổng thật đã được ghi nhận:

1. `Document::applyClientPortalConstraints` thêm điều kiện trạng thái: khách chỉ thấy tài liệu `status = published`. Hôm nay một tài liệu nhóm B còn `internal_draft` mà ai đó bật `client_can_view` là khách thấy ngay — đúng thứ SPEC §4.11 nói phải ngăn ("ngăn khách nhìn thấy một bản đơn mà toà chưa hề nhận được").
2. `DocumentPolicy::create()` trả `true` vô điều kiện. Thay bằng: nhân sự cần `matter.update` và xem được vụ việc; khách chỉ được tạo khi gắn vào một `matter_checklist_item` thuộc vụ việc họ xem được — đó chính là quyền "khách nộp tệp" còn thiếu. **Không thêm tên quyền mới vào SPEC §5**; diễn tả bằng policy.
3. `ClientRequestPolicy::create()` tương tự: khách chỉ tạo được yêu cầu trên vụ việc của mình.
4. `DocumentPolicy::update`/`delete` và `DeadlinePolicy::update`/`delete` thêm điều kiện quyền (`matter.update`), không chỉ khả năng thấy vụ việc — hiện tại một trợ lý trong đội ngũ xoá được tài liệu.

- [x] Test đỏ cho từng mục, cài đặt, test xanh, pint, commit `fix: documents and requests are gated by permission, not only by visibility`.

---

### Task 3: `UploadStaffDocument` và `PublishDocument`

**Files:** `app/Actions/Document/{UploadStaffDocument,PublishDocument}.php`, `app/Exceptions/DocumentNotPublishable.php`, tests

`UploadStaffDocument`: qua `FileGuard` và `VirusScanner`, đặt mặc định theo bảng SPEC §4.11 (nhóm A của nhân viên nộp thay → `published`, khách xem và tải được; nhóm B và C → `internal_draft`, khách không thấy; nhóm D → `internal_draft`, `client_can_download` **vĩnh viễn false**).

`PublishDocument` đúng năm bước SPEC §6.5:
1. Nhóm D bị chặn tuyệt đối, ném `DocumentNotPublishable`. Có test.
2. Nhóm B đòi `status = signed_filed`, ném exception có thông điệp rõ. Quy tắc chuyển trạng thái nhóm B ở SPEC §4.11 là `internal_draft → pending_approval → signed_filed → published`, **không được nhảy thẳng**.
3. `client_can_view` và `client_can_download` là hai cờ độc lập — cho khách biết đã có tài liệu mà chưa cho tải là trường hợp hợp lệ.
4. Đặt `status = published`, `published_at`, `published_by`.
5. Ghi activity log; dispatch thông báo cho khách nếu nhóm B hoặc C (chỉ dispatch event, listener là M6).

- [ ] Test đỏ (gồm cả ba test SPEC §11 "Tài liệu nội bộ"), cài đặt, test xanh, pint, commit `feat: staff uploads and controlled publication of documents`.

---

### Task 4: `SubmitClientDocument` và `ReviewChecklistItem`

**Files:** `app/Actions/Document/{SubmitClientDocument,ReviewChecklistItem}.php`, `lang/vi/checklist.php`, tests

`SubmitClientDocument` đúng chín bước SPEC §6.6. Điểm dễ sai:
- bước 7: nếu đầu mục đã có tài liệu thì **tạo bản mới** `version + 1` với `parent_document_id` trỏ bản cũ. **Không ghi đè.** SPEC §11 có test riêng cho việc này.
- bước 8: đặt `matter_checklist_items.status = pending_review`.
- bước 9: thông báo trong hệ thống cho lead lawyer và trợ lý (dispatch event; listener M6).

`ReviewChecklistItem`: `accepted` hoặc `rejected`; `rejected` bắt buộc lý do ≥ 20 ký tự **đếm bằng `mb_strlen`** (tiếng Việt nhiều byte — bài học từ M3), và lý do hiện thẳng cho khách. Ba mẫu lý do ở SPEC §6.7 đưa vào `lang/vi/checklist.php` nguyên văn để giao diện điền một chạm.

- [ ] Test đỏ (gồm "nộp lại tạo version 2, bản 1 còn nguyên" và "từ chối không kèm lý do → lỗi xác thực"), cài đặt, test xanh, pint, commit `feat: client document submission and checklist review`.

---

### Task 5: Route tải tệp có chữ ký

**Files:** `app/Http/Controllers/DocumentDownloadController.php`, `routes/web.php`, `app/Models/Document.php` (helper sinh URL), `tests/Feature/Http/DocumentDownloadTest.php`

SPEC §10.4: URL ký hết hạn sau 5 phút, **và controller vẫn kiểm tra policy** — chữ ký không thay thế quyền. Ghi `document_downloads` cho **mọi** lượt tải, cả nội bộ lẫn khách (SPEC §4.12), kèm IP và user agent, và ghi activity log (SPEC §10.6).

Test bắt buộc:
- khách A tải trực tiếp URL tài liệu của khách B → 404 (SPEC §11 "Cách ly dữ liệu");
- URL hết hạn → 403;
- URL hợp lệ nhưng người dùng không có quyền → 404, **không phải 403** (SPEC §10.10: không tồn tại và không có quyền trả cùng một mã);
- tài liệu nhóm D → 404 cho khách trong mọi trường hợp;
- `client_can_download = false` → khách xem được mà không tải được;
- mỗi lượt tải thành công sinh đúng một dòng `document_downloads`.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: signed download route that still checks the policy`.

---

### Task 6: Hai tab mới trên trang chi tiết vụ việc

**Files:** `app/Filament/Admin/Resources/Matters/RelationManagers/{ChecklistRelationManager,DocumentsRelationManager}.php`, lang, tests

SPEC §7.2:
- **Danh mục hồ sơ** — bảng checklist với thanh tiến độ `X/Y` (Y = số item bắt buộc cộng số item không bắt buộc đã có tài liệu, SPEC §4.10), thao tác duyệt hoặc từ chối ngay trên dòng, ba mẫu lý do bấm một cái là điền.
- **Tài liệu** — nhóm theo A/B/C/D. **Nhóm D hiển thị trên nền khác màu rõ rệt và có nhãn "Chỉ nội bộ — không bao giờ hiện cho khách".** Nút công bố gọi `PublishDocument`.

Cả hai relation manager phải lọc qua `ScopesToVisibleMatters` như các tab M3. Nhớ bẫy `isReadOnly()` mặc định `true` trên trang `ViewRecord`.

- [ ] Test đỏ (gồm: trợ lý không thấy nút công bố; nhóm D có nhãn; tiến độ X/Y đúng), cài đặt, test xanh, pint, commit `feat: checklist and documents tabs on the matter page`.

---

### Task 7: Widget còn thiếu, dọn dẹp, tài liệu

**Files:** `app/Filament/Admin/Widgets/*`, các resource M3, `docs/PROGRESS.md`, `README.md`, tài liệu bộ công cụ

- Widget SPEC §7.1 mục 3 ("Tài liệu chờ duyệt") và mục 4 ("Hồ sơ thiếu giấy tờ quá 14 ngày") — giờ đã có dữ liệu. Cả hai giới hạn theo `listableBy`.
- Dọn việc mang sang: gỡ `ForceDeleteAction`/`RestoreAction` thừa trên các resource M3 (không policy nào định nghĩa hai quyền đó nên chúng luôn bị từ chối), và cho mỗi resource một icon riêng.
- Dọn việc mang sang (review M3 round 2): `MatterType.code` có cùng lỗ hổng xoá-mềm-rồi-tạo-lại mà `matter_type_stages.key` từng có trước khi M3 thêm guard ở model — vá cùng lúc với task nào đụng `MatterTypeForm`.
- Dọn việc mang sang (review M3 round 2): `MattersByStageWidget` gộp cột theo nhãn giai đoạn (`label`), nên hai loại vụ việc có giai đoạn trùng nhãn bị cộng chung một cột — số liệu sai. Sửa để gộp theo `(matter_type_id, stage)` hoặc hiển thị riêng từng loại.
- Kiểm tra tay trên trình duyệt: nộp tệp thay khách, duyệt, từ chối kèm lý do, công bố tài liệu, tải về bằng đường dẫn ký. Chạy `migrate:fresh --seed` trước.
- Cập nhật `docs/PROGRESS.md` dòng M4 và mục "Ghi chú M4" (mọi phán quyết và việc hoãn), `README.md` nếu đổi, mục 9 tài liệu bộ công cụ.

- [ ] Test xanh, pint sạch, commit `docs: M4 complete — checklist, documents, signed downloads`.

---

## Tự rà soát kế hoạch

**Độ phủ SPEC §13 dòng M4:** checklist (Task 4, 6), upload (Task 1, 3, 4), review (Task 4, 6), `PublishDocument` (Task 3), lưu trữ private (Task 1), route tải có ký (Task 5). Tiêu chí "test phần Tài liệu nội bộ và Tải tệp xanh" → Task 1, 3, 5.

**Độ phủ §11 "Tài liệu nội bộ":** nhóm D không xuất hiện dưới guard `client` → đã có từ M2, thêm test ở Task 5; `PublishDocument` với nhóm D ném exception → Task 3; nhóm B ở `internal_draft`/`pending_approval` không công bố được → Task 3. **§11 "Tải tệp":** ba test ở Task 1 cộng các test HTTP ở Task 5.

**Nhất quán tên gọi:** `FileGuard::check` dùng ở Task 3 và 4. `VirusScanner` bind một chỗ ở Task 1. `DocumentNotPublishable` ném ở Task 3, bắt ở Task 6. `ScopesToVisibleMatters` (M3) dùng lại ở Task 6.

**Rủi ro đã lường trước:** `filament/spatie-laravel-media-library-plugin` cho Filament 5 có thể chưa ổn định — Task 1 phải `--dry-run` trước và báo lại nếu không có bản ổn định, chứ không dùng beta; nếu vậy thì tự viết field upload, phần Action không đổi. Medialibrary thêm migration nên Task 1 bắt buộc kiểm tra trên MariaDB thật. Ba mẫu lý do từ chối phải chép nguyên văn từ SPEC §6.7, không diễn đạt lại.

**Cố ý để lại:** giao diện portal để khách nộp tệp (M5 — Action và luật đã xong ở đây); listener gửi email và thông báo (M6); quét ClamAV thật (bật bằng `.env`, `NullScanner` mặc định).
