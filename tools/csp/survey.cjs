#!/usr/bin/env node
/*
 * Khảo sát CSP có đo đạc (M8a Task 4, phán quyết R4 của kế hoạch M8) — đi qua các trang chính
 * của CẢ HAI panel bằng một trình duyệt thật (Chromium hoặc WebKit), với đăng nhập thật, và ghi
 * từng vi phạm CSP.
 *
 * Kết quả và phán quyết: docs/research/2026-09-26-csp-khao-sat.md.
 *
 * Playwright KHÔNG nằm trong package.json của repo. Cài nó vào một thư mục ngoài repo rồi trỏ
 * NODE_PATH vào đó (tệp này là CommonJS chính vì thế — `import` của ESM không đọc NODE_PATH):
 *
 *   mkdir -p /d/vkwt/m8-tools && cd /d/vkwt/m8-tools && npm i playwright && npx playwright install chromium webkit
 *   /d/vkwt/m8-dev seed
 *   /d/vkwt/m8-dev serve -e CSP_MODE=report -e PHP_INI_SCAN_DIR=:/var/www/html/tools/csp/php
 *   (tệp ini chỉ bật opcache cho `php artisan serve` — không có nó một trang mất ~20 giây)
 *   NODE_PATH=/d/vkwt/m8-tools/node_modules node tools/csp/survey.cjs
 *
 * Biến môi trường:
 *   BASE     gốc của bản chạy (mặc định http://localhost:8090)
 *   LOG      laravel.log của bản chạy — nơi đọc mã đăng nhập một lần của cổng khách
 *            (mặc định storage/logs/laravel.log của repo này; bản chạy dùng MAIL_MAILER=log)
 *   LABEL    tên lượt đo, in ra đầu bảng
 *   OUT      ghi toàn bộ kết quả (JSON) ra tệp này
 *   BROWSER  chromium (mặc định) | webkit
 *   SCOPE    =uploads thì CHỈ đi các bước tải ảnh lên (đăng nhập hai panel, modal "Đưa tài liệu vào
 *            hồ sơ" của nhân sự, trang nộp giấy tờ của khách) — lượt đo nhanh cho worker-src.
 *   ACTIONS  =1 thì làm thêm các HÀNH ĐỘNG CHÍNH, có ghi dữ liệu: GỬI các ảnh đã chọn — khách nộp
 *            giấy tờ thật (một ảnh JPEG và một ảnh PNG — đường chụp ảnh bằng điện thoại của SPEC
 *            §8.4), nhân sự lưu một ảnh JPEG qua modal "Đưa tài liệu vào hồ sơ" — rồi tải một tệp
 *            và chuyển giai đoạn một vụ việc. Không có ACTIONS, các ảnh vẫn được CHỌN (tải lên tạm,
 *            dựng bản xem trước) nhưng không gửi. Dùng cho lượt kiểm ở chế độ enforce.
 *
 * Ảnh JPEG/PNG do CHÍNH trình duyệt mã hoá (canvas → toDataURL) nên là ảnh thật, không phải vài
 * byte giả: ô tải lên của Filament (FilePond) dựng bản xem trước cho ảnh trong một Web Worker
 * tạo từ `blob:` — thứ CSP phải cho phép qua `worker-src`, và chỉ một ảnh thật mới đi tới đó.
 *
 * Mỗi lần trang dựng một Web Worker, script ghi lại (bọc `window.Worker` trong init script): Worker
 * từ `blob:` hay không, đã nhận thông điệp đầu tiên từ Worker chưa (tức Worker THẬT SỰ chạy), có sự
 * kiện `error` không. Mỗi bước chọn ảnh bắt buộc: ≥ 1 Worker `blob:` đã trả thông điệp, 0 Worker
 * lỗi, ≥ 1 canvas xem trước — thiếu một điều là bước đó hỏng, nên một lượt "0 vi phạm" không thể
 * đến từ một bước không bao giờ đi tới Worker.
 *
 * Lượt đầy đủ đi cả các màn hình M6.5: widget "Mốc thời hạn 7 ngày tới" ở bảng điều khiển, modal
 * "Xem chi tiết" của nhật ký hệ thống, sổ "Thư đã gửi" (danh sách + một thư), trang sửa vụ việc,
 * tab "Đội ngũ" + modal "Thêm thành viên", modal "Bàn giao", hồ sơ cá nhân, và một lần nộp NHIỀU
 * tệp cùng lúc ở cổng khách (JPEG + PNG + PDF trong một lượt chọn).
 *
 * Mã thoát: 0 khi không có vi phạm CSP, lỗi JavaScript hay hành động hỏng nào; 1 nếu có.
 *
 * Giới hạn đăng nhập cổng khách (5 lần / 15 phút theo địa chỉ mạng, `PortalLoginThrottle`) KHÔNG
 * bao giờ được xoá bởi một lần đăng nhập thành công. Chạy nhiều lượt liền nhau thì xoá bộ đếm
 * trước: docker exec vkcrm-lane-m8-app php artisan cache:clear
 */
'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const playwright = require('playwright');

const BASE = (process.env.BASE || 'http://localhost:8090').replace(/\/$/, '');
const LOG = process.env.LOG || path.resolve(__dirname, '../../storage/logs/laravel.log');
const LABEL = process.env.LABEL || 'survey';
const OUT = process.env.OUT || '';
const ACTIONS = process.env.ACTIONS === '1';
const BROWSER = process.env.BROWSER || 'chromium';
const FULL = process.env.SCOPE !== 'uploads';

/** Ảnh thật do trình duyệt mã hoá, dựng một lần khi khởi động: { jpeg: Buffer, png: Buffer }. */
const images = {};

const STAFF = { email: 'admin@luatvukhang.com', password: 'password' };
const CLIENT = { email: 'khach1@example.com', password: 'password' };

/**
 * M8 Task 2 (R2, §10.7): panel `admin` giờ bắt buộc 2FA ứng dụng — `STAFF` không đăng nhập xong
 * chỉ bằng mật khẩu nữa, cần một mã TOTP đúng ngay sau đó. `admin@luatvukhang.com` mang secret cố
 * định của `Database\Seeders\DemoAccountsSeeder::DEMO_TWO_FACTOR_SECRET` (CHỈ gán ở
 * `local`/`testing` — bản chạy của làn seed bằng `/d/vkwt/m8b-dev seed`, môi trường `local`, nên
 * secret này có mặt). Hai chuỗi phải khớp NHAU — không tính lại tự động, vì script này không đọc
 * được `.env`/CSDL của bản PHP đang chạy.
 *
 * `SETUP_STAFF` — một tài khoản demo KHÁC, dùng để khảo sát CSP của trang "Cài đặt 2FA bắt buộc"
 * và modal QR (trang mà `STAFF` không bao giờ ghé, vì đã cài từ trước). **Trước lượt chạy chính,
 * xoá secret của tài khoản này:**
 *
 *   docker exec vkcrm-lane-m8b-app php artisan vkcrm:reset-2fa luatsu1@luatvukhang.com
 *
 * Script không tự làm việc này (không có quyền `docker exec` từ trong Node, và một script khảo
 * sát CSP không nên kiêm luôn việc sửa dữ liệu) — bỏ qua bước trên thì tour của `SETUP_STAFF` vẫn
 * chạy nhưng KHÔNG tới trang cài đặt (đã cài từ trước, cùng secret demo), chỉ ghi một `note()`
 * nói rõ điều đó thay vì báo lỗi.
 */
const DEMO_TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';
const SETUP_STAFF = { email: 'luatsu1@luatvukhang.com', password: 'password' };

/**
 * TOTP (RFC 6238) — HMAC-SHA1, bước 30 giây, 6 chữ số, cùng thuật toán `pragmarx/google2fa` phía
 * PHP dùng. Cài lại bằng tay (không gọi thư viện ngoài) vì Playwright không mang theo gói TOTP
 * nào, và đây là thuật toán chuẩn, ổn định, không đáng để thêm một phụ thuộc `npm`.
 */
function base32Decode(input) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of input.toUpperCase().replace(/[^A-Z2-7]/g, '')) {
    const value = alphabet.indexOf(char);
    if (value === -1) continue;
    bits += value.toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

function totp(secretBase32, atMs = Date.now(), step = 30, digits = 6) {
  const key = base32Decode(secretBase32);
  const counter = Math.floor(atMs / 1000 / step);
  const counterBuffer = Buffer.alloc(8);
  counterBuffer.writeBigUInt64BE(BigInt(counter));
  const hmac = crypto.createHmac('sha1', key).update(counterBuffer).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const code =
    (((hmac[offset] & 0x7f) << 24) |
      ((hmac[offset + 1] & 0xff) << 16) |
      ((hmac[offset + 2] & 0xff) << 8) |
      (hmac[offset + 3] & 0xff)) %
    10 ** digits;
  return String(code).padStart(digits, '0');
}

/**
 * Mã TOTP có thể vừa đổi ngay lúc gõ xong (biên 30 giây) — Filament chấp nhận một cửa sổ 8 bước
 * (`AppAuthentication::$codeWindow = 8`, ±4 phút) nên KHÔNG cần né biên, nhưng tính lại NGAY
 * TRƯỚC KHI gõ (không tính trước rồi giữ biến) để mỗi lần gọi luôn dùng mã của "bây giờ".
 */
function currentTotp(secret) {
  return totp(secret, Date.now());
}

/** Kết quả theo trang: { label, url, violations[], errors[], scripts[], notes[] }. */
const pages = [];
const actions = [];
let current = null;

function begin(label) {
  current = {
    label, url: '', violations: [], errors: [], scripts: [], notes: [],
    workers: { blob: 0, other: 0, message: 0, error: 0 },
  };
  pages.push(current);
  return current;
}

async function settle(page, ms = 600) {
  try {
    await page.waitForLoadState('networkidle', { timeout: 15000 });
  } catch (_) {
    /* Livewire có thể giữ một kết nối; hết hạn thì đi tiếp. */
  }
  await page.waitForTimeout(ms);
}

/** Mọi `<script>` nội tuyến của trang hiện hành: nonce có hay không, và sha256 của nội dung. */
async function collectInlineScripts(page) {
  const scripts = await page.evaluate(() =>
    Array.from(document.querySelectorAll('script:not([src])')).map((el) => ({
      hasNonce: Boolean(el.nonce),
      text: el.textContent,
    })),
  );
  return scripts.map((s) => ({
    hasNonce: s.hasNonce,
    sha256: crypto.createHash('sha256').update(s.text, 'utf8').digest('base64'),
    head: s.text.trim().replace(/\s+/g, ' ').slice(0, 60),
  }));
}

async function visit(page, label, url, after) {
  const entry = begin(label);
  const response = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
  await settle(page);
  entry.url = page.url().replace(BASE, '');
  entry.status = response ? response.status() : null;
  entry.scripts = await collectInlineScripts(page);
  if (after) {
    // Một bước hỏng (nút không mở modal, trang không tới đích) là MỘT KẾT QUẢ ĐO, không phải lý
    // do dừng cả lượt: ghi vào lỗi của trang rồi đi tiếp. Riêng đăng nhập thì ném lại — không
    // đăng nhập được thì mọi trang sau đều vô nghĩa.
    try {
      await after(page, entry);
      await settle(page);
    } catch (e) {
      entry.errors.push('bước hỏng: ' + e.message.split('\n')[0]);
      if (/đăng nhập/.test(label)) throw e;
    }
  }
  return entry;
}

function note(text) {
  current.notes.push(text);
}

function action(name, ok, detail) {
  actions.push({ name, ok, detail });
  console.log(`  [${ok ? 'OK ' : 'HỎNG'}] ${name}${detail ? ' — ' + detail : ''}`);
}

async function firstHref(page, pattern) {
  const hrefs = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')));
  const match = hrefs.find((h) => h && pattern.test(h));
  return match ? match.replace(BASE, '') : null;
}

async function allHrefs(page, pattern) {
  const hrefs = await page.$$eval('a[href]', (as) => as.map((a) => a.getAttribute('href')));
  return [...new Set(hrefs.filter((h) => h && pattern.test(h)).map((h) => h.replace(BASE, '')))];
}

/** Mở một action Filament theo nhãn nút, chờ modal, rồi (nếu không làm tiếp) đóng lại. */
async function openModal(page, buttonName) {
  await page.getByRole('button', { name: buttonName, exact: true }).first().click();
  const modal = page.locator('.fi-modal-window:visible').first();
  await modal.waitFor({ state: 'visible', timeout: 15000 });
  await settle(page);
  return modal;
}

/** "Chuyển giai đoạn" và "Thêm cập nhật" là action đầu bảng của tab "Tiến độ" (StageLogsRelationManager). */
async function openStageTab(page) {
  const tab = page.locator('.fi-tabs-item', { hasText: 'Tiến độ' }).first();
  await page.waitForFunction((el) => !el.disabled, await tab.elementHandle());
  await tab.click();
  await page.getByRole('button', { name: 'Chuyển giai đoạn', exact: true }).first().waitFor();
  await settle(page, 300);
}

async function closeModal(page) {
  await page.keyboard.press('Escape');
  await settle(page, 300);
}

/**
 * Sau khi chọn một ẢNH: chờ Worker `blob:` của FilePond trả thông điệp đầu tiên, rồi đòi đủ bằng
 * chứng rằng đường xem trước ảnh đã THẬT SỰ chạy. Ném lỗi (→ bước hỏng, mã thoát 1) nếu thiếu.
 */
async function requireImagePreview(page, scope, entry) {
  const deadline = Date.now() + 30000;
  while (entry.workers.message === 0 && entry.workers.error === 0 && Date.now() < deadline) {
    await page.waitForTimeout(200);
  }
  await settle(page, 500);
  const canvases = await scope.locator('.filepond--image-preview canvas, .filepond--image-bitmap canvas').count();
  const w = entry.workers;
  const summary = `Worker blob: ${w.blob}, đã trả thông điệp ${w.message}, lỗi ${w.error}; ${canvases} canvas xem trước`;
  note(summary);
  if (w.blob < 1 || w.message < 1 || w.error > 0 || canvases < 1) {
    throw new Error('đường xem trước ảnh KHÔNG chạy đủ — ' + summary);
  }
}

// ---------------------------------------------------------------------------------------------

async function staffTour(browser) {
  const context = await browser.newContext({ acceptDownloads: true });
  await wire(context);
  const page = await context.newPage();
  // Bản chạy của làn là `php artisan serve` trên bind mount Docker/Windows: một request cập nhật
  // Livewire mất vài giây là thường. Hạn chờ dài để "chậm" không bị đọc thành "hỏng".
  page.setDefaultTimeout(90000);
  page.setDefaultNavigationTimeout(120000);

  await visit(page, 'admin: đăng nhập (mật khẩu + mã ứng dụng)', '/admin/login', async (p) => {
    await p.fill('input[type="email"]', STAFF.email);
    await p.fill('input[type="password"]', STAFF.password);
    await p.click('button[type="submit"]');

    // Mật khẩu đúng chỉ MỞ bước nhập mã (R2) — chưa chuyển trang, cùng component Livewire.
    let codeInput = p.locator('input[autocomplete="one-time-code"]').first();
    await codeInput.waitFor({ state: 'visible' });
    await settle(p);

    // Ghé qua "Sử dụng mã khôi phục để thay thế" một lượt — CSP của ô nhập mã khôi phục trên
    // chính bước đăng nhập (khác modal "Tạo lại mã khôi phục" ở trang hồ sơ, ghé riêng bên dưới).
    // KHÔNG có đường bấm lại để TẮT (đọc mã nguồn: nút này chỉ `->visible(fn () => ! $get(
    // 'useRecoveryCode'))`, tự biến mất sau khi bấm, không có action nào đặt lại `false`) — nạp
    // lại hẳn trang đăng nhập rồi làm lại từ đầu bằng mã TOTP, sạch hơn là cố lần theo một nút
    // không còn tồn tại.
    const useRecoveryLink = p.getByRole('button', { name: 'Sử dụng mã khôi phục để thay thế', exact: true }).first();
    if (await useRecoveryLink.count()) {
      await useRecoveryLink.waitFor({ state: 'visible' });
      await p.waitForFunction((el) => !el.disabled, await useRecoveryLink.elementHandle());
      await useRecoveryLink.click();
      await settle(p, 500);
      // `recoveryCode` của Filament là ô kiểu `password` + `autocomplete="one-time-code"`
      // (`AppAuthentication::getChallengeFormComponents()`); `OneTimeCodeInput` của ô mã thường
      // KHÔNG phải `type="password"` — nên bộ chọn này chỉ khớp đúng ô mã khôi phục, không khớp ô
      // mã thường (bản trước dùng `.nth(1)` của bộ chọn rộng và luôn báo "false").
      const recoveryInput = p.locator('input[type="password"][autocomplete="one-time-code"]').first();
      await recoveryInput.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
      note('ô mã khôi phục lúc đăng nhập hiện ra: ' + (await recoveryInput.isVisible().catch(() => false)));

      await p.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
      await settle(p);
      await p.fill('input[type="email"]', STAFF.email);
      await p.fill('input[type="password"]', STAFF.password);
      await p.click('button[type="submit"]');
      codeInput = p.locator('input[autocomplete="one-time-code"]').first();
      await codeInput.waitFor({ state: 'visible' });
      await settle(p);
    }

    await codeInput.click();
    await p.keyboard.type(currentTotp(DEMO_TWO_FACTOR_SECRET), { delay: 30 });
    await settle(p, 300);
    const submit = p.locator('button[type="submit"]:visible').first();
    if (!/\/admin\/?$/.test(new URL(p.url()).pathname)) await submit.click().catch(() => {});
    await p.waitForURL(/\/admin\/?$/);
    action('admin đăng nhập đủ hai bước (mật khẩu + mã TOTP)', true, 'tới /admin');
  });

  if (FULL) await visit(page, 'admin: bảng điều khiển', '/admin', async (p) => {
    // M6.5: widget "Mốc thời hạn 7 ngày tới" (UpcomingDeadlinesWidget) nạp lười qua Livewire —
    // chờ nó vẽ xong, để "0 vi phạm" của trang này tính cả phần widget.
    await p.getByText('Mốc thời hạn 7 ngày tới').first().waitFor({ timeout: 60000 });
    note('widget "Mốc thời hạn 7 ngày tới" đã vẽ');
  });

  /*
   * Trang "Cài đặt 2FA bắt buộc" + modal QR (R2) — CHỈ ghé được bằng một tài khoản CHƯA cài. Xem
   * docblock hằng số `SETUP_STAFF`: chạy `vkcrm:reset-2fa luatsu1@luatvukhang.com` trước lượt
   * khảo sát chính, nếu không bước này tự bỏ qua có ghi chú (không báo lỗi cả lượt).
   *
   * **Context RIÊNG, không phải tab mới của cùng context** (đã tự đo lỗi này): `page` ở trên đã
   * đăng nhập `STAFF` — một TAB MỚI của CÙNG context Playwright dùng chung cookie phiên, nên
   * `/admin/login` chuyển hướng thẳng về `/admin` (`Login::mount()`: đã đăng nhập thì
   * `redirect()->intended()`), `input[type="email"]` không bao giờ xuất hiện, và bước điền form
   * treo tới hết 90 giây. Một `browser.newContext()` RIÊNG (cùng cách `staffTour`/`clientTour` đã
   * tách nhau) có cookie jar trống, không dính phiên của `STAFF`.
   */
  if (FULL) {
    const setupContext = await browser.newContext({ acceptDownloads: true });
    await wire(setupContext);
    const setupPage = await setupContext.newPage();
    setupPage.setDefaultTimeout(90000);
    setupPage.setDefaultNavigationTimeout(120000);

    await visit(setupPage, 'admin: đăng nhập tài khoản chưa cài 2FA', '/admin/login', async (p) => {
      // Chưa cài 2FA → KHÔNG có bước mã (`getFirstEnabledProvider()` không tìm thấy gì) —
      // `Login::authenticate()` đăng nhập THẲNG, `LoginResponse` chuyển hướng tới `Filament::getUrl()`
      // (= `/admin`), rồi request TẢI TRANG đó mới bị `EnsureMultiFactorAuthenticationIsEnabled`
      // (per-trang, `isRequired: true`) chuyển hướng LẦN NỮA sang `.../multi-factor-authentication/set-up`.
      // HAI lượt chuyển hướng nối nhau, một trong hai chạy qua Livewire (`window.location = ...`)
      // rồi máy chủ đáp lại bằng một 302 thật — đã tự đo: Chromium THỈNH THOẢNG `ERR_ABORTED` lượt
      // điều hướng thứ hai (đua giữa điều hướng gốc và cách Livewire tự theo dõi lịch sử), bỏ lại
      // trang trên `/admin/login` dù đăng nhập đã đúng. Thử lại TOÀN BỘ bước đăng nhập một lần khi
      // đó xảy ra, trước khi kết luận.
      for (let attempt = 1; attempt <= 2; attempt++) {
        // Đăng nhập thật sự có thể đã THÀNH CÔNG ở phía máy chủ dù trình duyệt còn kẹt trên
        // `/admin/login` (đúng cuộc đua ghi ở trên) — nạp lại `/admin/login` trước mỗi lượt thử
        // để hỏi lại: có phiên rồi thì `Login::mount()` tự chuyển hướng đi, ô email không còn để
        // điền, và code dưới phải NHẬN RA điều đó thay vì cố `fill()` vào một trang đã đổi khác.
        if (attempt > 1) {
          await p.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' }).catch(() => {});
          await settle(p, 500);

          if (!/\/admin\/login\/?$/.test(new URL(p.url()).pathname)) break;
        }

        await p.fill('input[type="email"]', SETUP_STAFF.email);
        await p.fill('input[type="password"]', SETUP_STAFF.password);
        await p.click('button[type="submit"]');
        await p.waitForURL((url) => !/\/admin\/login\/?$/.test(url.pathname), { timeout: 20000 }).catch(() => {});
        await settle(p, 500);

        if (!/\/admin\/login\/?$/.test(new URL(p.url()).pathname)) break;

        note(`đăng nhập SETUP_STAFF lượt ${attempt} vẫn ở /admin/login — thử lại`);
      }

      const codeInput = p.locator('input[autocomplete="one-time-code"]').first();
      if (await codeInput.isVisible().catch(() => false)) {
        // Tài khoản NÀY cũng đã có secret (quên chạy vkcrm:reset-2fa trước lượt) — vẫn đăng nhập
        // được bằng secret demo chung, chỉ không tới được trang cài đặt. Ghi rõ, không báo lỗi.
        note('SETUP_STAFF đã có 2FA từ trước — bỏ qua trang cài đặt bắt buộc (chạy vkcrm:reset-2fa trước khi khảo sát để đo đúng trang này)');
        await codeInput.click();
        await p.keyboard.type(currentTotp(DEMO_TWO_FACTOR_SECRET), { delay: 30 });
        await settle(p, 300);
        const submit = p.locator('button[type="submit"]:visible').first();
        if (!/\/admin\/?$/.test(new URL(p.url()).pathname)) await submit.click().catch(() => {});
      }
    });

    const onSetupRequired = /multi-factor-authentication\/set-up/.test(setupPage.url());
    if (onSetupRequired) {
      await visit(setupPage, 'admin: trang cài đặt 2FA bắt buộc', setupPage.url().replace(BASE, ''), async (p) => {
        const modal = await openModal(p, 'Cài đặt');
        // Chuỗi base32 hiển thị dạng chữ, cạnh mã QR (Text::make(...)->copyable()) — cách duy
        // nhất script lấy được secret RIÊNG của lần cài đặt này (sinh mới mỗi lần mở modal).
        // Secret hiện ra SAU khi modal mở (Livewire dựng nội dung modal ở một request riêng, chậm
        // trên bản chạy `php artisan serve` qua bind mount) — chờ tới 20 giây thay vì đọc một lần
        // (bản trước đọc một lần và có lượt báo "không tìm thấy" dù modal đúng).
        let secretMatch = null;
        for (let i = 0; i < 40 && !secretMatch; i++) {
          secretMatch = (await modal.innerText()).match(/\b[A-Z2-7]{16,32}\b/);
          if (!secretMatch) await p.waitForTimeout(500);
        }
        note('modal cài đặt 2FA mở ra, tìm được secret dạng chữ: ' + Boolean(secretMatch));
        if (secretMatch) {
          const codeInput = modal.locator('input[autocomplete="one-time-code"]').first();
          await codeInput.click();
          await p.keyboard.type(totp(secretMatch[0]), { delay: 30 });
          await settle(p, 300);

          // Bước 1/2 của Wizard ("app") → "Tiếp theo" (nhãn mặc định của Filament Wizard, không
          // phải nhãn submit cuối) — validate mã TOTP vừa gõ rồi mới cho qua bước "recovery".
          // Bước này GIẢI MÃ tham số action, hỏi RateLimiter, rồi xác minh TOTP — chậm hơn một
          // cập nhật Livewire thường (đã tự đo: có lần mất hơn 2 giây, dưới 8 giây luôn xong trên
          // bản chạy `php artisan serve` qua bind mount này — xem ghi chú "chậm là bình thường"
          // ở đầu tệp). `waitFor()` trên chính nút bước sau, KHÔNG `settle()` cố định.
          const nextStep = modal.getByRole('button', { name: 'Tiếp theo', exact: true }).first();
          if (await nextStep.count()) {
            await nextStep.click();

            const finish = modal.getByRole('button', { name: 'Bật ứng dụng xác thực', exact: true }).first();
            await finish.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
            const recoveryVisible = await modal.getByText('mã khôi phục', { exact: false }).first().isVisible().catch(() => false);
            note('sang bước mã khôi phục: ' + recoveryVisible);

            // Bước 2/2 ("recovery") → nút submit thật, nhãn tuỳ biến của action này.
            if (await finish.count()) {
              await finish.click();
              await settle(p, 800);
              note('đã bấm "Bật ứng dụng xác thực" — hoàn tất cài đặt cho SETUP_STAFF');
            }
          }
        }
        // KHÔNG closeModal() nếu còn mở: action này tắt hẳn đóng-bằng-Escape/click-ra-ngoài
        // (`closeModalByClickingAway(false)`, `closeModalByEscaping(false)`) — điều hướng đi tiếp
        // ở lượt `visit()` kế tiếp tự thay thế cả trang, không cần đóng modal trước.
      });
    } else {
      note('SETUP_STAFF không được đưa tới trang cài đặt 2FA bắt buộc — bỏ qua tour này');
    }

    await setupContext.close();
  }

  /*
   * Modal "Tạo lại mã khôi phục" trên trang hồ sơ — action còn lại của bộ 2FA mà lượt đăng nhập ở
   * trên không chạm tới (SetUpAppAuthenticationAction chỉ chạy MỘT LẦN, chưa từng thấy lại sau đó).
   */
  if (FULL) await visit(page, 'admin: trang hồ sơ (modal Tạo lại mã khôi phục)', '/admin/profile', async (p) => {
    const modal = await openModal(p, 'Tạo lại mã khôi phục');
    note('modal Tạo lại mã khôi phục mở ra');
    await closeModal(p);
  });
  await visit(page, 'admin: danh sách vụ việc', '/admin/matters');
  const matterUrl = await firstHref(page, /\/admin\/matters\/\d+$/);

  if (FULL) await visit(page, 'admin: trang vụ việc + từng tab', matterUrl, async (p) => {
    const tabs = p.locator('.fi-tabs [role="tab"], .fi-tabs-item');
    const count = await tabs.count();
    for (let i = 0; i < count; i++) {
      // Tab tự `disabled` trong lúc một request Livewire đang chạy (`wire:loading.attr`).
      await p.waitForFunction((el) => !el.disabled, await tabs.nth(i).elementHandle());
      await tabs.nth(i).click();
      await settle(p, 400);
    }
    note(`${count} tab đã bấm`);
  });

  if (FULL) await visit(page, 'admin: form "Chuyển giai đoạn"', matterUrl, async (p) => {
    await openStageTab(p);
    await openModal(p, 'Chuyển giai đoạn');
    await closeModal(p);
  });

  if (FULL) await visit(page, 'admin: form "Thêm cập nhật"', matterUrl, async (p) => {
    await openStageTab(p);
    await openModal(p, 'Thêm cập nhật');
    await closeModal(p);
  });

  if (FULL) {
    await visit(page, 'admin: tạo vụ việc', '/admin/matters/create');
    await visit(page, 'admin: danh sách khách hàng', '/admin/clients');
    const clientEdit = await firstHref(page, /\/admin\/clients\/\d+\/edit$/);
    await visit(page, 'admin: sửa khách hàng', clientEdit);
    await visit(page, 'admin: tạo khách hàng', '/admin/clients/create');
    await visit(page, 'admin: tài khoản cổng', '/admin/client-users');
    const clientUserEdit = await firstHref(page, /\/admin\/client-users\/\d+\/edit$/);
    if (clientUserEdit) await visit(page, 'admin: sửa tài khoản cổng', clientUserEdit);
    await visit(page, 'admin: tạo tài khoản cổng', '/admin/client-users/create');
    await visit(page, 'admin: nhân sự', '/admin/users');
    const userEdit = await firstHref(page, /\/admin\/users\/\d+\/edit$/);
    if (userEdit) await visit(page, 'admin: sửa nhân sự', userEdit);
    await visit(page, 'admin: tạo nhân sự', '/admin/users/create');
    await visit(page, 'admin: loại vụ việc', '/admin/matter-types');
    const typeEdit = await firstHref(page, /\/admin\/matter-types\/\d+\/edit$/);
    if (typeEdit) await visit(page, 'admin: sửa loại vụ việc', typeEdit);
    await visit(page, 'admin: tạo loại vụ việc', '/admin/matter-types/create');
    await visit(page, 'admin: nhật ký hệ thống', '/admin/activity-log-page');

    // Màn hình M6.5 (khảo sát lại khi gộp M8a vào main): modal chi tiết của nhật ký, sổ thư đã
    // gửi, trang sửa vụ việc, tab "Đội ngũ" và modal của nó, modal "Bàn giao", hồ sơ cá nhân.
    await visit(page, 'admin: nhật ký hệ thống — modal "Xem chi tiết"', '/admin/activity-log-page', async (p) => {
      await openModal(p, 'Xem chi tiết');
      await closeModal(p);
    });
    await visit(page, 'admin: thư đã gửi', '/admin/outbound-messages');
    const outboundView = await firstHref(page, /\/admin\/outbound-messages\/\d+$/);
    if (outboundView) {
      await visit(page, 'admin: xem một thư đã gửi', outboundView);
    } else {
      begin('admin: xem một thư đã gửi');
      current.errors.push('bước hỏng: danh sách "Thư đã gửi" không có dòng nào để mở');
    }
    await visit(page, 'admin: sửa vụ việc', matterUrl + '/edit');
    await visit(page, 'admin: tab "Đội ngũ" — modal "Thêm thành viên"', matterUrl, async (p) => {
      const tab = p.locator('.fi-tabs-item', { hasText: 'Đội ngũ' }).first();
      await p.waitForFunction((el) => !el.disabled, await tab.elementHandle());
      await tab.click();
      await settle(p, 300);
      await openModal(p, 'Thêm thành viên');
      await closeModal(p);
    });
    await visit(page, 'admin: modal "Bàn giao"', matterUrl, async (p) => {
      await openModal(p, 'Bàn giao');
      await closeModal(p);
    });
    await visit(page, 'admin: hồ sơ cá nhân', '/admin/profile');

    await visit(page, 'web: trang 404', '/khong-ton-tai-' + Date.now());
    await visit(page, 'web: /up (kiểm tra sống)', '/up');
    // Trang /up của Laravel nạp Tailwind từ cdn.jsdelivr.net để tô chữ "Application up". CSP chặn
    // nó là ĐÚNG (không cho script bên thứ ba), trang vẫn trả 200 — thứ duy nhất bộ giám sát đọc.
    // Chấp nhận có chủ đích, xem docs/research/2026-09-26-csp-khao-sat.md.
    current.accepted = /^https:\/\/cdn\.jsdelivr\.net\//;
  }

  if (ACTIONS && FULL) {
    await visit(page, 'HÀNH ĐỘNG admin: chuyển giai đoạn', matterUrl, async (p) => {
      try {
        await openStageTab(p);
        const modal = await openModal(p, 'Chuyển giai đoạn');
        const select = modal.locator('select').first();
        if (await select.count()) {
          const values = await select.locator('option').evaluateAll((os) => os.map((o) => o.value).filter(Boolean));
          await select.selectOption(values[values.length - 1]);
        } else {
          // Select có tìm kiếm của Filament: mở rồi chọn mục cuối.
          await modal.locator('.fi-select-input-btn, .fi-fo-select button').first().click();
          await p.locator('.fi-select-input-option:visible, [role="option"]:visible').last().click();
        }
        await settle(p, 300);
        // "Công bố cho khách ngay" bật sẵn thì nội dung công bố phải ≥ 30 ký tự; giai đoạn có mẫu
        // thì ô đã được điền sẵn, không có thì điền một câu thật.
        const publicContent = modal.getByLabel('Nội dung công bố cho khách');
        if ((await publicContent.inputValue()).trim().length < 30) {
          await publicContent.fill('Văn phòng đã hoàn tất bước này và chuyển hồ sơ sang giai đoạn tiếp theo.');
          await settle(p, 300);
        }
        await modal.getByRole('button', { name: 'Gửi', exact: true }).click();
        await p.getByText('Đã chuyển giai đoạn.').first().waitFor({ timeout: 60000 });
        action('admin chuyển giai đoạn một vụ việc', true, `thấy thông báo "Đã chuyển giai đoạn." ở ${matterUrl}`);
      } catch (e) {
        action('admin chuyển giai đoạn một vụ việc', false, e.message.split('\n')[0]);
      }
    });
  }

  // Modal "Đưa tài liệu vào hồ sơ" của tab Tài liệu (DocumentsRelationManager): chọn một ảnh JPEG
  // thật ở MỌI lượt; chỉ lượt ACTIONS mới điền nốt và gửi.
  await visit(page, `admin: modal "Đưa tài liệu vào hồ sơ" — chọn ảnh JPEG${ACTIONS ? ' rồi gửi' : ''}`, matterUrl, async (p, entry) => {
    const tab = p.locator('.fi-tabs-item', { hasText: 'Tài liệu' }).first();
    await p.waitForFunction((el) => !el.disabled, await tab.elementHandle());
    await tab.click();
    await settle(p, 300);
    const modal = await openModal(p, 'Đưa tài liệu vào hồ sơ');
    await modal.locator('input[type="file"]').first().setInputFiles({
      name: 'anh-chup-khao-sat.jpg', mimeType: 'image/jpeg', buffer: images.jpeg,
    });
    await modal.getByText('Tải lên thành công').first().waitFor({ timeout: 60000 });
    await requireImagePreview(p, modal, entry);
    if (!ACTIONS) return closeModal(p);

    const name = 'admin đưa một ảnh JPEG vào hồ sơ qua modal tải lên';
    try {
      await modal.getByLabel('Tên tài liệu').fill('Ảnh chụp khảo sát CSP');
      const group = modal.getByLabel('Nhóm tài liệu');
      if (await group.evaluate((el) => el.tagName === 'SELECT')) {
        const values = await group.locator('option').evaluateAll((os) => os.map((o) => o.value).filter(Boolean));
        await group.selectOption(values[0]);
      } else {
        await group.click();
        await p.locator('.fi-select-input-option:visible, [role="option"]:visible').first().click();
      }
      await settle(p, 300);
      await modal.getByRole('button', { name: 'Gửi', exact: true }).click();
      await p.getByText('Đã lưu tài liệu vào hồ sơ.').first().waitFor({ timeout: 60000 });
      action(name, true, `thấy "Đã lưu tài liệu vào hồ sơ." ở ${matterUrl}`);
    } catch (e) {
      action(name, false, e.message.split('\n')[0]);
    }
  });

  await context.close();
}

async function readLoginCode(offset) {
  for (let i = 0; i < 40; i++) {
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
  throw new Error('Không thấy mã một lần trong ' + LOG);
}

async function clientTour(browser) {
  const context = await browser.newContext({ acceptDownloads: true });
  await wire(context);
  const page = await context.newPage();
  // Bản chạy của làn là `php artisan serve` trên bind mount Docker/Windows: một request cập nhật
  // Livewire mất vài giây là thường. Hạn chờ dài để "chậm" không bị đọc thành "hỏng".
  page.setDefaultTimeout(90000);
  page.setDefaultNavigationTimeout(120000);

  await visit(page, 'portal: đăng nhập (mật khẩu + mã một lần)', '/portal/login', async (p) => {
    const offset = fs.existsSync(LOG) ? fs.statSync(LOG).size : 0;
    await p.fill('input[type="email"]', CLIENT.email);
    await p.fill('input[type="password"]', CLIENT.password);
    await p.click('button[type="submit"]');
    const codeInput = p.locator('input[autocomplete="one-time-code"]').first();
    await codeInput.waitFor({ state: 'visible' });
    await settle(p);
    const code = await readLoginCode(offset);
    await codeInput.click();
    await p.keyboard.type(code, { delay: 30 });
    await settle(p, 300);
    const submit = p.locator('button[type="submit"]:visible').first();
    if (!/\/portal\/?$/.test(new URL(p.url()).pathname)) await submit.click().catch(() => {});
    await p.waitForURL(/\/portal\/?$/);
    action('portal đăng nhập đủ hai bước (mật khẩu + mã)', true, 'tới /portal');
  });

  await visit(page, 'portal: danh sách hồ sơ', '/portal');
  const matterUrls = await allHrefs(page, /\/portal\/ho-so\/\d+$/);
  const matterUrl = matterUrls[0];

  await visit(page, 'portal: trang hồ sơ', matterUrl, async () => {
    action('portal mở trang hồ sơ', true, matterUrl);
  });

  // Trang nộp giấy tờ và trang yêu cầu lấy đường dẫn từ chính trang hồ sơ.
  let submitUrl = await firstHref(page, /\/portal\/nop-giay-to\/\d+/);
  let requestUrl = await firstHref(page, /\/portal\/yeu-cau\/\d+/);
  let downloadUrl = await firstHref(page, /\/documents\/\d+\/download/);
  for (const other of matterUrls.slice(1)) {
    if (submitUrl && requestUrl && downloadUrl) break;
    await page.goto(BASE + other);
    await settle(page, 200);
    submitUrl ??= await firstHref(page, /\/portal\/nop-giay-to\/\d+/);
    requestUrl ??= await firstHref(page, /\/portal\/yeu-cau\/\d+/);
    downloadUrl ??= await firstHref(page, /\/documents\/\d+\/download/);
  }

  const pdf = Buffer.from(
    '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' +
      '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n',
  );

  /** Đầu mục còn thiếu đầu tiên: đọc lại từ trang hồ sơ, vì mỗi lần nộp làm một đầu mục đổi trạng thái. */
  async function freshSubmitUrl() {
    for (const u of matterUrls) {
      await page.goto(BASE + u);
      await settle(page, 200);
      const found = await firstHref(page, /\/portal\/nop-giay-to\/\d+/);
      if (found) return found;
    }
    return submitUrl;
  }

  const uploads = [
    { kind: 'ảnh JPEG', file: { name: 'anh-chup-giay-to.jpg', mimeType: 'image/jpeg', buffer: images.jpeg }, image: true, send: ACTIONS },
    { kind: 'ảnh PNG', file: { name: 'anh-chup-giay-to.png', mimeType: 'image/png', buffer: images.png }, image: true, send: ACTIONS },
    { kind: 'PDF', file: { name: 'giay-to-khao-sat.pdf', mimeType: 'application/pdf', buffer: pdf }, image: false, send: false },
  ];

  if (submitUrl) {
    for (const upload of uploads) {
      const url = await freshSubmitUrl();
      await visit(page, `portal: nộp giấy tờ — chọn ${upload.kind}${upload.send ? ' rồi gửi' : ''}`, url, async (p, entry) => {
        const choice = p.locator('[data-portal-block="1"] button[wire\\:click^="chooseItem"]').first();
        if (await choice.count()) {
          await choice.click();
          await settle(p);
        }
        const input = p.locator('input[type="file"]').first();
        await input.waitFor({ state: 'attached', timeout: 15000 });
        await input.setInputFiles(upload.file);
        // FilePond báo "Tải lên thành công" khi tệp đã lên máy chủ (tệp tạm của Livewire).
        await p.getByText('Tải lên thành công').first().waitFor({ timeout: 60000 });
        note(`đã chọn ${upload.kind}, tải lên tạm thành công`);
        // Ảnh: bản xem trước dựng trong Worker `blob:` — đòi bằng chứng nó đã chạy.
        if (upload.image) await requireImagePreview(p, p, entry);

        if (upload.send) {
          const name = `portal nộp một giấy tờ thật (${upload.kind})`;
          try {
            await p.locator('[data-portal-action="send"]').click();
            // Khối "Chúng tôi đã nhận được" chỉ vẽ ra SAU KHI `SubmitClientDocument` chạy xong.
            await p.getByText('Chúng tôi đã nhận được').first().waitFor({ timeout: 60000 });
            action(name, true, `thấy "Chúng tôi đã nhận được" ở ${p.url().replace(BASE, '')}`);
          } catch (e) {
            action(name, false, e.message.split('\n')[0]);
          }
        }
      });
    }

    // M6.5 Task 17 (R10): một lần nộp gồm NHIỀU tệp (`FileUpload::multiple()`). Chọn cả ba tệp
    // trong CÙNG một lượt chọn — hai ảnh (đi qua Worker `blob:` dựng bản xem trước) và một PDF —
    // rồi, ở lượt ACTIONS, gửi cả lô.
    const multi = [uploads[0].file, uploads[1].file, uploads[2].file];
    const multiUrl = await freshSubmitUrl();
    await visit(page, `portal: nộp giấy tờ — chọn ${multi.length} tệp cùng lúc${ACTIONS ? ' rồi gửi' : ''}`, multiUrl, async (p, entry) => {
      const choice = p.locator('[data-portal-block="1"] button[wire\\:click^="chooseItem"]').first();
      if (await choice.count()) {
        await choice.click();
        await settle(p);
      }
      const input = p.locator('input[type="file"]').first();
      await input.waitFor({ state: 'attached', timeout: 15000 });
      await input.setInputFiles(multi);
      // Đếm theo TRẠNG THÁI từng mục FilePond, không theo chữ "Tải lên thành công": vùng
      // aria-live `.filepond--assistant` cũng in đúng chữ đó, nên đếm chữ thì ra đủ 3 trong khi
      // tệp thứ ba còn "Đang tải lên" — lượt đầu gửi đi một lô 2 tệp vì thế. Rồi chờ khối 3
      // ("xem trước", do máy chủ vẽ từ `pendingFiles()`) liệt kê đủ cả lô.
      const deadline = Date.now() + 120000;
      let done = 0;
      let listed = 0;
      while (Date.now() < deadline) {
        done = await p.locator('.filepond--item[data-filepond-item-state="processing-complete"]').count();
        listed = 0;
        for (const f of multi) {
          if (await p.locator('[data-portal-block="3"]', { hasText: f.name }).count()) listed++;
        }
        if (done >= multi.length && listed >= multi.length) break;
        await p.waitForTimeout(500);
      }
      if (done < multi.length || listed < multi.length) {
        throw new Error(`chỉ ${done}/${multi.length} tệp tải lên tạm xong, ${listed}/${multi.length} tệp hiện ở khối xem trước`);
      }
      note(`đã chọn ${multi.length} tệp cùng lúc, cả ${done} tải lên tạm xong và hiện ở khối xem trước`);
      await requireImagePreview(p, p, entry);

      if (ACTIONS) {
        const name = `portal nộp một lô ${multi.length} tệp trong một lần gửi`;
        try {
          await p.locator('[data-portal-action="send"]').click();
          await p.getByText('Chúng tôi đã nhận được').first().waitFor({ timeout: 60000 });
          action(name, true, `thấy "Chúng tôi đã nhận được" ở ${p.url().replace(BASE, '')}`);
        } catch (e) {
          action(name, false, e.message.split('\n')[0]);
        }
      }
    });
  } else {
    begin('portal: nộp giấy tờ');
    note('BỎ QUA: không hồ sơ nào của khach1 có đường nộp giấy tờ');
  }

  if (!FULL) {
    // SCOPE=uploads: dừng sau các bước tải ảnh lên.
  } else if (requestUrl) {
    await visit(page, 'portal: yêu cầu', requestUrl);
  } else {
    begin('portal: yêu cầu');
    note('BỎ QUA: không thấy đường tới trang yêu cầu');
  }

  if (FULL) await visit(page, 'portal: đổi mật khẩu', '/portal/change-password');

  if (ACTIONS && FULL) {
    if (downloadUrl) {
      begin('HÀNH ĐỘNG portal: tải tệp');
      try {
        const back = matterUrls.find(Boolean);
        await page.goto(BASE + back);
        await settle(page, 200);
        // Đường tải có chữ ký hết hạn sau 5 phút: lấy lại ngay trước khi bấm.
        for (const u of matterUrls) {
          await page.goto(BASE + u);
          await settle(page, 200);
          if (await firstHref(page, /\/documents\/\d+\/download/)) break;
        }
        const [download] = await Promise.all([
          page.waitForEvent('download', { timeout: 15000 }),
          page.locator('a[href*="/documents/"][href*="/download"]').first().click(),
        ]);
        const file = await download.path();
        const size = fs.statSync(file).size;
        action('portal tải một tệp', size > 0, `${download.suggestedFilename()} (${size} byte)`);
      } catch (e) {
        action('portal tải một tệp', false, e.message.split('\n')[0]);
      }
    } else {
      action('portal tải một tệp', false, 'không hồ sơ nào có đường tải');
    }
  }

  await context.close();
}

async function wire(context) {
  await context.exposeBinding('__cspReport', (_source, v) => {
    if (current) current.violations.push(v);
  });
  await context.exposeBinding('__workerReport', (_source, kind) => {
    if (current) current.workers[kind]++;
  });
  await context.addInitScript(() => {
    // Bọc `Worker` để biết Worker nào được dựng, từ `blob:` hay không, và nó có THẬT SỰ chạy
    // (trả thông điệp đầu tiên) hay hỏng (sự kiện `error`, hoặc hàm dựng ném lỗi).
    const NativeWorker = window.Worker;
    if (NativeWorker) {
      window.Worker = function (url, options) {
        window.__workerReport(String(url).startsWith('blob:') ? 'blob' : 'other');
        let worker;
        try {
          worker = new NativeWorker(url, options);
        } catch (e) {
          window.__workerReport('error');
          throw e;
        }
        let first = true;
        worker.addEventListener('message', () => {
          if (first) window.__workerReport('message');
          first = false;
        });
        worker.addEventListener('error', () => window.__workerReport('error'));
        return worker;
      };
      window.Worker.prototype = NativeWorker.prototype;
    }
    document.addEventListener('securitypolicyviolation', (e) => {
      window.__cspReport({
        directive: e.effectiveDirective,
        blocked: e.blockedURI,
        sample: e.sample,
        source: (e.sourceFile || '').replace(location.origin, ''),
        line: e.lineNumber,
        disposition: e.disposition,
      });
    });
  });
  context.on('page', (page) => {
    page.on('pageerror', (err) => {
      if (!current) return;
      // Một promise hành động Livewire bị từ chối với giá trị là id component (20 ký tự, không
      // stack) — request bị huỷ khi trang rời đi. Có ở CẢ chế độ CSP off (xem khảo sát), nên
      // không phải do CSP: ghi vào ghi chú của trang, không tính là lỗi.
      if (/^[A-Za-z0-9]{20}$/.test(err.message)) current.notes.push('Livewire huỷ request');
      else current.errors.push('pageerror: ' + err.message.split('\n')[0]);
    });
    page.on('console', (msg) => {
      if (msg.type() === 'error' && current && !/Content Security Policy/.test(msg.text())) {
        current.errors.push('console: ' + msg.text().split('\n')[0].slice(0, 200));
      }
    });
  });
}

function violationKey(v) {
  const where = v.blocked === 'inline' || v.blocked === 'eval' ? `${v.source}:${v.line}` : '';
  return `${v.directive} ${v.blocked} ${where} ${v.sample || ''}`.trim();
}

function report() {
  let total = 0;
  let errors = 0;
  // Trang cố ý trả 404 (trang lỗi; đổi mật khẩu với tài khoản không bị buộc đổi): dòng console
  // "Failed to load resource … 404" là chính phản hồi của trang, không phải một lỗi JS.
  for (const p of pages) {
    if (p.accepted) {
      p.acceptedViolations = p.violations.filter((v) => p.accepted.test(v.blocked || ''));
      p.violations = p.violations.filter((v) => !p.accepted.test(v.blocked || ''));
      if (p.acceptedViolations.length) {
        p.notes.push(`${p.acceptedViolations.length} vi phạm đã chấp nhận: ${[...new Set(p.acceptedViolations.map((v) => v.blocked))].join(', ')}`);
      }
    }
    if (p.status !== 404) continue;
    p.errors = p.errors.filter((e) => !/Failed to load resource: .* 404/.test(e));
    p.notes.push('trả 404 như mong đợi');
  }
  console.log(`\n=== ${LABEL} — ${BROWSER} — ${BASE} ===`);
  console.log('| Trang | Vi phạm (sự kiện) | Vi phạm khác nhau | Loại (chỉ thị → nguồn bị chặn) | Lỗi JS | Worker blob:/chạy/lỗi |');
  console.log('|---|---|---|---|---|---|');
  for (const p of pages) {
    const kinds = {};
    for (const v of p.violations) {
      const k = `${v.directive} → ${v.blocked || '?'}`;
      kinds[k] = (kinds[k] || 0) + 1;
    }
    total += p.violations.length;
    errors += p.errors.length;
    const kindText = Object.entries(kinds).map(([k, n]) => `${k} ×${n}`).join('; ') || '—';
    const noteText = p.notes.length ? ` (${p.notes.join('; ')})` : '';
    const distinctOnPage = new Set(p.violations.map(violationKey)).size;
    const w = p.workers;
    const workerText = w.blob || w.other || w.error ? `${w.blob}/${w.message}/${w.error}` : '—';
    console.log(`| ${p.label}${noteText} | ${p.violations.length} | ${distinctOnPage} | ${kindText} | ${p.errors.length} | ${workerText} |`);
  }
  const workers = pages.reduce(
    (a, p) => ({ blob: a.blob + p.workers.blob, message: a.message + p.workers.message, error: a.error + p.workers.error }),
    { blob: 0, message: 0, error: 0 },
  );
  console.log(`\nTổng vi phạm: ${total}. Lỗi JS: ${errors}. Worker blob: ${workers.blob}, đã chạy ${workers.message}, lỗi ${workers.error}.`);

  const distinct = new Map();
  for (const p of pages) for (const v of p.violations) {
    const k = violationKey(v);
    if (!distinct.has(k)) distinct.set(k, { ...v, pages: new Set() });
    distinct.get(k).pages.add(p.label);
  }
  if (distinct.size) {
    console.log('\nVi phạm khác nhau:');
    for (const [k, v] of distinct) console.log(`  - ${k}  [${v.pages.size} trang]`);
  }
  const errs = new Set(pages.flatMap((p) => p.errors));
  if (errs.size) {
    console.log('\nLỗi JS khác nhau:');
    for (const e of errs) console.log('  - ' + e);
  }

  const unnonced = new Map();
  for (const p of pages) for (const s of p.scripts) if (!s.hasNonce) {
    if (!unnonced.has(s.head)) unnonced.set(s.head, { hashes: new Set(), pages: new Set() });
    unnonced.get(s.head).hashes.add(s.sha256);
    unnonced.get(s.head).pages.add(p.label);
  }
  console.log(`\nScript nội tuyến KHÔNG nonce: ${unnonced.size} loại.`);
  for (const [head, v] of unnonced) {
    console.log(`  - "${head}" — ${v.hashes.size} băm khác nhau trên ${v.pages.size} trang`);
  }

  if (actions.length) {
    console.log('\nHành động chính:');
    for (const a of actions) console.log(`  [${a.ok ? 'OK ' : 'HỎNG'}] ${a.name}${a.detail ? ' — ' + a.detail : ''}`);
  }

  if (OUT) {
    const serialisable = pages.map((p) => ({ ...p, accepted: p.accepted ? String(p.accepted) : undefined }));
    fs.writeFileSync(OUT, JSON.stringify({ label: LABEL, base: BASE, pages: serialisable, actions }, null, 2));
  }

  return total === 0 && errors === 0 && actions.every((a) => a.ok);
}

(async () => {
  const browser = await playwright[BROWSER].launch();
  try {
    const canvasPage = await browser.newPage();
    const [jpeg, png] = await canvasPage.evaluate(() => {
      const c = document.createElement('canvas');
      c.width = 1200;
      c.height = 900;
      const x = c.getContext('2d');
      const g = x.createLinearGradient(0, 0, 1200, 900);
      g.addColorStop(0, '#f7f8fa');
      g.addColorStop(1, '#101d35');
      x.fillStyle = g;
      x.fillRect(0, 0, 1200, 900);
      x.fillStyle = '#c6283d';
      x.font = 'bold 64px sans-serif';
      x.fillText('Giấy tờ khảo sát CSP', 80, 450);
      return [c.toDataURL('image/jpeg', 0.85), c.toDataURL('image/png')];
    });
    await canvasPage.close();
    images.jpeg = Buffer.from(jpeg.split(',')[1], 'base64');
    images.png = Buffer.from(png.split(',')[1], 'base64');
    console.log(`${BROWSER} ${browser.version()}: ảnh JPEG ${images.jpeg.length} byte, PNG ${images.png.length} byte`);

    await staffTour(browser);
    await clientTour(browser);
  } catch (e) {
    console.error('Lượt khảo sát dừng giữa chừng:', e.message);
    if (current) current.errors.push('survey: ' + e.message.split('\n')[0]);
  } finally {
    await browser.close();
  }
  process.exit(report() ? 0 : 1);
})();
