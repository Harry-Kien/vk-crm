<?php

namespace App\Actions\Matter;

use App\Exceptions\TeamMemberHasOpenWork;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\OpenWork;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gỡ MỘT thành viên khỏi đội ngũ vụ việc (SPEC §4.7, §7.2; M6.5 Task 3, R6).
 *
 * Cổng: `MatterPolicy::manageTeam` — cùng cổng với {@see AddTeamMember}, cùng lý do (đọc docblock
 * lớp đó cho lý do `matter_user` cần một đường ghi có Action đứng sau, và vì sao `observer` không
 * tự hạn chế quyền gì).
 *
 * # Từ chối khi còn việc dở dang — VÀ khi đang là luật sư phụ trách của vụ ĐANG MỞ
 *
 * {@see OpenWork::forUser()} giới hạn vào ĐÚNG vụ việc này trả lời cả ba câu cùng lúc: còn là
 * lead của vụ việc ĐANG MỞ này, còn đứng tên một mốc hạn CHƯA XONG, còn được giao một yêu cầu
 * khách CHƯA ĐÓNG. `isEmpty()` rỗng thì mới gỡ được.
 *
 * **Không có một nhánh `role_in_matter === Lead` riêng, và đó là cố ý.** `OpenWork` tự phủ đúng
 * trường hợp đó: một người là `lead` của MỘT vụ việc ĐANG MỞ luôn xuất hiện trong `leadMatters`
 * của chính họ, kể cả khi vụ việc đang hỏi CHÍNH LÀ vụ việc đó — `$matter` truyền xuống
 * `OpenWork::forUser($member, $matter)` giới hạn truy vấn `leadMatters` vào đúng vụ này. Kết quả:
 * gỡ một lead khỏi vụ việc mà họ đang là lead, trong khi vụ ĐANG MỞ, bị chặn đúng bằng NHÁNH
 * `leadMatters` — không cần đọc lại `pivot.role_in_matter` một lần nữa ở Action này. Ngược lại,
 * gỡ một lead khỏi một vụ ĐÃ ĐÓNG (`closed_at` khác null) KHÔNG bị chặn ở đây: vụ đã đóng không
 * còn gì để "bàn giao", `ReassignMatter` (M7) không có việc trên nó, và R6 chỉ nói vai `lead` CHỈ
 * ĐỔI qua `ReassignMatter` — không nói không bao giờ gỡ được khỏi một vụ đã xong việc.
 *
 * # Kiểm tra "có phải thành viên không" TRƯỚC `OpenWork`, không phải sau
 *
 * Thứ tự này bắt buộc, không phải gọn gàng: `OpenWork::forUser()` không hỏi gì về việc người đó
 * có trong `team()` của vụ việc hay không — nó hỏi về mốc hạn/yêu cầu khách/vai lead trên TOÀN
 * dữ liệu, độc lập với `matter_user`. Một người CHƯA từng vào đội ngũ của vụ việc này vẫn có thể
 * đứng tên một mốc hạn của vụ đó (mốc hạn không đòi người phụ trách phải ở trong đội ngũ tại thời
 * điểm bị gỡ — chỉ đòi lúc GÁN, xem `DeadlinesRelationManager::responsibleOptions()`), nên hỏi
 * `OpenWork` trước sẽ cho một câu trả lời không liên quan gì tới câu hỏi thật ("người này có
 * trong đội ngũ để mà gỡ không").
 */
class RemoveTeamMember
{
    /**
     * @throws ValidationException
     * @throws TeamMemberHasOpenWork
     */
    public function handle(Matter $matter, User $actor, User $member): void
    {
        Gate::forUser($actor)->authorize('manageTeam', $matter);

        $pivot = $matter->team()->whereKey($member->getKey())->first()?->pivot;

        if ($pivot === null) {
            throw ValidationException::withMessages([
                'user_id' => [__('actions.remove_team_member.not_member')],
            ]);
        }

        $openWork = OpenWork::forUser($member, $matter);

        if (! $openWork->isEmpty()) {
            throw TeamMemberHasOpenWork::make($member, $matter, $openWork);
        }

        $matter->team()->detach($member->getKey());

        Audit::record('team_member_removed', $matter, [
            'user_id' => $member->getKey(),
            'role' => $pivot->role_in_matter->value,
        ], $actor);
    }
}
