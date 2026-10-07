{{--
    M12 R4 — service worker của app trên điện thoại, MỘT tệp cho cả hai panel; phục vụ ở
    `/{admin,portal}/sw.js` bởi `App\Http\Controllers\Pwa\ServiceWorkerController`. Mọi hằng số
    quyết định hành vi đến từ PHP (`App\Actions\Pwa\BuildServiceWorker`) và được test trên văn bản
    phục vụ ra (`tests/Feature/Pwa/ServiceWorkerTest.php`) — máy dev không có Node để chạy JS.

    Luật nặng ký nhất: KHÔNG BAO GIỜ lưu cái gì riêng tư.
     - Chỉ GET cùng origin. Mọi request khác (POST, cập nhật Livewire, tải lên, request tới
       fonts.bunny.net…) không gọi `respondWith`: trình duyệt xử lý như không có worker. Hai điều
       kiện `return` này đứng ĐẦU trình nghe `fetch`, trước mọi `respondWith` (test ghim).
     - Điều hướng: chỉ mạng (kể cả `preloadResponse`), không bao giờ ghi bộ đệm — kể cả lượt tải
       tệp qua route bí danh trong scope (`…/documents/{id}/download`): response `attachment` đi
       nguyên vẹn. Trang ngoại tuyến chỉ thay một LỖI MẠNG (`fetch` từ chối); một response lỗi
       (403, 404, 500…) của máy chủ vẫn hiện nguyên.
     - Tài nguyên tĩnh công khai theo danh sách cho phép (`STATIC_PREFIXES` = config): stale-while-
       revalidate, chỉ lưu response `ok` và `basic`. `cache.put` duy nhất của tệp nằm ở đây.
     - `install` cài sẵn `PRECACHE` (trang ngoại tuyến, logo, biểu tượng — công khai) rồi
       `skipWaiting()`; `activate` xoá bộ đệm CŨ của chính app này (tiền tố riêng — hai app có thể
       chung origin) rồi `clients.claim()`. An toàn vì không có HTML nào trong bộ đệm: phiên bản mới
       thay ngay không thể trộn trang cũ với JS mới.
     - Đăng xuất và cắt phiên không cần worker làm gì: không có gì riêng tư để dọn.

    Thông báo đẩy (M12 R11, Task 7): `push` hiện đúng nội dung máy chủ đã dựng (`App\Enums\PushTopic` —
    câu chung, không gì của hồ sơ), không ghi bộ đệm nào; `notificationclick` chỉ mở URL CÙNG origin và
    NẰM TRONG scope của chính app này (`inScope`) — URL khác bị bỏ qua — và dùng lại cửa sổ app đang mở
    (`focus()` rồi `navigate()`) thay vì mở cửa sổ thứ hai. Tiêu đề, câu dự phòng và biểu tượng render
    từ PHP (`PUSH_*`), không chữ tiếng Việt nào viết cứng trong JS.

    `renotify` (Task 9 vòng sửa 1, I1): một bản ghi được đẩy nhiều lần dưới CÙNG `tag` (bốn bậc của
    một mốc hạn, câu hỏi tiếp REQ-2, đợt thu quá hạn 7 ngày một lần — `App\Enums\PushTopic`, mục
    "Cùng tag"), và tin thay một tin cùng `tag` còn trong khay thì im lặng trừ khi `renotify: true`.
    Chỉ đặt khi có `tag`: `renotify` không kèm `tag` làm `showNotification` ném `TypeError`.

    Dưới 150 dòng khi phục vụ (test đếm). JSON qua `@json` (thoát `<`, `>`, `&`, `'`, `"`).
--}}
/* VK-CRM — service worker của app {{ $scope }} (M12 R4). Sinh từ resources/views/pwa/sw-js.blade.php. */
'use strict';

const VERSION = @json($version);
const SCOPE = @json($scope);
const CACHE_PREFIX = @json($cache_prefix);
const CACHE = CACHE_PREFIX + VERSION;
const OFFLINE_URL = @json($offline_url);
const PRECACHE = @json($precache);
const STATIC_PREFIXES = @json($static_prefixes);
const PUSH_TITLE = @json($push_title);
const PUSH_BODY = @json($push_body);
const PUSH_ICON = @json($push_icon);
const PUSH_BADGE = @json($push_badge);

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => cache.addAll(PRECACHE.map((url) => new Request(url, { cache: 'reload' }))))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE).map((name) => caches.delete(name)));
    if (self.registration.navigationPreload) {
      await self.registration.navigationPreload.enable();
    }
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(fromNetwork(event));
    return;
  }

  if (STATIC_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
    event.respondWith(fromStaticCache(event));
  }
});

/* Điều hướng: mạng hoặc trang ngoại tuyến. Không đọc, không ghi bộ đệm nào khác. */
async function fromNetwork(event) {
  try {
    const preloaded = await event.preloadResponse;
    if (preloaded) return preloaded;
    return await fetch(event.request);
  } catch (error) {
    const offline = await caches.match(OFFLINE_URL, { cacheName: CACHE });
    return offline || Response.error();
  }
}

/* Tài nguyên tĩnh công khai: trả bản đã lưu ngay, làm mới ở nền. */
async function fromStaticCache(event) {
  const cache = await caches.open(CACHE);
  const cached = await cache.match(event.request);
  const refresh = fetch(event.request).then((response) => {
    if (response.ok && response.type === 'basic') {
      event.waitUntil(cache.put(event.request, response.clone()));
    }
    return response;
  });

  if (cached) {
    event.waitUntil(refresh.catch(() => undefined));
    return cached;
  }

  return refresh;
}

/* Thông báo đẩy (M12 R11). Nội dung là câu chung do máy chủ dựng (App\Enums\PushTopic): không mã hồ sơ,
   không tên. Mỗi lần đẩy phải hiện một thông báo; không đọc được nội dung thì hiện câu dự phòng.
   Tin mới thay tin cùng tag mà vẫn rung (renotify); renotify không kèm tag thì ném TypeError. */
self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = {};
  }
  const url = inScope(payload.data && payload.data.url) || SCOPE;
  event.waitUntil(self.registration.showNotification(payload.title || PUSH_TITLE, {
    body: payload.body || PUSH_BODY,
    icon: payload.icon || PUSH_ICON,
    badge: payload.badge || PUSH_BADGE,
    tag: payload.tag || undefined,
    renotify: Boolean(payload.tag),
    lang: 'vi',
    data: { url },
  }));
});

/* Chạm: chỉ mở URL cùng origin và trong scope của chính app này; đã có cửa sổ app thì dùng lại nó. */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = inScope(event.notification.data && event.notification.data.url);
  if (!url) return;
  event.waitUntil(openInApp(url));
});

async function openInApp(url) {
  const windows = await self.clients.matchAll({ type: 'window' });
  const open = windows.find((client) => inScope(client.url));
  if (!open) return self.clients.openWindow(url);
  await open.focus();
  return open.navigate(url).catch(() => self.clients.openWindow(url));
}

/* URL tuyệt đối khi `raw` cùng origin và nằm trong SCOPE (khớp theo đoạn: /portal, /portal/…), không thì null. */
function inScope(raw) {
  if (typeof raw !== 'string' || raw === '') return null;
  try {
    const url = new URL(raw, self.location.origin);
    const inside = url.pathname === SCOPE || url.pathname.startsWith(SCOPE + '/');
    return url.origin === self.location.origin && inside ? url.href : null;
  } catch (error) {
    return null;
  }
}
