#!/usr/bin/env node
/*
 * Kiểm bằng trình duyệt THẬT (Chromium qua Playwright) hai trình nghe thông báo đẩy của service
 * worker — kế hoạch M12, Task 7, phán quyết R11. Máy dev không có Node trong repo nên JS không có test
 * tự động; Pest (`tests/Feature/Pwa/ServiceWorkerTest.php`) ghim văn bản và hằng số, lượt này đo
 * HÀNH VI của chính `sw.js` phục vụ ra.
 *
 * Cái gì đo được, cái gì không:
 *  - `push`: một lần đẩy được đưa THẲNG vào worker bằng CDP `ServiceWorker.deliverPushMessage` (không
 *    qua máy chủ push — Chromium headless không có đăng ký push thật, Task 5), rồi đọc lại thông báo
 *    đã hiện bằng `registration.getNotifications()`: tiêu đề, câu, `tag`, `data.url`.
 *  - `inScope()` (luật "chỉ mở URL cùng origin và trong scope" của `notificationclick`): gọi thẳng hàm
 *    của worker bằng `worker.evaluate()` với từng loại URL.
 *  - KHÔNG đo được: cú chạm thật (`notificationclick` → `focus()`/`navigate()`/`openWindow()`). Một sự
 *    kiện tổng hợp trong worker không gọi được `waitUntil()` (sự kiện không "trusted"), và `focus()`
 *    chỉ được phép trong lúc xử lý một cú chạm thật. Đó là mục D của danh sách kiểm tra máy thật
 *    (PENDING OWNER).
 *
 * Đo (mỗi dòng `[OK ]`/`[HỎNG]`; mã thoát 1 nếu có dòng HỎNG), cho CẢ HAI app (`/portal`, `/admin`):
 *   1. Worker của app đăng ký, `activated`.
 *   2. Đẩy một payload đúng hình dạng R11 (URL trong scope) → đúng một thông báo: tiêu đề, câu, `tag`
 *      như payload; `data.url` = URL tuyệt đối cùng origin.
 *   3. Đẩy với URL NGOÀI scope (`/{app kia}/…`, `/{app}x/…`, khác origin, `//khác-origin/…`) → thông báo
 *      vẫn hiện, `data.url` = scope của app (trang chính), không phải URL đã gửi.
 *   4. Đẩy dữ liệu không phải JSON, và đẩy không dữ liệu → thông báo dự phòng: tên văn phòng và câu
 *      tiếng Việt render từ PHP.
 *   5. `inScope()` của worker: trong scope → URL tuyệt đối; ngoài scope / khác origin / rỗng /
 *      `javascript:` → null.
 *   6. CacheStorage sau các lượt đẩy: không mục nào mới ngoài tài nguyên tĩnh công khai của Task 3.
 *   Mọi lỗi console/JS của trang được ghi; có một cái là HỎNG.
 *
 * `CHANNEL=chromium` là BẮT BUỘC: Chromium headless-shell mặc định của Playwright báo
 * `Notification.permission === 'denied'` dù context đã được cấp quyền (cùng hiện tượng Task 5), nên
 * `showNotification()` bị từ chối và không thông báo nào hiện; Chromium đầy đủ ở chế độ headless mới
 * thì hiện được (đo 2026-10-04: lượt mặc định 11/27, lượt `CHANNEL=chromium` đủ).
 *
 * Chạy (Playwright KHÔNG nằm trong repo — khuôn `tools/pwa/survey-sw.cjs`):
 *   /d/vkwt/m12-dev seed
 *   /d/vkwt/m12-dev serve -e CSP_MODE=enforce -e PHP_INI_SCAN_DIR=:/var/www/html/tools/csp/php
 *   CHANNEL=chromium NODE_PATH=/d/vkwt/m8-tools/node_modules node tools/pwa/survey-sw-push.cjs
 *
 * Biến môi trường: BASE (mặc định http://localhost:8097), FIRM (tên văn phòng mong đợi, mặc định
 * "Luật Vũ Khang"), FALLBACK (câu dự phòng mong đợi), CHANNEL (kênh Chromium của Playwright, ví dụ
 * `chromium` cho headless mới), OUT (JSON kết quả).
 */
'use strict';

const fs = require('fs');
const playwright = require('playwright');

const BASE = (process.env.BASE || 'http://localhost:8097').replace(/\/$/, '');
const FIRM = process.env.FIRM || 'Luật Vũ Khang';
const FALLBACK = process.env.FALLBACK || 'Có thông báo mới. Chạm để xem.';
const OUT = process.env.OUT || '';
// Bản sao của `config('vkcrm.pwa.static_prefixes')` (Pest ghim `sw.js` khớp config; đây chỉ là bộ lọc đo).
const STATIC_PREFIXES = ['/css/filament/', '/js/filament/', '/fonts/filament/', '/brand/', '/pwa/'];

const checks = [];
const errors = [];

function check(name, ok, detail) {
  checks.push({ name, ok: Boolean(ok), detail: detail || '' });
  console.log(`  [${ok ? 'OK ' : 'HỎNG'}] ${name}${detail ? ' — ' + detail : ''}`);
}

/** Chờ worker của `scope` ở trạng thái activated; trả Worker của Playwright (nếu có). */
async function waitForWorker(context, page, panel) {
  const scope = `${BASE}/${panel}`;
  const active = await page.evaluate(async (wanted) => {
    for (let i = 0; i < 120; i++) {
      const reg = await navigator.serviceWorker.getRegistration(wanted);
      if (reg && reg.active && reg.active.state === 'activated') return reg.scope;
      await new Promise((resolve) => setTimeout(resolve, 500));
    }
    return null;
  }, scope);
  check(`${panel}: worker đăng ký scope ${scope}, activated`, active === scope, `scope=${active}`);

  for (let i = 0; i < 60; i++) {
    const worker = context.serviceWorkers().find((w) => w.url() === `${scope}/sw.js`);
    if (worker) return worker;
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  return null;
}

/** Đóng mọi thông báo đang hiện của registration `scope`. */
async function clearNotifications(page, scope) {
  await page.evaluate(async (wanted) => {
    const reg = await navigator.serviceWorker.getRegistration(wanted);
    (await reg.getNotifications()).forEach((n) => n.close());
  }, scope);
}

/** Thông báo đang hiện của registration `scope`, chờ tối đa ~10 giây cho tới khi có ít nhất một. */
async function readNotifications(page, scope) {
  return page.evaluate(async (wanted) => {
    const reg = await navigator.serviceWorker.getRegistration(wanted);
    for (let i = 0; i < 40; i++) {
      const list = await reg.getNotifications();
      if (list.length > 0) {
        return list.map((n) => ({ title: n.title, body: n.body, tag: n.tag, icon: n.icon, badge: n.badge, data: n.data }));
      }
      await new Promise((resolve) => setTimeout(resolve, 250));
    }
    return [];
  }, scope);
}

async function cacheUrls(page) {
  return page.evaluate(async () => {
    const out = [];
    for (const name of await caches.keys()) {
      const cache = await caches.open(name);
      for (const request of await cache.keys()) out.push(request.url);
    }
    return out.sort();
  });
}

async function surveyPanel(context, page, cdp, registrations, panel, other) {
  const scope = `${BASE}/${panel}`;
  const scopePath = `/${panel}`;
  console.log(`\n== ${panel} ==`);

  await page.goto(`${BASE}/${panel}/login`, { waitUntil: 'load' });
  const worker = await waitForWorker(context, page, panel);
  const cachesBefore = await cacheUrls(page);

  let registrationId = null;
  for (let i = 0; i < 60 && !registrationId; i++) {
    const found = [...registrations.values()].find((r) => r.scopeURL === `${scope}` || r.scopeURL === `${scope}/`);
    registrationId = found ? found.registrationId : null;
    if (!registrationId) await new Promise((resolve) => setTimeout(resolve, 500));
  }
  check(`${panel}: CDP thấy registration của scope`, Boolean(registrationId), `id=${registrationId}`);
  if (!registrationId) return;

  const deliver = async (data) => {
    await clearNotifications(page, scope);
    const params = { origin: BASE, registrationId, data: data === undefined ? '' : data };
    await cdp.send('ServiceWorker.deliverPushMessage', params);
    return readNotifications(page, scope);
  };

  // 2. Payload đúng hình dạng R11.
  const inside = `${scopePath}/ho-so/1#tai-lieu`;
  const good = await deliver(JSON.stringify({
    title: FIRM,
    body: 'Hồ sơ của anh/chị có cập nhật mới. Chạm để xem.',
    icon: '/brand/vk-mark-192.png',
    badge: '/brand/vk-mark-96.png',
    tag: 'client.stage_update:1',
    data: { url: inside },
  }));
  check(`${panel}: một lần đẩy → đúng một thông báo`, good.length === 1, `có ${good.length}`);
  if (good.length === 1) {
    const n = good[0];
    check(`${panel}: tiêu đề, câu, tag đúng payload`, n.title === FIRM && n.body === 'Hồ sơ của anh/chị có cập nhật mới. Chạm để xem.' && n.tag === 'client.stage_update:1', JSON.stringify({ title: n.title, body: n.body, tag: n.tag }));
    check(`${panel}: data.url là URL trong scope của payload`, n.data && (n.data.url === inside || n.data.url === `${BASE}${inside}`), `data.url=${n.data && n.data.url}`);
    check(`${panel}: biểu tượng và badge cùng origin`, String(n.icon).startsWith(BASE + '/brand/') && String(n.badge).startsWith(BASE + '/brand/'), `${n.icon} | ${n.badge}`);
  }

  // 3. URL ngoài scope → về trang chính của app.
  for (const outside of [`/${other}/matters/1`, `${scopePath}x/evil`, 'https://evil.example/portal/ho-so/1', '//evil.example/portal', 'javascript:alert(1)']) {
    const list = await deliver(JSON.stringify({ title: FIRM, body: 'x', tag: 't', data: { url: outside } }));
    const url = list[0] && list[0].data ? list[0].data.url : undefined;
    check(`${panel}: URL ngoài scope "${outside}" → data.url = scope`, list.length === 1 && url === scopePath, `data.url=${url}`);
  }

  // 4. Không đọc được nội dung → câu dự phòng render từ PHP.
  for (const [label, data] of [['dữ liệu không phải JSON', 'khong-phai-json'], ['không dữ liệu', undefined]]) {
    const list = await deliver(data);
    const n = list[0] || {};
    check(`${panel}: ${label} → thông báo dự phòng tiếng Việt`, list.length === 1 && n.title === FIRM && n.body === FALLBACK && n.data && n.data.url === scopePath, JSON.stringify({ title: n.title, body: n.body, url: n.data && n.data.url }));
  }
  await clearNotifications(page, scope);

  // 5. inScope() của chính worker.
  if (worker) {
    const result = await worker.evaluate(({ base, p, o }) => ({
      root: inScope(`/${p}`),
      deep: inScope(`/${p}/ho-so/1?x=1#tai-lieu`),
      absolute: inScope(`${base}/${p}/yeu-cau/2`),
      other: inScope(`/${o}/matters/1`),
      prefix: inScope(`/${p}x/evil`),
      cross: inScope(`https://evil.example/${p}/ho-so/1`),
      protocolRelative: inScope(`//evil.example/${p}`),
      empty: inScope(''),
      nothing: inScope(undefined),
      js: inScope('javascript:alert(1)'),
    }), { base: BASE, p: panel, o: other });
    check(`${panel}: inScope() nhận URL trong scope`, result.root === `${BASE}/${panel}` && result.deep === `${BASE}/${panel}/ho-so/1?x=1#tai-lieu` && result.absolute === `${BASE}/${panel}/yeu-cau/2`, JSON.stringify([result.root, result.deep, result.absolute]));
    check(`${panel}: inScope() từ chối app kia, tiền tố giả, khác origin, rỗng, javascript:`, [result.other, result.prefix, result.cross, result.protocolRelative, result.empty, result.nothing, result.js].every((v) => v === null), JSON.stringify(result));
  } else {
    check(`${panel}: Playwright thấy Worker để gọi inScope()`, false, 'context.serviceWorkers() không có');
  }

  // 6. Không gì mới trong CacheStorage ngoài tài nguyên tĩnh công khai. Nhánh tĩnh (stale-while-revalidate,
  //    Task 3) có thể ghi NỐT một ảnh mà trang đăng nhập vừa tải SAU ảnh chụp "trước" (đo: `/brand/vk-mark-64.png`
  //    — lượt ghi nền chạy chậm hơn `load`); đó không phải việc của trình nghe `push`, nên chỉ mục ngoài danh sách
  //    tiền tố tĩnh mới là HỎNG.
  const cachesAfter = await cacheUrls(page);
  const added = cachesAfter.filter((url) => !cachesBefore.includes(url));
  const isStatic = (url) => STATIC_PREFIXES.some((prefix) => url.startsWith(BASE + prefix));
  check(`${panel}: các lượt đẩy không ghi gì ngoài tài nguyên tĩnh vào CacheStorage`, added.every(isStatic), `mới: ${added.join(', ') || '(không)'}`);
}

(async () => {
  const browser = await playwright.chromium.launch(process.env.CHANNEL ? { channel: process.env.CHANNEL } : {});
  const context = await browser.newContext();
  await context.grantPermissions(['notifications'], { origin: BASE });
  const page = await context.newPage();
  page.setDefaultTimeout(90000);
  page.setDefaultNavigationTimeout(120000);
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });

  const cdp = await context.newCDPSession(page);
  const registrations = new Map();
  cdp.on('ServiceWorker.workerRegistrationUpdated', ({ registrations: list }) => {
    for (const r of list) {
      if (r.isDeleted) registrations.delete(r.registrationId);
      else registrations.set(r.registrationId, r);
    }
  });
  await cdp.send('ServiceWorker.enable');

  const permission = await (async () => {
    await page.goto(`${BASE}/portal/login`, { waitUntil: 'load' });
    return page.evaluate(() => Notification.permission);
  })();
  console.log(`Notification.permission trong trang: ${permission}`);

  await surveyPanel(context, page, cdp, registrations, 'portal', 'admin');
  await surveyPanel(context, page, cdp, registrations, 'admin', 'portal');

  check('không lỗi console/JS nào', errors.length === 0, errors.join(' | '));

  await browser.close();

  const failed = checks.filter((c) => !c.ok);
  console.log(`\n${checks.length - failed.length}/${checks.length} OK`);
  if (OUT) fs.writeFileSync(OUT, JSON.stringify({ permission, checks, errors }, null, 2));
  process.exit(failed.length > 0 ? 1 : 0);
})().catch((error) => {
  console.error(error);
  process.exit(2);
});
