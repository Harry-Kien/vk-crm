<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\UserPosition;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    use GuardsStaffOffboarding;

    protected static string $resource = UserResource::class;

    /**
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng `UserPolicy::restore()`/`forceDelete()` luôn từ chối (cùng luật
     * `ClientPolicy`) — nên hai nút đó vẫn không hiện, giờ vì policy TỪ CHỐI thật (`false`), không
     * còn vì THIẾU phương thức tương ứng như trước Task 4. `HeaderActionsAreReachableTest` giữ
     * luật này cho mọi trang.
     *
     * `authorizationNotification()` — cùng thành ngữ `EditClient`: `UserPolicy::delete()` (R7) có
     * thể từ chối kèm một thông điệp (còn việc dở dang, hoặc là admin cuối cùng). Mặc định
     * Filament ẨN HẲN nút khi không có `authorizationNotification()`/`authorizationTooltip()`, nên
     * admin sẽ không hiểu vì sao nút biến mất; công tắc này giữ nút hiển thị và đổi một lần bấm bị
     * từ chối thành một thông báo nêu đúng lý do, thay vì xoá trót lọt hoặc một nút chết im lặng.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorizationNotification(),
        ];
    }

    /**
     * R7 (M6.5 Task 4): chặn Ở ĐÂY, KHÔNG ở `UserPolicy::update()` — policy chỉ nhận `$model` HIỆN
     * TẠI, không nhận `$data` mới đang gửi lên, nên "sắp tắt `is_active`" hay "sắp đổi chức danh
     * khỏi Quản trị" chỉ đọc được ở đây, TRƯỚC KHI `parent::handleRecordUpdate()` thật sự ghi đè
     * bản ghi — cùng vị trí `EditClientUser::mutateFormDataBeforeSave()` chặn đổi `client_id`.
     *
     * **Vô hiệu hoá** (`is_active` true → false) hỏi
     * {@see GuardsStaffOffboarding::offboardingOpenWorkReason()} — TRÊN TOÀN HỆ THỐNG (`$matter =
     * null`), khác `RemoveTeamMember` (Task 3) chỉ hỏi trong một vụ việc. **Đổi chức danh** KHÔNG
     * hỏi câu này — xem docblock của trait.
     *
     * **Quản trị viên đang hoạt động cuối cùng**: cả hai đường (tắt `is_active`, đổi `position`
     * khỏi Admin) đều hỏi {@see GuardsStaffOffboarding::wouldLeaveNoActiveAdmin()} — dùng chung
     * với `UserPolicy::delete()`, để hai nơi không định nghĩa "admin cuối cùng" theo hai cách.
     *
     * Ném `ValidationException` gắn đúng state path của form (`errorKey()`, cùng công thức
     * `CreateMatter::errorKey()`/`PartiesRelationManager::errorKey()`): `save()` của
     * `EditRecord` bắt `Throwable` và rollback transaction đang mở, nên một lần chặn ở đây không để
     * lại nửa bản ghi đã lưu.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $newIsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $record->is_active;
        $newPosition = isset($data['position']) ? UserPosition::from($data['position']) : $record->position;

        if ($record->is_active && ! $newIsActive) {
            $reason = $this->offboardingOpenWorkReason($record);

            if ($reason !== null) {
                throw ValidationException::withMessages([$this->errorKey('is_active') => [$reason]]);
            }
        }

        $remainsActiveAdmin = $newIsActive && $newPosition === UserPosition::Admin;

        if ($this->wouldLeaveNoActiveAdmin($record, $remainsActiveAdmin)) {
            $field = $newIsActive ? 'position' : 'is_active';

            throw ValidationException::withMessages([$this->errorKey($field) => [$this->lastActiveAdminReason()]]);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    /** Đổi chức danh phải đồng bộ lại vai trò ngay (xem ghi chú ở User::assignRoleFromPosition()). */
    protected function afterSave(): void
    {
        $this->record->assignRoleFromPosition();
    }

    /**
     * `{schema}.{field}` — cùng công thức `CreateMatter::errorKey()`/`PartiesRelationManager::
     * errorKey()`: một khoá TRẦN không khớp state path THẬT của form (`data.is_active`, không phải
     * `is_active`) thì Filament coi nó không thuộc field nào và không hiện lỗi ở đâu cả.
     */
    private function errorKey(string $field): string
    {
        $statePath = $this->getSchema('form')?->getStatePath();

        return filled($statePath) ? "{$statePath}.{$field}" : $field;
    }
}
