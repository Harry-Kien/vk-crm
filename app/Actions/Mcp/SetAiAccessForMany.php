<?php

namespace App\Actions\Mcp;

use App\Enums\AiAccessMode;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Validation\ValidationException;

/**
 * "Đổi chế độ" HÀNG LOẠT của bảng nhân sự trên trang "Kết nối AI" (làn ai, 2026-10-15). Phán quyết của
 * brief mục 5: {@see SetUserAiAccess} gọi lặp được an toàn — mỗi lần một transaction riêng, câu đầu
 * tiên khoá dòng `users` của đúng người đó, tự hỏi `Gate::forUser($actor)->authorize('setAiAccess')`,
 * tự kiểm điều kiện bật, tự ghi audit và tự thu hồi kết nối khi hạ về "Tắt". Nên lớp này KHÔNG có luật
 * riêng: nó gọi {@see SetUserAiAccess} đúng MỘT lần cho mỗi người, theo thứ tự, và chỉ gom kết quả.
 *
 *  - Người không bật được (tài khoản bị vô hiệu hoá, thiếu `matter.view` — ví dụ kế toán): Action từ
 *    chối bằng `ValidationException`; người đó bị BỎ QUA, tên vào `skipped`, những người còn lại vẫn
 *    được đặt. Người này đã đặt xong không bị cuộn lại vì người sau lỗi (mỗi người một transaction,
 *    như khi quản trị bấm từng dòng).
 *  - Thiếu quyền: `AuthorizationException` của Action ở NGƯỜI ĐẦU TIÊN, chưa ghi gì — quyền không phụ
 *    thuộc người được đặt ({@see UserPolicy::setAiAccess()}), nên không có lô "một nửa".
 *  - Người đã đúng chế độ: Action không ghi gì; vẫn đếm vào `applied` (chế độ của họ đã là chế độ chọn).
 *
 * Không gửi thư, không thông báo — như {@see SetUserAiAccess}.
 */
final class SetAiAccessForMany
{
    public function __construct(private readonly SetUserAiAccess $setUserAiAccess) {}

    /**
     * @param  iterable<User>  $targets
     * @return array{applied: int, skipped: list<string>}
     */
    public function handle(iterable $targets, AiAccessMode $mode, User $actor): array
    {
        $applied = 0;
        $skipped = [];

        foreach ($targets as $target) {
            try {
                $this->setUserAiAccess->handle($target, $mode, $actor);
                $applied++;
            } catch (ValidationException) {
                $skipped[] = (string) $target->name;
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }
}
