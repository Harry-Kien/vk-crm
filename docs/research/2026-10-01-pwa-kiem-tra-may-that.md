# Danh sách kiểm tra trên điện thoại thật — app Luật Vũ Khang (M12)

Danh sách này dành cho chủ văn phòng (hoặc người được giao), chạy trên **một iPhone** và **một điện
thoại Android** thật. Máy tính không thay được bước này: cách iPhone và Android cài app web, giữ
đăng nhập và hiện thông báo chỉ đo được trên chính điện thoại.

Kết quả ghi vào bảng ở cuối tài liệu rồi gửi lại cho kỹ thuật, kèm ảnh chụp màn hình.
Bối cảnh kỹ thuật và lý do của từng câu: `docs/research/2026-10-01-pwa-khao-sat.md`.

- Mục **A, B, C** ứng với ba câu hỏi của Task 1. Chạy được ngay khi bản có Task 2 và 3 (biểu tượng,
  manifest, service worker, liên kết tải trong phạm vi app) đã lên máy chủ HTTPS. Mục A kiểm việc
  **tải tài liệu ngay trong app đã cài** trên iPhone, cho cả app khách lẫn app nội bộ.
- Mục **D, E, F, G, H** là phần máy thật của nghiệm thu Task 10. Chạy khi bản đầy đủ của M12 đã lên
  máy chủ. Riêng **E** (mất mạng) và **F** (không để lại dữ liệu) đã chạy được ngay khi bản có Task 3
  lên máy chủ — làm cùng lúc với A–C nếu tiện. **G** thử đủ tám loại thông báo; **H** thử các màn
  hình chính của app nội bộ trên bề ngang của iPhone.

Mỗi bước có ba phần: **Làm**, **ĐẠT khi**, **Chụp**. Bước nào không ĐẠT thì ghi lại đúng những gì
thấy trên màn hình (chữ báo lỗi, trang trắng, trang "404") và chụp lại. Không cần sửa gì.

---

## 0. Chuẩn bị

1. **Một địa chỉ HTTPS mà điện thoại vào được**, ví dụ `https://khachhang.luatvukhang.com` (máy chủ
   chính thức hoặc máy chủ thử của văn phòng), đã cài bản có M12.
   - Địa chỉ `http://…` thường **không** dùng được: điện thoại chỉ cho cài app và nhận thông báo qua
     HTTPS.
   - Không dùng đường hầm công khai tới máy tính của kỹ thuật (đưa dữ liệu thử ra Internet). Nếu
     chưa có máy chủ nào, báo kỹ thuật để quyết định cách khác.
2. **Một tài khoản khách hàng để thử**, đã kích hoạt, có ít nhất **một hồ sơ** và **một tài liệu đã
   công bố** trong hồ sơ đó. Email của tài khoản này phải **mở được trên chính chiếc điện thoại đang
   thử** (ứng dụng Mail trên iPhone, Gmail trên Android), vì mã đăng nhập đi qua email.
3. **Một tài khoản nhân sự** (có ứng dụng xác thực 2 bước như khi đăng nhập trên máy tính), là
   **luật sư phụ trách hoặc người trong đội ngũ của chính hồ sơ ở mục 2** — người ngoài đội ngũ không
   thấy hồ sơ đó (A7–A9). Hồ sơ đó cần có ít nhất một tài liệu có tệp ở tab **Tài liệu**, và một đầu
   mục ở trạng thái **Chờ kiểm tra** (khách đã nộp tệp) ở tab **Danh mục hồ sơ**.
4. Ghi phiên bản máy:
   - iPhone: **Cài đặt → Cài đặt chung → Giới thiệu → Phiên bản iOS**. Cần **16.4 trở lên**.
   - Android: mở **Chrome → ⋮ (ba chấm) → Cài đặt → Giới thiệu về Chrome**. Ghi cả phiên bản Android
     (**Cài đặt → Giới thiệu điện thoại**).
5. Nếu trước đây đã thử app này trên máy: **xoá biểu tượng cũ** khỏi màn hình chính trước khi bắt đầu.

---

## A. iPhone — tải tài liệu ngay trong app đã cài: app khách (A1–A6), app nội bộ (A7–A9), liên kết hết hạn (A10)

| # | Làm | ĐẠT khi | Chụp |
|---|---|---|---|
| A1 | Mở **Safari** (không dùng Chrome hay Zalo), vào `https://<địa chỉ>/portal`. | Thấy trang đăng nhập "Luật Vũ Khang". | — |
| A2 | Chạm nút **Chia sẻ** (ô vuông có mũi tên lên) → kéo xuống → **Thêm vào Màn hình chính**. Trên iOS 26: để **bật** công tắc "Mở như ứng dụng web". Chạm **Thêm**. | Tên đề xuất là "Luật Vũ Khang"; biểu tượng là con dấu trên nền đặc **xanh đậm (navy)** (không có ô đen). | Hộp thoại "Thêm vào Màn hình chính" trước khi chạm Thêm. |
| A3 | Về màn hình chính, chạm biểu tượng **Luật Vũ Khang** vừa thêm. | App mở **không có thanh địa chỉ** của Safari (toàn màn hình như một app). | Màn hình app vừa mở. |
| A4 | Đăng nhập trong app: email + mật khẩu của tài khoản khách thử, rồi mã 6 số trong email (xem mục B — có thể ghi kết quả B cùng lúc). | Vào được danh sách hồ sơ. Lưu ý: dù đã đăng nhập trong Safari trước đó, app **vẫn bắt đăng nhập lại** — đó là bình thường trên iPhone. | — |
| A5 | Mở hồ sơ có tài liệu đã công bố, chạm vào **tên một tài liệu** để tải. | Tệp mở ra (xem trước PDF/ảnh) hoặc hỏi nơi lưu. **Không** hiện trang "404" hay "Không tìm thấy". | Màn hình ngay sau khi chạm (tệp đã mở, hoặc trang lỗi nếu có). |
| A6 | Nếu tệp mở trong một khung có nút **Xong** ở góc: chạm **Xong**. | Quay lại đúng trang hồ sơ trong app, **vẫn đăng nhập** (không bị đưa về trang đăng nhập). | Màn hình sau khi chạm Xong. |
| A7 | Trong **Safari**, vào `https://<địa chỉ>/admin`, rồi **Chia sẻ → Thêm vào Màn hình chính** như A2 (iOS 26: bật "Mở như ứng dụng web"). Mở app nội bộ vừa thêm từ màn hình chính, đăng nhập nhân sự (mật khẩu + mã ứng dụng xác thực). | App mở **không có thanh địa chỉ**; vào được trang tổng quan nội bộ. Trên màn hình chính có biểu tượng nội bộ **tách riêng** biểu tượng app khách của A2: tên "VK Nội bộ", con dấu trên nền **sáng** (app khách nền navy). | Màn hình chính có cả hai biểu tượng. |
| A8 | Trong app nội bộ: mở hồ sơ của khách thử → tab **Tài liệu** → ở một tài liệu có tệp, chạm **Tải tệp**. | Tệp mở ra (xem trước) hoặc hỏi nơi lưu **ngay trong cửa sổ app**; chạm **Xong** (nếu có) thì về đúng tab Tài liệu, vẫn đăng nhập. **KHÔNG ĐẠT** nếu Safari bật lên, hoặc hiện một khung có **dòng địa chỉ** ở trên — kể cả khi tệp vẫn tải được: khi đó liên kết đã rời cửa sổ app. Ghi lại địa chỉ trong khung và tệp có tải được không. | Màn hình ngay sau khi chạm Tải tệp. |
| A9 | Trong app nội bộ, cùng hồ sơ: tab **Danh mục hồ sơ** → ở đầu mục **Chờ kiểm tra**, chạm **Đã nhận** để mở hộp duyệt (chưa xác nhận gì) → trong phần **Tệp khách đã gửi**, chạm tên một tệp. Xong thì chạm **Huỷ thao tác** để đóng hộp, không duyệt. | Như A8: tệp mở **ngay trong cửa sổ app**, không có Safari, không có khung có dòng địa chỉ; quay lại vẫn thấy hộp duyệt hoặc trang hồ sơ, vẫn đăng nhập. | Màn hình ngay sau khi chạm tên tệp. |
| A10 | Làm ở **cả hai app** (app khách: hồ sơ của A5; app nội bộ: tab **Tài liệu** của A8). Mở hồ sơ rồi **để yên hơn 5 phút** (xem đồng hồ, không chạm gì), sau đó chạm vào một tệp để tải. Trên trang **Liên kết tải tệp đã hết hạn**, chạm nút **Về trang chính**. | Trang **Liên kết tải tệp đã hết hạn** hiện **ngay trong cửa sổ app**. Chạm **Về trang chính** thì về trang đầu của **chính app đó** (app khách: danh sách hồ sơ; app nội bộ: trang tổng quan nội bộ), **vẫn trong cửa sổ app**, vẫn đăng nhập; mở lại hồ sơ, chạm tệp thì tải được. **KHÔNG ĐẠT** nếu nút đó mở trang đăng nhập của app kia, bật Safari, hoặc hiện một khung có **dòng địa chỉ**. | Trang hết hạn, và màn hình ngay sau khi chạm Về trang chính — mỗi app một cặp. |

Ghi chú cho kỹ thuật: từ Task 3, liên kết tải trong cả hai app trỏ tới đường dẫn **trong** phạm vi
app (`/portal/documents/…/download`, `/admin/documents/…/download`) và mở trong **cùng cửa sổ**. Mục
A vì vậy **không đo câu hỏi cookie** gốc của Task 1 (trình duyệt trong app có mang cookie ra ngoài
phạm vi app không): câu đó đã được thay bằng phán quyết tạm (khảo sát, mục 3 dòng 1). A5–A9 ĐẠT
nghĩa là lượt tải trong phạm vi app chạy trên iPhone thật. Một trang lỗi ở A5, A8 hoặc A9 đọc như sau
— chụp lại cả mã lỗi lẫn giờ chạm:

- **403**: liên kết tải đã hết hạn. Liên kết chỉ sống 5 phút kể từ lúc trang vẽ ra nó (mở hoặc làm
  mới trang). Chạm nút **Về trang chính** trên trang lỗi (về đầu của chính app — xem A10), vào lại
  hồ sơ rồi chạm lại ngay; vẫn 403 mới là lỗi.
- **429**: quá 60 lượt tải trong một phút của cùng tài khoản. Chờ một phút rồi thử lại.
- **404**: máy chủ không thấy phiên đăng nhập đi kèm lượt tải, hoặc tài khoản đó không được tải tài
  liệu này (không thuộc đội ngũ, tài liệu chưa công bố cho khách, tệp không còn). Nếu 404 hiện
  **trong cửa sổ app** (không có dòng địa chỉ), đó **không** phải chuyện cookie của iPhone: kiểm lại
  điều kiện ở mục 0 (tài khoản thuộc đội ngũ hồ sơ, tài liệu đã công bố), rồi báo kỹ thuật kèm ảnh.
  Nếu 404 hiện trong một khung **có dòng địa chỉ** hoặc trong Safari, liên kết đã rời cửa sổ app
  (Task 3 chưa đúng) — ghi lại địa chỉ đó.

---

## B. iPhone và Android — đăng nhập có mã một lần trong app đã cài (câu hỏi 2)

Làm trên **cả hai máy**, trong **app đã cài** (iPhone: app ở mục A; Android: app ở mục C1–C3).
Trước khi làm, đăng xuất khỏi app (ảnh đại diện góc phải → Đăng xuất).

| # | Làm | ĐẠT khi | Chụp |
|---|---|---|---|
| B1 | Trong app, nhập email + mật khẩu, chạm **Đăng nhập**. | Hiện ô nhập **mã 6 số**. | Màn hình ô mã. |
| B2 | Chạm vào ô mã **trước khi** rời app. | **iPhone:** phía trên bàn phím có gợi ý **"Từ Mail"** kèm mã (có thể mất vài giây sau khi thư tới). **Android:** có thể có gợi ý mã trên bàn phím (Gboard) — có hay không đều ghi lại. | Bàn phím có (hoặc không có) gợi ý. |
| B3 | Nếu không có gợi ý: **rời app** (vuốt lên về màn hình chính), mở **Mail/Gmail**, đọc mã, rồi **quay lại app** bằng biểu tượng trên màn hình chính (hoặc vuốt chuyển app). | Quay lại vẫn thấy **ô nhập mã** như lúc đi. **KHÔNG ĐẠT** nếu app hiện lại trang nhập email và mật khẩu. | Màn hình ngay khi quay lại app. |
| B4 | Nhập mã, chạm **Xác nhận**. | Vào được danh sách hồ sơ. | — |
| B5 | Chỉ làm nếu B3 KHÔNG ĐẠT: nhập lại mật khẩu ngay lập tức, rồi chờ thư mã mới. | Ghi lại: thư mã mới có tới không, sau bao lâu; app có báo "gửi lại quá nhiều lần" không. | Thông báo hiện trên màn hình (nếu có). |

Ghi chú cho kỹ thuật: khi thử trên máy tính, nạp lại trang giữa bước mã thì mất bước mã, và hệ thống
chỉ gửi tối đa 2 mã mỗi phút cho một tài khoản (khảo sát mục 2.6). B3/B5 cho biết điện thoại thật có
gặp điều đó không.

---

## C. Android (Chrome) — cài cả app nội bộ lẫn app khách trên cùng một máy (câu hỏi 3)

| # | Làm | ĐẠT khi | Chụp |
|---|---|---|---|
| C1 | Mở **Chrome**, vào `https://<địa chỉ>/portal`. Chạm vài chỗ trên trang và để trang mở **ít nhất 30 giây**. | — | — |
| C2 | Chạm **⋮** → **Cài đặt ứng dụng** (hoặc **Thêm vào màn hình chính** → **Cài đặt**). | Tên là "Luật Vũ Khang — Khách hàng" (tên đầy đủ; dưới biểu tượng ở C4 sẽ là tên ngắn "Luật Vũ Khang"); biểu tượng con dấu trên nền **xanh đậm (navy)**. | Hộp thoại cài đặt. |
| C3 | Vào `https://<địa chỉ>/admin` trong Chrome, đăng nhập nhân sự (mật khẩu + mã ứng dụng xác thực), để trang mở ít nhất 30 giây, rồi **⋮ → Cài đặt ứng dụng** như C2. | Tên là "Luật Vũ Khang — Nội bộ" (hoặc "VK Nội bộ"); biểu tượng con dấu trên nền **sáng**. Chrome **không** báo "đã cài" hay thay app khách. | Hộp thoại cài đặt. |
| C4 | Về màn hình chính. | Có **hai biểu tượng riêng**: "Luật Vũ Khang" (nền navy) và "VK Nội bộ" (nền sáng). | Màn hình chính có cả hai biểu tượng. |
| C5 | Mở app khách, rồi mở app nội bộ, rồi bấm nút **đa nhiệm** (hình vuông, hoặc vuốt từ dưới lên và giữ). | Thấy **hai cửa sổ riêng**, mỗi cửa sổ đúng app của nó (một trang khách hàng, một trang nội bộ). | Màn hình đa nhiệm. |
| C6 | Trong app khách, nếu có liên kết dẫn sang trang nội bộ (hoặc ngược lại) thì chạm thử. | Trang của panel kia **không** mở bên trong app này (mở trong Chrome hoặc trong app kia). Không có liên kết nào thì ghi "không có". | — |

---

## D. Thông báo đẩy (nghiệm thu Task 10 — chạy khi bản đầy đủ M12 đã lên máy chủ)

Làm trên **cả hai máy**, trong app đã cài. iPhone chỉ nhận thông báo trong **app đã cài từ màn hình
chính** (iOS 16.4+); mở bằng Safari thường thì không có nút bật.

| # | Làm | ĐẠT khi | Chụp |
|---|---|---|---|
| D1 | Trong app khách: ảnh đại diện (góc phải) → **Thông báo trên điện thoại** → **Bật trên máy này**. | Điện thoại hỏi "cho phép thông báo"; chọn **Cho phép**. Máy này hiện trong danh sách thiết bị (ví dụ "iPhone · Safari"). Không có lời hỏi quyền nào hiện ra **trước** khi chạm nút. Trước và sau khi bật, chạm qua lại vài trang (danh sách hồ sơ, một hồ sơ, rồi về): app **không đứng hình** — một trang không phản hồi chạm nào ngay sau khi đăng nhập là KHÔNG ĐẠT (ghi phiên bản iOS/Android). | Hộp hỏi quyền; danh sách thiết bị sau khi bật. |
| D2 | Trên iPhone, mở cùng trang đó trong **Safari** (không phải app). | Thay cho nút Bật là câu hướng dẫn "Chạm nút Chia sẻ → Thêm vào Màn hình chính, rồi mở Luật Vũ Khang từ màn hình chính để bật thông báo". | Màn hình hướng dẫn. |
| D3 | Chạm **Gửi thông báo thử**. Khoá màn hình ngay. | Trong vòng 2 phút có thông báo trên **màn hình khoá**. Nội dung chỉ là tên văn phòng và một câu chung (không có tên khách, mã hồ sơ, tên vụ việc, tên tài liệu). | **Màn hình khoá** có thông báo. |
| D4 | Nhờ nhân sự làm một việc gửi thư cho khách thử (ví dụ công bố một tài liệu). Khoá màn hình, chờ. | Có thông báo; nội dung **không** chứa bất cứ thông tin nào của hồ sơ. | Màn hình khoá có thông báo. |
| D5 | Chạm vào thông báo ở D4. | Mở **đúng app khách** (không phải Chrome/Safari, không phải app nội bộ), tới đúng trang hồ sơ. Nếu đã hết phiên: tới trang đăng nhập, đăng nhập xong thì **về đúng trang hồ sơ đó**. | Trang mở ra sau khi chạm. |
| D6 | Android (máy có cả hai app): bật thông báo trong **app nội bộ** (như D1). Tạo một việc khiến nhân sự nhận thông báo (ví dụ khách thử nộp một giấy tờ). Chạm thông báo. | Mở **app nội bộ**, tới đúng trang vụ việc. | Trang mở ra. |
| D7 | Trong app khách: **Đăng xuất**. Nhờ nhân sự công bố thêm một tài liệu. | **Không** có thông báo nào tới máy này nữa (thư email vẫn tới bình thường). | — |
| D8 | Đăng nhập app khách **bằng một tài khoản khách khác** trên cùng máy (nếu có). Không chạm "Bật". | Trang hiện dải mời "Bật thông báo trên máy này"; máy **không** tự nhận thông báo của tài khoản trước, cũng chưa nhận của tài khoản mới cho tới khi chạm Bật. | Dải mời. |
| D9 | Android, **app nội bộ** đã bật thông báo (D6), đăng nhập bằng **luật sư phụ trách** của một vụ thử. Tắt chế độ im lặng / không làm phiền. Trên máy tính, tab **Mốc thời hạn** của vụ đó → **Thêm mốc thời hạn**, **Ngày đến hạn** là 3 ngày nữa. Chờ lượt kiểm tra mốc hạn kế tiếp (30 phút một lần, 07:00–19:30): điện thoại nhận "Có mốc thời hạn sắp đến cần chuẩn bị. Chạm để xem." **Để nguyên thông báo đó trong khay** (không chạm, không vuốt bỏ), khoá màn hình. Trên máy tính, **Sửa** mốc đó: **Ngày đến hạn** là ngày mai. Chờ lượt kiểm tra kế tiếp. Nếu iPhone có app nội bộ (A7), làm lại trên iPhone. | Thông báo "Có mốc thời hạn đến hạn hôm nay hoặc ngày mai. Chạm để xem." tới thì điện thoại **rung hoặc đổ chuông** như một thông báo mới, và trong khay chỉ còn **một** thông báo của mốc đó (tin mới thay tin cũ). Tin mới tới **im lặng** (không rung, không chuông) là KHÔNG ĐẠT. | Màn hình khoá có thông báo mới. |
| D10 | Máy có app khách đã bật thông báo (D1). Trên máy tính, quản trị viên mở **Tài khoản portal** → tài khoản khách thử → tắt **Đang hoạt động** → **Lưu thay đổi**. Nhờ nhân sự công bố một tài liệu cho khách thử (như G2), chờ 5 phút. Xong thì bật lại **Đang hoạt động** → **Lưu thay đổi**. | **Không** có thông báo nào tới máy của khách thử (thư email cũng không đi: tài khoản đang bị khoá). Chạm vào app khách lúc đó thì về trang đăng nhập. Sau khi bật lại: đăng nhập lại; nếu trang hiện dải mời "Bật thông báo trên máy này" thì chạm **Bật** — máy đã bị gỡ khi phiên bị cắt, đúng như thiết kế. | — |

---

## E. Mất mạng (nghiệm thu Task 10)

| # | Làm | ĐẠT khi | Chụp |
|---|---|---|---|
| E1 | Mở app khách một lần khi có mạng, rồi **đóng hẳn app**: mở màn hình đa nhiệm (iPhone: vuốt từ đáy lên và giữ; Android: nút hình vuông) rồi **vuốt app lên để bỏ** — chỉ về màn hình chính thì điện thoại còn giữ trang cũ trong bộ nhớ, không đo được gì. Bật **Chế độ máy bay**. Mở lại app. | Thấy trang tiếng Việt **"Chưa có kết nối mạng"**, có số hotline chạm để gọi, và nút "Thử lại". **Không** thấy nội dung hồ sơ cũ nào. | Trang ngoại tuyến. |
| E2 | Chạm số hotline. | Điện thoại mở màn hình gọi với đúng số của văn phòng. | — |
| E3 | Tắt chế độ máy bay, chạm **Thử lại**. | App tải lại bình thường (có thể phải đăng nhập lại). | — |
| E4 | **Chỉ làm nếu văn phòng đã bật giới hạn địa chỉ cho trang nội bộ** (`ADMIN_IP_ALLOWLIST`; hỏi kỹ thuật). Trên máy đã cài app nội bộ (A7 hoặc C3) **khi ở văn phòng**: tắt Wi-Fi văn phòng, dùng 4G, mở app nội bộ. | Trang báo **không tìm thấy (404)** hoặc trang lỗi — đúng như thiết kế, trang nội bộ chỉ mở trong văn phòng. **Không** thấy nội dung hồ sơ nào. | Màn hình. |
| E5 | Tiếp E4: vuốt bỏ app (như E1), bật lại Wi-Fi văn phòng, mở lại app nội bộ từ màn hình chính. | App mở bình thường, **không phải cài lại**, biểu tượng vẫn còn; nếu đã bật thông báo (mục D) thì máy vẫn có trong danh sách thiết bị. | Màn hình app sau khi mở lại. |

---

## F. Không để lại dữ liệu hồ sơ sau khi đăng xuất (nghiệm thu Task 10)

| # | Làm | ĐẠT khi | Chụp |
|---|---|---|---|
| F1 | Trong app khách đã đăng nhập: mở một hồ sơ, tải một tài liệu, nộp thử một giấy tờ (chụp ảnh bằng điện thoại). | Cả ba việc chạy bình thường, nút bấm phản hồi. | — |
| F2 | Đăng xuất. Đóng hẳn app (vuốt bỏ khỏi màn hình đa nhiệm, như E1). Bật chế độ máy bay. Mở lại app. | Chỉ thấy trang "Chưa có kết nối mạng" (như E1), không thấy hồ sơ vừa xem. | Màn hình. |

---

## G. Đủ tám loại thông báo (nghiệm thu Task 10)

Mỗi dòng là MỘT loại thông báo mà hệ thống gửi. Một người làm trên máy tính tạo ra sự kiện; điện
thoại thử đã bật thông báo trong đúng app (app khách: D1; app nội bộ: D6) và đã **khoá màn hình**.
Tài khoản: app khách dùng tài khoản khách thử (mục 0, ý 2); app nội bộ dùng **luật sư phụ trách** vụ
của khách thử (mục 0, ý 3) — người ngoài đội ngũ của vụ không nhận thông báo của vụ đó.

Với MỌI dòng, ĐẠT gồm cả ba điều:

1. Màn hình khoá chỉ có tiêu đề **Luật Vũ Khang** và đúng câu ghi ở dòng đó — không có tên khách, mã
   hồ sơ, tên vụ việc, tên tài liệu, tên giấy tờ, lý do từ chối hay nội dung câu hỏi.
2. Chạm vào thông báo thì mở đúng app (không mở Chrome/Safari, không mở app kia) và đúng chỗ ghi ở
   dòng đó.
3. Làm lại **một** dòng bất kỳ khi đã **hết phiên** (để app yên hơn 2 tiếng, không mở): chạm thông
   báo → trang đăng nhập (app khách: mật khẩu rồi mã 6 số trong email; app nội bộ: mật khẩu rồi mã của
   ứng dụng xác thực) → đăng nhập xong về **đúng trang đó**. Với app khách, trang mở ở đầu trang chứ
   không cuộn sẵn tới khối — vẫn ĐẠT.

Thông báo "gói bàn giao hồ sơ đã sẵn sàng" (`staff.handover_ready`) chưa có ở bản này: nó vào cùng
lúc gộp phần bàn giao hồ sơ (M7), và khi đó danh sách thêm dòng G9.

| # | Loại và app | Làm | ĐẠT khi (ngoài ba điều trên) | Chụp |
|---|---|---|---|---|
| G1 | `client.stage_update` — **app khách** | Trên máy tính, trang vụ việc của khách thử → tab **Tiến độ** → **Chuyển giai đoạn** (hoặc **Thêm cập nhật**), điền "Nội dung công bố cho khách", để bật **Công bố cho khách ngay**, chạm **Gửi**. | Màn hình khoá: **Luật Vũ Khang** — "Hồ sơ của anh/chị có cập nhật mới. Chạm để xem." Chạm: trang hồ sơ đó, dòng mới nằm ở khối **Diễn biến vụ việc**. | Màn hình khoá; trang mở ra. |
| G2 | `client.document_published` — **app khách** | Tab **Tài liệu** của vụ đó → ở một tài liệu chưa công bố (hoặc đưa một tài liệu mới vào bằng **Đưa tài liệu vào hồ sơ**) → **Công bố cho khách**. | Màn hình khoá: **Luật Vũ Khang** — "Văn phòng vừa gửi tài liệu mới trong hồ sơ của anh/chị. Chạm để xem." Chạm: trang hồ sơ, khối **Tài liệu**; tên tài liệu chỉ hiện trong app. | Màn hình khoá; trang mở ra. |
| G3 | `client.document_rejected` — **app khách** | Khách thử nộp một giấy tờ trong app (như F1). Trên máy tính, tab **Danh mục hồ sơ** → đầu mục vừa nộp → **Cần nộp lại**, ghi một lý do. | Màn hình khoá: **Luật Vũ Khang** — "Có giấy tờ trong hồ sơ của anh/chị cần nộp lại. Chạm để xem." Chạm: trang hồ sơ, khối **Hồ sơ giấy tờ**; lý do chỉ hiện trong app, không trên màn hình khoá. | Màn hình khoá; trang mở ra. |
| G4 | `client.request_answered` — **app khách** | Trong app khách, trang hồ sơ → **Gửi yêu cầu cho văn phòng**, gửi một câu hỏi. Trên máy tính, tab **Yêu cầu từ khách** của vụ → mở yêu cầu đó → trả lời. | Màn hình khoá: **Luật Vũ Khang** — "Văn phòng đã trả lời câu hỏi của anh/chị. Chạm để xem." Chạm: trang **Gửi yêu cầu và xem trả lời** của hồ sơ đó, có câu trả lời. | Màn hình khoá; trang mở ra. |
| G5 | `staff.deadline_reminder` — **app nội bộ** | Như D9 (một mốc 3 ngày nữa, rồi sửa thành ngày mai), cộng một mốc nữa: tab **Mốc thời hạn** → **Thêm mốc thời hạn**, **Ngày đến hạn** là hôm qua. Chờ lượt kiểm tra mốc hạn kế tiếp (30 phút một lần, 07:00–19:30). | Ba câu theo mức: "Có mốc thời hạn sắp đến cần chuẩn bị. Chạm để xem." · "Có mốc thời hạn đến hạn hôm nay hoặc ngày mai. Chạm để xem." · "Có mốc thời hạn đã quá hạn, cần xử lý ngay. Chạm để xem." Tiêu đề **Luật Vũ Khang**. Chạm: trang vụ việc, tab **Mốc thời hạn**. | Màn hình khoá (câu quá hạn); trang mở ra. |
| G6 | `staff.new_client_request` — **app nội bộ** | Khách thử (trên máy khác, hoặc trên máy tính) gửi một yêu cầu mới bằng **Gửi yêu cầu cho văn phòng**; vài phút sau viết thêm vào chính yêu cầu đó (**Gửi thêm**). | Hai lần, mỗi lần: **Luật Vũ Khang** — "Khách vừa gửi yêu cầu hoặc câu hỏi mới. Chạm để xem."; lần hai cũng rung, và trong khay chỉ còn một thông báo của yêu cầu đó. Chạm: trang vụ việc, tab **Yêu cầu từ khách**. | Màn hình khoá; trang mở ra. |
| G7 | `staff.new_client_document` — **app nội bộ** | Khách thử nộp một giấy tờ (như F1). | **Luật Vũ Khang** — "Khách vừa nộp giấy tờ mới cần xem. Chạm để xem." Chạm: trang vụ việc, tab **Danh mục hồ sơ** (nơi duyệt giấy tờ). | Màn hình khoá; trang mở ra. |
| G8 | `staff.instalment_overdue` — **app nội bộ** | Vụ thử có hợp đồng, với một đợt thu chưa thu đủ mà **ngày đến hạn đã qua**. Chờ lượt nhắc lúc **08:00** sáng. Người nhận: luật sư phụ trách và kế toán (vụ hạn chế: luật sư phụ trách và quản trị viên) — thử trên máy của một trong họ. | **Luật Vũ Khang** — "Có khoản thu đã quá hạn cần theo dõi. Chạm để xem." (không số tiền, không tên khách). Chạm: người mở được trang **Công nợ** (thường là kế toán, quản trị viên) tới trang **Công nợ**; người khác tới tab **Hợp đồng và thanh toán** của vụ. | Màn hình khoá; trang mở ra. |

---

## H. App nội bộ trên màn hình iPhone (nghiệm thu Task 10)

Mở **app nội bộ** đã cài trên iPhone (A7; chưa cài thì Safari trên iPhone), đăng nhập bằng luật sư
phụ trách vụ thử, cầm máy dọc. Màn hình nhỏ có thể xấu — chữ xuống dòng, bảng phải vuốt ngang,
nút xếp chồng: ghi lại ở cột "Ghi chú" nhưng vẫn là ĐẠT. Chỉ ghi KHÔNG ĐẠT khi **không làm được
việc**: một nút không chạm được, một ô bị che không gõ được, không cuộn tới được nút cần bấm. Làm trên
vụ thử: H3 công bố thật một dòng cho khách thử.

| # | Làm | ĐẠT khi | Chụp |
|---|---|---|---|
| H1 | Chạm nút menu (☰) → **Vụ việc**. Vuốt bảng sang ngang nếu cần, chạm vào vụ thử. | Danh sách hiện; mở được trang vụ việc. | Danh sách vụ việc. |
| H2 | Trên trang vụ việc: đọc tab **Tổng quan**, rồi chạm lần lượt từng tab (vuốt dải tab sang ngang nếu cần). | Đọc được thông tin; tab nào cũng chạm được. | Trang vụ việc. |
| H3 | Tab **Tiến độ** → **Chuyển giai đoạn**: chọn giai đoạn mới, điền "Nội dung công bố cho khách", cuộn xuống, chạm **Gửi**. | Form mở; ô nào cũng gõ được (bàn phím không che mất ô đang gõ); cuộn tới và chạm được **Gửi**; thấy báo đã chuyển giai đoạn. | Form; báo xong. |
| H4 | Tab **Mốc thời hạn** → **Thêm mốc thời hạn**: điền tên mốc, ngày đến hạn, lưu. Rồi **Sửa** mốc đó, đổi ngày, lưu. | Thêm và sửa được; bảng mốc hiện đúng mốc. | Bảng mốc thời hạn. |

---

## Bảng kết quả (gửi lại cho kỹ thuật)

Phiên bản iPhone (iOS): ______ Mẫu máy: ______ · Phiên bản Android: ______ Chrome: ______ Mẫu máy: ______
Địa chỉ đã thử: ______ Ngày thử: ______ Người thử: ______

| Bước | iPhone (ĐẠT / KHÔNG / không làm) | Android (ĐẠT / KHÔNG / không làm) | Ghi chú (thấy gì) |
|---|---|---|---|
| A1–A6 | | (không áp dụng) | |
| A7–A9 | | (không áp dụng) | |
| A10 | | (không áp dụng) | |
| B1–B5 | | | |
| C1–C6 | (không áp dụng) | | |
| D1–D10 | | | |
| E1–E3 | | | |
| E4–E5 (chỉ khi bật giới hạn địa chỉ) | | | |
| F1–F2 | | | |
| G1–G8 | | | |
| H1–H4 | | (nếu có thời gian) | |
