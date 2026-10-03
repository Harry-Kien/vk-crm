<?php

namespace App\Actions\Schedule;

use App\Actions\Matter\RecordMatterDestruction;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Notifications\Staff\RetentionExpiryAlert;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * SPEC §6.12, M7 Task 6 (R5) — tác vụ hằng ngày `retention.flag` (`routes/console.php`).
 *
 * **Nó cảnh báo. Nó không bao giờ xoá.** Tiêu huỷ hồ sơ pháp lý là quyết định của người, có biên
 * bản, làm ngoài hệ thống; quyết định đó được GHI LẠI bằng {@see RecordMatterDestruction}. Tác vụ
 * này chỉ ghi thông báo trong hệ thống ({@see RetentionExpiryAlert}, kênh `database`) — không đổi
 * một cột nào của `matters`, `matter_archives`, `documents` hay `media`, không thư, không job.
 *
 * **Hồ sơ nào:** bản ghi `matter_archives` (chưa xoá mềm) có
 *  - `retention_until < hôm nay` — định nghĩa duy nhất ở `MatterArchive::scopeRetentionExpired()`
 *    (còn trong hạn HẾT ngày `retention_until`);
 *  - `destroyed_at` rỗng — đã ghi quyết định thì không cảnh báo nữa;
 *  - vụ việc chưa xoá mềm VÀ đang đóng (`closed_at` có giá trị). Vụ đã được admin mở lại vẫn giữ
 *    `retention_until` của lần đóng trước trên bản ghi lưu trữ (`SyncMatterArchive` chỉ xoá
 *    `client_access_until`), nhưng một hồ sơ đang xử lý không phải hồ sơ chờ tiêu huỷ. Vụ đã xoá
 *    mềm không mở được trên trang vụ việc, nên một cảnh báo trỏ tới đó là một liên kết chết —
 *    `RecordMatterDestruction` cũng từ chối vụ đã xoá (khôi phục trước).
 *
 * **Ai nhận:** mọi admin đang hoạt động được xem vụ — `ResolveStaffRecipients::activeAdminsFor()`,
 * không đọc thẳng `User`. Admin xem được mọi vụ, nên một vụ `restricted` cũng chỉ tới admin.
 *
 * **Một lần, không lặp mỗi ngày** (cùng mẫu B-M1 của `CheckDeadlines::alreadyAlerted()`): một người
 * đã có cảnh báo cho ĐÚNG hồ sơ này với ĐÚNG `retention_until` này thì không nhận thêm. Admin được
 * thêm sau vẫn nhận một lần; hồ sơ được đóng lại với hạn mới rồi quá hạn lần nữa thì là một lần mới.
 *
 * **Không transaction, không khoá:** không có gì được ghi ngoài thông báo, và một lần ghi thông báo
 * không cần nhất quán với dòng nào khác. Hai lần chạy chồng nhau (khoá `withoutOverlapping` có hạn
 * của mục lịch đã chặn) cùng lắm ghi trùng một thông báo.
 *
 * **Lỗi ở một người nhận không dừng vòng lặp** — `try`/`catch` cho từng lần `notify()`, `report()`
 * để lỗi không biến mất; lỗi ở một hồ sơ (ví dụ khi hỏi người nhận) cũng vậy.
 *
 * **Mọi truy vấn gỡ `ClientPortalScope` tường minh**, cùng lý do đã ghi ở `ExpireClientAccess`:
 * scope của một phiên cổng ambient không được cắt tập ứng viên.
 */
class FlagRetentionExpiry
{
    /**
     * @return array{flagged: int, notified: int, failed: int}
     */
    public function __invoke(): array
    {
        return $this->handle();
    }

    /**
     * `flagged`: số hồ sơ quá hạn được xét; `notified`: số thông báo ghi ở lần chạy này (không
     * tính người đã có cảnh báo); `failed`: số lần lỗi (một người nhận, hoặc cả một hồ sơ).
     *
     * @return array{flagged: int, notified: int, failed: int}
     */
    public function handle(): array
    {
        $recipients = app(ResolveStaffRecipients::class);
        $flagged = 0;
        $notified = 0;
        $failed = 0;

        $archives = MatterArchive::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereNull('destroyed_at')
            ->retentionExpired()
            ->whereHas('matter', fn (Builder $matter) => $matter
                ->withoutGlobalScope(ClientPortalScope::class)
                ->closed())
            ->with(['matter' => fn ($matter) => $matter->withoutGlobalScope(ClientPortalScope::class)]);

        foreach ($archives->lazyById(100) as $archive) {
            $flagged++;

            try {
                /** @var Matter $matter */
                $matter = $archive->matter;

                foreach ($recipients->activeAdminsFor($matter) as $recipient) {
                    try {
                        if ($this->alreadyAlerted($recipient, $archive)) {
                            continue;
                        }

                        $recipient->notify(new RetentionExpiryAlert($matter, $archive));
                        $notified++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        return ['flagged' => $flagged, 'notified' => $notified, 'failed' => $failed];
    }

    /**
     * Người này đã có cảnh báo cho ĐÚNG hồ sơ này với ĐÚNG hạn lưu trữ này chưa. Khoá là
     * `viewData.matter_id` + `viewData.retention_until` mà {@see RetentionExpiryAlert::toDatabase()}
     * ghi.
     */
    private function alreadyAlerted(User $recipient, MatterArchive $archive): bool
    {
        return $recipient->notifications()
            ->where('type', RetentionExpiryAlert::class)
            ->where('data->viewData->matter_id', $archive->matter_id)
            ->where('data->viewData->retention_until', $archive->retention_until->toDateString())
            ->exists();
    }
}
