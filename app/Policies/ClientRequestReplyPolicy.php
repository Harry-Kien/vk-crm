<?php

namespace App\Policies;

use App\Models\ClientRequestReply;
use App\Models\ClientUser;
use App\Models\User;
use App\Policies\Concerns\ChecksPortalVisibility;
use App\Policies\Concerns\ReadsPortalParents;

/**
 * Trả lời trong một cuộc trao đổi với khách (SPEC §4.14).
 *
 * **TẦNG ĐÃ CHỌN: khách đọc được ở CẢ HAI tầng.** Cùng một lần chọn với
 * {@see StageLogViewPolicy}, và ở đây lý do đến thẳng từ đặc tả: SPEC §8.3 mục 7 nói khách "xem
 * lại lịch sử trao đổi". Một cuộc trao đổi mà khách chỉ đọc được câu mình hỏi chứ không đọc được
 * câu văn phòng trả lời thì không phải một cuộc trao đổi — và tiêu chí SPEC §14 mục 4 ("khách
 * nhận được phản hồi khi bị từ chối") không chứng minh được. Trước M5, global scope cho khách
 * đọc bảng này còn policy từ chối sạch (`$user instanceof User`); hai tầng lệch nhau như vậy
 * khiến hành vi của portal phụ thuộc vào việc màn hình hỏi bằng truy vấn hay bằng `Gate`.
 *
 * **Phạm vi đọc bằng ĐÚNG phạm vi của yêu cầu cha, một định nghĩa duy nhất.** Không chép lại
 * điều kiện nào: `view()` uỷ cho {@see ClientRequestPolicy::view()}, nên cách đọc "của chính
 * mình" — theo `Client`, không theo `ClientUser` (phán quyết 19/09/2026) — chỉ được phát biểu
 * một lần, ở model `ClientRequest`.
 *
 * `visibleToPortal()` ở nhánh khách là thiết bị chống lệch của M2, và phạm vi của nó phải được
 * nói đúng: **nó không phải một tầng độc lập ở đây.** Đo bằng mutation — xoá nó đi thì không
 * test nào đỏ, còn xoá `$user->can('view', $request)` thì `PortalIsolationSweepTest` đỏ ngay.
 * Lý do không phải bộ test thiếu: `ClientRequestReply::applyClientPortalConstraints()` là đúng
 * một câu `whereHas('request')`, không có điều kiện nào của riêng nó, nên nó nói lại đúng cái
 * luật mà lời gọi bên cạnh đã hỏi. Giữ lại để ngày nào bảng này có điều kiện riêng thì đây là
 * chỗ nó được hỏi lại, chứ không phải để đếm thành một tầng thứ hai.
 *
 * **Chưa có ability `create` ở đây, và đó là một ranh giới chứ không phải một chỗ quên.** Việc
 * khách viết trả lời tiếp vào một yêu cầu đã gửi là mô hình hội thoại của Task 6 (phán quyết
 * 19/09/2026: trả lời theo luồng, không mở yêu cầu mới), và `ReplyToClientRequest` ra đời ở đó.
 * Task 2 chỉ chốt tầng ĐỌC. Ability ghi phải sinh ra cùng Action gọi nó — nếu không thì nó lại
 * là một cái cổng không ai hỏi, đúng thứ M4 vừa phải vá ở `DocumentPolicy::create()`.
 */
class ClientRequestReplyPolicy
{
    use ChecksPortalVisibility;
    use ReadsPortalParents;

    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, ClientRequestReply $reply): bool
    {
        $request = $this->parentWithoutPortalScope($reply, 'request');

        if ($request === null) {
            return false;
        }

        return $user instanceof ClientUser
            ? $this->visibleToPortal($user, $reply) && $user->can('view', $request)
            : $user->can('view', $request);
    }
}
