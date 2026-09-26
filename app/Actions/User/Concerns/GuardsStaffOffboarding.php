<?php

namespace App\Actions\User\Concerns;

use App\Actions\Deadline\ChangeDeadlineResponsible;
use App\Actions\Matter\ReassignMatter;
use App\Actions\Matter\RemoveTeamMember;
use App\Actions\Portal\TriageClientRequest;
use App\Enums\Role;
use App\Models\User;
use App\Support\OpenWork;

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
 *  - {@see EditUser::handleRecordUpdate()} — tắt `is_active`, đổi `position` sang một chức danh
 *    không lãnh đạo được (Trợ lý/Kế toán — ruling fix round 1 "guard demotion"), hoặc đổi
 *    `position` khỏi Quản trị, qua form sửa. Luật "còn việc dở dang" áp cho TẮT `is_active` VÀ
 *    cho đổi sang chức danh không lãnh đạo được; luật "admin cuối cùng" áp cho tắt `is_active` VÀ
 *    đổi `position` khỏi Admin. Xem docblock hàm đó cho bảng đầy đủ bốn tổ hợp.
 *
 * Trait này KHÔNG tự quyết định đường vào (`Response::deny()` cho policy, `ValidationException`
 * cho form) — nó chỉ trả về LÝ DO tiếng Việt (hoặc `null` khi không có gì chặn), để mỗi nơi gọi
 * gói lại đúng hình dạng exception của nó. Xem lý lẽ đầy đủ ở hai nơi gọi.
 */
trait GuardsStaffOffboarding
{
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

        return __('users.offboarding.open_work_intro', ['name' => $user->name])
            .' '.implode('; ', $parts).'. '
            .__('users.offboarding.open_work_outro');
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
     */
    /**
     * "Còn dẫn một vụ việc đang mở" — CHỈ `leadMatters`, khác {@see self::offboardingOpenWorkReason()}
     * (cả ba loại việc). Dùng riêng cho ruling "guard demotion" (fix round 1) SAU KHI vòng sửa round
     * 2 thu hẹp lại (minor finding): đổi chức danh sang Trợ lý/Kế toán chỉ thật sự để lại hệ quả R7
     * muốn chặn (SPEC §7.4: hai chức danh đó không đứng tên `lead_lawyer_id` được) khi người đó CÒN
     * DẪN một vụ — chỉ còn giữ một mốc hạn hay một yêu cầu khách KHÔNG chặn được việc đổi sang Trợ
     * lý, vì Trợ lý vẫn giữ được cả hai loại việc đó (chỉ không giữ được vai `lead`). Bản trước dùng
     * chung `offboardingOpenWorkReason()` (cả ba loại) cho cả vô hiệu hoá LẪN đổi chức danh — đúng
     * cho vô hiệu hoá (một người nghỉ việc không giữ được BẤT KỲ loại việc nào), sai cho đổi chức
     * danh (chặn nhầm một người chỉ còn mốc hạn/yêu cầu khách, không còn vụ việc lead nào).
     */
    protected function demotionBlockedByLeadMattersReason(User $user): ?string
    {
        $leadMatters = OpenWork::forUser($user)->leadMatters;

        if ($leadMatters->isEmpty()) {
            return null;
        }

        return __('users.offboarding.open_work_intro', ['name' => $user->name])
            .' '.__('users.offboarding.open_work_lead_matters', ['count' => $leadMatters->count()]).'. '
            .__('users.offboarding.open_work_outro');
    }

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
}
