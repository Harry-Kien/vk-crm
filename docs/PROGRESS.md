# Tiến độ VK-CRM

| Milestone | Trạng thái | Ngày | Ghi chú |
|---|---|---|---|
| M0 Khởi tạo | ✅ Xong | 2026-09-13 | Laravel 13.17, Filament 5, hai panel, hai guard, Pest 4, Pint, `.env.example`, seed demo. 23 test xanh. Đăng nhập thật cả hai panel đã kiểm tra trên trình duyệt |
| M1 Migration / model / enum / factory / seeder | ✅ Xong | 2026-09-14 | 19 bảng SPEC §4 + `code_sequences` + `client_password_reset_tokens`; 13 enum; seeder đủ SPEC §12; 77 test xanh |
| M2 Phân quyền (spatie, Policy, global scope client) | ✅ Xong | 2026-09-14 | 130 test xanh |
| M3 Panel admin + `TransitionMatterStage` + `RunConflictCheck` | ✅ Xong | 2026-09-16 | 280 test xanh |
| M4 Danh mục hồ sơ + tài liệu + `PublishDocument` | ✅ Xong | 2026-09-20 | Checklist, upload có `FileGuard` + seam quét virus, duyệt/từ chối, `PublishDocument`, lưu trữ đĩa `private`, route tải có chữ ký vẫn kiểm policy, hai tab mới ở trang vụ việc, hai widget SPEC §7.1 còn thiếu. Đã qua cổng hợp nhất (4 Important + 6 Minor, không Critical). 823 test xanh |
| M5 Portal khách (OTP, hồ sơ, nộp tài liệu, yêu cầu) | ✅ Xong | 2026-09-22 | Merge f7f0880. `bin/dev test:mariadb` 1219 xanh / 0 đỏ. Chi tiết ở "Ghi chú M5" |
| M6 Thông báo + tác vụ định kỳ + heartbeat | 🟡 Đang làm | | Đã trên `main`: nhật ký thư + layout thương hiệu (Task 1), heartbeat/scheduler/dải cảnh báo cron (Task 2), thư `client.stage_update` (một phần Task 3), màn hình mốc thời hạn (Task 5), `CheckDeadlines` (Task 6). 2026-09-23: `bin/dev test:mariadb` 1337 xanh / 0 đỏ. Còn: Task 3 phần còn lại, 4, 7, 8, 9, 10 — **tạm dừng cho M6.5** |
| M6.5 Sửa lỗi quy trình | ✅ Xong | 2026-09-28 | Sửa 102 phát hiện của đợt kiểm tra 2026-09-24 (`docs/audits/2026-09-24-quy-trinh.md`: 90 xác nhận, 12 tranh chấp; bảng mã → task ở "Ghi chú M6.5") và CI đỏ từ 2026-09-22. 21 task, mỗi task qua rà soát Opus; rà soát toàn nhánh chia 3 vùng (1 Critical: nhật ký hệ thống lộ vụ restricted cho trưởng phòng) → 2 đợt sửa. Cổng merge: `test:mariadb` 2234/2234 xanh (2 bài đỏ do chạy chồng một CSDL test, chạy lại riêng 26/26 xanh), full suite 2228 xanh, pint sạch. Việc mang sang M8 Task 6: xem cuối "Ghi chú M6.5" |
| M7 Bàn giao + lưu trữ + liên lạc + tìm kiếm | ⬜ | | Task 1 (`ReassignMatter` một vụ) và R6 (chặn nghỉ việc) đã làm ở M6.5 Task 4 |
| M8 Bảo mật + backup + README triển khai | ⬜ | | |
| M11 Máy chủ MCP (ChatGPT, Claude) | ⬜ | | Chủ văn phòng chốt 2026-09-24: làm ngay sau M8; nhân sự đọc **và** ghi; nhân sự dùng tài khoản AI cá nhân; phạm vi dữ liệu theo chuẩn các máy chủ MCP đang chạy thật. Tra cứu: `docs/research/2026-09-24-mcp-phap-ly-goi.md`, `docs/research/2026-09-24-doi-chieu-ung-dung-mcp.md`. Lưu ý đã kiểm chứng: MCP có quyền ghi của ChatGPT chỉ mở cho gói Business/Enterprise/Edu; gửi dữ liệu khách qua AI nước ngoài cần hồ sơ đánh giá tác động chuyển dữ liệu (Luật 91/2025, Nghị định 356) |
| M9 Hợp đồng dịch vụ + đợt thanh toán | ⬜ | | Kế hoạch `docs/superpowers/plans/2026-09-19-m9-contracts-and-payments.md` |
| M10 Tiếp nhận khách | ⬜ | | Kế hoạch `docs/superpowers/plans/2026-09-22-m10-intake.md` |
| M12 Ứng dụng điện thoại (PWA) + thông báo đẩy | ⬜ | | Kế hoạch `docs/superpowers/plans/2026-09-24-m12-pwa.md` |

**Thứ tự làm đã chốt với chủ văn phòng: M6.5 → phần còn lại của M6 → M7 → M8 → M11 → M9 → M10
→ M12** (ghi trong sổ tay điều phối M6.5 ngày 2026-09-25). Bảng trên xếp theo thứ tự này, không
theo mã milestone như `docs/SPEC.md` §13. M11 đứng trước M9 và M10 vì chủ văn phòng chốt ngày
2026-09-24 làm MCP ngay sau M8. M12 là milestone mới, kế hoạch
`docs/superpowers/plans/2026-09-24-m12-pwa.md`.

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

- **`trustProxies` là một quyết định của M8, và nó đổi nghĩa của hai cột đã ghi.** Hôm nay
  ứng dụng không khai `trustProxies` ở bất cứ đâu (`bootstrap/app.php`, `config/`, `.env.example`
  — đã tìm, không có gì), nên `request()->ip()` trả `REMOTE_ADDR`. Hai cột đọc từ đó và cả hai
  được trình bày như bằng chứng: `stage_log_views.ip` (SPEC §4.18, "khách đã được cho xem") và
  `client_users.last_login_ip`. Sau một CDN hoặc một reverse proxy, giá trị ghi được là địa chỉ
  của CDN/proxy chứ không của khách — `X-Forwarded-For` bị bỏ qua, và bỏ qua là ĐÚNG khi chưa
  khai proxy nào đáng tin, vì một header ai cũng đặt được thì không phải bằng chứng. Việc của
  M8: quyết định có khai hay không, khai những dải nào, và ghi lại quyết định đó — vì một dòng
  cấu hình lặng lẽ sẽ làm những dòng ghi TRƯỚC và SAU nó nói về hai thứ khác nhau mà không ai
  phân biệt được khi đọc lại. Docblock `RecordStageLogView` đã nói rõ hôm nay cột đó là địa chỉ
  nào, để không ai đọc nó rộng hơn thực tế.

- **`RetractDocument` — đặc tả đã viết, cài đặt ở M7 Task 7.** *(Sửa 2026-09-27, M6.5 Task 21,
  `docs/docs-6`: bản M4 ghi "cài ở M6" và "xoá mềm là đường thu hồi tạm thời". Cả hai sai. Việc
  này nằm ở M7 Task 7 (`docs/superpowers/plans/2026-09-21-m7-handover-and-archive.md`), và giao
  diện không có nút xoá tài liệu nào, nên xoá mềm chưa bao giờ là một đường nhân sự dùng được.
  Sau M6.5, đường rút tạm duy nhất là **chuyển tài liệu vào nhóm D** (`RegroupDocument`, chỉ cần
  `document.update`, ghi `document_regrouped`; phán quyết Task 16). `PublishDocument` vẫn từ chối
  lời gọi tắt "Cho khách xem". Chuyển vào nhóm D ẩn tài liệu khỏi khách mà **không báo cho khách
  biết**, đúng chỗ `RetractDocument` phải sửa.)* Phần dưới là đặc tả gốc của M4, giữ nguyên trừ
  hai chỗ vừa nêu. Hình dạng đã chốt: một trạng thái thứ ba
  `retracted` (không phải một cờ bị lật — `status = published` cộng `client_can_view = false`
  là hai nguồn sự thật nói ngược nhau), lý do bắt buộc ≥ 20 ký tự `mb_strlen`, gác bằng
  `document.publish`, **một thông báo khách NHÌN THẤY ĐƯỢC** (họ có thể đang cầm tệp trong tay;
  một dòng "văn phòng đã rút lại tài liệu này, lý do: …" là thứ duy nhất ngăn họ tiếp tục dùng
  một bản sai), và một dòng `document_retracted` nối được với `document_downloads` để trả lời
  "khách đã mở bản đó trước khi mình rút chưa". ~~Cài ở M6 vì tầng thông báo cho khách ở đó.~~
  ~~Trong lúc chờ, xoá mềm là đường thu hồi tạm thời~~ (sai, xem đầu mục). Nếu sau này có
  đường xoá mềm tài liệu: `Document` dùng `LogsActivity` từ vòng sửa Task 3, nhưng với model có
  `SoftDeletes` spatie ghi giá trị cũ dưới khoá `old` chứ không phải `attributes`; ai đọc nhật ký
  đó phải biết điều này.
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
- **`document_downloads.document_id` phải rời `cascadeOnDelete` TRƯỚC khi M7 Task 7 viết đường
  xoá cứng của `RetractDocument`, không phải cùng lúc** (bản M4 ghi "M6"; sửa 2026-09-27, M6.5
  Task 21 — kế hoạch M7 Task 7 đã ghi đúng thứ tự này). SPEC §4.12 bắt ghi "mọi lượt tải" và bảng đó
  tồn tại để trả lời "khách đã mở bản này chưa" — nhưng khoá ngoại đang xoá theo tài liệu, nên
  bằng chứng chết cùng chính cái dòng nó chứng minh. Chừng nào chưa có đường xoá cứng thì chưa ai
  mất gì; ngày có, mất là mất im lặng và không khôi phục được. Thứ tự đúng: đổi sang
  `nullOnDelete` (hoặc `restrictOnDelete`) trong một migration RIÊNG, rồi mới viết
  `RetractDocument`.
- `TransitionMatterStage` vẫn nhận `DateTimeInterface|string` và đưa thẳng vào `Carbon::parse`,
  tức vẫn mang lỗi đã sửa ở hai Action tài liệu: một chuỗi không parse được thành 500 thay vì
  một lỗi xác thực trên form.
- ~~`RunConflictCheck` đối chiếu lại MỌI bên đã có ở mỗi lần chạy (cố ý). Hệ quả: một khi mức đỏ
  đã bị ghi đè, mọi lần thêm bên sau đó trên cùng vụ việc lại trả về đỏ...`~~ **Đã sửa, M6.5
  Task 8 (R13c/`conflict-01`).** `RunConflictCheck` vẫn đối chiếu lại MỌI bên đã có ở mỗi lần
  chạy — hành vi đó vẫn cố ý và không đổi — nhưng `ConflictCheckResult` giờ tách khớp MỚI khỏi
  khớp đã xác nhận/ghi đè ở một lần chạy TRƯỚC trên cùng vụ việc (`ConflictMatch::$pairKey`, đọc
  lại từ `confirmed_pairs` mà `OpenMatter`/`AddMatterParty` ghi vào `matter_opened`/
  `matter_party_added`). Khớp cũ vẫn hiện (`ConflictCheckResult::$confirmedMatches`), chỉ không
  còn chặn lại.
- ~~`SyncClientPartyIdentities` có thể TẠO RA một xung đột mức đỏ... mà không có lần kiểm tra
  nào chạy sau đó... là thời điểm thứ ba và cần một quyết định.~~ **Đã quyết và cài đặt, M6.5
  Task 8 (R13e/`conflict-04`).** Sau khi đồng bộ định danh, Action chạy lại `RunConflictCheck`
  cho mọi vụ việc ĐANG MỞ (`closed_at` null) có một bên trỏ về khách hàng vừa sửa; kết quả vàng
  hoặc đỏ MỚI sinh thông báo trong hệ thống (`Notification::sendToDatabase()`) cho người được
  xem vụ — qua `App\Actions\Notification\ResolveStaffRecipients`, nơi DUY NHẤT chọn người nhận
  theo R3 (Task 12/14 dùng lại) — và một dòng audit `client_identity_conflict_detected`.
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

## Ghi chú M5

### CHẶN RA MẮT — `TRUSTED_PROXIES` phải được điền trước khi cổng đứng trước mặt khách

Đây là một mục **chặn**, không phải một ghi chú, và nó thay mục "`trustProxies` là một quyết
định của M8" ở phần hoãn của M4: cơ chế đã dựng xong ở M5, chỉ còn CON SỐ là chưa có.

Cài đặt: `config/trustedproxy.php` đọc biến môi trường `TRUSTED_PROXIES` (ngăn cách bằng dấu
phẩy, hoặc `*`), và `Illuminate\Http\Middleware\TrustProxies` — vốn nằm sẵn trong danh sách
middleware toàn cục — đọc khoá ấy ở thời điểm xử lý request. Mặc định **không tin ai**, nên máy
dev và bộ test không đổi hành vi. Không khai được ở `bootstrap/app.php` vì callback
`withMiddleware()` chạy trước khi cấu hình và biến môi trường được nạp (lý do đầy đủ nằm trong
chính tệp đó).

**Phải điền `TRUSTED_PROXIES` bằng địa chỉ thật của proxy trước khi cổng khách hàng đứng trước
mặt khách, nếu không chiều IP của SPEC §10.3 khoá toàn bộ cổng và hai cột bằng chứng ghi lại địa
chỉ của proxy.** Cụ thể, đo được: với `REMOTE_ADDR = 10.0.0.5` và `X-Forwarded-For =
203.0.113.99`, `request()->ip()` trả `10.0.0.5`. Sau một proxy, mọi khách dùng chung đúng một
địa chỉ — tức một bộ đếm duy nhất cho cả cổng, và năm lần gõ sai của bất kỳ ai khoá mọi khách
hàng trong 15 phút. Cùng lúc đó `client_users.last_login_ip` và `stage_log_views.ip` ghi địa chỉ
của proxy; cột thứ hai là bằng chứng văn phòng dùng để nói "khách đã được cho xem cập nhật này"
(SPEC §4.18), nên một cột bằng chứng ghi nhầm địa chỉ là một cột bằng chứng không dùng được.

Kiểm bằng hai test ở `tests/Feature/Portal/LoginTest.php` ("ignores a forwarded-for header while
no proxy is trusted", "reads the real client address through a proxy once that proxy is named")
cộng một test hỏi thẳng tệp cấu hình, vì hai test kia vẫn xanh khi tệp bị xoá.

### Đăng nhập cổng khách (Task 1) — vòng sửa sau rà soát

- **Khoá đếm của SPEC §10.3 phải gấp chuỗi email y như collation của cột gấp nó.** `client_users.email`
  là `utf8mb4_unicode_ci`: nó bỏ qua hoa/thường, **bỏ qua dấu**, coi `ｕ` nửa rộng bằng `u`, `ﬁ`
  bằng `fi`, `ß` bằng `ss`. Đo trên MariaDB 11.8: `'user@example.test' = 'usér@example.test'` trả
  1, và cả hai đều qua được luật `email` của Laravel lẫn **đăng nhập vào đúng một hàng**. Khoá cũ
  băm `mb_strtolower(trim())` nên mỗi biến thể có một bộ đếm rỗng riêng — riêng dấu tiếng Việt đã
  cho vài chục biến thể cho một địa chỉ, tức trần 5 lần / 15 phút gia hạn được vô hạn.
  `PortalLoginThrottle::foldEmail()` nay gấp bốn bước (NFKC → `MB_CASE_FOLD` → NFD rồi bỏ
  `\p{Mn}` và `\p{Cf}` → `trim`). **Không đổi collation ở M5** — đó vẫn là quyết định của M8, và
  unique index dưới `_ci` vốn đã coi các biến thể có dấu là một hàng.
  `đ` ≠ `d`, `ø` ≠ `o`, `æ` ≠ `ae` — đo được, và phép gấp giữ đúng như vậy.
- **SQLite không nhìn thấy lỗ hổng trên** (nó so byte), nên test đối chiếu mở một kết nối MariaDB
  riêng trỏ vào `information_schema` và hỏi từng cặp một: *MariaDB bằng ⇔ khoá bằng*. Không có
  máy chủ nào thì test tự bỏ qua kèm lý do. Trên container dev nó CHẠY thật.
- **Một lần đăng nhập thành công chỉ xoá chiều TÀI KHOẢN của bộ đếm, không bao giờ chiều IP.**
  Trước vòng sửa, `clear()` lặp qua cả hai khoá, nên một lần đăng nhập bất kỳ từ một địa chỉ đặt
  lại bộ đếm IP cho mọi người sau địa chỉ đó: ai có một tài khoản dùng được cũng mua được vô hạn
  cửa sổ 5 lần cho mọi email khác, và trên wifi văn phòng thì một lần đăng nhập hợp lệ xoá bộ
  đếm của người ngồi cạnh. Cộng với lỗi gấp khoá ở trên thì §10.3 mất cả hai chiều.
- **Đổi mật khẩu lần đầu xong không được đá khách ra màn hình đăng nhập.** `AuthenticateSession`
  chạy ở lần tải trang đầy đủ chứ không ở request cập nhật Livewire, nên phiên còn giữ băm mật
  khẩu CŨ và cú chuyển hướng ngay sau đó là chỗ nó gọi `logout()` + `session()->flush()`. Nay
  `ChangePassword` cất băm mới vào phiên (giống `Filament\Auth\Pages\EditProfile`), nhưng qua kho
  phiên toàn cục chứ không qua `request()->session()`: request Livewire dựng trong test không
  mang phiên, và khi đó điều kiện `hasSession()` của Filament lặng lẽ bỏ qua đúng dòng ấy.
- **`ChangePassword::canAccess()` nay hỏi `must_change_password`.** `CanAuthorizeAccess::canAccess()`
  mặc định `true` cho mọi trang tự viết, nên trước đó khách nào cũng mở được `/portal/change-password`
  bất cứ lúc nào và đọc "Đây là lần đầu anh/chị đăng nhập" — một câu sai — rồi đổi mật khẩu mà
  không phải nhắc lại mật khẩu đang dùng. **Đổi mật khẩu tự nguyện (có ô "mật khẩu hiện tại") là
  một tính năng riêng, hoãn có chủ ý**, không phải một việc bị quên.
- `auth.timebox_duration` được ghim ở **500 ms**. `Hash::check` đo được 152–160 ms với bcrypt cost
  12; mặc định 200 ms của framework chỉ còn ~30 ms dư để nuốt một dòng nhật ký và hai lần ghi
  cache, tức SPEC §10.10 dựa vào một khoảng dư không ai ghim.
- Nhánh hỏng cuối cùng của SPEC §10.6 nay cũng để lại vết: khi lớp cha kiểm lại credentials SAU
  khi mã đã đúng (tài khoản bị vô hiệu hoặc mật khẩu bị đổi NGAY TRONG lúc khách đọc thư), nó gọi
  thẳng `throwFailureValidationException()` mà không qua `fireFailedEvent()` — trước đây đó là
  nhánh duy nhất đập bộ đếm mà không ghi dòng nào.
- `SendLoginCode::$code` nay là `private readonly` kèm `__debugInfo()` che: `#[SensitiveParameter]`
  chỉ che khung gọi hàm khởi tạo trong stack trace, không che `var_dump()` hay `json_encode()`.
- `Login::rateLimit()` nay **kiểm** hai tham số của nơi gọi thay vì im lặng bỏ qua: nếu một bản
  Filament sau đổi `rateLimit(5)` thành một con số khác, lớp này dừng lại ồn ào thay vì giữ 5.

### M5 hoàn tất — cổng khách hàng (2026-09-22)

**Đạt cổng merge:** `bin/dev test:mariadb` **1219 xanh / 0 đỏ** (5497 khẳng định, 646 giây),
`bin/dev test` 1218 xanh / 1 bỏ qua có lý do, `bin/dev pint --test` sạch trên 394 tệp.
Cơ sở dữ liệu thật là thước đo của milestone này, không phải bản nhẹ — xem lý do ngay dưới.

**Khách hàng làm được gì, sau M5.** Đăng nhập bằng mật khẩu cộng mã một lần qua email, khoá sau
năm lần sai theo cả tài khoản lẫn địa chỉ mạng. Xem danh sách hồ sơ; có đúng một hồ sơ thì vào
thẳng trang tiến độ. Đọc tiến độ viết bằng lời người thường, biết còn thiếu giấy tờ gì, chụp ảnh
nộp thẳng từ điện thoại, đọc nguyên văn lý do khi bị từ chối rồi nộp lại, gửi câu hỏi và nhận trả
lời. Văn phòng nhìn ngược lại thấy nhãn khách đã xem lúc nào, và hồ sơ khách chưa xem quá năm
ngày thì được nhắc gọi điện.

**Chín lỗi nghiêm trọng bị bắt trong milestone này, không lỗi nào do bộ test tự phát hiện.** Mỗi
lỗi đến từ một lượt rà soát độc lập được giao nhiệm vụ *giả định có một lỗi nghiêm trọng*. Đáng
nhớ nhất, theo thứ tự bài học:

1. **Bộ đếm chống dò mật khẩu gia hạn được vô hạn**, hai vòng sửa liên tiếp đều sai. Cả hai vòng
   đều cố chép tay quy tắc so sánh chữ của cơ sở dữ liệu vào PHP; vòng một sai một kiểu, vòng hai
   chép kỹ hơn và sai ba kiểu mới. Phán quyết cuối bỏ hẳn cách đó: **đếm theo tài khoản, tra ra
   hàng bằng đúng truy vấn mà phần đăng nhập vẫn chạy**, để chính cơ sở dữ liệu làm việc so sánh
   và không còn bản sao quy tắc nào để lệch.
2. **Phiếu "khách đã xem" ghi được cho một trang khách chưa hề nhận** — một kiểu yêu cầu trả thân
   rỗng vẫn sinh phiếu, và một lỗi giữa chừng trang cũng để lại phiếu. Đây là cột chứng cứ, nên
   lời khẳng định trong mã đã được **thu hẹp đúng bằng thứ đo được**: máy chủ đã dựng xong một
   câu trả lời thành công có chứa dòng đó, chứ không phải nó đã tới được trình duyệt.
3. **Yêu cầu của khách giao được cho luật sư đã nghỉ việc**, và cổng khách liền báo "văn phòng
   đang xem" về một việc không ai mở được. Lớp kiểm tra tài khoản còn hoạt động đã có sẵn và gắn
   đúng — nhưng gắn vào người bấm nút, không gắn vào người được giao.
4. **Hai vợ chồng cùng một khách hàng khoá lẫn nhau**: giới hạn tải tệp đếm theo địa chỉ mạng chứ
   không theo tài khoản, nên người thứ hai bị chặn ngay tệp đầu, suốt một giờ, kèm câu báo lỗi đổ
   cho tệp quá lớn và sóng yếu trong khi cả hai đều sai.
5. **Bộ test đỏ trên cơ sở dữ liệu thật mà xanh trên bản nhẹ**: một phép kiểm dò câu lệnh bằng cú
   pháp của SQLite. Hệ quả nặng hơn cái test đỏ — cuộc đua hai tab trên bảng chứng cứ **chưa từng
   được đo một lần nào** trên loại cơ sở dữ liệu sẽ chạy thật.

**Sáu bài kiểm rỗng bị phát hiện** — xanh vì một lý do khác với lý do chúng mang tên. Đủ nhiều để
thành luật cho các milestone sau: *khi một phép kiểm mang tên một đường đi qua yêu cầu mạng thì
nó phải thật sự gửi một yêu cầu; truyền tham số thẳng vào hàm là một đường khác đội lốt cùng câu
chữ.* Hai trường hợp nữa là **xanh nhờ giàn kiểm**: `actingAs($user, 'client')` âm thầm đổi luôn
loại đăng nhập mặc định của ứng dụng và che mất lỗi số 4; và một phép kiểm đưa cho hàm xử lý lỗi
nguyên thân phản hồi mà trình duyệt không bao giờ gửi.

**Ba công cụ được đưa vào dùng, không cài thêm gói nào.** `bin/dev test:mariadb` và một lượt chạy
thứ hai trong CI trên cơ sở dữ liệu thật — trước đó mọi phép kiểm chỉ nói thật trên MariaDB đều
**tự bỏ qua trong im lặng**, và đó chính là cách lỗi số 1 sống qua hai vòng. `pest-plugin-arch`
nằm sẵn trong vendor từ đầu dự án mà chưa ai dùng, nay thành tám luật kiến trúc, trong đó ba luật
giữ cho nghiệp vụ, model và phân quyền không biết gì tới giao diện — đó là thứ giữ lời hứa sau
này gắn được một lớp API cho ứng dụng di động mà không viết lại luật nào. Và `pest-plugin-mutate`
cũng có sẵn, để tự động hoá việc thử phá điều kiện mà cả dự án đang làm tay.

**Mang sang M6:** một chú thích nói quá về phạm vi của trang báo lỗi 403; một lần từ chối làm mất
phiếu của mọi dòng sau nó trong cùng một yêu cầu; luật quét dấu vết gỡ lỗi chưa thấy `die` trong
view và route, đồng thời báo nhầm vài lời gọi hợp lệ; và một chú thích ở route tải tệp nói rằng
throttle đứng sau chữ ký trong khi thứ tự thật là ngược lại, nên người dùng bắn nhiều id sai sẽ
tự khoá đường tải hợp lệ của chính mình trong một phút.

## Ghi chú M6.5

Nhánh `m6-5-flow-fixes`, cắt từ `main` tại `46ccd8d`. Đầu vào là đợt rà soát quy trình
2026-09-24 (`docs/audits/2026-09-24-quy-trinh.md`, bản đầy đủ `docs/audits/raw/2026-09-24-quy-trinh.json`):
102 phát hiện, gồm 90 được xác nhận (9 critical, 47 important, 34 minor) và 12 còn tranh chấp; 4
phát hiện khác bị bác bỏ (`requests/REQ-9`, `notify/notify-9`, `notify/notify-12`, `roles/roles-09`)
và không nằm trong bảng dưới. Kế hoạch: `docs/superpowers/plans/2026-09-24-m6-5-flow-fixes.md`.
Sổ tay điều phối đầy đủ (từng vòng sửa, từng phán quyết, từng việc hoãn):
`.superpowers/sdd/2026-09-24-m6-5-flow-fixes/progress.md`. Mục này là bản rút gọn để người đọc
được, không thay sổ tay.

Công việc chạy qua nhiều phiên điều phối và nhiều làn song song (worktree `D:\vkwt\lane-{b,c,d,e}`,
nhánh `m6-5-lane-{b,c,d,e}`), vì API bị giới hạn phiên nhiều lần và một lần bị giới hạn tuần
(2026-09-26).

### Trạng thái lúc viết (2026-09-27)

- **Xong và đã qua rà soát:** Task 1–13, 15–20.
- **Task 14** (mốc hạn: sửa, xoá, widget 7 ngày, cảnh báo quá hạn) đang hoàn tất trên làn D.
- **Còn lại của Task 21:** nghiệm thu (bộ test hai CSDL, vòng migration MariaDB, chạy lại kịch bản
  e2e trên `vk_crm_walk`), rà soát toàn nhánh bằng opus, rồi hợp nhất vào `main`.
- **Vị trí mã:** `m6-5-flow-fixes` có Task 1–5, 7, 8, 11, 12, 15–19. Nhánh `m6-5-lane-d`
  (`2dbd11c`) là nhánh gộp: có mọi thứ ở trên trừ vòng sửa 2 của Task 12 (`2485a7a`), cộng Task 6,
  9 (làn B), 10, 20 (làn C) và 13 (làn D). Hai nhánh phải gộp lại trước nghiệm thu.
- **Một test đỏ đã biết khi gộp làn D:** `ActivityLogEventTranslationsTest` — các khoá audit mới
  của các làn chưa có nhãn trong `lang/vi/activity.php`. Theo sổ tay, Task 21 chạy lại test này
  sau khi gộp.

### Phán quyết R1–R14 của chủ nhiệm

Chủ văn phòng giao ngày 2026-09-24 ("làm tiếp đến khi hoàn thành"). Các phán quyết này lấp chỗ
SPEC im lặng hoặc tự mâu thuẫn. Bản đầy đủ ở phần đầu kế hoạch M6.5. Kế hoạch không ghi giá nếu
sai cho R1–R14; dòng "Giá nếu sai" dưới đây do Task 21 suy ra.

- **R1 — Phạm vi.** M6.5 sửa lỗi của luồng đang dùng được và dựng màn hình mà luồng đó cần nhưng
  không kế hoạch nào nhận. M6.5 **không** viết các thư mới của M6 Task 3 và 4
  (`client.document_published`, `client.document_rejected`, `client.activation`,
  `staff.new_client_document`, `staff.new_client_request`); các phát hiện về đúng những thư đó
  chuyển sang M6 Task 3 và 4. *Giá nếu sai:* khách và văn phòng tiếp tục không được báo ở các sự
  kiện đó cho tới khi M6 xong.
- **R2 — Mọi thư đi qua hàng đợi, sau commit, không bao giờ trong transaction** (trừ mã OTP đăng
  nhập). Listener `ShouldQueue`, job có `tries` và `backoff`. Thư hỏng để lại dòng
  `outbound_messages` `failed`, không bị rollback. Luật sư không bao giờ gặp lỗi máy chủ vì máy chủ
  thư chết. *Giá nếu sai:* thư đến trễ theo nhịp hàng đợi (tối đa khoảng một phút với
  `queue:work --stop-when-empty` mỗi phút).
- **R3 — Người nhận thư về một vụ việc là người được xem vụ đó**: đang `is_active` và qua
  `Gate::forUser($u)->allows('view', $matter)`. "Toàn bộ vai trò manager" (SPEC §6.8) đọc là "mọi
  manager được xem vụ đó"; vụ `restricted` thay manager bằng admin. Không ai hợp lệ thì đi chuỗi
  dự phòng: người phụ trách → luật sư phụ trách → manager được xem vụ → admin. Không bao giờ im
  lặng. *Giá nếu sai:* một manager không được xem vụ hạn chế sẽ không được báo về vụ đó, kể cả khi
  văn phòng muốn họ biết.
- **R4 — Luật sư mở vụ cho khách mới.** Không có `client.manage`, không thấy danh sách khách. Trong
  form mở vụ, người có `matter.create` (a) nhập đúng số điện thoại hoặc CCCD (qua `Normalizer`),
  khớp đúng một hồ sơ thì được chọn hồ sơ đó, không gợi ý theo tên, không đếm; hoặc (b) tạo khách
  mới ngay trong form, trùng định danh chính xác thì dùng hồ sơ đã có. Mỗi lần tra ghi audit
  `client_lookup`, không ghi số thô. *Giá nếu sai:* ai có `matter.create` dò được một số điện
  thoại có phải khách của văn phòng không; kiểm tra xung đột vốn đã cho thấy điều này.
- **R5 — `matter.update` "hạn chế" của trợ lý** là mọi việc trừ quyết định đưa gì ra cho khách
  (công tắc cổng, công bố mốc hạn: đòi `stageLog.publish`) và cấu trúc vụ việc (đổi
  `confidentiality`, quản lý đội ngũ, bàn giao: đòi `matter.update` **và** không phải trợ lý).
  *Giá nếu sai:* trợ lý phải nhờ luật sư ở những việc văn phòng có thể muốn giao cho họ.
- **R6 — Đội ngũ vụ việc.** Thêm/gỡ vai `associate`, `assistant`, `observer`; vai `lead` chỉ đổi qua
  `ReassignMatter`. Người quản lý đội ngũ: luật sư phụ trách vụ, manager, admin. Gỡ người còn giữ
  mốc chưa xong hoặc yêu cầu khách chưa đóng thì bị từ chối kèm danh sách. Người mở vụ không có
  `matter.viewAny` mà giao người khác phụ trách thì tự vào đội ngũ vai `associate`. Luật ai thấy
  vụ hạn chế không đổi. *Giá nếu sai:* phải chuyển việc trước mới gỡ được người.
- **R7 — Nghỉ việc (kéo lên từ M7).** `ReassignMatter` cho một vụ theo SPEC §6.11 bước 1–5 (bàn
  giao hàng loạt vẫn là M7 Task 2). Vô hiệu hoá và xoá nhân sự bị chặn khi người đó còn lead vụ
  đang mở, còn phụ trách mốc chưa xong, hoặc còn được giao yêu cầu khách chưa đóng; thông điệp nêu
  số lượng từng loại. Admin đang hoạt động cuối cùng không tự hạ chức, tự vô hiệu hoá hay tự xoá.
  *Giá nếu sai:* không cho ai nghỉ được trước khi bàn giao xong từng vụ bằng tay.
- **R8 — "Kết thúc vụ việc" là vào một giai đoạn `is_terminal`.** `TransitionMatterStage` ghi
  `closed_at` khi vào, xoá khi rời (đường bỏ qua của admin). "Vụ đang mở" có đúng một định nghĩa,
  `Matter::scopeOpen()` (`closed_at` null và `deleted_at` null). M7 Task 3 dựng lưu trữ trên cột
  này. *Giá nếu sai:* văn phòng nào coi "Kết thúc" khác với giai đoạn `is_terminal` sẽ thấy widget
  và nhắc hạn bỏ vụ đó sớm hơn ý mình.
- **R9 — Vòng đời văn bản nhóm B.** "Trình duyệt" (`internal_draft` → `pending_approval`): ai có
  quyền `update` tài liệu. "Đã ký, đã nộp" (`pending_approval` → `signed_filed`): đòi
  `document.publish`; sau đó mới công bố được. Rời nhóm B đòi `document.publish` và bị từ chối khi
  chưa `signed_filed`/`published` (bản mở rộng ở Task 16, xem dưới). *Giá nếu sai:* luật sư có
  `document.publish` vẫn tự duyệt bản của mình; SPEC không tách người soạn và người duyệt.
- **R10 — Một lần nộp của khách gồm nhiều tệp và là một phiên bản.** Nộp thêm vào đầu mục đang
  `pending_review` là bổ sung vào version đang chờ; nộp sau khi bị từ chối là version mới; cổng
  hiện mọi tệp của version mới nhất. *Giá nếu sai:* khách nộp nhầm một tệp vào version đang chờ
  không thay được, phải chờ văn phòng từ chối.
- **R11 — Duyệt gắn với đúng những tệp người duyệt đã thấy.** `ReviewChecklistItem` nhận tập id tài
  liệu hộp xác nhận đã hiện; khác tập hiện tại thì từ chối bằng một câu tiếng Việt; audit ghi id đã
  duyệt. *Giá nếu sai:* thêm một lần mở lại hộp xác nhận khi khách vừa nộp thêm.
- **R12 — Tài khoản cổng do nhân sự tạo.** `must_change_password` luôn `true` khi tạo và khi đặt lại
  mật khẩu; mật khẩu tạm theo `PasswordRule::default()`; `activated_at` chỉ hệ thống ghi, lúc khách
  đổi mật khẩu lần đầu (có migration backfill); thư cho khách chỉ tới tài khoản `is_active` **và**
  `activated_at` không null; cổng bỏ ô "Ghi nhớ đăng nhập". *Giá nếu sai:* khách chưa từng đăng
  nhập không nhận thư tiến độ nào (luật sư được cảnh báo trên form, Task 7).
- **R13 — Kiểm tra xung đột.** (a) khách quay lại không xung đột với chính hồ sơ cũ của mình (bên
  khớp là `is_our_client` cùng `client_id`); (b) hai khách của văn phòng ở hai phía đối lập trong
  cùng vụ là Đỏ; (c) khi thêm bên, chỉ khớp mới chặn, khớp đã xác nhận/ghi đè trước đó vẫn hiện
  nhưng không chặn; (d) mỗi khớp mang nhãn "bên phía mình"; (e) sửa định danh khách thì kiểm tra lại
  mọi vụ đang mở liên quan, kết quả vàng/đỏ mới sinh thông báo (R3) và audit; (f) vai khách hàng bỏ
  `opposing_counsel`; (g) kiểm tra và lưu của `OpenMatter`/`AddMatterParty` nằm trong cùng
  `Cache::lock('conflict-check')`. *Giá nếu sai:* (c) có thể che một khớp cũ mà mức của nó tăng sau
  này — Task 8 chặn đúng chỗ đó bằng cách lưu mức đã chấp nhận.
- **R14 — Sửa không xoá lịch sử.** Sửa vụ, mốc, bên đều ghi audit nêu trường đổi, không bao giờ ghi
  số CCCD thô. Gỡ một bên là xoá mềm kèm lý do bắt buộc, và bên đã gỡ **không** còn trong đối chiếu
  xung đột ("nhập nhầm, chưa từng là bên"). Vụ việc xoá mềm thì khác: các bên của nó vẫn được tính.
  Xoá mốc hạn là xoá mềm kèm lý do. *Giá nếu sai:* một bên bị gỡ nhầm (thật ra là bên thật) biến
  khỏi đối chiếu xung đột.

### Phán quyết trong lúc làm (từ sổ tay điều phối)

Mỗi dòng "Ruling" của sổ tay, dịch và rút gọn, theo nhóm. Dòng nào sổ tay không ghi giá nếu sai
thì giá dưới đây do Task 21 suy ra, đánh dấu *(suy ra)*.

**Điều phối**
- Làm trong checkout chính, không dùng worktree, vì container `bin/dev` chỉ mount
  `D:\crmkhachhang`. Giá nếu sai: không có cô lập. Về sau bị thay bằng phán quyết làn song song.
- Chọn model: implementer sonnet; reviewer opus cho task bảo mật, cách ly dữ liệu, xung đột, thư
  (2, 3, 4, 6, 7, 8, 11, 12, 13, 20), sonnet cho phần còn lại; vòng sửa 4–5 và rà soát cuối opus.
  Giá nếu sai: review yếu hơn bỏ sót lỗi, rà soát cuối phải bắt.
- Làn song song (chủ văn phòng 2026-09-25 "bật nhiều agent chạy cùng lúc"): worktree
  `D:\vkwt\lane-{b,c,d}`, test qua `/d/vkwt/wt-dev`, mỗi làn một CSDL MariaDB; điều phối viên gộp
  làn vào `m6-5-flow-fixes` sau mỗi task sạch review. Giá nếu sai: xung đột gộp ở tệp lang/policy
  dùng chung, giải bằng subagent.
- Làn E (`D:\vkwt\lane-e`) chạy Task 7 song song thay vì xếp sau Task 16. Giá nếu sai: thêm một lần
  gộp.
- Tăng tốc (đề xuất của phiên song song, chủ văn phòng đồng ý): review opus cho task cổng khách,
  xung đột, tiền, bảo mật; task giao diện và câu chữ một review sonnet; minor để rà soát cuối; task
  ít rủi ro tối đa 2 vòng sửa. Giá nếu sai: lỗi giao diện sống tới rà soát cuối.
- Khi opus hết hạn mức tuần (2026-09-26): tiếp tục bằng sonnet, tối đa 2–3 agent; task lẽ ra
  review opus (Task 5 và các task bảo mật sau đó) được rà soát cuối opus phủ lại. Giá nếu sai: một
  lỗi tinh vi chờ tới rà soát cuối.
- 2026-09-27: opus mở lại; review bảo mật/cổng/xung đột và rà soát cuối trở lại opus; tối đa 3
  agent. Giá nếu sai *(suy ra)*: chi phí cao hơn.
- Vòng sửa tầm thường (Task 13 vòng 2 đổi đúng một điều kiện; Task 6 vòng 3 chỉ sửa test): điều
  phối viên tự đọc toàn bộ diff, không re-review riêng. Giá nếu sai: rà soát cuối opus phủ lại.
- Opus cho implementer và reviewer các task còn lại (chủ văn phòng mở hạn mức cao nhất
  2026-09-27). Giá nếu sai: chi phí cao hơn, chủ văn phòng đã chấp nhận.

**Rà trước khi chạy (chỗ các task dùng chung)**
- Task 2 tạm dùng `whereNull('closed_at')`; Task 5 thay bằng `Matter::open()`. Giá nếu sai: sót
  một định nghĩa "vụ đang mở" thứ hai.
- `App\Support\OpenWork` là một nơi duy nhất đếm việc dở dang của một người (lead vụ mở, mốc chưa
  xong, yêu cầu khách chưa đóng); Task 3 tạo, Task 4 dùng lại. Giá nếu sai: hai định nghĩa lệch
  nhau.
- Luật sư tạo khách ngay trong form mở vụ được phép nhờ `matter.create`, chỉ bên trong
  `CreateMatter`; `ClientPolicy::create` không đổi. Giá nếu sai: đặt trên policy thì luật sư tạo
  được khách ở ngoài vụ việc.
- (Bị thay) Thư tổng hợp khi bàn giao của Task 4 tự `ShouldQueue`. Bị thay bằng phán quyết hoãn
  thư đó sang M7 Task 1 (xem Task 4).

**Task 2 — cách ly tài khoản cổng**
- Khách bị xoá mềm giữa phiên: `EnsurePortalAccountIsActive` đăng xuất, huỷ phiên, đưa về trang
  đăng nhập cổng với câu cùng loại với tài khoản bị khoá. Không trả 403 như brief viết, vì SPEC
  §10.10 trả 404 cho yêu cầu bị từ chối và không được để một người đã đăng nhập mắc kẹt sau trang
  404. Giá nếu sai: một nhánh middleware và một test phải sửa lại.
- Lỗ thao tác hàng loạt kiểu C1 (thiếu `authorizeIndividualRecords` và các ability `*Any`) ở
  `UsersTable`, `MatterTypesTable`, `StagesRelationManager` được giao cho Task 4 và Task 19. Giá
  nếu sai: hai task nở thêm; không sửa thì luật "không xoá loại vụ việc đang có vụ" bị vượt.

**Task 3 — đội ngũ vụ việc**
- `AddTeamMember` từ chối người sẽ không qua `Gate::view` của vụ sau khi thêm (vụ `restricted`); ô
  chọn bỏ những người đó. Lý do: SPEC §4.6 vụ hạn chế chỉ lead và admin thấy; thêm người không thấy
  được là vô ích và làm rò thư. Giá nếu sai: văn phòng muốn có trợ lý trên vụ hạn chế phải đổi SPEC.
- `OpenWork` chỉ đếm việc trên vụ còn tồn tại và đang mở. Giá nếu sai: một mốc lạc trên vụ đã đóng
  không còn chặn nghỉ việc.
- Thiếu transaction/khoá ở `AddTeamMember`/`RemoveTeamMember` (bấm hai lần ra lỗi 500, đua với
  phân loại yêu cầu) được nâng thành Important vì Review Focus của kế hoạch có tên nó. Giá nếu sai
  *(suy ra)*: Task 3 nở thêm một vòng sửa.
- Vụ `restricted` do luật sư A mở và giao lead B: A mất quyền xem ngay sau khi lưu. Đúng R6; không
  bắt `CreateMatter` cảnh báo trước. Giá nếu sai: A bất ngờ; admin khôi phục được.
- `TriageClientRequest` khoá dòng `matters` **trước** dòng `client_requests`, cùng thứ tự với
  `RemoveTeamMember`, để giao việc và gỡ thành viên đi tuần tự. Giá nếu sai: thêm một khoá dòng mỗi
  lần phân loại.
- `OpenMatter` tự thêm người mở vào đội ngũ vai `associate` bỏ qua khi người đó không xem được vụ
  mới (restricted). Giá nếu sai: không (dòng đó không cho quyền gì).

**Task 4 — bàn giao và nghỉ việc**
- **Hoãn thư tổng hợp mốc hạn cho lead mới sang M7 Task 1.** Kế hoạch M7 đã ghi "chỉ còn thư tổng
  hợp bị hoãn", và R1 không cho viết mẫu thư mới trong M6.5. Giá nếu sai: lead mới chỉ biết mốc
  được chuyển qua màn hình cho tới M7.
- Giữ lead cũ làm `associate` phải theo luật của `AddTeamMember`: vụ mà lead cũ sẽ không xem được
  (restricted) thì gỡ hẳn thay vì giữ. Giá nếu sai: không.
- Thêm nút "Đổi người phụ trách" cho từng mốc (`ChangeDeadlineResponsible`): người mới phải đang
  hoạt động, chưa xoá, và xem được vụ; khoá trước; có audit. Thông điệp chặn nghỉ việc chỉ đúng
  đường xử lý (mốc: đổi người phụ trách; yêu cầu: giao lại). Giá nếu sai: Task 14 phải dựng màn
  hình sửa mốc quanh nút này.
- Hạ chức một người đang lead vụ mở xuống vai không lead được bị từ chối như vô hiệu hoá. Hạ xuống
  **kế toán** bị từ chối khi còn bất kỳ việc mở nào (lead, mốc, yêu cầu); hạ xuống **trợ lý** chỉ bị
  từ chối khi còn lead vụ. Giá nếu sai: admin phải bàn giao hoặc chuyển mốc trước khi đổi chức danh.

**Task 5 — sửa vụ việc, kết thúc, huỷ**
- `CheckDeadlines` dùng `Matter::open()`: không nhắc mốc của vụ đã đóng hay đã huỷ. Giá nếu sai
  *(suy ra)*: một mốc thật trên vụ bị đóng nhầm sẽ im lặng cho tới khi admin mở lại vụ.
- Sửa `summary_for_client` đòi cùng quyền với công bố cho khách (`stageLog.publish`); trợ lý không
  sửa được (ô bị khoá, Action từ chối). Giá nếu sai *(suy ra)*: trợ lý phải nhờ luật sư sửa tóm
  tắt.
- Đổi `confidentiality` (cả hai chiều) chỉ lead hoặc admin; chuyển sang `restricted` bị từ chối khi
  đội ngũ còn người khác ngoài lead và admin, kèm danh sách cần gỡ. Giá nếu sai *(suy ra)*: phải
  gỡ thành viên trước khi siết vụ.
- Các bên của vụ đã huỷ (xoá mềm) **vẫn** nằm trong đối chiếu xung đột: báo nhầm an toàn hơn bỏ sót
  với một kiểm soát đạo đức nghề. Giá nếu sai *(suy ra)*: thêm khớp giả mà luật sư phải xác nhận.

**Task 6 — luật sư mở vụ cho khách mới**
- R4(b) chỉ dùng lại hồ sơ trùng khi người thao tác **nhìn thấy** được khách đó; không thấy thì từ
  chối bằng câu trung tính không nêu tên ai ("Có thể khách hàng này đã có hồ sơ ở văn phòng. Nhờ
  trưởng phòng hoặc quản trị viên mở vụ."). Giá nếu sai: lộ việc "có một khách mang định danh
  này", điều R4(a) đã cho biết.
- Giới hạn 20 lần/giờ cho mỗi nhân sự, cả tra định danh lẫn nhánh dò trùng khi tạo mới; có câu
  tiếng Việt và audit (`client_lookup_throttled`). Giá nếu sai: ngày tiếp nhận đông chạm trần.
- R4(a) không cho thấy một khách mà mọi vụ chưa xoá của khách đó đều `restricted` và người tra
  không liệt kê được; người tra nhận cùng câu trung tính. Khách không có vụ nào, hoặc có ít nhất một
  vụ thường/liệt kê được, vẫn tra ra. `resolveClientId` kiểm lại luật này khi lưu. Giá nếu sai:
  luật sư phải nhờ manager/admin mở vụ cho khách quay lại mà chỉ được biết qua vụ hạn chế.

**Task 7 — tài khoản cổng**
- Đổi email tài khoản cổng thì `activated_at = null` và `must_change_password = true`. Giá nếu sai
  *(suy ra)*: khách phải kích hoạt lại cả khi nhân sự chỉ sửa chữ hoa/thường (xem việc nhỏ hoãn
  lại của Task 7).
- Mở khoá đăng nhập: lỗi ở bước mã một lần cũng ghi audit kèm IP như bước mật khẩu;
  `UnlockPortalLogin` chỉ xoá khoá theo IP khi **mọi** lần sai trong cửa sổ của khoá đó thuộc tài
  khoản này (an toàn khi nhiều người chung một IP NAT); nếu không thì báo rõ IP vẫn đang bị khoá.
  Giá nếu sai: kẻ tấn công chỉ nhắm tài khoản này từ một IP được gỡ luôn khoá IP đó.
- Backfill bỏ qua `activated_at` đang có: `activated_at = last_login_at` (null nếu chưa từng đăng
  nhập); cùng migration xoá `client_users.remember_token` (chưa có dữ liệu thật). Giá nếu sai
  *(suy ra)*: tài khoản được nhân sự gõ tay `activated_at` mà chưa đăng nhập bị coi là chưa kích
  hoạt — đúng ý R12.

**Task 8 — kiểm tra xung đột**
- Khoá của R13(c) là id dòng bên phía mình ↔ id dòng bên khớp, cộng mức đã chấp nhận; một khớp chỉ
  coi là đã xác nhận khi mức mới ≤ mức đã xác nhận. Giá nếu sai: hỏi lại nhiều hơn.
- Khớp giữa hai phía đối lập trong cùng vụ đi theo R13(c) như mọi khớp khác; bên đã gỡ (xoá mềm)
  bị loại khỏi mọi đối chiếu, kể cả trong cùng vụ (R14). Giá nếu sai: không; làm ngược lại thì lead
  bị chặn mãi.
- R13(e) kiểm tra lại **mọi vụ đang mở** có bên khớp với định danh mới, không chỉ vụ của khách vừa
  sửa, và báo lead/manager của vụ đó qua `ResolveStaffRecipients`; một vụ đã đóng của khách không
  làm việc này im lặng. Giá nếu sai *(suy ra)*: thêm thông báo cho người không cần biết.
- Các minor chạm tới đúng/sai của kiểm tra xung đột được làm ngay: khoá `unique()` gồm `pairKey`;
  `LockTimeoutException` thành câu từ chối tiếng Việt; `qualify()` loại user đã xoá; truy vấn kiểm
  tra lại bỏ `ClientPortalScope`; blade bỏ lớp Tailwind, dùng style nội tuyến; `OpenMatter` từ chối
  `client_role = opposing_counsel`; test (e) đi qua màn hình `EditClient`. Giá nếu sai *(suy ra)*:
  Task 8 nở thêm.
- Kiểm tra lại R13(e) chạy **sau khi commit** việc đồng bộ định danh, dưới khoá `conflict-check`;
  lỗi ở bước này không bao giờ rollback việc sửa định danh của khách. Giá nếu sai: không.
- Bước kiểm tra lại trở thành job hàng đợi `RecheckClientIdentityConflicts` (hàng đợi database,
  dispatch `afterCommit`, payload chỉ id khách, tính lại khi chạy); `LockTimeoutException` thoát ra
  để thử lại; `failed()` ghi audit và báo admin trong hệ thống. Thay cho `try/catch` + log của vòng
  sửa 2. Giá nếu sai: kết quả kiểm tra lại đến trễ vài phút.
- `OpenMatter` bước 5 đọc lại định danh khách dưới khoá dòng khách và dựng định danh các bên gắn
  với khách từ giá trị **hiện tại** đó (đóng khe hash cũ). Giá nếu sai: thêm một lần đọc có khoá mỗi
  lần mở vụ.
- Khi `OpenMatter`/`AddMatterParty` làm mới dưới khoá mà thấy định danh khách đã khác bản đã kiểm
  tra, dispatch kiểm tra lại sau commit; `SyncClientPartyIdentities` dispatch cả khi đồng bộ 0 dòng.
  Giá nếu sai: thêm một lần kiểm tra lại vô hại.
- Lịch `queue.drain` dùng `withoutOverlapping(10)` để một lần chạy bị giết không khoá hàng đợi 24
  giờ. Giá nếu sai: không.
- Chấp nhận job kiểm tra lại bị trùng trong ca hiếm "sửa khách đúng lúc đang mở vụ"
  (`ShouldBeUnique` có thể bỏ mất một lần kiểm tra cần thiết). Giá nếu sai: thỉnh thoảng admin
  nhận một thông báo trùng.
- Dispatch nhánh (a) bên trong khoá `conflict-check` là được, vì production dùng hàng đợi database
  (SPEC §2); hàng đợi `sync` không phải cấu hình production được hỗ trợ. Giá nếu sai: không, ở
  production.

**Task 9 — các bên**
- Mọi thay đổi `matter_parties` (thêm, sửa, gỡ, và bước lưu bên của `OpenMatter`) chạy dưới cùng
  khoá `conflict-check`; `RemoveMatterParty` cũng lấy khoá đó. Giá nếu sai: gỡ một bên phải chờ tới
  hết thời gian khoá khi đang có kiểm tra chạy.

**Task 10 — chuyển giai đoạn và công bố**
- Gỡ công bố một mốc hạn chỉ làm giảm cái khách thấy, nên chỉ cần `matter.update`; bật công bố cần
  `stageLog.publish` (cùng lối với "vào nhóm D luôn được"). Giá nếu sai: trợ lý giấu được một mốc
  khỏi khách.

**Task 11 — thư qua hàng đợi**
- `SendDeadlineReminderMail` hết lượt thử thì `failed()` (1) bỏ bậc đó khỏi `reminders_sent` dưới
  khoá dòng mốc, để lần `CheckDeadlines` sau thử lại, và (2) ghi audit và gửi thông báo trong hệ
  thống cho người phụ trách mốc, lead (qua `ResolveStaffRecipients`) và admin đang hoạt động. Giá
  nếu sai: một lời nhắc có thể bị gửi lại vào hôm sau.

**Task 12 — người nhận và nội dung thư**
- `ResolveStaffRecipients` có thêm `supervisorsFor(Matter)` (manager xem được vụ; admin khi vụ
  `restricted`), dùng bởi `CheckDeadlines`, `SendDeadlineReminderMail::failed` và
  `SyncClientPartyIdentities`. Job suy lại toàn bộ người nhận lúc gửi; lead thế chỗ người phụ trách
  không hợp lệ ở mọi bậc; Reply-To và URL logo chuyển vào `BrandedMailable` (theo cấu hình, an toàn
  khi để trống); job nhắc mốc bỏ qua người đã nhận thư cho đúng mốc và bậc đó (không gửi trùng khi
  thử lại). Giá nếu sai: thông báo của `SyncClientPartyIdentities` ngừng tới admin ở vụ thường.
- Bậc nhắc được ghi vào dòng nhật ký thư qua một header nội bộ (bị gỡ trước khi gửi như các header
  nội bộ khác); chống gửi trùng dựa trên bản ghi liên quan + người nhận + đã gửi + bậc (không dựa
  trên tiêu đề, vốn đổi theo ngày); `markSent` được bọc `catch` + `report`; khi cả người phụ trách
  lẫn lead đều không hợp lệ, thêm `supervisorsFor` ở mọi bậc. Giá nếu sai *(suy ra)*: manager hoặc
  admin nhận thêm thư nhắc ở các bậc sớm (14/7/3 ngày) mỗi khi mốc không còn ai phụ trách hợp lệ.

**Task 13 — nhật ký thư**
- Admin thấy mọi dòng `outbound_messages`, kể cả dòng mồ côi, dòng có loại liên quan không nhận ra,
  và dòng của vụ đã xoá mềm; người khác theo luật `listableBy`. Giá nếu sai *(suy ra)*: không mất
  gì — admin vốn xem được mọi vụ.

**Task 15 — danh mục hồ sơ mẫu**
- `Repeater::relationship()` ghi đầu mục mẫu mà không hỏi `ChecklistTemplateItemPolicy`; chấp nhận,
  cùng cách làm với `StagesRelationManager`, và cả hai policy cùng đòi `settings.manage`. Có
  docblock nói rõ. Giá nếu sai: một luật riêng cho đầu mục mẫu về sau sẽ không được áp.
- Thao tác danh mục (thêm, duyệt, không áp dụng) trên vụ **đã đóng** không bị chặn ở đâu (có từ
  trước, SPEC im lặng, R8 chỉ định nghĩa `closed_at`). Hoãn sang M7 (lưu trữ, chỉ đọc sau khi đóng);
  đã ghi vào kế hoạch M7 Task 3. Giá nếu sai: nhân sự vẫn sửa được danh mục của vụ đã đóng cho tới
  M7.

**Task 16 — văn bản nhóm B và công bố**
- R9 mở rộng: (a) chuyển **vào** nhóm D luôn được với `document.update` (chỉ làm giảm cái khách
  thấy; giữ đường rút của M4); (b) rời B sang A hoặc C đòi `document.publish` **và** một trong hai:
  tài liệu đã `signed_filed`/`published`, hoặc một lý do bắt buộc (≥ 10 ký tự) ghi vào audit như một
  lần sửa xếp nhầm nhóm. Giá nếu sai: một luật sư chuyển được bản nháp B chưa ký sang C nếu viết lý
  do (người đó vốn đã tự đánh dấu `signed_filed` được).
- Thêm "Trả về bản nháp" (`pending_approval` → `internal_draft`, đòi `document.publish`, có audit),
  vì một bản bị từ chối duyệt nội bộ trước đó nằm ở `pending_approval` mãi, không rời B được, không
  xoá được. Giá nếu sai: một Action nhỏ.
- Nhân sự tải tệp giữ tên tệp gốc; chỉ tải từ cổng khách mới dùng tiêu đề tài liệu. Giá nếu sai:
  không.
- Form công bố cũ (hai tab bật lại quyền tải): `PublishDocument` từ chối khi các cờ lúc mở form khác
  dòng hiện tại. Giá nếu sai: thêm một lần từ chối khi hai người cùng sửa.

**Task 17 — khách nộp giấy tờ và văn phòng duyệt**
- `ChecklistProgress`: Y chỉ tính tài liệu **nhóm A** (`ClientProvided`); nhóm B/C không bao giờ vào
  mẫu số của khách, đã công bố hay chưa. Thay cho ghi chú trước đó trong sổ tay ("B/C đã công bố tính
  vào mẫu số tuỳ chọn"). Giá nếu sai: một đầu mục tuỳ chọn chỉ được thoả bằng văn bản văn phòng phát
  hành thì không hiện là đã đếm.
- `ClientDocumentSubmitted` bắn **một lần mỗi lần nộp**, mang mọi tài liệu và version của lần nộp đó
  (chưa có listener; phải làm trước khi M6 Task 4 viết `staff.new_client_document`). Giá nếu sai
  *(suy ra)*: không; làm ngược lại thì M6 Task 4 gửi N thư cho một lần nộp N tệp.

**Task 18 — yêu cầu từ khách**
- `last_activity_at` = tin nhắn mới nhất của khách hoặc nhân sự, hoặc lần đổi trạng thái; `assign()`
  chỉ cập nhật khi nó đổi trạng thái (`new` → `in_progress`); backfill =
  lớn nhất của `created_at` và lần trả lời mới nhất, không bao giờ dùng `updated_at`; cả tab admin
  lẫn danh sách "Yêu cầu của tôi" ở cổng sắp theo cột này. Giá nếu sai: một luồng đã giao nhưng im
  lặng không nổi lên đầu.

**Task 20 — dấu vết đăng nhập, mật khẩu, nhật ký**
- Trang nhật ký che số điện thoại, email, địa chỉ (ví dụ `09xx***678`, `a***@domain`) và chặn hẳn
  số CCCD, mật khẩu, bí mật 2FA; không phân biệt hoa thường, đi đệ quy. Admin vẫn xem đủ trên màn
  hình khách hàng. Trang hồ sơ cá nhân của nhân sự để email chỉ đọc (chỉ admin đổi email đăng nhập,
  qua `EditUser`) và áp `PasswordRule::default()` cho mật khẩu mới. Giá nếu sai *(suy ra)*: người
  đọc nhật ký phải mở hồ sơ khách mới thấy số đầy đủ.

### Bảng mã phát hiện → task → trạng thái

Đủ 102 mã được xác nhận hoặc còn tranh chấp trong `docs/audits/2026-09-24-quy-trinh.md`. Cột
"Mức" và "Kiểm chứng" lấy từ tệp audit. "Task M6.5" lấy từ dòng "Phát hiện" của từng task trong
kế hoạch; "đã sửa" nghĩa là task đó đã xong và qua rà soát theo sổ tay điều phối, **chưa** qua
nghiệm thu và rà soát toàn nhánh. Không mã nào ở trạng thái "CHƯA RÕ".

Tổng: 84 đã sửa (`requests/REQ-2` chỉ một phần), 5 đang hoàn tất ở Task 14, 11 chuyển sang M6
Task 3/4, 1 sang M7 Task 7, 1 sang M8 Task 8.

| Mã | Mức | Kiểm chứng | Phát hiện (rút gọn tiêu đề trong audit) | Task M6.5 | Trạng thái |
|---|---|---|---|---|---|
| `intake/intake-01` | critical | xác nhận | Không có cách nào thêm người vào đội ngũ vụ việc: trợ lý và luật sư phối hợp không bao… | 3 | đã sửa (Task 3) |
| `intake/intake-02` | critical | xác nhận | Không quản lý được mẫu danh mục hồ sơ, không thêm được đầu mục: 3/6 loại vụ việc mở ra… | 15 | đã sửa (Task 15) |
| `intake/intake-03` | important | xác nhận | Chỉ trưởng phòng hoặc quản trị mở được vụ đầu tiên cho khách mới; đường vòng 'nhờ trợ lý… | 6 | đã sửa (Task 6, R4) |
| `intake/intake-04` | minor | tranh chấp | Thư cập nhật hồ sơ gửi tới cả địa chỉ email chưa ai xác minh (tài khoản chưa từng đăng… | 7 | đã sửa (Task 7, R12) |
| `intake/intake-05` | important | tranh chấp | Form tài khoản cổng cho tắt 'buộc đổi mật khẩu', nhận mật khẩu '1'; activated_at không… | 7 | đã sửa (Task 7, R12) |
| `intake/intake-06` | important | xác nhận | Vụ việc không sửa được sau khi mở: số thụ lý, toà án, tiêu đề, khách chọn nhầm, các bên… | 5 | đã sửa (Task 5) |
| `intake/intake-07` | important | tranh chấp | Không có kiểm tra trùng khi tạo khách hàng, dù QUY-TRINH ghi [Xong] | 6 | đã sửa (Task 6) — cảnh báo trùng hai lượt, không chặn cứng |
| `intake/intake-08` | important | xác nhận | Form khách hàng: ô 'Người đại diện' không hiện khi tạo khách là tổ chức; độ dài form vượt… | 6 | đã sửa (Task 6) |
| `intake/intake-09` | minor | xác nhận | Ô 'Loại vụ việc' khi mở vụ liệt kê cả loại đã ngưng hoạt động | 5 | đã sửa (Task 5) |
| `intake/intake-10` | minor | xác nhận | Test 'mở vụ end-to-end' xanh trên một đường mà khách mới không bao giờ đi; không test nào… | 3, 6 | đã sửa (Task 3 phần đội ngũ, Task 6 phần khách mới) |
| `conflict/conflict-01` | important | tranh chấp | Sau khi trưởng phòng ghi đè mức đỏ, luật sư phụ trách vĩnh viễn không thêm được bên nào… | 8 | đã sửa (Task 8, R13c) |
| `conflict/conflict-02` | important | xác nhận | Với luật sư, mức vàng bật ở mọi lần mở vụ vì chính hồ sơ cũ của khách bị coi là trùng;… | 8 | đã sửa (Task 8, R13a) |
| `conflict/conflict-03` | important | xác nhận | Văn phòng được đánh dấu đại diện cả nguyên đơn lẫn bị đơn trong cùng một vụ mà kết quả… | 8 | đã sửa (Task 8, R13b) |
| `conflict/conflict-04` | important | xác nhận | Sửa CCCD/SĐT khách hàng có thể làm lộ ra một xung đột đỏ mà không ai được báo | 8 | đã sửa (Task 8, R13e) — kiểm tra lại chạy bằng job hàng đợi |
| `conflict/conflict-05` | important | xác nhận | Không có cách sửa hay gỡ một bên đã nhập: CCCD/SĐT gõ sai hoặc cờ 'khách hàng của VP' bật… | 9 | đã sửa (Task 9, R14) |
| `conflict/conflict-06` | minor | xác nhận | Bằng chứng kiểm tra lúc mở vụ không gắn với vụ việc; lý do ghi đè và danh sách trùng… | 8, 20 | đã sửa (Task 8 gắn dòng kiểm tra vào vụ; Task 20 hiện `properties` trên trang nhật ký) |
| `conflict/conflict-07` | minor | xác nhận | Bảng/thông báo kết quả không nói bên nào của vụ đang mở bị trùng | 8 | đã sửa (Task 8, R13d) |
| `conflict/conflict-08` | minor | xác nhận | Không bấm được sang xem hồ sơ trùng như SPEC mô tả | 9, 21 | đã sửa (Task 9 giữ bảng tĩnh; SPEC §6.10 đính chính ở Task 21) |
| `conflict/conflict-09` | minor | xác nhận | Trên tab Các bên, danh sách hồ sơ trùng trong thông báo dính thành một dòng | 9 | đã sửa (Task 9) |
| `conflict/conflict-10` | minor | xác nhận | Ô số điện thoại từ chối cách viết '(+84) 912 345 678' và '+84 (0) 912-345-678' dù… | 9 | đã sửa (Task 9) |
| `conflict/conflict-11` | minor | xác nhận | Hai người mở đồng thời hai vụ đối nhau có thể cùng ra xanh | 8 | đã sửa (Task 8, R13g) |
| `conflict/conflict-12` | minor | xác nhận | Chọn vai khách hàng là related/third_party/opposing_counsel thì mức đỏ không bao giờ bật;… | 8 | đã sửa (Task 8, R13f) |
| `stage/stage-01` | important | xác nhận | Thư báo tiến độ gửi đồng bộ trong request: SMTP lỗi thì luật sư thấy lỗi dù dòng đã công… | 11 | đã sửa (Task 11, R2) |
| `stage/stage-02` | minor | tranh chấp | Chuyển giai đoạn KHÔNG công bố vẫn đổi nhãn giai đoạn khách thấy trên cổng, trái với câu… | 10 | đã sửa (Task 10) — sửa lời trên bản xem trước |
| `stage/stage-03` | important | xác nhận | Không có chỗ nào ghi closed_at: vụ đã sang giai đoạn 'Kết thúc' nằm mãi trong widget 'Hồ… | 5 | đã sửa (Task 5, R8) |
| `stage/stage-04` | minor | tranh chấp | Admin không dùng được quyền bỏ qua allowed_next (SPEC §6.2) trên giao diện: chuyển nhầm… | 10 | đã sửa (Task 10) — quyền bỏ qua của admin có trên giao diện |
| `stage/stage-05` | minor | xác nhận | Không khoá dòng vụ việc khi chuyển giai đoạn: hai lần gửi đồng thời đều đi từ cùng… | 10 | đã sửa (Task 10) |
| `stage/stage-06` | minor | xác nhận | Docblock và test hứa 'mở lại tài khoản thì lời báo còn nguyên', nhưng sản phẩm không có… | 7, 11 | đã sửa (Task 7 cảnh báo luật sư; Task 11 bỏ lời hứa sai) — không có đường gửi bù, xem `notify-13` |
| `stage/stage-07` | minor | xác nhận | Bản xem trước dùng lớp Tailwind không có trong CSS được phục vụ: khung không có viền,… | 10 | đã sửa (Task 10) |
| `stage/stage-08` | minor | xác nhận | Mẫu gợi ý của giai đoạn 'Kết thúc' (20 ký tự) và 'Xét xử sơ thẩm' hình sự (19 ký tự) bị… | 10 | đã sửa (Task 10) |
| `stage/stage-09` | minor | xác nhận | Hai định nghĩa 'quá hạn cập nhật': cột màu trên danh sách vụ việc khác widget SLA | 5 | đã sửa (Task 5) |
| `docs/docs-1` | critical | xác nhận | Văn bản nhóm B không bao giờ công bố được qua giao diện: không có đường nào tới… | 16 | đã sửa (Task 16, R9) |
| `docs/docs-2` | important | xác nhận | Chuyển nhóm B → C (hoặc A) rồi công bố là vượt được vòng đời nhóm B; trợ lý làm được bước… | 16 | đã sửa (Task 16, R9 và bản mở rộng) |
| `docs/docs-3` | important | xác nhận | DocumentPublished được bắn mà không có listener: khách không bao giờ được báo có văn bản… | — | chuyển sang M6 Task 3 (R1) |
| `docs/docs-4` | minor | xác nhận | Form 'Công bố cho khách' luôn điền sẵn 'Cho khách tải về = bật', không đọc giá trị hiện… | 16 | đã sửa (Task 16) |
| `docs/docs-5` | minor | xác nhận | Tệp khách tải về mang tên tệp gốc của nhân sự, không phải tiêu đề đã duyệt | 16 | đã sửa (Task 16) — chỉ tải từ cổng mang tiêu đề; nhân sự tải giữ tên gốc |
| `docs/docs-6` | minor | xác nhận | Không có nút xoá hay thu hồi tài liệu; PROGRESS ghi 'xoá mềm là đường thu hồi tạm thời'… | — | chuyển sang M7 Task 7; Task 21 sửa câu sai trong PROGRESS |
| `docs/docs-7` | minor | xác nhận | Test tải lên qua Filament đi đường MIME khác production; chú thích sai về luật mimetypes;… | 16 | đã sửa (Task 16) |
| `checklist/checklist-01` | important | xác nhận | Khách không bao giờ được báo giấy tờ bị từ chối, trong khi màn hình nói với luật sư là đã… | — | chuyển sang M6 Task 3 (R1) |
| `checklist/checklist-02` | important | xác nhận | Không có giao diện quản lý danh mục mẫu hay thêm đầu mục: 3/6 loại vụ việc có danh mục… | 15 | đã sửa (Task 15) |
| `checklist/checklist-03` | important | xác nhận | Giấy nhiều trang nộp từng tệp bị ghi thành 'phiên bản thay thế', trang trước biến mất… | 17 | đã sửa (Task 17, R10) |
| `checklist/checklist-04` | important | xác nhận | Thao tác 'Đã nhận'/'Cần nộp lại' không gắn với phiên bản tệp: tệp đến lúc hộp xác nhận… | 17 | đã sửa (Task 17, R11) |
| `checklist/checklist-05` | important | xác nhận | Văn phòng gắn văn bản nhóm B/C vào đầu mục tuỳ chọn thì khách bị báo 'chúng tôi còn chờ ở… | 17 | đã sửa (Task 17) — Y chỉ tính nhóm A, SPEC §4.10 đính chính |
| `checklist/checklist-06` | minor | xác nhận | Mẫu lý do 'Nộp nhầm tài liệu' đưa nguyên ngoặc vuông '[tên đầu mục]' tới màn hình khách | 17 | đã sửa (Task 17) |
| `checklist/checklist-07` | minor | xác nhận | Chưa có test nào đo hai lần nộp đồng thời vào cùng đầu mục trên MariaDB | 17 | đã sửa (Task 17) |
| `portal/portal-1` | important | xác nhận | Ô 'Ghi nhớ đăng nhập' cho vào lại cổng 400 ngày, bỏ qua cả mật khẩu lẫn mã một lần, và… | 7 | đã sửa (Task 7, R12) |
| `portal/portal-2` | important | xác nhận | Trường 'Tóm tắt cho khách' (summary_for_client) không bao giờ hiện trên portal dù SPEC… | 5 | đã sửa (Task 5) |
| `portal/portal-3` | minor | xác nhận | Khách hàng đã bị xoá mềm vẫn đăng nhập cổng, xem hồ sơ và sinh phiếu 'đã xem' như thường | 2 | đã sửa (Task 2) — trả 404 và kết thúc phiên, không phải 403 |
| `portal/portal-4` | minor | xác nhận | Câu khoá tạm bảo khách 'gọi văn phòng nếu cần vào ngay' nhưng văn phòng không có cách nào… | 7 | đã sửa (Task 7) |
| `requests/REQ-1` | important | tranh chấp | Văn phòng không có bất kỳ cách nào biết khách vừa gửi yêu cầu, trong khi cổng khách báo… | — | chuyển sang M6 Task 4 (R1) |
| `requests/REQ-2` | important | xác nhận | Khách hỏi tiếp vào một luồng cũ thì không ai được báo, và kế hoạch M6 cũng không tính tới… | 18 | đã sửa phần sắp xếp hộp thư (Task 18); phần báo cho văn phòng chuyển sang M6 Task 4 |
| `requests/REQ-3` | important | xác nhận | Luồng đã giao cho nhân sự mà người đó bị vô hiệu hoá hoặc xoá sau đó thì bị bỏ quên,… | 4, 18 | đã sửa (Task 4 chặn nghỉ việc; Task 18 hiển thị và gỡ người khi mở lại luồng) |
| `requests/REQ-4` | important | xác nhận | Khách không được báo là văn phòng đã trả lời, và trang hồ sơ trên cổng không có dấu hiệu… | — | chuyển sang M6 Task 4 (mẫu `client.request_answered`, SPEC §9) |
| `requests/REQ-5` | minor | xác nhận | Đánh dấu 'Đã trả lời' mà không viết câu trả lời thì cổng khách vẫn bảo khách 'xem bên… | 18 | đã sửa (Task 18) |
| `requests/REQ-6` | minor | xác nhận | Trả lời một yêu cầu thuộc hồ sơ đã gỡ khỏi cổng vẫn báo 'Khách đọc được ngay trên cổng… | 18 | đã sửa (Task 18) |
| `requests/REQ-7` | minor | xác nhận | Cột 'Người gửi' trong hộp thư để trống khi tài khoản khách đã bị xoá mềm | 18 | đã sửa (Task 18) |
| `requests/REQ-8` | minor | xác nhận | Việc tài khoản người nhà đọc được yêu cầu của nhau chỉ được ghi trong docblock, còn giao… | 18, 21 | đã sửa (Task 18 nhãn giao diện; SPEC §5 đính chính ở Task 21) |
| `deadlines/F1` | critical | xác nhận | Một hộp thư bị SMTP từ chối làm dừng cả lượt nhắc hạn 07:00, rollback nhật ký thư, và lặp… | 11 | đã sửa (Task 11, R2) |
| `deadlines/F2` | important | xác nhận | Thư nhắc hạn của vụ restricted gửi tới trưởng phòng và trợ lý là những người không được… | 12 | đã sửa (Task 12, R3) |
| `deadlines/F3` | important | xác nhận | Tiêu đề thư nhắc ghi con số của bậc chứ không ghi số ngày thật còn lại ('Còn 7 ngày' khi… | 12 | đã sửa (Task 12) |
| `deadlines/F4` | important | xác nhận | Vô hiệu hoá hoặc xoá tài khoản luật sư phụ trách làm các bậc nhắc 14/7/3 ngày không đến… | 4, 12 | đã sửa (Task 4 đổi người phụ trách; Task 12 chuỗi dự phòng R3) |
| `deadlines/F5` | important | xác nhận | Widget trang chủ 'Mốc thời hạn 7 ngày tới' (SPEC §7.1 mục 2) không tồn tại và không nằm… | 14 | đang hoàn tất (Task 14) |
| `deadlines/F6` | important | xác nhận | Bậc quá hạn không 'tạo thông báo cảnh báo' trong hệ thống như SPEC §6.8 đòi; chỉ gửi đúng… | 14 | đang hoàn tất (Task 14) |
| `deadlines/F7` | important | xác nhận | Không sửa được ngày/tên/người phụ trách và không xoá được một mốc: phiên toà hoãn thì… | 14 | đang hoàn tất (Task 14) |
| `deadlines/F8` | minor | xác nhận | Mốc của vụ việc đã xoá mềm vẫn được nhắc (tiêu đề ra '()') và không ai đánh dấu hoàn… | 5 | đã sửa (Task 5; `CheckDeadlines` dùng `Matter::open()`) |
| `deadlines/F9` | minor | xác nhận | Không test nào ghim việc CheckDeadlines được đăng ký lúc 07:00; test 'giờ Việt Nam' chỉ… | 14 | đang hoàn tất (Task 14) |
| `notify/notify-1` | important | xác nhận | Thư tiến độ gửi đồng bộ: SMTP lỗi thì luật sư gặp lỗi 500 sau khi dòng đã commit, bấm lại… | 11 | đã sửa (Task 11, R2) |
| `notify/notify-2` | critical | xác nhận | CheckDeadlines gửi thư bên trong DB::transaction: một lần gửi hỏng dừng việc nhắc mọi mốc… | 11 | đã sửa (Task 11, R2) |
| `notify/notify-3` | important | xác nhận | Thư nhắc hạn của vụ hạn chế gửi mã và tiêu đề vụ cho quản lý và trợ lý, những người không… | 12 | đã sửa (Task 12, R3) |
| `notify/notify-4` | important | xác nhận | Người phụ trách mốc hạn bị khoá tài khoản thì mốc im lặng hoàn toàn tới bậc 1 ngày, kể cả… | 4, 12 | đã sửa (Task 4 phần chặn; Task 12 chuỗi dự phòng R3) |
| `notify/notify-5` | important | xác nhận | DocumentPublished được bắn mà không có ai nghe: khách không bao giờ được báo có văn bản… | — | chuyển sang M6 Task 3 (R1) |
| `notify/notify-6` | important | xác nhận | ChecklistItemRejected được bắn mà không có ai nghe: khách bị từ chối giấy tờ vẫn nghĩ hồ… | — | chuyển sang M6 Task 3 (R1) |
| `notify/notify-7` | important | tranh chấp | Khách gửi yêu cầu qua cổng thì không ai trong văn phòng được báo, trong khi cổng nói 'Văn… | — | chuyển sang M6 Task 4 (R1) |
| `notify/notify-8` | important | xác nhận | Không có màn hình tra cứu outbound_messages: câu 'tôi không nhận được thông báo' không… | 13 | đã sửa (Task 13) |
| `notify/notify-10` | minor | xác nhận | Liên kết cổng khách trong thư dựng từ host của request quản trị, sai khi tách… | 12 | đã sửa (Task 12) |
| `notify/notify-11` | minor | xác nhận | Header X-VKCRM-* trong thư gửi khách lộ id tuần tự nội bộ (stage_log id, id nhật ký thư) | 12 | đã sửa (Task 12) |
| `notify/notify-13` | minor | xác nhận | Lời hứa 'mở lại tài khoản thì lời báo còn nguyên' không có đường sản phẩm nào thực hiện;… | 11 | đã sửa (Task 11) — bỏ lời hứa sai; gửi bù cho tài khoản kích hoạt sau: hoãn có chủ đích, chưa kế hoạch nào nhận |
| `notify/notify-14` | minor | xác nhận | Tên người gửi hiện 'VK-CRM' và không có Reply-To: khách thấy tên kỹ thuật trong hộp thư,… | 12 | đã sửa (Task 12) |
| `roles/roles-01` | critical | xác nhận | Luật sư sửa tài khoản cổng của khách khác thì bị ép chuyển tài khoản sang khách của mình,… | 2 | đã sửa (Task 2) |
| `roles/roles-02` | important | xác nhận | Luật sư thấy tên khách hàng, email và số điện thoại của mọi tài khoản cổng trong văn… | 2 | đã sửa (Task 2) |
| `roles/roles-03` | critical | xác nhận | Không ai thêm được trợ lý hay luật sư thành viên vào đội ngũ vụ việc, nên vai trò trợ lý… | 3 | đã sửa (Task 3) |
| `roles/roles-04` | important | tranh chấp | Luật sư không mở được vụ đầu tiên cho khách mới, kể cả sau khi trợ lý đã tạo hồ sơ khách,… | 6 | đã sửa (Task 6, R4) |
| `roles/roles-05` | important | xác nhận | Quyền matter.update 'hạn chế' của trợ lý thực tế là toàn quyền: trợ lý bật/tắt được công… | 5, 10 | đã sửa (Task 10 công tắc công bố; Task 5 tóm tắt cho khách và mức bảo mật; R5) |
| `roles/roles-06` | important | xác nhận | Không màn hình nào cấu hình được danh mục hồ sơ mẫu hay thêm một đầu mục cho vụ việc, nên… | 15 | đã sửa (Task 15) |
| `roles/roles-07` | important | xác nhận | Admin xoá được một luật sư còn dẫn vụ đang mở, để lại vụ việc không có luật sư phụ trách… | 4 | đã sửa (Task 4, R7) |
| `roles/roles-08` | important | tranh chấp | Khách gửi yêu cầu qua cổng thì không nhân sự nào được báo, không ai được giao, không có… | — | chuyển sang M6 Task 4 (R1) |
| `spec-gap/spec-gap-01` | critical | xác nhận | Không có cách nào thêm người vào đội ngũ vụ việc: vụ mở bằng giao diện chỉ có luật sư phụ… | 3 | đã sửa (Task 3) |
| `spec-gap/spec-gap-02` | important | xác nhận | Thư client.stage_update gửi đồng bộ thay vì qua job như SPEC §6.2: SMTP lỗi thì luật sư… | 11 | đã sửa (Task 11, R2) |
| `spec-gap/spec-gap-03` | important | xác nhận | closed_at không bao giờ được ghi: vụ đã chuyển sang giai đoạn 'Kết thúc' vẫn nằm trong… | 5 | đã sửa (Task 5, R8) |
| `spec-gap/spec-gap-04` | important | xác nhận | Không có màn hình quản lý danh mục hồ sơ mẫu (SPEC §7.4): 3/6 loại vụ việc không có danh… | 15 | đã sửa (Task 15) |
| `spec-gap/spec-gap-05` | important | xác nhận | Widget 'Mốc thời hạn 7 ngày tới' (SPEC §7.1 mục 2) chưa có và không có task nào, dù mã đã… | 14 | đang hoàn tất (Task 14) |
| `spec-gap/spec-gap-06` | important | xác nhận | Không có màn hình sửa vụ việc: số thụ lý, toà án, tiêu đề, tóm tắt cho khách và mức bảo… | 5 | đã sửa (Task 5) |
| `spec-gap/spec-gap-07` | important | xác nhận | Không có trang xem nhật ký thư (SPEC §7.4 'trang xem OutboundMessage') trong khi… | 13 | đã sửa (Task 13) |
| `spec-gap/spec-gap-08` | important | tranh chấp | Câu hỏi khách gửi qua cổng không báo cho ai; ba sự kiện nghiệp vụ đang được bắn mà không… | — | chuyển sang M6 Task 3/4 (R1) |
| `spec-gap/spec-gap-09` | minor | xác nhận | Liên kết cổng khách trong thư stage_update lấy theo tên miền của request luật sư, nên trỏ… | 12 | đã sửa (Task 12) |
| `spec-gap/spec-gap-10` | minor | xác nhận | §14.1 'độ phủ ≥ 80%' không đo được với công cụ hiện có: container không có pcov/xdebug và… | — | chuyển sang M8 Task 8 (cài `pcov`) |
| `e2e/F1` | critical | xác nhận | Tab Tiến độ vỡ vĩnh viễn sau lần cập nhật đầu tiên trên vụ việc chưa bật cổng khách | 1 | đã sửa (Task 1) |
| `e2e/F2` | important | xác nhận | Thư tiến độ gửi đồng bộ, không qua hàng đợi: SMTP lỗi thì luật sư gặp trang lỗi sau khi… | 11 | đã sửa (Task 11, R2) |
| `e2e/F3` | important | xác nhận | CheckDeadlines dừng cả lượt ở thư lỗi đầu tiên, các mốc sau không được nhắc, và dấu vết… | 11 | đã sửa (Task 11, R2) |
| `e2e/F4` | important | xác nhận | Không có đường nào đưa trợ lý / luật sư phối hợp vào đội ngũ vụ việc: vụ mới chỉ có luật… | 3 | đã sửa (Task 3) |
| `e2e/F5` | important | tranh chấp | Yêu cầu khách gửi không đến được ai ở văn phòng, trong khi cổng báo khách 'Văn phòng đã… | — | chuyển sang M6 Task 4 (R1) |
| `e2e/F6` | important | xác nhận | Khách không được báo khi giấy tờ bị từ chối hay khi văn phòng công bố văn bản mới: sự… | — | chuyển sang M6 Task 3 (R1) |

### Việc chuyển sang milestone sau

Đã ghi thẳng vào kế hoạch của milestone nhận việc (Task 21, 2026-09-27), trừ các dòng M7/M8 mà
phiên song song đã ghi sẵn ở commit `a12e5d1` và Task 21 chỉ đối chiếu lại.

- **M6 Task 3** (`docs/superpowers/plans/2026-09-21-m6-notifications.md`): `notify/notify-5`,
  `docs/docs-3` (`DocumentPublished` không ai nghe); `notify/notify-6`, `checklist/checklist-01`,
  `e2e/F6` (`ChecklistItemRejected` không ai nghe, và câu toast "Đã gửi yêu cầu nộp lại kèm lý do
  cho khách" nói sai cho tới khi thư có thật). Listener dùng hạ tầng R2, người nhận theo R12.
- **M6 Task 4:** `requests/REQ-1`, `notify/notify-7`, `roles/roles-08`, `spec-gap/spec-gap-08`,
  `e2e/F5` (khách gửi yêu cầu thì văn phòng không ai biết); báo khi khách hỏi tiếp vào luồng cũ
  (`REQ-2`); mẫu `client.request_answered` (`REQ-4`, đã thêm vào SPEC §9); huy hiệu "có trả lời
  mới" trên thẻ hồ sơ ở cổng.
- **M6 Task 3 và 4:** tiêu đề thư về tài liệu không bao giờ nêu tên tài liệu nhóm D, vì dòng nhật ký
  thư hiện cho mọi người xem được vụ (kể cả trợ lý) trên màn hình của Task 13.
- **M6 Task 7:** `CheckStaleMatters` dùng `Matter::open()` và người nhận theo R3.
- **M6 Task 10:** nút gửi lại một thư `failed`.
- **M7 Task 1:** đánh dấu "đã làm ở M6.5 (một vụ)"; còn lại thư tổng hợp mốc hạn cho lead mới mà
  Task 4 hoãn. **M7 R6:** đã làm ở M6.5 (R7). **M7 Task 3:** dựng trên `closed_at` của R8; thêm
  (Task 21) hai việc: chặn thao tác danh mục trên vụ đã đóng, và việc đổi `is_terminal` của một giai
  đoạn không cập nhật các vụ đang đứng ở giai đoạn đó. **M7 Task 7:** `RetractDocument`
  (`docs/docs-6`).
- **M8 Task 8:** cài `pcov` trong container và CI (`spec-gap/spec-gap-10`).

### Việc nhỏ hoãn lại

Mọi dòng "minor (deferred)" của sổ tay, theo task, để rà soát toàn nhánh phân loại (sửa trước khi
merge, chuyển milestone, hay bỏ). Chưa dòng nào được sửa ở bước tài liệu này.

- **Task 1:** `renderInternalNote()` chỉ được chứng minh an toàn bằng một probe không commit, không
  có test hồi quy. `env() ?: null` cũng biến chuỗi `"0"` thành null (không phải proxy hợp lệ, chỉ
  là một cạnh sắc).
- **Task 2:**
  - Admin không lưu được bất kỳ sửa đổi nào trên tài khoản cổng của một khách đã xoá mềm (ô
    `client_id` bị khoá nhưng vẫn validate theo `VisibleClientOptions`) — `ClientUserForm.php:35-41`.
  - `->dehydrated()` trên ô `client_id` bị khoá bỏ mất lớp chặn của chính Filament;
    `setAccessible(true)` vô tác dụng từ PHP 8.1 — `ClientUserForm.php:41`, `ClientUserResourceTest.php:368`.
  - Test bảng chưa bao giờ `assertDontSee(email)`; ca "khách của luật sư khác" dùng một khách không
    có vụ — `ClientUserResourceTest.php:73,101`.
  - `client_id` chỉ bất biến ở hook của trang; `ClientUser::$fillable` vẫn có `client_id`, không có
    chặn ở tầng model (`ClientUser.php:32`).
  - Test chập chờn khi chạy song song: `TransitionStageActionTest` (thiếu `on_hold`), nghi rò trạng
    thái giữa các test.
  - Luật "người này với tới được khách nào" lặp ở bốn nơi (`ClientUserPolicy` view/create,
    `ClientPolicy::view`, `VisibleClientOptions`).
  - Quy trình: bằng chứng đỏ chỉ đến từ mutation probe (code và test viết cùng lúc).
  - Đường nhanh của `MatterPolicy::releasedToPortal` tin quan hệ `client` đã nạp trong bộ nhớ, kể
    cả khi đã cũ hoặc đã xoá mềm (nên thêm `! trashed() && key === client_id`) — `MatterPolicy.php:132`.
  - Docblock probe cũ trong `MatterPolicyTest` ("still refuses on the policy layer").
  - Không có mutation probe dán kèm cho `EnsurePortalAccountIsActive.php:101` (`&& $user->client !== null`).
  - Câu thông báo khi từ chối thao tác hàng loạt chưa được assert; nhánh từng dòng của
    restore/forceDelete trong `ClientsTable` chưa có test.
  - `invalidate()` trong `EnsurePortalAccountIsActive` cũng huỷ luôn phiên đăng nhập nhân sự dùng
    chung cookie (có từ trước).
- **Task 3:**
  - Test chập chờn: mã loại vụ `DS`/`DD` viết cứng ở bốn tệp test (`DuplicateMatterType`).
  - Các test Livewire "chặn lời gọi ép" chỉ chứng minh Filament từ chối action đang ẩn
    (`TeamRelationManagerTest` ~207, ~231).
  - Nhãn vai `observer` "Theo dõi" dễ hiểu nhầm: một trợ lý làm observer vẫn có đủ `matter.update`;
    cần chữ gợi ý.
  - Action/Support import lớp Filament chỉ để dùng trong `@see` (nên dùng tên đầy đủ).
  - Quy trình: test viết cùng code, không đỏ trước (mutation probe bù lại).
  - Test thêm trùng chỉ đi qua bước kiểm tra trước, chưa bao giờ chạm unique index; không có đường
    lùi `UniqueConstraintViolation` (lập luận khoá trên MariaDB đúng nhưng chưa có test).
  - Kiểm quyền trên dòng vụ chưa khoá; vụ bị xoá mềm giữa lúc kiểm và lúc khoá cho ra 404 thay vì
    câu từ chối tiếng Việt (`AddTeamMember` :72/106, `RemoveTeamMember` :76/79).
  - Ô chọn người trên vụ hạn chế chạy một truy vấn quyền xem cho mỗi ứng viên.
- **Task 5:** đổi `is_terminal` trên cấu hình giai đoạn không cập nhật các vụ đang đứng ở giai đoạn
  đó (đã ghi vào kế hoạch M7 Task 3).
- **Task 6:** một khách mà mọi vụ đều `restricted` **và** đã xoá lại tra ra được (theo chữ
  "chưa xoá" của phán quyết R4(a)) — chủ văn phòng cần quyết.
- **Task 7:**
  - `EditClientUser` so email đổi có phân biệt hoa thường, nên sửa chữ hoa cũng đặt lại kích hoạt
    (an toàn thừa) — `EditClientUser.php:118`.
  - Chưa có probe riêng cho điều kiện NAT `every($isThisAccount)`; chưa có test IP dùng chung ở bước
    mã.
  - Chạy lại backfill sau khi seed làm mất kích hoạt của tài khoản demo (seeder đặt `activated_at`
    mà không đặt `last_login_at`).
- **Task 8:** phần dữ liệu test đã commit trên MariaDB được người rà soát xác nhận vô hại
  (`RefreshDatabase` migrate lại sau commit); nhưng chạy MariaDB `--parallel` sẽ thiếu dữ liệu cha.
- **Task 9:** đường hai tab thật trên giao diện được xử lý nhờ Filament lặng lẽ gỡ modal, không phải
  nhờ thông báo mới (thông báo chỉ là lớp phòng thủ thêm).
- **Task 11:** chưa tìm ra gốc của test chập chờn `--stop-when-empty` với job anh em (chỉ trong
  test; đã né bằng `--once`).
- **Task 15:**
  - Quy trình: không đỏ trước (có bằng chứng đỏ bằng stash bù lại).
  - Kiểm trùng tên đầu mục của `AddChecklistItem` phụ thuộc collation CSDL (không phân biệt hoa
    thường), còn `ApplyChecklistTemplate` dùng `in_array` (phân biệt); docblock lại nói "cùng luật".
  - Repeater đặt `sort_order` mặc định 1 cho mọi dòng, nên thứ tự bị hoà.
  - Chưa có test khẳng định sửa một mẫu đã áp không đụng vào các vụ đang mở.
- **Task 16:**
  - Chặn form công bố cũ không thấy được chuỗi ABA (đã cho khách xem → bỏ → cho lại, giữa lúc mở
    form và lúc xác nhận).
  - Chưa có test khoá thật dưới tải đồng thời cho `Submit`/`MarkSignedFiled`/`ReturnToDraft`.
- **Task 17:**
  - Khách vẫn tải được tệp của version đã bị từ chối của chính mình qua URL trực tiếp (có từ trước).
  - Chú thích ở `SubmitClientDocument.php:320` thiếu `->all()`.
- **Task 19:** `stageIncludingTrashed()` chọn dòng xoá gần nhất khi có nhiều giai đoạn đã xoá cùng
  khoá (biên chưa có test).
- **Task 20:** `SensitivePropertyFilter` chỉ che giá trị dạng chuỗi dưới khoá phone/email/address;
  giá trị khác kiểu sẽ lọt nguyên (hôm nay không có đường nào tạo ra).

### Giai đoạn 2 — nâng cấp sau bản đầu tiên (chủ văn phòng yêu cầu nhắc lại khi M12 xong)

Chủ văn phòng yêu cầu (qua phiên song song, ghi trong sổ tay điều phối M6.5) giữ bảy hạng mục này
ngoài bản đầu tiên, và **nhắc lại đúng danh sách này khi M12 xong**. Không tự bắt đầu mục nào trước
đó.

1. Chấm công theo giờ và tính phí (M9 chỉ dựng khung `time_entries`).
2. Hoá đơn điện tử Việt Nam.
3. Đồng bộ lịch Google/Outlook.
4. Ký điện tử.
5. Soạn văn bản tự động từ mẫu.
6. Tích hợp email và Zalo, thư tự lưu vào hồ sơ.
7. Báo cáo quản trị nâng cao.

### Nghiệm thu M6.5 và việc mang sang (2026-09-28)

- Rà soát toàn nhánh chia ba vùng (phân quyền, thư/lịch chạy, tài liệu/cấu hình). Một Critical: trang Nhật ký hệ thống cho trưởng phòng đọc nội dung vụ restricted — đã sửa (lọc theo vụ xem được, modal hỏi lại quyền). Sau đó hai đợt sửa, mỗi đợt có rà soát lại độc lập.
- Kiểu lỗi mới được ghi thành luật dự án: **trong một transaction, câu đầu tiên phải là một lần đọc có khoá**. Một lần đọc thường trước khoá cố định ảnh chụp REPEATABLE READ của InnoDB, nên mọi lần đọc sau thấy dữ liệu cũ (đã gây lỗi ghi thu vượt ở M9 và lỗi đồng bộ định danh ở M6.5). Lỗi 1020/1213 phải thành câu tiếng Việt "thử lại", không bao giờ là trang 500.
- Mang sang M8 Task 6 (quét §10): thông báo "thử lại" của đồng bộ định danh khách không hiện ra và nói sai "chưa được lưu"; ba đường còn có thể ra 500 khi đụng 1020 (UploadStaffDocument, SubmitClientDocument, pha lưu của OpenMatter); dòng nhật ký kiểm tra xung đột cho trưởng phòng đọc mã và tên các bên của vụ restricted; cuộc đua hẹp giữa failed() của job nhắc hạn và CheckDeadlines; khách mới tạo trong lúc hộp cảnh báo xung đột đang mở không được dò trùng lần nữa.
