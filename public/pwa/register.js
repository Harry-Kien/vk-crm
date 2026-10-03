/*
 * VK-CRM — đăng ký service worker của app trên điện thoại (M12, phán quyết R4/R5).
 *
 * Tệp TĨNH, nạp bằng một thẻ <script src="/pwa/register.js?v=<băm nội dung>" defer data-…> do
 * resources/views/pwa/head.blade.php in ra — không script nội tuyến nào, nên CSP của M8 R4 không
 * phải thêm gì. Mọi tham số đọc từ data-* của CHÍNH thẻ đó, render từ PHP; mã không viết cứng chuỗi
 * hiển thị nào (chuỗi tiếng Việt mà Task 5 cần cũng đi qua data-*):
 *
 *   data-sw     URL service worker của panel (/admin/sw.js, /portal/sw.js)
 *   data-scope  scope của app, KHÔNG dấu "/" cuối (/admin, /portal) — máy chủ gửi header
 *               Service-Worker-Allowed tương ứng
 *
 * Trình duyệt không có service worker thì không làm gì: trang chạy y như trước M12.
 * tests/Feature/Pwa/RegisterScriptTest.php ghim hợp đồng data-* và giới hạn 200 dòng.
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script || !('serviceWorker' in navigator)) return;

  var data = script.dataset;
  if (!data.sw || !data.scope) return;

  // Đăng ký sau khi trang tải xong, để lượt tải worker và bộ đệm không giành băng thông với trang.
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(data.sw, { scope: data.scope }).catch(function (error) {
      // Không đăng ký được thì app vẫn chạy như một trang web thường — chỉ ghi ra console.
      console.warn('[vk-pwa] service worker registration failed', error);
    });
  });
})();
