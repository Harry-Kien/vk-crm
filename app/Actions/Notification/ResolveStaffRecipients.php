<?php

namespace App\Actions\Notification;

use App\Enums\Confidentiality;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\IntakeRequest;
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
 * đều hợp lệ thì cả hai đều nhận, không phải chỉ người đầu tiên). `Gate::view()` cho một vụ
 * `restricted` vốn đã chỉ cho `lead_lawyer`/`admin` đi qua (xem `Matter::isListableBy()`), nên một
 * trưởng phòng thường lỡ có mặt trong `$preferred` tự động bị lọc ra — lớp này không cần biết gì
 * về `confidentiality` để làm việc đó.
 *
 * **{@see self::supervisorsFor()} — MỘT định nghĩa DUY NHẤT "ai giám sát vụ việc này" (vòng sửa
 * 1, M1, đọc code thật thay vì suy đoán, thay cho bản Task 12 gốc bên dưới).** Bản Task 12 gốc
 * đẩy quyết định "manager hay admin" ra từng CALLER, với lý lẽ "cứ đưa cả hai vai trò vào
 * `$preferred`, `Gate::view()` sẽ tự lọc đúng người" — kết quả là BA caller
 * (`CheckDeadlines`, `SyncClientPartyIdentities`, `SendDeadlineReminderMail::failed()`) mỗi nơi
 * tự quyết một cách khác nhau: một nơi làm đúng (ternary theo `confidentiality`), hai nơi CỘNG CẢ
 * manager LẪN admin không điều kiện. Sai ở đúng chỗ hai nơi kia: một vụ THƯỜNG cũng cho admin
 * `Gate::view()` qua (`matter.viewAny` là đủ), nên cộng cả hai vai trò không phân biệt vụ việc sẽ
 * khiến MỌI admin đang hoạt động nhận thêm thông báo của MỌI vụ THƯỜNG, không riêng vụ
 * `restricted` (đo được: một test đã có sẵn của `CheckDeadlinesTest`, "sends nothing for a
 * deadline whose matter is cancelled...", dựng sẵn một admin cho việc khác cạnh một mốc `d1` của
 * vụ THƯỜNG — cộng cả hai vai trò không điều kiện làm test đó đỏ). `supervisorsFor()` là NƠI DUY
 * NHẤT quyết định vai trò nào được hỏi — mọi caller cần "ai giám sát vụ này" gọi thẳng nó, không
 * tự dựng lại quyết định đó theo cách riêng.
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

    /**
     * "Ai giám sát vụ việc này" (vòng sửa 1, M1) — MỘT định nghĩa DUY NHẤT, xem docblock lớp. Mọi
     * quản lý được xem vụ; RIÊNG vụ `restricted`, mọi admin đang hoạt động THAY VÌ quản lý — một
     * quyết định vai trò có chủ ý, không phải hệ quả tình cờ của `Gate::view()` (khác hẳn
     * `fallbackChain()`, nơi "restricted thì manager tự rớt, rơi xuống admin" ĐÚNG LÀ hệ quả tình
     * cờ của Gate — hai cơ chế khác nhau, đừng nhầm).
     *
     * Vẫn đi qua {@see self::qualify()} như mọi danh sách khác: một quản lý bị vô hiệu hoá giữa
     * chừng vẫn bị loại dù đúng vai trò.
     *
     * @return Collection<int, User>
     */
    public function supervisorsFor(Matter $matter): Collection
    {
        $role = $matter->confidentiality === Confidentiality::Restricted ? Role::Admin : Role::Manager;

        return $this->qualify(
            User::query()->where('is_active', true)->role($role->value)->get(),
            $matter,
        );
    }

    /**
     * Một người CÓ qua được `is_active` + `Gate::view()` của `$matter` hay không — cùng luật của
     * {@see self::qualify()}, chỉ khác là hỏi về MỘT người thay vì lọc một danh sách. Dùng khi
     * caller cần biết "người X có còn hợp lệ không" để tự quyết định thay THẾ họ bằng ai (ví dụ
     * `CheckDeadlines::recipientsFor()`: người phụ trách mốc không qua được thì thế bằng luật sư
     * phụ trách vụ — I1, vòng sửa 1), chứ không chỉ đơn thuần lọc một danh sách sẵn có.
     */
    public function qualifies(User $user, Matter $matter): bool
    {
        return $this->qualify(collect([$user]), $matter)->isNotEmpty();
    }

    /**
     * @return Collection<int, User>
     *
     * **`! $user->trashed()` (fix round 1, minor ruling).** `$preferred` là caller-supplied — một
     * caller có thể truyền một `User` đã nạp bằng `withTrashed()` (ví dụ đi lấy "ai TỪNG là người
     * phụ trách" một mốc hạn), hay một quan hệ không tự áp `SoftDeletingScope`. `is_active` VÀ
     * `deleted_at` là HAI cột khác nhau — không có gì đảm bảo mọi đường xoá một tài khoản luôn đặt
     * `is_active = false` TRƯỚC KHI xoá mềm (R7 nói "vô hiệu hoá VÀ xoá" như hai bước, không phải
     * một bất biến DB). Kiểm tra tường minh ở đây, không tin cột kia làm thay việc của cột này.
     */
    private function qualify(Collection $candidates, Matter $matter): Collection
    {
        return $candidates
            ->filter(fn (?User $user): bool => $user instanceof User)
            ->unique(fn (User $user): int|string => $user->getKey())
            ->filter(fn (User $user): bool => $user->is_active)
            ->filter(fn (User $user): bool => ! $user->trashed())
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

    /**
     * Người nhận thư/thông báo về MỘT LẦN CÓ NGƯỜI LIÊN HỆ (M10 R5, Task 5 — `staff.intake_unanswered`).
     * Cổng riêng vì bản ghi tiếp nhận không có `Matter` nào để hỏi `Gate::view()` như {@see self::handle()};
     * cùng lớp, cùng hai bộ lọc `is_active` + chưa xoá mềm, và "xem được" là `IntakeRequestPolicy::view`
     * (`IntakeRequest::isVisibleTo()`). Ba tầng, dừng ở tầng ĐẦU TIÊN có người — "không bao giờ im lặng":
     *  1. người được giao (`assigned_to`), nếu đang hoạt động, chưa xoá và còn xem được bản ghi;
     *  2. không thì MỌI người có quyền `intake.viewAny` đang hoạt động, chưa xoá, xem được bản ghi;
     *  3. không thì MỌI admin đang hoạt động, chưa xoá — KHÔNG hỏi "xem được": tầng cuối là lưới an
     *     toàn khi chính quyền `intake.viewAny` đã bị gỡ khỏi mọi vai, và thư của mẫu này không mang
     *     dữ liệu nào của người liên hệ (chỉ mã, nguồn, thời gian đã chờ, liên kết), nên báo cho admin
     *     — người sửa được phân quyền — vẫn hơn im lặng.
     * Người đã ghi bản ghi mà không được giao thì không nhận: R5 nói "người được giao", không nói
     * "người nhấc máy". Người được giao đã xoá mềm không tới được tầng 1 (quan hệ `assignee` bỏ dòng đã
     * xoá); tầng 2 và 3 đọc `User::query()`, cũng bỏ dòng đã xoá.
     *
     * @return Collection<int, User>
     */
    public function forIntake(IntakeRequest $intake): Collection
    {
        $qualifies = fn (User $user): bool => $user->is_active
            && Gate::forUser($user)->allows('view', $intake);

        $assignee = $intake->assignee()->first();

        if ($assignee instanceof User && $qualifies($assignee)) {
            return collect([$assignee]);
        }

        $viewers = User::query()
            ->permission(Permission::IntakeViewAny->value)
            ->get()
            ->filter($qualifies)
            ->values();

        if ($viewers->isNotEmpty()) {
            return $viewers;
        }

        return User::query()->where('is_active', true)->role(Role::Admin->value)->get()->values();
    }
}
