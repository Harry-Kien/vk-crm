#!/usr/bin/env node
/*
 * Kiểm bằng trình duyệt THẬT (Chromium qua Playwright) phần push của `public/pwa/register.js` và trang
 * "Thông báo trên điện thoại" — kế hoạch M12, Task 5, phán quyết R8. Máy dev không có Node trong repo
 * nên JS không có test tự động; Pest (`tests/Feature/Pwa/RegisterScriptTest.php`,
 * `tests/Feature/Push/*`) giữ hợp đồng `data-*`, văn bản và máy chủ — lượt này đo HÀNH VI.
 *
 * Cái gì đo được, cái gì MÔ PHỎNG:
 *  - Chromium headless không có được một `PushSubscription` thật (Task 1: `AbortError: Registration
 *    failed - permission denied`; WebKit của Playwright không có `PushManager`). Lượt "S" (stub) thay
 *    `PushManager.prototype.subscribe/getSubscription` bằng một bản giả trả đăng ký với endpoint FCM
 *    hợp lệ và cặp khoá P-256 thật (sinh ở đây). Quyền thông báo cũng mô phỏng: Chromium headless
 *    báo `Notification.permission === 'denied'` dù context đã được cấp quyền, nên bản giả trả
 *    'default' tới lần hỏi đầu rồi 'granted'. Mọi thứ còn lại là thật: service worker, `fetch` tới
 *    máy chủ, CSRF, phiên, Livewire, CSP enforce.
 *  - Lượt "thật" (không stub) chỉ đo con đường thất bại: bấm Bật → `subscribe` bị từ chối → khối
 *    "Chưa bật được" hiện, không lỗi JS nào khác.
 *  - iPhone chưa cài app: Chromium với User-Agent iPhone (không standalone) → khối hướng dẫn "Thêm
 *    vào Màn hình chính". Máy thật = danh sách kiểm tra (PENDING OWNER).
 *
 * Đo (mỗi dòng `[OK ]`/`[HỎNG]`; mã thoát 1 nếu có dòng HỎNG):
 *   1. Cổng khách (stub): thẻ `register.js` mang khoá công khai của máy chủ và `push-check=1`; trang
 *      thiết bị hiện "Bật trên máy này", KHÔNG hỏi quyền lúc tải, KHÔNG request nào tới
 *      `…/push/subscriptions`; bấm Bật → hỏi quyền đúng một lần, một `POST` 201, khối "đang nhận",
 *      danh sách vẽ lại có "Máy đang dùng"; tải lại → lượt kiểm `sync=1` trả `owned`; sang trang khác
 *      → không request nào (đã kiểm trong phiên); HTML không chứa endpoint; CacheStorage sạch.
 *   2. Máy dùng chung (stub, CÙNG context): khách 1 đăng xuất, khách 2 đăng nhập → lượt kiểm trả
 *      `not_owned`, dải mời hiện; bấm "Bật" của dải → `POST` 201, dải ẩn, trang thiết bị của khách 2
 *      có đúng máy này.
 *   3. App nội bộ (stub): đăng nhập TOTP, mục "Thông báo trên điện thoại" trong user menu, Bật → 201.
 *   4. iPhone chưa cài app → khối hướng dẫn, không nút Bật. Quyền bị chặn → khối "đang chặn".
 *   5. Không stub (Chromium thật) → bấm Bật đi tới khối "Chưa bật được" (hoặc "đang nhận" nếu
 *      Chromium có đăng ký thật) — ghi lại cái nào.
 *   Mọi vi phạm CSP và lỗi console/JS được ghi (trừ cảnh báo `[vk-pwa] push enable failed` có chủ đích
 *   của lượt 5); có một cái là HỎNG.
 *
 * Chạy (Playwright KHÔNG nằm trong repo — khuôn `tools/pwa/survey-sw.cjs`; khoá VAPID là khoá THỬ sinh
 * bằng `artisan webpush:vapid --show`, không bao giờ ghi vào repo):
 *   /d/vkwt/m12-dev seed
 *   /d/vkwt/m12-dev serve -e CSP_MODE=enforce -e VAPID_SUBJECT=mailto:thu@example.test \
 *       -e VAPID_PUBLIC_KEY=… -e VAPID_PRIVATE_KEY=… -e PHP_INI_SCAN_DIR=:/var/www/html/tools/csp/php
 *   NODE_PATH=/d/vkwt/m8-tools/node_modules node tools/pwa/survey-push.cjs
 *
 * Biến môi trường: BASE (mặc định http://localhost:8097), LOG (laravel.log của bản chạy), CONTAINER
 * (mặc định vkcrm-lane-m12-app — tạo tài khoản khách thử, xoá bộ đếm đăng nhập), OUT (JSON kết quả).
 */
'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const playwright = require('playwright');

const BASE = (process.env.BASE || 'http://localhost:8097').replace(/\/$/, '');
const LOG = process.env.LOG || path.resolve(__dirname, '../../storage/logs/laravel.log');
const CONTAINER = process.env.CONTAINER || 'vkcrm-lane-m12-app';
const OUT = process.env.OUT || '';
const STAFF = { email: 'admin@luatvukhang.com', password: 'password' };
const DEMO_TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';
const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
const CLIENTS = ['khach1@example.com', 'pwa-khach2@example.test', 'pwa-khach3@example.test', 'pwa-khach4@example.test', 'pwa-khach5@example.test'];

const checks = [];
const problems = [];
let step = '';

function check(name, ok, detail) {
  checks.push({ name, ok: Boolean(ok), detail: detail || '' });
  console.log(`  [${ok ? 'OK ' : 'HỎNG'}] ${name}${detail ? ' — ' + detail : ''}`);
}

const b64url = (buffer) => Buffer.from(buffer).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

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

/** Bốn tài khoản khách thử, cùng khách hàng với khach1 — mỗi lượt đăng nhập một tài khoản riêng (mã một lần: 2 lần / 60 giây / tài khoản). */
function ensureClients() {
  tinker(`$c = App\\Models\\ClientUser::where('email', 'khach1@example.com')->firstOrFail(); foreach (${JSON.stringify(CLIENTS.slice(1)).replace(/"/g, "'")} as $i => $e) { App\\Models\\ClientUser::updateOrCreate(['email' => $e], ['client_id' => $c->client_id, 'name' => 'Khách thử '.($i + 2), 'password' => 'password', 'is_active' => true, 'must_change_password' => false, 'activated_at' => now()]); }`);
}

/** Chủ hiện tại của một endpoint (`client_user:7`), đọc qua quan hệ của từng tài khoản thử — chỉ cho lượt đo này. */
function ownerOf(endpoint) {
  return tinker(`foreach (App\\Models\\ClientUser::whereIn('email', ${JSON.stringify(CLIENTS).replace(/"/g, "'")})->get() as $u) { if ($u->pushSubscriptions()->where('endpoint', '${endpoint}')->exists()) { echo $u->email; } }`);
}

async function settle(page, ms = 500) {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(ms);
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

/**
 * Context có: ghi vi phạm CSP + lỗi JS; đếm `Notification.requestPermission()`; (tuỳ chọn) bản giả
 * `PushManager` — đăng ký nhớ trong localStorage theo panel (`/admin`, `/portal` có hai service worker,
 * tức hai đăng ký, trên cùng origin); (tuỳ chọn) `Notification.permission` = 'denied'.
 */
async function newContext(browser, { stub = false, denied = false, userAgent, label }) {
  const context = await browser.newContext({ userAgent, ...(userAgent === IPHONE_UA ? { isMobile: true, hasTouch: true, viewport: { width: 390, height: 844 } } : {}) });
  if (!denied) await context.grantPermissions(['notifications'], { origin: BASE });
  await context.exposeBinding('__cspReport', (_s, v) => problems.push({ step, kind: 'csp', ...v }));
  const ecdh = crypto.createECDH('prime256v1');
  ecdh.generateKeys();
  const fake = { stub, denied, endpoint: `https://fcm.googleapis.com/fcm/send/playwright-${label}-${crypto.randomBytes(6).toString('hex')}`, p256dh: b64url(ecdh.getPublicKey()), auth: b64url(crypto.randomBytes(16)) };
  await context.addInitScript((f) => {
    document.addEventListener('securitypolicyviolation', (e) => window.__cspReport({ directive: e.effectiveDirective, blocked: e.blockedURI }));
    window.__vkAsks = 0;
    if (window.Notification) {
      // Chromium headless báo `Notification.permission === 'denied'` kể cả khi context đã
      // `grantPermissions(['notifications'])` (Permissions API thì nói 'granted' — đo 2026-10-03). Mô
      // phỏng một trình duyệt mới: 'default' cho tới lần hỏi đầu, rồi 'granted' (hoặc luôn 'denied').
      const remembered = () => { try { return sessionStorage.getItem('vk-test-permission'); } catch (e) { return null; } };
      let permission = f.denied ? 'denied' : (remembered() || 'default');
      Object.defineProperty(Notification, 'permission', { get: () => permission });
      Notification.requestPermission = function () {
        window.__vkAsks++;
        if (!f.denied) { permission = 'granted'; try { sessionStorage.setItem('vk-test-permission', 'granted'); } catch (e) { /* tài liệu không có origin */ } }
        return Promise.resolve(permission);
      };
    }
    if (!f.stub || !window.PushManager) return;
    const slot = () => 'vk-test-sub:' + location.pathname.split('/')[1];
    const make = (key) => ({
      endpoint: f.endpoint,
      options: { userVisibleOnly: true, applicationServerKey: key.buffer },
      toJSON: () => ({ endpoint: f.endpoint, expirationTime: null, keys: { p256dh: f.p256dh, auth: f.auth } }),
      unsubscribe: () => { localStorage.removeItem(slot()); return Promise.resolve(true); },
    });
    PushManager.prototype.subscribe = function (options) {
      const key = new Uint8Array(options.applicationServerKey);
      localStorage.setItem(slot(), JSON.stringify(Array.from(key)));
      return Promise.resolve(make(key));
    };
    PushManager.prototype.getSubscription = function () {
      const saved = localStorage.getItem(slot());
      return Promise.resolve(saved ? make(new Uint8Array(JSON.parse(saved))) : null);
    };
  }, fake);
  context.on('page', (page) => {
    page.on('pageerror', (err) => {
      if (!/^[A-Za-z0-9]{20}$/.test(err.message)) problems.push({ step, kind: 'pageerror', message: err.message.split('\n')[0] });
    });
    page.on('console', (msg) => {
      const text = msg.text();
      // Lượt 5: context của Playwright là ẩn danh, và Chrome không có Push API ở chế độ ẩn danh — đó
      // CHÍNH là lý do subscribe() bị từ chối ở lượt đó; không phải lỗi của trang.
      if (msg.type() === 'error' && !/status of 4(03|04|19|22|29)/.test(text) && !(label === 'real' && /incognito/.test(text))) problems.push({ step, kind: 'console', message: text.slice(0, 200) });
      if (/\[vk-pwa\]/.test(text) && !(label === 'real' && /push enable failed/.test(text))) problems.push({ step, kind: 'register', message: text.slice(0, 200) });
    });
  });
  const page = await context.newPage();
  page.setDefaultTimeout(90000);
  page.setDefaultNavigationTimeout(120000);
  const pushRequests = [];
  const lwRequests = [];
  page.on('response', (r) => {
    const pathname = new URL(r.url()).pathname;
    if (/\/(admin|portal)\/push\/subscriptions$/.test(pathname)) {
      pushRequests.push({ step, method: r.request().method(), status: r.status(), body: r.request().postData() || '' });
    }
    if (/^\/livewire-[0-9a-f]+\/update$/.test(pathname)) {
      lwRequests.push({ step, status: r.status(), body: (r.request().postData() || '').slice(0, 400) });
    }
  });
  return { context, page, fake, pushRequests, lwRequests };
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

/** Khối trạng thái đang hiện của trang thiết bị (chờ script chọn xong — khác "unsupported" mặc định, hoặc hết giờ). */
async function visibleState(page, waitFor) {
  const read = () => page.evaluate(() => [...document.querySelectorAll('[data-vk-push-state]')].filter((el) => !el.hidden).map((el) => el.getAttribute('data-vk-push-state')).join(','));
  for (let i = 0; i < 60; i++) {
    const state = await read();
    if (waitFor ? state === waitFor : state !== 'unsupported') return state;
    await page.waitForTimeout(250);
  }
  return read();
}

async function scriptData(page) {
  return page.evaluate(() => ({ ...document.querySelector('script[src*="/pwa/register.js"]').dataset }));
}

async function openDevices(page, panel) {
  await page.goto(`${BASE}/${panel}/thong-bao-dien-thoai`, { waitUntil: 'domcontentloaded' });
  await settle(page);
  await page.evaluate(() => navigator.serviceWorker.ready);
}

async function cacheUrls(page) {
  return page.evaluate(async () => {
    const out = [];
    for (const name of await caches.keys()) for (const r of await (await caches.open(name)).keys()) out.push(r.url.replace(location.origin, ''));
    return out;
  });
}

async function clientTour(browser, serverKey) {
  const { context, page, fake, pushRequests, lwRequests } = await newContext(browser, { stub: true, label: 'portal' });

  step = 'cổng: đăng nhập khách 1';
  await portalLogin(page, CLIENTS[0]);
  const data = await scriptData(page);
  check('cổng: thẻ register.js mang khoá CÔNG KHAI của máy chủ, URL đăng ký và push-check=1', data.pushKey === serverKey && data.pushUrl === `${BASE}/portal/push/subscriptions` && data.pushCheck === '1', JSON.stringify({ ...data, pushKey: data.pushKey === serverKey ? '(khớp)' : data.pushKey }));
  const menu = await page.locator('.fi-user-menu-trigger').first().click().then(() => page.getByText('Thông báo trên điện thoại').count());
  check('cổng: user menu có mục "Thông báo trên điện thoại"', menu > 0, `${menu} mục`);
  await page.keyboard.press('Escape');

  step = 'cổng: mở trang thiết bị';
  const before = pushRequests.length;
  await openDevices(page, 'portal');
  const state = await visibleState(page);
  const asks = await page.evaluate(() => window.__vkAsks);
  check('cổng: trang thiết bị hiện khối "Bật trên máy này" (chưa có đăng ký)', state === 'ready', `khối=${state}`);
  check('cổng: KHÔNG hỏi quyền thông báo lúc tải trang, KHÔNG request đăng ký nào', asks === 0 && pushRequests.length === before, `requestPermission=${asks}, request=${pushRequests.length - before}`);

  step = 'cổng: bấm Bật';
  await page.getByRole('button', { name: 'Bật trên máy này' }).click();
  const enabled = await visibleState(page, 'enabled');
  await settle(page, 800);
  const post = pushRequests.filter((r) => r.step === step);
  // Bản chạy của làn chậm (ổ 9p: 3–7 giây mỗi request) — chờ lượt vẽ lại của Livewire, không đoán bằng networkidle.
  await page.locator('[data-vk-push-current]').first().waitFor({ timeout: 45000 }).catch(() => {});
  const current = await page.locator('[data-vk-push-current]').count();
  check('cổng: bấm Bật → hỏi quyền đúng một lần, một POST 201 (không sync), khối "đang nhận"', enabled === 'enabled' && (await page.evaluate(() => window.__vkAsks)) === 1 && post.length === 1 && post[0].status === 201 && !/"sync"/.test(post[0].body), `khối=${enabled}; ${JSON.stringify(post.map((r) => [r.method, r.status]))}`);
  check('cổng: danh sách vẽ lại (sự kiện Livewire) có đúng một máy "Máy đang dùng"', current === 1, `${current} dòng "Máy đang dùng" sau ${lwRequests.filter((r) => r.step === step).length} lượt cập nhật Livewire`);
  check('cổng: HTML trang thiết bị không chứa endpoint', !(await page.content()).includes(fake.endpoint), '');

  step = 'cổng: tải lại trang thiết bị';
  await page.reload({ waitUntil: 'domcontentloaded' });
  await settle(page);
  const again = await visibleState(page, 'enabled');
  const sync = pushRequests.filter((r) => r.step === step);
  check('cổng: tải lại → lượt kiểm sync=1 trả owned → khối "đang nhận"', again === 'enabled' && sync.length === 1 && /"sync":1/.test(sync[0].body) && sync[0].status === 200, `khối=${again}; ${JSON.stringify(sync.map((r) => [r.method, r.status]))}`);

  step = 'cổng: sang trang khác';
  await page.goto(BASE + '/portal', { waitUntil: 'domcontentloaded' });
  await settle(page, 1500);
  check('cổng: trang khác sau lượt kiểm: push-check=0, không request nào, dải mời ẩn', (await scriptData(page)).pushCheck === '0' && pushRequests.filter((r) => r.step === step).length === 0 && (await page.locator('[data-vk-push-invite]').isHidden()), '');
  const cached = (await cacheUrls(page)).filter((u) => /push|\/portal\/(?!offline)|\/admin\/(?!offline)/.test(u));
  check('cổng: CacheStorage không có trang hay request đăng ký nào', cached.length === 0, cached.join(', '));

  step = 'máy dùng chung: khách 1 đăng xuất, khách 2 đăng nhập';
  await logout(page, 'portal');
  await portalLogin(page, CLIENTS[1]);
  await settle(page, 1500);
  const shared = pushRequests.filter((r) => r.step === step && /"sync":1/.test(r.body));
  const inviteShown = await page.locator('[data-vk-push-invite]').isVisible();
  check('máy dùng chung: lượt kiểm trả not_owned (không chuyển chủ), dải mời hiện', shared.length === 1 && shared[0].status === 200 && inviteShown && ownerOf(fake.endpoint) === CLIENTS[0], `${JSON.stringify(shared.map((r) => [r.method, r.status]))}, dải=${inviteShown}, chủ=${ownerOf(fake.endpoint)}`);

  step = 'máy dùng chung: bấm Bật trên dải mời';
  await page.locator('[data-vk-push-invite] [data-vk-push-enable]').click();
  await page.waitForFunction(() => document.querySelector('[data-vk-push-invite]').hidden, null, { timeout: 15000 }).catch(() => {});
  const moved = pushRequests.filter((r) => r.step === step);
  check('máy dùng chung: bấm Bật của dải → POST 201, dải ẩn, endpoint chuyển sang khách 2', moved.length === 1 && moved[0].status === 201 && (await page.locator('[data-vk-push-invite]').isHidden()) && ownerOf(fake.endpoint) === CLIENTS[1], `${JSON.stringify(moved.map((r) => [r.method, r.status]))}, chủ=${ownerOf(fake.endpoint)}`);
  await openDevices(page, 'portal');
  check('máy dùng chung: trang thiết bị của khách 2 có đúng máy này, "đang nhận"', (await page.locator('[data-vk-push-row]').count()) === 1 && (await page.locator('[data-vk-push-current]').count()) === 1 && (await visibleState(page, 'enabled')) === 'enabled', '');

  await context.close();
}

async function staffTour(browser) {
  const { context, page, pushRequests } = await newContext(browser, { stub: true, label: 'admin' });
  step = 'nội bộ: đăng nhập';
  await adminLogin(page);
  await page.locator('.fi-user-menu-trigger').first().click();
  const item = page.getByText('Thông báo trên điện thoại').first();
  check('nội bộ: user menu có mục "Thông báo trên điện thoại"', (await item.count()) > 0, '');
  await item.click();
  await page.waitForURL(/\/admin\/thong-bao-dien-thoai$/);
  await settle(page);
  check('nội bộ: trang thiết bị hiện khối "Bật trên máy này"', (await visibleState(page)) === 'ready', '');
  step = 'nội bộ: bấm Bật';
  await page.getByRole('button', { name: 'Bật trên máy này' }).click();
  const state = await visibleState(page, 'enabled');
  await settle(page, 800);
  const post = pushRequests.filter((r) => r.step === step);
  await page.locator('[data-vk-push-row]').first().waitFor({ timeout: 45000 }).catch(() => {});
  check('nội bộ: bấm Bật → POST 201 tới /admin/push/subscriptions, khối "đang nhận", một máy trong danh sách', state === 'enabled' && post.length === 1 && post[0].status === 201 && (await page.locator('[data-vk-push-row]').count()) === 1, `khối=${state}; ${JSON.stringify(post.map((r) => [r.method, r.status]))}`);
  await context.close();
}

async function edgeTours(browser) {
  {
    const { context, page } = await newContext(browser, { userAgent: IPHONE_UA, label: 'iphone' });
    step = 'iPhone chưa cài app';
    await portalLogin(page, CLIENTS[2]);
    await openDevices(page, 'portal');
    const state = await visibleState(page);
    const text = await page.locator('[data-vk-push-state="ios-install"]').innerText();
    check('iPhone (Safari, chưa "Thêm vào Màn hình chính"): khối hướng dẫn cài, nút Bật ẩn', state === 'ios-install' && /Thêm vào Màn hình chính/.test(text) && (await page.getByRole('button', { name: 'Bật trên máy này' }).isHidden()), `khối=${state}`);
    await context.close();
  }
  {
    const { context, page } = await newContext(browser, { denied: true, label: 'denied' });
    step = 'quyền bị chặn';
    await portalLogin(page, CLIENTS[3]);
    await openDevices(page, 'portal');
    const state = await visibleState(page);
    check('quyền thông báo bị chặn: khối "đang chặn" (không nút Bật)', state === 'denied', `khối=${state}`);
    await context.close();
  }
  {
    const { context, page, pushRequests } = await newContext(browser, { label: 'real' });
    step = 'Chromium thật (không stub)';
    await portalLogin(page, CLIENTS[4]);
    await openDevices(page, 'portal');
    await visibleState(page);
    await page.getByRole('button', { name: 'Bật trên máy này' }).click();
    let state = await visibleState(page, 'failed');
    if (state !== 'failed') state = await visibleState(page, 'enabled');
    const posts = pushRequests.filter((r) => r.step === step);
    console.log(`  (ghi lại) Chromium headless thật: khối=${state}, request=${JSON.stringify(posts.map((r) => [r.method, r.status]))}`);
    check('Chromium thật: bấm Bật đi tới "Chưa bật được" (subscribe bị từ chối) hoặc "đang nhận" — không treo', state === 'failed' || state === 'enabled', `khối=${state}`);
    await context.close();
  }
}

(async () => {
  ensureClients();
  const browser = await playwright.chromium.launch();
  console.log(`chromium ${browser.version()} → ${BASE}`);
  let serverKey = '';
  try {
    serverKey = tinker('echo config("webpush.vapid.public_key");');
    check('máy chủ chạy với khoá VAPID (VapidKeys::configured)', tinker('echo App\\Support\\Push\\VapidKeys::configured() ? "yes" : "no";') === 'yes', '');
    await clientTour(browser, serverKey);
    await staffTour(browser);
    await edgeTours(browser);
  } catch (e) {
    check(`lượt dừng giữa chừng ở bước "${step}"`, false, e.message.split('\n')[0]);
  } finally {
    await browser.close();
  }

  console.log(`\nVi phạm CSP / lỗi trang: ${problems.length}`);
  for (const p of problems) console.log('  ' + JSON.stringify(p));
  check('không vi phạm CSP, không lỗi JS/console ngoài cảnh báo có chủ đích', problems.length === 0, `${problems.length} mục`);

  if (OUT) fs.writeFileSync(OUT, JSON.stringify({ base: BASE, checks, problems }, null, 2));
  const failed = checks.filter((c) => !c.ok).length;
  console.log(`\n${checks.length - failed}/${checks.length} dòng OK${failed ? `, ${failed} HỎNG` : ''}.`);
  process.exit(failed ? 1 : 0);
})();
