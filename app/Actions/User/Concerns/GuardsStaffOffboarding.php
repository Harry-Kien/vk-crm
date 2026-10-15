<?php

namespace App\Actions\User\Concerns;

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Intake\HandIntakeToLawyer;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use App\Support\OpenLeads;
use App\Support\OpenWork;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chặn nghỉ việc khi còn giữ việc dở dang, hoặc khi là quản trị viên đang hoạt động cuối cùng
 * (SPEC §11 "Bàn giao và lưu trữ"; M6.5 Task 4, R7, kéo lên từ M7 R6).
 *
 * Hai luật ĐỘC LẬP, dùng bởi HAI nơi gọi khác nhau — I4 (fix round 1): đoạn dưới đây từng nói SAI
 * là cả hai luật đều áp cho `UserPolicy::delete()`; đã sửa lại đúng code thật:
 *
 *  - {@see UserPolicy::delete()} — vô hiệu hoá (mềm) MỘT tài khoản qua `DeleteAction`/
 *    `DeleteBulkAction`. CHỈ luật "còn việc dở dang" áp ở đây — xem docblock `delete()` cho lý do
 *    luật "admin cuối cùng" KHÔNG hỏi lại ở đó (dòng `$user->isNot($model)` đã chặn tuyệt đối mọi
 *    lần tự xoá từ trước, và một admin khác xoá đúng admin cuối cùng còn lại là một tình huống
 *    không tồn tại được).
 *  - {@see EditUser::handleRecordUpdate()} — tắt `is_active`, đổi `position` khỏi Quản trị, hoặc
 *    đổi `position` sang một chức danh không lãnh đạo được (Trợ lý/Kế toán — ruling "guard
 *    demotion", fix round 1, THU HẸP LẠI ở fix round 3), qua form sửa. Luật "còn việc dở dang" áp
 *    cho TẮT `is_active` (mọi loại việc) VÀ cho đổi SANG KẾ TOÁN (mọi loại việc — ruling round 3:
 *    kế toán không xử lý được BẤT KỲ việc pháp lý nào); đổi SANG TRỢ LÝ chỉ hỏi phần hẹp hơn — CHỈ
 *    `leadMatters` (`demotionBlockedByLeadingWorkReason()`, ruling round 2/3: trợ lý vẫn giữ được
 *    mốc hạn/yêu cầu khách, chỉ không giữ được vai `lead`; làn fi thêm lead được chuyển tư vấn). Luật "admin cuối cùng" áp cho tắt
 *    `is_active` VÀ đổi `position` khỏi Admin. Xem docblock hàm đó cho bảng đầy đủ các tổ hợp.
 *
 * Làn fi (A1): lead tiếp nhận còn mở ({@see OpenLeads}) là việc dở như ba loại kia. Về lead: vô hiệu
 * hoá/xoá và đổi sang Kế toán chặn cả lead được chuyển tư vấn lẫn lead đang phụ trách; đổi sang Nhân viên
 * kinh doanh hay Trợ lý chỉ chặn lead được chuyển tư vấn (hai vai đó vẫn là "Người phụ trách" của lead được, nhưng
 * không mở được vụ từ lead — `HandIntakeToLawyer::canReceive()`).
 *
 * Trait này KHÔNG tự quyết định đường vào (`Response::deny()` cho policy, `ValidationException`
 * cho form) — nó chỉ trả về LÝ DO tiếng Việt (hoặc `null` khi không có gì chặn), để mỗi nơi gọi
 * gói lại đúng hình dạng exception của nó. Xem lý lẽ đầy đủ ở hai nơi gọi.
 */
trait GuardsStaffOffboarding
{
    /**
     * Các mảnh câu ("còn N vụ việc lead / N mốc hạn / N yêu cầu khách / N lead được chuyển tư vấn / N
     * lead đang phụ trách"), MỖI mảnh chỉ góp mặt khi loại việc đó CÒN — dùng chung bởi
     * {@see self::offboardingOpenWorkReason()} (vô hiệu hoá/xoá) VÀ
     * {@see self::demotionBlockedByAnyOpenWorkReason()} (đổi sang Kế toán hay Nhân viên kinh doanh, ruling
     * round 3): các nơi gọi hỏi CÙNG các loại việc, chỉ khác câu MỞ ĐẦU ("Không thể vô hiệu hoá hoặc
     * xoá..." so với "Không thể đổi chức danh..." — finding round 3, mục 4). Tách phần THÂN câu (các mảnh
     * + outro) ra khỏi phần MỞ ĐẦU để không viết lại các điều kiện `isNotEmpty()` này hai lần.
     *
     * Làn fi (A1): hai mảnh lead ({@see OpenLeads}) đứng sau ba mảnh cũ. `$assignedLeadsBlock` = false
     * khi chức danh mới vẫn là "Người phụ trách" của lead được (có `intake.create` — đích Nhân viên kinh
     * doanh): khi đó chỉ lead được chuyển tư vấn chặn, lead đang phụ trách thì không.
     */
    private function composeOpenWorkReason(User $user, string $intro, bool $assignedLeadsBlock = true): ?string
    {
        $openWork = OpenWork::forUser($user);
        $openLeads = OpenLeads::forUser($user);

        $parts = [];

        if ($openWork->leadMatters->isNotEmpty()) {
            $parts[] = __('users.offboarding.open_work_lead_matters', ['count' => $openWork->leadMatters->count()]);
        }

        if ($openWork->deadlines->isNotEmpty()) {
            $parts[] = __('users.offboarding.open_work_deadlines', ['count' => $openWork->deadlines->count()]);
        }

        if ($openWork->clientRequests->isNotEmpty()) {
            $parts[] = __('users.offboarding.open_work_client_requests', ['count' => $openWork->clientRequests->count()]);
        }

        if ($openLeads->consulting->isNotEmpty()) {
            $parts[] = __('users.offboarding.open_work_consulting_leads', ['count' => $openLeads->consulting->count()]);
        }

        if ($assignedLeadsBlock && $openLeads->assigned->isNotEmpty()) {
            $parts[] = __('users.offboarding.open_work_assigned_leads', ['count' => $openLeads->assigned->count()]);
        }

        if ($parts === []) {
            return null;
        }

        return $intro.' '.implode('; ', $parts).'. '.__('users.offboarding.open_work_outro');
    }

    /**
     * "Việc còn mở mà người này còn đứng tên" — hỏi {@see OpenWork::forUser()} và {@see OpenLeads::forUser()}
     * TRÊN TOÀN HỆ THỐNG (`$matter = null`), khác {@see RemoveTeamMember} (Task 3) chỉ hỏi trong
     * phạm vi MỘT vụ việc. Trả về lý do tiếng Việt nêu ĐÚNG số lượng từng loại (R7: "thông điệp
     * nêu đúng số lượng"), hoặc `null` khi không còn gì dở dang.
     *
     * **Fix round 1 (finding CRITICAL) — mỗi loại việc nêu ĐÚNG màn hình xử lý được nó, không còn
     * một câu chung chỉ về "Bàn giao".** Bản trước chỉ nói "Bàn giao qua nút Bàn giao trên từng vụ
     * việc" cho CẢ BA loại việc — đúng cho `leadMatters` (đổi qua `ReassignMatter`), nhưng SAI cho
     * `deadlines` (không đứng tên lead cũng có mốc, và `ReassignMatter` chỉ chuyển việc của LEAD)
     * và `clientRequests` (không đường ra nào qua "Bàn giao"). Một trợ lý còn đứng tên một mốc
     * chưa xong bị chặn nghỉ việc, đọc đúng câu, bấm đúng nút "Bàn giao" mà nút đó không đổi được
     * gì cho họ — một lời từ chối chỉ sai đường thoát còn tệ hơn không có đường thoát, vì nó có vẻ
     * như có. MỖI mảnh câu chỉ góp mặt khi loại việc đó CÒN — một người chỉ còn mốc hạn,
     * không còn vụ việc lead nào, không thấy nhắc tới "Bàn giao" nữa:
     *
     *  - `leadMatters` → "Bàn giao" trên từng vụ việc ({@see ReassignMatter}).
     *  - `deadlines` → "Đổi người phụ trách" trên tab Mốc thời hạn của từng vụ việc
     *    ({@see ChangeDeadlineResponsible}, thêm ở chính vòng sửa này).
     *  - `clientRequests` → "Giao việc" trên tab Yêu cầu từ khách của từng vụ việc
     *    ({@see TriageClientRequest::assign()}, đã có từ M6).
     *  - lead được chuyển tư vấn (làn fi) → "Chuyển cho luật sư" trên từng lead ({@see HandIntakeToLawyer}).
     *  - lead đang phụ trách (làn fi) → ô "Người phụ trách" trên trang sửa từng lead.
     */
    protected function offboardingOpenWorkReason(User $user): ?string
    {
        return $this->composeOpenWorkReason($user, __('users.offboarding.open_work_intro', ['name' => $user->name]));
    }

    /**
     * Việc chỉ người mở/dẫn được vụ việc giữ được: vụ đang dẫn (`leadMatters`) và lead được chuyển tư vấn
     * (làn fi, A1 — `HandIntakeToLawyer::canReceive()` đòi `intake.convert` + `matter.create`). Khác
     * {@see self::offboardingOpenWorkReason()} (mọi loại việc). Dùng riêng cho đích TRỢ LÝ của ruling
     * "guard demotion" (fix round 1, thu hẹp ở round 2, giữ nguyên ở round 3 — xem docblock của
     * {@see EditUser::handleRecordUpdate()} cho bảng đầy đủ). Đổi chức danh sang Trợ lý chỉ thật sự để
     * lại hệ quả R7 muốn chặn (SPEC §7.4: Trợ lý không đứng tên `lead_lawyer_id` được, không mở vụ từ
     * lead được) khi người đó CÒN DẪN một vụ hay còn giữ một lead được chuyển tư vấn — chỉ còn giữ một mốc
     * hạn, một yêu cầu khách hay một lead mình là "Người phụ trách" KHÔNG chặn được việc đổi sang Trợ lý,
     * vì Trợ lý vẫn giữ được các việc đó. Bản round 1 dùng chung `offboardingOpenWorkReason()` cho MỌI
     * đích không lãnh đạo được — đúng cho vô hiệu hoá, sai cho đích Trợ lý (chặn nhầm một người chỉ còn
     * mốc hạn/yêu cầu khách). Câu mở đầu riêng (`demotion_intro`, finding round 3 mục 4) — không mượn
     * "Không thể vô hiệu hoá hoặc xoá..." của `offboardingOpenWorkReason()`, vì đây là đổi chức
     * danh, không phải vô hiệu hoá hay xoá.
     */
    protected function demotionBlockedByLeadingWorkReason(User $user): ?string
    {
        $leadMatters = OpenWork::forUser($user)->leadMatters;
        $consultingLeads = OpenLeads::forUser($user)->consulting;

        $parts = [];

        if ($leadMatters->isNotEmpty()) {
            $parts[] = __('users.offboarding.open_work_lead_matters', ['count' => $leadMatters->count()]);
        }

        if ($consultingLeads->isNotEmpty()) {
            $parts[] = __('users.offboarding.open_work_consulting_leads', ['count' => $consultingLeads->count()]);
        }

        if ($parts === []) {
            return null;
        }

        return __('users.offboarding.demotion_intro', ['name' => $user->name])
            .' '.implode('; ', $parts).'. '.__('users.offboarding.open_work_outro');
    }

    /**
     * Ruling (fix round 3, mục 5): đổi chức danh sang KẾ TOÁN bị chặn khi người đó còn giữ BẤT KỲ
     * loại việc nào — lead vụ, mốc hạn, yêu cầu khách, HAY lead tiếp nhận (làn fi: kế toán không có quyền
     * `intake.*` nào) — khác Trợ lý (xem {@see self::demotionBlockedByLeadingWorkReason()}). Kế toán không
     * xử lý được việc nào trong các loại đó (SPEC §7.4 không cho Kế toán đứng tên `lead_lawyer_id`,
     * và không tab/màn hình nào cho Kế toán mở một mốc hạn, một yêu cầu khách hay một lead để
     * xử lý), nên còn giữ MỘT là để lại đúng hệ quả mà R7 muốn chặn: việc mất người xử lý hợp lệ.
     * Làn kd: đích Nhân viên kinh doanh cũng đi đường này (không `matter.*`), nhưng với
     * `$assignedLeadsBlock` = false — nhân viên kinh doanh vẫn là "Người phụ trách" của lead được.
     * Cùng thân câu với `offboardingOpenWorkReason()` qua {@see self::composeOpenWorkReason()}, chỉ khác
     * câu mở đầu — đây là đổi chức danh, không phải vô hiệu hoá hay xoá.
     */
    protected function demotionBlockedByAnyOpenWorkReason(User $user, bool $assignedLeadsBlock = true): ?string
    {
        return $this->composeOpenWorkReason($user, __('users.offboarding.demotion_intro', ['name' => $user->name]), $assignedLeadsBlock);
    }

    /**
     * "Admin đang hoạt động cuối cùng không thể tự hạ chức danh, tự vô hiệu hoá hay tự xoá" (R7).
     *
     * Đọc theo HIỆU ỨNG (còn ai khác giữ vai admin đang hoạt động sau thay đổi này không), không
     * theo "actor có phải chính người này không" — một admin khác đổi chức danh/vô hiệu hoá/xoá
     * đúng người admin cuối cùng còn lại cũng phải bị chặn, vì hệ quả giống hệt: hệ thống mất nốt
     * người quản trị được. `$remainsActiveAdmin` là câu trả lời "sau thay đổi này, $user còn được
     * tính là admin đang hoạt động không" — nơi gọi tự tính (tắt `is_active`, đổi `position` khỏi
     * Admin, hoặc xoá hẳn đều làm câu này thành `false`).
     *
     * **Sửa lỗi trình bày (fix round 3): docblock này từng đứng LẠC khỏi hàm của nó** — một hàm
     * khác (`demotionBlockedByLeadingWorkReason()`) được thêm CHEN VÀO GIỮA docblock này và hàm nó
     * mô tả ở vòng sửa round 2, để lại docblock này đứng trên một hàm SAI và hàm THẬT của nó (dưới
     * đây) không còn docblock nào. Đã dọn lại đúng chỗ.
     */
    protected function wouldLeaveNoActiveAdmin(User $user, bool $remainsActiveAdmin): bool
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin->value)) {
            // Không phải admin đang hoạt động hôm nay — không có gì để bảo vệ ở đây.
            return false;
        }

        if ($remainsActiveAdmin) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($user->getKey())
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', Role::Admin->value))
            ->exists();
    }

    protected function lastActiveAdminReason(): string
    {
        return __('users.offboarding.last_admin_blocked');
    }

    /**
     * Final review X4 (A-I3): hạ một QUẢN TRỊ VIÊN xuống chức danh khác. Admin thấy mọi vụ
     * `restricted` (`Matter::scopeListableBy`); chức danh mới chỉ thấy vụ `restricted` mình PHỤ
     * TRÁCH. Nên mọi vụ `restricted` chưa xoá mà người này KHÔNG phụ trách nhưng vẫn còn ở đội ngũ,
     * còn đứng tên một mốc hạn chưa xong, hay còn giữ một yêu cầu khách chưa đóng, sẽ có một thành
     * viên/người giữ việc không mở được chính vụ đó. `null` khi không có vụ nào như vậy; ngược lại
     * một câu tiếng Việt kèm số vụ.
     *
     * Chỉ gọi khi người này ĐANG là admin và chức danh mới không phải admin — caller quyết định.
     */
    protected function demotionFromAdminBlockedByRestrictedReason(User $user): ?string
    {
        $userId = $user->getKey();

        $count = Matter::query()
            ->where('confidentiality', Confidentiality::Restricted->value)
            ->where('lead_lawyer_id', '!=', $userId)
            ->where(fn (Builder $holds) => $holds
                ->whereHas('team', fn (Builder $team) => $team->whereKey($userId))
                ->orWhereHas('deadlines', fn (Builder $deadline) => $deadline
                    ->where('responsible_user_id', $userId)
                    ->where('is_completed', false))
                ->orWhereHas('clientRequests', fn (Builder $request) => $request
                    ->where('assigned_to', $userId)
                    ->where('status', '!=', ClientRequestStatus::Closed->value)))
            ->count();

        return $count === 0
            ? null
            : __('users.offboarding.demotion_from_admin_restricted', ['name' => $user->name, 'count' => $count]);
    }
}
