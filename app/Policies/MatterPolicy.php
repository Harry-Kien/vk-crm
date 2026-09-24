<?php

namespace App\Policies;

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
     * Bốn điều kiện ở {@see self::releasedToPortal()} (Task 2 vòng sửa 1 thêm điều kiện thứ tư —
     * khách hàng chưa xoá mềm) là đúng bốn điều kiện của `Matter::applyClientPortalConstraints()`,
     * phát biểu lại bằng thuộc tính thay vì bằng `where`. Chúng KHÔNG chung một câu lệnh nào với
     * chuỗi `where` kia: đó là toàn bộ giá trị của việc viết lại, và cũng là lý do không được rút
     * gọn thành một lần gọi lẫn nhau.
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
     * Bốn điều kiện ở đây, KHÔNG còn ba: Task 2, vòng sửa 1 (Important #2) thêm "khách hàng
     * (`Client`) chưa xoá mềm", đúng điều kiện thứ tư mà `Matter::applyClientPortalConstraints()`
     * mang từ Task 2 vòng đầu (`portal/portal-3`). Trước bản sửa này, hàm chỉ lặp lại BA điều
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
     */
    private function releasedToPortal(Matter $matter, ClientUser $clientUser): bool
    {
        return (int) $matter->client_id === (int) $clientUser->client_id
            && (bool) $matter->is_published_to_portal
            && ! $matter->trashed()
            && ($matter->relationLoaded('client')
                ? $matter->client !== null
                : $matter->client()->withoutGlobalScope(ClientPortalScope::class)->exists());
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
     */
    public function manageTeam(User|ClientUser $user, Matter $matter): bool
    {
        return $user instanceof User
            && ! $matter->trashed()
            && $this->view($user, $matter)
            && (
                $matter->lead_lawyer_id === $user->getKey()
                || $user->hasRole(Role::Admin->value)
                || $user->hasRole(Role::Manager->value)
            );
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

    /** Không ai xoá vĩnh viễn được: model cũng chặn (MatterNotDestroyable). */
    public function forceDelete(User|ClientUser $user, Matter $matter): bool
    {
        return false;
    }
}
