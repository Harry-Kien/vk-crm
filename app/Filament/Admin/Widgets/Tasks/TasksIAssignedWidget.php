<?php

namespace App\Filament\Admin\Widgets\Tasks;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Khối "Tôi đã giao": việc tôi giao còn mở, kể cả việc bị trả lại (lý do dưới trạng thái) để giao lại hoặc huỷ, và việc vừa hoàn thành (`Task::scopeAssignedBy()`). Xem {@see MyTasksWidget}. */
class TasksIAssignedWidget extends MyTasksWidget
{
    protected function narrow(Builder $query, User $user): Builder
    {
        return $query->assignedBy($user);
    }

    protected function group(): string
    {
        return 'assigned';
    }
}
