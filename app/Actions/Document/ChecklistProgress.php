<?php

namespace App\Actions\Document;

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Thanh tiến độ `Đã nộp X / Y` của SPEC §4.10 — luật đếm, ở một chỗ duy nhất.
 *
 * **Vì sao nó là một Action chứ không một phương thức của màn hình.** Bản đầu sống trong
 * `ChecklistRelationManager` của panel /admin. Đó là một luật nghiệp vụ có đính chính ghi ngày
 * trong SPEC nằm trong một relation manager của Filament, ngược với CLAUDE.md ("nghiệp vụ nằm
 * trong `app/Actions/`; Filament chỉ gọi Action") — và hai widget trích dẫn nó như NGUỒN của
 * luật (`MattersMissingDocumentsWidget`, `PendingChecklistReviewsWidget`; cả hai nay trỏ vào lớp
 * này). Con số này là **trường hiển thị trên portal** theo đúng chữ của §4.10, nên M5 phải đọc
 * được nó; để nguyên chỗ cũ thì M5 hoặc `use` một lớp của panel quản trị từ panel khách hàng,
 * hoặc viết lại luật lần thứ hai. Cả hai đều là cách để hai panel hiện hai con số khác nhau cho
 * cùng một hồ sơ.
 *
 * # Luật, viết đủ vì cách đọc đã phải sửa hai lần
 *
 * SPEC §4.10 định nghĩa `Y` là "số item `is_required = true` cộng số item không bắt buộc nhưng
 * đã có tài liệu". Đính chính 2026-09-16 trong chính SPEC nói rõ hai điều mà bản đầu thiếu:
 *
 *  - **`Y` là một TẬP HỢP các dòng**, không phải một con số đếm riêng, và **`X` đếm BÊN TRONG
 *    tập đó**. Thiếu ràng buộc `X ⊆ Y`, một hồ sơ `DS` của dữ liệu mẫu hiện ra "Đã nộp 5/3":
 *    `MatterSeeder` đánh dấu mọi đầu mục KHÔNG bắt buộc là `not_applicable` và không gắn tài
 *    liệu nào — đúng nghĩa của trạng thái đó — nên những dòng ấy nằm trong tử số mà không nằm
 *    trong mẫu số. Seeder không sai: một đầu mục không bắt buộc, không tài liệu, được đánh dấu
 *    "không cần nộp" thì đơn giản là không xuất hiện trên thanh tiến độ, ở cả hai vế.
 *  - **"đã có tài liệu" nghĩa là có ít nhất một tài liệu KHÔNG THUỘC NHÓM D.** Một tài liệu nhóm
 *    D (hồ sơ công việc nội bộ) gắn được vào một đầu mục danh mục và đó là việc hợp lệ — một ghi
 *    chú nội bộ về đúng giấy tờ đó. Tính nó là "đầu mục này đã có tài liệu" sẽ để một ghi chú
 *    công việc của văn phòng tự kéo một đầu mục không bắt buộc vào mẫu số, tức tự thêm một việc
 *    vào danh sách khách phải làm.
 *
 * Điều kiện của `X` chỉ đọc cột `status` (thứ mà `UploadStaffDocument`, `ReviewChecklistItem` và
 * `MarkChecklistItemNotApplicable` ghi) và không hỏi bảng `documents` một câu nào — nhưng `X`
 * VẪN phụ thuộc vào bảng đó, vì nó chỉ chạy trên các dòng đã nằm trong `Y`. Chỗ duy nhất
 * `documents` được hỏi là định nghĩa của `Y`, và nó phải được hỏi ở đó, vì chính SPEC §4.10
 * định nghĩa `Y` bằng chữ "đã có tài liệu".
 *
 * # Phép đếm bỏ `ClientPortalScope`, và đó là toàn bộ điểm khác so với bản cũ
 *
 * `withCount` áp GLOBAL SCOPE của `Document`. Bản cũ vì thế trả lời hai con số khác nhau cho
 * cùng một hồ sơ tuỳ theo guard nào đang mở: dưới guard `client`, `ClientPortalScope` thu tập
 * đếm được về "đã công bố VÀ khách được xem", trong khi §4.10 định nghĩa `Y` bằng "không thuộc
 * nhóm D" — không phải "đã ra tới khách".
 *
 * Đo được, và con số đó chính là thứ `ChecklistProgressTest` ghim: một hồ sơ có 3 đầu mục bắt
 * buộc đã `accepted` cộng một đầu mục KHÔNG bắt buộc mang đúng một tài liệu nhóm B còn
 * `internal_draft` — nhân sự đọc `3/4`, khách đọc `3/3`, cùng một hồ sơ, cùng một thời điểm.
 * Vòng rà soát cuối M4 đo cùng khuyết tật ấy trên dữ liệu mẫu (`VK-2026-DS-0003`: `3/4` so với
 * `2/3`). Và con số của khách mới là con số §4.10 gọi là trường hiển thị trên portal, nên bản cũ
 * sai ở đúng phía người đọc nó.
 *
 * Nói thẳng vì sao "khách chỉ nên đếm thứ khách thấy được" là cách đọc SAI: mẫu số trả lời
 * "văn phòng còn chờ khách nộp gì", không trả lời "khách đọc được gì". Một bản đơn văn phòng
 * đang soạn (nhóm B, `internal_draft`) là bằng chứng rằng đầu mục ấy KHÔNG còn là một việc của
 * khách, dù khách chưa được phép mở nó. Ẩn nó khỏi mẫu số sẽ báo cho khách một việc phải làm mà
 * văn phòng đã làm rồi.
 *
 * Scope `SoftDeletingScope` thì được GIỮ: một tài liệu đã xoá mềm không còn trong hồ sơ, nên nó
 * không còn là "đã có tài liệu".
 */
class ChecklistProgress
{
    /**
     * Bí danh của bộ đếm tài liệu gắn vào một đầu mục, KHÔNG kể nhóm D. Công khai vì bảng ở tab
     * "Danh mục hồ sơ" hiện đúng con số này thành một cột, và nó phải là CÙNG con số mẫu số đang
     * dùng — không phải một phép đếm thứ hai viết lại bên màn hình.
     */
    public const DOCUMENT_COUNT_ALIAS = 'client_facing_documents_count';

    /**
     * Hai trạng thái được tính là "đã xong" ở tử số `X`. `not_applicable` nằm cùng hạng với
     * `accepted` vì cả hai đều trả lời "văn phòng không còn chờ gì ở đầu mục này" — thứ duy nhất
     * thanh tiến độ nói.
     *
     * @var list<ChecklistItemStatus>
     */
    private const SETTLED_STATUSES = [ChecklistItemStatus::Accepted, ChecklistItemStatus::NotApplicable];

    /** @return array{submitted: int, total: int} */
    public function handle(Matter $matter): array
    {
        $counted = self::countClientFacingDocuments($matter->checklistItems()->getQuery())
            ->get()
            ->filter(fn (MatterChecklistItem $item): bool => $item->is_required
                || ($item->{self::DOCUMENT_COUNT_ALIAS} ?? 0) > 0);

        return [
            'submitted' => $counted
                ->filter(fn (MatterChecklistItem $item): bool => in_array($item->status, self::SETTLED_STATUSES, true))
                ->count(),
            'total' => $counted->count(),
        ];
    }

    /**
     * Gắn bộ đếm "tài liệu không thuộc nhóm D" vào một truy vấn `matter_checklist_items`.
     *
     * Tách ra khỏi {@see self::handle()} vì bảng ở tab "Danh mục hồ sơ" cần ĐÚNG con số này trên
     * từng dòng, và một `withCount` viết lại lần thứ hai bên màn hình là cách để cột "Số tài
     * liệu" và mẫu số của thanh tiến độ nói hai chuyện khác nhau trên cùng một dòng.
     *
     * `withoutGlobalScope(ClientPortalScope::class)` tường minh, cùng thành ngữ
     * `StoresDocumentFile::scopelessly()`: xem docblock lớp cho lý do đầy đủ.
     *
     * @param  Builder<MatterChecklistItem>  $items
     * @return Builder<MatterChecklistItem>
     */
    public static function countClientFacingDocuments(Builder $items): Builder
    {
        return $items->withCount([
            'documents as '.self::DOCUMENT_COUNT_ALIAS => fn (Builder $documents): Builder => $documents
                ->withoutGlobalScope(ClientPortalScope::class)
                ->where('group', '!=', DocumentGroup::Internal->value),
        ]);
    }
}
