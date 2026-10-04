<?php

namespace App\Enums;

/**
 * Vì sao một dòng `drive_objects` đã rời chỉ mục sống — cột `drive_objects.retired_reason` (kế hoạch
 * M14, R4, R8, R11). Dòng rời chỉ mục có `object_key = NULL`, `former_key` giữ khoá cũ và
 * `retired_at`; nó KHÔNG bao giờ bị xoá, vì nó là dấu vết của một tệp vẫn có thể còn trên Drive.
 *
 * - `trashed`: CRM đã cho tệp vào thùng rác của Shared Drive (xoá media, hay bản tải lên lệch md5).
 *   Thùng rác tự xoá sau 30 ngày; CRM không bao giờ xoá vĩnh viễn (R8);
 * - `superseded`: dựng lại chỉ mục (`vkcrm:storage:reindex`) thấy cùng khoá trên một Shared Drive
 *   KHÁC, đang được cấu hình — dòng cũ nhường chỗ, tệp cũ không bị chạm.
 */
enum DriveObjectRetirement: string
{
    case Trashed = 'trashed';
    case Superseded = 'superseded';

    public function label(): string
    {
        return __('enums.drive_object_retirement.'.$this->value);
    }
}
