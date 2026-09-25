<?php

namespace App\Actions\Matter;

use App\Actions\Portal\TriageClientRequest;
use App\Enums\MatterRole;
use App\Exceptions\TeamMemberHasOpenWork;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use App\Support\OpenWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gỡ MỘT thành viên khỏi đội ngũ vụ việc (SPEC §4.7, §7.2; M6.5 Task 3, R6).
 *
 * Cổng: `MatterPolicy::manageTeam` — cùng cổng với {@see AddTeamMember}, cùng lý do (đọc docblock
 * lớp đó cho lý do `matter_user` cần một đường ghi có Action đứng sau, và vì sao `observer` không
 * tự hạn chế quyền gì).
 *
 * # Vai `lead` KHÔNG BAO GIỜ gỡ được qua đây (fix round 1, finding S3)
 *
 * **Bản gốc của Task 3 SAI ở chỗ này — ghi lại để không lặp lại.** Bản đầu không có nhánh
 * `role_in_matter === Lead` riêng: nó dựa hẳn vào `OpenWork::leadMatters`, thứ chỉ chặn khi vụ
 * việc ĐANG MỞ (`closed_at` null). Hệ quả: gỡ một lead khỏi một vụ ĐÃ ĐÓNG lọt qua trót lọt — một
 * hành vi cụ thể một test cũ (`TeamMemberTest`, "allows removing the lead once the matter is
 * closed") còn GHIM LẠI như đúng. R6 nói "vai `lead` CHỈ ĐỔI qua `ReassignMatter`" — không có
 * điều kiện "trừ khi vụ đã đóng". Một vụ đã đóng vẫn cần MỘT người đứng tên là lead trong lịch sử
 * (M7 Task 3 dựng `matter_archives` trên chính `closed_at`/đội ngũ này) — gỡ trắng lead của nó
 * qua một nút "Gỡ thành viên" chung chung, không qua `ReassignMatter`, là đúng lỗ hổng SPEC §6.11
 * tồn tại để chặn. Giờ nhánh này đứng NGAY SAU khi xác nhận còn là thành viên, TRƯỚC `OpenWork`
 * — kể cả một lead của một vụ đã đóng (nơi `OpenWork::leadMatters` không còn thấy gì) cũng bị
 * chặn ở đây.
 *
 * **`OpenWork` vẫn được gọi sau đó, và điều đó không thừa.** `OpenWork::forUser($member, $matter)`
 * còn hai việc `role_in_matter === Lead` không làm: mốc hạn chưa xong và yêu cầu khách chưa đóng
 * của MỌI thành viên, kể cả `associate`/`assistant`/`observer`. Nhánh `leadMatters` bên trong nó
 * giờ chỉ còn "vô hại" với lời gọi này — với một `$member` đã qua được nhánh `Lead` ở trên (tức
 * KHÔNG phải lead của `$matter`), `leadMatters` giới hạn vào đúng `$matter` sẽ luôn rỗng (bất biến
 * của hệ thống: `pivot.role_in_matter === Lead` khớp đúng với `matter.lead_lawyer_id === $member`,
 * không có đường nào trong `app/` hôm nay làm hai giá trị đó lệch nhau) — không xoá nó vì `OpenWork`
 * là helper DÙNG CHUNG với việc nghỉ việc (Task 4, hỏi trên toàn bộ vụ việc, `$matter = null`),
 * nơi bất biến trên không còn đúng (một người có thể là lead của NHIỀU vụ việc khác).
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
 *
 * # Khoá dòng vụ việc, trong một transaction (fix round 1, finding I3; khép lại ở fix round 2)
 *
 * Đọc pivot, hỏi `OpenWork`, `detach()` và ghi audit giờ nằm trong CÙNG một `DB::transaction`,
 * sau khi khoá dòng `matters` bằng `lockForUpdate()`.
 *
 * **Khoá này chỉ đóng được đúng khe hở nó nhắm tới từ fix round 2 trở đi, khi
 * {@see TriageClientRequest::open()} CŨNG khoá đúng dòng `matters` này —
 * và khoá nó TRƯỚC dòng `client_requests` của chính nó, cùng THỨ TỰ TOÀN CỤC (vụ việc trước, bảng
 * con sau) mà hàm này dùng.** Ở fix round 1, khoá ở đây một mình chỉ nối tiếp được các lần
 * gỡ/thêm THÀNH VIÊN khác VỚI NHAU (`RemoveTeamMember`/`AddTeamMember` tranh chấp cùng một vụ
 * việc) — nó KHÔNG nối tiếp được với `TriageClientRequest::assign()`, vì Action đó đọc `matters`
 * không khoá gì (finding I3 residual, round 2). Một `assign()` chạy đồng thời vẫn có thể giao một
 * yêu cầu khách cho đúng người đang bị gỡ NGAY GIỮA lúc `OpenWork` đọc xong (thấy rỗng) và
 * `detach()` chạy ở đây.
 *
 * Từ fix round 2, hai Action xin khoá `matters` theo CÙNG một thứ tự nên chúng luôn xếp hàng, dù
 * ai tới trước: nếu `assign()` xin khoá trước, nó giao xong yêu cầu VÀ COMMIT rồi `RemoveTeamMember`
 * mới đọc được `OpenWork` — thấy đúng yêu cầu vừa giao, chặn gỡ đúng. Nếu `RemoveTeamMember` xin
 * khoá trước, nó gỡ xong người đó khỏi `team()` VÀ COMMIT rồi `assign()` mới chạy —
 * `canHoldTheThread()` hỏi lại `MatterPolicy::update` trên chính người vừa bị gỡ và tự từ chối
 * (họ không còn `matter.view` qua `team()`, trừ khi có `matter.viewAny`), nên yêu cầu không bị
 * giao cho một người đã rời đội ngũ. Không còn thứ tự thứ ba (đan xen giữa hai lần commit) vì cả
 * hai Action giữ khoá `matters` xuyên suốt transaction của mình.
 *
 * Khoá dòng vụ việc vẫn không chặn được MỌI ghi đồng thời trên bảng khác một cách tổng quát (ví
 * dụ hai `AddMatterDeadline` cùng lúc trên hai mốc hạn khác nhau của cùng vụ việc vẫn xếp hàng vì
 * lý do khác — xem `OpensDeadline`) — phạm vi đã đóng ở đây là đúng cặp Action finding I3 nêu
 * tên: `Add`/`RemoveTeamMember` và `TriageClientRequest::assign()`/`setStatus()`.
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

        DB::transaction(function () use ($matter, $actor, $member): void {
            $locked = Matter::query()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            $pivot = $locked->team()->whereKey($member->getKey())->first()?->pivot;

            if ($pivot === null) {
                throw ValidationException::withMessages([
                    'user_id' => [__('actions.remove_team_member.not_member')],
                ]);
            }

            if ($pivot->role_in_matter === MatterRole::Lead) {
                throw ValidationException::withMessages([
                    'user_id' => [__('actions.remove_team_member.lead_role_denied')],
                ]);
            }

            $openWork = OpenWork::forUser($member, $locked);

            if (! $openWork->isEmpty()) {
                throw TeamMemberHasOpenWork::make($member, $locked, $openWork);
            }

            $locked->team()->detach($member->getKey());

            Audit::record('team_member_removed', $locked, [
                'user_id' => $member->getKey(),
                'role' => $pivot->role_in_matter->value,
            ], $actor);
        });
    }
}
