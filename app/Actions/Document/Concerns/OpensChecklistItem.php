<?php

namespace App\Actions\Document\Concerns;

use App\Actions\Concerns\ChecksAccountActive;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Exceptions\MatterChecklistReadOnly;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\Gate;

/**
 * Mở một đầu mục danh mục ra để văn phòng thao tác lên nó, hoặc từ chối — dùng chung cho
 * `ReviewChecklistItem` (SPEC §6.7) và `MarkChecklistItemNotApplicable` (SPEC §4.10).
 *
 * **Vì sao nó là một trait chứ không phải hai đoạn mã giống nhau.** Thứ nằm ở đây là SPEC §10.10
 * áp cho danh mục hồ sơ: ba tình huống "không mở được dòng này" phải ra đúng MỘT lớp exception,
 * MỘT câu chữ và MỘT kết cục HTTP. Một tính chất kiểu "hai chỗ phải trả lời giống hệt nhau" mà
 * được cài đặt ở hai chỗ thì nó đúng cho tới lần đầu một trong hai chỗ được sửa. Ở đây có ĐÚNG
 * MỘT chỗ, và cả hai Action gọi nó.
 *
 * **Thứ tự, và nó đã được sửa một lần.** Bản đầu của `ReviewChecklistItem` trả lời "hồ sơ chứa
 * mục này đã bị xoá" TRƯỚC cả cổng quyền, với lập luận rằng câu đó giống hệt nhau cho mọi người
 * hỏi nên nó không rò rỉ gì. Lập luận đó đúng cho câu "không tìm thấy đầu mục" và SAI cho câu
 * kia: tình huống "hồ sơ đã bị xoá" chỉ với tới được khi đầu mục CÓ THẬT, nên trả lời nó cho một
 * người không có quyền nào là xác nhận rằng cái id họ vừa gõ là một id thật.
 *
 *  1. đọc lại đầu mục dưới khoá — không có thì {@see ChecklistItemNotReviewable::unavailable()};
 *  2. đọc hồ sơ KÈM cả bản đã xoá mềm, gắn sẵn vào bản ghi;
 *  3. tài khoản còn hiệu lực và `Gate` — từ chối thì ra ĐÚNG câu ở bước 1;
 *  4. hồ sơ đã xoá mềm — câu riêng, vì tới được đây nghĩa là đã có quyền trên hồ sơ đó;
 *  5. (M7 Task 3) hồ sơ đã kết thúc (`closed_at` khác null) — {@see MatterChecklistReadOnly},
 *     cũng câu riêng và cũng đứng SAU Gate, cùng lý lẽ với bước 4.
 *
 * Bước 2 phải `withTrashed()` để bước 3 còn trả lời được cho một hồ sơ đã xoá mềm:
 * `MatterPolicy::view` CỐ Ý cho quản trị viên nhìn thấy hồ sơ đã xoá (để còn khôi phục), và chính
 * họ là người cần đọc câu "khôi phục hồ sơ trước đã".
 *
 * **M7 Task 3 — thứ tự khoá: `matters` TRƯỚC, đầu mục sau** (ràng buộc toàn cục của làn). Bước 1
 * vì vậy gồm ba lần đọc: một lần đọc KHÔNG khoá để lấy `matter_id` do CSDL trả về (không phải
 * `matter_id` trên đối tượng caller đưa vào — thứ ai cũng gán được, xem `ReviewChecklistItem`
 * class docblock, mục "Bản ghi được ĐỌC LẠI trong transaction"); khoá dòng `matters` đó; rồi mới
 * khoá và đọc lại chính đầu mục. Cột `matter_checklist_items.matter_id` không Action nào ghi lại
 * sau khi dòng sinh ra, nên hai lần đọc đầu mục luôn cho cùng một `matter_id`; nếu có một ngày
 * chúng khác nhau thì trả lời như đầu mục không còn nữa, không đi tiếp trên một khoá sai dòng.
 *
 * **Lần đọc KHÔNG khoá đó chạy TRƯỚC `DB::transaction()` (rà soát cuối M7, I1)** — hai bước, hai
 * hàm: Action gọi {@see self::checklistItemMatterId()} trước khi mở transaction, rồi
 * {@see self::openChecklistItem()} làm câu đầu tiên BÊN TRONG transaction, bắt đầu bằng khoá
 * `matters`. Bản Task 3 đặt lần đọc trần làm câu đầu tiên bên trong transaction: trên MariaDB
 * (REPEATABLE READ) câu đó cố định READ VIEW của cả transaction TRƯỚC lúc đợi khoá `matters`, nên
 * một lần gỡ khỏi đội ngũ commit trong lúc đợi không được Gate phía sau nhìn thấy — người vừa bị gỡ
 * vẫn gạt được "không cần nộp" (đo bằng hai phiên thật trên MariaDB 11.8,
 * `MatterLockBeforeSnapshotTest`). Cùng luật M6.5 đã ghi ở `TriageClientRequest::realMatterId()`.
 */
trait OpensChecklistItem
{
    use ChecksAccountActive;

    /**
     * `matter_id` THẬT của đầu mục, đọc từ CSDL — GỌI TRƯỚC `DB::transaction()`, không bao giờ bên
     * trong (xem docblock trait, rà soát cuối M7 I1): chạy như một câu auto-commit riêng, nó không cố
     * định READ VIEW nào cho transaction sắp mở. `null` = đầu mục không còn (đã xoá mềm, hay chưa bao
     * giờ có); {@see self::openChecklistItem()} trả lời ca đó bằng câu từ chối chung.
     *
     * `withoutGlobalScope(ClientPortalScope::class)`: cùng lý do với hai lần đọc trong
     * `openChecklistItem()` (ghi chú ở đó).
     */
    protected function checklistItemMatterId(MatterChecklistItem $checklistItem): ?int
    {
        $matterId = MatterChecklistItem::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->whereKey($checklistItem->getKey())
            ->value('matter_id');

        return $matterId === null ? null : (int) $matterId;
    }

    /**
     * Chỉ gọi BÊN TRONG transaction, như câu đầu tiên của nó, với `$matterId` do
     * {@see self::checklistItemMatterId()} đọc TRƯỚC khi transaction mở.
     *
     * @return array{0: MatterChecklistItem, 1: Matter} bản ghi đã đọc lại, và hồ sơ của nó
     *
     * @throws ChecklistItemNotReviewable
     * @throws MatterChecklistReadOnly
     */
    protected function openChecklistItem(MatterChecklistItem $checklistItem, User $actor, ?int $matterId): array
    {
        // `withoutGlobalScope(ClientPortalScope::class)`: một nhân sự đăng nhập cả /admin lẫn
        // /portal có CẢ HAI guard cùng xác thực (xem `ClientPortalScope::isActive()`), và scope
        // của `MatterChecklistItem` là `whereHas('matter')` — tức lần đọc lại này sẽ không thấy
        // gì và một thao tác hợp lệ bị từ chối oan.
        //
        // KHÔNG có test đứng sau câu đó, và lý do đáng ghi lại: xoá nó đi thì bộ test vẫn xanh,
        // còn dựng một test cho nó thì ĐỎ CẢ KHI có nó. `MatterPolicy::view` — đường mà `Gate`
        // bên dưới đi qua — chạy `Matter::query()` không gỡ scope, nên với một phiên portal của
        // khách hàng khác đang mở thì chính cổng quyền từ chối, dù hai lần đọc ở đây đã đọc
        // đúng. Hai Action này không tự làm mình độc lập với guard được; chỗ còn lại nằm trong
        // `MatterPolicy` và đã được ghi lại thành việc mang sang.
        //
        // `lockForUpdate()` nối tiếp hai thao tác song song trên cùng một đầu mục trên MariaDB.
        // Bộ test chạy SQLite, nơi nó không sinh ra khoá nào, nên phần KHOÁ của câu này không có
        // test chứng minh; phần ĐỌC LẠI thì có.
        //
        // M7 Task 3 — thứ tự khoá `matters` trước (xem docblock trait). `matter_id` đã đọc TRƯỚC
        // transaction (rà soát cuối M7, I1): câu ĐẦU TIÊN ở đây là câu khoá `matters`, không câu
        // đọc trần nào đứng trước nó. `$matterId === null` (đầu mục không còn) thì không có dòng
        // nào để khoá; lần đọc đầu mục ngay dưới ra `null` và đi ra bằng câu từ chối chung.
        $matter = $matterId === null ? null : Matter::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->withTrashed()
            ->lockForUpdate()
            ->find($matterId);

        $fresh = MatterChecklistItem::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->lockForUpdate()
            ->find($checklistItem->getKey());

        if ($fresh === null || (int) $fresh->matter_id !== $matterId) {
            throw ChecklistItemNotReviewable::unavailable();
        }

        $fresh->setRelation('matter', $matter);

        // Hỏi trên `$fresh`, không trên đối tượng caller đưa vào: policy đọc `matter` của đối
        // tượng được hỏi, nên một `matter_id` bị sửa trong bộ nhớ sẽ trả lời thay cho dòng dữ
        // liệu thật.
        //
        // `inspect()` chứ không `authorize()`: `Gate::authorize()` ném một `AuthorizationException`
        // mang thông điệp mặc định tiếng Anh của Laravel — một LỚP khác, một CÂU khác và một MÃ
        // HTTP khác với lời từ chối ngay trên. Ba thứ cùng lúc, và cả ba đều quan sát được từ bên
        // ngoài. Xem {@see ChecklistItemNotReviewable::unavailable()}.
        //
        // `accountIsActive()` đi cùng vế vì nó trả lời cùng một câu hỏi ("người này có được làm
        // việc này không") nên phải ra cùng một câu trả lời — xem `ChecksAccountActive`.
        if (! $this->accountIsActive($actor)
            || Gate::forUser($actor)->inspect('review', $fresh)->denied()
        ) {
            throw ChecklistItemNotReviewable::unavailable();
        }

        // Từ ĐÂY trở xuống, người hỏi đã có quyền trên hồ sơ này, nên câu trả lời được phép nói
        // ra chuyện gì đã xảy ra với bản ghi và cách sửa.
        //
        // `$matter === null` nghĩa là khoá ngoại hỏng, và nhánh đó không với tới được sau cổng
        // quyền (`canSeeMatter(null)` trả `false`). Giữ lại vì một cổng hỏng theo hướng CHO QUA
        // đắt hơn hẳn một nhánh thừa, và vì `$matter` bên dưới được đọc như một giá trị chắc
        // chắn khác `null`.
        if ($matter === null || $matter->trashed()) {
            throw ChecklistItemNotReviewable::matterUnavailable($fresh);
        }

        // M7 Task 3: danh mục hồ sơ của một vụ đã kết thúc là chỉ đọc — kiểm DƯỚI khoá `matters`
        // vừa xin ở trên, SAU cả Gate lẫn cổng "hồ sơ đã xoá mềm" ngay phía trên. `$fresh` (đầu
        // mục) không đổi ở đây: `ReviewChecklistItem`/`MarkChecklistItemNotApplicable` vẫn được
        // phép ĐỌC nó (ví dụ để vẽ lại màn hình sau một lần bấm bị chặn) — thứ bị chặn là GHI,
        // và cả hai Action đều ghi ngay sau khi gọi hàm này trả về.
        if ($matter->closed_at !== null) {
            throw MatterChecklistReadOnly::make();
        }

        return [$fresh, $matter];
    }
}
