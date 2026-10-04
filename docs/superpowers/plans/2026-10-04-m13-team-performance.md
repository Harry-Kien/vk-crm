# VK-CRM M13 — Kế hoạch theo dõi đội ngũ và hiệu suất

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Viết ngày 2026-10-04**, theo quyết định cuối của chủ văn phòng cùng ngày: làm ngay, chạy song song với M11 và M12. Phạm vi lấy từ hai mục trong yêu cầu của chủ văn phòng:
> - (1) "Cấp trên/giám đốc/quản lý theo dõi tiến độ vụ việc của luật sư/chuyên viên";
> - (5) "đánh giá mức độ/tỉ lệ hoàn thành công việc".
>
> Ba mục còn lại của cùng yêu cầu **không** thuộc M13: (2) doanh thu, lộ trình và nhắc thanh toán đã nằm ở M9; (3) phân quyền — M13 chỉ thêm đúng một quyền (R2); (4) Google Drive là một quyết định riêng.
>
> Mọi tên lớp, phương thức và đường dẫn dưới đây đã đối chiếu với `main` @ `75f1d40`, với làn M9-final (`D:\vkwt\lane-m9f`, `9dc7cc9`, vòng sửa Task 13), làn M10 (`b951406`) và làn M11 (`1b99056`). Làn M9-final còn đang đi; **chạy lại grep của Task 1 lúc cắt nhánh**, không tin mã băm ở đây. Tên thật trong `main` lúc cắt nhánh thắng tên trong kế hoạch này.
>
> **Sửa 2026-10-04, sau rà soát kế hoạch (vòng 1).** Những thay đổi chính so với bản đầu:
> - yêu cầu của khách quy về người giữ luồng **tại thời điểm** trả lời (hoặc cuối kỳ), dựng lại từ nhật ký (R18), không còn theo người giữ hiện tại;
> - kỳ đã đóng không trôi: hoàn thành và trả lời tính tới hết kỳ (R19);
> - mốc tạo qua AI của M11 tính như mốc thường (R20);
> - hai widget xu hướng tự kiểm quyền, ảnh chụp `normal` đóng khi không chắc (R4, Task 7);
> - P6 đếm sự kiện duyệt trong nhật ký, không đọc trạng thái hiện tại của đầu mục;
> - luật "không định nghĩa thứ hai" quét cả tác vụ chụp, ba trang và hai widget, và cấm thêm các cột ngày, hoàn thành, người giữ;
> - các cột chỉ dành cho người phụ trách vụ hiện "Không áp dụng" với trợ lý (R6);
> - người nghỉ việc sau kỳ vẫn có dòng của kỳ họ làm (R3).

**Goal:** Trưởng phòng và giám đốc nhìn **một trang** là biết mỗi luật sư, mỗi trợ lý đang giữ bao nhiêu việc và việc nào đang nguy hiểm. Một trang thứ hai cho biết **trong một kỳ** mỗi người đã làm đúng hạn tới đâu. Mỗi con số đi kèm một câu tiếng Việt nói nó được tính thế nào, và **không con số nào có định nghĩa thứ hai**: mỗi số gọi lại đúng nguồn sự thật mà trang chủ, thư nhắc và trang doanh thu đang dùng.

**Vì sao milestone này tồn tại:** SPEC §1 viết rằng hôm nay "ban lãnh đạo không nhìn ra hồ sơ nào đang đình trệ". M3–M9 đã dựng đủ dữ liệu để trả lời câu đó: mốc thời hạn, danh mục hồ sơ, yêu cầu của khách, dòng tiến độ, khoản thu. Nhưng mọi màn hình hôm nay đều xếp **theo vụ việc**. Không màn hình nào xếp **theo người**. Muốn biết "anh A đang có mấy mốc quá hạn", trưởng phòng phải mở từng vụ.

**Architecture:** Không panel mới, không gói mới.
- Ba trang tự viết trên panel `admin`: "Theo dõi đội ngũ" (bây giờ), "Trang của một người" (đi sâu), "Hiệu suất theo kỳ".
- Hai Action đọc ở `app/Actions/Performance/` trả DTO readonly. Trang chỉ gọi Action.
- Một bảng ảnh chụp hằng ngày (`performance_snapshots`) để vẽ xu hướng. Một tác vụ định kỳ nối vào lịch đã có.
- Các định nghĩa còn thiếu được thêm **vào đúng lớp đang giữ luật đó** (`Matter`, `Deadline`, `ClientRequest`, `MatterChecklistItem`, `ChecklistProgress`, `MatterStaleness`, `ActivityOwningMatter`, `App\Support\Billing`). Không lớp nào của M13 tự viết một điều kiện nghiệp vụ.

Không thư, không thông báo đẩy, không tool MCP (R12, R13).

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8.1, Pest 4, Pint. **Không gói mới.** Mọi lệnh qua `bin/dev`. Bảng Filament dữ liệu tự dựng dùng `Table::records()` của Filament 5 (`vendor/filament/tables/src/Table/Concerns/HasRecords.php:40`). Đọc mã vendor trước khi viết, không viết theo trí nhớ.

**Spec:**
- `docs/SPEC.md` §1 (người dùng: "Trưởng phòng / Ban lãnh đạo"), §4.6, §4.7 (`matter_user`, `role_in_matter`), §4.8, §4.10 (`X/Y`), §4.13, §4.14;
- §5 (cần **đính chính có ngày**), §6.4 (SLA 14 ngày), §6.8, §6.9, §7.1 (bảy widget trang chủ — nguồn của mọi cột "bây giờ"), §10 mục 6 (nhật ký), §11, §13, §15.
- Kế hoạch M9 (`docs/superpowers/plans/2026-09-19-m9-contracts-and-payments.md`), phần "Trang doanh thu" (P2: hai nghĩa của bộ lọc luật sư) và "Hệ thống màu và quy cách biểu đồ".
- Kế hoạch M11 (`docs/superpowers/plans/2026-09-24-m11-mcp.md`), R4.

---

## Ràng buộc toàn cục

- **Nhánh:** `m13-team-performance`, làn `D:\vkwt\lane-m13`. Cắt từ `main` **sau khi M9-final đã gộp**. M13 dùng hai thứ chỉ có ở làn đó:
  - `StageLog::scopeEntries()`: định nghĩa "dòng đưa vụ VÀO một giai đoạn", điều kiện SQL của `! $isSameStage` trong `TransitionMatterStage`;
  - `RevenueFilters::bounds()`: hai cận đủ giờ cho cột `date`. Đây là bản sửa lỗi "21 test đỏ vào ngày cuối tháng" (M9 Task 13).

  Nếu lúc cắt nhánh hai thứ này chưa có trên `main` thì **dừng lại báo cáo**, không dựng tạm.
- **Chạy song song với M10, M11, M12.** Các tệp sẽ xung đột lúc gộp; người gộp giữ cả hai bên:
  - `app/Enums/Permission.php`, `app/Enums/Role.php`, `lang/vi/permissions.php`, SPEC §5: M10 thêm ba quyền `intake.*`, M13 thêm một;
  - `tests/Feature/Authorization/RolesAndPermissionsTest.php`: khẳng định `toHaveCount(17)` và ma trận quyền từng vai. M10 sửa cả hai; M13 sửa cả hai (Task 1). Người gộp cộng số và gộp ma trận;
  - `app/Support/ActivityOwningMatter.php`: M10 thêm cổng bản ghi tiếp nhận (`INTAKE_REQUEST`, `visibleIntakes()`) chồng lên `scopeVisibleTo()`; M13 thêm hai scope (Task 2). Sau khi gộp, scope mới của M13 phải mang **cùng** cổng đó (xem N11);
  - `app/Models/Deadline.php`: M11 thêm `created_via`, `confirmed_at` vào `casts()` và `$attributes`; M13 thêm scope và sửa `scopeUpcoming()` (Task 2);
  - `app/Models/ClientRequest.php`: M11 thêm cột nháp; M13 thêm scope (Task 2);
  - `AppServiceProvider` (morph map; M11 cũng sửa);
  - `routes/console.php` (M11 và M12 cũng thêm lịch);
  - `database/seeders/DemoDataSeeder.php`;
  - `docs/PROGRESS.md`;
  - `lang/vi/activity.php` (M10, M11, M12 cũng thêm nhãn).

  Docblock đếm số quyền của `Permission` ghi theo dạng cộng dồn ("… cộng 1 quyền của M13"), để lần gộp chỉ phải sửa con số tổng.
- **Grep trước khi viết** (dán kết quả vào báo cáo Task 1): `MatterStaleness`, `ChecklistProgress::`, `scopeEntries`, `RevenueFilters::bounds`, `BusinessHours`, `UpcomingDeadlinesWidget::WINDOW_DAYS`, `PendingChecklistReviewsWidget::rowsFor`, `LoadPerLawyerWidget`, `scopeUpcoming`, `deadline_responsible_changed`, `client_request_assigned`, `matter_reassigned`, `created_via`, `ActivityOwningMatter::`, `INTAKE_REQUEST`, `case ` trong `Permission.php`, `toHaveCount(` trong `RolesAndPermissionsTest.php`, thư mục `app/Mcp`.
- PHP sàn **8.3**. Không Redis, Horizon, Octane, Reverb, Pulse, Scout. Đúng một dòng cron. MariaDB strict.
- Nghiệp vụ chỉ ở `app/Actions/`. Định nghĩa dùng chung nằm ở model hoặc `app/Support/`, đúng lớp đang giữ luật đó.
  - `tests/Feature/ArchitectureTest.php` cấm `App\Actions` dùng Filament. Vì vậy những định nghĩa đang nằm trong widget (`UpcomingDeadlinesWidget::WINDOW_DAYS`, `PendingChecklistReviewsWidget::rowsFor()`) phải **chuyển xuống model** trước khi Action dùng được (Task 2). Không chép.
- Mọi chuỗi hiển thị tiếng Việt qua `__()` và `lang/vi/performance.php` (tệp mới), kể cả lời giải thích từng con số. Enum backed string có `label()`.
- **TDD với Pest.** Test đỏ trước.
  - Mutation probe cho mọi điều kiện mới. Một test âm không có cặp dương thì không tính.
  - Màn hình test qua Livewire, không gọi thẳng Action.
  - Đọc lại docblock đối chiếu với mã trước khi commit.
- **Mang từ M6.5, áp nguyên cho M13:**
  - Trang tự viết hỏi `Gate::forUser($account)` trong `canAccess()`, trong `mount()`, **và trong hook `boot()`** (chạy ở mọi request Livewire), rồi `abort(404)`. Từ chối trả 404, không 403. Người không tồn tại và người không được xem trả **cùng một** 404 (SPEC §10.10).
  - Chỉ style nội tuyến trên biến CSS của Filament. Không lớp Tailwind viết tay, không bước build CSS.
  - Mã người được xem nằm trong thuộc tính Livewire `#[Locked] public int $subjectId`. Luật này áp cho **mọi** component Livewire mang id người, không chỉ trang: trang `TeamMember` **và** hai widget xu hướng (Task 7). Mỗi component như vậy tự hỏi lại `Gate::forUser($viewer)->allows('viewPerformance', $subject)` ở `mount()` **và** `boot()`, rồi `abort(404)`; không tin trang cha đã hỏi. Không thuộc tính công khai nào khác mang id người hay id vụ.
- **Luật riêng của M13 — không định nghĩa thứ hai.**
  - **Tệp bị quét:** mọi tệp dưới `app/Actions/Performance/` và `app/Support/Performance/`; `app/Actions/Schedule/CapturePerformanceSnapshots.php` (chỗ tách `confidentiality`, đúng điểm rò của R4); ba trang `app/Filament/Admin/Pages/{TeamOverview,TeamMember,Performance}.php`; mọi tệp dưới `app/Filament/Admin/Widgets/Performance/`.
  - **Không được viết điều kiện** (`where*`, `having*`, `orWhere*`, so sánh trong bộ nhớ) trên các cột sau:
    - vụ việc: `closed_at`, `last_client_update_at`, `stage_entered_at`, `is_published_to_portal`, `confidentiality`, `lead_lawyer_id`, `role_in_matter`;
    - mốc: `is_completed`, `completed_at`, `due_date`, `responsible_user_id`;
    - yêu cầu: `answered_at`, `assigned_to`;
    - giấy tờ, tiền, tiến độ: `is_required`, `reviewed_by`, `reviewed_at`, `voided_at`, `paid_on`, `from_stage`, `to_stage`, `occurred_at`, `created_by`, `attributed_lawyer_id`;
    - chung: `status` và `deleted_at` của bất kỳ bảng nào, `created_at` của bất kỳ bảng nào, `is_active`.
  - **Được** đọc các cột quy người (`lead_lawyer_id`, `responsible_user_id`, `created_by`, `attributed_lawyer_id`, `causer_id`, `user_id` của `matter_user`) trong `select`/`groupBy`: đó là quy về người (R5), không phải điều kiện. Lọc theo một người cụ thể thì đi qua scope có tên (`Matter::scopeWorkedOnBy()`, `Deadline::scopeHeldBy()`, `ClientRequest::scopeHeldBy()`, Task 2).
  - **Ngoại lệ có tên, theo tệp:** `TeamRoster.php` được viết điều kiện trên `is_active` và `deleted_at` của `users`, và trên `event`, `subject_type`, `created_at` của dòng nhật ký vô hiệu hoá (nó **là** định nghĩa danh sách R3); `DeadlineHolderAtDue.php`, `RequestHolderAt.php` và `LeadAt.php` được viết điều kiện trên `event`, `subject_type`, `subject_id`, `created_at` của `activity_log` (chúng **là** định nghĩa "ai giữ việc lúc nào", R9, R18). Không tệp nào khác được thêm vào danh sách ngoại lệ mà không sửa kế hoạch.
  - **Hiển thị thì được**: `TextColumn::make('due_date')`, nhãn, định dạng.
  - Ảnh chụp không tự viết luật xem: `PerformanceSnapshot::scopeVisibleTo()` suy dòng `restricted` từ `Matter::isListableBy()` (R4), không viết lại nhánh `restricted` của `listableBy`.

  Mọi điều kiện đi qua scope hoặc lớp đã nêu tên trong bảng "Định nghĩa các con số". Task 2 viết test cấu trúc quét token cho luật này, theo khuôn `tests/Feature/Models/MatterTest.php:171`.
- **Test ở thư mục mới `tests/Feature/Performance/`.** `tests/Feature/Filament` đã có khoảng 60 tệp, và Docker 9p trên máy dev Windows làm rơi tệp ở thư mục trên 40 mục (PROGRESS, ghi chú 2026-09-28).
  - Chạy test qua công cụ của làn. Công cụ đó liệt kê tệp tường minh (`bin/container-test`).
  - So số test chạy với `find tests -name '*Test.php'`, đừng tin con số đếm cục bộ.
  - Hàm và hằng Pest ở phạm vi toàn cục mang tiền tố `m13` / `M13_`, để không trùng tên với làn khác.
- Task có migration chạy vòng MariaDB thật (`migrate:fresh --seed`, rồi `migrate:reset` → `migrate`) và dán output. Task đụng ngày tháng chạy thêm `bin/dev test:mariadb`, **tuần tự**.
- `git commit -- <path>`, không `add -A`, không commit trần. Trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, chép nguyên văn.
- Người rà soát mỗi task và rà soát cuối được brief **giả định có một Critical**. Với M13 dặn thêm hai câu:
  - giả định có một con số lộ ra sự tồn tại của một vụ `restricted`;
  - giả định có một con số được tính bằng một định nghĩa thứ hai.

---

## Phán quyết của chủ nhiệm

Các phán quyết đều là **"Phán quyết 2026-10-04 — chủ văn phòng đảo được"**. Đảo phán quyết nào thì sửa đúng task nêu tên. Task 8 chép tất cả vào PROGRESS.

**R1 — Hai câu hỏi, hai trang, và một trang đi sâu.**
- **"Theo dõi đội ngũ"** trả lời câu *bây giờ ai đang giữ gì, cái gì đang nguy hiểm*. Mọi số tính tại lúc xem. Đây là hàng đợi hành động: trưởng phòng đọc nó để biết phải gọi ai, phải bàn giao vụ nào.
- **"Hiệu suất theo kỳ"** trả lời câu *trong tháng hoặc quý này, mỗi người đã làm đúng hạn tới đâu*. Mọi số tính trên một kỳ có hai cận.
- **"Trang của một người"** (`/team/{user}`) là chỗ đi sâu từ cả hai trang: danh sách vụ, mốc, yêu cầu, giấy tờ chờ, cơ cấu lĩnh vực, biểu đồ xu hướng.

Lý do: trộn số "bây giờ" với số "trong kỳ" trên một bảng làm người đọc so hai thứ không cùng thời điểm. Ví dụ "12 vụ đang mở" đặt cạnh "80% đúng hạn của tháng trước".

**R2 — Quyền: một quyền mới qua đính chính SPEC §5 có ngày. Số của chính mình không cần quyền mới.**

| Quyền | admin | manager | lawyer | assistant | accountant |
|---|---|---|---|---|---|
| `performance.viewAny` (số liệu theo dõi và hiệu suất của **mọi** nhân sự được theo dõi; trang "Theo dõi đội ngũ") | ✓ | ✓ | — | — | — |

- **Số của chính mình:** ai có `matter.view` cũng xem được trang của chính mình và dòng của chính mình trên "Hiệu suất theo kỳ". Đó là luật sư và trợ lý (và quản lý, admin).
  - Cài bằng `UserPolicy::viewPerformance(User|ClientUser $viewer, User $subject)`: (có `performance.viewAny` **hoặc** `$viewer` là `$subject` và có `matter.view`) **và** `TeamRoster::isTrackable($subject)`. `isTrackable` nhận cả người đã nghỉ việc (R3), nên liên kết tên trên trang "Theo dõi đội ngũ" khi bật công tắc người nghỉ việc không bao giờ dẫn tới 404.
  - Quản lý không xem được trang của một quản lý khác? **Có xem được**: `performance.viewAny` là toàn bộ danh sách R3.
- **Kế toán: không gì cả.** 404 ở cả ba trang.
  - Lý do: phần đi sâu mang tên mốc thời hạn, chủ đề yêu cầu của khách, tên đầu mục giấy tờ. Đó là nội dung hồ sơ, và SPEC §1 cùng §5 giữ "kế toán không thấy nội dung hồ sơ".
  - Câu hỏi duy nhất của kế toán ở đây là "doanh thu theo luật sư". Câu đó đã có trên trang Doanh thu: bộ lọc luật sư của `RevenueOverTimeWidget` lọc theo `payments.attributed_lawyer_id`.
- **Cột doanh thu** (P7) chỉ hiện khi `UserPolicy::viewPerformanceRevenue($viewer, $subject)`: có `billing.view` **và** (có `revenue.viewAny` **hoặc** là chính người đó).
  - Trợ lý không có `billing.view`, nên không thấy cột này.
  - Luật sư thấy doanh thu của chính mình, đúng như trang Doanh thu cho luật sư thấy tiền của vụ mình.
- **Vì sao là một quyền, không phải `Gate::define()` như `bulkReassign`:**
  - Đây là quyền **truy cập dữ liệu nhân sự** (xem R14), không phải quyền mở một màn hình thao tác.
  - Nó phải nằm trong bảng vai trò của SPEC §5, nơi chủ văn phòng đọc được ai thấy gì. Tiền lệ là M9 và M10, cả hai thêm quyền bằng đính chính có ngày.
- **Test cấu trúc:** mọi vai trò có `performance.viewAny` cũng có `matter.viewAny`. Tương đương ảnh chụp ở R10 dựa vào điều này.

**R3 — Ai được theo dõi.** `App\Support\Performance\TeamRoster` là định nghĩa duy nhất của danh sách này:
- **`TeamRoster::isTrackable(User $subject)`** = vai trò thuộc `TeamRoster::TRACKED_ROLES` (`lawyer`, `assistant`, `manager`) **và** chưa xoá mềm. Người đã nghỉ việc (`is_active = false`) **vẫn** trackable: số của kỳ họ đã làm là của họ;
- **trang "Theo dõi đội ngũ"** (bây giờ): mặc định chỉ người đang hoạt động; công tắc "Gồm người đã nghỉ việc" thêm người trackable có `is_active = false`;
- **trang "Hiệu suất theo kỳ"**: mặc định là người trackable **đang hoạt động vào một lúc nào đó trong kỳ** (`TeamRoster::subjectsForPeriod()`, Task 6): người đang hoạt động, cộng người đã nghỉ việc mà lần vô hiệu hoá gần nhất xảy ra **từ ngày đầu kỳ trở đi**. Lần vô hiệu hoá đọc từ dòng nhật ký `updated` của chủ thể `user` (`User` dùng `LogsActivity` với `logOnly([... 'is_active'])`, nên dòng có `properties.old.is_active = true` và `properties.attributes.is_active = false`), lọc bằng PHP trên dòng của những người đang nghỉ việc (vài chục dòng, không truy vấn JSON). Người nghỉ việc không có dòng nào như vậy (dữ liệu cũ) coi như nghỉ trước mọi kỳ. Trang này cũng có công tắc "Gồm người đã nghỉ việc" thêm mọi người trackable đã nghỉ. Ví dụ: luật sư nghỉ ngày 05/11 vẫn có dòng trong kỳ "tháng trước" (tháng 10) mà không cần bật công tắc;
- **người đã xoá mềm không hiện ở đâu**, kể cả khi bật công tắc. Việc họ từng giữ vẫn nằm trong dòng "Chung" (R8). Xoá mềm một nhân sự là quyết định của admin rằng tài khoản đó không còn là một người trong văn phòng; `isTrackable` không đảo quyết định đó.

Mọi hàm của `TeamRoster` trả người dùng **đã nạp sẵn** `roles.permissions` và `permissions` (một lần `with()`), để các lần hỏi Gate theo từng người ở R2, R6 không sinh một truy vấn spatie cho mỗi người (R11).

**Admin không vào danh sách.** Admin là tài khoản quản trị hệ thống, và giám đốc xem danh sách chứ không nằm trong nó. Xem câu hỏi mở 2.

**Không lọc danh sách theo "có vụ mà người xem thấy được".** Lọc như vậy thì một luật sư chỉ phụ trách vụ `restricted` sẽ biến mất khỏi trang của trưởng phòng. Sự biến mất đó tự nó xác nhận rằng có những vụ trưởng phòng không thấy. Đây là chiều ngược của đúng lỗi mà `RevenueDashboard::filtersForm()` đã sửa ở vòng 1 (chú thích ở ô chọn luật sư). Danh sách người chỉ phụ thuộc vai trò, không phụ thuộc vụ việc.

Mở `/team/{user}` với người ngoài danh sách thì trả 404, giống hệt người không tồn tại.

**R4 — Vụ `restricted`: mỗi con số là một phép đếm trên phần giao, không bao giờ là một phép trừ.**
- Mọi con số về người X mà người xem V đọc được đều tính trên **giao** của hai tập: `Matter::listableBy(V)` và tập việc của X.
  - Không con số nào được tính trên tập rộng hơn rồi trừ đi.
  - Không có dòng "đã ẩn N vụ", không chú thích "có vụ bạn không thấy".
  - Không có tổng toàn văn phòng tính ngoài `listableBy(V)`.
- Hệ quả, viết ra để không ai "sửa":
  - Cùng một người có thể có hai con số khác nhau với hai người xem. Trưởng phòng thấy 12 vụ của luật sư A; luật sư A thấy 13, vì vụ thứ 13 là vụ `restricted` A phụ trách. Đó là **đúng thiết kế**. Trang Doanh thu của M9 làm y hệt (P3: "kể cả trong số liệu tổng hợp").
  - Admin thấy tất cả.
- Mọi trang in **cùng một câu** cho **mọi** người xem, kể cả admin: "Mọi con số tính trên các vụ việc bạn được xem." Câu này luôn có mặt nên sự có mặt của nó không mang tín hiệu nào.
- **Ảnh chụp hằng ngày (R10)** tách mỗi người thành hai dòng, `normal` và `restricted`. Cả hai dòng đều được tính **ngoài** `listableBy` (tác vụ chạy không người đăng nhập), nên `PerformanceSnapshot::scopeVisibleTo(Builder, User $viewer, User $subject)` **đóng khi không chắc**: một dòng chỉ được **truy vấn** (không phải nạp rồi lọc) khi chứng minh được nó bằng phần giao `listableBy(V) ∩ việc của X`:
  - dòng `normal`: chỉ khi `$viewer` có `matter.viewAny` (khi đó nửa `normal` của `listableBy(V)` là mọi vụ thường), **hoặc** `$viewer` là `$subject` và có `matter.view` (người phụ trách luôn có tên trong `team` của vụ mình: `Matter::booted()` thêm, `ReassignMatter` thêm, `RemoveTeamMember` từ chối gỡ `lead`). Người xem khác: không dòng nào;
  - dòng `restricted`: chỉ khi một vụ `restricted` **giả** do `$subject` phụ trách qua được `Matter::isListableBy($viewer)`: `(new Matter)->forceFill(['confidentiality' => Confidentiality::Restricted, 'lead_lawyer_id' => $subject->getKey()])->isListableBy($viewer)`. Không viết lại "admin, hoặc luật sư phụ trách có `matter.view`": đổi nhánh `restricted` của `listableBy` thì ảnh chụp đổi theo;
  - không vế nào đúng: `whereRaw('1 = 0')`.
- **Test quét rò rỉ** (Review Focus 1) chạy trên **mọi** trường của các DTO. Một chỉ số thêm sau này mà quên điều kiện thì test đỏ.

**R5 — Mỗi con số quy về đúng một người, theo đúng một cột đã có.**

| Loại việc | Quy về | Cột | Vì sao |
|---|---|---|---|
| Vụ việc, bây giờ (đang mở, đã kết thúc, quá hạn cập nhật, chờ giấy tờ khách, giấy tờ chờ duyệt, `X/Y`) | luật sư phụ trách hiện tại | `matters.lead_lawyer_id` | SPEC §6.4 và §6.9 gửi cảnh báo cho đúng người này |
| Vụ kết thúc **trong kỳ** (P5) | **luật sư phụ trách vào lúc vụ kết thúc** | lịch sử `matter_reassigned` (`from_user_id`, `to_user_id`) qua `LeadAt` (R18) | `MatterPolicy::manageTeam()` cho bàn giao cả vụ đã kết thúc; không để lần bàn giao sau viết lại kỳ đã qua |
| Vụ tham gia | thành viên đội ngũ với vai `associate` hoặc `assistant` | `matter_user.role_in_matter`, qua `Matter::scopeWithSupportingMember()` | `lead` đã tính ở dòng trên; `observer` không giữ việc |
| Mốc thời hạn, bây giờ (quá hạn, 7 ngày tới) | người giữ mốc hiện tại | `deadlines.responsible_user_id` | người phải xử lý **ngay bây giờ** |
| Mốc thời hạn, trong kỳ (đúng hạn, trễ, lỡ) | **người giữ mốc vào ngày đến hạn** | lịch sử `deadline_responsible_changed` (R9) | không để người nhận bàn giao gánh mốc người trước đã lỡ |
| Yêu cầu của khách, bây giờ (N9) | người đang giữ luồng | `ClientRequest::scopeWithHolder()`: người được giao **còn tài khoản** (chưa xoá mềm), không có thì luật sư phụ trách hiện tại | cùng người mà `ReplyToClientRequest::notifyHolderOfFollowUp()` báo khi khách viết thêm (`$thread->assignee ?? $matter->leadLawyer`; quan hệ `assignee` bỏ người đã xoá mềm) |
| Yêu cầu của khách, trong kỳ (P3, P9, P10) | **người giữ luồng lúc văn phòng trả lời lần đầu** (`answered_at`); luồng chưa trả lời tới hết kỳ thì **người giữ luồng lúc hết kỳ** | lịch sử `client_request_assigned` (`from`, `to`) và `matter_reassigned` qua `RequestHolderAt` (R18) | cùng lý lẽ với R9: không để người nhận bàn giao gánh luồng người trước đã trả lời hay đã bỏ dở |
| Chuyển giai đoạn | người ghi dòng tiến độ | `stage_logs.created_by` | dòng chỉ-thêm, không bao giờ đổi chủ |
| Giấy tờ đã duyệt | người bấm duyệt hoặc từ chối | `activity_log.causer_id` của dòng `checklist_item_reviewed` (`ReviewChecklistItem`) | trạng thái hiện tại của đầu mục không nói "ai đã duyệt trong kỳ": `UploadStaffDocument::settleChecklistItem()` ghi `accepted` và `reviewed_by = người tải lên` khi văn phòng nộp hộ, còn `SubmitClientDocument::markPendingReview()` xoá `reviewed_by`/`reviewed_at` khi khách nộp lại |
| Doanh thu đã thu | luật sư phụ trách tại lúc thu | `payments.attributed_lawyer_id` | M9 P2. Không bao giờ suy lại |
| Thao tác hồ sơ gần nhất | người gây ra dòng nhật ký | `activity_log.causer_id` | nhật ký là định nghĩa "ai đã làm gì" của SPEC §10 mục 6 |

Một việc không quy được về ai (ví dụ dòng lịch sử có `from` rỗng, R9) **không** bị đoán cho ai: nó chỉ vào dòng "Chung" (R8).

**R6 — Mọi công thức nằm trong bảng "Định nghĩa các con số" bên dưới**, kèm nguồn sự thật và câu giải thích hiện trên trang. Mỗi câu giải thích là một khoá `performance.explain.<mã>` trong `lang/vi/performance.php`. Trang có một khối thu gọn được, "Cách tính các con số", liệt kê đủ các câu đó.

**Không hiện số 0 cho việc người đó không được làm.** Số 0 ở đây sẽ đọc thành "không làm gì".
- **Một điều kiện duy nhất:** `TeamRoster::leadsMatters(User $subject): bool` = người đó có `matter.transitionStage`. Đây đúng là quyền mà `CreateMatter::leadLawyerOptions()` dùng để liệt kê người được chọn làm luật sư phụ trách. Trợ lý không có quyền này, nên không bao giờ đứng tên `lead_lawyer_id`.
- **Các cột chỉ dành cho người phụ trách vụ** hiện "Không áp dụng" khi `leadsMatters()` sai: N1, N3, N4 (kèm "chưa bật cổng"), N7, N8, N10, P4, P5, P7. Trong DTO, các trường đó là `?int` (hoặc `?Ratio`), `null` = không áp dụng.
- **Các cột vẫn áp dụng cho trợ lý:** N2, N5, N6, N9, N11, P1, P2, P3, P6, P9, P10. Trợ lý giữ mốc, giữ luồng yêu cầu và duyệt giấy tờ (`checklist.review`).
- **Chỉ theo quyền, không bao giờ theo vụ.** "Không áp dụng" không được suy từ "người này có vụ nào không": một luật sư chỉ phụ trách vụ `restricted` phải hiện **0** với trưởng phòng, không phải "Không áp dụng" (R4).
- **Doanh thu có hai lý do vắng mặt khác nhau, không trộn:** "Không áp dụng" (người được xem không phụ trách vụ, trường `null`) và "người xem không được thấy" (cả cột không có trên trang, `PerformanceReport::$revenueVisible = false`, Action không tính P7 chút nào).

**R7 — "Tỉ lệ hoàn thành việc đến hạn" là chỉ số chung, và nó là một phép đếm, không phải một điểm số.**

```
(mốc đến hạn đã xong tới hết kỳ, đúng hạn hoặc trễ) + (yêu cầu của khách đã trả lời tới hết kỳ)
─────────────────────────────────────────────────────────────────────────────────────────────────
     (mốc đến hạn trong kỳ) + (yêu cầu khách gửi trong kỳ, trừ yêu cầu đóng không trả lời)
```

- **"Tới hết kỳ"** là mốc cắt của R19: kỳ đã đóng thì cắt lúc 23:59:59 ngày cuối kỳ, kỳ đang chạy thì cắt lúc xem.
- **Yêu cầu đóng không trả lời** (`closed`, `answered_at` rỗng: trùng, khách rút, đã giải quyết ngoài hệ thống) không vào mẫu số. `TriageClientRequest::setStatus()` cho đi thẳng Mới → Đã đóng, và chỉ ghi `answered_at` khi trạng thái tới `answered`; để luồng đó trong mẫu số thì nó kéo tỉ lệ của người giữ xuống mãi mãi. Chúng hiện ở cột riêng P10, như mốc đã gỡ (P2): không tính vào tỉ lệ, nhưng có mặt để tỉ lệ không đẹp lên nhờ đóng luồng chưa trả lời. Đóng không trả lời **không** tính là "đã giải quyết": hệ thống không biết khách có được trả lời ngoài hệ thống hay không, còn "đánh dấu đã trả lời qua điện thoại" đã có đường riêng (`setStatus(Answered)` ghi `answered_at`).
  - *Vì sao không theo góp ý "loại qua một scope mới của `ClientRequest`":* luật nằm trên `ClientRequest`, nhưng ở dạng phương thức trong bộ nhớ (`isClosedWithoutAnswer()`, Task 2), không phải scope SQL. P3 và P10 đã nạp cả tập của kỳ để dựng người giữ và tính trung vị bằng PHP (R11); một scope SQL cùng luật sẽ là hình dạng thứ hai mà không chỗ nào gọi, tức một định nghĩa thứ hai chờ lệch. Nếu sau này có chỗ cần lọc bằng SQL, thêm scope cạnh phương thức đó, kèm test đồng nhất hai hình dạng như `Matter::scopeOpen()`/`isOpen()`.
- **Mỗi việc là một đơn vị, không trọng số.** Mọi trọng số đều là một quyết định mà không ai trong văn phòng đã đưa ra. Phân rã luôn in cạnh tỉ lệ: "12/15 mốc · 8/9 yêu cầu".
- **Chỉ hai loại việc vào chỉ số này.** Đây là hai loại việc có người ngoài đang chờ và có thời điểm "đến lúc phải xong" rõ ràng:
  - toà án hoặc cơ quan chờ (mốc thời hạn);
  - khách chờ (yêu cầu).
- **Danh mục hồ sơ (`X/Y`) không vào.** Tỉ lệ đó đo việc **khách** đã nộp đủ giấy tờ chưa, phần lớn không nằm trong tay nhân sự. Nó vẫn có cột riêng.
- **Dưới 5 việc thì không tính tỉ lệ.** Trang hiện "Chưa đủ dữ liệu (n = 3)". Hằng số `Ratio::MIN_SAMPLE = 5`, áp cho mọi tỉ lệ của M13. Một người có 2 mốc, lỡ 1, không phải "50%".
- Kỳ đang chạy hiện nhãn "(kỳ đang chạy — số còn thay đổi)".

**R8 — Không bảng xếp hạng.** Chủ văn phòng nói "đánh giá". Kế hoạch đọc chữ đó là "đánh giá được từng người", không phải "xếp hạng người này với người kia". Lý do:
- **Cơ cấu vụ khác nhau.** Một luật sư hình sự có nhiều mốc dày, khách khó liên lạc. Một luật sư doanh nghiệp có ít mốc. So thẳng tỉ lệ của hai người là so hai loại việc.
- **Mẫu nhỏ.** Văn phòng vài chục người; một người có vài chục mốc mỗi quý. Hai điểm phần trăm chênh nhau là nhiễu.
- **Định luật Goodhart.** Xếp hạng theo tỉ lệ đúng hạn dạy người ta bấm "hoàn thành" sớm, gỡ mốc khó (R6: cột "Mốc đã gỡ"), và tránh nhận vụ khó.
- **Vai trò khác nhau.** Trợ lý và luật sư không làm cùng loại việc.

Cài đặt cụ thể:
- Không cột hạng, không điểm tổng hợp, không huy hiệu "nhất tháng".
- Mặc định xếp theo tên.
- Trên trang **"Hiệu suất theo kỳ" không cột nào sắp xếp được** ngoài tên.
- Trên trang **"Theo dõi đội ngũ"** chỉ các cột **đếm việc đang tồn** sắp xếp được (mốc quá hạn, quá hạn cập nhật, yêu cầu chờ trả lời…). Đó là hàng đợi: sắp xếp để biết phải gọi ai trước, không phải để phán xét. Cột tỉ lệ (`X/Y`) không sắp xếp được.
- **Màu chỉ theo ngưỡng tuyệt đối đã có**, không theo vị trí so với người khác. Số mốc quá hạn > 0 và số vụ quá hạn cập nhật > 0 tô màu `danger`, đúng tiền lệ `StaleMattersWidget` (`->color('danger')` trên cột "quá hạn"). Trên trang tự viết, màu đó là style nội tuyến `var(--danger-600)`. (`UpcomingDeadlinesWidget::renderSeverity()` tô **mức nghiêm trọng** của mốc, không tô trạng thái quá hạn, nên không phải tiền lệ ở đây.) Tỉ lệ không bao giờ tô xanh hay đỏ.
- **Ngữ cảnh thay cho chuẩn hoá.** Không chuẩn hoá bằng con số theo loại vụ: mỗi người mỗi loại chỉ có vài vụ, nên chuẩn hoá sẽ tạo ra số ảo chính xác. Thay vào đó:
  - mỗi dòng của "Hiệu suất theo kỳ" có cột "Lĩnh vực chính": hai loại vụ có nhiều vụ nhất **trong số vụ mà người đó có việc trong kỳ** (vụ của các mốc P1, các luồng P3/P10, các dòng P4, các vụ P5 đã quy về người đó), kèm số vụ. Không tính từ N1: N1 là số "bây giờ", và R1 cấm đặt số "bây giờ" cạnh số "trong kỳ". Một luật sư vừa đóng hết vụ hình sự tháng trước vẫn có "Hình sự" là bối cảnh của tháng trước;
  - trang của một người có bảng cơ cấu đủ (N1 theo `matter_type_id`, số "bây giờ", đúng trang của nó).
- **Một dòng tham chiếu** "Chung — các vụ bạn được xem", đứng đầu bảng. Chỉ người có `performance.viewAny` mới có dòng này. So một người với chính văn phòng, không với từng người khác. Luật của dòng này:
  - **tập việc:** mọi việc trong `listableBy(V)` theo cùng công thức, **không lọc theo người giữ**. Gồm cả việc do admin, người ngoài danh sách R3, người đã nghỉ việc, người đã xoá mềm giữ, và việc không quy được về ai. Vì vậy dòng "Chung" **không** bằng tổng các dòng bên dưới; câu giải thích `performance.explain.reference` nói đúng điều này;
  - **P4** luôn hiện (số lần đưa vụ sang giai đoạn mới, do bất kỳ ai), không có "Không áp dụng": dòng này không phải một người;
  - **P7** chỉ hiện khi người xem có `billing.view` **và** `revenue.viewAny`. Khi đó P7 của dòng này bằng tổng của `RevenueOverTimeWidget` không lọc luật sư, cùng kỳ (Review Focus 2);
  - **"Lĩnh vực chính"** tính trên mọi vụ có việc trong kỳ;
  - không liên kết, không ghi `performance_viewed` riêng.
- Trang "Hiệu suất theo kỳ" có một đoạn ngắn **"Vì sao không có bảng xếp hạng"** nói lại bốn lý do trên.

Muốn xếp hạng thì chủ văn phòng đảo R8 (câu hỏi mở 1), kèm quyết định xếp theo số nào và chuẩn hoá thế nào.

**R9 — Lịch sử người giữ mốc nằm ở đúng một khoá sự kiện, và M13 làm cho nó đầy đủ.**

Hôm nay `responsible_user_id` của một mốc đổi qua năm đường:

| Đường | Có dòng `deadline_responsible_changed`? |
|---|---|
| `ChangeDeadlineResponsible` | Có (`from`, `to`) |
| Lần mở lại có chuyển người của `SetDeadlineCompletion` | Có, với `reason = reopened_holder_no_longer_qualifies` |
| `UpdateDeadline` | **Không.** Chỉ có dòng `deadline_updated` với `before`/`after` |
| `ReassignMatter` | **Không.** Một câu `update()` hàng loạt (`app/Actions/Matter/ReassignMatter.php:338-346`); dòng `matter_reassigned` chỉ mang **số lượng** mốc đã chuyển |
| `AddMatterDeadline` | Tạo mới, không phải đổi người |

Hậu quả nếu bỏ qua: một luật sư nghỉ việc với ba mốc đã lỡ. `ReassignMatter` chuyển cả ba sang người nhận, vì nó chuyển mọi mốc `is_completed = false`. Tỉ lệ đúng hạn của **người nhận** tụt vì việc của người trước. Đó đúng là lúc so sánh bất công nhất: ngày có người nghỉ việc.

Phán quyết:
- `ReassignMatter` ghi **một dòng `deadline_responsible_changed` cho mỗi mốc** đã chuyển, `reason = matter_reassigned` (hằng số mới `ReassignMatter::DEADLINE_HANDOVER_REASON`). Dòng nằm trong cùng transaction, ngay sau câu `update()`.
- `UpdateDeadline` ghi thêm dòng đó (`reason = deadline_updated`) khi người phụ trách thật sự đổi. Dòng `deadline_updated` vẫn giữ nguyên.
- Docblock của `SetDeadlineCompletion` đã mong đúng điều này: "lịch sử 'ai từng giữ mốc này' đọc ở MỘT chỗ". Từ M13 điều đó thành sự thật.
- **`App\Support\Performance\DeadlineHolderAtDue::resolve(Collection<Deadline>): array<int, ?int>`**:
  - với mỗi mốc, tìm dòng `deadline_responsible_changed` **sớm nhất** có `created_at` sau 23:59:59 của `due_date`;
  - nếu có, người giữ vào ngày đến hạn là `properties.from` của dòng đó; nếu không, là `responsible_user_id` hiện tại (cột `NOT NULL`, migration `2026_09_14_000014`, nên nhánh này luôn có người);
  - nếu `properties.from` của dòng đó rỗng hoặc không phải số (dữ liệu hỏng; mọi đường ghi hôm nay đều ghi một id): trả `null`. Mốc đó không quy về ai, không đoán, chỉ vào dòng "Chung" (R5, R8);
  - **một** truy vấn cho cả lô, qua index morph `subject` của `activity_log`.
- **Giới hạn đã biết:** lần bàn giao **trước ngày triển khai M13** không có dòng cho từng mốc. Những mốc đó rơi về người giữ hiện tại. Ghi vào PROGRESS và vào câu giải thích của P1.
- **Test cấu trúc:** mọi tệp dưới `app/Actions` **ghi** `responsible_user_id` của `deadlines` (ngoài `AddMatterDeadline`) cũng chứa `'deadline_responsible_changed'`.
  - "Ghi" là một trong ba mẫu token, trên mã đã bỏ chú thích: `->update\(\s*\[[^\]]*['"]responsible_user_id['"]\s*=>`, `->responsible_user_id\s*=(?![=>])`, `(create|forceFill|fill)\(\s*\[[^\]]*['"]responsible_user_id['"]\s*=>`.
  - Không phải "ghi": khoá lỗi `ValidationException::withMessages(['responsible_user_id' => [__(…)]])` (AddMatterDeadline, UpdateDeadline, ChangeDeadlineResponsible, SetDeadlineCompletion), các câu đọc `->where('responsible_user_id'`, `->select('responsible_user_id')` (UpdateMatterDetails, GuardsStaffOffboarding) và `$x->responsible_user_id` không có `=`. Test có fixture cho từng dạng âm này, không chỉ cho dạng dương.
  - Cùng test, cùng ba mẫu cho `assigned_to` của `client_requests` và khoá `'client_request_assigned'` (R18). Ngoại lệ: `OpenClientRequest` (tạo mới với `null`).
- **Nhãn lý do:** khoá `activity.reasons.<sự kiện>.<lý do>` trong `lang/vi/activity.php`, ví dụ `activity.reasons.deadline_responsible_changed.reopened_holder_no_longer_qualifies`, `….matter_reassigned`, `….deadline_updated`, `activity.reasons.client_request_assigned.matter_reassigned`. Lý do của `SetDeadlineCompletion` hôm nay chưa có nhãn; M13 thêm. Modal "Xem chi tiết" của trang Nhật ký hệ thống in nhãn thay mã cho khoá `reason` khi `Lang::has()`, giữ mã thô khi không có.
- **Yêu cầu của khách có lịch sử tương tự, xem R18.**

**R10 — Xu hướng đến từ ảnh chụp hằng ngày, không từ việc dựng lại lịch sử.**
- "Số vụ quá hạn cập nhật tuần trước là bao nhiêu" **không** tính lại được từ cột hiện tại: `last_client_update_at` bị ghi đè mỗi lần cập nhật. Dựng lại nó từ `stage_logs.published_at` là viết luật quá hạn lần thứ hai, điều mà `MatterStaleness` tồn tại để ngăn.
- Phán quyết: bảng `performance_snapshots`, một tác vụ hằng ngày lúc 23:50 (`App\Actions\Schedule\CapturePerformanceSnapshots`). Tác vụ gọi **đúng** `MatterStaleness::scopeStale()`, `Deadline::scopeOverdue()` và `ChecklistProgress::totalsByLead()`, rồi ghi kết quả theo người và theo `confidentiality` (tách qua `Matter::scopeOfConfidentiality()`, không tự viết điều kiện).
- Xu hướng trên trang một người có khoảng **cố định**: 90 ngày kết thúc hôm qua (`BuildPerformanceTrend::MEMBER_PAGE_DAYS`, `PerformancePeriod::trailingDays()`). Trang đó không có ô chọn kỳ, nên không để người cài tự chọn.
- **Ảnh chụp là bản ghi lịch sử của đầu ra một luật, không phải một luật.**
  - Số "hôm nay" trên mọi trang luôn tính **trực tiếp**, không bao giờ đọc từ ảnh chụp.
  - Ảnh chụp chỉ dùng cho những ngày đã qua.
  - Test đồng nhất: chạy tác vụ rồi so với số trực tiếp ở cùng thời điểm đóng băng, cho cả ba loại người xem.
- **Ngày không có ảnh chụp để trống, không vẽ thành 0.** Cron trên shared hosting có thể lỡ một ngày. Vẽ 0 là nói dối rằng hôm đó không có vụ nào quá hạn.
- **Không dựng ảnh chụp cho quá khứ.** Xu hướng bắt đầu từ ngày triển khai. Chỉ dữ liệu mẫu có ảnh chụp giả, và chỉ trong `DemoDataSeeder`.
- Giữ 25 tháng (`PerformanceSnapshot::KEEP_MONTHS`) để so được cùng tháng năm trước. Dòng cũ hơn bị xoá trong cùng tác vụ (câu hỏi mở 5).

**R11 — Hiệu năng: truy vấn gộp, đo bằng số, không cache.**
- **Chỉ số quy người theo một cột** (N1–N11, P2, P4, P6, P7): **một truy vấn `GROUP BY` cột quy người** (R5) trên `listableBy(V)`, **không** lọc theo tập người trong SQL. Action giữ lại dòng của người trong trang bằng PHP. Không bao giờ một truy vấn cho mỗi người.
- **Chỉ số quy người theo lịch sử** (P1, P3, P10, P5): nạp **tập việc của kỳ không lọc người** (`listableBy(V)` ∩ trong kỳ), rồi gọi bộ dựng lịch sử **một lần cho cả lô** (`DeadlineHolderAtDue`, `RequestHolderAt`, `LeadAt`), rồi phân loại và lọc người bằng PHP.
  - **Không** nạp mốc bằng `responsible_user_id IN (tập người)`, dù đó là cách đọc hiển nhiên của "truy vấn trên tập người". Nạp như vậy làm rơi mọi mốc đã bị chuyển đi **sau khi** lỡ: người lỡ mốc không bao giờ thấy nó, và R9 bị vô hiệu một cách lặng lẽ. Cùng lý do cho yêu cầu của khách.
  - Với luật sư xem dòng của chính mình, `listableBy(V)` đã nhỏ. Với trưởng phòng xem một quý, tập là vài nghìn dòng, trong ngân sách.
- **Kiểm quyền theo từng người không được sinh truy vấn theo từng người:** `TeamRoster` nạp sẵn `roles.permissions` và `permissions` (R3).
- Số truy vấn của mỗi trang là hằng số theo số người. Test khẳng định số truy vấn bằng nhau với 3 người và với 12 người (`DB::enableQueryLog()`).
- Ba việc tính bằng PHP trên số dòng có chặn, vì SQL không làm được một cách khả chuyển giữa SQLite và MariaDB:
  - trung vị;
  - phân loại đúng hạn/trễ/lỡ theo ngày ở múi giờ ứng dụng;
  - thời gian phản hồi.

  Số dòng của một quý ở quy mô vài nghìn vụ là vài nghìn. Task 8 ghi số đo.
- **Đo trên dữ liệu nhân lên** (`tests/Benchmark/TeamPerformanceBenchmarkTest.php`, ngoài mọi testsuite, theo khuôn `tests/Benchmark/SearchMattersBenchmarkTest.php`):
  - 3.000 vụ (5% `restricted`), 30 nhân sự;
  - 15.000 mốc, 45.000 dòng tiến độ;
  - 6.000 yêu cầu cùng 15.000 trả lời;
  - 30.000 đầu mục, 20.000 tài liệu nhóm A;
  - 150.000 dòng nhật ký, 9.000 khoản thu;
  - ảnh chụp hai năm. Bảng `performance_snapshots` chỉ có từ Task 7, nên phần này (và phép đo tác vụ chụp ≤ 10 giây) được **Task 7** thêm vào benchmark; Task 4 dựng phần còn lại.

  **Ngân sách**, trung vị của 5 lần, MariaDB thật:
  - "Theo dõi đội ngũ" với trưởng phòng ≤ 300 ms;
  - trang một người ≤ 200 ms;
  - "Hiệu suất theo kỳ", một quý, ≤ 500 ms;
  - tác vụ ảnh chụp ≤ 10 giây.
- **Index chỉ thêm khi `EXPLAIN` chứng minh cần**, và chỉ khi ngân sách vỡ. Ứng viên đã biết:
  - `deadlines(responsible_user_id, due_date)`;
  - `stage_logs(created_by, occurred_at)`;
  - `client_requests(created_at)`;
  - `activity_log(causer_type, causer_id, created_at)`;
  - `activity_log(event, created_at)` (P6, lần vô hiệu hoá của R3).

  MariaDB tự tạo index cho khoá ngoại `responsible_user_id`, `created_by`, `assigned_to`, nên có thể không cần cái nào.
- **Không cache.** Lý do:
  - (a) trưởng phòng vừa bàn giao một vụ thì con số phải đổi ngay; số cũ năm phút là số sai đúng lúc người ta nhìn;
  - (b) cache store là `database`, mỗi lần xem thành một lần ghi;
  - (c) một khoá cache quên mang id người xem là rò rỉ R4 kinh điển: số của admin hiện cho trưởng phòng;
  - (d) quy mô vài nghìn vụ nằm trong ngân sách.

  Chỉ khi ngân sách vẫn vỡ sau khi thêm index mới dùng `Cache::remember`, với khoá gồm id người xem và bộ lọc, TTL ≤ 5 phút, kèm test hai người xem không bao giờ dùng chung một mục.

**R12 — Không thư tổng hợp hằng tháng, không thông báo mới.**
- Một thư mang số liệu hiệu suất của từng người là dữ liệu nhân sự nằm trong hộp thư, nơi dữ liệu sống lâu nhất và ít ai kiểm soát nhất. Đây là cùng lập luận với M10 R5.
- Thư có số phải tính theo phạm vi xem của **từng** người nhận (R4). Đó là thêm một đường tính nữa cho đúng những con số này.
- Thư chỉ có liên kết thì không hơn một mục trên thanh điều hướng.
- Cảnh báo theo việc đã có sẵn và đã đúng người nhận: `CheckDeadlines` (mốc quá hạn), `CheckStaleMatters` (21 ngày đồng gửi quản lý), `RemindMissingDocuments`.
- Nếu chủ văn phòng muốn có thư (câu hỏi mở 4), hình dạng đã định trước:
  - chỉ liên kết tới "Hiệu suất theo kỳ" của tháng trước, không con số;
  - người nhận qua một cổng mới trong **đúng** `ResolveStaffRecipients` (như M10 R5 làm cho bản ghi tiếp nhận);
  - xếp hàng sau commit (M6.5 R2);
  - chống trùng qua `outbound_messages` theo `template` cộng tháng.

**R13 — MCP và PWA không chạm tới các con số này.**
- Thêm vào bảng R4 của kế hoạch M11 một dòng: **"Số liệu theo dõi đội ngũ và hiệu suất theo người (`performance_snapshots`, mọi lớp dưới `App\Actions\Performance` và `App\Support\Performance`) — không bao giờ; không tool"**. Ghi bằng đính chính có ngày trong PROGRESS, như M9 và M10 đã làm.
- Bộ tool của M11 hôm nay không có tool nào như vậy. `whoami` chỉ đếm số vụ. Muốn thêm thì phải sửa kế hoạch M11.
- Test cấu trúc `tests/Feature/Performance/PerformanceMcpBoundaryTest.php`, chép khuôn `tests/Feature/Intake/IntakeMcpBoundaryTest.php` của làn M10:
  - quét **token** (không quét chuỗi trong chú thích) dưới `app/Mcp`, `app/Support/Mcp`, `app/Actions/Mcp`;
  - không tham chiếu nào tới `Performance`, `performance_snapshot`, `performance_snapshots`, `TeamRoster`, `DeadlineHolderAtDue`, `RequestHolderAt`, `LeadAt`, `DeadlineOutcome`;
  - có cặp dương trên một fixture.
  - Thư mục MCP có ở làn M11, chưa có trên `main`. Test xanh vì rỗng cho tới khi M11 gộp, rồi canh từ đó.
- M12: không `PushTopic` nào cho các con số này.

**R14 — Đây là dữ liệu cá nhân của nhân sự.** Số liệu hiệu suất gắn với một người cụ thể là dữ liệu cá nhân theo Luật 91/2025/QH15. Kế hoạch không quyết thay luật sư, nhưng cài bốn điều mặc định an toàn:
1. **Chỉ xem, không quyết định tự động.** Hệ thống không tự làm gì dựa trên các con số này: không khoá, không nhắc, không đổi quyền.
2. **Ghi nhật ký khi xem số của người khác.** `Audit::record('performance_viewed', …, causer: $viewer)`:
   - một dòng mỗi lần mở trang của một người khác, chủ thể là người đó;
   - một dòng mỗi lần một người có `performance.viewAny` mở "Hiệu suất theo kỳ", chủ thể rỗng, `properties` mang kỳ;
   - **không** ghi khi xem số của chính mình;
   - một dòng mỗi lần một người có `performance.viewAny` mở "Theo dõi đội ngũ", chủ thể rỗng, `properties` mang `page = team_overview`. Trang đó hiện số "bây giờ" của **mọi** người được theo dõi, nên nó cũng là xem số của người khác. Bật công tắc người nghỉ việc hay sắp xếp không ghi thêm;
   - không ghi lại ở mỗi request Livewire, chỉ ở `mount()` và khi đổi kỳ.

   Nhãn tiếng Việt ở `lang/vi/activity.php` (`ActivityLogEventTranslationsTest`). Ghi đính chính SPEC §10 mục 6.
3. **Không lên MCP, không vào thư** (R12, R13).
4. **Nhân sự được biết.** Trang "Hiệu suất" của chính họ hiện cùng bảng "Cách tính" như trang của trưởng phòng. Việc thông báo chính thức cho nhân sự (nội quy, hợp đồng lao động) là câu hỏi mở 3 cho luật sư của văn phòng.

**R15 — Ngoài phạm vi, có chủ đích.**
- **Không đo giờ làm.** `time_entries` vẫn là khung của M9 (SPEC §15). M13 không đọc bảng đó.
- **Không tỉ lệ thắng kiện.** Không có cột kết quả vụ việc.
- **Không "thời gian xử lý theo loại vụ".**

Ba thứ này nằm trong mục 7 của danh sách nâng cấp giai đoạn 2 mà chủ văn phòng đã dặn nhắc lại khi M12 xong. Task 8 ghi rằng M13 đã làm phần "năng suất" của mục đó, còn lại hai phần.

**R16 — Kỳ.** `App\Support\Performance\PerformancePeriod`:
- các lựa chọn: `last_month` (**mặc định**), `this_month`, `last_quarter`, `this_quarter`, `custom`;
- `custom` dài tối đa 366 ngày, ngày cuối không quá hôm nay.

Mặc định là **tháng trước**, vì đánh giá trên kỳ đã đóng mới công bằng. Kỳ đang chạy phạt những ai có nhiều mốc đến hạn cuối tháng. "Đã đóng" chỉ có nghĩa khi con số của kỳ đó không trôi theo việc làm sau kỳ: R19.

`bounds()` trả hai cận đủ giờ (`00:00:00` … `23:59:59`), đúng như `RevenueFilters::bounds()` (M9 Task 13). Lý do: cast `date` trên SQLite lưu `Y-m-d 00:00:00`, nên cận trên dạng ngày trần làm rơi cả ngày cuối kỳ. `cutoff()` trả mốc cắt của R19.

**`PerformancePeriod` là bộ đọc kỳ thứ hai, có chủ đích, và nói thẳng điều đó.** `RevenueFilters::period()` (M9) là `private`, chỉ có `this_month`, `this_quarter`, `this_year`, `custom`, và không có `last_month`/`last_quarter`. Sửa nó để dùng chung sẽ đổi ô chọn kỳ của trang Doanh thu, ngoài phạm vi M13. Vì vậy:
- test đồng nhất so `PerformancePeriod::bounds()` với `RevenueFilters::fromPageFilters([...])->bounds()` cho `this_month`, `this_quarter` và `custom`;
- `last_month` và `last_quarter` được so **qua `custom`** với cùng hai ngày;
- nếu sau này trang Doanh thu cần "tháng trước", việc đúng là chuyển `RevenueFilters` sang gọi `PerformancePeriod` (ghi PROGRESS), không phải viết bộ đọc thứ ba.

**R17 — Thời gian phản hồi đo bằng giờ lịch, trừ khi `App\Support\BusinessHours` đã có.**
- SPEC không có mục tiêu thời gian trả lời yêu cầu của khách. Vì vậy M13 báo **trung vị và trung bình**, không báo "trong hạn".
- Nếu lúc cắt nhánh M10 đã gộp (`App\Support\BusinessHours`, cấu hình `vkcrm.business_hours`), đo bằng giờ làm việc qua **đúng** lớp đó. Nếu chưa, đo bằng giờ lịch, ghi rõ trên trang, và ghi việc chuyển sang giờ làm việc vào PROGRESS cho người gộp. Không viết định nghĩa "giờ làm việc" thứ hai.
- Đặt mục tiêu là câu hỏi mở 6.

**R18 — Người giữ yêu cầu của khách và người phụ trách vụ tại một thời điểm, dựng lại từ nhật ký.**

Bản đầu của kế hoạch quy yêu cầu về `COALESCE(assigned_to, lead_lawyer_id)` **hiện tại**. Cách đó sai đúng ở chỗ R9 sửa cho mốc:
- gần như mọi luồng có `assigned_to = NULL`: `OpenClientRequest` ghi `null`, trả lời của nhân sự (`ReplyToClientRequest::advanceStatus()`) không bao giờ ghi `assigned_to`, chỉ "Giao việc" tay (`TriageClientRequest::assign()`) mới ghi;
- `ReassignMatter` bước 4 chỉ chuyển luồng có `assigned_to = luật sư cũ`, nhưng vế dự phòng `lead_lawyer_id` lặng lẽ chuyển **mọi** luồng chưa giao, kể cả luồng đã trả lời và đã đóng, cho **mọi** kỳ đã qua;
- khi một luật sư nghỉ việc qua `BulkReassign`/`ReassignMatters`, P3 và P9 của người nhận thừa kế toàn bộ lịch sử và tồn đọng của người trước, còn các tháng đã qua của người trước bị viết lại. Kỳ mặc định "tháng trước" đổi sau khi đã xem.

Phán quyết:
- **`App\Support\Performance\LeadAt::resolve(Collection<Matter> $matters, Closure(Matter): CarbonInterface $at): array<int, ?int>`** — luật sư phụ trách của mỗi vụ tại thời điểm `$at($matter)`:
  - tìm dòng `matter_reassigned` (chủ thể là vụ) **sớm nhất** có `created_at` **sau** thời điểm đó; có thì lấy `properties.from_user_id`, không thì `lead_lawyer_id` hiện tại;
  - `ReassignMatter` là đường **duy nhất** đổi `lead_lawyer_id` (docblock lớp đó), và dòng `matter_reassigned` có từ M6.5, nên lịch sử người phụ trách **đầy đủ cả trước ngày triển khai M13**;
  - **một** truy vấn cho cả lô.
- **`App\Support\Performance\RequestHolderAt::resolve(Collection<ClientRequest> $requests, Closure(ClientRequest): CarbonInterface $at): array<int, ?int>`** — người giữ mỗi luồng tại thời điểm `$at($request)`:
  - người được giao tại thời điểm đó: `properties.from` của dòng `client_request_assigned` sớm nhất **sau** thời điểm đó; không có dòng nào thì `assigned_to` hiện tại;
  - người được giao rỗng thì là `LeadAt` của vụ tại **cùng** thời điểm;
  - **hai** truy vấn cho cả lô (dòng `client_request_assigned` của các luồng, dòng `matter_reassigned` của các vụ), không phụ thuộc số luồng;
  - **không** bỏ qua người đã xoá mềm: đây là lịch sử, không phải người nhận thông báo. Người đã xoá mềm không có dòng (R3), nên việc của họ chỉ hiện ở dòng "Chung". Khác với N9 (bây giờ), nơi người được giao đã xoá mềm nhường cho luật sư phụ trách, đúng như đường thông báo (R5). Hai cách đọc này chỉ khác nhau khi người được giao đã bị xoá mềm trong lúc còn giữ một luồng chưa đóng, điều mà `GuardsStaffOffboarding` chặn.
- **Thời điểm quy người:**
  - luồng **đã trả lời tới hết kỳ** (`answered_at ≤ cutoff`, R19): người giữ lúc `answered_at`, tức người đang chịu trách nhiệm khi văn phòng trả lời lần đầu. **Không** dùng tác giả của dòng `client_request_replies` đầu tiên của nhân sự: trợ lý trả lời thay luật sư là việc của người giữ, và "đã trả lời qua điện thoại" (`TriageClientRequest::setStatus(Answered)`) không có dòng trả lời nào. Một luật cho cả hai đường, cùng hình dạng với "người giữ mốc vào ngày đến hạn";
  - luồng **chưa trả lời tới hết kỳ**, và luồng **đóng không trả lời** (P10): người giữ lúc `cutoff`.
- **`ReassignMatter` bước 4 ghi một dòng `client_request_assigned` cho mỗi luồng đã chuyển** (`from` = luật sư cũ, `to` = luật sư mới, `reason = matter_reassigned`, hằng số `ReassignMatter::REQUEST_HANDOVER_REASON`), trong cùng transaction, ngay sau câu `update()`. Cùng lý lẽ với R9: đây là đường thứ ba ghi `assigned_to`, và hôm nay nó chỉ để lại **số lượng**. Dòng `matter_reassigned` giữ nguyên.
- **Giới hạn đã biết:** luồng được giao **đích danh** cho luật sư cũ rồi bị `ReassignMatter` chuyển **trước ngày triển khai M13** không có dòng riêng; với thời điểm trước lần chuyển đó, người được giao rơi về `assigned_to` hiện tại. Luồng chưa giao ai (gần như mọi luồng) không bị giới hạn này, vì `matter_reassigned` đã đủ. Ghi PROGRESS và câu giải thích của P3.
- **Đồng nhất:** `RequestHolderAt` tại `now()` bằng `ClientRequest::holderId()` trên mọi luồng mà người được giao chưa xoá mềm. `LeadAt` tại `now()` bằng `lead_lawyer_id`.

**R19 — Kỳ đã đóng không trôi: hoàn thành và trả lời chỉ tính tới hết kỳ.**
- `PerformancePeriod::cutoff()` = `min(23:59:59 ngày cuối kỳ, now())`.
- P1: "trễ" là xong **sau** ngày đến hạn nhưng **không muộn hơn** `cutoff`. Xong sau `cutoff` vẫn là "lỡ" của kỳ đó. Mốc đến hạn đúng ngày cuối kỳ vì vậy chỉ có thể đúng hạn hoặc lỡ; câu giải thích nói điều đó.
- P3: "đã trả lời" là `answered_at ≤ cutoff`. Thời gian phản hồi chỉ tính trên những luồng đó.
- P9: tử số theo hai điều trên. Vì vậy "tháng trước" **không** tăng dần trong tháng này khi người ta làm nốt việc tồn.
- **Những gì vẫn còn đổi được sau kỳ, có chủ đích, và câu giải thích chung `performance.explain.closed_period` liệt kê đủ:**
  - dòng tiến độ ghi lùi ngày (P4);
  - admin mở lại vụ đã đóng (P5);
  - gỡ một mốc (chuyển từ P1 sang P2);
  - dời ngày đến hạn (P1 dùng ngày đến hạn hiện tại);
  - mở lại một mốc đã xong (bảng ca biên của P1, ca 8);
  - đóng một luồng chưa trả lời (chuyển từ mẫu số P3 sang P10).

  Mỗi việc trong số đó là một thao tác có người bấm và có dòng nhật ký. Lần bàn giao **không** còn nằm trong danh sách này (R9, R18).
- Test ổn định (Review Focus 5): tính "tháng trước", rồi hôm nay hoàn thành một mốc đã lỡ, trả lời một luồng tồn và bàn giao vụ cho người khác; tính lại: mọi trường của `PerformanceRow` không đổi.

**R20 — Mốc tạo qua AI (M11) tính như mốc thường, ở mọi con số.**
- Khi M11 gộp, `deadlines` có `created_via`, `confirmed_at`, `confirmed_by`, và tool `create_deadline` tạo mốc "Tạo qua AI, chưa xác nhận" với người giữ mặc định là luật sư phụ trách.
- Kế hoạch M11 (Task 12) đã quyết: mốc "Tạo qua AI" được `CheckDeadlines` nhắc như mốc thường, vì "một mốc hạn thật không được im lặng chỉ vì AI tạo". Người giữ đã được nhắc qua thư và thấy mốc trên widget trang chủ.
- Vì vậy M13 **không** thêm scope loại mốc chưa xác nhận. N5, N6, P1, P9 tính chúng như mọi mốc. Một mốc AI tạo sai thì việc đúng là gỡ nó kèm lý do (`DeleteDeadline`), và nó hiện ở P2, không ở tỉ lệ.
- Lý do không loại: loại ở M13 mà không loại ở `CheckDeadlines` và `UpcomingDeadlinesWidget` là đúng một định nghĩa thứ hai của "mốc quá hạn"; loại ở cả ba là đảo phán quyết của M11.
- Mốc tạo qua AI với ngày đến hạn đã qua thì rơi vào ca "mốc ghi sau ngày đến hạn" của P1 (không vào tập), như mọi mốc khác.
- Test (Task 6) chạy khi cột `created_via` đã có trên `main` lúc cắt nhánh: một mốc `created_via = mcp`, chưa xác nhận, quá hạn, có mặt ở N5 và là "lỡ" ở P1, cùng con số với `CheckDeadlines::tierFor()`. Nếu M11 chưa gộp thì ghi vào PROGRESS cho người gộp M11 viết test này.
- `NoSecondDefinitionTest` cấm thêm `created_via`, `confirmed_at` vào điều kiện trong các tệp bị quét. Đảo R20 thì sửa đúng ba chỗ cùng lúc: một scope mới trên `Deadline`, `CheckDeadlines`, `UpcomingDeadlinesWidget::rowsFor()`.

---

## Định nghĩa các con số

Mọi con số: tập vụ gốc là `Matter::query()->listableBy($viewer)` (R4); "đang mở" là `Matter::scopeOpen()`, "đã kết thúc" là `Matter::scopeClosed()`.

Các cột **Lời giải thích** là nội dung (ý chính) của khoá `performance.explain.<mã>`; câu chữ cuối cùng do Task 4–6 viết, không được nói khác ý ở đây.

Dấu **(L)** sau mã: cột chỉ dành cho người phụ trách vụ, hiện "Không áp dụng" khi `TeamRoster::leadsMatters()` sai (R6).

Mọi cận ngày trên cột `date` (`due_date`, `paid_on`) là cận **đủ giờ**: `00:00:00` của ngày đầu, `23:59:59` của ngày cuối, kể cả trong các scope "bây giờ" (`overdue`, `dueWithin`, `upcoming`). Bài học `RevenueFilters::bounds()` áp cho mọi so sánh, không chỉ cho cận của kỳ.

### Bây giờ — trang "Theo dõi đội ngũ" và đầu trang của một người

| Mã | Tên cột | Công thức | Nguồn sự thật | Lời giải thích |
|---|---|---|---|---|
| N1 (L) | Vụ đang phụ trách | đếm `open()`, `GROUP BY lead_lawyer_id` | `Matter::scopeOpen()`; **cùng con số** với `LoadPerLawyerWidget` (M9, `listableBy()->open()` theo `lead_lawyer_id`) cho cùng người xem, không bộ lọc | Số vụ việc đang mở mà người này là luật sư phụ trách. |
| N2 | Vụ đang tham gia | đếm `open()` qua `Matter::scopeWithSupportingMember()` (mới: nối `matter_user`, vai thuộc `MatterRole::supporting()` = {associate, assistant}), `GROUP BY matter_user.user_id` | `MatterRole` | Vụ đang mở mà người này có tên trong đội ngũ với vai luật sư cộng sự hoặc trợ lý. Không tính vai theo dõi. |
| N3 (L) | Vụ đã kết thúc | đếm `closed()`, `GROUP BY lead_lawyer_id` | `Matter::scopeClosed()` | Tổng số vụ đã kết thúc **đang đứng tên** người này, từ trước tới nay. Một vụ đã kết thúc rồi mới bàn giao thì tính cho người nhận; số theo kỳ (P5) thì tính cho người phụ trách lúc vụ kết thúc. |
| N4 (L) | Quá hạn cập nhật cho khách | đếm `MatterStaleness::scopeStale()`, theo người phụ trách; kèm "chưa bật cổng: N" qua `MatterStaleness::scopeNotMeasurable()` (mới, Task 2) | `MatterStaleness` | Vụ đang mở, đã bật cổng khách, mà lần cập nhật gần nhất cho khách đã quá 14 ngày. Cùng luật với cảnh báo trên trang chủ. Luật này không áp cho vụ chưa bật cổng; số vụ đó hiện riêng. |
| N5 | Mốc quá hạn | `Deadline::scopeOverdue()` (mới, Task 2: chưa xong, `due_date < hôm nay 00:00:00`) trên vụ `open()`, `GROUP BY responsible_user_id` | `CheckDeadlines::tierFor()` (`OVERDUE_KEY`) | Mốc người này đang giữ, chưa đánh dấu xong, ngày đến hạn đã qua, trên vụ còn mở. Gồm cả mốc tạo qua AI chưa xác nhận (R20). |
| N6 | Mốc 7 ngày tới | `Deadline::scopeDueWithin(Deadline::UPCOMING_WINDOW_DAYS)` (mới, Task 2: chưa xong, `hôm nay 00:00:00 ≤ due_date ≤ (hôm nay + 7) 23:59:59`) trên vụ `open()` | `Deadline::scopeUpcoming()` = N5 ∪ N6 (sau khi Task 2 sửa cận trên của `scopeUpcoming()`); ngày +7 là bậc `d7` của `CheckDeadlines::tierFor()` | Mốc chưa xong, đến hạn từ hôm nay tới hết ngày thứ 7 kể từ hôm nay. |
| N7 (L) | Chờ giấy tờ của khách | đếm vụ trong `ChecklistProgress::mattersAwaitingClient()`; trong ngoặc là số vụ của `mattersAwaitingClient(…, ChecklistProgress::STUCK_AFTER_DAYS)` | `ChecklistProgress`. `MattersMissingDocumentsWidget::rowsFor()` truyền `STUCK_AFTER_DAYS`, nên widget trang chủ chỉ khớp **số trong ngoặc**, không khớp số chính | Vụ (đã bật cổng) còn đầu mục bắt buộc khách chưa nộp hoặc bị từ chối. Trong ngoặc: số vụ đã chờ quá 14 ngày. |
| N8 (L) | Giấy tờ chờ duyệt | `MatterChecklistItem::scopeAwaitingReview()` (mới, chuyển từ `PendingChecklistReviewsWidget::rowsFor()`), `GROUP BY` người phụ trách vụ | widget §7.1 mục 3 | Đầu mục khách đã nộp mà văn phòng chưa duyệt, trên vụ người này phụ trách. |
| N9 | Yêu cầu chờ trả lời | `ClientRequest::scopeAwaitingOffice()` (mới: `new`, `in_progress`) trên vụ `open()`, `GROUP BY holder_id` của `ClientRequest::scopeWithHolder()` (mới) | `ClientRequestStatus`; người giữ như `ReplyToClientRequest::notifyHolderOfFollowUp()` (R5) | Yêu cầu của khách ở trạng thái Mới hoặc Đang xử lý mà người này đang giữ: được giao, hoặc là luật sư phụ trách khi chưa giao ai (hoặc khi người được giao không còn tài khoản). |
| N10 (L) | Hoàn thiện danh mục | `Σ X / Σ Y` của `ChecklistProgress::totalsByLead()` (mới) trên vụ `open()` | `ChecklistProgress::handle()`, SPEC §4.10 | Trên các vụ đang phụ trách: số đầu mục đã xong (đã duyệt, hoặc không cần nộp) trên số đầu mục phải có. Cùng cách tính thanh "Đã nộp X/Y". |
| N11 | Thao tác hồ sơ gần nhất | `MAX(activity_log.created_at)`, `GROUP BY causer_id` (chỉ `causer_type = user`), dòng thuộc một vụ trong `listableBy(V)` qua `ActivityOwningMatter::scopeOwnedByVisibleMatters()` (mới). Scope đó gọi `whereOwnedByAny()` với `includeMoney = isAdmin(V) \|\| V có billing.view`, đúng như `scopeOwnedBy()`; sau khi M10 gộp, nó chồng **cùng** cổng bản ghi tiếp nhận của `scopeVisibleTo()` (dòng chủ thể `intake_request` có `properties.matter_id` chỉ khi V xem được chính bản ghi đó). Admin **không** được lối tắt `return` của `scopeVisibleTo()`: dòng không thuộc vụ nào (đăng nhập) không bao giờ tính | `ActivityOwningMatter` | Lần gần nhất người này ghi một thay đổi vào một vụ việc bạn được xem, theo nhật ký hệ thống. Đăng nhập không tính. |

### Trong kỳ — trang "Hiệu suất theo kỳ"

| Mã | Tên cột | Công thức | Nguồn sự thật | Lời giải thích |
|---|---|---|---|---|
| P1 | Mốc đúng hạn | **Nạp:** `Deadline::scopeDueBetween($period->bounds())` (mới), vụ trong `listableBy(V)` (vụ đã huỷ tự rơi vì `SoftDeletes` của `Matter`), **không** lọc người (R11). **Phân loại:** `Deadline::outcomeAt($period->cutoff())` (mới, Task 2) trả `DeadlineOutcome` (`on_time`, `late`, `missed`) hoặc `null` = không vào tập; xem bảng ca biên ngay dưới. **Tỉ lệ** = đúng hạn / (đúng hạn + trễ + lỡ). **Quy về:** `DeadlineHolderAtDue` (R9); `null` thì chỉ vào dòng "Chung" | `Deadline`, `SetDeadlineCompletion` | Trong các mốc đến hạn trong kỳ: số mốc được đánh dấu xong trước khi hết ngày đến hạn, trên tổng. "Trễ" là xong sau ngày đến hạn nhưng trước khi hết kỳ; xong sau khi hết kỳ vẫn là "lỡ" của kỳ đó. Mốc tính cho người giữ nó vào ngày đến hạn; với các lần bàn giao trước ngày triển khai tính năng này, tính cho người giữ hiện tại. Mốc ghi vào hệ thống sau ngày đến hạn, và mốc của vụ đã kết thúc trước khi hết ngày đến hạn, không tính. |
| P2 | Mốc đã gỡ | `Deadline::scopeRemovedBetween($period->bounds())` (mới: chỉ dòng xoá mềm, `deleted_at` trong kỳ), vụ trong `listableBy(V)`, `GROUP BY responsible_user_id` (mốc đã gỡ không bao giờ đổi người giữ: `ReassignMatter` và mọi Action khác chỉ nạp mốc chưa xoá) | `DeleteDeadline` | Mốc người này giữ đã bị gỡ (xoá kèm lý do) trong kỳ. Không tính vào tỉ lệ; hiện ra để tỉ lệ không đẹp lên nhờ gỡ mốc. |
| P3 | Trả lời yêu cầu của khách | **Nạp:** `ClientRequest::scopeCreatedBetween($period->bounds())` (mới), vụ trong `listableBy(V)`, không lọc người. **Tập (mẫu số):** mọi yêu cầu đã nạp trừ `isClosedWithoutAnswer()` (mới: `closed` và `answered_at` rỗng; những luồng này vào P10). **Đã trả lời:** `answeredBy($period->cutoff())` (mới: `answered_at` có giá trị và ≤ mốc cắt, R19). **Thời gian phản hồi:** `answered_at − created_at` (R17) trên các luồng đã trả lời, trung vị và trung bình. **Quy về:** `RequestHolderAt` (R18), tại `answered_at` với luồng đã trả lời, tại mốc cắt với luồng chưa trả lời | `ReplyToClientRequest` (`answered_at ??= now()`), `TriageClientRequest::setStatus()` | Yêu cầu khách gửi trong kỳ: số đã trả lời tới hết kỳ trên tổng (không tính yêu cầu văn phòng đóng mà không trả lời); thời gian từ lúc khách gửi tới lần trả lời đầu tiên của văn phòng (trung vị và trung bình, tính theo giờ lịch hoặc giờ làm việc). Luồng tính cho người đang giữ nó lúc văn phòng trả lời, hoặc lúc hết kỳ nếu chưa trả lời. |
| P4 (L) | Chuyển giai đoạn | `StageLog::entries()` (M9-final) và `StageLog::scopeOccurredBetween($period->bounds())` (mới), vụ trong `listableBy(V)`, `GROUP BY created_by, matter_id`: số dòng và số vụ khác nhau | `StageLog::scopeEntries()` | Số lần người này đưa một vụ sang giai đoạn mới trong kỳ, theo ngày ghi trên dòng tiến độ, và số vụ khác nhau đã được đưa đi. Không chia "tiến" hay "lùi". Dòng ghi lùi ngày làm đổi số của kỳ đã qua. |
| P5 (L) | Vụ kết thúc trong kỳ | `Matter::scopeClosedWithin($from, $to)` (mới), nạp `id`, `closed_at`, `lead_lawyer_id`; **quy về** `LeadAt` tại `closed_at` (R18) | `Matter` (chỗ duy nhất được viết điều kiện trên `closed_at`) | Vụ người này phụ trách **lúc vụ kết thúc** đã vào giai đoạn kết thúc trong kỳ. Bàn giao một vụ đã kết thúc không chuyển con số này. |
| P6 | Giấy tờ đã duyệt | dòng nhật ký `checklist_item_reviewed` (ghi bởi `ReviewChecklistItem`, mang `status` = `accepted`/`rejected`) qua `ActivityOwningMatter::scopeEventsWithin($query, ReviewChecklistItem::AUDIT_EVENT, $period->bounds())` (mới) và `scopeOwnedByVisibleMatters($query, V)`, `GROUP BY causer_id` | `ReviewChecklistItem` (hằng số tên sự kiện mới `ReviewChecklistItem::AUDIT_EVENT`) | Số lần người này bấm duyệt hoặc từ chối một đầu mục trong kỳ, theo nhật ký hệ thống. Một đầu mục khách nộp lại rồi được duyệt lại tính hai lần: đó là hai lần duyệt. Văn phòng tải giấy tờ lên thay khách không phải một lần duyệt. |
| P7 (L) | Doanh thu đã thu | `CollectedRevenue::query($viewer, $bounds)` (mới, tách nguyên văn từ `RevenueOverTimeWidget::computeBuckets()`), `SUM(amount)` theo `attributed_lawyer_id` | M9 P2 | Tiền khách đã trả trong kỳ, tính cho luật sư phụ trách vụ tại lúc ghi khoản thu. Cùng con số trên trang Doanh thu. |
| P8 | Xu hướng | N4, N5 và `X/Y` của N10 vào cuối mỗi ngày, từ `performance_snapshots` (R10); trên trang hiệu suất: giá trị ảnh chụp ngày đầu kỳ → ngày cuối kỳ có ảnh chụp (không muộn hơn hôm qua). Trên trang một người: 90 ngày gần nhất (Task 7) | `CapturePerformanceSnapshots` | Số vụ quá hạn cập nhật, số mốc quá hạn và mức hoàn thiện danh mục vào cuối mỗi ngày. Ngày không có ảnh chụp để trống. |
| P9 | Hoàn thành việc đến hạn | R7 | P1 + P3 | Mốc đến hạn đã xong tới hết kỳ (đúng hạn hoặc trễ) cộng yêu cầu khách đã trả lời tới hết kỳ, chia cho tổng mốc đến hạn và yêu cầu nhận trong kỳ (trừ yêu cầu đóng không trả lời). Mỗi việc một đơn vị, không trọng số. Dưới 5 việc thì không tính tỉ lệ. |
| P10 | Yêu cầu đóng không trả lời | các yêu cầu đã nạp ở P3 có `isClosedWithoutAnswer()`, quy về `RequestHolderAt` tại mốc cắt | `TriageClientRequest::setStatus()` (Mới → Đã đóng) | Yêu cầu khách gửi trong kỳ mà văn phòng đóng lại không trả lời (trùng, khách rút, đã giải quyết ngoài hệ thống). Không tính vào tỉ lệ; hiện ra để tỉ lệ không đẹp lên nhờ đóng luồng chưa trả lời. |
| — | Lĩnh vực chính | hai `matter_type_id` có nhiều vụ nhất trong **tập vụ có việc của người này trong kỳ**: vụ của các mốc P1 (khác `null`), các luồng P3/P10, các dòng P4, các vụ P5 đã quy về người đó; kèm số vụ. Một truy vấn `matter_type_id` cho hợp các vụ đó (đã nằm trong `listableBy(V)`) | `MatterType` | Hai lĩnh vực có nhiều vụ nhất trong số vụ người này có việc trong kỳ, để đọc các tỉ lệ trong đúng bối cảnh của kỳ đó. |

**P1 — các ca biên của `Deadline::outcomeAt(CarbonInterface $cutoff): ?DeadlineOutcome`** (Task 2 cài và test từng ca; `$dueEnd` = 23:59:59 của `due_date` theo `APP_TIMEZONE`; cần quan hệ `matter` đã nạp, kể cả `closed_at`):

| # | Ca | Kết quả | Vì sao |
|---|---|---|---|
| 1 | `created_at > $dueEnd` (mốc ghi vào hệ thống sau ngày đến hạn: nhập dữ liệu cũ, ghi lại một phiên toà đã qua, mốc AI tạo với ngày đã qua) | `null` | Không ai được giao việc đó trước hạn; tính "lỡ" là phạt người ghi chép |
| 2 | `is_completed = true`, `completed_at` rỗng (dữ liệu mẫu hoặc dữ liệu cũ; mọi đường của ứng dụng ghi hai cột cùng lúc trong `SetDeadlineCompletion`) | `null` | Không biết xong lúc nào thì không xếp được đúng hạn hay trễ; không đoán |
| 3 | `is_completed = true`, `completed_at ≤ $dueEnd` | `on_time` | |
| 4 | `is_completed = true`, `$dueEnd < completed_at ≤ $cutoff` | `late` | R19 |
| 5 | chưa xong, hoặc xong sau `$cutoff`; và `$dueEnd > $cutoff` (đến hạn hôm nay, kỳ đang chạy) | `null` | Chưa hết ngày đến hạn |
| 6 | chưa xong, hoặc xong sau `$cutoff`; vụ `closedOnOrBefore(due_date)` (mới: `isClosed()` và `closed_at ≤ $dueEnd`, kể cả vụ kết thúc **đúng** ngày đến hạn) | `null` | Vụ đã kết thúc thì mốc hết hiệu lực |
| 7 | chưa xong, hoặc xong sau `$cutoff`, các ca trên không áp | `missed` | |
| 8 | mốc đã xong đúng hạn rồi bị **mở lại** sau ngày đến hạn (`SetDeadlineCompletion` ghi `completed_at = null`) | theo trạng thái hiện tại: ca 7 (hoặc ca 4 nếu xong lại trước `$cutoff`), tính cho người giữ vào ngày đến hạn | Mở lại là văn phòng nói mốc đó **chưa** xong; con số đi theo lời đó. Lần mở lại có người bấm và có dòng `deadline_completion_set`. Đây là một trong các thao tác được phép làm đổi kỳ đã đóng (R19) |
| 9 | dòng lịch sử người giữ có `from` rỗng hoặc không phải số | kết quả phân loại giữ nguyên, nhưng `DeadlineHolderAtDue` trả `null` | Không quy về ai, chỉ vào dòng "Chung" (R9) |

`responsible_user_id` rỗng không phải một ca: cột `NOT NULL`.

**Định dạng.**
- Tỉ lệ in `87,5% (35/40)`: dấu phẩy thập phân, luôn kèm tử và mẫu, qua `App\Support\Performance\Ratio::label()`. Không dùng `intl`.
- Thời lượng in "3,5 giờ" hoặc "2 ngày 4 giờ".
- Tiền qua `Money::format()`.

---

## Mô hình dữ liệu

**`performance_snapshots`** — ảnh chụp cuối ngày (R10). Không blameable, vì hệ thống ghi. Không xoá mềm: đây là số gộp, không phải hồ sơ. Xoá dòng quá hạn bằng `delete()`, không phải `forceDelete()`, nên `RecordsAreNeverForceDeletedTest` không bị đụng.

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `captured_on` | date | Ngày theo `APP_TIMEZONE` lúc chụp |
| `user_id` | FK `users`, `restrictOnDelete` | Người được chụp, trong `TeamRoster::members()` (trackable, đang hoạt động) |
| `confidentiality` | string(20) | `Confidentiality`. Dòng `normal` **luôn** được ghi; dòng `restricted` chỉ ghi khi có ít nhất một giá trị > 0 |
| `open_lead_matters` | unsignedInteger | N1 |
| `stale_matters` | unsignedInteger | N4 |
| `overdue_deadlines` | unsignedInteger | N5 |
| `checklist_settled` | unsignedInteger | `X` của N10 |
| `checklist_total` | unsignedInteger | `Y` của N10 |
| `created_at` / `updated_at` | timestamps | |

- Unique `(captured_on, user_id, confidentiality)`: chạy lại trong ngày là `upsert`, ghi đè bằng số mới hơn của cùng ngày. Index `(user_id, captured_on)`.
- "Một ngày bị lỡ" nghĩa là **không có dòng `normal`** của ngày đó. Vì vậy dòng `normal` luôn được ghi, kể cả khi toàn số 0.
- Model `App\Models\PerformanceSnapshot`:
  - `RestrictedToClientPortal`, với `applyClientPortalConstraints()` là `1 = 0`;
  - policy `PerformanceSnapshotPolicy` từ chối mọi `ClientUser` và mọi thao tác ghi. `PortalCoverageTest` tự quét mọi model và phải xanh **không** thêm miễn trừ;
  - alias morph `performance_snapshot` (map nghiêm ngặt);
  - `visibleLevels(User $viewer, User $subject)` là hàm quyết định duy nhất; `scopeVisibleTo(Builder, User $viewer, User $subject)` và `scopeVisibleToMany()` (cho cả trang, Task 7) dịch nó sang SQL. Luật R4 cho **cả hai** loại dòng, đóng khi không chắc: dòng `normal` chỉ khi người xem có `matter.viewAny` hoặc là chính người đó (có `matter.view`); dòng `restricted` suy từ `Matter::isListableBy()` trên một vụ `restricted` giả do người đó phụ trách; không vế nào thì `1 = 0`.

**Enum mới:** `App\Enums\DeadlineOutcome: string` (`on_time`, `late`, `missed`), có `label()` qua `lang/vi/performance.php`. Kết quả của `Deadline::outcomeAt()`; không lưu vào cột nào.

**Không thêm cột nào vào bảng đã có.** Các thay đổi về dữ liệu khác:
- Dòng nhật ký mới: `deadline_responsible_changed` từ `ReassignMatter` và `UpdateDeadline` (R9); `client_request_assigned` từ `ReassignMatter` bước 4, mỗi luồng một dòng (R18); `performance_viewed` (R14).
- Quyền mới `performance.viewAny` (R2). Máy chủ đã có dữ liệu nhận quyền này qua `db:seed --force` (`RolesAndPermissionsSeeder`, `docs/CAI-DAT.md` mục cập nhật).
- Index chỉ khi R11 chứng minh cần. Nếu thêm: một migration riêng ở Task 8, chạy vòng MariaDB thật.

---

## Review Focus

1. **Lộ sự tồn tại của vụ `restricted` qua con số.** Dựng một vụ `restricted` của luật sư L. Vụ đó chứa **một bản ghi cho mọi chỉ số**:
   - quá hạn cập nhật;
   - mốc quá hạn, mốc lỡ trong kỳ, mốc đã gỡ;
   - đầu mục chờ khách, đầu mục chờ duyệt, một lần duyệt trong kỳ (dòng `checklist_item_reviewed`);
   - yêu cầu chờ trả lời, yêu cầu đã trả lời, yêu cầu đóng không trả lời;
   - dòng chuyển giai đoạn, vụ kết thúc trong kỳ;
   - khoản thu, dòng nhật ký, ảnh chụp.

   Phải chứng minh:
   - số trưởng phòng đọc về L **bằng đúng** số khi vụ đó không tồn tại, trên mọi trường của `TeamWorkloadRow`, `PerformanceRow` (kể cả dòng "Chung" và "Lĩnh vực chính") và dữ liệu xu hướng. Dataset lặp qua **tên thuộc tính của DTO**, nên một chỉ số thêm sau mà quên điều kiện sẽ làm test đỏ;
   - L và admin thấy vụ đó được tính;
   - không câu chữ, thứ tự sắp xếp hay số trang nào khác đi; không cột nào của L đổi giữa "0" và "Không áp dụng" (R6);
   - widget xu hướng, gọi thẳng qua Livewire với `subjectId` của L, cho trưởng phòng cùng chuỗi số như khi dòng `restricted` không tồn tại.

   Test ở Task 4, 5, 6, 7.
2. **Định nghĩa thứ hai.** Mỗi cột "bây giờ" bằng đúng số dòng widget trang chủ tương ứng, lọc theo người, cho cùng người xem:
   - N1 ↔ `LoadPerLawyerWidget` (M9, trang Doanh thu; đọc qua `numberTableRows()`, không bộ lọc): cùng số cho từng luật sư. *Vì sao test đồng nhất chứ không gộp hai bên vào một scope gộp:* truy vấn của widget mang bộ lọc lĩnh vực, bộ lọc luật sư và phép nối `users` để lấy tên, lại vừa được làn M9-final sửa; tách nó ra là sửa mã M9 không vì lý do của M9. Hai bên đã cùng đi qua `listableBy()->open()`, chỉ khác phép đếm; test ghim phép đếm đó;
   - N4 ↔ `StaleMattersWidget`;
   - N5 + N6 ↔ `UpcomingDeadlinesWidget::rowsFor()`;
   - **số trong ngoặc** của N7 ↔ `MattersMissingDocumentsWidget::rowsFor()` (widget truyền `STUCK_AFTER_DAYS`; số chính của N7 không có widget tương ứng, nó đồng nhất với `ChecklistProgress::mattersAwaitingClient()` không tham số);
   - N8 ↔ `PendingChecklistReviewsWidget::rowsFor()`.

   Các đồng nhất khác:
   - N10 bằng tổng `ChecklistProgress::handle()` từng vụ;
   - P7 bằng tổng của `RevenueOverTimeWidget` với bộ lọc luật sư cùng kỳ; P7 của dòng "Chung" bằng tổng không lọc luật sư;
   - `scopeOverdue()` và `scopeDueWithin(7)` đồng ý với `CheckDeadlines::tierFor()` (quá hạn ↔ `OVERDUE_KEY`; ngày +7 ↔ `d7`);
   - `ClientRequest::holderId()` bằng người mà `notifyHolderOfFollowUp()` báo, kể cả khi người được giao đã xoá mềm;
   - `RequestHolderAt` và `LeadAt` tại `now()` bằng `holderId()` và `lead_lawyer_id`.

   Test cấu trúc cấm điều kiện trên cột nghiệp vụ trong mã M13, ở **mọi** tệp bị quét (Ràng buộc toàn cục). Test ở Task 2, 4, 6.
3. **Công bằng khi bàn giao.**
   - **Mốc:** mốc lỡ rồi mới được bàn giao (qua `ReassignMatter`, `ReassignMatters`, `ChangeDeadlineResponsible`, `UpdateDeadline`, lần mở lại của `SetDeadlineCompletion`) tính cho người trước. Mốc bàn giao **trước** ngày đến hạn tính cho người sau.
   - **Yêu cầu của khách, chưa giao ai** (ca thường gặp nhất): luật sư A phụ trách vụ; luồng được trả lời trong tháng 9 khi A đang phụ trách; tháng 10 `ReassignMatter` (và, ca riêng, `ReassignMatters` hàng loạt) chuyển vụ sang B. P3 và P9 tháng 9: luồng là của A, B không có gì. Luồng chưa trả lời tới hết tháng 9 cũng là của A ở tháng 9. Tháng 9 của A không đổi sau lần bàn giao.
   - **Yêu cầu của khách, giao đích danh cho A**, rồi `ReassignMatter` bước 4 chuyển sang B (dòng `client_request_assigned` mới, R18): kỳ trước lần chuyển là của A, kỳ sau là của B.
   - **Yêu cầu của khách, giao cho trợ lý C:** lần bàn giao vụ không đụng tới; luồng vẫn của C.
   - **Vụ kết thúc rồi mới bàn giao:** P5 của kỳ kết thúc vẫn là của người phụ trách cũ.

   Test ở Task 3, 6.
4. **Đường Livewire và 404 đồng nhất.** Các trường hợp:
   - sửa `subjectId` (`#[Locked]` phải chặn) trên trang `TeamMember` **và** trên từng widget xu hướng;
   - mất quyền giữa `mount()` và một request Livewire (hook `boot()` trả 404), trên trang **và** trên từng widget (kể cả một lần gọi lại của widget sau khi trang cha đã render);
   - luật sư gọi thẳng widget xu hướng (`Livewire::test(StaleTrendWidget::class, ['subjectId' => <đồng nghiệp>])`): 404, không có chuỗi số nào trong response;
   - kế toán ở cả ba trang và hai widget;
   - luật sư mở trang người khác;
   - người ngoài danh sách R3, và id không tồn tại: cùng một response.

   Test ở Task 1, 5, 6, 7.
5. **Ngày cuối kỳ, ngày biên và múi giờ.** Các trường hợp:
   - mốc xong lúc 23:59:59 của ngày đến hạn là đúng hạn, lúc 00:00:01 hôm sau là trễ;
   - mốc đến hạn hôm nay chưa xong chưa vào tập;
   - khoản thu và mốc vào đúng ngày cuối kỳ được tính, trên **cả** SQLite và MariaDB (bài học `RevenueFilters::bounds()`);
   - `scopeOverdue()`, `scopeDueWithin(7)` và `scopeUpcoming(7)` ở ngày hôm nay và ngày +7, trên **cả** SQLite và `test:mariadb`: mốc ngày +7 có trong `dueWithin(7)` và `upcoming(7)` (hôm nay `scopeUpcoming()` làm rơi nó trên SQLite);
   - kỳ đã đóng ổn định (R19): hoàn thành mốc, trả lời luồng, bàn giao vụ sau kỳ không đổi số của kỳ;
   - ảnh chụp lúc 23:50 ghi `captured_on` theo `APP_TIMEZONE`.

   Test ở Task 2, 6, 7, có `travelTo()`.

---

## Tasks

### - [x] Task 1 — Quyền, policy, danh sách người được theo dõi, khung ba trang, đính chính SPEC, ranh giới MCP

**Tệp:**
- sửa: `app/Enums/Permission.php` (case mới, docblock đếm quyền cộng dồn), `app/Enums/Role.php` (Manager thêm quyền; Admin tự có qua `Permission::cases()`), `lang/vi/permissions.php`;
- sửa: `app/Policies/UserPolicy.php` (hai phương thức mới);
- mới: `app/Support/Performance/TeamRoster.php`;
- mới: `app/Filament/Admin/Pages/TeamOverview.php` (`/team`), `TeamMember.php` (`/team/{user}`), `Performance.php` (`/performance`), cùng ba view Blade dưới `resources/views/filament/admin/pages/`, và `lang/vi/performance.php`;
- sửa: `docs/SPEC.md` (đính chính, xem dưới);
- sửa: `tests/Feature/Authorization/RolesAndPermissionsTest.php`: `toHaveCount(17)` → 18 và ma trận quyền của Manager và Admin. Sửa **có chủ đích**, ở bước đầu, trước khi thêm case; báo trong báo cáo. Tệp này M10 cũng sửa (xung đột, Ràng buộc toàn cục);
- mới: `tests/Feature/Performance/PerformanceAccessTest.php`, `TeamRosterTest.php`, `PerformanceMcpBoundaryTest.php`.

**Giao diện:**
```php
enum Permission: string { case PerformanceViewAny = 'performance.viewAny'; }

final class TeamRoster {
    /** @var list<Role> */ public const TRACKED_ROLES = [Role::Lawyer, Role::Assistant, Role::Manager];
    /** @return Collection<int, User> có performance.viewAny: mọi người theo R3 ("bây giờ"); không có: chỉ chính $viewer nếu trackable.
     *  Đã nạp sẵn roles.permissions và permissions. */
    public static function subjectsFor(User $viewer, bool $includeInactive = false): Collection;
    /** mọi người trackable, không phụ thuộc người xem; subjectsFor(), subjectsForPeriod() và tác vụ chụp (Task 7) gọi lại. Đã nạp sẵn vai trò. */
    public static function members(bool $includeInactive = false): Collection;
    /** vai trò thuộc TRACKED_ROLES và chưa xoá mềm; người nghỉ việc VẪN trackable (R3) */
    public static function isTrackable(User $subject): bool;
    /** có matter.transitionStage — cùng quyền với CreateMatter::leadLawyerOptions(); R6 */
    public static function leadsMatters(User $subject): bool;
    // subjectsForPeriod() thêm ở Task 6, khi PerformancePeriod đã có.
}

// UserPolicy
public function viewPerformance(User|ClientUser $viewer, User $subject): bool;
public function viewPerformanceRevenue(User|ClientUser $viewer, User $subject): bool;
```

**Bước:**
- [x] Grep theo "Ràng buộc toàn cục" và dán kết quả. Nếu thiếu `StageLog::scopeEntries()` hoặc `RevenueFilters::bounds()` thì dừng lại.
- [x] Quyền và policy theo R2, danh sách người theo R3.
- [x] Ba trang, mỗi trang có icon riêng (Heroicon, không trùng icon đã dùng) và tiêu đề tiếng Việt:
  - `TeamOverview::canAccess()` = `performance.viewAny`;
  - `TeamMember` và `Performance`: `canAccess()` = `matter.view` **hoặc** `performance.viewAny`;
  - `TeamMember::mount(int|string $user)` nạp người dùng **chưa xoá mềm** (đang hoạt động hay đã nghỉ việc đều được), rồi `abort_unless(Gate::forUser($viewer)->allows('viewPerformance', $subject), 404)`. Không tìm thấy cũng là 404 đó;
  - `boot()` của cả ba trang hỏi lại.
  - `TeamOverview::mount()` ghi `performance_viewed` (R14, `page = team_overview`).
- [x] Thanh điều hướng:
  - người có `performance.viewAny` thấy "Theo dõi đội ngũ" và "Hiệu suất";
  - người khác thấy "Việc của tôi" (đường dẫn tới `TeamMember` của chính mình, qua `getNavigationUrl()`, đọc `vendor/filament/filament/src/Pages/Page.php:270` trước) và "Hiệu suất".

  Thân trang ở task này chỉ là khung rỗng, có câu R4 cố định.
- [x] **Đính chính SPEC, mỗi mục kèm ngày 2026-10-04:**
  - §1, bảng người dùng: dòng "Trưởng phòng / Ban lãnh đạo" thêm "theo dõi tiến độ và hiệu suất của đội ngũ (M13)";
  - §5: bảng R2, câu "số của chính mình", và kế toán "không";
  - §6.14 mới "Số liệu đội ngũ và hiệu suất": bảng "Định nghĩa các con số" ở trên (kể cả bảng ca biên của P1), R5, R6 ("Không áp dụng"), R7, R9, R18 (người giữ yêu cầu tại một thời điểm), R19 (kỳ đã đóng không trôi), R20 (mốc tạo qua AI);
  - §7.5 mới: ba trang;
  - §7.1: widget "Mốc thời hạn sắp tới" nay gồm cả mốc đến hạn đúng ngày thứ 7 trên mọi CSDL (bản sửa `scopeUpcoming()`, Task 2);
  - §10 mục 6: `performance_viewed` (ba trang), các lý do mới của `deadline_responsible_changed`, và dòng `client_request_assigned` mới từ `ReassignMatter`;
  - §11 mục mới "Theo dõi đội ngũ": restricted không lộ qua con số; kế toán 404; luật sư chỉ thấy số của mình;
  - §13: dòng M13;
  - §15: phần "năng suất" của báo cáo quản trị đã làm; giờ làm và tỉ lệ thắng vẫn để sau (R15).
- [x] Test cấu trúc MCP theo R13.

**Test bắt buộc** (mỗi vế một mutation probe; nhân chứng được cấp quyền trực tiếp, không qua vai trò, khi cần tách quyền):
- [x] Ma trận năm vai trò × ba trang:
  - admin, quản lý: 200 cả ba;
  - luật sư, trợ lý: 404 `TeamOverview`, 200 `Performance`, 200 trang của mình, 404 trang người khác;
  - kế toán: 404 cả ba, kể cả trang "của mình".
- [x] `/team/{id}` của admin, của kế toán, của người đã xoá mềm, và của id không tồn tại: cùng một response 404, cùng thân.
- [x] `/team/{id}` của một luật sư **đã nghỉ việc** (chưa xoá mềm): 200 với trưởng phòng. Mutation probe: thêm `is_active` vào `isTrackable()` thì test đỏ.
- [x] Mất quyền sau `mount()`: lần gọi Livewire kế tiếp trả 404, nhờ hook `boot()`. Mutation probe: xoá dòng hỏi trong `boot()` thì test đỏ.
- [x] `TeamRoster`:
  - có luật sư, trợ lý, quản lý; không có admin, kế toán;
  - `subjectsFor()`: người nghỉ việc chỉ có khi bật công tắc; người đã xoá mềm không bao giờ có;
  - **không** phụ thuộc vụ việc: luật sư chỉ có vụ `restricted` vẫn có mặt trong danh sách của trưởng phòng;
  - `leadsMatters()`: đúng với luật sư và quản lý, sai với trợ lý; theo quyền, không theo vụ;
  - gọi `Gate::allows('viewPerformance', …)` trên 3 rồi 12 người trả về: số truy vấn bằng nhau (vai trò đã nạp sẵn).
- [x] Mọi vai trò có `performance.viewAny` cũng có `matter.viewAny` (test cấu trúc trên `Role::permissions()`).
- [x] `PerformanceMcpBoundaryTest` xanh, kèm cặp dương trên fixture.

**Commit:** `feat: M13 Task 1 — quyền performance.viewAny (đính chính SPEC §5), UserPolicy::viewPerformance, TeamRoster, khung ba trang trả 404 đồng nhất, ranh giới MCP`

### - [x] Task 2 — Những định nghĩa còn thiếu, đặt vào đúng lớp đang giữ luật (không màn hình)

Task này không thêm hành vi nào mà người dùng thấy, trừ một bản sửa lỗi có chủ đích (`Deadline::scopeUpcoming()` làm rơi ngày +7 trên SQLite). Nó chuyển luật đang nằm trong widget xuống model, và thêm đúng những scope M13 cần **vào cạnh luật đã có**. Mỗi scope mới có test đồng nhất với nơi đang dùng luật đó.

**Tệp:**
- `app/Models/Matter.php`: `scopeClosedWithin()`, `closedOnOrBefore()`, `scopeWithSupportingMember()`, `scopeLedBy()`, `scopeWorkedOnBy()`, `scopeOfConfidentiality()`. Đây là tệp duy nhất được phép viết điều kiện trên `closed_at`;
- `app/Enums/MatterRole.php`: `supporting()` (= associate, assistant);
- `tests/Feature/Models/MatterTest.php:171`: **tách** phần quét thành một hàm nhận mã nguồn (`matterClosedAtConditionIn(string $source): bool`, theo khuôn `forceDeleteCallLines()` ở `tests/Feature/RecordsAreNeverForceDeletedTest.php:28`), để test gọi được trên một fixture chứ không chỉ trên `app_path()`. Rồi siết regex để bắt cả `whereBetween(`, `whereDate(`, `whereColumn(` và so sánh `<`, `<=`, `>`, `>=` trên `closed_at`;
- `app/Support/MatterStaleness.php`: `scopeNotMeasurable()`;
- `app/Models/Deadline.php`: `UPCOMING_WINDOW_DAYS`, `scopeOverdue()`, `scopeDueWithin()`, `scopeDueBetween()`, `scopeRemovedBetween()`, `scopeHeldBy()`, `outcomeAt()`; **sửa** `scopeUpcoming()` sang cận trên đủ giờ. `UpcomingDeadlinesWidget::WINDOW_DAYS` trở thành bí danh `= Deadline::UPCOMING_WINDOW_DAYS`;
- mới: `app/Enums/DeadlineOutcome.php`;
- `app/Models/MatterChecklistItem.php`: `scopeAwaitingReview()`. `PendingChecklistReviewsWidget::rowsFor()` gọi nó;
- `app/Models/ClientRequest.php`: `scopeAwaitingOffice()`, `scopeWithHolder()`, `scopeHeldBy()`, `holderId()`, `scopeCreatedBetween()`, `isClosedWithoutAnswer()`, `answeredBy()`. **Không** sửa `ReplyToClientRequest` (xem bước);
- `app/Models/StageLog.php`: `scopeOccurredBetween()`;
- `app/Actions/Document/ReviewChecklistItem.php`: hằng số `AUDIT_EVENT = 'checklist_item_reviewed'`, dùng ở chính câu `Audit::record()` của nó;
- `app/Actions/Document/ChecklistProgress.php`: `totalsByLead()`;
- mới: `app/Support/Billing/CollectedRevenue.php`. `RevenueOverTimeWidget::computeBuckets()` gọi nó, không còn tự viết truy vấn;
- `app/Support/ActivityOwningMatter.php`: `scopeOwnedByVisibleMatters()`, `scopeEventsWithin()`. Tệp này M10 cũng sửa (Ràng buộc toàn cục);
- mới: `tests/Feature/Performance/SingleSourceParityTest.php`, `NoSecondDefinitionTest.php`, `DeadlineOutcomeTest.php`, `DeadlineScopeBoundaryTest.php`.

**Giao diện:**
```php
// Matter
public function scopeClosedWithin(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder; // scopeClosed() + closed_at trong [from 00:00:00, to 23:59:59]
public function closedOnOrBefore(CarbonInterface $day): bool;                                            // isClosed() và closed_at ≤ 23:59:59 của $day (kể cả đúng ngày đó)
public function scopeWithSupportingMember(Builder $query): Builder; // join matter_user, role_in_matter ∈ MatterRole::supporting(), select matter_user.user_id as member_id
public function scopeLedBy(Builder $query, User $subject): Builder;     // lead_lawyer_id = X (danh sách giấy tờ chờ duyệt của Task 5)
public function scopeWorkedOnBy(Builder $query, User $subject): Builder; // ledBy(X) hoặc X trong team với vai supporting (bảng vụ của Task 5)
public function scopeOfConfidentiality(Builder $query, Confidentiality $level): Builder; // cho tác vụ chụp (Task 7), không phải luật xem

// MatterStaleness
public static function scopeNotMeasurable(Builder $query): Builder;  // open() và chưa bật cổng: phần bù của điều kiện 2 trong scopeStale()

// Deadline — mọi cận trên due_date là chuỗi ngày-giờ đủ giờ (Định nghĩa các con số)
public const UPCOMING_WINDOW_DAYS = 7;
public function scopeUpcoming(Builder $query, int $days): Builder;   // SỬA: due_date ≤ (today + days) 23:59:59 (hôm nay: ≤ ngày trần, rơi ngày +days trên SQLite)
public function scopeOverdue(Builder $query): Builder;              // is_completed = false, due_date < today 00:00:00
public function scopeDueWithin(Builder $query, int $days): Builder; // is_completed = false, today 00:00:00 ≤ due_date ≤ (today + days) 23:59:59
/** @param array{0: string, 1: string} $bounds */
public function scopeDueBetween(Builder $query, array $bounds): Builder;      // whereBetween(due_date, $bounds), chưa xoá mềm
public function scopeRemovedBetween(Builder $query, array $bounds): Builder;  // onlyTrashed(), deleted_at trong $bounds
public function scopeHeldBy(Builder $query, User $subject): Builder;          // responsible_user_id = X (danh sách của Task 5)
public function outcomeAt(CarbonInterface $cutoff): ?DeadlineOutcome;          // bảng ca biên của P1; cần matter đã nạp

// MatterChecklistItem
public function scopeAwaitingReview(Builder $query): Builder;  // status = pending_review

// ClientRequest — mọi cột viết đủ tên bảng (client_requests.created_at, client_requests.status …) vì withHolder() nối matters và users
public function scopeAwaitingOffice(Builder $query): Builder;  // client_requests.status ∈ {new, in_progress}
public function scopeWithHolder(Builder $query): Builder;      // join matters; left join users as live_assignee on id = assigned_to and deleted_at is null;
                                                               // select client_requests.*, COALESCE(live_assignee.id, matters.lead_lawyer_id) as holder_id
public function scopeHeldBy(Builder $query, User $subject): Builder; // withHolder() + điều kiện trên biểu thức holder_id (danh sách của Task 5)
public function holderId(): ?int;                              // bản trong bộ nhớ: $this->assignee?->getKey() ?? $this->matter?->lead_lawyer_id
                                                               // (quan hệ assignee bỏ người đã xoá mềm — đúng như notifyHolderOfFollowUp())
public function scopeCreatedBetween(Builder $query, array $bounds): Builder;  // client_requests.created_at trong $bounds
public function isClosedWithoutAnswer(): bool;                  // status = closed và answered_at null (P10)
public function answeredBy(CarbonInterface $cutoff): bool;      // answered_at !== null và answered_at ≤ $cutoff (R19)

// StageLog
public function scopeOccurredBetween(Builder $query, array $bounds): Builder; // occurred_at trong $bounds

// ChecklistProgress
/** @return array<int, array{settled: int, total: int}> theo lead_lawyer_id; cùng luật X ⊆ Y của handle() */
public static function totalsByLead(Builder $matters): array;

// CollectedRevenue
/** Khoản thu chưa huỷ, paid_on trong $bounds, vụ trong listableBy($viewer), bỏ ClientPortalScope — đúng truy vấn RevenueOverTimeWidget đang chạy */
public static function query(User $viewer, array $bounds): Builder;

// ActivityOwningMatter
/** Chỉ dòng thuộc một vụ trong listableBy($viewer) (bước 1–3), KHÔNG có bước 4 của scopeVisibleTo(), KHÔNG có lối tắt admin.
 *  includeMoney = isAdmin($viewer) || seesMoney($viewer), như scopeOwnedBy(). Sau khi gộp M10: chồng cùng cổng intake_request. */
public static function scopeOwnedByVisibleMatters(Builder $query, User $viewer): void;
/** event = $event, created_at trong $bounds (đủ giờ) */
public static function scopeEventsWithin(Builder $query, string $event, array $bounds): void;
```

**Bước:**
- [x] Viết test đồng nhất đỏ cho từng scope, rồi mới viết scope.
- [x] Viết docblock cho mọi scope mới. Mỗi docblock nêu luật gốc nó nói lại, và vì sao không phải định nghĩa thứ hai. Theo khuôn `MatterStaleness::scopeStale()` / `color()`: hai hình dạng, một luật, một hằng số.
- [x] **Sửa `Deadline::scopeUpcoming()`** sang cận trên `today()->addDays($days)->endOfDay()->toDateTimeString()`. Hôm nay nó so `due_date ≤ 'Y-m-d'` trần: trên SQLite cột lưu `Y-m-d 00:00:00`, lớn hơn chuỗi ngày trần, nên mốc ngày +7 rơi khỏi widget trang chủ, trong khi trên MariaDB nó có mặt, và `CheckDeadlines::tierFor()` trả `d7` cho đúng ngày đó. Trước khi sửa, grep các test của `UpcomingDeadlinesWidget` đang khẳng định ngày +7; nếu có test đang khẳng định hành vi sai trên SQLite thì sửa **có chủ đích** và nói trong báo cáo. Tệp này M11 cũng sửa (`casts()`); người gộp giữ cả hai.
- [x] `outcomeAt()` cài đúng bảng ca biên của P1 (Định nghĩa các con số), theo thứ tự trong bảng. `closedOnOrBefore()` dùng `≤ 23:59:59` của ngày đến hạn, không `<` ngày trần: vụ kết thúc **đúng** ngày đến hạn làm mốc hết hiệu lực.
- [x] **`ClientRequest::holderId()` giữ ngữ nghĩa của đường thông báo, không thay nó.** `ReplyToClientRequest::notifyHolderOfFollowUp()` viết `$thread->assignee ?? $matter->leadLawyer` (không có biến `$preferred` nào; chữ đó chỉ có trong docblock). Quan hệ `assignee` bỏ người đã xoá mềm, nên người được giao đã xoá mềm nhường cho luật sư phụ trách. `scopeWithHolder()` và `holderId()` làm **đúng** như vậy (nối `users` với `deleted_at is null`). `ReplyToClientRequest` **không** bị sửa: đổi đường thông báo sang `holderId()` không đem lại gì, mà còn phải nạp lại `User` từ một id. Test đồng nhất (dưới) ghim hai bên vào nhau.
- [x] `totalsByLead()` dùng chính `SETTLED_STATUSES` và `DocumentGroup::ClientProvided` của lớp. Bỏ `ClientPortalScope` của `Document` như `countClientSubmittedDocuments()`, giữ `SoftDeletingScope`, và loại đầu mục đã xoá mềm như quan hệ `checklistItems()`.
- [x] `CollectedRevenue` là phép **tách nguyên văn**: cùng `withoutGlobalScope(ClientPortalScope::class)`, `whereNull('voided_at')`, `whereBetween('paid_on', $filters->bounds())`, `whereHas('instalment.contract.matter', listableBy)`.
  - Bộ lọc lĩnh vực và luật sư của widget vẫn nằm ở widget, gắn thêm vào truy vấn trả về.
  - Ghi trong PROGRESS: tệp này M9-final vừa sửa 1 dòng (`bounds()`), nên lúc gộp phải đọc lại.
- [x] `scopeOwnedByVisibleMatters()`: gọi `whereOwnedByAny()` (đang `private`, giữ `private`) với `listableBy($viewer)` và `includeMoney` như `scopeOwnedBy()`. Nếu lúc cắt nhánh M10 đã gộp (grep `INTAKE_REQUEST`), tách lớp chồng "dòng `intake_request` chỉ khi xem được bản ghi" của `scopeVisibleTo()` thành một hàm `private` dùng chung và gọi ở cả hai scope. Nếu chưa, ghi vào PROGRESS cho người gộp M10, và thêm test đỏ-chờ (`->todo()`) nêu đúng ca: dòng `intake_request` có `properties.matter_id` của một vụ trưởng phòng xem được, nhưng bản ghi tiếp nhận trưởng phòng không xem được.
- [x] **`NoSecondDefinitionTest`** quét token (bỏ chú thích) của **mọi tệp bị quét** ở "Ràng buộc toàn cục" (gồm `CapturePerformanceSnapshots.php`, ba trang, thư mục widget hiệu suất; tệp chưa tồn tại thì bỏ qua, test tự canh khi tệp xuất hiện) theo đúng danh sách cột và danh sách ngoại lệ theo tệp ở đó.
  - Mẫu "điều kiện": `(where|orWhere|having|orHaving)\w*\(\s*['"](\w+\.)?<cột>['"]`, `whereIn`/`whereNotIn`/`whereBetween` cùng dạng, và so sánh trong bộ nhớ `->\s*<cột>\s*(===|!==|==|!=|<=|>=|<|>)` hoặc ngược lại.
  - `select`/`groupBy`/`orderBy`/`pluck` trên cột quy người **không** bị bắt (R5, Ràng buộc toàn cục).
  - Cặp dương trên fixture cho từng nhóm cột, cặp âm cho `groupBy('lead_lawyer_id')` và `TextColumn::make('due_date')`.
  - Thêm `created_via`, `confirmed_at` vào danh sách cấm (R20).
- [x] Ghi hằng số `ReviewChecklistItem::AUDIT_EVENT` và dùng nó ở chính câu `Audit::record()` của lớp đó, để P6 không đọc một chuỗi có thể trôi.

**Test bắt buộc:**
- [x] `scopeOverdue()` và `CheckDeadlines::tierFor() === OVERDUE_KEY` đồng ý trên một dải mốc từ −3 tới +8 ngày, kể cả ngày hôm nay. `scopeDueWithin(7)` chứa đúng các mốc mà `tierFor()` trả một bậc `d1`/`d3`/`d7` (với mốc thường) cộng mốc hôm nay.
- [x] `upcoming(7)` = `overdue()` ∪ `dueWithin(7)`, hai tập rời nhau, **và** mốc ngày +7 có trong cả `upcoming(7)` lẫn `dueWithin(7)`, mốc ngày +8 không. Chạy trên SQLite **và** `test:mariadb` (`DeadlineScopeBoundaryTest`). Mutation probe: đổi cận trên về ngày trần thì test SQLite đỏ.
- [x] `UpcomingDeadlinesWidget`: mốc ngày +7 có trong `rowsFor()` trên SQLite (bản sửa lỗi).
- [x] `StaleMattersWidget` và `scopeStale()`: không đổi. `scopeNotMeasurable()` và `scopeStale()` không giao nhau. Hợp của chúng cùng phần "đã bật cổng, chưa quá hạn" bằng `open()`.
- [x] `PendingChecklistReviewsWidget`: mọi test cũ xanh sau khi chuyển. Mutation probe trên `scopeAwaitingReview()` làm cả widget lẫn test mới đỏ.
- [x] `totalsByLead()` bằng tổng `handle()` từng vụ trên dữ liệu có:
  - đầu mục không bắt buộc có tài liệu nhóm A;
  - đầu mục không bắt buộc chỉ có tài liệu nhóm B (không vào `Y`);
  - tài liệu nhóm A đã xoá mềm;
  - đầu mục đã xoá mềm;
  - `not_applicable`.

  Chạy dưới `test:mariadb`.
- [x] `CollectedRevenue`: `RevenueDashboardTest` xanh nguyên. Tổng của `query()` bằng tổng các cột của widget cho cùng kỳ. Khoản thu vào đúng ngày cuối kỳ được tính, trên cả hai CSDL.
- [x] `closedWithin()`: vụ đóng ngày cuối kỳ có mặt; vụ đã huỷ (xoá mềm) không. `matterClosedAtConditionIn()` bắt được `whereBetween('closed_at'` và `->closed_at <` trong một chuỗi fixture, và không bắt `TextEntry::make('closed_at')`.
- [x] `closedOnOrBefore()`: vụ kết thúc 10:00 đúng ngày đến hạn → đúng; 00:00:01 hôm sau → sai.
- [x] `DeadlineOutcomeTest`: **mỗi ca** của bảng ca biên P1 một `it()`, kể cả ca 1 (mốc ghi sau ngày đến hạn), ca 2 (`completed_at` rỗng), ca 6 (vụ kết thúc đúng ngày đến hạn), ca 8 (mở lại sau ngày đến hạn). Mutation probe cho từng điều kiện.
- [x] `holderId()`, `scopeWithHolder()` và người mà `notifyHolderOfFollowUp()` báo (bắt `Notification::fake()`) đồng ý trên bốn luồng: chưa giao; giao cho trợ lý; giao cho người **đã xoá mềm** (cả ba nói luật sư phụ trách); luật sư phụ trách đã đổi. Mutation probe: bỏ điều kiện `deleted_at is null` của phép nối thì ca xoá mềm đỏ.
- [x] `scopeWithHolder()` kết hợp với `scopeCreatedBetween()` và `scopeAwaitingOffice()` chạy được trên MariaDB strict (không lỗi cột mơ hồ `created_at`/`status`).
- [x] `isClosedWithoutAnswer()`: Mới → Đã đóng qua `TriageClientRequest::setStatus()` → đúng; Đã trả lời rồi đóng → sai.
- [x] `scopeOwnedByVisibleMatters()`:
  - không thả dòng đăng nhập hay dòng của vụ `restricted` cho trưởng phòng; cùng dòng đó thả cho luật sư phụ trách và admin;
  - admin cũng **không** nhận dòng đăng nhập;
  - dòng khoản thu chỉ thả cho người có `billing.view` (luật sư phụ trách có, trợ lý không).
- [x] `scopeEventsWithin()`: dòng lúc 23:59:59 ngày cuối kỳ có mặt, 00:00:00 hôm sau không.
- [x] `NoSecondDefinitionTest` xanh, với cặp dương và âm trên fixture như ở bước.

**Commit:** `refactor: M13 Task 2 — luật đặt đúng chỗ: Deadline::overdue/dueWithin/dueBetween/removedBetween/outcomeAt và sửa upcoming() rơi ngày +7 trên SQLite, MatterChecklistItem::awaitingReview (chuyển từ widget), ClientRequest::awaitingOffice/withHolder/createdBetween (người giữ như đường thông báo), ChecklistProgress::totalsByLead, CollectedRevenue tách từ RevenueOverTimeWidget, Matter::closedWithin/closedOnOrBefore/withSupportingMember, ActivityOwningMatter::ownedByVisibleMatters/eventsWithin; test đồng nhất và test cấm định nghĩa thứ hai trên mọi tệp M13`

### - [ ] Task 3 — Lịch sử người giữ việc: mốc (R9), yêu cầu của khách và người phụ trách vụ (R18)

**Tệp:**
- sửa: `app/Actions/Matter/ReassignMatter.php`:
  - bước 3: mỗi mốc một dòng `deadline_responsible_changed`; hằng số `DEADLINE_HANDOVER_REASON`;
  - bước 4: mỗi luồng một dòng `client_request_assigned`; hằng số `REQUEST_HANDOVER_REASON`;
- sửa: `app/Actions/Deadline/UpdateDeadline.php` (dòng thêm khi người phụ trách đổi; hằng số `HANDOVER_REASON = 'deadline_updated'`);
- mới: `app/Support/Performance/DeadlineHolderAtDue.php`, `LeadAt.php`, `RequestHolderAt.php`;
- `lang/vi/activity.php`: nhóm khoá mới `reasons` (dưới);
- sửa: `app/Filament/Admin/Pages/ActivityLogPage.php`: modal "Xem chi tiết" in nhãn của `reason`;
- mới: `tests/Feature/Performance/DeadlineHolderAtDueTest.php`, `RequestHolderAtTest.php`, `HolderHistoryCompletenessTest.php` (test cấu trúc R9 cho cả `responsible_user_id` và `assigned_to`), `ActivityReasonLabelsTest.php`.

**Giao diện:**
```php
final class DeadlineHolderAtDue {
    /** @param Collection<int, Deadline> $deadlines  @return array<int, ?int> deadline_id => user_id; null = không quy được (R9) */
    public static function resolve(Collection $deadlines): array;
}

final class LeadAt {
    /** @param Collection<int, Matter> $matters  @param Closure(Matter): CarbonInterface $at
     *  @return array<int, ?int> matter_id => user_id; một truy vấn matter_reassigned cho cả lô */
    public static function resolve(Collection $matters, Closure $at): array;
}

final class RequestHolderAt {
    /** @param Collection<int, ClientRequest> $requests (matter đã nạp)  @param Closure(ClientRequest): CarbonInterface $at
     *  @return array<int, ?int> request_id => user_id; người được giao tại $at, rỗng thì LeadAt tại $at (R18) */
    public static function resolve(Collection $requests, Closure $at): array;
}
```

```php
// lang/vi/activity.php — hình dạng khoá
'reasons' => [
    'deadline_responsible_changed' => [
        'reopened_holder_no_longer_qualifies' => '…', // SetDeadlineCompletion::REOPEN_HANDOVER_REASON (hôm nay chưa có nhãn)
        'matter_reassigned' => '…',                   // ReassignMatter::DEADLINE_HANDOVER_REASON
        'deadline_updated' => '…',                    // UpdateDeadline::HANDOVER_REASON
    ],
    'client_request_assigned' => [
        'matter_reassigned' => '…',                   // ReassignMatter::REQUEST_HANDOVER_REASON
    ],
],
```

**Bước:**
- [ ] Trước khi sửa, grep các test đang đếm dòng nhật ký của `ReassignMatter`, `ReassignMatters`, `BulkReassign`, `SendReassignmentDigest` và `UpdateDeadline` (`toHaveCount`, `count()` trên `Activity`, khẳng định trên `client_request_assigned`). Dòng mới sẽ làm những test đó đổi số. Sửa số ở đó **có chủ đích**, và nói rõ trong báo cáo.
- [ ] `ReassignMatter` bước 3: sau câu `update()` hàng loạt, ghi một `Audit::record('deadline_responsible_changed', $deadline, ['matter_id', 'client_id', 'from', 'to', 'reason' => self::DEADLINE_HANDOVER_REASON], causer: $actor)` cho mỗi mốc đã chuyển, trong cùng transaction.
  - Nạp các mốc bằng **một** truy vấn theo `$movedDeadlineIds`, không một truy vấn mỗi mốc.
- [ ] `ReassignMatter` bước 4: tương tự, `Audit::record('client_request_assigned', $thread, ['matter_id', 'client_id', 'from' => $oldLead->id, 'to' => $lockedNewLead->id, 'reason' => self::REQUEST_HANDOVER_REASON], causer: $actor)` cho mỗi luồng trong `$movedRequestIds`, một truy vấn nạp. Cùng tên sự kiện với `TriageClientRequest::assign()` và lần gỡ khi mở lại của `setStatus()`, để "ai từng giữ luồng này" đọc ở **một** khoá.
  - Dòng `matter_reassigned` giữ nguyên ở cả hai bước.
- [ ] `UpdateDeadline`: khi `responsible_user_id` thật sự đổi, ghi thêm dòng `deadline_responsible_changed` (`reason = deadline_updated`), sau dòng `deadline_updated`.
- [ ] `DeadlineHolderAtDue` theo R9, một truy vấn cho cả lô. Ghi giới hạn "trước ngày triển khai" và ca `from` rỗng vào docblock.
- [ ] `LeadAt` và `RequestHolderAt` theo R18. `RequestHolderAt` gọi `LeadAt` cho các luồng có người được giao rỗng tại thời điểm hỏi, **cùng** thời điểm. Tổng: hai truy vấn cho cả lô. So `created_at > $at` chặt: dòng ghi đúng giây `$at` coi như đã có hiệu lực. Docblock nêu giới hạn "luồng giao đích danh bị bàn giao trước ngày triển khai".
- [ ] Nhãn lý do theo hình dạng trên. Modal "Xem chi tiết" của `ActivityLogPage` in `__('activity.reasons.'.$event.'.'.$reason)` thay mã khi `Lang::has()`, mã thô khi không.

**Test bắt buộc:**
- [ ] **Mốc:**
  - lỡ rồi mới bàn giao, qua từng đường trong năm đường (`ReassignMatter`, `ReassignMatters` hàng loạt, `ChangeDeadlineResponsible`, `UpdateDeadline`, lần mở lại có chuyển người của `SetDeadlineCompletion`): người giữ vào ngày đến hạn là người **trước**;
  - bàn giao **trước** ngày đến hạn: người **sau**;
  - hai lần đổi sau ngày đến hạn: lấy `from` của lần **sớm nhất**;
  - đổi đúng lúc 23:59:59 của ngày đến hạn tính là "trước"; 00:00:01 hôm sau tính là "sau";
  - không có dòng lịch sử nào (dữ liệu cũ): người giữ hiện tại;
  - dòng lịch sử có `from` rỗng: `null`, không đoán.
- [ ] **Yêu cầu của khách** (Review Focus 3):
  - luồng chưa giao ai, trả lời khi A phụ trách, rồi `ReassignMatter` A → B: tại `answered_at` là A;
  - cùng ca qua `ReassignMatters` hàng loạt: A;
  - luồng chưa giao, chưa trả lời, hỏi tại một thời điểm trước lần bàn giao: A; sau: B;
  - luồng giao đích danh cho A, `ReassignMatter` bước 4 chuyển sang B (dòng mới): trước lần chuyển là A, sau là B. Mutation probe: bỏ dòng ghi ở bước 4 thì ca "trước" đỏ;
  - luồng giao cho trợ lý C: lần bàn giao vụ không đổi người giữ;
  - `TriageClientRequest::assign()` từ C sang D rồi gỡ (`to = null`): đúng người ở từng khoảng, khoảng cuối là luật sư phụ trách tại thời điểm đó;
  - luồng mở lại sau khi đóng làm `setStatus()` gỡ người giữ (dòng `client_request_assigned` với `to = null`): đọc đúng;
  - người được giao đã xoá mềm: `RequestHolderAt` vẫn trả người đó (lịch sử), trong khi `holderId()` trả luật sư phụ trách (R18, ghi trong docblock);
  - đồng nhất: `RequestHolderAt` tại `now()` bằng `holderId()` khi người được giao chưa xoá mềm; `LeadAt` tại `now()` bằng `lead_lawyer_id`.
- [ ] **Người phụ trách vụ:** `LeadAt` tại `closed_at` của một vụ kết thúc rồi mới bàn giao (bàn giao từ trang vụ, `MatterPolicy::manageTeam()` cho phép) là người phụ trách cũ.
- [ ] Test cấu trúc R9 (`HolderHistoryCompletenessTest`): ba mẫu token "ghi" cho `responsible_user_id` và `assigned_to` (R9); một đường ghi trong fixture mà thiếu khoá sự kiện thì test đỏ; các dạng âm (khoá `ValidationException`, câu `where`/`select`, đọc không gán) không bị bắt.
- [ ] Mutation probe: bỏ dòng ghi trong `ReassignMatter` bước 3 thì test bàn giao mốc đỏ.
- [ ] Số truy vấn của `DeadlineHolderAtDue::resolve()`, `LeadAt::resolve()` và `RequestHolderAt::resolve()` không đổi khi số phần tử tăng từ 3 lên 30.
- [ ] `ActivityReasonLabelsTest`: mọi hằng số `*_REASON` dưới `app/Actions` (quét token `const \w+_REASON = '…'`, kèm tên sự kiện của dòng mà Action đó ghi) có khoá `activity.reasons.<sự kiện>.<lý do>`; modal "Xem chi tiết" in nhãn, không in mã, cho một dòng `reopened_holder_no_longer_qualifies`.

**Commit:** `feat: M13 Task 3 — lịch sử người giữ việc ở một khoá sự kiện: ReassignMatter ghi deadline_responsible_changed cho từng mốc và client_request_assigned cho từng luồng, UpdateDeadline ghi lần đổi người, DeadlineHolderAtDue, LeadAt và RequestHolderAt dựng người giữ tại một thời điểm, nhãn lý do trong nhật ký`

### - [x] Task 4 — Trang "Theo dõi đội ngũ" (N1–N11)

**Tệp:**
- mới: `app/Actions/Performance/BuildTeamWorkload.php`, `app/Support/Performance/TeamWorkloadRow.php`;
- sửa: `app/Filament/Admin/Pages/TeamOverview.php` và view;
- `lang/vi/performance.php` (`columns.*`, `explain.*`, `not_applicable`);
- mới: `tests/Feature/Performance/TeamWorkloadTest.php`, `TeamOverviewPageTest.php`, `RestrictedLeakSweepTest.php` (dùng chung cho Task 5–7), `tests/Benchmark/TeamPerformanceBenchmarkTest.php`.

**Giao diện:**
```php
final class BuildTeamWorkload {
    /** @param Collection<int, User> $subjects  @return array<int, TeamWorkloadRow> theo user_id
     *  @throws AuthorizationException khi một $subject không qua viewPerformance — phòng thủ, trang đã lọc */
    public function handle(User $viewer, Collection $subjects): array;
}

// Trường (L) của R6 là ?int: null = "Không áp dụng" (TeamRoster::leadsMatters() sai). Không bao giờ null vì "không có vụ".
final readonly class TeamWorkloadRow {
    public function __construct(
        public int $userId, public string $name, public bool $isActive, public bool $leadsMatters,
        public ?int $leadOpen, public int $teamOpen, public ?int $leadClosed,
        public ?int $stale, public ?int $notMeasurable,
        public int $overdueDeadlines, public int $deadlinesDueSoon,
        public ?int $awaitingClientMatters, public ?int $awaitingClientStuck,
        public ?int $awaitingReviewItems, public int $awaitingOfficeRequests,
        public ?int $checklistSettled, public ?int $checklistTotal,
        public ?CarbonImmutable $lastMatterActivityAt,
    ) {}
}
```

**Bước:**
- [x] `handle()` chạy **một truy vấn gộp cho mỗi chỉ số** (R11), gốc là `listableBy($viewer)` (R4), `GROUP BY` cột quy người, **không** lọc tập người trong SQL; giữ dòng của `$subjects` bằng PHP. Chỉ gọi các scope của Task 2, không viết điều kiện nào (`NoSecondDefinitionTest` quét tệp này).
- [x] Hỏi `viewPerformance` cho từng `$subject` (phòng thủ); `$subjects` đến từ `TeamRoster`, đã nạp vai trò, nên không thêm truy vấn.
- [x] Trường (L) là `null` khi `TeamRoster::leadsMatters($subject)` sai; trang in `__('performance.not_applicable')` ("Không áp dụng").
- [x] Trang dùng `Table::records()` với các cột N1–N11:
  - cột đếm việc đang tồn sắp xếp được, cột tỉ lệ thì không (R8); "Không áp dụng" xếp như giá trị rỗng, sau mọi số;
  - tô màu theo R8 (tiền lệ `StaleMattersWidget`, style nội tuyến `var(--danger-600)`);
  - tên người dẫn tới `TeamMember` (kể cả người đã nghỉ việc, R3);
  - công tắc "Gồm người đã nghỉ việc";
  - khối thu gọn "Cách tính các con số" (R6);
  - câu R4.
- [x] `mount()` ghi `performance_viewed` (R14, `page = team_overview`); công tắc và sắp xếp không ghi thêm.
- [x] N11: đo ngay ở task này. Nếu truy vấn gộp vượt 150 ms trên dữ liệu benchmark, cột N11 rời trang tổng quan, chỉ còn trên trang một người (tính cho một người), và ghi phán quyết vào PROGRESS.
- [x] Dựng benchmark theo R11, **trừ phần ảnh chụp** (Task 7 thêm), chèn theo lô bằng `DB::table()->insert()` như `SearchMattersBenchmarkTest`. In số đo và `EXPLAIN` ra STDERR.

**Test bắt buộc:**
- [x] Mỗi cột một fixture có ca biên:
  - N1 bằng `LoadPerLawyerWidget::numberTableRows()` (không bộ lọc) cho từng luật sư, với trưởng phòng và với luật sư (Review Focus 2);
  - N2 không đếm `observer` và không đếm `lead`;
  - N3 không đếm vụ đã huỷ;
  - N5, N6 ở ngày hôm nay và ngày +7 (đồng ý với `CheckDeadlines::tierFor()`); N6 không đếm mốc quá hạn;
  - N7: số trong ngoặc bằng `MattersMissingDocumentsWidget::rowsFor()` lọc theo người; số chính bằng `ChecklistProgress::mattersAwaitingClient()` không tham số;
  - N9 đếm cho luật sư phụ trách khi `assigned_to` rỗng, cho người được giao khi có, và cho luật sư phụ trách khi người được giao đã xoá mềm;
  - N10 khớp `X/Y` trên tab Danh mục của từng vụ;
  - N11 không tính dòng đăng nhập, kể cả với admin; dòng khoản thu chỉ khi người xem có `billing.view`.
- [x] **Trợ lý:** mọi cột (L) là "Không áp dụng" trên trang, các cột còn lại là số (kể cả 0). Luật sư không có vụ nào: các cột (L) là 0, không phải "Không áp dụng". Mutation probe: đổi `leadsMatters()` sang "có vụ đang phụ trách" thì ca luật sư chỉ có vụ `restricted` (trưởng phòng xem) đỏ.
- [x] **Đồng nhất với trang chủ** (Review Focus 2), cho trưởng phòng và cho luật sư.
- [x] **Quét rò rỉ** (Review Focus 1) trên mọi thuộc tính của `TeamWorkloadRow`: trưởng phòng, luật sư phụ trách, admin.
- [x] Số truy vấn bằng nhau với 3 và 12 người (R11).
- [x] Livewire:
  - sắp xếp theo N5 được;
  - gọi `sortTable` trên cột `X/Y` không đổi thứ tự;
  - công tắc người nghỉ việc;
  - có câu R4 và đủ các câu giải thích (khoá dịch tồn tại, không in ra tên khoá);
  - đúng một dòng `performance_viewed` mỗi lần `mount()`, không thêm dòng khi bật công tắc.
- [x] Người được theo dõi đã nghỉ việc vẫn hiện tên khi bật công tắc, và liên kết tên mở được trang của họ (200, không 404).

**Commit:** `feat: M13 Task 4 — trang "Theo dõi đội ngũ": BuildTeamWorkload, mỗi chỉ số một truy vấn gộp trên listableBy, đồng nhất với widget trang chủ và LoadPerLawyerWidget, "Không áp dụng" theo quyền cho cột của người phụ trách, quét rò rỉ restricted, benchmark`

### - [ ] Task 5 — Trang của một người (đi sâu)

**Tệp:**
- sửa: `app/Filament/Admin/Pages/TeamMember.php` và view;
- mới: `tests/Feature/Performance/TeamMemberPageTest.php`.

**Bước:**
- [ ] Đầu trang gồm:
  - tên, chức danh (`UserPosition::label()`), trạng thái (kể cả "đã nghỉ việc", R3);
  - **đúng** `TeamWorkloadRow` của người đó, gọi `BuildTeamWorkload` với một người; cột (L) in "Không áp dụng" như Task 4;
  - bảng cơ cấu lĩnh vực (N1 theo `matter_type_id`; với trợ lý, bảng cơ cấu tính trên vụ đang tham gia N2, ghi rõ trên tiêu đề bảng).
- [ ] Bảng "Vụ việc" là bảng Filament trên Eloquent:
  - truy vấn: `Matter::query()->listableBy($viewer)->workedOnBy($subject)` (Task 2). Trang không tự viết điều kiện trên `lead_lawyer_id` hay `role_in_matter` (`NoSecondDefinitionTest` quét tệp này);
  - cột: mã, khách, tiêu đề, giai đoạn, vai của X, "cập nhật gần nhất cho khách" tô màu bằng `MatterStaleness::color()`;
  - lọc: đang mở/đã kết thúc (`open()`/`closed()`), phụ trách/tham gia (`ledBy()`/`withSupportingMember()`);
  - mỗi dòng mở `MatterResource` `view`. Trang đó tự kiểm quyền.
- [ ] Ba danh sách ngắn, mỗi danh sách dựng trên **truy vấn đang có** rồi thêm **scope người** của Task 2:
  - mốc quá hạn và 7 ngày tới: `UpcomingDeadlinesWidget::rowsFor($viewer)->heldBy($subject)`. Trang là lớp Filament nên được gọi widget;
  - yêu cầu chờ trả lời: `ClientRequest::awaitingOffice()->heldBy($subject)` trên vụ `open()` và `listableBy`;
  - giấy tờ chờ duyệt: `PendingChecklistReviewsWidget::rowsFor($viewer)->whereHas('matter', fn ($m) => $m->ledBy($subject))`.
- [ ] Chỗ dành cho biểu đồ xu hướng; Task 7 lấp vào bằng hai widget **tự kiểm quyền** (Ràng buộc toàn cục). Trang truyền `subjectId` cho widget qua `getWidgetData()`; widget không tin giá trị đó.
- [ ] `#[Locked] public int $subjectId`. `mount()` và `boot()` hỏi `viewPerformance` (Task 1).
- [ ] `Audit::record('performance_viewed', $subject, [], causer: $viewer)` ở `mount()` khi người xem không phải chính người đó (R14).

**Test bắt buộc:**
- [ ] Bảng vụ chứa đúng tập `listableBy(V) ∩ việc của X`:
  - vụ `restricted` X phụ trách: X và admin thấy, trưởng phòng không;
  - vụ X chỉ là `observer`: không có.
- [ ] Ba danh sách: mỗi dòng có trong widget trang chủ tương ứng của cùng người xem (Review Focus 2). Quét rò rỉ trên ba danh sách. Danh sách yêu cầu: luồng giao cho người đã xoá mềm hiện ở trang của luật sư phụ trách (cùng người với N9).
- [ ] Sửa `subjectId` qua Livewire thì bị chặn. Mất quyền giữa chừng thì 404.
- [ ] Trang của một trợ lý: cột (L) in "Không áp dụng", không in 0.
- [ ] `performance_viewed`:
  - đúng một dòng mỗi lần `mount()` khi xem người khác;
  - không có dòng khi xem chính mình;
  - không thêm dòng khi lọc bảng (request Livewire);
  - nhãn tiếng Việt hiện trên trang Nhật ký hệ thống.
- [ ] Luật sư mở trang của chính mình thấy cả vụ `restricted` mình phụ trách, trong bảng và trong số đầu trang.

**Commit:** `feat: M13 Task 5 — trang của một người: số đầu trang dùng lại BuildTeamWorkload, bảng vụ việc trên listableBy và Matter::workedOnBy, ba danh sách dựng từ truy vấn widget trang chủ cộng scope người, audit performance_viewed`

### - [ ] Task 6 — Trang "Hiệu suất theo kỳ" (P1–P7, P9, P10)

**Tệp:**
- mới: `app/Support/Performance/PerformancePeriod.php`, `Ratio.php`, `PerformanceRow.php`, `PerformanceReport.php`;
- sửa: `app/Support/Performance/TeamRoster.php` (`subjectsForPeriod()`, R3);
- mới: `app/Actions/Performance/BuildPerformanceReport.php`;
- sửa: `app/Filament/Admin/Pages/Performance.php` và view;
- mới: `tests/Feature/Performance/PerformancePeriodTest.php`, `PerformanceFormulasTest.php`, `PerformancePageTest.php`, `ClosedPeriodStabilityTest.php`.

**Giao diện:**
```php
final class PerformancePeriod {
    public static function fromFilters(?array $filters): self;   // R16; mặc định last_month
    public static function trailingDays(int $days): self;        // [hôm nay − days, hôm qua]; trang một người (Task 7)
    /** @return array{0: string, 1: string} 00:00:00 … 23:59:59, như RevenueFilters::bounds() */
    public function bounds(): array;
    /** min(23:59:59 ngày cuối kỳ, now()) — R19 */
    public function cutoff(): CarbonImmutable;
    public function isRunning(): bool;
    public function label(): string;
}

// TeamRoster
/** người trackable đang hoạt động, cộng người đã nghỉ việc có lần vô hiệu hoá gần nhất từ ngày đầu kỳ (R3);
 *  $includeInactive: mọi người trackable đã nghỉ. Không có performance.viewAny: chỉ chính $viewer. */
public static function subjectsForPeriod(User $viewer, PerformancePeriod $period, bool $includeInactive = false): Collection;

final readonly class Ratio {
    public const MIN_SAMPLE = 5;
    public function __construct(public int $numerator, public int $denominator) {}
    public function isMeasurable(): bool;          // denominator >= MIN_SAMPLE
    public function label(): string;               // "87,5% (35/40)" hoặc "Chưa đủ dữ liệu (n = 3)"
}

final class BuildPerformanceReport {
    /** @param Collection<int, User> $subjects
     *  @throws AuthorizationException khi một $subject không qua viewPerformance — phòng thủ, như BuildTeamWorkload */
    public function handle(User $viewer, Collection $subjects, PerformancePeriod $period): PerformanceReport;
}
// PerformanceReport: ?PerformanceRow $reference (chỉ khi performance.viewAny), array<int, PerformanceRow> $rows,
//   bool $revenueVisible (cột P7 có trên trang hay không; false thì Action không tính P7)
// PerformanceRow: deadlinesOnTime, deadlinesLate, deadlinesMissed, deadlinesRemoved, onTimeRatio,
//   requestsReceived, requestsAnswered, requestsClosedUnanswered (P10), responseMedianHours, responseMeanHours,
//   stageEntries, mattersMoved, mattersClosed (?int: null = không áp dụng, R6), itemsReviewed,
//   revenueCollected (?int: null = không áp dụng, R6), completionRatio, mainPracticeAreas
```

**Bước:**
- [ ] Chỉ số quy người theo một cột: một truy vấn `GROUP BY` (R11). Chỉ số quy người theo lịch sử: nạp tập của kỳ **không lọc người** rồi dựng lịch sử một lần (R11):
  - P1: `Deadline::dueBetween($period->bounds())` trên vụ trong `listableBy(V)`, nạp kèm `matter` (`closed_at`); `DeadlineHolderAtDue::resolve()`; `outcomeAt($period->cutoff())`; lọc người bằng PHP;
  - P3, P10: `ClientRequest::createdBetween($period->bounds())` trên vụ trong `listableBy(V)`, nạp `created_at`, `answered_at`, `status`, `assigned_to`, `matter_id` và `matter`; `RequestHolderAt::resolve()` với thời điểm `answered_at` (luồng `answeredBy(cutoff)`) hoặc `cutoff` (mọi luồng khác); trung vị và trung bình bằng PHP (R17) chỉ trên luồng đã trả lời;
  - P5: `Matter::closedWithin(...)` trên `listableBy(V)`, `LeadAt::resolve()` tại `closed_at`;
  - P6: `ActivityOwningMatter::scopeEventsWithin(…, ReviewChecklistItem::AUDIT_EVENT, bounds)` cộng `scopeOwnedByVisibleMatters(…, V)`, `GROUP BY causer_id`;
  - "Lĩnh vực chính": hợp các `matter_id` đã quy về người đó ở P1, P3, P10, P4, P5; một truy vấn `matter_type_id`.
- [ ] Hỏi `viewPerformance` cho từng `$subject` (phòng thủ, như `BuildTeamWorkload`). Vai trò đã nạp sẵn qua `TeamRoster`, nên kiểm tra này không thêm truy vấn (R11).
- [ ] Các trường (L) trả `null` khi `TeamRoster::leadsMatters($subject)` sai (R6). P7 chỉ được tính khi `PerformanceReport::$revenueVisible`: người xem có `billing.view` và (có `revenue.viewAny` hoặc tập người chỉ là chính người xem), tức `viewPerformanceRevenue` đúng cho mọi người trong trang.
- [ ] Dòng tham chiếu R8 chỉ khi người xem có `performance.viewAny`, theo đúng các luật của dòng "Chung" ở R8 (không lọc người giữ, P4 luôn hiện, P7 theo `revenue.viewAny`).
- [ ] Trang:
  - form kỳ (R16) và công tắc "Gồm người đã nghỉ việc" (R3);
  - tập người mặc định: `TeamRoster::subjectsForPeriod()`;
  - bảng `records()` **không cột nào sắp xếp được ngoài tên** (R8);
  - phân rã R7 in cạnh tỉ lệ;
  - nhãn "kỳ đang chạy";
  - "Cách tính các con số" (gồm câu `closed_period` của R19) và "Vì sao không có bảng xếp hạng";
  - câu R4.
- [ ] Người xem không có `performance.viewAny` chỉ có dòng của chính mình. Tập người tính lại từ người xem ở mỗi request, không nằm trong trạng thái Livewire.
- [ ] `performance_viewed` theo R14: khi `mount()` và khi đổi kỳ, chỉ với người có `performance.viewAny`.
- [ ] Mở rộng benchmark với một quý.

**Test bắt buộc** (mỗi ca một `it()`):
- [ ] P1, ranh giới thời gian:
  - xong 23:59:59 ngày đến hạn → đúng hạn; 00:00:01 hôm sau → trễ;
  - đến hạn hôm nay, chưa xong → không vào tập; đến hạn hôm nay, đã xong → vào tập, đúng hạn;
  - kỳ đã đóng: xong sau 23:59:59 ngày cuối kỳ → lỡ, không phải trễ (R19).
- [ ] P1, vụ và mốc đặc biệt (qua trang, đối chiếu bảng ca biên đã test ở Task 2):
  - vụ kết thúc **trước** hoặc **đúng** ngày đến hạn, mốc chưa xong → không vào tập;
  - vụ kết thúc **sau** ngày đến hạn, mốc chưa xong → lỡ;
  - mốc ghi vào hệ thống sau ngày đến hạn → không vào tập;
  - mốc mở lại sau ngày đến hạn → lỡ, tính cho người giữ vào ngày đến hạn;
  - mốc đã gỡ → không vào tỉ lệ, có ở P2;
  - vụ đã huỷ → không ở đâu cả.
- [ ] P1 quy người qua bàn giao (Review Focus 3): **luật sư xem dòng của chính mình** sau khi mốc mình lỡ đã được bàn giao cho người khác: mốc vẫn có trong dòng của mình là "lỡ". Mutation probe: nạp mốc bằng `responsible_user_id IN (tập người)` thì ca này đỏ.
- [ ] P3:
  - trung vị với n chẵn và n lẻ;
  - yêu cầu chưa trả lời vào mẫu số của P9, không vào thời gian phản hồi;
  - trả lời qua `TriageClientRequest` (đánh dấu đã trả lời qua điện thoại) có `answered_at`, nên được tính;
  - kỳ đã đóng: trả lời sau mốc cắt → chưa trả lời của kỳ đó;
  - quy về người giữ luồng lúc trả lời; các ca bàn giao của Review Focus 3 qua trang.
- [ ] P10: Mới → Đã đóng qua `TriageClientRequest::setStatus()` → không ở mẫu số P3/P9, có ở P10; luồng đã trả lời rồi đóng → vẫn "đã trả lời" ở P3. Mutation probe: bỏ điều kiện loại ở mẫu số thì test đỏ.
- [ ] P4:
  - dòng "thêm cập nhật" cùng giai đoạn và dòng bàn giao nội bộ của `ReassignMatter` không tính (`entries()`);
  - dòng ghi lùi ngày tính theo `occurred_at`;
  - trợ lý hiện "Không áp dụng".
- [ ] P5: vụ đóng rồi được admin mở lại (đường bỏ qua M6.5 R8) không còn tính; vụ kết thúc rồi mới bàn giao vẫn tính cho người phụ trách lúc kết thúc.
- [ ] P6:
  - một lần duyệt và một lần từ chối trong kỳ → 2, theo người bấm;
  - khách nộp lại sau khi bị từ chối (`markPendingReview()` xoá `reviewed_by`) → lần từ chối trong kỳ vẫn được tính;
  - văn phòng tải giấy tờ thay khách (`settleChecklistItem()` ghi `accepted`) → không tính;
  - lần duyệt trên vụ `restricted` không tính cho trưởng phòng.
- [ ] P7:
  - bằng tổng `RevenueOverTimeWidget` với bộ lọc luật sư cùng kỳ (Review Focus 2);
  - trợ lý: "Không áp dụng" khi người xem thấy cột; người xem là trợ lý: không có cột;
  - luật sư chỉ thấy cột của mình;
  - khoản thu đã huỷ không tính.
- [ ] **"Không áp dụng"**: dòng của trợ lý in "Không áp dụng" ở P4, P5, P7, số ở mọi cột còn lại.
- [ ] **Dòng "Chung"**: gồm việc do admin và người ngoài danh sách giữ; không bằng tổng các dòng; P7 của dòng này bằng tổng `RevenueOverTimeWidget` không lọc luật sư.
- [ ] **"Lĩnh vực chính"**: luật sư có 3 vụ hình sự đã kết thúc trong kỳ và 1 vụ dân sự đang mở không có việc trong kỳ → "Hình sự (3)", không có "Dân sự".
- [ ] P9 và `Ratio`: n = 4 → "Chưa đủ dữ liệu"; n = 5 → có tỉ lệ. Mutation probe trên `MIN_SAMPLE`.
- [ ] **Kỳ đã đóng ổn định** (`ClosedPeriodStabilityTest`, R19): tính "tháng trước"; rồi hôm nay hoàn thành một mốc đã lỡ, trả lời một luồng tồn, bàn giao vụ (`ReassignMatter` và `ReassignMatters`) sang người khác; tính lại: mọi trường của mọi `PerformanceRow` không đổi. Chạy dưới `test:mariadb`.
- [ ] **R20** (khi `created_via` đã có trên `main`): mốc tạo qua AI chưa xác nhận, quá hạn → lỡ ở P1, có ở N5, cùng con số với `CheckDeadlines::tierFor()`.
- [ ] **Tập người theo kỳ** (`subjectsForPeriod()`): luật sư vô hiệu hoá ngày 05/11 có dòng trong "tháng trước" (tháng 10) khi xem ngày 10/11, không cần công tắc; luật sư vô hiệu hoá ngày 20/09 không có, trừ khi bật công tắc; người đã xoá mềm không bao giờ có.
- [ ] `PerformancePeriod`:
  - `last_month` vào ngày 31/10 và ngày 01/11 (`travelTo()`);
  - quý;
  - `custom` dài 367 ngày → lỗi validation tiếng Việt;
  - ngày cuối ở tương lai → lỗi;
  - `bounds()` bằng `RevenueFilters::fromPageFilters()->bounds()` cho `this_month`, `this_quarter` và `custom`; `last_month`, `last_quarter` so qua `custom` cùng hai ngày (R16);
  - `cutoff()`: kỳ đã đóng → 23:59:59 ngày cuối; kỳ đang chạy → `now()`;
  - `trailingDays(90)` vào ngày 2026-10-04 → 2026-07-06 … 2026-10-03.

  Chạy dưới `test:mariadb`.
- [ ] Quét rò rỉ (Review Focus 1) trên mọi thuộc tính của `PerformanceRow` và của dòng tham chiếu.
- [ ] Livewire:
  - luật sư gửi bộ lọc cố chèn id người khác vẫn chỉ có một dòng;
  - kế toán 404;
  - `sortTable` trên cột tỉ lệ không đổi thứ tự.
- [ ] Số truy vấn hằng theo số người (3 và 12), kể cả kiểm quyền từng người.

**Commit:** `feat: M13 Task 6 — trang "Hiệu suất theo kỳ": mốc đúng hạn/trễ/lỡ theo người giữ vào ngày đến hạn và cắt ở cuối kỳ, phản hồi yêu cầu khách theo người giữ lúc trả lời (trung vị, trung bình), yêu cầu đóng không trả lời tách riêng, chuyển giai đoạn qua StageLog::entries, duyệt giấy tờ theo nhật ký, doanh thu qua CollectedRevenue, tỉ lệ hoàn thành việc đến hạn có ngưỡng mẫu, không xếp hạng`

### - [ ] Task 7 — Ảnh chụp hằng ngày và xu hướng (R10, P8)

**Tệp:**
- mới: migration `create_performance_snapshots_table`; `app/Models/PerformanceSnapshot.php`; `app/Policies/PerformanceSnapshotPolicy.php`; alias morph trong `AppServiceProvider`;
- mới: `app/Actions/Schedule/CapturePerformanceSnapshots.php`; đăng ký trong `routes/console.php`;
- mới: `app/Actions/Performance/BuildPerformanceTrend.php`;
- mới: `app/Filament/Admin/Widgets/Performance/StaleTrendWidget.php`, `OverdueTrendWidget.php`, và trait `app/Filament/Admin/Widgets/Performance/Concerns/AuthorizesPerformanceSubject.php` (kiểm quyền dùng chung cho hai widget);
- sửa: trang của một người (biểu đồ) và trang "Hiệu suất theo kỳ" (cột P8: đầu kỳ → cuối kỳ);
- sửa: `tests/Benchmark/TeamPerformanceBenchmarkTest.php` (phần ảnh chụp, R11);
- mới: `tests/Feature/Performance/CapturePerformanceSnapshotsTest.php`, `PerformanceTrendTest.php`, `PerformanceTrendWidgetAccessTest.php`, `PerformanceSnapshotVisibilityTest.php`, `PerformanceSnapshotScheduleTest.php`.

**Giao diện:**
```php
final class CapturePerformanceSnapshots {
    public function __invoke(): void;   // Schedule::call(new CapturePerformanceSnapshots)
    public function handle(): int;      // số dòng đã ghi; prune > PerformanceSnapshot::KEEP_MONTHS
}

final class BuildPerformanceTrend {
    /** Khoảng cố định của trang một người: 90 ngày, kết thúc hôm qua (R10: hôm nay luôn tính trực tiếp, không từ ảnh chụp). */
    public const MEMBER_PAGE_DAYS = 90;
    /** @return array{dates: list<string>, stale: list<int|null>, overdue: list<int|null>, checklist: list<?Ratio>} null = ngày không có ảnh chụp */
    public function handle(User $viewer, User $subject, PerformancePeriod $period): array;
}

// PerformanceSnapshot — MỘT hàm quyết định, hai hình dạng
/** @return list<Confidentiality> loại dòng $viewer được thấy về $subject (R4); [] = không gì. Không truy vấn (vai trò đã nạp sẵn, vụ giả). */
public static function visibleLevels(User $viewer, User $subject): array;
public function scopeVisibleTo(Builder $query, User $viewer, User $subject): Builder;          // whereIn(confidentiality, visibleLevels()), [] thì 1 = 0
public function scopeVisibleToMany(Builder $query, User $viewer, Collection $subjects): Builder; // OR của (user_id, visibleLevels()) cho cả trang, một truy vấn

// BuildPerformanceTrend (cột P8 của trang hiệu suất)
/** @return array<int, array{staleStart: ?int, staleEnd: ?int, overdueStart: ?int, overdueEnd: ?int}> theo user_id; một truy vấn cho cả trang (R11) */
public function endpoints(User $viewer, Collection $subjects, PerformancePeriod $period): array;

// Hai widget
#[Locked] public int $subjectId;
public function mount(int $subjectId): void;  // gán, rồi authorizeSubject(): abort_unless(Gate::forUser($viewer)->allows('viewPerformance', $subject), 404)
public function boot(): void;                 // authorizeSubject() lại ở MỌI request Livewire của widget
protected ?string $pollingInterval = null;    // không kế thừa '5s' của CanPoll
```

**Bước:**
- [ ] Migration theo "Mô hình dữ liệu"; chạy vòng MariaDB thật.
- [ ] Tác vụ chụp:
  - gọi **đúng** các scope của Task 2 trên `Matter::query()`, **không** qua `listableBy`. Tác vụ chạy không người đăng nhập và chụp toàn bộ; tách theo `confidentiality` **qua `Matter::scopeOfConfidentiality()`**, không tự viết điều kiện (`NoSecondDefinitionTest` quét tệp này);
  - chỉ chụp người của `TeamRoster::members()` (trackable, đang hoạt động); tác vụ không tự viết điều kiện trên `is_active`;
  - `upsert` theo khoá unique;
  - lịch: `->dailyAt('23:50')->name('performance.snapshot')->withoutOverlapping(30)`, kèm docblock nói lý do 23:50 và lý do khoá 30 phút, theo khuôn các mục khác của `routes/console.php`.
- [ ] `PerformanceSnapshot::visibleLevels()` theo R4: `normal` chỉ khi người xem có `matter.viewAny` hoặc là chính người đó (có `matter.view`); `restricted` khi `(new Matter)->forceFill(['confidentiality' => Restricted, 'lead_lawyer_id' => $subject->id])->isListableBy($viewer)`. `scopeVisibleTo()` và `scopeVisibleToMany()` chỉ dịch kết quả đó sang SQL; không vế nào: `1 = 0`.
- [ ] Cột P8 của trang hiệu suất: `BuildPerformanceTrend::endpoints()` đọc `scopeVisibleToMany()` một lần cho cả trang; `PerformanceRow` nhận bốn trường `?int` (null = không có ảnh chụp ngày đó).
- [ ] `BuildPerformanceTrend`:
  - đọc `PerformanceSnapshot::visibleTo($viewer, $subject)` (R4);
  - cộng dòng `normal` với dòng `restricted` khi người xem được thấy dòng `restricted`;
  - ngày thiếu dòng `normal` trả `null`;
  - trang một người gọi với `PerformancePeriod::trailingDays(self::MEMBER_PAGE_DAYS)`; trang hiệu suất gọi với kỳ đang chọn, cắt ở hôm qua.
- [ ] **Hai widget tự kiểm quyền** (Ràng buộc toàn cục; Review Focus 4). Một widget là một component Livewire riêng: request của nó (đổi trang của bảng số, `$refresh`, hay một lần gọi tay) **không** đi qua `boot()` của trang `TeamMember`. Vì vậy:
  - `#[Locked] public int $subjectId`, trang truyền qua `getWidgetData()`;
  - `mount()` **và** `boot()` nạp người dùng chưa xoá mềm theo `subjectId` và hỏi `Gate::forUser($viewer)->allows('viewPerformance', $subject)`; không có hoặc từ chối: `abort(404)`, cùng response với trang;
  - `canView()` tĩnh = người xem có `matter.view` hoặc `performance.viewAny` (lớp ngoài), không thay cho kiểm tra theo người;
  - `$pollingInterval = null`: không thăm dò; mỗi lần thăm dò là một lần chạy lại `BuildPerformanceTrend`;
  - logic kiểm quyền ở trait `AuthorizesPerformanceSubject`, gọi lại đúng `UserPolicy::viewPerformance` của Task 1; không viết luật xem thứ hai.
- [ ] Hai widget, mỗi widget một chuỗi, một màu `#4a73bd`, không chú giải. Không bao giờ hai trục y.
  - Mức hoàn thiện danh mục khác đơn vị, nên chỉ nằm trong bảng số, không vẽ chung.
  - `$isDiscovered = false`.
  - View dùng lại `filament.admin.widgets.revenue.chart-with-table` và `HasMoneyNumberTable`, đúng khuôn M9 Task 9 và phán quyết CSP M8 R4. Không JS mới. `DashboardWidgetOrderTest` không đổi.
- [ ] **Benchmark:** thêm hai năm ảnh chụp cho 30 người (cả hai loại dòng) và phép đo tác vụ chụp trên 3.000 vụ (ngân sách ≤ 10 giây, R11), cùng phép đo trang một người có biểu đồ (≤ 200 ms). In số đo ra STDERR.

**Test bắt buộc:**
- [ ] **Đồng nhất:** đóng băng thời gian, chạy tác vụ. Dòng `normal` của X bằng N1, N4, N5, N10 trực tiếp mà trưởng phòng đọc về X. `normal` cộng `restricted` bằng số mà X và admin đọc. Chạy với X có cả hai loại vụ.
- [ ] Trưởng phòng: dữ liệu xu hướng của X không có vết nào của dòng `restricted`.
  - Dòng đó không được truy vấn: kiểm query log không có `confidentiality = 'restricted'`.
  - Số bằng số khi dòng đó không tồn tại.
- [ ] **`scopeVisibleTo()` đóng khi không chắc** (`PerformanceSnapshotVisibilityTest`), ma trận người xem × loại dòng:
  - admin: cả hai; trưởng phòng: `normal`; chính X có `matter.view`: cả hai; X đã mất `matter.view`: không gì; luật sư khác, trợ lý: không gì; người có `performance.viewAny` cấp trực tiếp mà **không** có `matter.viewAny`: không dòng `normal` nào;
  - dòng `restricted` đồng ý với `isListableBy()` của vụ giả trên **mọi** ô của ma trận. Mutation probe: thay bằng `hasRole(Admin) || is($subject)` thì ô "X đã mất `matter.view`" đỏ; bỏ vế `matter.viewAny` của dòng `normal` thì ô "luật sư khác" đỏ;
  - `scopeVisibleToMany()` cho cùng tập dòng với hợp các `scopeVisibleTo()` từng người; số truy vấn của cột P8 bằng nhau với 3 và 12 người.
- [ ] **Widget bị can thiệp** (`PerformanceTrendWidgetAccessTest`, Review Focus 4):
  - luật sư L gọi `Livewire::test(StaleTrendWidget::class, ['subjectId' => <đồng nghiệp>])` → 404, response không chứa chuỗi số nào; tương tự `OverdueTrendWidget`;
  - L mở widget của chính mình, rồi `->set('subjectId', <đồng nghiệp>)` → bị `#[Locked]` chặn;
  - trưởng phòng mở widget của X, rồi mất `performance.viewAny`; lần gọi kế tiếp của **widget** (không qua trang) → 404 nhờ `boot()`. Mutation probe: xoá dòng hỏi trong `boot()` của trait thì test đỏ;
  - kế toán → 404;
  - widget không thăm dò: `$pollingInterval` là `null`, HTML không có `wire:poll`.
- [ ] Khoảng của trang một người: vào ngày 2026-10-04, `dates` là 2026-07-06 … 2026-10-03 (90 phần tử); không có điểm của hôm nay.
- [ ] Chạy hai lần cùng ngày: số dòng không đổi, số mới thắng.
- [ ] Lúc 23:50 ngày D theo `APP_TIMEZONE`, `captured_on = D`. Kiểm bằng `travelTo()`, `APP_TIMEZONE = Asia/Ho_Chi_Minh`.
- [ ] Ngày thiếu là `null`, không phải 0. Bảng số in "—".
- [ ] Prune: dòng 25 tháng 1 ngày bị xoá, dòng 25 tháng còn.
- [ ] Người đã nghỉ việc không có dòng mới, dòng cũ còn.
- [ ] Lịch: tác vụ có tên `performance.snapshot`, chạy 23:50, khoá 30 phút (khuôn `tests/Feature/Schedule/*`).
- [ ] `PortalCoverageTest` xanh không miễn trừ. `Audit::record(…, $snapshot)` không ném lỗi.
- [ ] Widget không có trên trang chủ.

**Commit:** `feat: M13 Task 7 — ảnh chụp hằng ngày performance_snapshots (23:50, tách normal/restricted, upsert, giữ 25 tháng, xem được đóng khi không chắc và suy từ isListableBy), xu hướng 90 ngày trên trang một người và đầu kỳ → cuối kỳ trên trang hiệu suất, hai widget tự kiểm quyền ở mount và boot, không thăm dò, ngày thiếu để trống`

### - [ ] Task 8 — Đo hiệu năng, dữ liệu mẫu, nghiệm thu, tài liệu, cổng merge

**Bước:**
- [ ] **Đo.** Chạy `tests/Benchmark/TeamPerformanceBenchmarkTest.php` trên MariaDB thật, theo khối lượng của R11.
  - Ghi vào "Ghi chú M13" một bảng số đo (trung vị 5 lần) cho từng trang × từng loại người xem (admin, trưởng phòng, luật sư), cùng `EXPLAIN` của từng truy vấn gộp.
  - Ngân sách vỡ thì thêm index theo R11 bằng một migration riêng, chạy vòng MariaDB thật, rồi đo lại và ghi cả hai lần.
  - Ghi rõ đã quyết gì về cache, và vì sao.
- [ ] **Dữ liệu mẫu** trong `DemoDataSeeder` (không bao giờ chạy ở production):
  - ba luật sư và hai trợ lý với hồ sơ khác nhau: một người đúng hạn đều, một người có mốc lỡ, một người nhận bàn giao (qua `ReassignMatters`, đúng đường thật) từ một luật sư nghỉ việc sau tháng trước: một mốc đã lỡ (R9) và vài luồng yêu cầu chưa giao ai mà người nghỉ việc đã trả lời (R18);
  - luật sư nghỉ việc đó vẫn có dòng trong "tháng trước" (R3);
  - yêu cầu khách trả lời nhanh và chậm; một yêu cầu đóng không trả lời (P10);
  - dòng tiến độ trải ba tháng;
  - một vụ `restricted` của luật sư A có vụ quá hạn cập nhật và mốc quá hạn, để thấy khác biệt giữa trưởng phòng và A;
  - 90 ngày ảnh chụp giả, ghi rõ là dữ liệu mẫu.
- [ ] **Đi hết luồng trên dữ liệu seed**, ghi từng bước:
  1. Trưởng phòng mở "Theo dõi đội ngũ", sắp theo mốc quá hạn, mở trang luật sư A, thấy A có 2 vụ quá hạn cập nhật. Trang Nhật ký hệ thống có dòng `performance_viewed`.
  2. A đăng nhập, mở "Việc của tôi", thấy 3 vụ quá hạn: 2 vụ thường và 1 vụ `restricted` của mình. A không mở được trang của B.
  3. Trưởng phòng mở "Hiệu suất", kỳ "tháng trước". Người nhận bàn giao không bị tính mốc lỡ, cũng không bị tính các luồng yêu cầu của người trước; người nghỉ việc vẫn có dòng của mình. Yêu cầu đóng không trả lời nằm ở cột riêng. Không có cột hạng. Dòng tham chiếu đứng đầu. Bấm vào tiêu đề cột tỉ lệ không đổi thứ tự.
  4. Kế toán: 404 ở ba địa chỉ. Trang Doanh thu vẫn lọc được theo luật sư.
  5. Trợ lý: dòng của mình, không cột doanh thu; "Chuyển giai đoạn", "Vụ kết thúc trong kỳ" là "Không áp dụng". Trang "Việc của tôi" của trợ lý: các cột của người phụ trách vụ là "Không áp dụng", không phải 0.
  6. Hôm nay hoàn thành mốc đã lỡ của tháng trước; trang "Hiệu suất" kỳ "tháng trước" không đổi (R19).
- [ ] **Test SPEC §11 phần "Quyền nội bộ"** và phần mới "Theo dõi đội ngũ": liệt kê theo tên rồi chạy. Độ phủ ≥ 80% cho `app/Actions/Performance/`, `app/Support/Performance/` và policy mới (SPEC §14 mục 1).
- [ ] **Kiểm chứng:**
  - `bin/dev test` xanh (so số test với `find`);
  - `bin/dev pint --test` sạch;
  - `bin/dev test:mariadb` xanh, **tuần tự**;
  - `migrate:fresh --seed` và vòng `migrate:reset` → `migrate` trên MariaDB thật.
- [ ] **Tài liệu:**
  - `docs/PROGRESS.md`: dòng M13 trong bảng và "Ghi chú M13" gồm mọi phán quyết R1–R20, số đo, các mục cần chủ văn phòng hoặc luật sư xác nhận, và các việc để lại cho người gộp làn khác: R17 (M10), cổng bản ghi tiếp nhận trong `scopeOwnedByVisibleMatters()` (M10, nếu chưa gộp), test R20 (M11, nếu chưa gộp);
  - đính chính có ngày cho bảng R4 của kế hoạch M11 (R13);
  - đính chính có ngày nếu R17 còn chờ M10 (đổi sang giờ làm việc);
  - ghi rằng phần "năng suất luật sư" của mục 7 trong danh sách nâng cấp giai đoạn 2 đã làm (R15).
  - `docs/QUY-TRINH.md`: cách dùng hai trang trong buổi giao ban hằng tuần (trang "bây giờ") và đánh giá hằng tháng (trang "trong kỳ", kỳ "tháng trước"), kèm bốn lý do không xếp hạng;
  - `docs/CAI-DAT.md`: bản cập nhật M13 làm gì trên máy chủ đã có dữ liệu:
    - chạy `migrate`, rồi `db:seed --force` để nhận quyền `performance.viewAny`;
    - xu hướng bắt đầu từ ngày triển khai;
    - lịch sử người giữ mốc chỉ đầy đủ từ ngày triển khai; lịch sử người giữ luồng yêu cầu **giao đích danh** cũng vậy (luồng chưa giao ai thì đủ, nhờ `matter_reassigned`);
    - không biến `.env` mới.
- [ ] Đối chiếu lại các đính chính SPEC của Task 1 với mã cuối cùng.
- [ ] Rà soát toàn nhánh bằng Opus, brief **giả định có một Critical** cộng hai câu riêng của M13. Merge, push, chờ CI xanh (SQLite và MariaDB).

**Commit:** `docs: M13 Task 8 — nghiệm thu: số đo trên 3.000 vụ, dữ liệu mẫu theo dõi đội ngũ (bàn giao khi nghỉ việc, yêu cầu đóng không trả lời), đi bộ năm vai trò và kỳ đã đóng, Ghi chú M13, QUY-TRINH giao ban và đánh giá tháng, CAI-DAT cập nhật`

---

## Những chỗ đã biết trước là sẽ cắn

**Ngày giờ và kỳ:**
- **Cột `date` trên SQLite lưu `Y-m-d 00:00:00`.** `whereBetween` với cận trên là ngày trần làm rơi cả ngày cuối kỳ. M9 mất 21 test đỏ vào ngày cuối tháng vì đúng chuyện này. Mọi cận của M13 đi qua `PerformancePeriod::bounds()`, đủ giờ, **kể cả các scope "bây giờ"** của `Deadline`. `Deadline::scopeUpcoming()` có sẵn đang mắc đúng lỗi này (rơi ngày +7 trên SQLite, giữ trên MariaDB); một test so `upcoming(7) = overdue() ∪ dueWithin(7)` mà cả hai vế cùng sai vẫn xanh trên SQLite. Vì vậy Task 2 test ngày +7 trên cả hai CSDL, và đối chiếu với `CheckDeadlines::tierFor()` (trả `d7` cho đúng ngày đó).
- **Kỳ đã đóng vẫn đổi được qua sáu thao tác có người bấm** (R19): ghi lùi dòng tiến độ, mở lại vụ, gỡ mốc, dời ngày đến hạn, mở lại mốc, đóng luồng chưa trả lời. Hoàn thành muộn, trả lời muộn và bàn giao **không** đổi được kỳ đã đóng. Một ai đó "sửa" `outcomeAt()` để mốc xong sau kỳ thành "trễ" là phá R19; `ClosedPeriodStabilityTest` canh.
- **`stage_logs.occurred_at` là ngày người dùng chọn, ghi lùi được.** Một dòng ghi hôm nay cho ngày tháng trước làm số của tháng trước đổi sau khi đã xem. Câu giải thích của P4 nói điều đó. Không "khoá kỳ".
- **Admin mở lại một vụ đã đóng** (đường bỏ qua của M6.5 R8) thì `closed_at` bị xoá, và P5 của một kỳ đã qua giảm đi. Đúng: vụ đó không còn "đã kết thúc".

**Cách các con số bị lệch hoặc bị lách:**
- **`completed_at` là lúc bấm nút, không phải lúc làm xong.** Luật sư quen đánh dấu muộn trông như trễ hạn. Luật sư bấm "xong" sớm trông như đúng hạn; `deadline_completion_set` ghi lại người bấm. Đây là lý do thứ ba của R8.
- **Dời ngày đến hạn là hợp lệ** (toà hoãn phiên) nhưng cũng là một cách tránh "lỡ". P1 dùng ngày đến hạn hiện tại. Lịch sử dời nằm ở dòng `deadline_updated`. M13 không đếm nó, vì truy vấn JSON trên `changed_fields` không khả chuyển giữa SQLite và MariaDB.
- **Yêu cầu của khách không quy về người giữ hiện tại** (R18). Gần như mọi luồng có `assigned_to = NULL`, nên cách đọc "hiện tại" (`COALESCE(assigned_to, lead_lawyer_id)`) chuyển **mọi** luồng của một vụ, kể cả luồng đã trả lời và đã đóng, sang người nhận mỗi lần bàn giao, và viết lại các tháng đã qua của người trước. Đừng "đơn giản hoá" `RequestHolderAt` về biểu thức đó; Review Focus 3 canh.
- **Hai cách đọc người giữ luồng, có chủ đích:** "bây giờ" (`holderId()`, N9) bỏ qua người được giao đã xoá mềm, đúng như đường thông báo `notifyHolderOfFollowUp()`; "trong kỳ" (`RequestHolderAt`) giữ người đó, vì đó là lịch sử. Chúng chỉ khác nhau khi một người bị xoá mềm lúc còn giữ luồng chưa đóng, điều `GuardsStaffOffboarding` chặn.
- **Lần bàn giao trước ngày triển khai M13** không có dòng `deadline_responsible_changed` cho từng mốc (R9), và không có dòng `client_request_assigned` cho luồng giao đích danh bị `ReassignMatter` chuyển (R18). Vài tháng đầu, tỉ lệ đúng hạn của người từng nhận bàn giao hàng loạt có thể thấp hơn thật. Luồng chưa giao ai không bị ảnh hưởng: `matter_reassigned` có từ M6.5.
- **N3 và P5 quy người khác nhau, có chủ đích.** `MatterPolicy::manageTeam()` không chặn bàn giao một vụ đã kết thúc. N3 ("bây giờ", vụ đã kết thúc đang đứng tên ai) đi theo người nhận; P5 ("trong kỳ") đi theo người phụ trách lúc kết thúc (`LeadAt`). Câu giải thích của N3 nói điều đó.
- **Mốc tạo qua AI chưa xác nhận tính như mốc thường** (R20). Người giữ mặc định là luật sư phụ trách và đã được `CheckDeadlines` nhắc. Mốc AI tạo sai thì gỡ kèm lý do (P2). Đừng thêm `whereNotNull('confirmed_at')` vào một chỗ duy nhất.
- **"Không áp dụng" chỉ theo quyền.** `TeamRoster::leadsMatters()` đọc `matter.transitionStage`, cùng quyền với `CreateMatter::leadLawyerOptions()`. `ReassignMatter` lại hỏi vai `lawyer`/`manager`. Hôm nay hai tập trùng nhau trong `TeamRoster` (admin không ở danh sách). Nếu một ngày chúng lệch nhau, sửa ở chỗ chọn người phụ trách, không sửa `leadsMatters()` thành "có vụ nào không" (R4).

**Chỗ phải sửa mã của milestone khác:**
- **`UpcomingDeadlinesWidget::WINDOW_DAYS` và `PendingChecklistReviewsWidget::rowsFor()` đang được test cũ gọi.** Giữ hằng số cũ làm bí danh, giữ chữ ký `rowsFor()`. Chỉ thân của nó gọi scope mới.
- **Thêm dòng nhật ký vào `ReassignMatter` (cả bước 3 lẫn bước 4) làm đổi số đếm** của những test M7 đếm dòng `Activity` hay khẳng định trên `client_request_assigned`. Sửa có chủ đích (Task 3, bước đầu), đừng để test "tự đỏ" rồi xoá khẳng định.
- **`RolesAndPermissionsTest` khẳng định `toHaveCount(17)` và ma trận quyền** nên đỏ ngay ở Task 1. Sửa có chủ đích; M10 cũng sửa đúng hai chỗ đó.
- **Sửa `Deadline::scopeUpcoming()`** làm widget "Mốc thời hạn sắp tới" trên SQLite thêm mốc ngày +7. Đó là bản sửa lỗi, không phải hồi quy; test cũ nào khẳng định ngược lại thì sửa có chủ đích (Task 2).
- **`RevenueOverTimeWidget` vừa được M9-final sửa một dòng** (`bounds()`). Tách `CollectedRevenue` sau khi M9-final đã gộp, không trước.
- **Regex của `MatterTest:171` không bắt `whereBetween('closed_at'`**, và nó chỉ quét `app_path()`. Một `scopeClosedWithin` viết ngoài `Matter.php` sẽ lọt qua test cũ, và không có cách đặt một fixture vào `app/`. Task 2 tách phần quét thành một hàm nhận mã nguồn (khuôn `forceDeleteCallLines()`), siết regex, rồi mới viết scope.
- **`User` phải tiếp tục ghi `is_active` vào nhật ký** (`getActivitylogOptions()->logOnly([... 'is_active'])`): `TeamRoster::subjectsForPeriod()` đọc lần vô hiệu hoá từ đó. Ai bỏ `is_active` khỏi danh sách thì người nghỉ việc biến khỏi kỳ họ đã làm; test "tập người theo kỳ" của Task 6 đỏ.

**Rò rỉ và kiểm quyền:**
- **`withTrashed()` không gỡ `ClientPortalScope`**, và `ActivityOwningMatter` đọc bảng con bằng `DB::table()` để tránh scope. Dùng lại đúng các hàm của nó, đừng viết truy vấn nhật ký mới.
- **Bảng `records()` của Filament sắp xếp và phân trang bằng PHP.** Trạng thái Livewire chỉ mang bộ lọc. Không bao giờ để danh sách id người hay id vụ trong thuộc tính công khai không khoá.
- **Widget nhúng trong trang là một component Livewire riêng.** Request của widget không chạy `boot()` của trang cha, và `getWidgetData()` chỉ là giá trị khởi đầu. Mỗi widget mang id người phải tự `#[Locked]` và tự hỏi Gate ở `mount()` và `boot()` (Task 7). Widget kế thừa `CanPoll` với `'5s'` mặc định; đặt `$pollingInterval = null`.
- **Dòng `normal` của ảnh chụp là tổng toàn văn phòng của một người, tính ngoài `listableBy`.** Nó chỉ bằng phần giao R4 khi người xem có `matter.viewAny` hoặc là chính người đó. `scopeVisibleTo()` đóng mọi trường hợp khác (R4); đừng nới nó thành "người xem có `performance.viewAny`".
- **`Gate::allows()` theo từng người gọi spatie**, và spatie nạp `roles`/`permissions` của từng người nếu chưa nạp: một truy vấn mỗi người, phá R11. `TeamRoster` nạp sẵn; test số truy vấn 3 ↔ 12 người canh.
- **`ClientRequest::scopeWithHolder()` nối `matters` và `users`.** Mọi cột trong P3, N9 và các scope đi cùng phải viết đủ tên bảng (`client_requests.created_at`, `client_requests.status`), không thì MariaDB strict báo cột mơ hồ.

**Môi trường và công cụ:**
- **Docker 9p** làm rơi tệp test ở thư mục trên 40 mục. Test của M13 nằm ở `tests/Feature/Performance/` và chạy qua công cụ liệt kê tệp tường minh.
- **Tên hàm Pest toàn cục trùng giữa các làn** lộ ra thành "Cannot redeclare" lúc gộp. Dùng tiền tố `m13`.

**Sắp xếp theo tên:** `utf8mb4_unicode_ci` và SQLite sắp xếp tên tiếng Việt khác nhau. Test không khẳng định thứ tự giữa hai tên chỉ khác dấu.

**Dữ liệu mẫu:** ảnh chụp giả trong `DemoDataSeeder` không được chạy ở production. `docs/CAI-DAT.md` đã cấm `db:seed --class=DemoDataSeeder` ở đó; nhắc lại trong docblock seeder.

---

## Còn cần chủ văn phòng hoặc luật sư xác nhận

Không mục nào chặn task nào; mỗi mục có mặc định an toàn ở trên.

1. **Có hiện thứ hạng không** (R8). Mặc định: không. Nếu có, cần quyết thêm hai điều: xếp theo số nào, và cách nào để không bất công giữa các lĩnh vực.
2. **Admin hoặc giám đốc có hành nghề có vào danh sách theo dõi không** (R3). Mặc định: không. Nếu có, thêm một cờ trên người dùng do admin bật. Không suy từ "có phụ trách vụ nào không", vì suy như vậy lộ vụ `restricted`.
3. **Thông báo cho nhân sự về việc hệ thống tính các con số này** (R14, Luật 91/2025/QH15). Cần quyết hai điều: ghi vào nội quy hay hợp đồng lao động, và có cần nhân sự xác nhận đã đọc không. Đây là việc của luật sư văn phòng, không phải của kế hoạch.
4. **Thư tổng hợp hằng tháng cho trưởng phòng** (R12). Mặc định: không. Nếu có, hình dạng đã định sẵn trong R12.
5. **Giữ ảnh chụp bao lâu** (R10). Mặc định 25 tháng.
6. **Mục tiêu thời gian trả lời yêu cầu của khách** (R17). Ví dụ "trong 1 ngày làm việc". Có mục tiêu thì chỉ số chung (R7) đổi "đã trả lời" thành "trả lời trong hạn".
7. **Có cần chia "tiến" và "lùi" giai đoạn không** (P4). Cần một quy ước thứ tự cho từng loại vụ. `sort_order` hôm nay không nói được `on_hold` là tiến hay lùi.
8. **Kế toán có cần cột doanh thu theo luật sư trên trang hiệu suất không** (R2). Mặc định: không, vì trang Doanh thu đã lọc được theo luật sư.
9. **Yêu cầu văn phòng đóng mà không trả lời có tính là "đã giải quyết" không** (R7, P10). Mặc định: không vào mẫu số, hiện ở cột riêng. Nếu muốn tính là đã giải quyết, cần một lý do đóng bắt buộc (trùng, khách rút, đã giải quyết ngoài hệ thống) để phân biệt với đóng cho đẹp số; hôm nay `TriageClientRequest::setStatus()` không có lý do.
10. **Mốc tạo qua AI chưa xác nhận có tính vào tỉ lệ đúng hạn không** (R20). Mặc định: có, theo phán quyết của M11 rằng mốc đó được nhắc như mốc thường. Đảo thì sửa cùng lúc `Deadline`, `CheckDeadlines` và widget trang chủ.
11. **Kỳ đã đóng cắt ở cuối kỳ** (R19). Mặc định: xong sau khi hết kỳ vẫn là "lỡ" của kỳ đó, nên mốc đến hạn ngày cuối kỳ chỉ có đúng hạn hoặc lỡ. Nếu muốn một khoảng ân hạn (ví dụ 3 ngày sau ngày đến hạn), đó là một hằng số mới trên `Deadline`, không phải một ngoại lệ ở trang.
