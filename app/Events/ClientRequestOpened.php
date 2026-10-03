<?php

namespace App\Events;

use App\Models\ClientRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Khách hàng vừa mở một luồng trao đổi mới — SPEC §4.14, §8.3 mục 7 (`App\Actions\Portal\
 * OpenClientRequest`). Lấp `requests/REQ-1`/`notify/notify-7`/`roles/roles-08`/`spec-gap/spec-gap-08`
 * /`e2e/F5` (audit 2026-09-24, chuyển sang M6 Task 4 bởi R1 của M6.5 Task 21): trước sự kiện này,
 * `OpenClientRequest::handle()` tạo hàng `client_requests` rồi dừng lại — không thư, không thông
 * báo, không danh sách tổng nào báo cho văn phòng, trong khi cổng khách vẫn nói "Văn phòng đã nhận
 * được yêu cầu của anh/chị."
 *
 * Người nhận không đi kèm sự kiện: listener đọc `$request->matter` rồi đội ngũ vụ việc lúc NÓ
 * chạy — xem `App\Actions\Notification\NotifyStaffOfNewClientRequest`, cùng lý lẽ với
 * `ClientDocumentSubmitted`.
 *
 * `ShouldDispatchAfterCommit`: `OpenClientRequest::handle()` hôm nay KHÔNG mở transaction của
 * riêng nó, nhưng một caller tương lai (hay một test) có thể bọc nó trong một transaction ngoài —
 * cùng lý lẽ `ClientDocumentSubmitted`/`DocumentPublished` đã ghi: dispatch đồng bộ bên trong một
 * transaction có thể rollback là báo tin về một hàng chưa từng tồn tại thật.
 */
class ClientRequestOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly ClientRequest $request) {}
}
