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
 * # Khoá dòng vụ việc, trong một transaction (fix round 1, finding I3; khép lại ở fix round 2 và
 * round 3)
 *
 * Đọc pivot, hỏi `OpenWork`, `detach()` và ghi audit giờ nằm trong CÙNG một `DB::transaction`,
 * và câu ĐẦU TIÊN bên trong transaction đó là khoá dòng `matters` bằng `lockForUpdate()` — không
 * câu đọc trần (không khoá) nào đứng trước nó. Thứ tự này BẮT BUỘC, không chỉ để gọn: trên
 * MariaDB, mức cô lập REPEATABLE READ cố định READ VIEW của một transaction tại LẦN ĐỌC KHÔNG
 * KHOÁ ĐẦU TIÊN của nó (một câu `FOR UPDATE` không cố định gì, nó luôn đọc dữ liệu mới nhất) —
 * một câu đọc trần đứng TRƯỚC khoá này sẽ khiến MỌI câu đọc trần SAU ĐÓ (kể cả sau khi khoá đã
 * được cấp) vẫn thấy dữ liệu CŨ. Đây chính xác là lỗi round 3 sửa ở
 * {@see TriageClientRequest::open()} — xem docblock hàm đó và
 * {@see TriageClientRequest::realMatterId()} cho cơ chế đầy đủ; hàm NÀY không có câu đọc trần
 * nào trước khoá — đã rà lại (fix round 3): `Gate::forUser($actor)->authorize('manageTeam',
 * $matter)` là câu DUY NHẤT chạy trước, và nó chạy TRƯỚC `DB::transaction()` mở (dòng 116), không
 * phải bên trong nó, nên không cố định gì cho transaction NÀY. Cùng luật đã kiểm lại cho
 * {@see AddTeamMember}.
 *
 * **Khoá này chỉ đóng được đúng khe hở finding I3 nhắm tới TỪ FIX ROUND 3 TRỞ ĐI**, khi
 * {@see TriageClientRequest::open()} CŨNG khoá đúng dòng `matters` này làm câu ĐẦU TIÊN của nó
 * (không phải chỉ "trước `client_requests`" như round 2 làm — round 2 vẫn còn MỘT câu đọc trần
 * đứng trước cả hai khoá, xem finding I3 residual). Hai Action tranh chấp trên CÙNG một vụ việc
 * giờ luôn xếp hàng: dù ai xin khoá `matters` trước, phía CÒN LẠI đợi tới khi phía đó COMMIT rồi
 * mới chạy tiếp — và vì KHÔNG câu đọc trần nào chạy trước khoá ở CẢ HAI phía, câu đọc đầu tiên
 * (dù là câu nào) của phía đợi luôn cố định READ VIEW của nó SAU khi phía kia đã commit — không
 * còn khoảng hở cho một READ VIEW cũ sống sót qua một lần commit của phía kia.
 *
 * Cụ thể cho cặp `RemoveTeamMember`/`TriageClientRequest::assign()`: nếu `assign()` xin khoá
 * trước, nó giao xong yêu cầu VÀ COMMIT rồi `RemoveTeamMember` mới đọc được `OpenWork` — thấy
 * đúng yêu cầu vừa giao, chặn gỡ đúng. Nếu `RemoveTeamMember` xin khoá trước, nó gỡ xong người đó
 * khỏi `team()` VÀ COMMIT rồi `assign()` mới chạy tiếp — `canHoldTheThread()` hỏi lại
 * `MatterPolicy::update` trên chính người vừa bị gỡ, với một READ VIEW cố định SAU khi
 * `RemoveTeamMember` đã commit, nên tự từ chối đúng (họ không còn `matter.view` qua `team()`, trừ
 * khi có `matter.viewAny`).
 *
 * **Hai `AddMatterDeadline` (hoặc `AddMatterDeadline` với `Add`/`RemoveTeamMember`) cùng lúc trên
 * CÙNG một vụ việc CŨNG xếp hàng đúng vì lý do NÀY, không phải một lý do khác** —
 * `OpensDeadline::openMatterForDeadline()` khoá dòng `matters` làm câu ĐẦU TIÊN của nó, cùng thứ
 * tự toàn cục (vụ việc trước, bảng con sau) mà hàm này dùng. (Sửa lại một câu sai ở bản fix round
 * 2: bản đó nói hai `AddMatterDeadline` xếp hàng "vì lý do khác" — không đúng, chúng xếp hàng
 * chính vì tranh chấp CÙNG một khoá `matters` này.)
 *
 * **Khe hở CÒN LẠI, KHÔNG được khoá này che: `setStatus()` mở lại một luồng ĐÃ ĐÓNG mà người
 * đang đứng tên (`assigned_to`) đã rời đội ngũ TRONG LÚC luồng đóng, KHÔNG được đối chiếu lại.**
 * `setStatus()` chỉ đổi cột `status` — nó không gọi `canHoldTheThread()` (chỉ `assign()` gọi hàm
 * đó), nên mở lại một luồng đã đóng không hỏi lại "người đang đứng tên còn mở được vụ việc này
 * không". Khoá `matters` ở đây giải quyết đúng vấn đề ĐỘC LẬP về ĐỌC DỮ LIỆU CŨ (REPEATABLE READ
 * snapshot); nó không thêm một điều kiện NGHIỆP VỤ nào cho `setStatus()`. Đây là một lỗ hổng
 * KHÁC, được Task 18 nhận (theo phán quyết fix round 3) — không sửa ở đây.
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
