<?php

namespace App\Actions\Document;

use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
 * # Luật, viết đủ vì cách đọc đã phải sửa BA lần
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
 *  - **"đã có tài liệu" nghĩa là có ít nhất một tài liệu NHÓM A** (`DocumentGroup::ClientProvided`)
 *    — không phải "khác nhóm D" (đính chính 2026-09-16) và cũng không phải "khách đọc được" (bản
 *    sửa checklist-05 lần đầu, M6.5 Task 17). Cả hai cách đọc trước đều sai theo cùng một hướng:
 *    chúng để một tài liệu VĂN PHÒNG tự đưa vào (nhóm B/C) kéo một đầu mục tuỳ chọn vào `Y`. Xem
 *    "**Sửa lại checklist-05, lần hai**" bên dưới cho lý do và bằng chứng.
 *
 * Điều kiện của `X` chỉ đọc cột `status` (thứ mà `UploadStaffDocument`, `ReviewChecklistItem` và
 * `MarkChecklistItemNotApplicable` ghi) và không hỏi bảng `documents` một câu nào — nhưng `X`
 * VẪN phụ thuộc vào bảng đó, vì nó chỉ chạy trên các dòng đã nằm trong `Y`. Chỗ duy nhất
 * `documents` được hỏi là định nghĩa của `Y`, và nó phải được hỏi ở đó, vì chính SPEC §4.10
 * định nghĩa `Y` bằng chữ "đã có tài liệu".
 *
 * # Sửa lại checklist-05, lần hai: "khách đọc được" cũng chưa đúng — phải là "khách NỘP"
 *
 * Đính chính 2026-09-16 viết `Y` gồm các đầu mục không bắt buộc "có ít nhất một tài liệu không
 * thuộc nhóm D" — tức nhóm A, B, C đều tính, bất kể trạng thái vòng đời. Bản sửa đầu tiên của
 * checklist-05 (M6.5 Task 17, trước rà soát vòng 1) đọc lại thành "khách ĐỌC ĐƯỢC" — ba điều
 * kiện của `Document::isReleasedToPortal()` (`client_can_view`, `published`, khác nhóm D) — với
 * lập luận: một quyết định nhóm B/C ĐÃ CÔNG BỐ không khác gì một tài liệu nhóm A về mặt "khách
 * đọc được gì". Lập luận đó SAI, và rà soát vòng 1 (finding C1, nghiêm trọng) chỉ ra đúng chỗ:
 * MỘT KHI quyết định ấy được công bố, nó đi qua đúng con đường mà bản sửa lần đầu định sửa — đầu
 * mục vẫn `missing` (chưa ai duyệt nó là "đã xong"), nên nó VẪN rơi vào "Giấy tờ chúng tôi còn
 * chờ ở anh/chị" mà `MatterProgress::outstandingItems()` vẽ ra, trong khi khách đã thấy chính
 * quyết định đó ở khối "Tài liệu" — script y hệt bug gốc, chỉ dịch pha từ "chưa công bố" sang
 * "đã công bố".
 *
 * Chốt lại: mẫu số trả lời đúng MỘT câu — **"văn phòng còn chờ KHÁCH nộp gì"** — không phải
 * "khách đọc được gì" và không phải "văn phòng đã có gì trong tủ hồ sơ". Một tài liệu chỉ là
 * bằng chứng "khách đã làm xong việc này" khi CHÍNH KHÁCH là người tạo ra nó — tức nhóm A
 * (`DocumentGroup::ClientProvided`, "khách cung cấp bất kể ai bấm nút tải lên", SPEC §4.11).
 * Một quyết định nhóm B/C, dù đã đi hết vòng đời tới `published`, vẫn là một tài liệu VĂN PHÒNG
 * tạo ra — nó có thể làm đầu mục hết còn là việc phải làm ở một nghĩa KHÁC (văn phòng tự quyết
 * định không cần khách nộp nữa), nhưng nghĩa đó có Action riêng
 * (`MarkChecklistItemNotApplicable`), không phải một cách ngầm định qua việc đính kèm tài liệu.
 * Test `ChecklistProgressTest` ghim: một quyết định nhóm B/C — dù `internal_draft` hay đã
 * `published`/`client_can_view` — đều KHÔNG kéo được đầu mục vào `Y`.
 *
 * # Phép đếm bỏ `ClientPortalScope`
 *
 * `withCount` áp GLOBAL SCOPE của `Document`. Phép đếm tự bỏ nó ra một cách tường minh
 * (`withoutGlobalScope`) để con số không phụ thuộc vào việc `ClientPortalScope::isActive()` có
 * đang `true` hay không tại đúng thời điểm `handle()` được gọi — SPEC §4.10 gọi `X/Y` là "trường
 * tính toán hiển thị trên portal", tức MỘT con số, không phải một con số tuỳ theo có ai đang mở
 * guard `client` hay không lúc câu truy vấn chạy.
 *
 * **Vòng sửa 2 — viết lại lý do sau khi luật `Y` đổi còn một điều kiện.** Bản trước lập luận
 * "trên một hồ sơ đã công bố, ba điều kiện còn lại của luật `Y` gần trùng với chính
 * `ClientPortalScope`, nên bỏ hay giữ scope không còn lệch nhau" — câu đó nói về BA điều kiện
 * (`client_can_view`, `published`, khác nhóm D) mà C1 đã bỏ. Từ C1, `Y` chỉ hỏi MỘT câu (`group
 * = ClientProvided`), thứ không có quan hệ gì với các điều kiện `ClientPortalScope` lọc theo
 * (phiên đăng nhập nào đang mở, hồ sơ đã công bố lên portal chưa) — nên phép bỏ scope này giờ
 * LUÔN cần thiết, không chỉ trên một hồ sơ chưa công bố. Test riêng vẫn dựng một hồ sơ CHƯA công
 * bố lên portal (`Matter::factory()->unpublished()`) để đo đúng phần này, vì đó là kịch bản DỄ
 * THẤY NHẤT phép bỏ scope tạo khác biệt — `ClientPortalScope` trên `Matter`/`Document` đóng cửa
 * hoàn toàn khi hồ sơ chưa công bố, nên không bỏ scope thì con số tụt về không bất kể tài liệu
 * nhóm A nào đã tồn tại.
 *
 * Scope `SoftDeletingScope` thì được GIỮ: một tài liệu đã xoá mềm không còn trong hồ sơ, nên nó
 * không còn là "đã có tài liệu".
 *
 * # M6 Task 8 — "còn thiếu" (SPEC §6.9) và đồng hồ "thiếu từ", cùng một chỗ với `X/Y`
 *
 * Định nghĩa "đầu mục BẮT BUỘC còn thiếu" (`is_required` và `status` thuộc {missing, rejected})
 * cùng đồng hồ "thiếu từ" từng sống PRIVATE trong `MattersMissingDocumentsWidget`. Task 8
 * (`RemindMissingDocuments`, thư nhắc khách) cần đúng định nghĩa đó, và một Action không được
 * `use` một lớp của Filament — chép lại là cách để widget "hồ sơ tắc quá 14 ngày" nói một chuyện
 * còn thư nhắc nói chuyện khác trên cùng một hồ sơ. Nên chúng chuyển vào đây, cạnh `X/Y`, và cả
 * widget lẫn Action gọi lại:
 *
 *  - {@see self::outstandingRequired()} — điều kiện lọc (SQL);
 *  - {@see self::missingSinceSql()} / {@see self::missingSince()} — đồng hồ, hai hình dạng của
 *    CÙNG một biểu thức `COALESCE(reviewed_at, created_at)`;
 *  - {@see self::mattersAwaitingClient()} — tập hồ sơ §6.9 (đang mở + đã công bố portal + còn
 *    đầu mục thiếu, tuỳ chọn "quá N ngày");
 *  - {@see self::outstandingRequiredItems()} — danh sách đầu mục của MỘT hồ sơ, để thư liệt kê.
 *
 * Không đụng vào `handle()`/`countedInTotal()`: `X/Y` và "còn thiếu" trả lời hai câu khác nhau
 * (mọi đầu mục bắt buộc đều nằm trong `Y`, nên mỗi dòng "còn thiếu" là một phần tử của `Y` chưa
 * vào `X` — nhưng `pending_review` cũng chưa vào `X` mà KHÔNG "còn thiếu": khách đã nộp).
 */
class ChecklistProgress
{
    /**
     * Bí danh của bộ đếm tài liệu NHÓM A gắn vào một đầu mục (xem "Sửa lại checklist-05, lần hai"
     * ở docblock lớp). Công khai vì bảng ở tab "Danh mục hồ sơ" hiện đúng con số này thành một
     * cột, và nó phải là CÙNG con số mẫu số đang dùng — không phải một phép đếm thứ hai viết lại
     * bên màn hình.
     *
     * **Đổi tên từ `client_facing_documents_count` (fix round 1, C1).** Cái tên cũ nói về TẦM
     * NHÌN ("khách nhìn thấy được") — đúng cho một quyết định nhóm B/C đã công bố, nhưng KHÔNG
     * còn đúng cho thứ hằng số này đếm kể từ khi `Y` chỉ tính nhóm A. Giữ tên cũ sẽ để cột "Số
     * tệp đã nộp" đọc một con số đúng ("0") nhưng do một cái tên SAI đứng đằng sau — một nhân sự
     * đọc code sẽ tưởng "0 tài liệu khách nhìn thấy" trong khi có thể có một quyết định nhóm C đã
     * `published` nằm ngay trên đầu mục đó. Tên mới nói đúng thứ được đếm: tài liệu do CHÍNH
     * KHÁCH gửi lên.
     */
    public const CLIENT_SUBMITTED_DOCUMENT_COUNT_ALIAS = 'client_submitted_documents_count';

    /** SPEC §6.9 bullet cuối: thiếu kéo dài quá số ngày này thì hồ sơ "đình trệ vì thiếu giấy tờ". */
    public const STUCK_AFTER_DAYS = 14;

    /**
     * Các trạng thái §6.9 gọi là "còn thiếu": khách chưa nộp, hoặc đã nộp và bị trả lại.
     * `pending_review` cố ý KHÔNG có mặt — khách đã nộp, quả bóng ở sân văn phòng.
     *
     * @var list<ChecklistItemStatus>
     */
    private const OUTSTANDING_STATUSES = [ChecklistItemStatus::Missing, ChecklistItemStatus::Rejected];

    /**
     * Hai trạng thái được tính là "đã xong" ở tử số `X`. `not_applicable` nằm cùng hạng với
     * `accepted` vì cả hai đều trả lời "văn phòng không còn chờ gì ở đầu mục này" — thứ duy nhất
     * thanh tiến độ nói.
     *
     * @var list<ChecklistItemStatus>
     */
    private const SETTLED_STATUSES = [ChecklistItemStatus::Accepted, ChecklistItemStatus::NotApplicable];

    /**
     * Một định nghĩa duy nhất của "đầu mục bắt buộc còn thiếu" (SPEC §6.9): điều kiện chọn hồ sơ,
     * bộ đếm ở widget, mốc "thiếu từ" và danh sách trong thư nhắc đều đi qua đây.
     *
     * @param  Builder<MatterChecklistItem>  $items
     * @return Builder<MatterChecklistItem>
     */
    public static function outstandingRequired(Builder $items): Builder
    {
        return $items
            ->where($items->qualifyColumn('is_required'), true)
            ->whereIn(
                $items->qualifyColumn('status'),
                array_map(static fn (ChecklistItemStatus $status): string => $status->value, self::OUTSTANDING_STATUSES),
            );
    }

    /**
     * Đồng hồ "thiếu từ" phía SQL: `COALESCE(reviewed_at, created_at)` của CHÍNH đầu mục.
     *
     * SPEC không đặt tên cho mốc bắt đầu của "tình trạng thiếu", và hai trạng thái có hai câu trả
     * lời tự nhiên khác nhau. Với `rejected`, `reviewed_at` là lúc văn phòng báo khách phải nộp
     * lại — đồng hồ chạy lại từ đó, vì một giấy tờ vừa bị từ chối hôm qua thì khách chưa kịp
     * thiếu. Với `missing`, chưa ai duyệt nên `reviewed_at` null và mốc còn lại đúng nghĩa là
     * `created_at`: đầu mục được sao từ mẫu lúc mở vụ việc (§4.10), tức lúc văn phòng bắt đầu chờ.
     * `updated_at` KHÔNG được dùng: nó nhích vì những lý do chẳng liên quan tới việc khách đã nộp
     * hay chưa (sửa tên đầu mục chẳng hạn), và mỗi lần nhích là một hồ sơ tắc 60 ngày tự đặt lại
     * về 0 — đúng lúc widget và thư nhắc phải lên tiếng.
     */
    public static function missingSinceSql(): string
    {
        return 'COALESCE(matter_checklist_items.reviewed_at, matter_checklist_items.created_at)';
    }

    /** Bản PHP của {@see self::missingSinceSql()} cho một đầu mục đã tải — cùng biểu thức, không phải định nghĩa thứ hai. */
    public static function missingSince(MatterChecklistItem $item): ?Carbon
    {
        return $item->reviewed_at ?? $item->created_at;
    }

    /**
     * Mốc "thiếu từ" SỚM NHẤT của một tập đầu mục — mốc của cả hồ sơ ("hồ sơ này chờ khách từ
     * lúc nào"). `null` khi tập rỗng.
     *
     * @param  Collection<int, MatterChecklistItem>  $items
     */
    public static function earliestMissingSince(Collection $items): ?Carbon
    {
        return $items
            ->map(fn (MatterChecklistItem $item): ?Carbon => self::missingSince($item))
            ->filter()
            ->min();
    }

    /**
     * Những đầu mục bắt buộc còn thiếu của MỘT hồ sơ, theo thứ tự danh mục — thứ thư nhắc liệt kê.
     * Bỏ `ClientPortalScope` tường minh như {@see self::countClientSubmittedDocuments()}: câu trả
     * lời không được phụ thuộc vào việc lúc này có phiên khách nào đang mở hay không.
     *
     * @return Collection<int, MatterChecklistItem>
     */
    public static function outstandingRequiredItems(Matter $matter): Collection
    {
        return self::outstandingRequired(
            MatterChecklistItem::query()
                ->withoutGlobalScope(ClientPortalScope::class)
                ->where('matter_id', $matter->getKey())
        )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Tập hồ sơ của SPEC §6.9: "matter đang mở, đã công bố portal, còn item BẮT BUỘC ở `missing`
     * hoặc `rejected`". `$stuckForDays` (tuỳ chọn) thu hẹp thành các hồ sơ có một đầu mục thiếu từ
     * quá ngần ấy ngày — ngưỡng "đình trệ" của §6.9 bullet cuối ({@see self::STUCK_AFTER_DAYS}).
     *
     * `Matter::scopeOpen()` (R8) là định nghĩa "đang mở" duy nhất của hệ thống;
     * `is_published_to_portal` vì chưa công bố thì khách không có đường nào để nộp, nên hồ sơ có
     * thiếu cũng không phải thiếu vì khách. Người gọi tự cộng điều kiện quyền xem của mình
     * (`listableBy()` ở widget) TRƯỚC khi truyền vào.
     *
     * @param  Builder<Matter>  $matters
     * @return Builder<Matter>
     */
    public static function mattersAwaitingClient(Builder $matters, ?int $stuckForDays = null): Builder
    {
        return $matters
            ->open()
            ->where('matters.is_published_to_portal', true)
            ->whereHas('checklistItems', function (Builder $items) use ($stuckForDays): void {
                self::outstandingRequired($items);

                if ($stuckForDays !== null) {
                    $items->whereRaw(self::missingSinceSql().' < ?', [now()->subDays($stuckForDays)]);
                }
            });
    }

    /** @return array{submitted: int, total: int} */
    public function handle(Matter $matter): array
    {
        $counted = self::countClientSubmittedDocuments($matter->checklistItems()->getQuery())
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
     * bằng {@see self::countClientSubmittedDocuments()}; thiếu nó thì `?? 0` làm một đầu mục không
     * bắt buộc ĐÃ có tài liệu rơi ra khỏi `Y`. Đó là lý do hàm này đứng cạnh hàm kia thay vì ở
     * một lớp tiện ích nào khác.
     */
    public static function countedInTotal(MatterChecklistItem $item): bool
    {
        return $item->is_required
            || ((int) ($item->{self::CLIENT_SUBMITTED_DOCUMENT_COUNT_ALIAS} ?? 0)) > 0;
    }

    /**
     * Gắn bộ đếm "tài liệu NHÓM A" (`DocumentGroup::ClientProvided`) vào một truy vấn
     * `matter_checklist_items` — đúng MỘT điều kiện kể từ fix round 1 (C1): trước đó hàm này còn
     * hỏi thêm `client_can_view`/`status = published`, những điều kiện luôn ĐÚNG cho nhóm A ngay
     * từ lúc tạo (`StoresDocumentFile::defaultsFor()`), nên chúng không loại thêm được gì — trừ
     * đúng cái không nên loại: một tài liệu nhóm A mà ai đó (một Action tương lai, hay một lệnh
     * sửa tay) tắt `client_can_view` đi. Bỏ hai điều kiện ấy để "nhóm A" là toàn bộ câu hỏi, đúng
     * như phán quyết "Y đếm CHỈ nhóm A" — không phải "nhóm A thoả thêm hai điều kiện".
     *
     * Đổi tên từ `countClientFacingDocuments()`: tên cũ nói về TẦM NHÌN, tên mới nói về NGƯỜI TẠO
     * — cùng lý do đổi tên hằng số ở trên.
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
    public static function countClientSubmittedDocuments(Builder $items): Builder
    {
        return $items->withCount([
            'documents as '.self::CLIENT_SUBMITTED_DOCUMENT_COUNT_ALIAS => fn (Builder $documents): Builder => $documents
                ->withoutGlobalScope(ClientPortalScope::class)
                ->where('group', DocumentGroup::ClientProvided->value),
        ]);
    }
}
