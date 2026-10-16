<?php

namespace App\Filament\Admin\Widgets\Tasks;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Khối "Chờ tôi nhận": việc giao cho tôi, chưa ai bấm "Đã nhận" — nút "Đã nhận"/"Trả lại" ngay trên dòng. Xem {@see MyTasksWidget}. */
class TasksAwaitingMeWidget extends MyTasksWidget
{
    protected function narrow(Builder $query, User $user): Builder
    {
        return $query->awaitingAcceptanceBy($user);
    }

    protected function group(): string
    {
        return 'awaiting';
    }
}
