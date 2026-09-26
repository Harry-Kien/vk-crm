<?php

namespace App\Actions\Matter;

use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Huỷ hồ sơ mở nhầm" (M6.5 Task 5) — admin, xoá mềm kèm lý do BẮT BUỘC. Đây là đường sửa cho
 * vụ gắn nhầm khách hàng hoặc nhầm loại vụ việc: hai cột đó (`client_id`, `matter_type_id`)
 * KHÔNG sửa được qua {@see UpdateMatterDetails} (xem docblock lớp đó), nên hồ sơ mở sai chỉ còn
 * một đường là huỷ và mở lại đúng — cùng lý lẽ SPEC dùng cho việc gỡ một bên nhập nhầm (R14: "gỡ
 * nghĩa là nhập nhầm, chưa từng là bên").
 *
 * # Khoá TRƯỚC, không đọc gì trước khi khoá
 *
 * Cùng kỷ luật với {@see UpdateMatterDetails}: câu lệnh ĐẦU TIÊN trong transaction là
 * `lockForUpdate()`, và `Gate::authorize()` chạy SAU đó trên bản ghi vừa khoá — không phải trên
 * `$matter` do caller truyền vào — để một lần đọc quyền không tự fix cứng ảnh chụp
 * (snapshot) REPEATABLE READ của MariaDB trước khi khoá kịp giữ dòng.
 *
 * # Audit GHI TRONG transaction, trước khi xoá mềm
 *
 * Dòng `matter_cancelled` ghi lý do huỷ TRƯỚC lệnh `delete()` — cùng một transaction, nên cả hai
 * cùng commit hoặc cùng rollback. Ghi trước hay sau lệnh xoá không đổi tính đúng đắn (cả hai đều
 * ở trong transaction), nhưng ghi trước để lý do luôn đọc được cùng bối cảnh của bản ghi CÒN
 * `exists = true`, không phải một bản ghi model đã có `deleted_at` gán sẵn trong bộ nhớ.
 *
 * # Cách ly cổng khách (SPEC §11) — không tầng nào được yếu đi vì tầng khác đã che
 *
 * Một vụ đã xoá mềm biến mất khỏi cổng khách hoàn toàn nhờ BA tầng ĐỘC LẬP, không tầng nào ở
 * đây cần sửa vì cả ba đã đứng vững từ M2–M5: tầng truy vấn
 * (`Matter::applyClientPortalConstraints()` gọi `whereNull('deleted_at')` tường minh, không chỉ
 * tin `SoftDeletingScope`), tầng policy (`MatterPolicy::releasedToPortal()` đọc lại
 * `$matter->trashed()`), và tầng serialize (`HidesInternalAttributesFromPortal` — không liên
 * quan trực tiếp tới xoá mềm nhưng cùng nguyên tắc "không tin một tầng một mình"). Hành động
 * DUY NHẤT của Action này là gọi `delete()` — không viết lại luật cách ly ở đây, chỉ dựa vào nó.
 */
class CancelMatter
{
    public function handle(Matter $matter, User $actor, string $reason): Matter
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($matter, $actor, $reason): Matter {
            /** @var Matter $locked */
            $locked = Matter::withTrashed()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('cancelMatter', $locked);

            if ($locked->trashed()) {
                throw ValidationException::withMessages([
                    'reason' => [__('actions.cancel_matter.already_cancelled')],
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [__('actions.cancel_matter.reason_required')],
                ]);
            }

            Audit::record('matter_cancelled', $locked, [
                'reason' => $reason,
            ], $actor);

            $locked->delete();

            return $locked;
        });
    }
}
