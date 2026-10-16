<?php

namespace App\Filament\Admin\Widgets\Tasks;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Khối "Đang làm": việc tôi đã nhận, chưa xong — hạn tô đỏ khi quá hạn, vàng khi sắp tới. Xem {@see MyTasksWidget}. */
class MyActiveTasksWidget extends MyTasksWidget
{
    protected function narrow(Builder $query, User $user): Builder
    {
        return $query->inProgressFor($user);
    }

    protected function group(): string
    {
        return 'active';
    }
}
