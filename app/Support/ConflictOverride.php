<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * "Người này có ghi đè được một xung đột mức đỏ hay không" (SPEC §6.10 bước 3) — một quy tắc thuần
 * vai trò, không dính chữ nghĩa hiển thị nào.
 *
 * **Vì sao một lớp dùng chung.** Nhánh M3 đã BỐN lần bị bản xem xét bắt gặp đúng một quy tắc được
 * cài đặt hai lần rồi lệch nhau (`buildParty`, `visibleClientOptions`, C-1 — một Critical đã sửa ở
 * một màn hình còn nguyên ở màn hình kia — và chính quy tắc này). Vòng 4 gom nửa HIỂN THỊ về một
 * chỗ; nửa còn lại, tức cổng thật, vẫn được viết tay trong `OpenMatter` và `AddMatterParty`. Đó mới
 * là tầng mà một lần lệch nhau cho phép SAI NGƯỜI ghi đè — nửa hiển thị chỉ làm màn hình nói sai.
 * Nên cả hai nửa giờ hỏi cùng một hàm.
 *
 * **Vì sao lớp này nằm ở `App\Support` chứ không ở `App\Filament\...` như bản trước.** Hai Action
 * trong `app/Actions/` là nơi dùng quan trọng nhất, và CLAUDE.md quy định chiều phụ thuộc một
 * hướng: Filament gọi xuống nghiệp vụ, không bao giờ ngược lại. Một Action `use
 * App\Filament\Admin\Support\...` sẽ khiến `OpenMatter` — vốn phải chạy được từ seeder, job và lệnh
 * console — mang theo một phụ thuộc vào tầng giao diện. `App\Support\Audit` đã là tiền lệ cho một
 * lớp hạ tầng ở đây cũng biết hỏi phiên đăng nhập.
 *
 * Phần KHÁC nhau giữa hai màn hình — câu chữ tiếng Việt — cố ý KHÔNG nằm ở đây: hai màn hình nói
 * về hai thao tác khác nhau ("mở vụ việc", "thêm bên") nên mỗi nơi tự chọn khoá dịch của mình.
 */
final class ConflictOverride
{
    /**
     * Cổng THẬT, dùng trong `app/Actions/`: hỏi về một actor TƯỜNG MINH — chính người đã được đem
     * đi kiểm tra quyền — chứ không về phiên đăng nhập tình cờ đang mở, vì hai Action phải trả lời
     * đúng cả khi chạy từ job, lệnh console hay import.
     */
    public static function allowedFor(User $actor): bool
    {
        return $actor->hasRole(Role::Manager->value) || $actor->hasRole(Role::Admin->value);
    }

    /**
     * Bản dành cho MÀN HÌNH: chỉ để quyết định khoá/mở ô "Lý do ghi đè" và chọn câu giải thích.
     * Không bao giờ là cổng — cổng là `allowedFor()` bên trong Action.
     */
    public static function allowedForCurrentUser(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && self::allowedFor($actor);
    }
}
