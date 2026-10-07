# Chính sách dùng AI với hồ sơ của văn phòng

> **Bản nháp, chờ chủ văn phòng duyệt câu chữ.** Phiên bản: `2026-10-04`.
>
> Phiên bản này là phiên bản mà mỗi nhân sự tự tích cam kết trên trang "Kết nối AI của tôi" trước
> lần kết nối đầu tiên. Đổi một câu có nghĩa trong văn bản này thì phải đổi cả phiên bản (cấu hình
> `vkcrm.mcp.policy_version`): mọi kết nối AI ngừng ở lần gọi kế tiếp, cho tới khi từng người đọc và
> cam kết lại.
>
> Phần pháp lý dưới đây là thông tin tham khảo, không phải tư vấn pháp lý. Chủ văn phòng là luật sư,
> đọc nguyên văn các văn bản được nêu và ký duyệt trước khi bật kết nối AI trên dữ liệu thật
> [PL:263], [PL:369].
>
> Ký hiệu nguồn: `[PL:n]` là dòng n của `docs/research/2026-09-24-mcp-phap-ly-goi.md`, `[DC:n]` là
> dòng n của `docs/research/2026-09-24-doi-chieu-ung-dung-mcp.md`. Chỗ nào tra cứu ghi "chưa kiểm
> được", văn bản này cũng ghi như vậy.

## 1. Văn bản này dùng để làm gì

Từ M11, nhân sự có thể nối tài khoản AI của chính mình (Claude, ChatGPT, và một số ứng dụng AI
khác) vào hệ thống quản lý hồ sơ, để **hỏi** hồ sơ ("mốc nào của tôi tuần này?", "vụ này đang ở giai
đoạn nào?") và **soạn nháp** công việc hằng ngày. Cách kết nối từng ứng dụng ở `docs/KET-NOI-AI.md`.

Mọi thứ AI đọc qua kết nối này đi ra máy chủ của nhà cung cấp AI ở nước ngoài. Văn bản này nói ai
được dùng, dữ liệu nào không bao giờ đi qua, nhân sự phải tự làm gì trên tài khoản của mình, và báo
sự cố thế nào. Nó cũng là danh sách việc của chủ văn phòng trước khi bật kết nối AI (mục 9).

## 2. Ai được dùng

- **Chỉ nhân sự của văn phòng.** Khách hàng không bao giờ kết nối được AI vào hệ thống.
- **Quản trị viên bật cho từng người**, trên trang "Kết nối AI": "Tắt" (mặc định), "Chỉ đọc", hay
  "Đọc và ghi". Ngoài ra có hai công tắc chung của cả văn phòng: bật máy chủ AI, và cho phép ghi —
  cả hai mặc định tắt. Tắt công tắc chung thì mọi kết nối ngừng ngay ở lần gọi kế tiếp.
- **Kế toán không dùng được**, vì vai trò kế toán không xem nội dung vụ việc trên web.
- **Trước lần kết nối đầu tiên, nhân sự đọc văn bản này và tự tích cam kết** trên trang "Kết nối AI
  của tôi". Hệ thống lưu phiên bản đã cam kết, thời điểm, địa chỉ IP và trình duyệt. Đây là cam kết
  giữ bí mật của chính nhân sự với văn phòng (Quy tắc 7.2 của Bộ Quy tắc Đạo đức nghề nghiệp luật
  sư [PL:342]). Nó **không thay** sự đồng ý của khách hàng (mục 4).
- Nghỉ việc, bị vô hiệu hoá, đổi mật khẩu, đặt lại xác thực hai lớp, hay bị hạ quyền AI về "Tắt":
  mọi kết nối AI của người đó bị thu hồi cùng lúc.

## 3. AI thấy gì

**AI không bao giờ thấy nhiều hơn chính người đó thấy trên web.** Mỗi lần AI hỏi, hệ thống hỏi lại
đúng quyền mà màn hình web tương ứng hỏi, dưới tên người đã kết nối. Rồi thu hẹp thêm:

- **Vụ hạn chế không bao giờ lên AI**, kể cả với luật sư phụ trách và quản trị viên.
- **Vụ chưa được đánh dấu "cho phép AI" không bao giờ lên AI** (mục 4).
- Vụ đã xoá không lên AI.
- Với mọi thứ trên, AI chỉ nhận đúng một câu "Không tìm thấy", giống hệt một mã không tồn tại:
  không đếm, không gợi ý rằng có kết quả bị ẩn.
- **Tên các bên không phải khách của văn phòng** (bị đơn, người liên quan) mặc định ra dạng vai và số
  thứ tự ("Bị đơn 1", "Người liên quan 2"), vì họ không thể đồng ý [PL:294], [PL:373]. Nói thẳng:
  **đây là giả danh, không phải khử nhận dạng**. Tiêu đề vụ việc hay chứa tên đương sự, và mã hồ sơ
  dẫn ngược về người thật [PL:361]. Dữ liệu vẫn là dữ liệu cá nhân.

## 4. Vụ việc chỉ lên AI khi khách đã đồng ý bằng văn bản

Luật Luật sư (Điều 25) cấm tiết lộ thông tin vụ việc, trừ khi khách hàng đồng ý **bằng văn bản**
[PL:340]. Luật Bảo vệ dữ liệu cá nhân (Luật 91, Điều 9): im lặng không phải là đồng ý [PL:331].

- Vụ việc mới mặc định **không** lên AI.
- Luật sư bật cờ "cho phép AI" cho từng vụ ở tab Tổng quan, và phải tích ô "Khách đã đồng ý bằng văn
  bản cho việc này". Ô không bao giờ được đánh dấu sẵn. Mỗi lần đổi cờ có một dòng nhật ký ghi người
  đổi và thời điểm.
- Chỉ bật khi đã có văn bản đồng ý thật của khách cho vụ đó (mục 9, việc 2).
- Nếu chủ văn phòng đổi mặc định thành "cho phép" (`MCP_MATTER_DEFAULT=allowed`), mọi vụ mới lên AI
  ngay mà không có ô tích nào và không có dòng nhật ký nào cho riêng việc đó. Khi ấy hệ thống không
  còn giữ bằng chứng khách đã đồng ý; bằng chứng phải nằm ở hợp đồng giấy.

## 5. Những dữ liệu không bao giờ đi qua AI

Kể cả với người được xem chúng trên web. Hệ thống chặn cả nhóm, không che từng phần, vì đó là cách
các nền tảng lớn làm [DC:250], [DC:280], vì nhà cung cấp AI cấm thu thập giấy tờ định danh
[DC:352], vì ảnh CCCD là dữ liệu nhạy cảm theo Nghị định 356 [PL:327-328], và vì Luật 91 đòi chỉ xử
lý dữ liệu tối thiểu cần thiết [DC:108].

| Dữ liệu | Qua AI |
|---|---|
| Số CCCD, mã số thuế của khách; số so trùng CCCD của các bên | Không bao giờ |
| Số điện thoại của khách | Chỉ dạng che, ví dụ `***456` |
| Số điện thoại, địa chỉ, ghi chú của các bên | Không bao giờ |
| Ghi chú nội bộ (của dòng tiến độ, của vụ việc, của khách, của các bên) | Không đọc được. AI chỉ biết "có ghi chú nội bộ". AI vẫn **ghi** được ghi chú nội bộ vào một bản nháp |
| Tài liệu nhóm D (nội bộ) | Không liệt kê, không đếm, không mở |
| Nội dung tệp, đường tải tệp | Không. AI chỉ thấy tên, nhóm, trạng thái, ngày của tài liệu nhóm A, B, C |
| Kết quả kiểm tra xung đột lợi ích | Không có cách nào để AI hỏi |
| Nhật ký thư đã gửi, nhật ký hệ thống | Không có cách nào để AI hỏi |
| Mọi thứ của màn hình Tiếp nhận (khách tiềm năng, câu chuyện của họ) | Không bao giờ |

Nội dung do **khách** viết (yêu cầu, trả lời của khách, tiêu đề tài liệu khách nộp) đi qua AI trong
một trường riêng, đã bỏ ký tự ẩn, mã HTML, ảnh, đường dẫn. AI được dặn đó là dữ liệu, không phải
chỉ dẫn. Việc lọc này chỉ là lớp phụ: lớp chặn thật là AI không có cách nào gửi gì ra ngoài (mục 6).

## 6. AI làm được gì, và không làm được gì

- **Đọc**: mười một chức năng đọc (tìm vụ việc, xem tổng quan vụ, dòng tiến độ, mốc thời hạn, danh
  mục hồ sơ, danh sách tài liệu, yêu cầu của khách…). Danh sách ở `docs/KET-NOI-AI.md`.
- **Ghi**, chỉ cho người được bật "Đọc và ghi" khi công tắc ghi chung đang bật:
  - **soạn nháp** một dòng cập nhật tiến độ, và **soạn nháp** trả lời một yêu cầu của khách. Nháp
    nằm ở bảng riêng, không bao giờ tự tới khách. Người mở nháp trên trang vụ việc trong `/admin`,
    sửa, rồi tự bấm nút gửi hay công bố của màn hình web như mọi khi;
  - **thêm mốc thời hạn** và **ghi nhật ký liên lạc**, chỉ nội bộ: mốc không công bố cho khách, nhật
    ký không hiện cho khách. Cả hai mang nhãn "Tạo qua AI" trên web; mốc mang nhãn "Tạo qua AI, chưa
    xác nhận" cho tới khi có người bấm "Xác nhận", và vẫn được nhắc hạn như mọi mốc khác.
- **Không bao giờ**: xoá, sửa, gửi thư, công bố, chuyển giai đoạn, đánh dấu xong mốc, duyệt giấy tờ,
  hay gửi gì cho khách. Không có chức năng nào nhận địa chỉ người nhận.

**Nói thẳng về bước xác nhận.** Thêm mốc và ghi nhật ký cần hai lần gọi: lần đầu hệ thống chỉ trả bản
xem trước, lần hai mới ghi. Nếu nhân sự đặt ứng dụng AI ở chế độ "luôn cho phép" ("Always allow"),
thì AI tự gọi được cả hai lần mà không hỏi ai [DC:180], [DC:264]. Lớp an toàn thật là ba điều: thứ AI
ghi chỉ nằm trong nội bộ, mang nhãn "Tạo qua AI", và người sửa hay xoá được trên web. Vì vậy mục 7
bắt buộc đặt các chức năng ghi ở chế độ phải duyệt.

## 7. Bắt buộc trên tài khoản AI của mỗi người

Hệ thống không kiểm được cài đặt bên trong tài khoản AI của nhân sự [DC:158]. Đây là cam kết của
từng người, và văn phòng kiểm lại định kỳ (mục 10).

1. **Tắt việc dùng hội thoại để huấn luyện AI.**
   - **ChatGPT** (Free, Plus, Go, Pro): tắt mục **"Improve the model for everyone"**. Bật thì OpenAI
     có thể dùng dữ liệu đi qua ứng dụng để huấn luyện; gói Business, Enterprise, Edu mặc định không
     dùng [PL:175].
   - **Claude** (Free, Pro, Max, kể cả Claude Code trên các tài khoản đó): tắt tuỳ chọn cho phép dùng
     hội thoại để huấn luyện. Bật thì dữ liệu lưu tới 5 năm, tắt thì 30 ngày [PL:191]. Tên đúng của
     mục cài đặt trên giao diện Claude **chưa kiểm được** ở ngày viết; ghi bổ sung kèm ảnh chụp ở
     lượt nghiệm thu thật.
   - **Kiểm lại sau mỗi lần ứng dụng AI cập nhật**: một bản cập nhật có thể đặt lại cài đặt [DC:160].
2. **Các chức năng ghi phải hỏi trước mỗi lần chạy.**
   - **Claude**: trong Customize → Connectors, đặt bốn chức năng ghi (`draft_progress_update`,
     `draft_request_reply`, `create_deadline`, `log_communication`) ở **"Needs approval"**, không
     "Always allow" [DC:82], [DC:718]. Chưa kiểm được Claude có tự hỏi trước khi chạy một chức năng
     ghi hay không [DC:79], nên đừng trông vào điều đó: tự đặt.
   - **ChatGPT**: không chọn "nhớ lựa chọn" khi ChatGPT hỏi duyệt một chức năng ghi. ChatGPT chỉ nhớ
     trong một cuộc hội thoại, và hỏi lại ở hội thoại mới [DC:695].
3. **Chỉ dùng tài khoản AI của chính mình**, đăng nhập trên máy của mình. Không chia sẻ tài khoản,
   không kết nối trên máy dùng chung.

## 8. Quy tắc khi dùng

- **Không tải tệp hồ sơ trực tiếp vào khung chat.** Không dán số CCCD, ảnh giấy tờ, số tài khoản
  ngân hàng vào khung chat. Kết nối này cố ý không đưa những thứ đó ra; dán tay là tự đưa chúng đi.
- **Luật sư luôn kiểm tra lại kết quả của AI trước khi dùng** [PL:379]. AI có thể tóm tắt sai, bỏ
  sót mốc, hay bịa.
- **Nội dung AI soạn mà gửi tới khách phải qua người**: người đọc, sửa, và tự bấm gửi trên web. Đây
  là nguyên tắc con người giám sát của Luật AI [PL:308]. Luật AI cũng đòi minh bạch khi đưa nội dung
  AI tạo ra cho khách [PL:308]; cách báo cho khách do chủ văn phòng quyết.
- Nội dung khách viết có thể chứa câu cố tình điều khiển AI ("bỏ qua chỉ dẫn trước…"). Nếu AI đề
  nghị làm điều không ai yêu cầu, dừng lại và báo quản trị viên.

## 9. Việc của chủ văn phòng trước khi bật kết nối AI trên dữ liệu thật

Danh sách này chỉ liệt kê, không quyết thay. Chưa xong thì giữ công tắc chung tắt.

1. **Hồ sơ đánh giá tác động chuyển dữ liệu cá nhân ra nước ngoài, và hồ sơ đánh giá tác động xử lý
   dữ liệu (DPIA).** Dữ liệu kết nối AI trả cho Claude hay ChatGPT là chuyển dữ liệu cá nhân xuyên
   biên giới theo Điều 20 Luật 91 [PL:279], [PL:370], và không có trường hợp miễn trừ phù hợp
   [PL:282-283]. Hồ sơ nộp Cục A05 (Bộ Công an) trong 60 ngày kể từ lần chuyển đầu tiên, và cập nhật
   6 tháng một lần [PL:324], [PL:326]. Chủ văn phòng gọi hồ sơ này là "Mẫu 10"; tra cứu **chưa xác
   nhận** Mẫu số 09 hay Mẫu số 10 của Nghị định 356 là mẫu nào, nộp qua kênh nào, và có phải chờ A05
   trả lời trước khi chuyển không [PL:326], [PL:386]. Khi đã nộp, quản trị viên ghi ngày nộp trên
   trang "Kết nối AI"; tới lúc đó trang hiện một dải cảnh báo.
2. **Đồng ý bằng văn bản của khách cho từng vụ được bật cờ AI**: một điều khoản riêng trong hợp đồng
   dịch vụ pháp lý (khách hiện hữu: phụ lục), nêu mục đích, loại dữ liệu, bên nhận (OpenAI,
   Anthropic), quốc gia (Mỹ), thời hạn lưu của nhà cung cấp, quyền rút lại; không đánh dấu sẵn; lưu
   phiên bản điều khoản, thời điểm, người ghi nhận [PL:372].
3. **Người hoặc bộ phận bảo vệ dữ liệu cá nhân** đạt yêu cầu của Nghị định 356, hoặc thuê dịch vụ
   ngoài [PL:337].
4. **Quy trình báo sự cố trong 72 giờ** cho Bộ Công an (mục 11) [PL:338].
5. **Cân nhắc gói doanh nghiệp.** Tra cứu khuyến nghị chỉ dùng ChatGPT Business/Enterprise hoặc Claude
   Team/Enterprise, có thoả thuận xử lý dữ liệu và cam kết không huấn luyện, và cấm tài khoản cá nhân
   [PL:316], [PL:377]. Văn phòng đã chọn tài khoản cá nhân; đây là câu hỏi còn mở của chủ văn phòng.

## 10. Kiểm tra định kỳ

Mỗi quý (và sau mỗi lần ứng dụng AI đổi giao diện cài đặt), quản trị viên cùng từng nhân sự đang có
kết nối kiểm lại mục 7: huấn luyện đã tắt, chức năng ghi vẫn ở chế độ phải duyệt. Người nào không
còn cần kết nối thì quản trị viên hạ quyền AI về "Tắt".

## 11. Sự cố: báo ngay quản trị viên

Báo **ngay**, không chờ chắc chắn, khi:

- nghi dữ liệu hồ sơ đã lộ ra ngoài (dán nhầm, chia sẻ nhầm cuộc hội thoại…);
- mất hay nghi lộ máy, điện thoại, hoặc tài khoản AI đang có kết nối;
- AI làm điều lạ: gọi chức năng không ai yêu cầu, soạn nháp mang nội dung lạ, nhắc tới dữ liệu đáng lẽ
  không thấy.

Quản trị viên thu hồi ngay kết nối của người đó (từng kết nối, hay tất cả) trên trang "Kết nối AI",
hoặc tắt công tắc chung. Luật 91 buộc văn phòng thông báo vi phạm cho Bộ Công an trong **72 giờ** kể
từ khi phát hiện, nếu vi phạm có thể gây hại cho chủ thể dữ liệu [PL:338] — giờ tính từ lúc phát
hiện, nên báo chậm là mất thời gian của cả văn phòng.
