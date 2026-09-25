<?php

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;

/**
 * Định nghĩa DUY NHẤT của "ai thấy tiền của vụ nào" (M9 P3; SPEC §5, bổ sung 2026-09-19, sửa
 * 2026-09-24): có `billing.view` **và** vụ nằm trong `Matter::listableBy($user)`. Bốn policy tiền
 * (`Contract`, `Instalment`, `Payment`, `ContractAmendment`) đều đi qua đây; màn hình, thư và
 * Action của M9 hỏi các policy đó chứ không viết lại điều kiện này.
 *
 * **Không hỏi `MatterPolicy::view`**, khác `ChecksMatterAccess::canSeeMatter()` của các bản ghi
 * con khác: kế toán không có `matter.view` mà vẫn phải thấy tiền, và `MatterPolicy::view` đòi
 * `matter.view` trước mọi thứ. Tiền là một trục riêng với nội dung hồ sơ.
 *
 * Hệ quả, không cần viết thêm dòng nào: vụ `restricted` chỉ luật sư phụ trách (còn `matter.view`)
 * và admin thấy tiền, vì đó chính là nhánh `restricted` của `isListableBy()`. Kế toán và quản lý
 * rớt ở đúng nhánh đó.
 */
trait ChecksBillingAccess
{
    /**
     * Bốn điều kiện, theo thứ tự:
     *
     * - **Nhân sự**, không phải khách. Nhánh khách trả `false` tường minh: cổng khách mở tiền có
     *   chủ đích ở M9 Task 10 (P1), không phải ở đây.
     * - **Vụ còn đó.** `$contract->matter` trả `null` khi vụ đã xoá mềm (`SoftDeletingScope`), và
     *   `isListableBy()` không hỏi `deleted_at` — nên điều kiện `trashed()` là thứ giữ câu trả lời
     *   trùng với `Matter::query()->listableBy($user)`, vốn loại vụ xoá mềm, kể cả khi nơi gọi đã
     *   nạp sẵn vụ bằng `withTrashed()`. Các trang tiền dựng trên `listableBy` ở tầng truy vấn
     *   (kế hoạch M9 Task 8, 9) vì vậy thấy đúng tập vụ mà policy này cho mở.
     * - **`billing.view`.**
     * - **`isListableBy()`** — bản trong bộ nhớ của `scopeListableBy()`, cùng ba nhánh. Có test
     *   khẳng định hai bản cho cùng một đáp án, với cả bảy tài khoản mẫu (năm vai trò, lead, thành
     *   viên, người ngoài) trên một vụ thường và một vụ `restricted` (`BillingAccessTest`, "answers
     *   the money question in memory exactly as the listable query does").
     */
    protected function canSeeBilling(User|ClientUser $user, ?Matter $matter): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $matter !== null
            && ! $matter->trashed()
            && $user->can(Permission::BillingView->value)
            && $matter->isListableBy($user);
    }

    /**
     * `viewAny` của cả bốn policy tiền, cùng một thân.
     *
     * - **Không ngữ cảnh** — câu hỏi giao diện "người này có màn hình tiền nào không": chỉ
     *   `billing.view`. Nó không mở tiền của vụ nào; mọi bản ghi vẫn qua `view()`.
     * - **Có ngữ cảnh vụ việc** (`Gate::allows('viewAny', [Contract::class, $matter])`) — câu hỏi
     *   "người này có thấy tiền của VỤ NÀY không", kể cả khi vụ chưa có hợp đồng. Đây là câu tab
     *   tiền của vụ và danh sách người nhận thư về tiền phải hỏi.
     * - **Ngữ cảnh không phải `Matter`** — từ chối, KHÔNG rơi về nhánh không ngữ cảnh: một câu hỏi
     *   có ngữ cảnh mà ngữ cảnh sai vẫn là một câu hỏi có ngữ cảnh (cùng lý lẽ với
     *   `DocumentPolicy::create`). Kiểu `mixed` để một lần gọi sai là "không", không phải
     *   `TypeError` thành trang 500.
     */
    protected function canListBilling(User|ClientUser $user, mixed $context): bool
    {
        if ($context === null) {
            return $user instanceof User && $user->can(Permission::BillingView->value);
        }

        return $this->canSeeBilling($user, $context instanceof Matter ? $context : null);
    }
}
