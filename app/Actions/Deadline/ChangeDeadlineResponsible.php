<?php

namespace App\Actions\Deadline;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Deadline\Concerns\ChecksDeadlineHolder;
use App\Actions\Deadline\Concerns\OpensDeadline;
use App\Actions\Matter\ReassignMatter;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Đổi người phụ trách" một mốc thời hạn (fix round 1 của M6.5 Task 4, CRITICAL) — đường ghi thứ
 * HAI vào `responsible_user_id`, sau khi mốc đã tạo.
 *
 * # Vì sao Action này phải tồn tại: một lỗ hổng nghỉ việc không có đường ra
 *
 * Trước bản sửa này, `responsible_user_id` không có đường ghi nào ngoài lúc TẠO mốc
 * ({@see AddMatterDeadline}) — bốn nút của `DeadlinesRelationManager` chỉ đổi `is_completed`
 * ({@see SetDeadlineCompletion}) và `is_published` ({@see SetDeadlinePublication}). Hệ quả: một
 * trợ lý, hoặc một luật sư cộng sự KHÔNG phải lead của bất kỳ vụ việc nào, vẫn có thể đứng tên
 * một mốc chưa xong — và {@see ReassignMatter} (Task 4) chỉ chuyển việc của
 * LEAD. `App\Actions\User\Concerns\GuardsStaffOffboarding` từ chối vô hiệu hoá/xoá người đó, chỉ
 * về nút "Bàn giao" — thứ không đổi được cột này. Đường ra DUY NHẤT trước bản sửa là đánh dấu giả
 * mốc đã hoàn thành, hoặc để tài khoản treo đó mãi. Action này đóng đúng lỗ hổng đó.
 *
 * # Người mới phải MỞ ĐƯỢC hồ sơ — `view`, không phải `update`
 *
 * Cố ý khác {@see AddMatterDeadline::canHoldTheDeadline()} (đòi `matter.update`, vì lúc TẠO một
 * mốc luôn có một người đang GHI vào hồ sơ): ở đây chỉ đòi `view` — người được CHUYỂN GIAO cho
 * một mốc không nhất thiết phải TỰ MÌNH ghi được vào hồ sơ ngay bây giờ, họ chỉ cần MỞ được nó để
 * biết mốc đó là gì và đọc lại tiến độ. Đây là phán quyết của vòng sửa 1 (fix round 1 findings,
 * CRITICAL); nó nới nhẹ so với `AddMatterDeadline` một cách có chủ đích, không phải một chỗ lệch.
 *
 * **M6.5 Task 14: câu hỏi này dời sang {@see ChecksDeadlineHolder::canHoldDeadline()}**, dùng
 * chung với `UpdateDeadline` (ô người phụ trách của nút "Sửa") và lần mở lại của
 * `SetDeadlineCompletion` — ba đường ghi cột này sau lúc tạo, MỘT luật. Luật đó thêm một vế mà bản
 * cũ không có: người mới phải CÒN TRONG ĐỘI NGŨ (hoặc là lead). Với luật sư/trợ lý vế này vốn đã
 * nằm trong `Gate::view()` của một vụ thường; nó chỉ đổi câu trả lời cho trưởng phòng/admin ngoài
 * đội ngũ — những người màn hình chưa bao giờ bày ra (`responsibleOptions()` chỉ liệt kê đội ngũ),
 * và R6 giữ đúng bất biến đó ("mốc chưa xong nằm trong tay đội ngũ").
 *
 * # Khoá vụ việc TRƯỚC, mốc thời hạn SAU — khác thứ tự của `OpensDeadline`
 *
 * {@see OpensDeadline::openDeadline()} (dùng bởi
 * `SetDeadlineCompletion`/`SetDeadlinePublication`) khoá MỐC trước rồi mới đọc vụ việc — đủ cho
 * hai Action đó vì chúng không đọc lại quyền của một người THỨ BA nào khác ngoài actor. Action
 * này thì có (`Gate::forUser($newResponsible)->allows('view', $matter)`), và ruling fix round 1
 * đòi đúng thứ tự "khoá vụ việc trước, mốc thời hạn sau" — cùng thứ tự toàn cục mà
 * `ReassignMatter`/`AddTeamMember`/`RemoveTeamMember`/`OpensDeadline::openMatterForDeadline()`
 * đã dùng (vụ việc trước, bảng con sau), để hai Action tranh chấp trên cùng một vụ việc không bao
 * giờ khoá theo hai chiều khác nhau (công thức deadlock kinh điển). Không dùng lại
 * `OpensDeadline` vì trait đó khoá theo chiều ngược — viết lại tại chỗ thay vì đổi thứ tự khoá
 * của hai Action kia (ngoài phạm vi vòng sửa này).
 */
class ChangeDeadlineResponsible
{
    use ChecksAccountActive;
    use ChecksDeadlineHolder;
    use ReadsWithoutPortalScope;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Deadline $deadline, User $actor, User $newResponsible): Deadline
    {
        return DB::transaction(function () use ($deadline, $actor, $newResponsible): Deadline {
            // Câu ĐẦU TIÊN: khoá vụ việc, đọc lại từ CSDL — không tin `$deadline->matter` do caller
            // đưa vào, có thể là một quan hệ cũ hoặc một đối tượng đã sửa trong bộ nhớ.
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

            // `DeadlinePolicy::update` — cùng cổng bốn nút còn lại của tab này.
            if (Gate::forUser($actor)->inspect('update', $fresh)->denied()) {
                $this->refuse();
            }

            // Minor (fix round 2): một mốc ĐÃ HOÀN THÀNH (đọc từ `$fresh`, đã khoá dòng — không
            // phải `$deadline` caller đưa vào) không còn "việc" nào để đổi người phụ trách nữa.
            // `DeadlinesRelationManager` ẩn nút cho trường hợp này, nhưng Action tự chặn LÀ lớp
            // phòng thủ thật — cùng kỷ luật "màn hình không phải cổng, Action mới là cổng" của cả
            // dự án.
            if ($fresh->is_completed) {
                throw ValidationException::withMessages([
                    'responsible_user_id' => [__('deadlines.validation.already_completed')],
                ]);
            }

            // Minor (fix round 2): khoá dòng người mới NGAY SAU vụ việc/mốc hạn — cùng thứ tự
            // toàn cục "vụ việc trước, bảng con sau, người thứ ba sau cùng" — rồi đọc lại
            // `is_active`/`trashed()` DƯỚI KHOÁ qua `canHoldDeadline()`. `$newResponsible` do
            // caller đưa vào chỉ đọc thuộc tính đã nạp sẵn, có thể cũ (form mở ra lúc người đó còn
            // hoạt động, rồi bị vô hiệu hoá/xoá giữa lúc người dùng đang chọn và lúc họ bấm lưu) —
            // câu này đóng đúng khe hở đó, cùng công thức `ReassignMatter`'s `$lockedNewLead`.
            $lockedNewResponsible = User::query()->withTrashed()->whereKey($newResponsible->getKey())->lockForUpdate()->first();

            if ($lockedNewResponsible === null || ! $this->canHoldDeadline($lockedNewResponsible, $matter)) {
                throw ValidationException::withMessages([
                    'responsible_user_id' => [__('deadlines.validation.responsible_cannot_open')],
                ]);
            }

            $previous = $fresh->responsible_user_id;

            $fresh->blameOn($actor)->update(['responsible_user_id' => $lockedNewResponsible->getKey()]);

            Audit::record('deadline_responsible_changed', $fresh, [
                'matter_id' => $matter->getKey(),
                'client_id' => $matter->client_id,
                'from' => $previous,
                'to' => $lockedNewResponsible->getKey(),
            ], causer: $actor);

            return $fresh;
        });
    }

    /**
     * Cùng câu, cùng lớp exception với {@see OpensDeadline}
     * (SPEC §10.10): mốc không tồn tại, vụ việc không tồn tại/đã xoá mềm, actor không có quyền,
     * tài khoản actor đã bị vô hiệu hoá — một câu duy nhất, không phân biệt được với nhau.
     */
    private function refuse(): never
    {
        throw new AuthorizationException(__('deadlines.unavailable'));
    }
}
