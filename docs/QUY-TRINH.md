# VK-CRM — Quy trình chuẩn của văn phòng, và hệ thống đỡ ở chỗ nào

Tài liệu này là **một nguồn sự thật duy nhất về luồng nghiệp vụ**: từ lúc một người
lần đầu gọi tới văn phòng, cho tới lúc hồ sơ của họ được bàn giao và lưu trữ. Mỗi
bước ghi rõ ba thứ: **văn phòng làm gì**, **hệ thống đỡ bằng màn hình hay tác vụ
nào**, và **hiện đã có hay chưa**.

Rà soát đối chiếu mã nguồn ngày **2026-09-23**. Trạng thái ghi ở đây là thứ đo được
trong repo, không phải ý định.

**Cập nhật 2026-09-27 (M6.5 Task 21).** Đợt rà soát quy trình 2026-09-24
(`docs/audits/2026-09-24-quy-trinh.md`) chứng minh nhiều dòng **[Xong]** dưới đây chưa chạy thật
lúc ghi: không có màn hình gán đội ngũ, không có kiểm tra trùng khách, không có màn hình tra thư
đã gửi, 3/6 loại vụ việc không có danh mục để áp, và nhiều lỗi khác trên đường đi. Các dòng đó đã
được sửa trong M6.5; mỗi dòng ghi mã phát hiện và task đã sửa. Mọi task trừ Task 14 đã xong và qua
rà soát; **M6.5 chưa merge vào `main`** lúc viết (đang nghiệm thu). Chi tiết ở `docs/PROGRESS.md`,
"Ghi chú M6.5". Những gì vẫn chưa làm được sau M6.5 ghi rõ ở từng dòng, không để ẩn sau chữ
[Xong].

Ký hiệu: **[Xong]** đã chạy được và đã hợp nhất · **[Đang làm]** đã có mã, đang khép
lỗi · **[Có kế hoạch]** đã đặc tả chi tiết, chưa viết mã · **[Chưa có chủ]** chưa
thuộc kế hoạch nào.

---

## Giai đoạn 1 — Tiếp nhận và thẩm định đầu vào

Đây là giai đoạn quyết định nhiều tiền nhất và mang rủi ro nghề nghiệp cao nhất. Trước M10 hệ
thống không có chỗ nào cho nó; sau M10 mọi dòng dưới đây đã chạy được.

**Cập nhật 2026-10-03 (M10 Task 8).** Trạng thái đo trên nhánh `m10-intake` (mọi cổng xanh, ba luồng
nghiệm thu đi hết trên dữ liệu mẫu — `tests/Feature/Intake/IntakeAcceptanceWalkTest.php`); **M10 chưa
gộp vào `main`** lúc viết. Chi tiết, phán quyết và những gì còn chờ chủ văn phòng/luật sư xác nhận ở
`docs/PROGRESS.md`, "Ghi chú M10".

**Cập nhật 2026-10-04 (việc sau gộp M9 + M10).** M10 đã gộp vào `main` (`b2e02d7`, 2026-10-04): mọi dòng
**[Xong]** của giai đoạn này là trạng thái của `main`. Nâng một máy chủ đang chạy lên bản này:
`docs/CAI-DAT.md`, Bước 5, "Bản cập nhật M10 (tiếp nhận) làm gì trên máy chủ đã có dữ liệu" — đặc
biệt `db:seed --force` (thiếu nó thì menu Tiếp nhận không hiện với ai) và con số
`PROSPECT_RETENTION_MONTHS` phải được luật sư xác nhận TRƯỚC khi nhân sự bắt đầu ghi tiếp nhận.

**Cập nhật 2026-10-04 (rà soát cuối M10, vòng sửa 1).** Ba câu dưới đây từng hứa nhiều hơn hệ thống làm,
đã sửa cho đúng mã: "người nhập chỉ thấy mã hồ sơ và vai" (nay đúng với khớp Đỏ; khớp Vàng vẫn hiện tên
để người nhập tự xem), "người gọi lại cũng bị khoá" (chỉ khi cùng vai đã khai), "cùng số là cùng một
người" (chỉ là gợi ý).

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Ghi lại mỗi lần có người liên hệ, dù qua điện thoại, Zalo, website hay đến trực tiếp | Màn hình **Tiếp nhận**: trang tạo nhập nhanh (bắt buộc tên, vai dự kiến, nguồn, SĐT hoặc email; câu chuyện ghi sau), mã `TN-2026-0001` để nhắc qua điện thoại, nguồn có cả "form website" cho lead nhân sự nhập tay; giao người phụ trách; chuyển thành vụ việc không gõ lại (`ConvertIntakeToMatter` — khách tra theo CCCD rồi SĐT, mở vụ bằng đúng `OpenMatter`, phí đã báo thành gợi ý của hợp đồng) | **[Xong]** sau M10 (Task 1–4). Chưa có đường công khai: form trên luatvukhang.com gửi thẳng vào là một milestone riêng (R6) |
| **Kiểm tra xung đột lợi ích trước khi nghe nội dung vụ việc** | Lưu phần danh tính là chạy đúng `RunConflictCheck` (cùng khoá với mở vụ), dò khách và các bên của mọi vụ VÀ những người văn phòng đã nghe mà chưa nhận việc (lần tiếp nhận cũ — tối đa Vàng, "đã liên hệ văn phòng ngày …"). Ô câu chuyện: Xanh đủ định danh thì mở; Vàng hoặc thiếu định danh thì phải xác nhận đã xem các khớp; Đỏ thì khoá — chỉ trưởng phòng/quản trị từ chối hoặc ghi đè kèm lý do; Đỏ "dính" (sửa danh tính không gỡ được); một lần gọi khác của CÙNG người — cùng SĐT hoặc CCCD **và cùng vai đã khai** — cũng bị khoá như Đỏ, kể cả lần gọi đã ghi trước đó (khai vai khác thì lần gọi kia chỉ hiện ở mức Vàng, kèm mã `TN-…`). Danh sách có bộ lọc "Đỏ chờ trưởng phòng xử lý" để trưởng phòng/quản trị tìm những bản chỉ họ mở được | **[Xong]** sau M10 (Task 2, 3; rà soát cuối). Với khớp **Đỏ**, người nhập (không phải trưởng phòng/quản trị) chỉ thấy mã hồ sơ và vai của bên trùng — không tên, không lĩnh vực, không tiêu chí khớp. Với khớp **Vàng**, người nhập thấy mã hồ sơ, lĩnh vực, vai và tên bên trùng để tự xem trước khi xác nhận. Không ai thấy tiêu đề hay nội dung vụ ở đây |
| Phát hiện cùng một người gọi nhiều lần | Gợi ý bản ghi cũ khi trùng đúng SĐT (đã chuẩn hoá) hoặc CCCD — chỉ là gợi ý: một số máy có thể dùng chung (vợ chồng, người nhà, đồng nghiệp); trùng theo tên chỉ hiện cho trưởng phòng/quản trị; trùng một khách hàng thì chỉ nói "số này đã là khách của văn phòng"; người nhập hỏi lại cho chắc rồi mới gộp bản trùng vào bản cũ hơn | **[Xong]** sau M10 (Task 2, 3) |
| Bảo đảm không ai bị bỏ quên không gọi lại | Mốc phản hồi lần đầu (lần đầu rời "Mới"); quá 4 giờ làm việc (`INTAKE_RESPONSE_HOURS`) thì nhắc người được giao — không có thì trưởng phòng/quản trị, cuối cùng là admin — bằng chuông và thư không mang dữ liệu người liên hệ; widget "Liên hệ chưa ai gọi lại"; báo cáo "Bức tranh đầu vào" (nguồn, tỉ lệ thành vụ việc, thời gian phản hồi, lý do không thành) | **[Xong]** sau M10 (Task 5, 6). Ngày lễ chưa được trừ khỏi giờ làm việc và Thứ Bảy chưa tính — chờ chủ văn phòng |
| Từ chối vụ việc và ghi lý do | Nút "Từ chối" bắt buộc lý do; "vì xung đột lợi ích" chỉ trưởng phòng/quản trị chọn; lý do của mọi lần từ chối chỉ trưởng phòng/quản trị (và chính người đã từ chối) đọc được; người khác chỉ thấy "Văn phòng từ chối" và câu trả lời chuẩn — giống nhau cho mọi lý do, để không ai đoán ra lần nào là vì xung đột | **[Xong]** sau M10 (Task 3; rà soát cuối) |
| Giữ dữ liệu của người không thành khách đúng hạn, xoá khi họ yêu cầu | Ghi nhận người liên hệ đã nghe thông báo và đồng ý (ô không tích sẵn) trước khi ghi câu chuyện; hết `PROSPECT_RETENTION_MONTHS` (24) tháng thì tự ẩn danh; admin "Xoá dữ liệu theo yêu cầu" kèm lý do; câu chuyện không bao giờ ra máy chủ MCP | **[Xong]** sau M10 (Task 2, 7). Câu thông báo là BẢN NHÁP, con số 24 tháng và việc giữ dấu băm sau ẩn danh chờ luật sư xác nhận |

**Nhận một cuộc gọi đầu — từng bước cho người trực điện thoại** (M10):

1. **Tiếp nhận → Tạo.** Hỏi tên, số điện thoại (hoặc email), người gọi đứng ở vai nào (sẽ là người
   kiện, người bị kiện, hay người liên quan), và bên kia là ai — tên, kèm SĐT hoặc CCCD nếu người gọi
   biết. **Chưa hỏi chuyện gì đã xảy ra.**
2. **Đọc câu thông báo** hiện trên form cho người gọi. Người gọi đồng ý thì tích ô "đã nghe thông báo
   và đồng ý" (ô không bao giờ tích sẵn). Giao cho luật sư phụ trách nếu đã biết. Bấm Tạo — hệ thống
   kiểm tra xung đột lợi ích ngay lúc đó.
3. **Đọc kết quả trên trang bản ghi** (ngày giờ lần kiểm tra hiện ngay đó):
   - **Xanh** — ô câu chuyện mở: nghe và ghi câu chuyện.
   - **Vàng / thiếu định danh** — xem bảng khớp (mã hồ sơ, lĩnh vực, vai và tên bên trùng, hoặc "đã liên
     hệ văn phòng ngày …" kèm mã `TN-…`), bấm "Xác nhận đã xem các khớp" rồi mới nghe chuyện. Không chắc
     thì hỏi luật sư trước.
   - **Đỏ** (kể cả "Đỏ chờ trưởng phòng xử lý") — **dừng, không nghe chuyện.** Bảng chỉ cho anh/chị mã
     hồ sơ và vai của bên trùng. Hẹn sẽ gọi lại, báo trưởng phòng/quản trị. Họ quyết: từ chối (vì xung
     đột) hoặc ghi đè kèm lý do. Lần gọi khác của cùng người — cùng số (hoặc CCCD) và **cùng vai** —
     cũng bị khoá như vậy, cả lần gọi lại hôm sau lẫn một lần gọi đã ghi từ trước. Nếu lần gọi lại khai
     vai KHÁC, hệ thống chỉ hiện lần gọi kia ở mức Vàng kèm mã `TN-…`: thấy mã một lần gọi trước của
     cùng số thì báo trưởng phòng trước khi nghe chuyện.
4. **Hệ thống báo đã có bản ghi cũ cùng số** — RẤT CÓ THỂ là cùng một người, nhưng một số máy có thể
   dùng chung (vợ chồng, người nhà, đồng nghiệp): hỏi lại cho chắc. Đúng người thì gộp bản mới vào bản
   CŨ hơn (đồng hồ phản hồi chạy theo bản còn lại); không phải thì để hai bản riêng.
5. **Gọi lại và đổi trạng thái** (Đã liên hệ → Đang tư vấn → Đã báo giá, ghi phí đã báo). Lần đầu đổi
   trạng thái là mốc "đã phản hồi"; quá 4 giờ làm việc mà bản ghi còn "Mới" thì hệ thống nhắc.
6. **Khách đồng ý:** luật sư bấm "Chuyển thành vụ việc" (trợ lý không có nút này) — hệ thống tìm khách
   cũ theo CCCD/SĐT, hỏi xác nhận đúng người, mở vụ có mã, phí đã báo hiện sẵn ở form soạn hợp đồng.
   **Không thành:** "Khách không theo tiếp", hoặc "Từ chối" kèm lý do. Từ chối vì xung đột thì chỉ nói
   với người gọi **"Văn phòng xin phép không nhận vụ việc này"**, không giải thích thêm.
7. **Dữ liệu người không thành khách** tự ẩn danh sau hạn lưu. Ai yêu cầu xoá: báo admin, admin dùng
   "Xoá dữ liệu theo yêu cầu" và ghi cách đã xác minh người yêu cầu. Bản sao lưu cũ còn dữ liệu cho tới
   khi bị dọn — 30 bản đêm gần nhất, cộng khoảng 30 ngày trong Thùng rác của Google Drive: thường
   khoảng hai tháng, lâu hơn nếu có đêm sao lưu bị lỡ (`docs/SAO-LUU-KHOI-PHUC.md`).

**Luật nghề nghiệp đứng sau giai đoạn này.** Nếu nghe hết câu chuyện rồi mới phát
hiện bên kia là khách hàng hiện hữu thì thông tin bí mật đã nghe rồi và không rút lại
được. Vì vậy kiểm tra xung đột đứng **trước** phần nội dung, không phải sau. Và khi
từ chối vì xung đột, nói lý do thật cho người gọi chính là tiết lộ rằng có tồn tại
một khách hàng khác.

---

## Giai đoạn 2 — Mở hồ sơ

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Tạo khách hàng, kiểm tra trùng | Resource `Client`, số định danh mã hoá khi lưu; trùng số điện thoại/CCCD thì cảnh báo kèm liên kết hồ sơ trùng, phải xác nhận mới tạo được bản thứ hai | **[Xong]** sau M6.5 — trước đó không có kiểm tra trùng nào (`intake/intake-07`); sửa ở Task 6. Form khách vượt độ dài cột thành lỗi 500 trên MariaDB (`intake-08`), sửa ở Task 6. Dò trùng theo tên và theo người gọi lại vẫn là việc của M10 |
| Mở vụ việc, sinh mã không bao giờ đổi | `OpenMatter`, mã dạng `VK-2026-DD-0147`; luật sư tra khách đúng số điện thoại/CCCD hoặc tạo khách mới ngay trong form; sửa được vụ sau khi mở, huỷ được vụ mở nhầm | **[Xong]** sau M6.5 — trước đó luật sư không mở được vụ cho khách mới (`intake-03`, `roles/roles-04`; Task 6, R4) và vụ không sửa được sau khi mở (`intake-06`, `spec-gap/spec-gap-06`; Task 5) |
| Chạy kiểm tra xung đột trước khi nhận | `RunConflictCheck` hiện ngay trong biểu mẫu tạo; kết quả Đỏ phải có lý do ghi đè | **[Xong]** sau M6.5 — rà soát tìm: khách quay lại luôn ra vàng với chính hồ sơ cũ, hai khách của văn phòng đối nhau vẫn ra xanh, ghi đè một lần rồi chặn mãi, sửa định danh khách không ai được báo, hai người mở hai vụ đối nhau cùng lúc cùng ra xanh (`conflict/conflict-01`–`04`, `06`, `07`, `11`, `12`); sửa ở Task 8 (R13) |
| Khai các bên trong vụ việc | Tab **Các bên**; thêm, sửa, gỡ một bên (gỡ là xoá mềm kèm lý do); mỗi lần đều chạy lại kiểm tra xung đột | **[Xong]** sau M6.5 — trước đó không sửa hay gỡ được bên đã nhập, số điện thoại viết kiểu `(+84) 912 345 678` bị từ chối (`conflict-05`, `09`, `10`); sửa ở Task 9 (R14) |
| Giao luật sư phụ trách và đội ngũ | Tab **Đội ngũ**: thêm, gỡ luật sư phối hợp, trợ lý, người theo dõi; đổi luật sư phụ trách qua **Bàn giao**; phạm vi nhìn thấy suy từ đội ngũ | **[Xong]** sau M6.5 — trước đó **không có màn hình nào** thêm người vào đội ngũ: mọi vụ chỉ có luật sư phụ trách, trợ lý không bao giờ thấy vụ (`intake/intake-01`, `roles/roles-03`, `spec-gap/spec-gap-01`, `e2e/F4`, critical); sửa ở Task 3 (R6), bàn giao một vụ ở Task 4 (R7) |
| Áp danh mục giấy tờ theo loại vụ việc | `ApplyChecklistTemplate`, màn hình quản lý danh mục mẫu trên loại vụ việc, nút thêm đầu mục cho một vụ, thanh tiến độ `X/Y` | **[Xong]** sau M6.5 — trước đó không có màn hình danh mục mẫu nào và 3/6 loại vụ việc mở ra với danh mục rỗng, khách không nộp được giấy tờ (`intake/intake-02`, `checklist/checklist-02`, `roles/roles-06`, `spec-gap/spec-gap-04`, critical; Task 15); thanh `X/Y` đếm nhầm văn bản văn phòng phát hành (`checklist-05`; Task 17, SPEC §4.10) |
| Ký hợp đồng dịch vụ, chốt giá trị và các đợt thu | Tab **Hợp đồng và thanh toán** trên trang vụ việc: luật sư phụ trách bấm "Soạn hợp đồng" (một giá trị, thuế suất nếu có, các đợt theo phần trăm hoặc số tiền), rồi "Kích hoạt" khi khách đã ký — tổng các đợt lệch một đồng thì không kích hoạt được | **[Xong]** M9 — xem Giai đoạn 5 |

---

## Giai đoạn 3 — Xử lý vụ việc

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Chuyển giai đoạn, viết cập nhật cho khách | Biểu mẫu chuyển giai đoạn có phần xem trước đúng thứ khách sẽ đọc; vào giai đoạn kết thúc thì vụ được đóng (`closed_at`) | **[Xong]** sau M6.5 — trước đó tab Tiến độ vỡ vĩnh viễn sau lần cập nhật đầu trên vụ chưa bật cổng (`e2e/F1`, critical; Task 1), máy chủ thư chết thì luật sư gặp lỗi 500 (`stage/stage-01`; Task 11), không chỗ nào ghi `closed_at` (`stage-03`; Task 5), bấm hai lần sinh hai dòng (`stage-05`; Task 10) |
| Ghi chú nội bộ không bao giờ lộ ra ngoài | Ghi chú nội bộ tách khỏi nội dung công bố, chặn ở ba lớp | **[Xong]** |
| Nhận giấy tờ khách nộp, duyệt hoặc từ chối kèm lý do | Tab **Danh mục hồ sơ**, duyệt ngay trên dòng; một lần nộp nhiều trang là một phiên bản; duyệt gắn với đúng những tệp người duyệt đã thấy | **[Xong]** sau M6.5 — trước đó giấy nhiều trang nộp từng tệp làm trang trước biến mất, và tệp đến lúc hộp xác nhận đang mở vẫn được nhận (`checklist-03`, `04`; Task 17, R10, R11). **Còn thiếu:** khách chưa được báo bằng thư khi giấy tờ bị từ chối (`checklist-01`, chuyển M6 Task 3) — khách chỉ thấy lý do khi tự mở cổng |
| Lưu tài liệu theo bốn nhóm, nhóm nội bộ không bao giờ hiện cho khách | Tab **Tài liệu**, nhóm D nền khác màu và không có nút công bố; văn bản nhóm B đi trình duyệt → đã ký, đã nộp → công bố | **[Xong]** sau M6.5 — trước đó văn bản nhóm B **không bao giờ công bố được** vì không có đường tới `signed_filed` (`docs/docs-1`, critical), và đổi nhóm B → C vượt được vòng đời (`docs-2`); sửa ở Task 16 (R9). Rút lại một tài liệu đã công bố: chưa có, M7 Task 7 |
| **Đặt mốc thời hạn tố tụng** | Tab **Mốc thời hạn**: thêm nhanh, đổi người phụ trách, quá hạn và hết hạn hôm nay tô đỏ, còn dưới bảy ngày tô vàng, đã xong thì xám | **[Xong]** 2026-09-23 — **sửa và xoá một mốc** (phiên toà hoãn) chưa có lúc rà soát (`deadlines/F7`), đang hoàn tất ở M6.5 Task 14; widget "Mốc thời hạn 7 ngày tới" trên trang chủ (`F5`, `spec-gap-05`) cũng ở Task 14 |
| Được nhắc trước khi tới hạn, theo bậc | Tác vụ `CheckDeadlines` 07:00 hằng ngày, bậc 14/7/3/1 ngày và quá hạn; thư qua hàng đợi, người nhận là người được xem vụ | **[Xong]** (M6 Task 6, trên `main` từ 2026-09-23) và sửa ở M6.5 — một hộp thư lỗi dừng cả lượt nhắc và mất nhật ký (`deadlines/F1`, `notify-2`, critical; Task 11), thư vụ hạn chế gửi tới người không được xem (`F2`; Task 12), người phụ trách bị khoá thì mốc im lặng (`F4`; Task 4, 12). Thông báo cảnh báo quá hạn trong hệ thống (`F6`) đang hoàn tất ở Task 14 |
| **Ghi lại cuộc gọi, buổi làm việc với khách** | Tab **Liên lạc**, ghi một cuộc gọi trong dưới 15 giây | **[Có kế hoạch]** M7, mới bổ sung 2026-09-22 |
| Biết hồ sơ nào đang đứng im quá lâu | Cảnh báo 14 ngày trong hệ thống, 21 ngày gửi thư cho quản lý | **[Có kế hoạch]** M6 |
| Bàn giao khi luật sư nghỉ việc mà không rơi mốc hạn nào | `ReassignMatter` cho một vụ: đổi luật sư phụ trách, chuyển mốc chưa xong và yêu cầu khách chưa đóng; không cho vô hiệu hoá hay xoá người còn giữ việc | **[Xong]** cho từng vụ (M6.5 Task 4, R7, kéo lên từ M7). **[Có kế hoạch]** M7: màn hình bàn giao hàng loạt, và thư tổng hợp mốc hạn cho người nhận |

---

## Giai đoạn 4 — Khách hàng theo dõi

| Khách làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Đăng nhập an toàn trên điện thoại | Mật khẩu cộng mã một lần qua email, khoá sau năm lần sai theo cả tài khoản lẫn địa chỉ mạng; văn phòng mở khoá được | **[Xong]** sau M6.5 — trước đó ô "Ghi nhớ đăng nhập" cho vào lại 400 ngày không cần mật khẩu lẫn mã (`portal/portal-1`), khách bị xoá vẫn đăng nhập được (`portal-3`), và văn phòng không có cách mở khoá (`portal-4`); sửa ở Task 2 và 7 (R12) |
| Xem danh sách hồ sơ của mình | Màn hình danh sách, một hồ sơ thì vào thẳng trang tiến độ | **[Xong]** |
| Xem hồ sơ đang ở giai đoạn nào, sắp tới làm gì | Trang tiến độ bảy khối, viết cho người không học luật, có tóm tắt cho khách | **[Xong]** sau M6.5 — "Tóm tắt cho khách" trước đó không hiện ở đâu (`portal-2`; Task 5) |
| Biết còn thiếu giấy tờ gì và nộp bằng ảnh chụp | Màn hình nộp giấy tờ, chụp thẳng từ điện thoại, một lần nộp nhiều trang | **[Xong]** sau M6.5 — trước đó 3/6 loại vụ việc không có đầu mục nào để nộp (`checklist-02`; Task 15) và giấy nhiều trang bị ghi đè từng trang (`checklist-03`; Task 17) |
| Đọc lý do khi giấy tờ bị từ chối và nộp lại | Lý do hiện nguyên văn, bản nộp lại nối vào bản cũ | **[Xong]** — khách phải tự mở cổng mới thấy; thư báo bị từ chối là M6 Task 3 |
| Hỏi lại văn phòng và nhận trả lời | Yêu cầu từ khách, trả lời theo luồng; hộp thư văn phòng sắp theo hoạt động gần nhất | **[Xong]** phần hỏi và trả lời trên màn hình. **Còn thiếu:** văn phòng **không được báo** khi khách gửi yêu cầu mới hay hỏi tiếp, nhân sự phải tự mở tab Yêu cầu của từng vụ (`requests/REQ-1`, `REQ-2`); khách không được báo khi văn phòng trả lời (`REQ-4`). Cả ba chuyển sang M6 Task 4 |
| Nhận thư báo khi có cập nhật mới | Bốn mẫu thư cho khách, chỉ chứa nội dung đã công bố | **[Có kế hoạch]** M6 |
| **Xem đã đóng bao nhiêu trên tổng giá trị hợp đồng** | Khối **Hợp đồng và thanh toán** trên trang tiến độ của cổng: số hợp đồng, tổng giá trị, thuế suất, ngày ký, từng đợt (đến hạn khi nào, đã thanh toán, còn lại, quá hạn) và các khoản văn phòng đã nhận. Không hiện ghi chú nội bộ, lý do miễn/huỷ, người ghi, khoản thu đã huỷ, bản nháp hay hợp đồng đã huỷ. Đợt theo tiến độ chưa tới bước của nó nói "đến hạn khi vụ việc tới bước …" bằng nhãn cho khách; tới bước đó thì đổi thành ngày đến hạn. Gói bàn giao in cùng bảng kê đó | **[Xong]** M9 Task 10 — chủ văn phòng đã quyết: **khách xem được** (phán quyết P1, 2026-09-24). Không có thư nhắc nợ nào gửi khách |

Văn phòng nhìn ngược lại: mỗi dòng đã công bố mang nhãn **khách đã xem lúc nào**, và
dòng chưa ai xem quá năm ngày thì nhắc luật sư gọi điện.

---

## Giai đoạn 5 — Tiền

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Chốt một giá trị hợp đồng duy nhất | Tab **Hợp đồng và thanh toán** của vụ: một hợp đồng cho một vụ, giá trị là số khách trả (đã gồm thuế nếu có); bản nháp sửa thẳng được; từ lúc kích hoạt, đổi giá trị hay lịch thu chỉ bằng **Ký phụ lục** (lý do, ngày ký, giá trị cũ và mới được giữ lại, không sửa, không xoá); bản nháp chưa có khoản thu thì xoá được | **[Xong]** M9 |
| Chia thành các đợt thu gắn vào tiến độ | Mỗi đợt đến hạn theo một trong ba cách: khi ký hợp đồng, vào một ngày cụ thể, hoặc **khi vụ việc tới một giai đoạn** ("thu đợt hai 15 ngày sau khi nộp đơn khởi kiện") — luật sư chuyển giai đoạn là đợt đó có ngày đến hạn ngay, tính từ ngày giai đoạn thật sự xảy ra; một lượt đối chiếu 07:00 hằng ngày bắt nốt những đợt lỡ | **[Xong]** M9 (Task 4, 6) |
| Ghi từng khoản tiền thật sự nhận, ai ghi, nhận bằng cách nào | Trang **Công nợ** của kế toán (xem "Kế toán ghi tiền" bên dưới); người ghi, ngày tiền về, cách nhận, mã giao dịch/số biên lai; ghi nhầm thì **huỷ** kèm lý do, khoản đã huỷ vẫn nằm đó | **[Xong]** M9 (Task 5, 8) |
| Được nhắc khi một đợt quá hạn | Thư nội bộ 08:00 hằng ngày: ngày đầu quá hạn, rồi bảy ngày một lần, tới kế toán và luật sư phụ trách (vụ hạn chế: luật sư phụ trách và quản trị viên). Không có thư nhắc nợ nào gửi khách | **[Xong]** M9 Task 11 |
| Nhìn bức tranh tiền: đã thu trên tổng, còn phải thu | Trang **Doanh thu**: vành khuyên đã thu / còn phải thu / quá hạn, doanh thu theo thời gian, theo đợt/giai đoạn, cơ cấu theo 12 lĩnh vực, tải theo luật sư, hồ sơ đã kết thúc còn công nợ; lọc theo kỳ, luật sư, lĩnh vực. Mỗi biểu đồ ghi rõ nó lọc theo ngày ký hay ngày tiền về, theo luật sư lúc thu hay luật sư hiện tại | **[Xong]** M9 Task 9 |
| Không đóng hồ sơ nhầm khi còn công nợ | Đóng vụ việc không bị chặn (vụ đã kết thúc còn nợ hiện trên trang Doanh thu, bộ lọc "Đã kết thúc, còn công nợ" của trang Công nợ và dải cảnh báo trên tab tiền), nhưng xoá vụ, huỷ vụ mở nhầm hay xoá khách hàng thì bị chặn khi còn hợp đồng đang hiệu lực có dư nợ | **[Xong]** M9 |

### Kế toán ghi tiền

1. Kế toán mở **Công nợ** (menu trái). Trang chỉ có mã hồ sơ, loại vụ việc, tên khách hàng, tên
   đợt, các con số và các ngày — **không** có tiêu đề vụ việc, tài liệu hay tiến độ; vụ hạn chế
   không có trên trang này (chỉ luật sư phụ trách và quản trị viên thấy và ghi tiền của nó).
2. Tìm đợt khách vừa trả: bộ lọc "Quá hạn", "Đến hạn trong 7 ngày", "Đã kết thúc, còn công nợ"
   và "Khách hàng".
3. Bấm **Ghi khoản thu** trên dòng đó: số tiền (gõ `1.250.000` — dấu chấm là phân cách nghìn, không
   nhận số lẻ), ngày tiền về (không được ở tương lai), cách nhận, mã giao dịch hoặc số biên lai.
   Thu một phần là bình thường; thu vượt số còn lại của đợt thì bị từ chối kèm số còn thiếu — phần
   dư không tự dồn sang đợt sau (muốn đổi lịch thu thì luật sư ký phụ lục). Thu đủ thì đợt tự sang
   "đã thu đủ".
4. Ghi nhầm: **Huỷ khoản thu** trên dòng của đợt (chọn khoản cần huỷ), hoặc — khi đợt đã thu đủ và
   không còn trong bảng — ở mục **Khoản thu gần đây** cuối trang. Mục này mặc định chỉ có khoản thu
   có ngày tiền về trong **90 ngày** gần nhất; khoản cũ hơn (như khoản ghi lùi ngày lúc nhập hợp đồng
   cũ, xem mục dưới) thì mở bộ lọc của mục, **gõ mã hồ sơ** vào ô "Mã hồ sơ" rồi **bấm "Áp dụng bộ
   lọc"**: mục hiện mọi khoản thu chưa huỷ của hồ sơ đó, cũ đến đâu cũng vậy. Gõ xong mà chưa bấm thì
   danh sách chưa đổi. Lý do ít nhất 20 ký tự. Khoản đã huỷ không bị xoá, chỉ ra khỏi mọi
   tổng; trạng thái đợt lùi lại đúng như trước. Hợp đồng đã hoàn tất thì không huỷ khoản thu được.
   Vụ hạn chế không có trên trang của kế toán: quản trị viên huỷ ở chính mục này (khoản cũ hơn 90
   ngày: cũng gõ mã hồ sơ rồi bấm "Áp dụng bộ lọc"); luật sư phụ trách huỷ trên tab **Hợp đồng và thanh toán** của vụ, nhưng nút ở đó chỉ huỷ khoản
   **mới nhất** chưa huỷ của đợt — muốn huỷ một khoản cũ hơn thì huỷ lần lượt từ mới về cũ rồi ghi
   lại những khoản đúng.
5. Doanh thu của một khoản thu tính cho **luật sư phụ trách lúc tiền về**; bàn giao vụ sau đó không
   dời khoản đã thu sang người mới (phần còn phải thu thì theo người mới).

Không có ô tải bản scan biên lai ở trang này (đã chọn giữ `mã giao dịch / số biên lai`, M9 Task 8);
bản scan, nếu cần lưu, tải lên tab Tài liệu của vụ ở nhóm "Chỉ nội bộ".

### Nhập hợp đồng đang chạy khi bắt đầu dùng hệ thống

Văn phòng bắt đầu dùng hệ thống khi đã có những hợp đồng ký từ trước, khách đã trả một phần, vụ
việc đã đi được nửa đường. Nhập chúng như sau (phép đo:
`tests/Feature/Actions/Billing/GoLiveImportScenarioTest.php`):

1. **Đưa vụ tới giai đoạn hiện tại bằng MỘT lần chuyển ghi lùi ngày** (quản trị viên bỏ qua được thứ
   tự giai đoạn), ngày xảy ra là ngày thật vụ vào giai đoạn đó. Hệ thống chỉ có đúng một dòng tiến
   độ: vào giai đoạn hiện tại. **Các giai đoạn trước đó không có dòng nào.**
2. **Soạn hợp đồng với ngày ký thật** và lịch thu thật, theo luật:
   - đợt của **giai đoạn đã qua** (ví dụ "khi nộp đơn" trên một vụ đã được toà thụ lý) nhập là
     **đến hạn vào một ngày cụ thể** — ngày đã hẹn thật. Nhập nó là "khi vụ tới giai đoạn nộp đơn"
     thì nó **không bao giờ đến hạn**: không có dòng tiến độ nào vào bước đó, và lượt đối chiếu hằng
     ngày chỉ đọc dòng tiến độ có thật;
   - đợt của **giai đoạn hiện tại** để "khi vụ tới giai đoạn" được: lần chuyển ở bước 1 là lần chạm
     của nó, hạn tính từ ngày ghi ở bước 1 (không sớm hơn ngày ký);
   - đợt của **giai đoạn chưa tới** để "khi vụ tới giai đoạn" như bình thường.
3. **Kích hoạt** với ngày ký thật. Đợt khi ký và đợt của giai đoạn hiện tại có ngày đến hạn ngay —
   nhiều khi đã qua.
4. **Kế toán ghi lùi ngay các khoản khách đã trả** (ngày tiền về thật). Làm trong cùng buổi, trước
   08:00 hôm sau: đợt nào đã qua hạn mà chưa ghi tiền thì là "quá hạn" thật trên trang Doanh thu,
   trang Công nợ, cổng khách và thư nhắc 08:00 — hệ thống không phân biệt được "chưa thu" với
   "đã thu mà chưa nhập". Ghi nhầm (sai đợt, sai ngày, sai số tiền) thì huỷ rồi ghi lại cho đúng:
   khoản ghi lùi hơn 90 ngày **không hiện** ở mục **Khoản thu gần đây** của trang Công nợ cho tới khi
   **gõ mã hồ sơ** vào ô "Mã hồ sơ" của bộ lọc mục đó **rồi bấm "Áp dụng bộ lọc"** — gõ mà chưa bấm thì
   danh sách chưa đổi (xem "Kế toán ghi tiền", bước 4; vụ hạn chế: quản trị viên hoặc luật sư phụ trách
   huỷ).
5. Kiểm: trang Doanh thu, kỳ chứa ngày ký — lát "Quá hạn" phải đúng bằng số khách thật sự còn nợ
   quá hạn (thường là 0); `php artisan billing:check-invariants` sạch.

---

## Giai đoạn 6 — Kết thúc, bàn giao, lưu trữ

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Sinh gói bàn giao cho khách | Tệp nén nhóm A/B/C kèm mục lục PDF và toàn bộ tường trình tiến độ, không bao giờ lẫn tài liệu nội bộ | **[Có kế hoạch]** M7 |
| Cho khách tải về từ cổng, có hạn | Quyền tra cứu hết sau 90 ngày mặc định, dữ liệu vẫn nguyên bên trong | **[Có kế hoạch]** M7 |
| Giữ hồ sơ theo chính sách lưu trữ | Hạn lưu trữ mặc định 10 năm, hệ thống **cảnh báo chứ không bao giờ tự xoá** | **[Có kế hoạch]** M7 |
| Tìm lại một hồ sơ cũ bằng bất cứ thứ gì nhớ được | Ô tìm kiếm sáu nguồn, kết quả luôn đi qua phân quyền | **[Có kế hoạch]** M7 |

---

## Nền móng chạy dưới tất cả

| Thứ gì | Trạng thái |
|---|---|
| Phân quyền theo vai trò, phạm vi nhìn thấy suy từ đội ngũ vụ việc | **[Xong]** sau M6.5 — trước đó luật sư xem và sửa được tài khoản cổng của mọi khách, kể cả ép chuyển sang khách khác (`roles/roles-01`, critical; `roles-02`; Task 2), và quyền "hạn chế" của trợ lý thực tế là toàn quyền (`roles-05`; Task 5, 10, R5) |
| Ba lớp bảo vệ độc lập cho dữ liệu khách hàng trên cổng | **[Xong]** — khách đã bị xoá mềm nay cũng bị chặn ở cả ba lớp (`portal-3`; M6.5 Task 2) |
| Không có quyền và không tồn tại đều trả lời giống hệt nhau | **[Xong]** |
| Nhật ký hoạt động cho mọi thao tác nhạy cảm | **[Xong]** một phần — trước M6.5 Task 20 trang nhật ký hiện khoá dịch thô và không hiện chi tiết (kể cả lý do ghi đè xung đột); nay đọc được, che số điện thoại/email/địa chỉ và chặn số CCCD. Còn thiếu tab nhật ký riêng của từng vụ việc, **[Có kế hoạch]** M7 |
| Tệp nằm ngoài thư mục web, chỉ tải qua đường ký có hạn năm phút | **[Xong]** |
| Thương hiệu văn phòng trên mọi màn hình | **[Xong]** |
| Thư đi ra đều có nhật ký để tra khi khách nói không nhận được | **[Xong]** sau M6.5 — bảng `outbound_messages` có từ M6 Task 1 (2026-09-23) nhưng **không có màn hình nào để tra** (`notify/notify-8`, `spec-gap/spec-gap-07`); M6.5 Task 13 thêm trang nhật ký thư, và nút "Thư đã gửi" trên trang vụ việc mở trang đó đã lọc theo vụ (mỗi người chỉ thấy thư của vụ mình được xem; admin thấy mọi dòng). Nút gửi lại một thư thất bại: M6 Task 10 |
| Giám sát cron: cron chết thì trang chủ nói ra | **[Xong]** 2026-09-23 |
| Xác thực hai lớp cho toàn bộ tài khoản nội bộ | **[Có kế hoạch]** M8 |
| Sao lưu hằng ngày **đã thử khôi phục thật** | **[Có kế hoạch]** M8 |

---

## Chưa có chủ — cần chủ văn phòng quyết

Năm thứ dưới đây không nằm trong bất kỳ kế hoạch nào. Tôi không tự thêm, vì mỗi thứ
đều đổi phạm vi công việc.

1. **Kho mẫu văn bản để soạn thảo.** Hệ thống hiện quản lý *danh mục giấy tờ cần có*,
   không quản lý *mẫu để soạn*. Với văn phòng soạn nhiều đơn từ lặp lại thì đây là
   thứ tiết kiệm thời gian nhiều nhất sau nhắc hạn. Đề xuất: một milestone riêng sau
   khi hệ thống chạy thật vài tháng, vì mẫu phải lấy từ chính văn bản văn phòng đang dùng.
2. **Lịch phiên toà dạng lịch.** Dữ liệu mốc hạn đã có, nhưng nhìn theo danh sách chứ
   chưa nhìn theo tháng. Rẻ để thêm sau khi màn hình mốc hạn của M6 xong.
3. **Giao việc nội bộ.** Hiện chỉ có mốc hạn gắn người phụ trách. Một văn phòng đông
   người thường cần giao việc nhỏ không phải mốc tố tụng.
4. **Hoá đơn điện tử.** M9 ghi thuế giá trị gia tăng trên hợp đồng, nhưng phát hành
   hoá đơn điện tử là một tích hợp với nhà cung cấp, cần chọn nhà cung cấp trước.
5. **Tính phí theo giờ.** Cố ý hoãn: mô hình hiện tại là ký một lần thu theo giai
   đoạn. Quan hệ dữ liệu đã chừa sẵn chỗ nên gắn thêm sau không phải sửa mô hình.

Và một câu hỏi đang chờ trả lời, đã hỏi trước đó:

- **Bốn thông tin pháp lý của văn phòng**: mã số thuế, Đoàn Luật sư, số Giấy đăng ký
  hoạt động, địa chỉ trụ sở. Chúng xuất hiện ở chân thư, mục lục gói bàn giao và tài
  liệu triển khai.

(Câu hỏi "khách có được xem hợp đồng và lịch thu của mình trên cổng không" đã có câu trả
lời ngày 2026-09-24: **có** — phán quyết P1 của kế hoạch M9, cài ở M9 Task 10; xem Giai
đoạn 4. Chủ văn phòng đảo được.)

---

## Ba chỗ trống tìm ra trong lượt rà soát ngày 2026-09-22

Ghi lại riêng, vì cả ba đều là **tính năng đã được đặc tả, có bảng dữ liệu, có phân
quyền, nhưng không có màn hình** — loại thiếu sót khó thấy nhất, bởi mọi test đều xanh.

1. **Không có màn hình tạo mốc thời hạn.** Nghiêm trọng nhất trong ba: tác vụ nhắc hạn
   của M6 sẽ chạy hằng ngày trên một bảng rỗng và vẫn xanh. Một tính năng đúng, chạy
   đều, và vô nghĩa. Đã đưa thành Task 5 của M6, đứng **trước** tác vụ nhắc. Xong 2026-09-23;
   sửa và xoá mốc thêm ở M6.5 Task 14.
2. **Không có màn hình ghi nhật ký liên lạc**, dù đặc tả liệt kê nó ngay trên dòng
   milestone bàn giao. Đã đưa thành Task 8 của M7, kèm một lỗ hổng phân quyền mang từ
   rà soát trước sang phải vá cùng lúc.
3. **Không có tab nhật ký riêng của từng vụ việc.** Nhật ký toàn hệ thống đã có; đây
   là bản lọc theo một vụ việc. Gộp vào cùng Task 8 của M7.

---

## Tôi sẽ dựa vào gì để nói "xong và sẵn sàng đưa vào hoạt động"

Viết ra trước, để lời báo cáo về sau kiểm được chứ không phải tin. Tôi chỉ báo xong
khi **cả ba nhóm dưới đây đều đạt**, và báo cáo sẽ kèm bản ghi từng bước chứ không
kèm một câu khẳng định.

### A. Ba vai chạy thông trên dữ liệu thật

Chạy tay, trên một cơ sở dữ liệu vừa dựng lại từ đầu, không phải trên test.

1. **Quản trị** tạo khách hàng mới, mở vụ việc, hệ thống chặn đúng khi bên đối lập
   trùng một khách hàng hiện hữu, và mọi lần kiểm tra đều để lại dấu vết.
2. **Luật sư** áp danh mục giấy tờ, đặt một mốc thời hạn, chuyển giai đoạn kèm một
   dòng cập nhật công bố cho khách, công bố một tài liệu, và ghi lại một cuộc gọi.
3. **Khách hàng** đăng nhập trên khổ điện thoại thật, thấy đúng dòng vừa công bố,
   thấy còn thiếu giấy tờ gì, nộp một ảnh chụp, bị từ chối và đọc được lý do nguyên
   văn, nộp lại, rồi gửi một câu hỏi và nhận trả lời.
4. **Quay lại phía văn phòng**: nhãn khách đã xem hiện đúng thời điểm, giấy tờ khách
   nộp duyệt được, và thanh tiến độ nhảy đúng.
5. **Ranh giới**: không một đường nào — kể cả sửa tham số trên thanh địa chỉ — cho
   một khách thấy dữ liệu của khách khác hoặc thấy tài liệu nội bộ.

### B. Chất lượng đo được, không phải cảm nhận

- Toàn bộ bộ kiểm thử xanh trên **cả hai** cơ sở dữ liệu: bản nhẹ dùng khi phát
  triển và bản thật dùng khi chạy.
- Độ phủ của tầng nghiệp vụ và tầng phân quyền từ 80% trở lên, có số đo dán kèm.
- Mỗi milestone đã qua **một lượt rà soát độc lập được giao nhiệm vụ giả định có
  một lỗi nghiêm trọng**, và mọi phát hiện đã đóng hoặc đã ghi rõ lý do chưa đóng.
- Dựng lại toàn bộ cơ sở dữ liệu từ số không và quay ngược lại được trọn vòng.

### C. Sẵn sàng vận hành thật

- Tên miền, chứng chỉ bảo mật, và **địa chỉ máy chủ trung gian điền đúng** — chừng
  nào ô này còn trống thì một người gõ sai mật khẩu năm lần sẽ khoá cả cổng khách.
- Đúng một dòng lịch chạy tự động, và trang chủ tự báo đỏ khi lịch đó chết.
- Sao lưu hằng ngày **đã thử khôi phục thật một lần**, có ghi thời gian khôi phục.
- Xác thực hai lớp bật cho toàn bộ tài khoản nội bộ.
- Bốn thông tin pháp lý của văn phòng đã điền: mã số thuế, Đoàn Luật sư, số Giấy
  đăng ký hoạt động, địa chỉ trụ sở.
- Tài liệu cài đặt đã được **một người chưa từng đọc mã nguồn** dựng lại thành công
  trên một máy chủ trống.

### Về việc đưa lên điện thoại thành ứng dụng

Cổng khách hàng đã được dựng cho điện thoại ngay từ đầu: một cột, chữ to, vùng bấm
44 điểm ảnh, không bảng ngang, chụp ảnh thẳng từ máy ảnh. Mở bằng trình duyệt điện
thoại là dùng được ngay, không cần chờ gì.

Muốn thành ứng dụng tải từ chợ ứng dụng thì cần thêm một lớp giao tiếp dữ liệu, và
kiến trúc hiện tại đã chừa sẵn chỗ: **toàn bộ nghiệp vụ nằm trong tầng Action chứ
không nằm trong màn hình**, nên lớp đó chỉ gọi lại đúng những Action đang chạy, không
phải viết lại luật nào. Đó là một milestone riêng, làm sau khi hệ thống chạy thật một
thời gian — vì thứ đáng đưa lên ứng dụng phải là thứ đã biết chắc khách dùng.
