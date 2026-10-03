<?php

namespace App\Exceptions;

use App\Actions\Document\SubmitClientDocument;
use App\Support\OfficeProfile;
use DomainException;

/**
 * M7 Task 3 — khách cố nộp tệp vào một vụ việc ĐÃ KẾT THÚC, qua cổng khách
 * ({@see SubmitClientDocument}).
 *
 * **Không dùng `AuthorizationException`, dù ba tình huống khác của cùng Action đều dùng nó.**
 * `App\Filament\Portal\Pages\SubmitDocument::submit()` đổi MỌI `AuthorizationException` từ
 * Action thành `abort(404)`, VỨT bỏ câu chữ — đúng và cần thiết cho ba tình huống của
 * `SubmitClientDocument::refuse()` (SPEC §10.10: không tồn tại/đã xoá khỏi danh mục/của người
 * khác phải trả lời giống hệt nhau, không câu nào được lộ ra để trở thành máy dò). "Vụ đã đóng"
 * KHÔNG thuộc họ đó: khách ở đây đã có quyền hợp lệ, hoàn toàn xác thực trên đúng đầu mục này —
 * không có gì để giấu — và câu cần nói (mời gọi hotline) chính là thứ R8/ctl-3 đòi khách phải đọc
 * được, không phải một trang 404 trống trơn.
 *
 * `DomainException` (không `ValidationException`, không `AuthorizationException`) là đúng họ mà
 * `SubmitDocument::submit()` đã có sẵn một nhánh cho: nó dán `$exception->getMessage()` vào ô
 * tệp — màn hình này có ĐÚNG MỘT ô nên mọi câu nói với khách đều đi qua đó, kể cả một câu nói về
 * trạng thái bản ghi chứ không về cái tệp (cùng lý lẽ `FileRejected` đã dùng).
 */
class MatterClosedForSubmission extends DomainException
{
    public static function make(): self
    {
        return new self(__('checklist.submit.matter_closed', [
            'hotline' => OfficeProfile::current()->hotline(),
        ]));
    }
}
