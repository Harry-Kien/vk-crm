<?php

namespace App\Policies;

use App\Actions\Matter\RecordMatterDestruction;
use App\Actions\Matter\UpdateMatterDetails;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Policies\Concerns\ChecksPortalVisibility;
use App\Support\Scopes\ClientPortalScope;

class MatterPolicy
{
    use ChecksPortalVisibility;

    public function viewAny(User|ClientUser $user): bool
    {
        if ($user instanceof ClientUser) {
            return true;
        }

        return $user->can(Permission::MatterViewAny->value) || $user->can(Permission::MatterView->value);
    }

    /**
     * Nhánh khách nói lại cùng một luật HAI LẦN, bằng hai thứ ngôn ngữ khác nhau — đúng thiết bị
     * mà `Document` đã có từ M4 (`isReleasedToPortal()` đứng cạnh `visibleToPortal()`), và là
     * thứ `Matter` thiếu cho tới M5 Task 2.
     *
     * Vì sao cần: một nhánh policy chỉ gồm "chạy lại tầng truy vấn" KHÔNG phải một tầng riêng —
     * nó sụp xuống thành chính tầng kia, và một câu `where` bị quên trong
     * `Matter::applyClientPortalConstraints()` là một vụ rò rỉ toàn phần, không phải một vụ rò
     * rỉ một phần. Nghi thức ba tầng của M5 (thay global scope bằng một scope rỗng rồi hỏi lại
     * policy) đo đúng điều này, và `Matter` là gốc: mọi model con hỏi lại qua
     * `ChecksMatterAccess::canSeeMatter()`, nên hai câu dưới đây bảo vệ cả bảy khối của SPEC
     * §8.3 chứ không riêng trang hồ sơ. Có test ở `PortalIsolationSweepTest`.
     *
     * Năm điều kiện ở {@see self::releasedToPortal()} (M6.5 Task 2 vòng sửa 1 thêm điều kiện thứ tư
     * — khách hàng chưa xoá mềm; M7 Task 5 thêm điều kiện thứ năm — khách chưa hết hạn tra cứu) là
     * đúng năm điều kiện của `Matter::applyClientPortalConstraints()`, phát biểu lại bằng thuộc tính
     * thay vì bằng `where`. Chúng KHÔNG chung một câu lệnh nào với chuỗi `where` kia: đó là toàn bộ
     * giá trị của việc viết lại, và cũng là lý do không được rút gọn thành một lần gọi lẫn nhau.
     */
    public function view(User|ClientUser $user, Matter $matter): bool
    {
        if ($user instanceof ClientUser) {
            return $this->releasedToPortal($matter, $user) && $this->visibleToPortal($user, $matter);
        }

        if (! $user->can(Permission::MatterView->value)) {
            return false;
        }

        // Đường trong bộ nhớ khi `team` đã nạp (ví dụ danh sách Filament eager-load nó): tránh
        // chạy một EXISTS cho mỗi dòng. Ngược lại giữ nguyên truy vấn cũ (cũng cho phép vụ đã
        // xoá mềm, để admin còn thao tác được).
        //
        // `withoutGlobalScope(ClientPortalScope::class)` là điều kiện để hai đường trên CÙNG trả
        // một câu trả lời. Không có nó, câu hỏi "nhân sự này có thấy vụ việc kia không" đổi đáp
        // án theo việc có ai đang mở phiên portal hay không: `ClientPortalScope::isActive()` bật
        // khi guard `client` đã xác thực và guard `web` thì chưa — tức chính xác một lời gọi
        // `Gate::forUser($staff)` từ một job, một lệnh console hay một Action bên trong một
        // request portal. Ở đó `Matter::query()` bị cắt theo KHÁCH đang đăng nhập, nên EXISTS
        // trả `false` về một vụ việc nhân sự đó thấy rõ, còn đường trong bộ nhớ ngay trên lại
        // trả `true`. Đo được bằng mutation ở `MatterPolicyTest`, và đây là cái chặn hai Action
        // danh mục hồ sơ của M4 tự làm mình độc lập với guard (rà soát M4 Task 4, mang sang M5).
        //
        // Không nới gì cho khách: nhánh `ClientUser` đã `return` ở trên, và nó hỏi lại scope
        // thật qua `ClientPortalScope::actingAs()`.
        return $matter->relationLoaded('team')
            ? $matter->isListableBy($user)
            : Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->listableBy($user)
                ->whereKey($matter->getKey())
                ->exists();
    }

    /**
     * KHÔNG phải một lần kiểm tra quyền đầy đủ — đừng gọi riêng. Nó chỉ đọc thẳng thuộc tính
     * trên bản ghi để trả lời "hồ sơ này có đang ở trên cổng của chính khách hàng này không",
     * và nó tồn tại để đứng CẠNH `visibleToPortal()`, không để thay. Xem docblock `view()`.
     *
     * Ép kiểu số ở hai vế: `client_id` không nằm trong `casts()` của cả hai model, nên một model
     * chưa đi qua cơ sở dữ liệu có thể còn giữ chuỗi từ request — và `===` giữa `'7'` và `7` sẽ
     * âm thầm từ chối một khách hàng hợp lệ. So lỏng (`==`) thì đi quá xa theo chiều ngược lại.
     *
     * Điều kiện thứ tư: M6.5 Task 2, vòng sửa 1 (Important #2) thêm "khách hàng (`Client`) chưa
     * xoá mềm", đúng điều kiện thứ tư mà `Matter::applyClientPortalConstraints()` mang từ Task 2
     * vòng đầu (`portal/portal-3`). Trước bản sửa đó, hàm chỉ lặp lại BA điều
     * kiện của `applyClientPortalConstraints()`, nên lời hứa ở đoạn docblock của `view()` ("Ba
     * điều kiện ở đây là đúng ba điều kiện của `applyClientPortalConstraints()`") đã SAI kể từ
     * khi vòng đầu thêm `whereHas('client')` — một khách hàng đã xoá mềm vẫn `view($matter)` =
     * `true` qua nhánh policy này, dù tầng truy vấn đã đóng cửa.
     *
     * **Đường trong bộ nhớ khi `client` đã nạp — CÙNG hình dạng nhánh `team` của `view()` staff
     * ở trên, và bắt buộc, không phải tối ưu tuỳ chọn.** Hai màn hình duyệt nhiều hồ sơ một lúc
     * (`MyMatters::buildCards()`, mỗi thẻ một lần hỏi `Gate`; `SubmitDocument::choosableItems()`,
     * mỗi đầu mục một lần hỏi `Gate` trên CÙNG một `$matter`) đã có ngân sách truy vấn đo được
     * (`MyMattersTest`, `SubmitDocumentTest`) từ trước vòng sửa này — một truy vấn MỚI cho MỖI
     * bản ghi/đầu mục phá ngân sách đó ngay (đo được: bỏ nhánh `relationLoaded` thì hai test trên
     * đỏ). Hai nơi gọi ấy giờ `->with('client')` cùng lúc với các quan hệ khác chúng đã nạp sẵn
     * (`MyMatters::buildCards()`, `SubmitDocument::resolveMatter()` — `$matter` dùng chung cho
     * mọi đầu mục qua `setRelation('matter', ...)`), nên nhánh này chạy MIỄN PHÍ ở đúng hai chỗ
     * cần nó rẻ.
     *
     * Nhánh dự phòng (`client()->exists()`) vẫn còn cho MỌI nơi gọi khác chưa nạp sẵn — không
     * đánh đổi đúng/sai lấy tốc độ, chỉ đánh đổi Ở NHỮNG NƠI đã chủ động trả giá bằng một
     * `with()`. `->withoutGlobalScope(ClientPortalScope::class)` trên truy vấn dự phòng đó — bắt
     * buộc, KHÔNG phải trang trí: `Client` mang `RestrictedToClientPortal` (xem `Client.php`,
     * `ClientUser.php` dòng ~76), nên `$matter->client()` KHÔNG PHẢI một truy vấn "sạch" — nó tự
     * cắt theo khách nào đang có PHIÊN CỔNG đang mở (`ClientPortalScope::isActive()` đọc
     * `auth('client')->check()` — trạng thái AMBIENT, không phải `$clientUser` tham số của hàm
     * này). Không có dòng `withoutGlobalScope`, một Action gọi `Gate::forUser($actor)` trong khi
     * MỘT PHIÊN CỔNG KHÁC đang mở (ví dụ `RecordStageLogView`/`ReplyToClientRequest` nhận actor
     * qua tham số, không qua session) khiến `exists()` lọc theo khách của phiên lạ đó — gần như
     * luôn `false` cho một vụ việc của $clientUser thật — và từ chối oan một actor hợp lệ. Đo
     * được: ba test viết cho đúng tình huống này ("còn phiên khác đang mở") đỏ ngay khi thiếu
     * dòng `withoutGlobalScope` — xem `ReplyToClientRequestTest`, `SubmitClientDocumentTest`,
     * `RecordStageLogViewTest`.
     *
     * **Năm điều kiện, KHÔNG còn bốn — M7 Task 5 (R4) thêm "khách chưa hết hạn tra cứu"**, đúng
     * điều kiện thứ năm của `Matter::applyClientPortalConstraints()` (`whereDoesntHave('archive', …
     * clientAccessExpired())`). Ở đây nó được nói lại bằng NGÀY trên thuộc tính
     * (`MatterArchive::isClientAccessExpired()`), không gọi lại scope — xem {@see
     * self::clientAccessExpired()}. Hai tầng có hai test riêng, mỗi test làm thủng tầng kia
     * (`ClientAccessExpiryTest`).
     */
    private function releasedToPortal(Matter $matter, ClientUser $clientUser): bool
    {
        return (int) $matter->client_id === (int) $clientUser->client_id
            && (bool) $matter->is_published_to_portal
            && ! $matter->trashed()
            && ($matter->relationLoaded('client')
                ? $matter->client !== null
                : $matter->client()->withoutGlobalScope(ClientPortalScope::class)->exists())
            && ! $this->clientAccessExpired($matter);
    }

    /**
     * Điều kiện thứ năm của {@see self::releasedToPortal()} (M7 Task 5, R4): vụ có một dòng lưu trữ
     * chưa xoá mềm mà `client_access_until` đã qua — khách còn xem được HẾT ngày đó.
     *
     * **Đường trong bộ nhớ khi `clientAccessArchive` đã nạp — cùng hình dạng và cùng lý do với
     * nhánh `client` ngay trên.** `MyMatters::buildCards()` (mỗi thẻ một lần hỏi `Gate`) và
     * `SubmitDocument::resolveMatter()` (mỗi đầu mục một lần hỏi `Gate` trên CÙNG một `$matter`)
     * nạp sẵn quan hệ này một lần cho cả trang; không có nhánh này thì mỗi thẻ/đầu mục thêm một truy
     * vấn, đúng thứ `MyMattersTest`/`SubmitDocumentTest` đo.
     *
     * **`clientAccessArchive`, không phải `archive`.** `MatterArchive` mang `ClientPortalScope` chặn
     * sạch (`1 = 0`), nên `archive` nạp dưới phiên khách luôn là `null` — và đọc `null` ở đây là đọc
     * "không hết hạn": tầng policy thủng đúng ở hai màn hình dùng đường trong bộ nhớ. Quan hệ
     * `clientAccessArchive` gỡ scope đó ngay trong định nghĩa (xem docblock ở `Matter`), nên cả
     * đường nạp sẵn lẫn đường dự phòng dưới đây đều đọc đúng bất kể phiên nào đang mở. Một nơi gọi
     * nạp `archive` thay vì `clientAccessArchive` không làm sai câu trả lời — nó chỉ không được
     * đường nhanh, vì nhánh này chỉ tin `clientAccessArchive`.
     */
    private function clientAccessExpired(Matter $matter): bool
    {
        $archive = $matter->relationLoaded('clientAccessArchive')
            ? $matter->clientAccessArchive
            : $matter->clientAccessArchive()->first();

        return $archive?->isClientAccessExpired() ?? false;
    }

    public function create(User|ClientUser $user): bool
    {
        return $user instanceof User && $user->can(Permission::MatterCreate->value);
    }

    public function update(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && ! $matter->trashed()
            && $user->can(Permission::MatterUpdate->value)
            && $this->view($user, $matter);
    }

    public function transitionStage(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && ! $matter->trashed()
            && $user->can(Permission::MatterTransitionStage->value)
            && $this->view($user, $matter);
    }

    /**
     * "Quản lý đội ngũ" (M6.5 Task 3, R6): luật sư phụ trách của CHÍNH vụ việc này, manager được
     * xem vụ, hoặc admin. Trợ lý không có — SPEC §5 không cho họ quyết định ai vào/ra một vụ
     * việc, dù chính họ có thể đang đứng trong đội ngũ đó.
     *
     * **Dựa trên `view()` làm nền, không viết lại luật hiển thị vụ `restricted`.** `view()` đã
     * chỉ cho admin và lead lọt qua ở nhánh `restricted` (xem docblock hàm đó); một manager không
     * phải admin vì vậy tự rớt ở ĐÚNG bước `$this->view()`, trước khi chạm tới ba điều kiện
     * `||` bên dưới — kết quả là "chỉ lead hoặc admin quản lý được đội ngũ của vụ `restricted`"
     * (Review Focus 1, Task 3 brief) mà không cần một nhánh `restricted` riêng ở đây. Viết lại
     * luật đó thành một `if` thứ hai sẽ là đúng hai nơi phải lệch nhau nếu SPEC §4.6 đổi.
     *
     * **Fix round 1, finding S2 — `matter.update` VÀ không phải trợ lý (R5).** R5 nói thẳng:
     * "Đổi confidentiality, quản lý đội ngũ và bàn giao đòi `matter.update` VÀ không phải trợ
     * lý." Bản gốc chỉ hỏi `lead_lawyer_id === $user->getKey()` cho nhánh lead — một luật sư
     * phụ trách bị ĐỔI CHỨC DANH sang trợ lý (`EditUser` → `assignRoleFromPosition()`, hồ sơ
     * `matters.lead_lawyer_id` không tự đổi theo) vẫn còn là "lead" trên vụ việc CŨ và vẫn qua
     * được nhánh đó — trong khi vai `Assistant` CÓ `matter.update` (`Role::Assistant->
     * permissions()`), nên thiếu điều kiện này để lọt qua được cả `$this->view()`. Đặt hai điều
     * kiện này ở TRÊN CÙNG (áp cho cả ba nhánh `||`), không chỉ nhánh lead: `Manager`/`Admin`
     * luôn có `matter.update` và không bao giờ đồng thời là `Assistant` (một người chỉ giữ đúng
     * một vai qua `assignRoleFromPosition()`), nên hai điều kiện mới không đổi gì cho hai nhánh
     * đó — chỉ đóng đúng lỗ hổng của nhánh lead.
     */
    public function manageTeam(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && ! $matter->trashed()
            && $this->view($user, $matter)
            && $user->can(Permission::MatterUpdate->value)
            && ! $user->hasRole(Role::Assistant->value)
            && (
                $matter->lead_lawyer_id === $user->getKey()
                || $user->hasRole(Role::Admin->value)
                || $user->hasRole(Role::Manager->value)
            );
    }

    /**
     * Fix round 1, finding I2 (chốt lại R5): đổi `confidentiality`, MỘT TRONG HAI CHIỀU, chỉ
     * dành cho LUẬT SƯ PHỤ TRÁCH của chính vụ việc này hoặc ADMIN — không còn "matter.update VÀ
     * không phải trợ lý" của bản đầu. Bản đầu đọc R5 theo nghĩa rộng nhất có thể ("mọi việc trừ
     * quyết định đưa gì ra cho khách và cấu trúc vụ việc"), nên để lọt một luật sư cộng sự
     * (associate) — có `matter.update`, không phải trợ lý — đổi được mức bảo mật của một vụ việc
     * họ không phụ trách. Quyết định ai được xem một vụ `restricted` (chỉ lead + admin,
     * `Matter::isListableBy()`) và quyết định AI ĐƯỢC CHUYỂN một vụ vào/ra khỏi trạng thái đó
     * phải là CÙNG một tập người — một trưởng phòng hay một cộng sự đổi được mức bảo mật của một
     * vụ họ không phụ trách, trong khi chính họ (nếu không phải admin) có thể không còn xem được
     * vụ đó SAU lần đổi, là một quyết định không ai chịu trách nhiệm được.
     *
     * `$this->update()` đã gồm `view()` (không cho vụ đã xoá mềm, xem docblock `update()`), nên
     * không cần lặp lại `! $matter->trashed()` ở đây.
     *
     * Luật "chuyển sang restricted khi còn thành viên khác lead/admin thì bị từ chối, kèm danh
     * sách" KHÔNG nằm ở đây — một ability policy chỉ nhận `(User, Matter)`, không nhận GIÁ TRỊ
     * MỚI đang định gán, nên không thể tự phân biệt "giữ nguyên"/"chuyển sang normal" (luôn được)
     * với "chuyển sang restricted" (cần thêm điều kiện đội ngũ). Luật đó nằm trong
     * {@see UpdateMatterDetails}, nơi giá trị MỚI đã có sẵn trong `$data`.
     */
    public function updateConfidentiality(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && $this->update($user, $matter)
            && ($matter->lead_lawyer_id === $user->getKey() || $user->hasRole(Role::Admin->value));
    }

    /**
     * Fix round 1, finding I1: `summary_for_client` là lời văn phòng ĐƯA RA CHO KHÁCH đọc (SPEC
     * §4.6, và SPEC §8.3 khối 1 vẽ nó ra ngay cạnh nhãn giai đoạn) — cùng LOẠI quyết định với công
     * bố một dòng tiến độ cho khách, nên đòi CÙNG quyền `stageLog.publish`, không phải chỉ
     * `matter.update`. Trợ lý có `matter.update` (`Role::Assistant->permissions()`) nhưng không có
     * `stageLog.publish`, nên không đổi được trường này — dù vẫn sửa được bốn trường còn lại của
     * `UpdateMatterDetails`.
     */
    public function updateSummaryForClient(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && $this->update($user, $matter)
            && $user->can(Permission::StageLogPublish->value);
    }

    /**
     * Bật/tắt công tắc "công bố cho khách" của TOÀN vụ việc (SPEC §7.2) — R5 (roles-05, M6.5
     * Task 10): "Các công tắc công bố (cổng của vụ việc, công bố mốc hạn) đòi `stageLog.publish`."
     * Trước bản sửa này, `SetMatterPortalPublication` chỉ hỏi `matter.update`, và trợ lý CÓ quyền
     * đó (`Role::Assistant->permissions()`) nhưng KHÔNG có `stageLog.publish` — nên trợ lý bật
     * được công tắc tổng, đưa CẢ vụ việc (và mọi dòng `stage_logs.is_published = true` đã tích
     * luỹ trong lúc tắt — carry-forward M6, xem docblock `SetMatterPortalPublication`) ra trước
     * mắt khách hàng, hoặc giấu nó đi. Cùng LOẠI quyết định với `updateSummaryForClient()` ở trên:
     * "đưa gì ra cho khách" luôn đòi `stageLog.publish`, dù đối tượng là một dòng tiến độ, cả vụ
     * việc, hay một mốc hạn (xem `DeadlinePolicy::publish()`, cùng luật).
     *
     * **Fix round 1 — ruling (task-10-fix1-findings.md): chỉ CHIỀU BẬT đòi `stageLog.publish`.**
     * Bản đầu đòi quyền đó cho CẢ HAI chiều. Chủ nhiệm chốt lại: BẬT là quyết định ĐƯA MỘT VỤ VIỆC
     * ra trước mắt khách (đúng loại quyết định `stageLog.publish` canh) — nhưng TẮT chỉ RÚT một vụ
     * việc khỏi cổng, tức THU HẸP những gì khách thấy, không phải một quyết định "đưa gì ra cho
     * khách" mới. Một trợ lý phát hiện vụ việc lỡ công bố nhầm (ví dụ do một luật sư khác thao tác
     * sai) phải tự rút được ngay, không phải chờ đúng người có `stageLog.publish` rảnh tay — cùng
     * tinh thần bất đối xứng mà `SetDeadlinePublication`/`DeadlinePolicy::publish()` đã áp dụng cho
     * điều kiện "vụ việc đã bật portal" (chỉ chặn chiều bật, không chặn chiều gỡ).
     *
     * `$publish` là tham số THỨ HAI của ability — truyền qua mảng khi hỏi Gate:
     * `Gate::allows('setPortalPublication', [$matter, $publish])`. KHÔNG có giá trị mặc định: mọi
     * nơi gọi phải tự quyết định rõ chiều đang hỏi là gì, không được suy luận ngầm.
     */
    public function setPortalPublication(User|ClientUser $user, Matter $matter, bool $publish): bool
    {
        return $user instanceof User
            && $this->update($user, $matter)
            && (! $publish || $user->can(Permission::StageLogPublish->value));
    }

    /**
     * "Huỷ hồ sơ mở nhầm" (M6.5 Task 5) — xoá mềm kèm lý do bắt buộc, qua {@see
     * \App\Actions\Matter\CancelMatter}. Cùng luật với {@see self::delete()} (chỉ quản trị), vì
     * đây đúng là hành động đó — cổng riêng chỉ để tên ability khớp đúng tên header action trên
     * `EditMatter` (`HeaderActionsAreReachableTest` đòi tên action trùng tên phương thức policy).
     */
    public function cancelMatter(User|ClientUser $user, Matter $matter): bool
    {
        return $this->delete($user, $matter);
    }

    /** Xoá mềm vụ việc là việc hệ trọng: chỉ quản trị. */
    public function delete(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    public function restore(User|ClientUser $user, Matter $matter): bool
    {
        return $this->delete($user, $matter);
    }

    /**
     * M7 Task 6 (R5): GHI quyết định tiêu huỷ hồ sơ ({@see RecordMatterDestruction})
     * — chỉ quản trị, cùng luật {@see self::delete()}. Không có quyền thứ 14 trong
     * `App\Enums\Permission` (SPEC §5 có đúng 13): vai trò admin là đủ, như xoá mềm vụ việc.
     * Không hỏi thêm `view()`: admin xem được mọi vụ, kể cả `restricted`, nên vế đó không bao giờ
     * đổi kết quả. Ghi quyết định không xoá gì — đây KHÔNG phải {@see self::forceDelete()}.
     */
    public function recordDestruction(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User && $user->hasRole(Role::Admin->value);
    }

    /** Không ai xoá vĩnh viễn được: model cũng chặn (MatterNotDestroyable). */
    public function forceDelete(User|ClientUser $user, Matter $matter): bool
    {
        return false;
    }

    /**
     * Tab "Nhật ký" của riêng vụ việc (SPEC §7.2, M7 Task 8): admin, trưởng phòng — tức đúng
     * những người có `auditLog.view` (SPEC §5), hai người đã đọc được các dòng này ở trang Nhật
     * ký hệ thống — và luật sư phụ trách CỦA VỤ NÀY. Không trợ lý, không cộng sự, không kế toán.
     *
     * **Nền là `view()`**, nên một trưởng phòng không lead một vụ `restricted` rớt ở đó, cùng lý
     * lẽ với {@see self::manageTeam()}; vụ đã xoá mềm vẫn đọc được với người `view()` cho qua
     * (tab chỉ đọc). Luật sư phụ trách so bằng `lead_lawyer_id` ĐANG có trong bản ghi — sau một
     * lần bàn giao, người cũ mất tab ở request kế tiếp dù vẫn còn trong đội ngũ.
     */
    public function viewActivityLog(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && $this->view($user, $matter)
            && ($user->can(Permission::AuditLogView->value)
                || (int) $matter->lead_lawyer_id === (int) $user->getKey());
    }
}
