<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\OpensChecklistItem;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Events\ChecklistItemRejected;
use App\Exceptions\ChecklistItemNotReviewable;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\Document;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Văn phòng duyệt hoặc từ chối một giấy tờ khách đã nộp — SPEC §6.7.
 *
 * **Cái được viết ra ở đây, khách sẽ đọc.** `rejection_reason` không phải một ghi chú nội bộ: nó
 * hiện nguyên văn trên portal (SPEC §6.7, §8.3 mục 4), cho một người đang lo lắng về hồ sơ của
 * chính mình. Ba thứ đi ra từ nhận xét đó:
 *
 *  - **Ngưỡng 20 ký tự là một ràng buộc về CHẤT LƯỢNG câu chữ, không phải một phép đo kỹ thuật.**
 *    SPEC §6.7 nói thẳng lý do nó tồn tại: không có nó thì trợ lý viết "không hợp lệ", và khách
 *    đọc xong vẫn không biết phải làm gì nên nộp lại đúng cái vừa bị từ chối. Đếm bằng
 *    `mb_strlen` — một câu 20 ký tự tiếng Việt có dấu dài 40 byte, và một ngưỡng đếm byte sẽ cho
 *    qua đúng những câu cụt lủn mà ngưỡng này sinh ra để chặn (bài học M3).
 *  - **Action không ghép thêm một chữ nào vào câu đó.** Không mã hồ sơ, không id bản ghi, không
 *    tên người duyệt, không tên cột. Thứ duy nhất nó làm với chuỗi người dùng nhập là `trim()`.
 *  - **Ba mẫu ở SPEC §6.7 nằm nguyên văn trong `lang/vi/checklist.php`** để giao diện điền một
 *    chạm, và cả ba đều dài hơn ngưỡng — có test ghim, vì một cái nút điền sẵn một câu rồi bị
 *    chính hệ thống từ chối là cái nút không ai bấm lần thứ hai.
 *
 * **Thứ tự các cổng.** Bốn cổng đầu — đọc lại bản ghi, giải hồ sơ, tài khoản còn hiệu lực,
 * `Gate`, rồi hồ sơ đã xoá mềm — nằm ở `OpensChecklistItem` vì `MarkChecklistItemNotApplicable`
 * phải trả lời giống hệt; docblock của trait đó giải thích cả thứ tự lẫn lần sửa thứ tự. Còn lại
 * ở Action này là hai cổng riêng của việc duyệt, theo đúng thứ tự:
 *
 *  1. cổng trạng thái của kết quả duyệt ({@see self::guardDecisionAgainstState()});
 *  2. xác thực ô nhập.
 *
 * **Xác thực ô nhập đứng SAU tất cả.** Người không có quyền trên hồ sơ này không đáng được biết
 * lý do họ nhập dài hay ngắn; họ chỉ đáng được biết là không.
 *
 * **Bản ghi được ĐỌC LẠI trong transaction**, và `$checklistItem` mà caller đưa vào chỉ dùng để
 * lấy khoá chính. Một đối tượng Eloquent là thứ ai cũng gán thuộc tính được, và `matter_id` của
 * nó chính là thứ quyết định `Gate` hỏi về hồ sơ nào.
 *
 * **HAI loại exception, không có loại thứ ba.** `ValidationException` cho ô nhập sai (gắn đúng
 * tên ô để Filament hiện tại chỗ), và `ChecklistItemNotReviewable` — một `DomainException` — cho
 * mọi thứ về BẢN GHI, kể cả lời từ chối vì thiếu quyền. Action này KHÔNG ném
 * `AuthorizationException`, và đó là một thay đổi có chủ đích khác với Action anh em
 * `SubmitClientDocument`: ở đó người đọc là khách trên portal và một mã 403/404 là kết cục đúng;
 * ở đây người đọc là nhân sự đang mở một màn hình mà `canAccess()` đã gác từ trước, và nguyên
 * nhân thật sự hay gặp nhất là một trang đã cũ. Một trang 403 ở tình huống đó vứt mất đúng câu
 * tiếng Việt vừa được viết ra để nói cho họ biết phải làm gì.
 *
 * Giá phải trả, nói thẳng: mọi caller của Action này BẮT BUỘC phải bắt `DomainException` (ràng
 * buộc toàn cục của kế hoạch M4), vì một `DomainException` không được bắt là một lỗi 500, không
 * phải một trang 403.
 *
 * **R11 (M6.5 Task 17, checklist-04) — duyệt gắn với đúng những tệp người duyệt đã THẤY.**
 * `$documentIds` là tập id tài liệu mà hộp xác nhận đã hiện ra lúc người duyệt MỞ nó
 * ({@see self::currentDocumentIds()}, gọi từ `ChecklistRelationManager` để dựng cả danh sách lẫn
 * ô ẩn mang tập id đó). Giữa lúc mở hộp và lúc bấm lưu, khách hoàn toàn có thể gửi thêm một tệp
 * (checklist-03 làm chuyện đó dễ hơn hẳn: một CCCD hai mặt giờ đi trong một lần, nhưng trang 3
 * riêng vẫn là một lần nộp riêng) hoặc gửi lại sau khi văn phòng vừa từ chối xong ở một tab khác
 * — cả hai đều đổi tập tài liệu "mới nhất" của đầu mục mà không đổi trạng thái `pending_review`
 * đủ để cổng trạng thái ở trên bắt được. `handle()` đọc lại tập đó DƯỚI KHOÁ, ngay cạnh lần đọc
 * lại đầu mục, và so với `$documentIds` — khác nhau thì từ chối bằng
 * {@see ChecklistItemNotReviewable::documentsChanged()} trước khi ghi bất cứ gì.
 *
 * `$documentIds === null` nghĩa là "caller không quan sát tập tài liệu nào" — bỏ qua lần so sánh
 * này, không phải mặc định coi là khớp. Đây là đường lùi có chủ đích cho những caller không phải
 * màn hình (job, lệnh console, hoặc test ở tầng Action không dựng cả màn hình), nơi không có "hộp
 * đã mở" nào để so — chứ KHÔNG phải một cách để màn hình bỏ qua luật này. `ChecklistRelationManager`
 * — lối vào DUY NHẤT của việc duyệt trong hệ thống — luôn truyền tập id thật, kể cả tập rỗng (một
 * đầu mục `missing` được duyệt "đã nhận" vì khách mang giấy ra tận nơi không có tài liệu nào cả).
 */
class ReviewChecklistItem
{
    use OpensChecklistItem;

    /**
     * SPEC §6.7 và SPEC §4.10 ("`status = rejected` thì `rejection_reason` bắt buộc, tối thiểu
     * 20 ký tự"). Cùng ngưỡng `RetractDocument` (M7 Task 7) dùng.
     */
    private const MIN_REJECTION_REASON_LENGTH = 20;

    /**
     * Tên sự kiện nhật ký của một lần duyệt hoặc từ chối (M13 Task 2). Cột P6 "Giấy tờ đã duyệt" đếm
     * đúng các dòng này (`ActivityOwningMatter::scopeEventsWithin()`), nên tên đi qua MỘT hằng số dùng
     * ở chính câu `Audit::record()` bên dưới — không để P6 đọc một chuỗi có thể trôi. Nhãn tiếng Việt:
     * `activity.events.checklist_item_reviewed` (`SingleSourceParityTest` ghim, vì
     * `ActivityLogEventTranslationsTest` chỉ quét chuỗi literal).
     */
    public const AUDIT_EVENT = 'checklist_item_reviewed';

    /**
     * @param  array<int, int|string>|null  $documentIds  Tập id tài liệu hộp xác nhận đã hiện —
     *                                                    xem docblock lớp, mục R11.
     */
    public function handle(
        MatterChecklistItem $checklistItem,
        User $actor,
        ChecklistItemStatus $decision,
        ?string $rejectionReason = null,
        ?array $documentIds = null,
    ): MatterChecklistItem {
        // TRƯỚC transaction, không bên trong — rà soát cuối M7 (I1), xem docblock `OpensChecklistItem`.
        $matterId = $this->checklistItemMatterId($checklistItem);

        return DB::transaction(function () use ($checklistItem, $actor, $decision, $rejectionReason, $documentIds, $matterId): MatterChecklistItem {
            // Đọc lại bản ghi, giải hồ sơ, hỏi quyền — bốn bước, một chỗ, dùng chung với
            // `MarkChecklistItemNotApplicable`: xem `OpensChecklistItem`, nơi SPEC §10.10 cho
            // danh mục hồ sơ được phát biểu. (Bốn BƯỚC, năm điều kiện từ chối: bước 3 hỏi cả tài
            // khoản còn hiệu lực lẫn `Gate`, bước 4 hỏi cả khoá ngoại hỏng lẫn hồ sơ đã xoá mềm.
            // Bản đầu của câu này viết "bốn cổng" trên một danh sách năm điều kiện.)
            // `$checklistItem` mà caller đưa vào chỉ dùng để lấy khoá chính. Câu ĐẦU TIÊN của
            // transaction là khoá `matters` bên trong lời gọi này.
            [$fresh, $matter] = $this->openChecklistItem($checklistItem, $actor, $matterId);

            $this->guardDecisionAgainstState($fresh, $decision);

            // R11 — xem docblock lớp. Đọc lại tập tài liệu HIỆN TẠI dưới cùng một khoá hàng mà
            // `openChecklistItem()` vừa giữ trên `matter_checklist_items` (đầu mục KHÔNG đổi
            // hàng khi có tài liệu mới, nên khoá đó không nối tiếp được một lần ghi vào
            // `documents` — nhưng nó nối tiếp được với chính Action này: hai lần duyệt song song
            // trên cùng đầu mục không thể cùng đọc "tập tài liệu hiện tại" rồi cùng qua cổng này
            // nữa, vì lần thứ hai chỉ chạy sau khi lần thứ nhất đã COMMIT và đổi `reviewed_at`).
            $currentDocumentIds = self::currentDocumentIds($fresh);

            if ($documentIds !== null && self::sortedIds($documentIds) !== self::sortedIds($currentDocumentIds)) {
                throw ChecklistItemNotReviewable::documentsChanged();
            }

            $reason = $this->resolveRejectionReason($decision, $rejectionReason);

            $fresh->update([
                'status' => $decision,
                // Lý do chỉ tồn tại ở nhánh từ chối. Một đầu mục đã nhận đủ mà còn treo câu "ảnh
                // bị mờ" là một dòng nói dối, và người đọc nó là khách hàng — cùng lý lẽ với
                // `UploadStaffDocument::settleChecklistItem()`.
                'rejection_reason' => $reason,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            // Từ chối là một việc khách phải được biết ngay (SPEC §9, mẫu `client.document_
            // rejected`). Sự kiện là `ShouldDispatchAfterCommit` nên dispatch trong transaction
            // là an toàn; listener thuộc M6.
            if ($decision === ChecklistItemStatus::Rejected) {
                event(new ChecklistItemRejected($fresh));
            }

            // Bên TRONG transaction, cùng lý do với `PublishDocument` và `UploadStaffDocument`:
            // ngoài transaction thì có một khoảng — ngắn, nhưng có — mà bản ghi đã commit còn
            // dòng nhật ký thì chưa ghi. Nói cho đủ: KHÔNG mutation nào phân biệt được hai chỗ
            // đặt, vì thứ nó đóng là một tiến trình chết đúng giữa hai câu lệnh. Câu này là một
            // lập luận, không phải một điều kiện có test đứng sau.
            //
            // `rejection_reason` được chép vào dòng nhật ký: `matter_checklist_items` chưa dùng
            // `LogsActivity`, nên đây là dấu vết DUY NHẤT của câu văn phòng đã nói với khách, và
            // một lần sửa lý do về sau ghi đè cột chứ không ghi đè dòng này. Chép nó vào đây
            // không làm lộ gì thêm: chính khách đã đọc câu đó.
            // `document_ids` — R11: id các tài liệu đã duyệt, đúng tập mà lần hỏi ở trên vừa
            // xác nhận là tập HIỆN TẠI (khớp `$documentIds` khi caller có truyền, hoặc chính
            // `$currentDocumentIds` khi không — dòng nhật ký vẫn nêu đích danh tệp nào, kể cả
            // với một caller không tự so sánh).
            Audit::record(self::AUDIT_EVENT, $fresh, [
                'matter_id' => $fresh->matter_id,
                'client_id' => $matter->client_id,
                'status' => $decision->value,
                'rejection_reason' => $reason,
                'document_ids' => $currentDocumentIds,
            ], $actor);

            return $fresh;
        });
    }

    /**
     * Tập id tài liệu "hiện tại" của một đầu mục — mọi tài liệu nhóm A (khách cung cấp, SPEC
     * §4.11) mang đúng số `version` LỚN NHẤT trong chuỗi nộp lại của đầu mục đó. Cùng định nghĩa
     * "bản mới nhất" mà {@see MatterProgress::documents()} dùng để vẽ
     * khối "Tài liệu" của khách — R10 cho một version nhiều tài liệu (CCCD hai mặt), nên "hiện
     * tại" là một TẬP, không phải một id.
     *
     * `withoutGlobalScope(ClientPortalScope::class)`, tường minh: đây là câu hỏi của VĂN PHÒNG
     * ("tệp nào đang chờ tôi duyệt"), không phải câu hỏi của khách, và một nhân sự đang mở cả hai
     * panel trong cùng trình duyệt (cookie phiên dùng chung — xem docblock `ClientPortalScope`)
     * không được phép nhận một tập rỗng chỉ vì guard `client` cũng đang xác thực.
     *
     * Công khai và `static` vì `ChecklistRelationManager` gọi đúng hàm này để dựng cả danh sách
     * tệp trong hộp duyệt lẫn ô ẩn mang tập id gửi ngược vào {@see self::handle()} — một nguồn sự
     * thật duy nhất cho "hộp đang hiện gì", không phải luật viết lại lần thứ hai bên màn hình.
     *
     * @return array<int, int>
     */
    public static function currentDocumentIds(MatterChecklistItem $checklistItem): array
    {
        $latestVersion = Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $checklistItem->matter_id)
            ->where('matter_checklist_item_id', $checklistItem->getKey())
            ->where('group', DocumentGroup::ClientProvided->value)
            ->max('version');

        if ($latestVersion === null) {
            return [];
        }

        return Document::query()
            ->withoutGlobalScope(ClientPortalScope::class)
            ->where('matter_id', $checklistItem->matter_id)
            ->where('matter_checklist_item_id', $checklistItem->getKey())
            ->where('group', DocumentGroup::ClientProvided->value)
            ->where('version', $latestVersion)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * So hai tập id KHÔNG PHÂN BIỆT thứ tự — `$documentIds` mà `ChecklistRelationManager` gửi lên
     * đi qua một ô ẩn của form (chuỗi JSON qua Livewire), còn `currentDocumentIds()` đọc thẳng từ
     * cột `id` tăng dần; hai nguồn không có lý do gì phải cùng thứ tự, chỉ cần cùng TẬP.
     *
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private static function sortedIds(array $ids): array
    {
        $normalized = array_map(fn (int|string $id): int => (int) $id, $ids);
        sort($normalized);

        return $normalized;
    }

    /**
     * Cổng trạng thái của SPEC §6.7, và nó BẤT ĐỐI XỨNG — hai nhánh duyệt không đối xứng vì hai
     * hậu quả không đối xứng.
     *
     * **`accepted` đi được từ mọi trạng thái.** Khách mang giấy tờ ra tận văn phòng đưa tay là
     * chuyện xảy ra hằng ngày, và lúc đó đầu mục vẫn đang `missing`. Chặn nhánh này sẽ buộc trợ
     * lý bịa ra một lần nộp trên portal để rồi tự duyệt lần nộp đó.
     *
     * **`rejected` thì phải có cái để từ chối.** `rejection_reason` không phải một ghi chú nội
     * bộ: nó hiện nguyên văn cho khách (SPEC §6.7, §8.3 mục 4) và kéo theo một email
     * `client.document_rejected` (SPEC §9). Từ chối một đầu mục `missing` là gửi cho khách câu
     * "ảnh anh/chị gửi bị mờ ở góc trên" về một tấm ảnh họ chưa từng gửi; `not_applicable` còn
     * tệ hơn một bậc, vì chính văn phòng vừa nói với họ rằng giấy tờ đó không cần nộp.
     *
     * Ba trạng thái còn lại đều đi được: `pending_review` là đường thường; `rejected` là lần sửa
     * lại một câu lý do viết chưa rõ — câu đó khách đang đọc nên phải sửa được; `accepted` là
     * lần văn phòng nhận ra mình duyệt nhầm, và đó là đường DUY NHẤT quay lại, vì SPEC không có
     * thao tác "bỏ duyệt".
     *
     * Đọc theo CỘT `status` chứ không theo "đầu mục này có dòng `documents` nào không": cột đó
     * là bản ghi của chính văn phòng về việc có gì đang chờ xem hay không, còn một dòng nhóm A
     * vẫn nằm đó sau khi một đầu mục được mở lại — và câu hỏi ở đây là "có gì đang chờ", không
     * phải "đã từng có gì".
     */
    private function guardDecisionAgainstState(MatterChecklistItem $item, ChecklistItemStatus $decision): void
    {
        if ($decision !== ChecklistItemStatus::Rejected) {
            return;
        }

        $nothingWaiting = [ChecklistItemStatus::Missing, ChecklistItemStatus::NotApplicable];

        if (in_array($item->status, $nothingWaiting, true)) {
            throw ChecklistItemNotReviewable::nothingToReject($item);
        }
    }

    /**
     * Câu sẽ hiện cho khách, hoặc `null` khi đầu mục được nhận đủ.
     *
     * Kết quả duyệt chỉ có hai giá trị (SPEC §6.7). `not_applicable` ở SPEC §4.10 là một trạng
     * thái có thật của đầu mục nhưng KHÔNG phải một kết quả duyệt — đánh dấu một giấy tờ là không
     * cần nộp là một quyết định về phạm vi hồ sơ, không phải một lần xem xét tệp khách gửi lên.
     * Thao tác đó có Action riêng: `MarkChecklistItemNotApplicable`.
     *
     * Hai câu `if` chứ không một `match` vét cạn — bản đầu của docblock này mô tả một `match`
     * chưa từng tồn tại. Hệ quả thực tế thì giống nhau (mọi giá trị ngoài hai cái kia dừng ở một
     * câu người dùng đọc được), nhưng khác ở một điểm có thật: thêm một giá trị mới vào
     * `ChecklistItemStatus` sẽ KHÔNG làm chỗ này nổ ra `UnhandledMatchError`, nó lặng lẽ rơi vào
     * nhánh "không phải kết quả duyệt". Đó là hành vi đúng ở đây — một trạng thái mới của đầu
     * mục không mặc nhiên là một kết quả duyệt mới — nhưng nó phải được viết ra đúng như nó là.
     */
    private function resolveRejectionReason(ChecklistItemStatus $decision, ?string $rejectionReason): ?string
    {
        if ($decision === ChecklistItemStatus::Accepted) {
            return null;
        }

        if ($decision !== ChecklistItemStatus::Rejected) {
            throw ValidationException::withMessages([
                'status' => [__('checklist.review.decision_not_allowed', [
                    'accepted' => ChecklistItemStatus::Accepted->label(),
                    'rejected' => ChecklistItemStatus::Rejected->label(),
                ])],
            ]);
        }

        $reason = trim((string) $rejectionReason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => [__('checklist.review.reason_required', [
                    'min' => self::MIN_REJECTION_REASON_LENGTH,
                ])],
            ]);
        }

        // `mb_strlen`, không `strlen` — xem docblock lớp.
        $length = mb_strlen($reason);

        if ($length < self::MIN_REJECTION_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'rejection_reason' => [__('checklist.review.reason_too_short', [
                    'length' => $length,
                    'min' => self::MIN_REJECTION_REASON_LENGTH,
                ])],
            ]);
        }

        // checklist-06 (M6.5 Task 17). SPEC §6.7 in ba mẫu lý do NGUYÊN VĂN, và mẫu thứ ba
        // ("Nộp nhầm tài liệu") mang cặp ngoặc vuông `[tên tài liệu đã nộp]`/`[tên đầu mục]` làm
        // CHỖ TRỐNG cho người duyệt tự điền tay — không phải tham số của `__()` (xem docblock
        // `lang/vi/checklist.php`, mục `rejection_templates`). `ChecklistRelationManager` tự
        // điền `[tên đầu mục]` bằng tên thật của dòng đang mở, nhưng `[tên tài liệu đã nộp]` thì
        // KHÔNG — màn hình không biết chắc khách đã gửi đúng tệp gì mà chỉ người duyệt vừa mở tệp
        // ra mới biết, nên nó vẫn là một chỗ trống phải điền tay. Bấm mẫu rồi gửi ngay mà quên
        // sửa (hoặc gõ tay để sót một cặp ngoặc) sẽ đưa nguyên văn `[tên …]` tới khách — đúng bug
        // gốc mà finding checklist-06 tả. Chặn ở đây, sau ngưỡng độ dài: một câu đủ dài nhưng còn
        // để sót chỗ trống vẫn là một câu không nói được gì với khách.
        //
        // Vòng sửa 1: chuẩn hoá về NFC TRƯỚC khi so — chuỗi nguồn `'[tên'` trong tệp PHP này là
        // NFC (chữ `ê` một điểm mã `U+00EA`), nhưng một bàn phím/hệ điều hành khác có thể gõ ra
        // NFD (`e` + dấu mũ tổ hợp `U+0302`, hai điểm mã) — hai chuỗi ĐỌC giống hệt nhau nhưng
        // `str_contains()` so BYTE nên không khớp, và một câu còn nguyên chỗ trống `[tên đầu
        // mục]` lọt qua cổng này tới thẳng khách. `\Normalizer` viết đủ tên — cùng lý do đã ghi ở
        // `PortalLoginThrottle::foldEmail()`: `App\Support\Normalizer` là một lớp khác của dự án.
        // Chuỗi vào không phải UTF-8 hợp lệ thì `normalize()` trả `false`; giữ nguyên `$reason` ở
        // đó thay vì biến nó thành rỗng, cùng kỷ luật với `foldEmail()`.
        $normalizedReason = \Normalizer::normalize($reason, \Normalizer::FORM_C);

        if (str_contains(is_string($normalizedReason) ? $normalizedReason : $reason, '[tên')) {
            throw ValidationException::withMessages([
                'rejection_reason' => [__('checklist.review.reason_placeholder')],
            ]);
        }

        return $reason;
    }
}
