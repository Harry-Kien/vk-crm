# VK-CRM M9 — Kế hoạch hợp đồng dịch vụ pháp lý và thu phí theo đợt

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## Vị trí trong thứ tự dựng — đọc trước mọi thứ khác

**Milestone này làm SAU M5 (cổng khách hàng).** Không phải vì phụ thuộc kỹ thuật — phụ thuộc thật của nó chỉ tới M4 — mà vì **M5 là lý do cả hệ thống tồn tại và M5 vẫn chưa được dựng**. SPEC §1 đặt ra đúng ba việc, việc thứ hai là "khách hàng tự đăng nhập tra cứu tiến độ 24/7"; hôm nay `/portal` chỉ có một màn hình đăng nhập. Một dashboard doanh thu đẹp trên một hệ thống mà khách hàng vẫn phải gọi điện hỏi tiến độ là một hệ thống đã đi chệch khỏi chính lý do nó được đặt hàng.

Nếu chủ văn phòng đảo lại thứ tự đó, đó là quyền của chủ văn phòng — nhưng phải là một lần đảo có ý thức, và cái giá phải được nói ra trước, không phải phát hiện sau.

**Số hiệu M9** vì nó đứng sau M8 trong bảng SPEC §13. Thứ tự thật mềm hơn số hiệu: M9 chạy được ngay sau M5, trước M6–M8, với ba cái giá cụ thể và đã lường trước:

- **Không có email nhắc công nợ** (tầng email là M6). M9 dựng *truy vấn* quá hạn, widget và trang doanh thu; lời nhắc gửi đi là một dòng thêm vào bộ job của M6, **đã đặc tả ở đây, không cài ở đây** — đúng cách M4 đã xử `RetractDocument`.
- **Gói bàn giao (M7) chưa có bảng kê thanh toán.** Khi M7 dựng `GenerateHandoverPackage`, nó phải nhớ rằng bảng kê đợt thu là thứ khách hàng sẽ hỏi. Ghi ở PROGRESS.
- **Không có CSP, HSTS, rate limit riêng cho trang tiền** (M8). Trang doanh thu là màn hình nhạy cảm nhất trong cả hệ thống nội bộ; chạy nó trước M8 là một rủi ro vận hành phải ghi vào README, không phải bỏ qua.

**Goal:** Văn phòng ghi được một hợp đồng dịch vụ pháp lý cho mỗi vụ việc với **một giá trị thoả thuận duy nhất**, chia thành các **đợt thanh toán** gắn vào tiến độ vụ việc ("thanh toán đợt 2 khi nộp đơn khởi kiện"), ghi nhận từng khoản tiền thật sự nhận được kèm người ghi và cách nhận, và nhìn thấy toàn bộ bức tranh tiền trên một trang có biểu đồ lọc được theo thời gian, luật sư và lĩnh vực. Đúng mô hình chủ văn phòng mô tả: *"văn phòng ký hồ sơ là giá trị 1 lần nhưng mà thanh toán theo giai đoạn."*

**Architecture:** Bốn bảng mới (`contracts`, `instalments`, `payments`, `contract_amendments`) gắn vào `matters`, đúng chỗ SPEC §15 đã chừa sẵn. Tiền lưu bằng **số nguyên đồng**. Nghiệp vụ nằm trong `app/Actions/Billing/`; Filament chỉ gọi Action. Đợt thanh toán theo giai đoạn nối vào `TransitionMatterStage` bằng **một sự kiện mới** (`MatterStageChanged`) — Action chuyển giai đoạn không biết gì về tiền, xem mục "Đợt thanh toán theo giai đoạn" bên dưới. Trang doanh thu là một `Filament\Pages\Dashboard` riêng với `HasFiltersForm`, không phải nhồi thêm vào trang chủ §7.1.

**Tech Stack:** PHP 8.3, Laravel 13.x, Filament 5.8 (`filament/widgets` có sẵn `DoughnutChartWidget`, `BarChartWidget`; `Filament\Pages\Dashboard\Concerns\HasFiltersForm` + `Filament\Widgets\Concerns\InteractsWithPageFilters` là cơ chế lọc — đã kiểm trong `vendor/` hôm nay), Pest 4, Pint. **Không gói mới.** Mọi lệnh qua `bin/dev`.

**Spec:** `docs/SPEC.md` §1 ("Ngoài phạm vi bản này" — mục này thu hẹp ở M9), §4 toàn bộ (đặc biệt §4.4 `matter_types`, §4.5 `matter_type_stages`, §4.6 `matters`, §4.8 `stage_logs`, §4.11 `documents` cho tệp phụ lục và uỷ nhiệm chi, §4.13 `deadlines` làm tiền lệ cho phát hiện quá hạn, §4.19 `matter_archives`), §5 + phần **Portal** (cần **một đính chính có ngày**, xem Task 3), §6.1 (sinh mã), §6.2/§6.3 (chuyển giai đoạn — chỗ đợt thanh toán móc vào), §6.8 (tiền lệ nhắc hạn), §6.10 (tiền lệ DTO readonly giới hạn thông tin), §6.11 (tiền lệ "chặn thao tác khi còn việc chưa bàn giao"), §6.12 (bàn giao, `client_access_until`), §7.1 (bảy widget trang chủ đã đặc tả — M9 **không** động vào thứ tự đó), §7.2, §8 (nếu chủ văn phòng cho khách xem hợp đồng), §10.5, §10.6, §10.10, §11, §12, §13, §14, **§15** (nguồn gốc của cả milestone này).

---

## Ràng buộc toàn cục

- **Nhánh:** `m9-contracts-and-payments`, cắt từ `main` **sau khi M5 đã merge**.
- PHP sàn **8.3**, cứng. Không Redis, Horizon, Octane, Reverb, Pulse, Scout, Telescope. Không `storage:link`.
- **CRM chỉ dùng TIẾNG VIỆT** — quyết định của chủ văn phòng ngày 19/09/2026, ghi ở `.superpowers/sdd/2026-09-19-m5-client-portal/progress.md`. Không dựng bộ chuyển ngôn ngữ, không thêm locale thứ hai. `lang/vi/` là nơi duy nhất; `lang/en/` chỉ tồn tại như fallback của framework. Định danh mã tiếng Anh, **mọi chuỗi hiển thị qua `__()`**.
- Nghiệp vụ chỉ ở `app/Actions/`. Page, resource, widget, controller, listener, job **chỉ gọi Action** và **phải bắt `DomainException`** để đổi thành lỗi trên form (bài học M3 Task 9 và M4: một exception không được bắt là lỗi 500 trên màn hình).
- **Enum backed string cho mọi cột trạng thái, có `label()`** đọc từ `lang/vi/enums.php`.
- **Quy ước actor (M3 fix round 3, agent B).** Mọi Action nhận `User $actor` **tường minh**, không đọc `Auth::` bên trong. Vị trí tham số theo họ Action đang có. Không một dòng `Auth::` nào được xuất hiện trong `app/Actions/Billing/`.
- **`blameOn($actor)` TRƯỚC `save()`/`update()`** cho mọi model dùng `HasBlameable`. `HasBlameable` rơi về `auth('web')` ambient nếu không ai tuyên bố actor; với tiền, một cột `created_by` sai là một câu trả lời sai cho câu hỏi "ai ghi khoản này". Đã có tiền lệ: `TransitionMatterStage` bước 5 và bước 6.
- **Chuẩn mutation probe (M4, kỹ thuật kiểm chứng mạnh nhất dự án có).** Với **mỗi** điều kiện người cài đặt thêm vào: xoá đúng điều kiện đó, chạy lại bộ test trong container, **khẳng định đúng những test nêu tên nó chuyển ĐỎ**, khôi phục, dán bằng chứng vào báo cáo. Hai probe **sống sót** ở M4 và cả hai đều lộ ra test rỗng ruột thật. Nếu một probe sống sót, đó không phải nhiễu — đó là một test không kiểm cái nó nói.
- **Docblock là thứ phải rà lại, không phải thứ để tin.** Sáu milestone liên tiếp, mỗi vòng rà soát đều tìm ra ít nhất một câu docblock nói sai sự thật, và ở M4 chính lần rà docblock tìm ra một lỗi thật (`safeName()` nói "bỏ byte thừa" trong khi `mb_convert_encoding` thay bằng `?`). Kết thúc mỗi task: đọc lại từng câu khẳng định trong docblock mình vừa viết và chứng minh hoặc sửa.
- **SQLite không bao giờ bắt được ràng buộc chỉ số/khoá ngoại của MariaDB** — nó dựng lại cả bảng mỗi khi index đổi. Dự án đã vỡ vì chuyện này **hai lần** (lỗi 1553 trên `matter_type_stages` ở M3; migration medialibrary không có `down()` nên `migrate:reset` im lặng no-op ở M4). **Mọi task đụng migration phải chạy trên container MariaDB thật: `bin/dev artisan migrate:fresh --seed`, rồi một vòng `bin/dev artisan migrate:reset` → `bin/dev artisan migrate`, và dán NGUYÊN VĂN output vào báo cáo.** M9 có nhiều unique composite và nhiều khoá ngoại hơn bất kỳ milestone nào kể từ M1; đây là nơi quy tắc này cắn mạnh nhất.
- **Filament 5 khác các bản trước rất nhiều. Không viết mã Filament từ trí nhớ** — phán quyết đã ghi ở kế hoạch M3 và nhắc lại ở M5, vẫn nguyên hiệu lực. Cách làm bắt buộc: chạy `bin/dev artisan make:filament-*` để lấy khung thật, đọc `vendor/filament/` khi cần, hoặc tra context7. **Kế hoạch này mô tả hành vi và luật nghiệp vụ; không có một dòng mã Filament nguyên văn nào là cố ý.**
- **False-green của Livewire, cho mọi test màn hình (M4 Task 2 fix round).** `PersistentMiddleware::applyPersistentMiddleware()` ghi nhớ theo `"{method}|{path}"` và chỉ xoá khi flush-state, nên **hai POST tới cùng một path trong một `it()`** khiến lần thứ hai chạy KHÔNG middleware bền và trả 200. Mỗi trường hợp phải là một `it()` riêng.
- **Từ chối trong panel trả 404, không phải 403** (`AnswerDeniedPanelRequestsWithNotFound`, chốt ở M4 Task 2). Mọi màn hình tiền của M9 nằm dưới quy tắc đó. Và như M4 Task 3 đã ghi: **không map exception nghiệp vụ sang một mã HTTP riêng** — làm vậy là dựng lại đúng cái máy dò sự tồn tại mà SPEC §10.10 cấm.
- TDD với Pest: test đỏ trước. Nếu red-first yếu về cấu trúc (mọi lỗi đều là "class does not exist"), **nói thẳng điều đó** và bù bằng mutation probe, đúng như M4 Task 3 đã làm.
- Kết thúc mỗi task: `bin/dev test` xanh, `bin/dev pint` sạch, commit **chỉ các tệp của mình theo đường dẫn tường minh** (`git commit -- <path>`), **không `git add -A`**.
- Commit message kết thúc bằng `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` (chép nguyên văn).
- `security-review` **bắt buộc** ở milestone này. Người rà soát cuối được brief là **giả định có một lỗi Critical**. Task phân quyền (Task 3) và task tiền (Task 4, 5) **giao cho Opus** — mọi Critical tìm được từ đầu dự án tới giờ đều đến từ một lượt rà soát Opus.

---

## Bảy quyết định thiết kế đã chốt — và chỗ tôi nghĩ chúng chưa đúng

Kế hoạch này dựng trên bảy quyết định đã được đưa ra trước khi viết. Sáu quyết định tôi tán thành và đã xây tiếp; **một quyết định tôi nghĩ cần sửa**, nói ngay ở đây thay vì chôn trong một task.

**1. Một hợp đồng cho một vụ việc.** Giá trị thoả thuận một lần, có tính quyết định. **Tán thành** — và cài bằng một **unique index thật trên `contracts.matter_id`**, không phải một quy ước. Xem điểm 4 dưới đây về hệ quả với xoá mềm.

**2. Đợt thanh toán mang lịch thu.** **Tán thành**, với một chỗ cần rẽ thành ba thay vì hai: "hoặc ngày đến hạn, hoặc chạm tới một giai đoạn" bỏ sót trường hợp phổ biến nhất trong hợp đồng dịch vụ pháp lý Việt Nam — **tạm ứng khi ký**. Lúc soạn lịch thu thì chưa ai biết ngày ký, nên nó không phải một `due_date`; và "ký hợp đồng" không phải một giai đoạn trong `matter_type_stages`. Ba loại kích hoạt: `on_signing`, `due_date`, `stage`.

**3. Bất biến: tổng các đợt phải khớp ĐÚNG giá trị hợp đồng.** **Tán thành**, và phần dư khi chia theo phần trăm **luôn rơi vào đợt cuối cùng** — một câu, một chỗ (`App\Support\Billing\SplitByPercent`), hiện ra bằng số đồng trên màn hình trước khi lưu.

**4. Khoản thu là bản ghi riêng.** **Tán thành tuyệt đối.** Và đi thêm một bước mà quyết định gốc chưa nói: **`payments` và `contracts` KHÔNG dùng `SoftDeletes`.** Hai lý do, cả hai đã có tiền lệ trong dự án. (a) `contracts.matter_id` là unique; một hợp đồng xoá mềm vẫn chiếm chỗ index, và "xoá mềm rồi tạo lại" chính là lỗ hổng dự án đã vấp **hai lần** (`matter_type_stages.key`, `MatterType.code`). (b) Một khoản thu ghi nhầm không được biến mất — nó được **huỷ** (`voided_at` + lý do ≥ 20 ký tự `mb_strlen`), và vẫn nằm đó. Đây là đúng tinh thần `stage_logs` (chỉ thêm) đã áp cho sổ pháp lý; sổ tiền không đáng được lỏng hơn. Deviation so với câu "Toàn bộ bảng dùng ... `deleted_at`" ở §4 — M1 đã có tiền lệ deviation tương tự cho bảng nhật ký và pivot, ghi rõ lý do.

**5. Tiền lưu bằng số nguyên đồng.** **Tán thành.** `unsignedBigInteger`, cast `integer`. Đồng không chia nhỏ trên thực tế, số nguyên là chính xác, và mọi câu hỏi làm tròn bị xoá khỏi tầng lưu trữ. **Cái giá nếu văn phòng có ngày tính phí bằng USD**, nói thẳng:

> Sẽ không đủ nếu chỉ thêm một cột `currency`. Một hệ thống hai tiền tệ cần **ba** thứ mà hôm nay không có: (a) một **đơn vị nhỏ nhất khác** — USD có cent, nên `amount` phải đổi nghĩa thành "đơn vị nhỏ nhất của đồng tiền đó", và mọi con số đã lưu phải được đọc lại qua đơn vị đó; (b) một **tỷ giá có dấu thời gian** để cộng được hai hợp đồng khác tiền tệ trên cùng một biểu đồ, và một quyết định xem tỷ giá đó chốt lúc ký hay lúc thu; (c) một **tầng hiển thị** biết ký hiệu, vị trí ký hiệu và cách nhóm chữ số của từng đồng tiền. Đó là một milestone riêng có đặc tả riêng, không phải một cột. Vì vậy kế hoạch này **cố ý KHÔNG thêm cột `currency`** — một cột luôn mang đúng một giá trị là một lời hứa chưa được giữ, và nó sẽ khiến người đọc sau tưởng hệ thống đã sẵn sàng cho đồng tiền thứ hai.

**6. Tiền là dữ liệu nhạy cảm, phải giới hạn theo vai trò.** **Tán thành**, chi tiết ở Task 3.

**7. VAT: đừng mô hình hoá quá tay.** **Tán thành, và tôi cắt sâu hơn đề bài.** Xem "Kết luận về VAT" bên dưới — **một cột nullable duy nhất**, không phải hai.

### Chỗ tôi nghĩ một quyết định chưa đúng như đã phát biểu

**Quyết định 1 đọc trần ra sẽ cấm phụ lục hợp đồng.** "Một hợp đồng cho một vụ việc, giá trị chốt một lần, có tính quyết định" là đúng về nguyên tắc, nhưng phụ lục hợp đồng là chuyện có thật và xảy ra thường xuyên trong nghề (vụ việc lên cấp phúc thẩm, phát sinh công việc ngoài phạm vi ban đầu). Nếu để nguyên câu chữ, người cài đặt sẽ làm một trong hai việc và cả hai đều tệ: hoặc **cấm hẳn** (và văn phòng sẽ nhập một hợp đồng giả thứ hai ở đâu đó ngoài hệ thống, tức số liệu doanh thu sai), hoặc **cho sửa thẳng `total_amount`** (và giá trị thoả thuận mất lịch sử, tức không trả lời được "tại sao con số này đổi").

Cách giữ được cả hai: **giá trị hợp đồng là một cột, lịch sử của nó là một bảng.** Xem `contract_amendments` trong mô hình dữ liệu. Hợp đồng vẫn là một, vẫn là một dòng, `total_amount` vẫn là nguồn sự thật duy nhất ai cũng đọc; mỗi lần con số đó đổi sinh một dòng phụ lục chỉ-thêm mang giá trị cũ, giá trị mới, lý do, ngày ký và (nếu có) bản scan phụ lục trong `documents`. Không có "phiên bản hợp đồng", không có câu hỏi "bản nào đang có hiệu lực" — thứ sinh ra cả một họ lỗi.

---

## Kết luận về VAT — biểu diễn nhỏ nhất còn trung thực

**Một cột: `contracts.vat_rate_percent` — `unsignedTinyInteger nullable`.** Không có cột `vat_mode`, không có cột tiền thuế, không có bảng thuế.

Lập luận. Bất biến số 3 nói tổng các đợt phải khớp đúng giá trị hợp đồng. Nếu khách hàng thực tế trả phí **cộng** VAT thì các đợt phải cộng lại bằng phí-cộng-VAT, nếu không con số "còn phải thu" sai đúng 10%. Vậy chỉ còn một cách đọc nhất quán: **`total_amount` LUÔN là số tiền khách hàng phải trả, đã gồm VAT ở nơi có VAT.** Khi đã cố định như vậy thì "chưa gồm / đã gồm" không còn là một trạng thái cần lưu — nó là một cách nhập liệu, và chỗ của nó là màn hình.

- `vat_rate_percent = null` → không có dòng thuế (không chịu thuế, hoặc không xuất hoá đơn).
- `vat_rate_percent = 0` → có hoá đơn, thuế suất 0%. Đây là hai chuyện khác nhau trong thực tế Việt Nam, và một cột nullable phân biệt được chúng mà không cần cột thứ hai.
- `vat_rate_percent = 8` hoặc `10` → phần thuế nằm **trong** `total_amount`, suy ra để hiển thị: `thuế = round(total × r / (100 + r))`, phần chưa thuế là phần còn lại. Suy ra **một chỗ duy nhất**, trong `App\Support\Billing\Vat`, và phép làm tròn phải rơi rõ ràng: **phần thuế làm tròn xuống, phần chưa thuế nhận phần dư**, để hai số luôn cộng lại đúng `total_amount` bằng số nguyên đồng. Có test cho một giá trị lẻ cố ý (ví dụ 33.333.333 đ ở 10%).

**Cái giá, nói thẳng:** một hợp đồng báo giá cho khách là "50.000.000 chưa VAT" phải được nhập thành 55.000.000 với thuế suất 10. Đó là một nghĩa vụ của màn hình, không phải của dữ liệu: form soạn hợp đồng có hai ô nhập ("số tiền chưa VAT" và "thuế suất") và hiện ngay số tổng sẽ lưu, hoặc cho nhập thẳng số tổng — nhưng **số được lưu chỉ có một**, và cả ba con số hiện cùng lúc trước khi bấm lưu để không ai gõ nhầm.

**Ngoài phạm vi, nói ra để không bị hiểu là quên:** xuất hoá đơn, hoá đơn điện tử, mẫu số / ký hiệu hoá đơn, tờ khai thuế, đối chiếu với phần mềm kế toán. Không có gì trong M9 sinh ra một hoá đơn.

---

## Đợt thanh toán theo giai đoạn — làm sao để `TransitionMatterStage` không biết gì về tiền

Đây là trái tim của *"thanh toán theo giai đoạn"* và là chỗ dễ làm hỏng nhất.

**Cái không được làm:** cho `TransitionMatterStage` truy vấn `instalments`. Action đó là nơi nhạy cảm nhất hệ thống (`stage_logs` chỉ-thêm, SLA §6.4, widget §7.1 đều treo vào nó), nó đã đi qua ba vòng sửa với Critical chạm thẳng vào sổ pháp lý, và CLAUDE.md nói nghiệp vụ nằm trong Action chứ không phải Action nào cũng gánh mọi nghiệp vụ.

**Cái làm:** một **sự kiện** mới, đặt tên theo đúng chuyện đã xảy ra trong từ vựng của chính Action đó.

```
app/Events/MatterStageChanged.php          (implements ShouldDispatchAfterCommit)
app/Listeners/ReleaseStageTriggeredInstalments.php
app/Actions/Billing/TriggerInstalmentsForStage.php
app/Jobs/ReconcileStageTriggeredInstalments.php
```

`TransitionMatterStage` nhận thêm **đúng một dòng**, ngay cạnh dòng `event(new StageLogPublished(...))` đã có:

- Dispatch **chỉ khi giai đoạn thật sự đổi** (`! $isSameStage`) — một dòng cập nhật cùng giai đoạn của §6.3 không phải một lần chạm tới giai đoạn, và tính nó là chạm sẽ làm một luật sư viết ghi chú tuần trở thành một lần phát sinh công nợ.
- Sự kiện mang `StageLog` (đã có `matter_id`, `from_stage`, `to_stage`, `occurred_at`, `id`) và **không mang một trường nào liên quan tới tiền**.
- **`ShouldDispatchAfterCommit`**, y hệt `StageLogPublished`, và vì đúng lý do docblock của `StageLogPublished` đã viết ra: nếu transaction ghi `StageLog` rollback thì listener không bao giờ chạy. Với tiền vế này còn nặng hơn — một đợt thanh toán "đến hạn" vì một lần chuyển giai đoạn đã bị rollback là một khoản tiền văn phòng đi đòi mà lý do không tồn tại.

Bốn điểm phải cài đúng, mỗi điểm một test:

1. **Chạy một lần duy nhất.** Giai đoạn có thể vào ra nhiều lần (`allowed_next` có chu trình, `on_hold` ra vào được theo §4.5). Cổng là `instalments.triggered_at IS NULL`, không phải "giai đoạn hiện tại bằng giai đoạn kích hoạt".
2. **Ngày đến hạn tính từ ngày giai đoạn THẬT SỰ xảy ra**, tức `stage_logs.occurred_at`, cộng `due_days_after_trigger`. Không phải `now()`. Hệ quả đúng và phải có test: một lần chuyển giai đoạn ghi lùi ngày có thể sinh ra một đợt **đã quá hạn ngay khi ra đời** — đó là sự thật, không phải lỗi.
3. **Chỉ hợp đồng `active`.** Lịch thu của một hợp đồng còn `draft` chưa ràng buộc ai.
4. **Ghi bằng chứng.** Đợt được kích hoạt lưu `triggered_by_stage_log_id` trỏ đúng dòng nhật ký đã kích hoạt nó. Đây là câu trả lời cho "tại sao đợt này đến hạn", và nó trỏ vào một bảng chỉ-thêm không ai sửa được.

**Lưới an toàn — `ReconcileStageTriggeredInstalments`, chạy hằng ngày.** Listener đồng bộ sau commit có thể hỏng (một lỗi trong Action tiền không được phép làm hỏng một lần chuyển giai đoạn), và nó cũng không phủ được ba đường khác hoàn toàn hợp lệ: một đợt được **thêm vào lịch thu sau khi** vụ việc đã đi qua giai đoạn kích hoạt; một hợp đồng được **kích hoạt** khi vụ việc đã ở giai đoạn giữa chừng; và seeder/console ghi thẳng `matters.stage`. Job đối chiếu `instalments` chưa kích hoạt với `stage_logs` (append-only, là bằng chứng thật về việc vụ việc đã từng ở đâu) và kích hoạt những đợt còn thiếu, dùng lại **đúng** Action `TriggerInstalmentsForStage`, không phải một bản sao logic.

Đăng ký trong `routes/console.php` — **đây là dòng `Schedule::` đầu tiên của dự án**; M6 sẽ thêm tiếp vào cùng tệp đó, và giám sát cron (SPEC §2, `heartbeat`) vẫn thuộc M6. Ghi rõ trong docblock: cho tới khi M6 xong, một cron chết âm thầm nghĩa là job đối chiếu không chạy — nên đường chính **phải** là listener, job chỉ là lưới.

**Đã kiểm hôm nay:** `matters.stage` không có nơi ghi nào khác trong `app/` ngoài `TransitionMatterStage` (grep toàn bộ `app/Filament`, `app/Models/Matter.php`; các nơi còn lại là `database/seeders` và `MatterFactory`). Nên một dòng sự kiện phủ được cánh cửa thật, và job đối chiếu phủ phần còn lại. **Người cài đặt phải grep lại** — nếu M6/M7 thêm một Action đổi giai đoạn (ví dụ `ReassignMatter` hay một `CloseMatter`), Action đó cũng phải dispatch sự kiện này, và đó là chỗ nó sẽ bị quên.

---

## Mô hình dữ liệu

Tất cả bảng: `id` bigint tự tăng, `created_at`, `updated_at`, `created_by`/`updated_by` FK `users` qua `HasBlameable`. **`deleted_at` chỉ có ở nơi ghi rõ.**

### `contracts` — một hợp đồng cho một vụ việc

| Cột | Kiểu | Ghi chú |
|---|---|---|
| matter_id | FK matters, **unique** | Quyết định 1, cài bằng index thật ở MariaDB |
| code | string(30) unique | `HD-{YYYY}-{0001}`, sinh bằng `App\Support\CodeSequence` đã có (SPEC §6.1 là tiền lệ). Không bao giờ đổi |
| status | enum `ContractStatus` | `draft`, `active`, `completed`, `cancelled` |
| billing_model | enum `BillingModel` | `fixed_fee` (duy nhất cài đặt ở M9), `hourly`, `mixed` — xem Task 9 |
| total_amount | unsignedBigInteger | **Đồng.** Số tiền khách phải trả, đã gồm VAT ở nơi có VAT |
| vat_rate_percent | unsignedTinyInteger nullable | Xem "Kết luận về VAT" |
| signed_at | date nullable | Bắt buộc khi rời `draft` |
| activated_by | FK users nullable | Người ở văn phòng ghi nhận việc ký. **Không phải chữ ký số** |
| ended_at | date nullable | Ngày `completed` hoặc `cancelled` |
| ended_reason | text nullable | Bắt buộc ≥ 20 ký tự `mb_strlen` khi `cancelled` |
| note | text nullable | **Nội bộ. Không bao giờ ra portal** |

**Không `deleted_at`.** Một hợp đồng còn `draft` xoá cứng được (chưa ai ký gì); từ `active` trở đi chỉ `completed` hoặc `cancelled`, không bao giờ xoá. Cài bằng hook `deleting` trên model, tiền lệ `Matter::forceDeleting` → `MatterNotDestroyable` từ M1.

Index: `(status)`, `(signed_at)`. Unique `(matter_id)`, `(code)`.

### `instalments` — đợt thanh toán

| Cột | Kiểu | Ghi chú |
|---|---|---|
| contract_id | FK contracts | |
| sequence | unsignedSmallInteger | Đợt 1, 2, 3… Unique cặp `(contract_id, sequence)` |
| name | string(150) | "Tạm ứng khi ký hợp đồng", "Thanh toán đợt 2 khi nộp đơn khởi kiện" |
| amount | unsignedBigInteger | **Đồng.** Là con số có tính quyết định |
| percent_basis | decimal(5,2) nullable | Phần trăm người dùng đã gõ, **chỉ để hiển thị và truy vết**. Không bao giờ dùng để tính lại `amount` |
| trigger_type | enum `InstalmentTrigger` | `on_signing`, `due_date`, `stage` |
| trigger_stage_key | string(40) nullable | Bắt buộc khi `trigger_type = stage`. Trỏ `matter_type_stages.key` của **đúng loại vụ việc đó** — không phải FK, y như `matters.stage` (mỗi loại có bộ key riêng) |
| due_days_after_trigger | unsignedSmallInteger default 0 | "30 ngày kể từ khi toà thụ lý" |
| due_date | date nullable | `null` = chưa đến đợt. Điền lúc soạn lịch (`due_date`), lúc kích hoạt hợp đồng (`on_signing`), hoặc lúc chạm giai đoạn (`stage`) |
| triggered_at | timestamp nullable | Cổng chống kích hoạt hai lần |
| triggered_by_stage_log_id | FK stage_logs nullable | Bằng chứng "tại sao đợt này đến hạn" |
| status | enum `InstalmentStatus` | `pending`, `paid`, `waived`, `cancelled` — **chỉ bốn**, xem dưới |
| waived_reason | text nullable | Bắt buộc ≥ 20 ký tự `mb_strlen` khi `waived` |
| waived_by / waived_at | FK users nullable / timestamp nullable | |
| note | text nullable | **Nội bộ** |

**Không `deleted_at`.** Một đợt của hợp đồng `draft` xoá cứng được; của hợp đồng `active` thì `cancelled` hoặc `waived`, không xoá.

Index: `(due_date, status)` — đúng hình dạng index `(due_date, is_completed)` của `deadlines` ở §4.13, và vì cùng lý do: truy vấn quá hạn chạy hằng ngày trên toàn bảng. `(contract_id)`, `(trigger_stage_key)`.

**Hai điều cố ý KHÔNG có trong bảng này, và lý do:**

- **Không có cột `paid_amount`.** Số tiền đã thu của một đợt là `SUM` của `payments` chưa bị huỷ. Một cột tổng hợp là **nguồn sự thật thứ hai về tiền** và nó sẽ lệch — không phải "có thể lệch", mà sẽ, ở lần đầu tiên ai đó huỷ một khoản thu bằng một đường không đi qua Action. Cái giá: mỗi lần đọc "còn phải thu" là một phép join. Ở quy mô vài nghìn hồ sơ đó là cái giá đúng, y như lập luận SPEC §6.13 chọn `LIKE` thay vì Elasticsearch. Nếu có ngày quy mô đổi, câu trả lời là một materialized summary **có job dựng lại được từ `payments`**, không phải một cột ai cũng ghi được.
- **Không có trạng thái `overdue` trong enum.** Quá hạn là một hàm của thời gian, không phải một sự kiện. Xem "Phát hiện quá hạn" bên dưới.

**Bốn trạng thái lưu, bảy trạng thái hiển thị.** `InstalmentStatus` (lưu) chỉ mang những sự thật **không phụ thuộc vào hôm nay là ngày nào**: `pending`, `paid`, `waived`, `cancelled`. Cái người dùng nhìn thấy là `InstalmentState` (suy ra, tính ở **một chỗ duy nhất** — `Instalment::state()`): `scheduled` (chưa có `due_date`), `due`, `overdue`, `partially_paid`, `paid`, `waived`, `cancelled`. Cả hai đều là enum backed string có `label()`; docblock của cả hai phải nói rõ cái nào lưu, cái nào suy ra, và vì sao — nếu không, milestone sau sẽ thêm `overdue` vào cột lưu và hệ thống sẽ có hai câu trả lời cho một câu hỏi.

### `payments` — khoản thu

| Cột | Kiểu | Ghi chú |
|---|---|---|
| instalment_id | FK instalments | Một khoản thu thuộc **một** đợt (quyết định 4) |
| amount | unsignedBigInteger | **Đồng**, > 0 |
| paid_on | date | Ngày tiền thật sự về, do người nhập chọn — như `stage_logs.occurred_at`. **Không được ở tương lai** (tiền lệ `TransitionMatterStage` bước 3, cùng cách so sánh theo NGÀY ở múi giờ ứng dụng) |
| method | enum `PaymentMethod` | `bank_transfer`, `cash`, `card`, `offset` (cấn trừ), `other` |
| reference | string(100) nullable | Mã giao dịch, số biên lai |
| receipt_document_id | FK documents nullable | Bản scan uỷ nhiệm chi / phiếu thu, dùng lại nguyên tầng tệp của M4 |
| note | text nullable | |
| voided_at / voided_by / void_reason | timestamp, FK users, text — đều nullable | Lý do bắt buộc ≥ 20 ký tự `mb_strlen` khi huỷ |

**Không `deleted_at`, và hook `deleting` từ chối mọi lần xoá.** Một khoản thu ghi nhầm được **huỷ kèm lý do**, không biến mất. `created_by` (qua `HasBlameable` + `blameOn($actor)`) là câu trả lời cho "ai ghi khoản này" — **không** thêm một cột `recorded_by` thứ hai; dự án đã có đúng một quy ước cho câu hỏi đó và quy ước thứ hai là chỗ hai giá trị bắt đầu lệch nhau.

Index: `(instalment_id)`, `(paid_on)`, `(voided_at)`.

### `contract_amendments` — phụ lục hợp đồng

Chỉ thêm, không sửa, không xoá — cài bằng model guard, tiền lệ `StageLog` / `StageLogImmutable` từ M1.

| Cột | Kiểu |
|---|---|
| contract_id | FK contracts |
| sequence | unsignedSmallInteger, unique cặp `(contract_id, sequence)` |
| previous_total_amount / new_total_amount | unsignedBigInteger |
| reason | text, bắt buộc ≥ 20 ký tự `mb_strlen` |
| signed_at | date |
| document_id | FK documents nullable — bản scan phụ lục |

### `time_entries` — chỉ dựng khung, xem Task 9

`matter_id`, `user_id`, `worked_on` date, `minutes` unsignedSmallInteger, `description` text, `is_billable` boolean, `hourly_rate` unsignedBigInteger nullable (đồng/giờ), `invoiced_at` timestamp nullable. **Không Action, không màn hình, không con số nào trên dashboard đọc bảng này ở M9.**

### Quan hệ thêm vào model đã có

- `Matter::contract(): HasOne`, `Matter::timeEntries(): HasMany` (đóng đúng ghi chú M1: *"Chưa khai báo `Matter::timeEntries()` (SPEC §15) vì chưa có bảng; làm ở giai đoạn 2 cùng bảng `time_entries`"* — giai đoạn 2 chính là đây).
- `Document::paymentReceipts()`, `Document::contractAmendments()` — để `DeleteDocument` biết tệp đang được một bản ghi tiền trỏ tới.
- `StageLog::triggeredInstalments(): HasMany` — chiều ngược của bằng chứng kích hoạt.

### Cổng khách hàng: cả năm model đóng kín ngay từ đầu

Cả năm model mới dùng `RestrictedToClientPortal` với `applyClientPortalConstraints()` trả về **không gì cả** dưới guard `client`, policy từ chối mọi `ClientUser`, và cột `note` nằm trong `HidesInternalAttributesFromPortal`. `PortalCoverageTest` (lưới an toàn từ M2: *"mọi model mới ở M3–M8 phải hoặc dùng `RestrictedToClientPortal`, hoặc vào danh sách miễn trừ kèm lý do"*) phải xanh mà **không** thêm một dòng miễn trừ nào.

Làm vậy **không** phải là đã quyết định khách không được xem hợp đồng của mình — xem Câu hỏi 2 cho chủ văn phòng. Làm vậy là để câu trả lời "có" về sau là **một lần nới đúng một điều kiện trong một scope**, thay vì năm mặt phẳng rò rỉ mới phải dựng từ đầu dưới sức ép.

---

## Phát hiện quá hạn: tính lúc đọc, không phải một job ghi trạng thái

**Quyết định: tính lúc đọc.** `Instalment::state()` so `due_date` với `today()` và với số tiền còn lại. `scopeOverdue()` là cùng điều kiện đó viết bằng SQL, cho widget và dashboard.

Lý do, và tiền lệ đã có trong chính SPEC. §4.13 + §6.8 dựng `deadlines` đúng hình dạng này: một cột `due_date`, một cột `reminders_sent` json, **không có cột `is_overdue`**; job hằng ngày chỉ **gửi** lời nhắc và ghi lại đã gửi mốc nào, nó không phán xử trạng thái. Một job ghi `overdue` vào cột sẽ sai trong 23 giờ mỗi ngày ngay khi ai đó sửa một ngày đến hạn, và nó tạo ra nguồn sự thật thứ hai — cùng lý do đã loại `paid_amount`.

**Job hằng ngày vẫn cần, nhưng chỉ làm hai việc không tính được lúc đọc:**

- `ReconcileStageTriggeredInstalments` — lưới an toàn cho đợt theo giai đoạn (mô tả ở trên). **Thuộc M9.**
- `RemindOverdueInstalments` — email nhắc công nợ, chống gửi trùng bằng một cột `reminders_sent` json y như `deadlines`. **Đặc tả ở đây, cài ở M6** cùng tầng email và dòng `outbound_messages`. M9 chỉ thêm cột `instalments.reminders_sent` json nullable để M6 không phải mở lại migration. *(Nếu người cài đặt thấy thêm một cột cho một milestone sau là sai nguyên tắc, hãy nói ra và bỏ cột — cái giá là một migration nữa ở M6, và quy tắc round-trip MariaDB áp lại lần nữa. Tôi nghiêng về thêm, nhưng đây là một lựa chọn chứ không phải một sự thật.)*

---

## Tiền trên một vụ việc đã đóng, đã lưu trữ, đã bàn giao, đã xoá mềm

Bốn tình huống, bốn câu trả lời khác nhau, và chúng khác nhau có lý do.

**Vụ việc chuyển sang giai đoạn kết thúc (`is_terminal`) mà còn công nợ → KHÔNG chặn, nhưng phải nhìn thấy.** Chặn việc đóng hồ sơ vì còn tiền sẽ dạy luật sư đừng đánh dấu hồ sơ đã kết thúc — và toàn bộ SLA §6.4, widget §7.1 và dòng thời gian portal đều đọc `stage`, nên làm hỏng dữ liệu giai đoạn để đòi tiền là đánh đổi sai. Thay vào đó: một widget **"Hồ sơ đã kết thúc còn công nợ"** trên trang doanh thu, và một dải cảnh báo trên tab tiền của chính vụ việc đó. Tiền không mất, nó chỉ thôi được giấu.

**Vụ việc bị xoá mềm mà còn công nợ → CHẶN.** Đây là chỗ câu trả lời phải khác với `RunConflictCheck`. Ở kiểm tra xung đột, M3 đã chốt *"soft-delete nghĩa là ẩn, không phải chưa từng xảy ra"* nên vụ đã xoá mềm **vẫn** được đối chiếu. Với tiền thì ngược chiều: nếu truy vấn doanh thu bỏ qua vụ đã xoá mềm (mà nó phải bỏ qua — ẩn là ẩn), thì xoá mềm một vụ việc trở thành cách làm bốc hơi một khoản công nợ khỏi sổ sách bằng một cú bấm không ai rà. Nên: `Matter::deleting` ném `MatterHasOutstandingBalance` khi còn hợp đồng `active` với số dư > 0. Đường đi qua là huỷ hoặc miễn các đợt còn lại, **kèm lý do**, rồi mới xoá. Tiền lệ trực tiếp: §6.11 chặn vô hiệu hoá một luật sư còn là lead lawyer của vụ đang mở, với đúng hình dạng thông điệp ("nêu rõ số vụ cần bàn giao" → ở đây là số tiền và số đợt còn lại).

**Bàn giao (`ReassignMatter`, M7) → tiền không đổi chủ, nhưng phải biết doanh thu ghi cho ai.** Kế hoạch này gán doanh thu theo `matters.lead_lawyer_id` **hiện tại**, và **nói ra điều đó ngay trên biểu đồ** ("theo luật sư phụ trách hiện tại"). Đây là một mặc định, không phải một kết luận — xem Câu hỏi 3.

**Lưu trữ và hết hạn tra cứu (`client_access_until`, `ExpireClientAccess`, M7) → không liên quan tới sổ tiền.** Một vụ việc biến mất khỏi portal của khách vẫn còn nguyên trong sổ. Hai câu hỏi khác nhau, hai scope khác nhau; ghi vào docblock của scope tiền để M7 không vô tình gộp chúng.

---

## Trang doanh thu — lập luận đã chốt, chép vào kế hoạch để nó không bị "cải tiến"

Chủ văn phòng hỏi biểu đồ tròn. Biểu đồ tròn đúng **chỉ khi** có một tổng thể và vài phần. Vậy:

| Câu hỏi | Dạng biểu đồ | Vì sao |
|---|---|---|
| **Đã thu / còn phải thu / quá hạn** | **Vành khuyên (donut)** | Đây là nơi duy nhất biểu đồ tròn xứng đáng: đúng một tổng thể (giá trị đã ký), đúng ba phần, cộng lại bằng 100% |
| Doanh thu theo thời gian | **Cột** (tháng / quý / năm) | Thời gian là một trục, và mắt người so chiều cao cột tốt hơn so góc |
| Cơ cấu vụ việc theo **12 lĩnh vực hành nghề** | **Cột ngang xếp hạng** | Một biểu đồ tròn 12 lát là không đọc được. Xếp theo giá trị giảm dần — "xếp hạng" nghĩa là xếp hạng, không phải theo `sort_order` |
| Vụ việc theo giai đoạn | Phễu hoặc **cột ngang** | Đã có `MattersByStageWidget`; xem việc mang sang |
| Tải theo luật sư | **Cột ngang** | |

**Bộ lọc:** khoảng thời gian (từ / đến + phím tắt tháng này, quý này, năm nay), luật sư, lĩnh vực, và công tắc **số vụ / số tiền**. Cài bằng `Filament\Pages\Dashboard\Concerns\HasFiltersForm` trên trang + `Filament\Widgets\Concerns\InteractsWithPageFilters` trên từng widget (đã kiểm có trong `vendor/filament/` hôm nay). Công tắc số vụ / số tiền **đổi thứ được đo**, không thêm trục thứ hai — tiền và số đếm không bao giờ dùng chung một trục.

> **KHÔNG làm ô cho người dùng tự đổi kiểu biểu đồ.** Viết ra ở đây vì đó đúng là thứ một người cài đặt sẽ "giúp thêm". Một ô chọn kiểu biểu đồ cho phép người dùng vẽ ra một biểu đồ sai — 12 lĩnh vực thành một hình tròn, một chuỗi thời gian thành một hình tròn — **rồi tin nó**. Cái giá của việc bỏ ô đó là một người dùng đôi khi muốn một kiểu khác; cái giá của việc có nó là một quyết định kinh doanh dựa trên một hình vẽ nói dối. Câu này phải nằm trong docblock của trang, không phải chỉ trong kế hoạch.

**Khoảng thời gian lọc cái gì — phải trả lời, vì trộn lẫn là cách một dashboard nói dối.** Hai nghĩa khác nhau, và mỗi widget **phải in nghĩa của mình lên chính nó bằng tiếng Việt**:

- Biểu đồ **cột doanh thu** lọc theo `payments.paid_on` — *"tiền về trong kỳ"*.
- **Donut** và **cơ cấu lĩnh vực** lọc theo `contracts.signed_at` — *"việc đã ký trong kỳ"* — và trạng thái đã thu / còn phải thu / **quá hạn** tính **tại hôm nay**, không phải tại ngày cuối kỳ. Nhãn trên widget phải nói đúng câu đó.

**Phân quyền của trang, dùng lại đúng một định nghĩa đã có.** Trang hiện cho ai có `billing.view`; **mọi** widget lấy tập vụ việc gốc từ `Matter::scopeListableBy($user)` — định nghĩa duy nhất của "nhân sự thấy vụ việc nào" từ M2, và nó đã trả đúng: luật sư chỉ vụ của mình, kế toán và quản lý toàn bộ. Riêng hai widget **so sánh toàn văn phòng** (tải theo luật sư, cơ cấu lĩnh vực) đòi thêm `revenue.viewAny`, vì với một luật sư chúng vừa vô nghĩa vừa tọc mạch.

**Định dạng tiền một chỗ duy nhất:** `App\Support\Money::format(int $dong): string` → `1.250.000 ₫`. Tooltip của Chart.js **cũng** phải đi qua nó; nếu không, một tỷ đồng hiện ra là `1250000000` và không ai đọc được. Có test.

---

## Hệ thống màu và quy cách biểu đồ — đã kiểm chứng bằng máy, ngày 2026-09-22

Bổ sung sau khi chủ văn phòng hỏi lại về biểu đồ. **Không chọn màu bằng mắt.** Bộ màu dưới đây đã chạy qua bộ kiểm sáu phép (dải độ sáng, sàn độ bão hoà, tách biệt cho người mù màu, sàn cho mắt thường, tương phản trên nền) **ở cả hai nền: trắng `#ffffff` và nền tối `#18181b`** — và cùng một bộ ba đạt ở cả hai, nên widget **không cần đổi màu theo chế độ**, chỉ đổi màu chữ và lưới.

**Bộ ba của biểu đồ vành khuyên** (đã thu / còn phải thu / quá hạn):

| Phần | Mã màu | Vai trò |
|---|---|---|
| Đã thu | `#0ca30c` | trạng thái tốt |
| Còn phải thu, chưa tới hạn | `#4a73bd` | **chính là màu thương hiệu**, bậc 500 của dải primary |
| Quá hạn | `#d03b3b` | trạng thái nghiêm trọng |

Số đo: tách biệt mù màu ΔE 19.9 ở cặp xấu nhất, mắt thường 27.6, cả ba đều vượt tương phản 3:1 trên **cả hai** nền. Chỉ có một màu xanh dương duy nhất trong toàn bộ trang, cố ý — hai sắc xanh gần nhau ở hai biểu đồ khác nhau là cách nhanh nhất để người đọc tưởng chúng cùng nghĩa.

**Cột một chuỗi dùng đúng một màu** `#4a73bd`, đạt cả hai nền. Một chuỗi thì **không có chú giải** — tiêu đề đã gọi tên nó rồi.

**Luật bắt buộc, chép vào docblock của trang:**

- **Không bao giờ hai trục y.** Hai đại lượng khác thang thì hai biểu đồ, không phải hai trục. Công tắc *số vụ / số tiền* đã có trong kế hoạch này chính là cách né đúng.
- **Màu đi theo thực thể, không đi theo thứ hạng.** Lọc bớt một lĩnh vực thì các lĩnh vực còn lại **không được đổi màu**.
- **Một sắc cho thang liên tục, hai sắc cộng một xám ở giữa cho thang hai cực. Không bao giờ cầu vồng.**
- **Chú giải luôn có khi từ hai chuỗi trở lên**, và các lát của vành khuyên phải có **nhãn trực tiếp ghi số**, vì cặp đỏ–xanh ở trên nằm sát dải cảnh báo của thị giác tritan; nhãn là lớp mã hoá thứ hai bắt buộc, không phải trang trí.
- **Chữ mặc màu chữ, không mặc màu chuỗi.** Con số và nhãn dùng màu chữ của Filament; ô màu nhỏ bên cạnh mới mang danh tính.
- **Bốn màu trạng thái là của riêng trạng thái**, không bao giờ tái sử dụng làm "chuỗi thứ tư", và luôn đi kèm biểu tượng và chữ.
- **Luôn có một bảng số** đi kèm mỗi biểu đồ, mở ra được. Ai không đọc được màu vẫn phải đọc được dữ liệu, và người muốn con số chính xác cũng cần nó.
- **Khoảng 2px nền giữa các mảng liền nhau**, đầu cột bo 4px, điểm đánh dấu tối thiểu 8px.

**Một widget nữa, đúng thứ chủ văn phòng vừa hỏi và kế hoạch cũ chưa có:**

`RevenueByStageWidget` — **doanh thu đã thu theo từng đợt/giai đoạn**. Cột ngang xếp theo thứ tự giai đoạn của loại vụ việc (không xếp theo giá trị, vì ở đây thứ tự thời gian chính là thông tin), mỗi cột là tổng tiền thực nhận của các đợt gắn vào giai đoạn đó. Trả lời đúng câu hỏi vận hành: *văn phòng đang kẹt tiền ở khúc nào của quy trình.* Cùng bộ lọc, cùng một màu, cùng bảng số đi kèm.

---

## 12 lĩnh vực hành nghề — thuộc kế hoạch này, và là Task 1

Seeder hiện có **sáu** loại vụ việc; văn phòng hành nghề **mười hai** lĩnh vực (lấy từ luatvukhang.com). Nghĩa là **một nửa dịch vụ của văn phòng hôm nay không mở được thành hồ sơ.**

**Quyết định: đóng khoảng trống đó trong kế hoạch này, ở một task riêng, và là task ĐẦU TIÊN.** Lý do nó thuộc về đây chứ không phải một việc vặt để sau: biểu đồ đinh của trang doanh thu là *cơ cấu vụ việc theo lĩnh vực hành nghề*, và một biểu đồ hiện 6 trên 12 lĩnh vực là một biểu đồ kể cho chủ văn phòng một câu chuyện sai về chính văn phòng của mình. Lý do nó là task **riêng**: nó không đụng một dòng mã nghiệp vụ nào, nó đụng dữ liệu cấu hình mà **fixture của mọi test khác đang ngồi lên**, nên nó phải xong và ổn định trước khi bốn người khác bắt đầu viết test.

Đối chiếu:

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

**Cái bẫy phải nói trước: đổi `name` thì được, đổi `code` thì KHÔNG.** SPEC §6.1 nói *"Mã không bao giờ đổi sau khi tạo"*, và `matters.code` đã nhúng mã loại (`VK-2026-DD-0147`). Đổi `matter_types.code` là làm mồ côi mọi mã hồ sơ đã sinh. Task 1 chỉ đổi `name`.

**Bộ giai đoạn cho sáu loại mới là kiến thức tố tụng, không phải việc gõ máy — và kế hoạch này không được bịa nó ra.** Xem Câu hỏi 1.

---

## Việc bắt buộc mang sang từ rà soát M2/M3/M4/M5

Ghi ở đây để không rơi. Mỗi mục có task phụ trách.

| Việc | Nguồn | Task |
|---|---|---|
| `MattersByStageWidget` gộp theo **nhãn** giai đoạn, nên hai loại vụ việc có giai đoạn trùng nhãn bị cộng chung một cột — số liệu sai. Hôm nay là lý thuyết; với **12** loại vụ việc nó thành chắc chắn (mọi loại đều có "Tiếp nhận", "Thu thập hồ sơ") | Rà soát M3 → kế hoạch M4 Task 7 → M5 Task 7 | **1** (task làm cho lỗi tiềm ẩn thành lỗi thật thì sở hữu bản vá; nếu M4/M5 đã sửa thì chỉ xác nhận bằng một test hai loại trùng nhãn) |
| `TransitionMatterStage` vẫn nhận `DateTimeInterface\|string` rồi `Carbon::parse` — tức nó mang lỗi I6 của M4: chuỗi không parse được ném `Carbon\InvalidFormatException`, **ngoài** hợp đồng `DomainException` mà mọi màn hình được dặn bắt, nên thành 500 | Ledger M4, "Deliberately NOT fixed" | **6** (task này là task duy nhất của M9 đụng vào tệp đó; sửa theo đúng cách M4 đã sửa `issuedAt`: parse tường minh → `ValidationException`) |
| `MatterChecklistItem` chưa dùng `LogsActivity` | Ledger M4 | — (không thuộc M9; ghi lại để không tưởng là đã xong) |
| `DocumentPolicy::publish` / `::delete` mang cùng vế `document.publish` đã **sống sót** một mutation probe ở `RegroupDocument`, và test của chúng cũng dùng trợ lý — cần probe lại | Ledger M4, "Carried, not fixed" | — (M4/M5 sở hữu; nếu rơi tới đây thì Task 10 nhặt) |
| `MatterPolicy::view` chạy một EXISTS mỗi lần gọi; **mọi** widget doanh thu gọi policy hoặc `listableBy` theo dòng | Rà soát M2 Task 5 | **8** (đếm truy vấn, có ngưỡng nêu rõ lý do) |
| **404 chứ không 403 cho mọi từ chối trong panel**, và **không** map exception nghiệp vụ sang mã HTTP riêng | M4 Task 2 + Task 3 ruling (c) | **7**, **8** |
| False-green `PersistentMiddleware` memoise theo `{method}\|{path}`: hai POST cùng path trong một `it()` → lần hai chạy không middleware bền | M4 Task 2 fix round | mọi task có màn hình |
| Chuỗi tiếng Việt đếm bằng `mb_strlen`, **không** `strlen` | M3 | **4**, **5** |
| `LogsActivity` trên model có `SoftDeletes` ghi giá trị cũ dưới khoá `old`, **không** `attributes` | M4 Task 3 fix round | **10** (đọc nhật ký) |
| **Không viết mã Filament 5 từ trí nhớ** | Kế hoạch M3, nhắc lại M5 | mọi task |
| `expect(fn () => ...)->not->toThrow(Throwable::class)` là một test **không thể đỏ** — `Throwable` là interface nên `class_exists` false và nhánh `not` của Pest nuốt cả hai kiểu hỏng. Còn ít nhất hai chỗ dùng dạng này trong bộ test | Ledger M4 Task 1 | mọi task (đừng viết thêm; nếu đi ngang qua thì sửa) |

---

## Cần chủ văn phòng quyết trước khi bắt đầu task tương ứng

Đây là quyết định **nghiệp vụ**, không phải kỹ thuật. Kế hoạch này cố ý **không** quyết thay. Mỗi câu có một mặc định để không chặn việc, và mỗi mặc định đảo ngược được.

| # | Câu hỏi | Vì sao không tự quyết được | Mặc định | Chặn task |
|---|---|---|---|---|
| **1** | **Bộ giai đoạn cho sáu lĩnh vực mới.** Một vụ "hành chính và giấy phép" hay "sở hữu trí tuệ" đi qua những bước nào ở văn phòng này? | Đây là kiến thức hành nghề. Một bộ giai đoạn bịa ra sẽ hiện thẳng cho khách hàng qua `client_label` và `client_description` ở SPEC §8.3 — tức văn phòng sẽ giải thích sai quy trình của chính mình cho khách | Mỗi loại mới nhận **một bộ năm giai đoạn chung** (`intake → collecting_documents → drafting → in_progress → closed`) được **đánh dấu là tạm** trong `description` và trong `docs/PROGRESS.md`, và không loại nào trong sáu loại mới được bật `is_published_to_portal` cho tới khi chủ văn phòng duyệt bộ giai đoạn thật | **1** (mặc định cho phép chạy tiếp) |
| **2** | **Khách có xem được hợp đồng và lịch thu của mình trên portal không?** | Họ đã ký nó, nên đó là thông tin của chính họ — lập luận này mạnh. Nhưng đây vẫn là một quyết định **công bố**: một khách đang có tranh chấp đọc được dòng "đợt 3 sẽ thu khi có bản án sơ thẩm" có thể đọc nó là một lời hứa về kết quả. Và SPEC §5 phần Portal liệt kê **bảy** loại dữ liệu khách được thấy; thêm một loại là mở rộng phạm vi, đúng thứ cần chữ ký chứ không phải suy ra (M5 đã dựng đúng tiền lệ này với `communication_logs`) | **Không** ở M9. Cả năm model đóng kín ở cả ba tầng ngay từ Task 2, nên câu trả lời "có" về sau là một lần nới có kiểm soát | **2** (đóng kín), và một task riêng của milestone sau nếu câu trả lời là có |
| **3** | **Doanh thu ghi cho luật sư nào khi vụ việc đã bàn giao?** Luật sư phụ trách hiện tại, hay người phụ trách lúc tiền về? | Nếu con số này có ngày dính tới thưởng hay đánh giá, thì đây là câu hỏi về thù lao của con người, không phải về một câu `GROUP BY` | Luật sư phụ trách **hiện tại**, và biểu đồ **nói ra điều đó** | **8** (mặc định cho phép chạy tiếp; nhưng nếu câu trả lời là "lúc tiền về" thì `payments` cần thêm một cột và đó là một migration — hỏi **trước** Task 2 nếu kịp) |
| **4** | **Ai được xoá một hợp đồng còn `draft`?** Kế hoạch cho `contract.manage`, tức gồm cả luật sư trên vụ của mình | Một hợp đồng `draft` chưa ràng buộc ai nên rủi ro thấp; nhưng nếu văn phòng muốn mọi con số tiền đi qua kế toán ngay từ bản nháp thì đó là một lựa chọn hợp lệ và khác | Như trên: `contract.manage` | **3** |
| **5** | **Giá trị hợp đồng có được ghi đè bởi chính người soạn không, hay cần người thứ hai duyệt?** | Với văn phòng nhỏ, bắt hai người duyệt mỗi hợp đồng là cái cổng người ta học cách bấm cho xong (cùng bài học với ghi đè xung đột ở M3). Với văn phòng lớn hơn thì ngược lại | Một người. Phụ lục cần lý do ≥ 20 ký tự và để lại dấu vết, đó là tuyến kiểm soát | **4** |
| **6** | **Có dựng khung `time_entries` bây giờ không?** (SPEC §15 nói đây là thứ khó gắn thêm sau nhất) | Một bảng không ai ghi là một khoản nợ kỹ thuật; nhưng SPEC nói thẳng nó là thứ khó bolt-on nhất, và ghi chú M1 đã hoãn nó "sang giai đoạn 2 cùng bảng `time_entries`" — giai đoạn 2 chính là đây | **Có**, ở Task 9, task **cuối** và **không có task nào khác phụ thuộc vào nó** — nếu chủ văn phòng nói không thì xoá đúng một task | **9** |

---

## Cấu trúc tệp (trạng thái cuối M9)

| Đường dẫn | Trách nhiệm |
|---|---|
| `database/migrations/*_create_contracts_table.php` … `_create_time_entries_table.php` | Năm migration, mỗi cái một bảng |
| `app/Models/{Contract,Instalment,Payment,ContractAmendment,TimeEntry}.php` | Guard bất biến / chống xoá ở model, đúng chỗ Action không phủ được |
| `app/Enums/{ContractStatus,BillingModel,InstalmentTrigger,InstalmentStatus,InstalmentState,PaymentMethod}.php` | Backed string, có `label()` |
| `app/Policies/{Contract,Instalment,Payment,ContractAmendment,TimeEntry}Policy.php` | |
| `app/Actions/Billing/DraftContract.php` | Soạn hợp đồng + lịch thu, trạng thái `draft` |
| `app/Actions/Billing/ActivateContract.php` | Cổng bất biến tổng: không khớp thì không rời `draft` |
| `app/Actions/Billing/AmendContract.php` | Phụ lục: đổi tổng + lịch, sinh `contract_amendments` |
| `app/Actions/Billing/{CancelContract,CompleteContract}.php` | |
| `app/Actions/Billing/{RecordPayment,VoidPayment}.php` | |
| `app/Actions/Billing/{WaiveInstalment,CancelInstalment}.php` | |
| `app/Actions/Billing/TriggerInstalmentsForStage.php` | Đường **duy nhất** một đợt theo giai đoạn được kích hoạt |
| `app/Support/Billing/{Money,Vat,SplitByPercent,BillingSummary,AccountantBillingRow}.php` | `AccountantBillingRow` là DTO readonly giới hạn thông tin, tiền lệ `ConflictMatch` §6.10 |
| `app/Events/MatterStageChanged.php` | `ShouldDispatchAfterCommit`, không mang trường tiền nào |
| `app/Listeners/ReleaseStageTriggeredInstalments.php` | |
| `app/Jobs/ReconcileStageTriggeredInstalments.php` + `routes/console.php` | Dòng `Schedule::` đầu tiên của dự án |
| `app/Console/Commands/CheckBillingInvariants.php` | `billing:check-invariants` — quét mọi hợp đồng `active` |
| `app/Exceptions/{ContractTotalMismatch,ContractNotAmendable,InstalmentNotPayable,PaymentExceedsInstalment,MatterHasOutstandingBalance,BillingModelNotSupported}.php` | |
| `app/Filament/Admin/Resources/Matters/RelationManagers/BillingRelationManager.php` | Tab "Hợp đồng và thanh toán" trên trang vụ việc |
| `app/Filament/Admin/Pages/RevenueDashboard.php` | Trang doanh thu, `HasFiltersForm` |
| `app/Filament/Admin/Widgets/Revenue/{ReceivablesDonut,RevenueOverTime,RevenueByStage,MatterMixByPracticeArea,LoadPerLawyer,ClosedWithBalance}Widget.php` | |
| `lang/vi/billing.php`, bổ sung `lang/vi/{enums,permissions,widgets,exceptions}.php` | |
| `database/seeders/{MatterTypeSeeder,ChecklistTemplateSeeder,BillingSeeder}.php`, `app/Support/StagePresets.php` | |
| `tests/Feature/Actions/Billing/*`, `tests/Feature/Authorization/BillingAccessTest.php`, `tests/Feature/Filament/RevenueDashboardTest.php` | |

---

### Task 1: Mười hai lĩnh vực hành nghề

**Files:** `database/seeders/MatterTypeSeeder.php`, `app/Support/StagePresets.php`, `database/seeders/ChecklistTemplateSeeder.php`, `app/Filament/Admin/Widgets/MattersByStageWidget.php`, `lang/vi/matter_types.php`, `tests/Feature/Seeders/MatterTypeSeederTest.php`, `tests/Feature/Filament/MattersByStageWidgetTest.php`

**Interfaces:** Produces — mười hai `matter_types` đang hoạt động, mỗi loại có bộ giai đoạn đầy đủ và ít nhất một `checklist_template`. Consumes — không gì.

1. Sáu loại mới: `HC`, `TM`, `NH`, `SH`, `TC`, `XD` theo bảng ở trên. Bốn loại cũ **đổi tên** cho khớp cách văn phòng tự gọi; **`code` không đổi một chữ nào** (SPEC §6.1, `matters.code` đã nhúng mã loại — đổi là làm mồ côi mọi mã hồ sơ đã sinh). Test khẳng định đúng điều đó.
2. **Bộ giai đoạn: chờ Câu hỏi 1.** Nếu chưa có câu trả lời, dùng bộ năm giai đoạn chung, đánh dấu **tạm** trong `description`, và ghi vào `docs/PROGRESS.md`. Không loại mới nào được dùng làm dữ liệu demo có `is_published_to_portal = true` cho tới khi bộ giai đoạn được duyệt — khách hàng demo sẽ đọc `client_label` và `client_description`.
3. `checklist_templates`: SPEC §12 đòi 12 đầu mục cho đất đai và **hai** template khác. Sáu loại mới mỗi loại cần ít nhất một template tối thiểu để `ApplyChecklistTemplate` không sinh ra một vụ việc không có danh mục nào.
4. **Vá `MattersByStageWidget`** (việc mang sang từ M3, qua M4 Task 7 và M5 Task 7): gộp theo `(matter_type_id, stage)` thay vì theo `label`. Với 12 loại, "Tiếp nhận" xuất hiện ở cả 12 nên lỗi này thành chắc chắn. Nếu M4/M5 đã vá thì chỉ thêm test hai loại trùng nhãn để nó không hồi quy.
5. **Không có migration** trong task này. Nếu người cài đặt thấy mình cần một migration thì đó là một sai lệch so với kế hoạch — báo lại trước khi viết, và khi đó quy tắc round-trip MariaDB áp dụng đầy đủ.

**Test bắt buộc:** đúng 12 loại hoạt động sau `db:seed`; `code` của bốn loại đổi tên giữ nguyên; mỗi loại có ≥ 1 giai đoạn và đúng một giai đoạn `is_terminal`; mỗi loại có ≥ 1 checklist template; `MattersByStageWidget` không gộp hai loại có nhãn giai đoạn trùng nhau (mutation probe: xoá vế `matter_type_id` khỏi `groupBy` phải làm test đỏ).

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: mười hai lĩnh vực hành nghề của văn phòng, không phải sáu`.

---

### Task 2: Bảng, model, enum, factory cho hợp đồng và thu phí

**Files:** năm migration, `app/Models/{Contract,Instalment,Payment,ContractAmendment}.php`, sáu enum, `database/factories/*`, quan hệ mới trên `Matter`/`Document`/`StageLog`, `lang/vi/enums.php`, `tests/Feature/Models/Billing/*`, `tests/Feature/Authorization/PortalCoverageTest.php`

**Interfaces:** Produces — bốn model với quan hệ, sáu enum có `label()`, factory cho cả bốn. **Không nghiệp vụ, không Action.** Consumes — `HasBlameable`, `RestrictedToClientPortal`, `HidesInternalAttributesFromPortal`, `ClientPortalScope` (M2).

Toàn bộ cột và index theo mục "Mô hình dữ liệu" ở trên. Điểm dễ sai:

- **`contracts.matter_id` là UNIQUE thật ở MariaDB**, và `contracts` **không có `deleted_at`** — hai điều này đi cùng nhau. Đây là deviation so với câu mở đầu SPEC §4; ghi lý do vào docblock model **và** vào `docs/PROGRESS.md` (M1 đã có tiền lệ deviation cho bảng nhật ký và pivot).
- **Hook `deleting` trên cả bốn model.** `Contract`: chỉ xoá được khi `draft`. `Payment` và `ContractAmendment`: không bao giờ xoá được. `Instalment`: chỉ xoá được khi hợp đồng còn `draft`. Nơi quyết định (ai được, log gì, thông điệp từ chối) thuộc về Action; hook **không hỏi gì về actor và không quyết định gì** — nó chỉ làm cho Action thành đường duy nhất. Đây chính xác là lập luận M4 đã viết cho `Document::saving` / `RegroupDocument`, và tiền lệ `Matter::forceDeleting` từ M1.
- **`ContractAmendment` chỉ-thêm**, cài như `StageLog` / `StageLogImmutable` (M1). Guard chạy qua model event, nên **cập nhật bằng query builder sẽ đi vòng qua nó** — ghi vào docblock đúng như ghi chú M1 đã ghi cho `StageLog`, để không ai tưởng nó tuyệt đối.
- **Cả năm model đóng kín ở portal.** `PortalCoverageTest` phải xanh **không** thêm dòng miễn trừ nào. Mỗi model có một test riêng khẳng định dưới guard `client` truy vấn trả về rỗng, và một mutation probe chứng minh test đó đỏ khi gỡ trait.
- Cast: `'total_amount' => 'integer'`, `'amount' => 'integer'`. `unsignedBigInteger` ở MariaDB vượt `PHP_INT_MAX` về lý thuyết; không hợp đồng nào tới đó, nhưng ghi một câu trong docblock để người sau không phải tự hỏi.
- **Morph map**: nếu có cột `*_type` nào thì phải đăng ký alias trong `AppServiceProvider` (bắt buộc từ M1). Theo thiết kế hiện tại **không có** cột morph nào trong M9 — nếu người cài đặt thấy mình cần một cái, đó là một sai lệch, báo lại.

**Bắt buộc trên MariaDB thật:** `migrate:fresh --seed`, rồi `migrate:reset` → `migrate`, **dán nguyên văn output vào báo cáo**. Đây là task có nhiều unique composite và khoá ngoại nhất kể từ M1. Kiểm tay rằng mỗi migration có `down()` thật sự đảo được (bài học medialibrary ở M4: `migrate:reset` in DONE rồi im lặng không làm gì).

- [ ] Test đỏ, cài đặt, test xanh, pint, **round-trip MariaDB dán vào báo cáo**, commit `feat: bảng hợp đồng, đợt thanh toán, khoản thu và phụ lục`.

---

### Task 3: Bốn quyền mới, policy, và đính chính SPEC §5 — **giao Opus**

**Files:** `app/Enums/Permission.php`, `database/seeders/RolesAndPermissionsSeeder.php`, năm policy mới, `app/Support/Billing/AccountantBillingRow.php`, `docs/SPEC.md` (§1, §5, §13, §15), `lang/vi/permissions.php`, `tests/Feature/Authorization/BillingAccessTest.php`

SPEC §5 chốt ở **13 quyền** và `app/Enums/Permission.php` nói thẳng *"Đúng 13 quyền ở SPEC §5"*. M9 cần thêm, nên **SPEC phải được sửa bằng một đính chính có ngày**, theo đúng kiểu "Đính chính 2026-09-16" dưới §6.10 — **không** thêm lặng lẽ vào enum.

**Bốn quyền mới, nâng bảng lên 17:** `billing.view`, `contract.manage`, `payment.record`, `revenue.viewAny`.

**Văn bản đính chính — chép nguyên văn vào `docs/SPEC.md`, ngay dưới ma trận quyền ở §5:**

> **Bổ sung 2026-09-19 (M9 — hợp đồng dịch vụ và thu phí theo đợt).** Danh sách 13 quyền ở trên được viết cho phạm vi bản 1.0, vốn **không có tiền** — §1 xếp "hợp đồng dịch vụ và đợt thanh toán, công nợ" vào phần ngoài phạm vi. M9 đưa chúng vào hệ thống, và không quyền nào trong 13 quyền trên diễn tả được chúng: `matter.view` là quyền đọc **nội dung hồ sơ**, còn tiền là một trục riêng — kế toán phải thấy tiền của mọi vụ việc trong khi vẫn **không** được thấy nội dung, còn luật sư phải thấy tiền của vụ mình mà **không** thấy doanh thu toàn văn phòng. Thêm **bốn** quyền:
>
> | Quyền | admin | manager | lawyer | assistant | accountant |
> |---|---|---|---|---|---|
> | `billing.view` (hợp đồng, đợt thanh toán và khoản thu của một vụ việc) | ✓ | ✓ | ✓ (vụ của mình) | — | ✓ (mọi vụ, **không kèm nội dung hồ sơ**) |
> | `contract.manage` (soạn, kích hoạt, ký phụ lục, huỷ hợp đồng) | ✓ | ✓ | ✓ (vụ của mình) | — | — |
> | `payment.record` (ghi nhận và huỷ một khoản thu) | ✓ | ✓ | — | — | ✓ |
> | `revenue.viewAny` (số liệu doanh thu toàn văn phòng) | ✓ | ✓ | — | — | ✓ |
>
> Cặp `billing.view` / `revenue.viewAny` lặp lại đúng cặp `matter.view` / `matter.viewAny` đã có ở bảng trên: một quyền cho từng bản ghi mình có phần, một quyền cho toàn văn phòng. Đây là thành ngữ sẵn có của bảng này, không phải một kiểu đặt tên mới.
>
> **Ranh giới của kế toán, viết ra vì đây là một sự nới rộng.** §1 và bảng trên nói kế toán "chỉ xem danh sách vụ việc, không thấy nội dung hồ sơ". `billing.view` **không** làm câu đó sai đi: màn hình tiền của kế toán mang mã hồ sơ, loại vụ việc, tên khách hàng, các con số và các ngày — và **không** mang tiêu đề vụ việc, tóm tắt, mô tả nội bộ, tài liệu, tiến độ hay các bên. Ranh giới này cài bằng một DTO readonly đúng như `ConflictMatch` ở §6.10, **không** bằng quy ước, và có test khẳng định tiêu đề vụ việc không lọt ra. Điểm **mới thật sự** so với bảng cũ là **tên khách hàng**: kế toán không có `client.manage`, nhưng không có tên thì không lập được phiếu thu — nên đây là một sự nới rộng có chủ đích, không phải một hệ quả suy ra.
>
> **`contract.manage` cũng là quyền đổi số tiền của từng đợt.** Cùng loại chú ý như dòng đã ghi dưới bảng về `document.publish`: cấp `contract.manage` cho một vai trò mới là cấp luôn quyền đổi lịch thu và giá trị của một hợp đồng đã ký — qua phụ lục, kèm lý do, có dấu vết, nhưng vẫn là đổi.

Kèm theo, ba sửa đổi nhỏ trong cùng commit: **§1** bỏ "hợp đồng dịch vụ và đợt thanh toán, công nợ" khỏi danh sách ngoài phạm vi (giữ nguyên "QR VietQR", "Zalo ZNS", "ký số", …); **§13** thêm một dòng **M9**; **§15** đánh dấu mệnh đề `contracts`/`instalments` là đã làm và ghi `time_entries` còn ở dạng khung.

**Policy — bốn điểm khó:**

1. **`ContractPolicy::view` không được hỏi "người này có xem được vụ việc không".** Kế toán không xem được vụ việc (§5) nhưng phải xem được tiền của nó. Điều kiện đúng: có `billing.view`, **và** (có `matter.viewAny` **hoặc** có tên trong đội ngũ vụ việc đó). Đây là nơi test sẽ rỗng ruột nếu người viết dùng luật sư làm nhân chứng — **nhân chứng bắt buộc là KẾ TOÁN**, và phải có một cặp khẳng định dương: chính tài khoản đó **đọc được** hợp đồng và **không đọc được** vụ việc.
2. **Vụ việc `confidentiality = restricted`** (§4.6, chỉ lead lawyer và admin). Tiền của một vụ hạn chế thì sao? Kế hoạch chốt: **kế toán vẫn thấy tiền, không thấy nội dung** — vì `restricted` bảo vệ nội dung hồ sơ, và không thu được tiền của một vụ việc là một hệ quả không ai muốn. Ghi lý do vào docblock và **ghim bằng test**, để một lần đổi ý sau này là một lần đổi có chủ đích.
3. **DTO `AccountantBillingRow`, readonly, đúng số trường đã liệt kê.** Tiền lệ `ConflictMatch` (§6.10): ranh giới cài bằng kiểu dữ liệu chứ không bằng quy ước, và có test khẳng định tiêu đề vụ việc không lọt ra — chép đúng hình dạng test đó.
4. **Mutation probe là bắt buộc ở task này và đây là chỗ nó hay sống sót nhất.** Bài học M4: xoá vế `document.publish` trong `RegroupDocument` để lại bộ test **xanh**, vì trợ lý trong test đã bị `view()` từ chối trước rồi — từ chối vì **không được nhìn**, không phải vì **không được quyết**. Với mỗi vế quyền thêm vào, nhân chứng phải là một tài khoản **được cấp quyền trực tiếp** sao cho chỉ đúng vế đang thử là thứ chặn họ, kèm một cặp khẳng định dương chứng minh họ thật sự qua được mọi cổng khác.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: bốn quyền cho tiền, và đính chính SPEC §5 kèm ngày`.

---

### Task 4: Hợp đồng — soạn, kích hoạt, phụ lục, và bất biến tổng — **giao Opus**

**Files:** `app/Actions/Billing/{DraftContract,ActivateContract,AmendContract,CancelContract,CompleteContract}.php`, `app/Support/Billing/{SplitByPercent,Vat,Money}.php`, `app/Console/Commands/CheckBillingInvariants.php`, `app/Exceptions/{ContractTotalMismatch,ContractNotAmendable}.php`, `lang/vi/billing.php`, tests

**Interfaces:** Produces — `DraftContract::handle(User $actor, Matter $matter, array $attributes, array $instalments): Contract`; `ActivateContract::handle(User $actor, Contract $contract, DateTimeInterface|string $signedAt): Contract`; `AmendContract::handle(User $actor, Contract $contract, int $newTotalAmount, array $instalmentChanges, string $reason, DateTimeInterface|string $signedAt, ?Document $document = null): ContractAmendment`. Actor **tường minh**, đứng đầu, đúng quy ước M3. Consumes — `CodeSequence::next()`, `Audit::record()`, `Gate`.

**Bất biến, và bốn tầng giữ nó.** `SUM(instalments.amount) === contracts.total_amount`, tính bằng số nguyên đồng, không có dung sai.

1. **`ActivateContract`** — không khớp thì hợp đồng **không rời được `draft`**. Đây là cổng chính.
2. **Hook `saving`/`deleting` trên `Instalment`** — từ chối mọi lần ghi làm lệch tổng trên một hợp đồng `active`. Cùng lập luận M4 đã viết cho `Document::saving`: quyết định thuộc về Action, hook không hỏi gì về actor và không quyết gì, nó chỉ làm Action thành đường duy nhất — **vì thứ nó chặn CHÍNH LÀ đường đi vòng qua Action**. Một bất biến chỉ được Action giữ là bất biến cho tới màn hình đầu tiên quên gọi Action.
3. **`AmendContract`** — đổi tổng mà không đổi lịch (hoặc ngược lại) phải thất bại; cả hai đi trong **một** transaction.
4. **`billing:check-invariants`** — lệnh console quét mọi hợp đồng `active` và in ra những hợp đồng lệch. Ba tầng trên chặn dữ liệu mới; lệnh này là cách tìm ra dữ liệu **đã** lệch (seeder cũ, import, một migration sai). Có test chạy lệnh trên một hợp đồng cố ý làm lệch bằng `DB::table()` — tức đúng đường đi vòng mà ba tầng trên không phủ.

**`SplitByPercent` — phần dư rơi ở đâu.** Một câu, một chỗ: **mọi đợt trừ đợt cuối lấy `intdiv(total × p, 100)`; đợt cuối lấy `total − tổng các đợt trước`.** Màn hình hiện số đồng của cả ba đợt **trước khi** lưu, nên người dùng nhìn thấy 1 đồng đi đâu thay vì được kể lại sau. Test: 33/33/34 trên 100.000.000; chia ba đều trên 10.000.000 (kết quả 3.333.333 / 3.333.333 / 3.333.334); và một test khẳng định `percent_basis` được **lưu nguyên** nhưng **không bao giờ** được dùng để tính lại `amount` (mutation probe: đổi Action sang tính lại từ `percent_basis` phải làm test đỏ).

**`Vat`** — `tax = intdiv(total × r, 100 + r)`, phần chưa thuế nhận phần dư, hai số luôn cộng lại đúng `total_amount`. Test với 33.333.333 đ ở 10%.

`DraftContract`: sinh `code` qua `CodeSequence` (tiền lệ §6.1); từ chối `billing_model` khác `fixed_fee` bằng `BillingModelNotSupported` với thông điệp tiếng Việt nói rõ đây là việc của giai đoạn sau (xem Task 9). `on_signing` để `due_date` rỗng ở bản nháp. `stage` đòi `trigger_stage_key` **là một giai đoạn có thật của đúng loại vụ việc đó** — kiểm qua `MatterType::stage()`, không phải qua một danh sách chép tay.

`ActivateContract`: đặt `signed_at`, `activated_by`, `status = active`; điền `due_date` cho mọi đợt `on_signing`; và **gọi `TriggerInstalmentsForStage`** (Task 6) cho những đợt mà vụ việc **đã** đi qua giai đoạn kích hoạt. *(Phụ thuộc chiều Task 6 → Task 4: nếu Task 6 chưa xong thì để lại một điểm nối tường minh và một test `todo`, đừng cài một bản sao logic — hai bản sao của một luật kích hoạt là đúng thứ M3 đã phải gộp lại ba lần.)*

`AmendContract`: chỉ trên hợp đồng `active`; lý do ≥ 20 ký tự **`mb_strlen`**; sinh dòng `contract_amendments` với `previous_total_amount` đọc từ chính hàng đang khoá (`lockForUpdate`), không từ đối tượng caller đưa vào — bài học M4 `PublishDocument`, nơi một `group` bị sửa trong bộ nhớ đã được thử và bị chặn đúng vì lý do này.

Mọi Action ghi `Audit::record(..., $actor)` **bên trong** transaction (bài học M4 I4: cửa sổ giữa commit và `Audit::record` là chỗ một tiến trình chết để lại một thay đổi không có dòng nhật ký; và nói thẳng rằng **không mutation probe nào phân biệt được hai vị trí** — đừng tuyên bố có test cho nó).

**Test bắt buộc:** tổng lệch 1 đồng → không kích hoạt được; ghi thẳng một `Instalment` lệch tổng trên hợp đồng `active` bằng model → bị từ chối; phần dư rơi vào đợt cuối (ba trường hợp); `percent_basis` không bao giờ tính lại `amount`; phụ lục lưu đúng giá trị cũ; phụ lục không lý do → `ValidationException`; lý do 19 ký tự tiếng Việt có dấu **bị từ chối** và 20 ký tự **được chấp nhận** (đây là test phân biệt `mb_strlen` với `strlen`); hợp đồng `draft` xoá được, `active` thì không; `billing_model = hourly` bị từ chối kèm thông điệp đọc được; `billing:check-invariants` tìm ra một hợp đồng lệch được tạo bằng `DB::table()`.

- [ ] Test đỏ, cài đặt, test xanh, pint, **mutation probe cho từng điều kiện, dán bằng chứng đỏ**, commit `feat: hợp đồng dịch vụ với bất biến tổng các đợt`.

---

### Task 5: Khoản thu, miễn, huỷ — và trạng thái suy ra — **giao Opus**

**Files:** `app/Actions/Billing/{RecordPayment,VoidPayment,WaiveInstalment,CancelInstalment}.php`, `app/Models/Instalment.php` (`state()`, `outstanding()`, `scopeOverdue()`), `app/Support/Billing/BillingSummary.php`, `app/Exceptions/{InstalmentNotPayable,PaymentExceedsInstalment}.php`, tests

**Interfaces:** Produces — `RecordPayment::handle(User $actor, Instalment $instalment, int $amount, DateTimeInterface|string $paidOn, PaymentMethod $method, ?string $reference, ?Document $receipt, ?string $note): Payment`; `VoidPayment::handle(User $actor, Payment $payment, string $reason): Payment`; `Instalment::state(): InstalmentState`; `Instalment::outstanding(): int`; `Instalment::scopeOverdue()`. Consumes — Task 4.

**Điểm phải cài đúng:**

- **Thu một phần là bình thường**, không phải lỗi. `RecordPayment` chấp nhận số tiền nhỏ hơn số còn lại. **Thu vượt** thì từ chối (`PaymentExceedsInstalment`) và thông điệp nói rõ phải làm gì: hoặc sửa số, hoặc ghi phần vượt vào đợt sau. *Không* tự động rải sang đợt sau — tự động chia tiền của khách là thứ phải có người quyết.
- **`paid_on` không được ở tương lai.** So theo NGÀY ở múi giờ ứng dụng, sao chép chính xác cách `TransitionMatterStage` bước 3 làm (`config('app.timezone')`, `today()->startOfDay()`), **không** phát minh lại.
- **`status = paid` viết khi tổng các khoản thu chưa huỷ ≥ `amount`**, tính lại trong cùng transaction với `lockForUpdate` trên đợt. Hai người ghi hai khoản cùng lúc là chuyện có thật ở một văn phòng có hai kế toán.
- **`VoidPayment` phải hạ `status` trở lại `pending`** nếu việc huỷ làm tổng tụt xuống dưới `amount`. Đây là đường dễ quên nhất trong cả task; có test riêng và một mutation probe.
- **`Instalment::state()` là chỗ duy nhất** quá hạn được định nghĩa, và `scopeOverdue()` phải là **cùng một điều kiện** viết bằng SQL. Có test khẳng định hai cách cho **cùng** kết quả trên một tập dữ liệu cố tình gồm cả biên (đến hạn đúng hôm nay = chưa quá hạn; đã miễn = không quá hạn dù ngày đã qua; đã huỷ = không quá hạn).
- **`WaiveInstalment`** đòi lý do ≥ 20 ký tự `mb_strlen`. **Miễn không làm thay đổi `contracts.total_amount`** — giá trị thoả thuận vẫn là giá trị thoả thuận; miễn là một quyết định về việc **thu**, không phải về việc **đã thoả thuận bao nhiêu**. Hệ quả cần nhìn thấy trên dashboard: "còn phải thu" phải trừ phần đã miễn ra, nếu không donut sẽ mãi mãi không khép. Ghi vào docblock của `BillingSummary`.
- Mọi Action ghi `Audit::record` trong transaction với `$actor` tường minh và `blameOn($actor)` trước `save()`.

**Test bắt buộc:** thu một phần → `state()` là `partially_paid`, `status` vẫn `pending`; thu đủ → `paid`; thu vượt → từ chối kèm thông điệp đọc được; huỷ một khoản làm tụt xuống dưới đủ → `status` về `pending` và `state()` về `overdue` nếu ngày đã qua; huỷ không lý do → lỗi xác thực; `paid_on` ngày mai → lỗi xác thực; `state()` và `scopeOverdue()` khớp nhau trên tập biên; một khoản thu **không xoá được** bằng bất kỳ đường nào (thử cả `delete()` lẫn `forceDelete()`); `created_by` là actor được truyền vào **chứ không phải** người đang đăng nhập trong session (test phải đăng nhập một người và truyền một người **khác** — đây là thiết kế test duy nhất bắt được lỗi ambient auth, đúng như `RunConflictCheckActorTest` ở M3).

- [ ] Test đỏ, cài đặt, test xanh, pint, **mutation probe từng điều kiện**, commit `feat: khoản thu, miễn và huỷ, với trạng thái suy ra thay vì lưu`.

---

### Task 6: Đợt thanh toán kích hoạt theo giai đoạn

**Files:** `app/Events/MatterStageChanged.php`, `app/Listeners/ReleaseStageTriggeredInstalments.php`, `app/Actions/Billing/TriggerInstalmentsForStage.php`, `app/Jobs/ReconcileStageTriggeredInstalments.php`, `app/Actions/TransitionMatterStage.php` (**một dòng dispatch + sửa lỗi I6 mang sang**), `routes/console.php`, `app/Providers/AppServiceProvider.php`, tests

**Interfaces:** Produces — `TriggerInstalmentsForStage::handle(Matter $matter, string $stageKey, StageLog $stageLog): int` (trả số đợt đã kích hoạt). **Không có tham số `$actor`**: kích hoạt một đợt không phải một quyết định của ai cả, nó là hệ quả của một sự kiện — và ghi một actor vào đó là **bịa ra một thẩm quyền không tồn tại**. `Audit::record` cho dòng này để `causer` rỗng và ghi `stage_log_id` làm nguồn gốc; tiền lệ: `RunConflictCheck` nhận `?User $actor = null` và ghi `actor_explicit` để người đọc không bao giờ nhầm một causer suy ra với một causer được khẳng định.

Toàn bộ thiết kế ở mục "Đợt thanh toán theo giai đoạn" phía trên. Người cài đặt đọc lại mục đó nguyên văn trước khi viết dòng đầu tiên.

Ngoài ra, trong cùng task vì đây là task duy nhất của M9 mở tệp đó:

- **Sửa lỗi I6 mang sang trong `TransitionMatterStage`**: `occurredAt`/`expectedNextUpdateAt` nhận `DateTimeInterface|string` rồi `Carbon::parse`, nên một chuỗi không hợp lệ ném `Carbon\InvalidFormatException` — **ngoài** hợp đồng `DomainException` mà mọi màn hình được dặn bắt, tức là một trang 500. Sửa đúng cách M4 đã sửa `UploadStaffDocument::$issuedAt`: parse tường minh, chuỗi hỏng → `ValidationException` trên đúng trường, chuỗi rỗng nghĩa là "không có ngày" chứ không phải hôm nay.
- **Grep lại `matters.stage`.** Hôm nay `TransitionMatterStage` là nơi ghi duy nhất trong `app/`. Nếu M6/M7 đã thêm một Action đổi giai đoạn (`ReassignMatter`, một `CloseMatter`), Action đó **cũng phải** dispatch `MatterStageChanged` — và đó chính là chỗ nó sẽ bị quên. Dán kết quả grep vào báo cáo.

**Test bắt buộc:** chuyển giai đoạn tới giai đoạn kích hoạt → đúng đợt đó có `due_date` và `triggered_at`, và `triggered_by_stage_log_id` trỏ đúng dòng vừa tạo; **một dòng cập nhật cùng giai đoạn (§6.3) KHÔNG kích hoạt gì** (đây là test quan trọng nhất của task); vào lại cùng giai đoạn lần hai **không** kích hoạt lại; hợp đồng `draft` không bị kích hoạt; `occurred_at` ghi lùi ngày sinh ra một đợt đã quá hạn; transaction rollback → listener **không** chạy (kiểm bằng cách bọc lời gọi trong một transaction ngoài rồi ném lỗi — đây là thứ `ShouldDispatchAfterCommit` hứa, và phải chứng minh chứ không phải tin); job đối chiếu kích hoạt được một đợt **thêm sau khi** vụ việc đã đi qua giai đoạn đó; job chạy hai lần chỉ kích hoạt một lần.

- [ ] Test đỏ, cài đặt, test xanh, pint, mutation probe, commit `feat: đợt thanh toán tự đến hạn khi vụ việc chạm giai đoạn`.

---

### Task 7: Tab "Hợp đồng và thanh toán" trên trang vụ việc

**Files:** `app/Filament/Admin/Resources/Matters/RelationManagers/BillingRelationManager.php` + form/action classes, `app/Filament/Admin/Resources/Matters/Pages/ViewMatter.php`, `app/Filament/Admin/Resources/Matters/Tables/MattersTable.php` (một cột), `lang/vi/billing.php`, `tests/Feature/Filament/BillingRelationManagerTest.php`

Tab thứ chín của SPEC §7.2 (SPEC §7.2 liệt kê tám tab; tab này là mở rộng đi cùng đính chính §5 ở Task 3 — ghi rõ trong PROGRESS rằng đây là một tab **thêm**, không phải một tab SPEC đã liệt kê).

Nội dung: giá trị hợp đồng, thuế suất và ba con số VAT; trạng thái; lịch thu dạng bảng dọc với từng đợt (tên, số tiền, kích hoạt bằng gì, đến hạn ngày nào, đã thu bao nhiêu, trạng thái hiển thị theo `InstalmentState`); các khoản thu dưới từng đợt; phụ lục; và một dòng tổng **đã thu / còn phải thu / quá hạn**.

- **Ba màu có nghĩa xuyên suốt** (nguyên tắc từ toolchain §4, đã dùng ở M5): xanh đã thu, vàng đang chờ, đỏ quá hạn. **Màu không được là kênh thông tin duy nhất** — luôn kèm chữ.
- Nút gọi Action, **không** tự viết nghiệp vụ. Mọi nút **bắt `DomainException`** và đổi thành lỗi trên form.
- **Cổng quyền hỏi kèm ngữ cảnh.** Bài học M4 I3 và M5: nhánh không-ngữ-cảnh của một `create()` policy trả lời câu hỏi *giao diện*, và mọi lần Filament tự hỏi đều **không** truyền ngữ cảnh. Dùng đúng thành ngữ `PartiesRelationManager` đang dùng: `->authorize(fn () => Gate::allows('create', [Payment::class, $this->getOwnerRecord()]))`.
- **Bẫy `RelationManager::isReadOnly()` mặc định `true` trên trang `ViewRecord`** (M3). Và **lọc qua `ScopesToVisibleMatters`** như mọi relation manager khác.
- **Dải cảnh báo** khi vụ việc đã ở giai đoạn `is_terminal` mà còn công nợ (xem "Tiền trên một vụ việc đã đóng").
- Một cột trên bảng danh sách vụ việc: **còn phải thu**, hiện cho ai có `billing.view`, ẩn hẳn cột với ai không có.

**Test bắt buộc:** luật sư thấy tab trên vụ của mình, không thấy trên vụ khác; **kế toán thấy tab nhưng KHÔNG thấy tiêu đề vụ việc ở bất kỳ đâu trên màn hình đó** (test quét chuỗi đánh dấu duy nhất, đúng hình dạng test `internal_note` của §11); trợ lý không thấy tab; luật sư không thấy nút ghi nhận khoản thu (không có `payment.record`); kế toán không thấy nút soạn hợp đồng; mỗi `DomainException` của Task 4 và 5 hiện thành lỗi trên form chứ không phải 500 — **một `it()` riêng cho từng trường hợp** (false-green `PersistentMiddleware`); vào thẳng URL tab của một vụ không có quyền → **404**.

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: tab hợp đồng và thanh toán trên trang vụ việc`.

---

### Task 8: Trang doanh thu

**Files:** `app/Filament/Admin/Pages/RevenueDashboard.php`, `app/Filament/Admin/Widgets/Revenue/*.php`, `app/Support/Billing/{Money,BillingSummary}.php`, `lang/vi/widgets.php`, `tests/Feature/Filament/RevenueDashboardTest.php`

Toàn bộ thiết kế ở mục "Trang doanh thu" phía trên — **đọc lại nguyên văn trước khi viết**, gồm cả câu cấm ô đổi kiểu biểu đồ, thứ **phải** được chép vào docblock của trang.

Năm widget: `ReceivablesDonutWidget` (donut, ba lát, kèm ba con số bằng chữ), `RevenueOverTimeWidget` (cột, `$filter` tháng/quý/năm — `ChartWidget::$filter` là cơ chế có sẵn), `MatterMixByPracticeAreaWidget` (cột ngang **xếp hạng**, 12 lĩnh vực), `LoadPerLawyerWidget` (cột ngang, đòi `revenue.viewAny`), `ClosedWithBalanceWidget` (bảng — vụ đã kết thúc còn công nợ).

**Đừng làm lại `MattersByStageWidget`** — nó đã có ở trang chủ §7.1 và Task 1 đã vá nó. Trang doanh thu tham chiếu lại nó chứ không tạo bản thứ hai.

**Bốn thứ dễ làm sai:**

1. **Mỗi widget in ra nghĩa của bộ lọc thời gian lên chính nó.** Cột doanh thu lọc theo `payments.paid_on`; donut và cơ cấu lĩnh vực lọc theo `contracts.signed_at`, còn quá hạn tính tại **hôm nay**. Một bộ lọc mang hai nghĩa mà không nói ra là cách nhanh nhất để một dashboard nói dối. Có test khẳng định nhãn có mặt.
2. **Tiền và số đếm không bao giờ chung một trục.** Công tắc số vụ / số tiền đổi thứ được đo.
3. **Định dạng qua `Money::format()` ở mọi nơi**, kể cả tooltip Chart.js. Có test.
4. **Hiệu năng** (việc mang sang từ M2): `MatterPolicy::view` chạy một EXISTS mỗi lần gọi và các widget chạm rất nhiều vụ việc. Dùng `Matter::scopeListableBy()` ở tầng truy vấn, **không** gọi policy theo dòng. Test đếm truy vấn với một ngưỡng **nêu rõ lý do của con số đó**, không phải một con số tròn trịa đặt cho đẹp.

**Test bắt buộc:** luật sư mở được trang và **chỉ** thấy số liệu của vụ mình (dựng hai luật sư và khẳng định hai con số khác nhau, không phải chỉ khẳng định trang mở được); luật sư **không** thấy hai widget toàn văn phòng; kế toán thấy đủ; trợ lý vào thẳng URL → **404**; ba lát donut cộng lại đúng tổng giá trị đã ký trong kỳ trừ phần đã miễn; đổi bộ lọc thời gian đổi đúng những widget nói rằng nó đổi và **không** đổi những widget khác; một hợp đồng của vụ việc đã xoá mềm **không** xuất hiện trong bất kỳ con số nào; một khoản thu đã huỷ **không** được cộng; đủ 12 lĩnh vực xuất hiện trong biểu đồ cơ cấu kể cả lĩnh vực có 0 vụ (một lĩnh vực biến mất vì không có vụ nào là một câu trả lời sai cho câu hỏi "cơ cấu của văn phòng").

- [ ] Test đỏ, cài đặt, test xanh, pint, commit `feat: trang doanh thu với biểu đồ lọc theo kỳ, luật sư và lĩnh vực`.

---

### Task 9: Khung `time_entries` — **task có thể bỏ**

**Files:** migration `time_entries`, `app/Models/TimeEntry.php`, `app/Policies/TimeEntryPolicy.php`, `Matter::timeEntries()`, `User::timeEntries()`, factory, `docs/SPEC.md` §15, tests

SPEC §15: *"Riêng `time_entries` tuy chưa làm ở bản 1.0 nhưng nên tạo sẵn quan hệ trong model `Matter`, vì khi văn phòng chuyển sang tính phí theo giờ thì đây là thứ khó gắn thêm sau nhất."* Ghi chú M1 đã hoãn nó *"sang giai đoạn 2 cùng bảng `time_entries`"*. **Giai đoạn 2 chính là đây.**

**Phạm vi chính xác: bảng, model, quan hệ, policy đóng kín, factory. KHÔNG Action, KHÔNG màn hình, KHÔNG một con số nào trên dashboard đọc bảng này.** `contracts.billing_model` đã có từ Task 2 với chỉ `fixed_fee` đi qua được; đó là nửa quan trọng hơn của "chừa chỗ", vì thứ thật sự khó gắn sau không phải cái bảng mà là **việc "giá trị hợp đồng" đổi nghĩa** khi có tính phí theo giờ.

**Task này đứng cuối và không có task nào phụ thuộc vào nó.** Nếu chủ văn phòng trả lời "không" cho Câu hỏi 6, xoá đúng task này và không có gì khác phải sửa.

**Test bắt buộc:** `PortalCoverageTest` xanh không thêm dòng miễn trừ; `TimeEntryPolicy` từ chối mọi `ClientUser`; `Matter::timeEntries()` và `User::timeEntries()` trả đúng quan hệ; **không tệp nào ngoài thư mục này tham chiếu `TimeEntry`** (một test grep — nó là thứ giữ cho task này xoá được).

- [ ] Test đỏ, cài đặt, test xanh, pint, **round-trip MariaDB**, commit `feat: khung time_entries cho mô hình tính phí theo giờ về sau`.

---

### Task 10: Dữ liệu mẫu, đi bộ tay, tài liệu

**Files:** `database/seeders/BillingSeeder.php`, `database/seeders/DatabaseSeeder.php`, `docs/PROGRESS.md`, `README.md`, `docs/superpowers/specs/2026-09-14-vk-crm-toolchain.md`

1. **Dữ liệu mẫu đủ để dashboard vẽ ra một bức tranh THẬT**, không phải một bức tranh có màu. SPEC §12 là sàn; M9 thêm:
   - Mọi vụ việc đã rời `intake` có một hợp đồng `active`; vài vụ ở `intake` có hợp đồng `draft`; ít nhất một vụ **cố ý không có hợp đồng** (để màn hình có trạng thái rỗng thật).
   - Giá trị rải trong khoảng có thật ở Việt Nam: 15.000.000 – 450.000.000 đ. Vài hợp đồng có VAT 8% và 10%, vài hợp đồng `vat_rate_percent = null`.
   - Lịch thu ba đợt theo hình dạng thường gặp (tạm ứng khi ký 30% / khi nộp đơn 40% / khi có bản án 30%), và **ít nhất bốn hợp đồng dùng kích hoạt theo giai đoạn** để đường đó có dữ liệu demo.
   - **Ít nhất hai đợt quá hạn** — một theo ngày, một theo giai đoạn. Đây là yêu cầu tường minh: không có nó thì lát đỏ của donut và widget nhắc nợ đều trống ở buổi demo.
   - Ít nhất: một đợt thu một phần, một đợt đã miễn **kèm lý do thật đọc được** (không phải chuỗi giả của factory — chủ văn phòng sẽ đọc câu đó), một khoản thu đã huỷ kèm lý do, một hợp đồng có phụ lục, **một vụ đã kết thúc còn công nợ**.
   - Khoản thu rải trên **ít nhất tám tháng** để biểu đồ cột có hình dạng chứ không phải một cột.
   - **Tổng phải khớp tuyệt đối.** Seeder là thứ đầu tiên hook bất biến của Task 4 sẽ bắt; nếu nó không chạy được thì đó là bất biến đang làm việc, không phải bất biến sai.
2. **`migrate:fresh --seed` trên container MariaDB thật**, rồi `migrate:reset` → `migrate`. Dán output.
3. **`billing:check-invariants` chạy sạch trên dữ liệu mẫu.** Dán output.
4. **Đi bộ tay**, ghi lại từng bước và kết quả: soạn một hợp đồng ba đợt trong đó đợt 2 kích hoạt bằng giai đoạn; kích hoạt; chuyển vụ việc tới giai đoạn đó và **xác nhận đợt 2 đến hạn ngay**; ghi một khoản thu một phần; ghi nốt; huỷ một khoản và xem trạng thái lùi lại; ký một phụ lục tăng giá trị và xem lịch thu phải chỉnh lại; mở trang doanh thu và **đổi từng bộ lọc một**, xác nhận từng biểu đồ đổi đúng cái nó nói. Thử cả nhánh xấu: tổng lệch 1 đồng, thu vượt, ngày thu ở tương lai, lý do 19 ký tự tiếng Việt có dấu, và đăng nhập bằng **kế toán** để xác nhận không có tiêu đề vụ việc nào hiện ra.
5. **Việc mang sang chưa ai nhặt** (`DocumentPolicy::publish`/`::delete` cần probe lại; `MatterChecklistItem` chưa có `LogsActivity`) — nhặt nếu M4/M5 đã rơi, hoặc ghi lại rõ là vẫn còn.
6. **Cập nhật `docs/PROGRESS.md`** dòng M9 và mục "Ghi chú M9": mọi phán quyết, mọi sai lệch so với kế hoạch, mọi việc hoãn, **trả lời của chủ văn phòng cho sáu câu hỏi**, deviation "không `deleted_at`" và lý do, và **`RemindOverdueInstalments` là việc của M6**. Cập nhật `README.md` (lệnh `billing:check-invariants`, rủi ro chạy trang tiền trước M8) và mục M9 của tài liệu bộ công cụ.
7. **`security-review` bắt buộc.** Người rà soát cuối được brief là giả định có một Critical.

- [ ] Test xanh, pint sạch, commit `docs: M9 hoàn tất — hợp đồng dịch vụ và thu phí theo đợt`.

---

## Thứ tự và việc chạy song song

```
M5 đã merge
   ├── Task 1 (12 lĩnh vực) ────────────────────────────────────────┐
   └── Task 2 (bảng + model) ──┬── Task 3 (quyền + policy) [Opus] ──┤
                               │                                    │
                               └── Task 9 (khung time_entries)      │
                                                                    │
            Task 4 (hợp đồng) [Opus] ───┬── Task 5 (khoản thu) [Opus] ──┬── Task 6 (theo giai đoạn)
                                        │                               │
                                        └───────── Task 7 (tab vụ việc) ┘
                                                                        │
                                                      Task 8 (trang doanh thu)
                                                                        │
                                                      Task 10 (mẫu + tài liệu)
```

- **Task 1 và Task 2 chạy song song được** — tệp rời nhau hoàn toàn (một bên seeder cấu hình, một bên migration và model). Task 1 **phải xong trước Task 8**, vì biểu đồ cơ cấu lĩnh vực là lý do nó tồn tại.
- **Task 3 và Task 9 chạy song song được** sau Task 2.
- **Task 4 cần Task 2 và Task 3.** Task 5 cần Task 4.
- **Task 6 và Task 7 chạy song song được** sau Task 5 — Task 6 đụng Action/event/job, Task 7 đụng Filament. Chỗ chạm nhau duy nhất là `lang/vi/billing.php`, nên hai người **phải commit theo đường dẫn tường minh** (bài học M3, học bằng cách làm hỏng). Có một phụ thuộc chiều ngược nhẹ: `ActivateContract` (Task 4) gọi `TriggerInstalmentsForStage` (Task 6) — để lại điểm nối tường minh và một test `todo`, **không** cài một bản sao logic.
- **Task 8 cuối cùng trong nhóm tính năng**, cần 1, 3, 4, 5, 6.
- **Task 10 một mình.**
- Giữ nguyên quy trình M3/M4/M5 vì nó vẫn đang tìm ra lỗi: một người cài đặt mới cho mỗi task, rà soát theo phạm vi task, rà soát lại theo phạm vi sau mỗi vòng sửa, rà soát toàn nhánh trước khi merge, và **brief người rà cuối là giả định có một Critical**. **Task 3, 4, 5 giao cho Opus** — đó là các task phân quyền và tiền, đúng hạng việc mà mọi Critical của dự án tới nay đều đến từ một lượt rà soát Opus.

---

## Tự rà soát kế hoạch

**Độ phủ mô tả của chủ văn phòng.** *"Ký hồ sơ là giá trị 1 lần"* → `contracts.total_amount`, unique trên `matter_id`, bất biến tổng (Task 2, 4). *"Thanh toán theo giai đoạn"* → `instalments.trigger_type = stage` + `MatterStageChanged` + job đối chiếu (Task 2, 6). *"Như các CRM luật thương mại làm"* → khoản thu là bản ghi riêng có người ghi và cách nhận, thu một phần là bình thường, phụ lục có lịch sử, công nợ quá hạn nhìn thấy được (Task 4, 5). *"Dashboard có biểu đồ lọc theo tháng, lĩnh vực…"* → Task 8, với dạng biểu đồ chọn theo câu hỏi chứ không theo sở thích.

**Độ phủ SPEC §15.** `contracts` + `instalments` gắn vào `matters` → Task 2. `time_entries` gắn vào `matters` và `users` → Task 9. Câu *"mô hình dữ liệu 1.0 phải để chỗ mở rộng mà không phải sửa lại"* → đã kiểm: **không một migration nào của M9 sửa một bảng đã có**, trừ ba quan hệ khai báo ở tầng model và một cột `reminders_sent` trên bảng mới. Tức SPEC §15 đã giữ được lời hứa của nó, và kế hoạch này là bằng chứng.

**Độ phủ §11.** M9 không thêm mục nào vào danh sách test bắt buộc §11, nhưng chạm vào ba mục đã có: *"Kế toán không xem được nội dung hồ sơ"* trở nên sắc hơn vì kế toán giờ có một màn hình mới (Task 3, 7); *"Chuyển giai đoạn sai `allowed_next` → ném exception"* phải vẫn xanh sau khi Task 6 thêm dòng dispatch; mục tiêu độ phủ 80% cho `app/Actions/` và `app/Policies/` áp cho `app/Actions/Billing/` và năm policy mới.

**Nhất quán tên gọi.** `TriggerInstalmentsForStage` sinh ở Task 6, gọi ở Task 4 (`ActivateContract`) và Task 6 (listener + job) — **ba nơi gọi, một định nghĩa**. `Instalment::state()` sinh ở Task 5, dùng ở Task 7 và Task 8 — hiển thị **không** được tự tính lại. `Money::format()` sinh ở Task 4, dùng ở Task 7 và Task 8, gồm cả tooltip Chart.js. `SplitByPercent` chỉ Task 4 gọi. `Matter::scopeListableBy()` (M2) là đường duy nhất mọi truy vấn tiền giới hạn theo người dùng. `AccountantBillingRow` sinh ở Task 3, là kiểu dữ liệu duy nhất màn hình kế toán nhận.

**Rủi ro đã lường trước.**
- *Bộ giai đoạn cho sáu lĩnh vực mới* là kiến thức hành nghề mà kế hoạch không có. Đã thành Câu hỏi 1 với một mặc định an toàn (bộ chung, đánh dấu tạm, không công bố portal) thay vì một lời đoán trông như sự thật.
- *`percent_basis`* là một cột chỉ-để-xem nằm cạnh một cột có tính quyết định — đúng hình dạng thứ ai đó sẽ dùng để tính lại. Đã có test và mutation probe riêng cho việc đó, nhưng nếu người cài đặt thấy cột đó gây hiểu nhầm nhiều hơn giúp ích thì **bỏ nó** là một lựa chọn hợp lệ; ghi lại lý do.
- *Round-trip MariaDB* ở Task 2 là nơi M9 dễ vỡ nhất: nhiều unique composite và nhiều khoá ngoại hơn bất kỳ milestone nào kể từ M1, và dự án đã vỡ đúng chỗ này hai lần.
- *Task 4 gọi Task 6* là một phụ thuộc chiều ngược. Đã xử bằng điểm nối tường minh, nhưng nếu hai người chạy song song thì đây là chỗ một bản sao logic sẽ mọc ra.

**Cố ý để lại ngoài M9.**
- **Xuất hoá đơn, hoá đơn điện tử, tờ khai thuế, đối chiếu với phần mềm kế toán.** Không có gì trong M9 sinh ra một hoá đơn.
- **Email nhắc công nợ** (`RemindOverdueInstalments`) và dòng `outbound_messages` tương ứng — **M6**. Đặc tả nằm ở mục "Phát hiện quá hạn"; M9 chỉ để sẵn cột `reminders_sent`.
- **QR VietQR và mọi hình thức đối soát ngân hàng tự động** — SPEC §1 xếp riêng, và nó là một milestone có đặc tả riêng.
- **Hợp đồng trên portal của khách** — Câu hỏi 2, đóng kín ở cả ba tầng cho tới khi có chữ ký.
- **Bảng kê thanh toán trong gói bàn giao** (§6.12) — **M7**, ghi vào PROGRESS để M7 không phải tự nghĩ ra.
- **Tính phí theo giờ** — chỉ có khung bảng (Task 9) và một cổng từ chối ở `billing_model`.
- **Ô cho người dùng tự đổi kiểu biểu đồ** — không phải bị quên; bị từ chối, kèm lý do, trong docblock của trang.

**Điều tôi ít chắc nhất.** Bốn thứ, theo thứ tự đáng lo:

1. **Không có cột `paid_amount`.** Tôi tin đây là quyết định đúng — một cột tổng hợp về tiền là nguồn sự thật thứ hai và nó **sẽ** lệch. Nhưng tôi đang đánh đổi một chỗ hỏng chắc chắn lấy một chi phí truy vấn tôi **chưa đo**, và trang doanh thu là nơi chi phí đó cộng dồn (năm widget, mỗi widget một phép join qua bốn bảng, nhân với mọi vụ việc người dùng thấy). Nếu Task 8 đo ra một con số xấu, câu trả lời đúng **không phải** là thêm cột `paid_amount` cho ai cũng ghi được — mà là một bảng tổng hợp **có job dựng lại được hoàn toàn từ `payments`**, nghĩa là nó không bao giờ là nguồn sự thật. Người cài Task 8 phải đo và báo lại con số thật, đừng im lặng chịu đựng.
2. **Vị trí trong thứ tự dựng.** Tôi viết ở đầu rằng M9 đi sau M5, và tôi tin điều đó. Nhưng tôi cũng biết tiền là thứ chủ văn phòng cảm nhận được ngay còn cổng khách hàng là thứ khách hàng cảm nhận được — và người trả tiền cho dự án là chủ văn phòng. Nếu câu trả lời là "làm tiền trước", tôi nghĩ nó **sai về thứ tự giá trị** nhưng **không sai về mặt kỹ thuật**: M9 chạy được ngay sau M4. Ba cái giá đã liệt kê ở đầu kế hoạch là đủ để quyết định một cách có hiểu biết, và đó là tất cả những gì một kế hoạch làm được.
3. **`TriggerInstalmentsForStage` không nhận actor.** Tôi cho rằng kích hoạt một đợt là hệ quả chứ không phải quyết định, nên ghi một actor vào đó là bịa ra thẩm quyền. Nhưng có một cách đọc ngược lại cũng đứng được: người chuyển giai đoạn **chính là** người gây ra việc đợt đó đến hạn, và một dòng nhật ký không có causer là một dòng khó dùng khi văn phòng phải giải thích với khách vì sao có một khoản phải thu. Tôi đã nghiêng theo tiền lệ `RunConflictCheck` (`?User $actor = null` + `actor_explicit`), nhưng nếu người rà soát thấy ngược lại thì lập luận của họ đáng nghe — và `stage_log_id` trong properties đã là một đường truy ngược tới người chuyển giai đoạn, nên cái giá của việc tôi sai không lớn.
4. **Số lượng task.** Mười task nhiều hơn M4 (bảy) và M5 (bảy). Tôi đã cân nhắc gộp Task 9 vào Task 2 (chung một vòng round-trip MariaDB) và gộp Task 5 vào Task 4. Không gộp, vì hai lý do: Task 9 phải **xoá được nguyên vẹn** nếu chủ văn phòng nói không, và Task 4 đã lớn sẵn (ba Action, ba lớp support, một lệnh console, một bất biến giữ ở bốn tầng). Nếu người cài đặt thấy Task 4 vượt một phiên, cách cắt đúng là tách **`AmendContract` + phụ lục** ra thành task riêng, **không** tách theo tầng — tách theo tầng nghĩa là hai người cùng sửa một bất biến, đúng chỗ M3 đã va chạm ba lần.
