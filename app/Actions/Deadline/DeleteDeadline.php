<?php

namespace App\Actions\Deadline;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Deadline\Concerns\OpensDeadline;
use App\Actions\RemoveMatterParty;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Gỡ một mốc thời hạn (M6.5 Task 14, `deadlines/F7`; R14) — xoá mềm kèm lý do bắt buộc, cùng hình
 * dạng {@see RemoveMatterParty} dựng cho một bên vụ việc.
 *
 * # Vì sao "gỡ" chứ không phải một cách lách khác
 *
 * Trước Action này, `DeadlinesRelationManager` không có nút xoá — cách lách duy nhất để dẹp một
 * mốc ghi nhầm (hồ sơ gõ hai lần, mốc dựng thử) là đánh dấu "hoàn thành" sai sự thật, đúng loại
 * bằng chứng mà một hồ sơ trách nhiệm nghề nghiệp sẽ đọc (`deadlines/F7`). Xoá mềm kèm lý do
 * (R14) để lại đúng dấu vết ngược lại: một dòng audit nói rõ VÌ SAO mốc này không còn, không phải
 * một cột `is_completed = true` nói dối.
 *
 * # Một mốc đã gỡ biến mất khỏi MỌI nơi, không cần lọc thêm ở nơi đọc
 *
 * `Deadline` dùng `SoftDeletes` từ M1. `CheckDeadlines::handle()` dựng danh sách ứng viên bằng
 * `Deadline::query()` (mang sẵn `SoftDeletingScope`), và `SendDeadlineReminderMail::handle()` đọc
 * lại mốc bằng `Deadline::query()->find()` — CẢ HAI đã tự loại một dòng đã xoá mềm TỪ TRƯỚC Action
 * này tồn tại, không cần sửa gì ở đó (xem test `SendDeadlineReminderMailTest` "skips a deadline
 * that was soft-deleted..." — mutation probe đổi `Deadline::query()` thành
 * `Deadline::withTrashed()` để CHỨNG MINH đây là chỗ đang chặn, không phải một dòng vô hại).
 * `UpcomingDeadlinesWidget` (widget mới của task này) cũng dùng `Deadline::query()` trần, nên
 * cùng một cơ chế giữ mốc đã gỡ khỏi widget mà không cần một điều kiện riêng.
 *
 * # Khoá vụ việc TRƯỚC, mốc thời hạn SAU
 *
 * Cùng thứ tự khoá nhà mà {@see ChangeDeadlineResponsible} đã dùng (khác `OpensDeadline`, nơi
 * khoá MỐC trước): câu ĐẦU TIÊN của transaction là khoá dòng `matters`, rồi mới khoá dòng
 * `deadlines` đang gỡ — để hai Action tranh chấp trên cùng một vụ việc không bao giờ khoá theo
 * hai chiều khác nhau.
 *
 * # Audit ghi TRONG transaction, TRƯỚC lệnh xoá mềm (cùng thứ tự `RemoveMatterParty`)
 *
 * R14: không bao giờ ghi số CCCD thô — không áp dụng trực tiếp ở đây (mốc thời hạn không mang
 * định danh cá nhân), nhưng cùng kỷ luật "ghi audit trước khi xoá, trong cùng transaction": một
 * lần xoá bị rollback (transaction thất bại vì lý do khác) không được để lại một dòng audit nói
 * về một lần xoá chưa từng xảy ra.
 */
class DeleteDeadline
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Deadline $deadline, User $actor, string $reason): Deadline
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($deadline, $actor, $reason): Deadline {
            // Câu ĐẦU TIÊN: khoá vụ việc, đọc lại từ CSDL.
            $matter = $this->scopelessly(Matter::query())->lockForUpdate()->find($deadline->matter_id);

            if ($matter === null || ! $this->accountIsActive($actor)) {
                $this->refuse();
            }

            $fresh = $this->scopelessly(Deadline::query())
                ->lockForUpdate()
                ->where('matter_id', $matter->getKey())
                ->find($deadline->getKey());

            if ($fresh === null) {
                $this->refuse();
            }

            $fresh->setRelation('matter', $matter);

            // `DeadlinePolicy::delete` uỷ thẳng cho `update` (cùng cổng bốn nút còn lại của tab).
            if (Gate::forUser($actor)->inspect('delete', $fresh)->denied()) {
                $this->refuse();
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => [__('deadlines.validation.delete_reason_required')],
                ]);
            }

            Audit::record('deadline_deleted', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'name' => $fresh->name,
                'due_date' => $fresh->due_date->toDateString(),
                'severity' => $fresh->severity->value,
                'reason' => $reason,
            ], causer: $actor);

            $fresh->delete();

            return $fresh;
        });
    }

    /** Cùng câu, cùng lớp exception với {@see OpensDeadline} (SPEC §10.10). */
    private function refuse(): never
    {
        throw new AuthorizationException(__('deadlines.unavailable'));
    }
}
