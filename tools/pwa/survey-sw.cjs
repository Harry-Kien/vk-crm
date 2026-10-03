#!/usr/bin/env node
/*
 * Kiểm bằng trình duyệt THẬT (Chromium qua Playwright) service worker của hai app trên điện thoại —
 * kế hoạch M12, Task 3, phán quyết R4 ("không bao giờ lưu cái gì riêng tư") và Review Focus 1, 5.
 * Máy dev không có Node trong repo nên JS của worker không có test tự động; lượt này là phép đo
 * hành vi, Pest (`tests/Feature/Pwa/ServiceWorkerTest.php`) giữ các hằng số và văn bản.
 *
 * Đo gì (mỗi dòng in `[OK ]`/`[HỎNG]`; mã thoát 1 nếu có một dòng HỎNG):
 *   1. Worker đăng ký ĐÚNG scope ở cả hai panel (`/portal`, `/admin` — không dấu `/` cuối), đang
 *      `activated`, và ĐIỀU KHIỂN trang (`navigator.serviceWorker.controller`) trước mọi hành động.
 *   2. Cổng khách, với worker đang điều khiển: đăng nhập (mật khẩu + mã một lần đọc từ
 *      laravel.log), mở trang hồ sơ, nộp một tệp (Livewire tải lên + gửi), tải một tài liệu qua
 *      bí danh TRONG scope `/portal/documents/{id}/download` (tệp về đủ byte), đăng xuất bằng menu.
 *   3. App nội bộ, tương tự: đăng nhập (mật khẩu + TOTP demo), chuyển giai đoạn qua form, đưa một
 *      tài liệu lên qua modal, tải một tệp qua `/admin/documents/{id}/download`, đăng xuất.
 *   4. Sau mỗi lượt: liệt kê TOÀN BỘ CacheStorage (`caches.keys()` → `cache.keys()`). Không được
 *      có URL nào dưới `/portal/` hay `/admin/` ngoài `/portal/offline`, `/admin/offline`; không
 *      URL `/livewire-…`, không `…/documents/…`; không response HTML/JSON nào ngoài hai trang
 *      ngoại tuyến; mọi URL khác nằm trong danh sách tiền tố tĩnh của config.
 *   5. Ngoại tuyến (`context.setOffline(true)`) rồi điều hướng: hiện trang ngoại tuyến (tiếng Việt,
 *      `tel:` hotline). Trực tuyến lại rồi bấm "Thử lại": về `start_url` của app.
 *   6. Tài khoản khách bị vô hiệu GIỮA phiên (`docker exec … tinker`), rồi (a) bấm một nút
 *      Livewire, (b) tải lại trang: về trang đăng nhập — và ĐỐI CHỨNG cùng kịch bản trong một
 *      context CHẶN service worker (`serviceWorkers: 'block'`) phải cho đúng cùng kết quả (trang
 *      đích, câu `portal.inactive` có hay không). Đường (b) phải hiện câu đó ở cả hai bên. Tài
 *      khoản được bật lại sau mỗi lượt, kể cả khi lỗi.
 *   Mọi vi phạm CSP (sự kiện `securitypolicyviolation`) và lỗi console/JS trên mọi trang được ghi;
 *   có một cái là HỎNG — lượt này chạy với `CSP_MODE=enforce`.
 *
 * Chạy (Playwright KHÔNG nằm trong repo — cùng khuôn `tools/csp/survey.cjs`):
 *   /d/vkwt/m12-dev seed
 *   /d/vkwt/m12-dev serve -e CSP_MODE=enforce -e PHP_INI_SCAN_DIR=:/var/www/html/tools/csp/php
 *   docker exec vkcrm-lane-m12-app php artisan cache:clear   # bộ đếm đăng nhập giữa các lượt
 *   NODE_PATH=/d/vkwt/m8-tools/node_modules node tools/pwa/survey-sw.cjs
 *
 * Biến môi trường: BASE (mặc định http://localhost:8097), LOG (laravel.log của bản chạy),
 * CONTAINER (container của bản chạy, cho bước vô hiệu tài khoản; mặc định vkcrm-lane-m12-app),
 * SHOTS (thư mục ghi ảnh chụp; trống = không chụp), OUT (ghi kết quả JSON).
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
const SHOTS = process.env.SHOTS || '';
const OUT = process.env.OUT || '';

const STAFF = { email: 'admin@luatvukhang.com', password: 'password' };
const CLIENT = { email: 'khach1@example.com', password: 'password' };
/** `Database\Seeders\DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET` (chỉ gán ở local/testing). */
const DEMO_TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';
/** `config('vkcrm.pwa.static_prefixes')` — đọc lại từ chính `sw.js` đang phục vụ, xem `staticPrefixes()`. */
let STATIC_PREFIXES = [];

const checks = [];
const problems = [];
let step = '';

function check(name, ok, detail) {
  checks.push({ name, ok: Boolean(ok), detail: detail || '' });
  console.log(`  [${ok ? 'OK ' : 'HỎNG'}] ${name}${detail ? ' — ' + detail : ''}`);
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

async function settle(page, ms = 500) {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(ms);
}

async function shot(page, name) {
  if (!SHOTS) return;
  fs.mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `task3-${name}.png`), fullPage: true }).catch(() => {});
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

/** Vi phạm CSP + lỗi JS/console của mọi trang trong context, gắn nhãn theo bước đang chạy. */
async function wire(context) {
  await context.exposeBinding('__cspReport', (_s, v) => problems.push({ step, kind: 'csp', ...v }));
  await context.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (e) => {
      window.__cspReport({ directive: e.effectiveDirective, blocked: e.blockedURI, source: (e.sourceFile || '').replace(location.origin, '') });
    });
  });
  context.on('page', (page) => {
    page.on('pageerror', (err) => {
      // Promise hành động Livewire bị huỷ khi trang rời đi (id component 20 ký tự) — xem survey.cjs.
      if (!/^[A-Za-z0-9]{20}$/.test(err.message)) problems.push({ step, kind: 'pageerror', message: err.message.split('\n')[0] });
    });
    page.on('console', (msg) => {
      if (msg.type() === 'error' && !/status of 4(03|04|19|22|29)/.test(msg.text())) {
        problems.push({ step, kind: 'console', message: msg.text().split('\n')[0].slice(0, 200) });
      }
      if (/\[vk-pwa\]/.test(msg.text())) problems.push({ step, kind: 'register', message: msg.text().slice(0, 200) });
    });
  });
}

async function newPage(context) {
  const page = await context.newPage();
  page.setDefaultTimeout(90000);
  page.setDefaultNavigationTimeout(120000);
  return page;
}

/** Trạng thái worker của trang hiện hành: scope, script, trạng thái, có điều khiển trang không. */
async function workerState(page) {
  return page.evaluate(async () => {
    const reg = await Promise.race([navigator.serviceWorker.ready, new Promise((r) => setTimeout(() => r(null), 30000))]);
    return {
      scope: reg ? reg.scope : null,
      script: reg && reg.active ? reg.active.scriptURL : null,
      state: reg && reg.active ? reg.active.state : null,
      controller: navigator.serviceWorker.controller ? navigator.serviceWorker.controller.scriptURL : null,
    };
  });
}

/** Chờ worker đăng ký và điều khiển trang (tải lại một lần nếu cần), rồi kiểm scope. */
async function requireWorker(page, panel) {
  let state = await workerState(page);
  if (!state.controller) {
    await page.reload({ waitUntil: 'domcontentloaded' });
    await settle(page);
    state = await workerState(page);
  }
  check(`${panel}: worker đăng ký đúng scope ${BASE}/${panel}`, state.scope === `${BASE}/${panel}`, `scope=${state.scope}`);
  check(`${panel}: worker là /${panel}/sw.js và đang activated`, state.script === `${BASE}/${panel}/sw.js` && state.state === 'activated', `${state.script} (${state.state})`);
  check(`${panel}: worker đang điều khiển trang ${page.url().replace(BASE, '')}`, state.controller === `${BASE}/${panel}/sw.js`, `controller=${state.controller}`);
}

async function controlled(page) {
  return page.evaluate(() => (navigator.serviceWorker.controller ? navigator.serviceWorker.controller.scriptURL : null));
}

/** Toàn bộ CacheStorage của origin: [{ cache, url, type }]. */
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

async function staticPrefixes(page) {
  const body = await page.evaluate(async () => (await fetch('/portal/sw.js', { cache: 'no-store' })).text());
  return JSON.parse(body.match(/^const STATIC_PREFIXES = (.+);$/m)[1]);
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
  const html = entries.filter((e) => /text\/html/.test(e.type || '')).map((e) => e.url);
  console.log(`  CacheStorage sau ${label}: ${entries.length} mục trong ${[...new Set(entries.map((e) => e.cache))].join(', ') || '(không bộ đệm nào)'}`);
  for (const e of entries) console.log(`      ${e.cache}  ${e.url}  [${e.type}]`);
  check(`CacheStorage sau ${label}: không URL riêng tư nào (chỉ trang ngoại tuyến + tài nguyên tĩnh công khai)`, bad.length === 0,
    bad.length ? 'LỌT: ' + bad.map((e) => e.url).join(', ') : `${entries.length} mục, HTML duy nhất: ${html.join(', ') || '(không)'}`);
}

async function logout(page, panel) {
  await page.locator('.fi-user-menu-trigger').first().click();
  await page.getByRole('button', { name: 'Đăng xuất' }).or(page.getByRole('menuitem', { name: 'Đăng xuất' })).first().click();
  await page.waitForURL(new RegExp(`/${panel}/login`));
  await settle(page);
  check(`${panel}: đăng xuất bằng menu người dùng (form POST, worker đang điều khiển)`, /\/login$/.test(new URL(page.url()).pathname), page.url().replace(BASE, ''));
}

async function offlineRoundTrip(context, page, panel, target) {
  step = `${panel}: ngoại tuyến`;
  await context.setOffline(true);
  await page.goto(BASE + target, { waitUntil: 'domcontentloaded' }).catch((e) => problems.push({ step, kind: 'goto', message: e.message.split('\n')[0] }));
  await settle(page, 300);
  const heading = await page.locator('h1').first().innerText().catch(() => '');
  const tel = await page.locator('a[href^="tel:"]').first().getAttribute('href').catch(() => null);
  const scripts = await page.locator('script').count().catch(() => -1);
  await shot(page, `${panel}-offline`);
  check(`${panel}: ngoại tuyến, điều hướng tới ${target} hiện trang ngoại tuyến`, heading.includes('Chưa có kết nối mạng'), `h1="${heading}", ${tel}, ${scripts} thẻ script`);
  check(`${panel}: trang ngoại tuyến có hotline dạng tel: và không script`, Boolean(tel) && scripts === 0, `${tel}`);

  await context.setOffline(false);
  await page.getByRole('link', { name: 'Thử lại' }).click();
  await page.waitForLoadState('domcontentloaded');
  await settle(page);
  await shot(page, `${panel}-after-retry`);
  const landed = new URL(page.url()).pathname;
  check(`${panel}: trực tuyến lại, "Thử lại" về start_url /${panel}`, landed === `/${panel}`, `tới ${landed}`);
}

async function portalLogin(page) {
  await page.goto(BASE + '/portal/login', { waitUntil: 'domcontentloaded' });
  await settle(page);
  const offset = fs.existsSync(LOG) ? fs.statSync(LOG).size : 0;
  await page.fill('input[type="email"]', CLIENT.email);
  await page.fill('input[type="password"]', CLIENT.password);
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
}

async function portalMatterUrls(page) {
  await page.goto(BASE + '/portal', { waitUntil: 'domcontentloaded' });
  await settle(page);
  const hrefs = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')));
  return [...new Set(hrefs.filter((h) => h && /\/portal\/ho-so\/\d+$/.test(h)).map((h) => h.replace(BASE, '')))];
}

async function firstHrefAcross(page, urls, pattern) {
  for (const u of urls) {
    await page.goto(BASE + u, { waitUntil: 'domcontentloaded' });
    await settle(page, 200);
    const hrefs = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')));
    const found = hrefs.find((h) => h && pattern.test(h));
    if (found) return { page: u, href: found.replace(BASE, '') };
  }
  return null;
}

function setClientActive(active) {
  execFileSync('docker', ['exec', CONTAINER, 'php', 'artisan', 'tinker', '--execute',
    `App\\Models\\ClientUser::where('email', '${CLIENT.email}')->update(['is_active' => ${active ? 'true' : 'false'}]);`], { stdio: 'pipe' });
}

/** Bộ đếm đăng nhập của cổng khách (5 lần / 15 phút theo IP) — xoá trước mỗi lần đăng nhập lại. */
function clearLoginThrottle() {
  execFileSync('docker', ['exec', CONTAINER, 'php', 'artisan', 'cache:clear'], { stdio: 'pipe' });
}

/**
 * Đăng nhập, mở trang nộp giấy tờ, vô hiệu tài khoản GIỮA phiên, rồi:
 *  - `livewire`: bấm một nút Livewire (chọn đầu mục, hoặc "Gửi") — request cập nhật Livewire;
 *  - `navigate`: tải lại trang — một lần điều hướng thường.
 * Trả { mode, sw, url, inactive } — `inactive`: câu `portal.inactive` có trên trang đích.
 * Tài khoản LUÔN được bật lại trước khi trả.
 */
async function deactivateMidSession(page, matterUrls, mode) {
  clearLoginThrottle();
  await portalLogin(page);
  const submit = await firstHrefAcross(page, matterUrls, /\/portal\/nop-giay-to\/\d+/);
  if (!submit) throw new Error('không hồ sơ nào có đường nộp giấy tờ');
  await page.goto(BASE + submit.href, { waitUntil: 'domcontentloaded' });
  await settle(page);
  const sw = await controlled(page);
  try {
    setClientActive(false);
    if (mode === 'livewire') {
      const button = page.locator('[data-portal-block="1"] button[wire\\:click^="chooseItem"]').first();
      if (await button.count()) await button.click();
      else await page.locator('[data-portal-action="send"]').click();
    } else {
      await page.reload({ waitUntil: 'domcontentloaded' });
    }
    await page.waitForURL(/\/portal\/login/, { timeout: 60000 }).catch(() => {});
    await settle(page, 1500);
  } finally {
    setClientActive(true);
  }
  const text = await page.locator('body').innerText();
  return { mode, sw, url: new URL(page.url()).pathname, inactive: text.includes('Tài khoản này hiện chưa đăng nhập được') };
}

async function clientTour(browser) {
  const context = await browser.newContext({ acceptDownloads: true });
  await wire(context);
  const page = await newPage(context);

  step = 'portal: đăng ký worker';
  await page.goto(BASE + '/portal/login', { waitUntil: 'domcontentloaded' });
  await settle(page);
  await requireWorker(page, 'portal');
  STATIC_PREFIXES = await staticPrefixes(page);
  console.log('  tiền tố tĩnh (từ /portal/sw.js): ' + STATIC_PREFIXES.join(' '));

  step = 'portal: đăng nhập';
  clearLoginThrottle();
  await portalLogin(page);
  check('portal: đăng nhập đủ hai bước với worker đang điều khiển', (await controlled(page)) !== null, page.url().replace(BASE, ''));

  const matterUrls = await portalMatterUrls(page);
  step = 'portal: trang hồ sơ';
  await page.goto(BASE + matterUrls[0], { waitUntil: 'domcontentloaded' });
  await settle(page);
  check('portal: mở trang hồ sơ', /\/portal\/ho-so\/\d+$/.test(page.url()), matterUrls[0]);

  step = 'portal: nộp giấy tờ';
  const submit = await firstHrefAcross(page, matterUrls, /\/portal\/nop-giay-to\/\d+/);
  try {
    await page.goto(BASE + submit.href, { waitUntil: 'domcontentloaded' });
    await settle(page);
    const choice = page.locator('[data-portal-block="1"] button[wire\\:click^="chooseItem"]').first();
    if (await choice.count()) { await choice.click(); await settle(page); }
    await page.locator('input[type="file"]').first().setInputFiles({
      name: 'giay-to-pwa.pdf', mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'),
    });
    await page.getByText('Tải lên thành công').first().waitFor({ timeout: 60000 });
    await page.locator('[data-portal-action="send"]').click();
    await page.getByText('Chúng tôi đã nhận được').first().waitFor({ timeout: 60000 });
    check('portal: nộp một tệp (Livewire tải lên + gửi) khi worker điều khiển', (await controlled(page)) !== null, submit.href);
  } catch (e) {
    check('portal: nộp một tệp (Livewire tải lên + gửi) khi worker điều khiển', false, e.message.split('\n')[0]);
  }

  step = 'portal: tải tài liệu';
  try {
    const link = await firstHrefAcross(page, matterUrls, /\/documents\/\d+\/download/);
    const linkPath = new URL(link.href, BASE).pathname;
    check('portal: nút tải trỏ vào bí danh TRONG scope /portal/documents/{id}/download', /^\/portal\/documents\/\d+\/download$/.test(linkPath), linkPath);
    const fromSw = [];
    page.on('response', (r) => { if (/\/documents\/\d+\/download/.test(r.url())) fromSw.push(r.fromServiceWorker()); });
    const sw = await controlled(page);
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 30000 }),
      page.locator('a[href*="/documents/"][href*="/download"]').first().click(),
    ]);
    const size = fs.statSync(await download.path()).size;
    check('portal: tải một tài liệu khi worker điều khiển trang (tệp về đủ, trang đứng nguyên)', size > 0 && sw !== null,
      `${download.suggestedFilename()} ${size} byte; controller=${sw}; response qua worker: ${JSON.stringify(fromSw)}; trang: ${page.url().replace(BASE, '')}`);
  } catch (e) {
    check('portal: tải một tài liệu khi worker điều khiển trang', false, e.message.split('\n')[0]);
  }

  await offlineRoundTrip(context, page, 'portal', matterUrls[0]);

  step = 'portal: đăng xuất';
  await logout(page, 'portal');
  auditCache('lượt cổng khách (đăng nhập → nộp → tải → ngoại tuyến → đăng xuất)', await cacheEntries(page));

  /*
   * Vô hiệu giữa phiên — luật của kế hoạch là "đúng như khi chưa có service worker": cùng kịch bản
   * chạy trong một context CHẶN worker làm đối chứng, và kết quả (trang đích, câu `portal.inactive`
   * có hay không) phải trùng nhau ở cả hai đường: bấm nút Livewire, và điều hướng thường.
   */
  const control = await browser.newContext({ serviceWorkers: 'block' });
  await wire(control);
  const controlPage = await newPage(control);
  try {
    for (const mode of ['livewire', 'navigate']) {
      step = `portal: vô hiệu giữa phiên, ${mode} (có worker)`;
      const withSw = await deactivateMidSession(page, matterUrls, mode);
      await shot(page, `portal-inactive-${mode}-with-sw`);
      step = `portal: vô hiệu giữa phiên, ${mode} (đối chứng, chặn worker)`;
      const withoutSw = await deactivateMidSession(controlPage, matterUrls, mode);
      await shot(controlPage, `portal-inactive-${mode}-without-sw`);

      check(`portal: vô hiệu giữa phiên + ${mode === 'livewire' ? 'bấm nút Livewire' : 'tải lại trang'} → về /portal/login (worker điều khiển)`,
        withSw.sw !== null && withSw.url === '/portal/login', JSON.stringify(withSw));
      check(`portal: cùng kịch bản (${mode}) KHÔNG có worker cho đúng cùng kết quả — trang đích và câu portal.inactive`,
        withoutSw.sw === null && withoutSw.url === withSw.url && withoutSw.inactive === withSw.inactive, JSON.stringify(withoutSw));
      if (mode === 'navigate') {
        check('portal: điều hướng thường sau khi bị vô hiệu hiện câu portal.inactive (cả hai bên)', withSw.inactive && withoutSw.inactive,
          `có worker: ${withSw.inactive}, không worker: ${withoutSw.inactive}`);
      } else {
        console.log(`  GHI CHÚ: đường Livewire — câu portal.inactive trên trang đích: có worker ${withSw.inactive}, không worker ${withoutSw.inactive}`);
      }
    }
  } finally {
    setClientActive(true);
    await control.close();
  }
  auditCache('lượt vô hiệu giữa phiên', await cacheEntries(page));

  await context.close();
}

async function staffTour(browser) {
  const context = await browser.newContext({ acceptDownloads: true });
  await wire(context);
  const page = await newPage(context);

  step = 'admin: đăng ký worker';
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await settle(page);
  await requireWorker(page, 'admin');

  step = 'admin: đăng nhập';
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
  check('admin: đăng nhập đủ hai bước với worker đang điều khiển', (await controlled(page)) !== null, page.url().replace(BASE, ''));

  await page.goto(BASE + '/admin/matters', { waitUntil: 'domcontentloaded' });
  await settle(page);
  const matterUrl = (await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href'))))
    .find((h) => /\/admin\/matters\/\d+$/.test(h)).replace(BASE, '');

  step = 'admin: chuyển giai đoạn';
  try {
    await page.goto(BASE + matterUrl, { waitUntil: 'domcontentloaded' });
    await settle(page);
    const tab = page.locator('.fi-tabs-item', { hasText: 'Tiến độ' }).first();
    await page.waitForFunction((el) => !el.disabled, await tab.elementHandle());
    await tab.click();
    await page.getByRole('button', { name: 'Chuyển giai đoạn', exact: true }).first().click();
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
    if ((await publicContent.inputValue()).trim().length < 30) {
      await publicContent.fill('Văn phòng đã hoàn tất bước này và chuyển hồ sơ sang giai đoạn tiếp theo.');
      await settle(page, 300);
    }
    await modal.getByRole('button', { name: 'Gửi', exact: true }).click();
    await page.getByText('Đã chuyển giai đoạn.').first().waitFor({ timeout: 60000 });
    check('admin: chuyển giai đoạn qua form khi worker điều khiển', (await controlled(page)) !== null, matterUrl);
  } catch (e) {
    check('admin: chuyển giai đoạn qua form khi worker điều khiển', false, e.message.split('\n')[0]);
  }

  step = 'admin: tải lên tài liệu';
  try {
    await page.goto(BASE + matterUrl, { waitUntil: 'domcontentloaded' });
    await settle(page);
    const tab = page.locator('.fi-tabs-item', { hasText: 'Tài liệu' }).first();
    await page.waitForFunction((el) => !el.disabled, await tab.elementHandle());
    await tab.click();
    await settle(page, 300);
    await page.getByRole('button', { name: 'Đưa tài liệu vào hồ sơ', exact: true }).first().click();
    const modal = page.locator('.fi-modal-window:visible').first();
    await modal.waitFor({ state: 'visible', timeout: 15000 });
    await settle(page);
    await modal.locator('input[type="file"]').first().setInputFiles({
      name: 'tai-lieu-pwa.pdf', mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'),
    });
    await modal.getByText('Tải lên thành công').first().waitFor({ timeout: 60000 });
    await modal.getByLabel('Tên tài liệu').fill('Tài liệu kiểm service worker');
    const group = modal.getByLabel('Nhóm tài liệu');
    if (await group.evaluate((el) => el.tagName === 'SELECT')) {
      const values = await group.locator('option').evaluateAll((os) => os.map((o) => o.value).filter(Boolean));
      await group.selectOption(values[0]);
    } else {
      await group.click();
      await page.locator('.fi-select-input-option:visible, [role="option"]:visible').first().click();
    }
    await settle(page, 300);
    await modal.getByRole('button', { name: 'Gửi', exact: true }).click();
    await page.getByText('Đã lưu tài liệu vào hồ sơ.').first().waitFor({ timeout: 60000 });
    check('admin: đưa một tài liệu lên qua modal khi worker điều khiển', (await controlled(page)) !== null, matterUrl);

    step = 'admin: tải tài liệu';
    await settle(page);
    const download = page.getByRole('link', { name: 'Tải tệp' }).first();
    const href = await download.getAttribute('href');
    const target = await download.getAttribute('target');
    check('admin: nút "Tải tệp" trỏ vào bí danh /admin/documents/{id}/download, cùng cửa sổ',
      /^\/admin\/documents\/\d+\/download$/.test(new URL(href, BASE).pathname) && target === null, `${new URL(href, BASE).pathname}, target=${target}`);
    const sw = await controlled(page);
    const [file] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), download.click()]);
    const size = fs.statSync(await file.path()).size;
    check('admin: tải một tệp khi worker điều khiển trang (tệp về đủ, trang đứng nguyên)', size > 0 && sw !== null,
      `${file.suggestedFilename()} ${size} byte; trang: ${page.url().replace(BASE, '')}`);
  } catch (e) {
    check(`${step}: hỏng`, false, e.message.split('\n')[0]);
  }

  await offlineRoundTrip(context, page, 'admin', matterUrl);

  step = 'admin: đăng xuất';
  await logout(page, 'admin');
  auditCache('lượt nội bộ (đăng nhập → chuyển giai đoạn → tải lên → tải về → ngoại tuyến → đăng xuất)', await cacheEntries(page));

  await context.close();
}

(async () => {
  const browser = await playwright.chromium.launch();
  console.log(`chromium ${browser.version()} → ${BASE}`);
  try {
    await clientTour(browser);
    await staffTour(browser);
  } catch (e) {
    check(`lượt dừng giữa chừng ở bước "${step}"`, false, e.message.split('\n')[0]);
  } finally {
    await browser.close();
  }

  console.log(`\nVi phạm CSP / lỗi trang: ${problems.length}`);
  for (const p of problems) console.log('  ' + JSON.stringify(p));
  check('không vi phạm CSP, không lỗi JS/console, không lỗi đăng ký worker trên mọi trang', problems.length === 0, `${problems.length} mục`);

  if (OUT) fs.writeFileSync(OUT, JSON.stringify({ base: BASE, checks, problems }, null, 2));
  const failed = checks.filter((c) => !c.ok).length;
  console.log(`\n${checks.length - failed}/${checks.length} dòng OK${failed ? `, ${failed} HỎNG` : ''}.`);
  process.exit(failed ? 1 : 0);
})();
