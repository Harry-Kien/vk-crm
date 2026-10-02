# Khảo sát PWA và Web Push trước khi viết mã — M12 Task 1, 2026-10-01

Kế hoạch M12 (`docs/superpowers/plans/2026-09-24-m12-pwa.md`, Task 1) đòi đo ba điều trên điện thoại
thật qua HTTPS trước khi viết mã: (1) iPhone, app đã cài, tải tài liệu qua route nằm ngoài scope;
(2) đăng nhập cổng khách có mã OTP trong app đã cài; (3) Chrome Android, hai app cùng origin.

Agent không điều khiển được điện thoại của văn phòng, nên theo phán quyết 3 của controller tài liệu
này gồm ba phần:

- **Mục 1 — tra cứu** tài liệu hiện hành, mỗi khẳng định kèm nguồn và ngày đọc.
- **Mục 2 — khảo sát MÔ PHỎNG** bằng Playwright trên bản chạy local của làn. *Mô phỏng — không thay
  máy thật.* Mọi con số ở mục này là của Chromium/WebKit chạy headless trên Windows, không phải của
  iOS hay Android.
- **Mục 3 — phán quyết TẠM** cho ba câu hỏi, để Task 2–9 không bị chặn. Câu 2 và 3 **PENDING OWNER**:
  chủ văn phòng chạy danh sách kiểm tra `docs/research/2026-10-01-pwa-kiem-tra-may-that.md` trên
  một Android và một iPhone thật. Danh sách đó cũng là phần máy thật của Task 10. Câu 1 gốc (cookie
  ngoài scope) **không đo**: nó được thay bằng phán quyết tạm, và phần còn PENDING OWNER của nó là
  "tải TRONG scope chạy trên iPhone thật, cho cả hai app" (mục 3, dòng 1).

Không mở đường hầm HTTPS công khai nào tới máy dev (dữ liệu demo không ra Internet).

## 1. Tra cứu (đọc ngày 2026-10-01)

### 1.1 iOS / iPadOS

| Khẳng định | Nguồn |
|---|---|
| Web Push cho web app có từ iOS/iPadOS **16.4**, **chỉ** cho web app đã "Thêm vào Màn hình chính" (Home Screen web app). | [WebKit — Web Push for Web Apps on iOS and iPadOS](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/) |
| Xin quyền thông báo phải là **phản hồi trực tiếp một thao tác của người dùng** (chạm). Tự hỏi lúc tải trang thì không được. | như trên |
| Manifest phải có `display` là `standalone` hoặc `fullscreen` thì icon mở ra thành web app (không phải dấu trang). | như trên |
| iOS 16.4 có Badging API (`navigator.setAppBadge`) cho web app đã cài. | như trên |
| **iOS/iPadOS 26:** mọi trang được "Thêm vào Màn hình chính" mặc định mở như web app; trong hộp thoại có công tắc "Mở như ứng dụng web" — tắt nó thì chỉ là dấu trang (và khi đó KHÔNG có push). | [heise — iOS 26 and iPadOS 26: Changed web app behaviour on the home screen](https://heise.de/-10749652) |
| Web app đã cài **không chia sẻ** cookie, bộ nhớ, service worker với Safari. Điều hướng ra ngoài scope (từ iOS 12.2) mở một "trình duyệt trong app" (in-app browser); trình duyệt đó **cũng không chia sẻ** bộ nhớ với Safari, nhưng **chia sẻ với các phiên bản trình duyệt-PWA khác** ("They share it with other instances of PWA Browsers though"). Khi trình duyệt trong app quay lại một URL trong scope, nó đóng lại và nạp URL đó trong cửa sổ app. | [Maximiliano Firtman — iOS 12.2 PWA](https://firt.dev/ios-12.2/) (bài 2019; chưa thấy tài liệu Apple chính thức nói lại cho iOS 16–26) |
| Hệ quả hay gặp: đăng nhập qua một trang ngoài scope rồi quay về thì app "mất đăng nhập". | [Apple Developer Forums 100524](https://developer.apple.com/forums/thread/100524), [Netguru — share session between PWA standalone and Safari](https://www.netguru.com/blog/how-to-share-session-cookie-or-state-between-pwa-in-standalone-mode-and-safari-on-ios) |
| Hạn mức lưu trữ của web app chạy standalone bằng hạn mức khi mở trong trình duyệt. | [WebKit — Updates to Storage Policy](https://webkit.org/blog/14403/updates-to-storage-policy/) |

**Câu hỏi 1 của Task 1 chưa có lời đáp chắc chắn từ tài liệu.** Nguồn duy nhất nói thẳng về trình
duyệt trong app (Firtman, 2019) nói nó dùng chung bộ nhớ với "các phiên bản PWA khác" — đọc theo
nghĩa đó thì cookie phiên của app *có thể* đi theo. Nhưng nguồn đã cũ 7 năm, không phải của Apple,
và các báo cáo "mất đăng nhập" ở trên cho thấy hành vi đã từng khác nhau giữa các bản iOS. Không đủ
để dựa vào — nên phán quyết tạm (mục 3, dòng 1) đưa mọi liên kết tải VÀO scope, thay vì đo câu này.
Danh sách kiểm tra chạy sau Task 3 không đo được nó nữa (mọi liên kết tải khi đó đã trong scope).

### 1.2 Chrome Android

| Khẳng định | Nguồn |
|---|---|
| Tiêu chí cài: HTTPS; manifest có `name` hoặc `short_name`, `icons` gồm **192px và 512px**, `start_url`, `display` thuộc `fullscreen`/`standalone`/`minimal-ui`/`window-controls-overlay`; `prefer_related_applications` vắng hoặc `false`; người dùng đã chạm trang ít nhất một lần và xem ≥ 30 giây. | [web.dev — What does it take to be installable?](https://web.dev/articles/install-criteria) |
| Từ Chrome **108 (Android)** / 112 (desktop) **không còn đòi** service worker có `fetch` để cài từ menu. Riêng *lời mời cài tự động* (install prompt) vẫn còn đòi `fetch`. | [Chrome — Revisiting Chrome's installability criteria](https://developer.chrome.com/blog/update-install-criteria) |
| `id` của manifest là danh tính của app. Thiếu `id` thì Chrome (từ 96) tự sinh từ `start_url`. Một manifest có `id` chưa khớp app nào đã cài thì được coi là **app mới, kể cả cùng origin**. | [Chrome — Uniquely identifying PWAs with the web app manifest id property](https://developer.chrome.com/docs/capabilities/pwa-manifest-id) |
| `scope` bị bỏ qua nếu `start_url` không nằm trong nó; khi đó scope rơi về thư mục của `start_url`. Một URL "trong scope" khi path của nó **bắt đầu bằng** path của scope (so tiền tố chuỗi). | [MDN — scope](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Manifest/Reference/scope) |
| Trên Chrome Android, `clients.openWindow()` trong `notificationclick` có thể mở URL trong cửa sổ của web app đã cài (khi URL thuộc scope của app đó). | [MDN — Clients.openWindow()](https://developer.mozilla.org/en-US/docs/Web/API/Clients/openWindow) |

### 1.3 Service worker

| Khẳng định | Nguồn |
|---|---|
| Scope tối đa mặc định của một service worker là thư mục chứa script (`/admin/sw.js` → `/admin/`). Muốn rộng hơn phải có header `Service-Worker-Allowed` trên response của script; thiếu thì `register()` bị từ chối với `SecurityError`. | [W3C web-platform-tests — Service-Worker-Allowed-header](https://cobalt.googlesource.com/cobalt/+/527110903c994f57b3fc64852b74e941d515b683/third_party/web_platform_tests/service-workers/service-worker/Service-Worker-Allowed-header.https.html), [docs.w3cub — ServiceWorkerContainer.register](https://docs.w3cub.com/dom/serviceworkercontainer/register) — **đã đo lại ở mục 2.1** |

### 1.4 Giới hạn của chính Playwright

| Khẳng định | Nguồn |
|---|---|
| Playwright chỉ hỗ trợ (theo nghĩa API của nó) service worker trên Chromium. Request lấy **script chính** của service worker khi cập nhật không route được. | [Playwright — Service Workers](https://playwright.dev/docs/service-workers) |
| `pushManager.subscribe()` hỏng trong trình duyệt headless; muốn có đăng ký thật phải chạy có giao diện, hoặc giả `subscribe`. | [tessl — web-push-tests](https://tessl.io/registry/testland/web-push-tests), [Notificare — Automated browser tests](https://notifica.re/blog/2021/01/08/Automated-browser-tests/) — **đã đo lại ở mục 2.6** |
| Thiết bị mô phỏng (`devices['iPhone 13']`, `devices['Pixel 7']`) chỉ đổi User-Agent, khung nhìn, tỉ lệ điểm ảnh và cảm ứng; động cơ vẫn là WebKit/Chromium build cho Windows. | [Playwright — Emulation](https://playwright.dev/docs/emulation) |

## 2. Khảo sát MÔ PHỎNG (Playwright) — *mô phỏng, không thay máy thật*

### 2.1 Cách đo

- Bản chạy của làn: `/d/vkwt/m12-dev seed`, rồi `/d/vkwt/m12-dev serve -e CSP_MODE=enforce
  -e PHP_INI_SCAN_DIR=:/var/www/html/tools/csp/php -v '<probe>/router.php:<server.php của artisan
  serve>:ro'`, ở http://localhost:8097 (`localhost` là secure context: service worker chạy không cần
  HTTPS). CSP ở chế độ **enforce** như production.
- Manifest và service worker tối thiểu, viết tay, do một router tạm phục vụ ở
  `/{admin,portal}/probe-manifest.webmanifest` và `/{admin,portal}/probe-sw.js` — cùng thư mục với
  `sw.js` tương lai, nên scope tối đa mặc định giống hệt (`/admin/`, `/portal/`). Biến thể chọn bằng
  query: `?swa=1` thêm `Service-Worker-Allowed`; `?v=slash` cho `scope: "/admin/"`; `?v=noid` bỏ `id`.
  Service worker khảo sát **không cache gì và không gọi `respondWith`**: nó chỉ ghi lại request đã
  thấy. **Router, manifest, service worker và hai script Playwright KHÔNG được commit** (nằm ở
  `.superpowers/sdd/m12/probe/`, thư mục bị `.gitignore`); không tệp nào trong `public/`, `routes/`
  hay `app/` bị đụng — Task 2 bắt đầu từ cây sạch.
- Trình duyệt: Playwright 1.63.0 — Chromium 153.0.8010.12 (headless shell; một lượt `channel:
  'chromium'` cho push) và WebKit 26.6. Nhật ký và ảnh chụp: `.superpowers/sdd/m12/probe/task1-*`.
- Đăng nhập thật: nhân sự `admin@luatvukhang.com` (mật khẩu + TOTP demo), khách `khach1@example.com`
  (mật khẩu + mã 6 số đọc từ `storage/logs/laravel.log`).

### 2.2 Scope không dấu `/` cuối và `Service-Worker-Allowed` — "sẽ cắn" số 1, đã đo

| Đăng ký `/admin/probe-sw.js` với | Header `Service-Worker-Allowed: /admin` | Kết quả (Chromium 153) |
|---|---|---|
| `scope: '/admin'` | không | **`SecurityError`**: "The path of the provided scope ('/admin') is not under the max scope allowed ('/admin/'). Adjust the scope, move the Service Worker script, or use the Service-Worker-Allowed HTTP header to allow the scope." |
| `scope: '/admin/'` | không | đăng ký được, scope `http://localhost:8097/admin/` |
| `scope: '/admin'` | có | đăng ký được, scope `http://localhost:8097/admin`; sau khi nạp lại, trang `/admin/login` được điều khiển |

Đăng ký với `scope: '/admin/'` thì trang `/admin/login` được điều khiển, nhưng theo luật so tiền tố
(mục 1.2–1.3) chính `start_url` `/admin` (bảng điều khiển) **không** nằm trong `/admin/`. Đây là cái
bẫy của kế hoạch: sai một bên thì trông như vẫn chạy.

### 2.3 Manifest: scope có/không dấu `/`, hai `id` — câu hỏi 3, phần đo được

Đọc qua CDP `Page.getAppManifest` (trình duyệt tự phân tích manifest) và `Page.getInstallabilityErrors`:

| Manifest | Lỗi phân tích | `scope` sau phân tích | Lỗi cài đặt |
|---|---|---|---|
| admin, `id: "/admin"`, `scope: "/admin"`, `start_url: "/admin"` | không | `http://localhost:8097/admin` | không |
| admin, `scope: "/admin/"` (có dấu `/`) | **"property 'scope' ignored. Start url should be within scope of scope URL."** | **`http://localhost:8097/`** — cả origin | không |
| admin, không `id` | không | `/admin` | không |
| portal, `id: "/portal"`, `scope: "/portal"`, `start_url: "/portal"` | không | `http://localhost:8097/portal` | không |
| portal, không `id` | không | `/portal` | không |

- Phát hiện nặng nhất của lượt đo: với `scope: "/admin/"`, Chromium **bỏ scope và rơi về `/`** —
  app nội bộ khi đó "nuốt" cả `/portal`. Trên một máy cài cả hai app, đó đúng là kịch bản "app thứ
  hai đè app thứ nhất" mà kế hoạch cảnh báo, chỉ đến từ một dấu `/`. R2 (scope không dấu `/`) được
  số đo xác nhận.
- `Page.getAppId` của CDP trả rỗng trong headless, nên lượt mô phỏng **không** đo được Chrome có giải
  hai `id` thành hai app hay không; chỉ đo được hai manifest phân tích sạch với hai scope tách rời.
  Phần "hai biểu tượng, hai cửa sổ" là PENDING OWNER (mục C của danh sách kiểm tra).
- Lỗi cài đặt rỗng ở mọi biến thể, kể cả khi không có service worker và chỉ có icon 512 + 256: khớp
  với tiêu chí không còn đòi `fetch` (mục 1.2). Icon 192 vẫn làm theo R3 vì web.dev còn liệt kê nó.
- Hai panel hôm nay không có route nào bắt đầu bằng `admin`/`portal` mà không theo sau bởi `/`
  (đọc `route:list`). Scope `/admin` so tiền tố chuỗi, nên một route tương lai như `/admin-xyz` sẽ
  lọt vào scope của app nội bộ. Ghi lại cho Task 3.

### 2.4 Service worker đang điều khiển trang: Livewire, CSP, bộ nhớ đệm

Đăng ký service worker khảo sát (`scope: '/admin'`, có header), nạp lại, rồi đăng nhập admin đủ hai
bước (mật khẩu + TOTP) **trong khi service worker đang điều khiển trang**:

- Đăng nhập xong, tới `/admin`, trang vẫn được điều khiển. **0 vi phạm CSP** ở chế độ enforce:
  `worker-src 'self' blob:` hiện có cho phép đăng ký service worker cùng origin; `manifest-src` rơi
  về `default-src 'self'`.
- Service worker **nhận sự kiện `fetch` cho mọi request của trang được điều khiển**, kể cả những thứ
  nằm ngoài scope hoặc khác origin. Mẫu đã thấy (55 request): `GET navigate /admin/login`,
  `/css/filament/…`, `/js/filament/…`, `/livewire-644bbbea/livewire.js`, `/brand/vk-mark-256.png`,
  `GET cors https://fonts.bunny.net/…woff2`, và **`POST cors /livewire-644bbbea/update`**. Vì vậy luật
  R4 "chỉ GET cùng origin, dòng đầu của trình xử lý `fetch`" là cần thiết thật: không có nó, một
  `respondWith` viết vội sẽ bắt cả Livewire lẫn font của bunny.net.
- `navigationPreload` bật được (`{ enabled: true, headerValue: 'true' }`).
- `caches.keys()` rỗng (service worker khảo sát không cache gì — đây là đối chứng cho Task 3).

### 2.5 Tải tài liệu khi service worker điều khiển `/portal` — câu hỏi 1, phần đo được

Khách đăng nhập (mật khẩu + OTP) trên trang đã có service worker `scope: '/portal'`, rồi bấm một liên
kết tải tài liệu trên trang hồ sơ:

- Liên kết là `/documents/{id}/download?…&signature=…` — **ngoài** scope `/portal` (đúng như kế hoạch).
- Tải được (Chromium, trình duyệt thường): tệp "Giấy tờ tuỳ thân của người khởi kiện, bản sao chứng
  thực.pdf". Service worker **không** nhận request tải (điều hướng tới URL ngoài scope không đi qua
  service worker của `/portal`).
- Cùng URL có chữ ký: **200 khi có cookie phiên, 404 khi không có cookie**. Đây chính là cái giá nếu
  trình duyệt trong app của iOS (mục 1.1) không mang cookie: khách thấy 404 cho mọi tài liệu.
- Câu iPhone thật "trình duyệt trong app có mang cookie không" **không được đo**: mục A của danh
  sách kiểm tra chạy sau Task 3, khi liên kết tải đã nằm TRONG scope, nên nó chỉ kiểm được "tải
  trong scope có chạy không". Câu cookie được thay bằng phán quyết tạm (mục 3, dòng 1), và phán
  quyết đó chỉ đứng được nếu liên kết mở trong cùng cửa sổ app (mục 2.10).

### 2.6 Đăng nhập cổng có OTP — câu hỏi 2, phần đo được

- Ô mã: `type="text"`, `inputmode="numeric"`, **`autocomplete="one-time-code"`** (WebKit với mô phỏng
  iPhone 13; Chromium như nhau). Khớp với đọc mã: `OneTimeCodeInput` của Filament 5.8.1
  (`app/Filament/Portal/Auth/PortalEmailAuthentication.php:119-122`).
- **Nạp lại trang giữa bước nhập mã thì mất bước mã**: quay về `/portal/login` với ô mật khẩu, không còn
  ô mã. Bước mã là trạng thái của component Livewire, không được giữ qua một lần nạp lại. Nếu iOS hay
  Android nạp lại app đã cài trong lúc khách sang ứng dụng Mail đọc mã, khách phải nhập lại mật khẩu
  và chờ mã mới.
- Mã mới lại vướng giới hạn gửi của Filament: `EmailAuthentication::sendCode()` cho **2 lần gửi mỗi
  60 giây cho mỗi tài khoản** (`maxAttempts: 2`, cửa sổ mặc định 60 giây). Lượt đo thấy đúng điều đó:
  lần đăng nhập thứ ba trong cùng một phút không sinh thư mã nào trong nhật ký.
- Deep link sống qua OTP: mở `/portal/ho-so/13` khi chưa đăng nhập → chuyển về `/portal/login` →
  mật khẩu → mã → **về đúng `/portal/ho-so/13`** (Chromium). Chưa đo nhánh có
  `RequirePortalPasswordChange` (Task 6 có test HTTP theo R9).
- App có thật sự bị nạp lại khi chuyển sang Mail không, và bàn phím có gợi ý mã từ Mail không, là
  hành vi của hệ điều hành: PENDING OWNER (mục B).

### 2.7 Push trong trình duyệt tự động

| Trình duyệt | `PushManager` | `Notification.permission` | `pushManager.subscribe()` |
|---|---|---|---|
| Chromium headless shell (mặc định của Playwright) | có | `denied` (kể cả khi đã cấp quyền) | `AbortError: Registration failed - permission denied` |
| Chromium `channel: 'chromium'` (headless mới), quyền cấp lúc tạo context | có | `granted` | `AbortError: Registration failed - permission denied` |
| WebKit 26.6 (Playwright, Windows), mô phỏng iPhone 13 | **không** | — (`Notification` không có) | — |

Hệ quả cho các task sau: **không lượt Playwright nào có được một `PushSubscription` thật.** Task 3 và
Task 5 kiểm bằng trình duyệt tới bước xin quyền và gọi `subscribe` (giả `pushManager.subscribe` khi
cần đi tiếp tới máy chủ); Task 7 giả transport như kế hoạch đã định (`Http::fake()` hoặc bind lại
`WebPush`). Nhận thông báo thật, chạm vào thông báo, mở đúng app: chỉ đo được trên máy thật (mục C, D).

### 2.8 WebKit và thiết bị mô phỏng

- WebKit 26.6 (mô phỏng iPhone 13): `serviceWorker` có, đăng ký `scope: '/portal'` (có header) được;
  `PushManager`, `Notification`, `navigator.setAppBadge`, `navigator.standalone` **không có**;
  `display-mode: standalone` không khớp. User-Agent ghi "iPhone OS 15_0" chỉ vì đó là chuỗi của bộ
  mô tả thiết bị. **Không suy ra hành vi iOS từ các số này.**
- Chromium mô phỏng Pixel 7: `PushManager`, `Notification`, `setAppBadge`, `navigationPreload` có.
- Ảnh chụp hiện trạng (khung nhìn điện thoại): trang đăng nhập cổng, bước mã, trang hồ sơ (iPhone 13,
  WebKit); trang đăng nhập cổng và admin (Pixel 7) — `.superpowers/sdd/m12/probe/task1-*.png`.

### 2.9 Sự thật về máy chủ web, đọc được trong lúc đo (cho Task 2–3)

- `php artisan serve` **không** chuyển `/admin/` về `/admin` (cả hai cùng trả 302 tới
  `/admin/login`); phép chuyển 301 bỏ dấu `/` cuối chỉ có ở `public/.htaccess:16-19` (Apache). Mẫu
  nginx (`tools/deploy/nginx.conf.example:124-126`) cũng không có. Test HTTP của Laravel vì vậy không
  thấy phép chuyển đó; lý do chọn scope không dấu `/` không dựa vào nó mà dựa vào số đo ở 2.2–2.3.
- Mẫu nginx có `location ~* \.(?:css|js|…)$` (`tools/deploy/nginx.conf.example:114-122`) với
  `try_files $uri =404` và `Cache-Control "public, max-age=31536000, immutable"`. Một `/admin/sw.js`
  do Laravel phục vụ sẽ khớp location này trước và trả 404 → cần khối `location = /admin/sw.js` và
  `location = /portal/sw.js` (đã có trong phán quyết của controller). Cũng vì location này,
  `public/pwa/register.js` sẽ bị trình duyệt giữ một năm **không hỏi lại**: thẻ `<script>` của Task 2
  phải mang một tham số phiên bản (`?v=` băm nội dung) thì bản sửa mới tới được điện thoại.

### 2.10 Nút tải của app nội bộ mở TAB MỚI — sự thật cho Task 3 (đọc mã, chưa đo trên iPhone)

Đọc mã ngày 2026-10-01, sau rà soát Task 1:

- Nút "Tải tệp" của tab **Tài liệu** (admin) gọi `->openUrlInNewTab()`
  (`app/Filament/Admin/Resources/Matters/RelationManagers/DocumentsRelationManager.php:767`).
- Danh sách "Tệp khách đã gửi" trong hộp duyệt của tab **Danh mục hồ sơ** (nút "Đã nhận" / "Cần nộp
  lại") vẽ từng tệp thành `<a … target="_blank">`
  (`app/Filament/Admin/Resources/Matters/RelationManagers/ChecklistRelationManager.php:361`).
- Liên kết tải của cổng khách (`resources/views/filament/portal/pages/matter-progress.blade.php:218`)
  **không** có `target` — nó mở trong cùng cửa sổ.

Hệ quả: trong app nội bộ đã cài trên iPhone, một tab mới **không mở trong cửa sổ app** — web app
standalone không có tab, nên iOS đưa nó ra trình duyệt trong app hoặc Safari (kết luận của rà soát
Task 1, chưa đo trên máy thật; bước A8–A9 sẽ thấy) — **dù URL đã nằm trong scope** `/admin`. Khi đó
chuyện tải được hay không lại rơi về đúng câu hỏi 1 chưa đo (cookie của trình duyệt ngoài cửa sổ
app) — tức route bí danh `/admin/documents/{document}/download` một mình **không** cứu được nhân sự.

**Sự thật cho Task 3:** cùng lúc với route bí danh, liên kết tải của admin phải mở trong **cùng cửa
sổ**: bỏ `openUrlInNewTab()` ở `DocumentsRelationManager.php:767` và `target="_blank"` ở
`ChecklistRelationManager.php:361` khi URL là route bí danh trong scope. Bỏ được mà không mất gì
trên máy tính: response tải là `Content-Disposition: attachment` (`DocumentDownloadController`), nên
trình duyệt tải tệp về và **giữ nguyên trang** (cả hộp duyệt đang mở) chứ không rời trang. Task 3
ghim điều này bằng test Livewire (liên kết tải của hai chỗ không mang `target="_blank"` và trỏ route
bí danh). Danh sách kiểm tra có bước A7–A9 cho việc này trên iPhone thật.

## 3. Phán quyết TẠM (PENDING OWNER) — controller đã duyệt ngày 2026-10-01

| # | Câu hỏi | Tình trạng | Phán quyết tạm, để Task 2–9 đi tiếp |
|---|---|---|---|
| 1 | iPhone, app đã cài: trình duyệt trong app (mở khi tải tài liệu ở `/documents/{id}/download`, ngoài scope) có mang cookie phiên không? | **Không đo — thay bằng phán quyết tạm.** Tài liệu không đủ chắc (1.1); mô phỏng chỉ xác nhận cái giá: thiếu cookie = 404 (2.5). Danh sách kiểm tra chạy sau Task 3, khi mọi liên kết tải đã trong scope, nên không trả lời được câu này. **Còn PENDING OWNER:** tải TRONG scope chạy trên iPhone thật, trong cửa sổ app, cho cả hai app — mục A, bước A5–A6 (khách) và A7–A9 (nội bộ), kèm ảnh chụp và phiên bản iOS. | **Task 3 làm route tải bí danh TRONG scope**: `/portal/documents/{document}/download` và `/admin/documents/{document}/download`, cùng controller, cùng middleware (`signed`, `throttle:document-download`); nơi ký URL chọn tên route theo panel hiện hành. **Và liên kết tải của admin mở trong cùng cửa sổ** (bỏ `openUrlInNewTab()` ở `DocumentsRelationManager.php:767` và `target="_blank"` ở `ChecklistRelationManager.php:361` — mục 2.10); thiếu vế này thì route bí danh không giúp gì app nội bộ trên iPhone. Sai thì thừa hai route vô hại; không làm mà iOS không mang cookie thì khách iPhone không tải được tài liệu nào. |
| 2 | Đăng nhập cổng có OTP trong app đã cài: app có bị nạp lại khi sang Mail không; ô mã có gợi ý tự điền không? | **PENDING OWNER** (mục B). Đã có `autocomplete="one-time-code"`; nạp lại thì mất bước mã, và mã mới vướng giới hạn 2 lần/60 giây (2.6). | **Không sửa luồng OTP.** Nếu máy thật cho thấy mất bước mã, Task 10 ghi thành phát hiện và đề xuất trong phạm vi kế hoạch cho phép (giữ trạng thái bước mã); không tự sửa luồng OTP ngoài phạm vi đó. |
| 3 | Chrome Android: cài cả `/admin` lẫn `/portal` cùng origin — hai biểu tượng, hai cửa sổ, push mở đúng app? | **PENDING OWNER** (mục C, D). Mô phỏng: hai manifest phân tích sạch với hai scope tách rời; scope có dấu `/` bị bỏ và rơi về cả origin (2.3). | **Làm đúng R2: hai `id` (`/admin`, `/portal`) và hai `scope` không dấu `/`.** Task 2 thêm test ghim `id` và `scope` của cả hai manifest khác nhau và không có dấu `/` cuối. |
