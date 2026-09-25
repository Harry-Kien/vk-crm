<?php

namespace App\Actions\Document;

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\Document;
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
 *  - **"đã có tài liệu" nghĩa là có ít nhất một tài liệu KHÁCH ĐỌC ĐƯỢC** — đúng ba điều kiện
 *    của `Document::isReleasedToPortal()`: `client_can_view`, `status = published`, và khác nhóm
 *    D. Đây là một sửa lại so với đính chính 2026-09-16, thứ chỉ viết "không thuộc nhóm D" và bỏ
 *    sót vế `client_can_view`/`status` — xem "**Sửa lại checklist-05**" bên dưới cho lý do và
 *    bằng chứng.
 *
 * Điều kiện của `X` chỉ đọc cột `status` (thứ mà `UploadStaffDocument`, `ReviewChecklistItem` và
 * `MarkChecklistItemNotApplicable` ghi) và không hỏi bảng `documents` một câu nào — nhưng `X`
 * VẪN phụ thuộc vào bảng đó, vì nó chỉ chạy trên các dòng đã nằm trong `Y`. Chỗ duy nhất
 * `documents` được hỏi là định nghĩa của `Y`, và nó phải được hỏi ở đó, vì chính SPEC §4.10
 * định nghĩa `Y` bằng chữ "đã có tài liệu".
 *
 * # Sửa lại checklist-05: "khác nhóm D" không phải là "khách đọc được"
 *
 * Đính chính 2026-09-16 viết `Y` gồm các đầu mục không bắt buộc "có ít nhất một tài liệu không
 * thuộc nhóm D" — tức nhóm A, B, C đều tính, bất kể trạng thái vòng đời. Bản đọc đó có một lỗ:
 * một quyết định nhóm C (`internal_draft`, `client_can_view = false`) gắn vào một đầu mục tuỳ
 * chọn kéo đầu mục đó vào `Y` NGAY LẬP TỨC, trong khi trạng thái đầu mục vẫn `missing` — tức nó
 * rơi thẳng vào nhóm "Giấy tờ chúng tôi còn chờ ở anh/chị" mà `MatterProgress::outstandingItems()`
 * vẽ ra, và mẫu số tăng lên đúng lúc khách bị đòi một thứ văn phòng ĐÃ CÓ trong tay mà họ lại
 * không nhìn thấy (finding `checklist/checklist-05`, M6.5 Task 17). Docblock trước bản sửa này
 * còn lập luận NGƯỢC với hậu quả đó — nó viết "một bản đơn văn phòng đang soạn LÀ bằng chứng
 * rằng đầu mục ấy không còn là một việc của khách" để giải thích vì sao ẩn nó đi khỏi mẫu số là
 * sai, trong khi hành vi thật là GIỮ nó trong mẫu số mới tạo ra việc phải làm.
 *
 * Luật đúng đọc theo tinh thần của chính đính chính (mẫu số trả lời "văn phòng còn chờ khách nộp
 * gì", không phải "văn phòng đã có gì trong tủ hồ sơ nội bộ"): một tài liệu chỉ là bằng chứng
 * "đầu mục này không còn là việc của khách" khi CHÍNH KHÁCH đọc được nó — `client_can_view` và
 * `published`, không chỉ khác nhóm D. Một quyết định nhóm B/C đã đi hết vòng đời và được công bố
 * (SPEC §4.11: `internal_draft` → `pending_approval` → `signed_filed` → `published`, cộng
 * `PublishDocument` bật `client_can_view`) VẪN kéo được đầu mục vào `Y` — nó không khác gì một
 * tài liệu nhóm A về mặt "khách đã đọc được gì" — nên luật không loại nhóm, nó lọc theo tầm
 * nhìn. Test `ChecklistProgressTest` ghim cả hai vế: một quyết định nhóm C còn `internal_draft`
 * không kéo được đầu mục vào `Y`; cùng quyết định đó sau khi `published`/`client_can_view` thì
 * kéo được.
 *
 * # Phép đếm bỏ `ClientPortalScope`
 *
 * `withCount` áp GLOBAL SCOPE của `Document`. Phép đếm tự bỏ nó ra một cách tường minh
 * (`withoutGlobalScope`) để con số không phụ thuộc vào việc `ClientPortalScope::isActive()` có
 * đang `true` hay không tại đúng thời điểm `handle()` được gọi — SPEC §4.10 gọi `X/Y` là "trường
 * tính toán hiển thị trên portal", tức MỘT con số, không phải một con số tuỳ theo có ai đang mở
 * guard `client` hay không lúc câu truy vấn chạy. Có test riêng dựng một hồ sơ CHƯA công bố lên
 * portal để đo đúng phần này (vì trên một hồ sơ đã công bố, ba điều kiện còn lại của luật `Y` đã
 * trùng gần khớp với chính `ClientPortalScope`, nên bỏ hay giữ scope không còn lệch nhau nữa).
 *
 * Scope `SoftDeletingScope` thì được GIỮ: một tài liệu đã xoá mềm không còn trong hồ sơ, nên nó
 * không còn là "đã có tài liệu".
 */
class ChecklistProgress
{
    /**
     * Bí danh của bộ đếm tài liệu KHÁCH ĐỌC ĐƯỢC gắn vào một đầu mục (xem "Sửa lại checklist-05"
     * ở docblock lớp). Công khai vì bảng ở tab "Danh mục hồ sơ" hiện đúng con số này thành một
     * cột, và nó phải là CÙNG con số mẫu số đang dùng — không phải một phép đếm thứ hai viết lại
     * bên màn hình.
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
            ->filter(self::countedInTotal(...));

        return [
            'submitted' => $counted
                ->filter(fn (MatterChecklistItem $item): bool => in_array($item->status, self::SETTLED_STATUSES, true))
                ->count(),
            'total' => $counted->count(),
        ];
    }

    /**
     * **Dòng này có nằm trong mẫu số `Y` hay không** — tức thanh tiến độ có nói về nó hay không.
     *
     * Tách ra thành một hàm CÔNG KHAI vì `handle()` trả về hai SỐ NGUYÊN chứ không trả về các
     * dòng, nên không màn hình nào hỏi được nó "dòng này có được đếm không". Trước vòng này mỗi
     * màn hình tự nói lại điều kiện ấy: `MyMatters` có một bản, và khối "việc anh/chị cần làm" ở
     * `MatterProgress` thì KHÔNG hỏi gì cả và vì vậy liệt kê mười một dòng bên trên một thanh nói
     * về bốn. Một chỗ giữ luật, ba chỗ đọc nó.
     *
     * Điều kiện đọc `is_required` VÀ bí danh bộ đếm tài liệu, nên người gọi phải nạp bộ đếm ấy
     * bằng {@see self::countClientFacingDocuments()}; thiếu nó thì `?? 0` làm một đầu mục không
     * bắt buộc ĐÃ có tài liệu rơi ra khỏi `Y`. Đó là lý do hàm này đứng cạnh hàm kia thay vì ở
     * một lớp tiện ích nào khác.
     */
    public static function countedInTotal(MatterChecklistItem $item): bool
    {
        return $item->is_required
            || ((int) ($item->{self::DOCUMENT_COUNT_ALIAS} ?? 0)) > 0;
    }

    /**
     * Gắn bộ đếm "tài liệu khách ĐỌC ĐƯỢC" vào một truy vấn `matter_checklist_items` — ba điều
     * kiện của {@see Document::isReleasedToPortal()}, viết lại bằng `where` vì
     * `withCount` không gọi được một phương thức instance trên từng dòng con.
     *
     * Tách ra khỏi {@see self::handle()} vì bảng ở tab "Danh mục hồ sơ" cần ĐÚNG con số này trên
     * từng dòng, và một `withCount` viết lại lần thứ hai bên màn hình là cách để cột "Số tài
     * liệu" và mẫu số của thanh tiến độ nói hai chuyện khác nhau trên cùng một dòng.
     *
     * `withoutGlobalScope(ClientPortalScope::class)` tường minh, cùng thành ngữ
     * `StoresDocumentFile::scopelessly()` — xem "Phép đếm bỏ `ClientPortalScope`" ở docblock lớp.
     *
     * @param  Builder<MatterChecklistItem>  $items
     * @return Builder<MatterChecklistItem>
     */
    public static function countClientFacingDocuments(Builder $items): Builder
    {
        return $items->withCount([
            'documents as '.self::DOCUMENT_COUNT_ALIAS => fn (Builder $documents): Builder => $documents
                ->withoutGlobalScope(ClientPortalScope::class)
                ->where('client_can_view', true)
                ->where('status', DocumentStatus::Published->value)
                ->where('group', '!=', DocumentGroup::Internal->value),
        ]);
    }
}
