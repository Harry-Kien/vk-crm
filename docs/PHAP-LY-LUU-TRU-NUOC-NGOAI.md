# Dàn ý hồ sơ đánh giá tác động chuyển dữ liệu cá nhân ra nước ngoài

Đi cùng milestone M14 (Google Drive làm kho tài liệu phía sau CRM; kế hoạch
`docs/superpowers/plans/2026-10-04-m14-google-drive-storage.md`, phán quyết R13, Phụ lục B).

> Dàn ý này là thông tin chuẩn bị cho luật sư của văn phòng, **không phải tư vấn pháp lý**. Mẫu biểu chính thức
> (Nghị định 356/2025, Mẫu số 09/10; mẫu nào cho hồ sơ nào còn phải kiểm, `docs/research/2026-09-24-mcp-phap-ly-goi.md:386`)
> và nội dung cuối cùng do luật sư quyết.

**Vì sao cần.** Lưu tệp hồ sơ trên Google là "sử dụng nền tảng ở ngoài lãnh thổ để xử lý dữ liệu cá nhân thu thập
tại Việt Nam", tức chuyển dữ liệu cá nhân xuyên biên giới theo khoản 1 Điều 20 Luật 91/2025/QH15
(`docs/research/2026-09-24-mcp-phap-ly-goi.md:279`, `:323`). Tài liệu nghiên cứu đó khuyến nghị giữ dữ liệu gốc trên
máy chủ tại Việt Nam (`:378`); M14 đi ngược khuyến nghị này theo quyết định của chủ văn phòng, và đưa bản thứ hai ở
máy chủ văn phòng (có mã hoá) vào câu hỏi 3c cho luật sư.

**Trong CRM.** Trên production, kho Google Drive **không bật được** (`vkcrm:storage:enable` từ chối, dòng kiểm
`data_transfer_dossier` ĐỎ) cho tới khi trang **"Kho tài liệu"** (/admin, chỉ quản trị viên) có **ngày hồ sơ**
hoặc **ý kiến bằng văn bản của luật sư** cho phép chuyển trước khi nộp hồ sơ (ngày + căn cứ). Lần chuyển dữ liệu
đầu tiên được hệ thống tự ghi; từ ngày thứ 45 mà chưa có ngày hồ sơ, người vận hành nhận thư nhắc mỗi ngày; quá
ngày 60 thì `data_transfer_dossier` ĐỎ. Ngày chấp nhận DPA và số/mã hồ sơ cũng ghi trên trang đó.

## Dàn ý

1. **Bên chuyển:** tên pháp lý, mã số thuế, địa chỉ (lấy từ trang "Thông tin văn phòng"), người đại diện, người
   hoặc bộ phận phụ trách bảo vệ dữ liệu cá nhân.
2. **Bên nhận / bên xử lý:**
   - thực thể Google ký hợp đồng Workspace với văn phòng (ghi đúng tên trên hoá đơn), vai trò bên xử lý;
   - nơi lưu trữ: các trung tâm dữ liệu của Google ở nước ngoài; Workspace không có vùng dữ liệu Việt Nam.
3. **Mục đích:** lưu trữ tệp hồ sơ vụ việc của khách hàng phục vụ dịch vụ pháp lý; truy cập chỉ qua hệ thống CRM
   của văn phòng.
4. **Loại dữ liệu:**
   - dữ liệu **cơ bản**: họ tên, ngày sinh, số giấy tờ tuỳ thân dạng chữ, địa chỉ, số điện thoại
     (`mcp-phap-ly-goi.md:328`);
   - dữ liệu **nhạy cảm**:
     - **ảnh CCCD/CMND**: Nghị định 356 đưa vào nhóm nhạy cảm (`:327`, `:328`), và đây là loại tệp khách nộp nhiều
       nhất;
     - đời sống riêng tư, sức khoẻ, tài chính, thông tin liên quan tội phạm do văn phòng thu thập (`:396`);
     - hồ sơ vụ việc nói chung nên coi là nhạy cảm;
   - dữ liệu của **bên thứ ba** (bên đối lập, người liên quan) không thể lấy đồng ý.
5. **Chủ thể:** khách hàng, các bên trong vụ việc, nhân sự văn phòng.
6. **Căn cứ xử lý và chuyển:** hợp đồng dịch vụ pháp lý; đồng ý của khách (nếu luật sư kết luận cần, câu hỏi 3b);
   nghĩa vụ giữ bí mật theo Điều 25 Luật Luật sư.
7. **Biện pháp bảo vệ** (mô tả đúng hệ thống đã dựng, kể cả chỗ yếu):
   - chỉ một tài khoản dịch vụ truy cập, vai Người quản lý nội dung (không xoá vĩnh viễn, không chia sẻ); Shared
     Drive "chỉ thành viên"; kiểm thành viên và vai mỗi giờ;
   - tên tệp trên Drive không chứa thông tin cá nhân (khoá mờ: mã media và mã ngẫu nhiên);
   - mã hoá khi truyền (TLS);
   - mã hoá khi lưu trên Google là **mã hoá mặc định của Google, khoá do Google giữ**: về kỹ thuật Google đọc được
     tệp. Văn phòng **không** mã hoá tệp trước khi gửi (chủ văn phòng ký nhận ở câu hỏi 8 của kế hoạch M14);
   - mọi lượt tải đi qua CRM: kiểm quyền theo vụ, ghi nhật ký người tải, IP, thời điểm;
   - 2FA bắt buộc cho nhân sự;
   - bản thứ hai tại máy chủ văn phòng ở Việt Nam, **mã hoá** bằng `rclone crypt`, khoá do văn phòng giữ ngoài máy
     chủ web;
   - sao lưu CSDL mã hoá AES-256;
   - huỷ tệp khi hết hạn lưu hay theo yêu cầu của chủ thể theo sổ tay "Huỷ tệp của hồ sơ đã quá hạn lưu"
     (`docs/KHO-TAI-LIEU-GOOGLE-DRIVE.md`);
   - quy trình sự cố: thông báo Bộ Công an trong 72 giờ (`mcp-phap-ly-goi.md:338`).
8. **Đánh giá rủi ro và tác động**, với biện pháp giảm thiểu tương ứng (xoay khoá, bản trong nước có mã hoá, DPA):
   - truy cập trái phép do lộ khoá;
   - quản trị viên Workspace hay Google đọc tệp;
   - yêu cầu của cơ quan nước ngoài đối với nhà cung cấp;
   - nhà cung cấp ngừng dịch vụ.
9. **Hợp đồng:** Cloud Data Processing Addendum (ngày chấp nhận, bản PDF). DPA đáp ứng yêu cầu có thoả thuận với
   bên nhận, **không thay** hồ sơ (`mcp-phap-ly-goi.md:398`). Đường chấp nhận: Admin console → Tài khoản → Cài đặt
   tài khoản → Pháp lý và tuân thủ → "Security and Privacy Additional Terms" → Review and Accept (cần tài khoản siêu
   quản trị); nếu hợp đồng Workspace đã gộp sẵn CDPA thì chấp nhận lại cũng không đổi gì.
10. **Thủ tục:**
    - gửi bản chính cho Cục A05 (Bộ Công an) trong 60 ngày kể từ lần chuyển đầu tiên (`:324`). Ngày đó do hệ thống
      tự ghi và hiện trên trang "Kho tài liệu";
    - Nghị định 356 áp dụng **tiền kiểm**: A05 xem xét 15 ngày xem hồ sơ đạt hay không đạt, bổ sung trong 30 ngày
      (`:326`). Có phải chờ kết quả trước khi chuyển hay không là câu hỏi 3a; trong app, mặc định là chặn;
    - cập nhật 6 tháng một lần hoặc khi đổi bên xử lý;
    - kèm **hồ sơ đánh giá tác động xử lý dữ liệu cá nhân** (DPIA), lập riêng.
11. **Lưu trữ trong nước:** kết luận của luật sư về Nghị định 333/2026 Điều 19 (câu hỏi 3c). Nếu văn phòng thuộc
    diện đó, bản ở máy chủ văn phòng thành **bắt buộc trước khi bật** kho.

## Câu hỏi cho luật sư (trích kế hoạch M14, câu hỏi 3 và 8)

- (a) Có được bắt đầu lưu trên Google **trước** khi nộp hồ sơ, hay phải nộp trước? Có phải chờ A05 trả lời "đạt"
  không? **Mặc định trong app: chặn trên production** cho tới khi có ngày hồ sơ, hoặc ý kiến bằng văn bản của luật
  sư cho phép chuyển trước.
- (b) Lưu trên đám mây của bên xử lý có DPA có bị coi là "tiết lộ" theo Điều 25 Luật Luật sư không, và có cần thêm
  điều khoản đồng ý vào hợp đồng dịch vụ pháp lý không?
- (c) Văn phòng có thuộc diện lưu trữ trong nước của Nghị định 333/2026 Điều 19 không?
- (d) Ảnh CCCD/CMND là dữ liệu nhạy cảm theo Nghị định 356. Có cần biện pháp riêng cho loại tệp này không, ví dụ
  không đẩy lên kho mà giữ ở máy chủ?
- Câu hỏi 8 (chủ văn phòng ký): chấp nhận rằng tệp trên Kho ở dạng Google đọc được (mã hoá khi lưu bằng khoá của
  Google), trong khi trước M14 mọi bản ngoài máy chủ đều là archive AES-256 của văn phòng.
