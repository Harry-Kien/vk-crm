<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\StoresDocumentFile;
use App\Enums\DocumentGroup;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
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
 * `status` được suy ra từ `$group` ở {@see self::defaultsFor()} và không có tham số nào ghi đè
 * được. Đây là cách "nhóm D: client_can_download vĩnh viễn false" (SPEC §4.11) trở thành một điều
 * không diễn đạt nổi ở tầng gọi, thay vì một điều mà mọi màn hình phải nhớ tự tay đặt đúng.
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

    public function handle(
        Matter $matter,
        User $actor,
        UploadedFile $file,
        DocumentGroup $group,
        string $title,
        ?MatterChecklistItem $checklistItem,
        DateTimeInterface|string|null $issuedAt,
    ): Document {
        // Bước 1.
        Gate::forUser($actor)->authorize('create', [Document::class, $matter]);

        // Bước 2.
        if ($checklistItem !== null && $checklistItem->matter_id !== $matter->getKey()) {
            throw ValidationException::withMessages([
                'matter_checklist_item_id' => [__('documents.upload.checklist_item_other_matter')],
            ]);
        }

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
        // cái nhóm viết lần thứ hai: {@see self::defaultsFor()} là nơi duy nhất biết nhóm nào ra
        // tới khách, nên một lần đổi bảng SPEC §4.11 ở đó kéo theo cả cái cổng này.
        if ($defaults['status'] === DocumentStatus::Published && $defaults['client_can_view']) {
            $transientDocument = (new Document)
                ->forceFill(['group' => $group])
                ->setRelation('matter', $matter);

            Gate::forUser($actor)->authorize('publish', $transientDocument);
        }

        // Bước 4.
        $this->guardFile($file);

        // Bước 5.
        $document = DB::transaction(function () use (
            $matter, $actor, $file, $group, $title, $checklistItem, $issuedAt, $defaults,
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
                'published_at' => $defaults['status'] === DocumentStatus::Published ? now() : null,
                'published_by' => $defaults['status'] === DocumentStatus::Published ? $actor->getKey() : null,
                'issued_at' => $issuedAt,
            ]);

            $this->storeFile($document, $file);

            return $document;
        });

        // Bước 6.
        Audit::record('document_uploaded', $document, [
            'matter_id' => $matter->getKey(),
            'matter_checklist_item_id' => $checklistItem?->getKey(),
            'group' => $group->value,
            'status' => $defaults['status']->value,
            'client_can_view' => $defaults['client_can_view'],
            'client_can_download' => $defaults['client_can_download'],
        ], $actor);

        return $document;
    }

    /**
     * Bảng "Quy tắc mặc định khi tạo" ở SPEC §4.11, chép thẳng thành mã. `match` vét cạn trên
     * enum nên thêm một nhóm mới vào `DocumentGroup` sẽ là một lỗi `UnhandledMatchError` ngay lần
     * chạy đầu, không phải một nhóm âm thầm nhận mặc định của nhóm khác.
     *
     * @return array{status: DocumentStatus, client_can_view: bool, client_can_download: bool}
     */
    private function defaultsFor(DocumentGroup $group): array
    {
        return match ($group) {
            // A — khách cung cấp, nhân viên nộp thay: khách xem và tải được ngay.
            DocumentGroup::ClientProvided => [
                'status' => DocumentStatus::Published,
                'client_can_view' => true,
                'client_can_download' => true,
            ],
            // B — văn bản văn phòng phát hành: còn phải đi hết vòng đời trước khi khách thấy.
            // C — văn bản từ cơ quan nhà nước: nhân sự đọc trước, công bố sau (SPEC §6.5).
            DocumentGroup::Issued, DocumentGroup::Authority => [
                'status' => DocumentStatus::InternalDraft,
                'client_can_view' => false,
                'client_can_download' => false,
            ],
            // D — hồ sơ công việc nội bộ. `client_can_download` là **vĩnh viễn** false; ở đây nó
            // chỉ là giá trị khởi tạo, còn cái giữ cho nó false là `PublishDocument` chặn tuyệt
            // đối nhóm D — nên không Action nào bật được nó lên.
            // Nói cho đủ: "không Action nào" không phải "không đường nào". Một lệnh `update()`
            // thẳng trên model (một form Filament ở Task 6, một lệnh console) vẫn ghi được
            // `client_can_download = true` lên một dòng nhóm D, vì `documents` không có ràng buộc
            // nào và `Document` không có hook nào chặn. Khách không thấy tài liệu đó (global scope
            // và `DocumentPolicy` đều loại nhóm D), nên đây là một dòng dữ liệu nói dối chứ chưa
            // phải một lỗ hổng — xem báo cáo Task 3, đã đề nghị một guard ở tầng model.
            DocumentGroup::Internal => [
                'status' => DocumentStatus::InternalDraft,
                'client_can_view' => false,
                'client_can_download' => false,
            ],
        };
    }
}
