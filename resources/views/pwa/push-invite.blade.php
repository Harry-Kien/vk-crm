{{--
    M12 R8 — dải mời "Bật thông báo trên máy này?", gắn qua `PanelsRenderHook::CONTENT_START` ở cả
    hai panel provider, chỉ trên trang ĐÃ ĐĂNG NHẬP khi máy chủ có khoá VAPID (cùng điều kiện với ba
    thuộc tính push của thẻ `register.js` — `App\Support\Pwa\RegisterScript::pushData()`).

    In sẵn, ẩn (`hidden`). `public/pwa/register.js` chỉ bỏ `hidden` khi trình duyệt này ĐÃ có một
    đăng ký push mà máy chủ trả lời "không phải của bạn" (máy dùng chung: đăng ký thuộc người đăng
    nhập trước, hay không thuộc ai), hoặc khi khoá của máy chủ đã đổi (R7). Lượt kiểm không chuyển chủ
    gì; chỉ nút "Bật" ở đây (`data-vk-push-enable`, cùng trình xử lý với nút trên trang thiết bị) mới
    chuyển — quyền hệ điều hành đã cấp nên không có hộp hỏi lần nữa. "Để sau" chỉ ẩn dải trên trang
    này; lượt kiểm chạy một lần mỗi phiên nên dải không quay lại ở trang sau.

    `display` ở thẻ con, không ở thẻ `hidden` (một `display` nội tuyến thắng luật ẩn của trình duyệt).
--}}
@if (\App\Support\Pwa\RegisterScript::pushData(filament()->getId()) !== [])
    <div data-vk-push-invite hidden>
        <div role="status" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;padding:0.75rem 1rem;margin-bottom:1rem;border-radius:0.75rem;background-color:color-mix(in srgb, var(--primary-500) 12%, transparent);">
            <p style="flex:1 1 12rem;font-weight:600;">{{ __('push.invite.text') }}</p>
            <button type="button" data-vk-push-enable style="min-height:44px;padding:0.625rem 1rem;border-radius:0.5rem;border:0;font-weight:600;cursor:pointer;background-color:var(--primary-600);color:var(--primary-50);">{{ __('push.invite.enable') }}</button>
            <button type="button" data-vk-push-dismiss style="min-height:44px;padding:0.625rem 1rem;border-radius:0.5rem;border:0;cursor:pointer;background-color:transparent;color:inherit;">{{ __('push.invite.dismiss') }}</button>
        </div>
    </div>
@endif
