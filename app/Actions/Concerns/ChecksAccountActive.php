<?php

namespace App\Actions\Concerns;

use App\Models\ClientUser;
use App\Models\User;

/**
 * "Tài khoản đang thao tác này còn hiệu lực không" — MỘT định nghĩa, cho cả hai guard.
 *
 * **Vì sao câu hỏi này phải được hỏi lại ở tầng Action.** Cả `User::canAccessPanel()` lẫn
 * `ClientUser::canAccessPanel()` đều đọc đúng cột `is_active`, và cho tới hôm nay chúng là chỗ
 * DUY NHẤT đọc nó. Một cái cổng chỉ đứng ở cửa panel là một cái cổng đúng cho tới lần đầu có
 * người gọi nghiệp vụ từ chỗ khác: một job chạy lại, một lệnh console, một màn hình gọi thẳng
 * Action. Các Action trong dự án này được viết CỐ Ý để không đọc `auth()` — chúng nhận `$actor`
 * tường minh — nên với chúng, "người này đã bị vô hiệu hoá chưa" không phải một câu hỏi mà panel
 * đã trả lời hộ. Đó chính là lập luận `SubmitClientDocument` tự viết ra cho mình và rồi bỏ sót
 * đúng một điều kiện.
 *
 * SPEC §10.9 nói thẳng cho phía khách: `client_users.is_active = false` thì mọi phiên đang mở
 * phải mất hiệu lực NGAY ở request kế tiếp, không đợi hết hạn session. Một tài khoản vừa bị vô
 * hiệu mà vẫn nộp được tài liệu vào hồ sơ là đúng thứ điều khoản đó tồn tại để chặn. Phía nhân
 * sự SPEC không có điều khoản tương đương, nhưng lập luận "đúng cho tới lần đầu gọi từ một job"
 * không phân biệt guard, nên `ReviewChecklistItem` hỏi cùng câu hỏi.
 *
 * **Phạm vi hôm nay, nói cho đủ.** Bốn Action hỏi câu này: `SubmitClientDocument` và
 * `RecordStageLogView` hỏi thẳng, còn `ReviewChecklistItem` và `MarkChecklistItemNotApplicable`
 * hỏi qua `OpensChecklistItem`. Tầng policy thì chưa: nhánh khách của `DocumentPolicy::create()`
 * — và y hệt vậy, của `StageLogViewPolicy::create()` — vẫn trả `true` cho một tài khoản đã bị
 * khoá hoặc đã xoá mềm khi được hỏi KHÔNG kèm ngữ cảnh, nghĩa là màn hình M5 vẫn VẼ cái nút
 * "Gửi tệp" cho họ — bấm vào thì Action từ chối. Không sai về an toàn, nhưng là một cái nút mời
 * người ta bấm vào một lời từ chối. Đã ghi vào bảng việc mang sang của kế hoạch M5.
 *
 * Phía nhân sự thì rộng hơn thế: `MatterPolicy::view/update` và các nhánh nhân sự của
 * `DocumentPolicy` VẪN chỉ được `canAccessPanel()` canh. Đưa nốt chúng vào đây là một thay đổi
 * có bán kính rộng hơn nhiều — mọi màn hình admin đi qua `MatterPolicy::view` — nên nó được ghi
 * lại chứ không làm lén ở vòng sửa này.
 */
trait ChecksAccountActive
{
    /**
     * **`trashed()` đứng cạnh `is_active`, không thay nó.** Cả `User` lẫn `ClientUser` đều dùng
     * `SoftDeletes`, và xoá mềm một tài khoản KHÔNG hạ cờ `is_active`: hai cột nói hai chuyện
     * khác nhau và không cột nào kéo theo cột kia. Đo được trước vòng sửa này — một `ClientUser`
     * đã xoá mềm vẫn ghi được một biên bản "khách đã xem".
     *
     * Đây là chỗ đúng để hỏi, chứ không phải trong `ClientUser`: các Action nhận `$actor` tường
     * minh và đối tượng đó có thể đến từ một lần đọc `withTrashed()`, từ một job đang chạy lại
     * một hàng đợi cũ, hay từ một phiên mở trước khi tài khoản bị xoá. Cùng lập luận với đoạn
     * trên: một cái cổng chỉ đứng ở cửa panel là một cái cổng đúng cho tới lần đầu có người gọi
     * nghiệp vụ từ chỗ khác.
     *
     * Với một bảng bằng chứng như `stage_log_views`, hệ quả còn nặng hơn một lần ghi thừa: một
     * biên bản mang tên một tài khoản KHÔNG CÒN TỒN TẠI là một bằng chứng tự mâu thuẫn, và nó
     * mâu thuẫn ở đúng chỗ người phản biện sẽ nhìn đầu tiên.
     */
    protected function accountIsActive(User|ClientUser $actor): bool
    {
        return (bool) $actor->is_active && ! $actor->trashed();
    }
}
