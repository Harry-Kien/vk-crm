<?php

namespace App\Actions\Notification;

use App\Enums\Role;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * R3 (M6.5 Task 8, đọc lại SPEC §6.8): người nhận thư/thông báo trong hệ thống về một vụ việc chỉ
 * là người đang `is_active` VÀ qua được `Gate::forUser($u)->allows('view', $matter)`. Đây là NƠI
 * DUY NHẤT chọn người nhận theo luật này — Task 12 (nhắc hạn) và Task 14 dùng lại chính lớp này,
 * KHÔNG viết lại một lần nữa. M9 sẽ thêm một điều kiện khác vào ĐÂY khi tới lượt, không tạo lớp
 * thứ hai — brief nói rõ điều đó.
 *
 * **Chữ ký `handle(Matter $matter, array $preferred): Collection<User>`.** `$preferred` là danh
 * sách người ƯU TIÊN theo thứ tự mà CALLER biết rõ nhất cho chính sự kiện của họ (ví dụ: người
 * phụ trách một mốc hạn cụ thể, hay người phụ trách một yêu cầu khách cụ thể — những khái niệm
 * KHÔNG tồn tại ở tầng `Matter`, nên không thể "cứng" vào lớp này). Lớp CHỈ lọc: giữ lại đúng
 * những người trong `$preferred` đang `is_active` VÀ được xem `$matter`, theo ĐÚNG thứ tự đã
 * truyền — có thể trả về NHIỀU người (ví dụ: cả luật sư phụ trách LẪN mọi trưởng phòng được xem vụ
 * đều hợp lệ thì cả hai đều nhận, không phải chỉ người đầu tiên). "Vụ `restricted`: thay manager
 * bằng admin" của R3 không cần một nhánh riêng ở đây: `Gate::view()` cho một vụ `restricted` vốn
 * đã chỉ cho `lead_lawyer`/`admin` đi qua (xem `Matter::isListableBy()`), nên một trưởng phòng
 * thường trong `$preferred` tự động bị lọc ra — caller chỉ cần đưa cả manager LẪN admin vào
 * `$preferred` (thứ tự không quan trọng cho việc lọc, vì lớp không dừng ở người đầu tiên hợp lệ).
 *
 * **Chuỗi dự phòng CHỈ chạy khi `$preferred` không còn ai hợp lệ ("Không bao giờ im lặng" — R3).**
 * Không phải một danh sách caller có thể tự chọn: đây là lưới an toàn CUỐI CÙNG của R3, giống nhau
 * cho MỌI sự kiện gắn với một `Matter`, nên nằm cứng trong lớp — `luật sư phụ trách` (
 * `$matter->leadLawyer`) → `manager được xem vụ` (trên một vụ `restricted`, không ai qua được đây
 * — xem `fallbackChain()`, R3 tự thoả bằng `Gate::view()`, không cần một nhánh riêng) → bất kỳ
 * `admin` nào được xem vụ. Tầng "người phụ trách" đầu tiên của R3 KHÔNG lặp lại ở
 * đây: nó là khái niệm của TỪNG sự kiện (mốc hạn, yêu cầu khách, ...), caller đã thử nó qua chính
 * `$preferred` — lặp lại một khái niệm `Matter` không biết tới ở tầng dự phòng sẽ chỉ là một biến
 * luôn `null`. Dừng ở người ĐẦU TIÊN hợp lệ của chuỗi (không như `$preferred`, ở đây "có ai đó" là
 * đủ, không cần "mọi người đủ điều kiện").
 *
 * **Vì sao không lọc `Gate` trước rồi mới is_active (hay ngược lại) làm khác biệt gì.** Cả hai
 * điều kiện đều bắt buộc, không có thứ tự ưu tiên — một người bị vô hiệu hoá giữa chừng (Review
 * Focus 2 của brief) không được nhận, kể cả khi họ vẫn `Gate::view()` được (cột `is_active` không
 * nằm trong bất kỳ điều kiện nào của `MatterPolicy::view()`).
 */
class ResolveStaffRecipients
{
    /**
     * @param  array<int, User|null>  $preferred  Danh sách người ưu tiên theo thứ tự, theo đúng
     *                                            ngữ cảnh sự kiện mà caller biết (SPEC §6.8: "toàn
     *                                            bộ vai trò manager" được đọc là "mọi manager được
     *                                            xem vụ đó" — caller nên đưa MỌI manager vào đây,
     *                                            không chỉ một người). Phần tử `null` bị bỏ qua
     *                                            lặng lẽ — tiện cho caller truyền thẳng một quan hệ
     *                                            có thể rỗng (`$deadline->responsibleUser`).
     * @return Collection<int, User> Rỗng KHÔNG BAO GIỜ xảy ra trừ khi vụ việc không còn admin nào
     *                               đang hoạt động — R7 cấm chính điều đó ("admin đang hoạt động
     *                               cuối cùng không thể tự vô hiệu hoá hay tự xoá").
     */
    public function handle(Matter $matter, array $preferred): Collection
    {
        $qualified = $this->qualify(collect($preferred), $matter);

        if ($qualified->isNotEmpty()) {
            return $qualified;
        }

        return $this->fallbackChain($matter);
    }

    /** @return Collection<int, User> */
    private function qualify(Collection $candidates, Matter $matter): Collection
    {
        return $candidates
            ->filter(fn (?User $user): bool => $user instanceof User)
            ->unique(fn (User $user): int|string => $user->getKey())
            ->filter(fn (User $user): bool => $user->is_active)
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $matter))
            ->values();
    }

    /**
     * "Không bao giờ im lặng" (R3): người phụ trách → luật sư phụ trách → manager được xem vụ →
     * admin. Trả về ngay khi tìm được MỘT người hợp lệ; tầng "người phụ trách" của SPEC không lặp
     * lại ở đây — xem docblock lớp.
     *
     * @return Collection<int, User>
     */
    private function fallbackChain(Matter $matter): Collection
    {
        if ($matter->leadLawyer !== null) {
            $leadLawyerQualified = $this->qualify(collect([$matter->leadLawyer]), $matter);

            if ($leadLawyerQualified->isNotEmpty()) {
                return $leadLawyerQualified;
            }
        }

        // "Thay manager bằng admin" ở vụ restricted (R3) không cần một điều kiện RIÊNG ở đây: một
        // manager thường không bao giờ qua được Gate::view() của một vụ restricted (xem docblock
        // lớp và Matter::isListableBy() — nhánh restricted chỉ đọc admin/lead_lawyer_id, không
        // đọc vai trò manager chút nào), nên qualify() ở dưới tự loại họ và tầng này tự rơi xuống
        // tầng admin kế tiếp — đo được bằng một mutation probe: khoá cứng $managerRole thành
        // Role::Manager (bỏ điều kiện restricted) không đổi hành vi quan sát được ở BẤT KỲ test
        // nào, đúng nghĩa "điều kiện chết". Giữ code đơn giản, không thêm một nhánh không ai đo
        // được là đang làm gì.
        $manager = $this->qualify(
            User::query()->where('is_active', true)->role(Role::Manager->value)->get(),
            $matter,
        )->first();

        if ($manager !== null) {
            return collect([$manager]);
        }

        $admin = $this->qualify(
            User::query()->where('is_active', true)->role(Role::Admin->value)->get(),
            $matter,
        )->first();

        return $admin !== null ? collect([$admin]) : collect();
    }
}
