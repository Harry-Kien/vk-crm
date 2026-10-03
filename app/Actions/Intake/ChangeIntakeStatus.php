<?php

namespace App\Actions\Intake;

use App\Enums\IntakeStatus;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Đổi trạng thái một lần tiếp nhận theo các BƯỚC NGƯỜI TA TỰ ĐẶT (M10 Task 3): đã liên hệ lại, đang
 * tư vấn, đã báo phí, khách không theo tiếp. Bốn trạng thái còn lại không đi qua đây, có chủ đích:
 *  - `new` là điểm xuất phát; không quay về được (R5 đo lần ĐẦU rời `new`).
 *  - `won` chỉ do chuyển thành vụ việc (R3, `ConvertIntakeToMatter`, Task 4).
 *  - `merged` chỉ do gộp ({@see MergeIntake}, R4).
 *  - `declined` chỉ do từ chối có lý do ({@see DeclineIntake}, R8).
 * Chỉ đổi được từ một trạng thái còn mở (`new`, `contacted`, `consulting`, `quoted`); `lost`,
 * `declined`, `won`, `merged` là trạng thái cuối của thao tác này. Đổi sang chính trạng thái hiện tại
 * bị từ chối (không có gì để ghi).
 *
 * `first_response_at` (lần đầu rời `new`, R5) là việc của Task 5. `retention_until` khi vào `lost` (R7b)
 * do `IntakeRequest::stampRetention()` đặt lúc lưu (Task 7) — một chỗ cho mọi đường vào trạng thái cuối.
 *
 * Quyền: `IntakeRequestPolicy::update` (người ghi, người được giao, hoặc `intake.viewAny`). Bản ghi đã
 * xong việc ({@see IntakeRequest::isClosedToChanges()}: ẩn danh, gộp, chuyển đổi) bị từ chối. Câu đầu tiên của transaction là lần đọc có khoá dòng bản ghi; trạng thái được
 * đọc từ dòng vừa khoá, không từ bản trong bộ nhớ. Nhật ký `intake_status_changed` mang `from`/`to`,
 * causer = actor; bản ghi tự động của model tắt cho lần lưu này (nó lấy causer theo phiên).
 */
class ChangeIntakeStatus
{
    /** @var list<IntakeStatus> */
    public const TARGETS = [IntakeStatus::Contacted, IntakeStatus::Consulting, IntakeStatus::Quoted, IntakeStatus::Lost];

    /** @var list<IntakeStatus> */
    public const OPEN = [IntakeStatus::New, IntakeStatus::Contacted, IntakeStatus::Consulting, IntakeStatus::Quoted];

    public function handle(User $actor, IntakeRequest $intake, IntakeStatus $to): IntakeRequest
    {
        Gate::forUser($actor)->authorize('update', $intake);

        return DB::transaction(function () use ($actor, $intake, $to): IntakeRequest {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($locked->isClosedToChanges()
                || ! in_array($from, self::OPEN, true)
                || ! in_array($to, self::TARGETS, true)
                || $from === $to) {
                throw ValidationException::withMessages(['status' => [__('intake.errors.status_not_allowed', [
                    'from' => $from->label(),
                    'to' => $to->label(),
                ])]]);
            }

            $locked->status = $to;
            $locked->blameOn($actor);
            $locked->disableLogging()->save();
            $locked->enableLogging();

            Audit::record('intake_status_changed', $locked, ['from' => $from->value, 'to' => $to->value], $actor);

            return $locked;
        });
    }
}
