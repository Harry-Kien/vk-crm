<?php

namespace App\Actions\Deadline\Concerns;

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Deadline\SetDeadlineCompletion;
use App\Actions\Deadline\UpdateDeadline;
use App\Actions\Notification\ResolveStaffRecipients;
use App\Models\Matter;
use App\Models\User;

/**
 * "Người này có còn GIỮ được một mốc chưa xong của vụ việc này không" — MỘT định nghĩa cho mọi
 * đường ghi `deadlines.responsible_user_id` SAU lúc tạo mốc (M6.5 Task 14): đổi người phụ trách
 * ({@see ChangeDeadlineResponsible}), sửa mốc
 * ({@see UpdateDeadline}) và mở lại một mốc đã xong
 * ({@see SetDeadlineCompletion}). Ba Action cùng ghi một cột thì phải hỏi
 * cùng một câu — hai luật khác nhau cho một cột là hai luật sẽ lệch nhau lần đầu một bên được sửa.
 *
 * Ba điều kiện, cả ba bắt buộc:
 *
 *  - **Còn đi làm và xem được hồ sơ** — {@see ResolveStaffRecipients::qualifies()}
 *    (`is_active`, chưa xoá mềm, `Gate::view()`), ĐÚNG luật R3 mà `CheckDeadlines` dùng để quyết
 *    định người phụ trách mốc còn nhận được thư nhắc hay không. Người giữ mốc mà không nhận được
 *    thư nhắc của chính mốc đó là một mốc im lặng.
 *  - **Còn trong đội ngũ** (hoặc là luật sư phụ trách hồ sơ — `lead_lawyer_id` là nguồn sự thật
 *    của vai `lead`, dòng `matter_user` tương ứng không phải lúc nào cũng có). Không suy ra được từ
 *    `Gate::view()`: trưởng phòng và admin có `matter.viewAny`, nên vẫn XEM được một vụ thường sau
 *    khi bị gỡ khỏi đội ngũ. R6 giữ bất biến "mốc chưa xong nằm trong tay đội ngũ" bằng cách từ
 *    chối gỡ một người còn giữ mốc chưa xong — điều kiện này giữ cùng bất biến đó ở các cửa còn lại.
 *
 * `view`, không `update`: xem docblock `ChangeDeadlineResponsible` cho lý do người GIỮ một mốc chỉ
 * cần mở được hồ sơ (fix round 1 của Task 4, CRITICAL).
 */
trait ChecksDeadlineHolder
{
    protected function canHoldDeadline(User $user, Matter $matter): bool
    {
        $onTeam = (int) $matter->lead_lawyer_id === (int) $user->getKey()
            || $matter->team()->whereKey($user->getKey())->exists();

        return $onTeam && app(ResolveStaffRecipients::class)->qualifies($user, $matter);
    }
}
