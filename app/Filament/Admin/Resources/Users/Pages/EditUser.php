<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Actions\User\DeleteStaffMember;
use App\Enums\UserPosition;
use App\Filament\Admin\Concerns\ReportsActionFailures;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    use GuardsStaffOffboarding;
    use ReportsActionFailures;

    protected static string $resource = UserResource::class;

    /**
     * Chỉ `DeleteAction`. Khuôn mẫu `make:filament-resource` sinh thêm `ForceDeleteAction` và
     * `RestoreAction`, nhưng `UserPolicy::restore()`/`forceDelete()` luôn từ chối (cùng luật
     * `ClientPolicy`) — nên hai nút đó vẫn không hiện, giờ vì policy TỪ CHỐI thật (`false`), không
     * còn vì THIẾU phương thức tương ứng như trước Task 4. `HeaderActionsAreReachableTest` giữ
     * luật này cho mọi trang.
     *
     * `authorizationNotification()` — cùng thành ngữ `EditClient`: `UserPolicy::delete()` (R7) có
     * thể từ chối kèm một thông điệp (còn việc dở dang). Mặc định Filament ẨN HẲN nút khi không
     * có `authorizationNotification()`/`authorizationTooltip()`, nên admin sẽ không hiểu vì sao
     * nút biến mất; công tắc này giữ nút hiển thị VÀ đổi một lần bấm bị từ chối (không đua) thành
     * một thông báo nêu đúng lý do — vẫn chỉ là cổng HIỂN THỊ, `->using()` dưới đây mới là cổng
     * THẬT.
     *
     * **`->using()` (I2, fix round 1): thay `$record->delete()` mặc định bằng
     * {@see DeleteStaffMember}, chạy dưới khoá dòng VÀ `Cache::lock()`.** Trước bản sửa này,
     * Filament tự hỏi `UserPolicy::delete()` (không khoá gì) để quyết định nút có bấm được không,
     * rồi gọi `$record->delete()` NGAY SAU — hai câu lệnh RỜI. Giữa hai câu đó, một request khác
     * có thể đổi đúng thứ vừa được đọc. Xem docblock lớp của Action đó cho lý lẽ đầy đủ.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorizationNotification()
                ->using(function (DeleteAction $action): bool {
                    $this->runAction($action, fn () => app(DeleteStaffMember::class)->handle(Auth::user(), $this->getRecord()));

                    return true;
                }),
        ];
    }

    /** Chức danh KHÔNG đứng tên phụ trách được một vụ việc — cùng tập vai `ReassignMatter` từ chối cho lead mới (I3, fix round 1). */
    private const NON_LEADING_POSITIONS = [UserPosition::Assistant, UserPosition::Accountant];

    /**
     * R7 (M6.5 Task 4): chặn Ở ĐÂY, KHÔNG ở `UserPolicy::update()` — policy chỉ nhận `$model` HIỆN
     * TẠI, không nhận `$data` mới đang gửi lên, nên "sắp tắt `is_active`" hay "sắp đổi chức danh
     * khỏi Quản trị" chỉ đọc được ở đây, TRƯỚC KHI `parent::handleRecordUpdate()` thật sự ghi đè
     * bản ghi — cùng vị trí `EditClientUser::mutateFormDataBeforeSave()` chặn đổi `client_id`.
     *
     * **Vô hiệu hoá** (`is_active` true → false) hỏi
     * {@see GuardsStaffOffboarding::offboardingOpenWorkReason()} — TRÊN TOÀN HỆ THỐNG (`$matter =
     * null`), khác `RemoveTeamMember` (Task 3) chỉ hỏi trong một vụ việc.
     *
     * **Đổi chức danh sang một chức danh KHÔNG lãnh đạo được (Trợ lý/Kế toán) — ruling fix round 1
     * "guard demotion" — hỏi CÙNG câu đó.** R7 gốc chỉ liệt "vô hiệu hoá và xoá", nhưng đổi chức
     * danh sang Trợ lý/Kế toán trong khi còn dẫn một vụ đang mở để lại đúng hệ quả mà R7 muốn chặn
     * (SPEC §7.4 không cho hai chức danh đó đứng tên `lead_lawyer_id`, và
     * `ReassignMatter`/`ViewMatter::reassignCandidateOptions()` cũng từ chối họ làm lead mới — I3,
     * cùng vòng sửa) — vụ việc mất người phụ trách hợp lệ y hệt một lần vô hiệu hoá. Đổi SANG
     * Trưởng phòng hay Quản trị (VẪN lãnh đạo được) không hỏi câu này — chỉ hai đích cụ thể mới
     * chặn, không phải "mọi lần đổi chức danh của người đang dẫn vụ".
     *
     * **Quản trị viên đang hoạt động cuối cùng**: cả hai đường (tắt `is_active`, đổi `position`
     * khỏi Admin) đều hỏi {@see GuardsStaffOffboarding::wouldLeaveNoActiveAdmin()} — dùng chung
     * với `UserPolicy::delete()`, để hai nơi không định nghĩa "admin cuối cùng" theo hai cách.
     *
     * **`Cache::lock('staff-admin-headcount')` (I2, fix round 1) — cùng khoá tên
     * {@see DeleteStaffMember} giữ, đóng cuộc đua HAI HÀNG KHÁC NHAU** (hai admin cuối cùng tự hạ
     * chức danh gần như đồng thời, ở hai request khác nhau) mà một khoá dòng đơn lẻ không đóng
     * được — xem lý lẽ đầy đủ ở docblock lớp `DeleteStaffMember`.
     *
     * **`lockForUpdate()` trên chính dòng `$record` — đóng cuộc đua CÙNG MỘT HÀNG** (hai request
     * cùng sửa một nhân sự). Đọc lại `is_active`/`position` từ `$locked` (không phải `$record` do
     * Filament truyền vào, có thể cũ hơn CSDL) cho MỌI điều kiện dưới đây. Việc GHI thật vẫn qua
     * `$record` (giữ nguyên định danh đối tượng `$this->record` để `afterSave()` đọc đúng), không
     * phải `$locked` — hai biến trỏ tới CÙNG một hàng CSDL, khoá đã giữ nó ổn định tới hết
     * transaction.
     *
     * Ném `ValidationException` gắn đúng state path của form (`errorKey()`, cùng công thức
     * `CreateMatter::errorKey()`/`PartiesRelationManager::errorKey()`): `save()` của
     * `EditRecord` bắt `Throwable` và rollback transaction đang mở, nên một lần chặn ở đây không để
     * lại nửa bản ghi đã lưu.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        return Cache::lock('staff-admin-headcount', 10)->block(5, function () use ($record, $data): Model {
            return DB::transaction(function () use ($record, $data): Model {
                $locked = User::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

                $newIsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $locked->is_active;
                $newPosition = isset($data['position']) ? UserPosition::from($data['position']) : $locked->position;

                if ($locked->is_active && ! $newIsActive) {
                    $reason = $this->offboardingOpenWorkReason($locked);

                    if ($reason !== null) {
                        throw ValidationException::withMessages([$this->errorKey('is_active') => [$reason]]);
                    }
                }

                if ($newPosition !== $locked->position && in_array($newPosition, self::NON_LEADING_POSITIONS, true)) {
                    $reason = $this->offboardingOpenWorkReason($locked);

                    if ($reason !== null) {
                        throw ValidationException::withMessages([$this->errorKey('position') => [$reason]]);
                    }
                }

                $remainsActiveAdmin = $newIsActive && $newPosition === UserPosition::Admin;

                if ($this->wouldLeaveNoActiveAdmin($locked, $remainsActiveAdmin)) {
                    $field = $newIsActive ? 'position' : 'is_active';

                    throw ValidationException::withMessages([$this->errorKey($field) => [$this->lastActiveAdminReason()]]);
                }

                return parent::handleRecordUpdate($record, $data);
            });
        });
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
