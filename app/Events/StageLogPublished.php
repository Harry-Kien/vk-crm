<?php

namespace App\Events;

use App\Models\StageLog;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatch ở SPEC §6.2 bước 6, khi một `StageLog` vừa tạo có `publish = true` VÀ
 * `matter.is_published_to_portal = true`. Listener (dispatch job `SendStageUpdateNotification`,
 * kiểm tra `notified_at` để chống gửi trùng) thuộc M6 — không viết ở đây.
 *
 * `ShouldDispatchAfterCommit`: `TransitionMatterStage` dispatch sự kiện này ngay bên trong
 * transaction ghi `StageLog`. Nếu dispatch đồng bộ (mặc định của Laravel) và một bước sau đó
 * trong CÙNG transaction ném lỗi — kể cả một transaction bên ngoài do caller mở (ví dụ trang
 * Filament tương lai bọc thêm một transaction) — listener đã chạy trong khi `StageLog` bị rollback.
 * Vì `stage_logs` chỉ thêm không sửa/xoá, không có cách nào sửa lại: khách đã nhận thông báo về
 * một dòng tiến độ không tồn tại, và `notified_at` không ghi được để chặn gửi lại. Implement
 * interface này để Laravel tự hoãn dispatch đến khi transaction NGOÀI CÙNG thật sự commit; nếu nó
 * rollback, listener không bao giờ chạy.
 */
class StageLogPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly StageLog $stageLog) {}
}
