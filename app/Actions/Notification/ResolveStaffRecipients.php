<?php

namespace App\Actions\Notification;

use App\Enums\Confidentiality;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\IntakeRequest;
use App\Models\Matter;
use App\Models\Task;
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
 *
 * **Thư về TIỀN (M9 Task 11, P3): cùng lớp, cổng khác.** {@see self::forBilling()} là
 * {@see self::handle()} với MỘT điều kiện đổi chỗ: "được xem vụ" (`Gate view`) thành "được xem TIỀN
 * của vụ" (`Gate viewAny` với `[Contract::class, $matter]` — định nghĩa duy nhất ở
 * `ChecksBillingAccess`: `billing.view` + `Matter::isListableBy()` + vụ chưa xoá mềm). Cùng
 * `is_active`, cùng `! trashed()`, cùng chuỗi dự phòng R3 (luật sư phụ trách → một quản lý → một
 * admin) — mỗi tầng của chuỗi cũng hỏi cổng tiền, không phải cổng vụ. Kế toán không có
 * `matter.view` nên `handle()` sẽ luôn loại họ; đó là lý do cổng tiền tồn tại riêng.
 * {@see self::billingAudienceFor()} là nơi DUY NHẤT quyết định vai trò nào nhận thư tiền của vụ
 * nào (như `supervisorsFor()` cho việc giám sát): nơi gọi không tự rẽ nhánh theo `confidentiality`.
 *
 * **Thư giao việc: bộ lọc khả năng `$mustAllow` của {@see self::handle()} (việc sau gộp M9 + M10,
 * làn fu3, Task 1 mục C).** Có thư không chỉ báo tin mà giao việc — thư "gói bàn giao sẵn sàng"
 * (`App\Jobs\SendHandoverPackageReady`) bảo người nhận mở gói, công bố, rồi khách sẽ được báo. "Được
 * xem vụ" không đủ cho thư đó: luật sư phụ trách bị đổi vai thành trợ lý vẫn xem được vụ (là lead),
 * nhưng không tải được gói in tiền và không công bố được — báo cho đúng họ, và chỉ họ, là không ai
 * làm gì. `$mustAllow` (khả năng ⇒ tham số của `Gate`) thêm các câu hỏi đó vào {@see self::qualify()},
 * nên MỌI tầng — danh sách ưu tiên lẫn từng tầng của chuỗi dự phòng — cùng hỏi; rỗng (mặc định) thì
 * không đổi gì cho mọi nơi gọi khác. Câu trả lời là của policy (`DocumentPolicy::download`,
 * `DocumentPolicy::publish`), không chép điều kiện của policy vào đây.
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
     * @param  array<string, mixed>  $mustAllow  Khả năng ⇒ tham số: người nhận còn phải qua
     *                                           `Gate::forUser($u)->allows($ability, $arguments)`
     *                                           cho MỌI cặp, ở danh sách ưu tiên lẫn ở chuỗi dự
     *                                           phòng (xem docblock lớp). Rỗng: chỉ luật R3.
     * @return Collection<int, User> Rỗng KHÔNG BAO GIỜ xảy ra trừ khi vụ việc không còn admin nào
     *                               đang hoạt động — R7 cấm chính điều đó ("admin đang hoạt động
     *                               cuối cùng không thể tự vô hiệu hoá hay tự xoá") — hay, khi có
     *                               `$mustAllow`, không admin đang hoạt động nào qua được các khả
     *                               năng đó.
     */
    public function handle(Matter $matter, array $preferred, array $mustAllow = []): Collection
    {
        $qualified = $this->qualify(collect($preferred), $matter, mustAllow: $mustAllow);

        if ($qualified->isNotEmpty()) {
            return $qualified;
        }

        return $this->fallbackChain($matter, mustAllow: $mustAllow);
    }

    /**
     * Người nhận thư về TIỀN của `$matter` — {@see self::handle()} với cổng tiền thay cổng vụ (xem
     * docblock lớp). `$preferred` lọc theo `is_active` + `! trashed()` + cổng tiền, giữ thứ tự,
     * trả MỌI người hợp lệ; rỗng thì chuỗi dự phòng (cũng qua cổng tiền), không bao giờ im lặng.
     *
     * @param  array<int, User|null>  $preferred  thường là {@see self::billingAudienceFor()}
     * @return Collection<int, User>
     */
    public function forBilling(Matter $matter, array $preferred): Collection
    {
        $qualified = $this->qualify(collect($preferred), $matter, billing: true);

        if ($qualified->isNotEmpty()) {
            return $qualified;
        }

        return $this->fallbackChain($matter, billing: true);
    }

    /**
     * "Ai nhận thư về tiền của vụ này" (P3) — MỘT định nghĩa, quyết định vai trò ở ĐÂY. Luật sư
     * phụ trách đứng đầu; rồi vụ THƯỜNG: mọi kế toán đang hoạt động; vụ `restricted`: mọi admin
     * đang hoạt động (cả kế toán lẫn quản lý đều không thấy tiền của vụ hạn chế, nên không ai trong
     * hai vai trò đó nhận thư về nó). Admin KHÔNG có mặt ở vụ thường dù cổng tiền cho
     * admin qua — thư này không phải bản sao gửi mọi admin cho mọi vụ (admin chỉ tới đây qua chuỗi
     * dự phòng khi không còn ai khác).
     *
     * Đã qua {@see self::qualify()} với cổng tiền: một kế toán bị vô hiệu hoá, hay người phụ trách
     * đã nghỉ việc, không có mặt trong kết quả. Kết quả có thể RỖNG — {@see self::forBilling()}
     * mới là nơi chuỗi dự phòng chạy.
     *
     * @return Collection<int, User>
     */
    public function billingAudienceFor(Matter $matter): Collection
    {
        $role = $matter->confidentiality === Confidentiality::Restricted ? Role::Admin : Role::Accountant;

        // Không `where('is_active', true)` ở đây: `qualify()` là nơi DUY NHẤT lọc `is_active`, và một
        // bộ lọc thứ hai ở truy vấn chỉ là điều kiện chết (mutation probe: gỡ nó không đổi kết quả).
        $members = User::query()->role($role->value)->orderBy('id')->get();

        return $this->qualify(collect([$matter->leadLawyer])->merge($members), $matter, billing: true);
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
     * M7 Task 6 (R5): MỌI admin đang hoạt động được xem `$matter` — người nhận cảnh báo "hồ sơ quá
     * hạn lưu trữ" (`App\Actions\Schedule\FlagRetentionExpiry`). Quyết định tiêu huỷ là việc của
     * quản trị, không của luật sư phụ trách hay trưởng phòng, nên đây không phải
     * {@see self::supervisorsFor()} (manager cho vụ thường) và không có chuỗi dự phòng: không còn
     * admin nào thì trả rỗng (R7 của M6.5 cấm chính điều đó). Truy vấn chỉ chọn vai trò; ba điều
     * kiện người nhận (`is_active`, chưa xoá mềm, `Gate::view()`) nằm ở MỘT chỗ,
     * {@see self::qualify()}, như mọi danh sách khác — không lọc `is_active` lần thứ hai ở đây.
     *
     * @return Collection<int, User>
     */
    public function activeAdminsFor(Matter $matter): Collection
    {
        return $this->qualify(
            User::query()->role(Role::Admin->value)->orderBy('id')->get(),
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
     * Như {@see self::qualifies()} nhưng với cổng TIỀN của {@see self::forBilling()} (`is_active`, chưa xoá
     * mềm, `ContractPolicy::viewAny` của vụ) — gộp integ-4 mục D: `NotifyLeadLawyerOfContractEntry` hỏi
     * "luật sư phụ trách còn nhận được chuông về tiền của vụ không" để tự chọn người thay, thay vì chuỗi dự
     * phòng một người của `forBilling()`.
     */
    public function qualifiesForBilling(User $user, Matter $matter): bool
    {
        return $this->qualify(collect([$user]), $matter, billing: true)->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>  $mustAllow
     * @return Collection<int, User>
     *
     * **`! $user->trashed()` (fix round 1, minor ruling).** `$preferred` là caller-supplied — một
     * caller có thể truyền một `User` đã nạp bằng `withTrashed()` (ví dụ đi lấy "ai TỪNG là người
     * phụ trách" một mốc hạn), hay một quan hệ không tự áp `SoftDeletingScope`. `is_active` VÀ
     * `deleted_at` là HAI cột khác nhau — không có gì đảm bảo mọi đường xoá một tài khoản luôn đặt
     * `is_active = false` TRƯỚC KHI xoá mềm (R7 nói "vô hiệu hoá VÀ xoá" như hai bước, không phải
     * một bất biến DB). Kiểm tra tường minh ở đây, không tin cột kia làm thay việc của cột này.
     *
     * `$mustAllow`: bộ lọc khả năng của {@see self::handle()} (xem docblock lớp), hỏi SAU cổng vụ —
     * người không xem được vụ không bao giờ tới được câu hỏi về tài liệu của vụ đó.
     */
    private function qualify(Collection $candidates, Matter $matter, bool $billing = false, array $mustAllow = []): Collection
    {
        return $candidates
            ->filter(fn (?User $user): bool => $user instanceof User)
            ->unique(fn (User $user): int|string => $user->getKey())
            ->filter(fn (User $user): bool => $user->is_active)
            ->filter(fn (User $user): bool => ! $user->trashed())
            ->filter(fn (User $user): bool => $billing
                ? Gate::forUser($user)->allows('viewAny', [Contract::class, $matter])
                : Gate::forUser($user)->allows('view', $matter))
            ->filter(fn (User $user): bool => collect($mustAllow)->every(
                fn (mixed $arguments, string $ability): bool => Gate::forUser($user)->allows($ability, $arguments),
            ))
            ->values();
    }

    /**
     * "Không bao giờ im lặng" (R3): người phụ trách → luật sư phụ trách → manager được xem vụ →
     * admin. Trả về ngay khi tìm được MỘT người hợp lệ; tầng "người phụ trách" của SPEC không lặp
     * lại ở đây — xem docblock lớp.
     *
     * `$billing` đổi cổng của MỌI tầng (kể cả luật sư phụ trách) từ "được xem vụ" sang "được xem
     * tiền của vụ" — {@see self::forBilling()}; chuỗi vẫn là một, không có bản thứ hai cho tiền.
     * `$mustAllow` cũng áp vào MỌI tầng (làn fu3, Task 1 mục C): tầng dừng là tầng đầu tiên có người
     * LÀM ĐƯỢC việc, không chỉ xem được vụ.
     *
     * @param  array<string, mixed>  $mustAllow
     * @return Collection<int, User>
     */
    private function fallbackChain(Matter $matter, bool $billing = false, array $mustAllow = [], ?int $exceptId = null): Collection
    {
        // Làn fa2, vòng sửa 1 (minor 7): `$exceptId` (chỉ {@see self::forApprovalRequest()}) bị bỏ ở MỌI tầng TRƯỚC khi chọn —
        // tầng có mỗi người đó thì đi tiếp tầng sau. `null` (mọi nơi gọi khác): không đổi gì.
        $without = fn (Collection $tier): Collection => $exceptId === null ? $tier : $tier->reject(fn (?User $user): bool => $user?->getKey() === $exceptId);

        if ($matter->leadLawyer !== null) {
            $leadLawyerQualified = $this->qualify($without(collect([$matter->leadLawyer])), $matter, $billing, $mustAllow);

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
            $without(User::query()->where('is_active', true)->role(Role::Manager->value)->get()),
            $matter,
            $billing,
            $mustAllow,
        )->first();

        if ($manager !== null) {
            return collect([$manager]);
        }

        $admin = $this->qualify(
            $without(User::query()->where('is_active', true)->role(Role::Admin->value)->get()),
            $matter,
            $billing,
            $mustAllow,
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

    /**
     * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — người nhận thông báo "bạn vừa được giao một lần
     * tiếp nhận": đúng người được giao (`assigned_to`), trừ `$except` (người tự giao cho chính mình).
     * Không lặp lại hai bộ lọc tầng 1 của {@see self::forIntake()}: hàm này chỉ được gọi ngay sau lần
     * lưu vừa ĐẶT người được giao, mà luật nhập liệu (`ValidatesIntakeIdentity`) chỉ nhận người đang
     * hoạt động có `intake.create` — người đó thấy bản ghi được giao cho mình (`isVisibleTo()`). Quan hệ
     * `assignee` bỏ người đã xoá mềm. Không ai thì rỗng — đây không phải cảnh báo cần lưới an toàn.
     *
     * @return Collection<int, User>
     */
    public function forIntakeAssignment(IntakeRequest $intake, ?User $except = null): Collection
    {
        $assignee = $intake->assignee()->first();

        return $assignee instanceof User && ! $assignee->is($except)
            ? collect([$assignee])
            : collect();
    }

    /**
     * Làn kd (2026-10-14) — người nhận thông báo "anh/chị vừa nhận một lead cần tư vấn"
     * (`HandIntakeToLawyer`): đúng luật sư tư vấn (`consulting_lawyer_id`), trừ `$except` (người tự chuyển
     * cho chính mình), và chỉ khi người đó đang hoạt động, chưa xoá mềm (quan hệ bỏ dòng đã xoá) và xem
     * được bản ghi (`IntakeRequestPolicy::view`) — Action vừa kiểm `canReceive()` dưới khoá, đây là lần
     * hỏi lại của lớp người nhận, cùng bộ lọc tầng 1 của {@see self::forIntake()}. Không ai thì rỗng.
     *
     * @return Collection<int, User>
     */
    public function forIntakeHandoff(IntakeRequest $intake, ?User $except = null): Collection
    {
        $lawyer = $intake->consultingLawyer()->first();

        return $lawyer instanceof User
            && ! $lawyer->is($except)
            && $lawyer->is_active
            && Gate::forUser($lawyer)->allows('view', $intake)
                ? collect([$lawyer])
                : collect();
    }

    /**
     * Làn fa (kiểm tra nghiệp vụ 2026-10-09, mục nhỏ) — người nhận thông báo "một lần tiếp nhận vừa ra Đỏ
     * chờ xử lý": mọi trưởng phòng/quản trị (`ConflictOverride` — đúng những vai `IntakeRequestPolicy::
     * resolveConflict` cho xử lý Đỏ) đang hoạt động, chưa xoá mềm. Không hỏi thêm "xem được bản ghi":
     * hàm này chỉ chạy khi một bản ghi CÒN MỞ vừa ra Đỏ, và `intake.viewAny` của hai vai này chỉ không
     * thấy những bản đã chuyển đổi/gộp vào một vụ `restricted` (`IntakeRequest::scopeVisibleTo()`). Trước
     * bản sửa không ai được báo: người được giao (nếu có) không xử lý được Đỏ, còn quản lý chỉ thấy khi
     * tự mở bộ lọc. Bản ghi được truyền vào để một luật hẹp hơn về sau có chỗ đọc.
     *
     * @return Collection<int, User>
     */
    public function forIntakeConflictResolution(IntakeRequest $intake): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->role([Role::Manager->value, Role::Admin->value])
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * Người nhận thông báo về việc dùng trợ lý AI của một NHÂN SỰ (M11 R8, Task 8 — cảnh báo "đọc quá
     * 200 bản ghi một giờ", `App\Actions\Mcp\AlertOnMcpReadVolume`): mọi người có quyền
     * `settings.manage` — chính những người bật, tắt và thu hồi được truy cập AI của nhân sự (R2) —
     * đang hoạt động, chưa xoá mềm (`User::query()` bỏ dòng đã xoá).
     *
     * Không gắn với vụ việc nào, nên không có cổng `view` hay chuỗi dự phòng của {@see self::handle()};
     * thông báo cũng không mang dữ liệu vụ việc nào (chỉ tên nhân sự và ngưỡng). Luôn có người: vai
     * admin mang mọi quyền, và M6.5 R7 cấm vô hiệu hoá admin đang hoạt động cuối cùng.
     *
     * @return Collection<int, User>
     */
    public function forAiOversight(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->permission(Permission::SettingsManage->value)
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * Làn tk, Task 1 — người nhận chuông/thư về MỘT VIỆC GIAO NỘI BỘ (`App\Jobs\SendTaskNotice`): đúng
     * những người trong `$preferred` (người nhận việc, hay người giao) đang hoạt động, chưa xoá mềm, VÀ
     * còn xem được chính việc đó (`TaskPolicy::view` — gồm "xem được vụ" của `MatterPolicy::view`, nên
     * một việc của vụ `restricted` không bao giờ tới ai ngoài luật sư phụ trách và admin).
     *
     * KHÔNG có chuỗi dự phòng của {@see self::handle()}: lời báo này nói với ĐÚNG một người về việc của
     * chính họ ("anh/chị được giao", "việc anh/chị giao đã xong") — gửi nó cho luật sư phụ trách hay một
     * quản lý thay người đã nghỉ là gửi sai người. Việc của người đã nghỉ hiện ở danh sách "Công việc"
     * của quản lý, nơi họ giao lại. Rỗng là câu trả lời đúng khi người đó không còn hợp lệ.
     *
     * @param  array<int, User|null>  $preferred
     * @return Collection<int, User>
     */
    public function forTask(Task $task, array $preferred): Collection
    {
        return collect($preferred)
            ->filter(fn (?User $user): bool => $user instanceof User)
            ->unique(fn (User $user): int|string => $user->getKey())
            ->filter(fn (User $user): bool => $user->is_active && ! $user->trashed())
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $task))
            ->values();
    }

    /**
     * Làn tk, Task 1, vòng sửa 1 (I1) — người nhận chuông "việc anh/chị đang giữ đã được giao cho người
     * khác" (`App\Notifications\Staff\TaskAlert::REASSIGNED_AWAY`): đúng những người trong `$preferred`
     * (người VỪA giữ việc) đang hoạt động và chưa xoá mềm.
     *
     * KHÔNG hỏi `TaskPolicy::view` như {@see self::forTask()}: sau khi giao lại, chỉ người nhận HIỆN TẠI xem
     * được việc, nên cổng đó luôn bỏ người cũ — đúng người cần được báo nhất. Chuông này vì vậy không mang
     * gì của việc (không tiêu đề, không mã vụ, không lý do): chỉ tên người giao lại, nên không cần "còn
     * xem được vụ". Không chuỗi dự phòng, cùng lý do của `forTask()`.
     *
     * @param  array<int, User|null>  $preferred
     * @return Collection<int, User>
     */
    public function forFormerTaskHolder(array $preferred): Collection
    {
        return collect($preferred)
            ->filter(fn (?User $user): bool => $user instanceof User)
            ->unique(fn (User $user): int|string => $user->getKey())
            ->filter(fn (User $user): bool => $user->is_active && ! $user->trashed())
            ->values();
    }

    /**
     * Làn tk, Task 2 — người nhận lời báo CẦN NGƯỜI GIAO LÀM GÌ ĐÓ về một việc: việc bị trả lại
     * (`staff.task.declined`), việc chưa ai nhận quá lâu, việc quá hạn, việc sắp đến hạn mà người nhận không còn
     * làm được (lịch `RemindPendingWork`). Người giao
     * nếu còn hợp lệ ({@see self::forTask()}); không thì — việc của một vụ — luật sư phụ trách HIỆN TẠI của vụ
     * (qua cùng `forTask()`), khi họ không phải chính người đang giữ việc; còn không nữa thì người quản lý
     * của vụ ({@see self::supervisorsFor()}, gộp làn tk 2026-10-16). Lý do: người giao rời vụ (bàn giao
     * sang người khác), nghỉ việc, hay bị vô hiệu — lời báo cần một người quyết định, và luật sư phụ trách
     * thấy mọi việc của vụ mình (`TaskPolicy::view`) và giao lại được (`TaskPolicy::reassign`).
     *
     * Tin cho người giao (làn fa1, phán quyết 7; vòng sửa 1 — rà soát Task 1, m4 + m5): từ làn fa1 các lời báo
     * "đã nhận", "đã hoàn thành", "người nhận tự mở lại" (`TaskAlert::INFO_ONLY_KINDS`) cũng qua đây — để luật sư
     * phụ trách nhận thay người giao đã đi — với `$decisionNeeded = false`: chúng chỉ là TIN, không cần ai quyết
     * định gì, nên dừng TRƯỚC bước người quản lý của vụ. Không thì khi luật sư phụ trách chính là người giữ việc,
     * mọi trưởng phòng (hay quản trị viên với vụ hạn chế) nhận một chuông + thư cho mỗi lần nhận/hoàn thành, với câu
     * "hồ sơ anh/chị phụ trách" sai với họ. Lời báo cần người quyết định (bị trả lại, chưa được nhận, sắp đến hạn mà
     * không còn người làm, quá hạn) giữ cả ba bước (`$decisionNeeded = true`, mặc định).
     *
     * Việc chung không có luật sư phụ trách. Kiểm toán 2026-10-21 (A7): người giao không còn hợp lệ và lời báo CẦN người
     * quyết định ("bị trả lại", chưa được nhận, sắp đến hạn mà không còn người làm, quá hạn) thì rơi tới trưởng phòng đang
     * hoạt động — không còn ai thì quản trị viên ({@see self::generalTaskSupervisors()}) — qua cùng cổng `forTask()`. Luật
     * cũ "rỗng" được viết khi chỉ quản lý/quản trị giao việc chung (người giao đi thì người nhận lời báo cũng chính là họ);
     * từ làn fa1 mọi nhân sự có `matter.view` giao được việc chung, nên rỗng là để lời báo rơi vào khoảng không. Tin
     * (`$decisionNeeded = false`) vẫn rỗng. Việc chung RIÊNG (người giao = người giữ) của người còn làm được việc vẫn rỗng
     * (người giữ tự quyết, như việc riêng của hồ sơ); người giữ đó đã nghỉ / bị khoá thì không còn ai quyết — trưởng phòng.
     *
     * Người nhận thay KHÔNG phải người giao (vòng sửa 1 của Task 2, I1): người gửi so người nhận với
     * `assigned_by` và dùng câu chữ "do :assigner giao" ({@see self::assignerName()}), đường dẫn về tab
     * "Công việc" của vụ — không "việc anh/chị giao", không tab "Tôi đã giao". Bàn giao vụ không đi đường này:
     * `MoveTasksOnHandover` đổi người giao sang lead mới, nên lead mới LÀ người giao.
     *
     * Người giao đang tự giữ việc (gộp integ-fa: họ nhận thay việc mình đã giao — `ReassignTask` `takeOver` — hay việc
     * được "Giao lại" về chính họ): lời báo cho người giao là lời báo về việc CỦA CHÍNH họ, nên bước người giao bị bỏ
     * và chuỗi đi tiếp như khi người giao đã đi — luật sư phụ trách (khi không phải người giữ). Gộp integ-fa (rà soát chéo
     * 2026-10-18, mục 3): việc riêng KHÔNG BAO GIỜ rơi tới người quản lý của vụ, kể cả lời báo cần người quyết định —
     * người giữ chính là người quyết định việc của mình (họ "Giao lại" hay "Huỷ" bằng quyền người giao), và một việc riêng
     * của luật sư phụ trách (bàn giao vụ biến việc lead cũ tự giữ, và việc lead cũ đã giao cho lead mới, thành việc riêng
     * của lead mới — `MoveTasksOnHandover`) không được thành một chuông + thư cho mọi trưởng phòng. Người giữ việc riêng
     * không làm được việc nữa (nghỉ, mất ghế): việc hiện "Cần giao lại" (`Task::scopeNeedsReassignment()`). Việc chung
     * thì rỗng.
     *
     * @param  bool  $decisionNeeded  `false` cho một lời báo chỉ là tin: không rơi tới người quản lý của vụ (xem trên)
     * @return Collection<int, User>
     */
    public function forTaskAssigner(Task $task, bool $decisionNeeded = true): Collection
    {
        $own = $task->assigned_by === $task->assignee_id;
        $assigner = $own ? collect() : $this->forTask($task, [$task->assigner]);

        if ($assigner->isNotEmpty()) {
            return $assigner;
        }

        // Kiểm toán 2026-10-21 (A7) — xem docblock.
        if ($task->matter_id === null) {
            $holder = $own ? User::query()->withTrashed()->find($task->assignee_id) : null;

            if (! $decisionNeeded || ($holder !== null && $holder->is_active && ! $holder->trashed())) {
                return collect();
            }

            return $this->forTask($task, $this->generalTaskSupervisors()
                ->reject(fn (User $user): bool => $user->getKey() === $task->assignee_id)
                ->all());
        }

        $matter = $task->matter;
        $lead = $matter?->leadLawyer;

        if ($lead !== null && $lead->getKey() !== $task->assignee_id) {
            $standIn = $this->forTask($task, [$lead]);

            if ($standIn->isNotEmpty()) {
                return $standIn;
            }
        }

        // Gộp làn tk (2026-10-16): không còn người giao, và luật sư phụ trách chính là người đang giữ (trả lại)
        // việc hay không còn hợp lệ — người quản lý của vụ ({@see self::supervisorsFor()}: trưởng phòng; quản trị
        // viên với vụ hạn chế) nhận lời báo, qua cùng cổng `forTask()`. Không ai quyết định thì việc nằm im. Lời báo
        // chỉ là tin (`$decisionNeeded = false` — làn fa1, vòng sửa 1, m5) thì dừng ở đây; việc riêng cũng vậy (gộp
        // integ-fa, rà soát chéo mục 3 — docblock).
        if ($matter === null || ! $decisionNeeded || $own) {
            return collect();
        }

        return $this->forTask($task, $this->supervisorsFor($matter)
            ->reject(fn (User $user): bool => $user->getKey() === $task->assignee_id)
            ->all());
    }

    /**
     * Người quyết định thay cho một việc CHUNG mà người giao không còn hợp lệ (kiểm toán 2026-10-21, A7 —
     * {@see self::forTaskAssigner()}): mọi trưởng phòng đang hoạt động, chưa xoá — cùng vai `supervisorsFor()` chọn cho
     * một vụ thường; không còn trưởng phòng nào thì mọi quản trị viên đang hoạt động (luôn có — M6.5 R7).
     *
     * @return Collection<int, User>
     */
    private function generalTaskSupervisors(): Collection
    {
        $active = fn (Role $role): Collection => User::query()->where('is_active', true)->role($role->value)->orderBy('id')->get();
        $managers = $active(Role::Manager);

        return $managers->isNotEmpty() ? $managers : $active(Role::Admin);
    }

    /**
     * Tên người giao của `$task` cho lời báo của người nhận thay ({@see self::forTaskAssigner()}) — kể cả
     * người đã xoá mềm (quan hệ `assigner` bỏ họ). Chỉ tên một đồng nghiệp: không dữ liệu hồ sơ.
     */
    public function assignerName(Task $task): string
    {
        return (string) User::query()->withTrashed()->whereKey($task->assigned_by)->value('name');
    }

    /**
     * Người nhận thư thử của nút "Gửi thư thử" (làn st, Cài đặt › Thư điện tử): ĐÚNG người đã bấm, và
     * chỉ khi LÚC GỬI họ vẫn đang hoạt động, chưa xoá mềm, còn `settings.manage` — người bị hạ quyền
     * hay vô hiệu hoá giữa lúc bấm và lúc job chạy không nhận gì. Không gắn với vụ việc nào (thư không
     * mang dữ liệu hồ sơ), nên không có cổng `view` hay chuỗi dự phòng: không ai thay người bấm.
     *
     * @return Collection<int, User> rỗng hoặc đúng một người
     */
    public function forTestMail(int $userId): Collection
    {
        return User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows(Permission::SettingsManage->value))
            ->values();
    }

    /**
     * Người DUYỆT của một yêu cầu duyệt do `$submitterId` gửi (làn fa2, vòng sửa 1, minor 7 — `SendDocumentReviewNotice`):
     * {@see self::handle()} với người trình không bao giờ có mặt trong kết quả.
     *
     *  - Danh sách ưu tiên (luật sư phụ trách) còn hợp lệ: đó là người duyệt; người trình bị bỏ SAU khi chọn — luật sư
     *    phụ trách tự trình văn bản của mình thì chính họ duyệt, không ai bị báo.
     *  - Không ai trong danh sách ưu tiên hợp lệ: chuỗi dự phòng, người trình bị bỏ ở MỌI tầng TRƯỚC khi chọn — tầng chỉ
     *    có mỗi họ (trưởng phòng duy nhất tự trình) thì đi tiếp tầng sau (quản trị viên duyệt được). Bỏ họ SAU chuỗi
     *    dự phòng thì chuỗi dừng ở tầng của họ rồi mới bị bỏ — không ai nhận.
     *
     * @param  array<int, User|null>  $preferred
     * @param  array<string, mixed>  $mustAllow
     * @return Collection<int, User>
     */
    public function forApprovalRequest(Matter $matter, array $preferred, array $mustAllow, ?int $submitterId): Collection
    {
        $qualified = $this->qualify(collect($preferred), $matter, mustAllow: $mustAllow);

        if ($qualified->isNotEmpty()) {
            return $qualified->reject(fn (User $user): bool => $user->getKey() === $submitterId)->values();
        }

        return $this->fallbackChain($matter, mustAllow: $mustAllow, exceptId: $submitterId);
    }
}
