<?php

namespace App\Exceptions;

use DomainException;

/**
 * Bên đang thao tác (sửa hoặc gỡ) không còn tồn tại dưới khoá — đã bị GỠ (xoá mềm), hoặc chưa từng
 * có dòng nào mang đúng id đó (M6.5 Task 9, fix round 1, C1).
 *
 * Kịch bản: hai tab (hai người, hoặc cùng một người mở hai tab) cùng nhìn vào một bên. Một tab gỡ
 * nó; tab kia — modal SỬA hoặc GỠ đã mở TRƯỚC lúc gỡ, không hề biết — gửi lại đúng dòng đó.
 * `MatterParty` dùng `SoftDeletes`, nên một `lockForUpdate()->firstOrFail()` trần trên id đó ném
 * `ModelNotFoundException` — một trang lỗi 404 không câu tiếng Việt nào, và (khác mọi luật nghiệp
 * vụ khác của hai Action này) KHÔNG phải `DomainException`, nên không đi qua được lưới
 * `catch (DomainException $exception)` mà `PartiesRelationManager` đã có sẵn cho `editParty()`/
 * `removeParty()`.
 *
 * `UpdateMatterParty::handle()` và `RemoveMatterParty::handle()` giờ tự kiểm tra: khoá xong mà
 * không tìm thấy dòng (soft-deleted đã bị `SoftDeletingScope` loại, hoặc id không tồn tại) thì ném
 * lớp này thay vì để `ModelNotFoundException` lọt ra — đúng lớp `DomainException` mà hai màn hình
 * đã bắt, nên chỉ cần dạy chúng hiển thị một Notification thay vì một lỗi form (không có ô nào để
 * gắn lỗi vào nữa — cả dòng đã biến mất).
 */
class MatterPartyAlreadyRemoved extends DomainException
{
    public static function make(): self
    {
        return new self(__('matters.parties.already_removed'));
    }
}
