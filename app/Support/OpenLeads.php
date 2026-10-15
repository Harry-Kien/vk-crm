<?php

namespace App\Support;

use App\Actions\Intake\ChangeIntakeStatus;
use App\Actions\Intake\HandIntakeToLawyer;
use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\IntakeStatus;
use App\Models\IntakeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lead tiếp nhận còn mở mà một nhân sự còn giữ (làn fi, A1) — loại việc dở thứ tư và thứ năm của luật
 * chặn nghỉ việc/đổi vai ({@see GuardsStaffOffboarding}), bên cạnh ba loại của {@see OpenWork}. Tách
 * riêng khỏi `OpenWork` vì `OpenWork` còn được hỏi trong phạm vi MỘT vụ việc (gỡ thành viên khỏi đội ngũ),
 * nơi lead tiếp nhận không có nghĩa gì.
 *
 *  - `consulting` — lead được "Chuyển cho luật sư" tới người này (`consulting_lawyer_id`,
 *    {@see HandIntakeToLawyer}). Chỉ người mở được vụ việc giữ được vai này.
 *  - `assigned` — lead người này là "Người phụ trách" (`assigned_to`). Ai có `intake.create` giữ được.
 *
 * "Còn mở" = {@see self::scopeOpen()}: còn ở một bước đang làm việc (`ChangeIntakeStatus::OPEN`), chưa
 * chuyển thành vụ, chưa ẩn danh — đúng phần bù của {@see HandIntakeToLawyer::isClosed()} (bản trong bộ nhớ).
 * Lead đã từ chối, khách không theo, đã gộp hay đã thành vụ không còn ai phải làm gì nên không chặn ai.
 */
final readonly class OpenLeads
{
    /**
     * @param  Collection<int, IntakeRequest>  $consulting
     * @param  Collection<int, IntakeRequest>  $assigned
     */
    public function __construct(
        public Collection $consulting,
        public Collection $assigned,
    ) {}

    public static function forUser(User $user): self
    {
        return new self(
            consulting: self::scopeOpen(IntakeRequest::query())->where('consulting_lawyer_id', $user->getKey())->get(),
            assigned: self::scopeOpen(IntakeRequest::query())->where('assigned_to', $user->getKey())->get(),
        );
    }

    /**
     * @param  Builder<IntakeRequest>  $query
     * @return Builder<IntakeRequest>
     */
    private static function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('matter_id')
            ->whereNull('anonymised_at')
            ->whereIn('status', array_map(fn (IntakeStatus $status): string => $status->value, ChangeIntakeStatus::OPEN));
    }
}
