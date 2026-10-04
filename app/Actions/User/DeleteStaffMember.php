<?php

namespace App\Actions\User;

use App\Actions\Mcp\RevokeAiConnections;
use App\Actions\User\Concerns\GuardsStaffOffboarding;
use App\Enums\AiRevocationReason;
use App\Enums\Role;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Xoá (mềm) một tài khoản nhân sự qua `EditUser`'s `DeleteAction` (I2, fix round 1), VÀ qua
 * `UsersTable`'s `DeleteBulkAction` (I2.1, fix round 2 — cùng luật, một Action DUY NHẤT cho cả hai
 * đường vào, không phải hai luật tưởng giống nhau).
 *
 * # Vì sao Action này tồn tại, khi luật thật đã nằm trong `UserPolicy::delete()`
 *
 * Trước bản sửa này, `DeleteAction` mặc định của Filament tự hỏi `UserPolicy::delete()` một lần
 * (để quyết định nút có bấm được không), rồi gọi `$record->delete()` NGAY SAU — hai câu lệnh RỜI,
 * không khoá gì, không chung một transaction. Giữa hai câu đó, một request KHÁC (một
 * `ChangeDeadlineResponsible`, một `ReassignMatter`, một lượt lưu `EditUser` khác) có thể thay đổi
 * đúng thứ `offboardingOpenWorkReason()` vừa đọc — I2 đòi khoá dòng nhân sự TRƯỚC KHI hỏi lại luật
 * đó, trong CÙNG một transaction với lần xoá.
 *
 * Action này KHÔNG viết lại luật của `UserPolicy::delete()` — nó gọi lại ĐÚNG hàm đó (`Gate::
 * inspect('delete', ...)`), chỉ khác là hỏi trên một bản ghi ĐÃ khoá, đọc thẳng CSDL. Một
 * `Response::deny($reason)` (còn việc dở dang) dịch thành `DomainException` — {@see
 * \App\Filament\Admin\Concerns\ReportsActionFailures} hiện nó bằng một `Notification` mang
 * NGUYÊN VĂN lý do; một từ chối KHÔNG có lý do (tự xoá chính mình — trường hợp không tồn tại được
 * qua nút này, nút đã ẩn từ trước) dịch thành `AuthorizationException`, hiện bằng câu chung SPEC
 * §10.10 đòi.
 *
 * # `Cache::lock()` — đóng cuộc đua "hai admin cuối cùng cùng bị xoá lúc hai request khác nhau"
 *
 * Khoá dòng ở trên đóng đúng cuộc đua "hai request cùng thao tác MỘT hàng" (ví dụ hai tab cùng
 * xoá một nhân sự). Nó KHÔNG đóng được cuộc đua giữa HAI HÀNG KHÁC NHAU — ví dụ admin A và admin
 * B là hai admin đang hoạt động cuối cùng, hai request khác nhau cùng xoá đúng NGƯỜI KIA gần như
 * đồng thời: khoá dòng A không ngăn được request đang đọc "có admin nào khác đang hoạt động"
 * trên dòng B (một câu đọc không khoá). `Cache::lock('staff-admin-headcount', ...)` (cache store
 * `database`, cùng chủ trương "không Redis" của dự án) tuần tự hoá đúng câu hỏi TOÀN CỤC đó: chỉ
 * một lượt xoá/vô hiệu hoá/đổi chức danh nhân sự chạy tới bước hỏi "còn admin nào khác" tại một
 * thời điểm, trên toàn hệ thống. Cùng khoá được `EditUser::handleRecordUpdate()` giữ (đặt tên
 * THỐNG NHẤT — hai nơi khác nhau hỏi cùng một câu phải xếp hàng chung một khoá, không phải hai
 * khoá riêng biệt tưởng là an toàn).
 *
 * **Đánh đổi đã biết, nói thẳng ra:** khoá này tuần tự hoá MỌI lượt xoá/sửa nhân sự đi qua hai nơi
 * trên, không chỉ những lượt đụng tới admin — nhân sự là bảng ít thao tác (vài chục nhân sự, sửa
 * vài lần một tuần), nên cái giá thông lượng gần như bằng không. Không có test đo cuộc đua thật
 * (cần hai tiến trình PHP thật) — bằng chứng cho khoá này chỉ là nó có mặt trong đường đi.
 *
 * # Đọc lại CHÍNH actor dưới khoá (I2, fix round 2) — không tin `$actor` caller đưa vào
 *
 * Bản fix round 1 chỉ khoá lại DÒNG CỦA TARGET rồi hỏi `Gate::forUser($actor)` — nhưng `$actor` vẫn
 * là đối tượng Filament nạp lúc ĐẦU request (qua `Auth::user()`), không đọc lại gì. Cuộc đua thật:
 * hai admin A và B là hai admin đang hoạt động CUỐI CÙNG; request 1 (A xoá B) và request 2 (B xoá
 * A) gần như đồng thời. `Cache::lock` tuần tự hoá hai transaction, nhưng nếu KHÔNG đọc lại actor,
 * request 2 (chạy SAU khi request 1 đã xoá B) vẫn hỏi `Gate::forUser($actor = B đối tượng CŨ)` —
 * đối tượng đó trong bộ nhớ vẫn "is_active = true", dù B vừa bị chính request 1 xoá — nên request 2
 * xoá luôn A, hệ thống còn 0 admin. Đọc lại actor DƯỚI khoá (locking read, luôn thấy mới nhất bất kể
 * snapshot REPEATABLE READ — bài học Task 3) đóng đúng khe hở đó: actor B của request 2 lúc này đã
 * `trashed()`, bị từ chối trước khi chạm gì tới A.
 *
 * Ba điều kiện đọc lại: `is_active`, không `trashed()`, và vẫn giữ vai Admin
 * (`hasRole(Role::Admin)`) — CÙNG ba điều kiện `UserPolicy::viewAny()`/`GuardsStaffOffboarding::
 * wouldLeaveNoActiveAdmin()` đã dùng để định nghĩa "admin đang hoạt động", không phải một định nghĩa
 * riêng. Thiếu một trong ba, actor không còn là chính người mà `Gate::forUser()` tưởng là đang thao
 * tác — `AuthorizationException` (không lý do, cùng lớp/câu SPEC §10.10 quy định cho một cổng thô).
 *
 * **Sửa lại (fix round 3) — tuyên bố trước đây ở đây SAI: `is_active` KHÔNG phải điều kiện độc lập
 * duy nhất.** Bản round 2 nói `UserPolicy::viewAny()` "đã tự từ chối một actor... đã bị xoá mềm",
 * suy luận từ chỗ nó chỉ hỏi `settings.manage`. SAI: xoá mềm (`SoftDeletes`) chỉ đặt `deleted_at`
 * trên bảng `users`, KHÔNG đụng gì tới bảng vai trò riêng của spatie/laravel-permission
 * (`model_has_roles`) — một actor đã bị xoá mềm vẫn còn NGUYÊN vai Admin, nên `can(SettingsManage)`
 * vẫn trả `true`, và `Gate::forUser($lockedActor)->inspect('delete', ...)` KHÔNG hề từ chối họ.
 * Mutation probe (xoá riêng điều kiện `trashed()`, giữ nguyên ba điều kiện còn lại) xác nhận: test
 * "refuses when the actor was soft deleted after being loaded" ĐỎ ngay — chính điều kiện `trashed()`
 * ở đây, KHÔNG phải `Gate`, mới đóng đúng cuộc đua "A xoá B trong khi B xoá A" cho vế "actor đã bị
 * chính lượt kia xoá". Chỉ `hasRole(Role::Admin)` mới thật sự trùng lặp với `Gate` (mất vai Admin
 * làm `can(SettingsManage)` tự trả `false`) — giữ lại làm phòng thủ tường minh (đọc code không cần
 * lần theo `Gate` mới hiểu actor phải còn là ai). `is_active` VÀ `trashed()` đều là điều kiện ĐỘC
 * LẬP thật sự: `viewAny()` không hỏi cột `is_active`, và không hỏi `deleted_at` — thiếu MỘT trong
 * hai, actor bị vô hiệu hoá/xoá mềm giữa chừng vẫn qua được `Gate`.
 *
 * # `wouldLeaveNoActiveAdmin()` gọi lại trên `$lockedTarget` (I2, fix round 2)
 *
 * Giữ ĐÚNG chữ finding round 2 đòi ("check the last-admin rule against fresh data"), dù phân tích kỹ
 * cho thấy — MỘT KHI actor đã qua được ba điều kiện ngay trên (còn là admin đang hoạt động, khác
 * target vì tự xoá đã bị `UserPolicy::delete()`'s `$user->isNot($model)` chặn từ trước) — actor TỰ
 * NÓ luôn là "một admin khác" đang hoạt động, nên nhánh này không còn kịch bản nào tới được nữa
 * trong kiến trúc hiện tại (không có mutation probe RED cho riêng nhánh này tách khỏi ba điều kiện
 * actor — xem báo cáo, mục "Tự đánh giá"). Giữ lại làm phòng thủ nhiều lớp CÓ CHỦ ĐÍCH, không phải
 * mã thừa: nếu một vòng sửa tương lai đổi thứ tự (ví dụ actor được đọc lại LỎNG hơn), nhánh này vẫn
 * đứng đó bắt lại đúng luật R7.
 *
 * # Thu hồi kết nối AI (M11 R8, Task 6)
 *
 * Xoá mềm đã làm token MCP của người đó vô dụng (provider Eloquent không nạp người đã xoá mềm), nhưng
 * dòng token vẫn "chưa thu hồi" và `UserPolicy::restore()` khôi phục được tài khoản. Nên cùng
 * transaction với lần xoá, {@see RevokeAiConnections} thu hồi mọi access token, refresh token và mã uỷ
 * quyền của người đó, và hạ `ai_access` về `off`: một tài khoản được khôi phục không mang theo quyền
 * AI hay kết nối cũ.
 */
class DeleteStaffMember
{
    use GuardsStaffOffboarding;

    public function handle(User $actor, User $target): void
    {
        Cache::lock('staff-admin-headcount', 10)->block(5, function () use ($actor, $target): void {
            DB::transaction(function () use ($actor, $target): void {
                $lockedTarget = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

                $lockedActor = User::query()->withTrashed()->whereKey($actor->getKey())->lockForUpdate()->first();

                if ($lockedActor === null || ! $lockedActor->is_active || $lockedActor->trashed() || ! $lockedActor->hasRole(Role::Admin->value)) {
                    throw new AuthorizationException;
                }

                $response = Gate::forUser($lockedActor)->inspect('delete', $lockedTarget);

                if ($response->denied()) {
                    if (blank($response->message())) {
                        throw new AuthorizationException;
                    }

                    throw new DomainException($response->message());
                }

                if ($this->wouldLeaveNoActiveAdmin($lockedTarget, remainsActiveAdmin: false)) {
                    throw new DomainException($this->lastActiveAdminReason());
                }

                $lockedTarget->delete();

                // M11 R8 (Task 6): xem docblock lớp, mục cuối.
                app(RevokeAiConnections::class)->handle($lockedTarget, AiRevocationReason::Deleted, $lockedActor);
            });
        });
    }
}
