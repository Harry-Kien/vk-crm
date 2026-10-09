<?php

namespace App\Actions\Deadline;

use App\Actions\Deadline\Concerns\OpensDeadline;
use App\Enums\CreatedVia;
use App\Exceptions\DeadlineNotCreatedViaAi;
use App\Models\Deadline;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Nút "Xác nhận" trên một mốc tạo qua AI (M11 R5, Task 12): một người có quyền sửa mốc nói "mốc này
 * đúng", và nhãn "Tạo qua AI, chưa xác nhận" thôi hiện. Ghi `confirmed_at` = bây giờ và
 * `confirmed_by` = người bấm (hai cột cố ý không `fillable` trên `Deadline`), cùng một dòng audit
 * `deadline_ai_confirmed`.
 *
 * Cổng ngang mọi nút khác của tab Mốc thời hạn: `DeadlinePolicy::update` (qua
 * {@see OpensDeadline::openDeadline()}, khoá dòng mốc, tài khoản còn hiệu lực, câu từ chối chung).
 * Mốc nhập trên web → {@see DeadlineNotCreatedViaAi}. Đã xác nhận rồi → trả nguyên mốc, không ghi
 * gì: người xác nhận ĐẦU TIÊN là người đứng tên, một lần bấm thứ hai (hai tab) không đổi điều đó.
 *
 * Xác nhận không đổi việc nhắc hạn: `CheckDeadlines` nhắc mốc tạo qua AI như mọi mốc khác, đã xác
 * nhận hay chưa (SPEC §6.8 — một mốc tố tụng thật bị im lặng là thiệt hại thật).
 */
class ConfirmAiDeadline
{
    use OpensDeadline;

    public function handle(Deadline $deadline, User $actor): Deadline
    {
        return DB::transaction(function () use ($deadline, $actor): Deadline {
            [$fresh, $matter] = $this->openDeadline($deadline, $actor);

            if ($fresh->created_via !== CreatedVia::Mcp) {
                throw DeadlineNotCreatedViaAi::make();
            }

            if ($fresh->confirmed_at !== null) {
                return $fresh;
            }

            $fresh->blameOn($actor)->forceFill([
                'confirmed_at' => now(),
                'confirmed_by' => $actor->getKey(),
            ])->save();

            Audit::record('deadline_ai_confirmed', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'due_date' => $fresh->due_date->toDateString(),
                'severity' => $fresh->severity->value,
            ], causer: $actor);

            return $fresh;
        });
    }
}
