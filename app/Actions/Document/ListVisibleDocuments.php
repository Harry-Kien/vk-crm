<?php

namespace App\Actions\Document;

use App\Enums\DocumentGroup;
use App\Enums\Permission;
use App\Models\Document;
use App\Models\User;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Làn tl (2026-10-15) — "nhân sự này thấy những tài liệu nào", xuyên các vụ: truy vấn gốc của trang
 * "Tài liệu" (`App\Filament\Admin\Pages\DocumentLibrary`). Một Action ĐỌC, không ghi gì.
 *
 * # Đúng nhánh nhân sự của `DocumentPolicy::view`, nói lại bằng SQL, TRƯỚC phân trang
 *
 * Bảng Filament đếm, phân trang và tìm trên chính truy vấn này và không hỏi policy cho từng dòng — nên
 * mọi điều kiện của `DocumentPolicy::view` phải nằm TRONG câu SQL. Lọc sau khi phân trang làm lộ số
 * lượng (trang đầy 10 dòng mà hiện 8) và để lại tên tài liệu trong kết quả tìm.
 *
 *  - **`matter.view`** — `MatterPolicy::view` (gốc của `canSeeMatter()`) đòi quyền này trước mọi thứ.
 *    Không có nó thì không dòng nào: kế toán có `matter.viewAny`, và `Matter::scopeListableBy()` một
 *    mình trả MỌI vụ thường cho người có `matter.viewAny` — đúng cho danh sách vụ rút gọn của kế toán,
 *    sai cho tài liệu (SPEC §5: màn hình của kế toán không mang tài liệu).
 *  - **Vụ người xem được thấy** — `Matter::scopeListableBy()`, định nghĩa duy nhất: vụ thường theo
 *    `matter.viewAny`/đội ngũ; vụ `restricted` chỉ luật sư phụ trách và admin (trưởng phòng có tên
 *    trong đội cũng không). `whereHas('matter')` mang `SoftDeletingScope` của `Matter`: tài liệu của vụ
 *    đã xoá mềm không ra (chặt hơn policy — policy còn cho vụ đã xoá để admin thao tác — vì trang vụ
 *    việc không mở được vụ đã xoá, liên kết "Mở vụ" sẽ là một trang lỗi).
 *  - **Vụ đã ghi quyết định tiêu huỷ** (`matter_archives.destroyed_at`, cùng định nghĩa
 *    `Matter::isRecordDestroyed()`: bản ghi lưu trữ chưa xoá mềm) — không ra: hồ sơ đó đã khép lại để
 *    huỷ, danh sách chung không mời ai mở lại nó. Tab Tài liệu của vụ vẫn là đường cho người cần.
 *  - **Nhóm D** chỉ cho người có `document.viewInternal`.
 *  - **Tài liệu đã xoá mềm** không ra — `SoftDeletingScope` của `Document`.
 *
 * # Câu trả lời không đổi theo một phiên cổng khách đang mở song song
 *
 * Ba truy vấn (tài liệu, vụ, bản ghi lưu trữ) gỡ `ClientPortalScope` — cùng lý do `MatterPolicy::view`
 * gỡ nó: scope đó bật khi guard `client` đã xác thực mà `web` thì chưa, và khi đó nó cắt theo KHÁCH
 * của phiên kia chứ không theo nhân sự đang hỏi. Trong panel admin guard `web` luôn đã xác thực nên
 * scope không bật; ở đây nó được gỡ để câu trả lời về NHÂN SỰ là một hàm của nhân sự đó, không của
 * phiên nào tình cờ đang mở (vế bản ghi lưu trữ đặc biệt quan trọng: để scope bật ở đó thì vụ đã tiêu
 * huỷ của khách khác KHÔNG bị loại). Gỡ scope không nới gì: điều kiện quyền phía trên là của nhân sự.
 *
 * Tài khoản đã vô hiệu không tới được đây: panel đăng xuất họ ở request kế tiếp (`EndDisabledStaffSessions`).
 */
final class ListVisibleDocuments
{
    /** @return Builder<Document> */
    public function query(User $viewer): Builder
    {
        $query = Document::query()->withoutGlobalScope(ClientPortalScope::class);

        if (! $viewer->can(Permission::MatterView->value)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereHas('matter', fn (Builder $matter): Builder => $matter
                ->withoutGlobalScope(ClientPortalScope::class)
                ->listableBy($viewer)
                ->whereDoesntHave('archive', fn (Builder $archive): Builder => $archive
                    ->withoutGlobalScope(ClientPortalScope::class)
                    ->whereNotNull('destroyed_at')))
            ->unless(
                $viewer->can(Permission::DocumentViewInternal->value),
                fn (Builder $visible): Builder => $visible->where(
                    $visible->qualifyColumn('group'),
                    '!=',
                    DocumentGroup::Internal->value,
                ),
            );
    }
}
