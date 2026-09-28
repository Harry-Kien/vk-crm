<?php

namespace App\Actions\Portal;

use App\Models\ClientRequest;
use App\Models\User;

/**
 * Kết quả của {@see TriageClientRequest::setStatus()} — luồng SAU khi ghi, và (nếu có) người vừa
 * bị GỠ khỏi vai trò người xử lý vì không còn mở được vụ việc lúc MỞ LẠI một luồng đã đóng.
 *
 * **Một value object, thay vì chỉ trả `ClientRequest` (fix round 1, minor).** Bản trước
 * `ClientRequestsRelationManager::changeStatusAction()` tự SUY ra việc gỡ người có xảy ra không
 * bằng cách so `assigned_to` của bản ghi TRƯỚC lời gọi (`$record`, đọc từ hàng của bảng — có thể
 * cũ hơn hàng thật một nhịp) với `assigned_to` của luồng SAU lời gọi. Suy luận đó đúng TRONG THỰC
 * TẾ hôm nay (không đường nào khác đụng `assigned_to` giữa hai lần đọc), nhưng nó không PHẢI một
 * sự thật mà Action công bố — nó là một phép trừ hai ảnh chụp màn hình mà màn hình tự làm lấy, và
 * một Action tương lai đụng vào `assigned_to` (ví dụ `ReassignMatter`, bàn giao hàng loạt) sẽ âm
 * thầm làm phép trừ đó sai mà không ai phải sửa dòng nào ở đây. Nay Action tự báo: nó biết chính
 * xác nó vừa gỡ ai, và trả ra đúng người đó — màn hình chỉ đọc lại, không suy đoán.
 *
 * `unassignedAssignee` là `User|null`, không phải một id hay một tên trần: màn hình cần cả tên
 * (để ghép câu thông báo) lẫn khả năng đọc thêm thuộc tính khác sau này mà không phải sửa chữ ký.
 */
final class SetClientRequestStatusResult
{
    public function __construct(
        public readonly ClientRequest $thread,
        public readonly ?User $unassignedAssignee = null,
    ) {}
}
