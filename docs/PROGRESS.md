# Tiến độ VK-CRM

| Milestone | Trạng thái | Ngày | Ghi chú |
|---|---|---|---|
| M0 Khởi tạo | ✅ Xong | 2026-09-13 | Laravel 13.17, Filament 5, hai panel, hai guard, Pest 4, Pint, `.env.example`, seed demo. 23 test xanh. Đăng nhập thật cả hai panel đã kiểm tra trên trình duyệt |
| M1 Migration / model / enum / factory / seeder | ✅ Xong | 2026-09-14 | 19 bảng SPEC §4 + `code_sequences` + `client_password_reset_tokens`; 13 enum; seeder đủ SPEC §12; 77 test xanh |
| M2 Phân quyền (spatie, Policy, global scope client) | ✅ Xong | 2026-09-14 | 130 test xanh |
| M3 Panel admin + `TransitionMatterStage` + `RunConflictCheck` | ✅ Xong | 2026-09-16 | 280 test xanh |
| M4 Danh mục hồ sơ + tài liệu + `PublishDocument` | ✅ Xong | 2026-09-20 | Checklist, upload có `FileGuard` + seam quét virus, duyệt/từ chối, `PublishDocument`, lưu trữ đĩa `private`, route tải có chữ ký vẫn kiểm policy, hai tab mới ở trang vụ việc, hai widget SPEC §7.1 còn thiếu. Đã qua cổng hợp nhất (4 Important + 6 Minor, không Critical). 823 test xanh |
| M5 Portal khách (OTP, hồ sơ, nộp tài liệu, yêu cầu) | ✅ Xong | 2026-09-22 | Merge f7f0880. `bin/dev test:mariadb` 1219 xanh / 0 đỏ. Chi tiết ở "Ghi chú M5" |
| M6 Thông báo + tác vụ định kỳ + heartbeat | ✅ Xong | 2026-10-01 | Task 1, 2, 5, 6 và một phần Task 3 có từ trước; phần còn lại (Task 3, 4, 7, 8, 9, 10) gộp từ làn `m6-rest` tại f491a2a. Suite 3438 xanh, CI xanh (SQLite + MariaDB). Thư cho khách (công bố tài liệu, từ chối giấy tờ, kích hoạt cổng, đã trả lời yêu cầu), báo nhân sự (yêu cầu/tệp mới, khách hỏi tiếp), CheckStaleMatters, RemindMissingDocuments, RemindUnseenUpdates, nút "Gửi lại" thư lỗi. Chi tiết và việc hoãn ở "Ghi chú M6" |
| M6.5 Sửa lỗi quy trình | ✅ Xong | 2026-09-28 | Sửa 102 phát hiện của đợt kiểm tra 2026-09-24 (`docs/audits/2026-09-24-quy-trinh.md`: 90 xác nhận, 12 tranh chấp; bảng mã → task ở "Ghi chú M6.5") và CI đỏ từ 2026-09-22. 21 task, mỗi task qua rà soát Opus; rà soát toàn nhánh chia 3 vùng (1 Critical: nhật ký hệ thống lộ vụ restricted cho trưởng phòng) → 2 đợt sửa. Cổng merge: `test:mariadb` 2234/2234 xanh (2 bài đỏ do chạy chồng một CSDL test, chạy lại riêng 26/26 xanh), full suite 2228 xanh, pint sạch. Việc mang sang M8 Task 6: xem cuối "Ghi chú M6.5" |
| M7 Bàn giao + lưu trữ + liên lạc + tìm kiếm | 🟡 Đang làm | | Task 1–5 xong ở làn `m7-handover` (thư tổng hợp, bàn giao hàng loạt, lưu trữ khi kết thúc, gói bàn giao hồ sơ, hết hạn tra cứu của khách). Task 6–7 ở làn `m7-handover`, Task 10, 8, 9 tách sang làn `m7-extras` để M11 có sớm bảng `settings`; Task 11 + rà soát toàn milestone sau khi gộp hai làn |
| M8 Bảo mật + backup + README triển khai | 🟡 Gần xong | 2026-10-01 | M8a (a879d33) và làn `m8b-security` (035c4d3) đã trên `main`, CI xanh: sao lưu mã hoá + diễn tập khôi phục, CSP enforce, ép HTTPS + HSTS, `TRUSTED_PROXIES` chặn go-live + `vkcrm:preflight`, giới hạn IP admin, 2FA bắt buộc cho nhân sự, giới hạn đăng nhập/tải tệp, quét dữ liệu cá nhân, hướng dẫn triển khai + `vkcrm:create-admin`. Còn: Task 6 (rà soát §10 toàn hệ thống) và Task 8 (nghiệm thu) sau khi mọi làn đã gộp |
| M11 Máy chủ MCP (ChatGPT, Claude) | 🟡 Đang làm | | Làn `m11-mcp-server` cắt từ `main` sau M8 (chủ văn phòng yêu cầu làm ngay); nhận bảng `settings` và nhật ký liên lạc từ làn `m7-extras` khi các task đó đạt. Phán quyết của chủ văn phòng ngày 2026-09-24 ở kế hoạch `docs/superpowers/plans/2026-09-24-m11-mcp.md` |
| M9 Hợp đồng dịch vụ + đợt thanh toán | 🟡 Phần lớn trên `main` | 2026-10-01 | Gộp a65ba4c (Task 2–5, 7–9, 12) và 4280a4c (làn `m9-rest`: Task 1 mười hai lĩnh vực hành nghề, Task 11 nhắc đợt quá hạn); suite 3080 xanh. Đã có: bảng + quyền + policy, hợp đồng/phụ lục, khoản thu, tab vụ việc, trang Công nợ, trang doanh thu, khung `time_entries`, 12 loại vụ việc, nhắc nội bộ khi quá hạn. Còn: Task 6 + 10 (chờ M7), Task 13 |
| M10 Tiếp nhận khách | 🟡 Đang làm | | Làn `m10-intake` (D:\vkwt\lane-m10), cắt từ `main` sau khi M9 vào. Kế hoạch `docs/superpowers/plans/2026-09-22-m10-intake.md` |
| M12 Ứng dụng điện thoại (PWA) + thông báo đẩy | 🟡 Đang làm | | Làn `m12-pwa-push` cắt từ `main` (kế hoạch nói M12 không phụ thuộc M9/M10). Phần thử trên máy Android và iPhone thật (Task 1, 10) do chủ văn phòng làm theo danh sách kiểm tra |

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

## Ghi chú M6

Phần còn lại của M6 (Task 3 phần còn lại, Task 4, 7, 8, 9, 10) làm trên làn `m6-rest` (worktree
`D:\vkwt\lane-m6`), cắt từ `origin/m6-5-lane-d` @ `d2de674` — tức SAU toàn bộ M6.5, không phải từ
`main` như dòng "Nhánh" của kế hoạch (`docs/superpowers/plans/2026-09-21-m6-notifications.md`).
Task 1, 2, 5, 6 và thư `client.stage_update` của Task 3 đã có trên nền từ trước. Sổ tay làn (mọi
vòng rà soát, mọi phán quyết): `.superpowers/sdd/m6/progress.md`; báo cáo từng task:
`.superpowers/sdd/m6/task-<n>-report.md`. Làn KHÔNG tự merge: thứ tự đã chốt là M6.5 → M8a → M9 →
`m6-rest` → M8b → M7 → M11 → M10 → M12.

### Trạng thái lúc viết (2026-10-01)

- **Xong:** Task 3, 4, 7, 8, 9 (mỗi task qua rà soát độc lập "giả định có một Critical": Task 3 và
  Task 4 mỗi task hai vòng sửa, Task 7 một vòng; Task 8 và 9 duyệt thẳng, mỗi task 4 minor, chép ở
  "Việc hoãn, mang sang" bên dưới) và Task 10 (nút "Gửi lại" + nghiệm thu này).
- **Rà soát cuối làn** (toàn dải `d2de674..cd275fd`): cần sửa — 0 Critical, 3 Important. I1: câu
  cảm ơn sau khi nộp hứa email, nhưng thư từ chối không đi cho vụ đã đóng còn trên cổng (khách vẫn
  nộp được ở đó) — sửa ở `2b9cead`, xem phán quyết bên dưới. I2: docblock nói sai sự thật — sửa ở
  `08d55ce`. I3: bằng chứng nghiệm thu chưa nguyên văn — sửa ở phần nghiệm thu bên dưới. Minor để
  sau chép ở "Việc hoãn, mang sang".
- **Số đo sau vòng sửa** (commit `08d55ce`): bộ test đầy đủ **2574 xanh / 6 bỏ qua / 0 đỏ** (10637
  khẳng định; SQLite, `--parallel --processes=2`, 911 giây) — lượt chạy đầy đủ đầu tiên SAU ba sửa
  nhỏ cuối của `dff1ccc` nêu ở dòng dưới; `test:mariadb` tuần tự trên 9 tệp test vòng sửa đã chạm —
  **254 xanh / 0 đỏ** (996 khẳng định, 207 giây), kể cả test khoá thật hai phiên CSDL; `pint --test`
  sạch 606 tệp.
- **Số đo cuối Task 10** (commit `dff1ccc`): bộ test đầy đủ **2570 xanh / 6 bỏ qua / 0 đỏ** (10605
  khẳng định; SQLite, `--parallel --processes=2`, 955 giây); `test:mariadb` tuần tự trên 27 tệp —
  mọi tệp test làn đã chạm, cộng `OutboundMessageResourceTest` và `ActivityLogEventTranslationsTest`
  — **660 xanh / 0 đỏ** (2229 khẳng định, 421 giây), kể cả một test khoá THẬT hai phiên CSDL;
  `pint --test` sạch 606 tệp. Ba sửa nhỏ sau lượt chạy đầy đủ (bỏ một hằng không dùng, dời một chú
  thích Blade, siết một khẳng định) đã chạy lại trên các tệp chịu ảnh hưởng: 117 xanh + 1 bỏ qua
  (SQLite), 118 xanh (MariaDB). Làn không thêm migration nào.
- Đường cơ sở trước làn (`d2de674`): 2216 xanh + 5 bỏ qua. Làn thêm 359 test (355 tới Task 10, 4
  ở vòng sửa sau rà soát cuối); test bỏ qua thứ sáu là test khoá thật, chỉ chạy trên MariaDB.

### Làn đã giao gì

- **Task 3 — thư cho khách, kích hoạt bởi hành động.** `client.document_published` (công bố tài
  liệu → sự kiện → listener hàng đợi), `client.document_rejected` (MỖI lần từ chối là một thư: khoá
  chống trùng `rejected@<reviewed_at>`), `client.activation` (`IssuePortalAccess` →
  `SendPortalActivationMail`, mật khẩu tạm sinh LÚC GỬI, không bao giờ nằm trong hàng đợi). Luật
  người nhận R12 tách ra MỘT chỗ: `App\Actions\Notification\ResolveClientRecipients`. Câu báo sau
  khi từ chối nói đúng thư có đi hay không, ba nhánh: có thư / không thư nhưng lý do vẫn chờ trên
  cổng / cổng giấu hẳn, phải gọi khách.
- **Task 4 — thư cho nhân sự, kích hoạt bởi khách.** `staff.new_client_request`,
  `staff.new_client_document` (một thư cho một LÔ tệp của một lần nộp), thông báo khi khách viết
  tiếp vào một yêu cầu (REQ-2), `client.request_answered` + huy hiệu "có trả lời mới" (REQ-4). Vụ
  đã đóng mà còn công bố cổng vẫn báo văn phòng (vòng sửa 2).
- **Task 7 — `stale-matters.check`, 07:30 hằng ngày.** Mốc 14 ngày: thông báo trong hệ thống một
  lần mỗi đợt đình trệ; mốc 21 ngày: thư `staff.stale_matter` tối đa một lần mỗi 7 ngày LỊCH (vòng
  sửa 1: cửa sổ theo ngày, không trôi thành 8 ngày vì `sent_at` đóng dấu sau 07:30). Định nghĩa
  "quá hạn" ở `App\Support\MatterStaleness`, dùng chung với widget và danh sách.
- **Task 8 — `missing-documents.remind`, 08:00 thứ Hai/Tư/Sáu.** Thư `client.missing_documents`
  liệt kê ĐÚNG đầu mục bắt buộc còn thiếu bằng tên người đọc được; thiếu quá 14 ngày thì báo luật
  sư. "Còn thiếu" và đồng hồ "thiếu từ" nằm ở `ChecklistProgress`, widget dùng chung. Chống trùng
  3 ngày lịch cộng lịch T2/T4/T6 ⇒ thực tế khách nhận thứ Hai và thứ Sáu.
- **Task 9 — `unseen-updates.remind`, 08:30 hằng ngày.** Báo luật sư phụ trách gọi khách khi một
  dòng tiến độ đã công bố quá 5 ngày mà khách chưa xem; chỉ trong hệ thống, không thư. Định nghĩa
  "chưa xem" ở `App\Support\UnseenStageLogs`, dùng chung với widget.
- **Task 10 — nút "Gửi lại" ở Nhật ký thư** (sửa đổi Task 21 của kế hoạch; M6.5 Task 13 để lại).
  Trên một dòng `failed`, bảng và trang xem cùng một nút
  (`app/Filament/Admin/Resources/OutboundMessages/Actions/ResendOutboundMessageAction.php`) gọi
  `App\Actions\Notification\ResendOutboundMessage`:
  - **Ai bấm:** ability riêng `OutboundMessagePolicy::resend()` — CHỈ admin, và vẫn qua `view()`
    của đúng dòng đó. Manager và luật sư phụ trách XEM được dòng nhưng không có nút; một lời gọi
    Livewire giả mạo vào nút đã ẩn không làm gì; gọi thẳng Action bị Gate từ chối.
  - **Mẫu nào:** tám mẫu dựng lại được từ `related` (`client.stage_update`,
    `client.document_published`, `client.document_rejected`, `client.request_answered`,
    `client.missing_documents`, `staff.new_client_request`, `staff.new_client_document`,
    `staff.stale_matter`), khai MỘT chỗ ở `App\Actions\Notification\ResendTargets`. Không gửi lại:
    `client.otp` (mã 5 phút đã chết), `staff.deadline_reminder` (đường thử lại riêng của
    `CheckDeadlines`, gửi tay là gửi hai lần — lý do này sai sau khi gộp với I-2 của main; lý do
    đúng ở "Việc sau gộp vào main" bên dưới), `client.activation` (gửi lại = cấp mật khẩu tạm
    mới, dùng nút ở màn hình tài khoản cổng), `undeclared`. Dòng `queued`/`sent` không có nút.
  - **Không luật thứ hai:** mỗi mẫu gọi đúng `eligibleRecipients()`/`alreadyDelivered()`/`handle()`
    của Action/Job gốc (sáu Action `Notify*` và hai job `Send*Mail` được tách hàm, hành vi cũ giữ
    nguyên). Người nhận suy lại lúc gửi (R3 nhân sự qua `ResolveStaffRecipients`, R12 khách), cột
    `recipient` cũ không bao giờ dùng lại; không còn ai đủ điều kiện thì từ chối rõ ràng, không gửi.
  - **Dòng cũ không đổi:** nó là bằng chứng; thư gửi lại là một dòng MỚI do `OutboundLedgerTransport`
    ghi. Hàng đợi sau commit (`ResendOutboundMessageJob`, cùng ngân sách 5 lượt 60/300/900/3600 giây
    của listener gốc); hỏng hẳn thì báo người bấm trong hệ thống (người bấm đã nghỉ thì chuỗi dự
    phòng R3 nhận thay), câu báo không nêu mã hay tên vụ.
  - **Bấm hai lần:** khoá dòng `matters` rồi dòng nhật ký thư, dấu chặn là dòng audit
    `outbound_message_resent` ghi trong cùng transaction — mỗi dòng hỏng gửi lại được MỘT lần. Có
    test khoá THẬT trên MariaDB với hai phiên đồng thời
    (`tests/Feature/Actions/Notification/ResendOutboundMessageLockingTest.php`), đo được: đọc dấu
    TRƯỚC khi giành khoá thì lần bấm thứ hai lọt qua (mutation probe đỏ).
  - **Đúng sự việc:** dòng hỏng của một lần từ chối CŨ không gửi lại được khi đầu mục đã bị từ chối
    LẦN MỚI (so khoá `payload.tier`), cả lúc bấm lẫn lúc job chạy.
  - `.env.example` nối thêm các biến `BRAND_*` mà `config/vkcrm.php` đọc (kể cả
    `BRAND_REPLY_TO_ADDRESS`); bốn thông tin pháp lý của chân thư để dạng chú thích — giá trị thật
    là việc của M8.

### Nghiệm thu thật trên dữ liệu seed (SPEC §13: "`schedule:test` sinh đúng email vào log")

CSDL MariaDB riêng của làn `vk_crm_seed_lane_m6`, `migrate:fresh --seed --force` sạch (seed lúc
00:35 ngày 01/10/2026), `MAIL_MAILER=log`, `QUEUE_CONNECTION=database`, giờ `Asia/Ho_Chi_Minh`.

**Đầu ra nguyên văn.** Đầu ra terminal của lượt nghiệm thu thứ nhất (00:35–00:38 ngày 01/10) KHÔNG
được lưu lại; khối từng dán ở đây là bản rút gọn ghép từ nhiều lần chạy, không phải nguyên văn (rà
soát cuối làn, I3). Khối dưới đây chép NGUYÊN VĂN lượt chạy lại `schedule:list` và `schedule:test`
cho từng tác vụ trên CHÍNH CSDL đó, lúc 10:03–10:05 cùng ngày (vòng sửa sau rà soát cuối làn). Đây
là lượt THỨ HAI trên cùng dữ liệu, nên nó cũng là phép đo chống trùng: trước và sau lượt này
`outbound_messages` vẫn 91 dòng, `notifications` vẫn 27, `jobs` và `failed_jobs` vẫn 0, log vẫn
đúng 85 thư — không thư, không thông báo, không job nào mới. (`queue.drain` là tên hiển thị;
`schedule:test --name` của Laravel 13 so với chuỗi lệnh, nên tác vụ đó gọi bằng chuỗi lệnh.) Mỗi
dòng `$ php artisan …` là một lần `docker run` riêng theo sổ tay làn, với đúng các biến môi trường
ở trên; phần dưới mỗi dòng là stdout của nó, không sửa.

```
$ php artisan schedule:list

  *    *    * * *      system-health.touch ............. Next Due: 29 giây tới
  */5  *    * * *      system-health.heartbeat .......... Next Due: 1 phút tới
  *    *    * * *      php artisan queue:work --stop-when-empty --max-time=50  Next Due: 29 giây tới
  */30 7-19 * * *      deadlines.check ................. Next Due: 26 phút tới
  30   7    * * *      stale-matters.check .............. Next Due: 21 giờ tới
  0    8    * * 1,3,5  missing-documents.remind ......... Next Due: 21 giờ tới
  30   8    * * *      unseen-updates.remind ............ Next Due: 22 giờ tới


$ php artisan schedule:test --name=system-health.touch
  Running [system-health.touch] ................................. 72.13ms DONE


$ php artisan schedule:test --name=system-health.heartbeat
  Running [system-health.heartbeat] ............................. 43.60ms DONE


$ php artisan schedule:test --name=deadlines.check
  Running [deadlines.check] .................................... 317.27ms DONE


$ php artisan schedule:test --name=stale-matters.check
  Running [stale-matters.check] ................................ 595.91ms DONE


$ php artisan schedule:test --name=missing-documents.remind
  Running [missing-documents.remind] ........................... 352.94ms DONE


$ php artisan schedule:test --name=unseen-updates.remind
  Running [unseen-updates.remind] .............................. 613.04ms DONE

$ php artisan schedule:test --name="queue:work --stop-when-empty --max-time=50"
  Running ['artisan' queue:work --stop-when-empty --max-time=50]  10 giây DONE
  ⇂ queue.drain  
```

Kết quả của lượt thứ nhất, đo trên CSDL và trong `storage/logs/laravel.log`:

- `system-health.touch` ghi `system_health.last_schedule_run_at`; `system-health.heartbeat` không
  làm gì vì `HEARTBEAT_URL` trống (đúng thiết kế; URL thật là việc của M8).
- `deadlines.check`: 2 job → 4 thư `staff.deadline_reminder` ("Còn 2 ngày: Hết thời hạn kháng cáo
  (VK-2026-LD-0001)", tới luật sư phụ trách và trợ lý của từng vụ). Chạy lại: không job mới.
- `stale-matters.check`: dữ liệu seed có ba vụ ở mốc 20 ngày → 3 thông báo 14 ngày, không thư. Đẩy
  đồng hồ của `VK-2026-DD-0001` lùi thêm 2 ngày (22 ngày) rồi chạy lại HAI lần: 2 job, nhưng chỉ **2 thư**
  `staff.stale_matter` ("Hồ sơ VK-2026-DD-0001 chưa cập nhật cho khách hàng", tới luật sư phụ
  trách và quản lý) — job thứ hai tự bỏ qua người đã nhận (R4).
- `missing-documents.remind` chạy HAI lần: 10 job, **6 thư** `client.missing_documents`, mỗi
  (người nhận, hồ sơ) đúng một thư — kể cả hồ sơ `VK-2026-HN-0002` có hai tài khoản khách.
- `unseen-updates.remind`: 10 thông báo "khách chưa xem"; chạy lại: không thêm thông báo nào.
- Rút hàng đợi cũng gửi 8 job listener mà bước seed để lại: 14 thư `staff.new_client_document`, 1
  thư `client.document_rejected`. Tổng 27 thư ở bước này (28 kể cả thư gửi lại bên dưới),
  `failed_jobs` = 0, không thư trùng (ở mọi mẫu, số dòng `sent` bằng số cặp người nhận × bản ghi
  khác nhau), không còn header `X-VKCRM-*` nào trong thư đi ra, mọi thư có
  `Reply-To: lienhe@luatvukhang.com`.

Nhật ký thư và thông báo trên CSDL nghiệm thu, truy vấn nguyên văn (sau lượt chạy lại). 62 dòng
`client.stage_update` `sent` lúc 00:35:22 là lịch sử mẫu do `MatterSeeder` ghi thẳng (không phải thư
đi thật); dòng thứ 63 là thư gửi lại #91 bên dưới. 14 thông báo `NewClientDocumentAlert` đi cùng 14
thư `staff.new_client_document`.

```
+---------------------------+--------+---------------------+---------------------+----+
| template                  | status | first_at            | last_at             | n  |
+---------------------------+--------+---------------------+---------------------+----+
| client.stage_update       | sent   | 2026-10-01 00:35:22 | 2026-10-01 00:38:11 | 63 |
| staff.new_client_document | sent   | 2026-10-01 00:37:20 | 2026-10-01 00:37:21 | 14 |
| client.document_rejected  | sent   | 2026-10-01 00:37:21 | 2026-10-01 00:37:21 |  1 |
| client.missing_documents  | sent   | 2026-10-01 00:37:21 | 2026-10-01 00:37:21 |  6 |
| staff.deadline_reminder   | sent   | 2026-10-01 00:37:21 | 2026-10-01 00:37:21 |  4 |
| staff.stale_matter        | sent   | 2026-10-01 00:37:21 | 2026-10-01 00:37:21 |  2 |
| client.stage_update       | failed | 2026-10-01 00:37:43 | 2026-10-01 00:37:43 |  1 |
+---------------------------+--------+---------------------+---------------------+----+
+----+---------------------+--------+--------------------+----------------------------------------------------+---------------------+
| id | template            | status | recipient          | error                                              | created_at          |
+----+---------------------+--------+--------------------+----------------------------------------------------+---------------------+
| 90 | client.stage_update | failed | khach4@example.com | Symfony\Component\Mailer\Exception\TransportExcept | 2026-10-01 00:37:43 |
| 91 | client.stage_update | sent   | khach4@example.com | NULL                                               | 2026-10-01 00:38:11 |
+----+---------------------+--------+--------------------+----------------------------------------------------+---------------------+
+-----+-------------------------+--------------+------------+-----------+----------------------------------------------------------------------------+
| id  | event                   | subject_type | subject_id | causer_id | properties                                                                 |
+-----+-------------------------+--------------+------------+-----------+----------------------------------------------------------------------------+
| 215 | outbound_message_resent | matter       |          4 |         1 | {"outbound_message_id":90,"template":"client.stage_update","recipients":1} |
+-----+-------------------------+--------------+------------+-----------+----------------------------------------------------------------------------+
+---------------+------+-------------+
| notifications | jobs | failed_jobs |
+---------------+------+-------------+
|            27 |    0 |           0 |
+---------------+------+-------------+
+------------------------------------------------+----+
| type                                           | n  |
+------------------------------------------------+----+
| App\Notifications\Staff\NewClientDocumentAlert | 14 |
| App\Notifications\Staff\StaleMatterAlert       |  3 |
| App\Notifications\Staff\UnseenUpdatesAlert     | 10 |
+------------------------------------------------+----+
```

Một thư mỗi mẫu, chép nguyên văn từ `storage/logs/laravel.log` của lượt thứ nhất: dòng log, toàn bộ
header, và phần `text/plain`. Phần `text/html` (cùng nội dung trong layout thương hiệu) lược bỏ
cho gọn — nó nằm ngay sau trong log. Tiêu đề mã hoá MIME (RFC 2047) đúng như trong log; bản giải mã
ghi ở dòng trên mỗi khối.

`staff.deadline_reminder` — tiêu đề giải mã: "Còn 2 ngày: Hết thời hạn kháng cáo (VK-2026-LD-0001)"

```
[2026-10-01 00:37:21] local.DEBUG: From: VK-CRM <no-reply@luatvukhang.com>
To: luatsu1@luatvukhang.com
Reply-To: lienhe@luatvukhang.com
Subject: =?utf-8?Q?C=C3=B2n?= 2
 =?utf-8?Q?ng=C3=A0y=3A_H=E1=BA=BFt_th=E1=BB=9Di_h=E1=BA=A1n_k?=
 =?utf-8?Q?h=C3=A1ng_c=C3=A1o?= (VK-2026-LD-0001)
MIME-Version: 1.0
Date: Thu, 01 Oct 2026 00:37:21 +0700
Message-ID: <eed65bfec62ec606d6cb5450b8519492@luatvukhang.com>
Content-Type: multipart/alternative; boundary=z70rvUgB

--z70rvUgB
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Kính gửi Vũ Đức Khang,

Còn 2 ngày nữa là tới hạn.

Hết thời hạn kháng cáo
Hạn: 2026-10-03

Hồ sơ: VK-2026-LD-0001 — Tranh chấp chấm dứt hợp đồng lao động với Đinh Quốc Nam

Anh/chị mở hồ sơ trên hệ thống để xem chi tiết và đánh dấu đã xong khi hoàn tất.

Công ty Luật TNHH Vũ Khang Solutions & Partners
--
Công ty Luật TNHH Vũ Khang Solutions & Partners
Điện thoại: 0832270898
Website: https://luatvukhang.com
Thư này do hệ thống hồ sơ của văn phòng gửi tự động. Có điều gì chưa rõ, xin anh/chị gọi giúp văn phòng theo số điện thoại ở trên.
```

`staff.stale_matter` — tiêu đề giải mã: "Hồ sơ VK-2026-DD-0001 chưa cập nhật cho khách hàng"

```
[2026-10-01 00:37:21] local.DEBUG: From: VK-CRM <no-reply@luatvukhang.com>
To: luatsu1@luatvukhang.com
Reply-To: lienhe@luatvukhang.com
Subject: =?utf-8?Q?H=E1=BB=93_s=C6=A1?= VK-2026-DD-0001
 =?utf-8?Q?ch=C6=B0a_c=E1=BA=ADp_nh=E1=BA=ADt?= cho
 =?utf-8?Q?kh=C3=A1ch_h=C3=A0ng?=
MIME-Version: 1.0
Date: Thu, 01 Oct 2026 00:37:21 +0700
Message-ID: <37cf80ad56b6554b24ad53754b3faaa9@luatvukhang.com>
Content-Type: multipart/alternative; boundary=Xw8ZYNwO

--Xw8ZYNwO
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Kính gửi Vũ Đức Khang,

Hồ sơ VK-2026-DD-0001 — Tranh chấp ranh giới thửa đất tại Lý Văn Lâm đã 22 ngày chưa có cập nhật mới cho khách hàng.

Anh/chị mở hồ sơ trên hệ thống để cập nhật tiến độ hoặc liên hệ khách hàng.

Công ty Luật TNHH Vũ Khang Solutions & Partners
--
Công ty Luật TNHH Vũ Khang Solutions & Partners
Điện thoại: 0832270898
Website: https://luatvukhang.com
Thư này do hệ thống hồ sơ của văn phòng gửi tự động. Có điều gì chưa rõ, xin anh/chị gọi giúp văn phòng theo số điện thoại ở trên.
```

`client.missing_documents` — tiêu đề giải mã: "Hồ sơ VK-2026-HN-0002 còn thiếu giấy tờ cần anh/chị gửi" (tài khoản thứ hai của cùng khách hàng)

```
[2026-10-01 00:37:21] local.DEBUG: From: VK-CRM <no-reply@luatvukhang.com>
To: khach8b@example.com
Reply-To: lienhe@luatvukhang.com
Subject: =?utf-8?Q?H=E1=BB=93_s=C6=A1?= VK-2026-HN-0002
 =?utf-8?Q?c=C3=B2n_thi=E1=BA=BFu_gi=E1=BA=A5y_t?=
 =?utf-8?Q?=E1=BB=9D_c=E1=BA=A7n_anh/ch=E1=BB=8B_g=E1=BB=ADi?=
MIME-Version: 1.0
Date: Thu, 01 Oct 2026 00:37:21 +0700
Message-ID: <6d059267b06d879e109277fb144d13ab@luatvukhang.com>
Content-Type: multipart/alternative; boundary=NvDtFRid

--NvDtFRid
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Kính gửi anh/chị Người thân của Công ty Cổ phần Thương mại Sao Việt,

Để văn phòng tiếp tục xử lý hồ sơ VK-2026-HN-0002, anh/chị vui lòng gửi giúp những giấy tờ sau:

- Giấy chứng nhận kết hôn
- Hợp đồng dịch vụ pháp lý và giấy uỷ quyền

Mở hồ sơ để gửi giấy tờ http://localhost/portal

Nếu anh/chị đã gửi rồi hoặc có điều gì chưa rõ, xin gọi giúp văn phòng theo số 0832270898.

Trân trọng, Công ty Luật TNHH Vũ Khang Solutions & Partners
--
Công ty Luật TNHH Vũ Khang Solutions & Partners
Điện thoại: 0832270898
Website: https://luatvukhang.com
Thư này do hệ thống hồ sơ của văn phòng gửi tự động. Có điều gì chưa rõ, xin anh/chị gọi giúp văn phòng theo số điện thoại ở trên.
```

`staff.new_client_document` — tiêu đề giải mã: "Hồ sơ VK-2026-DD-0001 có giấy tờ mới cần kiểm tra"

```
[2026-10-01 00:37:21] local.DEBUG: From: VK-CRM <no-reply@luatvukhang.com>
To: luatsu1@luatvukhang.com
Reply-To: lienhe@luatvukhang.com
Subject: =?utf-8?Q?H=E1=BB=93_s=C6=A1?= VK-2026-DD-0001
 =?utf-8?Q?c=C3=B3_gi=E1=BA=A5y_t=E1=BB=9D_m=E1=BB=9Bi_c?=
 =?utf-8?Q?=E1=BA=A7n_ki=E1=BB=83m?= tra
MIME-Version: 1.0
Date: Thu, 01 Oct 2026 00:37:20 +0700
Message-ID: <fb69a894e7e77a8f8e3d20983f431152@luatvukhang.com>
Content-Type: multipart/alternative; boundary=hmkj8at0

--hmkj8at0
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Kính gửi Vũ Đức Khang,

Khách hàng vừa nộp 1 tệp cho đầu mục "Giấy tờ tuỳ thân của người khởi kiện, bản sao chứng thực" của hồ sơ VK-2026-DD-0001 (Tranh chấp ranh giới thửa đất tại Lý Văn Lâm).

Anh/chị mở danh mục hồ sơ trên hệ thống để kiểm tra và duyệt.

Công ty Luật TNHH Vũ Khang Solutions & Partners
--
Công ty Luật TNHH Vũ Khang Solutions & Partners
Điện thoại: 0832270898
Website: https://luatvukhang.com
Thư này do hệ thống hồ sơ của văn phòng gửi tự động. Có điều gì chưa rõ, xin anh/chị gọi giúp văn phòng theo số điện thoại ở trên.
```

`client.document_rejected` — tiêu đề giải mã: "Hồ sơ VK-2026-DD-0001 cần bổ sung giấy tờ"

```
[2026-10-01 00:37:21] local.DEBUG: From: VK-CRM <no-reply@luatvukhang.com>
To: khach1@example.com
Reply-To: lienhe@luatvukhang.com
Subject: =?utf-8?Q?H=E1=BB=93_s=C6=A1?= VK-2026-DD-0001
 =?utf-8?Q?c=E1=BA=A7n_b=E1=BB=95?= sung =?utf-8?Q?gi=E1=BA=A5y_t=E1=BB=9D?=
MIME-Version: 1.0
Date: Thu, 01 Oct 2026 00:37:21 +0700
Message-ID: <c8c65b9efaa302a3617199bbdf2d921d@luatvukhang.com>
Content-Type: multipart/alternative; boundary=_xC8Rfcx

--_xC8Rfcx
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Kính gửi anh/chị Nguyễn Văn An,

Văn phòng chưa thể nhận giấy tờ "Giấy chứng nhận quyền sử dụng đất hoặc giấy tờ về quyền sử dụng đất" của hồ sơ VK-2026-DD-0001. Lý do:

Ảnh chụp bị mờ ở phần số thửa và số tờ bản đồ nên không đọc được. Anh/chị chụp lại ngoài trời, để phẳng cả trang và tránh bóng đèn hắt vào giúp chúng tôi.

Mở hồ sơ để nộp lại http://localhost/portal

Có điều gì chưa rõ, anh/chị gọi giúp văn phòng theo số 0832270898.

Trân trọng, Công ty Luật TNHH Vũ Khang Solutions & Partners
--
Công ty Luật TNHH Vũ Khang Solutions & Partners
Điện thoại: 0832270898
Website: https://luatvukhang.com
Thư này do hệ thống hồ sơ của văn phòng gửi tự động. Có điều gì chưa rõ, xin anh/chị gọi giúp văn phòng theo số điện thoại ở trên.
```

`client.stage_update` — tiêu đề giải mã: "Hồ sơ VK-2026-LD-0001 có cập nhật mới" — thư GỬI LẠI bằng nút "Gửi lại" (dòng #91)

```
[2026-10-01 00:38:12] local.DEBUG: From: VK-CRM <no-reply@luatvukhang.com>
To: khach4@example.com
Reply-To: lienhe@luatvukhang.com
Subject: =?utf-8?Q?H=E1=BB=93_s=C6=A1?= VK-2026-LD-0001
 =?utf-8?Q?c=C3=B3_c=E1=BA=ADp_nh=E1=BA=ADt_m=E1=BB=9Bi?=
MIME-Version: 1.0
Date: Thu, 01 Oct 2026 00:38:11 +0700
Message-ID: <d5e9018b17d5df6b5c1d695bb6f9a71d@luatvukhang.com>
Content-Type: multipart/alternative; boundary=jbITFwns

--jbITFwns
Content-Type: text/plain; charset=utf-8
Content-Transfer-Encoding: quoted-printable

Kính gửi anh/chị Phạm Đại Phát,

Văn phòng vừa cập nhật tiến độ hồ sơ VK-2026-LD-0001 của anh/chị.

Văn phòng đã nộp bổ sung hồ sơ theo yêu cầu của toà án.

Mở hồ sơ để xem đầy đủ http://localhost/portal

Có điều gì chưa rõ, anh/chị gọi giúp văn phòng theo số 0832270898.

Trân trọng, Công ty Luật TNHH Vũ Khang Solutions & Partners
--
Công ty Luật TNHH Vũ Khang Solutions & Partners
Điện thoại: 0832270898
Website: https://luatvukhang.com
Thư này do hệ thống hồ sơ của văn phòng gửi tự động. Có điều gì chưa rõ, xin anh/chị gọi giúp văn phòng theo số điện thoại ở trên.
```

(Lượt nghiệm thu thử trước đó thấy liên kết dính ngay dưới đầu mục cuối và một dấu cách thừa cuối
mỗi dòng của `client.missing_documents`: `@endif` nuốt dòng mới. Đã sửa
`missing-documents-text.blade.php`, có test ghim; thư trên là hình dạng sau khi sửa.)

Nút "Gửi lại" trên cùng CSDL: một cập nhật tiến độ THẬT (`TransitionMatterStage`, hồ sơ
`VK-2026-LD-0001`), worker chạy listener với SMTP chết (`127.0.0.1:1`) → dòng #90 `failed`
("Connection could not be established…"); giả lập listener đã hết lượt thử (bỏ job còn lại khỏi
hàng đợi); admin bấm hai lần liền đúng Action của nút:

```
canResend: true — Gate resend (admin): true — Gate resend (quản lý): false
Lần bấm 1: xếp hàng cho 1 người nhận
Lần bấm 2: Thư này đã được yêu cầu gửi lại lúc 01/10/2026 00:37. Hãy xem dòng mới nhất …
audit #215 causer=user#1 subject=matter#4 properties={"outbound_message_id":90,"template":"client.stage_update","recipients":1}
```

(Đầu ra của script nghiệm thu, chép lúc chạy; câu từ chối ở lần bấm 2 bị cắt bằng `…` khi chép — nguyên
câu là khoá `outbound.resend.refused.already_requested`. Bằng chứng nguyên văn trên CSDL là truy vấn
dòng #90, #91 và audit #215 ở khối nhật ký thư phía trên.)

Rút hàng đợi (`queue.drain`, log): dòng #91 `sent` tới đúng `khach4@example.com` (thư cuối trong các
thư mẫu ở trên), dòng #90 giữ nguyên `failed` với lý do cũ, `stage_logs.notified_at` được ghi, không
job nào hỏng.

**Môi trường nghiệm thu, không phải lỗi mã:** thư trong log mang `From: VK-CRM` vì `.env` của làn
chép từ trước M6.5 Task 12 (`MAIL_FROM_NAME="${APP_NAME}"`); `.env.example` đã là "Luật Vũ Khang".
Liên kết là `http://localhost/portal` vì `PORTAL_DOMAIN` trống. Cả hai là cấu hình của M8.

### Phán quyết của làn (bản đầy đủ ở sổ tay)

- Luật làn ghi đè kế hoạch: nhánh `m6-rest`, công cụ `/d/vkwt/wt-dev` thay `bin/dev`.
- Thư cho khách chỉ tới tài khoản `is_active` VÀ `activated_at` không null (R12), một định nghĩa
  duy nhất; `client.activation` là ngoại lệ có chủ ý (gửi tới chính tài khoản chưa kích hoạt).
- Tiêu đề thư không bao giờ nêu tên tài liệu (chỉ mã hồ sơ + cụm chung): dòng nhật ký thư mà trợ lý
  đọc được không được lộ tài liệu nội bộ nhóm D.
- Hai lần từ chối khác nhau của cùng một đầu mục là hai thư.
- Vụ ĐÃ ĐÓNG mà văn phòng còn để trên cổng vẫn là vụ "sống" với mọi thư trả lời việc khách làm qua
  cổng: khách vẫn nộp giấy tờ và gửi yêu cầu được ở đó, nên `client.document_rejected`,
  `client.request_answered`, `staff.new_client_document`, `staff.new_client_request` đều đi; chỉ vụ
  đã huỷ (xoá mềm) hay tắt công bố cổng thì không (rà soát cuối làn, I1: câu cảm ơn sau khi nộp hứa
  email vô điều kiện, nên thư từ chối không được dừng ở vụ đã đóng). `client.document_published`
  (brief Task 3) và `client.missing_documents` (kế hoạch Task 8: "chỉ matter đang mở") vẫn chỉ cho
  vụ đang mở — không câu chữ nào hứa hai thư đó.
- Mốc 14 ngày "một lần mỗi đợt", mốc 21 ngày "tối đa 7 ngày lịch một lần" (R5); trí nhớ chống trùng
  là nhật ký thư (R3) và bảng `notifications`, không thêm cột.
- Nút "Gửi lại": chỉ admin; một dòng hỏng gửi lại một lần; người nhận suy lại; tám mẫu gửi lại được,
  bốn mẫu không (lý do ở trên); job gửi lại có cùng ngân sách thử lại với listener gốc (R2). Sau gộp
  vào main: sáu mục không gửi lại (thêm hai họ thư của main) — xem "Việc sau gộp vào main" bên dưới.

### Việc hoãn, mang sang

- **Tuỳ chọn chưa mở phạm vi:** M6.5 Task 14 minor M5 — xoá hoặc đổi ngày một mốc `critical`
  không báo ai; rà soát cuối M6.5 B-M5 — `OutboundMessagesTable` còn N+1 ở `relatedLabel`/
  `relatedUrl` (nút mới chỉ thêm truy vấn cho dòng `failed` gửi lại được, và chỉ với admin); B-M4 —
  dispatch trong transaction.
- **Task 7:** hồ sơ được tính là "đã nhắc" 7 ngày theo VỤ, không theo người: luật sư đã nhận mà
  quản lý hỏng hẳn thì quản lý chỉ nhận thông báo hỏng, không được gửi lại tự động (nay admin có
  thể bấm "Gửi lại" trên dòng hỏng đó). Xoá chuông thông báo thì hôm sau có lại (trí nhớ là bảng
  `notifications`).
- **Task 8** (4 minor của vòng duyệt): lịch thứ Hai/Tư/Sáu cộng cửa sổ chống trùng 3 ngày lịch ⇒
  hồ sơ thiếu kéo dài nhận thư thứ Hai và thứ Sáu, thứ Tư chỉ cho đợt thiếu mới hoặc lượt hỏng (chủ
  văn phòng quyết); chống trùng theo NGƯỜI NHẬN trong khi kế hoạch viết "cho cùng một matter" (chặt
  hơn ở chỗ cần); thư không có liên kết sâu tới đúng hồ sơ; chưa có test "một hồ sơ hỏng không chặn
  cả vòng lặp" (SQLite không dựng được lỗi đó).
- **Task 9** (4 minor của vòng duyệt): xoá chuông thì 08:30 hôm sau báo lại (trí nhớ là bảng
  `notifications`); test múi giờ không dựng lại được lịch dưới múi giờ khác. Chấp nhận, không làm:
  `distinct` không có mutation probe nào đo được; một lượt probe từng chạy song song và tạm gỡ
  `whereDoesntHave('views')` (đã khôi phục, đã kiểm lại).
- **Rà soát cuối làn — minor để sau** (không chặn merge): thư kích hoạt hiện mật khẩu tạm không có
  nhãn và không nói mật khẩu cũ hết hiệu lực khi cấp lại; đổi email tài khoản cổng thay luôn mật
  khẩu và gửi mật khẩu tạm tới địa chỉ mới mà không hỏi xác nhận (rủi ro thiết kế, chủ văn phòng
  quyết); câu từ chối gửi lại `client.activation` gọi tên nút là "cấp lại quyền truy cập" trong khi
  nút ghi "Cấp lại mật khẩu"; `SendPortalActivationMail::failed()` chưa `e()` email trong thông báo;
  luật "trợ lý trong đội ngũ" chép ba nơi (`NotifyStaffOfNewClientRequest`,
  `NotifyStaffOfNewClientDocument`, `CheckDeadlines`); hai lần từ chối trước khi worker chạy cho MỘT
  thư (lý do mới nhất), docblock `stillRejected()` nói khác; test REQ-2 chưa chạy trong ngữ cảnh
  cổng; toast trả lời yêu cầu không nói khách có nhận thư `client.request_answered` không; một đầu
  mục vừa bị từ chối có thể tới khách hai lần cùng sáng (thư từ chối rồi thư còn thiếu lúc 08:00).
  Nút "Gửi lại": mỗi dòng hỏng anh em của cùng một thư đều có nút (dấu chặn theo dòng — không thư
  thứ hai dưới một worker, nhưng hai job và hai dòng audit; xem lại trước khi M8 thêm worker); câu
  `no_eligible_recipient` đổ cho cổng/tài khoản cả khi lý do thật là "nội dung đã đổi"; câu thành
  công/từ chối giả định có dòng nhật ký mới cả khi job thoát lặng lẽ; nút vẫn hiện trên dòng đã gửi
  lại và trong lúc listener gốc còn lượt thử.
- **Task 10:** gửi lại một cập nhật tiến độ CŨ vẫn đi nguyên văn cập nhật đó dù khách đã nhận các
  cập nhật mới hơn — người bấm quyết định, modal nói rõ người nhận được tính lại; gửi lại
  `staff.new_client_request` cho một yêu cầu đã trả lời vẫn đi (luồng gốc không hỏi trạng thái
  yêu cầu lúc gửi). Số tệp trong thư `staff.new_client_document` gửi lại là bản dựng lại từ tệp đại
  diện (cùng người nộp, đầu mục, version, trong 60 giây), vì sự kiện gốc không được lưu.
- M7 thêm `client_access_until` vào ranh giới cổng thì phải thêm vào cổng lúc-gửi của các thư cho
  khách (ghi chú của vòng rà soát Task 3).

### Việc sau gộp vào main (làn `fu`, nhánh `m6-merge-followups`, 2026-10-01)

Bảy việc rẻ từ lượt rà soát gộp `m6-rest` → `main` (`f491a2a`); quyết định của điều phối ghi ở brief
làn (`.superpowers/sdd/fu/task-1-brief.md`), báo cáo ở `.superpowers/sdd/fu/task-1-report.md`.

- **`staff.deadline_reminder` vẫn không có nút "Gửi lại" — thêm vào là quyết định của chủ văn
  phòng, chưa làm.** Lý do cũ ("`CheckDeadlines` tự thử lại ở lần kiểm tra kế tiếp, gửi tay là gửi
  hai lần") sai từ final review wave 2, I-2 (`e0d7f39`): bậc nhắc hỏng hẳn không được xếp lại trong
  ngày, chỉ lượt `deadlines.check` 07:00 hôm sau nhắc lại mốc đó; chuông báo lỗi đã tới người phụ
  trách mốc, luật sư phụ trách và cấp trên. Làn giữ loại trừ và viết lại lý do cho đúng (câu từ chối
  ở `lang/vi/outbound.php`, docblock `ResendTargets`, `ResendOutboundMessageAction`,
  `OutboundMessageNotResendable`; câu từ chối ghim ở `CopyPromisesTest`). Nếu chủ văn phòng muốn gửi
  tay cùng ngày: an toàn về chống trùng nhờ khoá `tier@due_date` theo từng người nhận của
  `SendDeadlineReminderMail` (chỉ dòng `sent`).
- **Hai họ thư của main khai tường minh là KHÔNG gửi lại:** `staff.instalment_overdue` (lượt
  `instalments.remind` 08:00 kế tiếp tự nhắc lại, vì chỉ dòng `sent` chặn) và `staff.backup_alert.*`
  (thư nói về một lượt sao lưu đã qua; sự cố còn thì lượt 02:00/08:00 kế tiếp tự báo lại). Danh sách
  loại trừ giờ là hằng `ResendTargets::NOT_RESENDABLE` (họ mẫu có hậu tố động ghi `xxx.*`), mỗi mục
  một câu từ chối riêng; `MailTemplateRegistryTest` đòi mọi mẫu của `app/Mail` có nhãn ở
  `outbound.templates` và nằm ở ĐÚNG một trong hai danh sách (gửi lại được / không), để mẫu của
  milestone sau không rơi vào nhánh `default` mà không ai biết. Nút "Gửi lại": tám mẫu gửi lại được,
  sáu mục không.
- **Nhãn mẫu thư:** `OutboundMessagesTable::templateLabel()` dùng `Lang::has("outbound.templates.$template")`
  — tên mẫu có dấu chấm nên luôn trượt, cột/trang xem/ô lọc hiện khoá thô cho MỌI mẫu. Nay tra theo
  mảng; thêm nhãn cho hai họ thư của main.
- **Tài khoản cổng (N1, N2 của rà soát cuối làn m6):** trước khi tạo tài khoản, và trước mọi lần lưu
  ở trang sửa SẼ gửi mật khẩu tạm (đổi email, bật lại tài khoản chưa từng kích hoạt), trang hỏi xác
  nhận nêu rõ địa chỉ đích (và "mật khẩu cũ sẽ không dùng được nữa" ở trang sửa); huỷ thì không gì
  đổi. Nút lưu và phím Enter đều đi qua hộp đó. Sau khi lưu, một thông báo nói thư đi tới địa chỉ
  nào, hoặc vì sao chưa đi (tài khoản tắt; khách bị xoá giữa chừng). Thư kích hoạt có nhãn "Mật khẩu
  tạm thời:", và lần CẤP LẠI (nút "Cấp lại mật khẩu", đổi email, bật lại) có tiêu đề/câu mở riêng và
  nói mật khẩu trước không còn dùng được. Một lời gọi Livewire thẳng vào `create()`/`save()` vẫn
  chạy không hỏi — hộp xác nhận chặn lỗi gõ nhầm, không phải cổng quyền.
- **Lịch 08:00 — chỉ cần biết khi đọc log, không đổi giờ (test ghim giờ):** bốn tác vụ cùng đến hạn
  lúc 08:00 — `deadlines.check` (lượt */30), `backup.monitor` (kèm kiểm `rclone` ở `->then()`),
  `instalments.remind`, và thứ Hai/Tư/Sáu `missing-documents.remind`. `schedule:run` chạy chúng tuần
  tự theo thứ tự đăng ký trong `routes/console.php`, nên không đụng nhau về khoá; hai tác vụ xếp thư
  sau cùng có thể chạy trễ sau lượt kiểm sao lưu (mỗi lệnh `rclone` tới `BACKUP_RCLONE_TIMEOUT`, mặc
  định 1800 giây).
- Việc nhỏ khác: docblock `NotifyClientOfStageUpdate::hasEligibleRecipient()` và bốn chú thích còn
  trỏ `eligibleRecipientsQuery()` (đã gỡ) nay trỏ `ResolveClientRecipients`; test M-3 của main thôi
  điền ô `password` không còn trên form, vế dương đo job `SendPortalActivationMail` được xếp; câu từ
  chối gửi lại `client.activation` gọi đúng tên nút "Cấp lại mật khẩu".

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

## Ghi chú M9

### Làn m9r — Task 1 và Task 11

- **Task 1 — mười hai lĩnh vực hành nghề (2026-09-30).** `MatterTypeSeeder` có 12 loại: bốn loại cũ đổi tên (`DD` Đất đai và bất động sản, `DN` Đầu tư và doanh nghiệp, `DS` Giải quyết tranh chấp, `LD` Lao động và nhân sự; `code` giữ nguyên) và sáu loại mới `HC`, `TM`, `NH`, `SH`, `TC`, `XD`. Máy chủ đã có dữ liệu đổi tên bằng migration dữ liệu `2026_09_30_000001_rename_matter_types_to_office_names` (chỉ đổi khi tên hiện tại **đúng từng ký tự** bằng tên seed cũ; so bằng `===` trong PHP vì `utf8mb4_unicode_ci` không phân biệt hoa/thường và dấu). Seeder vẫn chỉ thêm.
- **Bộ giai đoạn tạm cho sáu lĩnh vực mới — chờ chủ văn phòng mô tả quy trình thật.** `StagePresets::provisional()`: tiếp nhận → thu thập hồ sơ → soạn hồ sơ → đang thực hiện → kết thúc, chuỗi thẳng. `StagePresets::for()` có nhánh tường minh cho sáu mã (nhánh `default` vẫn là bộ dân sự). `description` của mỗi loại mới ghi "TẠM". Không loại mới nào có vụ mẫu công bố ra cổng.
- Mỗi loại vụ việc nay có ít nhất một danh mục hồ sơ mẫu tối thiểu (thêm chín mẫu: `HS`, `DN`, `LD` chưa từng có và sáu loại mới), đều có đầu mục bắt buộc "Hợp đồng dịch vụ pháp lý và giấy uỷ quyền" (tên lấy từ đúng hằng số tab Thanh toán của M9 Task 7 đọc, `BillingRelationManager::REQUIRED_CHECKLIST_ITEM_NAME`; seeder không giữ bản chép nào). Mẫu seed chỉ được tạo cho loại CHƯA có danh mục mẫu nào (kể cả đã xoá) — rà soát cuối làn C1: `OpenMatter` áp mẫu đang dùng mới nhất, nên một mẫu seed chèn cạnh mẫu văn phòng tự soạn cho `HS`/`DN`/`LD` (hay cho một loại `HC`…`XD` văn phòng tự tạo) sẽ thay chỗ nó ở mọi vụ mới. `MatterTypeFactory` loại mọi mã của seeder khỏi mã ngẫu nhiên.
- **Task 11 — nhắc đợt quá hạn (2026-09-30).** `App\Actions\Schedule\RemindOverdueInstalments` (08:00 hằng ngày, `instalments.remind`, `withoutOverlapping(60)`) xếp `SendInstalmentOverdueMail` (chỉ mang id đợt + ngày đến hạn) → thư `staff.instalment_overdue` (`App\Mail\Staff\InstalmentOverdue`, chuỗi ở `lang/vi/billing.php` khối `overdue_email`). Người nhận qua `ResolveStaffRecipients::forBilling()` + `billingAudienceFor()` — cùng lớp với nhắc hạn, cổng tiền thay cổng vụ; vụ thường: luật sư phụ trách + kế toán, vụ `restricted`: luật sư phụ trách + admin; chuỗi dự phòng R3 chỉ chạy khi cả danh sách rớt. Nhịp: ngày đầu quá hạn rồi bảy ngày lịch một lần; chống trùng theo từng người nhận qua `outbound_messages` (khoá `overdue@<ngày đến hạn>` trong `payload->tier`), kiểm lúc xếp job lẫn lúc gửi. Vụ đã kết thúc (`closed_at`) vẫn được nhắc, vụ xoá mềm thì không. Thư mang liên kết: trang "Công nợ" cho người mở được nó (`Receivables::canBeOpenedBy()`, tách từ `canAccess()`), tab "Hợp đồng và thanh toán" của vụ cho luật sư phụ trách (`?relation=<vị trí của BillingRelationManager trong MatterResource::getRelations()>`, tra ngược bằng `array_search`). SPEC §6.8 và §9 đã đính chính có ngày 2026-09-30. Việc hoãn: dòng `outbound_messages` của thư này chỉ admin thấy (`instalment` không có `matter_id`, không nằm trong `OutboundMessage::DIRECT_MATTER_TYPES`) — muốn kế toán/luật sư tự xem thì phải đi qua cổng tiền, ngoài phạm vi. `routes/console.php`: thêm dòng `use` cho `RemindOverdueInstalments` (giữa `RecordScheduleRun` và `SendHeartbeat`) và khối lịch ở cuối tệp — xung đột với làn m6 ở cả hai chỗ, xem "Việc hoãn của làn" bên dưới.
- **Rà soát cuối làn, vòng sửa 1 (2026-09-30).** Ngoài C1 và tên đầu mục hợp đồng dịch vụ (dòng trên): thư quá hạn không còn giữ đợt/người nhận ở thuộc tính `public` (Laravel đưa mọi thuộc tính public vào view, đợt mang sẵn tiêu đề vụ); câu "ghi khoản thu" gửi người không ghi được nay là "Kế toán hoặc quản trị viên…" (đúng cả khi chuỗi dự phòng R3 tới quản lý vì không còn kế toán); số ngày nhắc lại đọc từ `SendInstalmentOverdueMail::REPEAT_EVERY_DAYS`; đầu mục bắt buộc của mẫu `DN` không còn bảo khách "bỏ qua". `docs/CAI-DAT.md` ghi bản cập nhật M9 làm gì trên máy chủ đã có dữ liệu. Các minor còn lại của mọi lượt rà soát làn nằm ở "Việc hoãn của làn" bên dưới.
- **Rà soát cuối làn, lần 2 (2026-10-01).** Không có Critical. Một Important: danh sách việc hoãn của làn chỉ nằm trong sổ làn, là thư mục không vào git — nay chép đủ vào mục dưới đây, mỗi việc một dòng kèm lý do. Suite đầy đủ ở `e4562a3` xanh ngày 2026-10-01.
- **Việc hoãn của làn** (mỗi dòng: việc — lý do hoãn). Gom từ mọi lượt rà soát của làn (Task 1, Task 11, các lượt rà soát lại, hai lượt rà soát cuối làn); mọi minor khác của các lượt đó đã sửa (việc hoãn về quyền xem sổ thư nằm ở dòng Task 11).
  - Ghi chú gộp, làn m10: migration `2026_09_30_000001_rename_matter_types_to_office_names` trùng số thứ tự với `2026_09_30_000001_create_intake_requests_table` của làn m10 — hoãn cho người gộp: vô hại (tên đầy đủ khác, sắp `c` trước `r`, hai migration không phụ thuộc nhau); đổi tên tệp bây giờ phải chạy lại cả vòng migration MariaDB mà không đổi hành vi.
  - Ghi chú gộp, làn m6: `routes/console.php` — làn m6 thêm ba `use` (`CheckStaleMatters`, `RemindMissingDocuments`, `RemindUnseenUpdates`) đúng chỗ làn này chèn `use RemindOverdueInstalments;`, và cũng nối khối lịch ở cuối tệp, nên xung đột văn bản ở cả hai chỗ — hoãn cho người gộp: thay đổi của làn chỉ thêm, giữ cả hai bên. `ResolveStaffRecipients` chỉ xung đột nếu làn m6 sửa tệp này (nhánh `m6-rest` ngày 2026-10-01 chưa sửa).
  - Ghi chú gộp, làn m6: sổ "gửi lại" của làn m6 (`ResendTarget`/`ResendOutboundMessage`, đang làm) phải quyết định thư `staff.instalment_overdue` (liên kết `instalment`, không có `matter_id`) có được gửi lại không — hoãn cho người gộp: lúc làn này xong chưa có mã nào để quyết.
  - Ghi chú gộp, làn m6: sau khi gộp `RemindMissingDocuments`, khách của vụ công bố ra cổng thuộc chín loại vừa có mẫu (vụ mở sau bản cập nhật, vì `OpenMatter` áp danh mục lúc mở vụ) cũng được nhắc đầu mục bắt buộc "Hợp đồng dịch vụ pháp lý và giấy uỷ quyền", như `DD`/`DS`/`HN` hôm nay — hoãn cho người gộp: đúng ý mẫu, chỉ cần biết trước.
  - N+1 ở `RemindOverdueInstalments::handle()` (`app/Actions/Schedule/RemindOverdueInstalments.php:80-90`): mỗi đợt quá hạn một lượt tìm người nhận (truy vấn vai + `Gate` từng người) và một truy vấn `outbound_messages` cho mỗi người nhận — hoãn: quy mô một văn phòng (vài chục đợt quá hạn), chạy một lần lúc 08:00 dưới `withoutOverlapping(60)`.
  - Chống trùng theo địa chỉ thư (`SendInstalmentOverdueMail::alreadyReminded()`, `app/Jobs/SendInstalmentOverdueMail.php:139-151` so cột `recipient` với email hiện tại): người nhận đổi email trong bảy ngày thì nhận thêm một thư ở địa chỉ mới — hoãn: vô hại (cùng người, thư nội bộ, cùng nội dung).
  - Migration `2026_09_30_000001_rename_matter_types_to_office_names` không kiểm một loại vụ việc sống khác đã mang đúng tên mới — hoãn: chỉ xảy ra khi văn phòng đã tự tạo loại trùng tên y hệt dưới mã khác; `matter_types.name` không có unique nên không hỏng dữ liệu, chỉ hiện hai mục cùng tên trong ô chọn; `docs/CAI-DAT.md` hướng dẫn tắt "Đang dùng" ở một mục.
  - Tên đầu mục chép nguyên ở `lang/vi/billing.php:86` (`billing.tab.checklist_nudge`, chuỗi của Task 7): đổi `BillingRelationManager::REQUIRED_CHECKLIST_ITEM_NAME` thì lời nhắc ở tab Thanh toán vẫn gọi tên cũ, nên docblock `database/seeders/ChecklistTemplateSeeder.php:129-134` ("đổi ở hằng số, một chỗ") nói quá, và câu "chưa có hợp đồng dịch vụ" trong ngoặc của nó không phải chữ thật của lời nhắc — hoãn: chuỗi thuộc Task 7 trên main, không phải của làn; sửa đúng là đưa tên vào chuỗi qua `:name` lấy từ hằng số.
  - `database/seeders/ChecklistTemplateSeeder.php` phụ thuộc lớp Filament `BillingRelationManager` chỉ để lấy một hằng chuỗi — hoãn: chạy đúng; gỡ bằng cách chuyển hằng số sang `App\Support`, dùng chung cho seeder và tab Thanh toán (tệp của Task 7).
  - `InstalmentOverdue::link()` (`app/Mail/Staff/InstalmentOverdue.php:160-174`): ai không mở được "Công nợ" đều nhận URL trang vụ, không hỏi `view` vụ — hoãn: với bảng quyền hôm nay (định nghĩa trong mã, chưa có màn hình sửa vai) chỉ luật sư phụ trách rơi vào nhánh này; URL chỉ mang id vụ (không mã, tên vụ hay khách) và trang vụ tự hỏi `view`; xem lại khi vai sửa được trên màn hình.
  - Docblock sai từ khi `InstalmentOverdue` cũng đặt `X-VKCRM-Ledger-Tier`: `app/Mail/OutboundHeaders.php:33` ("CHỈ `DeadlineReminder` đặt", danh sách bậc thiếu `overdue@<ngày đến hạn>`), `app/Actions/Notification/RecordOutboundMessage.php:57-59` và `:172-173` ("mọi mẫu thư TRỪ `DeadlineReminder`") — hoãn: chỉ là chú thích; hành vi (khoá `overdue@<ngày đến hạn>` trong `payload->tier`) có test.
  - `app/Actions/Notification/ResolveStaffRecipients.php:134` ghi "`qualify()` là nơi DUY NHẤT lọc `is_active`", nhưng `supervisorsFor()` (`:158`) và `fallbackChain()` (`:227`, `:237`) cùng lớp cũng lọc ở truy vấn — hoãn: chỉ là chú thích.
  - `tests/Feature/Filament/MattersByStageWidgetTest.php:16-22` đọc `getData()` của widget qua reflection (`mattersByStageWidgetData()`) — hoãn: khuôn có sẵn của tệp từ trước làn; widget không đổi.
  - `tests/Feature/Mail/InstalmentOverdueActionTest.php` dựng thư trực tiếp (`overdueMailFor()`) thay vì đi qua job — hoãn: đường job đã có `tests/Feature/Jobs/SendInstalmentOverdueMailTest.php`.
  - Hàm/hằng Pest ở phạm vi toàn cục của làn (`overdue*`, `OVERDUE_*`, `jobFor`, `jobSentTo`, `jobEmails`, `billingIds`, `billingIdList`, `instalmentReminderEvent`, `activateOverdueContract`, `useInstalmentSelectiveFailMailer`, `expectActionSentences`, `seedOldTypeRow`, `renameMigration`, `renameTestTypeName`, `M9R_*`) — hoãn: ngày 2026-10-01 đã grep `tests/` của mọi làn và của main, không trùng; các tên chung chung nhất đã đổi. Tên trùng lúc gộp lộ ngay thành lỗi "Cannot redeclare".
  - Ngoài làn: `tests/Feature/Filament/RevenueDashboardTest.php` đỏ 21 test khi chạy ngày 2026-09-30 (ngày cuối tháng), xanh lại ngày 2026-10-01 — lỗi phụ thuộc ngày có sẵn trên main, chưa chẩn đoán; nhiều khả năng lặp lại vào ngày cuối tháng kế tiếp.

### Làn m9f — Task 6, 10, 13

#### Task 10 — khách xem hợp đồng và lịch thu của chính mình trên cổng (2026-10-03, nhánh `m9-final-portal`)

- **Ba tầng của cổng, nới có chủ đích (P1), mỗi tầng một câu lệnh riêng.** *Truy vấn:* `applyClientPortalConstraints()` của `Contract` = `scopeShownToClient()` (`active`/`completed`) + `whereHas('matter')` trần — kế thừa nguyên năm điều kiện ranh giới cổng của `Matter`, gồm `client_access_until` (M7) và khách hàng chưa xoá mềm (M6.5), không một `where('client_id')` nào; `Instalment` = khác `cancelled` + `whereHas('contract')`; `Payment` = `voided_at` rỗng + `whereHas('instalment')`; `ContractAmendment` = `whereHas('contract')`. *Quyền:* nhánh `ClientUser` trong `view()` của bốn policy nói lại điều kiện của chính dòng bằng thuộc tính rồi hỏi `Gate` của bản ghi cha (cha nạp qua `ReadsPortalParents`), tới `MatterPolicy::view` — không chạy lại scope tiền. `viewAny`, soạn, sửa, xoá, miễn, ghi, huỷ vẫn đóng với khách (`ChecksBillingAccess` không đổi luật, chỉ đổi docblock). *Serialize:* danh sách ẩn của bốn model thêm đúng các cột kế hoạch nêu (`activated_by`, `waived_by`, `voided_by`, `attributed_lawyer_id`, `percent_basis`, `created_by`, `updated_by`) cộng `triggered_by_stage_log_id` (ngoài danh sách kế hoạch: id một dòng tiến độ có thể chưa công bố); trang trả một hình chiếu chuỗi hẹp. `TimeEntry` vẫn `1 = 0` — test cặp dương mới ở `TimeEntryPolicyTest` (cùng khách đọc được hợp đồng đã ký của cùng vụ, giờ làm việc vẫn đóng ở cả hai tầng); đặt ở đó vì phép quét cấu trúc của `TimeEntryTest` chỉ cho các tệp Task 12 nhắc tên model. `PortalCoverageTest` xanh, không thêm miễn trừ.
- **Phán quyết B.6 (`ContractAmendment`) — mở theo chữ kế hoạch** (phán quyết controller): scope + policy cho phụ lục của hợp đồng khách thấy; `reason`, `document_id` ẩn; trang cổng KHÔNG vẽ phụ lục (P1 không liệt kê). Giá nếu sai: một dòng `whereHas('contract')` và một nhánh policy cho một bảng không màn hình nào đọc — đóng lại là đổi về `1 = 0` và `false`.
- **Khối "Hợp đồng và thanh toán"** trên `MatterProgress` (`billing()` + view), đứng SAU khối 6 và TRƯỚC khối 7, `data-portal-block="billing"` (không phải một số), chỉ hiện khi có hợp đồng `active`/`completed`. Hợp đồng, từng đợt, từng khoản thu đều qua `Gate::forUser($viewer)->allows('view', …)` (tầng 2 trên chính trang). Một cột, không bảng, màu chỉ qua biến CSS Filament, "Quá hạn thanh toán" luôn bằng chữ. Chữ của khách ở `lang/vi/portal_progress.php` (`blocks.billing`, `billing.*`) — KHÔNG dùng nhãn nội bộ của `enums.instalment_state`/`payment_method` (quyết định: giọng của người trả tiền, "Bù trừ với khoản khác giữa hai bên" thay "Cấn trừ"). Đợt theo tiến độ chưa tới bước nói `client_label`, không bao giờ `label` hay khoá thô; không tra được nhãn thì "Đến hạn theo tiến độ vụ việc". Hợp đồng `completed`: dòng in trạng thái HỢP ĐỒNG ("Hợp đồng đã hoàn tất ngày …"), từng đợt chỉ còn "Văn phòng đã miễn" khi đúng vậy (luật I4 của `Instalment::state()`); "còn lại" đọc `outstanding()` nên là 0 ₫.
- **Bảng kê trong `MUC-LUC.pdf`:** partial `handover/partials/billing.blade.php` + một `@include` (sau khối tiến độ) + khoá `billing` từ `RenderHandoverIndex::billingStatement()`. Cổng và PDF dùng CHUNG một hình chiếu `App\Support\Billing\ClientBillingStatement` (test so `toBe` hai mảng trên cùng dữ liệu). **Cách lọc của PDF:** gói dựng trong job, không có phiên cổng, nên PDF gỡ scope cổng và lọc bằng ĐÚNG các scope `shownToClient()` mà tầng truy vấn của cổng dùng (một định nghĩa SQL cho hai nơi), chọn cột tường minh (ghi chú, lý do, mã giao dịch, biên lai, người ghi không được nạp). Ranh giới VỤ VIỆC của cổng không áp cho PDF (như mọi khối khác của mục lục). Biên lai và scan phụ lục là nhóm D, không vào zip (test giải nén). Chân trang vẫn đọc `OfficeProfile`.
- **Test đổi nghĩa có chủ đích:** `BillingAccessTest` "never lets a client user list, manage, record or void money, even on their own restricted matter" → "lets a client user read but never list, manage, record or void the money of its own matter, restricted or not" (vế ĐỌC đảo theo P1, cặp âm: khách khác không đọc được). `BillingChildPolicyTest` bốn tiêu đề "never" → "draft … not even the matter's own client" + một cặp dương (ký xong thì đọc được, vẫn không `viewAny`). `PortalClosureTest` bốn test → "…of a draft contract" + một cặp dương; docblock viết lại. `MatterProgress` docblock "Không có khối thứ tám" viết lại: khối tiền là loại dữ liệu thứ tám có chủ đích; `communication_logs` vẫn không lên cổng. `OfficeProfilePageTest` (M7) dựng tay mảng dữ liệu của `handover.index` — thêm khoá `billing => null` (view có khoá thứ năm).
- **Sửa một lỗi chập chờn của công cụ test M7** (`tests/Support/PdfText.php`): luồng nén kết thúc bằng byte `\r` (~1/256 luồng) bị regex `\r?\nendstream` nuốt mất byte cuối → `gzuncompress` hỏng → CẢ TRANG mất khỏi văn bản trích ra. Đo: 300 lần dựng mục lục hai trang, 1/2400 luồng, đúng một trang mất. Sửa: cắt theo `/Length` trực tiếp. Test tất định `tests/Unit/Support/PdfTextTest.php` (đỏ khi gỡ bản sửa).
- **SPEC đính chính 2026-10-03:** §5 phần Portal (loại dữ liệu thứ tám), §8.3 (khối mới, vị trí), §6.12 (bảng kê trong gói).
- **Câu hỏi cho chủ văn phòng:** khách thấy tiền của chính mình trên vụ `restricted` (P3 là luật của nhân sự; khách là bên đã ký) — đề xuất giữ. Đảo: thêm `confidentiality` vào nhánh khách của `ContractPolicy::view` và scope `Contract`.
- Mutation probe (30, đều đỏ đúng test nêu tên nó, bằng chứng ở báo cáo làn): scope 7, policy 7, cột ẩn 4, hình chiếu/RPC 1, `Gate` trên trang 3, lọc của PDF 3, luật trình bày 4, `PdfText` 1. Probe scope không làm đỏ test nào của trang (tầng quyền còn đứng) — đúng điều nghi thức ba tầng đòi.

## Ghi chú M8

Làn `m8b-security` (nhánh `m8b-security`, worktree `D:\vkwt\lane-m8b`), cắt từ `m8-backup-csp` gộp
`m6-5-lane-d`. Chi tiết đầy đủ ở sổ SDD của làn:
`D:\vkwt\lane-m8b\.superpowers\sdd\m8b\progress.md`.

### Task 1 — Proxy, HTTPS, header, CSP, giới hạn IP admin (R1, R4, R7)

- **`vkcrm:preflight` (R1)** — `App\Actions\Deployment\RunPreflight`, `App\Console\Commands\
  PreflightCommand`. Kiểm `TRUSTED_PROXIES`, `HEARTBEAT_URL`, `SESSION_SECURE_COOKIE` (giá trị ĐÃ
  GIẢI), `APP_DEBUG`, PHP extension bắt buộc, `storage/app/private` phục vụ công khai được không,
  ba điều kiện máy chủ cho sao lưu (`ZipArchive::EM_AES_256`, `proc_open`, `mariadb-dump`/
  `mysqldump`), bốn thông tin `BRAND_*` (vàng), và ba biến số sao lưu có giá trị không phải số
  (đỏ, mọi môi trường). `APP_ENV` để trống là đỏ ở mọi nơi; khác `production` chỉ in một dòng vàng,
  không kiểm các điều kiện trên. **Chạy lệnh này TRƯỚC `php artisan config:cache`** — ba điều kiện
  đọc `env()` trực tiếp (không qua `config()`) để bắt đúng giá trị RAW (phát hiện lỗi gõ như
  `BACKUP_RCLONE_TIMEOUT=30m`), và sau khi cấu hình đã cache, `env()` ngoài một tệp cấu hình luôn
  trả `null` (`LoadEnvironmentVariables::bootstrap()` bỏ qua việc nạp `.env` khi có cache) — ghi rõ
  trong docblock và `docs/CAI-DAT.md`.
  - **`gd` không nằm trong danh sách extension bắt buộc, là một dòng VÀNG riêng.** SPEC §2 liệt kê
    `gd`, và `config/media-library.php` chọn nó làm `image_driver` mặc định, nhưng soát ngày
    2026-09-28 (`grep -rn "registerMediaConversions\|addMediaConversion" app/`) không thấy dự án
    đăng ký chuyển đổi ảnh nào — thiếu `gd` hôm nay không làm vỡ tính năng đang chạy thật. Đỏ hoá
    dòng này khi có Task nào đăng ký `addMediaConversion()` đầu tiên.
  - Danh sách extension đọc qua `config('vkcrm.deployment.required_extensions')` (không phải một
    `const` cứng) để test gài được một tên giả, dựng cả hai chiều đỏ/xanh mà không cần gỡ thật một
    extension của container.
  - Đã sửa hai chỗ tài liệu nói sai chủ của lệnh này: `docs/CAI-DAT.md` mục "Khi đưa lên máy chủ
    thật" (mục 5) và `docs/SAO-LUU-KHOI-PHUC.md` Bước 5 — cả hai từng ghi "M8 Task 8, chưa có".
- **Ép HTTPS + HSTS** — `App\Http\Middleware\EnforceHttps` (toàn cục, `append()` ở
  `bootstrap/app.php` — SAU `TrustProxies`, bắt buộc để `$request->secure()` đọc đúng
  `X-Forwarded-Proto` của proxy ĐÃ được tin). `FORCE_HTTPS`/`SESSION_SECURE_COOKIE`/`HSTS_MAX_AGE`
  để trống là chặt nhất (bật/hạn một năm) ở mọi môi trường trừ `local`/`testing`
  (`App\Support\Security\HttpsDefaults`, cùng thành ngữ với `CSP_MODE`). GET/HEAD chuyển hướng
  301; phương thức khác 308 (không mất thân request). `SESSION_SECURE_COOKIE` giải xong được ghi
  đè vào `config('session.secure')` ở `AppServiceProvider::boot()` (không sửa được ngay trong
  `config/session.php` — gọi `app()->environment()` ở đó ném `BindingResolutionException`, vì
  `LoadConfiguration` nạp tệp cấu hình TRƯỚC dòng `detectEnvironment()`; đọc docblock của
  `HttpsDefaults` và `AppServiceProvider::boot()`). `URL::forceHttps()` cũng gọi ở `boot()` (không
  ở middleware) để link trong thư/PDF do queue worker sinh ra luôn là `https`.
  - HSTS không kèm `includeSubDomains`/`preload` mặc định — các tên miền con khác của
    luatvukhang.com nằm ngoài ứng dụng này.
  - Lớp CHÍNH cho HSTS là máy chủ web: `tools/deploy/nginx.conf.example` và
    `tools/deploy/apache-vhost.conf.example`, **đã chạy thử** ngày 2026-09-28:
    - `docker run --rm nginx:stable-alpine` (mount cấu hình + chứng chỉ giả) → `nginx: the
      configuration file /etc/nginx/nginx.conf syntax is ok` / `test is successful`.
    - `docker run httpd:2.4-alpine` (bật `mod_ssl`/`mod_rewrite`/`mod_headers`/
      `mod_socache_shmcb`, chứng chỉ tự ký giả) → `Syntax OK`.
    - Cả hai mẫu cộng thêm ba header SPEC §10 mục 2 cho tệp tĩnh dưới `public/` (đóng nốt minor
      hoãn của M8a: "public/ static files never see any header"), chặn dotfile, và chặn
      `/storage/` (dự án không dùng `storage:link`, SPEC §2 — một symlink như vậy chỉ có thể là
      cấu hình sai, đúng thứ `vkcrm:preflight` cũng dò).
- **Giới hạn IP admin (R7)** — `App\Http\Middleware\RestrictAdminIpAllowlist`, đọc
  `ADMIN_IP_ALLOWLIST` (`config('vkcrm.security.admin_ip_allowlist')`, phân tách dấu phẩy, IPv4/
  IPv6/CIDR). Rỗng = TẮT (mặc định). Đăng ký trên panel `admin`, `isPersistent: true`, TRƯỚC
  `AnswerDeniedPanelRequestsWithNotFound` — IP ngoài danh sách nhận 404 (SPEC §10.10), kể cả trên
  request cập nhật Livewire. KHÔNG phủ `documents.download`/`/livewire/upload-file` (quyết định có
  chủ đích, xem docblock middleware).
  - **CẦN HỎI CHỦ VĂN PHÒNG** (kế hoạch M8, dòng 49): có bật `ADMIN_IP_ALLOWLIST` không, và nếu
    bật thì những dải IP nào (văn phòng, VPN nếu có). Để trống cho tới khi có câu trả lời.
  - **Lưu ý cho M12** (đã ghi trong brief Task 1): bật allowlist thì app admin trên điện thoại
    dùng mạng 4G (không qua VPN/IP văn phòng) sẽ nhận 404.
- **Soát `unsafe-eval` (M8a minor hoãn "→ owner + M8 audit").** Liệt kê MỌI chỗ vẽ HTML thô trong
  `app/` và `resources/views/` ngày 2026-09-28: `->html()` (6 chỗ, `ChecklistRelationManager`,
  `ClientRequestsRelationManager`, `DeadlinesRelationManager`, `DocumentsRelationManager`
  (`internalRowStyle()` — CSS tĩnh, không có dữ liệu người dùng), `StageLogsRelationManager` ×2,
  `UpcomingDeadlinesWidget` ×2), `HtmlString` (cùng danh sách), không `Str::markdown()` nào, không
  `{!! !!}` nào ngoài các view thư `text/plain` (đã có docblock giải thích lý do an toàn — thư văn
  bản thuần không có trình duyệt nào diễn giải HTML), và KHÔNG có thuộc tính Alpine
  (`x-data`/`x-init`/`x-on`/`x-bind`) nào do dự án tự viết trong `resources/views/` (Alpine chỉ
  đến từ nội bộ Filament, đã qua khảo sát CSP của M8a). **Kết luận: mọi chỗ vẽ HTML thô của dự án
  đều `e()` từng biến trước khi đưa vào `sprintf()`** — không chỗ nào vẽ chữ người dùng gõ mà
  không escape hôm nay. Đây là ảnh chụp ngày 2026-09-28, không phải một bảo đảm tĩnh mãi mãi — một
  cột `->html()` mới quên `e()` sẽ tái mở lỗ hổng này. **Quyết định giữ hay bỏ `unsafe-eval` vẫn
  là của chủ văn phòng** (kế hoạch M8, phán quyết R4 phần audit): giữ nó vì Alpine của Livewire cần
  nó để chạy (mất nó thì trang đăng nhập nhân sự không dùng được, đo ở M8a); bỏ nó chỉ an toàn hơn
  về lý thuyết chừng nào không ai thêm một sink chưa escape — không có động tác kỹ thuật nào khác
  đổi được kết luận này ngoài việc chờ Filament tự chuyển toàn bộ Alpine sang chế độ CSP-safe.
- **Việc mang sang M7** (Task 10, hồ sơ pháp lý văn phòng qua `OfficeProfile`): khi Task đó xong,
  đổi nguồn đọc bốn trường `tax_code`/`bar_association`/`licence_number`/`office_address` trong
  `RunPreflight::brandFieldsRow()` từ `config('vkcrm.brand.*')` sang `OfficeProfile`, giữ nguyên
  bốn tên biến `.env` hiển thị trong thông điệp vàng.
- **`.env.example`**: khối mới ở cuối tệp — `SESSION_SECURE_COOKIE`, `FORCE_HTTPS`, `HSTS_MAX_AGE`,
  `HSTS_INCLUDE_SUBDOMAINS`, `HSTS_PRELOAD`, `ADMIN_IP_ALLOWLIST`.

### Task 1, fix round 1 (2026-09-28) — bốn điểm rà soát

1. **`tools/deploy/nginx.conf.example` — Livewire 4 bị chính location regex tệp tĩnh 404 hoá.**
   Script `livewire.min.js` phục vụ qua một ROUTE PHP mang tiền tố băm
   (`/livewire-<hash>/livewire.min.js`), không phải tệp thật dưới `public/`, nhưng đuôi tên trùng
   với `location ~* \.(?:css|js|...)$ { try_files $uri =404; }` — nginx chọn location regex khớp
   trước khi PHP kịp thấy request, nên MỌI trang có Livewire (đăng nhập admin, đăng nhập portal,
   mọi form) tải HTML bình thường nhưng không component nào chạy được. Sửa bằng một location tiền
   tố `^~ /livewire-` đứng trước, `try_files $uri /index.php?$query_string;` — `^~` cắt hẳn bước dò
   regex của nginx khi đây là tiền tố khớp dài nhất.

   **Xác nhận THẬT bằng curl qua nginx + php-fpm** (không chỉ `nginx -t`), container
   `nginx:stable-alpine` + `webdevops/php:8.3-alpine` (worktree mount `ro`), ngày 2026-09-28:
   - **TRƯỚC khi sửa** (`location` chặn còn thiếu):
     `curl -sk https://.../livewire-ba5adf96/livewire.min.js?id=b7ac2fb1` → `HTTP/2 404`, thân
     trang lỗi mặc định của chính nginx (`<center>404 Not Found</center><hr><center>nginx/1.30.5
     </center>`) — script không tới được PHP.
   - **SAU khi sửa** (đúng nội dung `tools/deploy/nginx.conf.example` hiện tại): cùng URL →
     `HTTP/2 200`, `content-type: application/javascript; charset=utf-8`,
     `content-length: 257998` (đúng kích thước `livewire.min.js` thật). `/admin/login` và
     `/portal/login` qua cùng cấu hình đều `200`.
   - Mẫu `apache-vhost.conf.example` KHÔNG cần sửa tương ứng: `<FilesMatch>` của Apache chỉ gắn
     header vào tệp Apache THẬT SỰ phục vụ; đường dẫn Livewire không khớp tệp nào trên đĩa nên
     `.htaccess`/`AllowOverride All` của Laravel rewrite thẳng nó sang `index.php` như mọi route
     khác — không đi qua `<FilesMatch>` — xác nhận lại bằng `httpd -t` (Syntax OK) trên bản có
     bình luận mới, không đổi khối rewrite.
2. **`tests/Feature/Panels/AdminIpAllowlistTest.php` — test "isPersistent" cũ không đo được gì.**
   POST `['components' => []]` (rỗng) bị chính Livewire `abort(404)` NGAY ở bước phân giải
   component, trước khi middleware bền kịp chạy — test xanh bất kể allowlist bật/tắt hay
   `isPersistent` có mặt hay không. Sửa theo đúng khuôn `DenialCodeTest.php`: lấy `wire:snapshot`
   THẬT từ một trang admin render thật (`ClientResource` edit, dưới một IP qua được allowlist), rồi
   POST snapshot đó tới `Livewire::getUpdateUri()` — một request cập nhật không rỗng, đi hết tới
   bước middleware bền. Hai `it()` riêng (bắt buộc — `PersistentMiddleware` nhớ theo
   `"{method}|{path}"` trong cùng một request thật, gộp hai POST cùng đường dẫn vào một test làm
   POST thứ hai không chạy middleware bền nào): một IP bị chặn (404) và một IP được phép (200,
   cùng snapshot, cùng tài khoản) — cặp âm/dương chứng minh middleware phân biệt theo IP thật, chứ
   không phải luôn luôn 404. Mutation probe: đổi `isPersistent: true` → `false` ở
   `AdminPanelProvider` → test "IP bị chặn" đỏ đúng chỗ (`Expected 404 but received 200`); khôi
   phục lại xanh.
3. **`AppServiceProvider::boot()` — hai dòng HTTPS/session chưa có test gọi thẳng.** Test cũ chỉ đo
   hành vi CỦA `EnforceHttps`/`HttpsDefaults` qua middleware, hoặc tự gọi `URL::forceHttps()` thay
   vì đi qua provider — xoá một trong hai dòng ở `boot()` không làm bộ test đỏ. Thêm hai `it()`
   TÁCH RIÊNG (không gộp một test — đo được lúc viết: `Symfony\Component\HttpFoundation\Response::
   prepare()` tự đặt `secureDefault=true` cho MỌI cookie khi CHÍNH request hiện tại `isSecure()`,
   và một khi `URL::forceHttps()` đã chạy thì `$this->get('/portal/login')` [đường dẫn tương đối]
   tự sinh request https qua `url()` — gộp chung khiến phép đo dòng `session.secure` xanh giả nhờ
   đúng dòng `URL::forceHttps()` kia, không nhờ chính nó):
   - Dòng `config(['session.secure' => HttpsDefaults::boolFromRaw(...)])`: tắt hẳn `FORCE_HTTPS`
     (không để trống) để cô lập, gọi `boot()` thật, GET một URL TUYỆT ĐỐI `http://` (bỏ qua
     `forceScheme` vì `UrlGenerator::isValidUrl()` trả nguyên văn URL đã đủ scheme), rồi hỏi cookie
     phiên thật có `Secure`.
   - Dòng `URL::forceHttps()`: để `FORCE_HTTPS` trống (bật mặc định production), gọi `boot()`
     thật, sinh chữ ký `temporarySignedRoute()` và hỏi nó có bắt đầu `https://`.
   Mutation probe từng dòng (comment tạm + `&& false`): mỗi lần đỏ đúng test tương ứng
   (`Failed asserting that false is true` / URL sinh ra bắt đầu `http://`); khôi phục lại xanh cả
   hai.
4. **`RunPreflight::trustedProxiesRow()` — `TRUSTED_PROXIES` tin toàn bộ IP báo XANH.** Mẫu
   `tools/deploy/` tả một cấu hình KHÔNG proxy tách rời (nginx/apache nói thẳng với php-fpm), và
   `config/trustedproxy.php` từng gợi ý `*` cho đúng tình huống "không biết địa chỉ đó" — một
   người theo đúng mẫu, không có proxy nào để điền, làm theo gợi ý đó được preflight XANH trong khi
   `*`/`**`/`0.0.0.0/0`/`::/0` tin bất kỳ IP nào tự khai `X-Forwarded-For`, xuyên thủng
   `ADMIN_IP_ALLOWLIST` (R7, Ghi chú M8 mục Task 1) và bộ đếm đăng nhập theo IP (§10.3). Sửa: bốn
   giá trị đó (không phân biệt hoa/thường, kể cả đứng cạnh một IP thật trong danh sách) nay ĐỎ,
   dòng thông điệp mới `preflight.trusted_proxies_trust_all`. Test dataset 5 trường hợp
   (`*`, `**`, `0.0.0.0/0`, `::/0`, `10.0.0.1,0.0.0.0/0`) — mutation probe (`if (false)` tạm thay
   điều kiện thật) làm cả 5 đỏ đúng chỗ, khôi phục lại cả 19 test của tệp xanh. Cập nhật
   `docs/CAI-DAT.md` mục 1, `.env.example`, `config/trustedproxy.php`, và cả hai mẫu
   `tools/deploy/` — chốt giá trị đúng cho cấu hình không-proxy này là
   `TRUSTED_PROXIES=127.0.0.1` ("không có proxy nào để tin, REMOTE_ADDR đã là địa chỉ thật" —
   nginx tự đặt `fastcgi_param REMOTE_ADDR $remote_addr;` bằng địa chỉ client thật bất kể
   TCP/unix-socket nối tới php-fpm), không phải `*`.

Bằng chứng chạy: cả bộ `/d/vkwt/m8b-dev test --parallel --processes=4` → **2429 passed, 6 skipped, 0
failed** (10099 assertions), 475.44s (mốc trước fix round 1: 2421 passed — chênh +8 khớp đúng số
test mới của bốn điểm trên: 5+1+2). `pint --test` sạch 599 tệp. Ba tệp test đụng tới
(`PreflightCommandTest`, `EnforceHttpsTest`, `AdminIpAllowlistTest`) chạy lại tuần tự trên
`test:mariadb` → 42 passed, 0 failed. Chi tiết mã nguồn/dòng, lệnh và log đầy đủ ở
`.superpowers/sdd/m8b/task-1-report.md`, mục "## Fix round 1".

### Task 2 — 2FA bắt buộc cho toàn bộ tài khoản nội bộ (R2, §10 mục 7)

- **Bật ở panel `admin`**: `->multiFactorAuthentication([StaffAppAuthentication::make()->recoverable()
  ->brandName(...)], isRequired: true)` cộng `->persistentMiddleware([EnsureMultiFactorAuthentication
  IsEnabled::class])` (cổng của Filament chỉ đứng trước route của TRANG; request `/livewire/update`
  của người vừa bị "Đặt lại 2FA" đi vòng qua nó nếu thiếu dòng này — có test dùng `wire:snapshot`
  thật). Filament 5.8.1 có sẵn 2FA ứng dụng + mã khôi phục, không cài gói TOTP. `users.two_factor_secret`
  / `two_factor_recovery_codes` cast `encrypted` / `encrypted:array`, **không migration** (chưa
  đường nào từng ghi hai cột; text đủ chứa bản mã). SPEC §3 và §4.1 đã đính chính ("Fortify" →
  "2FA ứng dụng của Filament").
- **Không đường tắt**: `StaffAppAuthentication` bỏ `DisableAppAuthenticationAction` (trang hồ sơ
  giữ "Tạo lại mã khôi phục"). Test quét: trang hồ sơ (a), ép gọi hành động tắt (b), quét `app/`
  tìm lời gọi `saveAppAuthenticationSecret(null)` (c), request Livewire của người chưa có 2FA (d),
  và (e) **toàn bộ router** — mỗi route thuộc đúng một nhóm (panel admin mang cổng 2FA / panel
  portal guard `client` / danh sách lý lẽ tường minh, có chiều ngược để dòng chết không tồn tại).
- **Vòng sửa 1 tìm thấy một đường thật ở (e)**: `documents.download` nằm ngoài panel; một đường
  dẫn ký (sống 5 phút) sinh trước lúc "Đặt lại 2FA" + phiên mới bằng mật khẩu tải được tệp mà chưa
  có 2FA. Đã RED (200) rồi đóng ở `DocumentDownloadController::actor()` (nhân sự không có secret →
  404). `filament.exports.download` / `filament.imports.failed-rows.download` (chỉ middleware
  `filament.actions`, không cổng 2FA) không phục vụ được gì: `app/` không có Exporter/Importer
  và CSDL không có bảng `exports`/`imports` — test đỏ khi ai thêm tính năng đó.
- **"Đặt lại 2FA"**: `App\Actions\User\ResetStaffTwoFactor` (M11 móc thu hồi token vào đây) — khoá
  dòng `users`, xoá secret + mã khôi phục, tăng `users.session_epoch`, đổi `remember_token`, audit
  `staff_two_factor_reset`; từ chối tự đặt lại cho chính mình. Hai đường vào: nút trên `EditUser`
  (chỉ admin) và `php artisan vkcrm:reset-2fa {email}` (causer null, `via = console`).
- **Phiên cũ của người bị đặt lại chết bằng "epoch", không bằng `sessions.user_id`** (vòng sửa 1,
  lượt 2): `DatabaseSessionHandler` điền `sessions.user_id` từ guard MẶC ĐỊNH lúc ghi, và
  `Filament\Http\Middleware\Authenticate` đổi guard mặc định sang `client` trên `/portal` — một trình
  duyệt nhân sự đồng thời đăng nhập cổng khách để lại `user_id` của khách, dòng phiên sống sót qua
  "xoá theo user_id", và phiên cũ tự cài TOTP của kẻ tấn công ở trang cài đặt bắt buộc. Nay
  `users.session_epoch` (migration `2026_09_30_000001`) tăng mỗi lần đặt lại; `StampStaffSessionEpoch`
  ghi nó vào phiên lúc đăng nhập guard `web`; `RejectStaffSessionsFromBeforeReset` (nhóm `web` +
  middleware panel `admin`, sau `StartSession`, trước cổng 2FA) đăng xuất phiên lệch epoch. Test
  HTTP thật với `session.driver=database`: `StaffTwoFactorResetSessionTest`.
- **Mất `APP_KEY`** (đã đo, sửa `docs/CAI-DAT.md` cho đúng): mỗi nhân sự đã cài 2FA gặp
  `DecryptException` (500) ở bước nhập mã; `vkcrm:reset-2fa <email>` vẫn xoá được secret từng người
  (không cần giải mã) và họ cài lại — hai test trong `ResetStaffTwoFactorTest`. Số định danh khách
  hàng đã mã hoá thì mất vĩnh viễn, như cũ.
- **Demo**: `UserFactory` mặc định có secret (state `withoutTwoFactor()`); seeder gán một secret
  cố định (ghi ở `docs/CAI-DAT.md`) cho nhân sự demo CHỈ khi `APP_ENV` là `local`/`testing`.
- **Khảo sát CSP `enforce` qua 2FA (0 vi phạm)**: `tools/csp/survey.cjs` đăng nhập hai bước (TOTP
  RFC 6238 bằng `crypto` của Node), ghé ô mã khôi phục lúc đăng nhập, đi hết trang "Cài đặt 2FA bắt
  buộc" + modal QR + bước mã khôi phục + bấm "Bật ứng dụng xác thực" (một tài khoản vừa
  `vkcrm:reset-2fa`), modal "Tạo lại mã khôi phục", rồi toàn bộ trang admin/portal của M6.5 và các
  ô tải ảnh. Bản chạy thật `/d/vkwt/m8b-dev seed` (MariaDB `vk_crm_lane_m8b`, secret trong CSDL là
  bản mã `eyJpdiI6…`) + `serve -e CSP_MODE=enforce`: **Tổng vi phạm 0, lỗi JS 0, Worker blob 3/3 chạy
  0 lỗi**, 34 trang/bước, hành động chính đủ ba dòng `[OK]`. Đầu ra đầy đủ:
  `.superpowers/sdd/m8b/probe/csp-enforce-run7.log`. (Sáu lượt trước bị Chromium "Page crashed"
  giữa chừng hoặc chạy đè nhau trên cùng một máy chủ một luồng — chỉ lượt sạch cuối được tính.)
- **Chưa làm / để lại**: luật 5 lần/15 phút cho bước nhập mã là Task 3 (bước này dùng luật mặc định
  5 lần/60 giây theo tài khoản của Filament). `redirect()->intended()` sau bước 2FA có test ở
  `AdminTwoFactorRequiredTest` (M11 dựa vào).

### Task 3 — Rate limit đăng nhập admin + tải tệp, API, nhật ký hoạt động (§10 mục 3, 6)

- **Đăng nhập nhân sự 5 lần / 15 phút theo email VÀ IP, cả hai bước.** Luật của cổng khách được
  tổng quát hoá theo guard chứ không chép bản thứ hai: `App\Support\LoginThrottle` (abstract, toàn
  bộ luật + docblock dài của M5) với hai lớp con `final` — `PortalLoginThrottle` (guard `client`,
  tiền tố khoá `portal-login`, **chuỗi khoá giữ nguyên từ M5**) và `StaffLoginThrottle` (guard `web`,
  `staff-login`, hai guard không bao giờ chung rổ). Trang `App\Filament\Admin\Pages\Auth\Login` +
  `StaffMultiFactorChallenge` theo đúng khuôn portal; bước mã dùng CHUNG một bộ đếm cho TOTP và mã
  khôi phục. Lỗi ở bước mã ghi `login_failed` kèm `step = code` và IP (cùng phán quyết T7 của
  portal; lỗi bước mật khẩu do `RecordStaffLoginFailure` ghi, nay kèm `step = password`); lần bị
  chặn không ghi dòng nào (không ai chấm mã). Test portal (`LoginTest`, `LoginOtpTransportFailure
  Test`, `ClientUserResourceTest` — 119 test) xanh không đổi một khẳng định nào sau refactor.
- **Mở khoá nhân sự**: `App\Actions\User\UnlockStaffLogin` + nút `unlockLogin` trên `EditUser`
  (chỉ admin — `UserPolicy::unlockLogin()`, Gate hỏi ba lần: `visible()`, `action()`, Action), audit
  `staff_login_unlocked`. Luật "xoá chiều IP chỉ khi NAT-an toàn" rút ra thành trait
  `App\Actions\Concerns\ClearsNatSafeIpLocks`, dùng chung với `UnlockPortalLogin`, và **thêm lọc theo
  `properties.guard`**: một dòng `login_failed` của guard kia ở cùng địa chỉ không tiêu lượt nào
  trong rổ này nên không được làm khoá IP trông như dùng chung.
- **Tải tệp: đếm TỆP, không đếm request.** `throttle:livewire-upload` (bộ đếm có tên của framework,
  một đơn vị mỗi request) thay bằng `App\Http\Middleware\ThrottleUploadedFiles` (cắm ở
  `config/livewire.php`): tăng nguyên tử `RateLimiter::increment($key, …, $soTệp)`, vượt trần thì
  HOÀN LẠI và từ chối CẢ request bằng 429 + `Retry-After` — không nhận một phần. Trước đó một request
  `files[]` 20 tệp chỉ tốn 1 suất (20 request × 20 tệp = 400 tệp/giờ lọt trần 20). Khoá cache vẫn
  `md5('livewire-upload'.$khoá)` nên `SubmitDocument::_uploadErrored()` hỏi lại được; vì lô bị từ
  chối không tăng bộ đếm, middleware còn đánh dấu lần từ chối (`UploadThrottle::markRefused()`, 60
  giây) để màn hình nộp không đọc nhầm 429 của lô thành "tệp lỗi/sóng yếu".
- **Phán quyết "có áp cho nhân sự không": 20 tệp/giờ là luật nộp tài liệu của KHÁCH; nhân sự có trần
  riêng 200 tệp/giờ/tài khoản** (`UploadThrottle::STAFF_FILES_PER_HOUR`) trên cùng endpoint — không bỏ
  trần vì mỗi POST ghi đĩa. Giá nếu sai: một phiên nhân sự bị chiếm ghi được 200 thay vì 20 tệp/giờ;
  luật sư tải > 200 trang trong một giờ gặp lời từ chối. SPEC §10.3 đã đính chính.
- **API 60/phút**: hôm nay không có route `api/*` (không `routes/api.php`, `bootstrap/app.php` không
  khai routing api) — `RateLimitSpec103Test` khẳng định, và đỏ ngay khi có route đầu tiên. **M11 R8
  đã nhận giới hạn 60 request/phút cho máy chủ MCP** (`docs/superpowers/plans/2026-09-24-m11-mcp.md`
  dòng 39, 219; không sửa kế hoạch M11).
- **§10.6 — tám loại, mỗi loại một test tên `§10.6 …`** (`tests/Feature/Security/ActivityLogSpec106
  Test.php`, đi qua Livewire/HTTP thật, khẳng định khoá sự kiện, subject, causer, IP): đăng nhập
  thành công/thất bại của CẢ hai guard; tải tài liệu (route ký thật); công bố tài liệu (nút trên tab
  Tài liệu). Ba loại trước chỉ gián tiếp nay là **sự kiện tường minh**: `stage_log_published`
  (`TransitionMatterStage`, cả chuyển giai đoạn lẫn "Thêm cập nhật"; chủ thể là dòng tiến độ nên
  `ActivityOwningMatter` lọc theo quyền xem vụ — test vụ `restricted` không lộ ra manager; properties
  chỉ id, không mã/tên vụ); `permission_changed` (`RecordStaffPermissionChange`, gọi trong transaction
  của `EditUser`, kèm chức danh + vai trò cũ → mới, không ghi khi chỉ đổi tên);
  `portal_account_created` / `portal_account_deactivated` (`CreatePortalAccount`,
  `UpdatePortalAccount` — nghiệp vụ trong Action, hai trang ClientUser chỉ gọi; mật khẩu không bao
  giờ vào properties; dòng vô hiệu hoá chỉ sinh khi `is_active` thật sự đổi true → false, đọc dưới
  khoá dòng). Dòng `updated` của `LogsActivity` vẫn đứng cạnh (chấp nhận được). Nhãn mới:
  `staff_login_unlocked` (bốn nhãn còn lại đã khai sẵn).
- **"Xuất dữ liệu"**: hôm nay chỉ MỘT đường — `documents.download` (đã ghi `document_downloaded`).
  Hai test đóng băng tập đó từ hai phía độc lập: router (mọi route tên/uri có `download|export|backup|
  archive|handover` trừ hai route Filament không phục vụ được gì vì app không có Exporter/Importer)
  và mã nguồn `app/` (chỉ `DocumentDownloadController` được gọi `->download(`/`streamDownload(`/…).
  **Mang sang M7 Task 4**: sinh/tải gói bàn giao hồ sơ phải ghi `data_exported` và mở rộng đúng hai
  test này (M7 merge SAU M8b nên chỗ nối là ở đây).
- **Giữ nhật ký (đóng minor M-8 của M6.5)**: không lên lịch `activitylog:clean` (test quét lịch), và
  `activitylog.delete_records_older_than_days` từ 365 → `RETENTION_YEARS × 366` để một lần chạy tay
  cũng không xoá được gì trong thời hạn lưu. Giá nếu sai: bảng `activity_log` lớn dần (cỡ MB mỗi năm).
- **Đo bằng mutation** (24 probe, `.superpowers/sdd/m8b/probe/t3-probes.log`): mỗi điều kiện mới bỏ đi
  đều đỏ đúng test nêu tên nó.
- **Còn lại / để lại**: giới hạn 60/phút của API là M11. Khe đua giữa quét virus và bộ đếm (M-6 của
  M6.5) giữ hoãn — nhưng bộ đếm endpoint nay là phép tăng nguyên tử nên không còn khe "đọc rồi ghi".
  Chiều IP của luật đăng nhập dựa vào `request()->ip()` nên phụ thuộc `TRUSTED_PROXIES` (Task 1,
  `vkcrm:preflight`); test đổi IP đi bằng request HTTP thật (`REMOTE_ADDR`), không phải `livewire()`.
- **Task 3, fix round 1 (review vòng 1)**:
  - *Mã ĐÚNG không tiêu chiều IP dùng chung.* Bước mã đập bộ đếm trước khi chấm (an toàn với request
    song song) nên lần đúng cũng tiêu một suất ở chiều địa chỉ, và đăng nhập không xoá chiều đó; với
    2FA bắt buộc + một địa chỉ NAT của văn phòng, người thứ sáu gõ ĐÚNG mã bị "thử quá nhiều lần" mỗi
    sáng, và nút "Mở khoá đăng nhập" không gỡ được (không có dòng `login_failed` nào để đọc). Sửa:
    `LoginThrottle::refundCodeIp()` — sau mã đúng, request tự HOÀN đúng suất nó vừa tiêu (cờ
    `codeIpHit`: đăng nhập không qua bước mã, ví dụ nhân sự chưa cài 2FA, không hoàn; `attempts > 0`
    chặn ghi -1 khi khoá hết hạn giữa chừng). Lần SAI vẫn ở lại đủ. **Cổng khách KHÔNG đổi**: test
    `LoginTest` "clears only the account dimension of the code lock…" ghim có chủ ý (M5) rằng lần
    đúng vẫn giữ suất trên chiều IP; cùng lỗi ở cổng khách nhẹ hơn (OTP qua thư, ít khi nhiều khách
    chung NAT) và thuộc phán quyết M5 — ghi lại đây để bộ điều khiển quyết. *(Đã quyết ở rà soát
    cuối, vòng sửa 1 — I1: cổng khách nay hoàn suất y như nhân sự, pin `LoginTest` đã lật; xem mục
    "Rà soát cuối M8b, vòng sửa 1" ở cuối Ghi chú này.)*
  - *Câu từ chối riêng cho nhân sự.* `App\Filament\Admin\Concerns\ExplainsStaffUploadRefusal` (gắn
    vào `DocumentsRelationManager`, ô tải tệp duy nhất của /admin) đổi 429 của trần 200 tệp/giờ thành
    `documents.errors.staff_upload_rate_limited` (số tệp + số phút chờ, không số điện thoại văn phòng);
    422 và lỗi tệp khác vẫn qua `parent::_uploadErrored()`. Trước đó Livewire chỉ báo "Tải lên
    mountedActions.0.data.file không thành công".
  - *Proxy tin cậy cho /admin/login.* Test mới: bộ đếm mật khẩu và mã đếm theo địa chỉ ở
    `X-Forwarded-For` phía sau proxy được tin; header từ địa chỉ không phải proxy bị bỏ qua; không tin
    ai thì mọi người chung khoá của proxy (chế độ hỏng mà preflight chặn); allowlist IP và bộ đếm đọc
    cùng một địa chỉ.

### Task 4 — Dữ liệu cá nhân và tệp (§10 mục 4, 5)

- **§10.5 — quét dữ liệu thật, không đọc mã bằng mắt.** `tests/Support/SensitiveDataFlows` cho các
  giá trị lính canh đi qua MÀN HÌNH thật (Livewire/HTTP/Artisan, không gọi thẳng Action): tạo khách
  hàng có CCCD gõ dấu cách; tra định danh khi mở vụ (gõ dấu chấm) rồi mở vụ có bên đối lập mang CCCD
  riêng; tải lên + tải về một tài liệu qua route ký; tạo tài khoản cổng; sửa CCCD khách (dấu gạch —
  đồng bộ `matter_parties`); thư tiến độ chạy thật qua hàng đợi `database` tới mailer `log`; một thư
  tiến độ hỏng hẳn sau 5 lượt (`failed_jobs`); cài 2FA qua trang bắt buộc, đăng nhập bằng mã TOTP và
  bằng mã khôi phục; gõ mật khẩu vào ô email (admin + cổng); "Mở khoá đăng nhập" và "Đặt lại 2FA" (màn
  hình + `vkcrm:reset-2fa`); `vkcrm:preflight` giả lập production; hai request HTTP thật đẩy phiên
  xuống bảng `sessions`. Cache/hàng đợi/phiên chạy trên `database` như máy thật (không `array`/`sync`
  như bộ test), log + thư vào MỘT tệp tạm riêng của lượt chạy.
  `tests/Support/SensitiveTraceScanner` rồi quét **mọi cột của mọi bảng** (liệt kê bằng `Schema`,
  không danh sách tay, kể cả `clients.id_number` — chỉ được chứa bản mã), tệp log của lượt chạy, và
  **một bản sao lưu THẬT** (`backup:run` có `mariadb-dump`, archive mở bằng mật khẩu, quét từng mục —
  `tests/Feature/Backup/BackupPersonalDataScanTest.php`, chạy bằng `test:dump`). Dạng tìm: chữ số
  với mọi dấu chen giữa (kể cả `%20`), `sha256`/`sha1`/`md5` trần của chữ số và của từng cách gõ,
  mật khẩu nguyên văn/mã hoá URL/băm trần, secret 2FA, mã khôi phục, `APP_KEY` — ở bốn dạng của mỗi
  văn bản (nguyên bản, quoted-printable, base64 từng dải, base64 sau khi nối dòng 76 ký tự). Máy quét
  có test đối chứng riêng: cài từng dạng vào một bảng khác nhau, đòi nó báo đủ, và đòi nó KHÔNG báo
  một HMAC có khoá.
- **RED trên nền `42dba80`**: phép quét bắt `matter_parties.id_number_hash` — `sha256` TRẦN của CCCD
  khách hàng (bản sao gần như rõ của `clients.id_number`, nằm ngoài cột đã mã hoá) và của bên đối lập
  (dạng lưu duy nhất của số của họ); cùng hai phát hiện trong bản dump của archive sao lưu. Đó là
  đúng lỗ hổng C-I4/X8 của rà soát cuối M6.5, mà X8 chỉ đóng cho nhật ký.
- **Sửa (phán quyết controller):** `Normalizer::idNumberHash()` gọi thẳng `Audit::identifierHash()`
  — HMAC-SHA256 khoá `APP_KEY`, MỘT định nghĩa cho cột so trùng lẫn `properties` của nhật ký; docblock
  hai nơi viết lại. Migration `2026_10_01_000001_rehash_matter_party_id_number_hashes`: dòng có
  `client_id` (kể cả xoá mềm) tính lại từ `clients.id_number` giải mã; dòng không có nguồn số thô
  (bên đối lập, dòng cũ tự nhận `is_our_client` không trỏ hồ sơ) thành `NULL`; một
  `clients.id_number` không giải mã được → `RuntimeException` nêu `APP_KEY`, cả lượt trong MỘT
  transaction nên không dòng nào bị đổi; `down()` trả bên khách hàng về `sha256` trần (thứ mã cũ so).
  Công thức HMAC viết thẳng trong migration (một migration là ảnh chụp lịch sử), test ghim nó bằng
  `Normalizer` hôm nay. Mọi test xung đột lợi ích (bậc `ConflictMatchTier::Hash`, R13) xanh.
- **Hệ quả phải biết: đổi `APP_KEY` giờ làm hỏng cả so trùng CCCD.** Mọi hash đã lưu thôi khớp — tầng
  số CCCD của kiểm tra xung đột lợi ích mù IM LẶNG với mọi bên nhập trước đó (test "a new key silently
  blinds the id-number tier", `RunConflictCheckTest`); bên đối lập không tính lại được. Thêm một lý do
  cho luật "không bao giờ sinh khoá mới trên dữ liệu thật"; `docs/CAI-DAT.md` (cảnh báo `APP_KEY`) đã
  ghi. Giá nếu phán quyết `NULL` sai: một bản cài trước ra mắt mất so trùng CCCD của bên đối lập đã
  nhập trước migration — nhập lại số của bên đó.
- **Vòng MariaDB thật trên CSDL làn `vk_crm_lane_m8b`** (bộ đếm: `.superpowers/sdd/m8b/probe/
  t4-check-hashes.php`, so từng dòng `matter_parties` có `client_id` với `clients.id_number` giải mã):
  `migrate:fresh --seed` → 21/21 dòng khách hàng mang HMAC đúng, 21/33 dòng bên khác có hash;
  `migrate:rollback --step=1` → 21/21 về `sha256` trần, dòng bên khác `NULL`; `migrate` → 21/21 HMAC
  đúng; `migrate:reset` (mọi `down()`) → `migrate` → `db:seed` → 21/21 HMAC đúng, 21/33 bên khác có
  hash.
- **§10.4 — mỗi điều một test tên `§10.4 …`** (đổi tên test sẵn có, không viết trùng): tệp qua ô tải
  lên của màn hình rơi vào đĩa `private` = `storage/app/private`, ngoài `public/`, tên tệp trên đĩa
  không phải tên gốc (`DocumentsRelationManagerTest`); không `storage:link` nào và không symlink nào
  dưới `public/` trỏ vào kho tệp, đo trên cây thư mục thật (`PrivateDiskTest`); **không route GET nào
  khác phát tệp** — mọi route GET có tham số + mọi route ngoài hai panel, với id tài liệu/id/uuid
  media/tên tệp/đường dẫn tương đối, dưới tài khoản mạnh nhất của đúng panel, không phản hồi nào mang
  nội dung tệp (`tests/Feature/Security/PrivateFilesSpec104Test.php`); URL ký còn tải được ở giây thứ
  300 và bị từ chối ở giây 301 (du hành thời gian); chữ ký hợp lệ + không có quyền → 404; chữ ký
  sai/thiếu/hết hạn → 403 — **ngoại lệ có chủ đích** của §10.10 (middleware `signed` trả lời trước khi
  bất kỳ bản ghi nào được đọc, nên 403 không lộ gì; test "chữ ký hết hạn trả 403 kể cả khi tài liệu
  không tồn tại" ghim điều đó); URL ký cho người A không dùng được bởi người B đang đăng nhập, kể cả
  khi B cũng có quyền (`DocumentDownloadTest`).
- **Máy chủ web — chạy THẬT, không chỉ chú thích.** `tools/deploy/verify-storage-blocked.sh` dựng
  container `nginx:stable-alpine` và `httpd:2.4-alpine` chính thức từ đúng khối 443 của hai mẫu,
  document root CỐ Ý đặt vào gốc dự án, tệp lính canh trong `storage/app/private` và `storage/logs`,
  request thật bằng `curlimages/curl` từ container thứ hai (mạng docker riêng, không map cổng, xoá hết
  khi xong). Kết quả ngày 2026-10-01 (đầu ra nguyên văn:
  `.superpowers/sdd/m8b/probe/t4-verify-storage-green.txt`):
  - nginx, mẫu nguyên vẹn: `/storage/app/private/<lính canh>` cùng các dạng `//`, `%73torage`,
    `/public/../`, `/storage/app/private/.htaccess`, `/storage/logs/<lính canh>`, `/.env`,
    `/.github/workflows/ci.yml` → 404 cả tám; **đối chứng** bỏ khối `location ^~ /storage/` → 200 KÈM
    nội dung lính canh (tệp hồ sơ lẫn log).
  - Apache, mẫu nguyên vẹn: cùng tám URL → 404; bỏ luật `/storage/` của mẫu, `AllowOverride All` →
    `.htaccess` của `storage/app/private` trả 403; **đối chứng** thêm `AllowOverride None` → 200 KÈM
    nội dung lính canh.
  - **Lỗ thật tìm được ở mẫu Apache**: luật dotfile cũ `<FilesMatch "^\.">` chỉ so TÊN TỆP cuối, nên
    với `DocumentRoot` đặt nhầm nó phát `/.github/workflows/ci.yml` (200, đo được). Đổi sang
    `RedirectMatch 404 "/\."` (so cả đường dẫn, chạy trước kiểm tra quyền và `.htaccess`); `/storage/`
    đổi từ `<LocationMatch> Require all denied` (403) sang `RedirectMatch 404 "^/storage/"`; mẫu nginx
    đổi `deny all` (403) sang `return 404` ở cả hai khối — một 403 báo cho người dò rằng ở đó có thứ
    được canh giữ; 404 là cùng mã ứng dụng trả cho mọi thứ không được phép (§10.10). Chạy lại kịch bản
    trên hai mẫu CŨ: 17 dòng FAIL (`.superpowers/sdd/m8b/probe/t4-verify-storage-red-old-templates.txt`).
- **Phạm vi của các task trước trong làn** (preflight, 2FA, mở khoá đăng nhập, đặt lại 2FA) nằm trong
  cùng phép quét: không secret 2FA, mã khôi phục, mật khẩu hay chuỗi gõ nhầm vào ô email nào ở bất kỳ
  cột, log hay mục archive nào.
- **Chưa làm / để lại**: `README.md` dẫn tới hai mẫu `tools/deploy/` và kịch bản kiểm là Task 7. Một
  mật khẩu gõ nhầm vào ô email mà TÌNH CỜ có dạng email hợp lệ vẫn được ghi nguyên văn làm "email đã
  gõ" của dòng `login_failed` (thiết kế §10.6 có từ trước: dòng thất bại kèm email đã gõ khi nó là một
  email) — các chuỗi lính canh của phép quét không có dạng email nên bị form từ chối trước khi tới bộ
  đếm hay nhật ký; trường hợp kia không được đo và không đổi ở đây.

### Task 7 — `README.md`, hướng dẫn triển khai, `vkcrm:create-admin` (§14 mục 8, R6)

- **`php artisan vkcrm:create-admin`** (`App\Console\Commands\CreateAdminCommand` →
  `App\Actions\User\CreateAdminFromConsole`) thay khối `tinker` + `User::create` của
  `docs/CAI-DAT.md`: hỏi họ tên (≤ 100), email (≤ 150, đúng dạng, chưa thuộc ai kể cả nhân sự đã
  xoá mềm — câu tiếng Việt riêng, không lỗi unique 500), mật khẩu nhập ẩn hai lần theo
  `PasswordRule::default()` (cùng luật form Nhân sự). Tạo chức danh Admin + vai trò `admin`, đang
  hoạt động, KHÔNG secret 2FA → lần đăng nhập đầu bị dẫn tới trang cài 2FA bắt buộc. Từ chối khi đã
  có admin chưa xoá mềm (kể cả bị vô hiệu hoá), nêu số lượng, trừ `--additional`. Chỉ tương tác:
  không tham số nào nhận mật khẩu, `--no-interaction` bị từ chối (phán quyết controller T7). Nhật ký
  `admin_created_via_console` (causer `null`, `via=console`, `additional`, `admins_before`). 26 test
  qua `$this->artisan(...)->expectsQuestion(...)`, kể cả đăng nhập Livewire tới trang cài 2FA; 19
  mutation probe đỏ.
- **`.env.example`**: thêm đủ mười lăm biến `BRAND_*` — bốn thông tin pháp lý là dòng trống kèm nơi
  lấy giá trị; mười một biến có mặc định là dòng CHÚ THÍCH mang đúng mặc định (một dòng `BRAND_…=`
  trống là chuỗi rỗng, không phải "dùng mặc định"). Xoá `BACKUP_DISK=s3` + khối `AWS_*` (không ai
  đọc). `tests/Feature/Deployment/EnvExampleTest.php` quét hai chiều: mọi `env()` của `config/`,
  `app/` có dòng mẫu (trừ danh sách biến framework không dùng, nhóm theo lý do), và mọi dòng mẫu được
  đọc ở đâu đó. Ba dòng Sail cũ (`WWWUSER`, `WWWGROUP`, `VITE_APP_NAME`) không ai đọc — để lại vì luật
  làn chỉ cho sửa dòng task nêu đích danh, ghi trong test là "chưa dọn"; **dọn ở M8 Task 8**.
- **Tài liệu**: `docs/CAI-DAT.md` phần "Khi đưa lên máy chủ thật" (mở đầu "Chưa làm…") thành "Cài
  lên máy chủ thật (production)" mười hai bước có thứ tự (Bước 0 hỏi chủ văn phòng → … → Bước 6
  `vkcrm:create-admin` ngay sau seed → Bước 7 `vkcrm:preflight` TRƯỚC `php artisan optimize` → …
  → Bước 11 mở cổng), cộng "Vận hành hằng ngày", "Nâng cấp lên bản mới" (M9 nối tiếp mục này),
  "Thao tác tiền và `innodb_lock_wait_timeout`", "Giới hạn đã biết", "Kiểm tra tay trước mỗi bản
  phát hành". `README.md` có mục "Triển khai lên máy chủ thật" (tóm tắt + dẫn tới CAI-DAT/SAO-LUU) và
  bảng tài khoản demo kèm secret 2FA demo. SPEC §2: đính chính danh sách extension (19 cái, đúng
  danh sách `vkcrm:preflight`; `gd` VÀNG). `docs/SAO-LUU-KHOI-PHUC.md`: câu 36 giờ, `rclone lsf |
  sort`, `APP_KEY` còn là khoá cột so trùng CCCD. Cảnh báo `APP_KEY` thêm: `APP_PREVIOUS_KEYS`
  không cứu cột so trùng CCCD (minor (4) của rà soát Task 4).
- **Minor hoãn của M8a đã đóng**: `backup.monitor` giữ khoá chống chồng lấn 60 phút — áp NGUYÊN VĂN
  `ecc1342` của `main` (routes + test) để lúc gộp hai bên giống hệt; câu "36 giờ" (docblock
  `CheckRcloneRemoteFreshness`, `config/vkcrm.php`, SAO-LUU) nói đúng là chỉ bắt được HAI đêm hỏng
  liên tiếp — chủ văn phòng giữ 36 giờ; `rclone lsf | sort`; Safari < 15.5 `worker-src` ghi ở
  "Giới hạn đã biết". Minor (7) của Task 4 (quét bản sao lưu thật chỉ chạy khi có `mariadb-dump`)
  thành mục "Kiểm tra tay trước mỗi bản phát hành".
- **`innodb_lock_wait_timeout`** (mang từ sổ M7/M9): thao tác tiền chạy lại cả transaction tối đa 3
  lần khi gặp 1020/1205/1213; mỗi lượt chờ khoá dòng tối đa `innodb_lock_wait_timeout` (MariaDB mặc
  định 50 giây) nên có thể ~150 giây, trong khi nginx chỉ chờ PHP-FPM 60 giây — người dùng thấy 504
  trong khi PHP vẫn chạy và có thể vẫn ghi. Khuyến nghị VPS `innodb_lock_wait_timeout = 15` (3 × 15 <
  60); shared hosting: dặn kế toán kiểm danh sách khoản thu trước khi nhập lại sau một 504. Mục này
  nhắc thao tác tiền của M9 — chúng có trên `main`, chưa có trong nền làn này.
- **Một lượt cài thật theo đúng chữ của tài liệu** (brief mục 5; kịch bản
  `.superpowers/sdd/m8b/probe/t7-walkthrough.sh`, đầu ra `t7-walkthrough-final.txt`): clone mới từ
  GitHub (nhánh `m8b-security`), `mariadb:11` + `webdevops/php:8.3-alpine` (PHP-FPM) + nginx dựng từ
  chính `tools/deploy/nginx.conf.example` với chứng chỉ tự ký ở đúng đường dẫn Let's Encrypt của
  mẫu, mạng Docker riêng, không map cổng. Kết quả: `composer install --no-dev` chép tài sản Filament
  vào `public/`; `migrate --force` + `db:seed --force` → 0 người dùng, 5 vai trò, 6 loại vụ việc;
  `vkcrm:create-admin` tạo đúng một admin chưa có 2FA, chạy lần hai bị từ chối ("đã có 1 quản trị
  viên"), `--no-interaction` bị từ chối; `vkcrm:preflight` ĐỎ đúng một dòng `HEARTBEAT_URL` (để trống
  có chủ đích), VÀNG bốn `BRAND_*` pháp lý, XANH mọi dòng khác — kể cả "storage/app/private không
  phục vụ công khai được" đo bằng request thật qua nginx; điền `HEARTBEAT_URL` → không còn ĐỎ;
  `php artisan optimize` cache config/route/view/sự kiện/Filament không lỗi; `schedule:run` ghi
  `last_schedule_run_at`; qua nginx: http → 301 https, `/storage/app/private/`, `/storage/logs/…`,
  `/.env`, `/.git/config`, `/composer.json` → 404, script Livewire 200; **đăng nhập HTTP thật** (CSRF
  + snapshot Livewire + POST cập nhật) → `GET /admin` 302 tới
  `/admin/multi-factor-authentication/set-up` → 200 "Cài đặt Bảo mật 2 bước (2FA)", có HSTS + CSP;
  khối lệnh nâng cấp: `down` → 503, … → `up` → 200. Bốn chỗ môi trường container buộc khác tài liệu
  (MariaDB và PHP-FPM ở container riêng: `'vk_crm'@'%'`, `DB_HOST`, `fastcgi_pass` TCP; chown cả mã
  nguồn vì `docker cp` chép vào với chủ `root`) in rõ `[KHÁC TÀI LIỆU]` trong đầu ra.
  **Hai lỗi tài liệu/mẫu tìm được và đã sửa**: (1) mẫu nginx — location tệp tĩnh có `add_header`
  riêng nên bỏ mất HSTS của server block (`/favicon.ico` không có `Strict-Transport-Security`); lặp
  lại dòng HSTS trong location đó, `tools/deploy/verify-storage-blocked.sh` chạy lại: mọi dòng PASS;
  (2) `chown storage bootstrap/cache` phải chạy SAU mỗi `composer install` (cả khi nâng cấp), vì lệnh
  artisan composer tự gọi tạo tệp mang chủ là người chạy composer.
- **Kiểm chứng**: cả bộ `test --parallel --processes=2` → 2602 passed, 7 skipped, 0 failed (1020 s;
  Task 4 là 2569/7); bốn tệp test chạm tới trên MariaDB thật (`test:mariadb`) → 41 passed; `pint
  --test` sạch 638 tệp.
- **Vòng sửa 1 (rà soát Task 7, Important I1) — dữ liệu mẫu trên máy chủ thật.** Bước 5 cũ cho chạy
  `db:seed --class=DemoDataSeeder --force` trên production mà không nói đó là trust-on-first-use:
  tám tài khoản nhân sự demo (`admin@luatvukhang.com` + bảy tài khoản `StaffSeeder`) mật khẩu
  `password`, chưa có 2FA, nên ai đăng nhập trước là người gắn app xác thực của mình — trên tên miền
  mở là chiếm trọn quyền quản trị; và không có đường sang dùng thật (Bước 6 từ chối vì "đã có 1 quản
  trị viên"). Nay Bước 5 nêu đích danh tám tài khoản và rủi ro, BẮT BUỘC `ADMIN_IP_ALLOWLIST` (kiểm
  `/admin/login` = `404` từ mạng ngoài) trước lệnh seed demo, khuyên bản cài riêng (vẫn allowlist),
  và cho chuỗi "Hết demo, chuyển sang dùng thật" chạy TRƯỚC Bước 6: `migrate:fresh --force` →
  `db:seed --force` → `rm -rf storage/app/private/[0-9]*` (tệp tài liệu demo — `migrate:fresh` không
  xoá tệp, và id media bắt đầu lại từ 1) → `vkcrm:create-admin`; Bước 6 dặn đừng dùng
  `--additional` trên CSDL demo. Test `tests/Feature/Deployment/InstallGuideDemoDataTest.php` (2):
  đọc danh sách email từ chính `DemoDataSeeder` chạy ở `production` và đòi tài liệu nêu từng cái;
  trích khối lệnh của tài liệu và CHẠY đúng như viết trên một CSDL demo, so số dòng MỌI bảng với một
  máy chủ chưa từng demo, cộng tệp còn lại trên đĩa. RED 2 failed; 11 mutation probe đỏ (6 trên tài
  liệu, 5 "oracle" mà chỉ phần chạy thật bắt được); cả bộ 2604 passed, 7 skipped, 0 failed (1123 s);
  `test:mariadb` hai tệp Deployment → 28 passed; `pint --test` sạch 639 tệp. Tài liệu là chốt chặn
  duy nhất — chưa có gì trong mã ngăn seed demo ở production khi allowlist trống (đề xuất một dòng
  `vkcrm:preflight` ĐỎ cho Task 8).
- **Chưa làm / mang sang M8 Task 8**: nghiệm thu R6 bằng một agent CHƯA đọc repo (làn này không
  dispatch agent — phán quyết controller T7); dọn ba dòng Sail cũ của `.env.example`. Lúc gộp với
  `main`: giữ phần M9 của `docs/CAI-DAT.md` (12 loại vụ việc, luật mới của `ChecklistTemplateSeeder`,
  khối "Bản cập nhật M9 làm gì trên máy chủ đã có dữ liệu", đoạn "Trên Windows, đừng gọi thẳng
  `php artisan test`") — chúng nằm ở mục seed mà bản này đã chuyển thành "Bước 5" của phần
  production. Quan sát ngoài phạm vi: `config/database.php` không đặt `dump.useSingleTransaction`,
  nên `mariadb-dump` của sao lưu 02:00 chạy với khoá bảng mặc định thay vì một transaction nhất quán
  không khoá (sổ M8a là chủ của sao lưu).
- **Rà soát cuối M8b, vòng sửa 1** (bốn Important của lượt rà soát toàn làn, `2bb3626` → vòng này):
  - *I1 — cổng khách hoàn suất địa chỉ của lần OTP ĐÚNG, y như nhân sự.* Hai trang đăng nhập dùng
    chung `LoginThrottle` nhưng chỉ trang nhân sự hoàn suất (`refundCodeIp()`, T3 C1); cổng khách
    giữ hành vi M5, nên năm khách gõ ĐÚNG OTP trên wifi văn phòng trong 15 phút khoá khách thứ sáu,
    và nút mở khoá báo "đăng nhập lại được ngay" vì khách ấy không có dòng `login_failed` nào. Sửa:
    `App\Filament\Portal\Pages\Auth\Login` thêm cờ `$codeIpHit` + hoàn sau lần vào được, cùng khuôn
    trang nhân sự. Pin `LoginTest` "clears only the account dimension of the code lock…" đã lật (chiều
    IP còn 4 lần SAI, không phải 5); test mới "lets six clients behind one shared address…" và
    "refunds nothing when a client sign-in never reached the code step…" (tắt bước mã của panel ngay
    trong test để dựng một lần vào không đập khoá — ngày nay OTP bắt buộc nên mọi lần vào đều qua
    bước mã). Không có Ruling nào cấm: Ruling (T3) "tests must stay green unchanged" nói về phép
    tổng quát hoá, không phải về đổi hành vi sau đó.
  - *I2 — nút "Mở khoá đăng nhập" không hứa suông.* Lần thử bị cổng chặn không ghi dòng nào, nên
    người bị khoá CHỈ vì lần hỏng của đồng nghiệp/người khác cùng NAT không có dòng của riêng mình và
    `ClearsNatSafeIpLocks` trả "không còn gì khoá". Nay trait hỏi thêm, SAU khi đã xoá khoá NAT-an
    toàn: còn khoá địa chỉ nào (cả hai bước) của MỌI địa chỉ có trong nhật ký `login_failed` của guard
    đó trong cửa sổ 15 phút đang chạm trần không; kết quả mang `anyAddressLockedMinutes`, và hai
    trang chỉ hứa "đăng nhập lại được ngay" khi không còn khoá nào — ngược lại câu mới
    `users.actions.unlock_login.success_other_address_locked` /
    `client_users.actions.unlock_login_success_other_address_locked` (có số phút, gợi ý 4G). Không
    ghi thêm dòng nhật ký cho lần bị chặn (một kẻ dò sẽ thổi phồng `activity_log` theo từng request).
    Ngoại lệ còn lại, ghi trong docblock trait: ô mã cổng khách gửi TRỐNG bằng request sửa tay đập
    khoá địa chỉ mà không ghi dòng nào (trình duyệt chặn vì ô mang `required`).
  - *I4 — `vkcrm:preflight` chặn trust-on-first-use của dữ liệu demo.* Dòng mới `demo_accounts`: ĐỎ
    khi một tài khoản trong `DemoDataSeeder::staffEmails()` (quản trị demo + `StaffSeeder::roster()`,
    tám email) còn mật khẩu mẫu (`Hash::check`) mà `ADMIN_IP_ALLOWLIST` trống; VÀNG khi có allowlist;
    XANH khi không còn. Hỏi mật khẩu chứ không chỉ email vì văn phòng có quyền tạo quản trị viên thật
    bằng đúng `admin@luatvukhang.com` (test riêng: `vkcrm:create-admin` với địa chỉ đó → XANH).
    Hằng mới `DemoAccountsSeeder::ADMIN_EMAIL`, `DEMO_PASSWORD`. `docs/CAI-DAT.md` Bước 5 và Bước 7
    nói dòng này. Test chạy CHÍNH hai lệnh seed của tài liệu ở `production`.
  - *I3 — Lúc gộp với `main` (đo bằng `git merge-tree` HEAD↔`origin/main` `257291b`, 74 commit
    trước làn)*: chín tệp xung đột — `.env.example`, `CreateClientUser.php`, `EditClientUser.php`,
    `AdminPanelProvider.php`, `docs/CAI-DAT.md`, `docs/PROGRESS.md`, `lang/vi/activity.php`,
    `routes/console.php`, `tools/csp/survey.cjs`.
    - `.env.example`: một hunk duy nhất ở cuối tệp — khối HTTPS/HSTS/allowlist + khối `BRAND_*` của
      làn đối đầu khối "Nhận diện thương hiệu…" của `main` (mặc định BỎ chú thích, bốn thông tin pháp
      lý chú thích). **Giữ ĐÚNG MỘT khối `BRAND_*` — của làn** (đủ 15 biến, mỗi biến có giải thích,
      `CAI-DAT` Bước 3 trỏ vào nó); phpdotenv lấy dòng ĐẦU khi trùng, nên giữ cả hai thì người vận
      hành sửa dòng thứ hai mà không có gì đổi. Test mới của `EnvExampleTest` ("mỗi biến chỉ có đúng
      một dòng mẫu…") đỏ đúng 15 biến `BRAND_*` nếu giữ cả hai (probe: nối khối của `main` vào cuối).
      Giữ việc làn đã XOÁ `BACKUP_DISK` và khối `AWS_*` (`main` còn) — test "không biến chết" và
      "ngoại lệ thừa" đỏ nếu chúng quay lại. Lưu ý chú thích của `main`: CI chạy `cp .env.example
      .env`, nên bốn dòng pháp lý để TRỐNG của làn tồn tại dưới dạng chuỗi rỗng (BrandFooter và
      preflight coi trống = thiếu, nên giống nhau) — chạy cả bộ sau khi gộp đúng như CI làm. Đo ở
      `257291b`: mọi `env()` mà `config/`/`app/` của `main` đọc đều đã có dòng mẫu trong
      `.env.example` của làn (không thiếu biến nào); merge sau có `env()` mới thì `EnvExampleTest`
      đỏ cho tới khi thêm dòng mẫu.
    - `EditClientUser.php`/`CreateClientUser.php`: `main` (M6-rest) thêm nút "Gửi lại thư kích
      hoạt" (`reissueAccessAction`) và `afterSave()`/`afterCreate()` gọi `IssuePortalAccess`; làn
      thêm nút "Mở khoá đăng nhập" (nay với nhánh `anyAddressLockedMinutes`) và
      `handleRecordUpdate()`/`handleRecordCreation()` gọi Action của làn (`UpdatePortalAccount`…).
      Giữ CẢ HAI phía: hai nút trong `getHeaderActions()`, `handleRecord*()` của làn, và
      `IssuePortalAccess` vẫn ở `after*()` — tức sau khi `handleRecord*()` đã trả về, không lồng vào
      bên trong nó (thư sau commit, R2).
    - **Lúc gộp M10 (`origin/m10-intake`)**: `tests/Unit/Support/NormalizerTest.php:110` của nhánh
      đó ghim `hash('sha256', …)` trần. Bỏ pin đó, giữ bản HMAC của làn (`Normalizer::idNumberHash()`
      = `Audit::identifierHash()`, khoá `APP_KEY`) — TUYỆT ĐỐI không "sửa" bằng cách đưa
      `idNumberHash` về `sha256` trần (dò ngược được CCCD 12 số bằng vét cạn). Và thêm một lượt nộp
      phiếu tiếp nhận (`intake_requests.contact_id_number_hash`, `intake_parties.id_number_hash`)
      vào `tests/Support/SensitiveDataFlows::run()` để phép quét §10.5 có dữ liệu ở bảng tiếp nhận.
  - *Bằng chứng chạy vòng này*: RED trước mỗi sửa (I1 2 failed, I2 2 failed, I4 4 failed, I3 1
    failed — `probe/final-fix1-red-*.txt`); 13 mutation probe đỏ đúng test (I1 2, I2 4, I4 5, I3 1,
    cộng pin lật của I1 — `probe/final-fix1-probe*.txt`); cả bộ `test --parallel --processes=2` →
    **2613 passed, 7 skipped, 0 failed** (11489 assertions, 1094 s; mốc trước 2604 — chênh +9 khớp
    số test mới 2+3+3+1); `test:mariadb` năm tệp đụng tới → 191 passed; `pint --test` sạch 639 tệp.
    Chi tiết ở `.superpowers/sdd/m8b/final-fix-report.md`, mục "## Fix round 1".

## Ghi chú M7

Worktree `D:\vkwt\lane-m7`, nhánh `m7-handover`, cắt từ `origin/m6-5-lane-d` @ `d2de674` (M6.5 đã
nghiệm thu + đợt sửa cuối). Kế hoạch: `docs/superpowers/plans/2026-09-21-m7-handover-and-archive.md`.
Sổ làn: `.superpowers/sdd/m7/progress.md`.

### Làn m7b (Task 10, 8, 9 — chạy song song với làn m7)

Worktree `D:\vkwt\lane-m7b`, nhánh `m7-extras`, cắt từ `m7-handover` @ `985a5c4` (Task 1–5 xong), để
M11 có sớm bảng `settings` và nhật ký liên lạc. Sổ làn: `.superpowers/sdd/m7b/progress.md`. Controller
gộp nhánh này vào `m7-handover` trước Task 11. Mục này đặt ở ĐẦU "Ghi chú M7" (không ở cuối) để lần
gộp không đụng các ghi chú Task 6, 7 mà làn m7 viết thêm ở cuối.

#### Task 10 — Thông tin văn phòng sửa được trong app

Chủ văn phòng quyết ngày 2026-09-24 sẽ tự nhập bốn thông tin pháp lý trong app. Trước task này mọi
thông tin thương hiệu chỉ đổi được qua `.env`.

- **Tên giữ cho M11** (kế hoạch M11 dòng 50, 97):
  - bảng `settings` (migration `2026_09_28_071000_create_settings_table`): `key` `string(100)`
    unique, `value` `text` NULL (NULL = chưa đặt), `updated_by` FK `users` NULL, timestamps. Bảng
    khoá–giá trị CHUNG; khoá văn phòng có tiền tố `office.`;
  - model `App\Models\Setting` — không `LogsActivity` (Action của tính năng ghi audit có cấu trúc),
    miễn trừ ranh giới cổng có lý do ở `PortalCoverageTest` (cổng phải đọc được hotline);
  - **bước ghi chung** `App\Actions\Settings\WriteSettings::handle(array<string, ?string> $values,
    ?User $actor): list<string>` — câu đầu là `lockForUpdate()` mọi khoá của lần ghi; khoá chưa có
    dòng thì `insertOrIgnore` một dòng NULL rồi khoá-đọc lại; so cũ/mới dưới khoá, `save()` từng dòng đổi (kèm
    `updated_by`), trả khoá đã đổi. Không hỏi quyền, không audit. Chuỗi rỗng/khoảng trắng = NULL;
    `'0'` là một giá trị; giá trị không phải chuỗi bị từ chối (`(string) false` là `''`); khoá rỗng
    hay dài hơn 100 ký tự bị từ chối trước khi chạm DB;
  - **Cho M11:** Action công tắc MCP của M11 gọi `WriteSettings` với
    `['mcp.enabled' => '1'|'0', 'mcp.write_enabled' => '1'|'0']`, tự hỏi `settings.manage`, tự ghi
    `ai_access_changed` khi danh sách trả về không rỗng. Middleware `EnsureMcpAccess` đọc
    `Setting::query()->where('key', 'mcp.enabled')->value('value') === '1'` ở MỖI request (không
    cache; dòng vắng mặt hay NULL = tắt, đúng "mặc định tắt");
  - service đọc `App\Support\OfficeProfile`; Action lưu `App\Actions\Settings\UpdateOfficeProfile`;
    trang `App\Filament\Admin\Pages\OfficeProfilePage` (slug `office-profile`).
- **Chín trường** = bốn thông tin pháp lý (`tax_code`, `bar_association`, `licence_number`,
  `office_address`) + `legal_name`, `hotline`, `zalo`, `website`, `reply_to` ("email liên hệ" =
  Reply-To của mọi thư). Giới hạn ký tự ở MỘT chỗ, `OfficeProfile::FIELDS` (255; `tax_code` 14;
  `licence_number` 100; `office_address` 500; `hotline` 20 cho ô nhập; `reply_to` 254), dùng cho cả
  `maxLength()` của form lẫn luật `max:` của Action. Cột `text` chứa xa hơn mọi giới hạn đó.
- **Thứ tự đọc:** `settings` (giá trị không rỗng) → `config('vkcrm.brand.*')`. **Trống = dùng cấu
  hình**: form mở với giá trị ĐÃ LƯU (ô chưa lưu để trống, placeholder nói giá trị `.env` đang dùng)
  — điền sẵn giá trị cấu hình sẽ khiến lần Lưu đầu chép `.env` vào bảng. Muốn một trường TRỐNG hẳn:
  đặt biến RỖNG trong `.env` (`BRAND_HOTLINE=`; xoá cả dòng thì mặc định của `config/vkcrm.php` quay
  lại) rồi để trống ô (ghi ở docblock `OfficeProfile`).
- **Không cache vượt một lần render:** mỗi `OfficeProfile::current()` là một đối tượng mới, đọc bảng
  MỘT lần (một truy vấn cho cả chín trường) khi được hỏi lần đầu. Không `static`, không
  `singleton`/`scoped`. Thư đang nằm trong hàng đợi dùng giá trị ở LÚC RENDER: mẫu thư đọc service
  trong `content()`/`toMail()`/layout, không chụp vào thuộc tính (test: một `StageUpdate` tuần tự
  hoá trước lần lưu, gửi sau, mang giá trị mới).
- **Kiểm tra đầu vào (Action, lần nữa sau form):** mã số thuế bỏ khoảng trắng, 13 chữ số liền viết
  lại thành `0123456789-001`, rồi phải là `^\d{10}(-\d{3})?$`; hotline: đầu số dịch vụ
  `1900`/`1800` được nhận ra TRƯỚC `Normalizer::phone()` (sau khi bỏ khoảng trắng, chấm, gạch,
  ngoặc), phải đủ 8 hoặc 10 chữ số và LƯU nguyên các chữ số (`1900 6557` → `19006557`); số khác
  qua `Normalizer::phone()` phải ra `84` + phần quốc gia 9…10 chữ số bắt đầu bằng 2…9 (không số
  thuê bao nào còn phần quốc gia 8 chữ số hay đầu 1), LƯU theo cách viết trong nước (`0` + phần
  quốc gia — cùng dạng mặc định `0832270898`, vì số này in nguyên văn cho khách đọc và nằm trong
  `tel:`) (vòng sửa 1: trước đó `1900 6557` bị lưu thành số không tồn tại `019006557`); Zalo/website
  `url:http,https`; email liên hệ `email`. Lỗi của Action gắn vào đúng ô trên form (`data.<trường>`).
  Khoá vắng mặt trong đầu vào thì giữ nguyên; khoá ngoài chín trường bị bỏ qua.
- **Audit:** `office_profile_updated` (nhãn trong `lang/vi/activity.php`), `changed_fields` = tên
  các trường đã đổi, không giá trị, không chủ thể; không ghi khi không có gì đổi.
- **Cổng 404:** `canAccess()` = `Gate::forUser($user)->allows('settings.manage')`. `boot()` của trang
  `abort(404)` — Livewire gọi `boot()` ở đầu cả mount lẫn mọi request cập nhật, TRƯỚC
  `hydrateCanAuthorizeAccess()` của Filament (hook đó trả 403). `save()` hỏi lại; Action hỏi lần nữa.
- **Nơi đọc đã chuyển sang `OfficeProfile`:** layout thư HTML + văn bản, `BrandFooter` (nhận đối
  tượng của người gọi để cả chân thư từ một lần đọc), `BrandedMailable::replyToAddress()`,
  `StageUpdate`, `DeadlineReminder`, `MatterReassigned`, `HandoverPackageReady`, `SendLoginCode`,
  `RenderHandoverIndex` (`MUC-LUC.pdf`), chân trang đăng nhập, trang lỗi 403/404, `MatterProgress`,
  `MyMatters` (CHỈ trong nhánh "chưa có hồ sơ" — ngân sách truy vấn "phần cố định đúng bằng 6" của
  `MyMattersTest` không đổi), `SubmitDocument` (trang + view), `MatterClosedForSubmission`, `Login`,
  `EnsurePortalAccountIsActive`. Test cấu trúc (`tests/Feature/Support/OfficeProfileTest.php`) quét
  `app/`, `resources/views/`, `routes/` bằng `scandir()` (ổ 9p) và đỏ ở bất kỳ
  `vkcrm.brand.<một trong chín trường>`, `'vkcrm.brand'` hay `'vkcrm'` nguyên khối nào ngoài
  `OfficeProfile`; thêm một test: không màn hình cổng nào đọc thẳng `Setting`.
- **`alt` của logo** (`brand/logo.blade.php`) là ba dòng lockup ghép lại, không phải tên pháp lý:
  `alt` mô tả hình (không sửa được trong app), trùng từng chữ với tên pháp lý mặc định, và đọc tên
  pháp lý ở đó sẽ thêm một truy vấn vào MỌI trang của hai panel.
- **Để trống:** chân thư HTML/văn bản bỏ hẳn dòng tên pháp lý, hotline, website khi trống ở cả hai
  nơi (trước đây in nhãn treo nếu `.env` đặt rỗng); bốn thông tin pháp lý vẫn qua `BrandFooter`;
  `MUC-LUC.pdf` bỏ dòng tên văn phòng ở đầu trang và chân trang khi trống.
- **Việc cho lần gộp `main` (M6 phần còn lại):** các mẫu thư `main` thêm sau `985a5c4` vẫn đọc
  `config('vkcrm.brand.legal_name')`/`hotline`: `Mail/Client/{Activation, DocumentPublished,
  DocumentRejected, MissingDocuments, RequestAnswered}`, `Mail/Staff/{BackupAlert,
  InstalmentOverdue, NewClientDocument, NewClientRequest, StaleMatterReminder}`. Test cấu trúc sẽ
  đỏ và nêu đích danh từng dòng; sửa mỗi `content()` thành `$office = OfficeProfile::current();` rồi
  `$office->legalName()`/`$office->hotline()`, như `StageUpdate`.
- **M8 Task 7 (làn M8b):** cảnh báo "bốn thông tin pháp lý còn trống" của `vkcrm:preflight` đọc
  `OfficeProfile::current()->taxCode()` (…), không đọc `config('vkcrm.brand.*')` — sau khi gộp, test
  cấu trúc bắt chỗ đọc cấu hình trực tiếp.
- **Giới hạn đã biết:** màn hình cổng (chân trang đăng nhập, trang lỗi 403/404, `MatterProgress`,
  `MyMatters`) chưa bỏ nút/liên kết `tel:` khi hotline trống ở CẢ hai nơi — như trước Task 10;
  chỉ xảy ra khi `.env` đặt `BRAND_HOTLINE=` rỗng VÀ trang để trống ô, vì mặc định của
  `config/vkcrm.php` có số.
- Test: `tests/Feature/Support/OfficeProfileTest.php` (service + cấu trúc),
  `tests/Feature/Actions/Settings/{UpdateOfficeProfileTest, WriteSettingsTest}.php`,
  `tests/Feature/Filament/OfficeProfilePageTest.php` (màn hình qua Livewire/HTTP: 404 cả request
  cập nhật Livewire thật; lưu xong thì thư khách, ba thư nhân sự (`DeadlineReminder`,
  `MatterReassigned`, `HandoverPackageReady` — khẳng định cả tên CŨ không còn, vì chân thư một mình
  đã đủ làm "có tên mới" xanh), thư OTP, `MUC-LUC.pdf`, chân trang đăng nhập,
  trang 404, trạng thái trống của cổng mang giá trị mới; để trống thì bỏ dòng); `PortalCoverageTest`
  thêm `Setting` vào danh sách miễn trừ; `SenderIdentityTest` sửa docblock (chuỗi rỗng bị lọc ở hai
  tầng, câu "bỏ `filled()` làm test này đỏ" của bản trước sai với ca đó).

#### Task 8 — Nhật ký liên lạc và nhật ký riêng của vụ việc

- **Tên giữ cho M11** (kế hoạch M11 dòng 49, 318):
  - `CommunicationLogPolicy::create(User|ClientUser $user, ?Matter $matter = null)` — khách luôn
    `false`; nhân sự không nêu vụ việc: `false`; nêu vụ: đúng `MatterPolicy::update` (chưa xoá
    mềm + `matter.update` + xem được vụ) qua `ChecksMatterAccess::canUpdateMatter()`. Gọi bằng
    `Gate::forUser($actor)->…('create', [CommunicationLog::class, $matter])`;
  - **Action ghi** `App\Actions\Communication\LogCommunication::handle(Matter $matter, User $actor,
    CommunicationType $type, string $summary, ?string $counterpart = null, string|CarbonInterface|null
    $occurredAt = null, ?int $durationMinutes = null): CommunicationLog`. Câu đầu khoá dòng `matters`;
    hỏi cổng trên vụ ĐỌC LẠI dưới khoá; mọi lý do từ chối ra một câu (`communications.unavailable`,
    `AuthorizationException`); kiểm tra đầu vào chạy SAU cổng (`ValidationException` gắn khoá
    `summary`/`counterpart`/`occurred_at`/`duration_minutes`). M11 thêm cột `created_via` và truyền
    nó vào Action này;
  - Action xoá `App\Actions\Communication\DeleteCommunicationLog::handle(CommunicationLog $log, User
    $actor, string $reason)` — khoá `matters` rồi `communication_logs`, lý do bắt buộc (≤ 1000 ký tự),
    audit TRƯỚC lệnh xoá mềm trong cùng transaction. Không `forceDelete()` ở đâu cả.
- **Luật đầu vào (Action):** `summary` bắt buộc sau `trim`, ≤ 16.383 ký tự (`TEXT` 65.535 byte ÷ 4
  byte utf8mb4 — trần bảo đảm vừa trên MariaDB strict); `counterpart` trống = tên khách của vụ, ≤ 200;
  `occurred_at` trống = bây giờ, không được ở tương lai quá 60 giây (nhật ký ghi điều đã xảy ra);
  `duration_minutes` 0…65.535 hoặc trống; `is_visible_to_client` luôn `false` — không phải tham số.
- **Policy siết:** `update()`/`delete()` = dòng chưa xoá mềm VÀ điều kiện của `create()` trên vụ của
  dòng (bản trước mở cho bất kỳ ai xem được vụ); `forceDelete()` luôn `false`. `view()` không đổi
  (d069424). `ChildPolicyTest` đổi một dòng: `can('create', [CommunicationLog::class, $matter])`.
- **Tab "Liên lạc"** (`CommunicationLogsRelationManager`, quan hệ `communicationLogs`): một hàng nút
  chọn kênh (`ToggleButtons` inline, không mặc định — một lần chạm) + một ô nội dung; khối thu gọn
  "Thời điểm, người liên lạc, thời lượng" đã điền sẵn bây giờ và tên khách; người ghi là người đăng
  nhập (không có ô); không có ô `is_visible_to_client`; không nút "Sửa"; "Xoá" có ô lý do. Cổng tab =
  `MatterPolicy::view`; nút ghi = `create` với vụ; nút xoá = `delete`. Bảng mới nhất trước, hiện
  người ghi kể cả tài khoản đã xoá mềm.
- **Tab "Nhật ký"** (`MatterActivityRelationManager`): ability mới `MatterPolicy::viewActivityLog` =
  `view()` VÀ (`auditLog.view` — admin, trưởng phòng — HOẶC `lead_lawyer_id` của chính vụ). Ẩn tab
  (`canViewForRecord`), 404 ở mount (`booted()`) và ở mọi request cập nhật Livewire (`hydrate()` —
  chạy TRƯỚC `hydrateCanAuthorizeAccess()` 403 của Filament), modal hỏi lại. Dòng = luật
  `ActivityOwningMatter` qua `scopeOwnedBy($query, $matter)`; `scopeVisibleTo()` nay dùng chung câu
  SQL `whereOwnedByAny()` với nó (một định nghĩa). Modal qua `SensitivePropertyFilter`; nhãn sự kiện
  qua `lang/vi/activity.php`.
- **Morph map** thêm `communication_log`; `ActivityOwningMatter::MATTER_OWNED` thêm
  `communication_log => communication_logs` (test: dòng audit nhật ký liên lạc của vụ `restricted`
  không lên trang Nhật ký hệ thống của trưởng phòng ngoài vụ). Hai khoá audit mới
  `communication_logged` (kênh, thời điểm — KHÔNG nội dung, KHÔNG tên người liên lạc) và
  `communication_log_deleted` (thêm lý do). Chuỗi mới ở `lang/vi/communications.php`.
- **Thứ tự tab:** Liên lạc nối sau Mốc thời hạn, Nhật ký cuối cùng. SPEC đặt Mốc thời hạn và Liên lạc
  trước "Yêu cầu từ khách"; không đảo dòng của M5/M6 để lần gộp các làn không đụng nhau (ghi ở
  docblock `MatterResource::getRelations()`).
- **Giới hạn đã biết:** trên một vụ đã xoá mềm, tab Liên lạc (như mọi tab con dùng
  `ScopesToVisibleMatters`) hiện rỗng với admin — `whereHas('matter')` loại vụ đã xoá; dữ liệu còn
  nguyên. Dòng `conflict_check_run` trong tab Nhật ký mang mã hồ sơ trùng — đúng ranh giới
  `ConflictMatch` mà SPEC §6.10 cho người chạy kiểm tra thấy; tab mở ranh giới đó thêm cho luật sư
  phụ trách của vụ (trước chỉ admin/trưởng phòng ở trang hệ thống).
- Đính chính SPEC §4.17 (không công tắc, bằng chứng, cổng ghi).
- Test: `tests/Feature/Filament/{CommunicationLogsRelationManagerTest, MatterActivityRelationManagerTest}.php`
  (Livewire/HTTP), `tests/Feature/Actions/Communication/{LogCommunicationTest, DeleteCommunicationLogTest}.php`,
  `tests/Feature/Authorization/CommunicationLogPolicyTest.php`. Test M5 về cổng khách
  (`PortalIsolationSweepTest`, `PortalVisibilityTest`) xanh nguyên.
- **Bằng chứng TDD.** Phiên đầu bị dừng sau commit `de05774` mà không để lại nhật ký RED; phiên tiếp
  dựng lại RED bằng cách đưa mã `app/` và `lang/` của Task 8 về base `579aaa8` (giữ nguyên test):
  44 bài đỏ, 3 bài của `CommunicationLogPolicyTest` xanh ngay trên base vì chúng đo điều kiện đã có
  (`matter.update`, khách bị từ chối). 58 mutation probe: 51 đỏ, 7 xanh và là đột biến tương đương —
  `create()` bỏ `instanceof User` (`MatterPolicy::update` đã từ chối khách); `LogCommunication` bỏ
  `$fresh === null` hoặc đọc vụ bằng `withTrashed()` (cổng `create($user, null)` và
  `MatterPolicy::update` đều trả `false`); modal "Xem chi tiết" bỏ `abort_unless` hoặc `authorize`
  (mọi request chạm tới modal đã qua `hydrate()` cùng câu hỏi); bảng Liên lạc bỏ
  `scopeToVisibleMatters()` (quan hệ đã khoá vào vụ chủ, và tab chỉ có cho người xem được vụ — chỉ
  khác ở vụ đã xoá mềm, tức giới hạn đã biết ở trên); `using()` bỏ nhánh `type` rỗng (`->required()`
  của form chặn trước). Phiên tiếp thêm hai ca: người ghi đã bị xoá mềm vẫn hiện tên (bảo vệ
  `author` `withTrashed()`), và ép khoá một dòng nhật ký của vụ `restricted` khác vào "Xem chi tiết"
  không mở được modal (kèm vế dương), cộng ba chỗ docblock cũ (danh sách `MATTER_OWNED`, tab "Đội
  ngũ" trong thứ tự tab, `booted()` cũng chạy ở request cập nhật).
- **Ghi chú lúc gộp main** (đo bằng `git merge-tree origin/main m7-extras` ngày 2026-10-03, main @
  `88b6044`; làn không gộp gì):
  - `ActivityOwningMatter`: main (gộp M9) thêm `MONEY_OWNED` và cổng `seesMoney($viewer)` vào
    `scopeVisibleTo()`; Task 8 tách ba bước đầu của nó thành `whereOwnedByAny()`, dùng chung với
    `scopeOwnedBy()` của tab "Nhật ký". `scopeOwnedBy()` KHÔNG nhận người xem, nên khi gộp **không
    được** đưa nhánh `MONEY_OWNED` vào `whereOwnedByAny()` vô điều kiện: luật sư phụ trách không có
    `billing.view` sẽ thấy dòng hợp đồng/khoản thu trong tab. Hoặc thêm `User $viewer` cho
    `scopeOwnedBy()` và áp đúng `seesMoney()`, hoặc để dòng tiền ngoài tab (chúng nằm trong
    `matterOwnedTypes()`, nên nhánh `properties.matter_id` cũng không thả chúng ra) — kèm một test
    cho luật sư phụ trách không có `billing.view`.
  - `MatterResource::getRelations()`: main thêm `BillingRelationManager` (M9) vào cuối; "Nhật ký"
    phải vẫn đứng cuối cùng (SPEC §7.2). `AppServiceProvider`: xung đột ở `use` và khối listener —
    giữ cả hai bên; morph map giữ `communication_log` cạnh các khoá của M9.
  - Địa chỉ trụ sở: main `88b6044` đặt mặc định `vkcrm.brand.office_address` = "1808 đường Nguyễn
    Ái Quốc, phường Trấn Biên, thành phố Đồng Nai" (chủ văn phòng đưa ngày 2026-10-02).
    `config/vkcrm.php` và `.env.example` xung đột với Task 10 (cả hai sửa cùng docblock/khối
    `BRAND_*`): giữ dòng mặc định của main VÀ câu "giá trị nhập trong app thắng" của Task 10. Đo
    ngày 2026-10-03 (sửa tạm `config/vkcrm.php` của làn rồi trả lại): với mặc định đó, tám tệp test
    chạm chân thư/`OfficeProfile` (`BuildHandoverPackageTest`, `UpdateOfficeProfileTest`,
    `WriteSettingsTest`, `PortalCoverageTest`, `OfficeProfilePageTest`, `EmailLayoutTest`,
    `SenderIdentityTest`, `OfficeProfileTest`) chỉ đỏ đúng hai bài của `EmailLayoutTest` mà
    `88b6044` đã viết lại trên main; làn không đụng tệp đó nên lần gộp nhận bản của main, và bản đó
    (10 bài) xanh trên mã của làn cộng mặc định ấy.

#### Task 9 — Tìm kiếm (SPEC §6.13, R7)

- **Tên giữ cho M11** (kế hoạch M11 dòng 307, 632 — `search_matters` "tìm giống M7 Task 9"):
  - Action đọc `App\Actions\Search\SearchMatters`:
    - `handle(User $actor, string $term, ?array $sources = null, int $limit = 25): MatterSearchResults`
      — mới nhất trước (`matters.id` giảm dần), `limit` kẹp [1, `MAX_LIMIT` = 50], cờ `truncated`
      (lấy `limit + 1` dòng) thay cho tổng số;
    - `matching(User $actor, string $term, ?array $sources = null): Builder<Matter>` — truy vấn ĐÃ
      mang luật hiển thị, để M11 tự lắp bộ lọc (loại vụ, giai đoạn, "vụ tôi phụ trách", đang mở),
      `McpMatterScope` và phân trang cursor. M11 truyền `[SearchSource::Code, Title, ClientName,
      CaseNumber]` (kế hoạch M11: không tìm theo tên các bên hay tiêu đề tài liệu). Tham số nguồn
      chỉ thu hẹp, không nới;
    - `sourcesFor(User): list<SearchSource>`, `normalizeTerm(string): ?string` (NFC, gộp khoảng
      trắng, 2…100 ký tự sau chuẩn hoá; ô tìm mang `maxlength` = `MAX_TERM_LENGTH`).
  - DTO readonly `MatterSearchResults` (`matters`, `truncated`), `MatterSearchResult` (`matterId`,
    `code`, `title`, `clientName`, `matterTypeName`, `hits`), `MatterSearchHit` (`source`, `text`);
    enum `App\Enums\SearchSource` (sáu case, nhãn ở `lang/vi/search.php`).
- **Luật hiển thị trong câu SQL, trước `limit`:** `Matter::scopeListableBy($actor)` VÀ "khớp ít nhất
  một nguồn" trong cùng truy vấn. Không tổng số, không "có kết quả bị ẩn"; "không khớp" và "khớp mà
  không được xem" là một câu. Test: khối kết quả của trưởng phòng giống từng byte dù hai vụ
  `restricted` cùng khớp có tồn tại hay không; câu "chỉ hiện 25 hồ sơ mới nhất" chỉ bật trên tập
  người đó thấy. Vụ `restricted` không lộ với trưởng phòng hay thành viên đội ngũ không phải lead,
  qua cả sáu nguồn. Kiểm tra xung đột lợi ích không là cửa sau: vụ không xem được không bao giờ ra,
  kể cả khi tên một bên khớp đúng.
- **Ai tìm theo nguồn nào:** cần `MatterPolicy::viewAny`. Mã và tên khách: mọi người liệt kê được vụ.
  Tiêu đề vụ, số thụ lý, tên các bên, tiêu đề tài liệu: chỉ `matter.view`. Tài liệu nhóm D: chỉ
  `document.viewInternal` (`group != 'D'` trong SQL, cùng điều kiện nhóm của `DocumentPolicy::view`),
  áp CHUNG cho điều kiện lọc lẫn dòng "Khớp" — một vụ ra nhờ nguồn khác không kèm tiêu đề nhóm D.
  Tài liệu, các bên, vụ đã xoá mềm không ra. Dòng kết quả chỉ mang id/tiêu đề (tức liên kết vào
  trang vụ việc) khi `Gate::forUser($actor)->allows('view', $matter)` (đội ngũ đã nạp, trả lời trong
  bộ nhớ). Dòng "Khớp" của tên các bên/tiêu đề tài liệu có hai lớp chặn độc lập (chỉ truy vấn khi
  nguồn được phép; `present()` chỉ dựng cho nguồn được phép).
- **Lệch khỏi phán quyết controller — cần controller xác nhận.** Phán quyết ghi "kế toán: chỉ bốn
  nguồn đầu" (mã, tiêu đề, tên khách, số thụ lý). Làn cài **hai** (mã, tên khách), vì:
  `MattersTable` ẩn cột tiêu đề với kế toán (và Filament không tìm trên cột ẩn —
  `CanSearchRecords::applyGlobalSearchToTableQuery()` bỏ cột `isHidden()`); số thụ lý chỉ hiện trên
  trang vụ việc, nơi kế toán không mở được; SPEC §5 trên `main` ("Ranh giới của kế toán", M9) nói màn
  hình của kế toán mang mã, loại vụ, tên khách — **không** tiêu đề. Tìm theo một trường là đọc được
  trường đó (gõ "ly hôn" rồi xem vụ nào ra). Muốn mở thêm hai nguồn: bỏ `Title`/`CaseNumber` khỏi
  `SearchMatters::CONTENT_SOURCES` — dòng kết quả của kế toán vẫn không mang tiêu đề (`matterId`,
  `title` theo `MatterPolicy::view`), nhưng việc khớp thì vẫn lộ nội dung. Đính chính SPEC §6.13 ghi
  cách đọc của làn.
- **Trang** `App\Filament\Admin\Pages\Search` (slug `search`, "Tìm kiếm" trên thanh điều hướng):
  `canAccess()` = `Gate::forUser()->allows('viewAny', Matter::class)`; `boot()` `abort(404)` ở mount
  và mọi request cập nhật (TRƯỚC `hydrateCanAuthorizeAccess()` 403); `search()` hỏi lại; Action hỏi
  lần nữa. "Đang hoạt động" do `Authenticate` của panel lo — Livewire giữ nó làm middleware bền, nên
  một tài khoản vừa bị vô hiệu hoá nhận 404 cả ở request cập nhật (đo bằng request thật); bản đầu có
  thêm `is_active` trong `canAccess()`, mutation probe cho thấy không đường nào tới được nó, nên đã
  gỡ. Thuộc tính công khai duy nhất là `$term` (`wire:model.live.debounce.500ms`); kết quả tính
  trong `getViewData()` (protected). Chuỗi tìm KHÔNG lên query string: thường là tên người, và URL
  vào lịch sử trình duyệt và nhật ký máy chủ. Trang nói "Tìm trong: …" đúng các nguồn của người đó.
  Blade không lớp CSS, chỉ biến màu đã đăng ký (test).
- **Cách tìm và index** (migration `2026_09_28_070900_add_search_indexes`: `matters_case_number_index`,
  `matters_title_index`, `clients_name_index`, `documents_title_index`). `LIKE 'x%'` dùng được index,
  `LIKE '%x%'` thì không. Số thụ lý: tiền tố, vì độ chính xác (phần ký hiệu cuối chung cho cả loạt
  vụ). Mã: chứa (người ta nhớ "0147"; ô tìm của `MattersTable` cũng chứa). Tiêu đề, tên khách, tên
  các bên, tiêu đề tài liệu: chứa. **Câu tìm sáu nguồn không dùng index nào** — một `OR` có vế chứa;
  `EXPLAIN` bên dưới. Bốn index vẫn thêm theo kế hoạch: chúng phục vụ câu tiền tố đứng riêng và lúc
  quy mô vượt "vài nghìn hồ sơ"; docblock migration nói thẳng như vậy. `%`, `_` trong chuỗi gõ là
  chữ thường (`ESCAPE '!'` — SQLite không có ký tự thoát mặc định, `'\\'` đọc khác nhau giữa hai CSDL).
- **Số đo** (`tests/Benchmark/SearchMattersBenchmarkTest.php`, ngoài mọi testsuite của
  `phpunit.xml` — CI và `container-test` không tham số đều bỏ qua; chạy tay
  `/d/vkwt/m7b-dev test:mariadb tests/Benchmark/SearchMattersBenchmarkTest.php`). MariaDB trong Docker
  trên máy dev Windows, 6.000 vụ (5% restricted, 60% có số thụ lý), 3.000 khách, 18.000 bên, 30.000
  tài liệu, đội ngũ 2 người mỗi vụ; trung vị 5 lần của `SearchMatters::handle()`:

  | Chuỗi | admin | luật sư (≈400 vụ) | kế toán |
  |---|---|---|---|
  | `0147` (mảnh mã) | 15,1 ms | 13,3 ms | 5,3 ms |
  | `VK-2026` (25+ dòng) | 11,9 ms | 13,8 ms | 7,4 ms |
  | `Nguyễn Văn` | 20,1 ms | 22,5 ms | 10,2 ms |
  | `nguyen van an` | 20,8 ms | 14,1 ms | 5,9 ms |
  | `4711/2026` (số thụ lý) | 15,2 ms | 13,0 ms | 3,5 ms |
  | `khởi kiện 512` (tài liệu) | 18,6 ms | 14,7 ms | 4,1 ms |
  | `Ánh` | 20,1 ms | 23,5 ms | 9,5 ms |
  | `zzqqxx` (không khớp) | 12,6 ms | 13,0 ms | 4,3 ms |

  `EXPLAIN`: câu sáu nguồn — `matters`, `clients`, `documents`, `matter_parties` đều `type=ALL`
  (luật sư thêm `matter_user` `ref`); `case_number LIKE '4711/2026%'` đứng riêng — `range` trên
  `matters_case_number_index`; `title LIKE '%khởi%'` đứng riêng — `type=index` (duyệt cả index phủ).
- **Tiếng Việt — hành vi thật, đo trên cả hai CSDL** (`SearchPageTest`, "tìm tiếng Việt", chạy cả
  `test:mariadb`; đã xác nhận lần chạy MariaDB dùng driver `mariadb`): tên các bên so trên
  `name_normalized` với chuỗi chuẩn hoá cùng `Normalizer::name()` — có dấu/không dấu, hoa/thường,
  `đ`/`d` đều ra, mọi CSDL. Tiêu đề, tên khách: MariaDB `utf8mb4_unicode_ci` bỏ qua dấu ("thue nha"
  ra "thuê nhà") và hoa/thường ngoài ASCII ("ĐỖ VĂN" ra "Đỗ Văn"), nhưng `đ` khác `d` ("duong lam"
  không ra "Đường Lâm"); SQLite so theo byte, chỉ gộp hoa/thường ASCII. Chuỗi gõ ở dạng NFD được đưa
  về NFC trước khi so (mọi CSDL).
- **Giới hạn đã biết:** (1) khách hàng đã xoá mềm thì tên không còn tìm được (vụ vẫn ra theo mã) —
  cùng cách `MattersTable` đọc quan hệ `client`; (2) tiêu đề/tên LƯU ở dạng NFD (dán từ macOS) không
  khớp chuỗi NFC trên SQLite; (3) `đ` ≠ `d` ở tiêu đề và tên khách trên MariaDB — phải gõ đúng chữ
  đ; (4) tìm kiếm không ghi audit (không mở hồ sơ nào; chuỗi tìm thường là tên người, ghi lại là
  thêm một chỗ lưu dữ liệu cá nhân); (5) vụ đã xoá mềm không ra, kể cả với admin (như danh sách vụ
  việc).
- Test: `tests/Feature/Filament/SearchPageTest.php` (màn hình qua Livewire/HTTP: sáu nguồn × thấy/
  không thấy — mỗi "không thấy" kèm đối chứng admin thấy đúng vụ đó bằng đúng chuỗi đó —; vụ
  `restricted` × sáu nguồn; số lượng không rò rỉ; nhóm D với trợ lý; kế toán × năm nguồn bị chặn;
  kế toán không liên kết, không tiêu đề, không dòng khớp tên bên/tài liệu; xoá mềm; ký tự đại diện;
  tiếng Việt; NFD; 404 kể cả request cập nhật Livewire thật; không lớp CSS),
  `tests/Feature/Actions/Search/SearchMattersTest.php` (bề mặt M11: thu hẹp nguồn, không nới nguồn,
  `limit`, tài khoản vô hiệu hoá/xoá mềm, `matching()`, chuẩn hoá, gộp/cắt dòng khớp, chuỗi chuẩn hoá
  rỗng), `tests/Feature/Database/SearchIndexesTest.php`.
- **Bằng chứng TDD.** RED: ba tệp test viết trước mã — 58/58 đỏ (lớp `Search`/`SearchMatters` chưa
  có, bốn index chưa có). GREEN 58/58 ở lần chạy đầu, cả SQLite lẫn MariaDB. Sau đó thêm ba test
  Action (nguồn rỗng cho nhân sự không quyền, chuỗi chuẩn hoá rỗng, trần `MAX_LIMIT`), siết test kế
  toán (dòng khớp tên bên/tài liệu) và test số thụ lý (cờ khớp tiền tố) để ghim những điều kiện mà
  lần lập kế hoạch probe thấy chưa có test nào đỏ. **47 mutation probe:** 43 đỏ; 4 xanh — ba là đột
  biến tương đương của hai lớp chặn dòng khớp (bỏ riêng lớp "chỉ truy vấn khi nguồn được phép" cho
  tên bên, cho tài liệu, hoặc bỏ riêng lớp "`present()` chỉ dựng cho nguồn được phép"; bỏ CẢ HAI lớp
  thì đỏ, hai probe), một là `is_active` thừa trong `canAccess()` (đã gỡ, xem mục Trang).
- **Ghi chú lúc gộp** (đo bằng `git merge-tree` ngày 2026-10-03 trên một commit tạm chứa Task 9, so
  với cùng phép đo từ `572f3be`): Task 9 không thêm tệp xung đột nào — với `origin/m7-handover` vẫn
  đúng một tệp như trước (`lang/vi/activity.php`), với `origin/main` vẫn đúng 13 tệp như trước. Mọi
  tệp mã của Task 9 là tệp mới; đính chính SPEC §6.13 tự gộp với cả hai nhánh (làn m7 thêm đoạn
  §6.12 ngay TRÊN tiêu đề §6.13, Task 9 viết DƯỚI đoạn cuối của §6.13). Sau khi gộp main: M9 cho kế
  toán `billing.view` — `sourcesFor()` đọc `matter.view`, không đọc vai trò, nên không đổi; M8 (2FA
  bắt buộc): `UserFactory` của main mặc định có `two_factor_secret`, `SearchPageTest` dùng factory
  như mọi test trang khác; M11 gọi `matching()`/`handle()` với bốn nguồn của vụ.

### Task 1 — Phần còn lại của `ReassignMatter` (SPEC §6.11 bước 3, R10)

M6.5 Task 4 đã dựng `ReassignMatter` cho MỘT vụ việc (đổi lead, chuyển mốc hạn CHƯA hoàn thành,
audit) nhưng hoãn thư tổng hợp mốc hạn cho lead mới, vì hạ tầng thư xếp hàng (M6.5 Task 11) chưa
merge lúc đó. Task 1 dựng thư đó:

- `App\Jobs\SendReassignmentDigest` (queue mặc định, `ShouldQueue`) — dispatch bằng
  `->afterCommit()` từ `ReassignMatter::handle()` (R2). Mang `$newLeadId` + một mảng
  `matter_id => {deadline_ids, client_request_ids, reason}` — CHỈ id, dựng để dùng lại được cho
  M7 Task 2 (bàn giao hàng loạt): một lô nhiều vụ, một thư duy nhất cho lead mới.
- Dựng lại TOÀN BỘ nội dung LÚC GỬI, không tin payload: mốc phải còn tồn tại, chưa hoàn thành, và
  hiện do lead mới phụ trách; yêu cầu khách phải còn gán cho lead mới và chưa đóng; cả vụ việc bị
  loại khỏi thư nếu lead mới không còn qua được `ResolveStaffRecipients::qualifies()` (vụ
  `restricted` vừa bàn giao tiếp, tài khoản bị vô hiệu hoá giữa chừng, …). Không còn vụ nào qua
  được lọc thì không gửi gì.
  - Một vụ vẫn có mặt trong thư dù danh sách mốc rỗng ("không có mốc hạn nào được chuyển") — lead
    mới cần biết mình vừa nhận vụ.
- `App\Mail\Staff\MatterReassigned` (kế thừa `BrandedMailable`, mẫu `staff.matter_reassigned`) —
  tiêu đề KHÔNG nêu mã/tiêu đề vụ nào, chỉ số lượng; `relatedRecord()` trỏ người nhận (không phải
  một `Matter`) để dòng `outbound_messages` chỉ admin thấy.
- `ReassignMatter::handle()` thêm tham số `bool $sendDigest = true` — `false` dành cho Task 2 tự
  gộp một thư cho cả lô thay vì một thư trên mỗi vụ.
- `failed()` (hết `$tries`): audit `matter_reassignment_digest_failed` (chỉ `new_lead_id` +
  `matter_ids`) và thông báo trong hệ thống cho chính người nhận.
- Đính chính SPEC §6.11 bước 3 và §11 ("Bàn giao và lưu trữ"): chỉ deadline CHƯA HOÀN THÀNH được
  chuyển, không phải "toàn bộ" (R10, cùng cách đọc M6.5 Task 4 đã chọn).
- Test: `tests/Feature/Jobs/SendReassignmentDigestTest.php` (job, 17 test — mọi điều kiện lọc có
  mutation probe, cộng một test KHÔNG `Mail::fake()` xác nhận view Blade thật biên dịch và nhật
  ký `outbound_messages` mở đúng dòng), `tests/Feature/Actions/Matter/ReassignMatterTest.php`
  (2 test mới: dispatch mặc định + `sendDigest: false`), `tests/Feature/Filament/
  ReassignMatterActionTest.php` (cập nhật: `Mail::assertNothingSent()` cũ thay bằng
  `Mail::assertSent(MatterReassigned::class, …)` + `Mail::assertNotSent(StageUpdate::class)` cho
  đúng "không thư nào tới KHÁCH"; thêm test vụ `restricted` vẫn nhận thư, và rollback → không có
  thư).
- Không việc nào bị hoãn tiếp ở task này.

**Fix round 1** (review needs_fixes: 0 critical/2 important/7 minor — 2 important sửa ở đây):
- **Finding 1 (R2 chưa có test).** Test "rollback" cũ (`ReassignMatterActionTest`, "refuses to keep
  the old lead...") ném `ValidationException` TRƯỚC Bước 5/dispatch, nên `Mail::assertNothingSent()`
  ở đó đúng bất kể `->afterCommit()` có mặt hay không — không đo được gì về R2. Thêm một test MỚI,
  cùng tệp: bọc một lần bàn giao THÀNH CÔNG (chạm cả dòng dispatch) trong một `DB::transaction`
  NGOÀI của chính test rồi CỐ Ý rollback, khẳng định `Mail::assertNotSent(MatterReassigned::class)`
  — cùng thành ngữ `ClientIdentitySyncTest.php`. Xác nhận đỏ bằng tay khi bỏ `->afterCommit()` khỏi
  `ReassignMatter::handle()` (cả test mới này lẫn test dispatch payload ở `ReassignMatterTest.php`
  đều đỏ), rồi khôi phục lại. Cũng thêm `&& $job->afterCommit === true` vào closure
  `Queue::assertPushed()` của test dispatch payload cũ (`ReassignMatterTest.php`) — trước đó tên
  test nói "after commit" nhưng không hề đo cờ đó.
- **Finding 2 (Task 2 không có cách lấy đúng id đã chuyển mà không re-query sai).** `handle()` giờ
  trả về `App\Actions\Matter\ReassignMatterResult` (mới, `stageLog` + `movedDeadlineIds` +
  `movedRequestIds`) thay vì `StageLog` trần — đúng lựa chọn mà phán quyết controller Task 1 đã cho
  phép ("đổi giá trị trả về... nếu cập nhật MỌI nơi gọi và test"). Cập nhật
  `ViewMatter::submitReassign()` (vẫn bỏ qua giá trị trả về — màn hình MỘT vụ không cần gộp gì) và
  hai test ở tầng Action đang gán `$stageLog = handle(...)` sang `$result->stageLog`. Thêm một test
  mới xác nhận `$result->movedDeadlineIds`/`movedRequestIds` chỉ mang đúng id LẦN GỌI NÀY chuyển,
  không lẫn deadline/client request lead mới đã giữ TỪ TRƯỚC — đúng rủi ro mà một cách làm
  "re-query sau khi cả lô chạy xong" sẽ mắc phải. Sửa lại docblock lớp `ReassignMatter` (đoạn nói
  Task 2 "chỉ cần gọi lặp lại") cho đúng cơ chế mới.
- Test: 2 test mới (`ReassignMatterActionTest.php`: rollback qua transaction ngoài;
  `ReassignMatterTest.php`: `movedDeadlineIds`/`movedRequestIds` trên result object) + 2 test có
  sẵn được sửa (`$job->afterCommit === true`; `$stageLog` → `$result->stageLog` × 2). Toàn bộ 27
  test của hai tệp `ReassignMatterTest.php` + `ReassignMatterActionTest.php` xanh; `pint --test`
  sạch (546 tệp).

### Task 2 — Màn hình bàn giao hàng loạt (SPEC §6.11; admin/trưởng phòng)

Trang tự viết cho phép chọn nhiều vụ ĐANG MỞ của một luật sư/trưởng phòng và bàn giao cả lô sang
một lead mới cùng lúc — luật sư vẫn dùng nút "Bàn giao" từng vụ trên `ViewMatter` như trước (không
thấy trang này).

- `App\Actions\Matter\ReassignMatters` — vòng lặp gọi `ReassignMatter::handle()` cho TỪNG vụ (mỗi
  vụ tự mở/đóng transaction riêng của chính nó, KHÔNG có transaction ngoài bọc cả vòng lặp — một
  vụ lỗi không rollback các vụ đã xong trước đó). Bắt riêng bốn họ lỗi
  (`AuthorizationException`, `ValidationException`, `DomainException`, `ModelNotFoundException`)
  thành một dòng `App\Actions\Matter\BulkReassignMatterResult` tiếng Việt cho từng vụ — không phá
  vỡ vòng lặp. Vụ `restricted` LUÔN bị ép `keepOldLeadAsAssociate = false`, bất kể công tắc "giữ
  lại" của cả lô (cùng luật nút một vụ). Sau vòng lặp, gộp `movedDeadlineIds`/`movedRequestIds` của
  MỌI vụ THÀNH CÔNG theo `matter_id` rồi dispatch ĐÚNG MỘT `SendReassignmentDigest` (hạ tầng Task
  1), `->afterCommit()` — không vụ nào thành công thì không dispatch gì.
  - `BulkReassignMatterResult::$matterCode`/`$matterTitle` là `null` khi và chỉ khi actor không
    qua được `manageTeam` trên vụ đó (`AuthorizationException`) — actor không hợp lệ để THẤY vụ
    việc thì không được biết mã/tiêu đề của nó, kể cả trong một dòng kết quả thất bại.
- `App\Filament\Admin\Pages\BulkReassign` (tiêu đề "Bàn giao hàng loạt") — `canAccess()` hỏi
  `Gate::define('bulkReassign')` mới (`AppServiceProvider::boot()`, admin hoặc trưởng phòng; không
  phải quyền thứ 14 trong `App\Enums\Permission`, xem docblock nơi khai báo), hỏi lại ở CẢ
  `mount()` LẪN hành động thật `reassignSelected()` (`abort_unless(..., 404)` độc lập, cùng gotcha
  Livewire "middleware 404 của panel không phủ được request cập nhật Livewire" đã ghi từ
  `SubmitDocument`/`MyRequests`). Luồng bốn bước: chọn luật sư đang phụ trách → danh sách vụ đang
  mở CHỈ những vụ actor `manageTeam` được (vụ `restricted` không bao giờ vào tới mảng trả về cho
  một trưởng phòng không phải admin/lead — không phải ẩn UI, mà là chưa từng được tính) → chọn
  nhiều vụ + lead mới (hỏi lại `newLeadOptions()` lúc submit, không chỉ tin Select) + lý do (bắt
  buộc, `maxLength(5000)`) + công tắc "giữ lead cũ" → bấm. Tham số `?from=<user_id>` mở sẵn với một
  luật sư (R6). Mọi phương thức trả danh sách/tuỳ chọn là `private`/`protected` (gotcha Livewire
  M6.5 Task 3). `matter_ids` KHÔNG được lọc lại theo `matterOptions()` trước khi gọi Action — việc
  hỏi lại `manageTeam` cho TỪNG vụ là việc của Action.
  - **Cắn thật, đã sửa:** `CheckboxList` mặc định tự suy luật validate `in:` từ CHÍNH
    `options()` đã render — một id KHÔNG còn trong `matterOptions()` LÚC SUBMIT (vụ vừa bị bàn
    giao ở tab khác, hoặc một vụ `restricted` bị ép vào payload) bị Livewire chặn NGAY Ở TẦNG
    VALIDATE-FORM (lỗi "đã chọn không hợp lệ"), chặn CẢ LÔ và không bao giờ chạm tới
    `ReassignMatters` — đúng thứ docblock lớp cấm ("không được lọc theo matterOptions() trước
    khi gọi Action"), chỉ là Filament tự làm việc đó thay vì trang. Sửa bằng `->in(fn(): array
    => Matter::query()->pluck('id')->all())` trên `CheckboxList::make('matter_ids')` — luật
    "in:" chỉ còn giữ vai trò vệ sinh cơ bản (một id phải là một vụ việc còn tồn tại), quyết định
    AI được bàn giao VỤ NÀO vẫn hoàn toàn thuộc `manageTeam` bên trong Action.
  - **Báo cáo kết quả từng vụ** sau khi bấm (thành công/thất bại, lý do tiếng Việt thật), với mỗi
    vụ THÀNH CÔNG đã công bố portal (`is_published_to_portal`) một dòng gợi ý soạn cập nhật giới
    thiệu luật sư mới (chỉ gợi ý, có liên kết tới trang vụ, không tự soạn/gửi — SPEC §6.11 bước
    4). `$results` là mảng THUẦN (không phải đối tượng `BulkReassignMatterResult`) vì Livewire
    không serialize được một `final readonly class` không có synth đăng ký sẵn.
- R6: `EditUser::attachBulkReassignLink()` thêm một action-button "Mở màn hình Bàn giao hàng loạt"
  vào ĐÚNG thông báo mà `authorizationNotification()`/`unauthorizedNotification()` của
  `DeleteAction` tự dựng khi `UserPolicy::delete()` từ chối (tiêu đề = nguyên văn lý do policy,
  KHÔNG đổi); và một `Notification` RIÊNG (chuỗi lỗi form không mang được URL) khi tắt `is_active`
  bị chặn. Cả hai chỉ thêm liên kết khi người bị chặn CÒN dẫn ít nhất một vụ mở
  (`BulkReassign::offboardingLinkAction()`, đọc qua `OpenWork`) VÀ actor hiện tại
  `BulkReassign::canAccess()` được — `null` (không thêm gì) trong hai trường hợp còn lại.
- Chuỗi mới: `lang/vi/reassign.php` khối `bulk` (M7 Task 2); `lang/vi/users.php` khoá
  `offboarding.bulk_reassign_notice` (thêm cuối khối `offboarding`, chú thích `// M7 Task 2`).
- Test: `tests/Feature/Actions/Matter/ReassignMattersTest.php` (7 test tầng Action — vòng lặp
  không rollback chéo, id thiếu không vỡ vòng lặp, redact mã/tiêu đề khi `AuthorizationException`,
  ép gỡ lead cũ trên vụ `restricted`, một thư duy nhất chỉ liệt vụ thành công, không thư khi mọi vụ
  thất bại); `tests/Feature/Filament/BulkReassignTest.php` (11 test màn hình — 404 cho luật
  sư/trợ lý/kế toán kể cả bypass route, che vụ `restricted` khỏi trưởng phòng, từ chối payload ép
  vụ `restricted` không lộ mã/tiêu đề, một vụ thất bại không chặn các vụ khác, gợi ý giới thiệu chỉ
  cho vụ đã công bố — đếm đúng 1 lần, `?from=` mở sẵn, mutation probe cho `->in()`);
  `tests/Feature/Filament/UserResourceTest.php` (+3 test cho R6: liên kết ở thông báo chặn xoá,
  thông báo riêng ở đường tắt `is_active`, không liên kết nào khi người bị chặn không dẫn vụ mở —
  cả ba có mutation probe bằng tay, xác nhận đỏ khi bỏ dòng gắn liên kết rồi khôi phục).
- Không việc nào bị hoãn tiếp ở task này. Toàn bộ 59 test của ba tệp trên xanh trên SQLite và trên
  MariaDB (chạy tuần tự từng tệp); full suite `/d/vkwt/m7-dev test --parallel --processes=4`:
  **2258 passed / 5 skipped / 0 failed** (9665 assertions, 452.91s — so với baseline 2216/5/0);
  `pint --test` sạch (551 tệp).

**Fix round 1** (review needs_fixes: 1 critical/3 important/8 minor — bốn phát hiện sửa ở đây,
theo đúng thứ tự findings 1–4 của review):
- **Finding 1 (Critical — id trôi lead/đã đóng không bị chặn dưới khoá).** `ReassignMatters`
  không hề so `matter_ids` với "luật sư đang phụ trách" đã chọn ở đầu trang, cũng không hỏi
  `closed_at`; `ReassignMatter::handle()` chỉ hỏi `manageTeam` và tự tra `lead_lawyer_id` DƯỚI
  KHOÁ làm "lead cũ", không so nó với người actor NGHĨ đang phụ trách. Một vụ bị một tab khác bàn
  giao sang lead THỨ BA (khác lead mới N của lượt hàng loạt này) bị bàn giao LẦN NỮA, đè mất bàn
  giao của tab kia, báo "Đã bàn giao thành công."; cùng lỗ hổng cho một id giả của vụ thuộc lead
  khác, hoặc một vụ đã đóng. Sửa: `ReassignMatter::handle()` nhận thêm `?int $expectedLeadId`
  (mặc định `null`, không đổi nút một vụ của `ViewMatter`); `ReassignMatters::handle()` nhận
  `int $expectedLeadId` (KHÔNG có mặc định — luôn là "luật sư đang phụ trách" đã chọn trên
  `BulkReassign`) và truyền xuống MỖI lời gọi. Dưới khoá, ngay sau khi hỏi lại `manageTeam`: khác
  lead hoặc `closed_at` khác `null` thì `ValidationException` tiếng Việt mới
  (`reassign.validation.stale_or_closed`). Test: 3 test Livewire mới ở
  `BulkReassignTest.php` (lead thứ ba, id giả của lead khác, vụ đã đóng — mỗi test mutation-probe
  bằng tay: bỏ điều kiện, xác nhận đỏ, khôi phục); cập nhật docblock của test cũ "keeps a matter
  that already succeeded..." (bản trước đỏ đúng nhờ "same_lead" — một sự trùng hợp findings đã chỉ
  ra, giờ đỏ đúng lý do `stale_or_closed`).
- **Finding 2 (Important — `currentLeadOptions()` lộ tên lead của vụ `restricted`).** Ô "Luật sư
  đang phụ trách" liệt kê MỌI lead của vụ đang mở, không lọc `manageTeam` như `matterOptions()` —
  một trưởng phòng không quản lý được một vụ `restricted` vẫn thấy TÊN lead của vụ đó, một kênh rò
  rỉ mới (trang quản lý nhân sự vốn chỉ admin xem được). Sửa: lọc bằng CÙNG
  `Gate::allows('manageTeam', ...)` mà `matterOptions()` dùng, trước khi rút `lead_lawyer_id`.
  Test Livewire mới: một trưởng phòng không thấy tên một lead chỉ dẫn vụ `restricted`, admin vẫn
  thấy (mutation probe: bỏ `->filter(...)`, xác nhận đỏ, khôi phục).
- **Finding 3 (Important — một exception lạ giữa lô làm mất digest của các vụ đã commit).** Chỉ
  bốn họ lỗi được bắt; một `QueryException` (lock-wait/deadlock) hay bất kỳ `Throwable` nào khác
  thoát thẳng khỏi `ReassignMatters::handle()`, và dispatch digest (đứng SAU vòng lặp) không bao
  giờ chạy — các vụ ĐÃ commit trước đó không bao giờ được báo cho lead mới. Sửa: thêm
  `catch (Throwable)` cuối cùng (cùng lưới an toàn `UsersTable`'s `DeleteBulkAction::using()`) —
  `report()` rồi ghi một dòng thất bại chung chung (`reassign.bulk.results.unexpected_error`),
  KHÔNG ném tiếp; và bọc dispatch trong `finally` quanh TOÀN BỘ vòng lặp (không chỉ SAU nó) làm
  lưới thứ hai phòng xa (đính chính fix round 2: câu `Matter::query()->find($matterId)` NẰM TRONG
  `try` của từng vụ, không ngoài nó như bản này từng ghi; `finally` không có test riêng). Test mới ở `ReassignMattersTest.php`: một
  `ReassignMatter` giả (`ThrowsUnlistedExceptionOnSecondCall`, cùng thành ngữ
  `ThrowingOnMarkSentLedger`) ném `RuntimeException` ở vụ thứ hai — xác nhận vụ đầu vẫn có trong
  digest đã dispatch VÀ `Exceptions::assertReported(RuntimeException::class)` (mutation probe: bỏ
  `try{}finally{}` + `catch (Throwable)`, xác nhận đỏ — exception thoát thẳng khỏi `handle()`,
  không job nào được dispatch — rồi khôi phục).
- **Finding 4 (Minor — toast luôn xanh "Đã bàn giao vụ việc." kể cả khi cả lô thất bại).** Tiêu đề
  cố định `reassign.action.success` (câu của nút MỘT vụ) + `->success()` bất kể kết quả thật. Sửa:
  ba khoá mới `reassign.bulk.notification_titles.{success,partial,failure}`, chọn tiêu đề VÀ màu
  (`success`/`warning`/`danger`) theo `$successCount`/`$failureCount`. Test Livewire mới: cả lô
  thất bại (id giả vụ `restricted`) → `assertNotified('...notification_titles.failure')` +
  `Notification::assertNotNotified('reassign.action.success')` (mutation probe: khôi phục bản cứ
  định cũ, xác nhận đỏ, khôi phục lại bản sửa).
- Test mới: 3 (Livewire, finding 1) + 1 (Livewire, finding 2) + 1 (Action, finding 3) + 1
  (Livewire, finding 4) = 6 test mới, cộng cập nhật 7 lời gọi `ReassignMatters::handle()` hiện có
  (`ReassignMattersTest.php`) để truyền `expectedLeadId`. `pint --test` sạch (551 tệp); full suite
  `/d/vkwt/m7-dev test --parallel --processes=4` và `test:mariadb` (ba tệp đã đụng, tuần tự) — xem
  báo cáo `.superpowers/sdd/m7/task-2-report.md`, mục "Fix round 1" cho số liệu đầy đủ.

**Fix round 2** (review needs_fixes 0 critical/2 important/10 minor — hai phát hiện Important và các
minor rẻ được sửa ở đây):
- **I1 — test "ép id vụ restricted" rỗng.** Sau fix round 1 finding 2, `$oldLead` chỉ dẫn đúng vụ
  `restricted` nên bị lọc khỏi ô "Luật sư đang phụ trách" của trưởng phòng; form tự chặn
  `data.lead_lawyer_id` và test không bao giờ chạm tới `ReassignMatters`. Sửa: `$oldLead` còn dẫn
  một vụ thường; test thêm `assertHasNoErrors()` và đọc `results[0]` (mã/tiêu đề `null`). Probe: bỏ
  vụ thường → đỏ.
- **I2 — oracle tồn tại vụ `restricted`.** Luật `in:` của `CheckboxList` dựa trên `pluck('id')` làm
  id đã xoá/không tồn tại thành lỗi form, còn id vụ `restricted` thật thành một dòng kết quả với
  câu khác ("không có quyền") — đếm được số vụ `restricted`. Sửa: `in:` là dải `1..max(id, kể cả
  xoá mềm)`; nhánh "không tìm thấy" và `AuthorizationException` dùng CHUNG một câu
  `reassign.bulk.results.unavailable` (bỏ `not_found`/`unauthorized`). Test mới "gives the identical
  response to a forged nonexistent matter id and a forged restricted matter id". Probe: khôi phục
  `pluck('id')` → đỏ; tách lại hai câu → đỏ. Giới hạn còn lại đã biết: id LỚN HƠN mọi id từng cấp
  vẫn là lỗi form (chỉ lộ "id lớn nhất", không lộ vụ `restricted` nào).
- Minor đã sửa: nhánh `Throwable` không còn gắn mã/tiêu đề (lỗi có thể nổ trước `manageTeam`; probe
  → đỏ); docblock sai "find() nằm ngoài try" đã đính chính (ở lớp và ở mục fix round 1), và nêu
  thẳng `finally` không có probe riêng (đã chạy: bỏ riêng `finally` thì test vẫn xanh); bỏ khoá
  `reassign.bulk.fields.no_matters` không dùng; thêm test đường HTTP/`?from=` thật của liên kết R6
  (probe: bỏ `request()->query('from')` → đỏ).
- Minor để lại (không đụng luật nghiệp vụ, ghi cho reviewer): không chọn-tất-cả; không `wire:loading`
  trên nút gửi; công tắc "giữ luật sư cũ" mặc định bật cả với liên kết từ màn hình nghỉ việc; liên
  kết R6 chưa gắn ở nhánh hạ vai trò/xoá hàng loạt (cố ý — xem ghi chú Task 2 ở trên).

### Task 3 — Lưu trữ khi vụ việc kết thúc (sự kiện `MatterStageChanged`, `SyncMatterArchive`)

Bảng `matter_archives` đã có từ M1; task này dựng vòng đời của nó trên `matters.closed_at` (M6.5 R8).

- **Sự kiện + listener + Action.** `App\Events\MatterStageChanged` (`ShouldDispatchAfterCommit`, mang
  `StageLog`) phát ở `TransitionMatterStage` CHỈ khi giai đoạn thật sự đổi, độc lập với việc công bố
  ra portal. Hình dạng khớp kế hoạch M9 (M9 thêm listener của nó vào đúng sự kiện này). Trùng tên
  ngắn với `App\Exceptions\MatterStageChanged` nên `TransitionMatterStage` import sự kiện bằng bí danh
  `MatterStageChangedEvent`. Listener `SyncMatterArchiveOnStageChange` chỉ gọi
  `App\Actions\Matter\SyncMatterArchive::handle(int $matterId, ?User $actor)`.
- **Chạy đồng bộ, không xếp hàng (đã chọn, lý do ở docblock listener).** Một archive sai là lỗ hổng
  âm thầm (`client_access_until` quyết ngày khách mất quyền xem), nên lỗi phải hiện cho người bấm
  "Chuyển giai đoạn" thay vì nằm trong `failed_jobs`. Vì sự kiện là after-commit, `StageLog` đã commit
  trước khi listener chạy — lỗi archive không làm mất lần chuyển giai đoạn, và Action idempotent nên
  lần chuyển giai đoạn kế tiếp tự đồng bộ lại.
- **`SyncMatterArchive`.** Khoá `matters` trước, rồi `matter_archives`; đọc `closed_at` DƯỚI khoá
  (không tin sự kiện). `closed_at` khác null: tạo/cập nhật (`archived_at = now()`, `archived_by`,
  `client_access_until = closed_at + config('vkcrm.client_access_days')`,
  `retention_until = closed_at + config('vkcrm.retention_years')`, không số cứng). `closed_at` null
  (admin mở lại): CHỈ `client_access_until = null`, giữ nguyên dòng, không xoá mềm (unique
  `matter_id` trên MariaDB tính cả dòng xoá mềm); nếu gặp dòng đã xoá mềm thì khôi phục. Không đụng
  `destroyed_at`/cột tiêu huỷ/`handover_document_id`. Nhận `int $matterId` vì `ClientPortalScope` có
  thể làm quan hệ `$stageLog->matter` trả `null` khi một nhân sự mở cả hai panel.
- **Migration** `2026_09_28_070001_add_lifecycle_columns_to_matter_archives_table`:
  `handover_document_id` (FK `documents`, `nullOnDelete`), `destruction_reason` (text),
  `destruction_record_no` (**string(50)** — form Task 6 dùng `maxLength(50)`), `destroyed_by` (FK
  `users`, `nullOnDelete`). `handover_package_path` giữ cột nhưng bỏ khỏi `$fillable`. Đính chính SPEC
  §4.19. Vòng MariaDB thật (seed → `migrate:reset` → `migrate`) sạch, `down()` chạy được.
- **Danh mục hồ sơ của vụ đã đóng là chỉ đọc, chặn ở Action** (việc M6.5 Task 15 hoãn sang đây):
  `AddChecklistItem`, `ReviewChecklistItem`, `MarkChecklistItemNotApplicable` (qua
  `OpensChecklistItem`) ném `MatterChecklistReadOnly` khi `closed_at` khác null, kiểm dưới khoá
  `matters`, SAU Gate (không thành máy dò "vụ đã đóng chưa"). Ba nút của `ChecklistRelationManager`
  ẩn trên vụ đã đóng. `OpensChecklistItem` đổi sang thứ tự khoá `matters` trước, đầu mục sau (đọc
  không khoá để lấy `matter_id`, khoá `matters`, rồi khoá đầu mục); `SubmitClientDocument` cũng khoá
  `matters` ở đầu transaction.
- **Khách nộp tài liệu vào vụ đã đóng** (`SubmitClientDocument`) bị từ chối bằng
  `MatterClosedForSubmission` — một `DomainException` riêng, KHÔNG `AuthorizationException` (trang
  portal đổi mọi `AuthorizationException` thành 404 trống, còn khách cần đọc câu mời gọi hotline).
  Hai lần kiểm: một lần rẻ trước khi quét virus (test đếm số lượt quét = 0), một lần dưới khoá
  trong transaction (test "vụ bị đóng trong lúc quét virus").
- **Đổi `is_terminal` khi giai đoạn đang có vụ.** Không dựng cơ chế đồng bộ thứ hai: M6.5 final fix
  wave X9 đã CHẶN đổi cờ khi giai đoạn còn vụ đứng đó (`MatterTypeStage::booted()` →
  `StageTerminalFlagInUse`). Lý do chọn chặn thay vì đồng bộ: một đồng bộ hàng loạt `closed_at` +
  archive trong một lần lưu cấu hình sẽ đóng/mở hàng chục vụ mà không ai bấm "Chuyển giai đoạn" (không
  StageLog, không người chịu trách nhiệm); giá nếu sai (ruling M6.5): admin phải chuyển các vụ đi trước khi
  đổi cờ. Hệ quả cho task này: archive không cần đường đồng bộ theo
  cờ, vì cờ không đổi được khi còn vụ.
- **Seeder** (`MatterSeeder::closedMatter()`, chỉ phần demo): vụ `99/2026/TLST-DS` đã kết thúc, dựng
  `closed_at` trực tiếp rồi gọi `SyncMatterArchive` (không viết tay cột archive); bảy tài liệu THẬT
  trên đĩa `private`: nhóm A (khách nộp), B `signed_filed` + B `published` trùng tiêu đề, B còn
  `internal_draft`, B đã xoá mềm, C với tiêu đề chứa `../`, D. Chọn client `get(5)` và lead
  `$lawyers->last()` để không đổi số ghim của luatsu1/khach1. Test seeder và
  `DemoDataAuthorizationTest` cập nhật (21 → 22 vụ, kế toán 20 → 21).
- **Quyết định cho chủ văn phòng (chưa quyết): vụ bị huỷ vì mở nhầm.** `CancelMatter` (xoá mềm) không
  bao giờ có bản ghi archive nên không có `retention_until`; dữ liệu cá nhân trong vụ đó cần một hạn
  xoá. Cần chủ văn phòng quyết cùng chính sách lưu trữ của M10.
- **Không có test/probe cho:** khoá thật `lockForUpdate()` (SQLite không sinh khoá), so khớp
  `matter_id` giữa hai lần đọc đầu mục ở `OpensChecklistItem` (nhánh phòng thủ, cột bất biến).
- Test: `tests/Feature/Actions/Matter/SyncMatterArchiveTest.php` (14),
  `TransitionMatterStageTest` (+6), `TransitionStageActionTest` (+3, Livewire: đóng/mở lại/đóng lại
  qua form thật), các test Action + Livewire của danh mục/nộp portal, `DemoDataSeederTest` (+4).

### Task 4 — `GenerateHandoverPackage` (SPEC §6.12, R1, R3, R8, R9)

Job sinh gói bàn giao trên hàng đợi RIÊNG, dựng zip + `MUC-LUC.pdf`, lưu thành một `Document`.

- **Cấu trúc.** Nghiệp vụ nằm trong Action, job chỉ gọi Action:
  - `RequestHandoverPackage` — cửa DUY NHẤT xếp hàng (tự động khi vụ đóng, đúng MỘT lần, chỉ khi
    `handover_status` còn NULL; thủ công qua nút, cần `MatterArchivePolicy::generateHandover` =
    `document.publish` + xem được vụ). Khoá `matters` trước `matter_archives`; đặt `generating`, ghi
    dấu yêu cầu (`handover_requested_at`) rồi `GenerateHandoverPackage::dispatch()->afterCommit()`.
    Một lần `generating` cũ hơn 60 phút được coi là kẹt (worker chết không gọi `failed()`) và cho
    yêu cầu lại; nút bị khoá khi đang chạy và chưa kẹt.
  - `CollectHandoverEntries` (R8 — chọn tài liệu và đặt tên entry), `RenderHandoverIndex` (R3 — PDF
    bằng dompdf), `BuildHandoverPackage` (dựng trong thư mục tạm, kiểm zip đọc lại được với đúng số
    entry, rồi MỘT transaction tạo `Document` + gắn tệp + cập nhật lưu trữ + audit — xem "Khoá
    trong lúc chép gói" dưới đây: transaction này không ngắn với gói lớn),
    `RecordHandoverPackageFailure` (lỗi thất bại hẳn), job `SendHandoverPackageReady` (báo kết quả).
  - Tự sinh nối vào `SyncMatterArchiveOnStageChange` (sau khi bản ghi lưu trữ đã đồng bộ). Lỗi xếp
    hàng ở bước này được `report()` rồi nuốt: một lần đóng vụ đã commit không hiện ra như thất bại
    vì hàng đợi trục trặc; luật sư vẫn bấm nút thủ công được.
- **Hàng đợi (R9).** Kết nối `handover` MỚI trong `config/queue.php` (driver LUÔN `database`,
  không theo `QUEUE_CONNECTION`; `retry_after` 900 giây > `$timeout` 600 giây của job — kết nối
  `database` chung chỉ 90 giây, một gói lâu hơn sẽ bị nhặt lại và chạy song song). Mục lịch riêng
  `queue.handover` (`queue:work handover --queue=handover --stop-when-empty --max-time=50
  --timeout=600`, mỗi phút, `withoutOverlapping(15)`, `runInBackground()`) ở cuối `routes/console.php`;
  `queue.drain` không đổi. Chạy NỀN vì `schedule:run` chạy các mục của một phút lần lượt: một gói
  600 giây ở tiền cảnh sẽ bắt mọi mục đăng ký SAU nó (các tác vụ hằng ngày của Task 5/6, thêm vào
  cuối tệp) đứng chờ; khoá `withoutOverlapping` vẫn giữ tới khi lệnh nền xong. Job: `$timeout`
  600, `$tries` 2, `$failOnTimeout` true, backoff 120 giây; lỗi CÓ TÊN (`HandoverPackageFailed`:
  thiếu tệp, không nén được, không dựng được mục lục, và từ vòng sửa 1: thư mục tạm không ghi được,
  gói vượt trần một tệp của kho, kho không lưu được gói) là tất định hoặc cần người sửa trước nên
  ghi thất bại ngay, không thử lại, với câu chỉ người vận hành phải làm gì. Test ghim `retry_after > $timeout`, khoá hết hạn 15 phút, tên/tần
  suất mục lịch, và việc kết nối không đổi theo `QUEUE_CONNECTION`.
- **Dấu của lần yêu cầu.** Job mang `handover_requested_at` (giây UNIX); cả lúc dựng lẫn lúc ghi lỗi
  chỉ ghi khi dấu còn khớp dòng lưu trữ và trạng thái còn `generating` — job cũ nhặt lại muộn không
  đè kết quả của lần yêu cầu mới hơn.
- **Thư mục tạm khi tiến trình bị GIẾT.** Hết `$timeout` thì worker gọi `failed()` rồi tự giết tiến
  trình (`Worker::registerTimeoutHandler`); hết bộ nhớ hay bị máy chủ cắt thì không gì chạy cả — cả
  hai đều không tới `finally` của lần dựng, và zip dở (vài trăm MB, trên đĩa có hạn mức) sẽ nằm lại.
  Vì vậy thư mục tạm có tên CỐ ĐỊNH theo lần yêu cầu, `<work_dir>/<id vụ>-<dấu yêu cầu>`
  (`BuildHandoverPackage::workDirectory()`): lần chạy lại của cùng job dùng lại rồi xoá nó (kể cả khi
  nó thoát sớm vì yêu cầu đã bị thay), và `RecordHandoverPackageFailure` xoá nó khi job thất bại hẳn
  (hết giờ, hoặc hết lượt thử sau `retry_after` với một tiến trình chết). Còn một khe: dòng job bị xoá
  tay khỏi bảng `jobs` thì không ai dọn — thư mục vẫn nằm dưới `HANDOVER_WORK_DIR`, tìm theo tên.
  Lưu ý vận hành: `--timeout` của worker chỉ có hiệu lực khi PHP có `ext-pcntl`; không có nó, một job
  quá giờ chạy tiếp, và sau 900 giây (`retry_after`, cũng là hết khoá 15 phút) một worker khác có
  thể nhặt lại cùng job — ghi vào danh sách extension của M8 Task 7.
- **Gói là `Document` (R1).** Nhóm B, `signed_filed`, hai cờ khách tắt, đĩa `private`; sinh lại =
  version mới của cùng tài liệu, chỉ tệp version mới nhất được giữ (dòng `documents` và
  `document_downloads` của version cũ giữ nguyên). **Quyết định của task:** nếu version cũ đang mở
  cho khách thì bị gỡ khỏi cổng khách (`signed_filed`, hai cờ tắt) cùng lúc tệp bị xoá — nếu không,
  khách bấm một liên kết tải trỏ vào tệp đã xoá. Gói mới phải được công bố lại qua `PublishDocument`.
- **Nội dung gói (R8), đối chiếu mã.** A: mọi tệp của version mới nhất của đầu mục `accepted` (đầu
  mục chưa duyệt/bị từ chối/không áp dụng/đã xoá → không có gì); **quyết định của task:** tài liệu
  nhóm A nhân sự nộp thay KHÔNG gắn đầu mục nào cũng vào gói (không có luồng duyệt để "chưa chấp
  nhận"). Danh sách TRẮNG trạng thái `signed_filed`/`published` áp cho MỌI nhóm vào gói (A, B, C) —
  một trạng thái mới thêm sau (Task 7 "đã rút") tự nằm ngoài gói ở mọi nhóm cho tới khi có người cố
  ý thêm. Với nhóm A, luật này không bớt gì của đường thường (A luôn `published`), nhưng bắt một tài
  liệu ĐỔI NHÓM sang A: `RegroupDocument` chỉ đổi `group`, giữ `status`, nên một bản D → A vẫn
  `internal_draft` — khách chưa từng được thấy nó, và nó không vào gói. Version mới nhất của đầu mục
  tính trên mọi version rồi mới lọc: version mới nhất chưa phát hành/bị rút thì đầu mục không có gì
  trong gói, không lùi về bản đã bị thay. **Cho Task 7:** chỉ cần thêm case `retracted` vào enum,
  `CollectHandoverEntries::RELEASED_STATUSES` đã loại nó. Không bao giờ: D, đã xoá mềm,
  chính gói ở mọi version (đi ngược `parent_document_id` từ `handover_document_id`), không có tệp.
  Tên entry `<nhóm>/<NN>-<tên>.<đuôi>`, đuôi lấy từ tệp thật
  (`CollectHandoverEntries::entryName()`, theo thứ tự): UTF-8 hợp lệ (`mb_scrub`), `/` và `\` đổi
  thành `-` (tiêu đề văn bản pháp lý đầy `/`; `basename()` của `safeName` sẽ cắt còn "DS-ST"), NFC;
  rồi `FileGuard::safeName(<tiêu đề>.<đuôi thật>)` — **không** `safeName(<tiêu đề>)`, vì
  `safeName()` coi phần sau dấu chấm CUỐI là đuôi tệp và cắt nó còn 20 byte (vòng sửa 1: "… ngày
  05.3.2026 với Toà án nhân dân quận Hải Châu" thành "… với Toà án", "Đơn. Yêu cầu bồi thường…"
  bị cắt giữa chữ "ư" thành "?"); bỏ lại đuôi; cắt 100 ký tự; ký tự Windows cấm đổi thành `-` SAU
  mọi bước cắt.
- **Trần một tệp của kho, `MEDIA_MAX_FILE_SIZE_MB` (vòng sửa 1).** `config/media-library.php`
  giữ mặc định 10 MB của gói medialibrary, nên không gói bàn giao nào quá 10 MB lưu được (job thử
  lại, hỏng y hệt, rồi ghi "lỗi hệ thống"), và tệp tải lên 10–20 MB qua `FileGuard`
  (`UPLOAD_MAX_MB`) rồi hỏng ở medialibrary. Nay `max_file_size` = `MEDIA_MAX_FILE_SIZE_MB` (mặc
  định 2048; trống/không hợp lệ → 2048; không bao giờ thấp hơn `UPLOAD_MAX_MB`, để `FileGuard` là
  cổng tải lên duy nhất). Gói vượt trần → lỗi có tên `too_large` chỉ đúng biến này; đĩa từ chối
  ghi → `store_failed`; thư mục tạm không ghi được → `work_dir_failed`; cả ba không thử lại.
  **Cho làn M8b (Task 7, triển khai):** thêm `MEDIA_MAX_FILE_SIZE_MB` vào `.env` của máy chủ (đã
  có ở `.env.example`).
- **Khoá trong lúc chép gói.** Medialibrary không đổi tên zip vào đĩa `private` mà CHÉP nó
  (`fopen()` + `put()` cả luồng, rồi xoá nguồn), kể cả khi cùng ổ; việc chép nằm trong transaction
  giữ khoá dòng `matters` (rồi `matter_archives`) của đúng vụ đó. Một thao tác khác khoá vụ đó
  (chuyển giai đoạn; ghi tiền sau khi M9 merge) chờ tối đa bằng thời gian chép gói lớn nhất (trần
  cỡ gói chia tốc độ ghi đĩa); quá `innodb_lock_wait_timeout` (50 giây) thì thao tác ĐANG CHỜ hỏng,
  gói vẫn xong. Đưa việc chép ra ngoài khoá thì mất bảo đảm "không tệp mồ côi" — giới hạn được ghi
  lại, chưa gỡ (rà soát Task 4, m1).
- **MUC-LUC.pdf (R3).** dompdf qua `barryvdh/laravel-dompdf` 3.1.2 (gói mới DUY NHẤT, kèm dompdf
  3.1.6, php-font-lib, php-svg-lib, sabberworm/php-css-parser). Font DejaVu Sans đi kèm dompdf,
  nhúng nguyên (cắt font tốn ~2,5 giây CPU mỗi lần dựng, đo trong container); cache font ở
  `storage/app/dompdf-fonts` (ngoài git). Thư mục tạm dựng gói cấu hình được (`HANDOVER_WORK_DIR`,
  mặc định `storage/app/handover-tmp`) để trỏ tới ổ rộng hơn trên shared hosting. Nội dung: thông tin vụ, danh sách đánh số khớp entry,
  toàn bộ dòng tiến độ ĐÃ CÔNG BỐ với đúng các trường cổng khách hiện (giai đoạn, ngày, nội dung
  công khai, bước tiếp theo, việc khách cần làm), chân trang bốn thông tin pháp lý qua
  `BrandFooter` (bỏ hẳn dòng trống). Truy vấn dòng tiến độ chọn cột TƯỜNG MINH — `internal_note`
  không được nạp vào bộ nhớ (test bắt SQL). Test trích chữ từ PDF bằng một bộ trích nhỏ phía test
  (`tests/Support/PdfText.php`: giải nén FlateDecode, đọc CMap ToUnicode, giải mã `Tj`/`TJ`) — không
  thêm gói thứ hai. **Cho M9 Task 10 (bảng kê thanh toán trong MUC-LUC.pdf):** mỗi khối của PDF là
  một partial trong `resources/views/handover/partials/` (`matter-info`, `documents`, `timeline`,
  `footer`); thêm một khối = một partial dùng lại CSS chung của `handover/index.blade.php` + một
  `@include` + một khoá dữ liệu mới trong `RenderHandoverIndex::handle()`. Bảng kê tiền của một vụ
  `restricted` chỉ đi vào gói của chính vụ đó, và gói chỉ tới tay khách qua `PublishDocument`.
- **Audit `data_exported`.** Khi gói sinh xong (subject = tài liệu gói) và mỗi lần tải gói ở
  `DocumentDownloadController::recordDownload()` (nhận biết qua `MatterArchive::isHandoverDocument()`,
  bất kỳ version nào), THÊM vào `document_downloaded`, không thay. Thêm nhãn `handover_package_requested`
  và `handover_package_failed` vào `lang/vi/activity.php`.
- **Báo kết quả.** Xong: `SendHandoverPackageReady` (hàng `default`, sau commit) → thông báo trong hệ
  thống + MỘT thư xếp hàng (`Mail::queue`, mẫu mới `staff.handover_ready`) cho mỗi người nhận;
  người nhận = luật sư phụ trách và người bấm qua `ResolveStaffRecipients`, tính lại lúc gửi. Thất
  bại hẳn: `handover_status = failed` + câu tiếng Việt (KHÔNG thông điệp thô của exception lạ — chỉ ở
  log máy chủ), audit, thông báo trong hệ thống.
- **UI.** Khối "Gói bàn giao hồ sơ" ở tab Tổng quan (trạng thái, lúc bấm, người bấm hoặc "Tự động khi
  vụ kết thúc", lúc xong, tài liệu + phiên bản, câu lỗi, lời nhắc khi kẹt) và header action "Sinh
  (lại) gói bàn giao" trên `ViewMatter`. Test qua Livewire. Nút chỉ hiện khi vụ ĐANG kết thúc
  (`closed_at` có giá trị): bản ghi lưu trữ còn nguyên khi admin mở lại vụ, và nút ở đó chỉ dẫn tới
  lỗi "chưa kết thúc" — khối trạng thái gói cũ vẫn hiện. Người bấm đã bị xoá mềm thì cột "Người
  yêu cầu" là "—", không phải "Tự động" ("Tự động" chỉ khi `handover_requested_by` NULL). Chống bấm
  trùng có HAI lớp: nút `disabled` khi đang chạy, và `RequestHandoverPackage` kiểm lại dưới khoá
  (`HandoverPackageBusy`); test Livewire "trang mở từ trước khi người khác bấm" chỉ đỏ khi bỏ CẢ
  hai (bỏ một lớp thì lớp kia giữ — lớp Action có probe riêng ở `RequestHandoverPackageTest`).
- **Dữ liệu mẫu (cho Task 11).** `MatterSeeder::closedMatter()` (Task 3) đóng vụ bằng cách ghi thẳng
  `closed_at` rồi gọi `SyncMatterArchive`, không qua `TransitionMatterStage` — nên listener không chạy
  và seed KHÔNG xếp job gói nào: vụ mẫu đã kết thúc hiện "Chưa sinh" kèm nút "Sinh gói bàn giao".
  Sinh gói thật từ seed: bấm nút (hoặc `RequestHandoverPackage`), rồi chạy `queue:work handover
  --queue=handover --stop-when-empty` trên CSDL của làn.
- **M9 (hook `deleting` từ chối xoá mềm vụ còn công nợ).** Task này không xoá hay lưu trữ vụ việc
  nào: nó chỉ đọc vụ (kể cả `withTrashed()` để báo "vụ không còn") và ghi `matter_archives`/`documents`.
  Không có đường nào ở đây cần đi qua hook đó.
- **Thư `client.document_published` KHÔNG có trong làn này** (là của làn M6 Task 3, chưa có trên
  base). Test chỉ khẳng định công bố gói đi qua đúng `PublishDocument` và phát `DocumentPublished`;
  sau khi làn M6 merge, thư tự đi.
- **Cho làn M8b (Task 5/7).** dompdf đòi `ext-dom` (và `ext-mbstring`); việc nén gói dùng
  `ext-zip` (`ZipArchive`); `--timeout` của worker hàng `handover` cần `ext-pcntl` — cả bốn vào danh
  sách extension của M8 Task 7. **Quyết định còn mở cho M8 Task 5:** tài liệu gói bàn giao (nhóm B, `signed_filed`) là BẢN SAO THỨ HAI của mọi tệp A/B/C, và
  M8 sao lưu thêm một bản nữa — quyết có loại tài liệu gói khỏi sao lưu hay không (gói sinh lại được
  từ dữ liệu gốc, nên loại nó là hợp lý; nhận biết qua `MatterArchive::handoverDocumentIds()`).
  Hạn mức đĩa của shared hosting sẽ chạm trần ở vụ lớn nhất trước tiên.
- **Không có probe/test cho:** `Gate::allows('view', $record->archive)` trong `visible()` của khối
  (tương đương với quyền vào trang: `MatterArchivePolicy::view` = xem được vụ, cùng điều kiện vào
  `ViewMatter`); hai `whereNotIn('id', $excluded)` của truy vấn nhóm A (gói luôn là nhóm B nên không
  bao giờ nằm trong truy vấn A — phòng thủ); cờ `FL_ENC_UTF_8` bị bỏ thì libzip tự đoán ra UTF-8
  với tên hợp lệ (probe thay bằng `FL_ENC_CP437` mới đỏ — cờ giữ lại để ghi rõ ý định).

### Task 5 — `ExpireClientAccess` (R4, SPEC §6.12)

Quá `client_access_until` thì vụ việc rời cổng khách ở cả hai tầng; một tác vụ hằng ngày vô hiệu
hoá tài khoản cổng theo đúng điều kiện R4. Đính chính SPEC §6.12 ghi ngay dưới đoạn `ExpireClientAccess`.

- **Định nghĩa hết hạn (một luật, hai cách nói).** Dòng `matter_archives` chưa xoá mềm, với
  `client_access_until` khác null và < hôm nay theo giờ ứng dụng: khách xem được HẾT ngày
  `client_access_until`, mất quyền từ 00:00 hôm sau. Nói bằng `where` ở
  `MatterArchive::scopeClientAccessExpired()` (`whereDate`, vì cast `date` trên SQLite ghi cả phần
  giờ) và bằng thuộc tính ở `MatterArchive::isClientAccessExpired()` (so chuỗi `Y-m-d`). Hai câu
  không gọi nhau.
- **Tầng truy vấn.** Điều kiện thứ năm của `Matter::applyClientPortalConstraints()`:
  `whereDoesntHave('archive', … ->withoutGlobalScope(ClientPortalScope::class)->clientAccessExpired())`.
  Gỡ `ClientPortalScope` trong truy vấn con là bắt buộc: `MatterArchive` chặn sạch (`1 = 0`) dưới
  phiên khách, và không gỡ thì `whereDoesntHave` luôn đúng — test "tầng truy vấn một mình … dưới
  phiên khách đang mở" đỏ khi bỏ nó. Mọi model con (tài liệu, dòng tiến độ, mốc hạn, đầu mục, yêu
  cầu, biên bản đã xem) nhận điều kiện qua `whereHas('matter')` sẵn có — không sửa model con nào.
- **Tầng policy.** Điều kiện thứ năm của `MatterPolicy::releasedToPortal()` (docblock "bốn" → "năm"
  ở `view()` và `releasedToPortal()`), có đường trong bộ nhớ như nhánh `client`. **Quyết định của
  task: quan hệ mới `Matter::clientAccessArchive()`** (gỡ `ClientPortalScope` ngay trong định nghĩa),
  không nạp sẵn `archive`: `archive` nạp dưới phiên khách luôn là `null` (scope `1 = 0` của
  `MatterArchive`), và đường trong bộ nhớ sẽ đọc `null` thành "không hết hạn" — thủng tầng policy
  đúng ở hai màn hình dùng đường nhanh. `MyMatters::buildCards()` và `SubmitDocument::resolveMatter()`
  nạp sẵn `clientAccessArchive`; ngân sách `MyMattersTest` đổi phần cố định 5 → 6 (độ dốc vẫn 2
  truy vấn mỗi thẻ), `SubmitDocumentTest` không đổi (độ dốc ≤ 2 mỗi đầu mục). `MatterProgress`,
  `MyRequests` không nạp sẵn (một truy vấn dự phòng cho mỗi lần hỏi `Gate` trên vụ — cùng giá của
  nhánh `client` dự phòng ở đó).
- **Không sửa dữ liệu vụ việc.** Không cột nào của `matters`/`matter_archives`/`documents` bị đổi
  (test so ảnh chụp dòng trước/sau, cả khi chỉ qua ngày lẫn khi job chạy). Admin mở trang vụ như cũ;
  luật sư phụ trách vẫn tải được tệp. URL tải có chữ ký phát lúc 23:58 ngày cuối, dùng lúc 00:01 hôm
  sau (chữ ký còn hợp lệ) → 404 từ policy. Mở lại vụ (`client_access_until` về null qua
  `TransitionMatterStage` thật) đưa vụ về lại cổng.
- **Tác vụ `App\Actions\Schedule\ExpireClientAccess`.** Mục lịch `client-access.expire`, 00:30 giờ
  Việt Nam hằng ngày, `withoutOverlapping(60)`, ở cuối `routes/console.php`, cộng một dòng `use` MỚI
  ở đầu tệp (không sửa dòng nào có sẵn; Pint — `fully_qualified_strict_types` — không nhận tên lớp
  đầy đủ ở đó; một làn khác thêm `use` ngay cạnh khi merge là xung đột hai dòng liền kề, gỡ tay
  ngay). Việc của nó CHỈ là vô hiệu hoá tài khoản; vụ rời cổng nhờ hai tầng, đúng từ 00:00 ngày sau
  `client_access_until` dù tác vụ chưa chạy — một đêm cron lỡ chỉ hoãn việc khoá tài khoản.
  - Ai: mọi tài khoản đang hoạt động (chưa xoá mềm) của khách có ÍT NHẤT MỘT vụ (chưa xoá mềm) đã
    hết hạn VÀ không còn vụ nào hiển thị trên cổng — vế 2 hỏi bằng chính định nghĩa cổng
    (`ClientPortalScope::actingAs()` + `Matter::query()->exists()`). Khách mới có vụ đầu tiên chưa
    công bố không bao giờ bị đụng. Một vụ đã hết hạn rồi bị xoá mềm không còn tính cho vế 1 (có
    test ghim; khoá tài khoản không dựa trên một hồ sơ văn phòng đã rút lại).
  - Lưu từng model (`save()`, `LogsActivity` ghi `is_active` cũ/mới) + một dòng
    `portal_account_deactivated` (thuộc tính chỉ `reason`, không mã/tiêu đề vụ; người thực hiện
    trống = "Hệ thống" khi chạy từ scheduler). Idempotent: tập ứng viên chỉ gồm khách còn tài khoản
    đang hoạt động, nên lần chạy sau không mở transaction nào cho khách đã xử lý (test đếm
    `TransactionBeginning`), và số transaction mỗi đêm không lớn dần theo năm tháng.
  - Một khách một transaction: câu lệnh đầu tiên khoá mọi dòng `matters` của khách (thứ tự khoá toàn
    cục), rồi ĐỌC LẠI cả hai vế dưới khoá (test: một vụ vừa công bố / vừa mở lại giữa lúc tác vụ xử
    lý khách khác giữ tài khoản của khách đó). Lỗi ở một khách: `report()`, rollback trọn khách đó,
    các khách sau vẫn chạy (`['deactivated' => n, 'failed' => m]`). Không thư nào.
  - Mọi truy vấn của tác vụ gỡ `ClientPortalScope` tường minh (test chạy tác vụ trong lúc một phiên
    cổng đang mở trong cùng tiến trình; phiên đó mất ở request kế tiếp qua
    `EnsurePortalAccountIsActive`).
- **Cần chủ văn phòng/nhân sự biết.** (1) Tài khoản bị vô hiệu KHÔNG tự bật lại khi vụ được mở lại
  — nhân sự bật tay ở trang tài khoản cổng. (2) R4 theo đúng chữ: một khách CŨ có vụ đã hết hạn và
  vừa có vụ MỚI chưa công bố thoả cả hai vế nên bị vô hiệu hoá (có test ghim); nếu nhân sự bật lại
  tài khoản TRƯỚC khi công bố vụ mới thì đêm sau tác vụ lại khoá — công bố vụ mới trước (hoặc cùng
  lúc) rồi mới bật tài khoản. Nếu muốn "khách còn vụ đang mở thì không khoá", đó là một thay đổi
  của R4, cần phán quyết.
- **M9 (hook `deleting` từ chối xoá mềm vụ còn công nợ).** Task này không xoá, không lưu trữ vụ việc
  nào — chỉ đọc vụ và ghi `client_users`. Không đường nào ở đây cần đi qua hook đó.
- **Việc cho lần gộp `main` (M6 phần còn lại) ở Task 11.** Các Action thư cho khách về MỘT vụ việc
  hỏi "vụ còn trên cổng" bằng `where('is_published_to_portal', true)`, không bằng định nghĩa cổng:
  trong làn này `NotifyClientOfStageUpdate::stillReleasedToPortal()`; trên `main` thêm
  `NotifyClientOfRequestAnswered`, `NotifyClientOfChecklistItemRejected` (M6 cố ý gửi cả cho vụ
  đã đóng "còn trên cổng") và `NotifyClientOfDocumentPublished` (cái này còn hỏi `open()`, nên vụ
  đã đóng không nhận). Khách còn một vụ khác trên cổng thì tài khoản vẫn hoạt động, nên một thư về
  vụ ĐÃ hết hạn tra cứu vẫn đi, kèm liên kết tới một trang trả 404. Không lộ vụ cho người ngoài
  (người nhận là chính khách của vụ), nhưng trái với "vụ rời cổng". Khi gộp, cho các chỗ đó hỏi
  bằng định nghĩa cổng (`ClientPortalScope::actingAs($account, …)` hoặc
  `Gate::forUser($account)->allows('view', $matter)`), không thêm một định nghĩa thứ ba.
- **Không có probe hành vi cho:** câu khoá `lockForUpdate()` các dòng `matters` đầu transaction
  (SQLite bỏ qua `FOR UPDATE`; câu lệnh chạy thật trên MariaDB khi chạy `test:mariadb` tệp này) và
  `orderBy`/`distinct` của tập ứng viên (thứ tự xử lý, không phải điều kiện).
- **Nguyên nhân của họ test chập chờn "thiếu giai đoạn" (có từ trước, không sửa ở task này).**
  `MatterTypeFactory` bốc `code` ngẫu nhiên hai chữ (`lexify('??')`) và `withStages()` dựng giai
  đoạn theo `StagePresets::for($type->code)`. Khi mã bốc trúng `HS` hoặc `DN` (2/676), loại vụ nhận
  bộ hình sự/doanh nghiệp: không có `on_hold`, không có `enforcement → closed`. Đó là chỗ
  `TransitionStageActionTest` "thiếu `on_hold`" (Ghi chú M6.5) và `StaleMattersWidgetTest`
  (`closed_at` null) đỏ lẻ tẻ khi chạy cả bộ. Sửa: loại hai mã preset khỏi lần bốc ngẫu nhiên.

### Task 6 — `FlagRetentionExpiry` và ghi quyết định tiêu huỷ (R5, SPEC §6.12)

Hệ thống không bao giờ tự xoá hồ sơ. Một tác vụ hằng ngày CẢNH BÁO quản trị khi hồ sơ quá hạn lưu
trữ; một Action GHI LẠI quyết định tiêu huỷ đã lập biên bản ngoài hệ thống. Đính chính SPEC §6.12
ghi ngay dưới đoạn `FlagRetentionExpiry`.

- **Định nghĩa quá hạn lưu trữ (một luật, hai cách nói).** Cùng biên với hạn tra cứu của Task 5:
  hồ sơ còn trong hạn HẾT ngày `retention_until`, quá hạn từ 00:00 hôm sau.
  `MatterArchive::scopeRetentionExpired()` (`whereDate`) và `MatterArchive::isRetentionExpired()`
  (so chuỗi `Y-m-d`; chưa có ngày thì không bao giờ quá hạn).
- **Tác vụ `App\Actions\Schedule\FlagRetentionExpiry`.** Mục lịch `retention.flag`, 01:00 giờ Việt
  Nam hằng ngày (không dồn vào lượt 00:30 của `client-access.expire`), `withoutOverlapping(60)`,
  thêm ở cuối `routes/console.php` cùng một dòng `use` mới. Không transaction, không khoá: nó không
  sửa dòng nào, chỉ ghi thông báo.
  - Hồ sơ nào: archive chưa xoá mềm, quá hạn, `destroyed_at` rỗng, vụ chưa xoá mềm và đang đóng.
    Vụ đã mở lại giữ `retention_until` của lần đóng trước trên archive (`SyncMatterArchive` chỉ xoá
    `client_access_until`), nhưng không bị cảnh báo. Vụ đã xoá mềm cũng không: trang vụ việc không
    mở được nó, và Action từ chối nó.
  - Ai nhận: mọi admin đang hoạt động — hàm mới `ResolveStaffRecipients::activeAdminsFor()`, lọc
    qua đúng `qualify()` (`is_active`, chưa xoá mềm, `Gate::view()`), không chuỗi dự phòng. Vụ
    `restricted` vì vậy chỉ tới admin. Không phải `supervisorsFor()`: quyết định tiêu huỷ là việc
    của quản trị, không của trưởng phòng.
  - Cảnh báo: `App\Notifications\Staff\RetentionExpiryAlert`, kênh `database` (chuông của panel
    admin), cùng hình dạng `DeadlineOverdueAlert`, nút "Mở vụ việc". Thân thông báo có mã hồ sơ và
    ngày hết hạn, không tiêu đề vụ. Không thư nào.
  - Không lặp (mẫu B-M1): khoá chống lặp là (loại thông báo, `viewData.matter_id`,
    `viewData.retention_until`). Admin thêm sau nhận một lần; hồ sơ đóng lại với hạn mới rồi quá hạn
    lần nữa thì là một lần mới.
  - Lỗi ở một người nhận hay cả một hồ sơ: `report()`, các hồ sơ sau vẫn chạy
    (`['flagged', 'notified', 'failed']`). Mọi truy vấn gỡ `ClientPortalScope` tường minh (test chạy
    tác vụ dưới một phiên cổng đang mở trong cùng tiến trình).
- **Action `App\Actions\Matter\RecordMatterDestruction`** (nút "Ghi quyết định tiêu huỷ" ở header
  trang vụ việc, cộng khối "Lưu trữ hồ sơ" trên tab Tổng quan).
  - Chỉ admin: ability mới `MatterPolicy::recordDestruction` (vai trò admin, cùng luật `delete()`;
    không thêm quyền thứ 14 vào `App\Enums\Permission`). Action hỏi `Gate` TƯỜNG MINH trên người
    thực hiện ĐỌC LẠI từ CSDL (admin vừa bị gỡ vai, vô hiệu hoá hay xoá trong lúc hộp thoại mở bị
    từ chối). Vụ không tồn tại cũng là `AuthorizationException`.
  - Một transaction; câu đầu tiên khoá dòng `matters` (kể cả đã xoá mềm), rồi khoá `matter_archives`
    — có test thứ tự khoá chạy trên MariaDB.
  - Từ chối (câu tiếng Việt, `MatterDestructionNotAllowed`): vụ đã xoá mềm, không có archive (hoặc
    archive đã xoá mềm), vụ đang mở, chưa quá hạn (kể cả đúng ngày cuối), đã ghi rồi.
  - Lý do bắt buộc 20–5000 ký tự (`mb_strlen` sau `trim`), số biên bản bắt buộc, tối đa 50 ký tự
    (= `varchar(50)`); form có cùng `minLength`/`maxLength`.
  - Ghi bốn cột + audit `matter_destruction_recorded` (thuộc tính: id vụ, id archive, số biên bản,
    ngày hết hạn — không lý do, không mã/tiêu đề vụ; nhãn ở `lang/vi/activity.php`). **Không xoá
    gì**: không `delete()`, không `forceDelete()`, không xoá media.
  - `destroyed_by` là admin đã GHI quyết định; người phê duyệt trên biên bản nêu trong lý do (ghi ở
    đính chính SPEC). Quan hệ `MatterArchive::destroyer()` có `withTrashed()`: tài khoản người ghi bị
    xoá mềm sau này (nghỉ việc) không làm tên họ biến khỏi khối "Lưu trữ hồ sơ".
- **Test cấu trúc `RecordsAreNeverForceDeletedTest`.** Tách token bằng `token_get_all()` và chỉ bắt
  LỜI GỌI (`->`/`?->`/`::` + `forceDelete`/`forceDeleteQuietly`/`forceDestroy` + `(`) trong `app/`,
  `routes/`, `database/seeders/`. Phương thức policy `forceDelete()`, `ForceDeleteBulkAction`, chuỗi
  và chú thích không khớp. Kiểu của vế trái không đọc được bằng phân tích tĩnh, nên MỌI lời gọi đều
  bị coi là xoá hồ sơ; ngoại lệ hợp lệ một ngày nào đó phải vào `$allowed` kèm lý do. Xanh trên mã
  nền, đỏ khi cài một lời gọi thử ở cả ba thư mục.
- **M9 (hook `deleting` từ chối xoá mềm vụ còn công nợ).** Task này không xoá, không lưu trữ vụ việc
  nào, nên không đường nào ở đây chạm hook đó.
- **Cần chủ văn phòng biết.** Hạn lưu trữ lấy từ cấu hình (`retention_until` ghi khi đóng vụ, Task 3).
  Cảnh báo chỉ là lời nhắc; nếu văn phòng quyết định GIỮ hồ sơ lâu hơn, hôm nay không có nút "gia
  hạn lưu trữ" — cảnh báo đã đọc thì nằm trong chuông, không lặp lại. Nếu cần gia hạn, đó là một
  tính năng mới (M10, cùng chính sách lưu trữ).
- **Mutation probe (64).** 61 đỏ. Ba xanh là mutant tương đương, không phải lỗ hổng test:
  - bỏ `archive !== null` ở khối "Lưu trữ hồ sơ": vế `Gate::allows('view', null)` còn lại cũng từ
    chối (không có policy cho `null`), nên khối vẫn ẩn;
  - bỏ `Gate::allows('view', $record->archive)`: `MatterArchivePolicy::view` là "xem được vụ cha",
    đúng điều kiện để mở trang vụ việc, nên không ai tới được khối mà lại không qua vế này;
  - bỏ `instanceof MatterArchive` ở dòng nhắc quá hạn: chỉ là chốt `null`, dòng đó nằm trong khối
    vốn chỉ hiện khi có bản ghi lưu trữ.
- **Vụ đã xoá mềm mà có bản ghi lưu trữ** không bị cảnh báo. Hôm nay không đường nào tạo ra trường
  hợp đó: admin không có nút xoá vụ việc, và `CancelMatter` chỉ huỷ vụ mở nhầm, vốn không bao giờ có
  bản ghi lưu trữ.

### Task 7 — Rút lại tài liệu đã công bố (`RetractDocument`)

Món nợ từ M4 (`docs/docs-6`): tài liệu công bố nhầm nay có một đường rút đúng nghiệp vụ. Đính chính
SPEC §4.11 (trạng thái thứ năm) và §4.12 (khoá ngoại) ghi ngay dưới mục tương ứng.

- **Bước 1 (commit riêng, trước Action).** `document_downloads.document_id` thôi `cascadeOnDelete`,
  thành `restrictOnDelete` (migration `2026_09_28_070701_…`): xoá cứng một tài liệu đã có lượt tải
  bị CSDL từ chối, dòng tải còn nguyên. `DocumentDownloadsForeignKeyTest` (cả SQLite lẫn MariaDB).
- **Bước 2.** Migration `2026_09_28_070702_…` thêm `documents.retracted_at`, `retracted_by` (FK
  users, `nullOnDelete`), `retraction_reason` (text). Case `DocumentStatus::Retracted` (nhãn "Đã rút
  lại"). Chuỗi mới ở tệp lang riêng `lang/vi/retraction.php`; `enums.php`/`activity.php` chỉ thêm
  khối `// M7 Task 7`.
- **Action `App\Actions\Document\RetractDocument`.** Một transaction; câu đầu tiên khoá dòng
  `matters` (tìm bằng truy vấn con trên `documents.matter_id`, không tin caller), rồi khoá
  `documents`; người thực hiện đọc lại từ CSDL; quyền `DocumentPolicy::publish` hỏi lại dưới khoá
  (trợ lý không rút được). Từ chối bằng câu trạng thái (`DocumentNotRetractable`): không còn tồn tại,
  đã xoá mềm, vụ đã xoá mềm, đã rút rồi (quyết định đầu giữ nguyên), không đang ra tới khách. Lý do
  bắt buộc, gỡ khoảng trắng Unicode hai đầu, `mb_strlen` 20–5000. Ghi `status = retracted`, tắt hai
  cờ khách, ghi người/lúc/lý do; giữ tệp, `document_downloads`, `published_at/by`. Audit
  `document_retracted` mang `client_downloads` (số lượt tải CỦA KHÁCH trước lúc rút), không mang lý
  do hay tiêu đề. Không thư, không thông báo.
- **Vị từ "đang ra tới khách" chọn `Document::isReleasedToPortal()`**, một vị từ dùng chung cho
  Action, lời từ chối của "Chuyển nhóm", `DocumentPolicy::delete()` và điều kiện hiện nút. Không
  chọn `wasPublishedToClient()` vì nó thiếu vế nhóm D và xoá mềm. Vị từ không hỏi vụ việc: tài liệu
  đã công bố trên một vụ đang ẩn khỏi portal vẫn rút được (nó trở lại tầm mắt khách khi vụ lên lại).
- **Một đường rút duy nhất.**
  - `RegroupDocument` từ chối đưa vào nhóm D một tài liệu đang ra tới khách, câu chỉ tới nút "Rút
    lại" và nói ai bấm được. Phán quyết "vào D luôn được" của M6.5 Task 16 nay chỉ đúng cho tài liệu
    không đang ra tới khách và chưa bị rút. Câu tương ứng ở mục M4 "Việc hoãn lại" và ở ghi chú M6.5
    (hai "đường rút tạm") không sửa ở đây (luật làn: chỉ viết trong Ghi chú M7) — đọc chúng với đính
    chính này.
  - Thêm ngoài brief, cùng lý lẽ với quyền xoá: tài liệu ĐÃ RÚT cũng không vào nhóm D
    (`DocumentGroupNotChangeable::retractedStaysVisibleToClient()`), vì dòng giải thích của khách đọc
    từ chính bản ghi đó và không bao giờ trả nhóm D. Đổi giữa A/B/C vẫn được (dòng rút còn nguyên;
    tài liệu đã rút rời nhóm B sang A/C cần lý do sửa nhầm nhóm, vì `hasClearedIssuedLifecycle()` sai
    với trạng thái `retracted`).
  - `DocumentPolicy::delete()` trả `false` cho tài liệu đang ra tới khách hoặc đã rút, kể cả admin.
  - `PublishDocument` từ chối tài liệu đã rút (`DocumentNotPublishable::retracted`): muốn đưa lại
    cho khách thì tải lên một bản mới. Các bước vòng đời B (trình duyệt, đã ký, trả về nháp) vốn chỉ
    nhận đúng một trạng thái nguồn nên không chạm được `retracted`.
  - Test cũ dựng "rút bằng cách vào D" (dữ liệu có từ trước M7) nay ghi thẳng model, hook `saving` vẫn
    hạ cờ; `RegroupDocumentTest`, `PublishDocumentTest`, `DocumentDownloadTest`,
    `DocumentsRelationManagerTest`, `DocumentAccessTest` cập nhật theo.
- **Cổng khách.** Ở khối Tài liệu của `MatterProgress`, sau các tài liệu còn hiệu lực: tiêu đề (gạch
  ngang — **đã bỏ ở rà soát cuối, vòng sửa 1, C1**: nay là nhãn trung tính, hình chiếu không còn tiêu
  đề, xem mục cuối), "Văn phòng đã rút lại tài liệu này. Lý do: …", ngày rút; không liên kết tải, không người
  rút. Vụ chỉ còn tài liệu đã rút không hiện "chưa có tài liệu". Scope portal của `Document` KHÔNG
  nới: dòng rút đi qua đường hẹp `Document::retractionNoticesFor()` (gỡ đúng `ClientPortalScope`,
  chỉ `retracted`, không nhóm D, chưa xoá mềm, đúng vụ, vụ qua `Matter::applyClientPortalConstraints`
  của chính khách — gồm hạn tra cứu Task 5) cộng ability `DocumentPolicy::viewRetractionNotice` (chỉ
  khách, phát biểu bằng thuộc tính) cộng hình chiếu hẹp (tiêu đề, lý do, ngày). Đường dẫn tải ký trước
  lúc rút trả 404; nhân sự vẫn tải được (tệp là bằng chứng, lượt tải vẫn ghi).
- **Màn hình nội bộ.** Nút "Rút lại" trên tab Tài liệu (`authorize` = `publish`, `visible` =
  `isReleasedToPortal()`), ô lý do `maxLength(5000)` = trần của Action cho cột `text`, KHÔNG
  `minLength` (ngưỡng ở một chỗ, Action, lời từ chối về đúng ô). Nhãn trạng thái "Đã rút lại" màu đỏ,
  chú thích có lúc rút, người rút (hoặc "tài khoản đã xoá") và lý do; cột "Khách thấy" ghi "Đã rút
  khỏi cổng khách"; nút "Công bố" ẩn trên dòng đã rút.
- **Gói bàn giao (Task 4).** Danh sách trắng trạng thái của `CollectHandoverEntries` đã loại
  `retracted`; thêm test giải nén ghim điều đó ở cả ba nhóm A/B/C.
  - Chỗ hai task chạm nhau, KHÔNG sửa ở Task 7 (ghi cho rà soát cuối M7): sinh lại gói vẫn theo
    luật Task 4 "chỉ giữ version mới nhất". (1) Version cũ ĐÃ RÚT giữ nguyên `retracted` và dòng
    giải thích của khách (`wasPublishedToClient()` sai với nó), nhưng TỆP của nó bị xoá sau commit
    như mọi version cũ của gói — dòng `documents` và `document_downloads` còn nguyên. (2) Version cũ
    ĐANG công bố bị Task 4 hạ về `signed_filed` và tắt cờ khách, không lý do, không dòng rút: đó là
    thay bằng bản mới chứ không phải rút, nhưng khách thấy gói biến mất cho tới khi luật sư công bố
    bản mới. Nếu chủ nhiệm muốn "một đường rút duy nhất" phủ cả trường hợp này, sửa ở Task 4.
- **Chỗ gắn cho M9.** Làn M9 yêu cầu `RetractDocument` và `DocumentPolicy::delete()` từ chối tài liệu
  đang được `payments.receipt_document_id` / `contract_amendments.document_id` trỏ tới. Hai bảng đó
  chưa có trên làn này; M9 thêm phần chặn lúc merge, ở bước 6 của Action (ngay sau `notReleased`,
  dưới khoá `documents`; khoá bảng tiền tệ nếu cần đi sau `matters`). Ghi trong docblock Action. Task
  này không xoá hay lưu trữ vụ việc nào, nên không chạm hook `deleting` của M9.

### Task 11 — Nghiệm thu làn (cổng merge, 2026-10-03)

Base `7d670ce` (sau khi controller gộp `m7-extras`, Task 10, 8, 9, vào `m7-handover`). Task 11 KHÔNG
merge vào `main`; dòng M7 trong bảng milestone giữ ⬜, controller sửa lúc merge. Làn m7 làm Task 1–7
và 11; làn m7b làm Task 10, 8, 9 (mục "Làn m7b" ở đầu "Ghi chú M7").

#### Việc đã làm, theo task (dải `d2de674..m7-handover`)

- **Task 1** (`1d511e7`, `e82fc55`, `e235013`): thư tổng hợp mốc hạn cho lead mới
  (`SendReassignmentDigest`, mẫu `staff.matter_reassigned`). Thư chỉ liệt kê mốc CHƯA xong vừa
  chuyển, dựng lại lúc gửi, xếp hàng sau commit. Đính chính R10 (§6.11, §11).
- **Task 2** (`5dc7fc6`, `bc9cb65`, `cc3ee7a`): trang "Bàn giao hàng loạt" cho admin và trưởng phòng,
  `ReassignMatters`. Mỗi vụ một transaction, cả lô một thư. `expectedLeadId` được kiểm dưới khoá. Thông
  điệp chặn nghỉ việc có liên kết tới trang này.
- **Task 3** (`4f5336e`, `2213c99`): sự kiện `MatterStageChanged` (sau commit) gọi `SyncMatterArchive`
  (khoá `matters` trước, idempotent). Danh mục của vụ đã đóng chỉ đọc, chặn ở Action, cả khi khách
  nộp lẫn khi nhân sự nộp thay. Thêm vụ mẫu đã kết thúc.
- **Task 4** (`29d84ff`, `59f7d18`): `GenerateHandoverPackage` chạy trên kết nối và hàng `handover`.
  Zip theo R8, kèm `MUC-LUC.pdf` (dompdf, DejaVu Sans). Gói là `Document` nhóm B ở `signed_filed`. Trang
  vụ có trạng thái gói và nút sinh lại; hệ thống ghi `data_exported`.
- **Task 5** (`985a5c4`): ranh giới cổng có điều kiện thứ năm, áp ở cả hai tầng. Thêm
  `ExpireClientAccess`, chạy 00:30.
- **Task 6** (`e52e514`): `FlagRetentionExpiry` chạy 01:00 và chỉ cảnh báo admin. Thêm
  `RecordMatterDestruction`, cùng một test cấu trúc cấm `forceDelete()`.
- **Task 7** (`0904bbb`, `08e5766`): khoá ngoại `document_downloads` đổi sang `restrictOnDelete`. Thêm
  `RetractDocument`; cổng khách hiện dòng "Văn phòng đã rút lại".
- **Task 8** (`de05774`, `572f3be`, làn m7b): tab "Liên lạc" và tab "Nhật ký" của vụ việc.
- **Task 9** (`76e49c2`, làn m7b): trang "Tìm kiếm" và `SearchMatters`.
- **Task 10** (`8b5a1cf`, `579aaa8`, làn m7b): bảng `settings`, `OfficeProfile`, trang "Thông tin văn
  phòng".
- **Task 11** (`689e331` cho phần sửa, rồi một commit tài liệu): nghiệm thu, ba sửa nhỏ và một test
  (mục "Sửa trong Task 11" bên dưới), các đính chính SPEC, mục này.

#### Bốn test SPEC §11 "Bàn giao và lưu trữ" (theo tên) — chạy riêng, xanh

1. *Vô hiệu hoá luật sư còn lead vụ đang mở → bị chặn, thông điệp nêu số vụ.*
   `tests/Feature/Filament/UserResourceTest.php:234`, "refuses to deactivate a lawyer who still leads
   open matters, with a reason naming how many to hand off". Test này **mới ở Task 11**: đi qua Livewire
   `EditUser`, lawyer có 2 vụ mở và 1 vụ đã đóng, câu báo phải nói "2". Trước đó, đường VÔ HIỆU HOÁ chỉ
   được khẳng định là "có lỗi form" (`:637` "offboards a lead lawyer…", `:363` "sends a separate
   notification…"). Số vụ chỉ được đo ở đường XOÁ (`:170`) và ở mức Action
   (`tests/Feature/Actions/User/GuardsStaffOffboardingTest.php:84`). Test mới xanh ngay từ đầu, vì hành
   vi đã có từ M6.5. Ba mutation probe đều làm nó đỏ: đếm cứng `1` trong `composeOpenWorkReason()`,
   bỏ `->open()` khỏi `OpenWork::forUser()->leadMatters`, và bỏ nhánh `is_active` true → false của
   `EditUser::handleRecordUpdate()`.
2. *Bàn giao tự sinh `stage_logs` nội bộ và chuyển mốc chưa xong, kèm thư tổng hợp.*
   `tests/Feature/Filament/ReassignMatterActionTest.php:48` (M6.5 Task 4, M7 Task 1).
3. *Gói bàn giao không bao giờ chứa nhóm D, kiểm bằng cách giải nén.*
   `tests/Feature/Actions/Matter/BuildHandoverPackageTest.php:331` ("giải nén: nhóm D, tài liệu đã xoá
   mềm và nhóm B còn nháp…") và `:434` (tài liệu đã rút).
4. *Quá `client_access_until` thì vụ rời cổng, còn nguyên trong admin.*
   `tests/Feature/Portal/ClientAccessExpiryTest.php:159` (mọi trang theo vụ của cổng trả 404), `:242`
   (admin còn nguyên, không cột nào đổi), `:122` (ngày biên).

Bảy test trên chạy riêng (SQLite, lọc theo tên): **7 passed (78 assertions), 15,5 s**.

#### Gói thật từ vụ mẫu đã kết thúc (`VK-2026-DD-0007`, CSDL `vk_crm_lane_m7`)

Các bước: `/d/vkwt/m7-dev seed`, rồi `RequestHandoverPackage::handle(22, <lead luatsu3>)` chạy qua
`db:artisan tinker`. Sau đó chạy `db:artisan queue:work handover --queue=handover --stop-when-empty
--timeout=600`. Cuối cùng liệt kê entry bằng `ZipArchive`, đọc bit 11 (UTF-8) từ central directory
và trích chữ của `MUC-LUC.pdf` bằng `Tests\Support\PdfText`.

- **Lần chạy đầu thất bại.** Lỗi ghi lại là `handover_error` = "Hệ thống không dựng được tệp mục lục
  MUC-LUC.pdf…", lỗi gốc là `MatterType::stageIncludingTrashed(): Argument #1 ($key) must be of type
  string, null given` (`RenderHandoverIndex.php:142`). Vụ mẫu có dòng tiến độ đã công bố với
  `to_stage` NULL, mà SPEC §4.8 cho phép NULL. Đây là sửa thứ nhất ở dưới.
- **Sau bản sửa:** gói `ready`, tài liệu #57 "Gói bàn giao hồ sơ VK-2026-DD-0007", nhóm B,
  `signed_filed`, v1, đĩa `private`, 783.746 byte. `data_exported` được ghi.

```
5 entries in 01m401b0ne33a32anxmtw3bdb3.zip (783746 bytes)
  MUC-LUC.pdf                                                       880898 bytes  utf8-flag=yes
  A/01-Giấy tờ tuỳ thân của người khởi kiện, bản sao chứng thực.pdf    832 bytes  utf8-flag=yes
  B/02-Thông báo xử lý vụ án.pdf                                         760 bytes  utf8-flag=yes
  B/03-Thông báo xử lý vụ án.pdf                                         768 bytes  utf8-flag=yes
  C/04--..-etc-thong-bao — tiêu đề cố tình chứa đường dẫn cha.pdf        765 bytes  utf8-flag=yes
group D entries: 0
```

Đối chiếu với tám tài liệu của vụ:
- **Có mặt:** nhóm A chỉ có v2 đã chấp nhận (v1 bị từ chối không vào). Hai bản B trùng tiêu đề
  (`signed_filed` và `published`) thành `02` và `03`. Bản C có tiêu đề chứa `../../etc` không thoát ra
  khỏi thư mục `C/`.
- **Vắng mặt:** bản B `internal_draft`, bản B đã xoá mềm, bản D, và chính tài liệu gói.
- **Tên entry:** mọi tên là UTF-8 hợp lệ, có cờ UTF-8, giữ nguyên dấu tiếng Việt.
- **`MUC-LUC.pdf`:**
  - Trích lại được chữ có dấu: tên văn phòng, "MỤC LỤC HỒ SƠ BÀN GIAO", thông tin vụ.
  - Có bốn tài liệu đánh số khớp tên entry, và bốn dòng tiến độ đã công bố. Hai dòng không ghi giai
    đoạn chỉ in ngày.
  - Chân trang không có dòng pháp lý nào, vì bốn thông tin pháp lý còn trống.

Đi bộ tay trên cùng dữ liệu (qua `db:artisan tinker`, không qua trình duyệt):
- `ExpireClientAccess` trả `{"deactivated":0,"failed":0}`: vụ mẫu còn hạn tra cứu tới 29/12/2026.
- `FlagRetentionExpiry` trả `{"flagged":0,…}`.
- `SearchMatters` cho admin, `luatsu1` và kế toán cho ra đúng tập của từng người:
  - "Thông báo xử lý" (tiêu đề tài liệu): trong ba người, chỉ admin nhận kết quả;
  - "ranh giới" (tiêu đề vụ) không ra gì cho kế toán;
  - "Hoàng Minh" (tên khách) ra cho kế toán, không ra cho `luatsu1` (vụ không thuộc đội của họ).

Chưa đi bộ trên trình duyệt (`/d/vkwt/m7-dev serve`). Việc đó để cho lượt rà soát cuối hoặc chủ văn
phòng.

#### Sửa trong Task 11 (TDD; RED ghi lại, mỗi điều kiện mới có mutation probe)

1. **Mục lục của gói hỏng khi một dòng tiến độ đã công bố không có `to_stage`**
   (`app/Actions/Matter/RenderHandoverIndex.php`, `resources/views/handover/partials/timeline.blade.php`).
   - `stageLabel()` trả chuỗi rỗng khi khoá rỗng. View khi đó chỉ in ngày; khối thông tin bỏ hẳn dòng
     "Giai đoạn cuối".
   - Cùng chỗ, nhãn giai đoạn đổi từ `label` (nội bộ) sang `client_label`, vì mục lục là thứ giao cho
     khách (SPEC §4.5). Đó cũng là nhãn cổng khách hàng đang hiện (`MatterProgress::stageLabel()`).
   - Test: `BuildHandoverPackageTest.php:672`. RED là `TypeError` ở `RenderHandoverIndex.php:142`.
     Ba probe đều đỏ: bỏ chặn khoá rỗng, đổi lại `label`, và in "ngày — " cả khi không có nhãn.
   - Đính chính §6.12 đã ghi.
2. **Thư báo tiến độ về một vụ đã hết hạn tra cứu** (rà soát Task 5, m2; việc mục Task 5 hẹn cho Task
   11), `app/Actions/Notification/NotifyClientOfStageUpdate.php`.
   - Mỗi người nhận giờ phải qua `Gate::forUser($recipient)->allows('view', $matter)`, tức định nghĩa
     cổng. Không có định nghĩa thứ ba.
   - Đường sản phẩm thật: luật sư thêm một dòng cùng giai đoạn có công bố trên vụ đã đóng, đã quá hạn
     tra cứu, trong khi tài khoản khách còn hoạt động nhờ một vụ khác. Trước bản sửa, thư vẫn đi, kèm
     liên kết tới một trang trả 404.
   - Test: `tests/Feature/Mail/StageUpdateNotificationTest.php:779`, hai ca. "Đã hết hạn" phải ra 0 thư
     và `notified_at` trống; "hôm nay là ngày cuối" vẫn ra 1 thư. RED ghi lại: 1 thư thay vì 0.
   - Probe: bỏ lời hỏi `Gate` thì đỏ. Bỏ `$matter !== null` thì vẫn xanh. Đây là mutant tương đương:
     vụ đã xoá mềm đã bị `recipientsFor()` loại trước đó (quan hệ `matter` mang `SoftDeletingScope`),
     và `Gate::allows('view', null)` cũng trả `false`.
   - Ba thư cùng loại trên `main` (`NotifyClientOfRequestAnswered`,
     `NotifyClientOfChecklistItemRejected`, `NotifyClientOfDocumentPublished`) chưa có trên làn này.
     Sửa chúng theo đúng mẫu này lúc gộp `main` (mục dưới).
3. **Test 1 của SPEC §11** (trên).
4. **Câu chữ "ai rút"** (rà soát Task 7, m4). `lang/vi/retraction.php` (`blocked.regroup_to_internal`)
   và SPEC §4.11 nay ghi đúng: luật sư trong đội ngũ vụ việc, trưởng phòng, quản trị. `DocumentPolicy::
   publish` = `update` của vụ + `document.publish`, không riêng luật sư phụ trách.

#### Đính chính SPEC

- **Có từ các task:**
  - §4.11: Task 7, `retracted`. Task 11 sửa câu "ai rút".
  - §4.12: Task 7, khoá ngoại.
  - §4.17: Task 8.
  - §4.19: Task 3 và Task 4.
  - §6.11: Task 1, R10.
  - §6.12: Task 4, 5, 6.
  - §6.13: Task 9.
  - §7.4: Task 10.
  - §11: R10 của Task 1.
- **Thêm ở Task 11:**
  - §4.19: vòng đời dòng lưu trữ và bốn cột tiêu huỷ (Task 3, 5, 6).
  - §6.12: nhãn giai đoạn trong `MUC-LUC.pdf`.
  - §10.6: 10 sự kiện audit mới của M7, đếm bằng cách so `Audit::record('…'` (kể cả lời gọi xuống
    dòng) giữa `d2de674` và nhánh. Cả 10 có nhãn trong `lang/vi/activity.php`. "Xuất dữ liệu" =
    `data_exported`, "vô hiệu hoá tài khoản portal" = `portal_account_deactivated`.
  - §11: cách đọc bốn test.

#### Phán quyết của controller trong các brief, và giá nếu sai

- **T1:** thư thiết kế cho cả lô, Task 2 dùng lại. Tiêu đề thư không nêu mã vụ.
  *Giá nếu sai:* mỗi vụ một thư, lead mới nhận mười thư khi nhận mười vụ.
- **T2:** trang hàng loạt chỉ cho admin và trưởng phòng. *Giá nếu sai:* một luật sư muốn tự bàn giao
  nhiều vụ của mình phải bấm từng vụ.
- **T3:**
  - Danh mục của vụ đã đóng chỉ đọc, chặn ở Action, kể cả khi khách nộp. *Giá nếu sai:* khách của vụ
    đã đóng phải gọi điện để gửi thêm giấy tờ.
  - Dòng lưu trữ không bao giờ bị xoá mềm.
- **T4:**
  - Dùng dompdf; không thêm gói thứ hai, kể cả để trích chữ PDF trong test.
  - Gói chạy trên kết nối riêng (`retry_after` 900 > `$timeout` 600).
  - Gói tự sinh MỘT lần khi vụ đóng; sinh lại là nút bấm.
  - *Giá nếu sai:* một gói lớn giữ lượt `queue.drain`, và thư nhắc mốc hạn phải đứng chờ.
- **T5:**
  - Hết hạn khi `client_access_until < hôm nay`, nên ngày cuối khách vẫn xem được.
  - Ẩn vụ là việc của hai tầng ranh giới, không phải của job.
  - R4 đọc đúng chữ. *Giá nếu sai:* khách cũ có vụ mới chưa công bố bị khoá tài khoản (mục "Cần chủ
    văn phòng quyết").
- **T6:** chỉ cảnh báo, mỗi hạn một lần, chỉ tới admin. Ghi quyết định tiêu huỷ chỉ admin làm, không
  xoá gì. *Giá nếu sai:* hồ sơ quá hạn vẫn nằm nguyên trên đĩa cho tới khi người làm thủ công, và
  chưa có đường "gia hạn lưu trữ" (M10).
- **T7:** đổi nhóm sang D bị từ chối với tài liệu đang ra tới khách. *Giá nếu sai:* trợ lý phải nhờ
  luật sư hoặc trưởng phòng bấm "Rút lại".
- **T8:**
  - Không có nút sửa nhật ký liên lạc; xoá là xoá mềm, kèm lý do.
  - Tab Nhật ký chỉ cho admin, trưởng phòng và lead của vụ. *Giá nếu sai:* cộng sự không xem được
    nhật ký của vụ mình làm.
- **T9:** kế toán chỉ tìm theo mã và tên khách, chặt hơn phán quyết "bốn nguồn" (mục Task 9 ở trên;
  cần controller xác nhận). *Giá nếu sai:* kế toán gõ số thụ lý thì không ra vụ.
- **T10:** bảng `settings` khoá–giá trị chung. Ô để trống nghĩa là dùng giá trị `.env`. *Giá nếu sai:*
  muốn "cố ý để trống dù `.env` có giá trị" thì phải xoá ở `.env`.

#### Kiểm chứng trên bản cuối của làn

- **Cả bộ** (`/d/vkwt/m7-dev test --parallel --processes=2`, SQLite): **2804 passed / 6 skipped /
  0 failed** (12.279 assertions), 782 s. Số đo này lấy trước khi viết mục Ghi chú; sau đó chỉ tài
  liệu thay đổi.
- **MariaDB, tuần tự** (`/d/vkwt/m7-dev test:mariadb`): 62 tệp test mà M7 tạo hoặc sửa trong
  `d2de674..`, trừ `tests/Benchmark`, chạy một lượt. Kết quả **1348 passed / 0 failed** (6.150
  assertions), 747 s (12 phút 35 giây).
- **Pint** `--test`: sạch, 638 tệp.
- **Vòng migration thật** trên `vk_crm_lane_m7`:
  - `seed` (`migrate:fresh --seed`): DONE.
  - `db:artisan migrate:reset --force`: 42 migration DONE.
  - `db:artisan migrate --force`: 42 migration DONE.
  - Sáu migration của M7 (`2026_09_28_070001`, `070400`, `070701`, `070702`, `070900`, `071000`)
    chạy sạch cả hai chiều. Sau vòng này CSDL của làn được seed lại để dùng thử.

Tìm kiếm (Task 9) đo trên 6.000 hồ sơ: 3,5–23,5 ms mỗi câu. Sáu nguồn nằm trong một `OR` nên câu tìm
duyệt bảng (chi tiết ở mục Task 9 ở trên, test đo `tests/Benchmark/SearchMattersBenchmarkTest.php`
không chạy trong bộ thường).

#### Việc để lại cho milestone khác và cho lần gộp `main`

- **Lúc gộp `main` (M6 phần còn lại, M8, M9 đã trên `main`):**
  - `NotifyClientOfRequestAnswered`, `NotifyClientOfChecklistItemRejected` và
    `NotifyClientOfDocumentPublished` hỏi "vụ còn trên cổng" bằng
    `Gate::forUser($account)->allows('view', $matter)`, đúng mẫu
    `NotifyClientOfStageUpdate::recipientsOnPortal()` (Task 11; tên cũ `matterStillOnPortalOf()` đổi
    ở rà soát cuối, vòng sửa 1, I3).
  - Thư `client.document_published` khi công bố gói bàn giao là của làn M6 Task 3. Sau khi gộp,
    công bố gói qua `PublishDocument` sẽ tự gửi thư.
- **M9:**
  - Hook `deleting` (`MatterHasOutstandingBalance`) không chạm đường nào của M7. Lưu trữ, tiêu huỷ,
    huỷ và gói bàn giao đều không xoá vụ; mọi đường xoá hay lưu trữ vụ đi qua Action.
  - `RetractDocument` (bước 6) và `DocumentPolicy::delete()` phải từ chối tài liệu được
    `payments.receipt_document_id` hoặc `contract_amendments.document_id` trỏ tới. M9 thêm lúc gộp.
  - M9 dùng lại `App\Events\MatterStageChanged`.
  - Bảng kê thanh toán vào `MUC-LUC.pdf` là một partial mới cộng một `@include` (xem
    `RenderHandoverIndex`).
- **M11:** dùng đúng các tên sau:
  - `CommunicationLogPolicy::create($user, $matter)` và `LogCommunication` (Task 8);
  - bảng `settings`, `WriteSettings`, `OfficeProfile` (Task 10);
  - `SearchMatters::matching()` (Task 9).
- **M8 (làn M8b, nay trên `main`):**
  - Danh sách extension: `ext-dom` và `ext-mbstring` (dompdf), `ext-zip` (`ZipArchive`), `ext-pcntl`
    (`--timeout` của worker `handover`).
  - Cần quyết có loại tài liệu gói khỏi bản sao lưu không (`MatterArchive::handoverDocumentIds()`).
  - `vkcrm:preflight` đọc `OfficeProfile`.
  - Phải `migrate` (bảng `settings`) TRƯỚC khi bật lại web và queue: chân trang cổng, trang 404 và
    mọi thư đều đọc bảng này.
  - "Xuất dữ liệu" = sinh gói và tải gói (`data_exported`).
- **M10:** chính sách lưu trữ gồm cả "gia hạn lưu trữ", và hạn xoá dữ liệu cá nhân của vụ bị huỷ
  (`CancelMatter`, không có dòng lưu trữ). Vụ đã xoá mềm thì không bao giờ được cảnh báo hạn lưu (rà
  soát Task 6, m7).
- **Bộ test chập chờn có từ trước:** `MatterTypeFactory` bốc mã `HS`/`DN` ngẫu nhiên (mục Task 5).

#### Cần chủ văn phòng hoặc controller quyết

1. R4 đọc đúng chữ: một khách cũ có vụ đã hết hạn và vừa có vụ MỚI chưa công bố bị khoá tài khoản.
   Tài khoản bị khoá không tự bật lại (mục Task 5).
2. Cảnh báo hạn lưu trữ: admin bấm đóng thông báo trong chuông thì hôm sau nhận lại. Một vụ đã ghi
   quyết định tiêu huỷ vẫn mở lại được (rà soát Task 6, m1 và m2).
3. Sinh lại gói bàn giao:
   - xoá TỆP của version cũ kể cả khi version đó đã bị rút;
   - lặng lẽ gỡ version cũ đang công bố khỏi cổng, không có dòng "đã rút".
   (Rà soát Task 7, m1 và m2.) **Đã sửa ở rà soát cuối, vòng sửa 1 (I2)** theo mẫu Task 7 "bị chặn
   kèm thông điệp chỉ tới nút Rút"; controller xác nhận phán quyết (mục cuối).
6. **Dòng rút trên cổng không còn tiêu đề** (rà soát cuối, vòng sửa 1, C1) — controller xác nhận, hoặc
   chọn thêm đường "admin gỡ dòng rút có lý do" (mục cuối nói vì sao chưa làm).
4. Kế toán tìm theo hai nguồn hay bốn nguồn (Task 9, M1).
5. Hạn xoá dữ liệu cá nhân của vụ bị huỷ vì mở nhầm (cùng chính sách lưu trữ M10).

#### Ghi chú cũ đã lỗi thời (không sửa tại chỗ — luật làn chỉ cho viết trong "Ghi chú M7")

Mục "Việc hoãn lại" của Ghi chú M4 và các ghi chú M6.5 còn tả "chuyển vào nhóm D" là đường rút tạm
khỏi cổng khách. Từ M7 Task 7, đường rút duy nhất là "Rút lại" (`RetractDocument`, §4.11). Chuyển vào
D bị từ chối với tài liệu đang ra tới khách.

#### Gói rà soát toàn nhánh (lượt rà soát cuối do quy trình điều phối chạy)

Dải `d2de674..` đầu nhánh `m7-handover`. Danh sách tệp theo khu vực ghi ở sổ làn
(`.superpowers/sdd/m7/progress.md`, "Task 11: review package"). Brief rà soát giả định có một
Critical.

#### Rà soát cuối, vòng sửa 1 (2026-10-03, base `f88ed52`)

Năm phát hiện: một Critical (C1), bốn Important (I1–I4). Mỗi phát hiện có test đỏ trước bản sửa
(RED ghi ở báo cáo `.superpowers/sdd/m7/final-fix-report.md`), và mỗi điều kiện mới có mutation
probe đỏ (24 probe, cùng báo cáo).

- **C1 — dòng rút trên cổng không còn tiêu đề tài liệu.** Ca rút điển hình là tài liệu của khách
  KHÁC công bố nhầm. Dòng rút lại vĩnh viễn: tài liệu đã rút không xoá được, không vào nhóm D được,
  không công bố lại được, và không Action nào sửa tiêu đề. Vì vậy tiêu đề của khách kia từng nằm trên
  cổng của khách này, không ai gỡ được.
  - **Phán quyết của người sửa (controller xác nhận):** hình chiếu `MatterProgress::retractionNotices()`
    chỉ còn lý do và ngày rút. Blade vẽ nhãn trung tính "Tài liệu đã được văn phòng rút lại" thay cho
    tiêu đề. Ô lý do và hộp thoại "Rút lại" nói rõ: tiêu đề không hiện; muốn khách biết là tài liệu
    nào thì nêu trong lý do; tài liệu của khách khác thì đừng nêu tên.
  - **Chưa làm đường "admin gỡ dòng rút có lý do".** Sau bản sửa, chữ duy nhất còn lại trên dòng rút
    là lý do — do người rút viết khi đã được báo khách đọc nó. Thêm một đường gỡ là thêm một cách thứ
    hai làm tài liệu biến khỏi mắt khách không lời giải thích, đúng điều plan Task 7 tránh. Nếu chủ
    văn phòng muốn sửa được cả một lý do viết sai, đó là một Action mới (cột, audit, nút), cần phán
    quyết riêng.
  - Test: `tests/Feature/Portal/RetractedDocumentNoticeTest.php` (ca khách khác, có hình chiếu qua
    Livewire; mọi test nhận dòng rút bằng lý do), `PortalIsolationSweepTest`,
    `DocumentsRelationManagerTest`. Đính chính SPEC §4.11 "Khách thấy gì".
- **I1 — READ VIEW cố định trước khi đợi khoá `matters`.** Hai chỗ: `OpensChecklistItem` (Task 3)
  đọc trần đầu mục làm câu đầu tiên trong transaction; `RetractDocument` (Task 7) khoá `matters` qua
  một truy vấn con không khoá. Nay `matter_id` được đọc TRƯỚC `DB::transaction()` (cùng luật M6.5 ở
  `TriageClientRequest`), và câu đầu tiên trong transaction là câu khoá `matters` theo id.
  - Đo bằng hai phiên thật trên MariaDB 11.8: `tests/Feature/Actions/MatterLockBeforeSnapshotTest.php`
    và `tests/Support/MatterLockRace.php` (`pcntl_fork`, chờ có xác minh qua
    `information_schema.PROCESSLIST`, nhóm `mariadb-locking`).
  - Trước bản sửa: người vừa bị gỡ khỏi đội ngũ vẫn gạt được "không cần nộp" (`marked`) và vẫn rút
    được tài liệu (`retracted`). Người vừa bị vô hiệu hoá qua được bước đọc lại rồi chết ở câu UPDATE
    với lỗi 1020. Sau bản sửa: cả ba ca bị từ chối sạch.
  - Ba test thứ tự câu lệnh chạy được cả trên SQLite.
- **I2 — sinh lại gói bàn giao (mục 3 của danh sách quyết ở trên).** Theo đúng mẫu plan Task 7 "bị
  chặn kèm thông điệp chỉ tới nút Rút":
  - Gói hiện tại đang ra tới khách thì KHÔNG sinh lại được. `RequestHandoverPackage` từ chối bằng
    `HandoverPackageUnavailable::released()`; nút trên trang vụ báo câu đó. `BuildHandoverPackage`
    hỏi lại dưới khoá (gói có thể được công bố trong lúc job chờ hàng) và hỏng với lỗi có tên
    `previousReleased()`, không tạo version mới.
  - Job không bao giờ đổi trạng thái hay cờ khách của version cũ nữa.
  - Tệp của version cũ chỉ bị xoá khi version đó chưa từng tới tay khách: không `retracted` và không
    có lượt tải của khách. Lượt tải của nhân sự không tính.
  - Đính chính SPEC §6.12 ("Sinh lại và rút lại"); câu hộp thoại "Sinh lại gói" sửa theo.
- **I3 — cảnh báo trên form và việc gửi thư hỏi cùng một câu.**
  - `NotifyClientOfStageUpdate::hasEligibleRecipient()` nay đi đúng hai bước của `handle()`: tài
    khoản đủ điều kiện, rồi `Gate view` của từng tài khoản (dùng chung `recipientsOnPortal()`).
  - Câu cảnh báo mới (`archive.stage_update.not_on_portal_warning`) hiện khi khách có tài khoản mà
    vụ đã rời cổng vì hết hạn tra cứu. `hasEligibleAccount()` chỉ còn để chọn câu nào hiện.
  - Thêm ngoài phát hiện, tìm ra khi viết test đi hết đường thật:
    - Trên form "Chuyển giai đoạn", chọn một giai đoạn không kết thúc là MỞ LẠI vụ. Vụ trở lại cổng
      và thư vẫn đi, nên câu mới chỉ hiện khi giai đoạn đích là giai đoạn kết thúc.
    - `TransitionMatterStage` nay phát `MatterStageChanged` TRƯỚC `StageLogPublished`. Trước đó, với
      hàng đợi `sync`, thư của một lần mở lại vụ đã hết hạn tra cứu không đi (đo được: 0 thư thay vì
      1), vì dòng lưu trữ chưa kịp xoá `client_access_until`. Với hàng đợi thật, đó là một cuộc đua.
  - Docblock cũ hứa "không bao giờ lệch" đã viết lại.
- **I4 — log máy chủ không mang thông điệp nào khi sinh gói hỏng.** `RecordHandoverPackageFailure`
  chỉ ghi `matter_id`, lớp exception và lớp lỗi gốc. Câu tiếng Việt đầy đủ (có tiêu đề tài liệu)
  vẫn tới `handover_error` và chuông của người xem được vụ. Test với vụ `restricted`
  (`GenerateHandoverPackageTest`).
- **Kiểm chứng:**
  - Cả bộ SQLite (`--parallel --processes=2`): **2826 passed / 9 skipped / 0 failed**, 773 s. Chín
    test bỏ qua gồm sáu test cũ và ba test đua chỉ chạy trên MariaDB.
  - MariaDB, tuần tự: 18 tệp test đã sửa hoặc phủ mã đã sửa, gồm cả test đua. Kết quả **478 passed
    / 0 skipped / 0 failed**, 307 s.
  - Pint `--test` sạch, 640 tệp.
  - Vòng này không có migration.

#### Gộp `m7-handover` vào `main` (2026-10-03, `main` @ `88b6044`)

Gộp `--no-ff`, chưa commit (controller commit). Dòng M7 của bảng milestone để controller sửa.

- **16 tệp xung đột, giữ cả hai phía:**
  - `.env.example`: khối `BRAND_*` + sao lưu/CSP/HTTPS của `main`, rồi khối gói bàn giao
    (`HANDOVER_QUEUE_RETRY_AFTER`, `HANDOVER_WORK_DIR`, `MEDIA_MAX_FILE_SIZE_MB`); mỗi biến đúng
    một dòng.
  - `composer.lock`: lock của `main` cộng đúng năm gói dompdf của làn (`barryvdh/laravel-dompdf`
    v3.1.2, `dompdf/dompdf` v3.1.6, `dompdf/php-font-lib`, `dompdf/php-svg-lib`,
    `sabberworm/php-css-parser` — cùng phiên bản với làn). Không gói nào khác đổi. `composer
    update barryvdh/laravel-dompdf --with-all-dependencies` kéo theo 30 gói (Laravel 13.31 → 13.34,
    `brick/math` 0.18 → 1.0), nên lock được dựng lại bằng `composer update` CHỈ năm gói đó.
  - `routes/console.php`: đủ 13 mục lịch, mỗi mục một lần, mọi `withoutOverlapping` có hạn.
  - `AppServiceProvider`: bốn listener sao lưu của M8a, `Gate::define('bulkReassign')`, morph map có
    cả năm khoá tiền của M9 lẫn `communication_log`.
  - `MatterResource::getRelations()`: … Mốc thời hạn, Liên lạc, Hợp đồng và thanh toán, Nhật ký (cuối
    cùng, SPEC §7.2).
  - `DocumentPolicy::delete()`: chặn của M7 (đang ra tới khách / đã rút → `false`) TRƯỚC cổng quyền,
    chặn tiền của M9 (`Response::deny()` kèm lý do) SAU cổng quyền.
  - `ActivityOwningMatter`: `whereOwnedByAny()` của M7 nhận thêm `$includeMoney`; `scopeVisibleTo()`
    và `scopeOwnedBy()` (nay nhận `User $viewer`) cùng thả dòng TIỀN chỉ khi có `billing.view`.
  - `NotifyClientOfStageUpdate`: luật người nhận của `main` (`ResolveClientRecipients`,
    `eligibleRecipients()` public cho nút "Gửi lại") cộng bước "vụ còn trên cổng" của M7.
  - `MyMatters` + `MyMattersTest`: nạp sẵn cả `clientRequests.replies` (M6) lẫn
    `clientAccessArchive` (M7) — phần truy vấn cố định thành **bảy** (`2N + 7`).
  - `config/vkcrm.php`, `docs/SPEC.md` (§10.6: cả đính chính M8 lẫn M7, cộng một câu: với `main`,
    M7 thêm chín sự kiện mới, `portal_account_deactivated` đã có từ M8), `lang/vi/{activity, enums,
    exceptions}.php`, `docs/PROGRESS.md`: giữ mọi khoá, mọi mục.
- **Gộp ngữ nghĩa (mỗi mục có test đỏ trước, rồi xanh):**
  1. Ba thư khách của M6 (`client.document_published`, `client.document_rejected`,
     `client.request_answered`) và `eligibleRecipients()` của thư tiến độ (nút "Gửi lại") hỏi
     `ResolveClientRecipients::onPortal()` = `Gate::forUser($account)->allows('view', $matter)`:
     không thư nào tới khách đã hết hạn tra cứu. `NotifyClientOfDocumentPublished` giữ `open()` của
     M6. Test: một ca mỗi thư trong `tests/Feature/Mail/*NotificationTest.php`, một ca "Gửi lại" ở
     `ResendOutboundMessageTest`.
  2. M9 `Document::isReferencedByBillingRecord()`: `RetractDocument` bước 6 từ chối
     (`DocumentNotRetractable::referencedByBillingRecord()`); `BuildHandoverPackage::keepsFileOf()`
     giữ tệp của version cũ đang là biên lai (lần sinh lại xoá TỆP qua medialibrary, hook
     `Document::deleting` không đứng trước). Không đường nào của M7 xoá vụ việc: hook dư nợ của M9
     không bị vòng qua.
  3. Tab "Nhật ký" của vụ: dòng hợp đồng/khoản thu chỉ với `billing.view`
     (`MatterActivityRelationManagerTest`).
  4. `OfficeProfile`: mười mẫu thư của `main` (`Mail/Client/{Activation, DocumentPublished,
     DocumentRejected, MissingDocuments, RequestAnswered}`, `Mail/Staff/{BackupAlert,
     InstalmentOverdue, NewClientDocument, NewClientRequest, StaleMatterReminder}`) đọc
     `OfficeProfile::current()` trong `content()`. `vkcrm:preflight` đọc bốn thông tin pháp lý qua
     `OfficeProfile` (test mới: nhập trong app, `.env` trống → XANH); câu VÀNG và `docs/CAI-DAT.md`
     chỉ tới trang "Thông tin văn phòng". `OfficeProfile` vẫn rơi về
     `config('vkcrm.brand.office_address')` (mặc định = địa chỉ trụ sở).
  5. Test cấu trúc của `main` (`MatterTest`: `closed_at` chỉ là điều kiện ở `Matter.php` và
     `TransitionMatterStage`): 12 tệp của M7 viết lại bằng `isClosed()`/`isOpen()`/`closed()`. Mọi
     chỗ đều đã loại vụ xoá mềm trước đó (nên `isClosed()` đúng nghĩa cũ), trừ hai nơi đổi có chủ ý:
     nút danh mục ẩn với vụ đã huỷ (`isOpen()`), và `SyncMatterArchive` đọc vụ `withTrashed()` (chỉ
     chạy sau `TransitionMatterStage`, vốn từ chối vụ đã huỷ).
  6. `MailTemplateRegistryTest` của `main`: `staff.matter_reassigned` có nhãn; cả hai thư nội bộ của
     M7 vào `ResendTargets::NOT_RESENDABLE`, mỗi thư một câu từ chối riêng.
  7. **M6 và M7 nói ngược nhau về vụ ĐÃ ĐÓNG, M7 thắng** (kế hoạch M7 nhận việc này từ M6.5 Task 21:
     "Danh mục hồ sơ của vụ đã đóng" là chỉ đọc, khách không nộp thêm). Bốn test của M6 đo "vụ đã
     đóng vẫn duyệt/nộp được" được viết lại thành điều ngược lại:
     `DocumentRejectedNotificationTest`, `NewClientDocumentNotificationTest`, `SubmitDocumentTest`
     (câu cảm ơn không bao giờ hứa trên vụ đã đóng), `ChecklistRelationManagerTest` (bỏ dòng
     `closed`; `client_deleted` dựng trên vụ còn mở). Hai test "vụ đóng giữa lúc sự kiện bắn và
     lúc job chạy" của M6 giữ nguyên và vẫn là mutation probe cho "listener không đòi vụ còn mở".
- **Kiểm chứng:** `bin/dev test --parallel --processes=4` → **4317 passed, 29 skipped, 1 risky,
  1 todo, 0 failed** (18922 khẳng định, 996 s). `bin/dev test:mariadb`, tuần tự, 28 tệp (mọi test
  khoá/đua của làn, gồm `MatterLockBeforeSnapshotTest` hai phiên thật, cộng mọi tệp test chạm tới
  mã đã sửa lúc gộp) → **636 passed, 0 skipped, 0 failed** (562 s). `pint --test` sạch (206 tệp
  PHP của lần gộp; cả dự án 928 tệp).
- **Việc cần controller/chủ văn phòng quyết (mới, do gộp):**
  - Gói bàn giao luôn nằm trên vụ đã kết thúc, mà `client.document_published` (M6) đòi vụ còn mở —
    nên công bố gói KHÔNG gửi thư cho khách (trái câu "công bố gói qua `PublishDocument` sẽ tự gửi
    thư" ở mục Task 11 trên). Muốn có thư thì nới `open()` cho tài liệu gói (cổng `onPortal()` đã
    chặn vụ hết hạn tra cứu).
  - `RetractDocument` từ chối biên lai/phụ lục đúng như hai làn đã thống nhất. Rút không xoá gì,
    nên phần chặn này chỉ khoá một bản scan tiền bị công bố nhầm lại trên cổng (đường: nhóm D → đổi
    nhóm → công bố). Nếu muốn rút được, bỏ bước đó ở `RetractDocument` (policy xoá và hook vẫn giữ
    bằng chứng).
  - Đầu mục khách nộp khi vụ còn mở rồi vụ đóng trước khi duyệt: danh mục chỉ đọc nên không duyệt
    hay từ chối được nữa, và câu cảm ơn đã hứa "sẽ gửi email nếu có gì chưa ổn".
