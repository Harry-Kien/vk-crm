<?php

namespace App\Filament\Admin\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * "Người đang đăng nhập có ghi đè được một xung đột mức đỏ hay không" (SPEC §6.10 bước 3) — CHỈ để
 * HIỂN THỊ. Cổng thật nằm trong `OpenMatter`/`AddMatterParty`, vốn tự kiểm tra lại vai trò của
 * actor và không tin màn hình.
 *
 * **Vì sao một lớp dùng chung cho hai màn hình.** Hai màn hình hỏi cùng một câu hỏi và phải nhận
 * cùng một câu trả lời: màn hình mở vụ việc quyết định khoá/mở ô "Lý do ghi đè" và chọn câu giải
 * thích, tab "Các bên" làm y hệt. Nhánh này đã ba lần bị bản xem xét bắt gặp đúng một quy tắc được
 * cài đặt hai lần rồi lệch nhau (`buildParty`, `visibleClientOptions`, và chính C-1 — một Critical
 * đã sửa ở màn hình này còn nguyên ở màn hình kia), nên một quy tắc thuần vai trò, không dính chữ
 * nghĩa hiển thị nào, được đặt ở một chỗ ngay từ đầu.
 *
 * Phần KHÁC nhau giữa hai màn hình — câu chữ tiếng Việt — cố ý KHÔNG nằm ở đây: hai màn hình nói
 * về hai thao tác khác nhau ("mở vụ việc", "thêm bên") nên mỗi nơi tự chọn khoá dịch của mình.
 */
final class ConflictOverride
{
    public static function allowedForCurrentUser(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User
            && ($actor->hasRole(Role::Manager->value) || $actor->hasRole(Role::Admin->value));
    }
}
