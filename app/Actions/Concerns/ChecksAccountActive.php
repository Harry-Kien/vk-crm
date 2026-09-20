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
 * **Phạm vi hôm nay, nói cho đủ.** Ba Action hỏi câu này: `SubmitClientDocument` hỏi thẳng, còn
 * `ReviewChecklistItem` và `MarkChecklistItemNotApplicable` hỏi qua `OpensChecklistItem`. Tầng
 * policy thì chưa: nhánh khách của `DocumentPolicy::create()` vẫn trả `true` cho một tài khoản
 * đã bị khoá khi được hỏi KHÔNG kèm ngữ cảnh, nghĩa là màn hình M5 vẫn VẼ cái nút "Gửi tệp" cho
 * họ — bấm vào thì Action từ chối. Không sai về an toàn, nhưng là một cái nút mời người ta bấm
 * vào một lời từ chối. Đã ghi vào bảng việc mang sang của kế hoạch M5.
 *
 * Phía nhân sự thì rộng hơn thế: `MatterPolicy::view/update` và các nhánh nhân sự của
 * `DocumentPolicy` VẪN chỉ được `canAccessPanel()` canh. Đưa nốt chúng vào đây là một thay đổi
 * có bán kính rộng hơn nhiều — mọi màn hình admin đi qua `MatterPolicy::view` — nên nó được ghi
 * lại chứ không làm lén ở vòng sửa này.
 */
trait ChecksAccountActive
{
    protected function accountIsActive(User|ClientUser $actor): bool
    {
        return (bool) $actor->is_active;
    }
}
