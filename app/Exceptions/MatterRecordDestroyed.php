<?php

namespace App\Exceptions;

use DomainException;

/**
 * Làn fm, mục B2 (kiểm tra nghiệp vụ 2026-10-09): hồ sơ đã ghi quyết định tiêu huỷ
 * (`matter_archives.destroyed_at`, `RecordMatterDestruction`) là trạng thái KHOÁ. Sinh lại gói bàn
 * giao, chuyển giai đoạn hay thêm cập nhật (kể cả đường bỏ qua của quản trị viên — mở lại một hồ sơ
 * đã tiêu huỷ là sai lịch sử), công bố hay tải lên tài liệu đều từ chối bằng lớp này. Câu hỏi về
 * TRẠNG THÁI bản ghi, không phải quyền — `DomainException` thuần, `ReportsActionFailures` đổi thành
 * thông báo.
 */
class MatterRecordDestroyed extends DomainException
{
    public static function make(): self
    {
        return new self(__('lifecycle.destroyed.refused'));
    }
}
