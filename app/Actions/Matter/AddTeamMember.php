<?php

namespace App\Actions\Matter;

use App\Enums\MatterRole;
use App\Enums\Role;
use App\Filament\Admin\Resources\Matters\RelationManagers\DeadlinesRelationManager;
use App\Filament\Admin\Resources\Matters\RelationManagers\TeamRelationManager;
use App\Models\Matter;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Thêm MỘT thành viên vào đội ngũ vụ việc (SPEC §4.7, §5, §7.2; M6.5 Task 3, R6).
 *
 * # Vì sao Action này tồn tại: `matter_user` không có đường ghi nào ngoài `Matter::created()`
 *
 * Trước task này, chỗ DUY NHẤT trong `app/` ghi vào `matter_user` là hook `Matter::created()`
 * (chỉ thêm LUẬT SƯ PHỤ TRÁCH, vai `lead`) và `Matter::addTeamMember()` — hàm thứ hai chỉ được
 * `MatterSeeder` và test gọi (49 lời gọi, 22 tệp, không đường nào trong số đó đi qua giao diện —
 * finding `intake-01`/`roles-03`/`spec-gap-01`/`e2e-F4`, critical). Hậu quả: MỌI vụ việc mở qua
 * `CreateMatter` chỉ có một người — trợ lý và luật sư cộng sự không bao giờ thấy được vụ, không
 * duyệt được giấy tờ khách nộp, không được chọn ở ô "người phụ trách mốc hạn" hay "người xử lý
 * yêu cầu khách" (cả hai đọc `team()`), và bậc nhắc 3 ngày của `CheckDeadlines` không tìm thấy
 * trợ lý nào. Action này là đường ghi thứ hai, và là đường DUY NHẤT có một màn hình đứng trước nó
 * ({@see TeamRelationManager}).
 *
 * # Vai `lead` không đi qua đây
 *
 * `lead` chỉ đổi được qua `ReassignMatter` (M7, R6) — nó không phải một "thêm thành viên" mà là
 * một BÀN GIAO, với những bước riêng (SPEC §6.11: dọn `matter_user` cũ, kiểm tra việc dở dang của
 * người cũ, ghi audit riêng) mà Action này không làm. Cho `lead` lọt qua đây là một đường tắt
 * vòng qua toàn bộ luồng đó — chặn ngay ở bước 1, trước cả khi hỏi người được chọn có hợp lệ hay
 * không.
 *
 * # `observer` được làm gì trong hệ thống hôm nay — đọc trước khi đổi hàm này
 *
 * `role_in_matter` (cột của `matter_user`) là MỘT NHÃN hiển thị, không phải một tầng quyền. Rà
 * toàn bộ `app/` (grep `role_in_matter`, `MatterRole`): không một `Gate`, `Policy` hay Action nào
 * đọc `pivot.role_in_matter` để quyết định một người được làm gì với vụ việc — chỉ
 * `MatterInfolist` và `TeamRelationManager` ĐỌC nó để vẽ ra màn hình. Quyền thật của một thành
 * viên đến từ HAI thứ, cả hai độc lập với vai trong vụ việc:
 *
 *  1. Permission của chính họ (`Role::permissions()` — ví dụ `checklist.review` chỉ
 *     Lawyer/Manager/Admin/Assistant có, `Accountant` không).
 *  2. Việc có MẶT trong `team()` — thứ mở ra `matter.view` (`Matter::isListableBy()` /
 *     `scopeListableBy()`) và mọi cổng dựa trên nó: `MatterPolicy::update()`,
 *     `DeadlinesRelationManager::responsibleOptions()`,
 *     `ClientRequestsRelationManager::assignableUsers()` — tất cả đọc `team()` TRỰC TIẾP, không
 *     lọc theo `role_in_matter`.
 *
 * Vì vậy một `observer` — vai này KHÔNG có trong SPEC §1/§5 như một chức danh có việc riêng, nó
 * chỉ là "có mặt trong đội ngũ, không phải associate/assistant" — nhìn thấy TOÀN BỘ nội dung vụ
 * việc y hệt một `associate`, và nếu permission của họ cho phép (ví dụ họ là Manager), CŨNG sửa
 * được, duyệt được, và được chọn ở mọi ô chọn dựa trên `team()`. Task 3 KHÔNG thu hẹp việc này
 * lại — nó chỉ kiểm soát AI được GẮN vai `observer` ({@see self::eligibleForRole()}: nhân sự nội
 * bộ trừ kế toán), không kiểm soát observer LÀM ĐƯỢC GÌ một khi đã ở trong đội ngũ. Ghi lại ở đây
 * vì đây là nơi DUY NHẤT trong app "sinh" ra một `observer` qua giao diện, và người đọc sau cần
 * biết vai này không tự hạn chế quyền gì — nếu SPEC sau này muốn `observer` chỉ-đọc, cổng phải
 * được thêm ở `team()`/từng Action đọc `pivot.role_in_matter`, không phải ở đây.
 */
class AddTeamMember
{
    /**
     * @throws ValidationException
     */
    public function handle(Matter $matter, User $actor, User $member, MatterRole $role): void
    {
        Gate::forUser($actor)->authorize('manageTeam', $matter);

        if ($role === MatterRole::Lead) {
            throw ValidationException::withMessages([
                'role_in_matter' => [__('actions.add_team_member.lead_role_denied')],
            ]);
        }

        if (! self::eligibleForRole($member, $role)) {
            throw ValidationException::withMessages([
                'user_id' => [__('actions.add_team_member.role_not_eligible')],
            ]);
        }

        if (! $member->is_active || $member->trashed()) {
            throw ValidationException::withMessages([
                'user_id' => [__('actions.add_team_member.member_inactive')],
            ]);
        }

        if ($matter->team()->whereKey($member->getKey())->exists()) {
            throw ValidationException::withMessages([
                'user_id' => [__('actions.add_team_member.already_member')],
            ]);
        }

        $matter->addTeamMember($member, $role);

        Audit::record('team_member_added', $matter, [
            'user_id' => $member->getKey(),
            'role' => $role->value,
        ], $actor);
    }

    /**
     * "Có thể giữ vai này" (Task 3 brief): trợ lý cho `assistant`; luật sư hoặc manager cho
     * `associate`; nhân sự nội bộ TRỪ kế toán cho `observer`. `lead` không có nhánh hợp lệ nào —
     * luôn `false`, xem "Vai `lead` không đi qua đây" ở docblock lớp.
     *
     * Công khai vì cùng lý do {@see DeadlinesRelationManager::responsibleOptions()}:
     * đây là chỗ DUY NHẤT đo được danh sách thật của ô chọn người trong
     * `TeamRelationManager::memberOptions()` — một `Select` `native(false)` không in options vào
     * HTML ban đầu, Filament dựng chúng phía trình duyệt.
     *
     * KHÔNG phải một cổng đầy đủ một mình: `handle()` hỏi lại đúng hàm này trên NGƯỜI ĐƯỢC CHỌN,
     * nên một payload dàn dựng chọn sai vai vẫn bị chặn dù màn hình có lọc đúng hay không.
     */
    public static function eligibleForRole(User $member, MatterRole $role): bool
    {
        return match ($role) {
            MatterRole::Assistant => $member->hasRole(Role::Assistant->value),
            MatterRole::Associate => $member->hasRole(Role::Lawyer->value) || $member->hasRole(Role::Manager->value),
            MatterRole::Observer => ! $member->hasRole(Role::Accountant->value),
            MatterRole::Lead => false,
        };
    }
}
