<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\MatterType;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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
     *
     * **Trả `Response::deny()` kèm số hồ sơ (Task 19), không `bool` trần** — cùng kỹ thuật
     * `ClientPolicy::delete()`: `authorizeIndividualRecords('delete')` của `MatterTypesTable` đọc
     * đúng thông điệp này để hiện lý do khi bulk-delete bỏ qua một dòng, thay vì một notification
     * chung chung "một số bản ghi không xoá được".
     */
    public function delete(User|ClientUser $user, MatterType $matterType): bool|Response
    {
        if (! $this->create($user)) {
            return false;
        }

        $matterCount = $matterType->matters()->withTrashed()->count();

        if ($matterCount > 0) {
            return Response::deny(__('matter_types.delete_blocked_in_use', ['count' => $matterCount]));
        }

        return true;
    }

    /**
     * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy" — C1-class bulk-action hole):
     * `MatterTypesTable` không có `authorizeIndividualRecords()` gắn với các nút xoá/khôi phục/xoá
     * vĩnh viễn hàng loạt, và KHÔNG policy nào của model này định nghĩa `deleteAny`/`restoreAny`/
     * `forceDeleteAny` — Filament (không ở chế độ nghiêm ngặt) coi một ability không có phương
     * thức tương ứng là CHO PHÉP, nên trước bản vá này bất kỳ ai mở được trang danh sách đều bấm
     * xoá hàng loạt trót lọt, bỏ qua cả luật `settings.manage` lẫn luật "không còn hồ sơ nào dùng"
     * của {@see self::delete()}. Bốn phương thức dưới đây chỉ là CỔNG THÔ (nút có bấm được không);
     * luật thật cho TỪNG bản ghi vẫn ở {@see self::delete()}, và `MatterTypesTable` phải gọi
     * `->authorizeIndividualRecords('delete')` để mỗi dòng đi qua đúng đó trước khi bị xoá.
     */
    public function deleteAny(User|ClientUser $user): bool
    {
        return $this->create($user);
    }

    /** Không có luật riêng cho khôi phục (chiều ngược của {@see self::delete()}): còn settings.manage là khôi phục được. */
    public function restore(User|ClientUser $user, MatterType $matterType): bool
    {
        return $this->create($user);
    }

    /** Cổng thô của `RestoreBulkAction` — cùng lý do {@see self::deleteAny()}. */
    public function restoreAny(User|ClientUser $user): bool
    {
        return $this->create($user);
    }

    /** Không ai xoá vĩnh viễn một loại vụ việc được: đó là cấu hình mà hồ sơ cũ có thể vẫn trỏ vào. */
    public function forceDelete(User|ClientUser $user, MatterType $matterType): bool
    {
        return false;
    }

    /** Cổng thô của `ForceDeleteBulkAction` — cùng lý do {@see self::deleteAny()}, luôn từ chối. */
    public function forceDeleteAny(User|ClientUser $user): bool
    {
        return false;
    }
}
