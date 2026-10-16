<?php

namespace App\Support\Tasks;

use App\Actions\Task\UpdateTask;
use App\Enums\TaskPriority;
use App\Jobs\SendTaskNotice;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * "Điều đã đổi" của MỘT lần "Sửa" một việc (làn fa1, phán quyết 1) — đọc từ dòng nhật ký `task_updated` của lần
 * đó ({@see UpdateTask} ghi `old`/`new` cho hạn, ưu tiên, tiêu đề và `changed` là tên mọi cột đã đổi), không từ
 * dòng việc lúc gửi: một lần sửa thứ hai trước khi job chạy đã đổi dòng việc. Chuông và thư của
 * {@see SendTaskNotice} cùng đọc đây — một câu, một định nghĩa.
 *
 * Mô tả chỉ được nói là "đã sửa": nội dung dài, và nằm ngay ở khung "Xem" của việc. Câu chưa escape — người
 * gọi escape khi đưa vào chuông (`TaskAlert`), Blade escape trong thư.
 */
final class TaskChangeSummary
{
    /** Các phần đã đổi, theo thứ tự hạn → ưu tiên → tiêu đề → mô tả, nối bằng "; ". Rỗng khi dòng không ghi gì. */
    public static function fromEvent(Activity $event): string
    {
        $old = (array) $event->getExtraProperty('old', []);
        $new = (array) $event->getExtraProperty('new', []);
        $changed = (array) $event->getExtraProperty('changed', []);
        $parts = [];

        if (array_key_exists('due_date', $new)) {
            $parts[] = __('tasks.notice.changes.due_date', ['from' => self::date($old['due_date'] ?? null), 'to' => self::date($new['due_date'])]);
        }

        if (array_key_exists('priority', $new)) {
            $parts[] = __('tasks.notice.changes.priority', ['from' => self::priority($old['priority'] ?? null), 'to' => self::priority($new['priority'])]);
        }

        if (array_key_exists('title', $new)) {
            $parts[] = __('tasks.notice.changes.title', ['from' => (string) ($old['title'] ?? '')]);
        }

        if (in_array('description', $changed, true)) {
            $parts[] = __('tasks.notice.changes.description');
        }

        return implode('; ', $parts);
    }

    private static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return __('tasks.due.none');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = false;
        }

        return $date === false ? $value : $date->format('d/m/Y');
    }

    private static function priority(?string $value): string
    {
        return TaskPriority::tryFrom((string) $value)?->label() ?? (string) $value;
    }
}
