<?php

namespace App\Support\Tasks;

use App\Actions\Matter\TransferStaffWork;
use App\Actions\Task\MoveTasksOnHandover;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Khối "Diễn biến" của khung "Xem" một việc (kiểm toán màn hình 2026-10-21, mục 5): giao, nhận, trả lại kèm lý do,
 * giao lại (kèm hạn mới), mở lại kèm nhận xét, sửa (kèm điều đã đổi — {@see TaskChangeSummary}), huỷ, khôi phục, đổi
 * người giao khi bàn giao — đọc từ nhật ký hoạt động mà các Action trong `app/Actions/Task/` ghi (`Audit::record`).
 *
 * # Ai thấy gì
 *
 * Chỉ dòng nhật ký có `subject` là CHÍNH việc này — không dòng của việc khác, không dòng của hồ sơ. Khung "Xem" chỉ mở
 * cho người `TaskPolicy::view` cho phép (cùng luật `Task::visibleTo()`: việc của một hồ sơ hạn chế chỉ tới luật sư phụ
 * trách và quản trị viên), nên khối này không cho thấy thêm một hồ sơ, một khách hay một việc nào ngoài việc người xem
 * đã mở được. Nó KHÔNG in thuộc tính thô của dòng nhật ký: chỉ tên người (người làm, người nhận trước/sau — những người
 * của chính việc này), ngày hạn, mức ưu tiên/tiêu đề cũ qua câu của "Sửa", và lý do/nhận xét mà các bên của việc đã gõ
 * cho nhau. `matter_id`, `submission_key`, cờ nội bộ (`needs_reassignment`, `by_holder`…) không bao giờ ra màn hình.
 *
 * Lý do "Trả lại" của người giữ trước vẫn hiện cho người giữ sau: cùng một việc, cùng những người theo dõi nó (người
 * giao, luật sư phụ trách, quản lý), và người nhận mới cần biết vì sao việc tới tay mình. Đó là quyết định có chủ ý —
 * ghi ở PROGRESS (kiểm toán màn hình, làn màn hình).
 */
final class TaskTimeline
{
    /** Các sự kiện của nhật ký được kể ra — dòng có tên khác (nếu có) bị bỏ qua, không bao giờ in thô. */
    private const EVENTS = [
        'task_assigned', 'task_accepted', 'task_declined', 'task_completed', 'task_cancelled', 'task_reassigned',
        'task_reopened', 'task_restored', 'task_updated', 'task_assigner_changed',
    ];

    /**
     * @return list<array{at: CarbonInterface, text: string, notes: list<string>}> theo thứ tự xảy ra; chữ CHƯA
     *                                                                             escape (Blade escape)
     */
    public static function for(Task $task): array
    {
        $rows = Activity::query()
            ->where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->getKey())
            ->whereIn('event', self::EVENTS)
            ->orderBy('id')
            ->get();

        $names = self::names($rows);
        $name = fn (mixed $id): string => $names->get((int) $id) ?? __('tasks.timeline.someone');

        return $rows->map(function (Activity $row) use ($name): array {
            $who = $row->causer_type === (new User)->getMorphClass() && $row->causer_id !== null
                ? $name($row->causer_id)
                : __('tasks.timeline.system');
            $property = fn (string $key): mixed => $row->getExtraProperty($key);
            $notes = [];

            $text = match ($row->event) {
                'task_assigned' => __('tasks.timeline.assigned', ['who' => $who, 'assignee' => $name($property('assignee_id'))]),
                'task_accepted' => __('tasks.timeline.accepted', ['who' => $who]),
                'task_declined' => __('tasks.timeline.declined', ['who' => $who]),
                'task_completed' => __('tasks.timeline.completed', ['who' => $who]),
                'task_cancelled' => __('tasks.timeline.cancelled', ['who' => $who]),
                'task_reassigned' => $property('taken_over')
                    ? __('tasks.timeline.taken_over', ['who' => $who, 'from' => $name($property('previous_assignee_id'))])
                    : __('tasks.timeline.reassigned', ['who' => $who, 'from' => $name($property('previous_assignee_id')), 'to' => $name($property('assignee_id'))]),
                'task_reopened' => __('tasks.timeline.reopened', ['who' => $who]),
                'task_restored' => __('tasks.timeline.restored', ['who' => $who]),
                'task_updated' => __('tasks.timeline.updated', ['who' => $who]),
                'task_assigner_changed' => __('tasks.timeline.assigner_changed', ['from' => $name($property('previous_assigner_id')), 'to' => $name($property('assigner_id'))]),
            };

            if ($row->event === 'task_assigned' && filled($property('due_date'))) {
                $notes[] = __('tasks.timeline.due', ['date' => self::date((string) $property('due_date'))]);
            }

            if ($row->event === 'task_reassigned' && $row->properties->has('due_date_to')) {
                $notes[] = __('tasks.timeline.due_changed', [
                    'from' => self::date($property('due_date_from')),
                    'to' => self::date($property('due_date_to')),
                ]);
            }

            if ($row->event === 'task_updated' && ($changes = TaskChangeSummary::fromEvent($row)) !== '') {
                $notes[] = $changes;
            }

            $reason = $property('reason');

            if (is_string($reason) && $reason !== '') {
                $notes[] = match (true) {
                    $reason === MoveTasksOnHandover::HANDOVER_REASON => __('tasks.timeline.handover'),
                    $reason === TransferStaffWork::TRANSFER_REASON => __('tasks.timeline.transfer'),
                    $row->event === 'task_reopened' => __('tasks.timeline.comment', ['text' => $reason]),
                    default => __('tasks.timeline.reason', ['text' => $reason]),
                };
            }

            return ['at' => $row->created_at, 'text' => $text, 'notes' => $notes];
        })->values()->all();
    }

    /**
     * Tên của mọi người được nhắc tới trong các dòng — người làm và người nhận/người giao trước, sau (kể cả đã nghỉ: họ
     * vẫn là người của việc này; quan hệ `causer` của nhật ký mang scope xoá mềm nên không dùng) — một truy vấn.
     *
     * @param  Collection<int, Activity>  $rows
     * @return Collection<int, string>
     */
    private static function names(Collection $rows): Collection
    {
        $ids = $rows->flatMap(fn (Activity $row): array => array_filter([
            $row->causer_type === (new User)->getMorphClass() ? $row->causer_id : null,
            $row->getExtraProperty('assignee_id'),
            $row->getExtraProperty('previous_assignee_id'),
            $row->getExtraProperty('assigner_id'),
            $row->getExtraProperty('previous_assigner_id'),
        ], fn (mixed $id): bool => is_numeric($id)))->map(fn (mixed $id): int => (int) $id)->unique()->values();

        return $ids->isEmpty()
            ? collect()
            : User::query()->withTrashed()->whereKey($ids)->pluck('name', 'id');
    }

    private static function date(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return __('tasks.due.none');
        }

        try {
            return CarbonImmutable::parse($value)->format('d/m/Y');
        } catch (Throwable) {
            return $value;
        }
    }
}
