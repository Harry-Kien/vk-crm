<?php

namespace App\Events;

use App\Listeners\ReleaseStageTriggeredInstalments;
use App\Listeners\SyncMatterArchiveOnStageChange;
use App\Models\StageLog;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * M7 Task 3 (phán quyết của chủ nhiệm). Dispatch ở `TransitionMatterStage::handle()` bước 6,
 * đúng và CHỈ đúng khi giai đoạn THẬT SỰ đổi (`! $isSameStage`) — một dòng cập nhật không đổi
 * giai đoạn (SPEC §6.3) không "chuyển" gì cả, và phát sự kiện này cho nó sẽ chạy lại đồng bộ hoá
 * lưu trữ ({@see SyncMatterArchiveOnStageChange}) một cách vô ích trên mỗi lần
 * luật sư thêm một dòng ghi chú.
 *
 * **Trùng tên NGẮN với `App\Exceptions\MatterStageChanged`, cố ý không đổi tên lớp kia.** Lớp
 * exception đó đã tồn tại từ M6.5 (race giữa hai lần chuyển giai đoạn đồng thời) và
 * `TransitionMatterStage.php` đã `use` nó; đổi tên một lớp đang được dùng ở một action khác chỉ
 * để nhường chỗ cho sự kiện mới là một thay đổi không cần thiết trên mã đã ổn định. Nơi cả hai
 * cùng xuất hiện (`TransitionMatterStage.php`) import lớp exception bằng bí danh
 * (`use App\Exceptions\MatterStageChanged as MatterStageChangedException`) — PHP không cho hai
 * `use` trần cùng short name trong một file.
 *
 * **Hình dạng khớp kế hoạch M9** (dòng 139–145 của kế hoạch M9: "M7 dùng chung về sau"): M9 Task 6
 * đã thêm listener CỦA NÓ vào đúng sự kiện này — {@see ReleaseStageTriggeredInstalments} (đợt thanh
 * toán đến hạn khi vụ chạm giai đoạn) — không đổi hình dạng sự kiện, không phát sự kiện thứ hai. Sự
 * kiện mang nguyên `StageLog` (không
 * chỉ `matter_id`): mọi thông tin về LẦN CHUYỂN GIAI ĐOẠN vừa xảy ra (from/to, actor qua
 * `created_by`, thời điểm `occurred_at`) đều có sẵn cho listener mà không cần đọc
 * lại `stage_logs`.
 *
 * `ShouldDispatchAfterCommit` — cùng lý lẽ với `StageLogPublished`: `TransitionMatterStage` dispatch
 * sự kiện này NGAY BÊN TRONG transaction ghi `StageLog`/`matters`. Nếu dispatch đồng bộ (mặc định
 * Laravel) và một bước sau đó trong CÙNG transaction (kể cả một transaction ngoài do caller mở)
 * ném lỗi, listener đã chạy cho một lần chuyển giai đoạn sẽ bị rollback — {@see
 * \App\Actions\Matter\SyncMatterArchive} sẽ tạo một bản ghi lưu trữ cho một `Matter` mà
 * `closed_at` của nó vừa bị huỷ. Implement interface này để Laravel hoãn dispatch tới khi
 * transaction NGOÀI CÙNG thật sự commit; rollback thì listener không bao giờ chạy.
 */
class MatterStageChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly StageLog $stageLog) {}
}
