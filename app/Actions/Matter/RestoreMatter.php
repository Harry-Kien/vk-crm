<?php

namespace App\Actions\Matter;

use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Khôi phục một hồ sơ đã "Huỷ hồ sơ mở nhầm" ({@see CancelMatter}) — làn fm, mục A4 (kiểm tra nghiệp
 * vụ 2026-10-09). Trước Action này `MatterPolicy::restore` có nhưng không màn hình nào gọi: huỷ nhầm
 * một vụ thật thì chỉ còn cách sửa thẳng CSDL.
 *
 * Chỉ quản trị viên (`MatterPolicy::restore`, cùng luật xoá mềm), lý do bắt buộc, ghi
 * `matter_restored` kèm lý do BÊN TRONG transaction. Khoá dòng `matters` (kể cả đã xoá mềm) là câu
 * lệnh đầu tiên, rồi mới hỏi quyền trên bản đã khoá — cùng kỷ luật `CancelMatter`. Hồ sơ trở lại đúng
 * như lúc bị huỷ: các bảng con không bị `CancelMatter` đụng tới nên không có gì phải dựng lại; vụ
 * lại hiện trên danh sách, cổng khách (nếu đang bật công bố), nhắc hạn và widget.
 */
class RestoreMatter
{
    /** Trần chủ động cho lý do (nằm trong `properties` JSON của nhật ký). */
    public const REASON_MAX = 2000;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Matter $matter, User $actor, string $reason): Matter
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($matter, $actor, $reason): Matter {
            /** @var Matter $locked */
            $locked = Matter::withTrashed()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('restore', $locked);

            if (! $locked->trashed()) {
                throw ValidationException::withMessages([
                    'reason' => [__('lifecycle.restore.not_cancelled')],
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [__('lifecycle.restore.reason_required')],
                ]);
            }

            if (mb_strlen($reason) > self::REASON_MAX) {
                throw ValidationException::withMessages([
                    'reason' => [__('lifecycle.restore.reason_max', ['max' => self::REASON_MAX])],
                ]);
            }

            $locked->restore();

            Audit::record('matter_restored', $locked, [
                'reason' => $reason,
            ], $actor);

            return $locked;
        });
    }
}
