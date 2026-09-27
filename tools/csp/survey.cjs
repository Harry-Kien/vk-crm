#!/usr/bin/env node
/*
 * Khảo sát CSP có đo đạc (M8a Task 4, phán quyết R4 của kế hoạch M8) — đi qua các trang chính
 * của CẢ HAI panel bằng một Chromium thật, với đăng nhập thật, và ghi từng vi phạm CSP.
 *
 * Kết quả và phán quyết: docs/research/2026-09-26-csp-khao-sat.md.
 *
 * Playwright KHÔNG nằm trong package.json của repo. Cài nó vào một thư mục ngoài repo rồi trỏ
 * NODE_PATH vào đó (tệp này là CommonJS chính vì thế — `import` của ESM không đọc NODE_PATH):
 *
 *   mkdir -p /d/vkwt/m8-tools && cd /d/vkwt/m8-tools && npm i playwright && npx playwright install chromium
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
 *   ACTIONS  =1 thì làm thêm các HÀNH ĐỘNG CHÍNH, có ghi dữ liệu: nộp một giấy tờ thật, tải một
 *            tệp, chuyển giai đoạn một vụ việc. Dùng cho lượt kiểm ở chế độ enforce.
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
const { chromium } = require('playwright');

const BASE = (process.env.BASE || 'http://localhost:8090').replace(/\/$/, '');
const LOG = process.env.LOG || path.resolve(__dirname, '../../storage/logs/laravel.log');
const LABEL = process.env.LABEL || 'survey';
const OUT = process.env.OUT || '';
const ACTIONS = process.env.ACTIONS === '1';

const STAFF = { email: 'admin@luatvukhang.com', password: 'password' };
const CLIENT = { email: 'khach1@example.com', password: 'password' };

/** Kết quả theo trang: { label, url, violations[], errors[], scripts[], notes[] }. */
const pages = [];
const actions = [];
let current = null;

function begin(label) {
  current = { label, url: '', violations: [], errors: [], scripts: [], notes: [] };
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

// ---------------------------------------------------------------------------------------------

async function staffTour(browser) {
  const context = await browser.newContext({ acceptDownloads: true });
  await wire(context);
  const page = await context.newPage();
  // Bản chạy của làn là `php artisan serve` trên bind mount Docker/Windows: một request cập nhật
  // Livewire mất vài giây là thường. Hạn chờ dài để "chậm" không bị đọc thành "hỏng".
  page.setDefaultTimeout(90000);
  page.setDefaultNavigationTimeout(120000);

  await visit(page, 'admin: đăng nhập', '/admin/login', async (p) => {
    await p.fill('input[type="email"]', STAFF.email);
    await p.fill('input[type="password"]', STAFF.password);
    await p.click('button[type="submit"]');
    await p.waitForURL(/\/admin\/?$/);
  });

  await visit(page, 'admin: bảng điều khiển', '/admin');
  await visit(page, 'admin: danh sách vụ việc', '/admin/matters');
  const matterUrl = await firstHref(page, /\/admin\/matters\/\d+$/);

  await visit(page, 'admin: trang vụ việc + từng tab', matterUrl, async (p) => {
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

  await visit(page, 'admin: form "Chuyển giai đoạn"', matterUrl, async (p) => {
    await openStageTab(p);
    await openModal(p, 'Chuyển giai đoạn');
    await closeModal(p);
  });

  await visit(page, 'admin: form "Thêm cập nhật"', matterUrl, async (p) => {
    await openStageTab(p);
    await openModal(p, 'Thêm cập nhật');
    await closeModal(p);
  });

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
  await visit(page, 'web: trang 404', '/khong-ton-tai-' + Date.now());
  await visit(page, 'web: /up (kiểm tra sống)', '/up');
  // Trang /up của Laravel nạp Tailwind từ cdn.jsdelivr.net để tô chữ "Application up". CSP chặn
  // nó là ĐÚNG (không cho script bên thứ ba), trang vẫn trả 200 — thứ duy nhất bộ giám sát đọc.
  // Chấp nhận có chủ đích, xem docs/research/2026-09-26-csp-khao-sat.md.
  current.accepted = /^https:\/\/cdn\.jsdelivr\.net\//;

  if (ACTIONS) {
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

  if (submitUrl) {
    await visit(page, 'portal: nộp giấy tờ (mở form, chọn tệp)', submitUrl, async (p) => {
      const choice = p.locator('[data-portal-block="1"] button[wire\\:click^="chooseItem"]').first();
      if (await choice.count()) {
        await choice.click();
        await settle(p);
      }
      const input = p.locator('input[type="file"]').first();
      await input.waitFor({ state: 'attached', timeout: 15000 });
      await input.setInputFiles({ name: 'giay-to-khao-sat.pdf', mimeType: 'application/pdf', buffer: pdf });
      // FilePond báo "Tải lên thành công" khi tệp đã lên máy chủ (tệp tạm của Livewire).
      await p.getByText('Tải lên thành công').first().waitFor({ timeout: 60000 });
      await settle(p);
      note('đã chọn một tệp PDF, tải lên tạm thành công');

      if (ACTIONS) {
        try {
          await p.locator('[data-portal-action="send"]').click();
          // Khối "Chúng tôi đã nhận được" chỉ vẽ ra SAU KHI `SubmitClientDocument` chạy xong.
          await p.getByText('Chúng tôi đã nhận được').first().waitFor({ timeout: 60000 });
          action('portal nộp một giấy tờ thật', true, `thấy "Chúng tôi đã nhận được" ở ${p.url().replace(BASE, '')}`);
        } catch (e) {
          action('portal nộp một giấy tờ thật', false, e.message.split('\n')[0]);
        }
      }
    });
  } else {
    begin('portal: nộp giấy tờ');
    note('BỎ QUA: không hồ sơ nào của khach1 có đường nộp giấy tờ');
  }

  if (requestUrl) {
    await visit(page, 'portal: yêu cầu', requestUrl);
  } else {
    begin('portal: yêu cầu');
    note('BỎ QUA: không thấy đường tới trang yêu cầu');
  }

  await visit(page, 'portal: đổi mật khẩu', '/portal/change-password');

  if (ACTIONS) {
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
  await context.addInitScript(() => {
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
  console.log(`\n=== ${LABEL} — ${BASE} ===`);
  console.log('| Trang | Vi phạm (sự kiện) | Vi phạm khác nhau | Loại (chỉ thị → nguồn bị chặn) | Lỗi JS |');
  console.log('|---|---|---|---|---|');
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
    console.log(`| ${p.label}${noteText} | ${p.violations.length} | ${distinctOnPage} | ${kindText} | ${p.errors.length} |`);
  }
  console.log(`\nTổng vi phạm: ${total}. Lỗi JS: ${errors}.`);

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
  const browser = await chromium.launch();
  try {
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
