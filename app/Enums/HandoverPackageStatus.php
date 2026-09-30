<?php

namespace App\Enums;

/**
 * Trạng thái sinh gói bàn giao của một vụ việc đã kết thúc (M7 Task 4, cột
 * `matter_archives.handover_status`). NULL = chưa ai yêu cầu sinh gói; ba giá trị dưới đây là toàn
 * bộ vòng đời của MỘT lần yêu cầu.
 *
 * Không có giá trị "queued" riêng cho "đã xếp hàng, chưa chạy": với người bấm nút hai trạng thái đó
 * cùng một ý ("đang sinh, đừng bấm lại") và cùng một cách xử — khoá nút — nên tách chúng chỉ thêm
 * một trạng thái mà không màn hình nào rẽ nhánh khác đi.
 */
enum HandoverPackageStatus: string
{
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.handover_package_status.'.$this->value);
    }
}
