# App iPhone nội bộ — việc chủ văn phòng cần làm

Tài liệu này liệt kê những việc **chỉ chủ văn phòng (hoặc người đại diện pháp luật) làm được** phía
Apple, theo đúng thứ tự. Phần mã do đội kỹ thuật làm theo kế hoạch
`docs/superpowers/plans/2026-10-16-m15-ios-app.md`.

> **Trạng thái 2026-10-16:** app iPhone CHƯA được dựng. Chủ văn phòng chỉ nên làm mục A–C ngay bây giờ
> (Apple xác minh doanh nghiệp mất vài tuần). Mục D–G, các lệnh, đường dẫn và tag nêu dưới đây chỉ có sau
> khi đội kỹ thuật báo Task 2–9 của kế hoạch M15 xong — đừng tạo khoá APNs hay khoá API trước lúc đó.

Nên **bắt đầu mục A ngay hôm nay**: Apple xác minh doanh nghiệp mất vài ngày đến vài tuần. Phần mã chỉ
bắt đầu sau khi CRM đã được dùng thật tại văn phòng.

| Mục | Việc | Chi phí | Thời gian chờ thường gặp |
|---|---|---|---|
| A | Mã D-U-N-S của công ty | miễn phí | 1–5 ngày làm việc (có khi tới 2 tuần) |
| B | Tài khoản Apple Developer Program (tổ chức) | 99 USD/năm | 1–7 ngày sau khi có D-U-N-S |
| C | Apple Business Manager | miễn phí | vài ngày (Apple gọi điện xác minh) |
| D | Khoá và hồ sơ app trong App Store Connect | — | 30 phút |
| E | Bản thử TestFlight cho nhân sự | — | ngay khi bản đầu dựng xong |
| F | Nghiệm thu trên iPhone thật | — | 1 buổi |
| G | Nộp duyệt, phát hành riêng cho công ty | — | Apple duyệt thường 1–3 ngày |

## A. Mã D-U-N-S

1. Vào trang tra cứu D-U-N-S của Apple: <https://developer.apple.com/enroll/duns-lookup/>.
2. Nhập đúng **tên pháp lý** của công ty (như trên giấy đăng ký hoạt động), địa chỉ, số điện thoại.
3. Không có sẵn thì gửi yêu cầu cấp mới ngay trên trang đó (miễn phí). Dun & Bradstreet có thể gọi điện
   hoặc gửi email xác minh — trả lời sớm.

Lưu ý: phải là **pháp nhân** (công ty luật / văn phòng luật có đăng ký), không đăng ký dưới tên cá nhân.

## B. Apple Developer Program dạng tổ chức

1. Dùng một **Apple ID của công ty** (vd `it@luatvukhang.com`), bật xác thực hai yếu tố. Không dùng
   Apple ID cá nhân của ai đó: người đó nghỉ thì công ty mất app.
2. Đăng ký tại <https://developer.apple.com/programs/enroll/> → chọn **Organization**, điền D-U-N-S,
   website công ty (tên miền phải khớp email đăng ký), người đăng ký phải có thẩm quyền ký thay công ty.
3. Thanh toán 99 USD/năm. Ghi lại **Team ID** (10 ký tự, ở mục Membership).

## C. Apple Business Manager (để phát hành app riêng cho công ty)

1. Đăng ký tại <https://business.apple.com> bằng cùng D-U-N-S. Apple sẽ gọi điện xác minh.
2. Sau khi được duyệt: **Cài đặt → Thông tin tổ chức** → ghi lại **Organization ID**. Mã này dùng ở mục G.

## D. Khoá và hồ sơ app

Làm trong <https://developer.apple.com/account> và <https://appstoreconnect.apple.com>:

1. **Đăng ký Bundle ID**: Certificates, Identifiers & Profiles → Identifiers → `+` → App IDs →
   `com.luatvukhang.crm.staff`, bật **Push Notifications**.
2. **Khoá thông báo đẩy (APNs)**: Keys → `+` → chọn *Apple Push Notifications service (APNs)* → tải tệp
   `AuthKey_XXXXXXXXXX.p8` (**chỉ tải được một lần**). Ghi lại **Key ID**.
   Gửi tệp này cho người quản trị máy chủ qua kênh an toàn (không gửi qua Zalo/email thường). Trên máy
   chủ: đặt vào `storage/app/private/apns/AuthKey.p8`, quyền đọc chỉ cho tài khoản chạy web, và điền
   `.env`:
   ```
   APNS_KEY_ID=<Key ID>
   APNS_TEAM_ID=<Team ID>
   APNS_BUNDLE_ID=com.luatvukhang.crm.staff
   APNS_KEY_PATH=storage/app/private/apns/AuthKey.p8
   ```
3. **Tạo hồ sơ app**: App Store Connect → Apps → `+` → New App → iOS, tên (vd "Luật Vũ Khang — Nội bộ"),
   ngôn ngữ chính Tiếng Việt, Bundle ID ở bước 1, SKU `vkcrm-staff`.
4. **Khoá API cho máy dựng tự động**: Users and Access → Integrations → App Store Connect API → Team
   Keys → `+`, quyền **Admin** (cần cho ký tự động) → tải `.p8` (một lần), ghi **Key ID** và **Issuer ID**.
5. **Đưa vào GitHub** (repo `Harry-Kien/vk-crm`):
   - Settings → Environments → tạo `ios-release`, thêm chính anh/chị làm người duyệt bắt buộc (mỗi lần
     tải bản lên Apple phải có anh/chị bấm đồng ý).
   - Cũng trong `ios-release` (Settings → Environments → ios-release → **Environment secrets**) tạo
     `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_P8_BASE64` (nội dung tệp `.p8` mã hoá base64),
     `APPLE_TEAM_ID`. **Không** đặt bốn khoá này ở "Repository secrets": chỉ khoá nằm trong môi trường mới
     bị chặn cho tới khi người duyệt bấm đồng ý.
   - Settings → Secrets and variables → Actions → Variables: `VKCRM_SERVER_URL` =
     `https://khachhang.luatvukhang.com/admin` (biến thường, để ở mức repo cũng được).

## E. Bản thử TestFlight

1. App Store Connect → Users and Access: mời các nhân sự sẽ thử (vai *Developer* hoặc *Marketing* là
   đủ, tối đa 100 người).
2. Khi đội kỹ thuật báo bản đã lên: TestFlight → Internal Testing → thêm nhóm người thử.
3. Nhân sự cài app **TestFlight** trên iPhone, mở lời mời trong email. Bản thử hết hạn sau 90 ngày —
   dùng để nghiệm thu, không thay cho bản chính thức.

## F. Nghiệm thu trên iPhone thật (với bản TestFlight)

Khi app đã dựng xong, bản thử phải đạt các điểm sau. Đánh dấu từng dòng, chụp ảnh nếu có lỗi, ghi phiên bản iOS:

- [ ] Cài được, mở ra là trang đăng nhập CRM, đăng nhập được bằng mật khẩu + mã 2 lớp.
- [ ] Để app ở nền 5 phút, mở lại → phải mở khoá bằng Face ID / mật mã.
- [ ] Vuốt lên xem trình chuyển app → nội dung CRM bị che.
- [ ] Tải một tài liệu → xem được, chia sẻ được; đóng xem trước, mở app **Tệp** → không thấy tệp đó.
- [ ] Bật thông báo trong app, nhờ người khác giao một việc cho mình, khoá máy → có thông báo; bấm vào
      → mở đúng trang việc đó.
- [ ] Đăng xuất, nhờ giao thêm một việc → **không** có thông báo.
- [ ] Từ máy tính, quản trị viên bấm "Khoá truy cập ngay" với tài khoản thử → thao tác kế tiếp trên app
      về trang đăng nhập.
- [ ] Bấm một liên kết ra trang ngoài → mở bằng Safari, không mở trong app.

## G. Nộp duyệt và phát hành riêng cho công ty

1. **Giới hạn IP**: nếu máy chủ đặt `ADMIN_IP_ALLOWLIST`, người duyệt của Apple (ở Mỹ) sẽ thấy trang
   lỗi và app bị từ chối. Trong thời gian chờ duyệt phải tạm để trống biến này, hoặc chấp nhận app chỉ
   dùng được trong mạng văn phòng và trao đổi với Apple. Đây là quyết định của anh/chị.
   (Ghi chú chung: nếu giữ giới hạn IP lâu dài, nhân sự chỉ dùng được app khi ở văn phòng hoặc qua VPN.)
2. **Bật tài khoản người duyệt**: `php artisan vkcrm:app-review-account enable` trên máy chủ thật. Lệnh in
   email, mật khẩu và 8 mã khôi phục **một lần** — dán vào ô "Sign-in information" khi nộp, kèm hướng
   dẫn trong `mobile/store/review-notes.md`. Tài khoản này chỉ có vai "Nhân viên kinh doanh" và vài lead
   mẫu: không thấy vụ việc, không thấy tiền.
3. App Store Connect → app → **Pricing and Availability** → *App Distribution Method*: **Private**, nhập
   **Organization ID** ở mục C. (Đã chọn Private thì app không bao giờ lên App Store công khai.)
4. Điền mô tả, ảnh chụp màn hình, URL chính sách quyền riêng tư (`/chinh-sach-quyen-rieng-tu-app` —
   **luật sư phải duyệt nội dung trước**), câu trả lời App Privacy theo `mobile/store/metadata-vi.md`.
   Chọn bản đã nghiệm thu ở mục F → **Submit for Review**.
5. Duyệt xong: **khoá tài khoản người duyệt ngay** — `php artisan vkcrm:app-review-account disable` —
   và đặt lại `ADMIN_IP_ALLOWLIST` nếu đã tạm bỏ.
6. **Phát cho nhân sự**: business.apple.com → Apps and Books → tìm app → mua số giấy phép bằng số nhân sự
   (miễn phí) → chọn **Mã đổi (Redeemable codes)** → tải bảng mã → gửi mỗi người một mã. Nhân sự mở
   App Store → ảnh đại diện → *Đổi thẻ quà tặng hoặc mã* → nhập mã → app được cài.

## Cập nhật về sau

(Mô tả cách app SẼ vận hành khi đã dựng xong.)

- **Sửa tính năng CRM** (màn hình, quy trình, quyền): cập nhật máy chủ như bình thường. App hiện ngay bản
  mới, **không cần nộp lại Apple**.
- **Sửa phần vỏ app** (Face ID, tải tệp, biểu tượng, phiên bản iOS mới): đội kỹ thuật gắn tag
  `ios-v<số>` → anh/chị bấm duyệt ở môi trường `ios-release` → bản lên TestFlight → nghiệm thu mục F →
  Submit for Review. Nhân sự đã nhận app tự cập nhật qua App Store.
- **Hằng năm**: gia hạn Apple Developer Program (99 USD). Hết hạn thì app đã cài vẫn chạy nhưng không
  cập nhật được và thông báo đẩy có thể ngừng.

## Khi nhân sự mất điện thoại hoặc nghỉ việc

1. Quản trị viên vào **Nhân sự → người đó → "Khoá truy cập ngay"**: phiên trên điện thoại bị cắt ở thao
   tác kế tiếp, máy bị gỡ khỏi danh sách nhận thông báo.
2. Khi app đã dựng xong, nó sẽ luôn đòi Face ID / mật mã máy và không lưu hồ sơ trên máy, nên người nhặt
   được điện thoại không đọc được dữ liệu CRM.
3. Nghỉ việc hẳn: bàn giao việc như quy trình thường, thu hồi mã đổi trong Apple Business Manager nếu
   cần.
