<?php

namespace App\Actions\Document\Concerns;

use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bỏ `ClientPortalScope` ra khỏi một truy vấn, tường minh — dùng chung cho mọi Action tài liệu.
 *
 * `SubmitClientDocument` chạy với guard `client` đang mở trong đời thật, nên mọi truy vấn của nó
 * sẽ tự động bị cắt theo khách đang đăng nhập nếu không gỡ scope ra. Nghe thì có vẻ an toàn hơn,
 * nhưng nó sai ở hai đầu:
 *
 * - **Sai về tính đúng đắn.** Scope trên `Document` đòi `client_can_view = true` và
 *   `status = published`, nên một bản nhóm A cũ đã bị tắt cờ hiển thị sẽ vô hình với phép tính
 *   version — và lần nộp mới lại mang số 1 lần nữa, ghi đè ý nghĩa của bản cũ. Chuỗi version
 *   phải đọc từ dữ liệu thật. Cùng hình dạng ở `PublishDocument` và `RegroupDocument`: bản ghi
 *   được đọc lại dưới khoá để KHÔNG tin đối tượng caller cầm trong tay, và một lần đọc lại bị
 *   guard đang mở cắt mất trả lời "không có bản ghi nào như vậy" về một bản ghi đang tồn tại.
 * - **Sai về chỗ đặt quyết định.** Một Action để phạm vi dữ liệu phụ thuộc vào guard nào đang mở
 *   là một Action đúng cho tới lần đầu ai đó gọi nó từ một job, một lệnh console, hay một phiên
 *   thuộc về người khác. Quyền đã được hỏi một lần, tường minh, trên `$actor` — và câu trả lời
 *   đó phải là câu duy nhất quyết định.
 *
 * Ở phía nhân sự scope thường không kích hoạt, nên câu này ở đó là phòng thủ nhiều lớp — nhưng
 * nó phòng đúng thứ đã xảy ra một lần rồi: một nhân sự đăng nhập cả /admin lẫn /portal có cả hai
 * guard cùng xác thực (xem `ClientPortalScope::isActive()`). Và M5 mở portal, nơi guard `client`
 * là guard thường trực.
 *
 * Tầng phân quyền không bị nới ra chút nào: `ChecksPortalVisibility` bên trong policy vẫn chạy
 * scope thật qua `ClientPortalScope::actingAs($actor)`.
 *
 * Trait này tách ra khỏi `StoresDocumentFile` ở vòng rà soát cuối M4: `PublishDocument` và
 * `RegroupDocument` không lưu tệp nên không dùng trait kia, và vì thế chúng là hai Action duy
 * nhất của milestone đọc `Document::query()` trần.
 */
trait ReadsWithoutPortalScope
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopelessly(Builder $query): Builder
    {
        return $query->withoutGlobalScope(ClientPortalScope::class);
    }
}
