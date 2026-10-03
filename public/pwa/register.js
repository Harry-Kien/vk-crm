/*
 * VK-CRM — đăng ký service worker và thông báo đẩy của app trên điện thoại (M12, phán quyết R4/R5/R8).
 *
 * Tệp TĨNH, nạp bằng thẻ <script src="/pwa/register.js?v=<băm>" defer data-…> của
 * resources/views/pwa/head.blade.php — không script nội tuyến (CSP M8 R4 không phải thêm gì). Tham số
 * đọc từ data-* của CHÍNH thẻ đó; mã không mang chuỗi hiển thị nào — câu chữ đã in sẵn trong trang
 * (ẩn), tệp này chỉ chọn khối nào hiện. data-sw, data-scope (/admin, /portal, không "/" cuối): worker
 * của panel. data-push-key (khoá CÔNG KHAI VAPID), data-push-url (POST/DELETE …/push/subscriptions),
 * data-push-check ("1" khi phiên máy chủ chưa được kiểm): chỉ trên trang ĐÃ ĐĂNG NHẬP của máy chủ có
 * khoá (App\Support\Pwa\RegisterScript::pushData()) — thiếu thì không làm gì về push.
 *
 * Push (R8):
 *  - KHÔNG BAO GIỜ hỏi quyền lúc tải trang: Notification.requestPermission() chỉ gọi NGAY trong trình
 *    xử lý cú bấm nút [data-vk-push-enable] (iOS đòi thao tác người dùng; Chrome chặn lời xin tự bật).
 *  - Lượt kiểm sync=1 (một lần mỗi phiên máy chủ; trên trang thiết bị thì mỗi lần mở): gửi đăng ký
 *    đang có CHỈ ĐỂ HỎI. "owned" → máy này đang nhận; "not_owned" (máy dùng chung) → dải mời
 *    [data-vk-push-invite]. Chỉ cú bấm Bật mới chuyển chủ một endpoint.
 *  - R7: khoá của đăng ký đang có khác data-push-key → huỷ đăng ký cũ, mời bật lại.
 *  - iPhone/iPad chưa "Thêm vào Màn hình chính" (iOS chỉ cho push trong app đã cài) → khối hướng dẫn.
 *  - redirect: 'manual': một 302 (chưa đổi mật khẩu lần đầu, vừa bị vô hiệu) là một lần thất bại; đi
 *    theo nó sẽ tiêu mất thông báo flash mà trang đăng nhập cần hiện.
 *
 * Không có service worker thì không làm gì. tests/Feature/Pwa/RegisterScriptTest.php ghim hợp đồng
 * data-* và giới hạn 200 dòng; hành vi kiểm bằng trình duyệt thật (tools/pwa/survey-push.cjs).
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) return;

  var data = script.dataset;
  if (!data.sw || !data.scope) return;

  var hasWorker = 'serviceWorker' in navigator;

  if (hasWorker) {
    // Đăng ký sau khi trang tải xong, để lượt tải worker và bộ đệm không giành băng thông với trang.
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(data.sw, { scope: data.scope }).catch(function (error) {
        // Không đăng ký được thì app vẫn chạy như một trang web thường — chỉ ghi ra console.
        console.warn('[vk-pwa] service worker registration failed', error);
      });
    });
  }

  if (!data.pushKey || !data.pushUrl) return;

  var canPush = hasWorker && 'PushManager' in window && 'Notification' in window;
  var ios = /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var installed = navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  var staleKey = false;
  var busy = false;

  function show(state) {
    var blocks = document.querySelectorAll('[data-vk-push-state]');
    for (var i = 0; i < blocks.length; i++) {
      blocks[i].hidden = blocks[i].getAttribute('data-vk-push-state') !== state;
    }
  }

  function invite(visible) {
    var strip = document.querySelector('[data-vk-push-invite]');
    if (strip) strip.hidden = !visible;
  }

  function keyBytes() {
    var base64 = data.pushKey.replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(base64 + '==='.slice((base64.length + 3) % 4));
    var bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return bytes;
  }

  function sameKey(subscription) {
    var key = subscription.options && subscription.options.applicationServerKey;
    // Trình duyệt không cho đọc khoá thì không so được: coi như cùng khoá.
    if (!key) return true;
    var a = new Uint8Array(key);
    var b = keyBytes();
    if (a.length !== b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i] !== b[i]) return false;
    }
    return true;
  }

  // Đăng ký đang có của trình duyệt; khoá lệch (R7) thì huỷ nó và trả null.
  function existing(registration) {
    return registration.pushManager.getSubscription().then(function (subscription) {
      if (!subscription || sameKey(subscription)) return subscription;
      staleKey = true;
      return subscription.unsubscribe().then(function () { return null; });
    });
  }

  function send(subscription, extra) {
    var json = subscription.toJSON();
    var encodings = window.PushManager.supportedContentEncodings || ['aes128gcm'];
    var body = {
      endpoint: json.endpoint,
      keys: json.keys,
      contentEncoding: encodings.indexOf('aes128gcm') === -1 ? 'aesgcm' : 'aes128gcm'
    };
    for (var name in extra) body[name] = extra[name];
    var token = document.querySelector('meta[name="csrf-token"]');

    return fetch(data.pushUrl, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'manual',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token ? token.getAttribute('content') : ''
      },
      body: JSON.stringify(body)
    }).then(function (response) {
      if (!response.ok) throw new Error('push endpoint answered ' + response.status);
      return response.json();
    });
  }

  function enable() {
    if (busy) return;
    busy = true;
    // Hỏi quyền NGAY trong trình xử lý cú bấm, trước mọi lời gọi bất đồng bộ nào.
    var asked = Notification.requestPermission();

    Promise.resolve(asked).then(function (permission) {
      if (permission !== 'granted') {
        show(permission === 'denied' ? 'denied' : 'ready');
        return;
      }
      return navigator.serviceWorker.ready.then(function (registration) {
        return existing(registration).then(function (subscription) {
          return subscription || registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes() });
        });
      }).then(function (subscription) {
        return send(subscription, {});
      }).then(function () {
        invite(false);
        show('enabled');
        if (window.Livewire) window.Livewire.dispatch('vk-push-devices-changed');
      });
    }).catch(function (error) {
      console.warn('[vk-pwa] push enable failed', error);
      show('failed');
    }).then(function () {
      busy = false;
    });
  }

  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    if (target.closest('[data-vk-push-dismiss]')) {
      invite(false);
    } else if (target.closest('[data-vk-push-enable]')) {
      event.preventDefault();
      if (canPush) enable();
    }
  });

  window.addEventListener('load', function () {
    var devicePage = document.querySelector('[data-vk-push-state]') !== null;

    if (ios && !installed) return show('ios-install');
    if (!canPush) return show('unsupported');
    if (Notification.permission === 'denied') return show('denied');

    navigator.serviceWorker.ready.then(existing).then(function (subscription) {
      if (!subscription) {
        show('ready');
        if (staleKey && !devicePage) invite(true);
        return;
      }
      if (data.pushCheck !== '1' && !devicePage) return;

      return send(subscription, { sync: 1 }).then(function (answer) {
        if (answer.status === 'owned') return show('enabled');
        show('ready');
        if (!devicePage) invite(true);
      });
    }).catch(function (error) {
      console.warn('[vk-pwa] push check failed', error);
      show('ready');
    });
  });
})();
