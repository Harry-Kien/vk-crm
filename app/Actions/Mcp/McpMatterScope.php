<?php

namespace App\Actions\Mcp;

use App\Actions\Concerns\ReadsWithoutPortalScope;
use App\Enums\Confidentiality;
use App\Enums\MatterAiAccess;
use App\Enums\Permission;
use App\Models\Matter;
use App\Models\User;
use App\Policies\MatterPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Định nghĩa DUY NHẤT của "những vụ việc nào MCP được thấy cho người này" (kế hoạch M11, R3). Mọi
 * tool của Task 10–13 đi qua lớp này — tool đọc liệt kê bằng {@see self::query()}, tool nhận một id
 * vụ việc gọi {@see self::find()}, tool đọc bản ghi con (mốc, yêu cầu, tài liệu…) lọc bằng
 * {@see self::constrain()}. Không tool nào viết lại một điều kiện dưới đây.
 *
 * Tập vụ việc là GIAO của bốn điều kiện, mỗi điều kiện một mệnh đề trong {@see self::query()}:
 *
 *  1. `listableBy($actor)` VÀ `Gate::forUser($actor)->allows('view', $matter)` — MCP kế thừa
 *     policy của web, rồi chỉ được thu hẹp. Hai vế KHÔNG trùng nhau: `listableBy` mở mọi vụ thường
 *     cho người có `matter.viewAny` (kế toán), còn `MatterPolicy::view()` đòi `matter.view` trước
 *     tiên. Bản SQL của `view()` cho nhân sự là đúng `matter.view` ∧ `listableBy` (nhánh truy vấn
 *     của {@see MatterPolicy::view()}, đọc cả vụ đã xoá mềm — vế 4 lo phần đó), nên vế Gate được
 *     viết thành một điều kiện quyền `matter.view` trên truy vấn. Không có lời gọi Gate từng dòng:
 *     một danh sách phân trang và một số đếm (`whoami`) không lọc lại được từng dòng sau truy vấn mà
 *     không lệch số. Sự tương đương này được GHIM bằng test ("R3 is exactly the intersection",
 *     `MatterScopeTest`): với sáu vai × sáu vụ việc của test, có mặt ở đây ⇔ Gate `view` ∧ thường ∧
 *     `allowed` ∧ chưa xoá, hỏi bằng chính Gate — nên một điều kiện mới thêm vào
 *     `MatterPolicy::view()` mà không thêm ở đây làm test đó đỏ, miễn là bộ dữ liệu ấy chạm tới nó.
 *  2. `confidentiality = normal` — vụ `restricted` vắng mặt với MỌI người, kể cả luật sư phụ trách
 *     và admin, những người thấy nó trên web [DC:104]. Viết `= normal`, không `!= restricted`: một
 *     giá trị lạ trong cột thì đóng cửa (cùng hướng với `Matter::isListableBy()`).
 *  3. `ai_access = allowed` (R9) — vụ chưa có đồng ý bằng văn bản của khách vắng mặt khỏi mọi tool
 *     và mọi số đếm.
 *  4. chưa xoá mềm — TƯỜNG MINH (`whereNull(deleted_at)`), không chỉ tin `SoftDeletingScope`: một
 *     nơi gọi thêm `withTrashed()` lên trên truy vấn này không được kéo vụ đã huỷ vào (cùng lý lẽ
 *     với `Matter::scopeOpen()`).
 *
 * Không tìm thấy, không có quyền, vụ hạn chế, vụ `denied`, vụ đã xoá: cả năm cho cùng một câu trả
 * lời (`null` / vắng mặt). Lớp này không có đường nào nói "có nhưng bị ẩn" (R3, SPEC §10.10).
 *
 * **Bỏ `ClientPortalScope`, tường minh** ({@see ReadsWithoutPortalScope}). Request `/mcp` xác thực
 * bằng guard `mcp`, nên `auth('web')` rỗng; nếu trong cùng tiến trình có một phiên cổng khách
 * (`auth('client')`), `ClientPortalScope::isActive()` bật và mọi `Matter::query()` bị cắt theo KHÁCH
 * đó — tập vụ của nhân sự đổi theo một phiên lạ, im lặng. Quyền đã được hỏi trên `$actor` ở bốn điều
 * kiện trên; câu trả lời đó là câu duy nhất quyết định.
 *
 * Trả `Builder`/model cho Action đọc của Task 10–11, KHÔNG cho tool: Action đọc dựng DTO, presenter
 * (`App\Support\Mcp\Presenters`) đọc từng trường theo tên. Không có URL Filament ở đây (luật kiến trúc
 * "nghiệp vụ không phụ thuộc vào Filament"); URL dựng ở `App\Support\Mcp\AdminUrls`.
 */
final class McpMatterScope
{
    use ReadsWithoutPortalScope;

    /**
     * Mọi vụ việc trong tập R3 của `$actor`. Cột viết đủ tên bảng (`matters.`), nên truy vấn này
     * dùng được làm truy vấn con và ghép join mà không mơ hồ.
     *
     * @return Builder<Matter>
     */
    public function query(User $actor): Builder
    {
        $matters = $this->scopelessly(Matter::query());
        $model = $matters->getModel();

        return $matters
            // (1) listableBy ∧ Gate view — xem docblock lớp cho lý do vế Gate là `matter.view`.
            ->listableBy($actor)
            ->when(
                ! $actor->can(Permission::MatterView->value),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            // (2) không bao giờ vụ hạn chế, kể cả luật sư phụ trách và admin.
            ->where($model->qualifyColumn('confidentiality'), Confidentiality::Normal->value)
            // (3) R9: chỉ vụ đã được bật "truy cập qua AI".
            ->where($model->qualifyColumn('ai_access'), MatterAiAccess::Allowed->value)
            // (4) chưa xoá mềm, kể cả khi nơi gọi thêm withTrashed().
            ->whereNull($model->qualifyColumn('deleted_at'));
    }

    /**
     * Một vụ việc trong tập R3, hoặc `null` — cùng `null` cho id không tồn tại, vụ của đội khác, vụ
     * hạn chế, vụ `denied` và vụ đã xoá. Tool đổi `null` thành thông điệp "Không tìm thấy" duy nhất.
     */
    public function find(User $actor, int $matterId): ?Matter
    {
        return $this->query($actor)->whereKey($matterId)->first();
    }

    /**
     * Giới hạn một truy vấn bản ghi CON (mốc, yêu cầu, tài liệu, dòng tiến độ…) vào các vụ việc trong
     * tập R3: `$column IN (id các vụ trong tập)`. Đồng thời bỏ `ClientPortalScope` khỏi chính truy
     * vấn con đó, cùng lý do như {@see self::query()} — các model con mang `RestrictedToClientPortal`
     * và sẽ bị cắt theo phiên cổng đang mở nếu không.
     *
     * Chỉ lọc theo VỤ VIỆC. Luật riêng của từng loại bản ghi (nhóm D của tài liệu, policy `view` của
     * từng dòng) vẫn là việc của Action đọc tương ứng.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $actor, string $column = 'matter_id'): Builder
    {
        $scoped = $this->query($actor);

        return $this->scopelessly($query)->whereIn(
            $query->qualifyColumn($column),
            $scoped->select($scoped->getModel()->qualifyColumn('id')),
        );
    }
}
