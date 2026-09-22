<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\MatterType;
use App\Models\User;

/**
 * Nhãn giai đoạn/loại vụ việc: ai cũng đọc được (portal cần hiển thị), chỉ `settings.manage`
 * được ghi — và XOÁ thì còn một điều kiện nữa, xem {@see self::delete()}.
 */
class MatterTypePolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, MatterType $matterType): bool
    {
        return true;
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function update(User|ClientUser $user, MatterType $matterType): bool
    {
        return $this->create($user);
    }

    /**
     * **Không xoá được một loại vụ việc còn hồ sơ nào dùng.**
     *
     * Xoá mềm một loại làm quan hệ `Matter::matterType()` trả `null` cho mọi hồ sơ đang đứng
     * trong loại ấy, và trang chi tiết trên cổng khách hàng đọc qua đúng quan hệ đó hai lần
     * (khối 1 và nhãn giai đoạn ở khối 3, SPEC §8.3). Trước lần vá này, MỘT cú bấm ở màn hình
     * thiết lập biến trang của từng khách hàng liên quan thành một trang lỗi — và người bấm
     * không có cách nào nhìn thấy điều đó từ chỗ mình đứng.
     *
     * Phía đọc đã được viết lại cho an toàn với `null` (xem `Matter::currentStage()`),
     * nhưng an toàn ở đó chỉ có nghĩa là "không vỡ": khách vẫn mất nhãn giai đoạn của mình và
     * đọc một câu chung chung. Nên chốt chặn thật nằm ở đây, và nó nằm ở POLICY chứ không ở một
     * nút bấm: Filament hỏi `Gate` bằng đúng tên `delete` cho `DeleteAction`, nên một luật đặt ở
     * đây phủ mọi lối xoá của panel thay vì chỉ cái nút mà người viết form nhớ ra.
     *
     * **`withTrashed()`, và câu đó là một lựa chọn.** Một hồ sơ đã xoá mềm vẫn khôi phục được —
     * đó là toàn bộ ý nghĩa của xoá mềm ở hệ thống này — nên nó vẫn là một người dùng của loại
     * vụ việc này. Bỏ `withTrashed()` đi thì xoá loại rồi khôi phục hồ sơ dựng lại đúng cái trang
     * vỡ mà luật này đóng. Giá phải trả được nói thẳng: một loại đã từng có hồ sơ thì không xoá
     * được nữa, chỉ tắt `is_active` — với một văn phòng luật thì đó là hành vi đúng, vì cấu hình
     * mà một hồ sơ cũ trỏ vào là một phần của hồ sơ ấy.
     */
    public function delete(User|ClientUser $user, MatterType $matterType): bool
    {
        return $this->create($user)
            && ! $matterType->matters()->withTrashed()->exists();
    }
}
