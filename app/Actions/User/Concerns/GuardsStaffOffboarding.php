<?php

namespace App\Actions\User\Concerns;

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\ClientRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
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
 *    `leadMatters` (`demotionBlockedByLeadMattersReason()`, ruling round 2/3: trợ lý vẫn giữ được
 *    mốc hạn/yêu cầu khách, chỉ không giữ được vai `lead`). Luật "admin cuối cùng" áp cho tắt
 *    `is_active` VÀ đổi `position` khỏi Admin. Xem docblock hàm đó cho bảng đầy đủ các tổ hợp.
 *
 * Trait này KHÔNG tự quyết định đường vào (`Response::deny()` cho policy, `ValidationException`
 * cho form) — nó chỉ trả về LÝ DO tiếng Việt (hoặc `null` khi không có gì chặn), để mỗi nơi gọi
 * gói lại đúng hình dạng exception của nó. Xem lý lẽ đầy đủ ở hai nơi gọi.
 */
trait GuardsStaffOffboarding
{
    /**
     * Ba mảnh câu ("còn N vụ việc lead / N mốc hạn / N yêu cầu khách"), MỖI mảnh chỉ góp mặt khi
     * loại việc đó CÒN — dùng chung bởi {@see self::offboardingOpenWorkReason()} (vô hiệu hoá/xoá)
     * VÀ {@see self::demotionBlockedByAnyOpenWorkReason()} (đổi sang Kế toán, ruling round 3): hai
     * nơi gọi hỏi CÙNG BA LOẠI việc, chỉ khác câu MỞ ĐẦU ("Không thể vô hiệu hoá hoặc xoá..." so
     * với "Không thể đổi chức danh..." — finding round 3, mục 4). Tách phần THÂN câu (ba mảnh +
     * outro) ra khỏi phần MỞ ĐẦU để không viết lại đúng ba điều kiện `isNotEmpty()` này hai lần.
     */
    private function composeOpenWorkReason(User $user, string $intro): ?string
    {
        $openWork = OpenWork::forUser($user);

        if ($openWork->isEmpty()) {
            return null;
        }

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

        return $intro.' '.implode('; ', $parts).'. '.__('users.offboarding.open_work_outro');
    }

    /**
     * "Việc còn mở mà người này còn đứng tên" — hỏi {@see OpenWork::forUser()} TRÊN TOÀN HỆ THỐNG
     * (`$matter = null`), khác {@see RemoveTeamMember} (Task 3) chỉ hỏi trong
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
     * như có. Ba mảnh câu, MỖI mảnh chỉ góp mặt khi loại việc đó CÒN — một người chỉ còn mốc hạn,
     * không còn vụ việc lead nào, không thấy nhắc tới "Bàn giao" nữa:
     *
     *  - `leadMatters` → "Bàn giao" trên từng vụ việc ({@see ReassignMatter}).
     *  - `deadlines` → "Đổi người phụ trách" trên tab Mốc thời hạn của từng vụ việc
     *    ({@see ChangeDeadlineResponsible}, thêm ở chính vòng sửa này).
     *  - `clientRequests` → "Giao việc" trên tab Yêu cầu từ khách của từng vụ việc
     *    ({@see TriageClientRequest::assign()}, đã có từ M6).
     */
    protected function offboardingOpenWorkReason(User $user): ?string
    {
        return $this->composeOpenWorkReason($user, __('users.offboarding.open_work_intro', ['name' => $user->name]));
    }

    /**
     * "Còn dẫn một vụ việc đang mở" — CHỈ `leadMatters`, khác {@see self::offboardingOpenWorkReason()}
     * (cả ba loại việc). Dùng riêng cho đích TRỢ LÝ của ruling "guard demotion" (fix round 1, thu hẹp
     * ở round 2, giữ nguyên ở round 3 — xem docblock của {@see EditUser::handleRecordUpdate()} cho
     * bảng đầy đủ). Đổi chức danh sang Trợ lý chỉ thật sự để lại hệ quả R7 muốn chặn (SPEC §7.4: Trợ
     * lý không đứng tên `lead_lawyer_id` được) khi người đó CÒN DẪN một vụ — chỉ còn giữ một mốc hạn
     * hay một yêu cầu khách KHÔNG chặn được việc đổi sang Trợ lý, vì Trợ lý vẫn giữ được cả hai loại
     * việc đó (chỉ không giữ được vai `lead`). Bản round 1 dùng chung
     * `offboardingOpenWorkReason()` (cả ba loại) cho MỌI đích không lãnh đạo được — đúng cho vô hiệu
     * hoá, sai cho đích Trợ lý (chặn nhầm một người chỉ còn mốc hạn/yêu cầu khách, không còn vụ việc
     * lead nào). Câu mở đầu riêng (`demotion_intro`, finding round 3 mục 4) — không mượn
     * "Không thể vô hiệu hoá hoặc xoá..." của `offboardingOpenWorkReason()`, vì đây là đổi chức
     * danh, không phải vô hiệu hoá hay xoá.
     */
    protected function demotionBlockedByLeadMattersReason(User $user): ?string
    {
        $leadMatters = OpenWork::forUser($user)->leadMatters;

        if ($leadMatters->isEmpty()) {
            return null;
        }

        return __('users.offboarding.demotion_intro', ['name' => $user->name])
            .' '.__('users.offboarding.open_work_lead_matters', ['count' => $leadMatters->count()]).'. '
            .__('users.offboarding.open_work_outro');
    }

    /**
     * Ruling (fix round 3, mục 5): đổi chức danh sang KẾ TOÁN bị chặn khi người đó còn giữ BẤT KỲ
     * loại việc pháp lý nào — lead vụ, mốc hạn, HAY yêu cầu khách — khác Trợ lý (chỉ chặn vì
     * `leadMatters`, xem {@see self::demotionBlockedByLeadMattersReason()}). Kế toán không xử lý
     * được việc pháp lý nào trong ba loại đó (SPEC §7.4 không cho Kế toán đứng tên `lead_lawyer_id`,
     * và không tab/màn hình nào của portal-side cho Kế toán mở một mốc hạn hay một yêu cầu khách để
     * xử lý), nên còn giữ MỘT trong ba là để lại đúng hệ quả mà R7 muốn chặn: việc mất người xử lý
     * hợp lệ. Cùng thân câu (ba mảnh + outro) với `offboardingOpenWorkReason()` qua
     * {@see self::composeOpenWorkReason()}, chỉ khác câu mở đầu — đây là đổi chức danh, không phải
     * vô hiệu hoá hay xoá.
     */
    protected function demotionBlockedByAnyOpenWorkReason(User $user): ?string
    {
        return $this->composeOpenWorkReason($user, __('users.offboarding.demotion_intro', ['name' => $user->name]));
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
     * khác (`demotionBlockedByLeadMattersReason()`) được thêm CHEN VÀO GIỮA docblock này và hàm nó
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
