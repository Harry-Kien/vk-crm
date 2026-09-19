<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\StoresDocumentFile;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Events\ClientDocumentSubmitted;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Support\Audit;
use App\Support\Scopes\ClientPortalScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
 *     `ClientPortalScope` ra một cách tường minh — xem {@see self::scopelessly()}. Một Action mà
 *     tính đúng đắn phụ thuộc vào việc guard nào đang mở là một Action đúng cho tới lần đầu ai đó
 *     gọi nó từ một job hoặc một lệnh console.
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
 * **Không ghi dòng nhật ký `document_published`.** `UploadStaffDocument` ghi thêm dòng đó cho
 * nhóm A vì một lần nộp thay khách LÀ một lần văn phòng đưa tài liệu ra. Ở đây không ai trong văn
 * phòng đưa ra thứ gì, nên một dòng `document_published` sẽ làm mọi lần rà soát "văn phòng đã
 * công bố những gì" đếm thêm những tài liệu không ai trong văn phòng quyết định. Dấu vết của lần
 * nộp này là `document_submitted`.
 */
class SubmitClientDocument
{
    use StoresDocumentFile;

    public function handle(
        MatterChecklistItem $checklistItem,
        ClientUser $actor,
        UploadedFile $file,
    ): Document {
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
        // `canSeeMatter(null)` cho `false`, tức `Gate` ngay dưới sẽ từ chối. Vì vậy `$matter`
        // chắc chắn khác `null` KỂ TỪ SAU lời gọi `Gate::authorize`, không phải kể từ dòng này.
        $matter = $this->scopelessly(Matter::query())->find($item->matter_id);
        $item->setRelation('matter', $matter);

        // Bước 1, phần quyết định. KÈM đầu mục — xem docblock lớp.
        Gate::forUser($actor)->authorize('create', [Document::class, $item]);

        // Bước 2-5: đuôi tệp, MIME thật, kích thước, quét virus. Cùng một cổng với nhân sự,
        // không có bản nới lỏng cho khách — nếu có thì cổng đã mở đúng ở phía người ngoài.
        // NGOÀI transaction, xem docblock `StoresDocumentFile`.
        $this->guardFile($file);

        return DB::transaction(function () use ($item, $matter, $actor, $file): Document {
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

            $previous = $this->latestClientSubmission($locked);

            $defaults = $this->defaultsFor(DocumentGroup::ClientProvided);
            $releasedAtCreation = $this->releasesToClientAtCreation($defaults);

            // Bước 7 chạy TRƯỚC bước 6, cố ý: medialibrary chỉ gắn được tệp vào một model đã có
            // khoá chính, nên `Document` phải tồn tại trước khi `storeFile()` chạy. Cả hai nằm
            // trong cùng một transaction nên không có trạng thái dở dang nào commit được.
            $document = Document::query()->create([
                'matter_id' => $locked->matter_id,
                'matter_checklist_item_id' => $locked->getKey(),
                'group' => DocumentGroup::ClientProvided,
                'title' => $locked->name,
                'status' => $defaults['status'],
                'version' => $previous === null ? 1 : $previous->version + 1,
                'parent_document_id' => $previous?->getKey(),
                'uploader_type' => $actor->getMorphClass(),
                'uploader_id' => $actor->getKey(),
                'client_can_view' => $defaults['client_can_view'],
                'client_can_download' => $defaults['client_can_download'],
                // `published_at` có giá trị vì tệp này ở trong tầm tay khách kể từ giây phút nó
                // được tạo, y như nhóm A của `UploadStaffDocument`. `published_by` thì KHÔNG:
                // cột đó là khoá ngoại tới `users`, và người đưa tài liệu này ra không phải nhân
                // sự — họ là chính khách hàng. Điền đại một nhân sự nào đó vào đây (luật sư phụ
                // trách chẳng hạn) sẽ là ghi vào nhật ký một quyết định người đó chưa hề ra. Ai
                // đưa tệp vào hệ thống đọc ở cặp `uploader_type`/`uploader_id`.
                'published_at' => $releasedAtCreation ? now() : null,
                'published_by' => null,
                // Khách không khai ngày ban hành: màn hình nộp tệp ở SPEC §8.4 là "chọn đầu mục
                // → tải tệp lên → xem trước → gửi", không có ô nào cho ngày. Một ngày bịa ra ở
                // đây sẽ đứng cạnh những ngày ban hành thật của nhóm B và C mà không phân biệt
                // được.
                'issued_at' => null,
            ]);

            // Bước 6.
            $this->storeFile($document, $file);

            // Bước 8.
            $this->markPendingReview($locked);

            // SPEC §10.6 không liệt kê "khách nộp tệp", vì bảng đó liệt kê những việc văn phòng
            // làm. Dòng này vẫn phải có, và lý do hẹp hơn "vì không có dấu vết nào khác": trait
            // `LogsActivity` trên `Document` CÓ ghi một dòng `created` mang `version` và
            // `matter_checklist_item_id`, nhưng causer của nó suy ra từ phiên đăng nhập theo
            // resolver mặc định của spatie, thứ đọc guard mặc định (`web`) — trống rỗng trong
            // một request portal. Nên đây là dòng DUY NHẤT nêu đích danh tài khoản khách hàng
            // đã gửi tệp lên.
            //
            // `client_id` chép vào đây chứ không để người đọc suy ra qua `matter`: `matters
            // .client_id` là một cột sửa được, nên một hồ sơ chuyển sang khách hàng khác sẽ viết
            // lại lịch sử của mọi lần nộp đã xảy ra. Cùng lý lẽ với `PublishDocument`.
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

            // Bước 9. Listener ở M6; sự kiện là `ShouldDispatchAfterCommit` nên dispatch bên
            // trong transaction là an toàn.
            event(new ClientDocumentSubmitted($document));

            return $document;
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
     * Bản gần nhất của CHUỖI NỘP LẠI trên đầu mục này (SPEC §6.6 bước 7), hoặc `null` nếu đây là
     * lần đầu.
     *
     * **Chỉ nhóm A.** Một tài liệu nhóm B, C hay D gắn được vào một đầu mục danh mục và đó là
     * việc hợp lệ — một ghi chú công việc nội bộ về đúng giấy tờ đó, một văn bản toà liên quan;
     * kế hoạch Task 6 nói thẳng điều đó khi bàn cách đếm thanh tiến độ `X/Y`. Nhưng chuỗi version
     * ở bước 7 là chuỗi các LẦN NỘP cùng một giấy tờ, nên nối bản nộp của khách vào một tài liệu
     * của văn phòng sẽ đánh số nó là "bản thứ hai của tài liệu đó"; với nhóm D thì
     * `parent_document_id` còn trỏ vào một dòng khách không bao giờ được thấy. Nhóm A là "khách
     * cung cấp" bất kể ai bấm nút tải lên (SPEC §4.11), nên một lần nhân viên nộp thay VẪN nằm
     * trong chuỗi: đó là cùng một tờ giấy.
     *
     * **`withTrashed()`.** Một bản đã xoá mềm vẫn chiếm số version của nó. Cấp lại số 1 cho một
     * tệp khác biến mọi dòng nhật ký cũ mang `version` (ở đây, ở `UploadStaffDocument` và ở
     * `PublishDocument`) thành câu không còn chỉ đúng bản nào — và một bản xoá mềm thì khôi phục
     * lại được, nên hai dòng cùng số có thể cùng sống lại trên một đầu mục.
     *
     * Sắp xếp theo `version` rồi tới khoá chính: hai bản cùng số version xuất hiện được khi có ai
     * ghi thẳng vào cột, hoặc khi hai lần nộp chạy song song trên một cơ sở dữ liệu mà
     * `lockForUpdate()` không có tác dụng. Trong cả hai trường hợp thứ tự phải vẫn xác định.
     */
    private function latestClientSubmission(MatterChecklistItem $checklistItem): ?Document
    {
        return $this->scopelessly(Document::query())
            ->withTrashed()
            ->where('matter_checklist_item_id', $checklistItem->getKey())
            ->where('group', DocumentGroup::ClientProvided->value)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Đọc lại đầu mục danh mục từ cơ sở dữ liệu, hoặc từ chối.
     *
     * **Ba tình huống, một câu trả lời.** Không tồn tại, đã bị xoá mềm khỏi danh mục, và không
     * phải của người đang hỏi — cả ba đều ra một `AuthorizationException` giống hệt nhau. Đó là
     * SPEC §10.10 ("không có quyền và không tồn tại đều trả 404") áp ở tầng Action chứ không chỉ
     * ở tầng HTTP: nếu một id không tồn tại trả về một lỗi KHÁC với một id của người khác, thì
     * chính cặp thông điệp đó là một cái máy dò — gửi id bất kỳ, đọc loại lỗi, biết bản ghi có
     * thật hay không. Hai trong ba tình huống dừng ở đây; tình huống thứ ba (của người khác) dừng
     * ở `Gate` ngay sau, và vì cả hai chỗ ném cùng một lớp exception nên người ngoài không phân
     * biệt được.
     *
     * Nói thẳng phần không chứng minh được: `DocumentPolicy::create()` cũng từ chối cả ba tình
     * huống (`visibleToPortal()` hỏi lại theo KHOÁ nên một dòng đã xoá hay không tồn tại đều trả
     * `false`), nên xoá lời từ chối ở đây vẫn để bộ test xanh — đã đo bằng mutation. Nó được giữ
     * vì nó chạy trước khi hệ thống đọc và quét tệp, và vì một Action không nên uỷ thác câu "bản
     * ghi này có tồn tại không" cho tầng phân quyền; nhưng đó là phòng thủ nhiều lớp, không phải
     * một điều kiện có test đứng sau.
     *
     * Vì vậy đây KHÔNG phải một `ValidationException` kèm câu chữ thân thiện, dù `lang/vi
     * /checklist.php` có sẵn một câu như thế: câu đó dành cho màn hình M5 hiển thị SAU khi đã
     * quyết định từ chối, không phải để phân biệt ba tình huống trên.
     */
    private function findChecklistItem(mixed $key, bool $lock = false): MatterChecklistItem
    {
        $query = $this->scopelessly(MatterChecklistItem::query());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($key) ?? throw new AuthorizationException(__('checklist.submit.item_unavailable'));
    }

    /**
     * Bỏ `ClientPortalScope` ra khỏi một truy vấn, tường minh.
     *
     * Action này chạy với guard `client` đang mở trong đời thật, nên mọi truy vấn của nó sẽ tự
     * động bị cắt theo khách đang đăng nhập nếu không gỡ scope ra. Nghe thì có vẻ an toàn hơn,
     * nhưng nó sai ở hai đầu:
     *
     * - **Sai về tính đúng đắn.** Scope trên `Document` đòi `client_can_view = true` và
     *   `status = published`, nên một bản nhóm A cũ đã bị tắt cờ hiển thị sẽ vô hình với phép
     *   tính version — và lần nộp mới lại mang số 1 lần nữa, ghi đè ý nghĩa của bản cũ. Chuỗi
     *   version phải đọc từ dữ liệu thật.
     * - **Sai về chỗ đặt quyết định.** Một Action để phạm vi dữ liệu phụ thuộc vào guard nào
     *   đang mở là một Action đúng cho tới lần đầu ai đó gọi nó từ một job, một lệnh console,
     *   hay một phiên thuộc về người khác. Ở đây quyền đã được hỏi một lần, tường minh, trên
     *   `$actor` — và câu trả lời đó phải là câu duy nhất quyết định.
     *
     * `ChecksPortalVisibility` bên trong policy vẫn chạy scope thật qua
     * `ClientPortalScope::actingAs($actor)`, nên tầng phân quyền không bị nới ra chút nào.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopelessly(Builder $query): Builder
    {
        return $query->withoutGlobalScope(ClientPortalScope::class);
    }
}
