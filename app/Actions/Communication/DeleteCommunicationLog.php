<?php

namespace App\Actions\Communication;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Deadline\DeleteDeadline;
use App\Models\CommunicationLog;
use App\Models\Matter;
use App\Models\User;
use App\Policies\CommunicationLogPolicy;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Xoá một dòng nhật ký liên lạc (M7 Task 8) — xoá MỀM kèm lý do bắt buộc và một dòng audit, cùng
 * hình dạng {@see DeleteDeadline}.
 *
 * Nhật ký liên lạc là bằng chứng (SPEC §4.17: nó trả lời câu "văn phòng có thông báo cho tôi
 * không"). Bản trước của policy cho BẤT KỲ AI xem được vụ xoá một dòng, không lý do, không dấu
 * vết. Giờ: dòng ở lại trong CSDL (`withTrashed()` còn đọc được), lý do và người xoá nằm trong
 * nhật ký hệ thống, và không đường nào gọi `forceDelete()` (R5).
 *
 * Khoá dòng `matters` TRƯỚC, rồi dòng `communication_logs` (thứ tự khoá chung). Audit ghi TRONG
 * transaction, TRƯỚC lệnh xoá — một lần xoá bị rollback không để lại dòng audit về một lần xoá chưa
 * từng xảy ra. Cổng là {@see CommunicationLogPolicy::delete()}, hỏi trên dòng đọc lại dưới khoá
 * với vụ việc đã gắn sẵn; dòng đã xoá mềm bị từ chối bằng câu chung (một lần xoá, một dòng audit).
 */
class DeleteCommunicationLog
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /** Lý do nằm trong `activity_log.properties` (JSON); trần đặt cho một câu, không cho một bài. */
    public const REASON_MAX_LENGTH = 1000;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(CommunicationLog $log, User $actor, string $reason): CommunicationLog
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($log, $actor, $reason): CommunicationLog {
            // Câu ĐẦU TIÊN: khoá vụ việc. Vụ đã xoá mềm ra `null`.
            $matter = $this->scopelessly(Matter::query())->lockForUpdate()->find($log->matter_id);

            if ($matter === null || ! $this->accountIsActive($actor)) {
                $this->refuse();
            }

            // `CommunicationLog::query()` loại dòng đã xoá mềm — dòng như vậy ra `null`.
            $fresh = $this->scopelessly(CommunicationLog::query())
                ->lockForUpdate()
                ->where('matter_id', $matter->getKey())
                ->find($log->getKey());

            if ($fresh === null) {
                $this->refuse();
            }

            $fresh->setRelation('matter', $matter);

            if (Gate::forUser($actor)->inspect('delete', $fresh)->denied()) {
                $this->refuse();
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [__('communications.validation.delete_reason_required')],
                ]);
            }

            if (mb_strlen($reason) > self::REASON_MAX_LENGTH) {
                throw ValidationException::withMessages([
                    'reason' => [__('communications.validation.delete_reason_too_long', ['max' => self::REASON_MAX_LENGTH])],
                ]);
            }

            Audit::record('communication_log_deleted', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'type' => $fresh->type->value,
                'occurred_at' => $fresh->occurred_at->toDateTimeString(),
                'reason' => $reason,
            ], causer: $actor);

            $fresh->delete();

            return $fresh;
        });
    }

    /** Mọi lý do, MỘT câu (SPEC §10.10) — cùng câu với {@see LogCommunication}. */
    private function refuse(): never
    {
        throw new AuthorizationException(__('communications.unavailable'));
    }
}
