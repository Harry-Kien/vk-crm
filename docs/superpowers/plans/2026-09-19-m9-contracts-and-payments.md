# VK-CRM M9 — Kế hoạch hợp đồng dịch vụ pháp lý và thu phí theo đợt

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Sửa ngày 2026-09-24**, sau khi đối chiếu kế hoạch với SPEC, mã thật, các kế hoạch M6.5/M7/M8 và sổ controller `.superpowers/sdd/2026-09-19-m9-contracts-and-payments/progress.md`. Những thay đổi chính:
> - Thứ tự dựng mới: M9 chạy **sau M11**, trên CSDL production đã có dữ liệu thật. Bỏ mục "M9 chạy trước M6–M8" và ba "cái giá" của nó.
> - Hấp thụ phán quyết của sổ controller mà bản cũ chưa theo: **khách xem hợp đồng và lịch thu trên cổng** (P1), **doanh thu ghi cho luật sư phụ trách lúc tiền về**, lưu trên dòng khoản thu (P2).
> - **Vụ `restricted`:** SPEC thắng kế hoạch. Tiền của vụ hạn chế chỉ luật sư phụ trách và admin thấy và ghi, kế toán không (P3). Một định nghĩa duy nhất: `billing.view` + `Matter::listableBy()`.
> - Thêm **màn hình "Công nợ"** cho kế toán (Task 8 mới), vì kế toán không mở được trang vụ việc. Quản lý chỉ xem.
> - **Nhắc đợt quá hạn do M9 cài** (Task 11 mới), không còn đẩy sang M6. Bỏ cột `instalments.reminders_sent`: chống trùng qua `outbound_messages` (M6 R3).
> - Thêm **cổng khách: hợp đồng và lịch thu** (Task 10 mới), kèm bảng kê thanh toán trong gói bàn giao M7.
> - Sửa theo mã thật: "đã kết thúc" là `closed_at` / `Matter::scopeOpen()` (M6.5 R8), không phải `is_terminal`; dùng lại sự kiện đổi giai đoạn của M7 Task 3 nếu có; tác vụ định kỳ là Action trong `app/Actions/Schedule/`; alias morph cho mọi model mới; widget doanh thu `$isDiscovered = false`; `StagePresets` phải có nhánh tường minh cho sáu loại mới; guard khoá giai đoạn của M6.5 Task 19 phủ cả đợt thanh toán; tooltip biểu đồ phụ thuộc phán quyết CSP của M8 R4.
> - Đánh số lại task: 13 task (thêm 3, viết lại thân 10). Trailer `Claude Opus 5.5`. Thêm luật toàn cục của M6.5.

## Vị trí trong thứ tự dựng — đọc trước mọi thứ khác

**Thứ tự hiện hành** (PROGRESS, chủ văn phòng chốt 2026-09-24): M6.5 → phần còn lại của M6 → M7 → M8 → **M11 (máy chủ MCP)** → **M9** → M10 → M12 (PWA + push).

Hệ quả cho M9:

- **M9 chạy trên CSDL production có dữ liệu thật.** Migration chỉ **thêm** bảng. Seed phải an toàn khi chạy lại và không ghi đè thứ admin đã sửa trong app (Task 1). Mục "nâng cấp" của `README.md` (M8 R6) phải liệt kê lệnh seed quyền mới và lệnh `billing:check-invariants` (Task 13).
- **Những thứ M9 dùng lại, không dựng lại:** hạ tầng thư xếp hàng sau commit (M6.5 R2); `ResolveStaffRecipients` (M6.5 R3); `closed_at` + `Matter::scopeOpen()` và `CancelMatter` (M6.5 R8, Task 5); `CreateClient` (M6.5 Task 6); tách `ReferenceDataSeeder` / `DemoDataSeeder` và guard khoá giai đoạn (M6.5 Task 19); sự kiện vào giai đoạn kết thúc và `GenerateHandoverPackage`, `RetractDocument`, `OfficeProfile` (M7 Task 3, 4, 7, 10); phán quyết CSP và `vkcrm:preflight` (M8 R1, R4); presenter theo danh sách cho phép và cờ `matters.ai_access` (M11 R4, R9).
- **Việc phải dò trước khi viết dòng đầu tiên:** grep những tên trên trong `app/` ở `main` lúc cắt nhánh, và dán kết quả vào báo cáo Task 1. Tên thật thắng tên trong kế hoạch này.

**Goal:** Văn phòng ghi được một hợp đồng dịch vụ pháp lý cho mỗi vụ việc với **một giá trị thoả thuận duy nhất**, chia thành các **đợt thanh toán** gắn vào tiến độ vụ việc ("thanh toán đợt 2 khi nộp đơn khởi kiện"), ghi nhận từng khoản tiền thật sự nhận được kèm người ghi và cách nhận, nhắc công nợ quá hạn, cho khách xem hợp đồng và lịch thu của chính họ, và nhìn thấy toàn bộ bức tranh tiền trên một trang có biểu đồ lọc được theo thời gian, luật sư và lĩnh vực. Đúng mô hình chủ văn phòng mô tả: *"văn phòng ký hồ sơ là giá trị 1 lần nhưng mà thanh toán theo giai đoạn."*

**Architecture:** Bốn bảng mới (`contracts`, `instalments`, `payments`, `contract_amendments`) gắn vào `matters`, đúng chỗ SPEC §15 đã chừa sẵn. Tiền lưu bằng **số nguyên đồng**. Nghiệp vụ nằm trong `app/Actions/Billing/`; Filament chỉ gọi Action. Đợt thanh toán theo giai đoạn nối vào `TransitionMatterStage` bằng **một sự kiện** (dùng lại sự kiện M7 đã thêm nếu có) — Action chuyển giai đoạn không biết gì về tiền. Ba màn hình tiền: tab trên trang vụ việc (luật sư, quản lý, admin), trang **"Công nợ"** (kế toán, admin; quản lý chỉ xem), trang **doanh thu** (`Filament\Pages\Dashboard` riêng với `HasFiltersForm`, không nhồi vào trang chủ §7.1). Một khối "Hợp đồng và thanh toán" trên cổng khách.

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8 (biểu đồ: extend `Filament\Widgets\ChartWidget` + `getType()`; `BarChartWidget`/`DoughnutChartWidget` đã `@deprecated`. Bộ lọc: `Filament\Pages\Dashboard\Concerns\HasFiltersForm` + `Filament\Widgets\Concerns\InteractsWithPageFilters`, đã kiểm trong `vendor/`), Pest 4, Pint. **Không gói mới.** Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §1 ("Ngoài phạm vi bản này" — thu hẹp ở M9), §4 (đặc biệt §4.4, §4.5, §4.6 `confidentiality`, §4.8, §4.9 danh mục, §4.11, §4.13 làm tiền lệ quá hạn, §4.15 `outbound_messages`, §4.19), §5 + phần **Portal** (cần **đính chính có ngày**, Task 3 và Task 10), §6.1, §6.2/§6.3, §6.8 (người nhận thư), §6.10 (tiền lệ DTO giới hạn thông tin), §6.11, §6.12 (gói bàn giao), §7.1 (M9 **không** động vào), §7.2 (thêm một tab), §8 (cổng khách), §9 (thêm mẫu thư), §10.2 (CSP), §10.5, §10.6, §10.10, §11, §12, §13, §14, **§15**.

---

## Phán quyết 2026-09-24 (chủ văn phòng đảo được)

Ghi nhận từ sổ controller M9 và từ controller ngày 2026-09-24. Mỗi phán quyết **chủ văn phòng đảo được**; đảo thì sửa đúng task nêu tên, không vá chỗ khác. Task 13 chép cả bảy vào PROGRESS.

**P1 — Khách xem hợp đồng và lịch thu của chính mình trên cổng** (sổ controller, phán quyết 3). Hiện: số hợp đồng, tổng giá trị, thuế suất, ngày ký, các đợt (tên, số tiền, đến hạn khi nào, đã thu, còn lại, quá hạn), các khoản thu chưa huỷ (ngày, số tiền, cách trả). **Không** hiện: ghi chú nội bộ, lý do miễn, lý do huỷ, lý do phụ lục, người ghi, khoản thu đã huỷ, hợp đồng `draft` hoặc `cancelled`, bản scan biên lai. Ba tầng bảo vệ cổng phủ mọi model mới, `PortalCoverageTest` xanh. Task 2 đóng kín cả bốn model; Task 10 mở có chủ đích. Hệ quả: gói bàn giao M7 có bảng kê thanh toán (Task 10). **Không** có thư nhắc nợ cho khách ở M9.

**P2 — Doanh thu ghi cho luật sư phụ trách tại thời điểm thu**, lưu trên chính dòng khoản thu (`payments.attributed_lawyer_id`, sổ controller, câu hỏi 3). Bàn giao vụ (`ReassignMatter`) không dời tiền đã thu sang người mới. Tiền **chưa** thu (còn phải thu, quá hạn) theo luật sư phụ trách **hiện tại**. Mỗi widget in nghĩa đó lên chính nó.

**P3 — Ai thấy và ghi tiền.**
- **Một định nghĩa duy nhất của "ai thấy tiền của vụ nào":** có `billing.view` **và** vụ đó nằm trong `Matter::listableBy($user)` (bản trong bộ nhớ: `Matter::isListableBy()`). Không hỏi `MatterPolicy::view` (kế toán không có `matter.view`), không viết điều kiện thứ hai.
- Hệ quả, khớp SPEC §4.6 "chỉ lead lawyer và quản trị": **tiền của vụ `restricted` chỉ luật sư phụ trách và admin thấy và ghi.** Kế toán và quản lý không thấy. Bản cũ cho kế toán thấy tiền vụ hạn chế (Task 3 điểm 2 cũ); bỏ ngoại lệ đó.
- **Ghi khoản thu:** kế toán và admin, trên trang "Công nợ" (Task 8). Quản lý **chỉ xem**. Luật sư không ghi, **trừ** luật sư phụ trách của vụ `restricted` (không ai khác ngoài admin thấy vụ đó để ghi), trên tab của vụ (Task 7).
- **Tổng số của kế toán và quản lý không tính vụ `restricted`**, kể cả dạng gộp. Lý do chọn loại hẳn thay vì gộp: trang doanh thu lọc được theo tháng, luật sư và lĩnh vực; ở quy mô một văn phòng, lọc hẹp là suy ngược được số tiền của đúng một vụ hạn chế bằng phép trừ. Admin thấy đủ. Trang in một câu chung "Số liệu gồm các vụ việc anh/chị được xem", **không** in số vụ bị loại (§10.10: không lộ sự tồn tại).
- **Nhắc đợt quá hạn** (Task 11): vụ thường gửi kế toán + luật sư phụ trách; vụ `restricted` gửi luật sư phụ trách + admin. Người nhận qua `ResolveStaffRecipients` (M6.5 R3) với cổng "được xem tiền của vụ" thay cho "được xem vụ", trong **cùng** lớp đó, không định nghĩa thứ hai.

**P4 — Thứ tự dựng:** M9 sau M11, M10 sau M9 (xem mục trên).

**P5 — VAT: giữ một cột `vat_rate_percent`, không theo `vat_rate` + `vat_included` của sổ controller (phán quyết 4).** Lý do ở "Kết luận về VAT": `total_amount` luôn là số khách trả, đã gồm VAT, nên `vat_included` lúc nào cũng đúng — một cột luôn mang một giá trị. Ghi sai lệch này vào PROGRESS.

**P6 — Sáu câu hỏi cũ của kế hoạch đã có câu trả lời** (sổ controller): xem mục "Câu hỏi cho chủ văn phòng".

**P7 — Dữ liệu tiền là dữ liệu nhạy cảm** ("tài chính", Nghị định 356/2025, `docs/research/2026-09-24-mcp-phap-ly-goi.md:286`). Bốn model tiền không lộ qua máy chủ MCP của M11 (Task 3).

---

## Ràng buộc toàn cục

- **Nhánh:** `m9-contracts-and-payments`, cắt từ `main` **sau khi M11 đã merge** (P4).
- PHP sàn **8.3**, cứng. Không Redis, Horizon, Octane, Reverb, Pulse, Scout, Telescope. Không `storage:link`.
- **CRM chỉ dùng TIẾNG VIỆT** — quyết định của chủ văn phòng ngày 19/09/2026, ghi ở `.superpowers/sdd/2026-09-19-m5-client-portal/progress.md`. Không dựng bộ chuyển ngôn ngữ, không thêm locale thứ hai. `lang/vi/` là nơi duy nhất; `lang/en/` chỉ tồn tại như fallback của framework. Định danh mã tiếng Anh, **mọi chuỗi hiển thị qua `__()`**.
- Nghiệp vụ chỉ ở `app/Actions/`. Page, resource, widget, controller, listener, job **chỉ gọi Action** và **phải bắt `DomainException`** để đổi thành lỗi trên form (bài học M3 Task 9 và M4: một exception không được bắt là lỗi 500 trên màn hình).
- **Enum backed string cho mọi cột trạng thái, có `label()`** đọc từ `lang/vi/enums.php`.
- **Actor tường minh.** Mọi Action nhận `User $actor`, không đọc `Auth::` bên trong. Dự án **không** có một vị trí tham số chung (`OpenMatter::handle(User $actor, …)` đứng đầu; `TransitionMatterStage::handle(Matter, User $actor, …)` đứng thứ hai; `SetMatterPortalPublication` đứng cuối). Action mới trong `app/Actions/Billing/` đặt actor **đứng đầu**. Không một dòng `Auth::` nào trong `app/Actions/Billing/`.
- **`blameOn($actor)` TRƯỚC `save()`/`update()`** cho mọi model dùng `HasBlameable`. `HasBlameable` rơi về `auth('web')` ambient nếu không ai tuyên bố actor; với tiền, một cột `created_by` sai là một câu trả lời sai cho câu hỏi "ai ghi khoản này".
- **Chuẩn mutation probe (M4).** Với **mỗi** điều kiện thêm vào: xoá đúng điều kiện đó, chạy lại, **khẳng định đúng những test nêu tên nó chuyển ĐỎ**, khôi phục, dán bằng chứng. Probe sống sót nghĩa là test không kiểm cái nó nói. Một test âm không có cặp dương đi kèm thì không tính.
- **Docblock là thứ phải rà lại, không phải thứ để tin.** Kết thúc mỗi task: đọc lại từng câu khẳng định trong docblock vừa viết và chứng minh hoặc sửa.
- **SQLite không bắt được ràng buộc chỉ số/khoá ngoại của MariaDB.** Mọi task đụng migration chạy trên container MariaDB thật: `bin/dev artisan migrate:fresh --seed`, rồi `migrate:reset` → `migrate`, dán **nguyên văn** output. Task có khoá (`lockForUpdate`, `Cache::lock`) hoặc so chuỗi tiếng Việt chạy thêm `bin/dev test:mariadb`, **tuần tự**.
- **Filament 5 — không viết mã Filament từ trí nhớ.** Chạy `bin/dev artisan make:filament-*`, đọc `vendor/filament/`, hoặc tra context7. Kế hoạch này mô tả hành vi, không có mã Filament nguyên văn nào là cố ý.
- **Mang từ M6.5, áp nguyên cho M9:**
  - Test màn hình đi qua Livewire hoặc HTTP (`Livewire::test(...)->callTableAction(...)`, `->fillForm(...)->call(...)`), **không gọi thẳng Action**.
  - Trang Filament tự viết (`Receivables`, `RevenueDashboard`) tự hỏi `Gate::forUser($account)` trong `canAccess()` và ở mọi chỗ resolve record, rồi `abort(404)`.
  - `maxLength` của form bằng độ dài cột DB (`name` 150, `reference` 100, …). Ô tiền có giới hạn trên là **một hằng** `Money::MAX` dùng chung cho form và Action.
  - Chỉ style nội tuyến trên biến CSS của Filament (`--danger-500`…). Không có bước build CSS. Mã hex ở mục màu chỉ là màu dữ liệu của Chart.js và chuẩn so sánh.
  - Mọi thư đi qua hàng đợi, sau khi commit (M6.5 R2). Người nhận qua `ResolveStaffRecipients` (M6.5 R3).
  - Thư cho khách chỉ tới tài khoản `is_active` **và** `activated_at` không null (M6.5 R12) — M9 không gửi thư cho khách, ghi lại để không ai thêm mà quên.
- **False-green của Livewire (M4 Task 2).** Hai POST tới cùng một path trong một `it()` khiến lần thứ hai chạy không middleware bền và trả 200. Mỗi trường hợp một `it()` riêng.
- **Từ chối trong panel trả 404, không phải 403** (`AnswerDeniedPanelRequestsWithNotFound`). **Không** map exception nghiệp vụ sang một mã HTTP riêng (SPEC §10.10).
- TDD với Pest: test đỏ trước. Nếu red-first yếu về cấu trúc, **nói thẳng** và bù bằng mutation probe.
- Kết thúc mỗi task: `bin/dev test` xanh, `bin/dev pint` sạch, commit **chỉ các tệp của mình theo đường dẫn tường minh** (`git commit -- <path>`), **không `git add -A`**, không commit trần.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Chép nguyên văn, không thay tên model.
- `security-review` **bắt buộc**. Người rà soát mỗi task và rà soát cuối được brief **giả định có một lỗi Critical**. Task 3, 4, 5, 10 **giao cho Opus**.

---

## Bảy quyết định thiết kế đã chốt — và chỗ tôi nghĩ chúng chưa đúng

Kế hoạch này dựng trên bảy quyết định đã được đưa ra trước khi viết. Sáu quyết định tôi tán thành và đã xây tiếp; **một quyết định tôi nghĩ cần sửa**, nói ngay ở đây thay vì chôn trong một task.

**1. Một hợp đồng cho một vụ việc.** Giá trị thoả thuận một lần, có tính quyết định. **Tán thành** — và cài bằng một **unique index thật trên `contracts.matter_id`**, không phải một quy ước. Xem điểm 4 dưới đây về hệ quả với xoá mềm.

**2. Đợt thanh toán mang lịch thu.** **Tán thành**, với một chỗ cần rẽ thành ba thay vì hai: "hoặc ngày đến hạn, hoặc chạm tới một giai đoạn" bỏ sót trường hợp phổ biến nhất trong hợp đồng dịch vụ pháp lý Việt Nam — **tạm ứng khi ký**. Lúc soạn lịch thu thì chưa ai biết ngày ký, nên nó không phải một `due_date`; và "ký hợp đồng" không phải một giai đoạn trong `matter_type_stages`. Ba loại kích hoạt: `on_signing`, `due_date`, `stage`.

**3. Bất biến: tổng các đợt phải khớp ĐÚNG giá trị hợp đồng.** **Tán thành**, và phần dư khi chia theo phần trăm **luôn rơi vào đợt cuối cùng** — một câu, một chỗ (`App\Support\Billing\SplitByPercent`), hiện ra bằng số đồng trên màn hình trước khi lưu.

**4. Khoản thu là bản ghi riêng.** **Tán thành tuyệt đối.** Và đi thêm một bước: **`payments` và `contracts` KHÔNG dùng `SoftDeletes`.** (a) `contracts.matter_id` là unique; một hợp đồng xoá mềm vẫn chiếm chỗ index, và "xoá mềm rồi tạo lại" chính là lỗ hổng dự án đã vấp **hai lần** (`matter_type_stages.key`, `MatterType.code`). (b) Một khoản thu ghi nhầm không được biến mất — nó được **huỷ** (`voided_at` + lý do ≥ 20 ký tự `mb_strlen`), và vẫn nằm đó. Đúng tinh thần `stage_logs` (chỉ thêm). Deviation so với câu "Toàn bộ bảng dùng ... `deleted_at`" ở §4 — M1 đã có tiền lệ deviation tương tự cho bảng nhật ký và pivot, ghi rõ lý do.

**5. Tiền lưu bằng số nguyên đồng.** **Tán thành.** `unsignedBigInteger`, cast `integer`. **Cái giá nếu văn phòng có ngày tính phí bằng USD**, nói thẳng:

> Sẽ không đủ nếu chỉ thêm một cột `currency`. Một hệ thống hai tiền tệ cần **ba** thứ mà hôm nay không có: (a) một **đơn vị nhỏ nhất khác** — USD có cent, nên `amount` phải đổi nghĩa thành "đơn vị nhỏ nhất của đồng tiền đó"; (b) một **tỷ giá có dấu thời gian** và quyết định chốt tỷ giá lúc ký hay lúc thu; (c) một **tầng hiển thị** biết ký hiệu và cách nhóm chữ số của từng đồng tiền. Đó là một milestone riêng. Vì vậy kế hoạch này **cố ý KHÔNG thêm cột `currency`** — một cột luôn mang đúng một giá trị là một lời hứa chưa được giữ.

**6. Tiền là dữ liệu nhạy cảm, phải giới hạn theo vai trò.** **Tán thành**, chi tiết ở P3 và Task 3.

**7. VAT: đừng mô hình hoá quá tay.** **Tán thành, và cắt sâu hơn đề bài.** Xem "Kết luận về VAT" — **một cột nullable duy nhất** (P5).

### Chỗ tôi nghĩ một quyết định chưa đúng như đã phát biểu

**Quyết định 1 đọc trần ra sẽ cấm phụ lục hợp đồng.** Phụ lục là chuyện có thật và thường xuyên (lên phúc thẩm, phát sinh việc ngoài phạm vi). Để nguyên câu chữ thì người cài đặt hoặc **cấm hẳn** (văn phòng giữ hợp đồng thứ hai ngoài hệ thống), hoặc **cho sửa thẳng `total_amount`** (mất lịch sử).

Cách giữ được cả hai: **giá trị hợp đồng là một cột, lịch sử của nó là một bảng.** `total_amount` vẫn là nguồn sự thật duy nhất; mỗi lần con số đó đổi sinh một dòng `contract_amendments` chỉ-thêm mang giá trị cũ, giá trị mới, lý do, ngày ký và (nếu có) bản scan phụ lục. Không có "phiên bản hợp đồng", không có câu hỏi "bản nào đang có hiệu lực". (Sổ controller đã chấp nhận thiết kế này.)

---

## Kết luận về VAT — biểu diễn nhỏ nhất còn trung thực

**Một cột: `contracts.vat_rate_percent` — `unsignedTinyInteger nullable`.** Không có cột `vat_mode`/`vat_included`, không có cột tiền thuế, không có bảng thuế (P5).

Lập luận. Bất biến số 3 nói tổng các đợt phải khớp đúng giá trị hợp đồng. Nếu khách trả phí **cộng** VAT thì các đợt phải cộng lại bằng phí-cộng-VAT, nếu không "còn phải thu" sai đúng phần thuế. Vậy chỉ còn một cách đọc nhất quán: **`total_amount` LUÔN là số tiền khách hàng phải trả, đã gồm VAT ở nơi có VAT.** Khi đó "chưa gồm / đã gồm" không còn là một trạng thái cần lưu — nó là một cách nhập liệu, và chỗ của nó là màn hình.

- `vat_rate_percent = null` → không có dòng thuế (không chịu thuế, hoặc không xuất hoá đơn).
- `vat_rate_percent = 0` → có hoá đơn, thuế suất 0%. Hai chuyện khác nhau; một cột nullable phân biệt được.
- `vat_rate_percent = 8` hoặc `10` → phần thuế nằm **trong** `total_amount`: `thuế = intdiv(total × r, 100 + r)`, phần chưa thuế nhận phần dư. Suy ra **một chỗ duy nhất**, `App\Support\Billing\Vat`. Có test cho 33.333.333 đ ở 10%.

**Thuế suất là dữ liệu của từng hợp đồng, không bao giờ là hằng số.** Repo không có nguồn nào chốt thuế suất cho dịch vụ pháp lý; 8% và 10% trong dữ liệu mẫu chỉ là mẫu.

**Cái giá:** hợp đồng báo giá "50.000.000 chưa VAT" phải nhập thành 55.000.000 với thuế suất 10. Form soạn hợp đồng có hai ô ("số tiền chưa VAT", "thuế suất") và hiện ngay số tổng sẽ lưu, hoặc cho nhập thẳng số tổng — nhưng **số được lưu chỉ có một**, và cả ba con số hiện cùng lúc trước khi bấm lưu.

**Ngoài phạm vi:** xuất hoá đơn, hoá đơn điện tử, mẫu số / ký hiệu hoá đơn, tờ khai thuế, đối chiếu với phần mềm kế toán. Không có gì trong M9 sinh ra một hoá đơn.

---

## Đợt thanh toán theo giai đoạn — làm sao để `TransitionMatterStage` không biết gì về tiền

**Cái không được làm:** cho `TransitionMatterStage` truy vấn `instalments`. Action đó là nơi nhạy cảm nhất hệ thống (`stage_logs` chỉ-thêm, SLA §6.4, `closed_at` của M6.5 R8, lưu trữ của M7 đều treo vào nó).

**Cái làm:** một **sự kiện đổi giai đoạn**, và **chỉ một**.

- **Trước khi tạo sự kiện mới, grep `app/Events` và `TransitionMatterStage`.** M7 Task 3 đã thêm một listener `afterCommit` "khi `TransitionMatterStage` đưa vụ vào giai đoạn `is_terminal`", tức gần như chắc chắn đã có một sự kiện đổi giai đoạn. Nếu có: **mở rộng đúng sự kiện đó** (mang `StageLog`, chỉ phát khi giai đoạn thật sự đổi, `ShouldDispatchAfterCommit`) và thêm một listener. Nếu chưa có: tạo `app/Events/MatterStageChanged.php` theo đúng hình dạng dưới đây, và M7 dùng chung về sau. Hai sự kiện phát ra từ cùng một dòng là hai định nghĩa của "đã đổi giai đoạn".
- Sự kiện phát **chỉ khi giai đoạn thật sự đổi** (`! $isSameStage`) — một dòng cập nhật cùng giai đoạn (§6.3) không phải một lần chạm tới giai đoạn.
- Sự kiện mang `StageLog` (đã có `matter_id`, `from_stage`, `to_stage`, `occurred_at`, `id`) và **không mang trường tiền nào**.
- **`ShouldDispatchAfterCommit`**, như `StageLogPublished`: nếu transaction ghi `StageLog` rollback thì listener không chạy. Một đợt "đến hạn" vì một lần chuyển giai đoạn đã rollback là một khoản văn phòng đi đòi mà lý do không tồn tại.

```
app/Events/<sự kiện đổi giai đoạn — dùng lại của M7 nếu có>.php
app/Listeners/ReleaseStageTriggeredInstalments.php
app/Actions/Billing/TriggerInstalmentsForStage.php
app/Actions/Schedule/ReconcileStageTriggeredInstalments.php
```

Bốn điểm phải cài đúng, mỗi điểm một test:

1. **Chạy một lần duy nhất.** Cổng là `instalments.triggered_at IS NULL`, không phải "giai đoạn hiện tại bằng giai đoạn kích hoạt" (`allowed_next` có chu trình, `on_hold` ra vào được).
2. **Ngày đến hạn tính từ ngày giai đoạn THẬT SỰ xảy ra** (`stage_logs.occurred_at` + `due_days_after_trigger`), không phải `now()`. Một lần chuyển ghi lùi ngày có thể sinh ra một đợt **đã quá hạn ngay khi ra đời** — đó là sự thật, không phải lỗi.
3. **Chỉ hợp đồng `active`.**
4. **Ghi bằng chứng.** `triggered_by_stage_log_id` trỏ đúng dòng nhật ký đã kích hoạt.

**Giai đoạn đầu tiên của loại vụ việc không làm được giai đoạn kích hoạt.** `Matter::creating` đặt giai đoạn đầu **không** sinh dòng `stage_logs` (`app/Models/Matter.php:69`), nên không sự kiện nào và không lần đối chiếu nào thấy nó. `DraftContract` từ chối `trigger_stage_key` là giai đoạn đầu, thông điệp chỉ sang `on_signing`.

**Lưới an toàn — `ReconcileStageTriggeredInstalments`, chạy hằng ngày.** Là **Action** trong `app/Actions/Schedule/`, đăng ký bằng `Schedule::call(new ReconcileStageTriggeredInstalments)` trong `routes/console.php` cạnh các mục đã có (`RecordScheduleRun`, `SendHeartbeat`, `queue.drain`, `CheckDeadlines`, và các mục M6/M7 thêm sau), có `withoutOverlapping(<phút>)` có hạn. Nó phủ ba đường listener không phủ: một đợt **thêm vào lịch sau khi** vụ đã qua giai đoạn kích hoạt; một hợp đồng **kích hoạt** khi vụ đã ở giữa chừng; và seeder/console ghi thẳng `matters.stage`. Đối chiếu `instalments` chưa kích hoạt với `stage_logs` (chỉ-thêm) và gọi **đúng** `TriggerInstalmentsForStage`, không bản sao logic. Heartbeat và dải cảnh báo cron đã có từ M6 (`SendHeartbeat`), nên một cron chết sẽ hiện trên trang chủ admin.

**Người cài đặt phải grep lại mọi nơi ghi `matters.stage`** (M6.5 Task 5 `UpdateMatterDetails`/`CancelMatter`, M7 `ReassignMatter`…). Nơi nào đổi giai đoạn cũng phải phát cùng sự kiện đó. Dán kết quả grep vào báo cáo Task 6.

---

## Mô hình dữ liệu

Tất cả bảng: `id` bigint tự tăng, `created_at`, `updated_at`, `created_by`/`updated_by` FK `users` qua `HasBlameable`. **`deleted_at` chỉ có ở nơi ghi rõ.** Mọi model mới có **alias morph** trong `Relation::enforceMorphMap` của `AppServiceProvider` (`contract`, `instalment`, `payment`, `contract_amendment`, và `time_entry` ở Task 12). Map là bản nghiêm ngặt: thiếu alias thì `Audit::record(..., $contract)` và `outbound_messages.related` là lỗi 500 (`ClassMorphViolationException`).

### `contracts` — một hợp đồng cho một vụ việc

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK matters, **unique** | Quyết định 1, cài bằng index thật ở MariaDB |
| code | string(30) unique | `HD-{YYYY}-{0001}`, sinh bằng `App\Support\CodeSequence` đã có (§6.1). Không bao giờ đổi |
| status | enum `ContractStatus` | `draft`, `active`, `completed`, `cancelled` |
| billing_model | enum `BillingModel` | `fixed_fee` (duy nhất cài đặt ở M9), `hourly`, `mixed` — xem Task 12 |
| total_amount | unsignedBigInteger | **Đồng.** Số tiền khách phải trả, đã gồm VAT ở nơi có VAT |
| vat_rate_percent | unsignedTinyInteger nullable | Xem "Kết luận về VAT" |
| signed_at | date nullable | Bắt buộc khi rời `draft` |
| activated_by | FK users nullable | Người ở văn phòng ghi nhận việc ký. **Không phải chữ ký số** |
| ended_at | date nullable | Ngày `completed` hoặc `cancelled` |
| ended_reason | text nullable | Bắt buộc ≥ 20 ký tự `mb_strlen` khi `cancelled`. **Nội bộ** |
| note | text nullable | **Nội bộ. Không bao giờ ra portal** |

**Không `deleted_at`.** Hợp đồng `draft` xoá cứng được **khi chưa có khoản thu nào** (sổ controller, câu hỏi 4); từ `active` trở đi chỉ `completed` hoặc `cancelled`. Cài bằng hook `deleting`, tiền lệ `Matter::forceDeleting` → `MatterNotDestroyable`.

Index: `(status)`, `(signed_at)`. Unique `(matter_id)`, `(code)`.

### `instalments` — đợt thanh toán

| Cột | Kiểu | Ghi chú |
|---|---|---|
| contract_id | FK contracts | |
| sequence | unsignedSmallInteger | Unique cặp `(contract_id, sequence)` |
| name | string(150) | "Tạm ứng khi ký hợp đồng", "Thanh toán đợt 2 khi nộp đơn khởi kiện". **Khách thấy tên này** (P1); form ghi rõ điều đó |
| amount | unsignedBigInteger | **Đồng.** Là con số có tính quyết định |
| percent_basis | decimal(5,2) nullable | Phần trăm người dùng đã gõ, **chỉ để hiển thị và truy vết**. Không bao giờ dùng để tính lại `amount` |
| trigger_type | enum `InstalmentTrigger` | `on_signing`, `due_date`, `stage` |
| trigger_stage_key | string(40) nullable | Bắt buộc khi `trigger_type = stage`. Trỏ `matter_type_stages.key` của **đúng loại vụ việc đó**, không phải FK. **Không được là giai đoạn đầu** của loại vụ việc (xem trên). Guard của M6.5 Task 19 chặn xoá/đổi key còn được đợt `pending` chưa kích hoạt trỏ tới (Task 6) |
| due_days_after_trigger | unsignedSmallInteger default 0 | "30 ngày kể từ khi toà thụ lý" |
| due_date | date nullable | `null` = chưa đến đợt |
| triggered_at | timestamp nullable | Cổng chống kích hoạt hai lần |
| triggered_by_stage_log_id | FK stage_logs nullable | Bằng chứng "tại sao đợt này đến hạn" |
| status | enum `InstalmentStatus` | `pending`, `paid`, `waived`, `cancelled` — **chỉ bốn** |
| waived_reason | text nullable | Bắt buộc ≥ 20 ký tự `mb_strlen` khi `waived`. **Nội bộ** |
| waived_by / waived_at | FK users nullable / timestamp nullable | |
| note | text nullable | **Nội bộ** |

**Không `deleted_at`.** Đợt của hợp đồng `draft` xoá cứng được; của hợp đồng `active` thì `cancelled` (chỉ qua `AmendContract`) hoặc `waived`, không xoá.

Index: `(due_date, status)` — đúng hình dạng index của `deadlines` §4.13, vì truy vấn quá hạn chạy hằng ngày. `(contract_id)`, `(trigger_stage_key)`.

**Ba điều cố ý KHÔNG có trong bảng này:**

- **Không có cột `paid_amount`.** Số đã thu là `SUM` của `payments` chưa huỷ. Một cột tổng hợp là **nguồn sự thật thứ hai về tiền** và nó **sẽ** lệch. Cái giá: mỗi lần đọc "còn phải thu" là một phép join — Task 9 phải đo. Nếu quy mô đổi, câu trả lời là một bảng tổng hợp **dựng lại được từ `payments`**, không phải một cột ai cũng ghi được.
- **Không có trạng thái `overdue` trong enum.** Quá hạn là hàm của thời gian. Xem "Phát hiện quá hạn".
- **Không có cột `reminders_sent`.** Trí nhớ chống gửi trùng là `outbound_messages` (M6 R3: "không thêm cột 'đã nhắc lúc nào' ở đâu nữa"). Xem Task 11.

**Bốn trạng thái lưu, bảy trạng thái hiển thị.** `InstalmentStatus` (lưu) chỉ mang sự thật **không phụ thuộc hôm nay**: `pending`, `paid`, `waived`, `cancelled`. Cái người dùng thấy là `InstalmentState` (suy ra, **một chỗ duy nhất** — `Instalment::state()`): `scheduled`, `due`, `overdue`, `partially_paid`, `paid`, `waived`, `cancelled`. Docblock của cả hai nói rõ cái nào lưu, cái nào suy ra, và vì sao.

### `payments` — khoản thu

| Cột | Kiểu | Ghi chú |
|---|---|---|
| instalment_id | FK instalments | Một khoản thu thuộc **một** đợt |
| amount | unsignedBigInteger | **Đồng**, > 0, ≤ `Money::MAX` |
| paid_on | date | Ngày tiền thật sự về. **Không được ở tương lai** (so theo NGÀY ở múi giờ ứng dụng, như `TransitionMatterStage` bước 3) |
| method | enum `PaymentMethod` | `bank_transfer`, `cash`, `card`, `offset` (cấn trừ), `other` |
| reference | string(100) nullable | Mã giao dịch, số biên lai |
| receipt_document_id | FK documents nullable | Bản scan uỷ nhiệm chi / phiếu thu. **Luôn nhóm D** (không vào gói bàn giao M7 R8, không lên cổng) |
| attributed_lawyer_id | FK users | **P2.** Luật sư phụ trách của vụ **tại lúc ghi**, đọc từ hàng `matters` đang khoá trong cùng transaction. Không bao giờ cập nhật sau đó, kể cả khi bàn giao |
| note | text nullable | **Nội bộ** |
| voided_at / voided_by / void_reason | timestamp, FK users, text — đều nullable | Lý do bắt buộc ≥ 20 ký tự `mb_strlen` khi huỷ. **Nội bộ** |

**Không `deleted_at`, và hook `deleting` từ chối mọi lần xoá.** `created_by` (qua `HasBlameable` + `blameOn($actor)`) là câu trả lời cho "ai ghi khoản này" — **không** thêm cột `recorded_by`. `attributed_lawyer_id` trả lời một câu hỏi **khác** ("doanh thu của ai"), nên không trùng.

Index: `(instalment_id)`, `(paid_on)`, `(voided_at)`, `(attributed_lawyer_id, paid_on)`.

### `contract_amendments` — phụ lục hợp đồng

Chỉ thêm, không sửa, không xoá — model guard, tiền lệ `StageLog` / `StageLogImmutable`.

| Cột | Kiểu |
|---|---|
| contract_id | FK contracts |
| sequence | unsignedSmallInteger, unique cặp `(contract_id, sequence)` |
| previous_total_amount / new_total_amount | unsignedBigInteger |
| reason | text, bắt buộc ≥ 20 ký tự `mb_strlen`. **Nội bộ** |
| signed_at | date |
| document_id | FK documents nullable — bản scan phụ lục, **nhóm D** |

### `time_entries` — chỉ dựng khung, xem Task 12

`matter_id`, `user_id`, `worked_on` date, `minutes` unsignedSmallInteger, `description` text, `is_billable` boolean, `hourly_rate` unsignedBigInteger nullable (đồng/giờ), `invoiced_at` timestamp nullable. **Không Action, không màn hình, không con số nào trên dashboard đọc bảng này ở M9.**

### Quan hệ thêm vào model đã có

- `Matter::contract(): HasOne`; `Matter::timeEntries(): HasMany` ở Task 12 (đóng ghi chú M1).
- `Document::paymentReceipts()`, `Document::contractAmendments()` — để **`RetractDocument` (M7 Task 7) và `DocumentPolicy::delete`** từ chối rút/xoá một tệp đang được bản ghi tiền trỏ tới. (Không có Action `DeleteDocument`.)
- `StageLog::triggeredInstalments(): HasMany`.
- `User::attributedPayments(): HasMany` (P2).

### Cổng khách hàng: đóng kín ở Task 2, mở có chủ đích ở Task 10

Task 2: cả bốn model dùng `RestrictedToClientPortal` với `applyClientPortalConstraints()` trả **không gì cả** dưới guard `client`, policy từ chối mọi `ClientUser`, cột nội bộ nằm trong `HidesInternalAttributesFromPortal`. `PortalCoverageTest` (tự quét mọi model) xanh **không** thêm miễn trừ nào.

Task 10 (P1): nới đúng từng điều kiện ở cả ba tầng, cho đúng khách sở hữu vụ, kèm test chứng minh không gì khác mở theo. `TimeEntry` đóng kín mãi.

---

## Phát hiện quá hạn: tính lúc đọc, không phải một job ghi trạng thái

**Quyết định: tính lúc đọc.** `Instalment::state()` so `due_date` với `today()` và với số tiền còn lại. `scopeOverdue()` là cùng điều kiện viết bằng SQL, cho widget, trang "Công nợ" và tác vụ nhắc.

Tiền lệ: §4.13 + §6.8 dựng `deadlines` không có cột `is_overdue`; job hằng ngày chỉ **gửi** lời nhắc. Một job ghi `overdue` vào cột sẽ sai ngay khi ai đó sửa một ngày đến hạn, và tạo nguồn sự thật thứ hai.

**Hai tác vụ hằng ngày của M9, cả hai là Action trong `app/Actions/Schedule/`:**

- `ReconcileStageTriggeredInstalments` — lưới an toàn cho đợt theo giai đoạn (Task 6).
- `RemindOverdueInstalments` — thư nhắc nội bộ cho đợt quá hạn (Task 11, P3). Chống trùng qua `outbound_messages`.

---

## Tiền trên một vụ việc đã đóng, đã lưu trữ, đã bàn giao, đã xoá mềm

**Vụ việc đã kết thúc (`closed_at` khác null, M6.5 R8 — tức ngoài `Matter::scopeOpen()`) mà còn công nợ → KHÔNG chặn, nhưng phải nhìn thấy.** Chặn việc đóng hồ sơ vì còn tiền sẽ dạy luật sư đừng đánh dấu hồ sơ đã kết thúc, và SLA §6.4, widget §7.1, lưu trữ M7 đều đọc giai đoạn. Thay vào đó: widget **"Hồ sơ đã kết thúc còn công nợ"** trên trang doanh thu, một bộ lọc trên trang "Công nợ", và một dải cảnh báo trên tab tiền của vụ. Nợ còn được tính cho tới khi thu xong hoặc **miễn tường minh** (`waived`, có lý do và quyền). Dùng `closed_at`, **không** tự suy từ `is_terminal`: dự án có đúng một định nghĩa "đang mở".

**Vụ việc bị xoá mềm mà còn công nợ → CHẶN.** Ở kiểm tra xung đột, vụ xoá mềm **vẫn** được đối chiếu. Với tiền thì ngược chiều: truy vấn doanh thu bỏ qua vụ xoá mềm, nên xoá mềm thành cách làm bốc hơi một khoản nợ. Nên `MatterHasOutstandingBalance` được ném ở **cả hai** chỗ: hook `Matter::deleting` **và** bên trong `CancelMatter` (M6.5 Task 5, admin huỷ vụ mở nhầm), khi còn hợp đồng `active` với số dư > 0. Đường đi qua: huỷ (qua phụ lục) hoặc miễn các đợt còn lại, **kèm lý do**, rồi mới xoá. Thông điệp nêu số tiền và số đợt còn lại (tiền lệ §6.11).

**Khách hàng bị xoá mềm mà còn công nợ → CHẶN.** M6.5 Task 2 chỉ chặn xoá khách còn vụ **đang mở**. Một khách có vụ đã kết thúc còn nợ sẽ lọt qua, và màn hình tiền hiện khách rỗng. Mở rộng đúng kiểm tra đó (không viết kiểm tra thứ hai): còn hợp đồng `active` có dư nợ thì từ chối, thông điệp nêu số tiền.

**Bàn giao (`ReassignMatter`) → tiền không đổi chủ** (P2): khoản đã thu giữ `attributed_lawyer_id` cũ; còn phải thu đi theo luật sư phụ trách mới.

**Lưu trữ và hết hạn tra cứu (`client_access_until`, `ExpireClientAccess`, M7) → không đổi sổ tiền.** Vụ biến mất khỏi portal thì khối hợp đồng trên cổng cũng biến mất (Task 10 kế thừa ranh giới cổng của vụ), nhưng sổ tiền trong admin còn nguyên. Ghi vào docblock của scope tiền.

---

## Trang doanh thu — lập luận đã chốt, chép vào kế hoạch để nó không bị "cải tiến"

Chủ văn phòng hỏi biểu đồ tròn. Biểu đồ tròn đúng **chỉ khi** có một tổng thể và vài phần. Vậy:

| Câu hỏi | Dạng biểu đồ | Vì sao |
|---|---|---|
| **Đã thu / còn phải thu / quá hạn** | **Vành khuyên (donut)** | Nơi duy nhất biểu đồ tròn xứng đáng: một tổng thể, ba phần, cộng lại 100% |
| Doanh thu theo thời gian | **Cột** (tháng / quý / năm) | Thời gian là một trục |
| Cơ cấu vụ việc theo **12 lĩnh vực hành nghề** | **Cột ngang xếp hạng** | Biểu đồ tròn 12 lát không đọc được. Xếp theo giá trị giảm dần |
| Doanh thu đã thu theo đợt/giai đoạn | **Cột ngang** theo thứ tự giai đoạn | Xem `RevenueByStageWidget` |
| Tải theo luật sư | **Cột ngang** | |

(`MattersByStageWidget` của trang chủ §7.1 không làm lại ở đây.)

**Bộ lọc:** khoảng thời gian (từ / đến + phím tắt tháng này, quý này, năm nay), luật sư, lĩnh vực, và công tắc **số vụ / số tiền**. `HasFiltersForm` trên trang + `InteractsWithPageFilters` trên từng widget. Công tắc **đổi thứ được đo**, không thêm trục thứ hai.

> **KHÔNG làm ô cho người dùng tự đổi kiểu biểu đồ.** Một ô chọn kiểu biểu đồ cho phép người dùng vẽ ra một biểu đồ sai — 12 lĩnh vực thành một hình tròn, một chuỗi thời gian thành một hình tròn — **rồi tin nó**. Câu này phải nằm trong docblock của trang.

**Khoảng thời gian lọc cái gì** — mỗi widget **in nghĩa của mình lên chính nó bằng tiếng Việt**:

- **Cột doanh thu** lọc theo `payments.paid_on` — *"tiền về trong kỳ"*.
- **Donut** và **cơ cấu lĩnh vực** lọc theo `contracts.signed_at` — *"việc đã ký trong kỳ"* — và đã thu / còn phải thu / **quá hạn** tính **tại hôm nay**.

**Bộ lọc luật sư có hai nghĩa, và widget in ra nghĩa của mình** (P2): tiền **đã thu** lọc theo `payments.attributed_lawyer_id` ("luật sư phụ trách lúc thu"); tiền **còn phải thu / quá hạn** và số vụ lọc theo `matters.lead_lawyer_id` ("luật sư phụ trách hiện tại").

**Phân quyền của trang, dùng đúng một định nghĩa** (P3). Trang mở cho ai có `billing.view` (`canAccess()` + `Gate::forUser()` + `abort(404)`). **Mọi** widget lấy tập vụ việc gốc từ `Matter::scopeListableBy($user)`: luật sư thấy vụ của mình (kể cả vụ `restricted` mình phụ trách); kế toán và quản lý thấy mọi vụ **thường**, không thấy vụ `restricted`; admin thấy tất cả. Hai widget **so sánh toàn văn phòng** (tải theo luật sư, cơ cấu lĩnh vực) đòi thêm `revenue.viewAny`.

**Định dạng tiền một chỗ:** `App\Support\Billing\Money::format(int $dong): string` → `1.250.000 ₫`, và `Money::parse(string): int` nhận `"1.250.000"` → `1250000` (dấu chấm là phân cách nghìn, không phải thập phân). Tooltip Chart.js: xem Task 9 — phụ thuộc phán quyết CSP của M8 R4, và **không** được viết bộ định dạng tiền thứ hai bằng JS.

---

## Hệ thống màu và quy cách biểu đồ — đã kiểm chứng bằng máy, ngày 2026-09-22

**Không chọn màu bằng mắt.** Bộ màu dưới đây đã chạy qua bộ kiểm sáu phép (dải độ sáng, sàn độ bão hoà, tách biệt cho người mù màu, sàn cho mắt thường, tương phản trên nền) **ở cả hai nền: trắng `#ffffff` và nền tối `#18181b`**, nên widget **không cần đổi màu theo chế độ**, chỉ đổi màu chữ và lưới. Đây là màu **dữ liệu** truyền vào Chart.js; chữ và khung vẫn theo biến CSS của Filament.

**Bộ ba của biểu đồ vành khuyên** (đã thu / còn phải thu / quá hạn):

| Phần | Mã màu | Vai trò |
|---|---|---|
| Đã thu | `#0ca30c` | trạng thái tốt |
| Còn phải thu, chưa tới hạn | `#4a73bd` | trùng bậc 500 của dải màu **cổng khách** (`config/vkcrm.php` `primary_ramp.500`). Panel admin dùng `primary = Slate`, nên trên admin đây là màu dữ liệu, không phải màu thương hiệu |
| Quá hạn | `#d03b3b` | trạng thái nghiêm trọng. Gần nhưng **không** bằng `danger` của admin (`#c6283d`, đỏ thương hiệu); giữ `#d03b3b` vì bộ số đo dưới đây đo trên nó |

Số đo: tách biệt mù màu ΔE 19.9 ở cặp xấu nhất, mắt thường 27.6, cả ba vượt tương phản 3:1 trên **cả hai** nền. Chỉ có một màu xanh dương duy nhất trong toàn trang, cố ý.

**Cột một chuỗi dùng đúng một màu** `#4a73bd`. Một chuỗi thì **không có chú giải**.

**Luật bắt buộc, chép vào docblock của trang:**

- **Không bao giờ hai trục y.**
- **Màu đi theo thực thể, không đi theo thứ hạng.** Lọc bớt một lĩnh vực thì các lĩnh vực còn lại **không đổi màu**.
- **Một sắc cho thang liên tục, hai sắc cộng một xám ở giữa cho thang hai cực. Không bao giờ cầu vồng.**
- **Chú giải luôn có khi từ hai chuỗi trở lên**, và lát vành khuyên có **nhãn trực tiếp ghi số** (cặp đỏ–xanh nằm sát dải cảnh báo tritan).
- **Chữ mặc màu chữ, không mặc màu chuỗi.**
- **Bốn màu trạng thái là của riêng trạng thái**, luôn đi kèm biểu tượng và chữ.
- **Luôn có một bảng số** đi kèm mỗi biểu đồ, mở ra được, định dạng qua `Money::format()` ở phía PHP.
- **Khoảng 2px nền giữa các mảng liền nhau**, đầu cột bo 4px, điểm đánh dấu tối thiểu 8px.

**`RevenueByStageWidget`** — doanh thu đã thu theo từng đợt/giai đoạn. Cột ngang xếp theo thứ tự giai đoạn của loại vụ việc (thứ tự thời gian chính là thông tin), mỗi cột là tổng tiền thực nhận của các đợt gắn vào giai đoạn đó. Trả lời: *văn phòng đang kẹt tiền ở khúc nào của quy trình.*

---

## 12 lĩnh vực hành nghề — thuộc kế hoạch này, và là Task 1

Seeder hiện có **sáu** loại vụ việc; văn phòng hành nghề **mười hai** lĩnh vực (lấy từ luatvukhang.com). Biểu đồ đinh của trang doanh thu là *cơ cấu vụ việc theo lĩnh vực*, và một biểu đồ hiện 6 trên 12 lĩnh vực kể cho chủ văn phòng một câu chuyện sai. Task **riêng** và **đầu tiên**, vì nó đụng dữ liệu cấu hình mà fixture của mọi test khác ngồi lên.

| Lĩnh vực của văn phòng | Trạng thái |
|---|---|
| Đất đai và bất động sản | có — `DD` (đổi **tên**) |
| Đầu tư và doanh nghiệp | có — `DN` (đổi **tên**) |
| Giải quyết tranh chấp | có — `DS` (đổi **tên**) |
| Hình sự | có — `HS` |
| Hôn nhân và gia đình | có — `HN` |
| Lao động và nhân sự | có — `LD` (đổi **tên**) |
| Hành chính và giấy phép | **thiếu** — `HC` |
| Hợp đồng và thương mại | **thiếu** — `TM` |
| Ngân hàng và tín dụng | **thiếu** — `NH` |
| Sở hữu trí tuệ và công nghệ | **thiếu** — `SH` |
| Thuế và tài chính | **thiếu** — `TC` |
| Xây dựng và hạ tầng | **thiếu** — `XD` |

**Đổi `name` thì được, đổi `code` thì KHÔNG** (§6.1; `matters.code` nhúng mã loại `VK-2026-DD-0147`).

**Chạy trên production đã có dữ liệu.** `MatterTypeSeeder` hôm nay dùng `updateOrCreate(['code'], ['name', 'sort_order'])`: chạy lại là ghi đè tên và thứ tự admin đã sửa trong app. Task 1 đổi thành "tạo nếu chưa có, không bao giờ ghi đè", và bốn lần đổi tên đi bằng **migration dữ liệu một lần**, chỉ đổi khi tên hiện tại vẫn **đúng bằng** tên seed cũ.

**Bộ giai đoạn cho sáu loại mới là kiến thức tố tụng.** Sổ controller đã quyết: bộ năm giai đoạn chung, **đánh dấu tạm**, không công bố ra cổng, cho tới khi chủ văn phòng mô tả quy trình thật. **Bẫy:** `StagePresets::for()` có `default => self::civil()` (`app/Support/StagePresets.php`), nên chỉ thêm sáu mã vào seeder sẽ lặng lẽ cấp cho chúng **bộ tố tụng dân sự đầy đủ** (nộp đơn, toà thụ lý, phúc thẩm…), không phải bộ tạm. Phải có nhánh tường minh.

---

## Việc bắt buộc mang sang từ rà soát M2–M8

| Việc | Nguồn | Task |
|---|---|---|
| `MattersByStageWidget` gộp theo nhãn: **đã vá ở M4** (`MattersByStageWidget.php`, gộp theo `(matter_type_id, stage)`). Với 12 loại, "Tiếp nhận" có ở cả 12 | Rà soát M3 → M4 | **1** (chỉ thêm test hồi quy hai loại trùng nhãn) |
| `TransitionMatterStage` nhận `DateTimeInterface\|string` rồi `Carbon::parse` — lỗi I6: chuỗi hỏng ném `InvalidFormatException`, ngoài hợp đồng `DomainException`, thành 500. Vẫn còn; M6.5 Task 10 không nhận | Ledger M4 | **6** (nếu M6.5/M7 đã sửa thì chỉ xác nhận bằng test) |
| `MatterChecklistItem` chưa dùng `LogsActivity` | Ledger M4 | — (không thuộc M9; ghi lại) |
| `DocumentPolicy::publish` / `::delete` cần probe lại | Ledger M4 | 13 nhặt nếu còn rơi |
| `MatterPolicy::view` chạy một EXISTS mỗi lần gọi | Rà soát M2 Task 5 | **8**, **9** (dùng `listableBy` ở tầng truy vấn, đếm truy vấn) |
| **404 chứ không 403**, không map exception sang mã HTTP riêng | M4 | **7**, **8**, **9**, **10** |
| False-green `PersistentMiddleware` | M4 | mọi task có màn hình |
| Chuỗi tiếng Việt đếm bằng `mb_strlen` | M3 | **4**, **5** |
| `LogsActivity` trên model có `SoftDeletes` ghi giá trị cũ dưới khoá `old` | M4 | **13** |
| `expect(fn () => ...)->not->toThrow(Throwable::class)` là test **không thể đỏ**. Còn ở `tests/Feature/Portal/LoginTest.php:1304` | Ledger M4 | mọi task (đừng viết thêm) |

---

## Câu hỏi cho chủ văn phòng

**Sáu câu cũ đã có câu trả lời** (sổ controller; P6). Mỗi câu trả lời **chủ văn phòng đảo được**:

| # | Câu hỏi | Trả lời | Task |
|---|---|---|---|
| 1 | Bộ giai đoạn cho sáu lĩnh vực mới | Bộ năm giai đoạn chung (`intake → collecting_documents → drafting → in_progress → closed`), **đánh dấu tạm**, không loại mới nào bật `is_published_to_portal` trong dữ liệu mẫu, cho tới khi chủ văn phòng mô tả quy trình thật | 1 |
| 2 | Khách có xem hợp đồng và lịch thu trên cổng không | **Có** (P1) | 2, 10 |
| 3 | Doanh thu ghi cho ai sau bàn giao | **Luật sư phụ trách lúc tiền về**, lưu trên dòng khoản thu (P2) | 2, 5, 9 |
| 4 | Ai xoá được hợp đồng `draft` | Người có `contract.manage`, chỉ khi còn `draft` **và chưa có khoản thu nào** | 3, 4 |
| 5 | Có cần người thứ hai duyệt giá trị hợp đồng | **Không** | 4 |
| 6 | Có dựng khung `time_entries` | **Có**, task cuối, xoá được | 12 |

**Còn mở, không chặn task nào:**
- Bộ giai đoạn thật cho sáu lĩnh vực mới (câu 1).
- Nơi lưu bằng chứng khách **đồng ý cho dùng AI** (điều khoản riêng trong hợp đồng dịch vụ, `docs/research/2026-09-24-mcp-phap-ly-goi.md:372`). M11 làm trước và lưu nó ở cờ `matters.ai_access` kèm ô tích "Khách đã đồng ý bằng văn bản" (kế hoạch M11 R9). M9 **không** thêm cột thứ hai; tab hợp đồng chỉ hiện trạng thái cờ đó, chỉ đọc. Nếu chủ văn phòng muốn bằng chứng gắn với bản ghi hợp đồng (phiên bản điều khoản, bản scan), đó là một đính chính cho M11, không phải một cột của M9.
- Đầu mục danh mục "✱ Hợp đồng dịch vụ pháp lý và giấy uỷ quyền" (SPEC §4.9) gộp **hai** giấy tờ trong một đầu mục. Có tách làm hai không? (xem Task 7).

---

## Cấu trúc tệp (trạng thái cuối M9)

| Đường dẫn | Trách nhiệm |
|---|---|
| `database/migrations/*_create_{contracts,instalments,payments,contract_amendments}_table.php` | **Bốn** migration ở Task 2; `time_entries` ở Task 12 |
| `database/migrations/*_rename_matter_types_to_office_names.php` | Migration dữ liệu một lần (Task 1) |
| `app/Models/{Contract,Instalment,Payment,ContractAmendment,TimeEntry}.php` | Guard bất biến / chống xoá ở model |
| `app/Enums/{ContractStatus,BillingModel,InstalmentTrigger,InstalmentStatus,InstalmentState,PaymentMethod}.php` | Backed string, có `label()` |
| `app/Policies/{Contract,Instalment,Payment,ContractAmendment,TimeEntry}Policy.php` | |
| `app/Actions/Billing/{DraftContract,ActivateContract,AmendContract,CancelContract,CompleteContract}.php` | |
| `app/Actions/Billing/{RecordPayment,VoidPayment,WaiveInstalment}.php` | Huỷ một đợt của hợp đồng `active` chỉ đi qua `AmendContract` |
| `app/Actions/Billing/TriggerInstalmentsForStage.php` | Đường **duy nhất** một đợt theo giai đoạn được kích hoạt |
| `app/Actions/Schedule/{ReconcileStageTriggeredInstalments,RemindOverdueInstalments}.php` + `routes/console.php` | Hai tác vụ hằng ngày |
| `app/Actions/Notification/ResolveStaffRecipients.php` | Thêm cổng "được xem tiền của vụ" (Task 11) |
| `app/Support/Billing/{Money,Vat,SplitByPercent,BillingSummary,AccountantBillingRow}.php` | `AccountantBillingRow` là DTO readonly giới hạn thông tin, tiền lệ `ConflictMatch` |
| sự kiện đổi giai đoạn (dùng lại của M7 nếu có) + `app/Listeners/ReleaseStageTriggeredInstalments.php` | |
| `app/Console/Commands/CheckBillingInvariants.php` | `billing:check-invariants` |
| `app/Exceptions/{ContractTotalMismatch,ContractNotAmendable,InstalmentNotPayable,PaymentExceedsInstalment,MatterHasOutstandingBalance,BillingModelNotSupported}.php` | |
| `app/Filament/Admin/Resources/Matters/RelationManagers/BillingRelationManager.php` | Tab "Hợp đồng và thanh toán" trên trang vụ việc |
| `app/Filament/Admin/Pages/Receivables.php` | Trang "Công nợ" của kế toán |
| `app/Filament/Admin/Pages/RevenueDashboard.php` | Trang doanh thu, `HasFiltersForm` |
| `app/Filament/Admin/Widgets/Revenue/{ReceivablesDonut,RevenueOverTime,RevenueByStage,MatterMixByPracticeArea,LoadPerLawyer,ClosedWithBalance}Widget.php` | **Sáu** widget, đều `$isDiscovered = false` |
| `app/Filament/Portal/Pages/MatterProgress.php` + view | Khối "Hợp đồng và thanh toán" trên cổng (Task 10) |
| thư `staff.instalment_overdue` theo khuôn thư M6 | Task 11 |
| `lang/vi/billing.php`, bổ sung `lang/vi/{enums,permissions,widgets,exceptions,portal,mail}.php` | |
| `database/seeders/{MatterTypeSeeder,ChecklistTemplateSeeder}.php` (trong `ReferenceDataSeeder`), `database/seeders/BillingSeeder.php` (trong `DemoDataSeeder`), `app/Support/StagePresets.php` | |
| `tests/Feature/Actions/Billing/*`, `tests/Feature/Authorization/BillingAccessTest.php`, `tests/Feature/Filament/{BillingRelationManager,Receivables,RevenueDashboard}Test.php`, `tests/Feature/Portal/BillingOnPortalTest.php` | |

---

### Task 1: Mười hai lĩnh vực hành nghề

**Files:** `database/seeders/MatterTypeSeeder.php`, `database/migrations/*_rename_matter_types_to_office_names.php`, `app/Support/StagePresets.php`, `database/seeders/ChecklistTemplateSeeder.php`, `lang/vi/matter_types.php`, `docs/SPEC.md` (§12), `tests/Feature/Seeders/MatterTypeSeederTest.php`, `tests/Feature/Filament/MattersByStageWidgetTest.php`

**Interfaces:** Produces — mười hai `matter_types` đang hoạt động, mỗi loại có bộ giai đoạn và ít nhất một `checklist_template`. Consumes — `ReferenceDataSeeder` (M6.5 Task 19).

0. **Dò trước** (xem "Vị trí trong thứ tự dựng"): grep các tên M6.5/M7/M8/M11 mà M9 dùng lại, dán kết quả vào báo cáo.
1. Sáu loại mới: `HC`, `TM`, `NH`, `SH`, `TC`, `XD`. `MatterTypeSeeder` chuyển sang **tạo nếu chưa có, không ghi đè** (`firstOrCreate` theo `code`), nằm trong `ReferenceDataSeeder`. Bốn loại cũ **đổi tên** bằng migration dữ liệu một lần, chỉ khi tên hiện tại đúng bằng tên seed cũ; `code` không đổi một chữ nào.
2. **Bộ giai đoạn tạm** (câu 1): thêm vào `StagePresets::for()` **nhánh tường minh** cho `HC`/`TM`/`NH`/`SH`/`TC`/`XD` trỏ tới bộ năm giai đoạn tạm. Đánh dấu **tạm** trong `description`, ghi vào PROGRESS. Mọi `client_description` ≥ 30 ký tự (test của M6.5 Task 10 lặp qua mọi loại đã seed phải còn xanh). Không loại mới nào có `is_published_to_portal = true` trong dữ liệu mẫu.
3. `checklist_templates`: mỗi loại mới ít nhất một template tối thiểu, để `ApplyChecklistTemplate` không sinh vụ việc không có danh mục.
4. **`MattersByStageWidget`**: đã vá ở M4. Chỉ thêm test hồi quy hai loại trùng nhãn.
5. **Đính chính SPEC §12**: "6 `matter_types`" thành 12, kèm ngày và lý do.

**Test bắt buộc:** đúng 12 loại hoạt động sau `db:seed`; `code` của bốn loại đổi tên giữ nguyên; chạy seeder lần hai sau khi admin đổi tên một loại: tên admin **còn nguyên**; migration đổi tên không đụng một loại đã bị admin đổi tên; sáu loại mới nhận bộ **năm** giai đoạn tạm, không phải bộ dân sự (mutation probe: xoá nhánh tường minh phải làm test đỏ); mỗi loại có ≥ 1 giai đoạn và đúng một giai đoạn `is_terminal`; mỗi loại có ≥ 1 checklist template; `MattersByStageWidget` không gộp hai loại có nhãn trùng.

- [x] Test đỏ, cài đặt, test xanh, pint, **vòng MariaDB** (có migration dữ liệu), commit `feat: mười hai lĩnh vực hành nghề của văn phòng, không phải sáu`.

---

### Task 2: Bảng, model, enum, factory cho hợp đồng và thu phí

**Files:** **bốn** migration, `app/Models/{Contract,Instalment,Payment,ContractAmendment}.php`, sáu enum, `database/factories/*`, quan hệ mới trên `Matter`/`Document`/`StageLog`/`User`, `app/Providers/AppServiceProvider.php` (alias morph), `lang/vi/enums.php`, `tests/Feature/Models/Billing/*`

**Interfaces:** Produces — bốn model với quan hệ, sáu enum có `label()`, factory cho cả bốn. **Không nghiệp vụ, không Action.** Consumes — `HasBlameable`, `RestrictedToClientPortal`, `HidesInternalAttributesFromPortal`, `ClientPortalScope`.

Toàn bộ cột và index theo "Mô hình dữ liệu". Điểm dễ sai:

- **`contracts.matter_id` UNIQUE thật**, và `contracts` **không có `deleted_at`**. Deviation so với §4; ghi lý do vào docblock model **và** PROGRESS.
- **`payments.attributed_lawyer_id`** (P2) có ngay ở migration này. Không nullable: mọi vụ có luật sư phụ trách.
- **Không có cột `instalments.reminders_sent`** (M6 R3).
- **Hook `deleting` trên cả bốn model.** `Contract`: chỉ khi `draft` và chưa có khoản thu. `Payment`, `ContractAmendment`: không bao giờ. `Instalment`: chỉ khi hợp đồng còn `draft`. Hook không hỏi actor và không quyết định gì — nó chỉ làm Action thành đường duy nhất (tiền lệ `Document::saving`, `Matter::forceDeleting`).
- **`ContractAmendment` chỉ-thêm**, như `StageLog` / `StageLogImmutable`. Cập nhật bằng query builder đi vòng qua guard; ghi vào docblock.
- **Cả bốn model đóng kín ở portal** (sẽ mở ở Task 10). `PortalCoverageTest` tự quét mọi model, nên xanh mà **không** thêm miễn trừ nào. Mỗi model một test: dưới guard `client` truy vấn trả rỗng, kèm mutation probe gỡ trait.
- **Alias morph** `contract`, `instalment`, `payment`, `contract_amendment`. Test: `Audit::record(..., $contract)` không ném.
- Cast `'total_amount' => 'integer'`, `'amount' => 'integer'`. Ghi một câu trong docblock về `unsignedBigInteger` và `PHP_INT_MAX`.

**Bắt buộc trên MariaDB thật:** `migrate:fresh --seed`, rồi `migrate:reset` → `migrate`, **dán nguyên văn output**. Kiểm tay mỗi `down()` đảo được.

- [ ] Test đỏ, cài đặt, test xanh, pint, **round-trip MariaDB dán vào báo cáo**, commit `feat: bảng hợp đồng, đợt thanh toán, khoản thu và phụ lục`.

---

### Task 3: Bốn quyền mới, policy, và đính chính SPEC — **giao Opus**

**Files:** `app/Enums/Permission.php`, `app/Enums/Role.php` (`permissions()` — ma trận vai trò → quyền nằm ở đây; `RolesAndPermissionsSeeder` chỉ đồng bộ từ nó, admin tự có `Permission::cases()`), bốn policy tiền, `app/Support/Billing/AccountantBillingRow.php`, `docs/SPEC.md`, `lang/vi/permissions.php`, bảng R4 của kế hoạch M11 (chỉ ghi đính chính) và một test cấu trúc MCP, `tests/Feature/Authorization/BillingAccessTest.php`

`app/Enums/Permission.php` nói *"Đúng 13 quyền ở SPEC §5"*. M9 thêm bằng **đính chính có ngày**, theo khuôn "Đính chính 2026-09-16" dưới §6.10 — **không** thêm lặng lẽ vào enum. Sửa docblock thành 17.

**Bốn quyền mới, nâng bảng lên 17:** `billing.view`, `contract.manage`, `payment.record`, `revenue.viewAny`.

**Văn bản đính chính — chép nguyên văn vào `docs/SPEC.md`, ngay dưới ma trận quyền ở §5:**

> **Bổ sung 2026-09-19, sửa 2026-09-24 (M9 — hợp đồng dịch vụ và thu phí theo đợt).** Danh sách 13 quyền ở trên được viết cho phạm vi bản 1.0, vốn **không có tiền** — §1 xếp "hợp đồng dịch vụ và đợt thanh toán, công nợ" vào phần ngoài phạm vi. M9 đưa chúng vào hệ thống, và không quyền nào trong 13 quyền trên diễn tả được chúng: `matter.view` là quyền đọc **nội dung hồ sơ**, còn tiền là một trục riêng — kế toán phải thấy tiền trong khi vẫn **không** được thấy nội dung, còn luật sư phải thấy tiền của vụ mình mà **không** thấy doanh thu toàn văn phòng. Thêm **bốn** quyền:
>
> | Quyền | admin | manager | lawyer | assistant | accountant |
> |---|---|---|---|---|---|
> | `billing.view` (hợp đồng, đợt thanh toán và khoản thu của một vụ việc) | ✓ | ✓ | ✓ (vụ của mình) | — | ✓ (**không kèm nội dung hồ sơ**) |
> | `contract.manage` (soạn, kích hoạt, ký phụ lục, huỷ hợp đồng) | ✓ | ✓ | ✓ (vụ của mình) | — | — |
> | `payment.record` (ghi nhận và huỷ một khoản thu) | ✓ | — | — (trừ vụ `restricted`, xem dưới) | — | ✓ |
> | `revenue.viewAny` (số liệu doanh thu toàn văn phòng, trang "Công nợ") | ✓ | ✓ | — | — | ✓ |
>
> **Ai thấy tiền của vụ nào: một định nghĩa.** Có `billing.view` **và** vụ nằm trong danh sách người đó được liệt kê (`Matter::listableBy`, cùng định nghĩa với danh sách vụ việc). Vì vậy tiền của vụ `restricted` (§4.6: "chỉ lead lawyer và quản trị thấy") chỉ luật sư phụ trách và admin thấy; kế toán và quản lý không thấy, kể cả trong số liệu tổng hợp. Trên vụ `restricted`, luật sư phụ trách ghi được khoản thu dù không có `payment.record`, vì ngoài admin không ai khác thấy vụ đó.
>
> Cặp `billing.view` / `revenue.viewAny` lặp lại đúng cặp `matter.view` / `matter.viewAny`.
>
> **Ranh giới của kế toán, viết ra vì đây là một sự nới rộng.** `billing.view` **không** làm câu "kế toán chỉ xem danh sách vụ việc, không thấy nội dung hồ sơ" sai đi: màn hình tiền của kế toán mang mã hồ sơ, loại vụ việc, tên khách hàng, tên đợt, các con số và các ngày — **không** mang tiêu đề vụ việc, tóm tắt, mô tả nội bộ, tài liệu, tiến độ hay các bên. Ranh giới này cài bằng một DTO readonly như `ConflictMatch` ở §6.10, có test. Điểm **mới thật sự** là **tên khách hàng**: không có tên thì không lập được phiếu thu — một sự nới rộng có chủ đích, cũng là một mục đích xử lý dữ liệu mới cần ghi vào PROGRESS.
>
> **`contract.manage` cũng là quyền đổi số tiền của từng đợt** qua phụ lục, kèm lý do, có dấu vết.

**Các đính chính SPEC khác trong cùng commit** (mỗi mục kèm ngày 2026-09-24):
- **§1**: bỏ "hợp đồng dịch vụ và đợt thanh toán, công nợ" khỏi danh sách ngoài phạm vi (giữ "QR VietQR", "Zalo ZNS", "ký số", …).
- **§4.6**: tiền của vụ `restricted` theo đúng quy tắc của nội dung (đoạn trên).
- **§6.8**: người nhận thư về tiền là người "được xem tiền của vụ" (Task 11).
- **§7.2**: thêm tab "Hợp đồng và thanh toán" (tab **thêm**; §7.2 liệt kê chín tab, M6.5 thêm "Đội ngũ").
- **§9**: thêm mẫu `staff.instalment_overdue` (Task 11).
- **§13**: thêm dòng **M9**.
- **§15**: đánh dấu `contracts`/`instalments` đã làm, `time_entries` còn ở dạng khung.
- Phần **Portal** của §5 và §8: Task 10 ghi (loại dữ liệu thứ tám khách được thấy).
- §12: Task 1 ghi.

**Policy — năm điểm khó:**

1. **Không hỏi `MatterPolicy::view`.** Kế toán không có `matter.view`. Điều kiện đọc tiền: `billing.view` **và** `$matter->isListableBy($user)` (bản trong bộ nhớ của `scopeListableBy`). **Nhân chứng bắt buộc là KẾ TOÁN**, kèm cặp khẳng định dương: chính tài khoản đó **đọc được** hợp đồng và **không đọc được** vụ việc.
2. **Vụ `restricted`** (P3): kế toán và quản lý **không** đọc được tiền; luật sư phụ trách và admin đọc được; thành viên đội ngũ không phải lead **không** đọc được. Ghim bằng test, mỗi vế một mutation probe.
3. **`PaymentPolicy::create(User, Instalment)`**: được xem tiền của vụ, **và** (vụ thường: `payment.record`; vụ `restricted`: admin hoặc luật sư phụ trách). Nhân chứng cho vế `payment.record` là **quản lý** (thấy tiền, không được ghi).
4. **DTO `AccountantBillingRow`**, readonly, đúng số trường ở đính chính. Test khẳng định tiêu đề vụ việc không lọt ra, chép đúng hình dạng test của `ConflictMatch`.
5. **Mutation probe bắt buộc.** Với mỗi vế quyền, nhân chứng phải là tài khoản **được cấp quyền trực tiếp** sao cho chỉ đúng vế đang thử là thứ chặn họ (bài học M4 `RegroupDocument`).

**MCP (P7).** Kế hoạch M11 (`docs/superpowers/plans/2026-09-24-m11-mcp.md`, R4) trả dữ liệu qua presenter theo **danh sách cho phép**, nên bốn model tiền mặc định không ra ngoài. M9 không mở chúng: thêm dòng "tiền của vụ việc — không bao giờ" vào bảng R4 của M11 (đính chính có ngày trong PROGRESS), và một test cấu trúc khẳng định không tool hay presenter nào trong `app/Mcp` / `app/Support/Mcp` tham chiếu `Contract`, `Instalment`, `Payment`, `ContractAmendment`. Tên thư mục thật lấy từ bước dò ở Task 1.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: bốn quyền cho tiền, và đính chính SPEC kèm ngày`.

---

### Task 4: Hợp đồng — soạn, kích hoạt, phụ lục, và bất biến tổng — **giao Opus**

**Files:** `app/Actions/Billing/{DraftContract,ActivateContract,AmendContract,CancelContract,CompleteContract}.php`, `app/Support/Billing/{SplitByPercent,Vat,Money}.php`, `app/Console/Commands/CheckBillingInvariants.php`, `app/Exceptions/{ContractTotalMismatch,ContractNotAmendable,BillingModelNotSupported}.php`, `lang/vi/billing.php`, tests

**Interfaces:** Produces — `DraftContract::handle(User $actor, Matter $matter, array $attributes, array $instalments): Contract`; `ActivateContract::handle(User $actor, Contract $contract, DateTimeInterface|string $signedAt): Contract`; `AmendContract::handle(User $actor, Contract $contract, int $newTotalAmount, array $instalmentChanges, string $reason, DateTimeInterface|string $signedAt, ?Document $document = null): ContractAmendment`; `Money::format()`, `Money::parse()`, `Money::MAX`. Actor tường minh, đứng đầu (quy ước cho `app/Actions/Billing/`, xem ràng buộc toàn cục). Consumes — `CodeSequence::next()`, `Audit::record()`, `Gate`.

**Bất biến, và bốn tầng giữ nó.** `SUM(instalments.amount) === contracts.total_amount`, số nguyên đồng, không dung sai.

1. **`ActivateContract`** — không khớp thì **không rời được `draft`**.
2. **Hook `saving`/`deleting` trên `Instalment`** — từ chối mọi lần ghi làm lệch tổng trên hợp đồng `active`. Thứ nó chặn **chính là** đường đi vòng qua Action.
3. **`AmendContract`** — đổi tổng mà không đổi lịch (hoặc ngược lại) phải thất bại; cả hai trong **một** transaction. **Huỷ một đợt của hợp đồng `active` chỉ đi qua đây** (không có Action `CancelInstalment` riêng): một đợt biến mất là tổng các đợt đổi, tức là phụ lục.
4. **`billing:check-invariants`** — quét mọi hợp đồng `active`, in hợp đồng lệch. Test với hợp đồng làm lệch bằng `DB::table()`.

**`SplitByPercent`:** mọi đợt trừ đợt cuối lấy `intdiv(total × p, 100)`; đợt cuối lấy phần còn lại. Màn hình hiện số đồng **trước khi** lưu. Test: 33/33/34 trên 100.000.000; chia ba đều trên 10.000.000 (3.333.333 / 3.333.333 / 3.333.334); `percent_basis` lưu nguyên nhưng **không bao giờ** dùng tính lại `amount` (mutation probe).

**`Vat`**: `tax = intdiv(total × r, 100 + r)`, phần chưa thuế nhận phần dư. Test 33.333.333 đ ở 10%.

**`Money`**: `format()` → `1.250.000 ₫`; `parse("1.250.000") === 1250000`, `parse("1250000") === 1250000`, `parse("1.25")` → `ValidationException` (không bao giờ hiểu là 1,25); vượt `Money::MAX` → `ValidationException`.

`DraftContract`: sinh `code` qua `CodeSequence`; từ chối `billing_model` khác `fixed_fee` bằng `BillingModelNotSupported` (thông điệp tiếng Việt). `on_signing` để `due_date` rỗng. `stage` đòi `trigger_stage_key` **là giai đoạn có thật của đúng loại vụ việc** (qua `MatterType::stage()`) **và không phải giai đoạn đầu** (`firstStage()`).

`ActivateContract`: đặt `signed_at`, `activated_by`, `status = active`; điền `due_date` cho mọi đợt `on_signing`; **gọi `TriggerInstalmentsForStage`** (Task 6) cho đợt mà vụ **đã** đi qua giai đoạn kích hoạt. (Task 6 chưa xong thì để điểm nối tường minh và một test `todo`, không cài bản sao logic.) **Không** tự đổi đầu mục danh mục nào (xem Task 7).

`AmendContract`: chỉ trên `active`; lý do ≥ 20 ký tự **`mb_strlen`**; `previous_total_amount` đọc từ hàng đang khoá (`lockForUpdate`), không từ đối tượng caller đưa vào.

Mọi Action ghi `Audit::record(..., $actor)` **bên trong** transaction (và nói thẳng: không mutation probe nào phân biệt được hai vị trí).

**Test bắt buộc:** tổng lệch 1 đồng → không kích hoạt được; ghi thẳng một `Instalment` lệch tổng trên hợp đồng `active` bằng model → bị từ chối; phần dư rơi vào đợt cuối (ba trường hợp); `percent_basis` không bao giờ tính lại `amount`; phụ lục lưu đúng giá trị cũ; phụ lục huỷ một đợt làm tổng mới khớp; phụ lục không lý do → `ValidationException`; lý do 19 ký tự tiếng Việt có dấu **bị từ chối**, 20 ký tự **được chấp nhận**; hợp đồng `draft` xoá được, `draft` có khoản thu hoặc `active` thì không; `billing_model = hourly` bị từ chối; `trigger_stage_key` là giai đoạn đầu bị từ chối; `Money::parse` ba trường hợp; `billing:check-invariants` tìm ra hợp đồng lệch.

- [ ] Test đỏ, cài đặt, test xanh, pint, **mutation probe cho từng điều kiện, dán bằng chứng đỏ**, commit `feat: hợp đồng dịch vụ với bất biến tổng các đợt`.

---

### Task 5: Khoản thu, miễn, huỷ, chặn xoá — và trạng thái suy ra — **giao Opus**

**Files:** `app/Actions/Billing/{RecordPayment,VoidPayment,WaiveInstalment}.php`, `app/Models/Instalment.php` (`state()`, `outstanding()`, `scopeOverdue()`), `app/Models/Matter.php` (hook `deleting`), `app/Actions/Matter/CancelMatter.php` (M6.5), kiểm tra xoá khách của M6.5 Task 2, `app/Support/Billing/BillingSummary.php`, `app/Exceptions/{InstalmentNotPayable,PaymentExceedsInstalment,MatterHasOutstandingBalance}.php`, tests

**Interfaces:** Produces — `RecordPayment::handle(User $actor, Instalment $instalment, int $amount, DateTimeInterface|string $paidOn, PaymentMethod $method, ?string $reference, ?Document $receipt, ?string $note): Payment`; `VoidPayment::handle(User $actor, Payment $payment, string $reason): Payment`; `WaiveInstalment::handle(User $actor, Instalment $instalment, string $reason): Instalment`; `Instalment::state()`, `::outstanding()`, `::scopeOverdue()`. Consumes — Task 3, 4.

**Điểm phải cài đúng:**

- **Cổng quyền** là `PaymentPolicy::create` của Task 3 (gồm vế vụ `restricted`), hỏi qua `Gate::forUser($actor)`.
- **Thu một phần là bình thường.** **Thu vượt** → `PaymentExceedsInstalment`, thông điệp nói phải làm gì. *Không* tự rải sang đợt sau.
- **`paid_on` không được ở tương lai**, so theo NGÀY ở múi giờ ứng dụng, chép đúng cách `TransitionMatterStage` bước 3.
- **`attributed_lawyer_id`** (P2) = `lead_lawyer_id` đọc từ hàng `matters` **khoá trong cùng transaction**, không từ đối tượng caller đưa vào.
- **`status = paid` khi tổng khoản thu chưa huỷ ≥ `amount`**, tính lại trong cùng transaction với `lockForUpdate` trên đợt.
- **`VoidPayment` hạ `status` về `pending`** khi tổng tụt dưới `amount`. Test riêng và mutation probe.
- **`Instalment::state()` là chỗ duy nhất** định nghĩa quá hạn; `scopeOverdue()` là **cùng điều kiện** bằng SQL. Test khẳng định hai cách cho cùng kết quả trên tập biên (đến hạn hôm nay = chưa quá hạn; đã miễn, đã huỷ = không quá hạn).
- **`WaiveInstalment`**: lý do ≥ 20 ký tự `mb_strlen`; **không** đổi `total_amount`. "Còn phải thu" trừ phần đã miễn (docblock `BillingSummary`).
- **Chặn xoá khi còn nợ** (xem "Tiền trên một vụ việc…"): `MatterHasOutstandingBalance` ném ở hook `Matter::deleting` **và** trong `CancelMatter`; xoá mềm khách còn hợp đồng `active` có dư nợ bị từ chối bằng cách mở rộng đúng kiểm tra của M6.5 Task 2.
- Mọi Action ghi `Audit::record` trong transaction với `$actor` tường minh và `blameOn($actor)` trước `save()`.

**Test bắt buộc:** thu một phần → `partially_paid`, `status` vẫn `pending`; thu đủ → `paid`; thu vượt → từ chối kèm thông điệp đọc được; huỷ làm tụt dưới đủ → `pending`, và `overdue` nếu ngày đã qua; huỷ không lý do → lỗi xác thực; `paid_on` ngày mai → lỗi xác thực; `state()` và `scopeOverdue()` khớp trên tập biên; khoản thu **không xoá được** (`delete()` lẫn `forceDelete()`); `created_by` là actor truyền vào **chứ không phải** người đang đăng nhập (đăng nhập một người, truyền người **khác**, như `RunConflictCheckActorTest`); `attributed_lawyer_id` là lead lúc ghi, và **không đổi** sau `ReassignMatter`; quản lý gọi `RecordPayment` → từ chối; luật sư phụ trách vụ `restricted` ghi được, kế toán trên vụ đó bị từ chối; **hai kế toán ghi hai khoản đồng thời trên cùng đợt** không vượt tổng và `status` đúng — chạy dưới `bin/dev test:mariadb`; xoá mềm vụ còn nợ → từ chối, qua **cả** hook lẫn `CancelMatter`; xoá mềm khách có vụ đã kết thúc còn nợ → từ chối.

- [ ] Test đỏ, cài đặt, test xanh, pint, `test:mariadb` tuần tự, **mutation probe từng điều kiện**, commit `feat: khoản thu, miễn và huỷ, với trạng thái suy ra thay vì lưu`.

---

### Task 6: Đợt thanh toán kích hoạt theo giai đoạn

**Files:** sự kiện đổi giai đoạn (dùng lại của M7 nếu có), `app/Listeners/ReleaseStageTriggeredInstalments.php`, `app/Actions/Billing/TriggerInstalmentsForStage.php`, `app/Actions/Schedule/ReconcileStageTriggeredInstalments.php`, `app/Actions/TransitionMatterStage.php` (**một dòng dispatch, nếu chưa có**, + sửa lỗi I6 nếu còn), `routes/console.php`, `app/Policies/MatterTypeStagePolicy.php` và `.../MatterTypes/RelationManagers/StagesRelationManager.php` (guard của M6.5 Task 19), `app/Providers/AppServiceProvider.php`, tests

**Interfaces:** Produces — `TriggerInstalmentsForStage::handle(Matter $matter, string $stageKey, StageLog $stageLog): int`. **Không có tham số `$actor`**: kích hoạt một đợt là hệ quả của một sự kiện, không phải quyết định của ai. `Audit::record` để `causer` rỗng và ghi `stage_log_id` làm nguồn gốc (tiền lệ `RunConflictCheck` với `actor_explicit`). Chữ ký thật của `TransitionMatterStage` là `handle(Matter, User $actor, …)`; task này không đổi nó.

Toàn bộ thiết kế ở mục "Đợt thanh toán theo giai đoạn". Đọc lại nguyên văn trước khi viết.

Trong cùng task:

- **Sự kiện:** grep trước. Có sự kiện đổi giai đoạn của M7 thì mở rộng và nghe nó; chưa có thì tạo và ghi vào PROGRESS để M7/M10 dùng chung. Dán kết quả grep.
- **Sửa lỗi I6** nếu còn (`TransitionMatterStage` parse `occurredAt`/`expectedNextUpdateAt` bằng `Carbon::parse`): parse tường minh, chuỗi hỏng → `ValidationException` trên đúng trường, chuỗi rỗng là "không có ngày". Tiền lệ `UploadStaffDocument::$issuedAt`.
- **Grep mọi nơi ghi `matters.stage`**; nơi nào đổi giai đoạn cũng phát cùng sự kiện. Dán kết quả.
- **Mở rộng guard của M6.5 Task 19:** chặn xoá hoặc đổi `key` của một giai đoạn khi còn đợt `pending`, chưa kích hoạt, của hợp đồng `draft` hoặc `active` trên vụ cùng loại, trỏ tới key đó. Thông điệp nêu số đợt. Không viết guard thứ hai.
- **Lịch:** `Schedule::call(new ReconcileStageTriggeredInstalments)` hằng ngày, `withoutOverlapping(<phút>)` có hạn, test ghim giờ như M6.5 Task 14.

**Test bắt buộc:** chuyển tới giai đoạn kích hoạt → đúng đợt đó có `due_date`, `triggered_at`, và `triggered_by_stage_log_id` trỏ đúng dòng vừa tạo; **một dòng cập nhật cùng giai đoạn (§6.3) KHÔNG kích hoạt gì** (test quan trọng nhất); vào lại lần hai **không** kích hoạt lại; hợp đồng `draft` không bị kích hoạt; `occurred_at` ghi lùi sinh đợt đã quá hạn; transaction rollback → listener **không** chạy (bọc trong transaction ngoài rồi ném lỗi); đối chiếu kích hoạt được một đợt **thêm sau khi** vụ đã qua giai đoạn đó; đối chiếu chạy hai lần chỉ kích hoạt một lần; đổi `key` của giai đoạn còn đợt `pending` trỏ tới → bị từ chối qua Livewire; chuỗi ngày hỏng ở form chuyển giai đoạn → lỗi trên trường, không 500.

- [ ] Test đỏ, cài đặt, test xanh, pint, mutation probe, commit `feat: đợt thanh toán tự đến hạn khi vụ việc chạm giai đoạn`.

---

### Task 7: Tab "Hợp đồng và thanh toán" trên trang vụ việc

**Files:** `app/Filament/Admin/Resources/Matters/RelationManagers/BillingRelationManager.php` + form/action classes, `app/Filament/Admin/Resources/Matters/Pages/ViewMatter.php`, `app/Filament/Admin/Resources/Matters/Tables/MattersTable.php` (một cột), `lang/vi/billing.php`, `tests/Feature/Filament/BillingRelationManagerTest.php`

Tab **thêm** vào §7.2 (chín tab của SPEC cộng "Đội ngũ" của M6.5; đính chính §7.2 ở Task 3). Người dùng tab: luật sư trên vụ của mình, quản lý, admin — tức người mở được `ViewMatter`. **Kế toán không mở được trang vụ việc** (`MatterPolicy::view` đòi `matter.view`), nên màn hình của kế toán là Task 8, không phải tab này.

Nội dung: giá trị hợp đồng, thuế suất và ba con số VAT; trạng thái; lịch thu dạng bảng dọc (tên, số tiền, kích hoạt bằng gì, đến hạn ngày nào, đã thu, trạng thái theo `InstalmentState`); các khoản thu dưới từng đợt (kèm luật sư được ghi doanh thu, P2); phụ lục; dòng tổng **đã thu / còn phải thu / quá hạn**.

- **Ba màu có nghĩa** (xanh đã thu, vàng đang chờ, đỏ quá hạn), bằng style nội tuyến trên biến CSS Filament; **luôn kèm chữ**.
- Nút gọi Action, **bắt `DomainException`** và đổi thành lỗi trên form. Ô tiền đi qua `Money::parse()`, `maxLength` và giới hạn bằng cột / `Money::MAX`.
- **Cổng quyền hỏi kèm ngữ cảnh**, thành ngữ của tab **Tài liệu** (M4): `->authorize(fn () => Gate::allows('create', [Payment::class, $this->getOwnerRecord()]))`. (Tab Các bên dùng `Gate::allows('update', $this->getOwnerRecord())`, không phải dạng này.) Lọc qua `ScopesToVisibleMatters` như mọi relation manager khác.
- **Nút ghi khoản thu trên tab chỉ hiện khi `PaymentPolicy::create` cho phép** — thực tế: admin, và luật sư phụ trách của vụ `restricted`. Kế toán ghi ở trang "Công nợ".
- **Dải cảnh báo** khi vụ đã kết thúc (`closed_at` khác null) mà còn công nợ.
- **Gợi ý đầu mục danh mục.** Sau khi kích hoạt, nếu vụ còn đầu mục bắt buộc "Hợp đồng dịch vụ pháp lý và giấy uỷ quyền" (SPEC §4.9) ở trạng thái thiếu, tab hiện một dòng nhắc luật sư tải bản đã ký lên đúng đầu mục đó qua đường tải của M4, để nhắc §6.9 không đòi khách nộp thứ văn phòng đang giữ. **Không** tự đánh dấu đầu mục là đã nhận: đầu mục gộp cả giấy uỷ quyền, và chỉ nhận diện được bằng tên (câu hỏi mở).
- Một cột trên danh sách vụ việc: **còn phải thu**, hiện cho ai có `billing.view`, ẩn hẳn với ai không có.

**Test bắt buộc (Livewire):** luật sư thấy tab trên vụ của mình, không thấy trên vụ khác; trợ lý không thấy tab; quản lý thấy tab nhưng **không** có nút ghi khoản thu; luật sư vụ thường không có nút ghi khoản thu; luật sư phụ trách vụ `restricted` ghi được; thành viên đội ngũ không phải lead của vụ `restricted` không thấy tiền; `"1.250.000"` lưu thành 1250000; mỗi `DomainException` của Task 4 và 5 hiện thành lỗi trên form, **một `it()` riêng cho từng trường hợp**; vào thẳng URL tab của một vụ không có quyền → **404**; dải cảnh báo hiện khi `closed_at` có giá trị và không hiện khi vụ còn mở.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: tab hợp đồng và thanh toán trên trang vụ việc`.

---

### Task 8: Trang "Công nợ" cho kế toán

**Files:** `app/Filament/Admin/Pages/Receivables.php` + view nếu cần, `app/Policies/DocumentPolicy.php` (nhánh biên lai, nếu chọn cách (a) dưới đây), `lang/vi/billing.php`, `tests/Feature/Filament/ReceivablesPageTest.php`

**Vì sao có task này (P3):** kế toán là người ghi tiền, nhưng không mở được trang vụ việc, và không được cần tới trang đó.

- **Trang tự viết**, `canAccess()` hỏi `Gate::forUser($account)` quyền `revenue.viewAny`, và `abort(404)` ở mọi chỗ resolve record. Admin, quản lý, kế toán vào được; luật sư và trợ lý nhận 404.
- **Dữ liệu chỉ đi qua `AccountantBillingRow`**: mã hồ sơ, loại vụ việc, tên khách, tên đợt, số tiền, đã thu, còn lại, ngày đến hạn, trạng thái hiển thị. **Không** tiêu đề vụ việc. Mã hồ sơ **không** là liên kết tới trang vụ việc với người không qua `MatterPolicy::view`.
- **Tập dòng:** `Matter::listableBy($user)` + `billing.view` (P3). Kế toán và quản lý không thấy vụ `restricted`; admin thấy.
- **Bộ lọc:** quá hạn, đến hạn trong 7 ngày, đã kết thúc còn nợ (`closed_at` khác null), theo khách. Sắp xếp mặc định: quá hạn lâu nhất trước.
- **Hành động** (chỉ hiện khi `PaymentPolicy::create` / `::void` cho phép — tức kế toán và admin; quản lý **chỉ xem**): "Ghi khoản thu" (ô tiền qua `Money::parse`, ngày, cách trả, mã giao dịch, tệp biên lai); xem các khoản thu của một đợt và "Huỷ khoản thu" kèm lý do. Gọi `RecordPayment` / `VoidPayment`, bắt `DomainException`.
- **Tệp biên lai của kế toán.** `UploadStaffDocument` tự hỏi `DocumentPolicy::create` với ngữ cảnh vụ việc, và vế đó đòi `matter.update` mà kế toán không có. Chọn một, ghi phán quyết vào PROGRESS:
  - (a) `DocumentPolicy::create` thêm **đúng một** nhánh: ngữ cảnh là khoản thu, người dùng qua `PaymentPolicy::create`, nhóm **bắt buộc là D**; `UploadStaffDocument` vẫn là đường tải duy nhất (FileGuard, quét virus). Mutation probe cho từng vế; test khẳng định kế toán **không** tạo được tài liệu nhóm A/B/C bằng nhánh này.
  - (b) Nếu (a) phải đụng nhánh cổng khách của `DocumentPolicy::create`, bỏ tệp biên lai khỏi M9; chỉ giữ `reference`.
- **Hiệu năng:** `listableBy` ở tầng truy vấn, không gọi policy theo dòng. Test đếm truy vấn với ngưỡng có lý do.

**Test bắt buộc (Livewire/HTTP, mỗi trường hợp một `it()`):** kế toán mở trang, thấy dòng, **không thấy tiêu đề vụ việc ở bất kỳ đâu** (chuỗi đánh dấu duy nhất đặt trong tiêu đề, như test `internal_note` của §11); kế toán ghi khoản thu qua Livewire và đợt chuyển trạng thái; quản lý mở trang, không có hành động, và gọi thẳng hành động qua Livewire bị từ chối; luật sư → 404; trợ lý → 404; đợt của vụ `restricted` không có trong bảng của kế toán và quản lý, có trong bảng của admin; `"1.250.000"` → 1250000; mỗi `DomainException` hiện thành lỗi trên form; biên lai theo cách đã chọn ở trên.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: trang công nợ cho kế toán, không cần mở hồ sơ`.

---

### Task 9: Trang doanh thu

**Files:** `app/Filament/Admin/Pages/RevenueDashboard.php`, `app/Filament/Admin/Widgets/Revenue/*.php`, `app/Support/Billing/BillingSummary.php`, `lang/vi/widgets.php`, `tests/Feature/Filament/RevenueDashboardTest.php`

Toàn bộ thiết kế ở mục "Trang doanh thu" — **đọc lại nguyên văn trước khi viết**, gồm câu cấm ô đổi kiểu biểu đồ, thứ **phải** được chép vào docblock của trang.

**Sáu widget:** `ReceivablesDonutWidget` (donut, ba lát, kèm ba con số bằng chữ), `RevenueOverTimeWidget` (cột, `ChartWidget::$filter` tháng/quý/năm), `RevenueByStageWidget` (cột ngang theo thứ tự giai đoạn), `MatterMixByPracticeAreaWidget` (cột ngang **xếp hạng**, 12 lĩnh vực, đòi `revenue.viewAny`), `LoadPerLawyerWidget` (cột ngang, đòi `revenue.viewAny`), `ClosedWithBalanceWidget` (bảng — vụ có `closed_at` còn công nợ).

**Năm thứ dễ làm sai:**

1. **Widget doanh thu không được lên trang chủ.** Panel admin tự dò widget **đệ quy** (`discoverWidgets` trên cả `Widgets/`, kể cả `Widgets/Revenue/`), và `Dashboard` trang chủ hiện mọi widget của panel. Mỗi widget doanh thu đặt `protected static bool $isDiscovered = false;` và chỉ đăng ký trong `RevenueDashboard::getWidgets()`. `DashboardWidgetOrderTest` phải còn xanh, và có test khẳng định trang chủ **không** có widget doanh thu nào.
2. **Extend `ChartWidget` + `getType()`**, không dùng `BarChartWidget`/`DoughnutChartWidget` (đã `@deprecated`).
3. **Tooltip tiền và CSP.** Tuỳ chọn Chart.js đi xuống trình duyệt qua `@js(...)`; một bộ định dạng phải là `RawJs`. **Đọc phán quyết CSP của M8 R4 trước.** Nếu `RawJs` không chạy được dưới CSP đã chốt, đưa sẵn chuỗi `Money::format()` vào nhãn/dataset và vào bảng số đi kèm; **không** viết bộ định dạng tiền thứ hai bằng JS. Test cả trên trang có header CSP thật.
4. **Mỗi widget in nghĩa của bộ lọc thời gian và bộ lọc luật sư lên chính nó** (xem "Trang doanh thu", P2). Có test khẳng định nhãn có mặt.
5. **Hiệu năng:** `Matter::scopeListableBy()` ở tầng truy vấn, **không** gọi policy theo dòng. Test đếm truy vấn với ngưỡng **nêu rõ lý do**. **Đo và báo lại** chi phí của việc không có `paid_amount` (sổ controller: "chưa đo" là rủi ro thật).

**Test bắt buộc:** luật sư chỉ thấy số liệu của vụ mình (dựng hai luật sư, khẳng định hai con số khác nhau); luật sư **không** thấy hai widget toàn văn phòng; kế toán thấy đủ **trừ** vụ `restricted`; quản lý như kế toán; admin thấy cả vụ `restricted`; trang không in số vụ bị loại; trợ lý vào thẳng URL → **404**; ba lát donut cộng lại đúng tổng giá trị đã ký trong kỳ trừ phần đã miễn; đổi bộ lọc thời gian đổi đúng widget nói rằng nó đổi và **không** đổi widget khác; lọc luật sư: khoản thu trước bàn giao vẫn tính cho luật sư cũ, còn phải thu tính cho luật sư mới; hợp đồng của vụ đã xoá mềm **không** xuất hiện; khoản thu đã huỷ **không** được cộng; đủ 12 lĩnh vực kể cả lĩnh vực 0 vụ; trang chủ không có widget doanh thu.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: trang doanh thu với biểu đồ lọc theo kỳ, luật sư và lĩnh vực`.

---

### Task 10: Cổng khách — hợp đồng và lịch thu của chính mình (P1) — **giao Opus**

**Files:** `app/Models/{Contract,Instalment,Payment,ContractAmendment}.php` (`applyClientPortalConstraints()`, danh sách ẩn), bốn policy (nhánh `ClientUser`), `app/Filament/Portal/Pages/MatterProgress.php` + view, `lang/vi/portal.php`, `GenerateHandoverPackage` và view `MUC-LUC.pdf` của M7, `docs/SPEC.md` (§5 Portal, §8, §6.12), `tests/Feature/Portal/BillingOnPortalTest.php`, `tests/Feature/Authorization/PortalCoverageTest.php` (chỉ đọc, phải xanh)

**Nới có chủ đích, từng tầng độc lập** (luật ba tầng của M5):

1. **Scope** `applyClientPortalConstraints()`: `Contract` — vụ của nó hiển thị trên cổng với đúng khách đó (dùng lại ranh giới của `Matter`, gồm `client_access_until` của M7 và khách chưa xoá mềm của M6.5) **và** `status` là `active` hoặc `completed`. `Instalment` — hợp đồng hiển thị **và** `status` khác `cancelled`. `Payment` — đợt hiển thị **và** `voided_at` null. `ContractAmendment` — hợp đồng hiển thị.
2. **Policy**: nhánh `ClientUser` phát biểu lại cùng các điều kiện bằng thuộc tính (tiền lệ `MatterPolicy::releasedToPortal`), **không** gọi lại scope.
3. **Ẩn cột**: `HidesInternalAttributesFromPortal` phủ `note`, `ended_reason`, `waived_reason`, `waived_by`, `void_reason`, `voided_by`, `reason` (phụ lục), `created_by`, `updated_by`, `activated_by`, `attributed_lawyer_id`, `receipt_document_id`, `document_id`, `percent_basis`.

**Khối trên trang tiến độ của cổng:** số hợp đồng, tổng giá trị, thuế suất, ngày ký; bảng các đợt (tên, số tiền, "đến hạn ngày …" hoặc "đến hạn khi vụ việc tới bước: <`client_label`>", đã thu, còn lại, "quá hạn" bằng chữ và màu); các khoản đã thu (ngày, số tiền, cách trả). Đợt đã miễn hiện "Văn phòng đã miễn", không lý do. Tiền qua `Money::format()`. Không hiện gì khi vụ chưa có hợp đồng `active`/`completed`. `client_label` của sáu loại tạm không hiện ra vì chúng chưa được công bố (Task 1).

**Gói bàn giao (M7 R8):** thêm vào `MUC-LUC.pdf` một mục "Bảng kê thanh toán", chỉ gồm đúng các trường khối trên cổng hiện, chân trang đọc qua `OfficeProfile` (M7 Task 10). Test trích chữ từ PDF: có bảng kê; chuỗi đánh dấu đặt trong `note`, `waived_reason`, `void_reason` **không** có. Biên lai và bản scan phụ lục là nhóm D nên không vào zip (khẳng định bằng cách giải nén, như M7 R2).

**Đính chính SPEC:** §5 phần Portal — loại dữ liệu thứ tám khách được thấy; §8 — khối "Hợp đồng và thanh toán"; §6.12 — bảng kê trong gói bàn giao.

**Test bắt buộc (mỗi tầng một mutation probe):** khách thấy hợp đồng và lịch thu của vụ mình; khách B không thấy của khách A (scope, policy, URL/Livewire — mỗi đường một `it()`); hợp đồng `draft`, `cancelled` không hiện; đợt `cancelled` và khoản thu đã huỷ không hiện; chuỗi đánh dấu trong từng cột nội bộ không có trong HTML của cổng; vụ chưa công bố cổng hoặc đã hết `client_access_until` → không có khối tiền; khách bị xoá mềm → không thấy gì; `TimeEntry` vẫn đóng; `PortalCoverageTest` xanh không miễn trừ.

- [x] Test đỏ, cài đặt, test xanh, pint, **mutation probe từng tầng**, commit `feat: khách xem hợp đồng và lịch thu của chính mình trên cổng`.

---

### Task 11: Nhắc đợt quá hạn (`RemindOverdueInstalments`)

**Files:** `app/Actions/Schedule/RemindOverdueInstalments.php`, `app/Actions/Notification/ResolveStaffRecipients.php`, thư `staff.instalment_overdue` theo khuôn thư của M6 (tên lớp và view theo đúng thư M6 đã có), `routes/console.php`, `lang/vi/mail.php`, `docs/SPEC.md` (§6.8, §9 — đã ghi ở Task 3, task này làm cho đúng), tests

- **Chọn đợt:** `Instalment::scopeOverdue()` trên hợp đồng `active`, vụ chưa xoá mềm. Vụ đã kết thúc vẫn nhắc (nợ không biến mất khi đóng hồ sơ).
- **Người nhận** (P3), qua **`ResolveStaffRecipients`** — thêm một cổng "được xem tiền của vụ" vào **cùng** lớp (ví dụ `forBilling(Matter $matter, array $preferred)`), dùng đúng định nghĩa của Task 3 (`billing.view` + `isListableBy`) thay cho `view` của vụ, cùng điều kiện `is_active` và cùng chuỗi dự phòng R3. **Không** viết định nghĩa người nhận thứ hai.
  - Vụ thường: mọi kế toán đang hoạt động + luật sư phụ trách.
  - Vụ `restricted`: luật sư phụ trách + admin. Kế toán **không** nhận, vì không thấy vụ.
  - Không ai hợp lệ thì theo chuỗi dự phòng tới admin. Không bao giờ im lặng.
- **Nội dung thư chỉ gồm các trường của `AccountantBillingRow`** (mã hồ sơ, loại vụ việc, tên khách, tên đợt, số tiền còn lại, ngày đến hạn, số ngày quá hạn) và một liên kết: tới trang "Công nợ" với người vào được trang đó, tới tab của vụ với người còn lại. **Không** tiêu đề vụ việc.
- **Nhịp:** ngày đầu tiên quá hạn, rồi 7 ngày một lần, cho tới khi thu đủ, miễn hoặc huỷ.
- **Chống trùng** qua `outbound_messages` theo `template` + `related` (đợt, alias `instalment`) + người nhận + `sent_at`, chỉ tính `status = sent` (M6 R3). Không cột mới.
- **Mọi thư xếp hàng, sau commit** (M6.5 R2). Thư hỏng để lại dòng `failed`, không chặn ai.
- **Lịch:** `Schedule::call(new RemindOverdueInstalments)` 08:00 hằng ngày, `withoutOverlapping(<phút>)` có hạn, test ghim giờ.
- **Không có thư cho khách** (P1). Muốn nhắc khách là một phán quyết mới, và phải theo M6.5 R12.

**Test bắt buộc:** chạy hai lần liên tiếp không sinh thư thứ hai (M6 R4); `travelTo()` qua ngày 1, 7, 8, 14 của quá hạn; vụ thường: kế toán và lead nhận, quản lý không; vụ `restricted`: lead và admin nhận, kế toán không; kế toán bị vô hiệu hoá không nhận; lead nghỉ việc (không `is_active`) thì thư đi theo dự phòng; chuỗi đánh dấu trong tiêu đề vụ việc không có trong thư; máy chủ thư hỏng → dòng `failed`, không lỗi 500, và lần chạy sau gửi lại; đợt được thu đủ giữa hai lần chạy → không nhắc nữa.

- [x] Test đỏ, cài đặt, test xanh, pint, mutation probe, commit `feat: nhắc nội bộ khi đợt thanh toán quá hạn`.

---

### Task 12: Khung `time_entries` — **task có thể bỏ**

**Files:** migration `time_entries`, `app/Models/TimeEntry.php`, `app/Policies/TimeEntryPolicy.php`, `Matter::timeEntries()`, `User::timeEntries()`, alias morph `time_entry`, factory, `docs/SPEC.md` §15, tests

SPEC §15: *"Riêng `time_entries` tuy chưa làm ở bản 1.0 nhưng nên tạo sẵn quan hệ trong model `Matter`, vì khi văn phòng chuyển sang tính phí theo giờ thì đây là thứ khó gắn thêm sau nhất."* Ghi chú M1 đã hoãn nó *"sang giai đoạn 2 cùng bảng `time_entries`"*.

**Phạm vi chính xác: bảng, model, quan hệ, policy đóng kín, factory. KHÔNG Action, KHÔNG màn hình, KHÔNG một con số nào trên dashboard đọc bảng này.**

**Task này đứng cuối và không task nào phụ thuộc vào nó.** Nếu chủ văn phòng nói "không", xoá đúng task này.

**Test bắt buộc:** `PortalCoverageTest` xanh không thêm miễn trừ; `TimeEntryPolicy` từ chối mọi `ClientUser`; `Matter::timeEntries()` và `User::timeEntries()` trả đúng quan hệ; **không tệp nào ngoài danh sách tệp của task này** (migration, model, policy, factory, `Matter.php`, `User.php`, `AppServiceProvider.php`, test của task) tham chiếu `TimeEntry` — test grep, là thứ giữ cho task xoá được.

- [ ] Test đỏ, cài đặt, test xanh, pint, **round-trip MariaDB**, commit `feat: khung time_entries cho mô hình tính phí theo giờ về sau`.

---

### Task 13: Dữ liệu mẫu, đi bộ tay, tài liệu, cổng merge

**Files:** `database/seeders/BillingSeeder.php`, `database/seeders/DemoDataSeeder.php` (M6.5 Task 19), lệnh `vkcrm:preflight` (M8 R1), `docs/PROGRESS.md`, `docs/QUY-TRINH.md`, `README.md`, `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md`

1. **`BillingSeeder` nằm trong `DemoDataSeeder`**, không bao giờ chạy ở production. Dữ liệu đủ để dashboard vẽ ra một bức tranh THẬT:
   - Mọi vụ đã rời `intake` có hợp đồng `active`; vài vụ ở `intake` có `draft`; ít nhất một vụ **cố ý không có hợp đồng**.
   - Giá trị 15.000.000 – 450.000.000 đ. Vài hợp đồng VAT 8% và 10%, vài hợp đồng `vat_rate_percent = null`.
   - Lịch ba đợt (30% khi ký / 40% khi nộp đơn / 30% khi có bản án), **ít nhất bốn hợp đồng kích hoạt theo giai đoạn**.
   - **Ít nhất hai đợt quá hạn** (một theo ngày, một theo giai đoạn); một đợt thu một phần; một đợt đã miễn **kèm lý do thật đọc được**; một khoản thu đã huỷ kèm lý do; một hợp đồng có phụ lục; **một vụ đã kết thúc còn công nợ**; **một vụ `restricted` có hợp đồng** (để thử P3); **một vụ đã bàn giao** có khoản thu trước và sau (để thử P2); một vụ đã công bố cổng có hợp đồng (để thử Task 10).
   - Khoản thu rải trên **ít nhất tám tháng**.
   - **Tổng khớp tuyệt đối.**
2. **`migrate:fresh --seed` trên MariaDB thật**, rồi `migrate:reset` → `migrate`. Dán output. Chạy thêm `ReferenceDataSeeder` hai lần trên dữ liệu đã có: không đổi tên admin đã sửa, quyền mới có mặt.
3. **`billing:check-invariants` chạy sạch** trên dữ liệu mẫu, và được thêm vào `vkcrm:preflight` (đỏ khi có hợp đồng lệch). Dán output.
4. **Đi bộ tay**, ghi từng bước và kết quả: soạn hợp đồng ba đợt, đợt 2 kích hoạt bằng giai đoạn; kích hoạt; chuyển vụ tới giai đoạn đó và **xác nhận đợt 2 đến hạn ngay**; **đăng nhập kế toán**, mở "Công nợ", ghi một khoản thu một phần bằng `1.250.000`, ghi nốt, huỷ một khoản và xem trạng thái lùi lại — xác nhận không tiêu đề vụ việc nào hiện ra; ký phụ lục tăng giá trị; bàn giao vụ và xem doanh thu cũ vẫn ở luật sư cũ; mở trang doanh thu và **đổi từng bộ lọc một**; đăng nhập khách và xem khối hợp đồng trên cổng; chạy `RemindOverdueInstalments` và mở thư trong Mailpit. Nhánh xấu: tổng lệch 1 đồng, thu vượt, ngày thu ở tương lai, lý do 19 ký tự tiếng Việt có dấu, `1.25` trong ô tiền, kế toán tìm vụ `restricted`.
5. **Kịch bản "nhập hợp đồng đang chạy lúc go-live":** hợp đồng ký trong quá khứ, khoản đã thu ghi lùi `paid_on`, vụ đã ở giữa chừng. Kiểm đối chiếu kích hoạt đúng đợt theo `stage_logs` có thật, và ghi rõ: vụ nhập thẳng vào giai đoạn giữa không có dòng `stage_logs` cho các giai đoạn trước, nên đợt của các giai đoạn đó phải nhập là `due_date` hoặc ghi lùi thu. Đo donut sau đó; không được có "quá hạn" giả. Viết thành một mục trong `README.md` / `docs/QUY-TRINH.md`.
6. **Việc mang sang chưa ai nhặt** (`DocumentPolicy::publish`/`::delete` cần probe lại; `MatterChecklistItem` chưa có `LogsActivity`) — nhặt, hoặc ghi rõ là vẫn còn.
7. **Tài liệu:**
   - `docs/PROGRESS.md`, dòng M9 và "Ghi chú M9": bảy phán quyết P1–P7, mọi sai lệch so với kế hoạch (gồm P5 so với sổ controller), mọi việc hoãn, deviation "không `deleted_at`", mục đích xử lý mới (kế toán thấy tên khách), cách chọn biên lai ở Task 8.
   - `docs/QUY-TRINH.md`: bỏ mục "khách có được xem hợp đồng… đang chờ" (đã quyết, P1); thêm quy trình ghi tiền của kế toán.
   - `README.md`, mục nâng cấp (M8 R6): chạy `ReferenceDataSeeder` để có bốn quyền mới, chạy `billing:check-invariants`.
   - Tài liệu bộ công cụ: không có "mục M9"; sửa hàng "Giai đoạn 2 (sau M8)" (`installments` → `instalments`) cho khớp.
8. **Nghiệm thu:** `bin/dev test` xanh, `bin/dev pint --test` sạch, `bin/dev test:mariadb` xanh **tuần tự**; `security-review` bắt buộc; rà soát toàn nhánh bằng Opus, brief **giả định có một Critical**. Merge vào `main`, push, chờ CI xanh.

- [ ] Test xanh, pint sạch, commit `docs: M9 hoàn tất — hợp đồng dịch vụ và thu phí theo đợt`.

---

## Thứ tự và việc chạy song song

```
main sau khi M11 đã merge
   ├── Task 1 (12 lĩnh vực) ──────────────────────────────────────────────┐
   └── Task 2 (bảng + model) ──┬── Task 3 (quyền + policy) [Opus] ────────┤
                               └── Task 12 (khung time_entries)           │
                                                                          │
       Task 4 (hợp đồng) [Opus] ── Task 5 (khoản thu) [Opus] ──┬── Task 6 (theo giai đoạn)
                                                               ├── Task 7 (tab vụ việc)
                                                               ├── Task 8 (Công nợ)
                                                               ├── Task 10 (cổng khách) [Opus]
                                                               └── Task 11 (nhắc quá hạn)
                                                                          │
                                                        Task 9 (trang doanh thu)
                                                                          │
                                                        Task 13 (mẫu + tài liệu + merge)
```

- **Task 1 và Task 2 song song được.** Task 1 **phải xong trước Task 9**.
- **Task 3 và Task 12 song song được** sau Task 2.
- **Task 4 cần Task 2 và 3.** Task 5 cần Task 4.
- **Sau Task 5:** Task 6, 7, 8, 10, 11 song song được. Chỗ chạm nhau: `lang/vi/billing.php` (7, 8), `routes/console.php` (6, 11), model tiền (10). **Commit theo đường dẫn tường minh.** `ActivateContract` (Task 4) gọi `TriggerInstalmentsForStage` (Task 6): điểm nối tường minh + test `todo`, **không** bản sao logic.
- **Task 9** cần 1, 3, 4, 5, 6.
- **Task 13 một mình**, cuối.
- Giữ nguyên quy trình: một người cài đặt mới cho mỗi task, rà soát theo phạm vi task, rà soát lại sau mỗi vòng sửa, rà soát toàn nhánh trước merge, brief **giả định có một Critical**. **Task 3, 4, 5, 10 giao Opus.**

---

## Tự rà soát kế hoạch

**Độ phủ mô tả của chủ văn phòng.** *"Ký hồ sơ là giá trị 1 lần"* → `contracts.total_amount`, unique `matter_id`, bất biến tổng (Task 2, 4). *"Thanh toán theo giai đoạn"* → `trigger_type = stage` + sự kiện đổi giai đoạn + đối chiếu (Task 2, 6). *"Như các CRM luật thương mại làm"* → khoản thu riêng có người ghi và cách nhận, thu một phần, phụ lục có lịch sử, công nợ quá hạn nhìn thấy và được nhắc (Task 4, 5, 8, 11). *"Dashboard có biểu đồ lọc theo tháng, lĩnh vực…"* → Task 9.

**Độ phủ SPEC §15.** `contracts` + `instalments` → Task 2. `time_entries` → Task 12. *"Mô hình dữ liệu 1.0 phải để chỗ mở rộng mà không phải sửa lại"* → **không migration nào của M9 sửa cấu trúc một bảng đã có**; chỉ thêm bảng, thêm quan hệ ở tầng model, và một migration **dữ liệu** đổi tên bốn loại vụ việc.

**Độ phủ §11.** *"Kế toán không xem được nội dung hồ sơ"* sắc hơn vì kế toán có hai màn hình mới và một thư mới (Task 3, 8, 11). *"Chuyển giai đoạn sai `allowed_next` → ném exception"* phải còn xanh sau Task 6. Độ phủ 80% cho `app/Actions/` và `app/Policies/` áp cho `app/Actions/Billing/`, hai Action lịch và bốn policy mới.

**Nhất quán tên gọi.** `TriggerInstalmentsForStage` sinh ở Task 6, gọi ở Task 4, listener và đối chiếu — **một định nghĩa**. `Instalment::state()` / `scopeOverdue()` sinh ở Task 5, dùng ở Task 7, 8, 9, 10, 11 — hiển thị **không** tự tính lại. `Money::format()`/`parse()` sinh ở Task 4. "Ai thấy tiền của vụ nào" = `billing.view` + `listableBy`, định nghĩa ở Task 3, dùng ở mọi màn hình, thư và policy. `AccountantBillingRow` sinh ở Task 3, là kiểu dữ liệu duy nhất của trang "Công nợ" và thư nhắc. "Vụ đã kết thúc" = `closed_at` (M6.5 R8).

**Rủi ro đã lường trước.**
- *Bộ giai đoạn tạm* là kiến thức hành nghề kế hoạch không có; nhánh tường minh ở `StagePresets` giữ nó khỏi rơi vào bộ dân sự.
- *`percent_basis`* nằm cạnh một cột có tính quyết định. Có test và probe; bỏ nó là lựa chọn hợp lệ, ghi lý do.
- *Round-trip MariaDB* ở Task 2 — nhiều unique composite và khoá ngoại nhất kể từ M1.
- *Task 4 gọi Task 6* — điểm nối tường minh.
- *Chạy trên production*: seed ghi đè tên admin đã sửa; quyền mới chưa tới production. Task 1 và Task 13 bước 2 giữ.

**Cố ý để lại ngoài M9.**
- **Xuất hoá đơn, hoá đơn điện tử, tờ khai thuế, đối chiếu phần mềm kế toán.**
- **Thư nhắc nợ cho khách** (P1). Chỉ có thư nội bộ (Task 11).
- **QR VietQR và đối soát ngân hàng tự động** — SPEC §1 xếp riêng.
- **Tính phí theo giờ** — chỉ khung bảng (Task 12) và một cổng từ chối ở `billing_model`.
- **Ô cho người dùng tự đổi kiểu biểu đồ** — bị từ chối, kèm lý do, trong docblock của trang.

**Điều tôi ít chắc nhất.**

1. **Không có cột `paid_amount`.** Đúng về tính đúng đắn, nhưng chi phí truy vấn **chưa đo**, và trang doanh thu cùng trang "Công nợ" là nơi nó cộng dồn. Task 9 phải đo. Nếu xấu, câu trả lời là bảng tổng hợp **dựng lại được từ `payments`**, không phải một cột ai cũng ghi được.
2. **Loại hẳn vụ `restricted` khỏi tổng số của kế toán và quản lý** (P3). Cái giá: con số "toàn văn phòng" của họ thấp hơn thật, và chỉ admin thấy con số đúng. Tôi chọn vậy vì bộ lọc làm cho mọi cách gộp đều suy ngược được. Nếu chủ văn phòng muốn quản lý thấy tổng đúng, cách đúng là một con số gộp **không lọc được** (chỉ tổng toàn thời gian), không phải nới `listableBy`.
3. **`TriggerInstalmentsForStage` không nhận actor.** Có cách đọc ngược lại đứng được (người chuyển giai đoạn gây ra việc đợt đến hạn). `stage_log_id` trong properties đã truy ngược được tới người đó, nên cái giá nếu tôi sai là nhỏ.
4. **Số lượng task.** Mười ba task. Nếu Task 4 vượt một phiên, tách **`AmendContract` + phụ lục** ra, **không** tách theo tầng.
