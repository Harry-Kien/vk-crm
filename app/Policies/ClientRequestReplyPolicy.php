<?php

namespace App\Policies;

use App\Actions\Portal\ReplyToClientRequest;
use App\Enums\Permission;
use App\Exceptions\ClientRequestNotOpen;
use App\Models\ClientRequest;
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
 * **Ability `create` ra đời ở M5 Task 6, cùng lúc với Action gọi nó — đúng như Task 2 đã hẹn.**
 * Task 2 cố ý KHÔNG viết nó: một ability ghi mà chưa có nơi gọi là một cái cổng không ai hỏi,
 * đúng thứ M4 vừa phải vá ở `DocumentPolicy::create()`. Mô hình hội thoại đã được chốt ngày
 * 19/09/2026 — **trả lời theo luồng**, khách viết tiếp vào chính yêu cầu đã gửi chứ không mở một
 * yêu cầu mới — và {@see ReplyToClientRequest} là nơi duy nhất hỏi ability này. Xem
 * {@see self::create()}.
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

    /**
     * "Người này có được viết thêm vào cuộc trao đổi kia không." Hỏi bởi
     * {@see ReplyToClientRequest}, và **luôn kèm `ClientRequest`**.
     *
     * # Quy ước tham số ngữ cảnh tuỳ chọn, và ba nhánh của nó
     *
     * Cùng hình dạng với {@see DocumentPolicy::create()}, {@see ClientRequestPolicy::create()} và
     * {@see StageLogViewPolicy::create()} — đọc ba tệp đó trước khi sửa tệp này:
     *
     *  - **Không ngữ cảnh** (`null`) chỉ trả lời câu hỏi GIAO DIỆN: "loại tài khoản này nói chung
     *    có viết trả lời được không", tức có vẽ ô nhập hay không. Với `ClientUser` nó là `true`
     *    vô điều kiện, cố ý. Nó **không** là cổng chặn, và Action có nghĩa vụ không bao giờ hỏi
     *    trống — nghĩa vụ đó được ghi ở docblock `ReplyToClientRequest::authorize()`.
     *  - **Có ngữ cảnh đúng kiểu** là cổng thật.
     *  - **Ngữ cảnh SAI KIỂU bị TỪ CHỐI**, không được coi là "không có ngữ cảnh". Kiểu khai báo
     *    là `mixed` một cách cố ý, cùng lý lẽ với ba policy kia: một khai báo hẹp biến một lời
     *    gọi sai ngữ cảnh thành `TypeError` — lỗi 500 — thay vì một lời từ chối, và một lần rơi
     *    xuống nhánh `null` sẽ biến nó thành một lời ĐỒNG Ý.
     *
     * # Hai guard, hai luật
     *
     * - **Khách**: viết được vào đúng những cuộc trao đổi mình ĐỌC được, không hơn — nên điều
     *   kiện uỷ thẳng cho `ClientRequestPolicy::view()`, nơi cách đọc "của chính mình" (**theo
     *   `Client`**, phán quyết 19/09/2026) được phát biểu một lần duy nhất. Hệ quả cần nói
     *   thẳng, vì nó là một quyết định về sự riêng tư trong một gia đình: hai tài khoản portal
     *   của cùng một khách hàng viết được vào cuộc trao đổi của nhau.
     * - **Nhân sự**: uỷ cho `ClientRequestPolicy::update()`, tức `MatterPolicy::update` —
     *   **không phải** "thấy được vụ việc". Trả lời một khách hàng là GHI vào vụ việc đó, và
     *   SPEC §5 không cho kế toán (`matter.update` không có trong vai đó) viết vào hồ sơ. Không
     *   chép lại điều kiện nào: nếu một ngày `MatterPolicy::update` đổi, câu trả lời ở đây đổi
     *   theo.
     *
     * # `! $context->trashed()` đứng TRƯỚC cả hai nhánh, và nó là một câu phát biểu bằng THUỘC TÍNH
     *
     * Cùng thiết bị và cùng lý lẽ với {@see ClientRequestPolicy::view()}: không có nó, điều kiện
     * "một yêu cầu đã rút thì không nhận thêm chữ nào" chỉ còn được giữ bên trong
     * `visibleToPortal()` / `whereHas('matter')` — tức bởi `SoftDeletingScope`, một scope KHÁC mà
     * một lần `withTrashed()` gỡ ra. Vòng sửa của Task 2 đã lên án đúng hình dạng ấy ở bốn chỗ
     * khác. Nó đứng trước cả hai nhánh vì nó đúng cho cả hai: nhánh nhân sự đi qua
     * `ClientRequestPolicy::update()`, và hàm đó **không** hỏi `trashed()`.
     *
     * # Cái nó cố ý KHÔNG hỏi
     *
     * **Trạng thái `closed`.** Đó là một cổng TRẠNG THÁI, không phải một cổng quyền, và dự án
     * tách hai câu hỏi đó ở mọi chỗ khác (`ReviewChecklistItem::guardDecisionAgainstState()` so
     * với `MatterChecklistItemPolicy::review`). Nó sống ở {@see ClientRequestNotOpen}, được hỏi
     * SAU cổng này, và nó nói ra một câu thật ("cuộc trao đổi đã kết thúc") thay vì câu chung
     * của SPEC §10.10 — điều chỉ an toàn vì cổng quyền đã chạy trước.
     *
     * **`is_active` của tài khoản.** Cùng chỗ hở đã ghi ở `DocumentPolicy::create()` và
     * `StageLogViewPolicy::create()`: nhánh không-ngữ-cảnh trả `true` cho một tài khoản đã bị
     * khoá, nên màn hình vẫn VẼ ô nhập cho họ — bấm gửi thì Action từ chối
     * ({@see ChecksAccountActive}, SPEC §10.9). Không sai về an toàn, sai về việc mời người ta
     * viết một câu rồi vứt đi. Ghi lại ở đây để nó là cùng một chỗ hở đã biết chứ không phải một
     * chỗ hở mới.
     *
     * @param  ClientRequest|null  $context
     */
    public function create(User|ClientUser $user, mixed $context = null): bool
    {
        if ($context === null) {
            return $user instanceof ClientUser || $user->can(Permission::MatterUpdate->value);
        }

        if (! $context instanceof ClientRequest || $context->trashed()) {
            return false;
        }

        return $user instanceof ClientUser
            ? $user->can('view', $context)
            : $user->can('update', $context);
    }
}
