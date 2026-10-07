<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\MatterTypeStage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Cùng luật với MatterTypePolicy: ai cũng đọc được (portal cần hiển thị giai đoạn), chỉ
 * settings.manage được ghi. Không có policy riêng thì Filament (không ở chế độ nghiêm ngặt)
 * mặc định CHO PHÉP mọi hành động trên StagesRelationManager — khai báo rõ ở đây để việc thêm/
 * sửa/xoá giai đoạn không chỉ dựa vào việc luật sư không mở được trang EditMatterType.
 */
class MatterTypeStagePolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return true;
    }

    public function view(User|ClientUser $user, MatterTypeStage $matterTypeStage): bool
    {
        return true;
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::SettingsManage->value);
    }

    public function update(User|ClientUser $user, MatterTypeStage $matterTypeStage): bool
    {
        return $this->create($user);
    }

    /**
     * Task 19 (rà soát cuối, "Cấu hình không phá dữ liệu đang chạy"): trước bản vá này, xoá mềm
     * một giai đoạn mà hồ sơ đang đứng ở đó làm hồ sơ ấy ĐÓNG BĂNG vĩnh viễn — cả "Chuyển giai
     * đoạn" lẫn "Thêm cập nhật" đều ném `InvalidStageTransition` vì `MatterType::stage($key)`
     * không còn tìm thấy cấu hình, kể cả với admin (quyền bỏ qua `allowed_next` của
     * `TransitionMatterStage` không giúp gì: giai đoạn ĐÍCH phải có cấu hình thật). Hai điều kiện
     * chặn, mirror {@see MatterTypePolicy::delete()}:
     *
     *  1. Còn hồ sơ (kể cả đã xoá mềm — vẫn khôi phục được) đang có `stage` bằng đúng `key` này.
     *  2. `key` này còn nằm trong `allowed_next` của một giai đoạn KHÁC cùng loại vụ việc: xoá nó
     *     đi thì màn hình "Chuyển giai đoạn" của giai đoạn kia mời một đích không còn cấu hình.
     *
     * M9 Task 6 thêm điều kiện thứ ba, cùng họ — đứng giữa hai điều kiện trên: còn đợt thanh toán
     * `pending` chưa kích hoạt của hợp đồng `draft`/`active` trên hồ sơ cùng loại chờ hồ sơ chạm `key`
     * này ({@see MatterTypeStage::instalmentsAwaitingStage()}, cùng hàm đếm với luật đổi `key`). Xoá
     * giai đoạn thì không lần chuyển giai đoạn nào còn tới được nó, và đợt đó không bao giờ đến hạn.
     * Câu từ chối nêu số đợt.
     *
     * Trả `Response::deny()` kèm lý do, không `bool`: `StagesRelationManager` bật
     * `authorizationNotification()` cho `DeleteAction` này, nên admin đọc được NGAY vì sao không
     * xoá được, thay vì một nút biến mất không lời giải thích (cùng kỹ thuật
     * `ClientPolicy::delete()`).
     */
    public function delete(User|ClientUser $user, MatterTypeStage $matterTypeStage): bool|Response
    {
        if (! $this->create($user)) {
            return false;
        }

        $matterCount = Matter::query()
            ->withTrashed()
            ->where('matter_type_id', $matterTypeStage->matter_type_id)
            ->where('stage', $matterTypeStage->key)
            ->count();

        if ($matterCount > 0) {
            return Response::deny(__('matter_types.stages.delete_blocked_in_use', ['count' => $matterCount]));
        }

        $awaitingInstalments = MatterTypeStage::instalmentsAwaitingStage((int) $matterTypeStage->matter_type_id, $matterTypeStage->key);

        if ($awaitingInstalments > 0) {
            return Response::deny(__('matter_types.stages.delete_blocked_instalments', ['count' => $awaitingInstalments]));
        }

        // Task 19, vòng sửa 1: dùng chung MatterTypeStage::stagesReferencing() với luật đổi
        // `key` (MatterTypeStage::keyInUse()) — cùng một câu hỏi ("còn giai đoạn nào khác trỏ
        // allowed_next vào key này không"), không lặp lại truy vấn ở hai nơi.
        $referencingLabels = MatterTypeStage::stagesReferencing(
            (int) $matterTypeStage->matter_type_id,
            $matterTypeStage->key,
            $matterTypeStage->getKey(),
        )->pluck('label');

        if ($referencingLabels->isNotEmpty()) {
            return Response::deny(__('matter_types.stages.delete_blocked_allowed_next', [
                'labels' => $referencingLabels->implode(', '),
            ]));
        }

        return true;
    }

    /**
     * Cổng thô của `DeleteBulkAction` trên `StagesRelationManager` (C1-class bulk-action hole,
     * xem docblock {@see MatterTypePolicy::deleteAny()} cho cơ chế đầy đủ). Luật
     * thật cho TỪNG bản ghi vẫn ở {@see self::delete()}; relation manager gọi
     * `->authorizeIndividualRecords('delete')` để mỗi dòng đã chọn đi qua đúng đó.
     */
    public function deleteAny(User|ClientUser $user): bool
    {
        return $this->create($user);
    }

    /** Không có luật riêng cho khôi phục (chiều ngược của {@see self::delete()}): còn settings.manage là khôi phục được. */
    public function restore(User|ClientUser $user, MatterTypeStage $matterTypeStage): bool
    {
        return $this->create($user);
    }

    /** Cổng thô của `RestoreBulkAction` — cùng lý do {@see self::deleteAny()}. */
    public function restoreAny(User|ClientUser $user): bool
    {
        return $this->create($user);
    }

    /** Không ai xoá vĩnh viễn một giai đoạn được: đó là cấu hình mà dòng tiến độ cũ vẫn có thể trỏ vào. */
    public function forceDelete(User|ClientUser $user, MatterTypeStage $matterTypeStage): bool
    {
        return false;
    }

    /** Cổng thô của `ForceDeleteBulkAction` — cùng lý do {@see self::deleteAny()}, luôn từ chối. */
    public function forceDeleteAny(User|ClientUser $user): bool
    {
        return false;
    }
}
