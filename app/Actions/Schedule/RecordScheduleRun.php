<?php

namespace App\Actions\Schedule;

use App\Models\SystemHealth;

/**
 * Chạm `system_health.last_schedule_run_at`. Lịch gọi mỗi phút.
 *
 * Là một Action có `__invoke()` chứ không phải một closure trong `routes/console.php`, vì một
 * closure ở đó chỉ chạy được qua scheduler, nên không test nào gọi thẳng được nó — và thứ không
 * test được là thứ sẽ hỏng lặng lẽ, đúng cái mà cả tác vụ này sinh ra để chống.
 */
class RecordScheduleRun
{
    public function __invoke(): void
    {
        $this->handle();
    }

    public function handle(): SystemHealth
    {
        $health = SystemHealth::current();

        $health->forceFill(['last_schedule_run_at' => now()])->save();

        return $health;
    }
}
