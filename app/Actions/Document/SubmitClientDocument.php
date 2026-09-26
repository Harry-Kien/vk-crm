<?php

namespace App\Actions\Document;

use App\Actions\Concerns\ChecksAccountActive;
use App\Actions\Document\Concerns\StoresDocumentFile;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Events\ClientDocumentSubmitted;
use App\Filament\Portal\Pages\MatterProgress;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Khách hàng nộp một tệp vào một đầu mục danh mục hồ sơ — chín bước SPEC §6.6.
 *
 * **Đây là Action đầu tiên trong hệ thống mà người thao tác KHÔNG phải nhân sự.** Mọi Action
 * trước nó nhận một `User`; cái này nhận một `ClientUser`, và sự khác biệt không nằm ở kiểu tham
 * số mà ở chỗ người đó đứng ngoài văn phòng. Ba hệ quả được cài thẳng vào hình dạng của Action:
 *
 *  1. **Không có tham số nào đáng tin.** `$checklistItem` là một đối tượng đi ra từ một tham số
 *     trên URL của portal, nên Action ĐỌC LẠI dòng dữ liệu thật và dùng bản đọc lại đó cho mọi
 *     quyết định; đối tượng caller đưa vào chỉ được dùng cho đúng một việc là lấy khoá chính.
 *     Cùng kỷ luật mà `PublishDocument` đã dùng, và ở đây nó cần hơn: ai sửa được
 *     `$item->matter_id` trong bộ nhớ thì nối được tài liệu của mình vào danh mục một hồ sơ khác.
 *  2. **Không tham số nào của khách chạm tới nhóm, trạng thái hay hai cờ hiển thị.** Nhóm là A,
 *     cố định (SPEC §6.6 bước 7); ba giá trị còn lại đọc từ bảng SPEC §4.11 qua
 *     `StoresDocumentFile::defaultsFor()`. Khách không đặt được tiêu đề, không đặt được ngày ban
 *     hành, không bật được cờ nào.
 *  3. **Không đọc `auth()`.** Danh tính người nộp là `$actor`, và mọi truy vấn của Action bỏ
 *     `ClientPortalScope` ra một cách tường minh — xem `StoresDocumentFile::scopelessly()`. Một
 *     Action mà tính đúng đắn phụ thuộc vào guard nào đang mở là một Action đúng cho tới lần đầu
 *     ai đó gọi nó từ một job hoặc một lệnh console. Hệ quả mà chính câu đó kéo theo và bản đầu
 *     bỏ sót: `is_active` cũng phải được hỏi ở đây, vì `ClientUser::canAccessPanel()` — chỗ duy
 *     nhất đọc cột đó cho tới hôm nay — chỉ canh cửa panel. Xem `ChecksAccountActive` và SPEC
 *     §10.9.
 *
 * **Cổng quyền đi KÈM ngữ cảnh, và đó là điều kiện để nó có nghĩa.** `DocumentPolicy::create()`
 * có hai nhánh cho khách: nhánh KHÔNG có ngữ cảnh trả `true` vô điều kiện (nó chỉ trả lời câu hỏi
 * giao diện "khách nói chung có nộp tệp được không"), nhánh CÓ ngữ cảnh mới là cái chặn thật.
 * Rà soát Task 2 ghi lại lỗ hổng đó và giao cho Task 4 nghĩa vụ gọi kèm đầu mục; đây là chỗ thực
 * hiện nghĩa vụ đó. Gọi `Gate::authorize('create', Document::class)` ở đây sẽ là một cái cổng
 * luôn mở.
 *
 * **Không đi qua cổng `document.publish`, dù nhóm A ra tới khách ngay lúc tạo.**
 * `UploadStaffDocument` phải qua cổng đó vì ở đó người nộp là nhân sự và nội dung tệp là thứ văn
 * phòng chọn; cổng ấy hỏi "ai được quyết định đưa một tài liệu ra trước mặt khách". Ở đây tệp đi
 * theo chiều ngược lại: nó xuất phát từ khách, và thứ "ra tới khách" là chính cái họ vừa gửi lên.
 * SPEC §5 phần Portal cho khách đúng việc này mà không kèm điều kiện nào khác.
 *
 * **Quyền được hỏi HAI LẦN, và lần thứ hai mới là lần có hiệu lực.** Giữa hai lần có
 * `guardFile()`, nơi một lần quét virus được phép chạy tới 30 giây. Ba mươi giây là thừa để ai
 * đó bấm nút gỡ hồ sơ khỏi portal hoặc xoá mềm nó — hai việc người ta làm CÓ CHỦ ĐÍCH. Lần hỏi
 * đầu tồn tại để một id bịa dừng lại ở câu truy vấn rẻ nhất; lần hỏi trong transaction mới là
 * câu trả lời được dùng.
 *
 * Nói cho đúng phạm vi của câu đó, vì bản đầu viết nó rộng hơn sự thật: thứ được hỏi lại trên DỮ
 * LIỆU MỚI là đầu mục, hồ sơ và `Gate`. `accountIsActive()` thì KHÔNG — cả hai lần nó đọc cùng
 * một đối tượng `$actor` trong bộ nhớ, thứ caller nạp trước khi gọi, nên một tài khoản bị vô hiệu
 * hoá ĐÚNG trong 30 giây quét vẫn đi qua được cả hai lần hỏi. Điều đó chấp nhận được ở đây và
 * không phải một lỗ hổng SPEC §10.9: đối tượng đó đến từ phiên đăng nhập của chính request đang
 * chạy, và §10.9 đòi hiệu lực "ngay ở request KẾ TIẾP" — request kế tiếp nạp lại `$actor` từ cơ
 * sở dữ liệu và dừng ở `canAccessPanel()`. Nếu một ngày nào đó cần chặt hơn thì chỗ sửa là đọc
 * lại `$actor` dưới transaction, không phải gọi `accountIsActive()` thêm một lần nữa trên cùng
 * đối tượng cũ.
 *
 * **Một câu từ chối duy nhất cho ba tình huống** — SPEC §10.10, xem {@see self::refuse()}. Không
 * tồn tại, đã bị xoá khỏi danh mục, và không phải của người đang hỏi phải không phân biệt được ở
 * cả LỚP lẫn CÂU CHỮ; bản đầu chỉ làm được vế thứ nhất, và vế thứ hai mới là thứ người ngoài
 * quan sát được.
 *
 * **Trạng thái của đầu mục KHÔNG phải một cái cổng, và đó là một quyết định.** Khách nộp được
 * vào một đầu mục đang `accepted`, `pending_review` hay `not_applicable`, không chỉ `missing` và
 * `rejected`. SPEC §8.3 chỉ vẽ nút nộp ở hai trạng thái sau, nhưng đó là một chuyện của MÀN
 * HÌNH: Action này phải đúng cho một caller không phải cái màn hình đó (cùng lý lẽ với việc
 * không đọc `auth()`). Ba lý do cụ thể:
 *
 *  - Chặn lại sẽ cắt mất đường sửa sai DUY NHẤT của khách. SPEC không có thao tác "bỏ duyệt",
 *    nên một khách nhận ra mình gửi nhầm trang sau khi văn phòng đã duyệt sẽ phải gọi điện.
 *  - Bản nộp mới KHÔNG ghi đè bản cũ: nó là một `Document` mới trong chuỗi version (bước 7), và
 *    đầu mục quay về `pending_review` với `reviewed_by`/`reviewed_at` bị xoá, nên dòng dữ liệu
 *    không khai một lần duyệt chưa xảy ra.
 *  - Cái hại thật của việc nộp lại không giới hạn là TẦN SUẤT (thông báo dồn dập, đĩa, thời gian
 *    của clamd), và SPEC §10.3 đã giao đúng việc đó cho một giới hạn 20 tệp/giờ/tài khoản. Một
 *    cổng trạng thái không phải một giới hạn tần suất; nó chỉ cấm thêm một việc hợp lệ.
 *
 * **Không ghi dòng nhật ký `document_published`.** `UploadStaffDocument` ghi thêm dòng đó cho
 * nhóm A vì một lần nộp thay khách LÀ một lần văn phòng đưa tài liệu ra. Ở đây không ai trong văn
 * phòng đưa ra thứ gì, nên một dòng `document_published` sẽ làm mọi lần rà soát "văn phòng đã
 * công bố những gì" đếm thêm những tài liệu không ai trong văn phòng quyết định. Dấu vết của lần
 * nộp này là `document_submitted`. Hệ quả cho người đi tìm — "khách đọc được những gì" là HỢP
 * của hai tên sự kiện — được phát biểu ở docblock của {@see Audit}.
 *
 * **R10 (M6.5 Task 17, checklist-03) — MỘT lần nộp có thể gồm NHIỀU tệp, và chúng là MỘT
 * version.** Bản đầu (M4) nhận đúng một `UploadedFile`; ô tải lên của SPEC §8.4 khi đó chỉ nhận
 * một tệp, nên CCCD hai mặt hay một hợp đồng bốn trang buộc khách phải nộp nhiều LẦN — và mỗi lần
 * là một `Document` mới trong chuỗi version, nên `StoresDocumentFile::nextInSubmissionChain()`
 * (đọc "đã nộp trang trước rồi" từ chính chuỗi đó) hiểu nhầm trang sau là một lần NỘP LẠI trang
 * trước: mặt sau thành version 2, `parent_document_id` trỏ về mặt trước, và khối "Tài liệu" của
 * khách (chỉ vẽ bản mới nhất mỗi chuỗi — {@see MatterProgress}) làm mặt
 * trước biến mất ngay khi mặt sau tới. Finding checklist-03.
 *
 * Sửa ở ĐÚNG MỘT chỗ mỗi bên của ranh giới "một lần nộp = một version": `nextInSubmissionChain()`
 * được hỏi ĐÚNG MỘT LẦN cho cả lô (không phải một lần mỗi tệp), và mọi `Document` sinh ra từ lô đó
 * dùng CHUNG một cặp `version`/`parent_document_id`. `Concerns/RefusesWhileAwaitingReview.php` thì
 * KHÔNG đổi: luật "không đóng một đầu mục đang có tệp chờ xem" vẫn đúng y hệt, vì nó nói về việc
 * ĐÓNG (accept/not_applicable), không nói về việc BỔ SUNG (khách tự nộp thêm) — hai luật sống cạnh
 * nhau mà không chạm nhau, xem thêm ở docblock
 * {@see StoresDocumentFile::nextInSubmissionChain()} cho luật
 * "bổ sung vào version đang chờ, không phải version mới" chạy TRONG chính hàm đó.
 *
 * Mỗi tệp của lô vẫn đi qua ĐỦ chín bước SPEC §6.6 CHO RIÊNG NÓ — `guardFile()` (đuôi, MIME thật,
 * kích thước, quét virus) chạy trên TỪNG tệp, không chỉ tệp đầu tiên, vì một lô hai tệp mà một tệp
 * là mã độc thì cả lô phải dừng, không được "chấp nhận nửa lô". Ba thứ CÒN LẠI vẫn dùng chung cho
 * cả lô, đúng như tên gọi "một lần nộp": một lần hỏi quyền (đầu mục không đổi giữa các tệp của
 * cùng một lô), một lần khoá hàng đầu mục, một lần chuyển trạng thái `pending_review`.
 */
class SubmitClientDocument
{
    use ChecksAccountActive;
    use StoresDocumentFile;

    /**
     * @param  list<UploadedFile>  $files  Một lần nộp — một hoặc nhiều tệp, tất cả cùng một
     *                                     version (xem docblock lớp, mục R10).
     * @return Collection<int, Document>
     */
    public function handle(
        MatterChecklistItem $checklistItem,
        ClientUser $actor,
        array $files,
    ): Collection {
        // Vòng sửa 1: một lô rỗng không phải một điều khách bấm ra được — màn hình SPEC §8.4 đòi
        // ô tệp trước khi cho bấm Gửi — nhưng Action này không được TIN caller đã kiểm tra thay
        // mình (cùng kỷ luật với "không đọc `auth()`" ở docblock lớp). Không chặn ở đây, một lô
        // rỗng chạy hết tới `markPendingReview()`: đầu mục chuyển `pending_review`, phát sự kiện
        // báo đội ngũ có tệp mới cần xem, và `$documents` rỗng trả về cho caller — một trạng thái
        // nói dối cả khách lẫn văn phòng về một lần nộp chưa hề xảy ra. `InvalidArgumentException`
        // (không phải `ValidationException`/`FileRejected`/`DomainException`): đây là một lỗi của
        // NGƯỜI GỌI, không phải một điều khách làm sai — cùng quy ước với
        // `AddMatterDeadline::handle()`.
        if ($files === []) {
            throw new InvalidArgumentException('SubmitClientDocument::handle() được gọi với một lô rỗng.');
        }

        // Bước 1, phần đọc lại. Đọc TRƯỚC khi hệ thống bỏ công đọc, quét và ghi một tệp 20 MB
        // xuống đĩa: một id bịa hoặc một đầu mục của người khác phải dừng lại ở câu truy vấn
        // rẻ nhất.
        $item = $this->findChecklistItem($checklistItem->getKey());

        // Gắn sẵn quan hệ `matter` TRƯỚC khi đưa đối tượng cho `Gate`, và gắn bằng một truy vấn
        // đã bỏ `ClientPortalScope`. Không có dòng này, `DocumentPolicy::create()` đọc
        // `$item->matter` như một quan hệ lười — và lần nạp đó chạy dưới guard NÀO ĐANG MỞ, chứ
        // không dưới `$actor`. Hệ quả đo được: gọi Action với actor là chủ hồ sơ trong khi phiên
        // portal thuộc về một khách hàng khác thì quan hệ trả `null`, `canSeeMatter(null)` trả
        // `false`, và một lần nộp hợp lệ bị từ chối. Chiều ngược lại thì policy vẫn an toàn —
        // `ChecksPortalVisibility` hỏi lại bằng `ClientPortalScope::actingAs($actor)` — nên đây
        // là sửa một chỗ TỪ CHỐI SAI, không phải nới một cái cổng.
        //
        // `Matter::query()` loại hồ sơ đã xoá mềm, nên một hồ sơ đã xoá cho `null` ở đây — và
        // `canSeeMatter(null)` cho `false`, tức cổng quyền ngay dưới sẽ từ chối.
        //
        // Kết quả của lần hỏi này KHÔNG được mang xuống dưới transaction: nó trả lời cho thời
        // điểm HÔM NAY, trước một cửa sổ quét dài tới 30 giây. Nó tồn tại để một id bịa hay một
        // đầu mục của người khác dừng lại ở câu truy vấn rẻ nhất, trước khi hệ thống bỏ công
        // đọc, quét và ghi một tệp 20 MB xuống đĩa — không phải để tiết kiệm lần hỏi thứ hai.
        $matter = $this->scopelessly(Matter::query())->find($item->matter_id);
        $item->setRelation('matter', $matter);

        // Bước 1, phần quyết định. KÈM đầu mục — xem docblock lớp.
        $this->authorize($actor, $item);

        // Bước 2-5, cho TỪNG tệp của lô — xem docblock lớp, mục R10. Một lô hai tệp mà tệp thứ
        // hai là mã độc phải dừng CẢ LÔ trước khi bất kỳ tệp nào của nó chạm tới đĩa `private`:
        // vòng lặp này chạy NGOÀI transaction, trước khi `Document` đầu tiên được tạo, nên một
        // lần `FileRejected` ở tệp thứ N không để lại tệp nào của N-1 tệp trước đó.
        foreach ($files as $file) {
            $this->guardFile($file);
        }

        return DB::transaction(function () use ($item, $actor, $files): Collection {
            // Đọc lại LẦN NỮA, dưới khoá, và mọi giá trị ghi xuống dưới đây đều lấy từ bản đọc
            // lại này chứ không từ `$item`.
            //
            // Lần đọc này không thừa so với lần ở trên: giữa hai lần có `guardFile()`, và lần
            // quét virus trong đó được phép chạy tới 30 giây (`config('vkcrm.clamav.timeout')`).
            // Ba mươi giây là thừa để một trợ lý xoá đầu mục khỏi danh mục hồ sơ, và nếu không
            // đọc lại thì tệp rơi vào một dòng không còn tồn tại. Có test đúng cho cảnh đó —
            // "đầu mục bị xoá trong lúc quét virus" — và xoá lần đọc này làm nó đỏ.
            //
            // `lockForUpdate()` thì KHÁC: nó nối tiếp hai lần nộp song song trên cùng một đầu
            // mục trên MariaDB, nhưng bộ test chạy SQLite, nơi nó không sinh ra khoá nào. Không
            // test nào trong dự án chứng minh được phần khoá của câu này.
            $locked = $this->findChecklistItem($item->getKey(), lock: true);

            // Và HỎI LẠI QUYỀN, trên bản đọc lại. Lần hỏi trước cửa sổ quét không còn trả lời
            // được cho thời điểm này: giữa hai lần có tới 30 giây, và trong 30 giây đó một nhân
            // sự có thể bấm nút gỡ hồ sơ khỏi portal, hoặc xoá mềm nó — hai việc người ta làm
            // CÓ CHỦ ĐÍCH, vì một lý do. Không hỏi lại thì lần nộp vẫn hạ cánh và để lại đúng
            // thứ cái nút kia vừa được bấm để ngăn: một tài liệu nhóm A đã công bố, khách xem
            // được, nằm trên một hồ sơ không còn ở trên portal — cộng một đầu mục
            // `pending_review` và một thông báo gọi đội ngũ vào xem một hồ sơ đã đóng.
            //
            // `setRelation()` chứ không để `Gate` tự nạp quan hệ, cùng lý do với lần hỏi ở trên:
            // một quan hệ lười nạp dưới guard NÀO ĐANG MỞ sẽ trả `null` khi phiên portal thuộc
            // về khách hàng khác, và một lần nộp hợp lệ bị từ chối oan. Đã đo: bỏ dòng
            // `setRelation` làm đỏ đúng test "nộp được khi actor là chủ hồ sơ dù phiên đăng nhập
            // là người khác".
            //
            // Nói thẳng phần KHÔNG có test đứng sau: `Matter::query()` loại hồ sơ đã xoá mềm,
            // nhưng đổi nó thành `withTrashed()` KHÔNG làm đỏ test nào — `Gate` từ chối cả hai
            // đường, vì `visibleToPortal()` hỏi lại đầu mục bằng `whereHas('matter')` và câu đó
            // cũng loại hồ sơ đã xoá. Điều kiện có test đứng sau ở đây là chính lần hỏi `Gate`.
            $matter = $this->scopelessly(Matter::query())->find($locked->matter_id);
            $locked->setRelation('matter', $matter);
            $this->authorize($actor, $locked);

            // Bước 7, phần đánh số. ĐỊNH NGHĨA DUY NHẤT nằm ở `StoresDocumentFile
            // ::nextInSubmissionChain()`, nơi phát biểu luôn cái bất biến mà không chỉ mục cơ sở dữ
            // liệu nào diễn đạt nổi — và nơi `UploadStaffDocument` hỏi đúng cùng câu hỏi đó, vì một
            // lần nhân viên nộp thay ở nhóm A cũng là một mắt xích của cùng chuỗi.
            //
            // R10: MỘT lần hỏi chuỗi version cho CẢ LÔ, không một lần mỗi tệp — xem docblock lớp
            // và docblock `nextInSubmissionChain()`. Mọi `Document` của lô này dùng CHUNG cặp
            // version/parent bên dưới, đúng bất biến "một lần nộp là một version". (Vòng sửa 1:
            // bản trước gọi hàm này HAI LẦN liên tiếp — dòng đầu là một lần gọi chết, kết quả bị
            // ghi đè ngay bởi dòng thứ hai; `nextInSubmissionChain()` không có tác dụng phụ nên
            // hai lần gọi cho cùng kết quả và không test nào bắt được, nhưng vẫn là một dòng thừa.)
            $chain = $this->nextInSubmissionChain($locked, DocumentGroup::ClientProvided);

            $defaults = $this->defaultsFor(DocumentGroup::ClientProvided);
            $releasedAtCreation = $this->releasesToClientAtCreation($defaults);

            $documents = collect($files)->map(function (UploadedFile $file) use (
                $locked, $actor, $chain, $defaults, $releasedAtCreation,
            ): Document {
                // Bước 7 chạy TRƯỚC bước 6, cố ý: medialibrary chỉ gắn được tệp vào một model đã
                // có khoá chính, nên `Document` phải tồn tại trước khi `storeFile()` chạy. Cả hai
                // nằm trong cùng một transaction nên không có trạng thái dở dang nào commit được.
                $document = Document::query()->create([
                    'matter_id' => $locked->matter_id,
                    'matter_checklist_item_id' => $locked->getKey(),
                    'group' => DocumentGroup::ClientProvided,
                    'title' => $locked->name,
                    'status' => $defaults['status'],
                    'version' => $chain['version'],
                    'parent_document_id' => $chain['parent_document_id'],
                    'uploader_type' => $actor->getMorphClass(),
                    'uploader_id' => $actor->getKey(),
                    'client_can_view' => $defaults['client_can_view'],
                    'client_can_download' => $defaults['client_can_download'],
                    // `published_at` có giá trị vì tệp này ở trong tầm tay khách kể từ giây phút
                    // nó được tạo, y như nhóm A của `UploadStaffDocument`. `published_by` thì
                    // KHÔNG: cột đó là khoá ngoại tới `users`, và người đưa tài liệu này ra không
                    // phải nhân sự — họ là chính khách hàng. Điền đại một nhân sự nào đó vào đây
                    // (luật sư phụ trách chẳng hạn) sẽ là ghi vào nhật ký một quyết định người đó
                    // chưa hề ra. Ai đưa tệp vào hệ thống đọc ở cặp `uploader_type`/`uploader_id`.
                    'published_at' => $releasedAtCreation ? now() : null,
                    'published_by' => null,
                    // Khách không khai ngày ban hành: màn hình nộp tệp ở SPEC §8.4 là "chọn đầu
                    // mục → tải tệp lên → xem trước → gửi", không có ô nào cho ngày. Một ngày bịa
                    // ra ở đây sẽ đứng cạnh những ngày ban hành thật của nhóm B và C mà không
                    // phân biệt được.
                    'issued_at' => null,
                ]);

                // Bước 6.
                $this->storeFile($document, $file);

                return $document;
            });

            // Bước 8. MỘT LẦN cho cả lô — đầu mục chỉ có một trạng thái, không phải một trạng
            // thái mỗi tệp.
            $this->markPendingReview($locked);

            $documents->each(function (Document $document) use ($locked, $actor, $matter): void {
                // SPEC §10.6 không liệt kê "khách nộp tệp", vì bảng đó liệt kê những việc văn
                // phòng làm. Dòng này vẫn phải có, và lý do hẹp hơn "vì không có dấu vết nào
                // khác": trait `LogsActivity` trên `Document` CÓ ghi một dòng `created` mang
                // `version` và `matter_checklist_item_id`, nhưng causer của nó suy ra từ phiên
                // đăng nhập theo resolver mặc định của spatie, thứ đọc guard mặc định (`web`) —
                // trống rỗng trong một request portal. Nên đây là dòng DUY NHẤT nêu đích danh tài
                // khoản khách hàng đã gửi tệp lên — MỘT dòng cho MỖI tệp của lô, vì mỗi tệp là
                // một `Document` riêng và một lần rà soát "khách đã nộp gì, lúc nào" cần thấy cả
                // hai mặt CCCD, không chỉ một.
                //
                // `client_id` chép vào đây chứ không để người đọc suy ra qua `matter`: `matters
                // .client_id` là một cột sửa được, nên một hồ sơ chuyển sang khách hàng khác sẽ
                // viết lại lịch sử của mọi lần nộp đã xảy ra. Cùng lý lẽ với `PublishDocument`.
                //
                // Causer là `$actor`, truyền tường minh: đây là lần đầu tiên trong hệ thống một
                // `ClientUser` đứng tên một dòng nhật ký do Action ghi ra.
                Audit::record('document_submitted', $document, [
                    'matter_id' => $locked->matter_id,
                    'client_id' => $matter->client_id,
                    'matter_checklist_item_id' => $locked->getKey(),
                    'group' => DocumentGroup::ClientProvided->value,
                    'version' => $document->version,
                ], $actor);
            });

            // Bước 9. Listener ở M6; sự kiện là `ShouldDispatchAfterCommit` nên dispatch bên
            // trong transaction là an toàn. MỘT sự kiện cho CẢ LÔ, không một sự kiện mỗi tệp —
            // vòng sửa 1, finding I4 (xem docblock lớp {@see ClientDocumentSubmitted}): một lần
            // nộp CCCD hai mặt LÀ một hành động của khách, không phải hai, và đội ngũ chỉ cần
            // một thông báo cho nó. `$documents` mang đủ cả lô nên listener đọc được mọi tệp từ
            // một lần dispatch duy nhất.
            //
            // `EloquentCollection::make($documents)` — vòng sửa 2, finding 4: `$documents` ở đây
            // là một `Illuminate\Support\Collection` (từ `collect($files)->map(...)`), nhưng
            // `ClientDocumentSubmitted` đòi một `Illuminate\Database\Eloquent\Collection` — xem
            // docblock constructor của sự kiện đó cho lý do (`SerializesModels` chỉ nhận diện
            // Eloquent Collection để nén thành id, không nhận diện Support Collection).
            event(new ClientDocumentSubmitted(EloquentCollection::make($documents->all())));

            return $documents;
        });
    }

    /**
     * Bước 8 của SPEC §6.6, cộng phần dọn dẹp mà SPEC không viết ra nhưng dữ liệu đòi.
     *
     * `rejection_reason` bị xoá vì nó hiện THẲNG cho khách (SPEC §6.7, §8.3): một đầu mục đang
     * chờ văn phòng kiểm tra mà vẫn treo câu "ảnh bị mờ ở góc trên" nói với khách rằng lần nộp
     * vừa rồi đã bị từ chối, trong khi chưa ai mở nó ra.
     *
     * `reviewed_by`/`reviewed_at` bị xoá vì chúng trả lời "ai đã xem bản này, lúc nào", và sau
     * một lần nộp mới thì chưa ai xem bản mới. Giữ lại hai giá trị cũ là để dòng dữ liệu tự khai
     * một lần duyệt chưa xảy ra — cùng loại "dòng nói dối" mà `UploadStaffDocument
     * ::settleChecklistItem()` xoá theo chiều ngược lại.
     */
    private function markPendingReview(MatterChecklistItem $checklistItem): void
    {
        $checklistItem->update([
            'status' => ChecklistItemStatus::PendingReview,
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    /**
     * Đọc lại đầu mục danh mục từ cơ sở dữ liệu, hoặc từ chối bằng {@see self::refuse()}.
     *
     * Hai trong ba tình huống của SPEC §10.10 dừng ở đây (không tồn tại, đã bị xoá mềm khỏi danh
     * mục); tình huống thứ ba — đầu mục của người khác — dừng ở {@see self::authorize()}.
     *
     * Nói thẳng phần không chứng minh được: `DocumentPolicy::create()` cũng từ chối cả ba tình
     * huống (`visibleToPortal()` hỏi lại theo KHOÁ nên một dòng đã xoá hay không tồn tại đều trả
     * `false`), nên xoá lời từ chối ở đây vẫn để bộ test xanh — đã đo bằng mutation. Nó được giữ
     * vì nó chạy trước khi hệ thống đọc và quét tệp, và vì một Action không nên uỷ thác câu "bản
     * ghi này có tồn tại không" cho tầng phân quyền; nhưng đó là phòng thủ nhiều lớp, không phải
     * một điều kiện có test đứng sau.
     */
    private function findChecklistItem(mixed $key, bool $lock = false): MatterChecklistItem
    {
        $query = $this->scopelessly(MatterChecklistItem::query());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($key) ?? $this->refuse();
    }

    /**
     * Cổng quyền của bước 1, hỏi KÈM đầu mục — xem docblock lớp.
     *
     * `inspect()` chứ không `authorize()`, và đây là toàn bộ lý do hàm này tồn tại:
     * `Gate::authorize()` tự ném một `AuthorizationException` mang thông điệp mặc định của
     * Laravel — **"This action is unauthorized."**, tiếng Anh, viết cho một lập trình viên. Người
     * đọc câu đó ở đây là khách hàng đang đứng trước màn hình portal (SPEC §8 cấm thuật ngữ kỹ
     * thuật, §8.4 đòi mỗi câu lỗi phải nói ra việc cần làm tiếp theo), nên nó không dùng được ở
     * hai nghĩa cùng lúc.
     *
     * Và nó hỏng đúng chỗ quan trọng hơn: hai chỗ từ chối của Action ném **cùng một lớp** nhưng
     * **hai câu khác nhau**, mà thứ người ngoài quan sát được là CÂU CHỮ, không phải tên lớp. Một
     * cặp thông điệp khác nhau chính là cái máy dò mà SPEC §10.10 dựng lên để chặn: gửi một id
     * bất kỳ, đọc câu trả lời, biết bản ghi có thật hay không.
     */
    private function authorize(ClientUser $actor, MatterChecklistItem $item): void
    {
        // SPEC §10.9, và nó đứng ở ĐÂY chứ không ở policy: xem `ChecksAccountActive`. Một tài
        // khoản portal vừa bị vô hiệu hoá đi ra bằng đúng câu từ chối của ba tình huống kia —
        // không phải vì §10.10 đòi (việc một tài khoản bị khoá hay không thì chính chủ tài khoản
        // biết rõ hơn ai hết), mà vì màn hình M5 chỉ có một chỗ để hiện câu trả lời và câu đó
        // vẫn đúng việc cần làm tiếp theo: gọi cho văn phòng.
        if (! $this->accountIsActive($actor)) {
            $this->refuse();
        }

        if (Gate::forUser($actor)->inspect('create', [Document::class, $item])->denied()) {
            $this->refuse();
        }
    }

    /**
     * **Ba tình huống, MỘT câu.** Không tồn tại, đã bị xoá mềm khỏi danh mục, và không phải của
     * người đang hỏi — cả ba đi ra từ đúng dòng `throw` này, nên chúng không phân biệt được ở
     * tên lớp lẫn ở câu chữ. SPEC §10.10 áp ở tầng Action chứ không chỉ ở tầng HTTP.
     *
     * Đây KHÔNG phải một `ValidationException`: `AuthorizationException` là thứ tầng HTTP của
     * Laravel đổi thành 403/404, và M5 cần đúng hành vi đó. Câu chữ thì lấy từ `lang/vi
     * /checklist.php` vì màn hình M5 hiển thị chính nó cho khách.
     */
    private function refuse(): never
    {
        throw new AuthorizationException(__('checklist.submit.item_unavailable'));
    }
}
