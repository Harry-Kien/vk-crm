<?php

namespace App\Actions\Document;

use App\Actions\Document\Concerns\RefusesWhileAwaitingReview;
use App\Actions\Document\Concerns\StoresDocumentFile;
use App\Enums\ChecklistItemStatus;
use App\Enums\DocumentGroup;
use App\Exceptions\MatterChecklistReadOnly;
use App\Models\Document;
use App\Models\Matter;
use App\Models\MatterChecklistItem;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
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
 *  5. Trong transaction: ĐỌC LẠI hồ sơ và đầu mục dưới khoá, HỎI LẠI quyền trên bản đọc lại, rồi
 *     mới tạo `Document` với bộ mặc định theo nhóm, gắn tệp và ghi nhật ký kiểm toán với actor
 *     tường minh.
 *
 * **Mọi câu trả lời của bước 1-3 đều là câu trả lời cho thời điểm TRƯỚC lần quét, và lần quét
 * được phép chạy tới 30 giây** (`config('vkcrm.clamav.timeout')`). Ba mươi giây là thừa để một
 * người khác bấm một cái nút có chủ đích: xoá mềm hồ sơ, xoá một đầu mục khỏi danh mục, gỡ người
 * nộp khỏi đội ngũ — hoặc để chính khách hàng gửi tệp lên đúng đầu mục ấy. Vì vậy bước 5 đọc lại
 * cả hai bản ghi dưới khoá và hỏi lại CẢ HAI cổng quyền; bước 1-3 tồn tại để một id bịa hay một
 * người ngoài dừng lại ở câu truy vấn rẻ nhất, trước khi hệ thống bỏ công đọc, quét và ghi một
 * tệp 20 MB xuống đĩa — không phải để tiết kiệm lần hỏi thứ hai. Cùng kỷ luật, cùng lý do và
 * cùng hình dạng với `SubmitClientDocument`; ở đó nó đã có test, ở đây thì trước vòng rà soát
 * cuối M4 là chưa.
 *
 * Nói thẳng MỘT tình huống mà lần hỏi lại KHÔNG từ chối, để không ai đọc nhầm phạm vi của nó:
 * hồ sơ bị **gỡ khỏi portal** giữa chừng. `is_published_to_portal` không phải cổng của nhân sự ở
 * bất kỳ đâu trong hệ thống (`MatterPolicy::update` hỏi ba điều: chưa xoá mềm, có `matter.update`,
 * thấy được hồ sơ), và văn phòng làm hồ sơ chưa lên portal suốt ngày. Thứ giữ cho tệp không ra
 * tới khách là `ClientPortalScope`, thứ đòi hồ sơ phải ở trên portal — nên một tài liệu nhóm A
 * nộp vào một hồ sơ đã gỡ là vô hình với khách đúng như mọi tài liệu nộp trước lúc gỡ. Có test
 * ghim cả hai nửa của câu này.
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
 *
 * **Và nhóm A dừng lại khi đầu mục đang `pending_review`** — {@see RefusesWhileAwaitingReview},
 * dùng chung với `MarkChecklistItemNotApplicable`. Lý do đầy đủ nằm ở docblock trait đó; nói ngắn:
 * `settleChecklistItem()` ghi `accepted` kèm tên người vừa bấm nút tải lên, nên nếu khách vừa gửi
 * một tệp thì dòng dữ liệu khai một lần duyệt mà không ai mở tệp của khách ra xem.
 */
class UploadStaffDocument
{
    use RefusesWhileAwaitingReview;
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
        //
        // Câu này đọc `deleted_at` TRÊN ĐỐI TƯỢNG caller đưa vào, nên nó chỉ bắt được một lần xoá
        // đã xảy ra TRƯỚC lời gọi. Một lần xoá xảy ra trong lúc quét virus đi ra bằng cùng thông
        // điệp này, nhưng từ lần đọc lại dưới khoá ở bước 5 — chỗ đó mới là cái cổng có hiệu lực,
        // chỗ này chỉ để một tham số đã sai sẵn không phải chờ hết 30 giây mới biết.
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
            // Đọc lại hồ sơ, và HỎI LẠI cả hai cổng quyền trên bản đọc lại. Xem docblock lớp cho
            // lý do đầy đủ; nói ngắn: giữa bước 1-3 và dòng này có một lần quét dài tới 30 giây,
            // và `MatterPolicy::update` hỏi `! $matter->trashed()` — trên ĐỐI TƯỢNG được hỏi.
            // Đối tượng caller đưa vào đã được nạp trước lần quét, nơi `deleted_at` vẫn là null,
            // nên nó trả lời cho một hồ sơ có thể không còn tồn tại nữa.
            //
            // `withTrashed()` chứ không lọc sẵn hồ sơ đã xoá: điều kiện "chưa xoá mềm" thuộc về
            // `MatterPolicy::update`, một chỗ duy nhất, và chép nó ra đây là chép một luật ra chỗ
            // thứ hai.
            //
            // **Nhánh `null` không có test đứng sau, và mutation probe đã chứng minh: xoá nó đi
            // bộ test vẫn xanh.** Hôm nay nó không với tới được — `Matter::forceDeleting` ném
            // `MatterNotDestroyable`, nên không đường nào xoá cứng một hồ sơ. Nó được giữ vì cái
            // nó chặn không phải một luật chép lại mà là một đường KHÁC hẳn: `DocumentPolicy
            // ::create($actor, null)` rơi vào nhánh "không có ngữ cảnh", nhánh chỉ hỏi
            // `matter.update` chung chung và vì thế mở toang. Một cổng hỏng theo hướng CHO QUA
            // đắt hơn hẳn một nhánh thừa, và "hôm nay không với tới được" là một tính chất của
            // mã xung quanh, không phải của hàm này.
            //
            // `lockForUpdate()` (M7 Task 3, vòng sửa 1): cổng "vụ đã đóng" ở dưới đọc
            // `closed_at` của bản đọc lại này, và `TransitionMatterStage` ghi cột đó dưới khoá
            // `matters`. Khoá hàng hồ sơ TRƯỚC (rồi mới tới đầu mục ở dưới) là đúng thứ tự khoá
            // của cả hệ thống — hồ sơ trước, đầu mục sau — nên câu kiểm tra và lần ghi ở
            // `settleChecklistItem()` không còn một khe để vụ việc đóng lọt vào giữa.
            $freshMatter = $this->scopelessly(Matter::query())
                ->withTrashed()
                ->lockForUpdate()
                ->find($matter->getKey());

            if ($freshMatter === null) {
                throw new AuthorizationException;
            }

            Gate::forUser($actor)->authorize('create', [Document::class, $freshMatter]);

            if ($releasedAtCreation) {
                Gate::forUser($actor)->authorize('publish', (new Document)
                    ->forceFill(['group' => $group])
                    ->setRelation('matter', $freshMatter));
            }

            // Đọc lại đầu mục danh mục DƯỚI KHOÁ, và mọi giá trị ghi xuống dưới đây lấy từ bản
            // đọc lại này. Hai việc trong một câu:
            //
            //  - **đọc lại**: cổng ở bước 2 đọc đối tượng caller cầm trong tay, nên một lần xoá
            //    xảy ra trong lúc quét không tới được nó. Không có dòng này, tệp treo vào một
            //    dòng danh mục đã biến mất VÀ `settleChecklistItem()` ghi `accepted` lên chính
            //    dòng đã xoá đó. `MatterChecklistItem::query()` có `SoftDeletingScope`, nên một
            //    đầu mục vừa bị xoá cho `null` ở đây và đi ra bằng đúng câu của bước 2.
            //  - **khoá hàng**: đây là cái khoá mà bất biến chuỗi version ở
            //    {@see StoresDocumentFile::nextInSubmissionChain()} đòi. `latestInSubmissionChain()`
            //    tự có `lockForUpdate()`, nhưng trên một chuỗi RỖNG câu `SELECT … LIMIT 1 FOR
            //    UPDATE` chỉ lấy được gap lock, thứ tương thích lẫn nhau trên InnoDB: hai lần nộp
            //    đầu tiên chạy song song cùng đọc `null` và cùng ghi `version = 1`. Khoá trên
            //    HÀNG đầu mục thì nối tiếp chúng lại. Bộ test chạy SQLite nên phần khoá của câu
            //    này không có test chứng minh; phần đọc lại thì có.
            $lockedItem = null;

            if ($checklistItem !== null) {
                $lockedItem = $this->scopelessly(MatterChecklistItem::query())
                    ->lockForUpdate()
                    ->find($checklistItem->getKey());

                if ($lockedItem === null || $lockedItem->matter_id !== $freshMatter->getKey()) {
                    throw ValidationException::withMessages([
                        'matter_checklist_item_id' => [__('documents.upload.checklist_item_deleted')],
                    ]);
                }
            }

            // M7 Task 3, vòng sửa 1: danh mục hồ sơ của một vụ đã kết thúc là CHỈ ĐỌC (xem
            // {@see MatterChecklistReadOnly}), và lần nộp thay là một lối GHI vào danh mục mà ba
            // Action kia (`AddChecklistItem`, `ReviewChecklistItem`,
            // `MarkChecklistItemNotApplicable`) không phủ tới: ở nhóm A nó ghi `accepted` +
            // `reviewed_by`, xoá `rejection_reason` và thêm một version "mới nhất được chấp nhận"
            // — đổi đúng thứ mà gói bàn giao đọc. Đứng SAU `Gate` (mọi cổng quyền ở trên): đây là
            // câu về TRẠNG THÁI hồ sơ, chỉ nói cho người đã qua cổng quyền.
            //
            // Điều kiện là "có đầu mục", KHÔNG phải "nhóm A": danh mục đứng yên trọn vẹn, cả
            // trạng thái lẫn tập tài liệu gắn vào từng đầu mục. Tệp KHÔNG gắn đầu mục — ở mọi
            // nhóm, kể cả A — vẫn vào được vụ đã đóng, vì đó là đường nhân viên bổ sung hồ sơ cho
            // việc bàn giao.
            if ($lockedItem !== null && $freshMatter->isClosed()) {
                throw MatterChecklistReadOnly::make();
            }

            // Một lần nộp thay ở nhóm A ĐÓNG đầu mục lại (xem `settleChecklistItem()` ngay dưới),
            // nên nó chịu đúng cái cổng trạng thái mà `MarkChecklistItemNotApplicable` chịu. Điều
            // kiện viết bằng cùng một biến với lần ghi mà nó bảo vệ, để hai câu không lệch nhau.
            $settlesChecklistItem = $lockedItem !== null && $releasedAtCreation;

            if ($settlesChecklistItem) {
                $this->refuseWhileAwaitingReview($lockedItem);
            }

            // SPEC §6.6 bước 7, phía GHI. Nhóm A nghĩa là "khách cung cấp" BẤT KỂ ai bấm nút tải
            // lên (SPEC §4.11), nên một lần nộp thay là một mắt xích của cùng cái chuỗi mà
            // `SubmitClientDocument` dựng — cùng tờ giấy, chỉ khác người cầm nó lúc bấm nút. Hai
            // Action hỏi CÙNG một hàm ở `StoresDocumentFile::nextInSubmissionChain()`, nơi cái
            // bất biến "mỗi số version của nhóm A trên một đầu mục chỉ thuộc về một tài liệu"
            // được phát biểu ra — không chỉ mục cơ sở dữ liệu nào diễn đạt nổi nó.
            //
            // Trước đây chỗ này ghi thẳng `'version' => 1` và không đặt `parent_document_id`,
            // nên một lần nộp thay sau hai lần khách nộp sinh ra dòng nhóm A THỨ HAI mang số 1
            // trên cùng đầu mục — và `document_submitted{version:1}` với `document_published
            // {version:1}` từ đó chỉ vào hai tài liệu khác nhau.
            $chain = $this->nextInSubmissionChain($lockedItem, $group);

            $document = Document::query()->create([
                'matter_id' => $freshMatter->getKey(),
                'matter_checklist_item_id' => $lockedItem?->getKey(),
                'group' => $group,
                'title' => $title,
                'status' => $defaults['status'],
                'version' => $chain['version'],
                'parent_document_id' => $chain['parent_document_id'],
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

            if ($settlesChecklistItem) {
                $this->settleChecklistItem($lockedItem, $actor);
            }

            // Nhật ký kiểm toán nằm BÊN TRONG transaction, cùng lý do với `PublishDocument` và
            // `TransitionMatterStage`: ngoài transaction thì có một khoảng — ngắn, nhưng có —
            // mà bản ghi đã commit còn dòng nhật ký thì chưa ghi. Ở đây khoảng đó nặng hơn hẳn,
            // vì một lần nộp nhóm A commit ở trạng thái khách đọc được ngay: một tiến trình
            // chết đúng lúc để lại một tài liệu khách đang xem mà SPEC §10.6 không có dòng nào
            // nói ai đưa nó ra.
            Audit::record('document_uploaded', $document, [
                'matter_id' => $freshMatter->getKey(),
                'matter_checklist_item_id' => $lockedItem?->getKey(),
                'group' => $group->value,
                'status' => $defaults['status']->value,
                'client_can_view' => $defaults['client_can_view'],
                'client_can_download' => $defaults['client_can_download'],
            ], $actor);

            // Và một dòng `document_published` NỮA khi bộ mặc định đã đưa tài liệu ra tới khách.
            // Hai dòng cho một thao tác là cố ý: `document_uploaded` trả lời "tệp vào hệ thống
            // lúc nào", `document_published` trả lời "VĂN PHÒNG đã quyết định đưa tài liệu nào
            // ra trước mặt khách" (SPEC §10.6 bắt ghi lại mọi lần công bố tài liệu). Gộp chúng
            // lại sẽ khiến một truy vấn dựng lại các lần công bố — lọc theo tên sự kiện, cách
            // duy nhất có — im lặng bỏ sót đúng nhóm không ai bấm nút công bố.
            //
            // Nói cho đúng, vì bản đầu của câu này viết "tệp ra tới khách lúc nào" và câu đó
            // KHÔNG còn đúng kể từ khi `SubmitClientDocument` tồn tại: một tệp khách tự gửi lên
            // cũng ở trong tầm tay khách ngay lúc tạo, nhưng nó để lại `document_submitted` chứ
            // không `document_published`. Từ vựng đầy đủ — và cái HỢP của hai tên sự kiện —
            // được phát biểu ở một chỗ duy nhất, trong docblock của `App\Support\Audit`.
            //
            // Hình dạng thuộc tính chép theo `PublishDocument` để hai nguồn của cùng một câu hỏi
            // đọc được bằng cùng một truy vấn.
            if ($releasedAtCreation) {
                Audit::record('document_published', $document, [
                    'matter_id' => $freshMatter->getKey(),
                    'client_id' => $freshMatter->client_id,
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
     * **Và lập luận đó có đúng MỘT chỗ nó gãy: khi đầu mục đang `pending_review`.** Lúc ấy có một
     * tệp khách vừa gửi lên chưa ai mở, nên câu "người nộp đã cầm tệp trên tay, đã đọc nó" không
     * nói gì về cái tệp ĐANG CHỜ — và dòng ghi ra sẽ khai một lần duyệt chưa xảy ra, kèm tên một
     * người chưa hề mở nó. Vì vậy `handle()` từ chối trước khi tới đây, qua
     * {@see RefusesWhileAwaitingReview}: cùng luật, cùng câu chữ với
     * `MarkChecklistItemNotApplicable`, phát biểu ở một chỗ.
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
