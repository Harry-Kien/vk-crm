<?php

namespace App\Support\Tasks;

use App\Filament\Admin\Resources\Tasks\Tables\TaskColumns;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Lang;

/**
 * Người nhận THAY người giao một lời báo "cho người giao" (`ResolveStaffRecipients::forTaskAssigner()`) — họ là ai, nói
 * với họ bằng câu nào, mở trang nào (kiểm toán luồng Công việc 2026-10-21, N1 và A7). MỘT định nghĩa cho chuông và thư
 * của lời báo việc (`TaskAlert`, `TaskNotice`) và lời nhắc việc (`TaskReminderAlert`, `TaskReminder`):
 *
 *  - {@see self::LEAD} — luật sư phụ trách hồ sơ nhận thay: bộ chữ `*_on_behalf` có sẵn ("… hồ sơ anh/chị phụ trách"),
 *    tab "Công việc" của hồ sơ;
 *  - {@see self::MANAGER} — người quản lý hồ sơ (trưởng phòng; quản trị viên với hồ sơ hạn chế) nhận thay khi luật sư
 *    phụ trách là chính người giữ việc hay không còn hợp lệ: bộ chữ `tasks.managed.*` ("… hồ sơ :code của đội ngũ
 *    anh/chị quản lý") — trước bản sửa họ đọc "hồ sơ anh/chị phụ trách", sai với họ; tab "Công việc" của hồ sơ;
 *  - {@see self::GENERAL} — việc CHUNG, trưởng phòng / quản trị viên nhận thay (A7): bộ chữ `tasks.managed.*_general`,
 *    trang Công việc tab "Tất cả" lọc sẵn người giao đã đi.
 *
 * `null`: người nhận chính là người giao — câu "việc anh/chị giao" như cũ.
 */
final class StandIn
{
    public const LEAD = 'lead';

    public const MANAGER = 'manager';

    public const GENERAL = 'general';

    /**
     * Vai người nhận thay của `$recipient` cho một lời báo người giao về `$task`; `$onBehalfOf` là tên người giao mà
     * người gửi đặt khi người nhận khác `assigned_by` (`ResolveStaffRecipients::assignerName()`).
     */
    public static function role(Task $task, ?string $onBehalfOf, ?object $recipient): ?string
    {
        if ($onBehalfOf === null) {
            return null;
        }

        if ($task->matter_id === null) {
            return self::GENERAL;
        }

        return $recipient instanceof User && $task->matter?->lead_lawyer_id === $recipient->getKey() ? self::LEAD : self::MANAGER;
    }

    /**
     * Khoá câu chữ `tasks.managed.<$area>.<$kind>[_general]` cho người quản lý / việc chung, khi khoá đó có (`$probe`:
     * phần con để hỏi `Lang::has()`, vd. `.title`); `null` — dùng bộ chữ `*_on_behalf` có sẵn.
     */
    public static function managedKey(string $area, string $kind, ?string $role, string $probe = ''): ?string
    {
        if (! in_array($role, [self::MANAGER, self::GENERAL], true)) {
            return null;
        }

        $key = 'tasks.managed.'.$area.'.'.$kind.($role === self::GENERAL ? '_general' : '');

        return Lang::has($key.$probe) ? $key : null;
    }

    /** Trang người nhận thay mở — xem docblock lớp. */
    public static function url(Task $task, string $role): string
    {
        return $role === self::GENERAL
            ? TaskResource::getUrl('index', [
                'tab' => 'all',
                'filters' => ['assigned_by' => ['value' => $task->assigned_by]],
            ], panel: 'admin')
            : TaskColumns::matterTabUrl((int) $task->matter_id);
    }
}
