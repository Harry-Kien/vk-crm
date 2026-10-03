<?php

namespace App\Policies;

use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\StageLog;
use App\Models\StageLogView;
use App\Models\User;
use App\Policies\Concerns\ChecksMatterAccess;
use App\Policies\Concerns\ChecksPortalVisibility;
use App\Policies\Concerns\ReadsPortalParents;

/**
 * Biên bản "khách đã đọc dòng tiến độ này" (SPEC §4.18).
 *
 * **TẦNG ĐÃ CHỌN: khách đọc được ở CẢ HAI tầng, và ghi được qua `create`.** Rà soát M2 ghi lại
 * rằng hai tầng đang lệch nhau — global scope cho khách đọc bảng này, còn policy từ chối sạch
 * bằng `$user instanceof User` — và hoãn việc chọn tới lúc dựng luồng yêu cầu. Đây là lúc đó, và
 * đây là lý do chọn như vậy:
 *
 *  - **Bảng này là dữ liệu VỀ CHÍNH KHÁCH.** Nó ghi lại việc họ đã mở một trang và đọc một dòng
 *    cập nhật; không có một cột nào trong đó là thông tin của văn phòng. Một tầng phân quyền từ
 *    chối cho người ta đọc nhật ký hành vi của chính người ta là một lựa chọn cần lý do, và ở
 *    đây không có lý do nào.
 *  - **Hai tầng lệch nhau là một cái bẫy, không phải một lớp phòng thủ.** Trong khi chúng lệch,
 *    mọi màn hình đọc bảng này qua truy vấn (scope cho qua) chạy được, còn mọi màn hình hỏi
 *    `Gate` (policy từ chối) thì không — nên hành vi của portal sẽ phụ thuộc vào việc người viết
 *    màn hình tình cờ dùng đường nào. Thiết kế ba tầng của M2 đòi ba tầng NÓI CÙNG MỘT LUẬT bằng
 *    ba thứ ngôn ngữ khác nhau, không đòi chúng mâu thuẫn nhau.
 *  - **Và vì không chọn thì `create` không có chỗ đứng.** Xem {@see self::create()}: một Action
 *    ghi biên bản thay khách phải hỏi được một câu hỏi phân quyền về chính khách đó.
 *
 * Phạm vi "của mình" ở đây là **theo `Client`, không theo `ClientUser`** — cùng một cách đọc với
 * `ClientRequest` (phán quyết của chủ văn phòng ngày 19/09/2026, xem docblock
 * {@see ClientRequest::applyClientPortalConstraints()}). Hai tài khoản portal của
 * cùng một khách hàng đọc được biên bản của nhau. Điều đó là CÓ CHỦ Ý, không phải một chỗ hở:
 * nhãn "Khách đã xem" ở panel nội bộ (SPEC §7.2) cũng nói về khách hàng chứ không về một tài
 * khoản, nên hai bên bàn đang nhìn cùng một tập dữ liệu. Có test ghim ở
 * `PortalIsolationSweepTest`.
 */
class StageLogViewPolicy
{
    use ChecksMatterAccess;
    use ChecksPortalVisibility;
    use ReadsPortalParents;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, StageLogView $view): bool
    {
        $stageLog = $this->parentWithoutPortalScope($view, 'stageLog');

        if ($stageLog === null) {
            return false;
        }

        // Một biên bản đúng bằng dòng tiến độ nó nói về — không hơn. Uỷ cho `StageLogPolicy` để
        // điều kiện "đã công bố, thuộc vụ việc thấy được" chỉ tồn tại một chỗ.
        //
        // `visibleToPortal()` ở nhánh khách là thiết bị chống lệch của M2 (chạy lại global scope
        // thật), và phải nói đúng phạm vi của nó: nó KHÔNG phải một tầng độc lập ở đây. Đo bằng
        // mutation, xoá nó đi thì **không test nào đỏ** — và điều đó là đúng chứ không phải một
        // lỗ hổng bộ test, vì `StageLogView::applyClientPortalConstraints()` không có một điều
        // kiện nào của riêng mình: nó là đúng một câu `whereHas('stageLog')`, tức cùng một luật
        // mà `$user->can('view', $stageLog)` ngay bên cạnh đã hỏi. Nó được giữ vì ngày nào bảng
        // này có điều kiện riêng, đây là chỗ điều kiện đó được hỏi lại mà không ai phải nhớ ra.
        // (M7 Task 5 đặt `client_access_until` ở `Matter`, không ở đây: bảng này nhận nó qua chuỗi
        // `whereHas('stageLog')` → `whereHas('matter')`, và vẫn chưa có điều kiện riêng.)
        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $view) && $user->can('view', $stageLog)
            : $user->can('view', $stageLog);
    }

    /**
     * "Khách ghi nhận đã đọc dòng này." Trước M5 bảng `stage_log_views` KHÔNG có ability nào cho
     * việc ghi, nên một lần ghi chỉ đi lọt vì không ai hỏi — đúng hình dạng lỗ hổng
     * `DocumentPolicy::create()` trả `true` vô điều kiện mà M4 phải vá.
     *
     * `$context` là tham số ngữ cảnh tuỳ chọn theo quy ước Laravel, giống
     * {@see DocumentPolicy::create()} và {@see ClientRequestPolicy::create()}: nhánh KHÔNG có
     * ngữ cảnh chỉ trả lời câu hỏi giao diện ("tài khoản loại này nói chung có ghi biên bản
     * được không"), còn CHẶN THẬT nằm ở nhánh có ngữ cảnh. `RecordStageLogView` luôn gọi kèm
     * dòng tiến độ, và nghĩa vụ đó được ghi ra ở docblock của Action.
     *
     * **`is_published` được đọc THẲNG trên bản ghi**, cùng thiết bị và cùng lý lẽ với
     * {@see StageLogPolicy::view()}. Vòng đầu của Task 2 để điều kiện ấy chỉ đi qua
     * `visibleToPortal()`, tức qua đúng một câu `where` trong
     * `StageLog::applyClientPortalConstraints()` — và đo được: làm rỗng scope của `StageLog` thì
     * `view` trên một dòng nháp trả `false` còn `create` trả `true`, nên `RecordStageLogView`
     * ghi một biên bản khẳng định khách đã được cho xem một cập nhật văn phòng CHƯA công bố.
     * Đó là đúng thứ bảng này tồn tại để chứng minh, lộn ngược. Ghim ở nghi thức ba tầng của
     * `PortalIsolationSweepTest` bằng một dòng nháp thuộc CHÍNH vụ việc của khách — một dòng của
     * khách khác xanh nhờ điều kiện khác nên không nhìn thấy chỗ này.
     *
     * Điều kiện vụ việc cha thì không nhắc lại ở đây, cùng lý do với `StageLogPolicy::view()`:
     * `canSeeMatter()` đưa nó về `MatterPolicy::view`, nơi nó đã được phát biểu hai lần.
     *
     * Kiểu `mixed` là cố ý, cùng lý lẽ với hai policy kia: khai báo hẹp biến một lần gọi sai ngữ
     * cảnh thành `TypeError` — lỗi 500 — thay vì một lời từ chối. Thứ không phải `StageLog` rơi
     * xuống nhánh từ chối chứ KHÔNG rơi xuống nhánh "không có ngữ cảnh".
     *
     * **Nhân sự không bao giờ ghi được biên bản này, kể cả quản trị.** Giá trị duy nhất của bảng
     * là nó chứng minh KHÁCH đã được cho xem một cập nhật (SPEC §7.2 nhãn "Khách đã xem", và nếu
     * có ngày phải đưa ra thì nó là bằng chứng văn phòng đã báo). Một dòng do nhân sự tạo ra là
     * một dòng văn phòng tự làm chứng cho mình, và nó không phân biệt được với dòng thật.
     *
     * @param  StageLog|null  $context
     */
    public function create(User|ClientUser $user, mixed $context = null): bool
    {
        if (! $user instanceof ClientUser) {
            return false;
        }

        if ($context === null) {
            return true;
        }

        return $context instanceof StageLog
            && (bool) $context->is_published
            && $this->visibleToPortal($user, $context)
            && $this->canSeeMatter($user, $this->parentWithoutPortalScope($context, 'matter'));
    }
}
