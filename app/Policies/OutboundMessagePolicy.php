<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\OutboundMessage;
use App\Models\User;

/**
 * Nhật ký thông báo gửi đi (SPEC §4.15, §7.4). M6.5 Task 13 (notify-8, spec-gap-07) dựng màn
 * hình đầu tiên đọc bảng này; trước đó policy tồn tại nhưng không nơi nào gọi tới.
 *
 * **`viewAny()` mở rộng, không còn chỉ `auditLog.view`.** Bản gốc chỉ admin/manager (quyền
 * `auditLog.view`) qua được — đúng cho MÀN HÌNH TỔNG (mọi dòng của văn phòng, SPEC §7.4). Nhưng
 * tab "Thư đã gửi" trên MỘT vụ việc (`ViewMatter`) cần trả lời đúng câu "luật sư phụ trách vụ
 * này tra được mail của CHÍNH vụ mình không" — không có lý do nghiệp vụ nào để một luật sư đọc
 * được tiến độ/mốc hạn của vụ mình (`MatterPolicy::view`) mà lại không tra được vì sao khách nói
 * "không nhận được thư" của đúng vụ đó. Nên `viewAny()` giờ CŨNG mở cho ai có `matter.view`; kế
 * toán vẫn bị chặn vì vai đó chỉ có `matter.viewAny` (danh sách rút gọn), không có `matter.view`
 * (SPEC §5) — không đổi gì so với trước cho vai này.
 *
 * **`view()` một dòng là nơi lọc thật, không phải `viewAny()`.** Một dòng CÓ vụ việc chỉ lọt qua
 * khi `Gate::allows('view', $matter)` của ĐÚNG vụ đó cho `$user` — dùng lại `MatterPolicy::view()`
 * thay vì viết lại luật restricted/team, để hai nơi không lệch nhau. Một dòng KHÔNG gắn vụ việc
 * nào (OTP tài khoản cổng, thư nội bộ không về vụ việc nào, hay một dòng mồ côi do dữ liệu hỏng)
 * mặc định CHỈ ADMIN xem — quyết định của task này vì SPEC im lặng.
 *
 * **Fix round 1 (chủ nhiệm): admin thấy MỌI dòng, kể cả dòng của một vụ việc đã xoá mềm.**
 * `relatedMatter()` đọc vụ việc bằng `Matter::query()->withTrashed()`, và
 * `Matter::scopeListableBy()` cho admin không lọc gì trên vụ đang sống — nên nhánh admin ở trên
 * (`hasRole(Role::Admin)`) đã đúng mà không cần sửa gì thêm ở đây; xem docblock
 * `OutboundMessage::scopeVisibleTo()` cho mặt SQL của cùng quyết định (admin không lọc gì cả).
 *
 * **Fix round 2 — `view()` phải TỰ đồng ý với `scopeVisibleTo()`, không dựa vào route binding
 * để che một chỗ lệch.** `relatedMatter()` (fix round 1) đọc vụ việc bằng `withTrashed()`, nên
 * `$matter` ở đây có thể là một vụ ĐÃ XOÁ MỀM. `MatterPolicy::view()` CỐ Ý cho một vụ đã xoá mềm
 * lọt qua (đọc docblock hàm đó: "cũng cho phép vụ đã xoá mềm, để admin còn thao tác được") — đúng
 * cho chính `MatterResource`, nơi `getRecordRouteBindingEloquentQuery()` áp `listableBy()` KHÔNG
 * `withTrashed()` nên một vụ đã xoá mềm không bao giờ tới được `view()` qua đường đó để bắt đầu.
 * Nhưng ở đây, `OutboundMessage::scopeVisibleTo()` cho NON-ADMIN cũng dùng
 * `Matter::listableBy($user)` không `withTrashed()` (Ruling fix round 1: "Non-admins keep the
 * `listableBy` rule") — tức route binding của CHÍNH resource này cũng chặn đúng như vậy. Nhưng
 * `Gate::forUser($manager)->allows('view', $row)` gọi TRỰC TIẾP (không qua route, ví dụ từ một
 * Action hay lệnh console tương lai) sẽ không có tầng chặn đó, và khi ấy `view()` phải TỰ đúng —
 * không được phép trông vào việc "route binding chặn trước rồi" để che một quyết định sai của
 * chính nó. Nên: một `$matter` đã xoá mềm chỉ admin qua được ở ĐÂY, bất kể `MatterPolicy::view()`
 * nói gì — hai lớp (scope và policy) phải độc lập đúng, đúng nguyên tắc fix round 1 đã áp cho
 * kế toán (`scopeVisibleTo()`) giờ áp tiếp cho ca này (`view()`).
 */
class OutboundMessagePolicy
{
    public function viewAny(User|ClientUser $user): bool
    {
        return $user instanceof User
            && ($user->can(Permission::AuditLogView->value) || $user->can(Permission::MatterView->value));
    }

    public function view(User|ClientUser $user, OutboundMessage $message): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        $matter = $message->relatedMatter();

        if ($matter === null || $matter->trashed()) {
            return $user instanceof User && $user->hasRole(Role::Admin->value);
        }

        return app(MatterPolicy::class)->view($user, $matter);
    }
}
