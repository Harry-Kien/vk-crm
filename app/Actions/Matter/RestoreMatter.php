<?php

namespace App\Actions\Matter;

use App\Actions\Concerns\ChecksAccountActive;
use App\Enums\Permission;
use App\Enums\Role;
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
 * Chỉ quản trị viên (`MatterPolicy::restore`, cùng luật xoá mềm) có tài khoản còn hiệu lực
 * ({@see ChecksAccountActive}, làn fi A3 — như `RetractStageLog`), lý do bắt buộc, ghi
 * `matter_restored` kèm lý do BÊN TRONG transaction. Khoá dòng `matters` (kể cả đã xoá mềm) là câu
 * lệnh đầu tiên, rồi mới hỏi quyền trên bản đã khoá — cùng kỷ luật `CancelMatter`. Hồ sơ trở lại đúng
 * như lúc bị huỷ: các bảng con không bị `CancelMatter` đụng tới nên không có gì phải dựng lại; vụ
 * lại hiện trên danh sách, cổng khách (nếu đang bật công bố), nhắc hạn và widget.
 *
 * **Người phụ trách không còn xử lý được vụ (làn fi, A3).** Trong lúc hồ sơ nằm ở "đã huỷ", luật sư phụ
 * trách có thể đã nghỉ việc (xoá), bị khoá truy cập, hay đổi sang một chức danh không phụ trách vụ được
 * (Nhân viên kinh doanh, Trợ lý, Kế toán) — luật chặn nghỉ việc không thấy hồ sơ đã huỷ, vì nó không còn là
 * vụ đang mở. Khôi phục nguyên trạng thì vụ mở lại dưới tên một người không làm gì được với nó. Phán quyết:
 * khi {@see self::leadCanStillHandle()} sai, khôi phục ĐÒI một người phụ trách mới (`$newLead`, ô chọn
 * trong hộp thoại "Khôi phục"), và cùng transaction chuyển vụ cho người đó qua {@see ReassignMatter} —
 * đúng luật bàn giao thường (chỉ luật sư/trưởng phòng đang làm việc; mốc hạn, yêu cầu khách của người cũ
 * đi theo). Không có cách "bàn giao trước rồi khôi phục": `ReassignMatter` không mở được hồ sơ đã huỷ.
 * Thiếu người mới → `ValidationException` trên `new_lead_id`, không gì đổi. Người mới bị `ReassignMatter`
 * từ chối → transaction hoàn lại cả lần khôi phục. Nhật ký `matter_restored` mang thêm `new_lead_id` và
 * `previous_lead_id` khi có lần chuyển này. Người phụ trách còn xử lý được thì `$newLead` bị bỏ qua.
 */
class RestoreMatter
{
    use ChecksAccountActive;

    /** Trần chủ động cho lý do (nằm trong `properties` JSON của nhật ký). */
    public const REASON_MAX = 2000;

    /**
     * Người phụ trách hiện tại của hồ sơ còn xử lý được nó: còn tài khoản (kể cả đọc qua xoá mềm), đang
     * hoạt động, chưa xoá, và có quyền chuyển giai đoạn vụ việc — cùng điều kiện ô "Luật sư phụ trách"
     * khi mở vụ (`CreateMatter::leadLawyerOptions()`).
     */
    public static function leadCanStillHandle(Matter $matter): bool
    {
        $lead = User::query()->withTrashed()->find($matter->lead_lawyer_id);

        return $lead instanceof User
            && $lead->is_active
            && ! $lead->trashed()
            && $lead->can(Permission::MatterTransitionStage->value);
    }

    /**
     * Ô chọn "Người phụ trách mới" của hộp thoại khôi phục: luật sư/trưởng phòng đang làm việc, trừ người
     * phụ trách hiện tại — cùng tập `ReassignMatter` chấp nhận (nó hỏi lại dưới khoá).
     *
     * @return array<int, string>
     */
    public static function newLeadOptions(Matter $matter): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereKeyNot($matter->lead_lawyer_id)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->hasRole(Role::Lawyer->value) || $user->hasRole(Role::Manager->value))
            ->mapWithKeys(fn (User $user): array => [$user->getKey() => $user->name])
            ->all();
    }

    /**
     * @param  User|null  $newLead  Bắt buộc khi {@see self::leadCanStillHandle()} sai — xem docblock lớp.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Matter $matter, User $actor, string $reason, ?User $newLead = null): Matter
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($matter, $actor, $reason, $newLead): Matter {
            /** @var Matter $locked */
            $locked = Matter::withTrashed()->whereKey($matter->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->accountIsActive($actor)) {
                throw new AuthorizationException;
            }

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

            $previousLeadId = $locked->lead_lawyer_id;
            $needsNewLead = ! self::leadCanStillHandle($locked);

            if ($needsNewLead && $newLead === null) {
                throw ValidationException::withMessages([
                    'new_lead_id' => [__('lifecycle.restore.lead_unavailable', [
                        'name' => User::query()->withTrashed()->find($previousLeadId)?->name ?? '—',
                    ])],
                ]);
            }

            $locked->restore();

            if ($needsNewLead) {
                app(ReassignMatter::class)->handle($locked, $actor, $newLead, $reason, keepOldLeadAsAssociate: false);
                $locked->refresh();
            }

            Audit::record('matter_restored', $locked, [
                'reason' => $reason,
                ...($needsNewLead ? ['new_lead_id' => $locked->lead_lawyer_id, 'previous_lead_id' => $previousLeadId] : []),
            ], $actor);

            return $locked;
        });
    }
}
