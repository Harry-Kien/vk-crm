<?php

namespace App\Actions\Deadline\Concerns;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Actions\Document\Concerns\OpensChecklistItem;
use App\Models\Deadline;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Mở một mốc thời hạn — hoặc chính vụ việc sắp nhận một mốc mới — ra để văn phòng ghi vào nó,
 * hoặc từ chối. Dùng chung cho cả ba Action của `app/Actions/Deadline/`.
 *
 * **Vì sao là một trait chứ không phải ba đoạn mã giống nhau.** Thứ nằm ở đây là SPEC §10.10 áp
 * cho bảng `deadlines`: mọi lý do không ghi được — không có bản ghi, không có quyền, hồ sơ đã
 * xoá mềm, tài khoản đã bị vô hiệu hoá — phải ra đúng MỘT lớp exception và MỘT câu chữ. Một tính
 * chất kiểu "ba chỗ phải trả lời giống hệt nhau" mà được cài đặt ở ba chỗ thì nó đúng cho tới
 * lần đầu một trong ba chỗ được sửa. Cùng hình dạng {@see OpensChecklistItem}
 * dựng cho danh mục hồ sơ, chỉ khác lớp exception: ở đây là `AuthorizationException`, thứ
 * `App\Filament\Admin\Concerns\ReportsActionFailures` đổi thành `__('actions.unauthorized')` — một câu
 * duy nhất, không phân biệt "không có quyền" với "không tồn tại". (Tên lớp ấy viết trong văn
 * xuôi chứ không qua `{@see}`: một `{@see}` đủ điều kiện sẽ bị Pint kéo thành một câu `use`, và
 * `ArchitectureTest` — đúng đắn — cấm `App\Actions` biết tới Filament.)
 *
 * **Không có câu trả lời RIÊNG cho hồ sơ đã xoá mềm ở đây, khác `OpensChecklistItem`.** Bên đó
 * câu riêng có việc thật: quản trị viên vẫn `view` được hồ sơ đã xoá (để còn khôi phục), nên họ
 * qua được cổng rồi mới cần biết phải khôi phục hồ sơ trước. Ở đây cổng là `MatterPolicy::update`,
 * thứ CHẶN mọi hồ sơ đã xoá mềm ngay cả với quản trị viên (xem docblock của policy đó), nên không
 * ai đi qua cổng để tới được một câu riêng. Một nhánh không với tới được thì không phải một lời
 * giải thích, nó chỉ là một dòng mã trông như đang canh gì đó.
 *
 * **`scopelessly()` ở cả hai lần đọc.** Một nhân sự đăng nhập cả `/admin` lẫn `/portal` có CẢ HAI
 * guard cùng xác thực (xem `ClientPortalScope::isActive()`), và `Deadline` mang
 * `RestrictedToClientPortal` với ràng buộc `is_published = true`: không gỡ scope thì một mốc CHƯA
 * công bố — tức phần lớn bảng này — vô hình với chính người vừa tạo ra nó, và một thao tác hợp lệ
 * bị từ chối oan. Lý lẽ đầy đủ ở {@see ReadsWithoutPortalScope}.
 *
 * **`lockForUpdate()` nối tiếp hai thao tác song song trên cùng một dòng trên MariaDB.** Bộ test
 * chạy SQLite, nơi nó biên dịch thành không gì cả, nên phần KHOÁ là một lập luận chứ không phải
 * một điều kiện đỏ được; phần ĐỌC LẠI thì có test. Hệ quả: mọi phương thức dưới đây chỉ gọi được
 * từ bên trong một transaction.
 */
trait OpensDeadline
{
    use ChecksAccountActive;
    use ReadsWithoutPortalScope;

    /**
     * Vụ việc sắp nhận một mốc mới, đã đọc lại dưới khoá và đã qua cổng.
     *
     * @throws AuthorizationException
     */
    protected function openMatterForDeadline(Matter $matter, User $actor): Matter
    {
        // `Matter::query()` loại hồ sơ đã xoá mềm, nên một hồ sơ như vậy ra `null` ở đây và đi ra
        // bằng đúng câu từ chối chung.
        $fresh = $this->scopelessly(Matter::query())->lockForUpdate()->find($matter->getKey());

        if ($fresh === null || ! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        // `MatterPolicy::update` — không phải "thấy được vụ việc". Kế toán có `matter.viewAny`
        // nhưng không có `matter.update` (SPEC §5), nên họ không đặt được một mốc tố tụng lên hồ
        // sơ của người khác.
        if (Gate::forUser($actor)->inspect('update', $fresh)->denied()) {
            $this->refuse();
        }

        return $fresh;
    }

    /**
     * Mốc thời hạn đã đọc lại dưới khoá, kèm vụ việc của nó gắn sẵn vào quan hệ.
     *
     * `setRelation('matter', …)` TRƯỚC khi `Gate` chạm vào đối tượng, cùng lý do đã đo ở M4: một
     * quan hệ nạp lười chạy dưới guard NÀO ĐANG MỞ, nên với một phiên portal đang mở trong cùng
     * trình duyệt `$deadline->matter` trả `null` và một nhân sự đủ quyền bị từ chối oan.
     *
     * Hỏi `Gate` trên `$fresh`, không trên đối tượng caller đưa vào: policy đọc `matter` của đối
     * tượng được hỏi, nên một `matter_id` bị sửa trong bộ nhớ sẽ trả lời thay cho dòng dữ liệu
     * thật.
     *
     * @return array{0: Deadline, 1: Matter}
     *
     * @throws AuthorizationException
     */
    protected function openDeadline(Deadline $deadline, User $actor): array
    {
        $fresh = $this->scopelessly(Deadline::query())->lockForUpdate()->find($deadline->getKey());

        if ($fresh === null || ! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        $matter = $this->scopelessly(Matter::query())->find($fresh->matter_id);
        $fresh->setRelation('matter', $matter);

        // `DeadlinePolicy::update` uỷ thẳng cho `MatterPolicy::update` qua
        // `ChecksMatterAccess::canUpdateMatter()`, và hàm đó tự hỏi `$matter !== null` — nên một
        // hồ sơ đã xoá mềm bị từ chối ở ngay dòng dưới mà không cần một câu riêng.
        if (Gate::forUser($actor)->inspect('update', $fresh)->denied()) {
            $this->refuse();
        }

        // Tới được đây thì `$matter` chắc chắn khác `null` (cổng ngay trên đã hỏi hộ), và các nơi
        // gọi đọc nó như một giá trị chắc chắn.
        return [$fresh, $matter];
    }

    /**
     * **Mọi lý do, MỘT câu** — SPEC §10.10, và §10.10 không chừa ngoại lệ cho người trong văn
     * phòng: M3 đã áp đúng luật này cho cả panel nội bộ (`AnswerDeniedPanelRequestsWithNotFound`).
     * Mốc không tồn tại, thuộc một vụ việc người hỏi không mở được, vụ việc đã bị xoá mềm, tài
     * khoản đã bị vô hiệu hoá: cùng một câu.
     */
    private function refuse(): never
    {
        throw new AuthorizationException(__('deadlines.unavailable'));
    }
}
