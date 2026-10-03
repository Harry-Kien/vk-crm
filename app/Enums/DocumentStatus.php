<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case InternalDraft = 'internal_draft';
    case PendingApproval = 'pending_approval';
    case SignedFiled = 'signed_filed';
    case Published = 'published';

    /**
     * M7 Task 7: văn phòng đã RÚT LẠI một tài liệu từng ra tới khách (`RetractDocument`). Trạng
     * thái cuối — không công bố lại được (`PublishDocument` từ chối); tệp và nhật ký tải giữ
     * nguyên. Khách thấy một dòng "Văn phòng đã rút lại tài liệu này" thay cho tài liệu.
     */
    case Retracted = 'retracted';

    public function label(): string
    {
        return __('enums.document_status.'.$this->value);
    }
}
