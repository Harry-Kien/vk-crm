<?php

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\ClientUser;
use App\Models\Matter;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;

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
     * Ba cột của hàng `matters` mà câu trả lời đọc thẳng trong bộ nhớ: `confidentiality` và
     * `lead_lawyer_id` (hai nhánh của `isListableBy()`), `deleted_at` (`trashed()`). Xem
     * {@see self::matterForBillingGate()}.
     */
    private const BILLING_GATE_COLUMNS = ['confidentiality', 'lead_lawyer_id', 'deleted_at'];

    /**
     * Năm điều kiện, theo thứ tự:
     *
     * - **Nhân sự**, không phải khách. Nhánh khách trả `false` tường minh: cổng khách mở tiền có
     *   chủ đích ở M9 Task 10 (P1), không phải ở đây.
     * - **Vụ đủ cột** — {@see self::matterForBillingGate()} (fix round 1, I1). Thiếu cột thì nạp lại.
     * - **Vụ còn đó.** `$contract->matter` trả `null` khi vụ đã xoá mềm (`SoftDeletingScope`), và
     *   `isListableBy()` không hỏi `deleted_at` — nên điều kiện `trashed()` là thứ giữ câu trả lời
     *   trùng với `Matter::query()->listableBy($user)`, vốn loại vụ xoá mềm, kể cả khi nơi gọi đã
     *   nạp sẵn vụ bằng `withTrashed()`. Các trang tiền dựng trên `listableBy` ở tầng truy vấn
     *   (kế hoạch M9 Task 8, 9) vì vậy thấy đúng tập vụ mà policy này cho mở.
     * - **`billing.view`.**
     * - **`isListableBy()`** — bản trong bộ nhớ của `scopeListableBy()`, cùng ba nhánh. Có test
     *   khẳng định hai bản cho cùng một đáp án, với cả bảy tài khoản mẫu (năm vai trò, lead, thành
     *   viên, người ngoài) trên một vụ thường và một vụ `restricted` (`BillingAccessTest`, "answers
     *   the money question in memory exactly as the listable query does"). Lời khẳng định đó chỉ
     *   đúng khi vụ có đủ ba cột ở trên — điều kiện thứ hai là thứ bảo đảm nó.
     */
    protected function canSeeBilling(User|ClientUser $user, ?Matter $matter): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $matter = $this->matterForBillingGate($matter);

        return $matter !== null
            && ! $matter->trashed()
            && $user->can(Permission::BillingView->value)
            && $matter->isListableBy($user);
    }

    /**
     * Vụ việc mà cổng tiền được phép đọc trong bộ nhớ: chính `$matter` khi nó mang đủ
     * {@see self::BILLING_GATE_COLUMNS}, ngược lại là một bản NẠP LẠI từ cơ sở dữ liệu.
     *
     * **Vì sao cần** (rà soát Task 3, I1). `isListableBy()` và `trashed()` tin thuộc tính trong bộ
     * nhớ. Một vụ nạp thiếu cột — `with('matter:id,code,lead_lawyer_id')`, đúng kiểu nạp mà test
     * đếm truy vấn của trang "Công nợ"/tab tiền sẽ đẩy người viết tới — có `confidentiality` là
     * `null`, nên rơi vào nhánh vụ THƯỜNG, nơi mọi người có `matter.viewAny` (kế toán, quản lý) đều
     * qua: cổng MỞ trên vụ `restricted`. Thiếu `deleted_at` thì `trashed()` nói "chưa xoá". Bản SQL
     * (`scopeListableBy`) đóng trên `NULL`; bản trong bộ nhớ thì không. `Matter.php` không sửa ở
     * đây (tệp của phiên khác); chốt chặn nằm ở cổng tiền.
     *
     * **Nạp lại, không đóng cửa — và vì sao.** Đóng cửa (trả "không" khi thiếu cột) chặn được lỗ
     * hổng, nhưng cũng trả "không" SAI cho luật sư phụ trách và admin trên chính vụ `restricted`
     * của họ: nút ghi khoản thu biến mất không một lời, đúng ở những màn hình đã tối ưu truy vấn,
     * và không ai biết vì sao. Nạp lại cho MỌI người đáp án đúng; cái giá là một truy vấn, chỉ ở
     * đường nạp thiếu. Đường nạp đủ cột (mặc định `select *`) không tốn gì thêm. Một mô hình vừa
     * `create()` cũng đi đường nạp lại, vì `deleted_at` chưa bao giờ được gán vào thuộc tính.
     *
     * Nạp lại KHÔNG kèm `withTrashed()`: vụ đã xoá mềm không trở về, ra `null` và bị từ chối — cùng
     * đáp án với đường quan hệ lười `$contract->matter`; thêm `withTrashed()` chỉ để `trashed()` từ
     * chối thay thì không đổi đáp án nào, nên không viết. Nạp lại kèm
     * `withoutGlobalScope(ClientPortalScope::class)` để câu trả lời về NHÂN SỰ không đổi theo một
     * phiên cổng khách đang mở song song (cùng lý do với đường truy vấn của `MatterPolicy::view`;
     * có test riêng). Không có khoá chính thì không nạp lại được → `null` → từ chối.
     *
     * Chỉ ba cột: ngoài khoá chính, `isListableBy()` và `trashed()` không đọc cột nào khác của
     * `matters`. Khoá chính không cần canh: nạp quan hệ `BelongsTo` mà thiếu `id` thì quan hệ ra
     * `null` (đã bị từ chối); một `Matter` đưa thẳng vào mà thiếu `id` thì phép thử đội ngũ của
     * `isListableBy()` tìm theo khoá `null` — đóng, không mở.
     *
     * Nơi nào đọc thêm một thuộc tính của vụ SAU cổng này (như `confidentiality` ở
     * `PaymentPolicy::canRecordPaymentOn()`) phải đọc trên vụ trả về từ đây, không trên vụ nơi gọi
     * đưa vào.
     */
    protected function matterForBillingGate(?Matter $matter): ?Matter
    {
        if ($matter === null) {
            return null;
        }

        $attributes = $matter->getAttributes();

        foreach (self::BILLING_GATE_COLUMNS as $column) {
            if (! array_key_exists($column, $attributes)) {
                return $matter->getKey() === null ? null : Matter::query()
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->find($matter->getKey());
            }
        }

        return $matter;
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
