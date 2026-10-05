#!/usr/bin/env node
/*
 * Nghiệm thu TỰ ĐỘNG của M12 (kế hoạch M12, Task 10; phán quyết 3 của controller: phần máy thật là
 * danh sách kiểm tra `docs/research/2026-10-01-pwa-kiem-tra-may-that.md`, PENDING OWNER). Bổ sung cho
 * ba lượt đã có, chạy lại trên bản cuối của nhánh: `tools/pwa/survey-sw.cjs` (Review Focus 1 và 5:
 * CacheStorage, nộp/tải/chuyển giai đoạn/đăng xuất với worker đang chạy, ngoại tuyến),
 * `tools/pwa/survey-push.cjs` (bật/gỡ/đăng xuất, máy dùng chung), `tools/pwa/survey-sw-push.cjs`
 * (trình nghe `push` của worker thật qua CDP). Lượt này đo những gì ba lượt kia không đo:
 *
 *   1. Cài được (Chromium, CDP): `Page.getAppManifest` không lỗi phân tích và
 *      `Page.getInstallabilityErrors` RỖNG cho CẢ HAI app; `id`/`scope`/`start_url` là `/{panel}` (không
 *      dấu `/` cuối), `display` standalone, `theme_color` navy, tên đúng, biểu tượng 192/512 + maskable
 *      tải được. (Không dùng điểm PWA của Lighthouse — kế hoạch.)
 *   2. iPhone chưa cài app (WebKit thật, `devices['iPhone 13']`, không standalone): trang "Thông báo
 *      trên điện thoại" hiện khối hướng dẫn "Thêm vào Màn hình chính", không nút Bật — ở cả hai app.
 *   3. Android (Chromium, `devices['Pixel 7']`, context BỀN — ở context ẩn danh Chrome tắt Push API): trang
 *      thiết bị hiện nút Bật, không hỏi quyền lúc tải; bấm Bật với quyền ĐÃ cấp và KHÔNG giả `PushManager`
 *      → đăng ký THẬT với FCM (endpoint trên `jmt17.google.com`, đo 2026-10-04), máy chủ nhận (201), đúng
 *      một dòng `push_subscriptions`; rồi "Gửi thông báo thử" qua FCM thật và ghi lại thông báo service
 *      worker hiện ra (GHI NHẬN — phụ thuộc kết nối GCM của Chromium headless). Ngoại tuyến → trang
 *      ngoại tuyến.
 *   4. Đăng xuất rồi bấm nút Back (máy dùng chung — rà soát Task 3 Minor 2, Task 6 Minor 8): đọc
 *      `Cache-Control` của trang hồ sơ, rồi xem Back có dựng lại trang hồ sơ của người vừa đăng xuất
 *      không — Chromium với bộ nhớ đệm Back/Forward BẬT (Playwright mặc định tắt nó bằng
 *      `--disable-back-forward-cache`; điện thoại thật thì bật), cổng khách và app nội bộ, và WebKit
 *      iPhone. HỎNG khi trang đã đăng nhập thiếu `no-store` hoặc Back dựng lại được trang hồ sơ.
 *   5. App nội bộ ở bề ngang 390 điểm ảnh (WebKit `devices['iPhone 13']`, 390×844): danh sách vụ việc,
 *      trang vụ việc, form chuyển giai đoạn, tab Mốc thời hạn — bấm THẬT từng nút cần cho việc, và
 *      khẳng định nó không bị che (`elementFromPoint` ở tâm nút là chính nút). Chỗ xấu mà vẫn bấm được
 *      không HỎNG (ghi bằng ảnh chụp); chỉ chỗ chặn thao tác mới HỎNG.
 *   6. CacheStorage sau mọi lượt trên: chỉ trang ngoại tuyến + tài nguyên tĩnh công khai.
 *   7. WebKit "Desktop Safari" của Playwright với và không với `PushManager` — đo một hiện tượng của bản
 *      WebKit cho Windows (xem `desktopWebkitPushProbe()`), in "GHI NHẬN".
 *   Ảnh cho hướng dẫn cài app của khách (`docs/QUY-TRINH.md`, ghi "mô phỏng"): DOCS_SHOTS.
 *
 * Chạy (Playwright KHÔNG nằm trong repo — khuôn `tools/pwa/survey-sw.cjs`; khoá VAPID là khoá THỬ
 * sinh bằng `artisan webpush:vapid --show`, không bao giờ ghi vào repo):
 *   /d/vkwt/m12-dev seed
 *   /d/vkwt/m12-dev serve -e CSP_MODE=enforce -e VAPID_SUBJECT=mailto:thu@example.test \
 *       -e VAPID_PUBLIC_KEY=… -e VAPID_PRIVATE_KEY=… -e PHP_INI_SCAN_DIR=:/var/www/html/tools/csp/php
 *   CHANNEL=chromium NODE_PATH=/d/vkwt/m8-tools/node_modules node tools/pwa/acceptance.cjs
 *
 * Biến môi trường: BASE (mặc định http://localhost:8097), LOG (laravel.log của bản chạy), CONTAINER
 * (mặc định vkcrm-lane-m12-app), CHANNEL (kênh Chromium, `chromium` = headless mới), SHOTS (ảnh của
 * lượt đo), DOCS_SHOTS (ba ảnh cho tài liệu), OUT (JSON kết quả).
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const playwright = require('playwright');

const BASE = (process.env.BASE || 'http://localhost:8097').replace(/\/$/, '');
const LOG = process.env.LOG || path.resolve(__dirname, '../../storage/logs/laravel.log');
const CONTAINER = process.env.CONTAINER || 'vkcrm-lane-m12-app';
const CHANNEL = process.env.CHANNEL || undefined;
const SHOTS = process.env.SHOTS || '';
const DOCS_SHOTS = process.env.DOCS_SHOTS || '';
const OUT = process.env.OUT || '';

const STAFF = { email: 'admin@luatvukhang.com', password: 'password' };
/** `Database\Seeders\DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET` (chỉ gán ở local/testing). */
const DEMO_TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';
/** Tài khoản khách thử riêng của lượt này (cùng khách hàng với khach1) — mã một lần: 2 / 60 giây / tài khoản. */
const CLIENTS = ['pwa-nt1@example.test', 'pwa-nt2@example.test', 'pwa-nt3@example.test', 'pwa-nt4@example.test', 'pwa-nt5@example.test', 'pwa-nt6@example.test'];
const STATIC_PREFIXES = ['/css/filament/', '/js/filament/', '/fonts/filament/', '/brand/', '/pwa/'];
const EXPECTED = {
  portal: { name: 'Luật Vũ Khang — Khách hàng', short_name: 'Luật Vũ Khang' },
  admin: { name: 'Luật Vũ Khang — Nội bộ', short_name: 'VK Nội bộ' },
};
const NAVY = '#101d35';

const checks = [];
const notes = [];
const problems = [];
let step = '';

function check(name, ok, detail) {
  checks.push({ name, ok: Boolean(ok), detail: detail || '' });
  console.log(`  [${ok ? 'OK ' : 'HỎNG'}] ${name}${detail ? ' — ' + detail : ''}`);
}

/** Phép đo không có đáp án đúng/sai sẵn trong kế hoạch — in ra để người đọc quyết. */
function note(name, detail) {
  notes.push({ name, detail });
  console.log(`  [GHI NHẬN] ${name} — ${detail}`);
}

function base32Decode(input) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of input.toUpperCase().replace(/[^A-Z2-7]/g, '')) bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

function totp(secret) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hmac = crypto.createHmac('sha1', base32Decode(secret)).update(counter).digest();
  const o = hmac[hmac.length - 1] & 0x0f;
  const code = (((hmac[o] & 0x7f) << 24) | ((hmac[o + 1] & 0xff) << 16) | ((hmac[o + 2] & 0xff) << 8) | (hmac[o + 3] & 0xff)) % 1e6;
  return String(code).padStart(6, '0');
}

function artisan(...args) {
  return execFileSync('docker', ['exec', CONTAINER, 'php', 'artisan', ...args], { stdio: 'pipe' }).toString();
}

function tinker(code) {
  return artisan('tinker', '--execute', code).trim();
}

function ensureClients() {
  tinker(`$c = App\\Models\\ClientUser::where('email', 'khach1@example.com')->firstOrFail(); foreach (${JSON.stringify(CLIENTS).replace(/"/g, "'")} as $i => $e) { App\\Models\\ClientUser::updateOrCreate(['email' => $e], ['client_id' => $c->client_id, 'name' => 'Khách nghiệm thu '.($i + 1), 'password' => 'password', 'is_active' => true, 'must_change_password' => false, 'activated_at' => now()]); }`);
}

function subscriptionsOf(email) {
  return Number(tinker(`echo App\\Models\\ClientUser::where('email', '${email}')->firstOrFail()->pushSubscriptions()->count();`));
}

async function settle(page, ms = 500) {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(ms);
}

async function shot(page, name, dir = SHOTS) {
  if (!dir) return;
  fs.mkdirSync(dir, { recursive: true });
  await page.screenshot({ path: path.join(dir, `${name}.png`) }).catch(() => {});
}

async function readLoginCode(offset) {
  for (let i = 0; i < 60; i++) {
    const size = fs.statSync(LOG).size;
    if (size > offset) {
      const fd = fs.openSync(LOG, 'r');
      const buffer = Buffer.alloc(size - offset);
      fs.readSync(fd, buffer, 0, buffer.length, offset);
      fs.closeSync(fd);
      const matches = [...buffer.toString('utf8').matchAll(/^\s*(\d{6})\s*$/gm)];
      if (matches.length) return matches[matches.length - 1][1];
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error('không thấy mã một lần trong ' + LOG);
}

/** Lỗi JS/console + vi phạm CSP của mọi trang trong context, gắn nhãn theo bước. */
async function wire(context, { allowEnableWarning = false } = {}) {
  await context.exposeBinding('__cspReport', (_s, v) => problems.push({ step, kind: 'csp', ...v }));
  await context.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (e) => {
      window.__cspReport({ directive: e.effectiveDirective, blocked: e.blockedURI, source: (e.sourceFile || '').replace(location.origin, '') });
    });
    // Trang dựng lại từ bộ nhớ đệm Back/Forward phát `pageshow` với `persisted = true`.
    window.addEventListener('pageshow', (e) => { window.__vkPersisted = e.persisted; });
  });
  context.on('page', (page) => {
    // WebKit báo một request cập nhật Livewire bị HUỶ vì trang rời đi bằng "… due to access control checks"
    // (kèm promise của Livewire bị từ chối với một đối tượng, "[object Object]") — cùng hiện tượng mà Chromium
    // báo bằng id component 20 ký tự (`tools/csp/survey.cjs`). Chỉ bỏ qua khi một lần điều hướng của khung
    // chính vừa bắt đầu trong 15 giây trước đó (máy chủ dev chậm: một lượt đăng xuất POST → 302 → trang đăng nhập
    // mất vài giây); ngoài cửa sổ đó vẫn là lỗi.
    let lastNavigation = 0;
    page.on('request', (r) => { if (r.isNavigationRequest() && r.frame() === page.mainFrame()) lastNavigation = Date.now(); });
    page.on('pageerror', (err) => {
      const message = err.message.split('\n')[0];
      if (/^[A-Za-z0-9]{20}$/.test(message)) return;
      if (Date.now() - lastNavigation < 15000 && (/livewire-[0-9a-f]+\/update due to access control checks/.test(message) || message === '[object Object]')) return;
      problems.push({ step, kind: 'pageerror', message });
    });
    page.on('console', (msg) => {
      const text = msg.text();
      if (allowEnableWarning && /\[vk-pwa\] push enable failed/.test(text)) return;
      // Bước ngoại tuyến CỐ Ý cắt mạng: trình duyệt ghi lỗi tải cho đúng lần điều hướng đó.
      if (/ngoại tuyến/.test(step) && /ERR_INTERNET_DISCONNECTED/.test(text)) return;
      if (msg.type() === 'error' && !/status of 4(03|04|19|22|29)/.test(text)) problems.push({ step, kind: 'console', message: text.split('\n')[0].slice(0, 200) });
      if (/\[vk-pwa\]/.test(text)) problems.push({ step, kind: 'register', message: text.slice(0, 200) });
    });
  });
}

async function newPage(context) {
  const page = await context.newPage();
  page.setDefaultTimeout(90000);
  page.setDefaultNavigationTimeout(120000);
  return page;
}

async function portalLogin(page, email) {
  artisan('cache:clear');
  await page.goto(BASE + '/portal/login', { waitUntil: 'domcontentloaded' });
  await settle(page);
  const offset = fs.existsSync(LOG) ? fs.statSync(LOG).size : 0;
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  const codeInput = page.locator('input[autocomplete="one-time-code"]').first();
  await codeInput.waitFor({ state: 'visible' });
  await settle(page);
  const code = await readLoginCode(offset);
  await codeInput.click();
  await page.keyboard.type(code, { delay: 30 });
  await settle(page, 300);
  if (!/\/portal\/?$/.test(new URL(page.url()).pathname)) await page.locator('button[type="submit"]:visible').first().click().catch(() => {});
  await page.waitForURL(/\/portal\/?$/);
  await settle(page);
}

async function adminLogin(page) {
  artisan('cache:clear');
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await settle(page);
  await page.fill('input[type="email"]', STAFF.email);
  await page.fill('input[type="password"]', STAFF.password);
  await page.click('button[type="submit"]');
  const codeInput = page.locator('input[autocomplete="one-time-code"]').first();
  await codeInput.waitFor({ state: 'visible' });
  await settle(page);
  await codeInput.click();
  await page.keyboard.type(totp(DEMO_TWO_FACTOR_SECRET), { delay: 30 });
  await settle(page, 300);
  if (!/\/admin\/?$/.test(new URL(page.url()).pathname)) await page.locator('button[type="submit"]:visible').first().click().catch(() => {});
  await page.waitForURL(/\/admin\/?$/);
  await settle(page);
}

async function logout(page, panel) {
  await page.locator('.fi-user-menu-trigger').first().click();
  await page.getByRole('button', { name: 'Đăng xuất' }).or(page.getByRole('menuitem', { name: 'Đăng xuất' })).first().click();
  await page.waitForURL(new RegExp(`/${panel}/login`));
  await settle(page);
}

async function visibleState(page, waitFor) {
  const read = () => page.evaluate(() => [...document.querySelectorAll('[data-vk-push-state]')].filter((el) => !el.hidden).map((el) => el.getAttribute('data-vk-push-state')).join(','));
  for (let i = 0; i < 80; i++) {
    const state = await read();
    if (waitFor ? state === waitFor : state !== 'unsupported') return state;
    await page.waitForTimeout(250);
  }
  return read();
}

async function openDevices(page, panel) {
  await page.goto(`${BASE}/${panel}/thong-bao-dien-thoai`, { waitUntil: 'domcontentloaded' });
  await settle(page);
  await page.evaluate(() => Promise.race([navigator.serviceWorker.ready, new Promise((r) => setTimeout(r, 30000))]));
}

async function cacheEntries(page) {
  return page.evaluate(async () => {
    const out = [];
    for (const name of await caches.keys()) {
      const cache = await caches.open(name);
      for (const request of await cache.keys()) {
        const response = await cache.match(request);
        out.push({ cache: name, url: request.url.replace(location.origin, ''), type: response ? response.headers.get('content-type') : null });
      }
    }
    return out;
  });
}

function auditCache(label, entries) {
  const offline = ['/portal/offline', '/admin/offline'];
  const bad = entries.filter((e) => {
    const pathOnly = e.url.split('?')[0];
    if (offline.includes(pathOnly)) return false;
    if (/^\/(portal|admin)\//.test(pathOnly)) return true;
    if (/\/livewire-|\/livewire\/|\/documents\//.test(pathOnly)) return true;
    if (/text\/html|application\/json/.test(e.type || '')) return true;
    return !STATIC_PREFIXES.some((p) => pathOnly.startsWith(p));
  });
  check(`CacheStorage sau ${label}: chỉ trang ngoại tuyến + tài nguyên tĩnh công khai`, bad.length === 0,
    bad.length ? 'LỌT: ' + bad.map((e) => e.url).join(', ') : `${entries.length} mục`);
}

/** Tâm của phần tử (sau khi cuộn tới) có trúng CHÍNH nó không — tức không bị gì che, nằm trong bề ngang màn hình. */
async function reachable(locator) {
  await locator.scrollIntoViewIfNeeded();
  return locator.evaluate((el) => {
    const r = el.getBoundingClientRect();
    const x = r.left + r.width / 2;
    const y = r.top + r.height / 2;
    const hit = document.elementFromPoint(x, y);
    return {
      ok: Boolean(hit) && (hit === el || el.contains(hit)) && r.left >= -1 && r.right <= window.innerWidth + 1,
      box: [Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)],
      viewport: window.innerWidth,
      hit: hit ? `${hit.tagName.toLowerCase()}.${String(hit.className).split(' ').slice(0, 2).join('.')}` : null,
    };
  });
}

/** Trang có bị kéo ngang không (toàn trang, không tính bảng tự cuộn bên trong). Xấu, không chặn. */
async function pageOverflow(page) {
  return page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth }));
}

// ---------------------------------------------------------------------------------------------
// 1. Cài được — CDP của Chromium
// ---------------------------------------------------------------------------------------------
async function installability() {
  // Context BỀN (có thư mục hồ sơ người dùng): context thường của Playwright là ẩn danh, và Chromium
  // trả lỗi cài đặt `in-incognito` cho MỌI trang ở chế độ đó — không phải lỗi của manifest.
  const context = await playwright.chromium.launchPersistentContext(fs.mkdtempSync(path.join(os.tmpdir(), 'vk-pwa-')), { channel: CHANNEL });
  await wire(context);
  const page = await newPage(context);
  const cdp = await context.newCDPSession(page);

  for (const panel of ['portal', 'admin']) {
    step = `${panel}: cài được`;
    await page.goto(`${BASE}/${panel}/login`, { waitUntil: 'domcontentloaded' });
    await settle(page, 1500);
    const manifest = await cdp.send('Page.getAppManifest');
    const installability = await cdp.send('Page.getInstallabilityErrors');
    const data = manifest.data ? JSON.parse(manifest.data) : {};
    const parsed = manifest.manifest || {};

    check(`${panel}: Page.getAppManifest phân tích sạch (không lỗi)`, Boolean(manifest.data) && manifest.errors.length === 0,
      `${manifest.url.replace(BASE, '')}; lỗi=${JSON.stringify(manifest.errors)}`);
    check(`${panel}: Page.getInstallabilityErrors rỗng`, installability.installabilityErrors.length === 0, JSON.stringify(installability.installabilityErrors));
    check(`${panel}: id, scope, start_url là /${panel} (không dấu / cuối); Chromium phân tích ra đúng scope`,
      data.id === `/${panel}` && data.scope === `/${panel}` && data.start_url === `/${panel}` && (!parsed.scope || parsed.scope === `${BASE}/${panel}`),
      `id=${data.id} scope=${data.scope} start_url=${data.start_url}; đã phân tích: scope=${parsed.scope} id=${parsed.id}`);
    check(`${panel}: display standalone, theme_color navy ${NAVY}, lang vi`, data.display === 'standalone' && data.theme_color === NAVY && data.lang === 'vi',
      `display=${data.display} theme=${data.theme_color} lang=${data.lang}`);
    check(`${panel}: tên "${EXPECTED[panel].name}", tên ngắn "${EXPECTED[panel].short_name}"`,
      data.name === EXPECTED[panel].name && data.short_name === EXPECTED[panel].short_name, `${data.name} / ${data.short_name}`);

    const icons = data.icons || [];
    const sizes = (purpose) => icons.filter((i) => (i.purpose || 'any').split(' ').includes(purpose)).map((i) => i.sizes);
    const fetched = await page.evaluate(async (srcs) => Promise.all(srcs.map(async (src) => {
      const r = await fetch(src, { cache: 'no-store' });
      return `${r.status} ${r.headers.get('content-type')}`;
    })), icons.map((i) => i.src));
    check(`${panel}: biểu tượng 192 + 512 (any) và 512 maskable, tải được`,
      sizes('any').includes('192x192') && sizes('any').includes('512x512') && sizes('maskable').includes('512x512') && fetched.every((f) => f === '200 image/png'),
      `any=${sizes('any')} maskable=${sizes('maskable')}; ${fetched.join(', ')}`);

    const themeMeta = await page.locator('meta[name="theme-color"]').getAttribute('content');
    check(`${panel}: thẻ <meta name="theme-color"> là navy (thanh trạng thái)`, themeMeta === NAVY, themeMeta);
  }
  await context.close();
}

// ---------------------------------------------------------------------------------------------
// 2. iPhone chưa cài app — WebKit thật
// ---------------------------------------------------------------------------------------------
async function iphoneNotInstalled(webkit) {
  const context = await webkit.newContext({ ...playwright.devices['iPhone 13'] });
  await wire(context);
  const page = await newPage(context);
  step = 'iPhone (WebKit): cổng khách, chưa cài app';
  await portalLogin(page, CLIENTS[0]);
  await openDevices(page, 'portal');
  const state = await visibleState(page);
  const text = await page.locator('[data-vk-push-state="ios-install"]').innerText().catch(() => '');
  const enableVisible = await page.getByRole('button', { name: 'Bật trên máy này' }).isVisible().catch(() => false);
  const standalone = await page.evaluate(() => navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches);
  await shot(page, 'iphone-chua-cai', DOCS_SHOTS);
  await shot(page, 'webkit-portal-ios-install');
  check('iPhone (WebKit 390×844, Safari thường): khối hướng dẫn "Thêm vào Màn hình chính", không nút Bật',
    !standalone && state === 'ios-install' && /Thêm vào Màn hình chính/.test(text) && /đăng nhập lại một lần/.test(text) && !enableVisible,
    `standalone=${standalone}; khối=${state}; "${text.replace(/\s+/g, ' ').slice(0, 140)}"`);
  await context.close();
}

// ---------------------------------------------------------------------------------------------
// 3. Android — Chromium với quyền đã cấp, KHÔNG giả PushManager
// ---------------------------------------------------------------------------------------------
async function androidEnable() {
  // Context BỀN: ở context ẩn danh (mặc định của Playwright) Chrome tắt hẳn Push API ("Chrome currently does not
  // support the Push API in incognito mode") — đo như vậy là đo chế độ ẩn danh, không phải điện thoại.
  const { defaultBrowserType, ...pixel } = playwright.devices['Pixel 7'];
  const context = await playwright.chromium.launchPersistentContext(fs.mkdtempSync(path.join(os.tmpdir(), 'vk-pwa-')),
    { channel: CHANNEL, ...pixel, permissions: ['notifications'] });
  await wire(context, { allowEnableWarning: true });
  const page = await newPage(context);
  const pushRequests = [];
  page.on('response', (r) => { if (/\/push\/subscriptions/.test(r.url())) pushRequests.push(`${r.request().method()} ${r.status()}`); });
  page.on('console', (msg) => { if (/\[vk-pwa\] push enable failed/.test(msg.text())) note('Android (Chromium): lỗi của subscribe() mà register.js ghi', msg.text().slice(0, 200)); });

  step = 'Android (Chromium): trang thiết bị';
  await portalLogin(page, CLIENTS[1]);
  await openDevices(page, 'portal');
  const permission = await page.evaluate(() => Notification.permission);
  const state = await visibleState(page);
  await shot(page, 'android-bat-thong-bao', DOCS_SHOTS);
  check('Android (Chromium, quyền đã cấp): trang thiết bị hiện nút "Bật trên máy này", không request đăng ký nào lúc tải',
    state === 'ready' && (await page.getByRole('button', { name: 'Bật trên máy này' }).isVisible()) && pushRequests.length === 0,
    `Notification.permission=${permission}; khối=${state}; request=${JSON.stringify(pushRequests)}`);

  step = 'Android (Chromium): bấm Bật, không giả PushManager';
  const before = subscriptionsOf(CLIENTS[1]);
  await page.getByRole('button', { name: 'Bật trên máy này' }).click();
  let after = await visibleState(page, 'enabled');
  if (after !== 'enabled') after = await visibleState(page, 'failed');
  const rows = subscriptionsOf(CLIENTS[1]);
  note('Android (Chromium của Playwright): bấm Bật với quyền thật, không giả PushManager',
    `khối=${after}; dòng push_subscriptions của tài khoản: ${before} → ${rows}; request=${JSON.stringify(pushRequests)}`);
  // Chromium thật (context không ẩn danh) có đăng ký push THẬT với FCM: endpoint trên `jmt17.google.com`. Trước
  // Task 10 máy chủ trả 422 cho tên máy đó (không có trong `vkcrm.pwa.push_hosts`) — đây là phép đo giữ lỗi đó.
  check('Android (Chromium thật, không giả): bấm Bật → "đang nhận" và đúng một dòng push_subscriptions (endpoint FCM thật được nhận)',
    after === 'enabled' && rows === before + 1, `khối=${after}, dòng ${before} → ${rows}`);

  if (after === 'enabled') {
    // Một lần đẩy THẬT qua FCM tới chính trình duyệt này: "Gửi thông báo thử" → rút hàng `push` một lượt trong
    // container → đọc thông báo mà service worker đã hiện. Không có kết nối GCM (headless) thì ghi đúng điều đó.
    step = 'Android (Chromium): gửi thử THẬT qua FCM';
    await page.getByRole('button', { name: 'Gửi thông báo thử' }).click();
    await page.waitForLoadState('domcontentloaded');
    await settle(page);
    artisan('queue:work', '--queue=push', '--once', '--stop-when-empty');
    const outbound = tinker("$m = App\\Models\\OutboundMessage::withoutGlobalScopes()->where('channel', 'push')->latest('id')->first(); echo $m ? $m->status->value.' '.substr((string) $m->error, 0, 120) : 'none';");
    let shown = [];
    for (let i = 0; i < 20 && shown.length === 0; i++) {
      await page.waitForTimeout(1500);
      shown = await page.evaluate(async () => (await (await navigator.serviceWorker.ready).getNotifications()).map((n) => `${n.title} | ${n.body}`));
    }
    note('Android (Chromium): một lần đẩy THẬT qua FCM (gửi thử)', `dòng outbound_messages kênh push: ${outbound}; thông báo service worker đã hiện: ${JSON.stringify(shown)}`);
  }

  step = 'Android (Chromium): ngoại tuyến';
  const matter = (await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')))).find((h) => /\/portal\/ho-so\/\d+$/.test(h || ''));
  await page.goto(BASE + '/portal', { waitUntil: 'domcontentloaded' });
  await settle(page);
  const target = matter || (await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')))).find((h) => /\/portal\/ho-so\/\d+$/.test(h || '')) || '/portal';
  await context.setOffline(true);
  await page.goto(new URL(target, BASE).toString(), { waitUntil: 'domcontentloaded' }).catch(() => {});
  await settle(page, 300);
  const heading = await page.locator('h1').first().innerText().catch(() => '');
  await shot(page, 'ngoai-tuyen', DOCS_SHOTS);
  check('Android (Chromium): chế độ máy bay → trang "Chưa có kết nối mạng"', heading.includes('Chưa có kết nối mạng'), `h1="${heading}" (${new URL(target, BASE).pathname})`);
  await context.setOffline(false);

  auditCache('lượt Android (đăng nhập → thiết bị → bật → ngoại tuyến)', await cacheEntries(page));
  await context.close();
}

// ---------------------------------------------------------------------------------------------
// 4. Đăng xuất rồi Back — bộ nhớ đệm Back/Forward và bộ đệm HTTP
// ---------------------------------------------------------------------------------------------
async function backAfterLogout(browserType, label, launchOptions, contextOptions, panel, email) {
  const browser = await browserType.launch(launchOptions);
  const context = await browser.newContext(contextOptions);
  await wire(context);
  const page = await newPage(context);
  try {
    step = `${label}: đăng xuất rồi Back`;
    let matter;
    if (panel === 'portal') {
      await portalLogin(page, email);
      matter = (await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')))).find((h) => /\/portal\/ho-so\/\d+$/.test(h || ''));
    } else {
      await adminLogin(page);
      await page.goto(BASE + '/admin/matters', { waitUntil: 'domcontentloaded' });
      await settle(page);
      matter = (await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')))).find((h) => /\/admin\/matters\/\d+$/.test(h || ''));
    }
    const response = await page.goto(new URL(matter, BASE).toString(), { waitUntil: 'domcontentloaded' });
    await settle(page);
    const cacheControl = response.headers()['cache-control'];
    const marker = (await page.locator('h1').first().innerText()).trim();
    note(`${label}: Cache-Control của trang hồ sơ ${new URL(matter, BASE).pathname}`, String(cacheControl));

    await logout(page, panel);
    await page.goBack({ waitUntil: 'domcontentloaded' }).catch(() => {});
    await settle(page, 800);
    const landed = new URL(page.url()).pathname;
    const body = await page.locator('body').innerText().catch(() => '');
    const persisted = await page.evaluate(() => window.__vkPersisted === true).catch(() => null);
    const navType = await page.evaluate(() => (performance.getEntriesByType('navigation')[0] || {}).type).catch(() => null);
    await shot(page, `${label.replace(/\W+/g, '-')}-back-after-logout`);
    const shows = body.includes(marker);
    note(`${label}: sau đăng xuất bấm Back`,
      `về ${landed}; trang hồ sơ của người vừa đăng xuất ${shows ? 'HIỆN LẠI' : 'không hiện'} (tiêu đề "${marker}"); pageshow.persisted=${persisted}; navigation.type=${navType}`);
    return { label, cacheControl, shows, persisted, landed };
  } finally {
    await context.close();
    await browser.close();
  }
}

// ---------------------------------------------------------------------------------------------
// 5. App nội bộ ở bề ngang 390 điểm ảnh — WebKit iPhone 13
// ---------------------------------------------------------------------------------------------
async function adminAt390(webkit) {
  // NO_SW=1: cùng lượt, worker bị chặn — đối chứng khi một lỗi trang chỉ thấy ở WebKit (có phải do worker không).
  const context = await webkit.newContext({ ...playwright.devices['iPhone 13'], ...(process.env.NO_SW ? { serviceWorkers: 'block' } : {}) });
  await wire(context);
  const page = await newPage(context);

  step = 'admin 390: đăng nhập';
  await adminLogin(page);

  step = 'admin 390: trang "Thông báo trên điện thoại" chưa cài';
  await openDevices(page, 'admin');
  const adminState = await visibleState(page, 'ios-install');
  const adminBlocks = await page.locator('[data-vk-push-state]').count();
  await shot(page, 'admin-390-push-devices');
  check('admin 390 (iPhone, chưa cài): trang thiết bị của app nội bộ hiện hướng dẫn "Thêm vào Màn hình chính"', adminState === 'ios-install',
    `khối=${adminState}; ${adminBlocks} khối trạng thái; trang ${new URL(page.url()).pathname}`);

  step = 'admin 390: H1 danh sách vụ việc';
  await page.goto(BASE + '/admin/matters', { waitUntil: 'domcontentloaded' });
  await settle(page);
  await shot(page, 'admin-390-h1-matters');
  const listOverflow = await pageOverflow(page);
  note('admin 390: H1 danh sách vụ việc — bề ngang trang', `scrollWidth=${listOverflow.scrollWidth} / innerWidth=${listOverflow.innerWidth}`);
  // Liên kết của DÒNG vụ việc (`/admin/matters/{id}`), không phải nút "Tạo" (`…/create`) hay "Sửa" (`…/edit`).
  const rowHref = (await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')))).find((h) => /\/admin\/matters\/\d+$/.test(h || ''));
  const rowLink = page.locator(`a[href="${rowHref}"]`).first();
  const rowReach = await reachable(rowLink);
  check('admin 390: H1 dòng đầu của danh sách vụ việc chạm được (không bị che, trong bề ngang)', rowReach.ok, JSON.stringify(rowReach));
  await rowLink.click();
  await page.waitForURL(/\/admin\/matters\/\d+(\?.*)?$/);
  await settle(page);
  const matterUrl = new URL(page.url()).pathname;
  check('admin 390: H1 chạm một vụ → trang vụ việc', /^\/admin\/matters\/\d+$/.test(matterUrl), matterUrl);

  step = 'admin 390: H2 trang vụ việc';
  await shot(page, 'admin-390-h2-matter');
  const tabs = page.locator('.fi-tabs-item');
  const tabCount = await tabs.count();
  const unreachableTabs = [];
  for (let i = 0; i < tabCount; i++) {
    const r = await reachable(tabs.nth(i));
    if (!r.ok) unreachableTabs.push(`${(await tabs.nth(i).innerText()).trim()} ${JSON.stringify(r)}`);
  }
  check(`admin 390: H2 cả ${tabCount} tab của trang vụ việc chạm được (dải tab cuộn ngang)`, tabCount > 0 && unreachableTabs.length === 0, unreachableTabs.join('; ') || `${tabCount} tab`);

  step = 'admin 390: H3 form chuyển giai đoạn';
  try {
    const progress = page.locator('.fi-tabs-item', { hasText: 'Tiến độ' }).first();
    await page.waitForFunction((el) => !el.disabled, await progress.elementHandle());
    await progress.click();
    await settle(page, 300);
    const open = page.getByRole('button', { name: 'Chuyển giai đoạn', exact: true }).first();
    const openReach = await reachable(open);
    check('admin 390: H3 nút "Chuyển giai đoạn" chạm được', openReach.ok, JSON.stringify(openReach));
    await open.click();
    const modal = page.locator('.fi-modal-window:visible').first();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await settle(page);
    const select = modal.locator('select').first();
    if (await select.count()) {
      const values = await select.locator('option').evaluateAll((os) => os.map((o) => o.value).filter(Boolean));
      await select.selectOption(values[values.length - 1]);
    } else {
      await modal.locator('.fi-select-input-btn, .fi-fo-select button').first().click();
      await page.locator('.fi-select-input-option:visible, [role="option"]:visible').last().click();
    }
    await settle(page, 300);
    const publicContent = modal.getByLabel('Nội dung công bố cho khách');
    const contentReach = await reachable(publicContent);
    check('admin 390: H3 ô "Nội dung công bố cho khách" gõ được (không bị che)', contentReach.ok, JSON.stringify(contentReach));
    if ((await publicContent.inputValue()).trim().length < 30) {
      await publicContent.fill('Văn phòng đã hoàn tất bước này và chuyển hồ sơ sang giai đoạn tiếp theo.');
      await settle(page, 300);
    }
    await shot(page, 'admin-390-h3-transition');
    const submit = modal.getByRole('button', { name: 'Gửi', exact: true });
    const submitReach = await reachable(submit);
    check('admin 390: H3 cuộn tới và chạm được nút "Gửi" của form', submitReach.ok, JSON.stringify(submitReach));
    await submit.click();
    await page.getByText('Đã chuyển giai đoạn.').first().waitFor({ timeout: 60000 });
    check('admin 390: H3 chuyển giai đoạn xong ở bề ngang 390', true, matterUrl);
  } catch (e) {
    await shot(page, 'admin-390-h3-fail');
    check('admin 390: H3 chuyển giai đoạn ở bề ngang 390', false, e.message.split('\n')[0]);
  }

  step = 'admin 390: H4 tab Mốc thời hạn';
  try {
    await page.goto(BASE + matterUrl, { waitUntil: 'domcontentloaded' });
    await settle(page);
    const tab = page.locator('.fi-tabs-item', { hasText: 'Mốc thời hạn' }).first();
    await page.waitForFunction((el) => !el.disabled, await tab.elementHandle());
    await tab.click();
    await settle(page, 300);
    const add = page.getByRole('button', { name: 'Thêm mốc thời hạn', exact: true }).first();
    const addReach = await reachable(add);
    check('admin 390: H4 nút "Thêm mốc thời hạn" chạm được', addReach.ok, JSON.stringify(addReach));
    await add.click();
    const modal = page.locator('.fi-modal-window:visible').first();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await settle(page);
    const name = `Mốc nghiệm thu 390 ${Date.now() % 100000}`;
    await modal.getByLabel('Cần làm gì').fill(name);
    const trigger = modal.locator('.fi-fo-date-time-picker-trigger').first();
    const triggerReach = await reachable(trigger);
    check('admin 390: H4 ô "Ngày đến hạn" mở được lịch', triggerReach.ok, JSON.stringify(triggerReach));
    await trigger.click();
    const panel = page.locator('.fi-fo-date-time-picker-panel:visible').first();
    await panel.waitFor({ state: 'visible', timeout: 10000 });
    const day = panel.locator('.fi-fo-date-time-picker-calendar-day', { hasText: /^28$/ }).first();
    const dayReach = await reachable(day);
    check('admin 390: H4 ngày trên lịch chạm được (lịch không tràn khỏi màn hình)', dayReach.ok, JSON.stringify(dayReach));
    await day.click();
    await settle(page, 300);
    // Người phụ trách: mặc định là luật sư phụ trách — nếu trống (người đăng nhập không phải), chọn người đầu.
    const responsible = modal.locator('.fi-fo-field', { hasText: 'Người phụ trách' }).first();
    if (/Chọn một tuỳ chọn|Select an option/.test(await responsible.innerText().catch(() => ''))) {
      await responsible.locator('.fi-select-input-btn, button').first().click();
      await page.locator('.fi-select-input-option:visible, [role="option"]:visible').first().click();
    }
    await shot(page, 'admin-390-h4-deadline-form');
    const submit = modal.getByRole('button', { name: /^(Gửi|Tạo|Lưu)/ }).first();
    const submitReach = await reachable(submit);
    check('admin 390: H4 cuộn tới và chạm được nút lưu mốc', submitReach.ok, JSON.stringify(submitReach));
    await submit.click();
    await page.getByText(name).first().waitFor({ timeout: 60000 });
    await settle(page);
    await shot(page, 'admin-390-h4-deadlines');
    check('admin 390: H4 thêm mốc thời hạn xong, bảng mốc hiện mốc mới', true, name);
  } catch (e) {
    await shot(page, 'admin-390-h4-fail');
    check('admin 390: H4 thêm mốc thời hạn ở bề ngang 390', false, e.message.split('\n')[0]);
  }

  auditCache('lượt app nội bộ 390 (WebKit)', await cacheEntries(page));
  step = 'admin 390: đăng xuất';
  await logout(page, 'admin');
  await context.close();
}

// ---------------------------------------------------------------------------------------------
// 6. WebKit "Desktop Safari" của Playwright và Push API — đo hiện tượng, không phải đáp án
// ---------------------------------------------------------------------------------------------
/** Trang có còn trả lời không: một `evaluate` rỗng, chờ tối đa `ms`. */
async function responsive(page, ms) {
  return Promise.race([page.evaluate(() => true).then(() => true, () => false), new Promise((r) => setTimeout(() => r(false), ms))]);
}

/**
 * Đo trên máy dev 2026-10-04: bản WebKit mà Playwright dựng cho Windows, hồ sơ "Desktop Safari" (không
 * phải iPhone), ĐỨNG HÌNH ngay sau trang đầu tiên đã đăng nhập — trang không trả lời `evaluate`, lần
 * điều hướng kế tiếp không bao giờ xong — khi có `PushManager`; xoá `PushManager` trước khi trang chạy
 * thì mọi thứ bình thường. Trang đầu tiên đã đăng nhập là trang `register.js` gọi
 * `pushManager.getSubscription()` cho lượt kiểm `sync=1`; với hồ sơ iPhone (chưa cài app) script dừng ở
 * khối hướng dẫn trước khi gọi Push API, nên không đứng. Lượt này giữ hai biến thể làm bằng chứng; máy
 * Mac/iPhone thật là bước D1 của danh sách kiểm tra (PENDING OWNER).
 */
async function desktopWebkitPushProbe(withPushEmail, withoutPushEmail) {
  for (const [label, email, removePush] of [['có PushManager', withPushEmail, false], ['xoá PushManager', withoutPushEmail, true]]) {
    const browser = await playwright.webkit.launch();
    const context = await browser.newContext({ ...playwright.devices['Desktop Safari'] });
    if (removePush) await context.addInitScript(() => { try { delete window.PushManager; } catch (e) { /* không xoá được thì thôi */ } });
    const page = await newPage(context);
    page.setDefaultTimeout(30000);
    page.setDefaultNavigationTimeout(30000);
    step = `WebKit Desktop Safari (${label})`;
    let result = 'không đăng nhập được';
    try {
      await portalLogin(page, email);
      await page.waitForTimeout(3000);
      const alive = await responsive(page, 20000);
      let navigated = false;
      if (alive) {
        navigated = await page.goto(`${BASE}/portal/thong-bao-dien-thoai`, { waitUntil: 'domcontentloaded' }).then(() => true, () => false);
      }
      result = `trang trả lời sau đăng nhập: ${alive}; điều hướng tiếp: ${navigated}`;
    } catch (e) {
      result += ` (${e.message.split('\n')[0].slice(0, 120)})`;
    }
    note(`WebKit Playwright (Windows), hồ sơ "Desktop Safari", ${label}`, result);
    await Promise.race([browser.close(), new Promise((r) => setTimeout(r, 15000))]);
  }
}

// Một lần treo không có hạn (trình duyệt đứng hình) không được giữ lượt đo mãi.
setTimeout(() => {
  console.log(`\n[HỎNG] lượt đo quá 30 phút, dừng ở bước "${step}"`);
  process.exit(1);
}, 30 * 60 * 1000).unref();

(async () => {
  ensureClients();
  const chromium = await playwright.chromium.launch({ channel: CHANNEL });
  const webkit = await playwright.webkit.launch();
  console.log(`chromium ${chromium.version()} (${CHANNEL || 'mặc định'}), webkit ${webkit.version()} → ${BASE}`);
  const back = [];
  // ONLY=1,5 chạy riêng vài mục (khi sửa một mục); để trống là chạy đủ — con số nghiệm thu là lượt đủ.
  const only = (process.env.ONLY || '').split(',').filter(Boolean);
  const want = (n) => only.length === 0 || only.includes(String(n));
  try {
    if (want(1)) {
      console.log('\n1. Cài được (CDP)');
      await installability();
    }
    if (want(2)) {
      console.log('\n2. iPhone chưa cài app (WebKit)');
      await iphoneNotInstalled(webkit);
    }
    if (want(3)) {
      console.log('\n3. Android (Chromium, không giả PushManager) + ngoại tuyến');
      await androidEnable();
    }
    if (want(4)) {
      console.log('\n4. Đăng xuất rồi Back');
      const bfcache = { channel: CHANNEL, ignoreDefaultArgs: ['--disable-back-forward-cache'] };
      const { defaultBrowserType: _p, ...pixel } = playwright.devices['Pixel 7'];
      const { defaultBrowserType: _i, ...iphone } = playwright.devices['iPhone 13'];
      back.push(await backAfterLogout(playwright.chromium, 'Chromium Android, bfcache BẬT, cổng khách', bfcache, pixel, 'portal', CLIENTS[2]));
      back.push(await backAfterLogout(playwright.chromium, 'Chromium, bfcache BẬT, app nội bộ', bfcache, {}, 'admin'));
      back.push(await backAfterLogout(playwright.webkit, 'WebKit iPhone 13, cổng khách', {}, iphone, 'portal', CLIENTS[3]));
      for (const b of back) {
        check(`${b.label}: trang đã đăng nhập mang Cache-Control no-store, và Back sau đăng xuất KHÔNG dựng lại trang hồ sơ`,
          /no-store/.test(b.cacheControl || '') && !b.shows && b.persisted !== true, `về ${b.landed}; ${b.cacheControl}; persisted=${b.persisted}`);
      }
    }
    if (want(5)) {
      console.log('\n5. App nội bộ ở bề ngang 390 (WebKit iPhone 13)');
      await adminAt390(webkit);
    }
    if (want(6)) {
      console.log('\n6. WebKit "Desktop Safari" của Playwright và Push API (đo hiện tượng)');
      await desktopWebkitPushProbe(CLIENTS[4], CLIENTS[5]);
    }
  } catch (e) {
    check(`lượt dừng giữa chừng ở bước "${step}"`, false, e.message.split('\n')[0]);
  } finally {
    await chromium.close();
    await webkit.close();
  }

  console.log(`\nVi phạm CSP / lỗi trang: ${problems.length}`);
  for (const p of problems) console.log('  ' + JSON.stringify(p));
  check('không vi phạm CSP, không lỗi JS/console ngoài cảnh báo có chủ đích', problems.length === 0, `${problems.length} mục`);

  if (OUT) fs.writeFileSync(OUT, JSON.stringify({ base: BASE, checks, notes, back, problems }, null, 2));
  const failed = checks.filter((c) => !c.ok).length;
  console.log(`\n${checks.length - failed}/${checks.length} dòng OK${failed ? `, ${failed} HỎNG` : ''}; ${notes.length} dòng GHI NHẬN.`);
  process.exit(failed ? 1 : 0);
})();
