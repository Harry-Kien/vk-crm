<?php

namespace App\Exceptions;

use App\Models\Concerns\IsMcpDraft;
use DomainException;

/**
 * Một nháp do AI soạn đã được DÙNG (một dòng tiến độ hay một câu trả lời thật đã sinh ra từ nó) hoặc
 * đã bị BỎ, nên không dùng, không bỏ và không sửa thêm được nữa (M11 Task 12, {@see IsMcpDraft}).
 *
 * Hai đường ném: Action dùng/bỏ nháp kiểm lại trạng thái DƯỚI KHOÁ (hai người cùng mở một nháp ở hai
 * tab), và hook `saving` của model chặn mọi lần ghi khác lên một nháp đã xong. Câu nói thẳng việc cần
 * làm (tải lại trang), vì người đọc nó vừa nhìn một màn hình đã cũ.
 */
class McpDraftNotPending extends DomainException
{
    public static function make(): self
    {
        return new self(__('ai_drafts.not_pending'));
    }
}
