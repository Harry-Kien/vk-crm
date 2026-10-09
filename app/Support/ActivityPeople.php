<?php

namespace App\Support;

use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Người gây ra (và người là chủ thể của) một dòng nhật ký — kể cả người đã bị xoá MỀM (sửa sau kiểm
 * tra nghiệp vụ toàn hệ thống, làn fb, mục A6).
 *
 * `causer()`/`subject()` của spatie là `morphTo()` trần: `User` và `ClientUser` đều `SoftDeletes`,
 * nên một nhân sự đã nghỉ việc (xoá mềm qua `DeleteStaffMember`) nạp ra `null` và dòng của họ hiện
 * "Hệ thống" — đúng lúc văn phòng cần chứng minh ai đã làm gì. `config/activitylog.php` giữ
 * `subject_returns_soft_deleted_models = false` cho mọi loại chủ thể khác; ở đây chỉ nới cho HAI
 * model người, qua `MorphTo::constrain()` (khoá là tên lớp, không phải bí danh morph).
 *
 * Tên người đã xoá luôn kèm nhãn ({@see self::name()}), để không ai đọc nhầm thành một tài khoản còn
 * hoạt động.
 */
final class ActivityPeople
{
    /**
     * Ràng buộc nạp cho một quan hệ `morphTo` trỏ tới người: nạp cả người đã xoá mềm.
     *
     * @return \Closure(MorphTo): MorphTo
     */
    public static function withTrashed(): \Closure
    {
        return fn (MorphTo $morphTo): MorphTo => $morphTo->constrain([
            User::class => fn (Builder $query) => $query->withTrashed(),
            ClientUser::class => fn (Builder $query) => $query->withTrashed(),
        ]);
    }

    /**
     * Tên hiển thị của một người trong nhật ký: tên trần khi tài khoản còn, tên kèm "(đã nghỉ việc)"
     * với nhân sự đã xoá mềm, kèm "(tài khoản đã xoá)" với tài khoản cổng đã xoá mềm. `null` khi không
     * có người (dòng của hệ thống) hoặc model không phải người.
     */
    public static function name(?Model $person): ?string
    {
        $name = $person?->getAttribute('name');

        if (! is_string($name) || $name === '') {
            return null;
        }

        if ($person instanceof User && $person->trashed()) {
            return __('staff_access.departed_name', ['name' => $name]);
        }

        if ($person instanceof ClientUser && $person->trashed()) {
            return __('staff_access.deleted_client_user_name', ['name' => $name]);
        }

        return $name;
    }

    /**
     * Danh sách chọn "Nhân sự" cho bộ lọc nhật ký: mọi nhân sự, KỂ CẢ người đã xoá mềm (nhãn
     * {@see self::name()}), theo tên.
     *
     * @return array<int, string>
     */
    public static function staffOptions(): array
    {
        return User::query()->withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at'])
            ->mapWithKeys(fn (User $user): array => [(int) $user->getKey() => (string) self::name($user)])
            ->all();
    }
}
