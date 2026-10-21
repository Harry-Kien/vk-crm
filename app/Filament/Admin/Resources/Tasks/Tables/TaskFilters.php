<?php

namespace App\Filament\Admin\Resources\Tasks\Tables;

use App\Filament\Admin\Resources\Matters\RelationManagers\TasksRelationManager;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Models\Task;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bộ lọc công tắc dùng chung của các bảng việc — trang "Công việc" ({@see ListTasks}) và tab "Công việc" của hồ sơ
 * ({@see TasksRelationManager}); kiểm toán màn hình 2026-10-21, mục 8. Mỗi bộ lọc là MỘT scope có sẵn của `Task` —
 * không điều kiện nào viết lại ở đây, nên "quá hạn" của bộ lọc là "quá hạn" của ô trang chủ và lịch nhắc, "cần giao
 * lại" là "cần giao lại" của cột người nhận.
 *
 * Mọi bộ lọc chỉ THU HẸP truy vấn của bảng (đã qua `Task::visibleTo()`). Không bộ lọc nào áp khi Filament tìm một dòng
 * theo id (`excludeWhenResolvingRecord()`): đường dẫn của một thông báo mở khung "Xem" của việc dù bộ lọc đang bật
 * loại nó ra khỏi danh sách (`TaskResource::taskUrl()`).
 */
final class TaskFilters
{
    /**
     * "Chỉ việc còn mở" — BẬT SẴN, chỉ ở tab "Tất cả" (`$onAllTab`): tab đó là danh sách theo dõi, việc đã hoàn thành và
     * đã huỷ của nhiều tháng không được che việc còn phải làm. Tắt công tắc (hay bấm "×" trên nhãn bộ lọc) là thấy cả
     * việc đã đóng. Ba tab kia đã tự giới hạn (tab "Tôi đã giao" cố ý giữ việc vừa hoàn thành — `Task::scopeAssignedBy()`),
     * nên ở đó bộ lọc không hiện và không làm gì.
     *
     * @param  Closure(): bool  $onAllTab
     */
    public static function openOnly(Closure $onAllTab): Filter
    {
        return Filter::make('open_only')
            ->label(__('tasks.filters.open_only'))
            ->toggle()
            ->default()
            ->visible($onAllTab)
            ->query(fn (Builder $query): Builder => $onAllTab() ? $query->open() : $query)
            ->modifyFormFieldUsing(fn (Toggle $field): Toggle => $field->helperText(__('tasks.filters.open_only_help')))
            ->excludeWhenResolvingRecord();
    }

    /** "Hạn hôm nay" — `Task::scopeDueWithinDays(0)`. */
    public static function dueToday(): Filter
    {
        return Filter::make('due_today')
            ->label(__('tasks.filters.due_today'))
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->dueWithinDays(0))
            ->excludeWhenResolvingRecord();
    }

    /** "Hạn 7 ngày tới" — hôm nay tới hết ngày thứ 7 sau hôm nay, `Task::scopeDueWithinDays(7)`. */
    public static function dueWeek(): Filter
    {
        return Filter::make('due_week')
            ->label(__('tasks.filters.due_week'))
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->dueWithinDays(7))
            ->excludeWhenResolvingRecord();
    }

    /**
     * "Việc của tôi (còn mở)" — việc người xem đang giữ (`Task::scopeHeldBy()`: chờ nhận hay đang làm).
     *
     * @param  Closure(): User  $viewer
     */
    public static function mineOpen(Closure $viewer): Filter
    {
        return Filter::make('mine_open')
            ->label(__('tasks.filters.mine_open'))
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->heldBy($viewer()))
            ->modifyFormFieldUsing(fn (Toggle $field): Toggle => $field->helperText(__('tasks.filters.mine_open_help')))
            ->excludeWhenResolvingRecord();
    }

    /**
     * "Chỉ việc quá hạn" — việc còn mở đã qua hạn trên vụ còn mở hay việc chung, mà người nhận còn GIỮ ĐƯỢC
     * (`overdue()` + `onLiveMatter()` + `heldBySeatedAssignee()` — làn fa1, phán quyết 5; xem docblock `ListTasks`).
     */
    public static function overdue(): Filter
    {
        return Filter::make('overdue')
            ->label(__('tasks.filters.overdue'))
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->overdue()->onLiveMatter()->heldBySeatedAssignee())
            ->excludeWhenResolvingRecord();
    }

    /** "Cần giao lại" — `Task::scopeNeedsReassignment()`; nhãn ngắn, giải thích ở chú thích dưới công tắc. */
    public static function needsReassignment(): Filter
    {
        return Filter::make('needs_reassignment')
            ->label(__('tasks.filters.needs_reassignment'))
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->needsReassignment())
            ->modifyFormFieldUsing(fn (Toggle $field): Toggle => $field->helperText(__('tasks.filters.needs_reassignment_help')))
            ->excludeWhenResolvingRecord();
    }

    /** "Còn mở" — `Task::scopeOpen()` (chờ nhận, đang làm, bị trả lại). Tab "Công việc" của hồ sơ. */
    public static function open(): Filter
    {
        return Filter::make('open')
            ->label(__('tasks.filters.open'))
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->open())
            ->excludeWhenResolvingRecord();
    }
}
