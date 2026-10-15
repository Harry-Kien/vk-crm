<?php

namespace App\Actions\Intake;

use App\Actions\Notification\ResolveStaffRecipients;
use App\Enums\Permission;
use App\Models\IntakeRequest;
use App\Models\User;
use App\Notifications\Staff\IntakeHandedToLawyer;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * "Chuyển cho luật sư" (làn kd, 2026-10-14): khi một lead cần tư vấn chuyên môn hay sắp mở vụ, người theo
 * lead — thường là nhân viên kinh doanh — chuyển nó cho MỘT luật sư tư vấn.
 *
 * **Trường riêng, không đổi người được giao.** `consulting_lawyer_id` (cùng `handed_to_lawyer_at`) mở bản
 * ghi cho luật sư đó qua `IntakeRequest::scopeVisibleTo()`; `assigned_to` giữ nguyên, nên nhân viên kinh
 * doanh vẫn thấy, vẫn chăm sóc và vẫn nhận lời nhắc của lead mình được giao. Chuyển lần nữa sang luật sư
 * khác thì luật sư trước mất vế "luật sư tư vấn" (vẫn thấy nếu chính họ ghi hay được giao bản ghi).
 * Việc mở vụ (`ConvertIntakeToMatter`) giữ nguyên quyền như trước: luật sư tư vấn thấy bản ghi nên qua
 * được `IntakeRequestPolicy::convert`.
 *
 * **Ai nhận được lead:** nhân sự đang làm việc (`is_active`, chưa xoá mềm) có cả `intake.convert` lẫn
 * `matter.create` — đúng những người mở được vụ từ lead ({@see self::canReceive()}): luật sư, trưởng
 * phòng, quản trị. Trợ lý, kế toán, nhân viên kinh doanh thì không.
 *
 * **Ai chuyển được:** người sửa được bản ghi (`IntakeRequestPolicy::update` — thấy được nó), trên bản ghi
 * còn mở: chưa xong việc (`isClosedToChanges()`: đã gộp, đã ẩn danh, đã thành vụ) và ở một bước người ta
 * còn làm việc (`ChangeIntakeStatus::OPEN` — không phải "Văn phòng từ chối"/"Khách không theo tiếp").
 * Chuyển cho chính luật sư đang giữ lead thì từ chối (không gì đổi, không thông báo lặp).
 *
 * Câu đầu tiên của transaction là lần đọc có khoá dòng bản ghi; mọi điều kiện về bản ghi — quyền sửa
 * (`update`, hỏi cả trước transaction trên model truyền vào và lại trên dòng đã khoá), "còn mở", luật sư
 * đang giữ — đọc lại trên dòng đó; người nhận đọc lại từ bảng `users`. Nhật ký: bản ghi tự động của
 * model tắt; dòng `intake_handed_to_lawyer` mang id luật sư mới và trước — không tên, SĐT hay nội dung. Luật sư nhận thông báo trong hệ thống {@see IntakeHandedToLawyer} (chỉ mã và
 * nguồn) SAU KHI transaction commit, người nhận qua `ResolveStaffRecipients::forIntakeHandoff()`.
 */
class HandIntakeToLawyer
{
    /** Người này nhận được một lead để tư vấn và mở vụ — xem docblock lớp. */
    public static function canReceive(User $user): bool
    {
        return $user->is_active
            && ! $user->trashed()
            && $user->can(Permission::IntakeConvert->value)
            && $user->can(Permission::MatterCreate->value);
    }

    /**
     * Ô chọn "Luật sư tư vấn": mọi người {@see self::canReceive()}, theo tên. Action hỏi lại đúng hàm đó
     * trên người được chọn, nên một payload dàn dựng chọn người ngoài danh sách vẫn bị chặn.
     *
     * @return array<int, string>
     */
    public static function candidateOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => self::canReceive($user))
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name])
            ->all();
    }

    /** Bản ghi đã xong việc hay đã ra khỏi các bước còn làm việc — không chuyển, không chăm sóc thêm. */
    public static function isClosed(IntakeRequest $intake): bool
    {
        return $intake->isClosedToChanges() || ! in_array($intake->status, ChangeIntakeStatus::OPEN, true);
    }

    public function handle(User $actor, IntakeRequest $intake, User $lawyer): IntakeRequest
    {
        Gate::forUser($actor)->authorize('update', $intake);

        $handed = DB::transaction(function () use ($actor, $intake, $lawyer): IntakeRequest {
            $locked = IntakeRequest::query()->whereKey($intake->getKey())->lockForUpdate()->firstOrFail();

            // Quyền sửa hỏi lại trên dòng đã khoá: lần hỏi trước transaction dùng model truyền vào, đọc trước
            // khoá — lead có thể vừa được giao cho người khác giữa hai lần đọc.
            Gate::forUser($actor)->authorize('update', $locked);

            if (self::isClosed($locked)) {
                throw ValidationException::withMessages(['consulting_lawyer_id' => [__('leads.handoff.errors.closed')]]);
            }

            $target = User::query()->find($lawyer->getKey());

            if (! $target instanceof User || ! self::canReceive($target)) {
                throw ValidationException::withMessages(['consulting_lawyer_id' => [__('leads.handoff.errors.not_a_lawyer')]]);
            }

            if ($locked->consulting_lawyer_id === $target->getKey()) {
                throw ValidationException::withMessages(['consulting_lawyer_id' => [__('leads.handoff.errors.same_lawyer')]]);
            }

            $previous = $locked->consulting_lawyer_id;

            $locked->forceFill([
                'consulting_lawyer_id' => $target->getKey(),
                'handed_to_lawyer_at' => now(),
            ])->blameOn($actor);
            $locked->disableLogging()->save();
            $locked->enableLogging();

            Audit::record('intake_handed_to_lawyer', $locked, [
                'lawyer_id' => $target->getKey(),
                'previous_lawyer_id' => $previous,
            ], $actor);

            return $locked;
        });

        Notification::send(
            app(ResolveStaffRecipients::class)->forIntakeHandoff($handed, $actor),
            new IntakeHandedToLawyer($handed),
        );

        return $handed;
    }
}
