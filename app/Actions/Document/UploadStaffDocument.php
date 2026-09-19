<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\StoresDocumentFile;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Nhân sự nội bộ đưa một tệp vào hồ sơ (SPEC §4.11 bảng "Quy tắc mặc định khi tạo"). Đường vào
 * của khách hàng là `SubmitClientDocument` (§6.6, Task 4) — hai Action riêng vì quyền, nhóm mặc
 * định và việc đánh version khác nhau; cái GIỐNG nhau (cổng tệp, quét virus, cách lưu) nằm chung
 * ở `StoresDocumentFile`.
 *
 * Thứ tự, có chủ đích:
 *
 *  1. Quyền TRƯỚC tệp. `DocumentPolicy::create($actor, $matter)` uỷ tiếp cho `MatterPolicy::update`
 *     nên một lời gọi vào vụ việc đã xoá mềm, hoặc của người không thấy vụ việc, dừng lại ở đây —
 *     trước khi hệ thống bỏ công đọc, quét và ghi một tệp 20 MB xuống đĩa.
 *  2. Đầu mục danh mục, nếu có, phải thuộc ĐÚNG vụ việc này. Không có ràng buộc cơ sở dữ liệu nào
 *     buộc `documents.matter_id` khớp `matter_checklist_items.matter_id`, nên một tham số truyền
 *     nhầm (hoặc một `Select` bị sửa trong trình duyệt) sẽ lặng lẽ nối tài liệu của hồ sơ này vào
 *     danh mục của hồ sơ khác — một liên kết chéo giữa hai khách hàng. Ném `ValidationException`
 *     chứ không `DomainException`: đây đúng là một ô nhập sai, và khoá `matter_checklist_item_id`
 *     được chọn để Filament gắn được câu lỗi vào đúng ô cùng tên nếu màn hình ở Task 6 đặt tên ô
 *     như vậy (không có gì ở đây bắt buộc nó phải thế).
 *  3. Nếu bộ mặc định của nhóm ĐÃ ra tới khách ngay lúc tạo thì đòi thêm cổng công bố.
 *  4. Cổng tệp và quét virus (`guardFile()`), NGOÀI transaction — xem docblock `StoresDocumentFile`.
 *  5. Trong transaction: tạo `Document` với bộ mặc định theo nhóm, gắn tệp, rồi ghi nhật ký kiểm
 *     toán với actor tường minh.
 *
 * **Caller không chọn được ba cờ khách hàng.** `client_can_view`, `client_can_download` và
 * `status` được suy ra từ `$group` ở `StoresDocumentFile::defaultsFor()` và không có tham số nào
 * ghi đè được — xem docblock phương thức đó, nơi bảng SPEC §4.11 được đọc cho CẢ hai Action.
 *
 * **Nhóm A của nhân viên nộp thay LÀ một lần công bố.** SPEC §4.11 cho nó `status = published`,
 * `client_can_view = true` ngay lúc tạo, nên sau lời gọi này có một người ngoài văn phòng đọc
 * được một tệp mà người nộp chọn nội dung. Vì vậy nó đi qua đúng cổng của `PublishDocument`
 * (`document.publish`) và ghi đúng loại dòng nhật ký của một lần công bố. Ba nhóm còn lại không
 * đổi: "nhân viên nộp thay" ở SPEC §4.11 vẫn là việc một trợ lý làm được, chỉ không còn làm được
 * ở nhóm ra thẳng tới khách.
 */
class UploadStaffDocument
{
    use StoresDocumentFile;

    /** Đúng độ rộng cột `documents.title` ở SPEC §4.11 và ở migration. */
    private const MAX_TITLE_LENGTH = 250;

    public function handle(
        Matter $matter,
        User $actor,
        UploadedFile $file,
        DocumentGroup $group,
        string $title,
        ?MatterChecklistItem $checklistItem = null,
        DateTimeInterface|string|null $issuedAt = null,
    ): Document {
        // Bước 1.
        Gate::forUser($actor)->authorize('create', [Document::class, $matter]);

        // Bước 2.
        if ($checklistItem !== null && $checklistItem->matter_id !== $matter->getKey()) {
            throw ValidationException::withMessages([
                'matter_checklist_item_id' => [__('documents.upload.checklist_item_other_matter')],
            ]);
        }

        // Một đầu mục đã xoá mềm không còn nằm trong danh mục hồ sơ mà ai cũng nhìn thấy, nhưng
        // `documents.matter_checklist_item_id` vẫn nhận id của nó: hệ quả là một tài liệu treo
        // vào một dòng không hiện ra ở đâu, và thanh tiến độ X/Y ở Task 6 đếm thiếu nó mãi mãi.
        // Xảy ra thật khi một màn hình giữ đối tượng đã nạp từ trước, hoặc khi caller dùng
        // `withTrashed()`.
        if ($checklistItem !== null && $checklistItem->trashed()) {
            throw ValidationException::withMessages([
                'matter_checklist_item_id' => [__('documents.upload.checklist_item_deleted')],
            ]);
        }

        // `documents.title` là `varchar(250)`. SQLite không bao giờ phàn nàn, nên một tiêu đề dài
        // hơn đi qua cả bộ test rồi mới thành lỗi 500 trên MariaDB ở chế độ strict — đúng loại
        // khác biệt mà ràng buộc toàn cục của kế hoạch M4 dặn phải tự canh. Đếm bằng
        // `mb_strlen` vì `varchar(250)` trên cột utf8mb4 đếm KÝ TỰ, và một tiêu đề tiếng Việt
        // 250 ký tự dài hơn 250 byte.
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => [__('documents.upload.title_required')],
            ]);
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw ValidationException::withMessages([
                'title' => [__('documents.upload.title_too_long', ['max' => self::MAX_TITLE_LENGTH])],
            ]);
        }

        $issuedAtDate = $this->parseIssuedAt($issuedAt);

        $defaults = $this->defaultsFor($group);

        // Bước 3: nộp vào một nhóm RA TỚI KHÁCH NGAY LÚC TẠO là một lần công bố, nên nó đi qua
        // đúng cái cổng của việc công bố. Task 2 đặt `document.publish` làm ranh giới giữa "làm
        // hồ sơ" và "quyết định số phận một tài liệu" (nó gác `DocumentPolicy::delete` và
        // `::publish`); đưa một tệp ra cổng khách hàng là quyết định số phận một tài liệu, và
        // nhóm là một THAM SỐ của caller nên nội dung tệp là bất cứ thứ gì người nộp tải lên.
        //
        // Hỏi qua `Gate` trên một `Document` CHƯA LƯU, gắn sẵn nhóm và quan hệ `matter` — cùng
        // thành ngữ với `TransitionMatterStage` khi nó hỏi `StageLogPolicy::publish`. Không chép
        // điều kiện của policy ra đây: một ngày `publish` siết thêm thì chỗ này siết theo.
        //
        // Điều kiện "ra tới khách ngay lúc tạo" đọc ra TỪ CHÍNH bộ mặc định, không từ một chữ
        // cái nhóm viết lần thứ hai: `StoresDocumentFile::defaultsFor()` là nơi duy nhất biết
        // nhóm nào ra tới khách, nên một lần đổi bảng SPEC §4.11 ở đó kéo theo cả cái cổng này.
        $releasedAtCreation = $this->releasesToClientAtCreation($defaults);

        if ($releasedAtCreation) {
            $transientDocument = (new Document)
                ->forceFill(['group' => $group])
                ->setRelation('matter', $matter);

            Gate::forUser($actor)->authorize('publish', $transientDocument);
        }

        // Bước 4.
        $this->guardFile($file);

        // Bước 5.
        return DB::transaction(function () use (
            $matter, $actor, $file, $group, $title, $checklistItem, $issuedAtDate, $defaults, $releasedAtCreation,
        ): Document {
            $document = Document::query()->create([
                'matter_id' => $matter->getKey(),
                'matter_checklist_item_id' => $checklistItem?->getKey(),
                'group' => $group,
                'title' => $title,
                'status' => $defaults['status'],
                'version' => 1,
                'uploader_type' => $actor->getMorphClass(),
                'uploader_id' => $actor->getKey(),
                'client_can_view' => $defaults['client_can_view'],
                'client_can_download' => $defaults['client_can_download'],
                // Nhóm A của nhân viên nộp thay ra tới khách ngay, nên nó CÓ một thời điểm công
                // bố và một người chịu trách nhiệm về việc đó. Để trống hai cột này sẽ tạo ra một
                // tài liệu khách đang đọc mà không dòng nào nói ai đã đưa nó ra và từ lúc nào —
                // đúng thứ SPEC §10.6 bắt ghi lại cho mọi lần công bố tài liệu.
                'published_at' => $releasedAtCreation ? now() : null,
                'published_by' => $releasedAtCreation ? $actor->getKey() : null,
                'issued_at' => $issuedAtDate,
            ]);

            $this->storeFile($document, $file);

            if ($checklistItem !== null && $releasedAtCreation) {
                $this->settleChecklistItem($checklistItem, $actor);
            }

            // Nhật ký kiểm toán nằm BÊN TRONG transaction, cùng lý do với `PublishDocument` và
            // `TransitionMatterStage`: ngoài transaction thì có một khoảng — ngắn, nhưng có —
            // mà bản ghi đã commit còn dòng nhật ký thì chưa ghi. Ở đây khoảng đó nặng hơn hẳn,
            // vì một lần nộp nhóm A commit ở trạng thái khách đọc được ngay: một tiến trình
            // chết đúng lúc để lại một tài liệu khách đang xem mà SPEC §10.6 không có dòng nào
            // nói ai đưa nó ra.
            Audit::record('document_uploaded', $document, [
                'matter_id' => $matter->getKey(),
                'matter_checklist_item_id' => $checklistItem?->getKey(),
                'group' => $group->value,
                'status' => $defaults['status']->value,
                'client_can_view' => $defaults['client_can_view'],
                'client_can_download' => $defaults['client_can_download'],
            ], $actor);

            // Và một dòng `document_published` NỮA khi bộ mặc định đã đưa tài liệu ra tới khách.
            // Hai dòng cho một thao tác là cố ý: `document_uploaded` trả lời "tệp vào hệ thống
            // lúc nào", `document_published` trả lời "tệp ra tới khách lúc nào" (SPEC §10.6 bắt
            // ghi lại MỌI lần công bố tài liệu). Gộp chúng lại sẽ khiến một truy vấn dựng lại
            // các lần công bố — lọc theo tên sự kiện, cách duy nhất có — im lặng bỏ sót đúng
            // nhóm không ai bấm nút công bố. Hình dạng thuộc tính chép theo `PublishDocument`
            // để hai nguồn của cùng một câu hỏi đọc được bằng cùng một truy vấn.
            if ($releasedAtCreation) {
                Audit::record('document_published', $document, [
                    'matter_id' => $matter->getKey(),
                    'client_id' => $matter->client_id,
                    'group' => $group->value,
                    'version' => $document->version,
                    'client_can_view' => $defaults['client_can_view'],
                    'client_can_download' => $defaults['client_can_download'],
                    'republished' => false,
                ], $actor);
            }

            return $document;
        });
    }

    /**
     * Ngày ban hành đi vào đây từ một ô nhập, nên nó có thể là bất cứ chuỗi nào người dùng gõ.
     * `Carbon::parse()` ném `InvalidFormatException` — một `InvalidArgumentException`, tức nằm
     * ngoài hợp đồng `DomainException`/`ValidationException` mà mọi màn hình M4 được dặn bắt, và
     * vì vậy nó đi thẳng lên thành lỗi 500 cho một lỗi gõ phím. Đổi thành lỗi xác thực gắn đúng
     * tên cột, cùng cách bước 2 xử lý một đầu mục danh mục sai.
     *
     * Chuỗi rỗng hoặc toàn khoảng trắng được hiểu là "không có ngày" chứ không phải "hôm nay":
     * `Carbon::parse('')` trả về thời điểm hiện tại, và một ô để trống biến thành ngày ban hành
     * hôm nay là một giá trị bịa ra, không phải một giá trị thiếu.
     */
    private function parseIssuedAt(DateTimeInterface|string|null $issuedAt): ?CarbonInterface
    {
        if ($issuedAt instanceof DateTimeInterface) {
            return Carbon::instance($issuedAt);
        }

        if ($issuedAt === null || trim($issuedAt) === '') {
            return null;
        }

        try {
            return Carbon::parse($issuedAt);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages([
                'issued_at' => [__('documents.upload.issued_at_invalid')],
            ]);
        }
    }

    /**
     * Một lần nộp thay khách ở nhóm A đóng luôn đầu mục danh mục nó được gắn vào.
     *
     * SPEC không nói câu này: §6.6 bước 8 (`pending_review`) mô tả luồng KHÁCH nộp qua portal,
     * nơi chưa ai trong văn phòng nhìn thấy tệp. Ở đây thì ngược lại — nhóm A nghĩa là "khách
     * cung cấp, nhân viên nộp thay", tức người nộp đã cầm tệp trên tay, đã đọc nó đủ để biết nó
     * thuộc nhóm A và khớp với đầu mục nào. Đặt `pending_review` sẽ là xếp hàng công việc của
     * chính mình để chính mình duyệt, và một cái cổng không ai thật sự duyệt là cái cổng người
     * ta học cách bấm cho xong — đúng lập luận kế hoạch M4 đã dùng cho lần ghi đè kiểm tra xung
     * đột. Nên trạng thái là `accepted`, và `reviewed_by`/`reviewed_at` ghi lại AI đã chấp nhận
     * nó, chứ không để trống như một dòng tự nhiên đúng.
     *
     * Ba nhóm còn lại không đụng tới: một văn bản toà (C), một bản đơn văn phòng soạn (B) hay
     * một ghi chú nội bộ (D) gắn vào đầu mục để tiện tra cứu KHÔNG phải giấy tờ khách phải nộp,
     * nên chúng không được tự đóng một dòng trong danh mục của khách.
     *
     * `rejection_reason` bị xoá cùng lúc: lý do từ chối hiện thẳng cho khách (SPEC §6.7), nên
     * một đầu mục đã `accepted` mà còn treo câu "ảnh bị mờ" là một dòng nói dối.
     */
    private function settleChecklistItem(MatterChecklistItem $checklistItem, User $actor): void
    {
        $checklistItem->update([
            'status' => ChecklistItemStatus::Accepted,
            'rejection_reason' => null,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
        ]);
    }
}
