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
