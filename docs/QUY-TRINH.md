# VK-CRM — Quy trình chuẩn của văn phòng, và hệ thống đỡ ở chỗ nào

Tài liệu này là **một nguồn sự thật duy nhất về luồng nghiệp vụ**: từ lúc một người
lần đầu gọi tới văn phòng, cho tới lúc hồ sơ của họ được bàn giao và lưu trữ. Mỗi
bước ghi rõ ba thứ: **văn phòng làm gì**, **hệ thống đỡ bằng màn hình hay tác vụ
nào**, và **hiện đã có hay chưa**.

Rà soát đối chiếu mã nguồn ngày **2026-09-22**. Trạng thái ghi ở đây là thứ đo được
trong repo, không phải ý định.

Ký hiệu: **[Xong]** đã chạy được và đã hợp nhất · **[Đang làm]** đã có mã, đang khép
lỗi · **[Có kế hoạch]** đã đặc tả chi tiết, chưa viết mã · **[Chưa có chủ]** chưa
thuộc kế hoạch nào.

---

## Giai đoạn 1 — Tiếp nhận và thẩm định đầu vào

Đây là giai đoạn quyết định nhiều tiền nhất và mang rủi ro nghề nghiệp cao nhất, và
cũng là giai đoạn hệ thống hiện **yếu nhất**.

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Ghi lại mỗi lần có người liên hệ, dù qua điện thoại, Zalo, website hay đến trực tiếp | Bảng tiếp nhận, màn hình nhập nhanh | **[Có kế hoạch]** M10 |
| **Kiểm tra xung đột lợi ích trước khi nghe nội dung vụ việc** | `RunConflictCheck` chạy ngay ở bước danh tính; kết quả Đỏ khoá phần nội dung | **[Có kế hoạch]** M10 — Action đã có từ M3 |
| Phát hiện cùng một người gọi nhiều lần | Dò trùng theo số điện thoại đã chuẩn hoá và tên đã chuẩn hoá | **[Có kế hoạch]** M10 |
| Bảo đảm không ai bị bỏ quên không gọi lại | Mốc phản hồi lần đầu, nhắc quá ngưỡng, widget trang chủ | **[Có kế hoạch]** M10 |
| Từ chối vụ việc và ghi lý do | Lý do xung đột chỉ hiện cho quản lý; người gọi không bao giờ được biết lý do thật | **[Có kế hoạch]** M10 |

**Luật nghề nghiệp đứng sau giai đoạn này.** Nếu nghe hết câu chuyện rồi mới phát
hiện bên kia là khách hàng hiện hữu thì thông tin bí mật đã nghe rồi và không rút lại
được. Vì vậy kiểm tra xung đột đứng **trước** phần nội dung, không phải sau. Và khi
từ chối vì xung đột, nói lý do thật cho người gọi chính là tiết lộ rằng có tồn tại
một khách hàng khác.

---

## Giai đoạn 2 — Mở hồ sơ

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Tạo khách hàng, kiểm tra trùng | Resource `Client`, số định danh mã hoá khi lưu | **[Xong]** |
| Mở vụ việc, sinh mã không bao giờ đổi | `OpenMatter`, mã dạng `VK-2026-DD-0147` | **[Xong]** |
| Chạy kiểm tra xung đột trước khi nhận | `RunConflictCheck` hiện ngay trong biểu mẫu tạo; kết quả Đỏ phải có lý do ghi đè | **[Xong]** |
| Khai các bên trong vụ việc | Tab **Các bên**; thêm một bên thì chạy lại kiểm tra xung đột tại chỗ | **[Xong]** |
| Giao luật sư phụ trách và đội ngũ | Bảng đội ngũ, phạm vi nhìn thấy suy từ đội ngũ | **[Xong]** |
| Áp danh mục giấy tờ theo loại vụ việc | `ApplyChecklistTemplate`, thanh tiến độ `X/Y` | **[Xong]** |
| Ký hợp đồng dịch vụ, chốt giá trị và các đợt thu | Hợp đồng một giá trị, chia đợt gắn vào giai đoạn | **[Có kế hoạch]** M9 |

---

## Giai đoạn 3 — Xử lý vụ việc

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Chuyển giai đoạn, viết cập nhật cho khách | Biểu mẫu chuyển giai đoạn có phần xem trước đúng thứ khách sẽ đọc | **[Xong]** |
| Ghi chú nội bộ không bao giờ lộ ra ngoài | Ghi chú nội bộ tách khỏi nội dung công bố, chặn ở ba lớp | **[Xong]** |
| Nhận giấy tờ khách nộp, duyệt hoặc từ chối kèm lý do | Tab **Danh mục hồ sơ**, duyệt ngay trên dòng | **[Xong]** |
| Lưu tài liệu theo bốn nhóm, nhóm nội bộ không bao giờ hiện cho khách | Tab **Tài liệu**, nhóm D nền khác màu và không có nút công bố | **[Xong]** |
| **Đặt mốc thời hạn tố tụng** | Tab **Mốc thời hạn** — *bảng dữ liệu và quyền đã có từ đầu, nhưng chưa có màn hình nào để tạo một mốc hạn* | **[Có kế hoạch]** M6, mới bổ sung 2026-09-22 |
| Được nhắc trước khi tới hạn, theo bậc | Tác vụ nhắc hằng ngày, bậc 14/7/3/1 ngày và quá hạn | **[Có kế hoạch]** M6 |
| **Ghi lại cuộc gọi, buổi làm việc với khách** | Tab **Liên lạc**, ghi một cuộc gọi trong dưới 15 giây | **[Có kế hoạch]** M7, mới bổ sung 2026-09-22 |
| Biết hồ sơ nào đang đứng im quá lâu | Cảnh báo 14 ngày trong hệ thống, 21 ngày gửi thư cho quản lý | **[Có kế hoạch]** M6 |
| Bàn giao khi luật sư nghỉ việc mà không rơi mốc hạn nào | `ReassignMatter`, chuyển toàn bộ mốc hạn sang người mới | **[Có kế hoạch]** M7 |

---

## Giai đoạn 4 — Khách hàng theo dõi

| Khách làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Đăng nhập an toàn trên điện thoại | Mật khẩu cộng mã một lần qua email, khoá sau năm lần sai theo cả tài khoản lẫn địa chỉ mạng | **[Đang làm]** M5 |
| Xem danh sách hồ sơ của mình | Màn hình danh sách, một hồ sơ thì vào thẳng trang tiến độ | **[Đang làm]** M5 |
| Xem hồ sơ đang ở giai đoạn nào, sắp tới làm gì | Trang tiến độ bảy khối, viết cho người không học luật | **[Đang làm]** M5 |
| Biết còn thiếu giấy tờ gì và nộp bằng ảnh chụp | Màn hình nộp giấy tờ, chụp thẳng từ điện thoại | **[Đang làm]** M5 |
| Đọc lý do khi giấy tờ bị từ chối và nộp lại | Lý do hiện nguyên văn, bản nộp lại nối vào bản cũ | **[Đang làm]** M5 |
| Hỏi lại văn phòng và nhận trả lời | Yêu cầu từ khách, trả lời theo luồng | **[Đang làm]** M5 |
| Nhận thư báo khi có cập nhật mới | Bốn mẫu thư cho khách, chỉ chứa nội dung đã công bố | **[Có kế hoạch]** M6 |
| **Xem đã đóng bao nhiêu trên tổng giá trị hợp đồng** | Hợp đồng và lịch thu trên cổng khách | **[Có kế hoạch]** M9 — *còn một quyết định của chủ văn phòng, xem dưới* |

Văn phòng nhìn ngược lại: mỗi dòng đã công bố mang nhãn **khách đã xem lúc nào**, và
dòng chưa ai xem quá năm ngày thì nhắc luật sư gọi điện.

---

## Giai đoạn 5 — Tiền

| Văn phòng làm gì | Hệ thống đỡ bằng gì | Trạng thái |
|---|---|---|
| Chốt một giá trị hợp đồng duy nhất | Hợp đồng gắn vào vụ việc, sửa bằng phụ lục chỉ thêm không sửa | **[Có kế hoạch]** M9 |
| Chia thành các đợt thu gắn vào tiến độ | "Thu đợt hai khi nộp đơn khởi kiện" | **[Có kế hoạch]** M9 |
| Ghi từng khoản tiền thật sự nhận, ai ghi, nhận bằng cách nào | Bảng thanh toán có người ghi và phương thức | **[Có kế hoạch]** M9 |
| Nhìn bức tranh tiền: đã thu trên tổng, còn phải thu | Trang biểu đồ lọc theo thời gian, luật sư, lĩnh vực | **[Có kế hoạch]** M9 |
| Không đóng hồ sơ nhầm khi còn công nợ | Đóng vụ việc không bị chặn, nhưng xoá thì bị chặn khi còn dư nợ | **[Có kế hoạch]** M9 |

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
| Phân quyền theo vai trò, phạm vi nhìn thấy suy từ đội ngũ vụ việc | **[Xong]** |
| Ba lớp bảo vệ độc lập cho dữ liệu khách hàng trên cổng | **[Xong]** |
| Không có quyền và không tồn tại đều trả lời giống hệt nhau | **[Xong]** |
| Nhật ký hoạt động cho mọi thao tác nhạy cảm | **[Xong]** một phần — còn thiếu tab nhật ký riêng của từng vụ việc, **[Có kế hoạch]** M7 |
| Tệp nằm ngoài thư mục web, chỉ tải qua đường ký có hạn năm phút | **[Xong]** |
| Thương hiệu văn phòng trên mọi màn hình | **[Xong]** |
| Thư đi ra đều có nhật ký để tra khi khách nói không nhận được | **[Có kế hoạch]** M6 |
| Giám sát cron: cron chết thì trang chủ nói ra | **[Có kế hoạch]** M6 |
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

Và hai câu hỏi đang chờ trả lời, đã hỏi trước đó:

- **Bốn thông tin pháp lý của văn phòng**: mã số thuế, Đoàn Luật sư, số Giấy đăng ký
  hoạt động, địa chỉ trụ sở. Chúng xuất hiện ở chân thư, mục lục gói bàn giao và tài
  liệu triển khai.
- **Khách có được xem hợp đồng và lịch thu của mình trên cổng không.** Lập luận thuận:
  họ đã ký, đó là thông tin của chính họ. Lập luận nghịch: một khách đang tranh chấp
  đọc dòng "đợt ba thu khi có bản án sơ thẩm" có thể hiểu thành một lời hứa về kết quả.
  Mặc định hiện tại là **không**, và mọi thứ được đóng kín sẵn để câu trả lời "có" về
  sau chỉ là một lần nới có kiểm soát.

---

## Ba chỗ trống tìm ra trong lượt rà soát ngày 2026-09-22

Ghi lại riêng, vì cả ba đều là **tính năng đã được đặc tả, có bảng dữ liệu, có phân
quyền, nhưng không có màn hình** — loại thiếu sót khó thấy nhất, bởi mọi test đều xanh.

1. **Không có màn hình tạo mốc thời hạn.** Nghiêm trọng nhất trong ba: tác vụ nhắc hạn
   của M6 sẽ chạy hằng ngày trên một bảng rỗng và vẫn xanh. Một tính năng đúng, chạy
   đều, và vô nghĩa. Đã đưa thành Task 5 của M6, đứng **trước** tác vụ nhắc.
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
