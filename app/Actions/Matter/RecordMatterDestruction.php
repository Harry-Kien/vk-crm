<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Schedule\FlagRetentionExpiry;
use App\Exceptions\MatterDestructionNotAllowed;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * M7 Task 6 (R5, SPEC §4.19) — GHI quyết định tiêu huỷ một hồ sơ đã quá hạn lưu trữ.
 *
 * **Action này không xoá gì.** Việc huỷ vật lý hồ sơ giấy và tệp là thao tác có biên bản, làm
 * NGOÀI hệ thống, do người quyết định. Ở đây chỉ ghi lại rằng quyết định đó đã có: bốn cột của
 * `matter_archives` (`destroyed_at`, `destroyed_by`, `destruction_reason`, `destruction_record_no`)
 * cộng một dòng audit `matter_destruction_recorded`. Không `delete()`, không `forceDelete()`, không
 * xoá media — vụ việc, tài liệu, tệp trên đĩa và chính bản ghi lưu trữ còn nguyên. Sau khi ghi,
 * {@see FlagRetentionExpiry} bỏ qua vụ này (nó chỉ chọn `destroyed_at` rỗng), nên cảnh báo không
 * lặp mãi.
 *
 * **Chỉ admin** — `MatterPolicy::recordDestruction`, hỏi TƯỜNG MINH ở đây, không tin màn hình đã
 * ẩn nút. Người thực hiện được ĐỌC LẠI từ CSDL trước khi hỏi Gate: một admin vừa bị gỡ vai, vô hiệu
 * hoá hay xoá trong lúc hộp thoại còn mở không ghi được bằng đối tượng cũ trong tay.
 *
 * **Thứ tự** (mọi bước trong MỘT transaction):
 *  1. Câu đầu tiên: khoá dòng `matters` (thứ tự khoá toàn cục — `matters` trước), gỡ
 *     `ClientPortalScope`, kể cả dòng đã xoá mềm.
 *  2. Quyền. Vụ không tồn tại cũng là `AuthorizationException` — cùng một câu, để lời từ chối
 *     không phân biệt "không có" với "không được" (SPEC §10.10).
 *  3. Vụ đã xoá mềm → từ chối (khôi phục trước; trang vụ việc không mở được vụ đã xoá).
 *  4. Khoá dòng `matter_archives` (chưa xoá mềm). Không có → từ chối.
 *  5. Vụ đang mở (`closed_at` rỗng — admin đã mở lại) → từ chối: hồ sơ đang xử lý không phải hồ sơ
 *     chờ tiêu huỷ, dù `retention_until` của lần đóng trước còn trên bản ghi.
 *  6. Chưa quá `retention_until` (còn trong hạn HẾT ngày đó, `MatterArchive::isRetentionExpired()`)
 *     hoặc không có ngày → từ chối.
 *  7. Đã ghi rồi (`destroyed_at` có giá trị) → từ chối; quyết định đầu giữ nguyên.
 *  8. Lý do: bắt buộc, tối thiểu {@see self::REASON_MIN} ký tự (`mb_strlen`, sau `trim`) như các lý
 *     do khác của dự án, tối đa {@see self::REASON_MAX} (cột `text`). Số biên bản: bắt buộc, tối đa
 *     {@see self::RECORD_NO_MAX} ký tự — đúng độ dài cột `varchar(50)`. Sai thì `ValidationException`
 *     gắn đúng tên ô của form.
 *  9. Ghi bốn cột + audit. Thuộc tính audit chỉ có mã định danh, số biên bản và ngày hết hạn lưu
 *     trữ — không lý do (đã nằm trên bản ghi lưu trữ, có thể dài), không mã/tiêu đề vụ.
 *
 * Không thư, không thông báo: người ghi chính là người đã quyết định.
 */
class RecordMatterDestruction
{
    use ReadsWithoutPortalScope;

    public const REASON_MIN = 20;

    public const REASON_MAX = 5000;

    /** Độ dài cột `matter_archives.destruction_record_no` (migration 2026_09_28_070001). */
    public const RECORD_NO_MAX = 50;

    public function handle(int $matterId, User $actor, string $reason, string $recordNo): MatterArchive
    {
        return DB::transaction(function () use ($matterId, $actor, $reason, $recordNo): MatterArchive {
            // 1. Câu ĐẦU TIÊN: khoá `matters`.
            $matter = $this->scopelessly(Matter::query())
                ->withTrashed()
                ->whereKey($matterId)
                ->lockForUpdate()
                ->first();

            // 2. Quyền, trên người thực hiện đọc lại từ CSDL.
            $freshActor = User::query()->find($actor->getKey());

            // `$matter === null` tường minh dù `Gate` cũng từ chối một ability không có đối tượng
            // để tìm policy — không dựa vào hành vi đó của framework cho một nhánh bảo mật.
            if ($matter === null || $freshActor === null || ! $freshActor->is_active) {
                throw new AuthorizationException;
            }

            Gate::forUser($freshActor)->authorize('recordDestruction', $matter);

            // 3.
            if ($matter->trashed()) {
                throw MatterDestructionNotAllowed::matterDeleted();
            }

            // 4. Khoá `matter_archives` SAU `matters`.
            $archive = $this->scopelessly(MatterArchive::query())
                ->where('matter_id', $matter->getKey())
                ->lockForUpdate()
                ->first();

            if ($archive === null) {
                throw MatterDestructionNotAllowed::noArchive();
            }

            // 5.
            if ($matter->closed_at === null) {
                throw MatterDestructionNotAllowed::notClosed();
            }

            // 6.
            if (! $archive->isRetentionExpired()) {
                throw MatterDestructionNotAllowed::retentionNotExpired($archive->retention_until);
            }

            // 7.
            if ($archive->destroyed_at !== null) {
                throw MatterDestructionNotAllowed::alreadyRecorded();
            }

            // 8.
            [$reason, $recordNo] = $this->validated($reason, $recordNo);

            // 9.
            $archive->update([
                'destroyed_at' => now(),
                'destroyed_by' => $freshActor->getKey(),
                'destruction_reason' => $reason,
                'destruction_record_no' => $recordNo,
            ]);

            Audit::record('matter_destruction_recorded', $matter, [
                'matter_id' => $matter->getKey(),
                'matter_archive_id' => $archive->getKey(),
                'destruction_record_no' => $recordNo,
                'retention_until' => $archive->retention_until->toDateString(),
            ], $freshActor);

            return $archive;
        });
    }

    /**
     * @return array{0: string, 1: string} lý do và số biên bản đã `trim`
     */
    private function validated(string $reason, string $recordNo): array
    {
        $reason = trim($reason);
        $recordNo = trim($recordNo);
        $errors = [];

        if (mb_strlen($reason) < self::REASON_MIN) {
            $errors['destruction_reason'] = [__('archive.destruction.validation.reason_min', ['min' => self::REASON_MIN])];
        } elseif (mb_strlen($reason) > self::REASON_MAX) {
            $errors['destruction_reason'] = [__('archive.destruction.validation.reason_max', ['max' => self::REASON_MAX])];
        }

        if ($recordNo === '') {
            $errors['destruction_record_no'] = [__('archive.destruction.validation.record_no_required')];
        } elseif (mb_strlen($recordNo) > self::RECORD_NO_MAX) {
            $errors['destruction_record_no'] = [__('archive.destruction.validation.record_no_max', ['max' => self::RECORD_NO_MAX])];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$reason, $recordNo];
    }
}
