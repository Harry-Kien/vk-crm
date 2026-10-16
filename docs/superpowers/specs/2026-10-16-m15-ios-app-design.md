# M15 — App iPhone nội bộ (thiết kế)

Ngày: 2026-10-16. Người quyết: chủ văn phòng đã giao toàn bộ lựa chọn kỹ thuật ("đăng nhập tài khoản vào
là dùng được hết; quan trọng nhất là bảo mật, tránh mất dữ liệu công ty"). Mọi lựa chọn dưới đây là
**lựa chọn của người thiết kế**, chủ văn phòng đảo được.

## 1. Mục đích

Nhân sự của văn phòng cài một app "thật" trên iPhone (từ App Store, phát hành riêng cho công ty), đăng
nhập bằng chính tài khoản nội bộ, và dùng được **mọi thứ** panel `/admin` đang có, theo đúng quyền của
vai mình. App không phải hệ thống thứ hai: dữ liệu vẫn chỉ ở một máy chủ, nghiệp vụ vẫn chỉ trong
`app/Actions/`.

Thành công = (1) nhân sự cài được qua liên kết/mã của công ty, (2) đăng nhập 2 lớp như website, (3)
nhận thông báo đẩy kể cả khi app đóng, (4) mất điện thoại thì quản trị viên cắt được ngay, (5) qua được
duyệt của Apple.

## 2. Không làm (non-goals)

- App cho **khách hàng** (`/portal`). Cổng khách vẫn là PWA. Làm sau, cần phán quyết riêng (M12, "Về sau").
- Giao diện gốc vẽ lại từng màn hình, API JSON mới, OAuth/Passport cho app. Không cần: app hiển thị
  chính panel `/admin` (xem 4.1).
- Kho tài liệu xem ngoại tuyến, đồng bộ, lưu hồ sơ trên máy (đi ngược R1 của M12).
- Android/Google Play. Kiến trúc để ngỏ (Capacitor có Android), nhưng không dựng.

## 3. Phản mục tiêu (làm thế này là hỏng dù "chạy được")

- Dữ liệu công ty nằm lại trên điện thoại sau khi đăng xuất hoặc sau khi xem tệp.
- App mở được trang của tên miền khác bên trong khung app (trang lạ gọi được cầu nối gốc).
- Một token đăng nhập dài hạn (JWT, API key) cất trên máy: mất máy = mất tài khoản.
- Đổi người dùng trên cùng một máy mà thông báo của người trước vẫn tới.
- Một đường vòng né đăng nhập 2 lớp hoặc né `ADMIN_IP_ALLOWLIST` "cho tiện app".

## 4. Quyết định kỹ thuật

### 4.1 Vỏ gốc Capacitor bọc panel `/admin`

- Dự án Capacitor ở `mobile/` (riêng `package.json`, không trộn với Vite của Laravel).
- `server.url` = `https://<APP_URL>/admin`. WKWebView nạp thẳng panel thật; mỗi lần cập nhật máy chủ,
  app có bản mới ngay, không phải nộp lại Apple.
- **App-Bound Domains** (`WKAppBoundDomains` trong `Info.plist` + `limitsNavigationsToAppBoundDomains`):
  WKWebView chỉ chạy script và giữ cookie cho đúng tên miền CRM. Điều hướng sang tên miền khác mở bằng
  Safari, ngoài app.
- Chuỗi User-Agent thêm `VKCRM-iOS/<phiên bản>` để máy chủ biết đang chạy trong app (ẩn nút "Bật thông
  báo" kiểu web, nạp cầu nối). Đây chỉ là gợi ý giao diện, **không bao giờ** là căn cứ cấp quyền.
- Trang ngoại tuyến đóng gói sẵn trong app (tiếng Việt, nút "Thử lại").

### 4.2 Xác thực: giữ nguyên phiên của website, không JWT

App dùng đúng đăng nhập của panel: mật khẩu + mã 2 lớp (bắt buộc, M8), cookie phiên `HttpOnly`,
`Secure`, `SameSite=Lax`, CSRF, `session_epoch`, khoá đăng nhập theo IP/tài khoản. Không token nào được
JavaScript đọc. Lý do bỏ JWT: JWT lưu trên máy là bí mật dài hạn, khó thu hồi tức thời; phiên máy chủ thu
hồi được ngay bằng "Khoá truy cập ngay". (JWT chỉ xuất hiện ở **phía máy chủ**, để ký yêu cầu gửi APNs
bằng khoá `.p8` — khoá đó không bao giờ rời máy chủ.)

`ADMIN_IP_ALLOWLIST` giữ nguyên ý nghĩa: nếu văn phòng đặt danh sách IP, app chỉ dùng được trong mạng đó
(hoặc qua VPN). Không có ngoại lệ cho app — một header "tôi là app" giả được.

### 4.3 Lớp bảo vệ trên máy

- **Khoá Face ID / mật mã máy**: khi mở app và khi quay lại sau ≥ 5 phút ở nền
  (`LAPolicy.deviceOwnerAuthentication`). Máy **không đặt mật mã** → app từ chối chạy, chỉ hiện hướng
  dẫn đặt mật mã (không có mật mã thì iOS không mã hoá dữ liệu app).
- **Che màn hình khi rời app**: ảnh chụp trong trình chuyển app không lộ dữ liệu.
- **Tệp tải về**: lưu vào thư mục tạm có `FileProtectionType.complete`, mở bằng xem trước/chia sẻ của
  iOS, **xoá khi đóng xem trước** và xoá sạch thư mục tạm mỗi lần app khởi động.
- Không cache HTTP lưu đĩa cho nội dung panel (máy chủ đã gửi `Cache-Control: no-store` cho trang có
  phiên — kiểm lại ở Task 1).
- ATS mặc định của Apple (chỉ HTTPS, TLS 1.2+); không `NSAllowsArbitraryLoads`.

### 4.4 Thông báo đẩy qua APNs, cùng một luật người nhận

- WKWebView không nhận Web Push, nên app nhận push qua APNs. Máy chủ thêm **một kênh gửi**:
  `SendPushAlert` vẫn là đường duy nhất, `PushTopic` và luật người nhận/nội dung R10–R12 giữ nguyên.
- Bảng `native_push_devices` (chỉ nhân sự): token APNs, môi trường (sandbox/production), nhãn máy,
  phiên bản app, `last_seen_at`. Token là duy nhất: máy đổi người đăng nhập thì dòng chuyển sang người mới.
- Đăng xuất gỡ máy (giống `ForgetPushDevice::onLogout`); "Khoá truy cập ngay", xoá nhân sự, đặt lại 2
  lớp gỡ mọi máy (`ForgetPushDevice::all`). APNs trả 410/`BadDeviceToken`/`Unregistered` → xoá dòng.
- Bấm thông báo mở đúng đường dẫn trong app, chỉ khi đường dẫn cùng máy chủ.
- Thiếu khoá APNs trong `.env` → kênh APNs tắt êm, Web Push không bị ảnh hưởng.

### 4.5 Phát hành

- Tài khoản **Apple Developer Program dạng tổ chức** (99 USD/năm, cần mã D-U-N-S).
- Giai đoạn 1: **TestFlight, người thử nội bộ** (≤ 100 người là thành viên App Store Connect, không
  phải chờ duyệt) — dùng ngay khi bản đầu dựng xong.
- Giai đoạn 2: **Custom App (phát hành riêng) qua Apple Business Manager** — Apple vẫn duyệt, app không
  hiện trên App Store công khai, nhân sự nhận bằng mã đổi/đường dẫn của công ty.
- Máy dev là Windows: gói iOS dựng trên máy macOS của **GitHub Actions**, ký bằng chứng chỉ do Xcode
  Cloud Signing quản lý qua khoá API App Store Connect, tải lên TestFlight bằng fastlane.
- **Tài khoản cho người duyệt Apple**: tài khoản vai "Nhân viên kinh doanh" (không thấy vụ việc, không
  thấy tiền), chỉ có vài lead mẫu, đăng nhập 2 lớp bằng **mã khôi phục** ghi trong ghi chú duyệt; tạo và
  khoá bằng một lệnh artisan. Bật trong lúc chờ duyệt, khoá ngay khi duyệt xong.

### 4.6 Rà soát bảo mật máy chủ đi kèm

Chủ văn phòng nêu SQL injection: thêm test chặn mọi câu SQL thô mới có chèn biến mà chưa được duyệt
(danh sách nền ghi lý do từng chỗ), và job CI `composer audit` + `npm audit`. Các lớp đã có từ M2/M8
(chính sách quyền, 404 khi từ chối, CSP, header bảo mật, khoá đăng nhập, sao lưu) không làm lại.

## 5. Ràng buộc

- PHP 8.3, không gói đòi PHP 8.4+; không Redis/Horizon/Octane/Reverb/Pulse/Scout (CLAUDE.md).
- Chữ giao diện tiếng Việt qua `__()`/`lang/vi/`, định danh code tiếng Anh; enum backed string có `label()`.
- Apple: dựng bằng Xcode 26 / iOS 26 SDK (bắt buộc với bản tải lên từ 2026-04); iOS tối thiểu 16.4.
- Gói JS của `mobile/` ghim phiên bản chính xác (package-lock), bản đã phát hành ≥ 2 tuần.

## 6. Để người dựng tự quyết

Cấu trúc lớp Swift, cách nối vào `CAPBridgeViewController`, câu chữ phụ, biểu tượng (dùng lại biểu tượng
PWA nội bộ của M12), bố cục trang ngoại tuyến.
