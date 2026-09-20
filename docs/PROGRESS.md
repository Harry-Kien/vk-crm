# Tiến độ VK-CRM

| Milestone | Trạng thái | Ngày | Ghi chú |
|---|---|---|---|
| M0 Khởi tạo | ✅ Xong | 2026-09-13 | Laravel 13.17, Filament 5, hai panel, hai guard, Pest 4, Pint, `.env.example`, seed demo. 23 test xanh. Đăng nhập thật cả hai panel đã kiểm tra trên trình duyệt |
| M1 Migration / model / enum / factory / seeder | ✅ Xong | 2026-09-14 | 19 bảng SPEC §4 + `code_sequences` + `client_password_reset_tokens`; 13 enum; seeder đủ SPEC §12; 77 test xanh |
| M2 Phân quyền (spatie, Policy, global scope client) | ✅ Xong | 2026-09-14 | 130 test xanh |
| M3 Panel admin + `TransitionMatterStage` + `RunConflictCheck` | ✅ Xong | 2026-09-16 | 280 test xanh |
| M4 Danh mục hồ sơ + tài liệu + `PublishDocument` | ✅ Xong | 2026-09-20 | Checklist, upload có `FileGuard` + seam quét virus, duyệt/từ chối, `PublishDocument`, lưu trữ đĩa `private`, route tải có chữ ký vẫn kiểm policy, hai tab mới ở trang vụ việc, hai widget SPEC §7.1 còn thiếu. Đã qua cổng hợp nhất (4 Important + 6 Minor, không Critical). 823 test xanh |
| M5 Portal khách (OTP, hồ sơ, nộp tài liệu, yêu cầu) | ⬜ | | |
| M6 Thông báo + tác vụ định kỳ + heartbeat | ⬜ | | |
| M7 Bàn giao + lưu trữ + liên lạc + tìm kiếm | ⬜ | | |
| M8 Bảo mật + backup + README triển khai | ⬜ | | |

## Ghi chú M0

- Môi trường local dùng `compose.yaml` tự viết (image `webdevops/php:8.3-alpine`,
  `mariadb:11`, `mailpit`) thay cho Sail vì Sail không chạy trên Git Bash Windows và
  build image quá chậm. Lệnh đi qua `bin/dev`.
- Bảng `users`, `clients`, `client_users` đã có đủ cột theo SPEC §4.1–4.3 để M1
  không phải sửa lại.
- 2FA nội bộ và OTP portal chưa làm (thuộc M8 và M5). Dự kiến dùng MFA tích hợp
  sẵn của Filament 5 thay cho Fortify.

## Ghi chú M1

- Mã tự sinh dùng bảng đếm `code_sequences` khoá dòng, không còn kẽ hở đồng thời. Có thể có khoảng trống số nếu insert lỗi sau khi lấy số; SPEC không yêu cầu liên tục.
- `stage_logs` không có `deleted_at`, model chặn sửa nội dung và xoá (`StageLogImmutable`). Chỉ `is_published`, `published_at`, `notified_at` được đổi.
- `matter_parties` có thêm `name_normalized` để so tên có index (SPEC §6.10 bước 2).
- Bảng nhật ký thuần và pivot không có `deleted_at` (xem "Ràng buộc toàn cục" trong kế hoạch M1).
- Chưa khai báo `Matter::timeEntries()` (SPEC §15) vì chưa có bảng; làm ở giai đoạn 2 cùng bảng `time_entries`.
- Tệp vật lý của `documents` gắn ở M4 qua medialibrary; M1 chỉ có metadata.
- Broker đặt lại mật khẩu của khách dùng bảng `client_password_reset_tokens` riêng.
- Bộ đếm mã dùng DB::transaction(..., 3): deadlock InnoDB khi hai request cùng tạo dòng đếm đầu tiên của một khoá được thử lại tối đa 3 lần.
- Normalizer::phone() bỏ số 0 đầu sau mã quốc gia: +84 0901234567 và 0901234567 đều thành 84901234567.
- MatterType::stage() và firstStage() đọc từ quan hệ stages đã nạp (tránh N+1 khi liệt kê); sau khi thêm giai đoạn trên cùng instance phải unsetRelation('stages').
- ApplyChecklistTemplate coi mục đã xoá mềm là đã tồn tại (không hồi sinh); không thiết kế cho hai lời gọi đồng thời trên cùng vụ việc.
- Deadline::upcoming() so sánh trực tiếp cột due_date (dùng được index). markReminderSent() không tự khoá dòng: job nhắc hạn ở M6 phải nạp Deadline bằng lockForUpdate() trong transaction.
- Guard bất biến của StageLog chạy qua model event; cập nhật bằng query builder (StageLog::where()->update()) sẽ bỏ qua guard. Mọi Action phải thao tác qua instance model.
- Morph map bắt buộc (AppServiceProvider): cột *_type lưu alias (user, client_user, stage_log, ...) thay cho tên class.
- Không thể forceDelete() vụ việc (MatterNotDestroyable) để nhật ký tiến độ và nhật ký tải về không bị cascade xoá.
- id_number_hash, phone_normalized (MatterParty) và code (Client) không còn mass-assignable; chỉ ghi qua identify() và hook creating.

## Ghi chú M2

- Ngữ cảnh portal định nghĩa một chỗ duy nhất ở `ClientPortalScope::isActive()`:
  `auth('client')->check() && ! auth('web')->check()`. Vế thứ hai là bắt buộc vì hai panel dùng
  chung cookie phiên; thiếu nó thì nhân sự đăng nhập cả hai panel sẽ bị giới hạn sai ở `/admin`.
- `User` và `ClientUser` cố ý không có scope portal: gọi `auth()` trong global scope của chính
  model xác thực sẽ đệ quy vô hạn. `MatterType`, `MatterTypeStage` cũng không, vì portal cần đọc
  nhãn giai đoạn.
- Ba lớp bảo vệ độc lập: global scope (truy vấn), policy (hành động), và
  `HidesInternalAttributesFromPortal` (serialize). Lớp thứ ba tồn tại vì một dòng tiến độ đã công
  bố vẫn mang `internal_note` trong cùng bản ghi.
- Policy của khách hàng không dùng spatie; nó áp lại chính điều kiện của global scope qua
  `ChecksPortalVisibility::visibleToPortal()`, nên hai tầng không thể lệch nhau.
- `Matter::scopeListableBy()` là định nghĩa duy nhất của "nhân sự thấy vụ việc nào". Mọi resource
  ở M3 trở đi **phải** dùng nó trong `getEloquentQuery()`, nếu không danh sách sẽ rò rỉ vụ việc
  ngoài đội ngũ (SPEC §4.7 "kể cả trong kết quả tìm kiếm").
- Tách `listableBy` (dòng thấy trong danh sách) khỏi `view` (mở được hồ sơ) vì SPEC §5 cho kế toán
  danh sách rút gọn nhưng không cho xem nội dung.
- **Không cài `filament-shield` ở M2.** Shield sinh quyền từ Filament Resource mà M2 chưa có
  resource; 6 trong 13 quyền SPEC §5 không phải cặp resource-action nên Shield không sinh được.
  Xét lại ở M3, chỉ dùng giao diện gán vai trò và cấu hình để không sinh lại tên quyền.
- Vai trò gán theo chức danh qua `User::assignRoleFromPosition()`. Action sửa nhân sự ở M3 phải
  gọi lại hàm này, nếu không đổi chức danh sẽ không đổi quyền.
- `PortalCoverageTest` là lưới an toàn: mọi model mới ở M3–M8 phải hoặc dùng
  `RestrictedToClientPortal`, hoặc được thêm vào danh sách miễn trừ kèm lý do.
- Policy của model con nhận cả User lẫn ClientUser; mọi nhánh dùng spatie đều nằm sau kiểm tra
  instanceof User, nên khách không bao giờ chạm tới can(<tên quyền>) hay hasRole().
- Khả năng `create` của policy chỉ nhận đối tượng người dùng: Gate bỏ tham số tên class trước khi
  gọi, nên khai báo thêm tham số model sẽ ném ArgumentCountError khi Filament hỏi quyền tạo.
- MatterUser (pivot) cũng bị chặn sạch ở portal. Quan hệ belongsToMany của Matter::team() đọc
  thẳng bảng qua join nên không ảnh hưởng.
- MatterPolicy::view dùng withTrashed(): quản trị vẫn xem và khôi phục được vụ việc đã xoá mềm.
- Nhánh vụ việc hạn chế trong scopeListableBy vẫn đòi quyền matter.view: một người bị đổi chức
  danh sang kế toán không còn thấy vụ hạn chế mình từng phụ trách.
- Việc để lại cho M3: MatterPolicy::view chạy một truy vấn EXISTS mỗi lần gọi, nên khi dựng bảng
  danh sách phải nạp sẵn quan hệ team và thêm đường kiểm tra trong bộ nhớ, tránh N+1.
- Việc để lại cho M4: chưa có quyền nào diễn tả "khách nộp tài liệu vào một đầu mục danh mục";
  DocumentPolicy::create hiện trả true và sẽ được siết ở M4. Luật sư không có quyền client.manage
  nên không xem được hồ sơ khách của chính vụ mình — xác nhận lại khi dựng trang chi tiết vụ việc.

## Ghi chú M3

- **Mâu thuẫn trong SPEC, đã phát hiện và xử lý ở M3:** §4.8 (ghi chú cột) nói `from_stage` là
  `null` cho một dòng không đổi giai đoạn (chỉ cập nhật tiến độ), nhưng §6.3 nói cả `from_stage`
  lẫn `to_stage` đều bằng giai đoạn hiện tại cho dòng đó. Bản cài đặt đi theo §6.3 (cụ thể hơn và
  ở mục mô tả hành vi transition trực tiếp). **Các task sau phải dùng điều kiện
  `from_stage === to_stage` để nhận biết "dòng không đổi giai đoạn", không được dùng
  `from_stage === null`** — nếu không sẽ đọc sai lịch sử tiến độ.
- **Bài học quy trình, áp dụng từ M3 trở đi:** cơ sở dữ liệu test (SQLite in-memory) dựng lại cả
  bảng mỗi khi một index đổi, nên test xanh trên SQLite **không chứng minh** ràng buộc
  index/khoá ngoại hoạt động đúng trên MariaDB. M3 từng vỡ ở đúng chỗ này: migration bỏ unique
  composite trên `matter_type_stages` chạy sạch trên SQLite nhưng `migrate:fresh` trên container
  MariaDB thật báo lỗi 1553 (khoá ngoại đang dùng chính index đó, không được xoá khi chưa có index
  thay thế). Sửa bằng cách thêm index thường trên `matter_type_id` trước khi xoá unique composite.
  **Mọi milestone sau này đụng tới index hoặc khoá ngoại phải tự chạy `migrate:fresh` và một vòng
  rollback/migrate trên container MariaDB thật**, không được coi bộ test xanh là đủ bằng chứng.
- Dispatch trong kế hoạch M3 lệch thứ tự đánh số: `RunConflictCheck` (Task 7) phải cài trước
  `OpenMatter` (Task 5) vì Task 5 gọi Task 7; đánh số trong kế hoạch theo thứ tự mục SPEC, không
  theo thứ tự phụ thuộc.
- `RunConflictCheck` trải qua ba vòng sửa vì liên tiếp lộ ra các đường "xanh giả" (false green) —
  cùng một họ lỗi: vụ việc đã xoá mềm vẫn phải tính (soft-delete nghĩa là ẩn, không phải chưa từng
  xảy ra); scope portal (`whereRaw('1=0')`) phải được bỏ qua có chủ đích như `listableBy`, nếu
  không mọi kiểm tra ở ngữ cảnh portal đều ra xanh; vai trò "khách của mình" phải lấy từ cả các bên
  đã lưu của vụ việc lẫn bên mới đề xuất, không chỉ một trong hai; một bên party không tạo qua
  `identify()` (thiếu hash/số điện thoại) phải được đánh dấu "thiếu thông tin" một cách tường minh
  thay vì âm thầm bỏ qua hai bậc kiểm tra mạnh nhất. Vòng cuối gộp cả tìm kiếm trùng khớp lẫn nguồn
  vai trò trên cùng một tập hợp bên, vì kiểm tra lại sau khi bên đã được thêm phải thấy xung đột
  phát sinh từ chính các bên đã lưu, không chỉ bên vừa thêm.
- `OpenMatter`/`AddMatterParty` chặn lưu khi có xung đột: cổng chặn dựa trên
  `ConflictCheckResult::requiresAcknowledgement()`, không dựa trên `level === Yellow` — một kết quả
  Xanh nhưng thiếu thông tin định danh vẫn phải được xác nhận trước khi lưu. `client_role` là
  tham số bắt buộc, không còn mặc định ngầm là nguyên đơn (giá trị mặc định từng âm thầm quyết định
  một vụ Đỏ có bị hạ xuống Vàng hay không). Actor ghi vào `Audit::record` được truyền tường minh,
  không lấy từ `auth('web')` trong Action.
- `TransitionMatterStage`/`StageLog`: `stage_entered_at` chỉ cập nhật khi giai đoạn thực sự đổi,
  không đổi ở dòng chỉ cập nhật tiến độ (nếu không sẽ mất khả năng biết "đã kẹt ở giai đoạn này bao
  lâu" mà SPEC §6.4 và widget quá hạn cần). Sự kiện `StageLogPublished` bắn sau khi transaction ghi
  StageLog đã commit — code M6 xử lý thông báo không được giả định mình đang ở trong transaction
  ghi, `notified_at` là tuyến chặn trùng còn lại. Nút bật portal cho một dòng chỉ được phép khi vụ
  việc đã công bố portal; tắt/bật lại công bố portal có thể làm lộ lại một loạt dòng đã publish
  trước đó (`notified_at` không tự reset) — cần thiết kế lại ở M6 nếu muốn tránh việc này.
- Form "Chuyển giai đoạn" trong Filament: chỉ hiện các giai đoạn kế tiếp hợp lệ theo cấu hình loại
  vụ việc (không hiện toàn bộ danh sách giai đoạn); trường nội dung công bố cho khách cập nhật
  ngay khi gõ (`live()`, không phải `live(onBlur:)`) để bản xem trước dưới form luôn khớp — đây là
  yêu cầu chống lộ thông tin nội bộ ra bản xem trước, không phải chỉ để tiện dùng; nút bật công bố
  portal bị vô hiệu hoá khi vụ việc chưa công bố portal, và lỗi `MatterNotPublishedToPortal` được
  bắt riêng để hiện thông báo thay vì lỗi 500.
- Đã xác nhận thủ công (Task 11, sau `migrate:fresh --seed`) cả sáu kịch bản: quản trị thấy đủ
  danh sách, widget quá hạn ở trên cùng, và thấy vụ hạn chế; kế toán thấy danh sách rút gọn (không
  cột nội dung vụ việc), không mở được vụ việc, sidebar không có Khách hàng/Tài khoản portal; luật
  sư `luatsu1` chỉ thấy vụ của mình, mở vụ thấy đủ ba tab, hai nút trên tab Tiến độ cùng cỡ; chuyển
  giai đoạn thật trên `VK-2026-DD-0001` (Thu thập hồ sơ → Soạn đơn) thành công, dòng mới hiện ngay
  trên Tiến độ; trợ lý `troly1` không thấy nút Chuyển giai đoạn; khách `khach1` vào `/portal` không
  lỗi. **Một điểm lệch so với kịch bản gốc, không phải lỗi:** dữ liệu mẫu cố tình gán vụ hạn chế duy
  nhất (`VK-2026-DD-0006`) cho `luatsu1` làm luật sư phụ trách (xem docblock
  `MatterSeeder::restrictedMatter`), nên không thể dùng chính tài khoản `luatsu1` để xác nhận "vụ
  hạn chế mà mình không phụ trách thì biến mất" — đã xác nhận thay bằng `luatsu2` (không đứng tên,
  không trong đội ngũ vụ đó): vụ hạn chế đúng là không xuất hiện trong danh sách của `luatsu2`.
- **Màn hình mở vụ việc mới (`CreateMatter` + `MatterForm`), bổ sung sau review toàn nhánh:**
  trước đó `OpenMatter` không có nơi gọi nào ngoài test, nên tiêu chí M3 của SPEC §13 ("tạo được
  vụ việc end-to-end") thực tế chưa đạt và thời điểm kiểm tra xung đột bắt buộc thứ nhất của SPEC
  §6.10 ("ngay trong form tạo vụ việc") chưa tồn tại — chỉ có nửa "thêm một bên". Trang mới gọi
  đúng một Action (`OpenMatter`), dùng cùng luồng hai lượt như `PartiesRelationManager` (lượt 1
  chạy kiểm tra và hiện kết quả, lượt 2 xác nhận hoặc ghi đè), nhưng hiện kết quả bằng BẢNG ngay
  trong form thay vì Notification, đúng câu chữ SPEC §6.10; ô "Lý do ghi đè" bị `disabled()` với
  ai không phải `manager`/`admin` (một trường bị khoá không dehydrate, nên luật sư không gửi lên
  được lý do — cổng thật vẫn ở Action). Đã xác nhận thủ công trên dữ liệu mẫu: luật sư `luatsu1`
  thấy nút và mở được vụ việc (mức vàng → tích xác nhận → `VK-2026-LD-0004`), bị đơn trùng căn
  cước khách hàng số 2 bị chặn đỏ với bảng liệt kê đủ mã hồ sơ/loại/vai nhưng không tiêu đề (gồm
  cả hai hồ sơ `luatsu1` không có quyền xem), `quanly` ghi đè được kèm lý do (`VK-2026-LD-0003`,
  nhật ký ghi đúng lý do và causer), kế toán không thấy nút và vào thẳng URL bị 403.
- **Hạn chế đã biết của màn hình mở vụ việc (chưa sửa, cần quyết định nghiệp vụ):** ô "Khách hàng"
  dùng `VisibleClientOptions` như mọi ô khách hàng khác của panel, mà luật sư không có
  `client.manage` (SPEC §5) nên chỉ thấy khách hàng của những vụ việc mình đã liệt kê được — hệ
  quả là **luật sư không mở được vụ việc cho một khách hàng hoàn toàn mới**, phải nhờ trợ lý/
  trưởng phòng tạo hồ sơ khách trước. Đây là hệ quả trực tiếp của ma trận quyền SPEC §5, không
  phải lỗi cài đặt; nới ô chọn này là nới đúng chỗ dữ liệu khách hàng đã rò rỉ hai lần trên nhánh
  M3, nên không tự ý nới.
- Việc hoãn sang M4: danh mục hồ sơ/tài liệu trên trang chi tiết vụ việc; `DocumentPolicy::create`
  vẫn tạm thời trả `true`, sẽ siết khi có mô hình quyền nộp tài liệu portal.
- Việc hoãn sang M5–M7: mốc thời hạn/liên lạc/yêu cầu từ khách trên trang chi tiết; widget cần dữ
  liệu tài liệu và hạn tố tụng; trang bàn giao, lưu trữ, tìm kiếm toàn văn.
- Việc hoãn sang M6 (rủi ro kỹ thuật cụ thể mang sang): `StageLogPublished` chạy sau commit, không
  được coi là nằm trong transaction ghi; cờ `MatterNotPublishedToPortal` chỉ chặn ở thời điểm tạo,
  chưa chặn khi bật/tắt lại công bố portal; một `DomainException` không được Action bắt riêng sẽ lộ
  thành lỗi 500 trên form Filament — cần rà lại các Action khác có cùng rủi ro.
- Vẫn **không cài `bezhansalleh/filament-shield`**: 13 quyền SPEC §5 không theo quy ước
  `<resource>.<action>` của Shield (ví dụ `matter.transitionStage`, `stageLog.publish`), ma trận
  quyền hiện là bảng gán cứng trong `RolesAndPermissionsSeeder`, chưa có giao diện sửa runtime —
  nếu M6–M8 cần giao diện đó thì xét lại Shield lúc đó. Vì chưa có giao diện sửa quyền runtime nên
  nhánh `AuthorizationException` khi thiếu `stageLog.publish` (dù có `matter.transitionStage`) chưa
  từng chạy được trên dữ liệu thật — mọi vai trò có quyền chuyển giai đoạn hiện đều có luôn quyền
  publish. Cũng không cài `saade/filament-fullcalendar` (không có yêu cầu lịch trong phạm vi M3) và
  `awcodes/filament-table-repeater` (thêm các bên vụ việc dùng RelationManager + Action
  `AddMatterParty` vì mỗi bên cần chạy `RunConflictCheck` riêng, repeater không phù hợp).
- Dọn dẹp nhỏ còn để lại (không chặn milestone): action ForceDelete/Restore không có policy tương
  ứng vẫn hiện trên ba resource; ba resource dùng chung một icon sidebar; widget "Vụ việc theo giai
  đoạn" gộp theo nhãn giai đoạn nên hai loại vụ việc trùng nhãn sẽ gộp chung một cột; trang nhật ký
  hoạt động nạp sẵn quan hệ subject nhưng chỉ hiển thị subject_type; `MatterType.code` có cùng lỗ
  hổng xoá-mềm-rồi-tạo-lại như `matter_type_stages.key` từng có trước khi M3 thêm guard ở model —
  chưa vá, mang sang task tiếp theo có đụng `MatterTypeForm`. Cả ba việc trên đã ghi lại trong kế
  hoạch M4 để không rơi mất.
- **Fix round 2 (review), đã đóng:** ngày `occurred_at` ở form chuyển giai đoạn giờ bị chặn ở cả
  hai lớp (picker `maxDate()` và guard thật trong `TransitionMatterStage`, không chỉ picker); công
  tắc công bố portal ở `ViewMatter` giờ đi qua Action `SetMatterPortalPublication` thay vì
  `$record->update()` thẳng, ghi kèm số dòng `stage_logs` đã công bố cho seam M6; widget "Hồ sơ quá
  hạn cập nhật" (SPEC §6.4) giờ xét cả `is_published_to_portal` lẫn trường hợp CHƯA TỪNG cập nhật
  (dùng `stage_entered_at` làm đồng hồ thay thế khi `last_client_update_at` rỗng — xem docblock
  widget), kèm test cho nhánh chỉ thấy hồ sơ của chính mình; `PartiesRelationManager` không còn
  bản sao riêng của `VisibleClientOptions`, dùng chung lớp
  `App\Filament\Admin\Support\VisibleClientOptions`; docblock của công tắc công bố ở form chuyển
  giai đoạn (`publishToggleField`, `BuildsStageUpdateSchema`) đã sửa lại đúng hành vi
  `disabled()`/`dehydrated()` thật của Filament 5
  — đọc `vendor/filament/schemas/src/Components/Concerns/{CanBeDisabled,HasState}.php` cho thấy một
  trường bị khoá không hề "dehydrate giá trị mặc định false" như docblock cũ viết, mà KHÔNG được
  dehydrate chút nào (khoá biến mất khỏi `$data`); hành vi thật vẫn an toàn — thậm chí chặt hơn mô
  tả cũ — không phải một lỗ hổng.
- **Lệch so với kế hoạch M3, chưa ghi trước đó:** tiêu chí chấp nhận của Task 4 ("chỉ vai trò có
  `settings.manage` mở được `MatterTypeResource`, `assertForbidden` cho luật sư") ngụ ý cả
  `viewAny`/`view` cũng phải bị chặn, nhưng `MatterTypePolicy::viewAny`/`view` cố ý trả `true` cho
  mọi vai trò — kể cả kế toán cũng xem được danh sách Loại vụ việc — vì đây là dữ liệu cấu hình
  (nhãn giai đoạn), không phải thông tin khách hàng, và portal cũng cần đọc được. Chỉ
  `create`/`update`/`delete` bị khoá theo `settings.manage`, đúng như test hiện có xác nhận. Quyết
  định này là chủ đích, không phải sót, nhưng chưa từng được ghi lại — ghi ở đây để không ai tưởng
  nhầm là lỗi khi đọc lại policy.

## Ghi chú M4

Ghi lại những quyết định KHÔNG tự đọc ra được từ mã hay từ SPEC, để chúng không chỉ nằm trong
một thông điệp commit. Sổ đầy đủ theo từng task ở `.superpowers/sdd/2026-09-16-m4-documents/progress.md`;
mục này là bản một người đọc được.

- **`document.publish` giờ còn nghĩa là "được xoá tài liệu"** (`DocumentPolicy::delete`, và
  `DocumentPolicy::publish` đi cùng một cổng). Kế hoạch M4 kê `matter.update`, nhưng thuốc đó
  không chữa được triệu chứng: theo bảng SPEC §5, cả bốn vai trò có `matter.view` đều có luôn
  `matter.update`, nên thêm mình `matter.update` không loại được trợ lý — người mà việc mang
  sang gọi tên. `document.publish` là quyền duy nhất trong bảng tách được "quyết định số phận
  một tài liệu" khỏi "làm hồ sơ thường ngày". **Hệ quả cần nhớ khi cấp quyền:** cấp
  `document.publish` cho một vai trò mới là cấp luôn quyền xoá tài liệu. Đã ghi một dòng chú
  ngay dưới bảng quyền SPEC §5 để người đọc bảng không phải biết chuyện này từ chỗ khác.
- **Từ chối trong panel trả 404, nhưng chỉ ở tầng route.**
  `AnswerDeniedPanelRequestsWithNotFound` phủ mọi từ chối do middleware của route panel ném ra,
  kể cả trên request cập nhật Livewire (nhờ `isPersistent`, nơi nó đứng trước middleware
  `Authenticate` của Filament). Nó KHÔNG phủ — và không thể phủ — một `abort(403)` phát sinh
  bên trong vòng đời component (`hydrateCanAuthorizeAccess`), vì Livewire chạy middleware bền
  với đích đường ống là một response 200 mới tinh, nên khung middleware đã kết thúc trước khi
  component được hydrate. Không đuổi theo là cố ý: ở đó cặp (403, 404) không còn là máy dò sự
  tồn tại (`$record` là `#[Locked]`, snapshot niêm bằng HMAC `APP_KEY`), và cách phủ nốt duy
  nhất sẽ nuốt luôn cổng chặn tải tệp của `SchemasServiceProvider` mà các ô upload M4 sẽ nằm
  sau. Ranh giới này có test hành vi bằng request `/livewire/update` thật ở `DenialCodeTest`.
- Cái giá đã nhận của quyết định 404: một tài khoản bị vô hiệu hoá nhận 404 thay vì 403. Phiên
  của họ vẫn bị chặn ngay ở request kế tiếp (SPEC §10.9) — việc đó do middleware `Authenticate`
  của Filament giữ, không phải middleware của ứng dụng.

### Tệp và kiểm tra tệp (Task 1)

- Tệp vật lý do `spatie/laravel-medialibrary` giữ trên đĩa `private`
  (`storage/app/private`, ngoài web root, không `storage:link` — SPEC §2 cấm).
- `FileGuard` là chỗ DUY NHẤT kiểm đuôi, MIME thật (`finfo`, không tin `Content-Type` client
  gửi), kích thước và tên tệp. Danh sách trắng đúng SPEC §6.6 bước 2; `.svg` bị cấm tường minh
  vì SVG mang được JavaScript.
- **Một tệp `.docx`/`.xlsx` còn bị mở ra kiểm bên trong** (`verifyOfficePackage`): gói phải có
  `[Content_Types].xml` cộng `word/document.xml` hoặc `xl/workbook.xml`. Lý do: MIME của OOXML
  chính là `application/zip` dưới mắt libmagic, nên nếu chỉ tin `finfo` thì MỘT ZIP BẤT KỲ —
  kể cả zip chứa `.exe` — lọt qua dưới cái tên `.docx`. Gói mang `vbaProject.bin` bị từ chối:
  đó là `.docm` đội lốt `.docx`, và danh sách trắng của SPEC không có đuôi macro nào.
- **Cố ý ngoài phạm vi, có lý do:** quan hệ ngoài và đối tượng nhúng trong gói OOXML. Hệ thống
  không bao giờ MỞ những tệp này, không chỗ nào giải nén chúng (nên tên tệp kiểu `../` và tỉ lệ
  nén zip-bomb không có tác dụng), và lớp kiểm soát cho nhóm đó là ClamAV — đúng lý do seam
  `VirusScanner` tồn tại.
- `NullScanner` là mặc định; `ClamAvScanner` bật bằng `CLAMAV_ENABLED=true`. **Chưa lần nào gặp
  một clamd thật** — lần bật đầu tiên trên máy chủ thật phải tự kiểm một tệp EICAR để chắc câu
  trả lời của bản clamd đó khớp mẫu đang đọc.
- `php artisan about` báo trạng thái quét bằng BA nhánh, không hai: tắt / bật và daemon trả lời /
  bật mà daemon im. Gộp nhánh thứ ba vào "tắt" sẽ đọc thành "chưa cấu hình quét" trong khi sự
  thật là mọi lượt tải lên sắp bị từ chối.
- Đĩa `local` đã được dời sang `app/local` và bỏ `serve`, vì trước đó nó trỏ CÙNG thư mục với
  `private` và có `serve => true`: `GET/PUT /storage/{path}` chạm tới được mọi tài liệu chỉ với
  một chữ ký, không qua policy, không ghi dòng tải về.

### Quyền và phạm vi tài liệu (Task 2)

- Khách chỉ thấy tài liệu `status = published`, điều kiện đặt ở CẢ global scope lẫn
  `DocumentPolicy::view`. Hai tầng cố ý không nói chuyện với nhau: có một test thay global
  scope bằng một scope rỗng (đúng hình dạng "ai đó quên một `where`") rồi chứng minh policy
  vẫn từ chối.
- `DocumentPolicy::create` cho khách vẫn trả `true` **khi không kèm ngữ cảnh**, và mọi lần
  Filament tự hỏi ability này đều không kèm ngữ cảnh. Mọi màn hình tạo `Document` hoặc
  `ClientRequest` vì thế phải tự hỏi kèm bản ghi:
  `->authorize(fn () => Gate::allows('create', [Document::class, $this->getOwnerRecord()]))`.
  M4 đã làm đúng vậy ở tab Tài liệu; **M5 phải làm điều tương tự cho `ClientRequest`.**
- `ClientRequest` còn thiếu điều kiện "của chính mình": `applyClientPortalConstraints()` chỉ có
  `whereHas('matter')`, tức phạm vi là mỗi `Client` chứ không phải mỗi `ClientUser`, nên hai
  tài khoản portal của cùng một khách đọc được yêu cầu của nhau. **Chưa đổi hành vi** — cần
  chốt cách đọc SPEC §5 ("của chính mình" là của cá nhân đăng nhập hay của khách hàng) trước
  khi M5 dựng giao diện yêu cầu.

### Nộp, duyệt, công bố (Task 3, 4)

- **Tên tệp lưu trên đĩa là một ULID viết thường cộng đuôi đã chuẩn hoá** — không một byte nào
  do người tải lên chọn, vì người tải lên có thể là khách, tức người ngoài hệ thống. Tên HIỂN
  THỊ là `FileGuard::safeName($originalClientName)` và **cố ý giữ lại phần đuôi**, khác quy ước
  của medialibrary, để route tải về dùng được nguyên văn (một tệp tải về không có đuôi thì trên
  Windows không mở được).
- `UploadStaffDocument` đặt mặc định theo bảng SPEC §4.11. **Nộp vào một nhóm ra tới khách NGAY
  LÚC TẠO (nhóm A) đòi thêm `document.publish`**: đó là một lần công bố, và Task 2 đã đặt
  `document.publish` làm ranh giới giữa "làm hồ sơ" và "quyết định số phận một tài liệu". Ba
  nhóm còn lại không đổi, nên "nhân viên nộp thay" vẫn là việc một trợ lý làm được. Điều kiện
  đọc ra từ chính bảng mặc định (`releasesToClientAtCreation()`), không viết lại chữ cái nhóm
  ở chỗ thứ hai.
- Một lần nộp thay khách ở **nhóm A** gắn vào một đầu mục danh mục sẽ đặt đầu mục đó sang
  `accepted` kèm `reviewed_by`/`reviewed_at` và **xoá lý do từ chối cũ** (một lý do còn hiện
  cho khách trên một dòng đã nhận là một dòng nói dối). Lập luận: nhóm A nghĩa là nhân sự đã
  cầm tệp trong tay và đọc đủ để biết nó đáp ứng đầu mục nào, nên `pending_review` sẽ là xếp
  hàng chính việc của mình để chính mình duyệt — và một cái cổng không ai duyệt là cái cổng
  người ta học cách bấm cho xong. Nhóm B, C, D không đụng tới trạng thái đầu mục.
- **Nhưng nếu đầu mục đang `pending_review` thì nộp thay khách bị TỪ CHỐI.** Tệp của khách đang
  nằm trên bàn; văn phòng duyệt nó trước (nhận, hoặc từ chối kèm lý do), rồi mới nộp thay nếu
  vẫn cần. Cùng một luật với `MarkChecklistItemNotApplicable`, cài đặt ở một chỗ
  (`OpensChecklistItem`).
- **Nhóm D là ranh giới tuyệt đối và nó có hai lớp.** `RegroupDocument` là cửa DUY NHẤT để một
  tài liệu rời nhóm D (đòi `document.publish`, ghi `document_regrouped` kèm nhóm cũ — thứ sau
  thao tác không còn tồn tại ở đâu khác). Hook `saving` trên `Document` là thứ làm cho nó thành
  đường duy nhất: nó không hỏi gì về người thao tác và không quyết định gì, nó chỉ chặn đường
  đi vòng qua Action. Tiền lệ: `Matter::forceDeleting` từ M1.
- `PublishDocument` **từ chối "công bố với `client_can_view = false`"**: khách có thể đã mở, đã
  tải, đã in — một cái nút làm tài liệu biến mất không lấy lại được gì nhưng làm văn phòng tin
  là đã lấy lại được. Thu hồi lại quyền TẢI mà vẫn cho xem thì được (SPEC §6.5 bước 3).
- Một tài liệu nhóm B **đã ra tới khách** thì công bố lại được mà không phải đi lại vòng đời
  `internal_draft → pending_approval → signed_filed`. "Đã ra tới khách" đọc là
  `status = published` VÀ `client_can_view`, không phải mình `status`: một lần ghi thẳng vào
  cột trạng thái không phải một lần công bố, và coi nó là công bố sẽ biến nó thành lối tắt qua
  đúng vòng đời SPEC §4.11 dựng ra để cấm.
- `SubmitClientDocument` nhận vào ĐẦU MỤC DANH MỤC chứ không nhận vụ việc — bỏ được một trạng
  thái (vụ việc + không đầu mục) mà SPEC §5 không cho phép. Action không bao giờ đọc `auth()`:
  mọi truy vấn bỏ `ClientPortalScope` tường minh, vì một Action có phạm vi dữ liệu phụ thuộc
  vào guard nào đang mở chỉ đúng cho tới lần đầu nó được gọi từ một job hay một lệnh console.
- Chuỗi `version` của SPEC §6.6 bước 7 là chuỗi CÁC LẦN NỘP CỦA MỘT TỜ GIẤY, nên nó chỉ đi qua
  tài liệu **nhóm A** (một ghi chú nhóm D gắn hợp lệ vào cùng đầu mục; nối một lần nộp của
  khách vào sau nó sẽ đánh số "bản 2 của ghi chú đó" và trỏ `parent_document_id` vào một dòng
  khách không bao giờ được thấy), và nó nhìn `withTrashed()` để một bản đã xoá mềm vẫn giữ số.
  Một lần nộp thay khách ở nhóm A NẰM TRONG chuỗi đó: nhóm A nghĩa là "khách cung cấp" bất kể
  ai bấm nút. Ràng buộc này **không diễn tả được bằng một unique index** (nhóm B/C/D trên cùng
  đầu mục đều mang `version = 1` hợp lệ, và MariaDB không có unique một phần) nên nó sống
  trong trait dùng chung.
- Ba tình huống SPEC §10.10 đòi không phân biệt được (không tồn tại / không phải của mình /
  không có quyền) trả về **cùng một thông điệp, byte cho byte**, không chỉ cùng một lớp
  exception. Lớp không phải thứ người ta nhìn thấy; câu chữ mới là.
- `rejected` bắt buộc lý do ≥ 20 ký tự đếm bằng `mb_strlen` (tiếng Việt nhiều byte). Nhận thì
  nhận từ trạng thái nào cũng được (khách mang giấy tới văn phòng mỗi ngày); **từ chối chỉ khi
  có cái gì đó đang chờ** — bất đối xứng, vì hậu quả bất đối xứng: một lời từ chối viết lên một
  đầu mục chưa ai nộp gì sẽ gửi cho khách một câu "ảnh của anh/chị bị mờ" về một tệp không tồn tại.

### Tải tệp (Task 5)

- Route `documents/{document}/download`, middleware `signed`, hết hạn 5 phút, **và controller
  vẫn kiểm policy** (SPEC §10.4: chữ ký không thay thế quyền). `{document}` là một CHUỖI THÔ,
  không phải route-model binding: binding ngầm giải qua `Document::query()`, tức qua global
  scope của guard nào đang mở, và sẽ trả 404 trước khi policy kịp chạy.
- Chữ ký mang theo một **token người nhận** (`user:12` / `client_user:7`). Thiếu nó, một URL đã
  ký là một tấm vé vô danh dùng được trong 5 phút và `document_downloads` sẽ ghi tên người bấm
  chứ không phải tên người được đưa tệp.
- 403 chỉ dùng cho CHỮ KÝ (sai hoặc hết hạn) — nó nói về đường dẫn, không về một bản ghi, nên
  không mâu thuẫn với luật 404 của SPEC §10.10. Mọi từ chối còn lại trong controller là 404.
- `Content-Disposition` do `FilesystemAdapter::download()` của Laravel dựng, KHÔNG BAO GIỜ nối
  bằng tay: `HeaderUtils::makeDisposition()` **ném exception với mọi tên tệp tiếng Việt có dấu
  và với bất kỳ ký tự `%` nào** — đã đo.
- Sổ bằng chứng: `GET` ghi đúng một dòng, ghi TRƯỚC khi trả response (thân một
  `StreamedResponse` chạy sau khi header đã đẩy đi, nên dòng đó nghĩa là "hệ thống đã trao tệp",
  không phải "người dùng đã nhận"). `HEAD` KHÔNG ghi dòng nào (nó chuyển 0 byte; một dòng ở đó
  là một lời nói dối trong sổ bằng chứng) và nhánh này chỉ nhìn PHƯƠNG THỨC HTTP, không bao giờ
  nhìn một header do client gửi — một header kiểu `Purpose: prefetch` sẽ là cái công tắc tải
  tệp không để lại dấu vết cho bất kỳ người trong nhà nào. Tải lại ghi hai dòng: hai lần mở là
  hai sự kiện. Tệp không tồn tại thì 404 TRƯỚC khi ghi, để không có dòng nào khai đã trao một
  tệp đã biến mất.
- Đo trên MariaDB thật: `user_agent` do phía tải lên điều khiển, cột là `varchar(500)` utf8mb4,
  `sql_mode` có `STRICT_TRANS_TABLES` — 501 ký tự là `ERROR 1406`, byte `0x80` là `ERROR 1366`,
  và cả hai sẽ giết dòng bằng chứng và biến một lượt tải thành 500. SQLite nhận cả hai trong im
  lặng, nên luật này được ghi ra chứ không dựa vào test.
- **Route này nằm ngoài cả hai panel, nên `canAccessPanel()` không canh nó** — và
  `canAccessPanel()` là chỗ DUY NHẤT trong hệ thống thi hành SPEC §10.9. Controller vì vậy tự
  hỏi `is_active`; thiếu nó, một tài khoản portal vừa bị vô hiệu hoá vẫn tải được tệp trong 5
  phút còn lại của bất kỳ đường dẫn nào đã phát.

### Thanh tiến độ `X/Y` (Task 6, đã thành đính chính trong SPEC §4.10)

- SPEC §4.10 định nghĩa `Y` nhưng không định nghĩa `X`, và cách đọc tự nhiên nhất cho ra một
  tử số LỚN HƠN mẫu số trên chính dữ liệu mẫu (`Đã nộp 5/3`). Luật đầy đủ đã được viết thẳng
  vào SPEC §4.10 dưới dạng một đính chính có ngày: `Y` là một TẬP HỢP (đầu mục bắt buộc, hợp
  với đầu mục không bắt buộc đã có tài liệu không thuộc nhóm D), `X` đếm BÊN TRONG tập đó.
- Nhóm D bị loại khỏi vế "đã có tài liệu" vì một ghi chú công việc nội bộ gắn được vào một đầu
  mục danh mục — việc hợp lệ — và nếu nó được tính thì thanh tiến độ báo rằng khách đã nộp xong
  một giấy tờ họ chưa hề nộp, trên đúng cái thanh trợ lý nhìn để biết còn phải giục gì.
- Một đầu mục không bắt buộc, không tài liệu, được đánh dấu `not_applicable` **không xuất hiện
  ở cả hai vế**. Dữ liệu mẫu sinh ra đúng những dòng như vậy và chúng KHÔNG sai.

### Giao diện, và một sự thật của dự án về CSS

- **Không có bước dựng CSS trong dự án này.** Máy dev và máy chủ chỉ có PHP trong Docker
  (CLAUDE.md), panel nạp `vendor/filament/filament/dist/theme.css` đã biên dịch sẵn, và tệp đó
  chỉ chứa các lớp `fi-*` của chính Filament — **không một lớp tiện ích Tailwind nào**, kể cả
  những lớp trông vô hại như `p-2` hay `text-sm`. Một lớp Tailwind viết tay trong mã PHP tô ra
  đúng số không. Đo trên trình duyệt: `bg-gray-100` cho `background-color: rgba(0, 0, 0, 0)`.
  Mọi kiểu dáng viết tay phải là `style=` nội tuyến trên biến CSS của Filament
  (`--gray-500`, `--danger-500`, `--warning-600`, `--primary-500`), hoặc một khối `<style>`
  in kèm bảng.
- Hai lỗi thật do sự thật đó, cả hai đều đã sống trong mã một thời gian và **đã sửa ở Task 7**:
  - `StageLogsRelationManager::renderInternalNote()` dùng `bg-gray-100 dark:bg-gray-700/50` từ
    M3, nên "nền xám, có nhãn Nội bộ" của SPEC §7.2 **chưa từng hiện ra lần nào**. Đó không
    phải chuyện thẩm mỹ: cái nền chính là thứ phân biệt "ghi chú nội bộ" với "đã gửi cho khách"
    trên màn hình luật sư gõ vào mỗi ngày. Nhãn "Khách chưa xem" quá 5 ngày cũng dùng
    `text-amber-600` nên chưa bao giờ vàng. Đo trước khi sửa: nền `rgba(0, 0, 0, 0)`, chữ
    `oklch(0.141 0.005 285.823)` (tức màu chữ thân trang). Sau khi sửa: nền
    `color(srgb 0.442934 0.442955 0.483839 / 0.18)`, chữ `oklch(0.666 0.179 58.318)`.
  - `DocumentsRelationManager::internalRowStyle()` (viết ở Task 6) nhắm vào
    `.fi-ta-content-ctn .fi-ta-content .fi-ta-record`, bộ chọn đọc được từ chính `theme.css`.
    Nó sai hai lần: khối luật đó thuộc bố cục dạng LƯỚI/DANH SÁCH, còn bảng thường render
    `<tr class="fi-ta-row">` KHÔNG có `.fi-ta-content` ở giữa; và kể cả khớp thì cái `<tr>` vẫn
    không nhận nền — đo trực tiếp, `background-color:red !important` trên nó cho
    `oklab(0 0 0 / 0)` vì các ô `<td>` phủ kín hàng. Nên "nền khác màu rõ rệt" cho nhóm D ở
    SPEC §7.2 cũng chưa từng hiện. Luật giờ tô `.fi-ta-cell`; đo lại:
    `color(srgb 0.939489 0.399615 0.423563 / 0.12)`.
- Bài học đủ chung để ghi thành luật: **khẳng định một chuỗi không phải một phép đo.** Cả hai
  lỗi trên đều có test xanh đi kèm, vì test khẳng định lớp CSS có mặt trong HTML hoặc bộ chọn
  chứa một chuỗi. `StageLogPaintingTest` giờ đối chiếu từng lớp CSS phát ra với chính tệp
  `theme.css` được phục vụ, và test của nhóm D đối chiếu từng lớp trong bộ chọn với HTML mà
  bảng thật sự sinh ra.
- `isReadOnly(): false` trên relation manager là MÃ CHẾT trong dự án này: mặc định `true` đó
  chỉ gác các lớp action dựng sẵn của Filament, và hai bảng M4 không dùng cái nào. Đã gỡ, có
  đo (đặt nó thành `true` vẫn để cả bộ test xanh).

### Widget trang chủ (Task 7)

- SPEC §7.1 mục 3 "Tài liệu chờ duyệt" liệt kê **ĐẦU MỤC DANH MỤC**, không liệt kê dòng
  `documents`, dù tên widget nói "Tài liệu". "Chưa ai xem" không phải thuộc tính của tệp —
  bảng `documents` không có cột nào ghi ai đã mở nó — mà là trạng thái `pending_review` trên
  đầu mục. Và liệt kê theo `documents` sẽ hiện ba dòng cho một đầu mục đã nộp lại ba lần, hai
  trong đó là bản cũ không còn ai phải duyệt. Mốc "khách nộp lúc" lấy `MAX(created_at)` của các
  tài liệu **nhóm A** của đầu mục, loại nhóm D ra, vì một ghi chú nội bộ viết hôm nay sẽ làm
  một lần nộp từ chín ngày trước trông như vừa mới đến.
- Widget này **không lọc theo `closed_at`**: SPEC §7.1 mục 3 không nêu điều kiện đó (khác §6.4
  và §6.9 nói thẳng "chưa đóng"/"đang mở"), và `MatterChecklistItemPolicy::review` vẫn cho
  duyệt trên một vụ việc đã đóng, nên dòng ở đó vẫn bấm được.
- SPEC §7.1 mục 4 "Hồ sơ thiếu giấy tờ quá 14 ngày" lấy luật **nguyên từ SPEC §6.9**, không tự
  nghĩ: vụ việc đang mở, đã công bố portal, còn đầu mục **bắt buộc** ở `missing` hoặc
  `rejected`, và tình trạng đó kéo dài quá 14 ngày. `pending_review` cố ý KHÔNG nằm trong tập:
  khách đã nộp, quả bóng ở sân văn phòng, và hiện nó dưới một cái tên "khách chưa nộp" là đổ
  lỗi nhầm người.
- **Đồng hồ của "thiếu từ bao giờ" là `COALESCE(reviewed_at, created_at)` của chính đầu mục.**
  SPEC không đặt tên cho mốc này. Với `rejected`, `reviewed_at` là lúc văn phòng báo cho khách
  phải nộp lại — đúng lúc đồng hồ chạy lại từ đầu. Với `missing`, chưa ai duyệt nên
  `reviewed_at` rỗng và mốc còn lại đúng nghĩa là `created_at` (đầu mục được sao từ template
  lúc mở vụ việc, tức lúc văn phòng bắt đầu chờ). `updated_at` bị loại tường minh: nó nhích vì
  những lý do chẳng liên quan tới việc khách đã nộp hay chưa, và mỗi lần nhích là một hồ sơ tắc
  60 ngày tự đặt lại về 0 — widget im lặng ở đúng hồ sơ nó tồn tại để la lên.
- Cột "Giấy tờ còn thiếu" đếm MỌI đầu mục bắt buộc chưa nộp, kể cả cái mới thiếu hôm qua:
  người sắp gọi điện cho khách cần biết phải xin bao nhiêu thứ, không phải bao nhiêu thứ đã quá hạn.
- Cả hai widget đi qua `Matter::scopeListableBy()`, và mỗi cái có một cặp test âm/dương cho
  nhánh `restricted` (trưởng phòng có `matter.viewAny` vẫn không thấy vụ mật mình không phụ trách,
  nhưng vẫn thấy mọi vụ thường).
- `getSort()` của hai widget từng bằng nhau (-1), nên chúng đứng theo thứ tự Filament tình cờ
  nạp lớp — đo trên trình duyệt: mục 6 hiện TRƯỚC mục 4. Một con số trùng không gây lỗi gì cả,
  nên không có gì báo động; `DashboardWidgetOrderTest` giờ ghim thứ tự tương đối của SPEC §7.1.

### Dọn dẹp mang sang từ M3, đã đóng ở Task 7

- **`ForceDeleteAction`/`RestoreAction` đã gỡ khỏi bốn trang sửa.** Không policy nào của bốn
  model định nghĩa `restore` hay `forceDelete`, và Laravel từ chối một ability không có phương
  thức tương ứng khi model đã có policy — đã đo bằng `Gate::forUser($admin)` trên dữ liệu thật:
  cả tám lần đều `false`. `HeaderActionsAreReachableTest` phát biểu LUẬT chứ không kể tên hai
  lớp đó: mọi thao tác trên thanh tiêu đề phải có một phương thức policy cùng tên.
- **Mỗi resource một icon sidebar riêng** (khách hàng, tài khoản portal, vụ việc, loại vụ việc,
  nhân sự). Năm mục cùng một hình thì cái hình không còn nói gì. `NavigationIconsTest` cũng
  phát biểu luật, không liệt kê icon nào thuộc về ai.
- **`matter_types.code`**: `unique` ở DB đã bỏ (migration `2026_09_20_000001`), đúng cách đã
  làm cho `matter_type_stages.key` ở M3. MariaDB không có unique một phần nên ràng buộc cũ
  tính cả dòng đã xoá mềm: xoá một loại vụ việc đặt nhầm rồi tạo lại đúng loại ấy với cùng mã
  — việc bình thường nhất sau một lần gõ sai — trả về `UniqueConstraintViolationException`,
  tức một trang 500, mà người dùng không nhìn thấy dòng đang chặn mình. "Dùng mã khác đi" không
  phải lối thoát: mã hồ sơ SPEC §6.1 nhúng mã loại vụ việc, nên đổi mã là đổi cách đánh số hồ
  sơ của cả văn phòng vĩnh viễn. Tính duy nhất trong phạm vi các dòng còn dùng giờ nằm ở
  `MatterType::booted()` (phủ MỌI đường ghi: form, seeder, factory, Action, artisan) và ở
  `MatterTypeForm` qua `scopedUnique` (thông báo thân thiện). Vòng `migrate:fresh --seed` và
  `migrate:reset` → `migrate` đã chạy trên MariaDB thật.
- **`MattersByStageWidget` gộp theo `(matter_type_id, stage)`**, không theo nhãn. Nhãn giai
  đoạn chỉ duy nhất trong phạm vi MỘT loại vụ việc, và "Chuẩn bị hồ sơ" là cái tên hai loại bất
  kỳ đều có thể đặt — gộp theo nhãn cộng chung hai giai đoạn khác hẳn nhau vào một cột, nên con
  số hiện ra không phải con số của giai đoạn nào cả, và không có gì trên màn hình nói rằng nó
  là tổng của hai thứ. Nhãn cột giờ luôn kèm tên loại vụ việc.

### Cổng hợp nhất M4 (2026-09-20)

Vòng rà soát cả nhánh: **không có Critical** — một lượt quét chính sách vét cạn trên dữ liệu mẫu
thật (16 tài khoản khách × 46 tài liệu × {xem, tải} = 1.472 quyết định `Gate`, không một vi phạm)
cho thấy hai luật tuyệt đối của SPEC §11 đứng bằng ba lớp độc lập. Bốn việc Important và sáu việc
Minor đã đóng; mỗi việc có mutation probe đi kèm.

- **Thanh `X/Y` trả hai con số khác nhau cho cùng một hồ sơ.** `withCount` áp global scope của
  `Document`, nên dưới guard `client` phép đếm thu về "đã công bố VÀ khách được xem", trong khi
  đính chính SPEC §4.10 định nghĩa `Y` bằng "có tài liệu KHÔNG thuộc nhóm D". Nhân sự đọc `3/4`,
  khách đọc `2/3` trên `VK-2026-DS-0003`. Năm test phủ luật đếm, không test nào chạy dưới guard
  khách. Luật nay ở `App\Actions\Document\ChecklistProgress` (nó là **trường hiển thị trên
  portal** theo đúng chữ của §4.10, nên M5 phải gọi được nó mà không `use` một lớp của panel
  quản trị), phép đếm bỏ `ClientPortalScope` tường minh, và có cặp test so hai guard.
- **Mỗi lần chạy `bin/dev test` ghi PDF thật vào kho hồ sơ sản xuất.** `Storage::fake('private')`
  nay nằm ở `tests/Pest.php` cho MỌI test, không còn là một dòng mà từng tệp test phải nhớ gọi —
  lỗi này đã quay lại lần thứ hai theo đúng con đường cũ. `PrivateDiskTest` có một test làm nhân
  chứng cho chính cái hook đó. Đã dọn 4.448 tệp rác, giữ đúng 46 tệp của dữ liệu mẫu (đối chiếu
  từng đường dẫn với bảng `media`).
- **`AuthorizationException` thoát khỏi hợp đồng "mọi lời từ chối là một câu tiếng Việt".** Nó
  kế thừa thẳng `\Exception` nên không thuộc ba họ mà `ReportsActionFailures` bắt, và nó bắn ra
  từ lần hỏi lại quyền sau cửa sổ quét virus mà chính M4 thêm vào. Nay wrapper bắt nó và trả một
  câu duy nhất cho mọi nguyên nhân (SPEC §10.10).
- **Nhóm D giữ được `client_can_view`.** Hook `saving` hạ cột tải nhưng không hạ cột xem, nên
  vòng `A → D → A` trả tài liệu về tay khách mà nhật ký chỉ có hai dòng `document_regrouped` —
  một lần ra tới khách vô hình với người lọc theo tên sự kiện (SPEC §10.6). Nay hạ cả hai; helper
  text của ô chuyển nhóm nói thẳng rằng vào nhóm D là thu hồi và ra khỏi nhóm D không trả lại.
- Sáu việc nhỏ: hai câu docblock cãi nhau trong `DocumentsRelationManager`; một test vô nghĩa ở
  `StageLogPaintingTest` (đã thay bằng phép đo biến màu `FilamentColor`);
  `HeaderActionsAreReachableTest` hứa rộng hơn dataset (đã mở rộng lên chín trang và nói đúng
  phạm vi); `PublishDocument` và `RegroupDocument` đọc `Document::query()` trần (nay qua
  `ReadsWithoutPortalScope`); `RELEASED_AT_CREATION` là bản chép thứ hai của bảng §4.11 (nay hỏi
  Action); và `var(--primary-500)` — biến màu duy nhất chưa có phép đo — đã đo trên trình duyệt
  thật, con số nằm trong docblock `ChecklistRelationManager::progressBar()`.

### Việc M5 phải làm TRƯỚC — không phải hoãn, là điều kiện vào

M5 chạy MỌI thứ dưới guard `client`, nên ba việc dưới đây không được để lẫn vào danh sách hoãn:
chúng là bước đầu tiên của milestone đó.

1. **19 khoá dịch của Filament vẫn hiện tiếng Anh**, trong đó có `aria_label` của ô nhập OTP —
   **chính là ô đăng nhập của cổng khách hàng**. `LocalizationTest` không thấy chúng vì nó chỉ
   duyệt các tệp đã publish dưới `lang/vendor/`. Đây là việc ĐẦU TIÊN của M5: màn hình đầu tiên
   một khách hàng nhìn thấy không được có chữ tiếng Anh nào. Liên quan: `lang/en/` đang CHE bản
   `en` của framework, nên một lần nâng Laravel thêm thông báo xác thực mới sẽ thiếu luôn ở bản
   `en` của ứng dụng mà `LocalizationTest` vẫn xanh.
2. **`MatterPolicy::view` chạy `Matter::query()` mà KHÔNG bỏ `ClientPortalScope`.** Phát hiện khi
   một mutation probe SỐNG SÓT. Dưới guard nhân sự nó vô hại; dưới guard khách — tức toàn bộ M5 —
   policy trả lời bằng con mắt của guard đang mở thay vì bằng câu hỏi tường minh trên `$actor`.
   Bốn Action tài liệu đã tự gỡ scope (`ReadsWithoutPortalScope`); policy là lớp còn lại.
3. **Màn hình portal cần đúng lớp "lời từ chối thành câu tiếng Việt" mà panel admin có.**
   `ReportsActionFailures` nằm trong `App\Filament\Admin\Concerns`, và bốn họ exception nó bắt
   (kể cả `AuthorizationException` vừa thêm) đều bắn ra từ `SubmitClientDocument` — Action mà
   portal gọi. Hoặc chuyển trait lên một namespace dùng chung, hoặc M5 sẽ dựng bản thứ hai và hai
   bản sẽ lệch.

### Việc hoãn lại, có chủ đích

- **`RetractDocument` — đặc tả đã viết, cài đặt ở M6.** Đường thu hồi một tài liệu HÔM NAY trên
  thực tế là `$document->delete()`: nó ẩn tài liệu khỏi khách mà không nói cho khách biết. Đó
  tệ hơn hẳn thứ mà `PublishDocument` từ chối. Hình dạng đã chốt: một trạng thái thứ ba
  `retracted` (không phải một cờ bị lật — `status = published` cộng `client_can_view = false`
  là hai nguồn sự thật nói ngược nhau), lý do bắt buộc ≥ 20 ký tự `mb_strlen`, gác bằng
  `document.publish`, **một thông báo khách NHÌN THẤY ĐƯỢC** (họ có thể đang cầm tệp trong tay;
  một dòng "văn phòng đã rút lại tài liệu này, lý do: …" là thứ duy nhất ngăn họ tiếp tục dùng
  một bản sai), và một dòng `document_retracted` nối được với `document_downloads` để trả lời
  "khách đã mở bản đó trước khi mình rút chưa". Cài ở M6 vì tầng thông báo cho khách ở đó.
  **Trong lúc chờ, xoá mềm là đường thu hồi tạm thời** — nó CÓ để lại dấu vết (`Document` dùng
  `LogsActivity` từ vòng sửa Task 3), nhưng với model có `SoftDeletes` spatie ghi giá trị cũ
  dưới khoá `old` chứ không phải `attributes`; ai đọc nhật ký đó phải biết điều này.
- **Giới hạn 20 tệp/giờ của SPEC §10.3 thuộc về M5, và nó KHÔNG đặt được trong Action.** Đo
  được: khi Action chạy dưới Filament thì BYTE ĐÃ NẰM TRÊN ĐĨA rồi, vì endpoint `_startUpload`
  của Livewire lưu tệp trước khi bất kỳ form action nào chạy. Giới hạn phải nằm ở chính
  endpoint tải lên, đếm theo tài khoản, và phải tự đếm số LẦN THỬ — một lần bị từ chối không
  để lại dòng nào để dựng lại từ dữ liệu nhật ký.
- `DocumentPolicy::create` nhánh khách không hỏi `is_active`, nên một màn hình M5 vẫn sẽ vẽ nút
  "Gửi tệp" cho một tài khoản đã bị khoá (Action thì từ chối — `ChecksAccountActive`).
- `MatterChecklistItem` chưa dùng `LogsActivity`: một lần sửa `rejection_reason` về sau ghi đè
  câu đang hiện cho khách mà không để lại dấu vết.
- `MatterChecklistItemPolicy::review` cho phép một vụ việc đã xoá mềm. Siết bằng `matter.update`
  sẽ là cái bẫy "xanh vì lý do khác" lần nữa (`checklist.review` và `matter.update` phủ đúng
  cùng bốn vai trò), nên số hạng duy nhất không rỗng là `! $matter->trashed()`.
- **`document_downloads.document_id` phải rời `cascadeOnDelete` TRƯỚC khi M6 viết đường xoá
  cứng của `RetractDocument`, không phải cùng lúc.** SPEC §4.12 bắt ghi "mọi lượt tải" và bảng đó
  tồn tại để trả lời "khách đã mở bản này chưa" — nhưng khoá ngoại đang xoá theo tài liệu, nên
  bằng chứng chết cùng chính cái dòng nó chứng minh. Chừng nào chưa có đường xoá cứng thì chưa ai
  mất gì; ngày có, mất là mất im lặng và không khôi phục được. Thứ tự đúng: đổi sang
  `nullOnDelete` (hoặc `restrictOnDelete`) trong một migration RIÊNG, rồi mới viết
  `RetractDocument`.
- `TransitionMatterStage` vẫn nhận `DateTimeInterface|string` và đưa thẳng vào `Carbon::parse`,
  tức vẫn mang lỗi đã sửa ở hai Action tài liệu: một chuỗi không parse được thành 500 thay vì
  một lỗi xác thực trên form.
- `RunConflictCheck` đối chiếu lại MỌI bên đã có ở mỗi lần chạy (cố ý). Hệ quả: một khi mức đỏ
  đã bị ghi đè, mọi lần thêm bên sau đó trên cùng vụ việc lại trả về đỏ — và một cái cổng phải
  bấm qua mỗi lần là cái cổng người ta học cách bấm cho xong. Chạm mô hình dữ liệu nên phải
  chốt trước khi viết task.
- `SyncClientPartyIdentities` có thể TẠO RA một xung đột mức đỏ khi nó ghi lại `id_number_hash`
  của các bên, mà không có lần kiểm tra nào chạy sau đó. SPEC §6.10 chỉ bắt buộc hai thời điểm
  nên đây không phải vi phạm, nhưng nó là thời điểm thứ ba và cần một quyết định.
- Hai việc từng nằm ở danh sách này — 19 khoá dịch Filament còn tiếng Anh, và
  `MatterPolicy::view` không bỏ `ClientPortalScope` — đã được ĐẨY LÊN thành điều kiện vào của
  M5, xem mục ngay trên. Chúng rời khỏi đây vì M5 chạy mọi thứ dưới guard `client` và màn hình
  đầu tiên khách hàng nhìn thấy là ô nhập OTP.
- Giới hạn tần suất TẢI VỀ đã rời khỏi danh sách này: `throttle:document-download` đếm theo tài
  khoản ĐÃ đặt (`DocumentDownloadController::DOWNLOADS_PER_MINUTE`, bộ đếm ở `AppServiceProvider`),
  nên mục cũ tự mâu thuẫn với chính tiêu đề "hoãn lại" của nó.

### Kiểm tra tay cuối M4 (sau `migrate:fresh --seed`)

Đi bằng phiên thật trên trình duyệt, đăng nhập bằng mã (không gõ mật khẩu vào form), rồi gỡ
đường tạm đó trước khi commit:

- `luatsu1` trang chủ: "Hồ sơ quá hạn cập nhật" 1 dòng, "Tài liệu chờ duyệt" 3 dòng có mốc nộp
  thật, "Thống kê nhanh" hiện nhãn dạng `Tranh chấp đất đai — Tiếp nhận`, "Hồ sơ thiếu giấy tờ
  quá 14 ngày" rỗng với câu trống đúng (dữ liệu mẫu tạo đầu mục hôm nay).
- `luatsu3` sau khi lùi ngày một hồ sơ: widget mục 4 hiện `VK-2026-DD-0002`, "Giấy tờ còn
  thiếu = 3", "Thiếu từ = 3 tuần trước".
- `luatsu1` trên `VK-2026-DS-0003`: từ chối một đầu mục bằng mẫu "Ảnh mờ, chụp lại" bấm một cái
  là điền, gửi — DB ghi `status = rejected`, `reviewed_by = 3`, đủ lý do nguyên văn cho khách.
  Nhận lại đầu mục đó: thanh tiến độ chạy `2/3` → `3/3`.
- Công bố tài liệu nhóm C trên cùng hồ sơ: thông báo "Đã công bố tài liệu cho khách",
  `status = published`, hai cờ bật, `published_by = 3`.
- Tải tệp qua đường ký: HTTP 200, `Content-Disposition: attachment;
  filename=giay-to-tuy-than-cua-nguoi-khoi-kien-ban-sao-chung-thuc.pdf`, `application/pdf`, và
  ĐÚNG MỘT dòng `document_downloads` kèm IP và user agent.
- Nộp thay khách: chạy qua đúng `UploadStaffDocument` (nhóm A, gắn vào một đầu mục) — tài liệu
  ra `published`, hai cờ bật, `version = 1`, đầu mục chuyển `accepted` kèm `reviewed_by`, tên
  hiển thị giữ nguyên tiếng Việt có dấu còn tên trên đĩa là ULID. **Ô chọn tệp không lái được
  từ công cụ trình duyệt đang dùng** (FilePond từ chối một `File` dựng trong trang), nên bước
  này đi qua chính Action mà màn hình gọi; đường qua form có test phủ ở
  `DocumentsRelationManagerTest`.
- Nhóm D: dòng tài liệu nhóm D trên `VK-2026-HN-0001` hiện nền đỏ nhạt thật
  (`color(srgb 0.939489 0.399615 0.423563 / 0.12)` trên từng ô) kèm badge "Chỉ nội bộ — không
  bao giờ hiện cho khách", và không có nút công bố.

### Cách làm việc, hai điều đáng giữ cho các milestone sau

- **Mutation probe cho mọi điều kiện được thêm**: xoá một điều kiện, chạy bộ test, xác nhận
  đúng những test gọi tên nó đỏ lên, rồi hoàn lại. Trên nhánh này kỹ thuật đó bắt được nhiều lỗ
  hổng hơn bất cứ cách nào khác, gồm vài probe SỐNG SÓT vạch ra test xanh vì lý do khác (một
  lý do từ chối 24 ký tự bị chính `required` của Filament chặn trước khi Action chạy; một
  `.svg` bị `acceptedFileTypes()` chặn chứ không phải `FileGuard`; một id bị luật `in:` ngầm
  của `Select` chặn; một khẳng định khớp đúng tên lớp nằm trong khối `<style>` của chính nó).
  Mỗi test âm phải có cặp dương đi kèm.
- **Đọc lại từng docblock vừa viết, đối chiếu với mã, trước khi commit.** Lượt đọc đó tìm ra
  một lỗi thật hoặc một lời khẳng định sai về độ phủ **ở từng task một của milestone này,
  không sót task nào** — kể cả hai sự thật CSS ở trên, và một câu nói `safeName()` "bỏ byte
  thừa" trong khi `mb_convert_encoding` THAY nó bằng `?` và làm hỏng tên tệp tiếng Việt.
