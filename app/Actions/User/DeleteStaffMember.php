<?php

namespace App\Actions\User;

use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Xoá (mềm) một tài khoản nhân sự qua `EditUser`'s `DeleteAction` (I2, fix round 1).
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
 */
class DeleteStaffMember
{
    public function handle(User $actor, User $target): void
    {
        Cache::lock('staff-admin-headcount', 10)->block(5, function () use ($actor, $target): void {
            DB::transaction(function () use ($actor, $target): void {
                $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

                $response = Gate::forUser($actor)->inspect('delete', $locked);

                if ($response->denied()) {
                    if (blank($response->message())) {
                        throw new AuthorizationException;
                    }

                    throw new DomainException($response->message());
                }

                $locked->delete();
            });
        });
    }
}
