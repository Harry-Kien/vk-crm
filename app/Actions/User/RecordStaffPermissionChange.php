<?php

namespace App\Actions\User;

use App\Enums\UserPosition;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Support\Audit;
use Spatie\Activitylog\Models\Activity;

/**
 * Ghi sự kiện `permission_changed` (SPEC §10.6, "đổi phân quyền", M8 Task 3) khi chức danh hoặc
 * vai trò của một nhân sự đổi — kèm giá trị CŨ và MỚI, để người đọc nhật ký không phải giải mã
 * một diff của `LogsActivity`.
 *
 * Trước task này sự kiện chỉ tồn tại GIÁN TIẾP: `LogsActivity` của `User` ghi dòng `updated` khi
 * `position` đổi, còn việc đồng bộ vai trò Spatie ({@see User::assignRoleFromPosition()}) không
 * để dấu vết nào — nhãn `permission_changed` được khai báo ở `lang/vi/activity.php` nhưng không
 * nơi nào ghi. Dòng `updated` vẫn còn đó (chấp nhận được: hai dòng cạnh nhau, một dòng đọc được).
 *
 * # Đối tượng và người thực hiện
 *
 * Chủ thể là nhân sự bị đổi; người thực hiện là quản trị viên bấm nút ($actor). Chỉ ghi khi có gì
 * đổi thật: một lần lưu form sửa nhân sự chỉ đổi tên hay điện thoại KHÔNG sinh dòng này.
 *
 * Properties chỉ mang giá trị enum/tên vai trò (không dữ liệu vụ việc), nên không có gì mật để
 * lọc theo `ActivityOwningMatter` — dòng không thuộc vụ nào.
 *
 * Được gọi từ {@see EditUser::handleRecordUpdate()},
 * TRONG cùng transaction/khoá với chính lần đổi, để dòng nhật ký và thay đổi cùng sống hoặc cùng
 * chết.
 */
final class RecordStaffPermissionChange
{
    /**
     * @param  list<string>  $rolesBefore  Tên vai trò Spatie trước khi đổi.
     * @param  list<string>  $rolesAfter  Tên vai trò Spatie sau khi đổi.
     */
    public function handle(
        User $target,
        UserPosition $positionBefore,
        UserPosition $positionAfter,
        array $rolesBefore,
        array $rolesAfter,
        ?User $actor,
    ): ?Activity {
        $sortedBefore = collect($rolesBefore)->sort()->values()->all();
        $sortedAfter = collect($rolesAfter)->sort()->values()->all();

        if ($positionBefore === $positionAfter && $sortedBefore === $sortedAfter) {
            return null;
        }

        return Audit::record('permission_changed', $target, [
            'position_from' => $positionBefore->value,
            'position_to' => $positionAfter->value,
            'roles_from' => $sortedBefore,
            'roles_to' => $sortedAfter,
        ], $actor);
    }
}
