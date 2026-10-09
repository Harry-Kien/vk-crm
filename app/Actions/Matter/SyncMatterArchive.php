<?php

namespace App\Actions\Matter;

use App\Listeners\SyncMatterArchiveOnStageChange;
use App\Models\Matter;
use App\Models\MatterArchive;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;

/**
 * M7 Task 3 (phán quyết của chủ nhiệm). Đồng bộ MỘT dòng `matter_archives` với `closed_at` HIỆN
 * TẠI của một `Matter` — gọi từ {@see SyncMatterArchiveOnStageChange}, mỗi lần
 * giai đoạn của vụ việc thật sự đổi (`App\Events\MatterStageChanged`).
 *
 * **Idempotent, và đó là toàn bộ lý do nó an toàn để gọi lại.** `handle()` không đọc "vừa đổi
 * giai đoạn hay chưa" — nó chỉ đọc `closed_at` NGAY LÚC NÀY dưới khoá, và hai lần gọi liên tiếp
 * với cùng `closed_at` luôn cho ra cùng một kết quả (`archived_at` là ngoại lệ CÓ CHỦ Ý duy nhất —
 * xem bên dưới). Đây là lý do listener được phép chạy ĐỒNG BỘ mà không cần một cơ chế chống gọi
 * trùng nào khác.
 *
 * **Khoá dòng `matters` TRƯỚC, rồi mới tới `matter_archives`** (ràng buộc toàn cục của làn:
 * "Thứ tự khoá mọi nơi: dòng matters trước"). `$matter` mà caller đưa vào chỉ dùng để lấy khoá
 * chính — `closed_at` được đọc lại DƯỚI KHOÁ, không tin giá trị trên đối tượng caller cầm (có thể
 * đã cũ: `MatterStageChanged` mang một `StageLog` của một lần chuyển giai đoạn đã XẢY RA, nhưng
 * giữa lúc event dispatch (sau commit) và lúc listener này chạy, một lần chuyển giai đoạn KHÁC —
 * lý thuyết, nhưng listener chạy đồng bộ ngay sau commit nên cửa sổ này gần như bằng không — có
 * thể đã ghi đè `closed_at` một lần nữa).
 *
 * **Hai nhánh, đúng theo phán quyết:**
 *
 *  - `closed_at !== null` (vụ đã đóng, hoặc vừa đóng): tạo hoặc cập nhật dòng lưu trữ.
 *    `archived_at = now()` — KHÔNG idempotent theo nghĩa "giữ nguyên giá trị cũ", vì nó trả lời
 *    câu "lần cuối dòng này được đồng bộ là khi nào", không phải "lần đầu vụ này đóng là khi
 *    nào" (câu đó đã có sẵn ở `matters.closed_at`, chỗ DUY NHẤT trong `app/` ghi cột đó — xem
 *    `TransitionMatterStage`). `client_access_until`/`retention_until` LUÔN được tính lại từ
 *    `closed_at` cộng cấu hình hiện hành (`config('vkcrm.client_access_days')`,
 *    `config('vkcrm.retention_years')`) — không đọc số cứng, và vì cả hai chỉ phụ thuộc
 *    `closed_at` (một giá trị `matters.closed_at` không đổi khi chuyển từ một giai đoạn terminal
 *    sang một giai đoạn terminal khác — xem docblock `TransitionMatterStage`), hai lần gọi liên
 *    tiếp với cùng `closed_at` luôn tính ra CÙNG hai ngày này.
 *  - `closed_at === null` (đường bỏ qua của admin vừa mở lại vụ): CHỈ xoá `client_access_until`
 *    về `null`, giữ nguyên phần còn lại của dòng — không có gì để "mở lại" thêm, vì `archived_at`
 *    ghi lại một sự kiện lịch sử có thật (lần đóng trước), và lần đóng LẠI (nếu có) sẽ tự ghi đè
 *    nó qua nhánh trên. Không có dòng archive nào để cập nhật (vụ chưa từng đóng mà vẫn nhận một
 *    sự kiện chuyển giai đoạn — không xảy ra trong luồng thật, vì sự kiện chỉ phát khi
 *    `! $isSameStage`, và một vụ chưa từng đóng thì đường "rời giai đoạn terminal" không chạm
 *    tới nó) thì không làm gì — không tạo một dòng archive rỗng cho một vụ chưa từng đóng.
 *
 * **KHÔNG BAO GIỜ xoá mềm dòng archive, và tự khôi phục một dòng đã xoá mềm nếu gặp phải.**
 * `matter_archives.matter_id` là `unique`, và MariaDB tính CẢ dòng đã xoá mềm vào ràng buộc đó
 * (`SoftDeletes` không đổi được điều này) — một `create()` thứ hai trên một vụ có dòng cũ đã xoá
 * mềm sẽ ném lỗi trùng khoá. Dữ liệu sản phẩm hôm nay không có đường nào xoá mềm một
 * `MatterArchive` (không Action nào gọi `delete()` trên nó), nên nhánh khôi phục này là phòng thủ
 * cho dữ liệu cũ/thao tác tay, không phải một luồng có thật — hai test dựng tiền đề bằng tay
 * (`->delete()` thẳng trên bản ghi) và mỗi nhánh có một mutation probe riêng.
 *
 * **KHÔNG BAO GIỜ ghi đè `destroyed_at`/ba cột quyết định tiêu huỷ (Task 6), không đụng
 * `handover_document_id` (Task 4/11).** `handle()` chỉ liệt kê ĐÚNG những cột nó có thẩm quyền
 * ghi trong mỗi `$attributes` bên dưới — không có `update($request->all())` hay bất cứ hình thức
 * nào đọc rộng hơn danh sách đó.
 *
 * **Nhận `int $matterId`, không nhận `Matter $matter`.** `Matter` mang `ClientPortalScope`
 * (`RestrictedToClientPortal`) — một nhân sự đang mở CẢ hai panel trong cùng trình duyệt (cookie
 * phiên dùng chung, xem docblock `ClientPortalScope`/`OpensChecklistItem`) khiến một lần tải
 * QUAN HỆ `$stageLog->matter` từ listener có thể trả `null` dù `matter_id` hoàn toàn hợp lệ — và
 * `null` truyền vào đây sẽ ném lỗi ngay tại `$matter->getKey()`, cho một Action lẽ ra phải chạy
 * VÔ ĐIỀU KIỆN sau mọi lần chuyển giai đoạn. Nhận thẳng khoá chính loại bỏ toàn bộ lớp lỗi đó:
 * không có đối tượng `Matter` nào của caller để một scope lặng lẽ làm rỗng. Cả hai truy vấn dưới
 * đây tự bỏ `ClientPortalScope`, tường minh, cùng lý do.
 */
class SyncMatterArchive
{
    public function handle(int $matterId, ?User $actor = null): ?MatterArchive
    {
        return DB::transaction(function () use ($matterId, $actor): ?MatterArchive {
            // Khoá `matters` TRƯỚC — xem docblock lớp. `closed_at` đọc từ ĐÂY.
            $lockedMatter = Matter::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->whereKey($matterId)
                ->lockForUpdate()
                ->firstOrFail();

            $archive = MatterArchive::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->withTrashed()
                ->where('matter_id', $lockedMatter->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedMatter->isClosed()) {
                return $this->syncClosed($lockedMatter, $archive, $actor);
            }

            return $this->syncReopened($archive);
        });
    }

    /**
     * Nhánh "đã đóng" — xem docblock lớp cho lý lẽ đầy đủ của từng cột.
     */
    private function syncClosed(Matter $matter, ?MatterArchive $archive, ?User $actor): MatterArchive
    {
        $closedAt = $matter->closed_at;

        $attributes = [
            'archived_at' => now(),
            'archived_by' => $actor?->getKey(),
            'client_access_until' => $closedAt->copy()
                ->addDays((int) config('vkcrm.client_access_days'))
                ->toDateString(),
            'retention_until' => $closedAt->copy()
                ->addYears((int) config('vkcrm.retention_years'))
                ->toDateString(),
        ];

        if ($archive === null) {
            return MatterArchive::query()->create([
                'matter_id' => $matter->getKey(),
                ...$attributes,
            ]);
        }

        // Xem docblock lớp, mục "KHÔNG BAO GIỜ xoá mềm...": phòng thủ cho một dòng đã xoá mềm từ
        // dữ liệu/thao tác cũ, không phải một luồng mã sản phẩm tạo ra được.
        if ($archive->trashed()) {
            $archive->restore();
        }

        // Làn fm A5: hạn tra cứu đã được gia hạn tay (`ExtendClientAccess`) và còn muộn hơn hạn vừa
        // tính lại thì giữ — một lần đồng bộ lại (chuyển giữa hai giai đoạn kết thúc) không được rút
        // ngắn quyền khách đã được cho. Vụ mở lại thì `syncReopened()` đã đưa cột về NULL, nên lần
        // đóng sau tính lại từ đầu như trước.
        $existing = $archive->client_access_until?->toDateString();

        if ($existing !== null && $existing > $attributes['client_access_until']) {
            $attributes['client_access_until'] = $existing;
        }

        $archive->update($attributes);

        return $archive;
    }

    /**
     * Nhánh "vừa mở lại" (đường bỏ qua của admin xoá `closed_at`, R8) — xem docblock lớp.
     */
    private function syncReopened(?MatterArchive $archive): ?MatterArchive
    {
        if ($archive === null) {
            return null;
        }

        if ($archive->trashed()) {
            $archive->restore();
        }

        $archive->update(['client_access_until' => null]);

        return $archive;
    }
}
